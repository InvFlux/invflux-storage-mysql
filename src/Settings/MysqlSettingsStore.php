<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Settings;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Settings\InvalidSettingValue;
use Nandan108\InvFlux\Settings\ReplicationPolicy;
use Nandan108\InvFlux\Settings\SettingDefinition;
use Nandan108\InvFlux\Settings\SettingsCatalog;
use Nandan108\InvFlux\Settings\SettingsStore;
use Nandan108\InvFlux\Settings\SettingValue;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Setting;
use Nandan108\InvFlux\Util\Json;

/**
 * MySQL implementation of {@see SettingsStore} backed by `invflux_settings`.
 *
 * Reads and writes go through the {@see Setting} Record, so the table's DDL has one source and
 * convergence can see it drift. Resolves the effective `replication_policy` at write time so
 * reads never re-derive.
 *
 * Strict mode: every key passed to `set()` is checked against the catalog;
 * unknown keys throw `\InvalidArgumentException`.
 */
final class MysqlSettingsStore implements SettingsStore
{
    public function __construct(
        private readonly SettingsCatalog $catalog,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Run `$fn` with the Record bound to this store's injected connection rather than the ambient
     * global one, so the writes land on the session the store was given — and stay observable in a
     * unit test that never touches global state.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function onConnection(callable $fn): mixed
    {
        return Record::usingConnection($this->connection, $fn);
    }

    #[\Override]
    public function getValue(string $name): mixed
    {
        $row = $this->get($name);
        if (null !== $row) {
            return $row->value;
        }

        return $this->catalog->get($name)->defaultValue;
    }

    #[\Override]
    public function get(string $name): ?SettingValue
    {
        $row = $this->onConnection(static fn (): ?Setting => Setting::findOne(
            WhereClause::where('name', $name),
        ));

        return null === $row ? null : $this->toValue($row);
    }

    #[\Override]
    public function set(string $name, mixed $value, ?ReplicationPolicy $merchantChoice = null): void
    {
        $definition = $this->catalog->get($name); // throws for unknown key (strict mode)
        $this->assertValid($definition, $value);

        [$effective, $storedMerchantChoice] = $this->resolvePolicy($definition, $merchantChoice);

        // upsertAll(), not save(): save() inserts a record built with newWith() — it is new by
        // definition — and would collide on an existing name. The keyed upsert is what "set" means.
        $this->persist([$this->row($name, $value, $effective, $definition->policyLocked, $storedMerchantChoice)]);
    }

    #[\Override]
    public function setBatch(array $items): void
    {
        // Validate every item up front (strict-mode catalog lookup + validator) so a failure
        // surfaces with its true exception type rather than wrapped by transactional(). The
        // persist below then cannot fail on validation, so either all rows land or none do.
        $rows = [];
        foreach ($items as $item) {
            $definition = $this->catalog->get($item['name']);
            $this->assertValid($definition, $item['value']);
            [$effective, $storedMerchantChoice] = $this->resolvePolicy($definition, $item['merchantChoice'] ?? null);
            $rows[] = $this->row($item['name'], $item['value'], $effective, $definition->policyLocked, $storedMerchantChoice);
        }

        // Last-wins on a repeated name, which the previous per-row loop got for free by simply
        // overwriting. One statement cannot rely on that: a keyed upsert carrying the same key
        // twice has no ordering guarantee, so the intent is made explicit here instead.
        $deduped = [];
        foreach ($rows as $row) {
            $deduped[$row->name] = $row;
        }

        $this->persist(array_values($deduped));
    }

    /**
     * Persist rows with the deadlock-safe keyed upsert — one statement, whatever the batch size.
     *
     * @param list<Setting> $rows
     */
    private function persist(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        // No transaction wrapper: a single upsert is already atomic, so the batch's
        // all-or-nothing guarantee needs no transaction wrapped around N round-trips.
        $this->onConnection(static fn (): mixed => (new RecordSet($rows))->upsertAll());
    }

    #[\Override]
    public function delete(string $name): void
    {
        $this->onConnection(static fn (): int => Setting::deleteWhere(WhereClause::where('name', $name)));
    }

    #[\Override]
    public function all(): array
    {
        $rows = $this->onConnection(static fn (): RecordSet => Setting::find(orderByLimit: 'ORDER BY `name` ASC'));

        return array_map($this->toValue(...), iterator_to_array($rows, false));
    }

    /** Hydrate a row into the value object the contract returns. */
    private function toValue(Setting $row): SettingValue
    {
        return new SettingValue(
            name: $row->name,
            value: Json::decode($row->value_json),
            effectivePolicy: $row->replication_policy,
            policyLocked: $row->policy_locked,
            merchantChoice: $row->merchant_choice,
        );
    }

    /** Build the row for a write; shared by set() and the batch path so both resolve identically. */
    private function row(
        string $name,
        mixed $value,
        ReplicationPolicy $effective,
        bool $policyLocked,
        ?ReplicationPolicy $merchantChoice,
    ): Setting {
        return Setting::newWith([
            'name'               => $name,
            'value_json'         => Json::encode($value),
            'replication_policy' => $effective,
            'policy_locked'      => $policyLocked,
            'merchant_choice'    => $merchantChoice,
        ]);
    }

    /**
     * Resolve the stored policy pair. A locked definition keeps its default and records no
     * merchant choice, so unlocking it later cannot silently promote a stale one.
     *
     * @return array{ReplicationPolicy, ?ReplicationPolicy}
     */
    private function resolvePolicy(SettingDefinition $definition, ?ReplicationPolicy $merchantChoice): array
    {
        if ($definition->policyLocked) {
            return [$definition->defaultPolicy, null];
        }
        if (null !== $merchantChoice) {
            return [$merchantChoice, $merchantChoice];
        }

        return [$definition->defaultPolicy, null];
    }

    /**
     * Run a definition's value validator (server-authoritative). No-op when the
     * definition has no validator.
     *
     * @throws InvalidSettingValue when the validator returns a non-null reason
     */
    private function assertValid(SettingDefinition $definition, mixed $value): void
    {
        if (null === $definition->validator) {
            return;
        }

        $error = ($definition->validator)($value);
        if (null !== $error) {
            throw new InvalidSettingValue($definition->name, $error);
        }
    }
}
