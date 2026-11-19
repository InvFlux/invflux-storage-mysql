<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Location;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Application\Location\ArchiveLocation;
use Nandan108\InvFlux\Application\Location\RestoreLocation;
use Nandan108\InvFlux\Application\Location\SaveLocation;
use Nandan108\InvFlux\Application\Location\SetSubjectLocations;
use Nandan108\InvFlux\Domain\Location\Location;
use Nandan108\InvFlux\Domain\Location\LocationCode;
use Nandan108\InvFlux\Domain\Location\LocationTree;
use Nandan108\InvFlux\Domain\Location\LocationWriteGuard;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\LocationException;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\Location\MysqlLocationRepository;
use Nandan108\InvFlux\Storage\Mysql\Location\MysqlSubjectLocationReader;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * The location tree and the product links, written through core's use cases and read back through
 * this package's repository and reader, against the real schema: paths and sort keys, the refusals,
 * retirement, ranked links updated in place, and the variation rule.
 */
final class LocationsTest extends TestCase
{
    private ?\PDO $db = null;

    private ?Connection $conn = null;

    private ?MysqlInventoryStore $inventory = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->db = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::env('INVFLOW_DB_HOST', '127.0.0.1'),
                self::env('INVFLOW_DB_PORT', '33067'),
                self::env('INVFLOW_DB_NAME', 'invflux_test'),
            ),
            self::env('INVFLOW_DB_USER', 'invflux'),
            self::env('INVFLOW_DB_PASS', 'invflux'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        );
        $session = new PdoMysqlSession($this->db);
        $this->dropTables();

        $this->conn = new Connection(new PdoDbSession($this->db), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($this->conn);

        $domainStore = new MysqlDomainStore($this->conn, tablePrefix: '', session: $session);
        SchemaFixture::install($this->conn);
        $domainStore->installReferenceTables();
        $this->inventory = new MysqlInventoryStore($session);
        $this->inventory->bootstrap((new SlotSpaceFactory())->createLayered());
        $domainStore->bootstrap();
    }

    private function pdo(): \PDO
    {
        \assert(null !== $this->db);

        return $this->db;
    }

    private function locations(): MysqlLocationRepository
    {
        return new MysqlLocationRepository();
    }

    private function store(): MysqlInventoryStore
    {
        \assert(null !== $this->inventory);

        return $this->inventory;
    }

    // ── The tree ────────────────────────────────────────────────────────────────────────────────

    public function testAChildsPathIsItsParentsPlusItsCodeAndItsSortKeyPadsDigits(): void
    {
        $zone = $this->save(null, 'zB', name: 'Zone B');
        $rack = $this->save($zone->id, 'r08');

        self::assertSame('zB/r08', $rack->path);
        self::assertSame('zb r0000000008', $rack->sort_key);
        self::assertSame('Zone B', $this->locations()->find((int) $zone->id)?->name);
    }

    /**
     * The order the database gives `sort_key` is the order the tree walks: digits naturally, and a
     * location's sublocations before its next sibling even when that sibling's code extends its own.
     */
    public function testSortKeyOrdersNaturallyAndKeepsSublocationsUnderTheirParent(): void
    {
        $zone = $this->save(null, 'zb');
        foreach (['r10', 'r4', 'r08'] as $code) {
            $this->save($zone->id, $code);
        }
        $this->save(null, 'zb-x');
        $this->save(null, 'za');

        $ordered = array_column(
            $this->pdo()->query('SELECT path FROM invflux_locations ORDER BY sort_key')->fetchAll(),
            'path',
        );
        self::assertSame(['za', 'zb', 'zb/r4', 'zb/r08', 'zb/r10', 'zb-x'], $ordered);
        self::assertSame($ordered, array_map(static fn (Location $l): string => $l->path, $this->locations()->tree()->all()));
    }

    public function testAPathTakenIgnoringCaseIsRefusedAndARetiredHolderSaysRestore(): void
    {
        $zone = $this->save(null, 'zB');
        $this->expectReason(LocationException::PATH_TAKEN, fn () => $this->save(null, 'ZB'));

        (new ArchiveLocation($this->locations(), $this->store()))((int) $zone->id);
        $e = $this->expectReason(LocationException::PATH_TAKEN, fn () => $this->save(null, 'zb'));
        self::assertTrue($e->context()['archived']);
    }

    public function testRenamingRewritesThePathsAndSortKeysOfTheWholeSubtree(): void
    {
        $zone = $this->save(null, 'zb');
        $aisle = $this->save($zone->id, 'a3');
        $rack = $this->save($aisle->id, 'r8');

        $this->save(null, 'zc', id: $zone->id);

        $rackNow = $this->locations()->find((int) $rack->id);
        self::assertNotNull($rackNow);
        self::assertSame('zc/a3/r8', $rackNow->path);
        self::assertSame(LocationCode::sortKey('zc/a3/r8'), $rackNow->sort_key);
        self::assertSame((int) $rack->id, (int) $rackNow->id, 'the id never changes: links and label barcodes key on it');
    }

    public function testMovingUnderAnotherParentCarriesTheSubtree(): void
    {
        $a = $this->save(null, 'za');
        $b = $this->save(null, 'zb');
        $aisle = $this->save($a->id, 'a1');
        $rack = $this->save($aisle->id, 'r1');

        $this->save($b->id, 'a1', id: $aisle->id);

        self::assertSame('zb/a1/r1', $this->locations()->find((int) $rack->id)?->path);
        self::assertSame((int) $b->id, $this->locations()->find((int) $aisle->id)?->parent_id);
    }

    public function testMovingALocationUnderItselfOrItsOwnSublocationIsRefused(): void
    {
        $zone = $this->save(null, 'zb');
        $aisle = $this->save($zone->id, 'a1');

        $this->expectReason(LocationException::CYCLE, fn () => $this->save($aisle->id, 'zb', id: $zone->id));
        $this->expectReason(LocationException::CYCLE, fn () => $this->save($zone->id, 'zb', id: $zone->id));
    }

    public function testDepthAndPathBudgetsCountTheSubtreeBeingMoved(): void
    {
        $parent = null;
        for ($level = 1; $level <= LocationCode::MAX_DEPTH; ++$level) {
            $parent = $this->save($parent?->id, 'l'.$level);
        }
        $this->expectReason(LocationException::TOO_DEEP, fn () => $this->save($parent->id, 'x'));

        $long = $this->save(null, str_repeat('a', 16));
        $longer = $this->save($long->id, str_repeat('b', 16));
        $this->save($longer->id, str_repeat('c', 14)); // 16+1+16+1+14 = 48, the budget exactly
        $other = $this->save(null, 'q');
        // Moving the subtree under `q` adds 2 bytes to its deepest path: 50 > 48.
        $e = $this->expectReason(LocationException::PATH_TOO_LONG, fn () => $this->save($other->id, str_repeat('a', 16), id: $long->id));
        self::assertSame(50, $e->context()['length']);
    }

    public function testACodeOutsideTheAlphabetIsRefusedBeforeAnyRead(): void
    {
        $this->expectReason(LocationException::INVALID_CODE, fn () => $this->save(null, 'a/b'));
        $this->expectReason(LocationException::INVALID_CODE, fn () => $this->save(null, '-a'));
        $this->expectReason(LocationException::EMPTY_CODE, fn () => $this->save(null, '  '));
        $this->expectReason(LocationException::CODE_TOO_LONG, fn () => $this->save(null, str_repeat('a', 17)));
    }

    public function testARegisteredGuardSeesTheProposedRowAndCanRefuse(): void
    {
        $guard = new class implements LocationWriteGuard {
            public ?string $seenPath = null;

            #[\Override]
            public function check(Location $proposed, LocationTree $tree): void
            {
                $this->seenPath = $proposed->path;
                if (str_starts_with($proposed->code, 'bin')) {
                    throw LocationException::refused('test.bins', 'No bins at the top level.');
                }
            }
        };
        // A container hands tagged services over as a one-shot generator, and one SaveLocation serves
        // every save of a request: the second save must still see the guard.
        $tagged = (static function () use ($guard): \Generator {
            yield $guard;
        })();
        $save = new SaveLocation($this->locations(), $this->store(), $tagged);

        $save(null, null, 'za');
        $save(null, null, 'zb');
        self::assertSame('zb', $guard->seenPath);
        $this->expectReason(LocationException::REFUSED, fn () => $save(null, null, 'bin1'));
        self::assertNull($this->locations()->tree()->findByPath('bin1'));
    }

    // ── Retirement ──────────────────────────────────────────────────────────────────────────────

    public function testArchivingRetiresTheSubtreeAndRestoringBringsBackOnlyWhatRetiredWithIt(): void
    {
        $zone = $this->save(null, 'zb');
        $early = $this->save($zone->id, 'a1');
        $together = $this->save($zone->id, 'a2');
        $archive = new ArchiveLocation($this->locations(), $this->store());
        $restore = new RestoreLocation($this->locations(), $this->store());

        $archive((int) $early->id);
        usleep(1000);
        $archive((int) $zone->id);
        self::assertTrue($this->locations()->find((int) $together->id)?->isArchived());

        $this->expectReason(LocationException::PARENT_ARCHIVED, fn () => $restore((int) $together->id));
        $this->expectReason(LocationException::PARENT_ARCHIVED, fn () => $this->save($zone->id, 'a3'));
        $this->expectReason(LocationException::ARCHIVED, fn () => $this->save(null, 'zz', id: $zone->id));

        $restore((int) $zone->id);
        self::assertFalse($this->locations()->find((int) $zone->id)?->isArchived());
        self::assertFalse($this->locations()->find((int) $together->id)?->isArchived());
        self::assertTrue($this->locations()->find((int) $early->id)?->isArchived(), 'retired on its own, so it stays retired');
    }

    // ── Links and the reader ────────────────────────────────────────────────────────────────────

    public function testTheListOrderIsTheRankAndKeptPairsAreUpdatedInPlace(): void
    {
        $product = $this->subject();
        [$a, $b, $c] = [$this->save(null, 'a'), $this->save(null, 'b'), $this->save(null, 'c')];
        $set = new SetSubjectLocations($this->locations(), $this->store());

        $set([$product => [['locationId' => (int) $a->id, 'note' => ' top shelf '], ['locationId' => (int) $b->id]]]);
        $this->pdo()->exec("UPDATE invflux_subject_locations SET created_at = '2020-01-01 00:00:00' WHERE location_id = {$b->id}");

        $set([$product => [['locationId' => (int) $b->id, 'note' => 'moved'], ['locationId' => (int) $c->id]]]);

        /** @var list<array{location_id: string, preference: string, note: ?string, created_at: string}> $rows */
        $rows = $this->pdo()->query("SELECT location_id, preference, note, created_at FROM invflux_subject_locations WHERE subject_id = {$product} ORDER BY preference")->fetchAll();
        self::assertSame([(int) $b->id, (int) $c->id], array_map('intval', array_column($rows, 'location_id')));
        self::assertSame([1, 2], array_map('intval', array_column($rows, 'preference')));
        self::assertSame('moved', $rows[0]['note']);
        self::assertStringStartsWith('2020-01-01', $rows[0]['created_at'], 'a kept pair is updated, not re-inserted');

        $set([$product => []]);
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM invflux_subject_locations WHERE subject_id = {$product}")->fetchColumn());
    }

    public function testAnInvalidListIsRefusedWholeAndWritesNothing(): void
    {
        $one = $this->subject();
        $two = $this->subject();
        $a = $this->save(null, 'a');
        $retired = $this->save(null, 'old');
        (new ArchiveLocation($this->locations(), $this->store()))((int) $retired->id);
        $set = new SetSubjectLocations($this->locations(), $this->store());

        $this->expectReason(LocationException::LOCATION_ARCHIVED, fn () => $set([
            $one => [['locationId' => (int) $a->id]],
            $two => [['locationId' => (int) $retired->id]],
        ]));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM invflux_subject_locations')->fetchColumn());

        $this->expectReason(LocationException::DUPLICATE_LINK, fn () => $set([$one => [['locationId' => (int) $a->id], ['locationId' => (int) $a->id]]]));
        $this->expectReason(LocationException::UNKNOWN_SUBJECT, fn () => $set([999999 => [['locationId' => (int) $a->id]]]));
        $this->expectReason(LocationException::NOT_FOUND, fn () => $set([$one => [['locationId' => 999999]]]));
        $this->expectReason(LocationException::NOTE_TOO_LONG, fn () => $set([$one => [['locationId' => (int) $a->id, 'note' => str_repeat('n', 256)]]]));
    }

    public function testARetiredLocationKeepsItsPlaceButIsNeverPreferred(): void
    {
        $product = $this->subject();
        $a = $this->save(null, 'a');
        $b = $this->save(null, 'b');
        $set = new SetSubjectLocations($this->locations(), $this->store());
        $set([$product => [['locationId' => (int) $a->id], ['locationId' => (int) $b->id]]]);
        (new ArchiveLocation($this->locations(), $this->store()))((int) $a->id);

        // Re-saving the list with the retired location still in it is allowed: it keeps its place.
        $set([$product => [['locationId' => (int) $a->id], ['locationId' => (int) $b->id, 'note' => 'n']]]);

        $read = $this->reader()->effectiveFor([$product])[$product];
        self::assertTrue($read->links[0]->archived);
        self::assertSame((int) $b->id, $read->preferred()?->locationId);
    }

    public function testAVariationWithoutLocationsOfItsOwnIsKeptWhereItsParentIs(): void
    {
        $parent = $this->subject(SubjectKind::Aggregate);
        $inherits = $this->subject(SubjectKind::Unit, $parent);
        $overrides = $this->subject(SubjectKind::Unit, $parent);
        $nowhere = $this->subject();
        $shelf = $this->save(null, 'shelf');
        $cage = $this->save(null, 'cage');
        $set = new SetSubjectLocations($this->locations(), $this->store());
        $set([
            $parent    => [['locationId' => (int) $shelf->id, 'note' => 'left']],
            $overrides => [['locationId' => (int) $cage->id]],
        ]);

        $read = $this->reader()->effectiveFor([$parent, $inherits, $overrides, $nowhere, 999999]);

        self::assertSame([$parent, $inherits, $overrides, $nowhere], array_keys($read), 'an unknown id is omitted');
        self::assertFalse($read[$parent]->inherited);
        self::assertTrue($read[$inherits]->inherited);
        self::assertSame('shelf', $read[$inherits]->links[0]->path);
        self::assertSame('left', $read[$inherits]->links[0]->note);
        self::assertFalse($read[$overrides]->inherited);
        self::assertSame(['cage'], array_map(static fn ($l) => $l->path, $read[$overrides]->links), 'own links replace the parent\'s, never merge');
        self::assertSame([], $read[$nowhere]->links);
        self::assertNull($read[$nowhere]->preferred());
    }

    /** A location's own list: direct links only, in path then rank order, counted and paged. */
    public function testCountsAndLinksAtReadDirectLinksInPathOrder(): void
    {
        $parent = $this->subject(SubjectKind::Aggregate);
        $variation = $this->subject(SubjectKind::Unit, $parent);
        $other = $this->subject();
        $zone = $this->save(null, 'zb');
        $r10 = $this->save($zone->id, 'r10');
        $r4 = $this->save($zone->id, 'r4');
        $set = new SetSubjectLocations($this->locations(), $this->store());
        $set([
            $parent => [['locationId' => (int) $r10->id, 'note' => 'top']],
            $other  => [['locationId' => (int) $r4->id], ['locationId' => (int) $r10->id]],
        ]);
        $reader = $this->reader();

        $counts = $reader->countsByLocation();
        ksort($counts);
        $expected = [(int) $r10->id => 2, (int) $r4->id => 1];
        ksort($expected);
        self::assertSame($expected, $counts, 'the inheriting variation is not counted again');

        $page = $reader->linksAt([(int) $r4->id, (int) $r10->id], limit: 10);
        self::assertSame(3, $page['total']);
        self::assertSame(
            [['zb/r4', $other, 1], ['zb/r10', $parent, 1], ['zb/r10', $other, 2]],
            array_map(static fn ($l): array => [$l->path, $l->subjectId, $l->rank], $page['links']),
        );
        self::assertNotContains($variation, array_map(static fn ($l): int => $l->subjectId, $page['links']));

        $second = $reader->linksAt([(int) $r4->id, (int) $r10->id], limit: 2, offset: 2);
        self::assertSame(3, $second['total']);
        self::assertCount(1, $second['links']);
        self::assertSame(['total' => 0, 'links' => []], $reader->linksAt([], 10));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────

    private function save(?int $parentId, string $code, ?int $id = null, ?string $name = null): Location
    {
        return (new SaveLocation($this->locations(), $this->store()))($id, $parentId, $code, $name);
    }

    private function reader(): MysqlSubjectLocationReader
    {
        \assert(null !== $this->conn);

        return new MysqlSubjectLocationReader($this->conn);
    }

    private function subject(SubjectKind $kind = SubjectKind::Unit, ?int $parentId = null): int
    {
        $subject = Subject::newWith(['kind' => $kind, 'parent_id' => $parentId]);
        $subject->save();
        \assert(null !== $subject->id);

        return $subject->id;
    }

    /** @param \Closure(): mixed $call */
    private function expectReason(string $reason, \Closure $call): LocationException
    {
        try {
            $call();
        } catch (LocationException $e) {
            self::assertSame($reason, $e->reason(), $e->getMessage());

            return $e;
        }
        self::fail("Expected a LocationException ({$reason}); nothing was thrown.");
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function dropTables(): void
    {
        $tables = [
            'invflux_subject_locations', 'invflux_locations',
            'invflux_subject_stock_concerns', 'invflux_inventory_ledger', 'invflux_inventory_state',
            'invflux_subject_identifiers', 'invflux_subjects', 'invflux_identifier_types', 'invflux_systems',
            'invflux_schema_ledger', 'invflux_config_state', 'invflux_adhoc_stock_adjustments', 'invflux_actors',
            'invflux_actor_types', 'invflux_surfaces', 'invflux_surface_types', 'invflux_ref_types',
            'invflux_movement_types', 'invflux_slotspace', 'invflux_dimension_values', 'invflux_dimensions',
            'invflux_layers',
        ];
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo()->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
