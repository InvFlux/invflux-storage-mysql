<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Support;

use Nandan108\Attrecord\Connection;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\InvFlux\Storage\Mysql\Schema\StorageSchema;

/**
 * Create this package's tables for an integration test, the same way a host application does.
 *
 * The stores don't emit DDL — a host converges {@see StorageSchema::records()} once, ahead of
 * every seed — so a test that calls `bootstrap()` against an empty database needs the same step
 * first. Doing it through the real migrator rather than a test-only shortcut is deliberate: the
 * schema these tests run against is then the schema a merchant gets, including the deferred
 * foreign key that breaks the `dimensions` ⇄ `dimension_values` cycle.
 */
final class SchemaFixture
{
    /**
     * Every dimension name any test slot-space uses, across the whole suite.
     *
     * The union rather than a per-test set, deliberately. Convergence runs once in `setUp()`, but a
     * single test may bootstrap several different definitions (one asserts that a *mismatched*
     * definition is rejected), so "the dimensions this test uses" is not even well-defined. Spare
     * `dim_*` columns are inert — defaulted and unread — whereas a missing one fails the insert.
     *
     * @var list<string>
     */
    public const TEST_DIMENSIONS = ['stt', 'loc', 'state', 'status'];

    /**
     * Converge every table this package owns. Idempotent; safe to call per test.
     *
     * @param list<string> $dimensionNames decides the slot space's `dim_<name>` columns
     */
    public static function install(Connection $connection, array $dimensionNames = self::TEST_DIMENSIONS): void
    {
        $migrator = new SchemaMigrator($connection);
        $migrator->apply($migrator->plan(StorageSchema::models($dimensionNames)));
    }
}
