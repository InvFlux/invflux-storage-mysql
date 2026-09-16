<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

/**
 * Opt-in sink for deadlock occurrences.
 *
 * A deadlock signals slipped lock-order discipline (see the lock-acquisition-order contract),
 * so InvFlux never swallows one silently: outside production it propagates and fails loud; in
 * production it is retried to keep serving, but only after being recorded here so it can be
 * traced and fixed. Wiring an implementation is a store-owner opt-in — when none is wired, the
 * {@see DeadlockAwareRetryPolicy} simply skips recording.
 */
interface DeadlockTelemetry
{
    /**
     * Record one deadlock occurrence for later diagnosis.
     *
     * Called from within the retry loop before the retry decision is applied, on the thread that
     * caught the conflict. Implementations must be cheap and must not throw — a telemetry failure
     * must never turn a recoverable deadlock into a hard error.
     */
    public function recordDeadlock(\Throwable $conflict): void;
}
