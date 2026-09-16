<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\InvFlux\Exceptions\PersistenceException;

/**
 * Enforce the lock-acquisition order across the two locking subsystems, at runtime.
 *
 * ## The rule
 *
 * Within one transaction, entity locks are taken **before** inventory-state locks, never after.
 * Both subsystems are internally deterministic — entity locks are tier-ordered then ascending-PK,
 * inventory-state locks are ordered by `(subject_id, slot_id)` — but neither mechanism can see the
 * other, so the rule that spans them belonged to nobody and was carried by a comment. Two
 * transactions taking the two kinds of lock in opposite orders deadlock; that is demonstrated, not
 * theoretical, and the transaction the database rolls back is not necessarily the one that broke
 * the rule.
 *
 * This class turns that comment into an assertion. It sits in the session chain, so it sees every
 * statement both subsystems issue and needs no cooperation from any call site — which matters
 * because the alternative, asking each locking call site to declare its phase, is one forgotten
 * call site away from a hole, and the call sites are extensible by add-ons.
 *
 * ## How a lock is recognised
 *
 * By inspecting SQL for `FOR UPDATE` and asking whether the statement touches the inventory-state
 * table. That is pattern-matching rather than a type, and it is a deliberate trade: it is the only
 * technique that requires nothing of the caller. It is safe here because both emitters are in-tree
 * and the marker is unambiguous — a statement either asks for `FOR UPDATE` or it does not, and the
 * table name survives any prefix the host applies.
 *
 * ## What it does not cover
 *
 * Only explicit `FOR UPDATE` reads. Plain `UPDATE`/`INSERT`/`DELETE` also take row locks and can
 * deadlock, but ordering *those* is not the documented rule, and treating every write as a phase
 * transition would report violations for ordinary work. The scope here is exactly the rule it
 * enforces — no more, so that a violation always means something.
 *
 * ## It only sees what flows through it
 *
 * Both subsystems must reach the database through the *same* guarded session — the entity path via
 * the attrecord `Connection`, the inventory path via the store. Hand either one a session that
 * skips this decorator and that half becomes invisible: entity locks stop being checked, or
 * inventory locks stop opening the phase, and the guard silently approves everything. There is no
 * way to detect that from in here, which is why the host builds one session and shares it rather
 * than constructing one per consumer.
 *
 * ## Placement is load-bearing
 *
 * This must sit **inside** any retry decorator — `Retrying(LockPhaseGuard(session))`, not the
 * reverse. Phase state is per-transaction *attempt*: a retry re-runs the operation from the start,
 * so it must re-enter this guard's `transactional()` and get a clean slate. Wrapped the other way
 * round, state from a failed attempt survives into the next one and reports a violation that never
 * happened.
 *
 * @api wired by the host when it builds its session chain
 */
final class LockPhaseGuard implements MysqlSession
{
    /**
     * The authoritative inventory-state table, unprefixed.
     *
     * Matched as a substring so any host table prefix (`wp_`, `wp_2_`, …) still resolves.
     */
    private const INVENTORY_STATE_TABLE = 'invflux_inventory_state';

    private const LOCK_MARKER = 'FOR UPDATE';

    /** Whether an inventory-state lock has been taken in the current transaction. */
    private bool $inventoryPhaseEntered = false;

    /** Transaction nesting depth, tracked here rather than read off the inner session. */
    private int $depth = 0;

    /**
     * @param bool                                  $throwOnViolation whether a violation is fatal. True outside production, so a
     *                                                                slipped lock order fails loudly and gets fixed. False in
     *                                                                production, where turning a *possible* deadlock into a
     *                                                                *certain* fatal would be the worse trade — there it records
     *                                                                and lets the statement through, leaving behaviour exactly as
     *                                                                it was before this guard existed
     * @param (\Closure(string, string): void)|null $onViolation      receives (message, sql); wire it to a log in production,
     *                                                                where nothing else would surface the violation
     */
    public function __construct(
        private readonly MysqlSession $inner,
        private readonly bool $throwOnViolation = true,
        private readonly ?\Closure $onViolation = null,
    ) {
    }

    /**
     * Reset the phase for each outermost transaction — including each retry attempt, since a retry
     * re-enters here.
     *
     * @param \Closure():mixed $operation
     */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        // Clearing on the way *in* rather than on the way out is deliberate: entry is the point
        // every attempt passes through, including a retry, and it holds even if a prior transaction
        // unwound abnormally. Clearing on exit as well would be a second mechanism for one job.
        if (0 === $this->depth) {
            $this->inventoryPhaseEntered = false;
        }
        ++$this->depth;

        try {
            return $this->inner->transactional($operation);
        } finally {
            --$this->depth;
        }
    }

    /**
     * Classify one statement and enforce the ordering.
     *
     * Outside a transaction there is no ordering to enforce: a `FOR UPDATE` in autocommit takes and
     * releases its lock in the same breath and cannot participate in a cycle. Skipping that case
     * also keeps the phase flag from latching on with no transaction boundary to clear it.
     */
    private function observe(string $sql): void
    {
        if (0 === $this->depth) {
            return;
        }

        // Cheap gate first: the overwhelming majority of statements are not locking reads.
        if (false === stripos($sql, self::LOCK_MARKER)) {
            return;
        }

        if (false !== stripos($sql, self::INVENTORY_STATE_TABLE)) {
            $this->inventoryPhaseEntered = true;

            return;
        }

        // An entity lock. Legal before the inventory phase, a violation after it.
        if (!$this->inventoryPhaseEntered) {
            return;
        }

        $this->reportViolation($sql);
    }

    private function reportViolation(string $sql): void
    {
        $message = 'Lock-order violation: an entity row was locked after inventory-state rows in the '
            .'same transaction. Entity locks must be acquired first — the reverse order deadlocks '
            .'against every path that follows the rule.';

        if (null !== $this->onViolation) {
            ($this->onViolation)($message, $sql);
        }

        if (!$this->throwOnViolation) {
            return;
        }

        throw new PersistenceException(
            $message,
            'lock_order_violation',
            // Truncated: enough to identify the statement, not enough to dump a payload into a log.
            ['sql' => substr($sql, 0, 300)],
        );
    }

    // ---- observed pass-throughs ----

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->observe($sql);

        return $this->inner->exec($sql, $params);
    }

    /**
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $this->observe($sql);

        return $this->inner->fetchAll($sql, $params);
    }

    /**
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->observe($sql);

        return $this->inner->fetchOne($sql, $params);
    }

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $this->observe($sql);

        return $this->inner->fetchScalar($sql, $params);
    }

    // ---- unobserved pass-throughs ----

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->inner->lastInsertId();
    }

    /** @param \Closure():mixed $callback */
    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $this->inner->withAdvisoryLock($lockName, $timeoutSeconds, $callback);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return $this->inner->isDuplicateKeyError($throwable);
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return $this->inner->isRetryableTransactionError($throwable);
    }

    #[\Override]
    public function defaultCollation(): string
    {
        return $this->inner->defaultCollation();
    }
}
