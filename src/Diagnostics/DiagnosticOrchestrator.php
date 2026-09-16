<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Diagnostics;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Enum\OnConflict;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Diagnostics\DiagnosticCheck;
use Nandan108\InvFlux\Diagnostics\DiagnosticResult;
use Nandan108\InvFlux\Diagnostics\DiagnosticStatus;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\DiagnosticResultRow;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\DiagnosticSchedule;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;
use Nandan108\InvFlux\Util\Json;

/**
 * Register, schedule, execute, and persist diagnostic check results.
 *
 * @api
 */
final class DiagnosticOrchestrator
{
    private const TABLE_SCHEDULE = 'invflux_diagnostic_schedule';
    private const TABLE_RESULTS = 'invflux_diagnostic_results';

    /** @var array<string, DiagnosticCheck> */
    private array $checks = [];

    /** Create an orchestrator over one MySQL session and optional WordPress table prefix. */
    public function __construct(
        private readonly MysqlSession $session,
        private readonly Connection $connection,
        private readonly string $tablePrefix = '',
    ) {
    }

    /**
     * Run `$fn` with the Records bound to this orchestrator's injected connection rather than the
     * ambient global one, so its writes land on the session it was given.
     *
     * The raw-SQL reads below share that session — `$connection->session` and `$this->session` are
     * the same object — so the attrecord and raw halves stay in one transaction when a caller
     * opens one.
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

    /**
     * Register one check in memory.
     *
     * Pure in-memory wiring — runs on every request (the container factory
     * registers all checks at construction). The matching table creation and
     * schedule-row seeding are deferred to {@see install()}, which runs only on
     * the version-gated provisioning path, so steady-state requests issue no
     * schema or seed writes.
     */
    public function register(DiagnosticCheck $check): void
    {
        $key = $check->key();
        if ('' === $key || strlen($key) > 64) {
            throw new ConfigurationException('Diagnostic check key must be 1-64 characters.', 'invalid_diagnostic_key');
        }

        if ($check->defaultFrequencySeconds() < 1) {
            throw new ConfigurationException('Diagnostic check frequency must be positive.', 'invalid_diagnostic_frequency');
        }

        $this->checks[$key] = $check;
    }

    /**
     * Seed a schedule row for every registered check.
     *
     * Idempotent (insert-or-ignore) but write-heavy — invoke ONLY from the version-gated
     * provisioning path, never per request. Re-running after a new check is registered (behind a
     * provision-version bump) seeds the new check's row. The tables themselves are converged like
     * every other managed table; this only fills them.
     */
    public function install(): void
    {
        if ([] === $this->checks) {
            return;
        }

        $rows = [];
        foreach ($this->checks as $key => $check) {
            $rows[] = DiagnosticSchedule::newWith([
                'check_key'         => $key,
                'enabled'           => true,
                'frequency_seconds' => $check->defaultFrequencySeconds(),
                'next_run_at'       => new \DateTimeImmutable(),
            ]);
        }

        // Insert-or-ignore: an existing row keeps the frequency the operator configured, so
        // re-provisioning never resets a tuned schedule. One statement, not a row-at-a-time loop.
        $this->onConnection(static fn (): mixed => (new RecordSet($rows))->insertAll(onConflict: OnConflict::Ignore));
    }

    /**
     * Run all registered checks whose schedule is currently due.
     *
     * @return list<array{check_key: string, result: DiagnosticResult}>
     */
    public function runOverdue(): array
    {
        if ([] === $this->checks) {
            return [];
        }

        $rows = $this->onConnection(static fn (): RecordSet => DiagnosticSchedule::find(
            'enabled = 1 AND next_run_at <= CURRENT_TIMESTAMP(6)',
            orderByLimit: 'ORDER BY next_run_at ASC, check_key ASC',
        ));

        $results = [];
        foreach ($rows as $row) {
            $key = $row->check_key;
            $check = $this->checks[$key] ?? null;
            if (!$check instanceof DiagnosticCheck) {
                continue;
            }

            $frequencySeconds = max(1, $row->frequency_seconds);
            $result = $this->executeCheck($check, $frequencySeconds);
            $results[] = ['check_key' => $key, 'result' => $result];
        }

        return $results;
    }

    /**
     * Run one registered check by key immediately and persist the result.
     *
     * @throws ConfigurationException when the key is not registered
     */
    public function runCheckByKey(string $key): DiagnosticResult
    {
        $check = $this->checks[$key] ?? null;
        if (!$check instanceof DiagnosticCheck) {
            throw new ConfigurationException("Diagnostic check key '{$key}' is not registered.", 'unknown_diagnostic_key');
        }

        $scheduleRow = $this->onConnection(static fn (): ?DiagnosticSchedule => DiagnosticSchedule::findOne(
            WhereClause::where('check_key', $key),
        ));

        $frequencySeconds = max(1, $scheduleRow->frequency_seconds ?? $check->defaultFrequencySeconds());

        return $this->executeCheck($check, $frequencySeconds);
    }

    /**
     * Return the latest persisted result for each registered check key.
     *
     * @return list<array{check_key: string, status: string, ran_at: string, findings: list<array<string, mixed>>, duration_ms: int}>
     */
    public function latestResultsByCheck(): array
    {
        if ([] === $this->checks) {
            return [];
        }

        $rows = $this->session->fetchAll(sprintf(
            'SELECT r.check_key, r.status, r.ran_at, r.findings_json, r.duration_ms
             FROM %1$s r
             INNER JOIN (
                 SELECT check_key, MAX(id) AS max_id
                 FROM %1$s
                 GROUP BY check_key
             ) latest ON latest.check_key = r.check_key AND latest.max_id = r.id
             ORDER BY r.check_key ASC',
            $this->table(self::TABLE_RESULTS),
        ));

        return array_map(function (array $row): array {
            /** @psalm-var mixed $decoded */
            $decoded = json_decode((string) ($row['findings_json'] ?? '[]'), true);
            /** @psalm-var list<array<string, mixed>> $findings */
            $findings = is_array($decoded) ? array_values($decoded) : [];

            return [
                'check_key'   => (string) $row['check_key'],
                'status'      => (string) $row['status'],
                'ran_at'      => (string) $row['ran_at'],
                'findings'    => $findings,
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }, $rows);
    }

    /**
     * Return unresolvable results that should trigger an admin notice.
     *
     * @return list<array{id: int, check_key: string, findings: list<array<string, mixed>>, ran_at: string}>
     */
    public function unnotifiedUnresolvableResults(): array
    {
        $rows = $this->session->fetchAll(sprintf(
            'SELECT id, check_key, findings_json, ran_at
             FROM %s r
             WHERE status = :status
               AND notified_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM %s newer
                   WHERE newer.check_key = r.check_key
                     AND newer.ran_at > r.ran_at
                     AND newer.status IN (:ok_status, :repaired_status)
               )
             ORDER BY ran_at ASC, id ASC',
            $this->table(self::TABLE_RESULTS),
            $this->table(self::TABLE_RESULTS),
        ), [
            'status'          => DiagnosticStatus::Unresolvable->value,
            'ok_status'       => DiagnosticStatus::Ok->value,
            'repaired_status' => DiagnosticStatus::AutoRepaired->value,
        ]);

        return array_map(function (array $row): array {
            /** @psalm-var mixed $decoded */
            $decoded = json_decode((string) $row['findings_json'], true);
            /** @psalm-var list<array<string, mixed>> $findings */
            $findings = is_array($decoded) ? array_values($decoded) : [];

            return [
                'id'        => (int) $row['id'],
                'check_key' => (string) $row['check_key'],
                'findings'  => $findings,
                'ran_at'    => (string) $row['ran_at'],
            ];
        }, $rows);
    }

    /** Mark one persisted result as having triggered its admin notice. */
    public function markResultNotified(int $resultId): void
    {
        $this->onConnection(static fn (): int => DiagnosticResultRow::updateWhere(
            ['notified_at' => new RawSql('CURRENT_TIMESTAMP(6)')],
            WhereClause::where('id', $resultId),
        ));
    }

    private function executeCheck(DiagnosticCheck $check, int $frequencySeconds): DiagnosticResult
    {
        $key = $check->key();
        $result = $this->runOne($check);
        $this->persistResult($key, $result);
        $this->advanceSchedule($key, $frequencySeconds);
        if ($result->isOk()) {
            $this->pruneOkResults($key);
        }

        return $result;
    }

    private function runOne(DiagnosticCheck $check): DiagnosticResult
    {
        try {
            $result = $check->run();
            if ($result->isOk()) {
                return $result;
            }

            $repaired = $check->repair($result);
            if (!$repaired instanceof DiagnosticResult) {
                return new DiagnosticResult(DiagnosticStatus::Unresolvable, $result->findings, $result->durationMs);
            }

            $verified = $check->run();
            if ($verified->isOk()) {
                return new DiagnosticResult(DiagnosticStatus::AutoRepaired, $result->findings, $result->durationMs + $repaired->durationMs + $verified->durationMs);
            }

            return new DiagnosticResult(DiagnosticStatus::Unresolvable, $verified->findings, $result->durationMs + $repaired->durationMs + $verified->durationMs);
        } catch (\Throwable $throwable) {
            return new DiagnosticResult(DiagnosticStatus::Unresolvable, [[
                'type'    => 'exception',
                'class'   => $throwable::class,
                'message' => $throwable->getMessage(),
            ]]);
        }
    }

    private function persistResult(string $key, DiagnosticResult $result): void
    {
        $notifiedAt = null;
        if (DiagnosticStatus::Unresolvable === $result->status && !$this->shouldNotifyUnresolvable($key)) {
            $notifiedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        }

        $this->onConnection(static fn (): mixed => DiagnosticResultRow::newWith([
            'check_key'     => $key,
            'ran_at'        => new \DateTimeImmutable(),
            'status'        => $result->status,
            'findings_json' => Json::encode($result->findings),
            // SMALLINT UNSIGNED: clamp rather than let a pathological duration overflow the column.
            'duration_ms'   => max(0, min(65535, $result->durationMs)),
            'notified_at'   => null === $notifiedAt ? null : new \DateTimeImmutable($notifiedAt),
        ])->save());
    }

    private function shouldNotifyUnresolvable(string $key): bool
    {
        $row = $this->onConnection(static fn (): ?DiagnosticResultRow => DiagnosticResultRow::findOne(
            WhereClause::where('check_key', $key),
            orderByLimit: 'ORDER BY ran_at DESC, id DESC LIMIT 1',
        ));

        if (null === $row) {
            return true;
        }

        return in_array($row->status, [DiagnosticStatus::Ok, DiagnosticStatus::AutoRepaired], true);
    }

    private function advanceSchedule(string $key, int $frequencySeconds): void
    {
        // Expression SET on a single table is attrecord's job, RawSql and all. The clock and the
        // interval stay the database's, not PHP's, so the next due time cannot drift with skew.
        $this->onConnection(static fn (): int => DiagnosticSchedule::updateWhere(
            [
                'last_run_at' => new RawSql('CURRENT_TIMESTAMP(6)'),
                'next_run_at' => new RawSql('DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ? SECOND)', [$frequencySeconds]),
            ],
            WhereClause::where('check_key', $key),
        ));
    }

    /**
     * Keep the ten most recent OK runs per check and drop the rest.
     *
     * Stays raw SQL: MySQL forbids referencing the deletion target inside a subquery of the same
     * DELETE, so the surviving ids have to be laundered through a derived table. That is a shape
     * deleteWhere() cannot express — a capability gap, not a preference.
     */
    private function pruneOkResults(string $key): void
    {
        $this->session->exec(sprintf(
            'DELETE FROM %1$s
             WHERE check_key = :check_key
               AND status = :status
               AND id NOT IN (
                   SELECT id FROM (
                       SELECT id
                       FROM %1$s
                       WHERE check_key = :check_key_inner
                         AND status = :status_inner
                       ORDER BY ran_at DESC, id DESC
                       LIMIT 10
                   ) keep_rows
               )',
            $this->table(self::TABLE_RESULTS),
        ), [
            'check_key'       => $key,
            'status'          => DiagnosticStatus::Ok->value,
            'check_key_inner' => $key,
            'status_inner'    => DiagnosticStatus::Ok->value,
        ]);
    }

    private function table(string $table): string
    {
        return '`'.str_replace('`', '``', $this->tablePrefix.$table).'`';
    }
}
