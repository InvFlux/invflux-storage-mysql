<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Settings;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\InvFlux\Settings\InvalidSettingValue;
use Nandan108\InvFlux\Settings\ReplicationPolicy;
use Nandan108\InvFlux\Settings\SettingDefinition;
use Nandan108\InvFlux\Settings\SettingsCatalog;
use Nandan108\InvFlux\Settings\SettingValue;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Setting;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Settings\MysqlSettingsStore;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for `MysqlSettingsStore`. Hits a real MySQL via the
 * test-env PDO so the lazy `install()` + UPSERT semantics + effective-policy
 * resolution are exercised end-to-end.
 */
final class MysqlSettingsStoreTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?MysqlSettingsStore $store = null;

    #[\Override]
    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $_SERVER['INVFLOW_DB_HOST'] ?? '127.0.0.1',
                    $_SERVER['INVFLOW_DB_PORT'] ?? '33067',
                    $_SERVER['INVFLOW_DB_NAME'] ?? 'invflux_test',
                ),
                $_SERVER['INVFLOW_DB_USER'] ?? 'invflux',
                $_SERVER['INVFLOW_DB_PASS'] ?? 'invflux',
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('MySQL unavailable: '.$e->getMessage());
        }

        $this->pdo->exec('DROP TABLE IF EXISTS invflux_settings');

        // The table is part of the managed schema now, so it is converged rather than
        // self-installed on first use — which is exactly what makes its shape diffable.
        $connection = new Connection(new PdoMysqlSession($this->pdo), new MysqlDialect());
        $migrator = new SchemaMigrator($connection);
        $migrator->apply($migrator->plan([Setting::class]));

        $catalog = new SettingsCatalog([
            new SettingDefinition('locked.key', 'locked-default', ReplicationPolicy::Local, policyLocked: true),
            new SettingDefinition('open.key', 'open-default', ReplicationPolicy::Replicated, policyLocked: false),
            new SettingDefinition('object.key', ['a' => 1], ReplicationPolicy::Local),
            new SettingDefinition(
                'validated.key',
                0,
                ReplicationPolicy::Local,
                validator: static fn (mixed $v): ?string => \is_int($v) && $v > 0 ? null : 'must be a positive int',
            ),
        ]);
        // The store binds its attrecord writes to the Connection it is handed, so the test can
        // observe them without configuring a global connection.
        $this->store = new MysqlSettingsStore(
            $catalog,
            new Connection(new PdoMysqlSession($this->pdo), new MysqlDialect()),
        );
    }

    public function testGetValueReturnsCatalogDefaultWhenNoRow(): void
    {
        $this->assertSame('locked-default', $this->store()->getValue('locked.key'));
        $this->assertSame('open-default', $this->store()->getValue('open.key'));
        $this->assertSame(['a' => 1], $this->store()->getValue('object.key'));
        $this->assertNull($this->store()->get('locked.key'), 'no row exists yet');
    }

    public function testSetThenGetValueReturnsPersistedValue(): void
    {
        $this->store()->set('open.key', 'persisted');

        $this->assertSame('persisted', $this->store()->getValue('open.key'));

        $row = $this->store()->get('open.key');
        $this->assertInstanceOf(SettingValue::class, $row);
        $this->assertSame('persisted', $row->value);
    }

    public function testLockedSettingIgnoresMerchantChoice(): void
    {
        // Try to override a locked setting — merchant_choice should be discarded.
        $this->store()->set('locked.key', 'set-value', ReplicationPolicy::Replicated);

        $row = $this->store()->get('locked.key');
        $this->assertNotNull($row);
        $this->assertSame(ReplicationPolicy::Local, $row->effectivePolicy, 'locked key keeps catalog policy');
        $this->assertTrue($row->policyLocked);
        $this->assertNull($row->merchantChoice, 'merchant_choice cleared when locked');
    }

    public function testOpenSettingHonorsMerchantOverride(): void
    {
        // Catalog default is Replicated; merchant overrides to Local.
        $this->store()->set('open.key', 'open-value', ReplicationPolicy::Local);

        $row = $this->store()->get('open.key');
        $this->assertNotNull($row);
        $this->assertSame(ReplicationPolicy::Local, $row->effectivePolicy);
        $this->assertFalse($row->policyLocked);
        $this->assertSame(ReplicationPolicy::Local, $row->merchantChoice);
    }

    public function testOpenSettingWithNoMerchantChoiceUsesCatalogDefault(): void
    {
        // No merchant override → effective policy = catalog default (Replicated).
        $this->store()->set('open.key', 'open-value');

        $row = $this->store()->get('open.key');
        $this->assertNotNull($row);
        $this->assertSame(ReplicationPolicy::Replicated, $row->effectivePolicy);
        $this->assertNull($row->merchantChoice);
    }

    public function testSetIsUpsert(): void
    {
        $this->store()->set('open.key', 'first');
        $this->store()->set('open.key', 'second');

        $this->assertSame('second', $this->store()->getValue('open.key'));
        $this->assertCount(1, $this->store()->all());
    }

    public function testSetRejectsUncatalogedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no entry for "missing.key"');
        $this->store()->set('missing.key', 'value');
    }

    public function testDeleteRemovesRowAndFallsBackToCatalogDefault(): void
    {
        $this->store()->set('open.key', 'persisted');
        $this->assertSame('persisted', $this->store()->getValue('open.key'));

        $this->store()->delete('open.key');

        $this->assertNull($this->store()->get('open.key'));
        $this->assertSame('open-default', $this->store()->getValue('open.key'));
    }

    public function testAllReturnsEveryPersistedRowOrderedByName(): void
    {
        $this->store()->set('open.key', 1);
        $this->store()->set('object.key', ['x']);
        $this->store()->set('locked.key', 'L');

        $names = array_map(static fn (SettingValue $v): string => $v->name, $this->store()->all());
        $this->assertSame(['locked.key', 'object.key', 'open.key'], $names);
    }

    public function testObjectValueRoundtrip(): void
    {
        $value = ['x' => 1, 'y' => ['nested' => true]];
        $this->store()->set('object.key', $value);

        $this->assertSame($value, $this->store()->getValue('object.key'));
    }

    public function testSetInvokesValidatorAndRejectsBadValue(): void
    {
        try {
            $this->store()->set('validated.key', -5);
            $this->fail('expected InvalidSettingValue');
        } catch (InvalidSettingValue $e) {
            $this->assertSame('validated.key', $e->name);
            $this->assertSame('must be a positive int', $e->reason);
        }

        // Rejected value never reached the table.
        $this->assertNull($this->store()->get('validated.key'));
    }

    public function testSetInvokesValidatorAndAcceptsGoodValue(): void
    {
        $this->store()->set('validated.key', 7);
        $this->assertSame(7, $this->store()->getValue('validated.key'));
    }

    public function testSetBatchPersistsEveryItemOnSuccess(): void
    {
        $this->store()->setBatch([
            ['name' => 'open.key', 'value' => 'batched'],
            ['name' => 'object.key', 'value' => ['z' => 9]],
            ['name' => 'validated.key', 'value' => 3],
            ['name' => 'open.key', 'value' => 'batched', 'merchantChoice' => ReplicationPolicy::Local],
        ]);

        $this->assertSame('batched', $this->store()->getValue('open.key'));
        $this->assertSame(['z' => 9], $this->store()->getValue('object.key'));
        $this->assertSame(3, $this->store()->getValue('validated.key'));

        $row = $this->store()->get('open.key');
        $this->assertNotNull($row);
        $this->assertSame(ReplicationPolicy::Local, $row->effectivePolicy, 'merchantChoice honored in batch');
    }

    public function testSetBatchIsAtomicWhenAValidatorFails(): void
    {
        // A valid item precedes the failing one; atomicity must roll it back too.
        try {
            $this->store()->setBatch([
                ['name' => 'open.key', 'value' => 'should-roll-back'],
                ['name' => 'validated.key', 'value' => -1],
            ]);
            $this->fail('expected InvalidSettingValue');
        } catch (InvalidSettingValue $e) {
            $this->assertSame('validated.key', $e->name);
        }

        // Nothing persisted — the earlier valid write rolled back with the batch.
        $this->assertCount(0, $this->store()->all());
        $this->assertSame('open-default', $this->store()->getValue('open.key'));
    }

    public function testSetBatchIsAtomicWhenAKeyIsUncataloged(): void
    {
        try {
            $this->store()->setBatch([
                ['name' => 'open.key', 'value' => 'should-roll-back'],
                ['name' => 'missing.key', 'value' => 'nope'],
            ]);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('missing.key', $e->getMessage());
        }

        $this->assertCount(0, $this->store()->all());
    }

    private function store(): MysqlSettingsStore
    {
        return $this->store ?? throw new \LogicException('store not initialised');
    }
}
