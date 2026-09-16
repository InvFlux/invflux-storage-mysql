<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

/**
 * InvFlux's transient-conflict retry policy, expressed as the `$retryable` predicate that
 * {@see \Nandan108\Attrecord\Session\RetryingDbSession} consults per caught throwable.
 *
 * It reuses the wrapped session's own {@see MysqlSession::isRetryableTransactionError()} for the
 * base "is this even a transient conflict?" decision (no duplicated error-code tables), then
 * layers InvFlux's no-deadlock discipline on top:
 *
 * - **Lock-wait timeout (1205)** / **MariaDB record-changed (1020)** — genuine contention, always
 *   retried.
 * - **Deadlock (1213)** — a lock-**order** or lock-**conversion** conflict. Recorded to the opt-in
 *   {@see DeadlockTelemetry} sink (when wired) and retried **only in production**, so that dev,
 *   test and CI surface it loudly and it gets fixed rather than masked.
 *
 *   Both halves of that naming are load-bearing, because only the first is a discipline failure. An
 *   *order* conflict means two transactions took the same locks in different sequences — the thing
 *   the lock-acquisition contract exists to prevent, and a bug. A *conversion* conflict needs no
 *   ordering mistake and no second resource: two sessions holding a shared lock on **one** row and
 *   both asking to upgrade it to exclusive deadlock each other, and no acquisition order could have
 *   avoided it. attrecord's `UpsertStrategy::Locked` reaches that shape when two writers upsert the
 *   same existing key concurrently. A reader who takes 1213 as proof of a mis-ordering will hunt for
 *   one that does not exist and end up doubting the log.
 * - Anything else — not retried.
 *
 * @see docs lock-acquisition-order contract (deadlock prevention)
 *
 * @api consumed by adapters to build the retry decorator's `$retryable` predicate
 */
final class DeadlockAwareRetryPolicy
{
    /** Stable MySQL/MariaDB deadlock message fragment, carried verbatim up the wrapped-exception chain. */
    private const DEADLOCK_SIGNATURE = 'Deadlock found';

    /** MySQL/MariaDB deadlock driver error number. */
    private const DEADLOCK_ERRNO = 1213;

    public function __construct(
        private readonly MysqlSession $classifier,
        private readonly bool $retryDeadlocks,
        private readonly ?DeadlockTelemetry $telemetry = null,
    ) {
    }

    public function __invoke(\Throwable $conflict): bool
    {
        // Base decision reuses the session's driver-specific classifier — the only place that
        // knows how this session wraps and codes transient errors.
        if (!$this->classifier->isRetryableTransactionError($conflict)) {
            return false;
        }

        if ($this->isDeadlock($conflict)) {
            $this->telemetry?->recordDeadlock($conflict);

            return $this->retryDeadlocks;
        }

        // 1205 / 1020: genuine contention, retry everywhere.
        return true;
    }

    /**
     * Walk the wrapped-exception chain so this works regardless of how the concrete session
     * surfaces the driver error (wpdb wraps a RuntimeException; PDO/mysqli wrap a driver
     * exception whose message carries the SQLSTATE text). The deadlock message text is fixed
     * English in the MySQL wire protocol and is not localized, so matching it is reliable.
     */
    private function isDeadlock(\Throwable $conflict): bool
    {
        for ($e = $conflict; null !== $e; $e = $e->getPrevious()) {
            if (self::DEADLOCK_ERRNO === $e->getCode()) {
                return true;
            }
            if (str_contains($e->getMessage(), self::DEADLOCK_SIGNATURE)) {
                return true;
            }
        }

        return false;
    }
}
