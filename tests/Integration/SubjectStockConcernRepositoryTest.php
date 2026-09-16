<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Subject\StockConcern;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Domain\Subject\SubjectStockConcern;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Subject\MysqlSubjectStockConcernRepository;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips the `#[BitmaskCaster]` on {@see SubjectStockConcern}
 * through the real `SMALLINT UNSIGNED` column: the repository API is integer-mask-based, the Record
 * carries a typed {@see StockConcern} set, and the caster folds between them — the stored value must
 * remain the raw bitmask (so the SQL `BIT_OR` / `(bits & X)` read paths keep working).
 */
final class SubjectStockConcernRepositoryTest extends TestCase
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

        $domainStore = new MysqlDomainStore($connection, tablePrefix: '', session: $session);
        SchemaFixture::install($connection);
        $domainStore->installReferenceTables();
        $inventoryStore = new MysqlInventoryStore($session);
        $inventoryStore->bootstrap((new SlotSpaceFactory())->createLayered());
        $domainStore->bootstrap();
    }

    public function testUpsertBitsRoundTripsAsATypedSetOverTheRawBitmask(): void
    {
        $subjectId = $this->newSubjectId();
        $repo = new MysqlSubjectStockConcernRepository();

        $mask = StockConcern::Deficit->value | StockConcern::LocAtRisk->value;
        $repo->upsertBits($subjectId, $mask, deficitQty: 12);

        // The Record hydrates the mask into a typed set, in declaration order.
        $row = $repo->findBySubjectId($subjectId);
        self::assertNotNull($row);
        self::assertSame([StockConcern::Deficit, StockConcern::LocAtRisk], $row->bits);
        self::assertSame(12, $row->deficit_qty);

        // The column still stores the raw integer bitmask — the SQL read paths depend on it.
        \assert($this->pdo instanceof \PDO);
        /** @psalm-var mixed $stored */
        $stored = $this->pdo->query(
            "SELECT bits FROM invflux_subject_stock_concerns WHERE subject_id = {$subjectId}",
        )->fetchColumn();
        self::assertSame($mask, (int) $stored);
    }

    public function testUpsertBitsReplacesTheConcernSetOnUpdate(): void
    {
        $subjectId = $this->newSubjectId();
        $repo = new MysqlSubjectStockConcernRepository();

        $repo->upsertBits($subjectId, StockConcern::Deficit->value);
        $repo->upsertBits($subjectId, StockConcern::SubjectInactive->value | StockConcern::QualityHold->value);

        $row = $repo->findBySubjectId($subjectId);
        self::assertNotNull($row);
        self::assertSame([StockConcern::SubjectInactive, StockConcern::QualityHold], $row->bits);
    }

    /**
     * A row written by somebody else is adopted, not duplicated.
     *
     * ⚠ This does **not** reproduce the duplicate-key race it was written for, and it would have
     * passed before the fix too — inserting the row up front means the repository's own read finds
     * it and takes the update path. The real failure needs the row to appear *between* that read
     * and the write, which two `shutdown` drains do to each other and a single-process test cannot:
     * the class is final, so the read cannot be stubbed to return the stale null either.
     *
     * What is left is still worth pinning — the repository tolerates a row it did not create — and
     * the actual guarantee against `Duplicate entry '<subject_id>' for key 'PRIMARY'` rests on the
     * write being an upsert rather than on this test.
     */
    public function testUpsertBitsAdoptsARowWrittenByAnotherWriter(): void
    {
        $subjectId = $this->newSubjectId();
        $repo = new MysqlSubjectStockConcernRepository();

        // The other request's drain got there first.
        SubjectStockConcern::newWith([
            'subject_id'  => $subjectId,
            'bits'        => [StockConcern::Deficit],
            'deficit_qty' => 1,
        ])->save();

        // Ours computed a different set from its own snapshot and writes second.
        $repo->upsertBits($subjectId, StockConcern::QualityHold->value, deficitQty: 7);

        $row = $repo->findBySubjectId($subjectId);
        self::assertNotNull($row);
        self::assertSame([StockConcern::QualityHold], $row->bits);
        self::assertSame(7, $row->deficit_qty);

        // Exactly one row: an upsert, not a second insert.
        \assert($this->pdo instanceof \PDO);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM invflux_subject_stock_concerns WHERE subject_id = {$subjectId}",
        )->fetchColumn());
    }

    /** `detected_at` means *first* seen, so an update must not restamp it; `updated_at` must tick. */
    public function testUpsertBitsKeepsTheOriginalDetectedAtAcrossAnUpdate(): void
    {
        $subjectId = $this->newSubjectId();
        $repo = new MysqlSubjectStockConcernRepository();

        $repo->upsertBits($subjectId, StockConcern::Deficit->value, deficitQty: 1);

        // Backdate the row so a restamp would be unmistakable rather than sub-second.
        \assert($this->pdo instanceof \PDO);
        $this->pdo->exec(
            "UPDATE invflux_subject_stock_concerns
                SET detected_at = '2020-01-01 00:00:00.000000',
                    updated_at  = '2020-01-01 00:00:00.000000'
              WHERE subject_id = {$subjectId}",
        );

        $repo->upsertBits($subjectId, StockConcern::Deficit->value, deficitQty: 99);

        /** @psalm-var array{detected_at: string, updated_at: string}|false $row */
        $row = $this->pdo->query(
            "SELECT detected_at, updated_at FROM invflux_subject_stock_concerns
              WHERE subject_id = {$subjectId}",
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertStringStartsWith('2020-01-01', $row['detected_at']);
        self::assertStringStartsNotWith('2020-01-01', $row['updated_at']);
    }

    /**
     * The operands are cached with the deficit, and a change to them writes even when their
     * difference does not move: `5 − 4` and `4 − 3` are both a deficit of 1, and keeping the old
     * pair would state an equation that is no longer true.
     */
    public function testUpsertBitsWritesWhenOnlyTheDeficitOperandsMove(): void
    {
        $subjectId = $this->newSubjectId();
        $repo = new MysqlSubjectStockConcernRepository();

        $repo->upsertBits($subjectId, StockConcern::Deficit->value, deficitQty: 1, demandQty: 5, ctdQty: 4);
        $repo->upsertBits($subjectId, StockConcern::Deficit->value, deficitQty: 1, demandQty: 4, ctdQty: 3);

        $row = $repo->findBySubjectId($subjectId);
        self::assertNotNull($row);
        self::assertSame(1, $row->deficit_qty);
        self::assertSame(4, $row->demand_qty, 'the no-op guard must compare the operands, not only their difference');
        self::assertSame(3, $row->ctd_qty);
    }

    private function newSubjectId(): int
    {
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        \assert(null !== $subject->id);

        return $subject->id;
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
