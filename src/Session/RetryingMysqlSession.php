<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\Attrecord\Session\RetryingDbSession;

/**
 * A {@see MysqlSession} that retries the outer transaction on transient conflicts.
 *
 * The entire retry mechanism (attempt loop, exponential backoff + jitter, nesting-aware
 * "only the outermost transaction retries") is inherited verbatim from attrecord's
 * {@see RetryingDbSession}; this subclass exists solely to bridge the one-method gap between
 * attrecord's {@see \Nandan108\Attrecord\DbSession} and InvFlux's richer {@see MysqlSession}
 * (`defaultCollation()`), which attrecord's `DbSession`-typed decorator cannot satisfy.
 *
 * Retry *policy* is injected through the `$retryable` callable — see
 * {@see DeadlockAwareRetryPolicy} for InvFlux's no-deadlock-outside-production stance. It takes any
 * callable, not just a `Closure`, so the policy object it was designed to pair with goes in as-is;
 * the conversion attrecord's `Closure`-typed constructor needs happens here instead of at every
 * call site.
 *
 * @api consumed by adapters to wrap their concrete MysqlSession with retry semantics
 */
final class RetryingMysqlSession extends RetryingDbSession implements MysqlSession
{
    /**
     * @param MysqlSession                      $innerMysql  the session to wrap (kept typed so the
     *                                                       MysqlSession-specific surface can delegate)
     * @param (callable(\Throwable): bool)|null $retryable   retry policy; defaults to the wrapped
     *                                                       session's isRetryableTransactionError()
     * @param int                               $maxAttempts total attempts, including the first (>= 1)
     * @param int                               $baseDelayUs base backoff in microseconds (doubled per attempt)
     * @param int                               $maxDelayUs  per-attempt backoff cap in microseconds
     */
    public function __construct(
        private readonly MysqlSession $innerMysql,
        ?callable $retryable = null,
        int $maxAttempts = 10,
        int $baseDelayUs = 5_000,
        int $maxDelayUs = 100_000,
    ) {
        parent::__construct($innerMysql, $maxAttempts, $baseDelayUs, $maxDelayUs, null === $retryable ? null : $retryable(...));
    }

    #[\Override]
    public function defaultCollation(): string
    {
        return $this->innerMysql->defaultCollation();
    }
}
