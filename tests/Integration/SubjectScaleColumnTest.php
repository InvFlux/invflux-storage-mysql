<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the per-subject `invflux_subjects.scale` column — the fixed-scale quantity-precision
 * override (null inherits the global `config_state.quantity_scale`; substrate for continuous-UoM,
 * unused at Essentials v1.0). Asserts the attrecord DDL producer creates a working column that
 * round-trips, including the null-inherits-global default.
 */
final class SubjectScaleColumnTest extends TestCase
{
    private ?\PDO $pdo = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->env('INVFLOW_DB_HOST', '127.0.0.1'),
                $this->env('INVFLOW_DB_PORT', '33067'),
                $this->env('INVFLOW_DB_NAME', 'invflux_test'),
            ),
            $this->env('INVFLOW_DB_USER', 'invflux'),
            $this->env('INVFLOW_DB_PASS', 'invflux'),
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        );

        $session = new PdoMysqlSession($this->pdo);
        $this->dropTables();

        $connection = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connection);

        // The inventory store creates `invflux_subjects` (with `scale`, straight from the Subject Record's
        // DDL); the domain store's reference/base tables sit alongside it.
        $domainStore = new MysqlDomainStore($connection, tablePrefix: '', session: $session);
        SchemaFixture::install($connection);
        $domainStore->installReferenceTables();
        $inventoryStore = new MysqlInventoryStore($session);
        $inventoryStore->bootstrap((new SlotSpaceFactory())->createLayered());
        $domainStore->bootstrap();
    }

    public function testSubjectScaleColumnPersistsAndDefaultsToNull(): void
    {
        $withScale = Subject::newWith(['kind' => SubjectKind::Unit, 'scale' => 3]);
        $withScale->save();
        $reloaded = Subject::where('id', $withScale->id)->first();
        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->scale, 'per-subject scale override persists');

        $noScale = Subject::newWith(['kind' => SubjectKind::Unit]);
        $noScale->save();
        $reloadedNull = Subject::where('id', $noScale->id)->first();
        self::assertNotNull($reloadedNull);
        self::assertNull($reloadedNull->scale, 'null scale inherits the global quantity_scale');
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_po_events', 'invflux_receipt_lines', 'invflux_goods_receipts',
            'invflux_po_lines', 'invflux_pos', 'invflux_document_parties', 'invflux_po_number_counters',
            'invflux_subject_cost_metadata', 'invflux_supplier_products', 'invflux_suppliers',
            'invflux_subject_worksheet_items', 'invflux_subject_worksheets',
            'invflux_subject_stock_concerns', 'invflux_inventory_ledger',
            'invflux_inventory_state', 'invflux_subject_identifiers', 'invflux_subjects',
            'invflux_identifier_types', 'invflux_systems', 'invflux_schema_ledger',
            'invflux_config_state', 'invflux_adhoc_stock_adjustments', 'invflux_actors',
            'invflux_actor_types', 'invflux_surfaces', 'invflux_surface_types', 'invflux_ref_types',
            'invflux_movement_types', 'invflux_slotspace', 'invflux_dimension_values',
            'invflux_dimensions', 'invflux_layers',
        ];

        \assert($this->pdo instanceof \PDO);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
