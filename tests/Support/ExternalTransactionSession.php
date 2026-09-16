<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Support;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;

/**
 * A {@see MysqlSession} whose transaction is owned by the caller, not by the session.
 *
 * Why a concurrency test needs this. {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore}
 * wraps its work in `transactional()`, and the concrete sessions treat depth 0 as "I am the
 * outermost transaction" — so a store call on an idle session opens *and commits* its own
 * transaction. A test that wants a transaction to stay **open** across several store calls (so a
 * second connection can contend with the locks it holds) therefore cannot simply issue
 * `START TRANSACTION` through `exec()`: that leaves the depth counter at 0, the store commits at
 * the end of its own call, and the locks are gone before the contention it was set up to test can
 * happen. That failure is silent and turns the whole test green for the wrong reason — it cost a
 * debugging cycle on the way to writing this, which is why it is spelled out here.
 *
 * Declaring `inTransaction() === true` and running `transactional()` closures inline makes every
 * store call behave as a *nested* participant in a transaction the test began itself, and the test
 * decides when it ends.
 *
 * The other reason it must be the caller: once a non-blocking lock request is in flight on the
 * connection (see {@see AsyncLockSession}), no further statement can be issued on it — including
 * `COMMIT`. Only the test knows when the connection is drained and safe to close out.
 *
 * Everything except transaction control delegates verbatim to the wrapped session.
 */
final class ExternalTransactionSession implements MysqlSession
{
    public function __construct(private readonly MysqlSession $inner)
    {
    }

    /** Run inline: this session is always already inside a transaction the caller opened. */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        return $operation();
    }

    /** Always true — see the class docblock; this is what keeps stores from self-committing. */
    #[\Override]
    public function inTransaction(): bool
    {
        return true;
    }

    // ---- everything else delegates verbatim ----

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
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
        return $this->inner->fetchOne($sql, $params);
    }

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        return $this->inner->fetchScalar($sql, $params);
    }

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
