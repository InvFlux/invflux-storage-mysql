<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\LockSet;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Procurement\PurchaseOrder;
use Nandan108\InvFlux\Domain\Procurement\Supplier;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Exceptions\PersistenceException;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\PersistMovement;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\SlotSpaceFactory;
use Nandan108\InvFlux\Schema\Stt;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Session\DeadlockAwareRetryPolicy;
use Nandan108\InvFlux\Storage\Mysql\Session\DeadlockTelemetry;
use Nandan108\InvFlux\Storage\Mysql\Session\LockPhaseGuard;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqliMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Session\RetryingMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\AsyncLockSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\ExternalTransactionSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\Results\MovementEvent;
use PHPUnit\Framework\TestCase;

/**
 * Prove the cross-subsystem lock-order boundary with real concurrency, not a SQL-shape assertion.
 *
 * ## What is at stake
 *
 * InvFlux has **two** locking subsystems and only one is mechanism-enforced. Domain-entity locks go
 * through {@see LockSet::acquire()}, which sorts by `#[LockTier(n)]` and throws on a tier collision —
 * pass every target in one call and the order *cannot* be got wrong. Inventory-state locks are raw
 * `SELECT … FOR UPDATE` inside {@see MysqlInventoryStore}; `invflux_inventory_state` is not an
 * attrecord Record and cannot participate in `LockTier`. The rule that spans them — domain locks
 * first, inventory second — is therefore enforced by a *comment*. This test is what makes it a fact.
 *
 * ## Observed, not merely asserted
 *
 * The adversarial case was **watched to deadlock before it was trusted** (errno 1213, on the dev rig:
 * MariaDB 11.8.6, `innodb_deadlock_detect=1`, REPEATABLE READ; reproduced on every one of an initial
 * 6 consecutive runs). Both transactions drive the **real** mechanisms — `LockSet::acquire()` for the
 * domain phase, `MysqlInventoryStore::persist()` for the inventory phase. Only the *transport* of the
 * one blocking lock request is test-owned (see {@see AsyncLockSession}), because a deadlock needs two
 * transactions blocked at once and PHP's drivers are synchronous.
 *
 * ## Why the negative control is load-bearing
 *
 * A concurrency test that stops reproducing the contention goes **green**, not red, and then sits in
 * the suite advertising a guarantee it no longer checks. That is not hypothetical here: an early
 * version of this harness drove the adversary's transaction with `exec('START TRANSACTION')`, which
 * leaves the session's depth counter at 0 — so `persist()` believed it was the outermost transaction
 * and **committed**, releasing the very locks the contention needed. It did not fail loudly; it
 * surfaced as errno 1020 rather than 1213 and read like a server quirk.
 *
 * Hence {@see assertAdversaryIsGenuinelyBlocked()}: every case asserts the blocked side was *still
 * blocked* at the moment the cycle was closed. Remove the contention and the test fails on that
 * assertion instead of passing vacuously.
 *
 * ## The victim is not necessarily the violator
 *
 * Which transaction InnoDB rolls back is weight-dependent and **not** stable across shapes: in this
 * shape the inverted transaction loses, but a minimal probe of the same AB-BA cycle with pure
 * `SELECT … FOR UPDATE` on both sides victimised the *correctly-ordered* one. Obeying the lock order
 * does not protect a transaction from being rolled back because of someone else's violation. These
 * tests therefore assert **that** a deadlock occurred, never **which** side lost.
 */
final class LockOrderBoundaryTest extends TestCase
{
    private const OWNER = 'LockOrderTest';
    private const MOVEMENT = 'LOCKTEST';
    private const LOC = SlotSpaceFactory::DEFAULT_LOCATION_SEED;

    /** Seeded into `atp.oh` so every reserve movement in this test has stock to move. */
    private const SEEDED_ATP = 1_000;

    /** Long enough to out-wait a real lock queue, short enough that a hang is not a 50s stall. */
    private const LOCK_WAIT_TIMEOUT = 5;

    private ?\PDO $pdo = null;
    private ?\mysqli $mysqli = null;
    private ?PdoMysqlSession $sessionA = null;
    private ?Connection $connA = null;
    private ?MysqlInventoryStore $storeA = null;
    private ?MysqlInventoryStore $storeB = null;
    private ?AsyncLockSession $asyncB = null;
    private ?Connection $connBAsync = null;
    private ?SlotSpaceFactory $factory = null;
    private ?SubjectId $subject = null;
    private int $poId = 0;

    #[\Override]
    protected function setUp(): void
    {
        if (!AsyncLockSession::isSupported()) {
            self::markTestSkipped('Needs mysqli built against mysqlnd for non-blocking queries (MYSQLI_ASYNC).');
        }

        // ---- connection A: the correctly-ordered transaction (domain → inventory), on PDO.
        // Unreachable MySQL is a *skip*, not an error: this suite is infrastructure-gated, and a hard
        // error would read as "the lock-order boundary is broken" on a machine that simply has no
        // database. The skip message names the gate so it can never be mistaken for a passing run.
        try {
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
        } catch (\PDOException $e) {
            self::markTestSkipped('Needs a reachable MySQL/MariaDB instance: '.$e->getMessage());
        }
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT);

        $sessionA = new PdoMysqlSession($this->pdo);
        $connA = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connA);

        // Start from bare tables, as the other integration suites do. Not optional here: `bootstrap()`
        // is idempotent, so a slot space left behind by a suite that declares *different* dimensions
        // survives it, and this test's `atp.oh` / `res.oh` slots are then never created.
        $this->dropInvfluxTables();

        $domainStore = new MysqlDomainStore($connA, tablePrefix: '', session: $sessionA);
        SchemaFixture::install($connA);
        $domainStore->installReferenceTables();

        $storeA = new MysqlInventoryStore($sessionA);
        $factory = new SlotSpaceFactory();
        $storeA->bootstrap($factory->createLayered());
        $domainStore->bootstrap();
        // `stt` is a sharedRef dimension now (values registered at runtime), so seed the native
        // states the host would register at bootstrap — mirroring BootstrapInvFlux::ensureNativeStates.
        $storeA->addDimensionValues('stt', array_map(
            static fn (string $code): DimensionValueDefinition => new DimensionValueDefinition($code),
            Stt::NATIVE,
        ));
        // Declare `atp` the default rather than relying on it sorting first — a sharedRef writes no
        // default at bootstrap, so resolution would otherwise fall back to first-in-code-order.
        $storeA->setDimensionDefault('stt', Stt::ATP);
        $storeA->addDimensionValues('loc', [new DimensionValueDefinition(self::LOC, level: 'warehouse')]);
        $storeA->registerMovementTypes(self::OWNER, [
            new MovementTypeDefinition(self::MOVEMENT, 'Lock-order boundary test movement'),
        ]);

        // ---- connection B: the adversary, on mysqli (the only driver here that can dispatch a
        // blocking statement without blocking the interpreter).
        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new \mysqli(
            $this->env('INVFLOW_DB_HOST', '127.0.0.1'),
            $this->env('INVFLOW_DB_USER', 'invflux'),
            $this->env('INVFLOW_DB_PASS', 'invflux'),
            $this->env('INVFLOW_DB_NAME', 'invflux_test'),
            (int) $this->env('INVFLOW_DB_PORT', '33067'),
        );
        $mysqli->set_charset('utf8mb4');
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT);

        // ExternalTransactionSession keeps B's transaction open across several store calls — see its
        // docblock; without it persist() self-commits and the contention silently disappears.
        $storeB = new MysqlInventoryStore(new ExternalTransactionSession(new MysqliMysqlSession($mysqli)));
        $storeB->useSchema($factory->createLayered());

        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_WAIT_TIMEOUT);

        // ---- fixtures: one subject, one order. The minimum that touches both subsystems.
        $subject = $storeA->registerSubject();
        $supplier = $domainStore->createSupplier(Supplier::newWith([
            'name' => 'Lock Order Supplier', 'default_currency' => 'EUR',
        ]));
        $po = $domainStore->createPurchaseOrder(
            PurchaseOrder::newWith(['supplier_id' => $supplier->id, 'currency' => 'EUR']),
        );

        $this->mysqli = $mysqli;
        $this->sessionA = $sessionA;
        $this->connA = $connA;
        $this->storeA = $storeA;
        $this->storeB = $storeB;
        $this->asyncB = new AsyncLockSession($mysqli);
        $this->connBAsync = new Connection($this->asyncB, new MysqlDialect());
        $this->factory = $factory;
        $this->subject = $subject;
        $this->poId = (int) $po->id;

        $this->seedStock($subject);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Drain any in-flight lock request before touching the connection again, then release both
        // transactions. A leaked open transaction would hold locks into the next test.
        $this->asyncB?->discard();

        try {
            $this->mysqli?->query('ROLLBACK');
        } catch (\Throwable) {
        }

        try {
            if (true === $this->pdo?->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\Throwable) {
        }

        $this->mysqli?->close();
        $this->mysqli = null;
        $this->pdo = null;
    }

    // ------------------------------------------------------------------ case 1: control

    /**
     * Both transactions take the locks in the documented order. They contend — the second genuinely
     * blocks on the first — and both still commit.
     *
     * Looped, because a one-shot pass proves little about a concurrency property.
     */
    public function testCorrectOrderOnBothSidesCommitsUnderContention(): void
    {
        for ($round = 1; $round <= 5; ++$round) {
            $blockedDuringRound = false;

            // A: domain → inventory, holding the domain lock while B queues behind it.
            $this->sessionA()->transactional(function () use (&$blockedDuringRound): void {
                LockSet::acquire($this->connA(), [PurchaseOrder::class => [$this->poId]]);

                // B: the SAME order. Its domain lock is dispatched without blocking us, and queues.
                $this->mysqli()->query('START TRANSACTION');
                LockSet::acquire($this->connBAsync(), [PurchaseOrder::class => [$this->poId]]);
                $blockedDuringRound = $this->settleAdversary();

                // A completes its own inventory phase while B is still queued — no cycle, because B
                // holds nothing A wants.
                $this->storeA()->persist($this->movement());
            });

            self::assertFalse(
                $blockedDuringRound,
                sprintf('Round %d: B did not block on A\'s domain lock, so the round proved nothing.', $round),
            );

            // A has committed and released; B's queued lock request now succeeds.
            $this->asyncB()->await();

            // B finishes in the correct order too, then commits.
            $this->storeB()->persist($this->movement());
            $this->mysqli()->query('COMMIT');

            self::assertSame(
                self::SEEDED_ATP - (2 * $round),
                $this->atpQuantity(),
                sprintf('Round %d: both transactions should have applied their movement.', $round),
            );
        }
    }

    // ------------------------------------------------------------------ case 2: adversarial

    /**
     * The deliverable. One transaction takes domain → inventory; the other takes **inventory →
     * domain**. The cycle closes and InnoDB kills one of them.
     *
     * This is the case that was watched to fail before it was trusted — see the class docblock.
     */
    public function testInvertedLockOrderDeadlocksAgainstTheCorrectOrder(): void
    {
        $outcome = $this->runInvertedOrderCycle();

        $this->assertAdversaryIsGenuinelyBlocked($outcome);

        self::assertTrue(
            $outcome->deadlocked(),
            sprintf(
                "Inverting the lock order did NOT deadlock — the boundary this test exists to prove was not exercised.\n".
                "  correctly-ordered tx: %s\n  inverted tx:          %s",
                self::describe($outcome->correctOrderError),
                self::describe($outcome->invertedOrderError),
            ),
        );

        // Exactly one side loses: a cycle has one victim, and the survivor must be free to continue.
        self::assertFalse(
            $outcome->isDeadlock($outcome->correctOrderError) && $outcome->isDeadlock($outcome->invertedOrderError),
            'Both sides reported a deadlock; a two-transaction cycle should victimise exactly one.',
        );
    }

    /**
     * The real {@see DeadlockAwareRetryPolicy}, fed the real exception this boundary produces.
     *
     * This is what §3's case 3 is actually for — "confirms the policy covers **this shape** rather
     * than a different one". A unit test can only feed the policy an exception it invented; this
     * one hands it the genuine article, wrapped exactly as the session that lost the cycle wrapped
     * it. Both stances are pinned, because both are load-bearing: production retries to keep
     * serving, and dev/CI deliberately does **not**, so a slipped lock order fails loudly here
     * instead of being masked.
     */
    public function testTheRealRetryPolicyClassifiesThisDeadlock(): void
    {
        $outcome = $this->runInvertedOrderCycle();

        $this->assertAdversaryIsGenuinelyBlocked($outcome);
        self::assertTrue($outcome->deadlocked(), 'Expected a deadlock to classify.');

        $victim = $outcome->victimError();
        self::assertNotNull($victim);

        // Classify with the session that actually produced the error: the two sessions wrap driver
        // errors differently, and the policy delegates the base decision to that wrapping.
        $classifier = $outcome->victimIsCorrectlyOrdered()
            ? $this->sessionA()
            : new MysqliMysqlSession($this->mysqli());

        $telemetry = new RecordingDeadlockTelemetry();

        self::assertTrue(
            (new DeadlockAwareRetryPolicy($classifier, retryDeadlocks: true, telemetry: $telemetry))($victim),
            'Production stance: this cross-subsystem deadlock must be retried, not surfaced to the merchant.',
        );
        self::assertFalse(
            (new DeadlockAwareRetryPolicy($classifier, retryDeadlocks: false))($victim),
            'Dev/CI stance: this deadlock must NOT be retried, so slipped lock-order discipline fails loudly.',
        );

        self::assertCount(
            1,
            $telemetry->recorded,
            'A deadlock must reach the telemetry sink even when it is about to be retried away.',
        );
    }

    /**
     * The victim's work succeeds when re-run after the surviving transaction lets go.
     *
     * ## Why this is not "the retry loop runs twice"
     *
     * §3 phrased case 3 as the victim retrying *inside* `RetryingMysqlSession`. That is not
     * reachable in this shape, and the reason is worth recording rather than working around: the
     * side that can be wrapped in the retrying session is the synchronous one, and in this shape
     * the synchronous (correctly-ordered) side is **not** the victim — InnoDB consistently rolls
     * back the inverted transaction here, which is the one whose blocking lock request must be
     * dispatched asynchronously and therefore cannot be driven by a retry loop.
     *
     * So this test covers the half that is specific to the boundary — that the victim's work is
     * genuinely re-runnable once the cycle clears, i.e. the deadlock left no partial state behind.
     * The retry *loop* itself is mechanism owned by attrecord's `RetryingDbSession` and is covered
     * by its own unit tests; re-proving it here would test the loop, not the boundary.
     */
    public function testTheVictimsWorkSucceedsOnceTheCycleClears(): void
    {
        $outcome = $this->runInvertedOrderCycle();

        $this->assertAdversaryIsGenuinelyBlocked($outcome);
        self::assertTrue($outcome->deadlocked(), 'Expected a deadlock before testing recovery from it.');

        $quantityBefore = $this->atpQuantity();

        // Release whichever transaction survived, so nothing is still holding locks.
        $this->mysqli()->query('ROLLBACK');
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }

        // Re-run the victim's work through the retrying session, in the correct order this time.
        $policy = new DeadlockAwareRetryPolicy($this->sessionA(), retryDeadlocks: true);
        $retrying = new RetryingMysqlSession($this->sessionA(), retryable: $policy);

        $retrying->transactional(function (): void {
            LockSet::acquire($this->connA(), [PurchaseOrder::class => [$this->poId]]);
            $this->storeA()->persist($this->movement());
        });

        self::assertSame(
            $quantityBefore - 1,
            $this->atpQuantity(),
            'The re-run should have applied exactly one movement — no partial state from the deadlock, no double-apply.',
        );
    }

    // ------------------------------------------------------------------ the guard

    /**
     * The phase guard stops the violation that case 2 proves is dangerous — at the violation site,
     * on the transaction that broke the rule, instead of as a deadlock on whichever transaction the
     * database happens to pick.
     *
     * Deliberately *not* a second copy of case 2: no second connection, no contention, no deadlock.
     * A single transaction taking the two locks in the wrong order is already the whole defect, and
     * catching it needs nothing else present. That is the point of moving from detection to
     * prevention — the failure no longer requires a race to be observable.
     */
    public function testThePhaseGuardRejectsTheInvertedOrderWithoutNeedingAContender(): void
    {
        [$guarded, $connection, $store] = $this->guardedStack();

        try {
            $guarded->transactional(function () use ($connection, $store): void {
                // Inventory phase first — the inversion.
                $store->persist($this->movement());

                // ...then an entity lock. Legal before the inventory phase, a violation after it.
                LockSet::acquire($connection, [PurchaseOrder::class => [$this->poId]]);
            });
            self::fail('The guard should have rejected an entity lock taken after inventory-state locks.');
        } catch (PersistenceException $e) {
            self::assertSame('lock_order_violation', $e->detailCode);
        }
    }

    /** The correct order passes the guard untouched — a guard that rejects valid work is worthless. */
    public function testThePhaseGuardPassesTheDocumentedOrder(): void
    {
        [$guarded, $connection, $store] = $this->guardedStack();

        $guarded->transactional(function () use ($connection, $store): void {
            LockSet::acquire($connection, [PurchaseOrder::class => [$this->poId]]);
            $store->persist($this->movement());
        });

        self::assertSame(self::SEEDED_ATP - 1, $this->atpQuantity());
    }

    /**
     * Build the guarded stack the way a host must wire it: **one** guarded session, shared by the
     * entity-lock path (through the attrecord `Connection`) and the inventory-lock path (through the
     * store).
     *
     * The sharing is the whole mechanism, and getting it wrong is silent. An earlier version of this
     * test built the `Connection` over a raw session while only the store was guarded; the entity
     * lock then bypassed the guard entirely and the violation sailed through. In production the two
     * cannot drift apart — `WpdbConnectionFactory` memoises a single session and hands the same one
     * to both — but a test that assembles its own stack has to reproduce that deliberately.
     *
     * @return array{LockPhaseGuard, Connection, MysqlInventoryStore}
     */
    private function guardedStack(): array
    {
        $guarded = new LockPhaseGuard($this->sessionA());
        $connection = new Connection($guarded, new MysqlDialect());

        $store = new MysqlInventoryStore($guarded);
        $store->useSchema($this->factory()->createLayered());

        return [$guarded, $connection, $store];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run the adversarial interleave once and report what each side saw.
     *
     * Ordering is deliberate and each step depends on the one before:
     *  1. A takes the domain lock (real `LockSet`).
     *  2. B takes the inventory lock (real `persist()`) — the inversion.
     *  3. B asks for the domain lock. It blocks on A; dispatched without blocking us so step 4 can run.
     *  4. A asks for the inventory lock B holds. The cycle is now closed.
     */
    private function runInvertedOrderCycle(): LockCycleOutcome
    {
        $outcome = new LockCycleOutcome();

        try {
            $this->sessionA()->transactional(function () use ($outcome): void {
                LockSet::acquire($this->connA(), [PurchaseOrder::class => [$this->poId]]);

                $this->mysqli()->query('START TRANSACTION');
                $this->storeB()->persist($this->movement());
                LockSet::acquire($this->connBAsync(), [PurchaseOrder::class => [$this->poId]]);

                $outcome->adversarySettledEarly = $this->settleAdversary();

                $this->storeA()->persist($this->movement());
            });
        } catch (\Throwable $e) {
            $outcome->correctOrderError = $e;
        }

        try {
            $this->asyncB()->await();
        } catch (\Throwable $e) {
            $outcome->invertedOrderError = $e;
        }

        return $outcome;
    }

    /**
     * Give the dispatched lock request time to reach the server and enter the lock queue, then
     * report whether it came back early.
     *
     * @return bool true when the request had already returned — i.e. it never blocked
     */
    private function settleAdversary(): bool
    {
        usleep(200_000);

        return $this->asyncB()->hasSettled();
    }

    /**
     * The negative control: if the adversary never actually blocked, the interleave under test did
     * not happen and every other assertion in the case is vacuous.
     */
    private function assertAdversaryIsGenuinelyBlocked(LockCycleOutcome $outcome): void
    {
        self::assertFalse(
            $outcome->adversarySettledEarly,
            'The adversary\'s lock request returned without blocking, so no lock cycle was ever formed. '.
            'The test proved nothing — check that its transaction is still open (see ExternalTransactionSession).',
        );
    }

    /** The movement both transactions make: reserve one unit, `atp.oh → res.oh`. */
    private function movement(?SubjectId $subject = null): PersistMovement
    {
        // The live layer, from the store that was bootstrapped in setUp — not a space compiled
        // from code. `stt` is DB-backed, so a code-built space knows only the native states and
        // would stop matching this install the moment an add-on registered one.
        $slotSpace = $this->storeA()->layerSchema(SlotSpaceFactory::LAYER_COMMERCIAL)->toSlotSpace();
        $event = new MovementEvent(
            new MovementEdge(
                $slotSpace->slot(['stt' => 'atp', 'loc' => self::LOC]),
                $slotSpace->slot(['stt' => 'res', 'loc' => self::LOC]),
                'reserve',
            ),
            1,
            self::SEEDED_ATP,
            0,
        );

        return new PersistMovement(
            subjectId: $subject ?? $this->subject(),
            movementTypeOwnerKey: self::OWNER,
            movementTypeCode: self::MOVEMENT,
            movementResult: new MovementResult([$event], 0),
            actor: new ActorReference('admin', '1'),
        );
    }

    /**
     * Pre-create both state rows with known quantities.
     *
     * Seeded rather than left to first-touch on purpose: the first touch of a (subject, slot) pair
     * takes an extra `INSERT IGNORE` + re-lock round trip, which is a different lock sequence from
     * the steady-state one this test is about.
     */
    private function seedStock(SubjectId $subject): void
    {
        foreach (['atp.'.self::LOC => self::SEEDED_ATP, 'res.'.self::LOC => 0] as $slotKey => $quantity) {
            $statement = $this->pdo()->prepare('SELECT id FROM invflux_slotspace WHERE slot_key = ?');
            $statement->execute([$slotKey]);
            /** @var array{id: string}|false $row */
            $row = $statement->fetch();
            false !== $row || self::fail(sprintf('Slot "%s" was not created by bootstrap.', $slotKey));

            $insert = $this->pdo()->prepare(
                'INSERT INTO invflux_inventory_state (subject_id, slot_id, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
            );
            $insert->bindValue(1, $subject->id, \PDO::PARAM_INT);
            $insert->bindValue(2, $row['id'], \PDO::PARAM_LOB);
            $insert->bindValue(3, $quantity, \PDO::PARAM_INT);
            $insert->execute();
        }
    }

    /**
     * Drop every table this test's bootstrap recreates, so the slot space is built from *this*
     * test's definition rather than whatever a previously-run suite left behind.
     */
    private function dropInvfluxTables(): void
    {
        $tables = [
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

        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo()->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function atpQuantity(): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT s.quantity FROM invflux_inventory_state s
             JOIN invflux_slotspace sp ON sp.id = s.slot_id
             WHERE s.subject_id = ? AND sp.slot_key = ?',
        );
        $statement->execute([$this->subject()->id, 'atp.'.self::LOC]);
        /** @var array{quantity: scalar}|false $row */
        $row = $statement->fetch();

        return false === $row ? -1 : (int) $row['quantity'];
    }

    private static function describe(?\Throwable $e): string
    {
        if (null === $e) {
            return 'committed cleanly';
        }

        $chain = [];
        for ($x = $e; null !== $x; $x = $x->getPrevious()) {
            $chain[] = $x::class.'#'.$x->getCode();
        }

        return implode(' <- ', $chain).' :: '.substr(str_replace("\n", ' ', $e->getMessage()), 0, 160);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('PDO was not initialized.');
    }

    private function mysqli(): \mysqli
    {
        return $this->mysqli ?? throw new \LogicException('mysqli was not initialized.');
    }

    private function sessionA(): PdoMysqlSession
    {
        return $this->sessionA ?? throw new \LogicException('Session A was not initialized.');
    }

    private function connA(): Connection
    {
        return $this->connA ?? throw new \LogicException('Connection A was not initialized.');
    }

    private function connBAsync(): Connection
    {
        return $this->connBAsync ?? throw new \LogicException('Async connection B was not initialized.');
    }

    private function asyncB(): AsyncLockSession
    {
        return $this->asyncB ?? throw new \LogicException('Async session B was not initialized.');
    }

    private function storeB(): MysqlInventoryStore
    {
        return $this->storeB ?? throw new \LogicException('Store B was not initialized.');
    }

    private function storeA(): MysqlInventoryStore
    {
        return $this->storeA ?? throw new \LogicException('Store A was not initialized.');
    }

    private function factory(): SlotSpaceFactory
    {
        return $this->factory ?? throw new \LogicException('Slot-space factory was not initialized.');
    }

    private function subject(): SubjectId
    {
        return $this->subject ?? throw new \LogicException('Subject was not initialized.');
    }
}

/**
 * What each side of one adversarial interleave saw.
 *
 * A plain carrier so the assertions read as prose rather than as index lookups into a tuple.
 */
final class LockCycleOutcome
{
    /** Raised inside the correctly-ordered (domain → inventory) transaction, if any. */
    public ?\Throwable $correctOrderError = null;

    /** Raised by the inverted (inventory → domain) transaction's lock request, if any. */
    public ?\Throwable $invertedOrderError = null;

    /** True when the adversary's lock request came back without ever blocking — see the negative control. */
    public bool $adversarySettledEarly = false;

    public function deadlocked(): bool
    {
        return $this->isDeadlock($this->correctOrderError) || $this->isDeadlock($this->invertedOrderError);
    }

    /** Whether the side InnoDB rolled back was the one that obeyed the documented lock order. */
    public function victimIsCorrectlyOrdered(): bool
    {
        return $this->isDeadlock($this->correctOrderError);
    }

    /** The error belonging to whichever side InnoDB rolled back, or null if neither deadlocked. */
    public function victimError(): ?\Throwable
    {
        if ($this->isDeadlock($this->correctOrderError)) {
            return $this->correctOrderError;
        }

        return $this->isDeadlock($this->invertedOrderError) ? $this->invertedOrderError : null;
    }

    /**
     * Walk the wrapped-exception chain looking for the deadlock signature, the same way
     * {@see DeadlockAwareRetryPolicy} does — the driver
     * error arrives wrapped differently depending on which session surfaced it.
     */
    public function isDeadlock(?\Throwable $error): bool
    {
        for ($e = $error; null !== $e; $e = $e->getPrevious()) {
            if (1213 === (int) $e->getCode() || str_contains($e->getMessage(), 'Deadlock found')) {
                return true;
            }
        }

        return false;
    }
}

/**
 * Captures what the retry policy reports, so the test can assert a deadlock is never retried away
 * silently — recording it is the only reason production is allowed to swallow one.
 */
final class RecordingDeadlockTelemetry implements DeadlockTelemetry
{
    /** @var list<\Throwable> */
    public array $recorded = [];

    #[\Override]
    public function recordDeadlock(\Throwable $conflict): void
    {
        $this->recorded[] = $conflict;
    }
}
