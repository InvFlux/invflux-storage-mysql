<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Support;

use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;

/**
 * Configurable in-memory {@see MysqlSession} double for session-decorator / policy unit tests.
 *
 * `transactional()` throws the queued `$conflicts` on successive calls (one per attempt); once the
 * queue is exhausted it runs the passed operation and returns its result.
 * `isRetryableTransactionError()` is driven by the `$retryable` constructor argument.
 */
final class FakeMysqlSession implements MysqlSession
{
    /** @var list<\Throwable> conflicts thrown on successive transactional() calls before succeeding */
    public array $conflicts = [];

    public int $transactionalCalls = 0;

    /** @var bool|\Closure(\Throwable): bool */
    private readonly bool | \Closure $retryable;

    /**
     * @param bool|\Closure(\Throwable): bool $retryable classification returned by isRetryableTransactionError()
     */
    public function __construct(
        bool | \Closure $retryable = false,
        public string $collation = 'utf8mb4_unicode_ci',
    ) {
        $this->retryable = $retryable;
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        ++$this->transactionalCalls;

        if ([] !== $this->conflicts) {
            throw array_shift($this->conflicts);
        }

        return $operation();
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        return $this->retryable instanceof \Closure
            ? ($this->retryable)($throwable)
            : $this->retryable;
    }

    #[\Override]
    public function defaultCollation(): string
    {
        return $this->collation;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return false;
    }

    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        return 0;
    }

    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        return [];
    }

    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return null;
    }

    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        return null;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return 0;
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $callback();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        return false;
    }
}
