<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Stock\AdjustmentReason;
use Nandan108\InvFlux\Domain\Stock\StockAdjustment;
use Nandan108\InvFlux\Domain\Stock\StockAdjustmentLine;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the A3 adjustment schema (arch-erp-parity §8.2): the configurable reason-code catalog
 * (`invflux_adjustment_reasons`) is installed + seeded with the six built-in codes, each pinned to a
 * fixed core `gl_class`; and a per-subject `invflux_stock_adjustment_lines` row round-trips (its FK to
 * the multi-subject `invflux_stock_adjustments` header resolves).
 */
final class AdjustmentReasonSeedTest extends TestCase
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
        // StockAdjustment mints a 16-byte id on save; a random minter is enough for the round-trip.
        RecordIdentity::setMinter(static fn (): string => random_bytes(16));

        $domainStore = new MysqlDomainStore($connection, tablePrefix: '', session: $session);
        SchemaFixture::install($connection);
        $domainStore->installReferenceTables();
        $inventoryStore = new MysqlInventoryStore($session);
        $inventoryStore->bootstrap((new SlotSpaceFactory())->createLayered());
        $domainStore->bootstrap();
    }

    public function testReasonCatalogSeedsSixBuiltinsPinnedToGlClasses(): void
    {
        self::assertSame(6, AdjustmentReason::countWhere('1 = 1'), 'the six built-in reasons are seeded');

        $missing = AdjustmentReason::where('code', 'missing')->first();
        self::assertNotNull($missing);
        self::assertSame(AdjustmentReason::SIGN_NEGATIVE, $missing->sign);
        self::assertSame('shrinkage_loss', $missing->gl_class);
        self::assertTrue($missing->is_builtin);
        self::assertFalse($missing->requires_note);

        $damaged = AdjustmentReason::where('code', 'damaged')->first();
        self::assertSame('scrap_writeoff', $damaged?->gl_class);

        // The "other" catch-alls require a free-text reason and pin to their sign's catch-all class.
        $otherOut = AdjustmentReason::where('code', 'other_write_off')->first();
        self::assertNotNull($otherOut);
        self::assertSame('deliberate_writeoff', $otherOut->gl_class);
        self::assertTrue($otherOut->requires_note);

        $found = AdjustmentReason::where('code', 'found')->first();
        self::assertNotNull($found);
        self::assertSame(AdjustmentReason::SIGN_POSITIVE, $found->sign);
        self::assertSame('gain', $found->gl_class);

        // Every seeded gl_class is a member of the fixed core set.
        \assert($this->pdo instanceof \PDO);
        /** @var list<string> $glClasses */
        $glClasses = $this->pdo->query('SELECT DISTINCT gl_class FROM invflux_adjustment_reasons')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($glClasses as $gl) {
            self::assertContains($gl, AdjustmentReason::GL_CLASSES);
        }
    }

    public function testStockAdjustmentLineRoundTripsUnderItsHeader(): void
    {
        $header = StockAdjustment::newWith(['origin' => 'on_hand_correction', 'operator_id' => 1]);
        $header->save();
        self::assertNotNull($header->id);

        $line = StockAdjustmentLine::newWith([
            'adjustment_id' => $header->id,
            'subject_id'    => 42,
            'delta'         => -3,
            'unit_cost'     => '5.0000',
        ]);
        $line->save();

        $reloaded = StockAdjustmentLine::where('id', $line->id)->first();
        self::assertNotNull($reloaded);
        self::assertSame($header->id, $reloaded->adjustment_id, 'line FK points at its header');
        self::assertSame(42, $reloaded->subject_id);
        self::assertSame(-3, $reloaded->delta, 'signed delta persists (write-off negative)');
        self::assertSame('5.0000', $reloaded->unit_cost);

        // A null cost is the graceful uncosted-movement state.
        $uncosted = StockAdjustmentLine::newWith(['adjustment_id' => $header->id, 'subject_id' => 43, 'delta' => 2]);
        $uncosted->save();
        self::assertNull(StockAdjustmentLine::where('id', $uncosted->id)->first()?->unit_cost);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_stock_adjustment_lines', 'invflux_stock_adjustments', 'invflux_adjustment_reasons',
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
