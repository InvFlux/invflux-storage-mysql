<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Support;

/**
 * Give a session's integration run its own database, so concurrent lanes stop corrupting each other.
 *
 * ## The problem this solves
 *
 * Every integration suite in this workspace drops and recreates the *same* tables in the *same*
 * `invflux_test` database — that is how they get a clean slate, and it is correct in isolation. But
 * several sessions share one working tree and one MySQL instance, so two suites running at the same
 * moment interleave their DDL: one lane's `DROP TABLE` lands between another's `plan()` and its
 * `CREATE TABLE`, and the second fails with "table already exists" or "table doesn't exist".
 *
 * The failure is loud but misleading — it looks like a schema bug in the code under test, and it is
 * intermittent, so the reflex is to re-run until green. On 2026-08-06 two lanes independently wrote
 * retry-on-contention loops around phpunit rather than fixing the collision; that duplication is what
 * prompted this.
 *
 * ## The mechanism
 *
 * Set `INVFLUX_TEST_LANE` to any short name and the run gets `invflux_test_<lane>`, created on demand.
 * Nothing else changes: every integration test already resolves its database from `INVFLOW_DB_NAME`,
 * so exporting that variable here is enough — no test file needs to know this exists.
 *
 *     INVFLUX_TEST_LANE=lock-order composer test
 *
 * Deliberately opt-in. With no lane set, behaviour is exactly what it has always been (shared
 * `invflux_test`), so a contributor who does nothing is unaffected and CI — which gets a private
 * instance per job anyway — needs no configuration.
 *
 * `INVFLOW_DB_NAME` always wins if set explicitly: pinning a database is a stronger statement of
 * intent than naming a lane, and a caller who did both meant the database.
 */
final class TestDatabase
{
    /** MySQL caps identifiers at 64 bytes; the prefix costs 13 of them. */
    private const PREFIX = 'invflux_test_';
    private const MAX_LANE_LENGTH = 51;

    /**
     * Resolve this run's database and make sure it exists. Safe to call more than once.
     *
     * Call from `tests/bootstrap.php`, before any test opens a connection.
     */
    public static function configure(): void
    {
        // An explicitly pinned database is authoritative — do not second-guess it.
        if ('' !== (string) getenv('INVFLOW_DB_NAME')) {
            return;
        }

        $lane = self::sanitizeLane((string) getenv('INVFLUX_TEST_LANE'));
        if ('' === $lane) {
            return; // No lane: the historical shared-database behaviour, unchanged.
        }

        $database = self::PREFIX.$lane;
        self::createIfMissing($database);
        putenv('INVFLOW_DB_NAME='.$database);
        $_ENV['INVFLOW_DB_NAME'] = $database;
        $_SERVER['INVFLOW_DB_NAME'] = $database;
    }

    /**
     * Reduce a lane name to something safe to interpolate into DDL.
     *
     * A database name cannot be a bound parameter, so this is the only thing standing between an
     * environment variable and a `CREATE DATABASE` statement. Whitelist rather than escape.
     */
    private static function sanitizeLane(string $lane): string
    {
        $lane = strtolower(trim($lane));
        $lane = (string) preg_replace('/[^a-z0-9_]+/', '_', $lane);
        $lane = trim($lane, '_');

        return substr($lane, 0, self::MAX_LANE_LENGTH);
    }

    private static function createIfMissing(string $database): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            self::env('INVFLOW_DB_HOST', '127.0.0.1'),
            self::env('INVFLOW_DB_PORT', '33067'),
        );

        try {
            $pdo = new \PDO(
                $dsn,
                self::env('INVFLOW_DB_USER', 'invflux'),
                self::env('INVFLOW_DB_PASS', 'invflux'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\PDOException) {
            // No database server at all. Not this class's problem to report: the suites are
            // infrastructure-gated and each skips itself with its own message.
            return;
        }

        try {
            $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4', $database));
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf(
                'Could not create the lane database `%s`: %s'."\n"
                .'The test user needs rights over the lane-database pattern. As a privileged user:'."\n"
                .'    GRANT ALL PRIVILEGES ON `invflux\_test\_%%`.* TO `%s`@`%%`;',
                $database,
                $e->getMessage(),
                self::env('INVFLOW_DB_USER', 'invflux'),
            ), 0, $e);
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
