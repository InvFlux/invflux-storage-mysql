<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Enum\OnConflict;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Domain\Procurement\DocumentParty;
use Nandan108\InvFlux\Domain\Procurement\DocumentPartyDecision;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceipt;
use Nandan108\InvFlux\Domain\Procurement\GoodsReceiptRepository;
use Nandan108\InvFlux\Domain\Procurement\PoEvent;
use Nandan108\InvFlux\Domain\Procurement\PoNumberCounter;
use Nandan108\InvFlux\Domain\Procurement\PoNumberScheme;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderLine;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrderRepository;
use Nandan108\InvFlux\Domain\Procurement\ReceiptLine;
use Nandan108\InvFlux\Domain\Procurement\ReceivingSession;
use Nandan108\InvFlux\Domain\Procurement\ReceivingSessionRepository;
use Nandan108\InvFlux\Domain\Procurement\SubjectCost;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Procurement\SupplierContact;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoice;
use Nandan108\InvFlux\Domain\Procurement\SupplierInvoiceLine;
use Nandan108\InvFlux\Domain\Procurement\SupplierProduct;
use Nandan108\InvFlux\Domain\Procurement\SupplierRepository;
use Nandan108\InvFlux\Domain\Procurement\Terms;
use Nandan108\InvFlux\Domain\Procurement\TermsLineage;
use Nandan108\InvFlux\Domain\Procurement\TermsRepository;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Domain\Stock\AdjustmentReason;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Domain\Subject\SubjectStockConcern;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\ActorTypeRecord;
use Nandan108\InvFlux\Identity\IdentifierTypeRecord;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Identity\SurfaceRecord;
use Nandan108\InvFlux\Identity\SurfaceTypeRecord;
use Nandan108\InvFlux\Identity\SystemRecord;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Worksheet\SubjectWorksheetItemRecord;
use Nandan108\InvFlux\Storage\Mysql\Worksheet\SubjectWorksheetRecord;
use Nandan108\InvFlux\Util\Ulid;

/**
 * Persistence layer for plain CRUD domain entities (suppliers, purchase orders, etc.)
 * and the cross-cutting identity registries (actor types, actors, surface types,
 * surfaces, reference types, identifier types, systems).
 *
 * @api
 *
 * Shares the same MysqlSession as MysqlInventoryStore so both participate in the
 * same transaction boundary without a second START TRANSACTION.
 *
 * Lock acquisition order within compound transactions: attrecord entity locks first
 * (via LockSet::acquire()), inventory state locks second. Never the reverse — any path
 * that inverts the order can deadlock against one that doesn't.
 *
 * Tables: this store does not emit DDL. Its Record classes are declared in
 * {@see REFERENCE_RECORDS}, {@see PROCUREMENT_RECORDS} and {@see DOMAIN_RECORDS}, and the
 * schema installer creates them in an order it derives from the declared foreign keys. What
 * remains here is *seeding*, which still has a hard ordering requirement of its own: the
 * identity registries must be populated before anything resolves an actor, surface or
 * reference type against them.
 */
final class MysqlDomainStore implements SupplierRepository, PurchaseOrderRepository, ReceivingSessionRepository, GoodsReceiptRepository, TermsRepository
{
    /** Memoized `purchase_order` registry id — see {@see purchaseOrderRefTypeId()}. */
    private ?int $purchaseOrderRefTypeId = null;

    /**
     * The cross-cutting identity registries: actor/surface/ref types and their instances,
     * plus the identifier-type and system catalogues.
     *
     * A **set**, not a sequence — creation order is derived from the declared foreign keys
     * by the schema installer. What position still governs here is {@see seedReferenceTables()},
     * which inserts the built-in rows and does depend on parents existing first.
     *
     * @var list<class-string<Record>>
     */
    public const REFERENCE_RECORDS = [
        ActorTypeRecord::class,
        ActorRecord::class,
        SurfaceTypeRecord::class,
        SurfaceRecord::class,
        RefTypeRecord::class,
        // FK targets for invflux_subject_identifiers.type_id / .system_id and the
        // raw-SQL invflux_identifier_assignment_policies; no inter-reference FKs, so
        // position is free. Created here (before MysqlInventoryStore::bootstrap())
        // so those FKs resolve. Schema source of truth is the Record class.
        IdentifierTypeRecord::class,
        SystemRecord::class,
        // Configurable stock-adjustment reason-code catalog (replaces the disposition enum). No
        // inter-reference FK, so position is free; seeded in seedReferenceTables(). See arch-erp-parity §8.2.
        AdjustmentReason::class,
    ];

    /**
     * The procurement domain: suppliers and their catalogue, purchase orders, goods receipts.
     *
     * `Supplier` references `invflux_actors`, `SupplierProduct` references `invflux_suppliers`
     * and `invflux_subjects` — all resolved from the declarations, so this too is a set.
     *
     * @var list<class-string<Record>>
     */
    public const PROCUREMENT_RECORDS = [
        Supplier::class,
        SupplierContact::class,
        SupplierProduct::class,
        SubjectCost::class,
        // Before PurchaseOrder, which FKs into it three times.
        DocumentParty::class,
        // The party decision log FKs into DocumentParty, so it follows it.
        DocumentPartyDecision::class,
        PurchaseOrder::class,
        PurchaseOrderLine::class,
        GoodsReceipt::class,
        ReceiptLine::class,
        // Reception WIP. No foreign key of its own — it names its target document by the same
        // discriminated ref a receipt does, so it installs independently of what it points at.
        ReceivingSession::class,
        // Supplier invoices FK into the order, and their lines into the ordered lines, so both
        // follow the pair they reference.
        SupplierInvoice::class,
        SupplierInvoiceLine::class,
        // The interned terms come first: a version FKs into the text by its content hash, and an
        // order will point at the same hash. The lineage is independent of both.
        Terms::class,
        TermsLineage::class,
        TermsVersion::class,
        PoNumberCounter::class,
        PoEvent::class,
    ];

    /**
     * Domain Records that belong to neither registry above: the subject worksheets (a saved
     * selection of subjects plus its items) and the per-subject stock-concern projection that
     * backs the workbench's stock-state badge.
     *
     * @var list<class-string<Record>>
     */
    public const DOMAIN_RECORDS = [
        SubjectWorksheetRecord::class,
        SubjectWorksheetItemRecord::class,
        SubjectStockConcern::class,
    ];

    private bool $referenceTablesInstalled = false;

    private bool $bootstrapped = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = '',
        private readonly ?MysqlSession $session = null,
    ) {
    }

    /**
     * Seed the cross-cutting identity registries (actor types/actors, surface types/surfaces,
     * ref types, identifier types, systems) with the core defaults.
     *
     * The tables themselves are created by the schema installer beforehand; this fills them.
     * Idempotent, and must still run BEFORE {@see MysqlInventoryStore::bootstrap()}, which
     * resolves rows in these registries while seeding its own.
     */
    public function installReferenceTables(): void
    {
        if ($this->referenceTablesInstalled) {
            return;
        }

        $this->seedReferenceTables();

        $this->referenceTablesInstalled = true;
    }

    /**
     * Ready this store for use. Idempotent.
     *
     * Now that table creation belongs to the schema installer, the only boot-time work left
     * is the reference-registry seed — so this forwards to {@see installReferenceTables()}.
     * It stays a separate entry point because callers ask for "this store, ready", not for a
     * particular internal step, and the two guards let either be called first.
     */
    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->installReferenceTables();
        $this->bootstrapped = true;
    }

    /**
     * Execute a closure inside a transaction, sharing the session with MysqlInventoryStore.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    public function transactional(\Closure $operation): mixed
    {
        return $this->connection->session->transactional($operation);
    }

    // -----------------------------------------------------------------
    // SupplierRepository
    // -----------------------------------------------------------------

    /** Actor-type code under which each supplier is registered as a first-class actor. */
    private const SUPPLIER_ACTOR_TYPE = 'supplier';

    #[\Override]
    public function createSupplier(Supplier $supplier): Supplier
    {
        // Before the transaction, not inside it: interning only ever inserts, and does so
        // idempotently, so it is safe unlocked — and doing it here keeps a party row (lock tier 27)
        // from being taken while this holds a supplier (tier 30), which would invert the
        // acquisition order.
        $this->pointAtCurrentParty([$supplier]);

        return $this->transactional(function () use ($supplier): Supplier {
            $supplier->actor_id = $this->mintSupplierActor();
            $supplier->save();

            return $supplier;
        });
    }

    #[\Override]
    public function saveSupplier(Supplier $supplier): Supplier
    {
        $this->pointAtCurrentParty([$supplier]);

        return $supplier->save();
    }

    #[\Override]
    public function saveSuppliers(array $suppliers): void
    {
        if ([] === $suppliers) {
            return;
        }

        $this->pointAtCurrentParty($suppliers);

        // Existing rows carrying one changed column — a save, not an upsert.
        (new RecordSet($suppliers))->upsertAll();
    }

    /**
     * Keep each supplier's `current_party_id` in step with the facts it is about to be saved with.
     *
     * Maintained here, at the aggregate boundary, for the same reason `actor_id` is: it is a
     * derived pointer, and every writer must get it — the REST controller, the procurement seeder,
     * and whatever writes next. Putting it in one caller leaves the others minting nothing and the
     * pointer silently stale.
     *
     * **The digest is computed before anything is asked of the database.** A party's key is a pure
     * function of its facts, so a save that changed only a nickname, a payment term or a lead time
     * recomputes the same hash, matches what is stored, and costs zero queries. Only a save that
     * genuinely changed a *stated* fact reaches the intern.
     *
     * Interning happens outside any transaction this opens: a party row is immutable and a retry
     * resolves to the same rows, so there is nothing to roll back — and a party is lock tier 27
     * against a supplier's 30, so taking one while holding the other would invert the order the
     * lock guard enforces.
     *
     * *Immutable*, not append-only, and the difference is load-bearing rather than pedantic:
     * `AppendOnly` forbids DELETE as well as UPDATE, which would put a reaper for unreferenced
     * rows out of reach of the record layer. A row no document points at asserts nothing, and
     * content-addressing means re-interning the same facts reproduces the same key — so removing
     * one is neither lossy nor a broken promise. See `DocumentParty`'s own docblock.
     *
     * @param list<Supplier> $suppliers
     */
    private function pointAtCurrentParty(array $suppliers): void
    {
        /** @var array<int, DocumentParty> $wanted keyed by this method's own index into $suppliers */
        $wanted = [];
        foreach ($suppliers as $i => $supplier) {
            $party = DocumentParty::fromFacts($supplier->partyFacts());
            if ($party->content_hash === $supplier->current_party_id) {
                continue; // facts unchanged — nothing to intern, nothing to write
            }
            $wanted[$i] = $party;
        }

        if ([] === $wanted) {
            return;
        }

        $interned = $this->internDocumentParties(array_values($wanted));

        foreach ($wanted as $i => $party) {
            // Keyed by the *requested* hash; a collision re-keys the row, so read the actual one
            // back rather than assuming it landed where its content implied.
            $row = $interned[$party->content_hash] ?? null;
            if (null !== $row) {
                $suppliers[$i]->current_party_id = $row->content_hash;
            }
        }
    }

    #[\Override]
    public function findSupplier(int $id): ?Supplier
    {
        return Supplier::where('id', $id)->first();
    }

    #[\Override]
    public function findSupplierByCode(string $code): ?Supplier
    {
        // Case-insensitivity comes from the column collation (the uq_supplier_code index matches).
        return Supplier::where('code', $code)->first();
    }

    #[\Override]
    public function actorReferenceFor(Supplier $supplier): ActorReference
    {
        $actor = ActorRecord::where('id', $supplier->actor_id)->first();

        return new ActorReference(self::SUPPLIER_ACTOR_TYPE, null !== $actor ? $actor->actor_ref : '');
    }

    /**
     * @return list<Supplier>
     */
    #[\Override]
    public function listSuppliers(): array
    {
        return [...Supplier::find(orderByLimit: 'ORDER BY `name` ASC')];
    }

    #[\Override]
    public function saveSupplierProduct(SupplierProduct $link): SupplierProduct
    {
        return $link->save();
    }

    #[\Override]
    public function deleteSupplierProduct(SupplierProduct $link): void
    {
        $link->delete();
    }

    /**
     * @return list<SupplierProduct>
     */
    #[\Override]
    public function supplierProductsForSupplier(int $supplierId): array
    {
        return [...SupplierProduct::where('supplier_id', $supplierId)];
    }

    /**
     * @return list<SupplierProduct>
     */
    #[\Override]
    public function supplierProductsForSubject(int $subjectId): array
    {
        return [...SupplierProduct::where('subject_id', $subjectId)];
    }

    #[\Override]
    public function saveSupplierContact(SupplierContact $contact): SupplierContact
    {
        return $contact->save();
    }

    #[\Override]
    public function deleteSupplierContact(SupplierContact $contact): void
    {
        $contact->delete();
    }

    #[\Override]
    public function findSupplierContact(int $id): ?SupplierContact
    {
        return SupplierContact::where('id', $id)->first();
    }

    /**
     * @return list<SupplierContact>
     */
    #[\Override]
    public function contactsForSupplier(int $supplierId): array
    {
        // Active first, then alphabetical — the order the send picker wants.
        return [...SupplierContact::find(
            where: 'supplier_id = :supplier_id',
            params: ['supplier_id' => $supplierId],
            orderByLimit: 'ORDER BY `status` ASC, `name` ASC',
        )];
    }

    /**
     * Mint a fresh `supplier` actor (opaque ULID `actor_ref` + UUIDv7 federation
     * identity) and return its id. Decision (B): opaque ref + single insert — the
     * caller sets `supplier.actor_id` and inserts the supplier in the same txn.
     */
    private function mintSupplierActor(): int
    {
        $type = ActorTypeRecord::where('code', self::SUPPLIER_ACTOR_TYPE)->first();
        if (null === $type || null === $type->id) {
            throw new \RuntimeException(
                "Actor type '".self::SUPPLIER_ACTOR_TYPE."' is not registered; installReferenceTables() must run first.",
            );
        }

        // uuid (federation identity) is left null, matching resolveActorId(); it is
        // assigned later when/if federation is configured.
        $actor = ActorRecord::newWith([
            'actor_type_id' => $type->id,
            'actor_ref'     => Ulid::generate(),
        ]);
        $actor->save();

        return (int) $actor->id;
    }

    // -----------------------------------------------------------------
    // PurchaseOrderRepository
    // -----------------------------------------------------------------

    #[\Override]
    public function createPurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        // No number minted here: a fresh draft is un-numbered (number NULL) until the Assign-number
        // action calls assignPurchaseOrderNumber(). Wrapped in a txn so a create that also stages
        // related rows stays atomic.
        return $this->transactional(function () use ($po): PurchaseOrder {
            $po->save();

            return $po;
        });
    }

    #[\Override]
    public function assignPurchaseOrderNumber(PurchaseOrder $po, PoNumberScheme $scheme, PoEvent $event): PurchaseOrder
    {
        return $this->transactional(function () use ($po, $scheme, $event): PurchaseOrder {
            // Idempotent: an already-numbered PO keeps its number — don't advance the counter or write
            // a duplicate event. A re-click on Assign-number just re-uses the existing number.
            if (null !== $po->number) {
                return $po;
            }
            $po->number = $scheme->format($this->nextPoSequence($scheme));
            // The number and the order date are one fact — the moment the order became a document —
            // so they are stamped together rather than left to be re-derived from the event log.
            $po->issued_at = new \DateTimeImmutable();
            // The caller cannot fill this in: the number does not exist until the line above. Stamping
            // it here keeps the audit event self-describing, which is the point of an event log.
            $event->payload = json_encode(['number' => $po->number], JSON_THROW_ON_ERROR);
            $po->save();
            $event->save();

            return $po;
        });
    }

    #[\Override]
    public function savePurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        return $po->save();
    }

    #[\Override]
    public function transitionPurchaseOrder(PurchaseOrder $po, PoEvent $event): PurchaseOrder
    {
        // Status change + its audit event commit together, or not at all.
        return $this->transactional(function () use ($po, $event): PurchaseOrder {
            $po->save();
            $event->save();

            return $po;
        });
    }

    #[\Override]
    public function updatePurchaseOrderHeader(PurchaseOrder $po, PoEvent $event): PurchaseOrder
    {
        // Header edit + its audit event commit together, or not at all (no status semantics).
        return $this->transactional(function () use ($po, $event): PurchaseOrder {
            $po->save();
            $event->save();

            return $po;
        });
    }

    #[\Override]
    public function findPurchaseOrder(int $id): ?PurchaseOrder
    {
        return PurchaseOrder::where('id', $id)->first();
    }

    #[\Override]
    public function internDocumentParties(array $parties): array
    {
        if ([] === $parties) {
            return [];
        }
        // Collapse the batch on content first: buyer and ship-to at a single-location store are the
        // same facts, and one INSERT must not name the same unique key twice.
        $byHash = [];
        foreach ($parties as $party) {
            $byHash[$party->content_hash] ??= $party;
        }

        // Insert-or-ignore on the content hash: rows already on file are skipped, never updated —
        // which is also what the table's AppendOnly contract would refuse. Auto-increment ids are not
        // back-filled under Ignore (a skipped row has none to give), hence the read-back.
        // One insert-or-ignore and one read-back for the whole batch: rows already on file are
        // skipped, never updated — which the table's AppendOnly contract would refuse anyway.
        (new RecordSet(array_values($byHash)))->insertAll(onConflict: OnConflict::Ignore);

        $stored = [];
        foreach (DocumentParty::whereIn('content_hash', array_keys($byHash)) as $row) {
            $stored[$row->content_hash] = $row;
        }

        $interned = [];
        foreach ($byHash as $requestedKey => $party) {
            $row = $stored[$requestedKey] ?? null;
            // Verify rather than trust the key: a 64-bit digest is an identity, not a proof, and two
            // different parties whose facts collided would otherwise share a row — a document
            // printing someone else's address, with nothing to show for it. Both objects are already
            // in hand, so the check is free.
            $interned[$requestedKey] = null !== $row && $row->sameFactsAs($party)
                ? $row
                : $this->internAfterCollision($party);
        }

        return $interned;
    }

    /**
     * Intern terms and read back the hash the engine computed for them.
     *
     * **Raw SQL because attrecord cannot express this, not because the operation deserves it.** The
     * hash is a generated column, so unlike a party — whose key the application computes and can
     * therefore look up — nothing here knows the digest until the row exists. The upsert makes
     * `LAST_INSERT_ID()` name the row whether this call created it or found one already on file,
     * which is what turns "insert or find" into a single answer. Retreat to attrecord when it grows
     * an insert-or-fetch that returns the stored row.
     *
     * **Not a lookup by `body`**, which is the obvious alternative and is wrong: the column's
     * collation is case- and accent-insensitive, so `WHERE body = ?` matches text that hashes
     * differently, and interning would return the hash of somebody else's terms. The digest is over
     * bytes; only the engine's own key comparison agrees with it.
     *
     * **The row the upsert lands on is checked, because it may hold different terms.** Two things
     * send it to another text's row, and they are told apart by what that row holds:
     *
     *  - **A key collision** — different terms own the key these would take. The text is written
     *    again at its next {@see Terms::$attempt}, which moves its key somewhere unrelated, and the
     *    same terms interned later retrace the same steps to the same row.
     *  - **A reused printed reference** — the reference's own unique key matched a row holding other
     *    terms. Refused rather than moved: moving it would print one reference on two artefacts.
     *
     * Idempotent, and cheap to retry: re-interning the same artefact returns the same hash and mints
     * no row. It costs an auto-increment id per duplicate, which a `BIGINT UNSIGNED` will not notice.
     */
    #[\Override]
    public function internTerms(string $body, ?string $publicRef = null): int
    {
        $session = $this->session ?? $this->connection->session;
        $table = $this->table('invflux_terms');

        for ($attempt = 0; $attempt <= Terms::MAX_KEY_ATTEMPTS; ++$attempt) {
            $session->exec(
                sprintf(
                    'INSERT INTO `%s` (body, public_ref, attempt, created_at) VALUES (?, ?, ?, NOW(6))
                     ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                    $table,
                ),
                [$body, $publicRef, 0 === $attempt ? null : $attempt],
            );
            /** @psalm-var array{content_hash: int|string, body: string, public_ref: ?string}|null $row */
            $row = $session->fetchOne(
                sprintf('SELECT content_hash, body, public_ref FROM `%s` WHERE id = LAST_INSERT_ID()', $table),
            );
            if (null === $row) {
                throw new \RuntimeException('The terms row just written could not be read back.');
            }

            // Compared here, byte for byte: the column's collation ignores case, and the digest does not.
            if ($row['body'] === $body && $row['public_ref'] === $publicRef) {
                return (int) $row['content_hash'];
            }
            if (null !== $publicRef && $row['public_ref'] === $publicRef) {
                throw new \DomainException(sprintf('The reference "%s" is already printed on different terms.', $publicRef));
            }
        }

        throw new \RuntimeException(sprintf(
            'Terms key exhausted after %d attempts — distinct terms cannot plausibly collide that often, so treat this as corruption rather than bad luck.',
            Terms::MAX_KEY_ATTEMPTS,
        ));
    }

    #[\Override]
    public function termsByHashes(array $contentHashes): array
    {
        if ([] === $contentHashes) {
            return [];
        }

        $found = [];
        foreach (Terms::whereIn('content_hash', array_values(array_unique($contentHashes))) as $row) {
            $found[$row->content_hash] = $row;
        }

        return $found;
    }

    #[\Override]
    public function termsLineages(bool $includeRemoved = false): array
    {
        return [...TermsLineage::find($includeRemoved ? '' : 'removed_at IS NULL', [], 'ORDER BY id ASC')];
    }

    #[\Override]
    public function findTermsLineage(int $id): ?TermsLineage
    {
        return TermsLineage::findOne('id = ?', [$id]);
    }

    #[\Override]
    public function currentTermsVersions(array $lineageIds): array
    {
        if ([] === $lineageIds) {
            return [];
        }
        $ids = array_values(array_unique($lineageIds));

        $out = [];
        foreach (TermsVersion::find(
            sprintf('is_current = 1 AND lineage_id IN (%s)', implode(', ', array_fill(0, \count($ids), '?'))),
            $ids,
        ) as $version) {
            $out[$version->lineage_id] = $version;
        }

        return $out;
    }

    #[\Override]
    public function termsVersionsOf(int $lineageId): array
    {
        return [...TermsVersion::find('lineage_id = ?', [$lineageId], 'ORDER BY ordinal ASC')];
    }

    #[\Override]
    public function termsVersionsForLineages(array $lineageIds): array
    {
        if ([] === $lineageIds) {
            return [];
        }
        $ids = array_values(array_unique($lineageIds));

        $out = [];
        foreach (TermsVersion::find(
            sprintf('lineage_id IN (%s)', implode(', ', array_fill(0, \count($ids), '?'))),
            $ids,
            'ORDER BY lineage_id ASC, ordinal ASC',
        ) as $version) {
            $out[$version->lineage_id][] = $version;
        }

        return $out;
    }

    /**
     * Grouped, so raw SQL: one count per text in a single statement rather than a query per text.
     */
    #[\Override]
    public function orderCountsForTerms(array $contentHashes): array
    {
        if ([] === $contentHashes) {
            return [];
        }
        $hashes = array_values(array_unique($contentHashes));
        $session = $this->session ?? $this->connection->session;

        /** @psalm-var list<array{terms_hash: int|string, n: int|string}> $rows */
        $rows = $session->fetchAll(
            sprintf(
                'SELECT terms_hash, COUNT(*) AS n FROM `%s` WHERE terms_hash IN (%s) GROUP BY terms_hash',
                $this->table('invflux_pos'),
                implode(', ', array_fill(0, \count($hashes), '?')),
            ),
            $hashes,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['terms_hash']] = (int) $row['n'];
        }

        return $out;
    }

    #[\Override]
    public function termsLineageSelections(int $lineageId): array
    {
        return [
            'suppliers' => array_map(
                static fn (Supplier $s): int => (int) $s->id,
                [...Supplier::find('terms_lineage_id = ?', [$lineageId], 'ORDER BY id ASC')],
            ),
            // Numbered orders froze their text into terms_hash, so only a draft still follows the set.
            'draftOrders' => array_map(
                static fn (PurchaseOrder $po): int => (int) $po->id,
                [...PurchaseOrder::find('terms_lineage_id = ? AND terms_hash IS NULL', [$lineageId], 'ORDER BY id ASC')],
            ),
        ];
    }

    #[\Override]
    public function createTermsLineage(TermsLineage $lineage, TermsVersion $first): TermsLineage
    {
        return $this->transactional(static function () use ($lineage, $first): TermsLineage {
            $lineage->save();
            $first->lineage_id = (int) $lineage->id;
            $first->save();

            return $lineage;
        });
    }

    #[\Override]
    public function saveTermsLineage(TermsLineage $lineage): TermsLineage
    {
        return $lineage->save();
    }

    #[\Override]
    public function saveTermsVersion(TermsVersion $version, ?TermsVersion $retired = null): TermsVersion
    {
        return $this->transactional(static function () use ($version, $retired): TermsVersion {
            // Retire first: the unique key admits one current edition per set, so the new one can
            // only be written once the old one has stepped aside.
            $retired?->save();

            return $version->save();
        });
    }

    #[\Override]
    public function archiveTermsLineage(TermsLineage $lineage): array
    {
        return $this->transactional(function () use ($lineage): array {
            $released = $this->releaseDraftsFollowing((int) $lineage->id);
            $lineage->save();

            return $released;
        });
    }

    #[\Override]
    public function deleteTermsLineage(TermsLineage $lineage): array
    {
        return $this->transactional(function () use ($lineage): array {
            // Drafts first: their foreign key into the set restricts its delete. Its editions go with
            // it through the cascade; the interned texts stay, since they belong to no set.
            $released = $this->releaseDraftsFollowing((int) $lineage->id);
            $lineage->delete();

            return $released;
        });
    }

    /**
     * Point the draft orders still following a set at none, so they inherit their supplier's or the
     * store's. A numbered order is not touched: it froze its text into `terms_hash` when numbered.
     *
     * @return list<int> the drafts released, ascending
     */
    private function releaseDraftsFollowing(int $lineageId): array
    {
        $where = 'terms_lineage_id = ? AND terms_hash IS NULL';
        $released = array_map(
            static fn (PurchaseOrder $po): int => (int) $po->id,
            [...PurchaseOrder::find($where, [$lineageId], 'ORDER BY id ASC')],
        );
        if ([] !== $released) {
            PurchaseOrder::updateWhere(['terms_lineage_id' => null], $where, [$lineageId]);
        }

        return $released;
    }

    /**
     * Store a party whose key is already taken by a *different* party, by moving it onto the key for
     * its next attempt until one is free or holds its own facts.
     *
     * Refusing instead would be worse than the collision: the digest is deterministic, so a merchant
     * who hit one could never issue that purchase order — retrying would reproduce it exactly. This
     * path costs an extra round-trip and runs about once in 37 million interns at a million rows.
     */
    private function internAfterCollision(DocumentParty $party): DocumentParty
    {
        for ($attempt = 1; $attempt <= DocumentParty::MAX_KEY_ATTEMPTS; ++$attempt) {
            $party->rekey($attempt);
            $existing = DocumentParty::where('content_hash', $party->content_hash)->first();
            if (null === $existing) {
                // Insert-or-ignore, then read back: a concurrent intern of the same facts may have
                // taken this key between the check and the write, and that row is just as good.
                (new RecordSet([$party]))->insertAll(onConflict: OnConflict::Ignore);
                $existing = DocumentParty::where('content_hash', $party->content_hash)->first();
            }
            if (null !== $existing && $existing->sameFactsAs($party)) {
                return $existing;
            }
        }

        throw new \RuntimeException(sprintf(
            'Document-party key exhausted after %d attempts for "%s" — %d distinct parties cannot plausibly collide, so treat this as corruption rather than bad luck.',
            DocumentParty::MAX_KEY_ATTEMPTS,
            $party->name,
            DocumentParty::MAX_KEY_ATTEMPTS,
        ));
    }

    #[\Override]
    public function findDocumentParties(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = [];
        foreach (DocumentParty::whereIn('content_hash', $ids) as $row) {
            $rows[$row->content_hash] = $row;
        }

        return $rows;
    }

    #[\Override]
    public function recordPartyDecision(DocumentPartyDecision $decision): void
    {
        // `save()` on a new record is the sanctioned single-row append for an AppendOnly Record, and
        // the only write this table takes. A decision about a party that is not there fails on the
        // RESTRICT foreign key rather than being stored pointing at nothing.
        $decision->save();
    }

    /**
     * Newest decision per hash, ranked in the database rather than by reading the log and folding it
     * here: the log grows without bound per party and the caller wants one row from each.
     *
     * The window function is the third of the four sanctioned reasons to leave attrecord — a
     * per-group top-1 is not a single-scalar aggregate. It selects **ids only**, and the rows come
     * back through the ordinary Record path: hand-listing columns in a raw projection is how a
     * column added later silently stops being read.
     *
     * `decided_at` first with `id` as the tiebreaker. Two decisions can share a microsecond, and the
     * id is a UUIDv7 — so the tiebreak is still by time rather than arbitrary.
     *
     * @param list<int> $partyHashes
     *
     * @return array<int, DocumentPartyDecision>
     */
    #[\Override]
    public function currentPartyDecisions(array $partyHashes): array
    {
        if ([] === $partyHashes) {
            return [];
        }
        $session = $this->session ?? $this->connection->session;
        $placeholders = implode(',', array_fill(0, count($partyHashes), '?'));
        $rows = $session->fetchAll(
            sprintf(
                'SELECT id FROM (
                     SELECT id, ROW_NUMBER() OVER (
                                PARTITION BY party_hash ORDER BY decided_at DESC, id DESC
                            ) AS rn
                       FROM `%s`
                      WHERE party_hash IN (%s)
                 ) ranked WHERE rn = 1',
                $this->table('invflux_document_party_decisions'),
                $placeholders,
            ),
            $partyHashes,
        );

        $ids = [];
        foreach ($rows as $row) {
            /** @psalm-var mixed $id */
            $id = $row['id'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $current = [];
        foreach (DocumentPartyDecision::whereIn('id', $ids) as $decision) {
            $current[$decision->party_hash] = $decision;
        }

        return $current;
    }

    /**
     * @return list<PurchaseOrder>
     */
    #[\Override]
    public function listPurchaseOrders(): array
    {
        return [...PurchaseOrder::find(orderByLimit: 'ORDER BY `id` DESC')];
    }

    /**
     * @param list<PoStatus> $statuses
     *
     * @return list<PurchaseOrder>
     */
    #[\Override]
    public function purchaseOrdersInStatus(array $statuses, int $limit = 100): array
    {
        if ([] === $statuses) {
            return [];
        }

        $values = array_map(static fn (PoStatus $status): int => $status->value, $statuses);

        return [...PurchaseOrder::find(
            'status IN ('.implode(',', array_fill(0, count($values), '?')).')',
            $values,
            'ORDER BY `id` DESC LIMIT '.max(1, $limit),
        )];
    }

    /**
     * @param list<int> $poIds
     *
     * @return array<int, int>
     */
    #[\Override]
    public function lineCountsForPurchaseOrders(array $poIds): array
    {
        if ([] === $poIds) {
            return [];
        }
        $session = $this->session ?? $this->connection->session;
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        $rows = $session->fetchAll(
            sprintf('SELECT po_id, COUNT(*) AS n FROM `%s` WHERE po_id IN (%s) GROUP BY po_id', $this->table('invflux_po_lines'), $placeholders),
            $poIds,
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['po_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<int> $poIds
     *
     * @return array<int, array{over: int, short: int}>
     */
    #[\Override]
    public function deliveryDiscrepancyRollup(array $poIds): array
    {
        if ([] === $poIds) {
            return [];
        }
        $session = $this->session ?? $this->connection->session;
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        // Set-based mirror of VarianceStatus::classify() under the Ordered lens: over = received beyond
        // ordered; short = received below ordered on a finalized line (qty_open = 0, i.e. closed-short).
        // qty_open is the DB-generated GREATEST(0, ordered - received - closed_short).
        $rows = $session->fetchAll(
            sprintf(
                'SELECT po_id,'
                .' SUM(CASE WHEN qty_received > qty_requested THEN 1 ELSE 0 END) AS over_n,'
                .' SUM(CASE WHEN qty_received < qty_requested AND qty_open = 0 THEN 1 ELSE 0 END) AS short_n'
                .' FROM `%s` WHERE po_id IN (%s) GROUP BY po_id'
                .' HAVING over_n > 0 OR short_n > 0',
                $this->table('invflux_po_lines'),
                $placeholders,
            ),
            $poIds,
        );

        $rollup = [];
        foreach ($rows as $row) {
            $rollup[(int) $row['po_id']] = ['over' => (int) $row['over_n'], 'short' => (int) $row['short_n']];
        }

        return $rollup;
    }

    /**
     * @return array<int, array<int, int>>
     */
    #[\Override]
    public function statusCountsBySupplier(): array
    {
        $session = $this->session ?? $this->connection->session;
        $rows = $session->fetchAll(
            // Filed-away orders are out: this feeds the suppliers list's open/draft counts, which
            // are about the working set. An archived order keeps its real status, so without this
            // clause it would keep being counted as open or as a draft forever.
            sprintf(
                'SELECT supplier_id, status, COUNT(*) AS n FROM `%s` WHERE archived_at IS NULL GROUP BY supplier_id, status',
                $this->table('invflux_pos'),
            ),
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['supplier_id']][(int) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** Prefixed, backtick-safe table name for a raw aggregate query. */
    private function table(string $name): string
    {
        return str_replace('`', '``', $this->tablePrefix.$name);
    }

    /**
     * @param list<PurchaseOrderLine> $lines
     *
     * @return list<PurchaseOrderLine>
     */
    #[\Override]
    public function savePurchaseOrderLines(array $lines): array
    {
        if ([] === $lines) {
            return [];
        }

        // One bulk INSERT for new lines, one deadlock-safe upsert for keyed ones; upsertAll()
        // back-fills the generated ids onto the new records, so callers can use them.
        (new RecordSet($lines))->upsertAll();

        return $lines;
    }

    #[\Override]
    public function deletePurchaseOrderLine(PurchaseOrderLine $line): void
    {
        $line->delete();
    }

    #[\Override]
    public function countOpenProcurementForSubject(SubjectId $subjectId): int
    {
        $session = $this->session ?? $this->connection->session;
        // Open = any PO that is not a draft (in-prep), not cancelled, and not filed away: a
        // submitted / in-transit / in-reception / partially-received / received-but-not-closed PO
        // can still move quantity, so it blocks. Archiving is a column, not a status, so it needs
        // its own clause. Distinct PO count (a PO with two lines on the subject is one).
        $count = $session->fetchScalar(
            sprintf(
                'SELECT COUNT(DISTINCT p.id)
                 FROM `%s` l JOIN `%s` p ON p.id = l.po_id
                 WHERE l.subject_id = ? AND p.archived_at IS NULL AND p.status NOT IN (%d, %d)',
                $this->table('invflux_po_lines'),
                $this->table('invflux_pos'),
                PoStatus::InPrep->value,
                PoStatus::Cancelled->value,
            ),
            [$subjectId->id],
        );

        return (int) $count;
    }

    #[\Override]
    public function removeSubjectFromDraftPurchaseOrders(SubjectId $subjectId): int
    {
        $session = $this->session ?? $this->connection->session;

        return $session->exec(
            sprintf(
                'DELETE l FROM `%s` l JOIN `%s` p ON p.id = l.po_id
                 WHERE l.subject_id = ? AND p.status = %d',
                $this->table('invflux_po_lines'),
                $this->table('invflux_pos'),
                PoStatus::InPrep->value,
            ),
            [$subjectId->id],
        );
    }

    #[\Override]
    public function removeSubjectFromCatalogues(int $subjectId): int
    {
        $session = $this->session ?? $this->connection->session;

        return $session->exec(
            sprintf('DELETE FROM `%s` WHERE subject_id = ?', $this->table('invflux_supplier_products')),
            [$subjectId],
        );
    }

    /**
     * @return list<PurchaseOrderLine>
     */
    #[\Override]
    public function linesForPurchaseOrder(int $poId): array
    {
        return [...PurchaseOrderLine::where('po_id', $poId)];
    }

    /**
     * @param list<ReceiptLine> $lines
     */
    #[\Override]
    public function recordReceipt(GoodsReceipt $receipt, array $lines): GoodsReceipt
    {
        return $this->transactional(function () use ($receipt, $lines): GoodsReceipt {
            $receipt->save();
            $receiptId = (int) $receipt->id;

            // Bulk-insert the receipt lines (one INSERT), stamping the receipt id on each.
            foreach ($lines as $line) {
                $line->receipt_id = $receiptId;
            }
            (new RecordSet($lines))->upsertAll();

            // Bump every referenced PO line's app-maintained rollups (`qty_received` = good units,
            // `qty_damaged` = damaged units) in bulk: accumulate the per-PO-line deltas, lock the lines
            // via LockSet (tier 34, ascending PK — re-entrant when ReceiveGoods already holds them),
            // apply the deltas in memory, and upsertAll() once. No per-line getOne/save loop. qty_open is
            // a generated column the DB recomputes from the new qty_received.
            // A line with no ordered line behind it — a source-less intake — has no rollup to bump,
            // and is skipped rather than defaulted. A null array key would silently become "" and
            // send an id-shaped nothing into the lock below.
            $receivedByPoLineId = [];
            $damagedByPoLineId = [];
            foreach ($lines as $line) {
                if (null === $line->po_line_id) {
                    continue;
                }
                $receivedByPoLineId[$line->po_line_id] = ($receivedByPoLineId[$line->po_line_id] ?? 0) + $line->qty;
                $damagedByPoLineId[$line->po_line_id] = ($damagedByPoLineId[$line->po_line_id] ?? 0) + $line->damaged_qty;
            }

            if ([] !== $receivedByPoLineId) {
                // LockSet builds the FOR UPDATE read, so it needs the dialect too. When this
                // store was handed its own session, wrap it with the injected connection's
                // dialect rather than falling back to the connection's session — the point of
                // the override is that the locks land on the session the caller owns.
                $connection = null !== $this->session
                    ? new Connection($this->session, $this->connection->dialect)
                    : $this->connection;
                $locked = LockSet::acquire($connection, [PurchaseOrderLine::class => array_keys($receivedByPoLineId)]);
                $poLines = $locked[PurchaseOrderLine::class];
                foreach ($poLines as $poLine) {
                    /** @var PurchaseOrderLine $poLine */
                    $poLine->qty_received += $receivedByPoLineId[(int) $poLine->id] ?? 0;
                    $poLine->qty_damaged += $damagedByPoLineId[(int) $poLine->id] ?? 0;
                }
                $poLines->upsertAll();
            }

            return $receipt;
        });
    }

    /**
     * The invoice, its lines and the write-back onto the ordered lines, in one transaction.
     *
     * The ordered lines arrive already carrying their new `qty_invoiced` / `unit_cost_invoiced` —
     * the caller computed them, because the two are maintained differently and only it knows the
     * prior documents. This method's job is that the document and the write-back land together, so
     * the order can never end up quoting a rate no invoice supports.
     *
     * @param list<SupplierInvoiceLine> $lines
     * @param list<PurchaseOrderLine>   $touchedOrderLines
     */
    #[\Override]
    public function recordSupplierInvoice(
        SupplierInvoice $invoice,
        array $lines,
        array $touchedOrderLines,
    ): SupplierInvoice {
        return $this->transactional(function () use ($invoice, $lines, $touchedOrderLines): SupplierInvoice {
            $invoice->save();
            $invoiceId = (int) $invoice->id;

            foreach ($lines as $line) {
                $line->invoice_id = $invoiceId;
            }
            (new RecordSet($lines))->upsertAll();

            // The caller handed these back already mutated, so this is a bulk write and not a
            // read-modify-write: locking them again to re-read would discard what it computed.
            if ([] !== $touchedOrderLines) {
                (new RecordSet($touchedOrderLines))->upsertAll();
            }

            return $invoice;
        });
    }

    /**
     * @return list<SupplierInvoice>
     */
    #[\Override]
    public function supplierInvoicesForPurchaseOrder(int $poId): array
    {
        return [...SupplierInvoice::find('po_id = ?', [$poId], orderByLimit: 'ORDER BY id ASC')];
    }

    /**
     * @param list<int> $poLineIds
     *
     * @return array<int, list<SupplierInvoiceLine>>
     */
    #[\Override]
    public function supplierInvoiceLinesForOrderLines(array $poLineIds): array
    {
        if ([] === $poLineIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, \count($poLineIds), '?'));
        $byOrderLine = [];
        // Ordered by id so "the latest document" means the same thing here as it does to the caller.
        foreach (SupplierInvoiceLine::find("po_line_id IN ($placeholders)", $poLineIds, orderByLimit: 'ORDER BY id ASC') as $line) {
            $byOrderLine[$line->po_line_id][] = $line;
        }

        return $byOrderLine;
    }

    /**
     * @return list<GoodsReceipt>
     */
    #[\Override]
    public function receiptsForPurchaseOrder(int $poId): array
    {
        $refTypeId = $this->purchaseOrderRefTypeId();
        if (null === $refTypeId) {
            return [];
        }

        return [...GoodsReceipt::find(
            'source_ref_type_id = ? AND source_id = ?',
            [$refTypeId, $poId],
        )];
    }

    /**
     * The registry id of the `purchase_order` ref type, memoized for the request.
     *
     * A receipt names *what kind* of document it answers by id, not by code, so reading back the
     * receipts of one purchase order means resolving the code once. Seeded at install alongside the
     * other ref types; null only on a store whose seed has not run, where the honest answer to "which
     * receipts answer this order" is none rather than an exception.
     */
    #[\Override]
    public function purchaseOrderRefTypeId(): ?int
    {
        return $this->purchaseOrderRefTypeId ??= RefTypeRecord::findOne('code = ?', ['purchase_order'])?->id;
    }

    #[\Override]
    public function receivingSessionForPurchaseOrder(int $poId): ?ReceivingSession
    {
        $refTypeId = $this->purchaseOrderRefTypeId();

        return null === $refTypeId
            ? null
            : ReceivingSession::findOne('source_ref_type_id = ? AND source_id = ?', [$refTypeId, $poId]);
    }

    #[\Override]
    public function receivingSessionsForPurchaseOrders(array $poIds): array
    {
        $refTypeId = $this->purchaseOrderRefTypeId();
        if (null === $refTypeId || [] === $poIds) {
            return [];
        }

        // Row-value seek on (source_ref_type_id, source_id) — the session table's unique key, so
        // this is one index lookup per order rather than a scan filtered afterwards.
        $out = [];
        foreach (ReceivingSession::whereInTuples(
            ['source_ref_type_id', 'source_id'],
            array_map(static fn (int $poId): array => [$refTypeId, $poId], $poIds),
        ) as $session) {
            $out[(int) $session->source_id] = $session;
        }

        return $out;
    }

    #[\Override]
    public function findReceivingSession(int $id): ?ReceivingSession
    {
        return ReceivingSession::getOne($id);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, PurchaseOrder>
     */
    #[\Override]
    public function purchaseOrdersByIds(array $ids): array
    {
        $unique = array_values(array_unique($ids));
        if ([] === $unique) {
            return [];
        }

        $out = [];
        foreach (PurchaseOrder::find(
            'id IN ('.implode(',', array_fill(0, count($unique), '?')).')',
            $unique,
        ) as $po) {
            $out[(int) $po->id] = $po;
        }

        return $out;
    }

    /**
     * @return list<GoodsReceipt>
     */
    #[\Override]
    public function recentGoodsReceipts(int $limit = 25, int $offset = 0): array
    {
        return [...GoodsReceipt::find(
            orderByLimit: sprintf(
                'ORDER BY received_at DESC, id DESC LIMIT %d OFFSET %d',
                max(1, $limit),
                max(0, $offset),
            ),
        )];
    }

    /**
     * @param list<int> $receiptIds
     *
     * @return array<int, array{lines: int, qty: int, damaged: int}>
     */
    #[\Override]
    public function goodsReceiptLineTotals(array $receiptIds): array
    {
        $ids = array_values(array_unique($receiptIds));
        if ([] === $ids) {
            return [];
        }

        // Raw SQL for a grouped aggregate, which attrecord cannot express — the same justification
        // as every other rollup here, and it retreats when attrecord grows the capability.
        $session = $this->session ?? $this->connection->session;
        $rows = $session->fetchAll(
            sprintf(
                'SELECT receipt_id, COUNT(*) AS lines_count, COALESCE(SUM(qty), 0) AS qty,
                        COALESCE(SUM(damaged_qty), 0) AS damaged
                   FROM `%s`
                  WHERE receipt_id IN (%s)
                  GROUP BY receipt_id',
                $this->table('invflux_receipt_lines'),
                implode(',', array_fill(0, count($ids), '?')),
            ),
            $ids,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['receipt_id']] = [
                'lines'   => (int) $row['lines_count'],
                'qty'     => (int) $row['qty'],
                'damaged' => (int) $row['damaged'],
            ];
        }

        return $out;
    }

    /**
     * @return list<ReceivingSession>
     */
    #[\Override]
    public function openReceivingSessions(int $limit = 100): array
    {
        // No predicate: every row in this table is an open reception. The table is emptied by the
        // receipts it produces, so its size is the number of counts currently under way.
        return [...ReceivingSession::find(
            orderByLimit: 'ORDER BY updated_at DESC LIMIT '.max(1, $limit),
        )];
    }

    #[\Override]
    public function saveReceivingSession(ReceivingSession $session): ReceivingSession
    {
        $session->save();

        return $session;
    }

    #[\Override]
    public function deleteReceivingSession(int $id): void
    {
        ReceivingSession::getOne($id)?->delete();
    }

    #[\Override]
    public function poEventsForPurchaseOrder(int $poId): array
    {
        // Oldest first — sort by the monotonic PK (occurred_at is optional; recorded_at is the wall time).
        $events = [...PoEvent::where('po_id', $poId)];
        usort($events, static fn (PoEvent $a, PoEvent $b): int => ($a->id ?? 0) <=> ($b->id ?? 0));

        return $events;
    }

    #[\Override]
    public function recordPoEvent(PoEvent $event): PoEvent
    {
        $event->save();

        return $event;
    }

    /**
     * Reserve the next sequence for the scheme's series: lock the counter row
     * (`SELECT … FOR UPDATE` inside the assign txn), read `next_value`, bump it. The
     * counter row is created on first use at the scheme's start value.
     *
     * The scheme's start value is a **floor**, not merely a seed: a counter sitting below it is
     * raised to it before the read. So configuring a higher start after some numbers have been
     * issued skips the sequence forward (as the operator intends), while a *lower* one is ignored —
     * an issued number can never be handed out twice, which is the whole point of a document sequence.
     *
     * Production hardening (later): seed the counter when a series is configured so the
     * first-PO path never races on the INSERT; until then the per-series PK makes a
     * concurrent duplicate INSERT fail safely (that txn rolls back and retries).
     */
    private function nextPoSequence(PoNumberScheme $scheme): int
    {
        $series = $scheme->seriesKey();
        $counter = PoNumberCounter::getOne($series, forUpdate: true)
            ?? PoNumberCounter::newWith(['series_key' => $series, 'next_value' => $scheme->startValue()]);

        $counter->next_value = max($counter->next_value, $scheme->startValue());
        $sequence = $counter->next_value;
        $counter->next_value = $sequence + 1;
        $counter->updated_at = new \DateTimeImmutable();
        $counter->save();

        return $sequence;
    }

    /** Return the table prefix used for all domain tables. */
    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Seed the base entries for each registry.
     *
     * Gated on `countWhere('1 = 1') === 0`: MySQL's `INSERT … ON DUPLICATE KEY
     * UPDATE` burns an AUTO_INCREMENT value on every call, even when no row is
     * inserted (innodb_autoinc_lock_mode default since 5.1). For TINYINT PKs
     * (255 max) this overflows after ~36 plugin boots. The empty-table check
     * means re-installs and routine wp-cli invocations don't tick the counter.
     *
     * Trade-off: if a row is manually deleted from the DB, this seeder won't
     * restore it on next boot. Acceptable — these are install-time invariants,
     * not runtime-managed data.
     */
    private function seedReferenceTables(): void
    {
        if (0 === ActorTypeRecord::countWhere('1 = 1')) {
            $actorTypes = [
                ['code' => 'admin',    'name' => 'Back-office user'],
                ['code' => 'system',   'name' => 'InvFlux core'],
                ['code' => 'plugin',   'name' => 'Add-on plugin action'],
                ['code' => 'supplier', 'name' => 'Supplier'],
            ];
            foreach ($actorTypes as $row) {
                ActorTypeRecord::newWith($row)->save();
            }
        }

        if (0 === SurfaceTypeRecord::countWhere('1 = 1')) {
            $surfaceTypes = [
                ['code' => 'admin_page', 'name' => 'Admin Page'],
                ['code' => 'api',        'name' => 'API'],
                ['code' => 'cli',        'name' => 'CLI'],
                ['code' => 'plugin',     'name' => 'Plugin'],
                ['code' => 'system',     'name' => 'System'],
                ['code' => 'import',     'name' => 'Import'],
                ['code' => 'job',        'name' => 'Scheduled Job'],
            ];
            foreach ($surfaceTypes as $row) {
                SurfaceTypeRecord::newWith($row)->save();
            }
        }

        if (0 === RefTypeRecord::countWhere('1 = 1')) {
            // identity_class: 'uuid' (default) for hot-path UUIDv7 documents; 'int' for
            // admin-minted auto-increment entities (POs, schema/dimension/setting events)
            // whose id lands in inventory_ledger.ref_int_id.
            $refTypes = [
                ['code' => 'order',            'name' => 'Customer Order'],
                ['code' => 'purchase_order',   'name' => 'Purchase Order',          'identity_class' => 'int'],
                // po_receipt ledger movements ref the goods_receipt (the proximate source doc for the
                // cost join), not the PO. PO grouping is one join away.
                ['code' => 'goods_receipt',    'name' => 'Goods Receipt',           'identity_class' => 'int'],
                ['code' => 'shipment',         'name' => 'Outbound Shipment'],
                ['code' => 'return',           'name' => 'Customer Return'],
                ['code' => 'supplier_return',  'name' => 'Supplier Return'],
                ['code' => 'transfer',         'name' => 'Inter-location Transfer'],
                ['code' => 'stock_take',       'name' => 'Stock Take'],
                ['code' => 'schema_change',    'name' => 'Schema Change',           'identity_class' => 'int'],
                ['code' => 'dimension_value',  'name' => 'Dimension Value Lifecycle', 'identity_class' => 'int'],
                ['code' => 'setting',          'name' => 'Plugin Setting',          'identity_class' => 'int'],
            ];
            foreach ($refTypes as $row) {
                RefTypeRecord::newWith($row)->save();
            }
        }

        if (0 === IdentifierTypeRecord::countWhere('1 = 1')) {
            // Host-neutral types only. An adapter's own identifier types (a WooCommerce
            // post id, a PrestaShop product/combination id, …) are registered by that
            // adapter via registerIdentifierType() — core cannot know a host's id
            // semantics, and seeding them here leaked host vocabulary across the
            // platform-agnostic boundary (a PrestaShop install shipped dead Woo rows).
            $identifierTypes = [
                ['code' => 'sku',              'name' => 'SKU',              'category' => 'internal'],
                ['code' => 'gtin',             'name' => 'GTIN',             'category' => 'barcode'],
                ['code' => 'ean13',            'name' => 'EAN-13',           'category' => 'barcode'],
                ['code' => 'ean8',             'name' => 'EAN-8',            'category' => 'barcode'],
                ['code' => 'upc_a',            'name' => 'UPC-A',            'category' => 'barcode'],
                ['code' => 'upc_e',            'name' => 'UPC-E',            'category' => 'barcode'],
                ['code' => 'isbn13',           'name' => 'ISBN-13',          'category' => 'barcode'],
                ['code' => 'isbn10',           'name' => 'ISBN-10',          'category' => 'barcode'],
                // Manufacturer-assigned, not scannable and not ours: the part number as the
                // maker designates it. Distinct from `sku` (our code) and from the barcode
                // family (a GTIN identifies the retail unit). Standard in product feeds, and
                // carried natively by both hosts we target.
                ['code' => 'mpn',              'name' => 'MPN',              'category' => 'manufacturer'],
                ['code' => 'asin',             'name' => 'ASIN',             'category' => 'marketplace'],
                ['code' => 'supplier_sku',     'name' => 'Supplier SKU',     'category' => 'internal'],
                ['code' => 'supplier_barcode', 'name' => 'Supplier Barcode', 'category' => 'barcode'],
            ];
            foreach ($identifierTypes as $row) {
                IdentifierTypeRecord::newWith($row)->save();
            }
        }

        if (0 === SystemRecord::countWhere('1 = 1')) {
            // Platform-internal identifier namespace (the InvFlux-minted side, parallel to
            // host systems like 'woo'): supplier SKUs/barcodes resolve here scoped per supplier
            // actor, and so do the host-neutral commercial identifiers (`sku`, `gtin`, the
            // barcode family) — the codes a catalogue is matched on rather than addressed by.
            // Host systems ('woo', …) are registered at runtime by adapters.
            SystemRecord::newWith(['slug' => SystemRecord::INVFLUX_SLUG, 'name' => 'InvFlux'])->save();
        }

        if (0 === AdjustmentReason::countWhere('1 = 1')) {
            // Built-in adjustment reason codes, each pinned to a fixed core gl_class so the accounting
            // export maps reason → GL account. Merchants add their own codes against these classes via
            // the settings UI. See arch-erp-parity §8.2.
            $reasons = [
                // negative (write-off): stock leaves
                ['code' => 'missing',         'label' => 'Missing / short count', 'sign' => AdjustmentReason::SIGN_NEGATIVE, 'gl_class' => 'shrinkage_loss',     'requires_note' => false],
                ['code' => 'damaged',         'label' => 'Damaged / unfit',       'sign' => AdjustmentReason::SIGN_NEGATIVE, 'gl_class' => 'scrap_writeoff',      'requires_note' => false],
                ['code' => 'other_write_off', 'label' => 'Other write-off',       'sign' => AdjustmentReason::SIGN_NEGATIVE, 'gl_class' => 'deliberate_writeoff', 'requires_note' => true],
                // positive (write-in): stock appears
                ['code' => 'found',           'label' => 'Found',                 'sign' => AdjustmentReason::SIGN_POSITIVE, 'gl_class' => 'gain',                'requires_note' => false],
                ['code' => 'recount_up',      'label' => 'Recount up',            'sign' => AdjustmentReason::SIGN_POSITIVE, 'gl_class' => 'gain',                'requires_note' => false],
                ['code' => 'other_write_in',  'label' => 'Other write-in',        'sign' => AdjustmentReason::SIGN_POSITIVE, 'gl_class' => 'gain',                'requires_note' => true],
            ];
            foreach ($reasons as $row) {
                $row['is_builtin'] = true;
                $row['active'] = true;
                AdjustmentReason::newWith($row)->save();
            }
        }
    }
}
