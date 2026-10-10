<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Application\Costing\WeightedAverage;
use Nandan108\InvFlux\Application\Procurement\ReceiveGoods;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\ProcurementMovementType;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\ReceiptCostPolicyException;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Registry\BaseMovementType;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
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
 * End-to-end coverage for the {@see ReceiveGoods} use-case: recording a
 * goods receipt and posting the resulting `po_receipt` stock movement (nil → oh.atp) with
 * the PO carried on the ledger's ref_int_id lane.
 */
final class ReceiveGoodsTest extends TestCase
{
    private const OWNER = BaseMovementType::OWNER_KEY;
    /** Where these receipts land. Registered in setUp() and passed to every receive call. */
    private const LOC = SlotSpaceFactory::DEFAULT_LOCATION_SEED;

    private ?\PDO $pdo = null;
    private ?MysqlDomainStore $domainStore = null;
    private ?MysqlInventoryStore $inventoryStore = null;
    private ?ReceiveGoods $receiveGoods = null;

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

        // `stt` is a sharedRef dimension now, so its native states are registered at bootstrap
        // (as BootstrapInvFlux::ensureNativeStates does) rather than baked into the definition.
        $inventoryStore->addDimensionValues('stt', array_map(
            static fn (string $code): DimensionValueDefinition => new DimensionValueDefinition($code),
            Stt::NATIVE,
        ));
        // The receipt writes into oh.atp, so the warehouse loc must exist (creates the slots).
        $inventoryStore->addDimensionValues('loc', [new DimensionValueDefinition(self::LOC, level: 'warehouse')]);
        $inventoryStore->registerMovementTypes(self::OWNER, [
            new MovementTypeDefinition(ProcurementMovementType::PO_RECEIPT, 'Purchase order receipt'),
            new MovementTypeDefinition(ProcurementMovementType::STOCK_INTAKE, 'Stock intake, no order'),
        ]);

        $this->domainStore = $domainStore;
        $this->inventoryStore = $inventoryStore;
        $this->receiveGoods = new ReceiveGoods($domainStore, $inventoryStore, new SlotSpaceAssembler($factory), 'EUR');
    }

    /**
     * The area a cost row must name, asked of the store rather than written as a literal — it is a
     * surrogate id, so the value it takes is whatever order the seeds ran in, and a test that
     * hardcodes one passes until somebody registers a dimension value earlier.
     */
    private function costArea(): int
    {
        return $this->inventoryStore()->dimensionValueId('loc', self::LOC);
    }

    /**
     * The bug this guards: a goods receipt used to be two client round trips, so a failure between
     * them left stock moved while the order still said it was expecting the goods — and the retry
     * that followed passed the same status check and received the delivery a second time. One key,
     * one delivery, however many times it is submitted.
     */
    public function testTheSameReceiptKeyMovesStockOnlyOnce(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'Retry Supplier', 'default_currency' => 'EUR']));
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $domain->createPurchaseOrder(PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']));
        [$line] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 10,
        ])]);

        $key = new IdempotencyKey(scope: 'po_receipt', operationKey: 'po:'.(int) $po->id.':receive:once');
        $submit = fn (): GoodsReceipt => ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 6, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
            $key,
        );

        $first = $submit();
        $second = $submit();

        self::assertSame((int) $first->id, (int) $second->id, 'the replay returns the receipt the first submission created');
        // The pure read a caller uses to spot a replay *before* its own preconditions reject it —
        // a receipt moves the order out of reception, so the retry would otherwise be told no
        // session is open, i.e. that nothing happened, when the delivery is already on the books.
        self::assertTrue($this->receiveGoods()->alreadyCompleted($key));
        self::assertFalse(
            $this->receiveGoods()->alreadyCompleted(new IdempotencyKey(scope: 'po_receipt', operationKey: 'po:'.(int) $po->id.':receive:never-sent')),
        );
        self::assertSame(1, GoodsReceipt::countWhere('source_id = ?', [(int) $po->id]), 'exactly one receipt on file');

        // The rollup bumped once, not twice — the assertion that would have caught the original bug.
        $reloaded = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($reloaded);
        self::assertSame(6, $reloaded->qty_received);
        self::assertSame(4, $reloaded->qty_open);

        $onHand = (int) $this->pdo()->query(
            "SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_inventory_state s
             JOIN invflux_slotspace ss ON ss.id = s.slot_id
             JOIN invflux_layers ly ON ly.id = ss.layer_id
             WHERE s.subject_id = {$subjectId} AND ss.dim_stt = 'atp' AND ly.slug = 'commercial'",
        )->fetchColumn();
        self::assertSame(6, $onHand, 'stock moved once');
    }

    /** A different intent — a genuinely separate delivery — is not a replay, even with identical lines. */
    public function testAByteIdenticalSecondDeliveryUnderItsOwnKeyIsRecorded(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'Split Supplier', 'default_currency' => 'EUR']));
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $domain->createPurchaseOrder(PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']));
        [$line] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 10,
        ])]);

        $submit = fn (string $token): GoodsReceipt => ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 5, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
            new IdempotencyKey(scope: 'po_receipt', operationKey: 'po:'.(int) $po->id.':receive:'.$token),
        );

        // Same lines, same quantity, same cost — two lorries, not one submitted twice. A key derived
        // from the payload would have swallowed the second, losing stock that physically arrived.
        $submit('first-click');
        $submit('second-click');

        self::assertSame(2, GoodsReceipt::countWhere('source_id = ?', [(int) $po->id]));
        $reloaded = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($reloaded);
        self::assertSame(10, $reloaded->qty_received, 'both deliveries counted');
    }

    /**
     * The case the polymorphic source exists for: stock arriving with no order behind it — an
     * opening balance. It must still be a *receipt* (a costed layer, dated, with provenance) rather
     * than a quantity correction, so the assertions are that the movement and the cost land exactly
     * as they do for a PO receipt, and that nothing procurement-shaped is touched on the way.
     */
    public function testReceiveGoodsAcceptsAnIntakeWithNoSourceDocument(): void
    {
        $this->domainStore();
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $receipt = ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::OpeningBalance]),
            [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => 7, 'unit_cost_snapshot' => '4.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        self::assertNotNull($receipt->id);
        self::assertFalse($receipt->hasSource(), 'nothing ordered these goods');
        self::assertSame(ReceiptReason::OpeningBalance, $receipt->reason);

        $stored = GoodsReceipt::where('id', $receipt->id)->first();
        self::assertNotNull($stored);
        self::assertNull($stored->source_ref_type_id, 'a source-less intake stays source-less on the way to the database');
        self::assertNull($stored->source_id);

        $line = ReceiptLine::where('receipt_id', $receipt->id)->first();
        self::assertNotNull($line);
        self::assertNull($line->po_id, 'no ordered line to count against');

        // The stock moved in exactly as a PO receipt's would — same flow, same slot.
        $onHand = (int) $this->pdo()->query(
            "SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_inventory_state s
             JOIN invflux_slotspace ss ON ss.id = s.slot_id
             JOIN invflux_layers ly ON ly.id = ss.layer_id
             WHERE s.subject_id = {$subjectId} AND ss.dim_stt = 'atp' AND ly.slug = 'commercial'",
        )->fetchColumn();
        self::assertSame(7, $onHand);

        // And it carried a real cost layer — the whole reason this is a receipt and not a correction.
        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost, 'an intake with a stated cost establishes the weighted average');
        self::assertSame(4.0, (float) $cost->weighted_avg_cost);

        // The ledger has to be able to say these goods answered no order. Same physical flow as a PO
        // receipt, different movement type — otherwise an opening balance counts toward what
        // suppliers delivered, and no later reading can separate them again.
        self::assertSame('stock_intake', $this->lastMovementCode($subjectId));
    }

    /**
     * A posted receipt reads back as a document: itself by id, and its lines in the order written —
     * what the receipt screen shows. An id that names nothing reads as nothing, not as an error.
     */
    public function testAPostedReceiptReadsBackWithItsLines(): void
    {
        $store = $this->domainStore();
        $ids = [];
        foreach ([1, 2] as $_) {
            $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
            $subject->save();
            $ids[] = (int) $subject->id;
        }

        $receipt = ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::PurchaseWithoutOrder, 'fx_rate' => '0.90000000']),
            [
                ReceiptLine::newWith(['subject_id' => $ids[1], 'qty' => 3, 'damaged_qty' => 1, 'unit_cost_snapshot' => '20.0000']),
                ReceiptLine::newWith(['subject_id' => $ids[0], 'qty' => 5, 'unit_cost_snapshot' => '10.0000']),
            ],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $read = $store->findGoodsReceipt((int) $receipt->id);
        self::assertNotNull($read);
        self::assertSame(ReceiptReason::PurchaseWithoutOrder, $read->reason);
        self::assertSame('0.90000000', $read->fx_rate);
        self::assertSame(
            [[$ids[1], 3, 1, '20.0000'], [$ids[0], 5, 0, '10.0000']],
            array_map(
                static fn (ReceiptLine $l): array => [$l->subject_id, $l->qty, $l->damaged_qty, $l->unit_cost_snapshot],
                $store->goodsReceiptLines((int) $receipt->id),
            ),
        );
        self::assertNull($store->findGoodsReceipt(999999));
        self::assertSame([], $store->goodsReceiptLines(999999));
    }

    /**
     * Stock found on a shelf cost *something*, but nobody can say what — so the product's standing
     * valuation stands in. The point of covering it is that the alternative is silent: an uncosted
     * line moves the stock perfectly well and simply drops out of the weighted average, leaving units
     * on hand that the books value at nothing.
     */
    public function testFoundStockValuesItselfAtTheStandingSeedCost(): void
    {
        $this->domainStore();
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        SubjectCost::newWith([
            'subject_id'   => $subjectId,
            'cost_area_id' => $this->costArea(),
            'seed_cost'    => '3.5000',
        ])->save();

        ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::FoundStock]),
            [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => 4, 'unit_cost_snapshot' => null])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $line = ReceiptLine::where('subject_id', $subjectId)->first();
        self::assertNotNull($line);
        self::assertSame(3.5, (float) $line->unit_cost_snapshot, 'the seed valuation was written onto the line');

        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        self::assertSame(3.5, (float) $cost->weighted_avg_cost, 'and it established the weighted average');
        self::assertSame(3.5, (float) $cost->seed_cost, 'the baseline itself is untouched — the receipt owns the WAC, not the seed');
    }

    /**
     * A donated unit cost nothing, and zero is the fact rather than a missing value — so it is
     * recorded, and it genuinely lowers what the stock on hand is worth per unit. Leaving the line
     * uncosted instead would drop it out of the average entirely and quietly overstate the rest.
     */
    public function testASampleEntersAtZeroAndDilutesTheAverage(): void
    {
        $this->domainStore();
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        // Buy 10 at 5.00, then receive 10 free ones.
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::PurchaseWithoutOrder]),
            [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => 10, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::SampleOrDonation]),
            [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => 10, 'unit_cost_snapshot' => null])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        // (10·5 + 10·0) / 20 = 2.50 — the free units are in the divisor, which is the whole point.
        self::assertSame(2.5, (float) $cost->weighted_avg_cost);
        self::assertSame(20, $this->forSaleQty($subjectId));
    }

    /**
     * A receipt records what its weighting read — the product's on-hand just before it and the cost it
     * was weighted against — on every line of that product. From those, its own lines and the same
     * public functions the posting used, the average it left is reproduced to the last decimal; a
     * product receiving its first stock carries no cost before, and the receipt's cost sets it.
     */
    public function testAReceiptRecordsWhatItsWeightingReadAndReproducesTheAverage(): void
    {
        $this->domainStore();
        $a = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $a->save();
        $b = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $b->save();
        $aId = (int) $a->id;
        $bId = (int) $b->id;

        ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::PurchaseWithoutOrder]),
            [ReceiptLine::newWith(['subject_id' => $aId, 'qty' => 10, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );
        // Two lines of A on one receipt, and B's first stock.
        $second = ($this->receiveGoods())(
            GoodsReceipt::newWith(['reason' => ReceiptReason::PurchaseWithoutOrder]),
            [
                ReceiptLine::newWith(['subject_id' => $aId, 'qty' => 3, 'unit_cost_snapshot' => '8.0000']),
                ReceiptLine::newWith(['subject_id' => $aId, 'qty' => 2, 'unit_cost_snapshot' => '9.0000']),
                ReceiptLine::newWith(['subject_id' => $bId, 'qty' => 4, 'unit_cost_snapshot' => '6.0000']),
            ],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $lines = [...ReceiptLine::find('receipt_id = ?', [(int) $second->id], 'ORDER BY id')];
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            if ($aId === $line->subject_id) {
                self::assertSame(10, $line->on_hand_before, 'every line of A carries the product-level figure');
                self::assertSame('5.0000', $line->cost_before);
            } else {
                self::assertSame(0, $line->on_hand_before);
                self::assertNull($line->cost_before, 'a first receipt was weighted against nothing');
            }
        }

        // Reproduce each product's average from the receipt alone, through the posting's own functions.
        [, $costedQty, $costedValue] = ReceiveGoods::aggregateBySubject($lines, 1.0);
        foreach ([$aId => $lines[0], $bId => $lines[2]] as $subjectId => $line) {
            $stored = SubjectCost::where('subject_id', $subjectId)->first()?->weighted_avg_cost;
            self::assertSame(
                $stored,
                WeightedAverage::blend((int) $line->on_hand_before, $line->cost_before, $costedQty[$subjectId], $costedValue[$subjectId]),
            );
        }
        // (10·5 + 3·8 + 2·9) / 15 = 6.1333; B's own 6.0000.
        self::assertSame('6.1333', SubjectCost::where('subject_id', $aId)->first()?->weighted_avg_cost);
        self::assertSame('6.0000', SubjectCost::where('subject_id', $bId)->first()?->weighted_avg_cost);
    }

    /**
     * The refusal that keeps a source-less intake from being a way around costing: an opening balance
     * whose lines state no cost is rejected outright, and nothing about it reaches the database.
     */
    public function testAnOpeningBalanceWithNoCostIsRefusedAndMovesNothing(): void
    {
        $this->domainStore();
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $this->expectException(ReceiptCostPolicyException::class);

        try {
            ($this->receiveGoods())(
                GoodsReceipt::newWith(['reason' => ReceiptReason::OpeningBalance]),
                [ReceiptLine::newWith(['subject_id' => $subjectId, 'qty' => 9, 'unit_cost_snapshot' => null])],
                new ActorReference('admin', '1'),
                self::LOC,
            );
        } finally {
            self::assertSame(0, $this->forSaleQty($subjectId), 'refused before anything moved');
            $receipts = (int) $this->pdo()->query('SELECT COUNT(*) FROM invflux_goods_receipts')->fetchColumn();
            self::assertSame(0, $receipts, 'and before anything was written');
        }
    }

    /** The movement-type code of the most recent ledger row for a subject. */
    private function lastMovementCode(int $subjectId): string
    {
        return (string) $this->pdo()->query(
            "SELECT mt.code
             FROM invflux_inventory_ledger l
             JOIN invflux_movement_types mt ON mt.id = l.movement_type_id
             WHERE l.subject_id = {$subjectId}
             ORDER BY l.id DESC LIMIT 1",
        )->fetchColumn();
    }

    public function testReceiveGoodsRecordsReceiptAndPostsPoReceiptMovement(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'GR Supplier', 'default_currency' => 'EUR']));
        // `ivfx_governed` is opt-in and defaults to false, and the store *silently skips*
        // ungoverned subjects when persisting movements — a receipt against one is a no-op that
        // still reports ok, so the failure lands on a downstream quantity assertion rather than
        // anywhere near the flag. Every goods-receipt test here needs a governed subject.
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $domain->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        [$line] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 10,
        ])]);

        // Receive 6 of 10.
        $receipt = ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 6, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        // Domain side: qty_received bumped, qty_open recomputed.
        $reloaded = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($reloaded);
        self::assertSame(6, $reloaded->qty_received);
        self::assertSame(4, $reloaded->qty_open);

        // Stock created in for-sale at the warehouse (commercial layer).
        $fs = (int) $this->pdo()->query(
            "SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_inventory_state s
             JOIN invflux_slotspace ss ON ss.id = s.slot_id
             JOIN invflux_layers ly ON ly.id = ss.layer_id
             WHERE s.subject_id = {$subjectId} AND ss.dim_stt = 'atp' AND ly.slug = 'commercial'",
        )->fetchColumn();
        self::assertSame(6, $fs, 'po_receipt wrote +6 into oh.atp');

        // Ledger: a po_receipt movement carrying the GOODS RECEIPT (the proximate source doc for the cost
        // join), not the PO, on the ref_int_id lane.
        $row = $this->pdo()->query(
            "SELECT mt.code AS mtype, rt.code AS reftype, l.ref_int_id, l.ref_id, l.quantity
             FROM invflux_inventory_ledger l
             JOIN invflux_movement_types mt ON mt.id = l.movement_type_id
             JOIN invflux_ref_types rt ON rt.id = l.ref_type_id
             WHERE l.subject_id = {$subjectId}
             ORDER BY l.id DESC LIMIT 1",
        )->fetch();
        self::assertIsArray($row);
        self::assertSame('po_receipt', $row['mtype']);
        // The ref_type ('goods_receipt' vs the former 'purchase_order') is the load-bearing assertion —
        // po.id and receipt.id can coincide (independent auto-increments, both 1 in a fresh DB), so the
        // id alone wouldn't prove the tightening; the ref_type code does.
        self::assertSame('goods_receipt', $row['reftype'], 'ref points at the goods_receipt, not the PO');
        self::assertSame((int) $receipt->id, (int) $row['ref_int_id'], 'goods_receipt id on the ref_int_id lane');
        self::assertNull($row['ref_id'], 'BINARY(16) lane left null');
        self::assertSame(6, (int) $row['quantity']);

        // WAC (0g): first receipt establishes the weighted-average cost. seed_cost is the merchant-set
        // valuation baseline — the receipt machine owns the WAC, not the baseline, so a receipt never writes
        // it; a null seed_cost cleanly means "merchant never set one" (see core 2bd838d).
        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        self::assertSame('5.0000', $cost->weighted_avg_cost);
        self::assertSame('EUR', $cost->cost_currency, 'WAC is grounded in the store base currency');
        self::assertNull($cost->seed_cost, 'receipt does not set seed_cost (merchant-set baseline)');

        // Second receipt: 4 @ 10.0000 → WAC = (6·5 + 4·10) / 10 = 7.0000.
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 4, 'unit_cost_snapshot' => '10.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $afterSecond = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($afterSecond);
        self::assertSame(10, $afterSecond->qty_received);
        self::assertSame(0, $afterSecond->qty_open);

        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        self::assertSame('7.0000', $cost->weighted_avg_cost, 'weighted average across both receipts');
        self::assertNull($cost->seed_cost, 'seed_cost stays null — receipts never write the merchant baseline');
    }

    /**
     * Damaged units on a receipt bump the separate `qty_damaged` rollup, never `qty_received` (good only),
     * and only the good qty reaches stock. A second receipt accumulates both rollups.
     */
    public function testReceiveGoodsAccumulatesDamagedRollupSeparately(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'GR Supplier', 'default_currency' => 'EUR']));
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $domain->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        [$line] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 20,
        ])]);

        // First delivery: 8 arrived, 3 of them damaged → 5 good into stock.
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 5, 'damaged_qty' => 3, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $afterFirst = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($afterFirst);
        self::assertSame(5, $afterFirst->qty_received, 'qty_received counts good units only');
        self::assertSame(3, $afterFirst->qty_damaged, 'qty_damaged rollup carries the damaged units');
        self::assertSame(15, $afterFirst->qty_open, 'qty_open nets the good qty (damaged does not count as received)');
        self::assertSame(5, $this->forSaleQty($subjectId), 'only good units move into oh.atp');

        // Second delivery: 4 arrived, 1 damaged → both rollups accumulate (9 good, 4 damaged).
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 3, 'damaged_qty' => 1, 'unit_cost_snapshot' => '5.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $afterSecond = PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id]);
        self::assertNotNull($afterSecond);
        self::assertSame(8, $afterSecond->qty_received, 'good rollup accumulates across deliveries');
        self::assertSame(4, $afterSecond->qty_damaged, 'damaged rollup accumulates across deliveries');
    }

    /**
     * A foreign-currency delivery converts its line costs to the store base at the receipt's fx_rate
     * before feeding the WAC: unit_cost_snapshot stays in supplier currency (audit), weighted_avg_cost
     * lands in base. A null fx_rate is the identity case (1.0).
     */
    public function testReceiveGoodsConvertsForeignLineCostToBaseAtReceiptFxRate(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'USD Supplier', 'default_currency' => 'USD']));
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $domain->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'USD']),
        );
        [$line] = $domain->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 10,
        ])]);

        // Receive 4 @ 10.00 USD, delivery rate 1.25 base/USD → base unit cost 12.50 → WAC 12.5000.
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id, 'fx_rate' => '1.25000000']),
            [ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $subjectId, 'qty' => 4, 'unit_cost_snapshot' => '10.0000'])],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        self::assertSame('12.5000', $cost->weighted_avg_cost, 'line cost converted to base at the receipt fx_rate');
    }

    public function testReceiveGoodsBatchesMultiSubjectReceiptInOneMovement(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'Batch Supplier', 'default_currency' => 'EUR']));

        // Two brand-new, zero-stock subjects — the write-in must post for both even though
        // neither has any inventory state yet.
        $subjectA = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subjectA->save();
        $a = (int) $subjectA->id;
        $subjectB = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subjectB->save();
        $b = (int) $subjectB->id;

        $po = $domain->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        [$lineA, $lineB] = $domain->savePurchaseOrderLines([
            PurchaseOrderLine::newWith([
                'po_id' => $po->id, 'subject_id' => $a, 'qty_requested' => 10]),
            PurchaseOrderLine::newWith([
                'po_id' => $po->id, 'subject_id' => $b, 'qty_requested' => 10]),
        ]);

        // One receive call covering both lines → one batch movement, one bulk qty_received
        // update, one bulk WAC save.
        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [
                ReceiptLine::newWith(['po_id' => $lineA->po_id, 'subject_id' => $a, 'qty' => 6, 'unit_cost_snapshot' => '5.0000']),
                ReceiptLine::newWith(['po_id' => $lineB->po_id, 'subject_id' => $b, 'qty' => 3, 'unit_cost_snapshot' => '8.0000']),
            ],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        // Both PO lines bumped (bulk qty_received).
        self::assertSame(6, PurchaseOrderLine::getOne(['po_id' => $lineA->po_id, 'subject_id' => $lineA->subject_id])?->qty_received);
        self::assertSame(3, PurchaseOrderLine::getOne(['po_id' => $lineB->po_id, 'subject_id' => $lineB->subject_id])?->qty_received);

        // Both subjects got their stock in oh.atp.
        self::assertSame(6, $this->forSaleQty($a));
        self::assertSame(3, $this->forSaleQty($b));

        // Per-subject WAC established from each subject's own cost.
        self::assertSame('5.0000', SubjectCost::where('subject_id', $a)->first()?->weighted_avg_cost);
        self::assertSame('8.0000', SubjectCost::where('subject_id', $b)->first()?->weighted_avg_cost);

        // One po_receipt ledger row per subject (the batch groups by subject).
        $ledgerCount = (int) $this->pdo()->query(
            "SELECT COUNT(*) FROM invflux_inventory_ledger l
             JOIN invflux_movement_types mt ON mt.id = l.movement_type_id
             WHERE mt.code = 'po_receipt' AND l.ref_int_id = {$po->id}",
        )->fetchColumn();
        self::assertSame(2, $ledgerCount, 'one po_receipt ledger row per subject in the batch');
    }

    public function testReceiveGoodsAggregatesSameSubjectLinesIntoOneMovement(): void
    {
        $domain = $this->domainStore();
        $supplier = $domain->createSupplier(Supplier::newWith(['name' => 'Agg Supplier', 'default_currency' => 'EUR']));
        $subject = Subject::newWith(['kind' => SubjectKind::Unit, 'ivfx_governed' => true]);
        $subject->save();
        $sid = (int) $subject->id;

        $po = $domain->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        // One ordered line (an order carries one line per product), received as two receipt lines
        // at different unit costs — two pallets of the same product, say.
        [$line] = $domain->savePurchaseOrderLines([
            PurchaseOrderLine::newWith([
                'po_id' => $po->id, 'subject_id' => $sid, 'qty_requested' => 20]),
        ]);

        ($this->receiveGoods())(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [
                ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $sid, 'qty' => 4, 'unit_cost_snapshot' => '5.0000']),
                ReceiptLine::newWith(['po_id' => $line->po_id, 'subject_id' => $sid, 'qty' => 6, 'unit_cost_snapshot' => '10.0000']),
            ],
            new ActorReference('admin', '1'),
            self::LOC,
        );

        // Both receipt lines roll up onto the one ordered line.
        self::assertSame(10, PurchaseOrderLine::getOne(['po_id' => $line->po_id, 'subject_id' => $line->subject_id])?->qty_received);

        // Stock: 4 + 6 = 10 into oh.atp.
        self::assertSame(10, $this->forSaleQty($sid));

        // WAC is value-weighted across the two lines: (4·5 + 6·10) / 10 = 8.0000.
        self::assertSame('8.0000', SubjectCost::where('subject_id', $sid)->first()?->weighted_avg_cost);

        // The batch groups by subject → a single po_receipt ledger row (qty 10), not one
        // row per receipt line.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->pdo()->query(
            "SELECT l.quantity FROM invflux_inventory_ledger l
             JOIN invflux_movement_types mt ON mt.id = l.movement_type_id
             WHERE mt.code = 'po_receipt' AND l.subject_id = {$sid}",
        )->fetchAll();
        self::assertCount(1, $rows, 'same-subject lines aggregate into one ledger row');
        self::assertSame(10, (int) $rows[0]['quantity']);
    }

    private function forSaleQty(int $subjectId): int
    {
        return (int) $this->pdo()->query(
            "SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_inventory_state s
             JOIN invflux_slotspace ss ON ss.id = s.slot_id
             JOIN invflux_layers ly ON ly.id = ss.layer_id
             WHERE s.subject_id = {$subjectId} AND ss.dim_stt = 'atp' AND ly.slug = 'commercial'",
        )->fetchColumn();
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
            // Idempotency claims outlive a torn-down schema otherwise: the receipts table is
            // recreated per test while a surviving claim replays, so the second run of a keyed
            // receipt would create nothing and the assertion would blame the code.
            'invflux_idempotency',
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

    /**
     * The registry id for `purchase_order`. A receipt names the *kind* of document it answers by
     * registry id, so a test that receives against an order resolves it the same way production does
     * rather than hard-coding a seed position.
     */
    private function poRefTypeId(): int
    {
        $refType = RefTypeRecord::findOne('code = ?', ['purchase_order']);
        self::assertNotNull($refType, 'the purchase_order ref type must be seeded');

        return (int) $refType->id;
    }
}
