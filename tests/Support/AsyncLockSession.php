<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Support;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\DbSession;
use Nandan108\InvFlux\Storage\Mysql\Session\NamedPlaceholderSql;

/**
 * A {@see DbSession} that dispatches one blocking `SELECT … FOR UPDATE` **without waiting for it**,
 * so a single-threaded test can hold two transactions in a genuine lock cycle.
 *
 * Why this exists. Proving a deadlock needs two transactions that are each blocked on a lock the
 * other holds. PHP's database drivers are synchronous: the moment transaction B issues the
 * statement that blocks, the process stops, and transaction A can never issue the statement that
 * closes the cycle. One of the two blocking statements must therefore be dispatched without
 * blocking the interpreter. `mysqli` over `mysqlnd` is the only driver here that can do that
 * (`MYSQLI_ASYNC` + {@see \mysqli_poll()}); PDO cannot.
 *
 * The important part is *where* the seam sits. This class is a `DbSession`, so the caller is still
 * the real {@see \Nandan108\Attrecord\LockSet::acquire()} — the production lock mechanism, emitting
 * its own SQL, in its own tier order. Only the transport underneath is test-owned. Nothing about
 * the statement being locked, its ordering, or its shape is re-implemented here, so the test cannot
 * drift away from what production actually does.
 *
 * Contract, and it is a narrow one:
 *  - {@see fetchAll()} fires the statement and returns `[]` **immediately** — the lock request is
 *    still in flight when it returns. Callers get no rows. That is a lie to `LockSet`, which will
 *    build empty RecordSets from it; acceptable only because the caller here wants the *lock*, not
 *    the rows, and discards the result.
 *  - Exactly one statement may be in flight. {@see await()} reaps it, and rethrows whatever the
 *    server raised — which is the point: a deadlocked lock request surfaces as errno 1213 here.
 *  - Every other `DbSession` method throws. This session is not a general-purpose connection and
 *    must never be handed to a store.
 *
 * The handle is shared with a {@see \Nandan108\InvFlux\Storage\Mysql\Session\MysqliMysqlSession}
 * wrapping the *same* `\mysqli` — same connection, therefore same transaction, which is what makes
 * the two phases parts of one lock cycle rather than two unrelated transactions.
 */
final class AsyncLockSession implements DbSession
{
    /** The statement currently in flight, or null when idle. Kept for failure messages. */
    private ?string $pending = null;

    public function __construct(private readonly \mysqli $mysqli)
    {
    }

    /**
     * Whether this runtime can dispatch a non-blocking query at all.
     *
     * `MYSQLI_ASYNC` is a mysqlnd-only capability: built against libmysqlclient, `mysqli_poll()`
     * does not exist and the flag is silently ignored, which would turn every await into a hang.
     */
    public static function isSupported(): bool
    {
        return \extension_loaded('mysqli')
            && \extension_loaded('mysqlnd')
            && \function_exists('mysqli_poll');
    }

    /**
     * Dispatch the statement and return no rows, without waiting for the server.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return list<array<string, scalar|null>> always empty — see the class docblock
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        null === $this->pending || throw new \LogicException(
            'A statement is already in flight on this session; await() it before firing another.',
        );

        $this->pending = $sql;
        $this->mysqli->query($this->interpolate($sql, $params), MYSQLI_ASYNC);

        return [];
    }

    /**
     * Poll once, without reaping: has the in-flight statement come back yet?
     *
     * Used to assert that a statement genuinely *blocked* rather than sailing through — a
     * contention test whose "blocked" transaction never actually blocked proves nothing.
     */
    public function hasSettled(float $timeoutSeconds = 0.0): bool
    {
        if (null === $this->pending) {
            return true;
        }

        return 0 < $this->poll($timeoutSeconds);
    }

    /**
     * Wait for the in-flight statement and rethrow whatever the server raised.
     *
     * @throws \mysqli_sql_exception the server-side failure — errno 1213 when this lock request
     *                               was chosen as the deadlock victim
     * @throws \RuntimeException     if the statement does not come back within the timeout, which
     *                               means the expected lock cycle never formed
     */
    public function await(float $timeoutSeconds = 10.0): void
    {
        if (null === $this->pending) {
            return;
        }

        $sql = $this->pending;
        if (0 >= $this->poll($timeoutSeconds)) {
            throw new \RuntimeException(sprintf(
                'Async statement did not return within %.1fs — the expected lock contention never resolved: %s',
                $timeoutSeconds,
                $sql,
            ));
        }

        $this->pending = null;
        // Throws mysqli_sql_exception under MYSQLI_REPORT_STRICT — which is how a deadlocked lock
        // request (errno 1213) surfaces. The explicit throw covers a runtime with reporting turned
        // down, where the failure would otherwise come back as a bare false.
        $result = $this->mysqli->reap_async_query();
        if ($result instanceof \mysqli_result) {
            $result->free();

            return;
        }

        if (0 !== $this->mysqli->errno) {
            throw new \mysqli_sql_exception($this->mysqli->error, $this->mysqli->errno);
        }
    }

    /**
     * Reap and discard the outcome, whatever it was.
     *
     * The counterpart to {@see await()} for the side of a cycle whose fate the test does not
     * assert on — the connection must still be drained before it can be reused.
     */
    public function discard(float $timeoutSeconds = 10.0): void
    {
        try {
            $this->await($timeoutSeconds);
        } catch (\Throwable) {
            $this->pending = null;
        }
    }

    /** @return int number of ready connections; 0 on timeout */
    private function poll(float $timeoutSeconds): int
    {
        $links = [$this->mysqli];
        $errors = [$this->mysqli];
        $rejects = [$this->mysqli];

        $seconds = (int) $timeoutSeconds;
        $micros = (int) \round(($timeoutSeconds - (float) $seconds) * 1_000_000.0);

        /** @psalm-suppress InvalidArgument mysqli_poll takes the arrays by reference */
        return (int) \mysqli_poll($links, $errors, $rejects, $seconds, $micros);
    }

    /**
     * Inline the bound parameters, because `MYSQLI_ASYNC` is a {@see \mysqli::query()} flag and
     * has no prepared-statement equivalent — `mysqli_stmt` cannot be executed asynchronously.
     *
     * Mirrors {@see \Nandan108\InvFlux\Storage\Mysql\Session\MysqliMysqlSession}'s interpolation,
     * including its single-pass substitution: an iterative replace would mis-bind once an
     * interpolated BINARY(16) value happens to contain a `?` byte.
     *
     * @param array<array-key, scalar|BinaryParam|null> $params
     */
    private function interpolate(string $sql, array $params): string
    {
        $unwrapped = \array_map(
            static fn (mixed $value): mixed => $value instanceof BinaryParam ? $value->bytes : $value,
            $params,
        );

        ['sql' => $sql, 'params' => $ordered] = NamedPlaceholderSql::positional($sql, $unwrapped);

        $count = \count($ordered);
        $index = 0;
        $substituted = \preg_replace_callback(
            '/\?/',
            function () use (&$index, $ordered, $count): string {
                if ($index >= $count) {
                    return '?';
                }

                $value = $ordered[$index++];
                if (null === $value) {
                    return 'NULL';
                }
                if (\is_int($value) || \is_float($value)) {
                    return (string) $value;
                }

                return "'".$this->mysqli->real_escape_string((string) $value)."'";
            },
            $sql,
        );

        return $substituted ?? throw new \RuntimeException('SQL parameter interpolation failed.');
    }

    // ---- Everything below is out of contract: this session fires one statement, nothing else. ----

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /**
     * @param array<array-key, scalar|BinaryParam|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        throw $this->unsupported(__FUNCTION__);
    }

    /** @param array<array-key, scalar|BinaryParam|null> $params */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function inTransaction(): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    private function unsupported(string $method): \LogicException
    {
        return new \LogicException(sprintf(
            '%s::%s() is not supported — this session exists only to dispatch one non-blocking lock request.',
            self::class,
            $method,
        ));
    }
}
