<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Procurement;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Exception\OptimisticLockException;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Procurement\ReceiptReason;
use Nandan108\InvFlux\Domain\Procurement\ReceivingSession;
use Nandan108\InvFlux\Domain\Procurement\ReceivingSessionLine;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\StaleReceivingLineException;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * A count's lines against real tables: each write touches its own line only, a write over a figure
 * the writer had not seen is refused, a clear stays visible to a refresh, and the lines go with the
 * session.
 */
final class ReceivingSessionLineTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?MysqlDomainStore $domainStore = null;
    private int $subjectId = 0;
    private int $otherSubjectId = 0;

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
        $other = Subject::newWith(['kind' => SubjectKind::Unit]);
        $other->save();
        $this->otherSubjectId = (int) $other->id;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->dropTables();
        $this->pdo = null;
    }

    /** Two workers on two products: each write leaves the other's line alone. */
    public function testWritesOnDifferentLinesNeverTouchEachOther(): void
    {
        $sessionId = $this->session();
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5, userId: 1), null);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->otherSubjectId, 3, userId: 2), null);

        $lines = $this->store()->receivingSessionLines($sessionId);
        self::assertSame([5, 3], array_map(static fn (ReceivingSessionLine $l): ?int => $l->qty_received, $lines));
        self::assertSame([1, 2], array_map(static fn (ReceivingSessionLine $l): ?int => $l->updated_by, $lines));
    }

    /** A write naming the version it saw replaces the line and moves the version on. */
    public function testAWriteAgainstTheCurrentVersionReplacesTheLine(): void
    {
        $sessionId = $this->session();
        $first = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5), null);

        $second = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 6, 1), $first->version);

        self::assertSame(6, $second->qty_received);
        self::assertSame(1, $second->qty_damaged);
        self::assertGreaterThan($first->version, $second->version);
    }

    /** Someone else changed the line since this screen read it: refused, with the figure as it is now. */
    public function testAWriteOverAFigureTheWriterHadNotSeenIsRefused(): void
    {
        $sessionId = $this->session();
        $read = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5, userId: 1), null);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 12, userId: 2), $read->version);

        try {
            $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 10, userId: 1), $read->version);
            self::fail('a write over an unseen figure must be refused');
        } catch (StaleReceivingLineException $e) {
            self::assertSame(12, $e->current?->qty_received);
            self::assertSame(2, $e->current?->updated_by);
        }

        self::assertSame(12, $this->store()->receivingSessionLines($sessionId)[0]->qty_received);
    }

    /** The screen showed an empty box, but someone had counted the line meanwhile. */
    public function testAFirstWriteOverALineSomeoneElseCountedIsRefused(): void
    {
        $sessionId = $this->session();
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 4), null);

        $this->expectException(StaleReceivingLineException::class);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 7), null);
    }

    /**
     * The guard itself, on this table's composite key: two requests each read the line at version 1,
     * the first writes it, and the second's save — its read now stale — matches no row and is refused,
     * leaving the first one's figure in place. This is the window between the store's read and its save.
     */
    public function testTheVersionGuardRefusesASaveWhoseReadWentStale(): void
    {
        $sessionId = $this->session();
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5), null);
        $key = ['session_id' => $sessionId, 'subject_id' => $this->subjectId];
        $first = ReceivingSessionLine::getOne($key);
        $second = ReceivingSessionLine::getOne($key);
        self::assertNotNull($first);
        self::assertNotNull($second);

        $first->qty_received = 8;
        $first->save();
        $second->qty_received = 2;

        try {
            $second->save();
            self::fail('a save over a version that moved on must be refused');
        } catch (OptimisticLockException $e) {
            self::assertSame(1, $e->expectedVersion);
        }

        $stored = ReceivingSessionLine::getOne($key);
        self::assertSame(8, $stored?->qty_received);
        self::assertSame(2, $stored?->version);
    }

    /**
     * Two workers create the same line at once: the second insert collides on the key and comes back
     * as stale, carrying the first worker's row — never a database error, never a second row.
     */
    public function testAFirstWriteThatCollidesOnTheKeyIsRefusedAsStale(): void
    {
        $sessionId = $this->session();
        // The other worker's insert, landed behind this store's back.
        self::assertNotNull($this->pdo);
        $this->pdo->prepare('INSERT INTO invflux_receiving_session_lines (session_id, subject_id, qty_received, qty_damaged, updated_at, version, updated_by) VALUES (?, ?, 4, 0, NOW(6), 1, 2)')
            ->execute([$sessionId, $this->subjectId]);

        try {
            $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 7, userId: 1), null);
            self::fail('a first write over a line someone else just created must be refused');
        } catch (StaleReceivingLineException $e) {
            self::assertSame(4, $e->current?->qty_received);
            self::assertSame(2, $e->current?->updated_by);
        }

        self::assertCount(1, $this->store()->receivingSessionLines($sessionId));
    }

    /** Two workers scanning the same product: every unit counts, whoever scanned it. */
    public function testIncrementsOnTheSameLineSum(): void
    {
        $sessionId = $this->session();

        $this->store()->addToReceivingLine($sessionId, $this->subjectId, 1, 1);
        $line = $this->store()->addToReceivingLine($sessionId, $this->subjectId, 2, 2);

        self::assertSame(3, $line->qty_received);
        self::assertSame(2, $line->updated_by);
        self::assertCount(1, $this->store()->receivingSessionLines($sessionId));
    }

    /** A cleared line starts again from zero; damaged units are never touched by a scan. */
    public function testAnIncrementOnAClearedLineStartsFromZeroAndLeavesDamagedAlone(): void
    {
        $sessionId = $this->session();
        $counted = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5, 2), null);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, null), $counted->version);
        $damagedLine = $this->store()->stageReceivingLine($this->line($sessionId, $this->otherSubjectId, 4, 1), null);

        self::assertSame(1, $this->store()->addToReceivingLine($sessionId, $this->subjectId, 1, null)->qty_received);
        $other = $this->store()->addToReceivingLine($sessionId, $this->otherSubjectId, 1, null);
        self::assertSame([5, 1], [$other->qty_received, $other->qty_damaged]);
        self::assertGreaterThan($damagedLine->version, $other->version);
    }

    /** A typed figure prepared before a scan landed is refused: the scan moved the version on. */
    public function testAnIncrementMovesTheVersionSoAStaleTypedFigureIsRefused(): void
    {
        $sessionId = $this->session();
        $seen = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5), null);
        $this->store()->addToReceivingLine($sessionId, $this->subjectId, 1, 2);

        $this->expectException(StaleReceivingLineException::class);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 4), $seen->version);
    }

    /** A clear keeps the row, so a refresh carries it; it does not count. */
    public function testAClearedLineReachesARefreshAndCountsNothing(): void
    {
        $sessionId = $this->session();
        $counted = $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5), null);
        $this->store()->stageReceivingLine($this->line($sessionId, $this->otherSubjectId, 2), null);
        $since = new \DateTimeImmutable();
        usleep(2000);

        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, null), $counted->version);

        $changed = $this->store()->receivingSessionLinesChangedSince($sessionId, $since);
        self::assertCount(1, $changed, 'only the line written since');
        self::assertSame($this->subjectId, $changed[0]->subject_id);
        self::assertNull($changed[0]->qty_received);
        self::assertSame([$sessionId => 1], $this->store()->countedLinesForSessions([$sessionId]));
    }

    /** The lines are the session's: discarding it takes them too. */
    public function testDiscardingASessionTakesItsLines(): void
    {
        $sessionId = $this->session();
        $this->store()->stageReceivingLine($this->line($sessionId, $this->subjectId, 5), null);

        $this->store()->deleteReceivingSession($sessionId);

        self::assertSame([], $this->store()->receivingSessionLines($sessionId));
    }

    private function session(): int
    {
        $session = $this->store()->saveReceivingSession(ReceivingSession::newWith(['reason' => ReceiptReason::FoundStock]));

        return (int) $session->id;
    }

    private function line(int $sessionId, int $subjectId, ?int $received, int $damaged = 0, ?int $userId = null): ReceivingSessionLine
    {
        return ReceivingSessionLine::newWith([
            'session_id'   => $sessionId,
            'subject_id'   => $subjectId,
            'qty_received' => $received,
            'qty_damaged'  => $damaged,
            'updated_by'   => $userId,
        ]);
    }

    private function store(): MysqlDomainStore
    {
        self::assertNotNull($this->domainStore);

        return $this->domainStore;
    }

    private function dropTables(): void
    {
        if (null === $this->pdo) {
            return;
        }
        $tables = [
            'invflux_receiving_session_lines', 'invflux_receiving_sessions',
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
