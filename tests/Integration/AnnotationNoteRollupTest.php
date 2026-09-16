<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\AttrecordMigrations\SchemaMigrator;
use Nandan108\InvFlux\Domain\Annotation\Annotation;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Storage\Mysql\Annotation\MysqlAnnotationRepository;
use PHPUnit\Framework\TestCase;

/**
 * The rollup a list surface reads to say "this one has notes" —
 * {@see MysqlAnnotationRepository::liveNotesForUuidTargets()}.
 *
 * Worth pinning at the storage layer rather than through a controller, because every rule here is a
 * *fold over versions* and each one fails silently in a different direction: over-counting puts a
 * note badge on rows nobody wrote on (destroying the sparse column's whole value), under-counting
 * hides a note someone needs to read, and a stale body shows text that has since been rewritten.
 * None of those raise an error anywhere.
 */
final class AnnotationNoteRollupTest extends TestCase
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
        $this->pdo->exec('DROP TABLE IF EXISTS invflux_annotations');

        $connection = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connection);
        RecordIdentity::setMinter(static fn (): string => random_bytes(16));

        // Only this one table: the annotation target is polymorphic, so there is no FK to satisfy
        // and nothing else has to exist for the rollup to be exercised.
        $migrator = new SchemaMigrator($connection);
        $migrator->apply($migrator->plan([Annotation::class]));
    }

    /** A tagged order is not an annotated one — the chips already said so. */
    public function testAPureTagChangeIsNotCountedAsANote(): void
    {
        $order = $this->targetId();
        $this->append($order, body: null, tagActions: ['added' => [4]]);

        self::assertSame([], (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]));
    }

    public function testANoteIsCountedAndCarriesItsText(): void
    {
        $order = $this->targetId();
        $this->append($order, body: 'Customer asked us to hold until Friday.');

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]);
        $hex = bin2hex($order);

        self::assertSame(1, $out[$hex]['count']);
        self::assertSame('Customer asked us to hold until Friday.', $out[$hex]['recent'][0]->body);
    }

    /** A deleted thread is gone from the count, not merely hidden in the panel. */
    public function testADeletedThreadIsNotCounted(): void
    {
        $order = $this->targetId();
        $thread = $this->append($order, body: 'Written in error.');
        $this->append($order, body: null, action: Annotation::ACTION_DELETED, thread: $thread, version: 2);

        self::assertSame([], (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]));
    }

    /** An edit shows the new text, not the version it replaced. */
    public function testTheNewestVersionsTextWins(): void
    {
        $order = $this->targetId();
        $thread = $this->append($order, body: 'Hold until Friday.');
        $this->append($order, body: 'Hold until Monday.', action: Annotation::ACTION_EDITED, thread: $thread, version: 2);

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]);

        self::assertSame(1, $out[bin2hex($order)]['count']);
        self::assertSame('Hold until Monday.', $out[bin2hex($order)]['recent'][0]->body);
    }

    /**
     * An edit that only moves tags leaves the newest version body-less. The thread is still a note
     * and still shows the text it has — reading the latest row alone would blank it.
     */
    public function testAThreadWhoseLatestVersionOnlyMovesTagsKeepsItsText(): void
    {
        $order = $this->targetId();
        $thread = $this->append($order, body: 'Fragile — double-box.');
        $this->append($order, body: null, tagActions: ['added' => [9]], action: Annotation::ACTION_EDITED, thread: $thread, version: 2);

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]);

        self::assertSame(1, $out[bin2hex($order)]['count']);
        self::assertSame('Fragile — double-box.', $out[bin2hex($order)]['recent'][0]->body);
    }

    /**
     * The count is of every note, never of the few carried back — the property the preview's
     * "N more…" line rests on. Truncating the count would make a partial list read as complete.
     */
    public function testTheCountIsOfAllNotesWhileOnlyTheNewestFewAreReturned(): void
    {
        $order = $this->targetId();
        // Inside one second, differing only in microseconds — the shape a burst of notes actually
        // takes, and the one a whole-second comparison would order arbitrarily.
        foreach (['first', 'second', 'third', 'fourth', 'fifth'] as $i => $body) {
            $this->append($order, body: $body, occurredAt: sprintf('2026-03-01 09:00:00.%06d', ($i + 1) * 1000));
        }

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order], recentPerTarget: 2);
        $hex = bin2hex($order);

        self::assertSame(5, $out[$hex]['count']);
        self::assertCount(2, $out[$hex]['recent']);
        // Newest first, so a preview showing a prefix shows the most recent.
        self::assertSame(['fifth', 'fourth'], array_map(
            static fn (Annotation $a): ?string => $a->body,
            $out[$hex]['recent'],
        ));
    }

    /**
     * One target, both shapes at once — the row that separates "counts threads" from "counts
     * notes", and the one shape the dev store does not contain (verified 2026-09-03: zero open
     * orders carry a body-less thread *and* a live note). Pinned here precisely because no fixture
     * can be relied on to exercise it.
     */
    public function testATargetCarryingBothATagDeltaAndANoteCountsOnlyTheNote(): void
    {
        $order = $this->targetId();
        $this->append($order, body: null, tagActions: ['added' => [4]]);
        $this->append($order, body: 'Customer called about the delay.');

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]);
        $hex = bin2hex($order);

        self::assertSame(1, $out[$hex]['count']);
        self::assertSame('Customer called about the delay.', $out[$hex]['recent'][0]->body);
    }

    /**
     * The same target with a note that was later deleted, alongside a tag delta: nothing live
     * remains, so the target drops out entirely rather than reporting the tag change as a note.
     */
    public function testATagDeltaBesideADeletedNoteLeavesTheTargetUncounted(): void
    {
        $order = $this->targetId();
        $this->append($order, body: null, tagActions: ['added' => [4]]);
        $thread = $this->append($order, body: 'Written in error.');
        $this->append($order, body: null, action: Annotation::ACTION_DELETED, thread: $thread, version: 2);

        self::assertSame([], (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order]));
    }

    /** Counting without bodies, for a caller that only needs the badge. */
    public function testZeroRecentReturnsTheCountAndNoBodies(): void
    {
        $order = $this->targetId();
        $this->append($order, body: 'Something worth reading.');

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$order], recentPerTarget: 0);

        self::assertSame(1, $out[bin2hex($order)]['count']);
        self::assertSame([], $out[bin2hex($order)]['recent']);
    }

    /** One query answers a page of orders, and each one gets its own answer. */
    public function testTargetsAreKeptApartAndTheUnannotatedAreAbsent(): void
    {
        $withNote = $this->targetId();
        $withTagOnly = $this->targetId();
        $withNothing = $this->targetId();
        $this->append($withNote, body: 'Ring the bell twice.');
        $this->append($withTagOnly, body: null, tagActions: ['added' => [2]]);

        $out = (new MysqlAnnotationRepository())->liveNotesForUuidTargets(
            1,
            [$withNote, $withTagOnly, $withNothing],
        );

        self::assertSame([bin2hex($withNote)], array_keys($out));
    }

    /** A different ref type's annotations never leak into an order's count. */
    public function testAnotherRefTypesAnnotationIsNotCounted(): void
    {
        $target = $this->targetId();
        $this->append($target, body: 'A note on a purchase order.', refTypeId: 2);

        self::assertSame([], (new MysqlAnnotationRepository())->liveNotesForUuidTargets(1, [$target]));
    }

    /**
     * @param array{added?: list<int>, removed?: list<int>}|null $tagActions
     *
     * @return string the thread's binary id, for appending a later version to it
     */
    private function append(
        string $targetId,
        ?string $body,
        ?array $tagActions = null,
        string $action = Annotation::ACTION_CREATED,
        ?string $thread = null,
        int $version = 1,
        int $refTypeId = 1,
        ?string $occurredAt = null,
    ): string {
        $row = Annotation::newWith([
            'target_ref_type_id' => $refTypeId,
            'target_ref_id'      => $targetId,
            'version'            => $version,
            'action'             => $action,
            'body'               => $body,
            'tag_actions'        => $tagActions,
            'author_actor_id'    => 7,
        ]);
        if (null !== $thread) {
            $row->thread_id = $thread;
        }
        if (null !== $occurredAt) {
            $row->occurred_at = new \DateTimeImmutable($occurredAt);
        }
        $row->save();

        return (string) $row->thread_id;
    }

    /** A binary(16) id standing in for an order — the rollup never dereferences it. */
    private function targetId(): string
    {
        return random_bytes(16);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
