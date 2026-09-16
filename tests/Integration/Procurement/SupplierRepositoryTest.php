<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Application\Procurement\TransitionPurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\InvalidPurchaseOrderTransition;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoNumberCounter;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\SequentialScheme;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Procurement\SupplierContact;
use Nandan108\InvFlux\Domain\Procurement\SupplierProduct;
use Nandan108\InvFlux\Domain\Procurement\SupplierStatus;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\ActorTypeRecord;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the supplier aggregate: the
 * actor-minting create flow, lookups, and uncapped multi-supplier-per-subject
 * links.
 */
final class SupplierRepositoryTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?MysqlDomainStore $domainStore = null;

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

        $this->domainStore = new MysqlDomainStore($connection, tablePrefix: '', session: $session);
        SchemaFixture::install($connection);
        $this->domainStore->installReferenceTables();

        // Inventory store creates invflux_subjects (which the supplier tables FK into),
        // then the domain store installs the procurement tables.
        $inventoryStore = new MysqlInventoryStore($session);
        $inventoryStore->bootstrap((new SlotSpaceFactory())->createLayered());
        $this->domainStore->bootstrap();
    }

    public function testCreateSupplierMintsSupplierActorAndPersists(): void
    {
        $saved = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'Acme Imports', 'default_currency' => 'EUR']),
        );

        self::assertNotNull($saved->id);
        self::assertGreaterThan(0, $saved->actor_id, 'actor_id is assigned by the repository');

        $actor = ActorRecord::where('id', $saved->actor_id)->first();
        self::assertNotNull($actor, 'a supplier actor row was minted');
        self::assertNotSame('', $actor->actor_ref, 'opaque actor_ref minted (ULID)');

        $type = ActorTypeRecord::where('id', $actor->actor_type_id)->first();
        self::assertNotNull($type);
        self::assertSame('supplier', $type->code, "minted under the 'supplier' actor type");
    }

    public function testFindAndListSuppliersOrderedByName(): void
    {
        $beta = $this->store()->createSupplier(Supplier::newWith(['name' => 'Beta']));
        $this->store()->createSupplier(Supplier::newWith(['name' => 'Alpha']));

        self::assertSame('Beta', $this->store()->findSupplier((int) $beta->id)?->name);
        self::assertNull($this->store()->findSupplier(999_999), 'missing id returns null');

        $names = array_map(
            static fn (Supplier $s): string => $s->name,
            $this->store()->listSuppliers(),
        );
        self::assertSame(['Alpha', 'Beta'], $names, 'listed alphabetically by name');
    }

    public function testMultipleSuppliersPerSubjectAreUncapped(): void
    {
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $s1 = $this->store()->createSupplier(Supplier::newWith(['name' => 'S1']));
        $s2 = $this->store()->createSupplier(Supplier::newWith(['name' => 'S2']));

        $this->store()->saveSupplierProduct(SupplierProduct::newWith([
            'supplier_id' => $s1->id, 'subject_id' => $subjectId, 'unit_price' => '10.0000', 'priority' => 0,
        ]));
        $this->store()->saveSupplierProduct(SupplierProduct::newWith([
            'supplier_id' => $s2->id, 'subject_id' => $subjectId, 'unit_price' => '9.5000', 'priority' => 1,
        ]));

        self::assertCount(2, $this->store()->supplierProductsForSubject($subjectId));
        self::assertCount(1, $this->store()->supplierProductsForSupplier((int) $s1->id));
    }

    public function testSupplierExtendedFieldsRoundTrip(): void
    {
        $saved = $this->store()->createSupplier(Supplier::newWith([
            'name'             => 'Full Supplier',
            'code'             => 'FULL-01',
            'tax_number'       => 'FR12345678901',
            'website'          => 'https://example.test',
            'ordering_url'     => 'https://orders.example.test',
            'address_1'        => '1 Rue de la Paix',
            'city'             => 'Paris',
            'state'            => 'IDF',
            'postcode'         => '75002',
            'country'          => 'FR',
            'default_tax_rate' => '20.00',
            'assigned_to'      => 7,
        ]));

        $fresh = $this->store()->findSupplier((int) $saved->id);
        self::assertNotNull($fresh);
        self::assertSame('FULL-01', $fresh->code);
        self::assertSame('FR12345678901', $fresh->tax_number);
        self::assertSame('https://orders.example.test', $fresh->ordering_url);
        self::assertSame('Paris', $fresh->city);
        self::assertSame('FR', $fresh->country);
        self::assertSame('20.00', $fresh->default_tax_rate);
        self::assertSame(7, $fresh->assigned_to);
    }

    public function testSupplierCodeIsUniqueButNullsCoexist(): void
    {
        // Unlimited suppliers may have no code (the unique index treats NULLs as distinct).
        $this->store()->createSupplier(Supplier::newWith(['name' => 'No Code A']));
        $this->store()->createSupplier(Supplier::newWith(['name' => 'No Code B']));
        self::assertCount(2, $this->store()->listSuppliers());

        $this->store()->createSupplier(Supplier::newWith(['name' => 'Coded', 'code' => 'ACME-01']));
        self::assertSame('Coded', $this->store()->findSupplierByCode('ACME-01')?->name);
        self::assertSame('Coded', $this->store()->findSupplierByCode('acme-01')?->name, 'lookup is case-insensitive');
        self::assertNull($this->store()->findSupplierByCode('NOPE'));

        // A second supplier with the same code is rejected by the unique index.
        $this->expectException(\Throwable::class);
        $this->store()->createSupplier(Supplier::newWith(['name' => 'Dup', 'code' => 'ACME-01']));
    }

    public function testSupplierContactsCrudAndOrdering(): void
    {
        $supplier = $this->store()->createSupplier(Supplier::newWith(['name' => 'Contacted Co']));
        $supplierId = (int) $supplier->id;

        $sales = $this->store()->saveSupplierContact(SupplierContact::newWith([
            'supplier_id'  => $supplierId,
            'name'         => 'Zoe Sales',
            'email'        => 'zoe@example.test',
            'role'         => 'Sales rep',
            'po_recipient' => true,
        ]));
        $this->store()->saveSupplierContact(SupplierContact::newWith([
            'supplier_id' => $supplierId,
            'name'        => 'Amy Accounts',
            'role'        => 'Accounting',
        ]));
        // A departed contact: inactive sorts after the active ones.
        $this->store()->saveSupplierContact(SupplierContact::newWith([
            'supplier_id' => $supplierId,
            'name'        => 'Bob Bygone',
            'status'      => SupplierStatus::Inactive,
        ]));

        $contacts = $this->store()->contactsForSupplier($supplierId);
        self::assertCount(3, $contacts);
        self::assertSame(
            ['Amy Accounts', 'Zoe Sales', 'Bob Bygone'],
            array_map(static fn (SupplierContact $c): string => $c->name, $contacts),
            'active contacts first (alphabetical), then inactive',
        );
        self::assertTrue($contacts[1]->po_recipient, 'po_recipient round-trips');

        // find + update (set inactive) + delete.
        $found = $this->store()->findSupplierContact((int) $sales->id);
        self::assertNotNull($found);
        $found->status = SupplierStatus::Inactive;
        $this->store()->saveSupplierContact($found);
        self::assertSame(SupplierStatus::Inactive, $this->store()->findSupplierContact((int) $sales->id)?->status);

        $this->store()->deleteSupplierContact($found);
        self::assertNull($this->store()->findSupplierContact((int) $sales->id), 'deleted contact is gone');
        self::assertCount(2, $this->store()->contactsForSupplier($supplierId));
    }

    public function testSubjectCostSidecarRoundTrips(): void
    {
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $subjectId = (int) $subject->id;

        SubjectCost::newWith([
            'subject_id'        => $subjectId,
            'seed_cost'         => '7.5000',
            'weighted_avg_cost' => '7.5000',
            'cost_currency'     => 'EUR',
        ])->save();

        $cost = SubjectCost::where('subject_id', $subjectId)->first();
        self::assertNotNull($cost);
        self::assertSame('7.5000', $cost->seed_cost);
        self::assertSame('7.5000', $cost->weighted_avg_cost);
        self::assertSame('EUR', $cost->cost_currency);
        self::assertNull($cost->updated_at, 'updated_at is writer-set; null when not provided');
    }

    public function testPurchaseOrderTablesInstallAndQtyOpenComputes(): void
    {
        $supplier = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'PO Supplier', 'default_currency' => 'EUR']),
        );
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = PurchaseOrder::newWith([
            'number'      => 'PO-1001',
            'supplier_id' => $supplier->id,
            'currency'    => 'EUR',
            'status'      => PoStatus::InPrep,
        ]);
        $po->save();
        self::assertNotNull($po->id);
        self::assertNotNull($po->created_at, 'created_at stamped via beforeSave on an autoincrement record');

        $line = PurchaseOrderLine::newWith([
            'po_id'         => $po->id,
            'subject_id'    => $subjectId,
            'qty_requested' => 10,
            'unit_cost'     => '5.0000',
        ]);
        $line->save();

        // qty_open is a GENERATED VIRTUAL column: GREATEST(0, qty_requested - qty_received).
        $fresh = PurchaseOrderLine::where('id', $line->id)->first();
        self::assertNotNull($fresh);
        self::assertSame(10, $fresh->qty_open, 'qty_open = 10 - 0');

        // Bumping the app-maintained cache recomputes qty_open (also exercises the UPDATE path).
        $fresh->qty_received = 4;
        $fresh->save();
        $afterReceipt = PurchaseOrderLine::where('id', $line->id)->first();
        self::assertNotNull($afterReceipt);
        self::assertSame(6, $afterReceipt->qty_open, 'qty_open = 10 - 4');

        // Receipt + receipt line round-trip (FK chain resolves).
        $receipt = GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]);
        $receipt->save();
        self::assertNotNull($receipt->received_at, 'received_at defaulted on save');

        ReceiptLine::newWith([
            'receipt_id'         => $receipt->id,
            'po_line_id'         => $line->id,
            'subject_id'         => $subjectId,
            'qty'                => 4,
            'unit_cost_snapshot' => '5.0000',
        ])->save();

        // Number counter (provided string PK) round-trips.
        PoNumberCounter::newWith(['series_key' => PoNumberCounter::DEFAULT_SERIES, 'next_value' => 2])->save();
        $counter = PoNumberCounter::where('series_key', PoNumberCounter::DEFAULT_SERIES)->first();
        self::assertNotNull($counter);
        self::assertSame(2, $counter->next_value);

        // PO event mirrors OrderEvent (INT PK); recorded_at stamped, FKs resolve.
        PoEvent::newWith([
            'po_id'      => $po->id,
            'event_type' => 'po.created',
            'payload'    => '{"by":"test"}',
        ])->save();
        $event = PoEvent::where('po_id', $po->id)->first();
        self::assertNotNull($event);
        self::assertSame('po.created', $event->event_type);
        // DATETIME(6) hydration: recorded_at must round-trip (not null) through attrecord.
        self::assertNotNull($event->recorded_at);

        $reloadedPo = PurchaseOrder::where('id', $po->id)->first();
        self::assertNotNull($reloadedPo);
        self::assertNotNull($reloadedPo->created_at, 'beforeSave created_at persists + re-hydrates');
    }

    public function testCreateLeavesDraftUnnumberedAndAssignMintsSequentialNumbers(): void
    {
        $supplier = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'Numbering Co', 'default_currency' => 'EUR']),
        );
        $scheme = new SequentialScheme(prefix: 'PO-', startValue: 1000, padding: 5);

        // A fresh draft is created un-numbered; the number is minted only at the Assign-number action.
        $po1 = $this->store()->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        self::assertNull($po1->number, 'create leaves the draft un-numbered');

        $po1 = $this->store()->assignPurchaseOrderNumber($po1, $scheme, $this->numberEvent($po1));

        $po2 = $this->store()->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        $po2 = $this->store()->assignPurchaseOrderNumber($po2, $scheme, $this->numberEvent($po2));

        self::assertSame('PO-01000', $po1->number);
        self::assertSame('PO-01001', $po2->number, 'sequence advances per assigned number');

        $counter = PoNumberCounter::where('series_key', PoNumberCounter::DEFAULT_SERIES)->first();
        self::assertNotNull($counter);
        self::assertSame(1002, $counter->next_value, 'counter advanced past both assignments');

        self::assertSame('PO-01000', $this->store()->findPurchaseOrder((int) $po1->id)?->number);
        self::assertCount(2, $this->store()->listPurchaseOrders());
    }

    /**
     * The number and the order date are one fact, and the audit event has to say which number it
     * assigned — none of which the caller can supply, since the number does not exist until the mint.
     */
    public function testAssigningStampsTheOrderDateAndTellsTheEventItsNumber(): void
    {
        $supplier = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'Stamping Co', 'default_currency' => 'EUR']),
        );

        $po = $this->store()->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        self::assertNull($po->issued_at, 'a draft has no order date — it is not an order yet');

        $po = $this->store()->assignPurchaseOrderNumber($po, new SequentialScheme(prefix: 'PO-', startValue: 500), $this->numberEvent($po));

        self::assertSame('PO-500', $po->number);
        self::assertNotNull($po->issued_at, 'the order date is stamped with the number, at the same gate');

        $events = $this->store()->poEventsForPurchaseOrder((int) $po->id);
        self::assertCount(1, $events);
        self::assertSame(PoEvent::TYPE_NUMBER_ASSIGNED, $events[0]->event_type);
        self::assertSame(
            ['number' => 'PO-500'],
            json_decode((string) $events[0]->payload, true, flags: JSON_THROW_ON_ERROR),
            'the event names the number it assigned, so the log stands on its own',
        );
    }

    public function testAssignPurchaseOrderNumberIsIdempotent(): void
    {
        $supplier = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'Idempotent Co', 'default_currency' => 'EUR']),
        );
        $scheme = new SequentialScheme(prefix: 'PO-', startValue: 1);

        $po = $this->store()->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        $po = $this->store()->assignPurchaseOrderNumber($po, $scheme, $this->numberEvent($po));
        self::assertSame('PO-1', $po->number);

        // A second call is a no-op: same number, counter not advanced, no second event.
        $po = $this->store()->assignPurchaseOrderNumber($po, $scheme, $this->numberEvent($po));
        self::assertSame('PO-1', $po->number);

        $counter = PoNumberCounter::where('series_key', PoNumberCounter::DEFAULT_SERIES)->first();
        self::assertSame(2, $counter?->next_value, 'counter not advanced by the idempotent re-call');
        self::assertCount(1, $this->store()->poEventsForPurchaseOrder((int) $po->id));
    }

    private function numberEvent(PurchaseOrder $po): PoEvent
    {
        return PoEvent::newWith([
            'po_id'      => (int) $po->id,
            'event_type' => PoEvent::TYPE_NUMBER_ASSIGNED,
        ]);
    }

    public function testRecordReceiptBumpsQtyReceived(): void
    {
        $supplier = $this->store()->createSupplier(
            Supplier::newWith(['name' => 'Receiving Co', 'default_currency' => 'EUR']),
        );
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $subjectId = (int) $subject->id;

        $po = $this->store()->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        [$line] = $this->store()->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $subjectId, 'qty_requested' => 10,
        ])]);

        // First receipt: 4 of 10.
        $receipt = $this->store()->recordReceipt(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id, 'note' => 'partial']),
            [ReceiptLine::newWith(['po_line_id' => $line->id, 'subject_id' => $subjectId, 'qty' => 4])],
        );
        self::assertNotNull($receipt->id);

        $afterFirst = PurchaseOrderLine::where('id', $line->id)->first();
        self::assertNotNull($afterFirst);
        self::assertSame(4, $afterFirst->qty_received);
        self::assertSame(6, $afterFirst->qty_open, 'qty_open recomputed after the receipt bump');

        // Second receipt: the remaining 6.
        $this->store()->recordReceipt(
            GoodsReceipt::newWith(['source_ref_type_id' => $this->poRefTypeId(), 'source_id' => $po->id]),
            [ReceiptLine::newWith(['po_line_id' => $line->id, 'subject_id' => $subjectId, 'qty' => 6])],
        );
        $afterSecond = PurchaseOrderLine::where('id', $line->id)->first();
        self::assertNotNull($afterSecond);
        self::assertSame(10, $afterSecond->qty_received);
        self::assertSame(0, $afterSecond->qty_open, 'fully received');

        self::assertCount(2, $this->store()->receiptsForPurchaseOrder((int) $po->id));
    }

    public function testRefTypesCarryIdentityClass(): void
    {
        // Admin-minted INT documents route to the ref_int_id lane; UUID docs to ref_id.
        $po = RefTypeRecord::where('code', 'purchase_order')->first();
        self::assertNotNull($po);
        self::assertSame('int', $po->identity_class);

        $order = RefTypeRecord::where('code', 'order')->first();
        self::assertNotNull($order);
        self::assertSame('uuid', $order->identity_class, 'default identity class is uuid');
    }

    public function testTransitionPurchaseOrderAdvancesStatusAndAppendsEvent(): void
    {
        $store = $this->store();
        $supplier = $store->createSupplier(Supplier::newWith(['name' => 'PO Vendor']));
        $po = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        self::assertSame(PoStatus::InPrep, $po->status);

        (new TransitionPurchaseOrder($store))($po, PoStatus::Submitted, note: 'sent by e2e');

        // Status persisted…
        self::assertSame(PoStatus::Submitted, $store->findPurchaseOrder((int) $po->id)?->status);

        // …and exactly one `po.submitted` audit event appended, atomically.
        $events = [...PoEvent::where('po_id', (int) $po->id)];
        self::assertCount(1, $events);
        self::assertSame('po.submitted', $events[0]->event_type);
        self::assertSame('sent by e2e', $events[0]->note);
    }

    public function testTransitionPurchaseOrderRejectsAnIllegalEdge(): void
    {
        $store = $this->store();
        $supplier = $store->createSupplier(Supplier::newWith(['name' => 'PO Vendor 2']));
        $po = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );

        // 1 (In Prep) → 7 (Received) skips the chain — rejected before any write.
        $this->expectException(InvalidPurchaseOrderTransition::class);
        (new TransitionPurchaseOrder($store))($po, PoStatus::Received);
    }

    public function testLineCountsAndSupplierStatusCountsAreBulkGrouped(): void
    {
        $store = $this->store();
        $subjectA = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subjectA->save();
        $subjectB = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subjectB->save();

        $supplierA = $store->createSupplier(Supplier::newWith(['name' => 'Counts A']));
        $supplierB = $store->createSupplier(Supplier::newWith(['name' => 'Counts B']));

        // Supplier A: a draft PO with 2 lines + a submitted PO with 1 line.
        $draftA = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplierA->id, 'currency' => 'EUR']),
        );
        $store->savePurchaseOrderLines([
            PurchaseOrderLine::newWith(['po_id' => $draftA->id, 'subject_id' => $subjectA->id, 'qty_requested' => 3]),
            PurchaseOrderLine::newWith(['po_id' => $draftA->id, 'subject_id' => $subjectB->id, 'qty_requested' => 5]),
        ]);
        $openA = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplierA->id, 'currency' => 'EUR']),
        );
        $store->savePurchaseOrderLines([
            PurchaseOrderLine::newWith(['po_id' => $openA->id, 'subject_id' => $subjectA->id, 'qty_requested' => 2]),
        ]);
        (new TransitionPurchaseOrder($store))($openA, PoStatus::Submitted);

        // Supplier B: a single draft PO, no lines.
        $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplierB->id, 'currency' => 'EUR']),
        );

        // Line counts — keyed by po id; a line-less PO is simply absent.
        $lineCounts = $store->lineCountsForPurchaseOrders([(int) $draftA->id, (int) $openA->id]);
        self::assertSame(2, $lineCounts[(int) $draftA->id] ?? null);
        self::assertSame(1, $lineCounts[(int) $openA->id] ?? null);
        self::assertSame([], $store->lineCountsForPurchaseOrders([]));

        // Per-supplier status counts: A has 1 in-prep + 1 submitted; B has 1 in-prep.
        $counts = $store->statusCountsBySupplier();
        self::assertSame(1, $counts[(int) $supplierA->id][PoStatus::InPrep->value] ?? null);
        self::assertSame(1, $counts[(int) $supplierA->id][PoStatus::Submitted->value] ?? null);
        self::assertSame(1, $counts[(int) $supplierB->id][PoStatus::InPrep->value] ?? null);
    }

    public function testDeliveryDiscrepancyRollupCountsOverAndFinalizedShort(): void
    {
        $store = $this->store();
        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $supplier = $store->createSupplier(Supplier::newWith(['name' => 'Rollup Co']));

        // PO with a mix: one over (received > ordered), one finalized-short (closed-short → qty_open 0),
        // one still-open (received < ordered, nothing closed), one exact. Only over + short are counted.
        $poWithDiscrepancies = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        $store->savePurchaseOrderLines([
            PurchaseOrderLine::newWith(['po_id' => $poWithDiscrepancies->id, 'subject_id' => $subject->id, 'qty_requested' => 10, 'qty_received' => 12]),
            PurchaseOrderLine::newWith(['po_id' => $poWithDiscrepancies->id, 'subject_id' => $subject->id, 'qty_requested' => 10, 'qty_received' => 7, 'qty_closed_short' => 3]),
            PurchaseOrderLine::newWith(['po_id' => $poWithDiscrepancies->id, 'subject_id' => $subject->id, 'qty_requested' => 10, 'qty_received' => 4]),
            PurchaseOrderLine::newWith(['po_id' => $poWithDiscrepancies->id, 'subject_id' => $subject->id, 'qty_requested' => 10, 'qty_received' => 10]),
        ]);

        // A clean PO (exact only) must be absent from the rollup.
        $cleanPo = $store->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );
        $store->savePurchaseOrderLines([
            PurchaseOrderLine::newWith(['po_id' => $cleanPo->id, 'subject_id' => $subject->id, 'qty_requested' => 5, 'qty_received' => 5]),
        ]);

        $rollup = $store->deliveryDiscrepancyRollup([(int) $poWithDiscrepancies->id, (int) $cleanPo->id]);
        self::assertSame(['over' => 1, 'short' => 1], $rollup[(int) $poWithDiscrepancies->id] ?? null);
        self::assertArrayNotHasKey((int) $cleanPo->id, $rollup, 'a discrepancy-free PO is absent');
        self::assertSame([], $store->deliveryDiscrepancyRollup([]));
    }

    private function store(): MysqlDomainStore
    {
        \assert($this->domainStore instanceof MysqlDomainStore);

        return $this->domainStore;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_po_events',
            'invflux_receipt_lines',
            'invflux_goods_receipts',
            'invflux_po_lines',
            'invflux_pos', 'invflux_document_parties',
            'invflux_po_number_counters',
            'invflux_subject_cost_metadata',
            'invflux_supplier_products',
            'invflux_supplier_contacts',
            'invflux_suppliers',
            'invflux_subject_worksheet_items',
            'invflux_subject_worksheets',
            'invflux_subject_stock_concerns',
            'invflux_inventory_ledger',
            'invflux_inventory_state',
            'invflux_subject_identifiers',
            'invflux_subjects',
            'invflux_identifier_types',
            'invflux_systems',
            'invflux_schema_ledger',
            'invflux_config_state',
            'invflux_adhoc_stock_adjustments',
            'invflux_actors',
            'invflux_actor_types',
            'invflux_surfaces',
            'invflux_surface_types',
            'invflux_ref_types',
            'invflux_movement_types',
            'invflux_slotspace',
            'invflux_dimension_values',
            'invflux_dimensions',
            'invflux_layers',
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
