<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Exception\AppendOnlyViolationException;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * The shared, append-only party table behind issued documents. What these pin is the property that
 * makes sharing safe: a row's identity *is* its content, so the same facts always intern to the same
 * id, and nothing can change a row that many documents point at.
 */
final class DocumentPartyRepositoryTest extends TestCase
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
        (new MysqlInventoryStore($session))->bootstrap((new SlotSpaceFactory())->createLayered());
        $this->domainStore->bootstrap();
    }

    /** The same facts interned twice — or a thousand times — are one row. */
    public function testIdenticalFactsInternToOneRow(): void
    {
        $first = $this->store()->internDocumentParties([$this->hamburg()]);
        $again = $this->store()->internDocumentParties([$this->hamburg()]);

        $hash = $this->hamburg()->content_hash;
        self::assertArrayHasKey($hash, $first);
        self::assertSame($hash, $first[$hash]->content_hash);
        self::assertSame($first[$hash]->content_hash, $again[$hash]->content_hash, 'a second intern of the same facts returns the same row');
        self::assertSame(1, $this->countRows());
    }

    /** Buyer and ship-to at a single-location store are identical facts in one batch: still one row. */
    public function testABatchNamingTheSameFactsTwiceCollapsesToOneRow(): void
    {
        $interned = $this->store()->internDocumentParties([$this->hamburg(), $this->hamburg(), $this->berlin()]);

        self::assertCount(2, $interned, 'keyed by content, so the duplicate collapses');
        self::assertSame(2, $this->countRows());
    }

    /** A supplier who moves produces a new row; the old one is untouched, so old documents keep it. */
    public function testChangedFactsAreANewRowNotAnUpdate(): void
    {
        $before = $this->store()->internDocumentParties([$this->hamburg()]);
        $after = $this->store()->internDocumentParties([$this->berlin()]);

        $old = $before[$this->hamburg()->content_hash];
        $new = $after[$this->berlin()->content_hash];
        self::assertNotSame($old->content_hash, $new->content_hash);
        self::assertSame('Hamburg', $this->store()->findDocumentParties([$old->content_hash])[$old->content_hash]->city, 'the old row still says Hamburg');
    }

    /** Whitespace and empty-vs-null must not fork rows: "" and null are the same absence. */
    public function testNormalizationKeepsTrivialVariantsOnOneRow(): void
    {
        $a = DocumentParty::of(name: 'Bäcker GmbH ', address: ['city' => 'Hamburg', 'address_2' => ''], taxNumber: 'DE111');
        $b = DocumentParty::of(name: 'Bäcker GmbH', address: ['city' => ' Hamburg ', 'address_2' => null], taxNumber: 'DE111');

        self::assertSame($a->content_hash, $b->content_hash);
    }

    /**
     * The row carries no provenance, so the same facts reached from two documents — a supplier's
     * address on a PO, the same business as a customer on a delivery note — are one row. "Whose" is
     * the document's business; a shared row could not answer it anyway.
     */
    public function testTheSameFactsFromAnyDocumentAreOneRow(): void
    {
        $asSupplier = DocumentParty::of(name: 'Shared Depot', address: ['city' => 'Basel']);
        $asCustomer = DocumentParty::of(name: 'Shared Depot', address: ['city' => 'Basel']);

        self::assertSame($asSupplier->content_hash, $asCustomer->content_hash);
    }

    /**
     * A 64-bit digest is an identity, not a proof. Two different parties whose facts collided must
     * neither share a row — one document would print the other's address — nor make the loser
     * unstorable, since the digest is deterministic and a refusal would leave that merchant unable
     * to issue the order at all. The newcomer is re-keyed instead. Simulated by forcing the hash,
     * the only way to reach a case the arithmetic makes vanishingly rare.
     */
    public function testACollidingPartyIsRekeyedRatherThanRefusedOrShared(): void
    {
        $first = $this->store()->internDocumentParties([$this->hamburg()])[$this->hamburg()->content_hash];

        $impostor = DocumentParty::of(name: 'Someone Else SA', address: ['city' => 'Lisboa', 'country' => 'PT']);
        $impostor->content_hash = $first->content_hash; // stand in for a genuine digest collision

        $interned = $this->store()->internDocumentParties([$impostor]);
        $row = $interned[$first->content_hash];

        self::assertNotSame($first->content_hash, $row->content_hash, 'the newcomer moved to a key of its own');
        self::assertSame('Someone Else SA', $row->name, 'and kept its own facts');
        self::assertSame('Hamburg', $this->store()->findDocumentParties([$first->content_hash])[$first->content_hash]->city, 'the incumbent is untouched');
        self::assertSame(2, $this->countRows());
    }

    /** Re-keying is deterministic, so the same collision resolves to the same row rather than piling up. */
    public function testARepeatedCollisionResolvesToTheSameRekeyedRow(): void
    {
        $first = $this->store()->internDocumentParties([$this->hamburg()])[$this->hamburg()->content_hash];

        $make = function () use ($first): DocumentParty {
            $p = DocumentParty::of(name: 'Someone Else SA', address: ['city' => 'Lisboa', 'country' => 'PT']);
            $p->content_hash = $first->content_hash;

            return $p;
        };

        $a = $this->store()->internDocumentParties([$make()])[$first->content_hash];
        $b = $this->store()->internDocumentParties([$make()])[$first->content_hash];

        self::assertSame($a->content_hash, $b->content_hash);
        self::assertSame(2, $this->countRows(), 'no duplicate row for the second attempt');
    }

    /** The guard behind "shared is safe": a stored row cannot be updated, by anyone, ever. */
    public function testAStoredPartyRefusesToBeUpdated(): void
    {
        $row = $this->store()->internDocumentParties([$this->hamburg()])[$this->hamburg()->content_hash];
        $row->city = 'Berlin';

        $this->expectException(AppendOnlyViolationException::class);
        $row->save();
    }

    public function testFindReturnsOnlyWhatExists(): void
    {
        $row = $this->store()->internDocumentParties([$this->hamburg()])[$this->hamburg()->content_hash];

        $found = $this->store()->findDocumentParties([$row->content_hash, 999_999]);
        self::assertCount(1, $found);
        self::assertArrayHasKey($row->content_hash, $found);
        self::assertSame([], $this->store()->findDocumentParties([]));
    }

    private function hamburg(): DocumentParty
    {
        return DocumentParty::of(
            name: 'Bäcker GmbH',
            address: ['address_1' => 'Speicherstraße 1', 'city' => 'Hamburg', 'postcode' => '20457', 'country' => 'DE'],
            taxNumber: 'DE111',
            contactName: 'Anke',
        );
    }

    private function berlin(): DocumentParty
    {
        return DocumentParty::of(
            name: 'Bäcker GmbH',
            address: ['address_1' => 'Torstraße 9', 'city' => 'Berlin', 'postcode' => '10119', 'country' => 'DE'],
            taxNumber: 'DE999',
            contactName: 'Anke',
        );
    }

    private function countRows(): int
    {
        \assert($this->pdo instanceof \PDO);

        return (int) $this->pdo->query('SELECT COUNT(*) FROM invflux_document_parties')->fetchColumn();
    }

    private function store(): MysqlDomainStore
    {
        \assert($this->domainStore instanceof MysqlDomainStore);

        return $this->domainStore;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_po_events', 'invflux_receipt_lines', 'invflux_goods_receipts', 'invflux_po_lines',
            'invflux_pos', 'invflux_document_parties', 'invflux_po_number_counters',
            'invflux_subject_cost_metadata', 'invflux_supplier_products', 'invflux_supplier_contacts',
            'invflux_suppliers', 'invflux_subject_worksheet_items', 'invflux_subject_worksheets',
            'invflux_subject_stock_concerns', 'invflux_inventory_ledger', 'invflux_inventory_state',
            'invflux_subject_identifiers', 'invflux_subjects', 'invflux_identifier_types', 'invflux_systems',
            'invflux_schema_ledger', 'invflux_config_state', 'invflux_adhoc_stock_adjustments',
            'invflux_actors', 'invflux_actor_types', 'invflux_surfaces', 'invflux_surface_types',
            'invflux_ref_types', 'invflux_movement_types', 'invflux_slotspace', 'invflux_dimension_values',
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
}
