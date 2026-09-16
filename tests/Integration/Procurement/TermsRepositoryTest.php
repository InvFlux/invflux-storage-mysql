<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Procurement\PoStatus;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Procurement\Terms;
use Nandan108\InvFlux\Domain\Procurement\TermsLineage;
use Nandan108\InvFlux\Domain\Procurement\TermsVersion;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Interning purchase terms — the shared, content-addressed table an issued order points at.
 *
 * These exercise the property that makes the pointer safe: a row's identity is the artefact it
 * holds, computed by the database rather than stated by us, so the same terms always reach the same
 * row and a row many orders point at cannot be rewritten.
 */
final class TermsRepositoryTest extends TestCase
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
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['invflux_terms_versions', 'invflux_terms_lineages', 'invflux_terms'] as $table) {
            $this->pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $connection = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connection);

        $this->domainStore = new MysqlDomainStore($connection, tablePrefix: '', session: $session);
        SchemaFixture::install($connection);
        $this->domainStore->installReferenceTables();
        (new MysqlInventoryStore($session))->bootstrap((new SlotSpaceFactory())->createLayered());
        $this->domainStore->bootstrap();
    }

    /** The same terms interned twice are one row, and the caller gets the same pointer back. */
    public function testIdenticalTermsInternToOneRow(): void
    {
        $first = $this->store()->internTerms("1. Delivery.\n2. Payment.");
        $again = $this->store()->internTerms("1. Delivery.\n2. Payment.");

        self::assertSame($first, $again);
        self::assertSame(1, $this->countRows());
        self::assertSame($first, (int) $this->pdo()->query('SELECT content_hash FROM invflux_terms')->fetchColumn(), 'the hash comes back exactly as stored');
    }

    /** Different terms are a different row, and the first is left exactly as it was. */
    public function testDifferentTermsAreANewRow(): void
    {
        $old = $this->store()->internTerms('1. Net 30.');
        $new = $this->store()->internTerms('1. Net 60.');

        self::assertNotSame($old, $new);
        self::assertSame(2, $this->countRows());
        self::assertSame('1. Net 30.', $this->bodyOf($old), 'the earlier text is untouched');
    }

    /**
     * **Case is content.** The digest is over bytes, so terms differing only in case are different
     * artefacts and must reach different rows.
     *
     * This is the discriminating case for how interning recognises its own row. The column's
     * collation is case- and accent-insensitive, so `WHERE body = ?` — or a comparison made in SQL —
     * would treat this text as the row below and hand back a hash belonging to terms the merchant
     * never wrote. Nothing else in this file would notice.
     */
    public function testTermsDifferingOnlyInCaseDoNotShareARow(): void
    {
        $lower = $this->store()->internTerms('goods remain at the seller risk.');
        $upper = $this->store()->internTerms('GOODS REMAIN AT THE SELLER RISK.');

        self::assertNotSame($lower, $upper);
        self::assertSame(2, $this->countRows());
        self::assertSame('goods remain at the seller risk.', $this->bodyOf($lower));
        self::assertSame('GOODS REMAIN AT THE SELLER RISK.', $this->bodyOf($upper));

        // And the trap is real rather than theoretical: matching on the text itself finds *both*
        // rows, so an implementation that located an existing row that way would return whichever
        // came back first. Asserted here so a future reader can see why this is not over-caution.
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM invflux_terms WHERE body = ?');
        $stmt->execute(['goods remain at the seller risk.']);
        self::assertSame(2, (int) $stmt->fetchColumn(), 'the column collation ignores case, the digest does not');
    }

    /**
     * A printed reference is part of the artefact, so the same body under two references is two
     * rows — they print differently, and dedup must collapse only what is genuinely identical.
     */
    public function testAPrintedReferenceIsPartOfTheIdentity(): void
    {
        $bare = $this->store()->internTerms('1. Net 30.');
        $referenced = $this->store()->internTerms('1. Net 30.', 'TC-2026-A');

        self::assertNotSame($bare, $referenced);
        self::assertSame(2, $this->countRows());
    }

    /**
     * A printed reference is fixed once assigned, so reusing it for other terms is refused rather
     * than moved — moving it would print one reference on two different artefacts.
     */
    public function testAReferenceAlreadyPrintedOnOtherTermsIsRefused(): void
    {
        $this->store()->internTerms('1. Net 30.', 'TC-2026-A');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('TC-2026-A');
        $this->store()->internTerms('1. Net 60.', 'TC-2026-A');
    }

    /**
     * A 64-bit key is an identity, not a proof. Two different texts that collide must neither share a
     * row — one order would print the other's terms — nor make the newcomer unstorable, since the
     * digest is deterministic and a refusal would repeat on every retry. The newcomer moves to its
     * next attempt instead.
     *
     * Simulated by redefining the digest so that every row at its first key collides: a real 64-bit
     * collision cannot be found by search, and a generated column cannot be written.
     */
    public function testACollidingTextMovesToAKeyOfItsOwn(): void
    {
        $this->collideEveryFirstKey();
        $incumbent = $this->store()->internTerms('1. Net 30.');

        $newcomer = $this->store()->internTerms('1. Net 60.');

        self::assertNotSame($incumbent, $newcomer, 'the newcomer moved to a key of its own');
        self::assertSame('1. Net 60.', $this->bodyOf($newcomer), 'and kept its own text');
        self::assertSame('1. Net 30.', $this->bodyOf($incumbent), 'the incumbent is untouched');
        self::assertSame(2, $this->countRows());
    }

    /** Moving is deterministic, so the same text interned again finds the row it moved to. */
    public function testARepeatedCollisionResolvesToTheSameRow(): void
    {
        $this->collideEveryFirstKey();
        $this->store()->internTerms('1. Net 30.');

        $first = $this->store()->internTerms('1. Net 60.');
        $again = $this->store()->internTerms('1. Net 60.');

        self::assertSame($first, $again);
        self::assertSame(2, $this->countRows(), 'no second row for the same text');
    }

    /** Collisions that exhaust every attempt are corruption, and interning says so rather than looping. */
    public function testExhaustingEveryAttemptFailsLoudly(): void
    {
        $this->redefineDigest('42');
        $this->store()->internTerms('1. Net 30.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exhausted');
        $this->store()->internTerms('1. Net 60.');
    }

    /** Editing a referenced row is refused by the database, not by convention. */
    public function testTextAnOrderPointsAtCannotBeRewritten(): void
    {
        $hash = $this->store()->internTerms('1. Net 30.');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO invflux_terms_lineages (name, created_at) VALUES (?, NOW(6))',
        );
        $stmt->execute(['Standard purchase terms']);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO invflux_terms_versions (lineage_id, ordinal, content_hash, is_current, created_at)
             VALUES (LAST_INSERT_ID(), 1, ?, 1, NOW(6))',
        );
        $stmt->execute([$hash]);

        $this->expectException(\PDOException::class);
        $this->pdo()->exec("UPDATE invflux_terms SET body = '1. Net 7.'");
    }

    /** A set is created together with its first edition, which is then its current one. */
    public function testASetIsCreatedWithItsFirstEdition(): void
    {
        [$lineage, $first] = $this->aSet('Standard', '1. Net 30.');

        self::assertNotNull($lineage->id);
        self::assertSame(['Standard'], array_map(static fn (TermsLineage $l): string => $l->name, $this->store()->termsLineages()));
        $current = $this->store()->currentTermsVersions([$lineage->id])[$lineage->id] ?? null;
        self::assertNotNull($current);
        self::assertSame($first->content_hash, $current->content_hash);
    }

    /** A new edition and the retirement of the one it replaces are one write, in the order the schema needs. */
    public function testANewEditionRetiresTheCurrentOneInTheSameWrite(): void
    {
        [$lineage, $first] = $this->aSet('Standard', '1. Net 30.');
        $first->is_current = null;
        $first->retired_at = new \DateTimeImmutable();

        $this->store()->saveTermsVersion($this->edition($lineage, 2, '1. Net 60.'), $first);

        self::assertSame([1, 2], array_map(static fn (TermsVersion $v): int => $v->ordinal, $this->store()->termsVersionsOf((int) $lineage->id)));
        self::assertSame(2, ($this->store()->currentTermsVersions([(int) $lineage->id])[(int) $lineage->id] ?? null)?->ordinal);
    }

    /** One current edition per set is the schema's rule, not a convention: minting without retiring fails. */
    public function testTheSchemaAdmitsOneCurrentEditionPerSet(): void
    {
        [$lineage] = $this->aSet('Standard', '1. Net 30.');

        $refused = null;
        try {
            $this->store()->saveTermsVersion($this->edition($lineage, 2, '1. Net 60.'));
        } catch (\Exception $e) {
            $refused = $e;
        }

        self::assertNotNull($refused, 'a second current edition was stored');
        self::assertStringContainsString('uq_terms_version_current', $refused->getMessage());
        self::assertCount(1, $this->store()->termsVersionsOf((int) $lineage->id), 'and the refused write left nothing behind');
    }

    /**
     * Usage is counted per text, across however many orders carry it; a text no order carries is
     * absent. The texts and numbers are unique per run, because this database keeps its orders between
     * runs and an order under yesterday's identical text would count today.
     */
    public function testOrdersAreCountedPerText(): void
    {
        $run = bin2hex(random_bytes(4));
        $used = $this->store()->internTerms('1. Net 30. '.$run);
        $unused = $this->store()->internTerms('1. Net 60. '.$run);
        // Through the repository, which registers the supplier's actor: a bare save breaks its FK.
        $supplier = $this->store()->createSupplier(Supplier::newWith(['name' => 'Terms test '.$run]));
        foreach (['A', 'B'] as $suffix) {
            PurchaseOrder::newWith([
                'number'      => sprintf('TERMS-%s-%s', $run, $suffix),
                'supplier_id' => $supplier->id,
                'currency'    => 'EUR',
                'status'      => PoStatus::InPrep,
                'terms_hash'  => $used,
            ])->save();
        }

        self::assertSame([$used => 2], $this->store()->orderCountsForTerms([$used, $unused]));
        self::assertSame([], $this->store()->orderCountsForTerms([]));
    }

    /** Deleting a set takes its editions with it, and leaves the texts, which belong to no set. */
    public function testDeletingASetTakesItsEditionsAndLeavesTheText(): void
    {
        [$lineage] = $this->aSet('Standard', '1. Net 30.');

        self::assertSame([], $this->store()->deleteTermsLineage($lineage), 'no draft followed it');

        self::assertNull($this->store()->findTermsLineage((int) $lineage->id));
        self::assertSame([], $this->store()->termsVersionsOf((int) $lineage->id));
        self::assertSame(1, $this->countRows(), 'the text stays interned');
    }

    /**
     * A draft following a set is released with it — pointed at no set, so it inherits — whether the
     * set is deleted or archived. Without the release, the delete would fail on the draft's foreign key.
     */
    public function testDeletingOrArchivingASetReleasesTheDraftsFollowingIt(): void
    {
        $run = bin2hex(random_bytes(4));
        [$deleted] = $this->aSet('Deleted '.$run, '1. Net 30. '.$run);
        [$archived] = $this->aSet('Archived '.$run, '1. Net 45. '.$run);
        $supplier = $this->store()->createSupplier(Supplier::newWith(['name' => 'Terms test '.$run]));
        $draft = static fn (TermsLineage $set): PurchaseOrder => PurchaseOrder::newWith([
            'supplier_id'      => $supplier->id,
            'currency'         => 'EUR',
            'status'           => PoStatus::InPrep,
            'terms_lineage_id' => $set->id,
        ])->save();
        $a = $draft($deleted);
        $b = $draft($archived);

        self::assertSame([(int) $a->id], $this->store()->deleteTermsLineage($deleted));
        $archived->removed_at = new \DateTimeImmutable();
        self::assertSame([(int) $b->id], $this->store()->archiveTermsLineage($archived));

        foreach ([$a, $b] as $po) {
            self::assertNull(PurchaseOrder::findOne('id = ?', [$po->id])?->terms_lineage_id, 'the draft follows no set');
        }
        self::assertNotNull($this->store()->findTermsLineage((int) $archived->id)?->removed_at, 'and the archival was stored');
    }

    /** A removed set leaves the listing and comes back only when asked for: it is archived, not gone. */
    public function testARemovedSetIsListedOnlyWhenAskedFor(): void
    {
        [$lineage] = $this->aSet('Standard', '1. Net 30.');
        $lineage->removed_at = new \DateTimeImmutable();
        $this->store()->saveTermsLineage($lineage);

        self::assertSame([], $this->store()->termsLineages());
        self::assertCount(1, $this->store()->termsLineages(includeRemoved: true));
    }

    /** @return array{TermsLineage, TermsVersion} */
    private function aSet(string $name, string $body): array
    {
        $first = TermsVersion::newWith(['ordinal' => 1, 'content_hash' => $this->store()->internTerms($body), 'is_current' => true]);

        return [$this->store()->createTermsLineage(TermsLineage::newWith(['name' => $name]), $first), $first];
    }

    private function edition(TermsLineage $lineage, int $ordinal, string $body): TermsVersion
    {
        return TermsVersion::newWith([
            'lineage_id'   => (int) $lineage->id,
            'ordinal'      => $ordinal,
            'content_hash' => $this->store()->internTerms($body),
            'is_current'   => true,
        ]);
    }

    /** Every row still at its first key hashes to one value; a moved row hashes as it really would. */
    private function collideEveryFirstKey(): void
    {
        $this->redefineDigest(sprintf('IF(`attempt` IS NULL, 42, %s)', self::digestExpression()));
    }

    private function redefineDigest(string $expression): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo()->exec(sprintf('ALTER TABLE invflux_terms MODIFY content_hash BIGINT AS (%s) STORED', $expression));
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private static function digestExpression(): string
    {
        $attributes = (new \ReflectionProperty(Terms::class, 'content_hash'))->getAttributes(Column::class);
        $expression = $attributes[0]->newInstance()->generatedAs;
        self::assertIsString($expression);

        return $expression;
    }

    private function bodyOf(int $hash): string
    {
        $stmt = $this->pdo()->prepare('SELECT body FROM invflux_terms WHERE content_hash = ?');
        $stmt->execute([$hash]);

        return (string) $stmt->fetchColumn();
    }

    private function countRows(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM invflux_terms')->fetchColumn();
    }

    private function pdo(): \PDO
    {
        self::assertNotNull($this->pdo);

        return $this->pdo;
    }

    private function store(): MysqlDomainStore
    {
        self::assertNotNull($this->domainStore);

        return $this->domainStore;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
