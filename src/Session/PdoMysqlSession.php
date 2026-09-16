<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Session;

use Nandan108\InvFlux\Exceptions\InvFluxException;
use Nandan108\InvFlux\Exceptions\PersistenceException;

final class PdoMysqlSession implements MysqlSession
{
    private int $transactionDepth = 0;

    /** Return whether PDO MySQL support is available in the current runtime. */
    public static function isAvailable(): bool
    {
        return extension_loaded('pdo') && in_array('mysql', \PDO::getAvailableDrivers(), true);
    }

    /**
     * Try to open a PDO-backed MySQL session for the given connection settings.
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

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );

        try {
            return new self(new \PDO($dsn, $user, $password));
        } catch (\Throwable) {
            return null;
        }
    }

    private ?string $collationCache = null;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function defaultCollation(): string
    {
        if (null !== $this->collationCache) {
            return $this->collationCache;
        }

        $stmt = $this->pdo->query(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()',
        );
        /** @psalm-var string|false $raw */
        $raw = false !== $stmt ? $stmt->fetchColumn() : false;

        return $this->collationCache = is_string($raw) && '' !== $raw
            ? $raw
            : 'utf8mb4_unicode_ci';
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->prepareAndExecute($sql, $params);

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new PersistenceException($e->getMessage(), 'pdo_error', [], $e);
        }
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    #[\Override]
    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->prepareAndExecute($sql, $params);

            /** @var list<array<string, scalar|null>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $rows;
        } catch (\PDOException $e) {
            throw new PersistenceException($e->getMessage(), 'pdo_error', [], $e);
        }
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    #[\Override]
    public function fetchOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->prepareAndExecute($sql, $params);
            /** @var array<string, scalar|null>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (\PDOException $e) {
            throw new PersistenceException($e->getMessage(), 'pdo_error', [], $e);
        }
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    #[\Override]
    public function fetchScalar(string $sql, array $params = []): string | int | float | null
    {
        try {
            $stmt = $this->prepareAndExecute($sql, $params);
            /** @var scalar|false|null $value */
            $value = $stmt->fetchColumn();
            /** @var string|int|float|null $scalarValue */
            $scalarValue = false === $value ? null : $value;

            return $scalarValue;
        } catch (\PDOException $e) {
            throw new PersistenceException($e->getMessage(), 'pdo_error', [], $e);
        }
    }

    #[\Override]
    public function lastInsertId(): string | int
    {
        // PDO::lastInsertId() returns the id as a string ("0" when there is no
        // AUTO_INCREMENT value). attrecord's Record layer assigns this directly to the
        // record's PK property, which is typically `?int` — so, like WpdbMysqlSession
        // (which returns wpdb->insert_id as an int), normalize a numeric id to int.
        // A non-numeric id (a driver-generated string key) passes through unchanged.
        $id = $this->pdo->lastInsertId();

        return is_numeric($id) ? (int) $id : $id;
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
     *
     * @psalm-suppress PossiblyUnusedReturnValue the return value IS used — by
     *   MysqlDomainStore::transactional(), which returns it — but only through the
     *   MysqlSession interface, and findUnusedCode cannot attribute an interface-typed call
     *   back to this implementation. What it does see are the integration tests, which
     *   construct PdoMysqlSession concretely and call this for its side effects. Dropping
     *   the return type is not an option: it is fixed by DbSession and TransactionalStore.
     */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        $isOuterTransaction = 0 === $this->transactionDepth;
        if ($isOuterTransaction) {
            $this->pdo->beginTransaction();
        }

        $committed = false;
        ++$this->transactionDepth;

        try {
            $result = $operation();

            if ($isOuterTransaction) {
                $committed = $this->pdo->commit();
            }

            return $result;
        } catch (InvFluxException $e) {
            throw $e;
        } catch (\PDOException $e) {
            throw new PersistenceException($e->getMessage(), 'pdo_error', [], $e);
        } catch (\Throwable $e) {
            throw new PersistenceException($e->getMessage(), 'unexpected_error', [], $e);
        } finally {
            if ($isOuterTransaction && !$committed && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
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
        return $this->pdo->inTransaction();
    }

    #[\Override]
    public function isDuplicateKeyError(\Throwable $throwable): bool
    {
        $e = $throwable instanceof PersistenceException && $throwable->getPrevious() instanceof \PDOException
            ? $throwable->getPrevious()
            : ($throwable instanceof \PDOException ? $throwable : null);

        if (!$e instanceof \PDOException) {
            return false;
        }

        $sqlState = $e->getCode();
        $errorInfo = $e->errorInfo;
        $driverCode = is_array($errorInfo) && isset($errorInfo[1]) && is_scalar($errorInfo[1])
            ? (int) $errorInfo[1]
            : null;

        return '23000' === $sqlState && 1062 === $driverCode;
    }

    #[\Override]
    public function isRetryableTransactionError(\Throwable $throwable): bool
    {
        $e = $throwable instanceof PersistenceException && $throwable->getPrevious() instanceof \PDOException
            ? $throwable->getPrevious()
            : ($throwable instanceof \PDOException ? $throwable : null);

        if (!$e instanceof \PDOException) {
            return false;
        }

        $errorInfo = $e->errorInfo;
        $driverCode = is_array($errorInfo) && isset($errorInfo[1]) && is_scalar($errorInfo[1])
            ? (int) $errorInfo[1]
            : null;

        // 1213 deadlock, 1205 lock-wait timeout, 1020 MariaDB "record has changed since last read".
        return in_array($driverCode, [1213, 1205, 1020], true);
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    private function prepareAndExecute(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        false !== $stmt || throw new PersistenceException('Expected SQL preparation to return a PDO statement.', 'missing_prepared_statement');

        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;

            if (is_int($value)) {
                $stmt->bindValue($parameter, $value, \PDO::PARAM_INT);
                continue;
            }

            if (null === $value) {
                $stmt->bindValue($parameter, null, \PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($parameter, (string) $value, \PDO::PARAM_STR);
        }

        $stmt->execute();

        return $stmt;
    }
}
