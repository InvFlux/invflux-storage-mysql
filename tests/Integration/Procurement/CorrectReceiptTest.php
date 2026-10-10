<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Application\Procurement\CorrectReceipt;
use Nandan108\InvFlux\Application\Procurement\ReceiptLineChange;
use Nandan108\InvFlux\Application\Procurement\ReceiveGoods;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\ProcurementMovementType;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptCorrection;
use Nandan108\InvFlux\Domain\Procurement\ReceiptCorrectionLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\ReceiptCorrectionException;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Registry\BaseMovementType;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Registry\RefTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\Extension\SlotSpaceAssembler;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Schema\Stt;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for {@see CorrectReceipt}: a posted receipt corrected after the fact moves the
 * stock the mistake requires, corrects the average, records before → after, and moves the ordered
 * line's counts — or refuses and moves nothing.
 */
final class CorrectReceiptTest extends TestCase
{
    private const OWNER = BaseMovementType::OWNER_KEY;
    private const LOC = SlotSpaceFactory::DEFAULT_LOCATION_SEED;
    private const TEST_MOVE = 'test_move';

    private ?\PDO $pdo = null;
    private ?MysqlDomainStore $domainStore = null;
    private ?MysqlInventoryStore $inventoryStore = null;
    private ?ReceiveGoods $receiveGoods = null;
    private ?CorrectReceipt $correctReceipt = null;

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
        $factory = new SlotSpaceFactory();
        $inventoryStore->bootstrap($factory->createLayered());
        $domainStore->bootstrap();

        $inventoryStore->addDimensionValues('stt', array_map(
            static fn (string $code): DimensionValueDefinition => new DimensionValueDefinition($code),
            Stt::NATIVE,
        ));
        $inventoryStore->addDimensionValues('loc', [new DimensionValueDefinition(self::LOC, level: 'warehouse')]);
        $inventoryStore->registerMovementTypes(self::OWNER, [
            new MovementTypeDefinition(ProcurementMovementType::PO_RECEIPT, 'Purchase order receipt'),
            new MovementTypeDefinition(ProcurementMovementType::STOCK_INTAKE, 'Stock intake, no order'),
            new MovementTypeDefinition(ProcurementMovementType::RECEIPT_CORRECTION, 'Goods receipt correction'),
            new MovementTypeDefinition(self::TEST_MOVE, 'Test movement'),
        ]);
        $inventoryStore->registerRefTypes('procurement', [
            new RefTypeDefinition(ReceiptCorrection::REF_TYPE, 'Goods Receipt Correction', 'int'),
        ]);

        $assembler = new SlotSpaceAssembler($factory);
        $this->domainStore = $domainStore;
        $this->inventoryStore = $inventoryStore;
        $this->receiveGoods = new ReceiveGoods($domainStore, $inventoryStore, $assembler, 'EUR');
        $this->correctReceipt = new CorrectReceipt($inventoryStore, $assembler, 'EUR');
    }

    /**
     * The plan's worked example, with nothing shipped: 90 on hand at 1.00, a receipt of 10 at 10.00
     * when only 5 arrived. Correcting it must leave exactly what a receipt of 5 would have.
     */
    public function testRemovingUnitsTakesThemOutAndLeavesTheAverageACorrectReceiptWouldHave(): void
    {
        $subjectId = $this->subject();
        $this->intake($subjectId, 90, '1.0000');
        [$receipt, $line, $poLineKey] = $this->poReceipt($subjectId, 10, '10.0000', requested: 10);
        self::assertSame('1.9000', $this->average($subjectId));

        $correction = ($this->correct())(
            (int) $receipt->id,
            [new ReceiptLineChange((int) $line->id, 5, 0, '10.0000')],
            new ActorReference('admin', '1'),
            self::LOC,
            correctedBy: 1,
            note: 'Counted 5 on the pallet',
        );

        self::assertSame(95, $this->onHand($subjectId));
        self::assertSame('1.4737', $this->average($subjectId));

        $stored = ReceiptCorrectionLine::where('correction_id', $correction->id)->first();
        self::assertNotNull($stored);
        self::assertSame([10, 5, 100, '1.9000', '1.4737', '0.0000'], [
            $stored->qty_before, $stored->qty_after, $stored->on_hand_before,
            $stored->cost_before, $stored->cost_after, $stored->cost_variance,
        ]);
        self::assertSame('Counted 5 on the pallet', ReceiptCorrection::getOne((int) $correction->id)?->note);

        // The ordered line is owed the 5 again.
        $poLine = PurchaseOrderLine::getOne($poLineKey);
        self::assertNotNull($poLine);
        self::assertSame(5, $poLine->qty_received);
        self::assertSame(5, $poLine->qty_open);

        // The ledger says why, and points at the correction.
        $last = $this->lastMovement($subjectId);
        self::assertSame(ProcurementMovementType::RECEIPT_CORRECTION, $last['code']);
        self::assertSame(ReceiptCorrection::REF_TYPE, $last['ref_type']);
        self::assertSame((int) $correction->id, (int) $last['ref_int_id']);

        // The receipt itself is untouched.
        self::assertSame(10, ReceiptLine::getOne((int) $line->id)?->qty);
    }

    public function testCommittedUnitsCanBeRemovedLeavingTheOrdersShort(): void
    {
        $subjectId = $this->subject();
        [$receipt, $line] = $this->poReceipt($subjectId, 20, '5.0000', requested: 20);
        // 12 sold and not shipped: 8 free, 12 committed.
        $this->flow('book_recovered', [$subjectId => 12]);

        ($this->correct())(
            (int) $receipt->id,
            [new ReceiptLineChange((int) $line->id, 10, 0, '5.0000')],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        self::assertSame(0, $this->slotQty($subjectId, Stt::ATP));
        self::assertSame(10, $this->slotQty($subjectId, Stt::CTD), 'free stock went first, then 2 committed');
    }

    public function testUnitsNoLongerOnHandAreRefusedAndNothingMoves(): void
    {
        $subjectId = $this->subject();
        [$receipt, $line] = $this->poReceipt($subjectId, 5, '5.0000', requested: 5);
        $this->flow(SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_SUB, [$subjectId => 2]); // 2 left

        try {
            ($this->correct())(
                (int) $receipt->id,
                [new ReceiptLineChange((int) $line->id, 0, 0, '5.0000')],
                new ActorReference('admin', '1'),
                self::LOC,
            );
            self::fail('Expected a refusal.');
        } catch (ReceiptCorrectionException $e) {
            self::assertSame(ReceiptCorrectionException::NOT_ON_HAND, $e->reasonCode);
            self::assertSame(3, $e->available);
        }

        self::assertSame(3, $this->onHand($subjectId));
        self::assertSame(0, ReceiptCorrection::countWhere('receipt_id = ?', [(int) $receipt->id]));
    }

    /**
     * Most of the stock left after the receipt, so the un-blend would go negative: the average stays,
     * and the correction records what the stock could not absorb.
     */
    public function testWhatTheStockCannotAbsorbIsRecordedAsAVariance(): void
    {
        $subjectId = $this->subject();
        $this->intake($subjectId, 90, '1.0000');
        [$receipt, $line] = $this->poReceipt($subjectId, 10, '10.0000', requested: 10);
        $this->flow(SlotSpaceFactory::FLOW_STOCK_ADJUST_ATP_SUB, [$subjectId => 92]); // 8 left

        $correction = ($this->correct())(
            (int) $receipt->id,
            [new ReceiptLineChange((int) $line->id, 5, 0, '10.0000')],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        self::assertSame(3, $this->onHand($subjectId));
        self::assertSame('1.9000', $this->average($subjectId));
        self::assertSame('40.5000', ReceiptCorrectionLine::where('correction_id', $correction->id)->first()?->cost_variance);
    }

    public function testASecondCorrectionStartsFromWhereTheFirstLeftTheLine(): void
    {
        $subjectId = $this->subject();
        [$receipt, $line, $poLineKey] = $this->poReceipt($subjectId, 10, '5.0000', requested: 10);
        $correct = $this->correct();

        $correct((int) $receipt->id, [new ReceiptLineChange((int) $line->id, 8, 2, '5.0000')], new ActorReference('admin', '1'), self::LOC);
        $second = $correct((int) $receipt->id, [new ReceiptLineChange((int) $line->id, 9, 1, '6.0000')], new ActorReference('admin', '1'), self::LOC);

        $stored = ReceiptCorrectionLine::where('correction_id', $second->id)->first();
        self::assertNotNull($stored);
        self::assertSame([8, 9, 2, 1, '5.0000', '6.0000'], [
            $stored->qty_before, $stored->qty_after, $stored->damaged_before, $stored->damaged_after,
            $stored->unit_cost_before, $stored->unit_cost_after,
        ]);
        self::assertSame(9, $this->onHand($subjectId));

        $poLine = PurchaseOrderLine::getOne($poLineKey);
        self::assertNotNull($poLine);
        self::assertSame([9, 1], [$poLine->qty_received, $poLine->qty_damaged]);
    }

    public function testTheSameKeyRecordsTheCorrectionOnce(): void
    {
        $subjectId = $this->subject();
        [$receipt, $line] = $this->poReceipt($subjectId, 10, '5.0000', requested: 10);
        $key = new IdempotencyKey(scope: 'receipt_correction', operationKey: 'receipt:'.(int) $receipt->id.':once');
        $submit = fn (): ReceiptCorrection => ($this->correct())(
            (int) $receipt->id,
            [new ReceiptLineChange((int) $line->id, 4, 0, '5.0000')],
            new ActorReference('admin', '1'),
            self::LOC,
            idempotencyKey: $key,
        );

        $first = $submit();
        $second = $submit();

        self::assertSame((int) $first->id, (int) $second->id);
        self::assertSame(4, $this->onHand($subjectId), 'stock moved once');
    }

    public function testThePreviewWritesNothing(): void
    {
        $subjectId = $this->subject();
        $this->intake($subjectId, 90, '1.0000');
        [$receipt, $line] = $this->poReceipt($subjectId, 10, '10.0000', requested: 10);

        $plan = $this->correct()->preview((int) $receipt->id, [new ReceiptLineChange((int) $line->id, 5, 0, '10.0000')], self::LOC);

        self::assertSame('1.4737', $plan->products[$subjectId]->after->cost);
        self::assertSame(100, $this->onHand($subjectId));
        self::assertSame('1.9000', $this->average($subjectId));
        self::assertSame(0, ReceiptCorrection::countWhere('receipt_id = ?', [(int) $receipt->id]));
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function subject(): int
    {
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();

        return (int) $subject->id;
    }

    private function intake(int $subjectId, int $qty, string $cost): void
    {
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::OpeningBalance]),
            [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => $qty, 'unit_cost_snapshot' => $cost])],
            new ActorReference('admin', '1'),
            self::LOC,
        );
    }

    /** @return array{GoodsReceipt, ReceiptLine, array{po_id: int, subject_id: int}} */
    private function poReceipt(int $subjectId, int $qty, string $cost, int $requested): array
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'Correction Supplier', 'default_currency' => 'EUR']));
        $po = $domain->createPurchaseOrder(PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']));
        [$poLine] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => $requested,
        ])]);

        $receipt = ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $poLine->po_id, 'subject_id' => $subjectId, 'qty' => $qty, 'unit_cost_snapshot' => $cost])],
            new ActorReference('admin', '1'),
            self::LOC,
        );
        $line = ReceiptLine::where('receipt_id', $receipt->id)->first();
        self::assertNotNull($line);

        return [$receipt, $line, ['po_id' => $poLine->po_id, 'subject_id' => $subjectId]];
    }

    /** @param array<int, int> $quantities */
    private function flow(string $flow, array $quantities): void
    {
        $this->inventoryStore()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: self::OWNER,
            movementTypeCode: self::TEST_MOVE,
            flow: $flow,
            subjectIds: array_map(static fn (int $id): SubjectId => new SubjectId($id), array_keys($quantities)),
            params: [SlotSpaceFactory::PARAM_LOCATION => self::LOC],
            actor: new ActorReference('admin', '1'),
            quantitiesBySubjectId: $quantities,
            layerName: SlotSpaceFactory::LAYER_COMMERCIAL,
        ));
    }

    private function onHand(int $subjectId): int
    {
        return $this->inventoryStore()->onHandTotalsForSubjects([new SubjectId($subjectId)])[$subjectId] ?? 0;
    }

    private function slotQty(int $subjectId, string $stt): int
    {
        return (int) $this->pdo()->query(
            "SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_inventory_state s
             JOIN invflux_slotspace ss ON ss.id = s.slot_id
             JOIN invflux_layers ly ON ly.id = ss.layer_id
             WHERE s.subject_id = {$subjectId} AND ss.dim_stt = '{$stt}' AND ly.slug = 'commercial'",
        )->fetchColumn();
    }

    private function average(int $subjectId): ?string
    {
        return SubjectCost::where('subject_id', $subjectId)->first()?->weighted_avg_cost;
    }

    /** @return array{code: string, ref_type: ?string, ref_int_id: ?string} */
    private function lastMovement(int $subjectId): array
    {
        /** @var array{code: string, ref_type: ?string, ref_int_id: ?string} $row */
        $row = $this->pdo()->query(
            "SELECT mt.code, rt.code AS ref_type, l.ref_int_id
             FROM invflux_inventory_ledger l
             JOIN invflux_movement_types mt ON mt.id = l.movement_type_id
             LEFT JOIN invflux_ref_types rt ON rt.id = l.ref_type_id
             WHERE l.subject_id = {$subjectId}
             ORDER BY l.id DESC LIMIT 1",
        )->fetch();

        return $row;
    }

    private function domainStore(): MysqlDomainStore
    {
        \assert($this->domainStore instanceof MysqlDomainStore);

        return $this->domainStore;
    }

    private function receiveGoods(): ReceiveGoods
    {
        \assert($this->receiveGoods instanceof ReceiveGoods);

        return $this->receiveGoods;
    }

    private function correct(): CorrectReceipt
    {
        \assert($this->correctReceipt instanceof CorrectReceipt);

        return $this->correctReceipt;
    }

    private function inventoryStore(): MysqlInventoryStore
    {
        \assert($this->inventoryStore instanceof MysqlInventoryStore);

        return $this->inventoryStore;
    }

    private function pdo(): \PDO
    {
        \assert($this->pdo instanceof \PDO);

        return $this->pdo;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_idempotency',
            'invflux_receipt_correction_lines', 'invflux_receipt_corrections',
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

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function poRefTypeId(): int
    {
        $refType = RefTypeRecord::findOne('code = ?', ['purchase_order']);
        self::assertNotNull($refType);

        return (int) $refType->id;
    }
}
