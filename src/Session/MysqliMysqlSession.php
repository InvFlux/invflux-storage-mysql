<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\InvFlux\Exceptions\InvFluxException;
use Nandan108\InvFlux\Exceptions\PersistenceException;

/** @psalm-suppress UnusedClass instantiated by integrators rather than this package itself */
final class MysqliMysqlSession implements MysqlSession
{
    private int $transactionDepth = 0;

    /** Return whether mysqli support is available in the current runtime. */
    public static function isAvailable(): bool
    {
        return extension_loaded('mysqli') && class_exists(\mysqli::class);
    }

    /**
     * Try to open a mysqli-backed MySQL session for the given connection settings.
     *
     * @psalm-suppress PossiblyUnusedMethod public factory for external integrators
     */
    public static function tryConnect(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
        string $charset = 'utf8mb4',
    ): ?self {
        if (!self::isAvailable()) {
            return null;
        }

        try {
            \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new \mysqli($host, $user, $password, $database, $port);
            $mysqli->set_charset($charset);

            return new self($mysqli);
        } catch (\Throwable) {
            return null;
        }
    }

    private ?string $collationCache = null;

    public function __construct(
        private readonly \mysqli $mysqli,
    ) {
    }

    #[\Override]
    public function defaultCollation(): string
    {
        if (null !== $this->collationCache) {
            return $this->collationCache;
        }

        $collation = $this->fetchScalar(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
        );

        return $this->collationCache = is_string($collation) && '' !== $collation
            ? $collation
            : 'utf8mb4_unicode_ci';
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    /** @psalm-suppress PossiblyUnusedReturnValue required by the shared session contract */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        $this->queryInternal($sql, $params);

        return (int) $this->mysqli->affected_rows;
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        $result = $this->queryInternal($sql, $params);
        if (!$result instanceof \mysqli_result) {
            return [];
        }

        /** @var list<array<string, scalar|null>> $rows */
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->queryInternal($sql, $params);
        if (!$result instanceof \mysqli_result) {
            return null;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        $row = $this->fetchOne($sql, $params);
        if (null === $row) {
            return null;
        }

        /** @var string|int|float|null $scalarValue */
        $scalarValue = reset($row);

        return $scalarValue;
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        return $this->mysqli->insert_id;
    }

    /**
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     *
     * The template is restated here rather than inherited: psalm resolves the concrete
     * method, so without it `$result = $operation()` is a MixedAssignment and every caller
     * holding a concrete session (rather than the MysqlSession interface) loses the
     * closure's return type
     */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        $isOuterTransaction = 0 === $this->transactionDepth;
        if ($isOuterTransaction) {
            $this->exec('START TRANSACTION');
        }

        $committed = false;
        ++$this->transactionDepth;

        try {
            $result = $operation();

            if ($isOuterTransaction) {
                $this->exec('COMMIT');
                $committed = true;
            }

            return $result;
        } catch (InvFluxException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException($e->getMessage(), 'unexpected_error', [], $e);
        } finally {
            if ($isOuterTransaction && !$committed && $this->inTransaction()) {
                $this->exec('ROLLBACK');
            }
            --$this->transactionDepth;
        }
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        $acquired = $this->fetchScalar('SELECT GET_LOCK(:name, :timeout)', ['name' => $lockName, 'timeout' => $timeoutSeconds]);

        if (1 !== (int) $acquired) {
            throw new PersistenceException(
                sprintf('Could not acquire advisory lock "%s" within %d second(s).', $lockName, $timeoutSeconds),
                'advisory_lock_timeout',
                ['lock_name' => $lockName, 'timeout' => $timeoutSeconds],
            );
        }

        try {
            return $callback();
        } finally {
            $this->fetchScalar('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
        }
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return 0 < $this->transactionDepth;
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        $e = $throwable instanceof PersistenceException && $throwable->getPrevious() instanceof \mysqli_sql_exception
            ? $throwable->getPrevious()
            : ($throwable instanceof \mysqli_sql_exception ? $throwable : null);

        return $e instanceof \mysqli_sql_exception && 1062 === $e->getCode();
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        $e = $throwable instanceof PersistenceException && $throwable->getPrevious() instanceof \mysqli_sql_exception
            ? $throwable->getPrevious()
            : ($throwable instanceof \mysqli_sql_exception ? $throwable : null);

        // 1213 deadlock, 1205 lock-wait timeout, 1020 MariaDB "record has changed since last read".
        return $e instanceof \mysqli_sql_exception && \in_array($e->getCode(), [1213, 1205, 1020], true);
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    private function queryInternal(string $sql, array $params = []): \mysqli_result | bool
    {
        try {
            $compiledSql = $this->interpolate($sql, $params);
            $result = $this->mysqli->query($compiledSql);

            if (false === $result) {
                throw new PersistenceException($this->mysqli->error, 'mysqli_error');
            }

            return $result;
        } catch (\mysqli_sql_exception $e) {
            throw new PersistenceException($e->getMessage(), 'mysqli_error', [], $e);
        }
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    private function interpolate(string $sql, array $params): string
    {
        ['sql' => $sql, 'params' => $params] = NamedPlaceholderSql::positional($sql, $params);

        // Substitute every `?` placeholder in a single left-to-right pass. An
        // iterative `preg_replace(..., 1)` would mis-bind subsequent placeholders
        // when an interpolated value contains a `?` byte (common with BINARY(16)
        // UUIDv7 ids — ~6% of values), because the next iteration would match
        // the `?` byte inside the already-quoted string instead of the next
        // real placeholder.
        $count = count($params);
        $index = 0;
        $substituted = preg_replace_callback(
            '/\?/',
            function () use (&$index, $params, $count): string {
                if ($index >= $count) {
                    return '?';
                }
                $value = $params[$index++];
                is_string($value) || is_int($value) || is_float($value) || null === $value || throw new PersistenceException(
                    'SQL parameter must be string, int, float, or null.',
                    'invalid_sql_parameter_type',
                );

                return $this->quoteValue($value);
            },
            $sql,
        );

        return $substituted ?? throw new PersistenceException('SQL parameter interpolation failed.', 'sql_interpolation_failed');
    }

    private function quoteValue(string | int | float | null $value): string
    {
        if (null === $value) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".$this->mysqli->real_escape_string($value)."'";
    }
}
