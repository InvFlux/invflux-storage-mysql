<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Application\Procurement\CorrectSupplierInvoice;
use Nandan108\InvFlux\Application\Procurement\DeleteSupplierInvoice;
use Nandan108\InvFlux\Application\Procurement\RecordSupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoiceLine;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\DuplicateSupplierInvoiceException;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * A supplier invoice recorded, corrected and deleted against real tables: one number names one
 * document per order, a correction replaces the document's lines rather than adding to them, and the
 * ordered line's cached figures follow the documents that stand.
 */
final class SupplierInvoiceTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?MysqlDomainStore $domainStore = null;
    private int $subjectId = 0;

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
        (new MysqlInventoryStore($session))->bootstrap((new SlotSpaceFactory())->createLayered());
        $this->domainStore->bootstrap();

        $subject = Subject::newWith(['kind' => SubjectKind::Unit]);
        $subject->save();
        $this->subjectId = (int) $subject->id;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->dropTables();
        $this->pdo = null;
    }

    /** The owner's case end to end: recorded as 2, corrected to 1, the order line reads 1. */
    public function testACorrectionReplacesTheLinesAndTheFigures(): void
    {
        $po = $this->order();
        $invoice = $this->record($po, 'INV-2610-342', 2, '4.0000');

        (new CorrectSupplierInvoice($this->store()))(
            $po,
            $invoice,
            'INV-2610-342',
            null,
            'EUR',
            null,
            null,
            [SupplierInvoiceLine::newWith(['subject_id' => $this->subjectId, 'qty' => 1, 'unit_cost' => '4.0000'])],
        );

        $lines = SupplierInvoiceLine::find('invoice_id = ?', [(int) $invoice->id]);
        self::assertCount(1, $lines, 'the previous line was replaced, not kept beside the new one');
        self::assertSame(1, $this->orderLine($po)->qty_invoiced);
        self::assertCount(1, $this->store()->supplierInvoicesForPurchaseOrder((int) $po->id));
    }

    /** A second entry under the same number — however it is typed — is refused, naming the first. */
    public function testTheSameNumberTwiceOnOneOrderIsRefused(): void
    {
        $po = $this->order();
        $first = $this->record($po, 'INV-2610-342', 2, '4.0000');

        try {
            $this->record($po, ' inv-2610-342 ', 1, '4.0000');
            self::fail('a duplicate number must be refused');
        } catch (DuplicateSupplierInvoiceException $e) {
            self::assertSame($first->id, $e->existingInvoiceId);
        }

        self::assertCount(1, $this->store()->supplierInvoicesForPurchaseOrder((int) $po->id));
        self::assertSame(2, $this->orderLine($po)->qty_invoiced, 'the refused save left the figures alone');
    }

    /** Renaming an invoice onto another one's number is the same duplicate. */
    public function testACorrectionCannotTakeAnotherInvoicesNumber(): void
    {
        $po = $this->order();
        $this->record($po, 'INV-A', 1, '4.0000');
        $second = $this->record($po, 'INV-B', 1, '4.0000');

        $this->expectException(DuplicateSupplierInvoiceException::class);
        (new CorrectSupplierInvoice($this->store()))(
            $po,
            $second,
            'INV-A',
            null,
            'EUR',
            null,
            null,
            [SupplierInvoiceLine::newWith(['subject_id' => $this->subjectId, 'qty' => 1, 'unit_cost' => '4.0000'])],
        );
    }

    /** The same number on another order is a separate document: one invoice may bill two orders. */
    public function testTheSameNumberOnAnotherOrderIsAllowed(): void
    {
        $this->record($this->order(), 'INV-1', 1, '4.0000');
        $this->record($this->order(), 'INV-1', 1, '4.0000');

        self::assertSame(2, SupplierInvoice::countWhere('reference = ?', ['INV-1']));
    }

    public function testDeletingAnInvoiceRemovesItsLinesAndClearsTheFigures(): void
    {
        $po = $this->order();
        $invoice = $this->record($po, 'INV-1', 3, '4.0000');

        (new DeleteSupplierInvoice($this->store()))($po, $invoice);

        self::assertNull($this->store()->findSupplierInvoice((int) $invoice->id));
        self::assertCount(0, SupplierInvoiceLine::find('invoice_id = ?', [(int) $invoice->id]));
        $line = $this->orderLine($po);
        self::assertNull($line->qty_invoiced);
        self::assertNull($line->unit_cost_invoiced);
    }

    private function order(): PurchaseOrder
    {
        $supplier = $this->store()->createSupplier(Supplier::newWith(['name' => 'Invoicing Co '.uniqid(), 'default_currency' => 'EUR']));
        $po = $this->store()->createPurchaseOrder(PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']));
        $this->store()->savePurchaseOrderLines([PurchaseOrderLine::newWith([
            'po_id' => $po->id, 'subject_id' => $this->subjectId, 'qty_requested' => 10, 'unit_cost' => '4.0000',
        ])]);

        return $po;
    }

    private function record(PurchaseOrder $po, string $reference, int $qty, string $unitCost): SupplierInvoice
    {
        return (new RecordSupplierInvoice($this->store()))(
            $po,
            SupplierInvoice::newWith(['reference' => trim($reference), 'currency' => 'EUR']),
            [SupplierInvoiceLine::newWith(['subject_id' => $this->subjectId, 'qty' => $qty, 'unit_cost' => $unitCost])],
        );
    }

    private function orderLine(PurchaseOrder $po): PurchaseOrderLine
    {
        $lines = $this->store()->linesForPurchaseOrder((int) $po->id);
        self::assertCount(1, $lines);

        return $lines[0];
    }

    private function store(): MysqlDomainStore
    {
        \assert($this->domainStore instanceof MysqlDomainStore);

        return $this->domainStore;
    }

    private function dropTables(): void
    {
        if (null === $this->pdo) {
            return;
        }
        $tables = [
            'invflux_supplier_invoice_lines', 'invflux_supplier_invoices',
            'invflux_po_events', 'invflux_receipt_lines', 'invflux_goods_receipts', 'invflux_po_lines',
            'invflux_pos', 'invflux_document_parties', 'invflux_po_number_counters', 'invflux_subject_cost_metadata',
            'invflux_supplier_products', 'invflux_supplier_contacts', 'invflux_suppliers',
            'invflux_subject_worksheet_items', 'invflux_subject_worksheets', 'invflux_subject_stock_concerns',
            'invflux_inventory_ledger', 'invflux_inventory_state', 'invflux_subject_identifiers', 'invflux_subjects',
            'invflux_identifier_types', 'invflux_systems', 'invflux_schema_ledger', 'invflux_config_state',
            'invflux_adhoc_stock_adjustments', 'invflux_actors', 'invflux_actor_types', 'invflux_surfaces',
            'invflux_surface_types', 'invflux_ref_types', 'invflux_movement_types', 'invflux_slotspace',
            'invflux_dimension_values', 'invflux_dimensions', 'invflux_layers',
        ];
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
}
