<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Diagnostics\DiagnosticCheck;
use Nandan108\InvFlux\Diagnostics\DiagnosticResult;
use Nandan108\InvFlux\Diagnostics\DiagnosticStatus;
use Nandan108\InvFlux\Domain\Subject\IdentifierAssignmentPolicy;
use Nandan108\InvFlux\Domain\Subject\IdentifierClaimSpec;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifierClaim;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Exceptions\IdentifierAlreadyClaimedException;
use Nandan108\InvFlux\Exceptions\InvalidFilterException;
use Nandan108\InvFlux\Exceptions\InvalidQuantityException;
use Nandan108\InvFlux\Exceptions\PersistenceException;
use Nandan108\InvFlux\Exceptions\SchemaException;
use Nandan108\InvFlux\Exceptions\UnknownActorTypeException;
use Nandan108\InvFlux\Exceptions\UnknownMovementTypeException;
use Nandan108\InvFlux\Flow\Constraints\MinSlotTotal;
use Nandan108\InvFlux\Flow\FlowDefinition;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Idempotency\IdempotentExecution;
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;
use Nandan108\InvFlux\Layer\BoundaryFlow;
use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\EntityReference;
use Nandan108\InvFlux\Mutation\MetaEvent;
use Nandan108\InvFlux\Mutation\PersistBatchMovement;
use Nandan108\InvFlux\Mutation\PersistMovement;
use Nandan108\InvFlux\Mutation\QuantityGuard;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Mutation\StorageBoundaryFlowRequest;
use Nandan108\InvFlux\Projection\ProjectionContext;
use Nandan108\InvFlux\Projection\ProjectionLockTarget;
use Nandan108\InvFlux\Projection\ProjectionParticipant;
use Nandan108\InvFlux\Registry\ActorTypeDefinition;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\DimensionScope;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\DimensionValueSelector;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\InvFlux\Storage\Mysql\Diagnostics\DiagnosticOrchestrator;
use Nandan108\InvFlux\Storage\Mysql\Diagnostics\LayerTotalsCheck;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Projection\MysqlProjectionRuntime;
use Nandan108\InvFlux\Storage\Mysql\Session\PdoMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\BatchQuantityStateDelta;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementResult;
use Nandan108\SlotFlow\Results\MovementEvent;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

/** @extends QuantityStateBatch<string> */
final class ZeroDeltaBatch extends QuantityStateBatch
{
    public function __construct(private readonly SlotSpace $slotSpace)
    {
        parent::__construct([]);
    }

    #[\Override]
    public function deltas(): array
    {
        return [
            new BatchQuantityStateDelta('SKU-ZERO-BATCH', $this->slotSpace->slot(['state' => 'fs']), 0.0),
            new BatchQuantityStateDelta('SKU-ZERO-BATCH', $this->slotSpace->slot(['state' => 'res']), -0.0),
        ];
    }
}

final class ProbeProjectionParticipant implements ProjectionParticipant
{
    public ?\DateTimeImmutable $lastRecordedAt = null;

    public function __construct(
        private readonly string $tableName,
        private readonly bool $throwOnApply = false,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return 'probe';
    }

    #[\Override]
    public function collectLockTargets(ProjectionContext $context): array
    {
        return array_map(
            static function (array $row): ProjectionLockTarget {
                /** @var array{subject_id: int, slot_key: string} $row */

                return new ProjectionLockTarget('probe', $row['subject_id'].':'.$row['slot_key']);
            },
            $context->deltaRows,
        );
    }

    #[\Override]
    public function lock(ProjectionContext $context, array $targets): void
    {
        $runtime = $context->runtime instanceof MysqlProjectionRuntime
            ? $context->runtime
            : throw new \LogicException('Expected MysqlProjectionRuntime.');

        $lockedCount = count($context->lockedInventoryRows);
        if (0 === $lockedCount) {
            throw new \LogicException('Projection locks require locked inventory rows.');
        }

        foreach ($targets as $target) {
            $runtime->session->fetchAll(sprintf(
                'SELECT resource_key FROM %s WHERE resource_key = ? FOR UPDATE',
                $this->tableName,
            ), [$target->resourceKey]);
        }
    }

    #[\Override]
    public function apply(ProjectionContext $context): void
    {
        if ($this->throwOnApply) {
            throw new \RuntimeException('Projection apply failed.');
        }

        $metadataFingerprint = implode('|', [
            $context->movementTypeOwnerKey,
            $context->movementTypeCode,
            $context->recordedAt->format('c'),
            $context->referenceType ?? '',
            $context->referenceId ?? '',
            $context->actorTypeCode ?? '',
            $context->actorId ?? '',
            (string) count($context->lockedInventoryRows),
        ]);
        $movementCode = substr($metadataFingerprint, 0, 0).$context->movementTypeCode;

        $runtime = $context->runtime instanceof MysqlProjectionRuntime
            ? $context->runtime
            : throw new \LogicException('Expected MysqlProjectionRuntime.');

        $this->lastRecordedAt = $context->recordedAt;

        foreach ($context->deltaRows as $row) {
            /** @var array{subject_id: int, slot_key: string, delta: int} $row */
            $runtime->session->exec(sprintf(
                'INSERT INTO %s (resource_key, quantity_delta, movement_code)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    quantity_delta = VALUES(quantity_delta),
                    movement_code = VALUES(movement_code)',
                $this->tableName,
            ), [
                $row['subject_id'].':'.$row['slot_key'],
                $row['delta'],
                $movementCode,
            ]);
        }
    }

    #[\Override]
    public function postCommit(): void
    {
        // No deferred work for the probe — the test exercises apply() side-effects only.
    }
}

final class MysqlInventoryStoreTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?MysqlInventoryStore $store = null;

    /** @var array<string, SubjectId> */
    private array $subjects = [];

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
        $this->store = new MysqlInventoryStore($session);
        $this->subjects = [];
        $this->dropInvflowTables();

        $connection = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());

        // Configure attrecord's default Connection. In production this happens
        // once per request in Plugin::buildContainer(); the test's setUp() is
        // the equivalent bootstrap point for the test environment.
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connection);

        // The five identity-registry tables (actor_types, actors, surface_types,
        // surfaces, ref_types) are owned by MysqlDomainStore and must exist
        // BEFORE MysqlInventoryStore::bootstrap() creates the state tables that
        // FK into them. See MysqlDomainStore::installReferenceTables().
        $domainStore = new MysqlDomainStore(
            $connection,
            tablePrefix: '',
            session: $session,
        );
        SchemaFixture::install($connection);
        $domainStore->installReferenceTables();
    }

    public function testBootstrapCreatesActiveStateDimensionAndSlotspace(): void
    {
        $definition = $this->baseDefinition();

        $this->store()->bootstrap($definition);

        $schema = $this->store()->schema();
        self::assertSame(['state' => ['fs', 'res', 'sd']], $schema->dimensionsAsArray());
        self::assertCount(1, $schema->activeDimensions());
        self::assertSame('fs', $schema->dimensions[0]->defaultValue);

        $configRow = $this->fetchAssoc(<<<SQL
            SELECT quantity_scale, active_dimensions_json
            FROM invflux_config_state
            WHERE id = 1
        SQL);
        self::assertSame('0', (string) $configRow['quantity_scale']);
        self::assertSame(['state'], json_decode((string) $configRow['active_dimensions_json'], true, flags: JSON_THROW_ON_ERROR));

        // Ordered by slot_key, not by id: the id is an application-minted UUIDv7, which is only
        // *approximately* ordered — rows written inside one millisecond are separated by the random
        // component, so `ORDER BY id` returns these three in an arbitrary sequence run to run.
        $slotRows = $this->pdo()->query(<<<SQL
            SELECT slot_key, active, dim_state
            FROM invflux_slotspace ORDER BY slot_key
        SQL)->fetchAll();

        self::assertCount(3, $slotRows);
        self::assertSame([
            ['slot_key' => 'fs', 'active' => 1, 'dim_state' => 'fs'],
            ['slot_key' => 'res', 'active' => 1, 'dim_state' => 'res'],
            ['slot_key' => 'sd', 'active' => 1, 'dim_state' => 'sd'],
        ], $slotRows);
    }

    public function testDiagnosticOrchestratorRunsRegisteredDueCheck(): void
    {
        $diagSession = new PdoMysqlSession($this->pdo());
        $orchestrator = new DiagnosticOrchestrator($diagSession, new Connection($diagSession, new MysqlDialect()));
        $orchestrator->register(new class implements DiagnosticCheck {
            #[\Override]
            public function key(): string
            {
                return 'probe_check';
            }

            #[\Override]
            public function defaultFrequencySeconds(): int
            {
                return 60;
            }

            #[\Override]
            public function run(): DiagnosticResult
            {
                return new DiagnosticResult(DiagnosticStatus::Ok);
            }

            #[\Override]
            public function repair(DiagnosticResult $result): ?DiagnosticResult
            {
                unset($result);

                return null;
            }
        });
        $orchestrator->install();

        $results = $orchestrator->runOverdue();

        self::assertCount(1, $results);
        self::assertSame('probe_check', $results[0]['check_key']);
        self::assertSame(DiagnosticStatus::Ok, $results[0]['result']->status);
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_diagnostic_results WHERE check_key = 'probe_check' AND status = 'ok'"));
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_diagnostic_schedule WHERE check_key = 'probe_check' AND last_run_at IS NOT NULL"));
    }

    public function testLayerTotalsDiagnosticPassesWhenLayersAgree(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());
        $subject = $this->subject('layer-consistent');
        // Commercial (fs) total == physical (wh) total → no cross-layer drift.
        $this->insertInventoryStateBySlotKey($subject, ['fs' => 5, 'wh' => 5]);

        $orchestrator = new DiagnosticOrchestrator($s = new PdoMysqlSession($this->pdo()), new Connection($s, new MysqlDialect()));
        $orchestrator->register(new LayerTotalsCheck(new PdoMysqlSession($this->pdo())));
        $orchestrator->install();

        $results = $orchestrator->runOverdue();

        self::assertCount(1, $results);
        self::assertSame('layer_totals', $results[0]['check_key']);
        self::assertSame(DiagnosticStatus::Ok, $results[0]['result']->status);
    }

    public function testLayerTotalsDiagnosticDetectsCrossLayerDriftNotifyOnly(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());
        $subject = $this->subject('layer-drift');
        // Commercial (fs=5) != physical (wh=3). This can only arise from
        // external interference or an engine bug — the diagnostic surfaces
        // it as authority drift and does NOT auto-repair (notify-only).
        $this->insertInventoryStateBySlotKey($subject, ['fs' => 5, 'wh' => 3]);

        $orchestrator = new DiagnosticOrchestrator($s = new PdoMysqlSession($this->pdo()), new Connection($s, new MysqlDialect()));
        $orchestrator->register(new LayerTotalsCheck(new PdoMysqlSession($this->pdo())));
        $orchestrator->install();

        $results = $orchestrator->runOverdue();

        self::assertCount(1, $results);
        self::assertSame('layer_totals', $results[0]['check_key']);
        self::assertSame(DiagnosticStatus::Unresolvable, $results[0]['result']->status);

        $findings = $results[0]['result']->findings;
        self::assertCount(1, $findings);
        self::assertSame('cross_layer_drift', $findings[0]['type']);
        self::assertSame($subject->id, $findings[0]['subject_id']);
        self::assertSame(2, $findings[0]['delta']);

        // Notify-only: persisted as unresolvable, never auto_repaired.
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_diagnostic_results WHERE check_key = 'layer_totals' AND status = 'unresolvable'"));
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_diagnostic_results WHERE check_key = 'layer_totals' AND status = 'auto_repaired'"));
    }

    public function testSetQuantityScaleUpdatesConfigStateWhenStoreIsEmpty(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        self::assertSame(0, $this->store()->quantityScale());

        $this->store()->setQuantityScale(2);

        self::assertSame(2, $this->store()->quantityScale());
        $configRow = $this->fetchAssoc('SELECT quantity_scale FROM invflux_config_state WHERE id = 1');
        self::assertSame('2', (string) $configRow['quantity_scale']);
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'quantity_scale_set'"));
    }

    public function testSetQuantityScaleReturnsEarlyWhenScaleAlreadyMatches(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->store()->setQuantityScale(0);

        self::assertSame(0, $this->store()->quantityScale());
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'quantity_scale_set'"));
    }

    public function testPersistWritesInventoryStateAndLedger(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation', 'Move quantity from for-sale to reserved'),
        ]);
        $slotSpace = $definition->toSlotSpace();

        $subject1 = $this->subject('SKU-1');
        $seedState = $this->insertInventoryState($subject1, ['fs' => 10, 'res' => 0, 'sd' => 0]);
        self::assertSame(3, $seedState);

        $event = new MovementEvent(
            new MovementEdge(
                $slotSpace->slot(['state' => 'fs']),
                $slotSpace->slot(['state' => 'res']),
                'reserve',
            ),
            2,
            10,
            0,
        );

        // ref_id is BINARY(16) per arch-uuid-identity §5.5 — every UUID-keyed entity (orders,
        // events, corrections, ...) is referenced by its 16-byte surrogate id directly.
        $orderRefBinary = pack('NNNN', 0, 0, 0, 1234);
        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subject1,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
            reference: new EntityReference('order', $orderRefBinary),
            actor: new ActorReference('admin', '42'),
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(2, $persisted->affectedSlots);
        self::assertSame(1, $persisted->insertedLedgerRows);

        $balances = $this->store()->inventoryBalances($subject1);
        self::assertCount(3, $balances);
        self::assertSame([
            'fs'  => '8',
            'res' => '2',
            'sd'  => '0',
        ], $this->quantitiesByState($balances));

        $ledger = $this->store()->ledger($subject1);
        self::assertCount(1, $ledger);
        self::assertSame('InFlow', $ledger[0]->movementTypeOwnerKey);
        self::assertSame('RSRV', $ledger[0]->movementTypeCode);
        self::assertSame('fs', $ledger[0]->fromDimensions['state'] ?? null);
        self::assertSame('res', $ledger[0]->toDimensions['state'] ?? null);
        self::assertSame(2, $ledger[0]->quantity);
        self::assertSame('order', $ledger[0]->referenceType);
        self::assertSame($orderRefBinary, $ledger[0]->referenceId);
        self::assertSame('admin', $ledger[0]->actorTypeCode);
        self::assertSame('42', $ledger[0]->actorId);
    }

    public function testPersistUsesOneStableRecordedAtAcrossContextLedgerAndResult(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $this->createProjectionProbeTable();
        $participant = new ProbeProjectionParticipant('invflux_projection_probe');
        $this->store()->registerProjectionParticipant($participant);

        $slotSpace = $definition->toSlotSpace();
        $subjectTime = $this->subject('SKU-TIME');
        $this->insertInventoryState($subjectTime, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res']), 'reserve'),
            2,
            10,
            0,
        );

        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subjectTime,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        $ledgerRow = $this->fetchAssoc(<<<SQL
            SELECT recorded_at
            FROM invflux_inventory_ledger
            WHERE subject_id = {$subjectTime->id}
            ORDER BY id DESC
            LIMIT 1
        SQL);

        self::assertNotNull($participant->lastRecordedAt);
        self::assertSame(
            $participant->lastRecordedAt->format('Y-m-d H:i:s.u'),
            $persisted->recordedAt->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            $participant->lastRecordedAt->format('Y-m-d H:i:s.u'),
            (string) $ledgerRow['recorded_at'],
        );
    }

    public function testPersistPreservesBinary16ReferenceIdsExactly(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $slotSpace = $definition->toSlotSpace();

        $subjectBigref = $this->subject('SKU-BIGREF');
        $this->insertInventoryState($subjectBigref, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res']), 'reserve'),
            2,
            10,
            0,
        );

        // A real BINARY(16) UUID (hex 01_8F_4B…_99) — verifies that the engine stores the
        // 16 bytes exactly, without any truncation or decoding pass.
        $orderRefBinary = hex2bin('018F4B0BDEAD000000000000000000FF');
        self::assertNotFalse($orderRefBinary);
        self::assertSame(16, strlen($orderRefBinary));

        $this->store()->persist(new PersistMovement(
            subjectId: $subjectBigref,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
            reference: new EntityReference('order', $orderRefBinary),
        ));

        $ledgerRow = $this->fetchAssoc(<<<SQL
            SELECT ref_id
            FROM invflux_inventory_ledger
            WHERE subject_id = {$subjectBigref->id}
            ORDER BY id DESC
            LIMIT 1
        SQL);
        self::assertSame($orderRefBinary, $ledgerRow['ref_id']);
    }

    public function testPersistRoutesIntReferenceToRefIntIdLane(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $slotSpace = $definition->toSlotSpace();

        $subjectPoRef = $this->subject('SKU-POREF');
        $this->insertInventoryState($subjectPoRef, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res']), 'reserve'),
            2,
            10,
            0,
        );

        // An INT-keyed document reference (a PO id) routes to ref_int_id by value type,
        // leaving the BINARY(16) ref_id lane null. See arch-uuid-identity §5.5.
        $this->store()->persist(new PersistMovement(
            subjectId: $subjectPoRef,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
            reference: new EntityReference('purchase_order', 4242),
        ));

        $ledgerRow = $this->fetchAssoc(<<<SQL
            SELECT ref_id, ref_int_id
            FROM invflux_inventory_ledger
            WHERE subject_id = {$subjectPoRef->id}
            ORDER BY id DESC
            LIMIT 1
        SQL);
        self::assertNull($ledgerRow['ref_id'], 'an int ref never touches the BINARY(16) lane');
        self::assertSame(4242, (int) $ledgerRow['ref_int_id']);
    }

    public function testPersistReturnsConflictsWithoutWritingStateOrLedger(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $slotSpace = $definition->toSlotSpace();

        $subjectConflict = $this->subject('SKU-CONFLICT');
        $this->insertInventoryState($subjectConflict, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge(
                $slotSpace->slot(['state' => 'fs']),
                $slotSpace->slot(['state' => 'res']),
                'reserve',
            ),
            2,
            10,
            0,
        );

        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subjectConflict,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
            guardsBySlotKey: [
                'fs' => new QuantityGuard(min: 9),
            ],
        ));

        self::assertFalse($persisted->ok);
        self::assertSame(0, $persisted->affectedSlots);
        self::assertSame(0, $persisted->insertedLedgerRows);
        self::assertCount(1, $persisted->conflicts);
        self::assertSame('min_quantity', $persisted->conflicts[0]->reason);
        self::assertSame($subjectConflict->id, $persisted->conflicts[0]->subjectId->id);
        self::assertSame('fs', $persisted->conflicts[0]->slotKey);
        self::assertSame('8', $persisted->conflicts[0]->projectedQuantity);
        self::assertSame('9', $persisted->conflicts[0]->minQuantity);
        self::assertSame([
            'fs'  => '10',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectConflict)));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistConflictRollsBackNewlyInsertedZeroQuantityRows(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $slotSpace = $definition->toSlotSpace();

        $subjectNewConflict = $this->subject('SKU-NEW-CONFLICT');

        $event = new MovementEvent(
            new MovementEdge(
                $slotSpace->slot(['state' => 'fs']),
                $slotSpace->slot(['state' => 'res']),
                'reserve',
            ),
            2,
            0,
            0,
        );

        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subjectNewConflict,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
            guardsBySlotKey: [
                'fs' => new QuantityGuard(min: 0),
            ],
        ));

        self::assertFalse($persisted->ok);
        self::assertCount(1, $persisted->conflicts);
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_inventory_state WHERE subject_id = {$subjectNewConflict->id}"));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testRecordMetaEventWritesSeparateAuditTrail(): void
    {
        $this->store()->recordMetaEvent(new MetaEvent(
            eventType: 'quantity_scale_changed',
            payload: ['from' => 0, 'to' => 2],
            actor: new ActorReference('admin', '7'),
            reference: new EntityReference('setting', 1),
        ));

        $row = $this->fetchAssoc(<<<SQL
            SELECT ml.event_type, a.actor_ref AS actor_id, at.code AS actor_type_code,
                   rt.code AS ref_type, ml.ref_id, ml.payload_json
            FROM invflux_schema_ledger ml
            LEFT JOIN invflux_actors a ON a.id = ml.actor_id
            LEFT JOIN invflux_actor_types at ON at.id = a.actor_type_id
            LEFT JOIN invflux_ref_types rt ON rt.id = ml.ref_type_id
            ORDER BY ml.id DESC
            LIMIT 1
        SQL);

        self::assertSame('quantity_scale_changed', $row['event_type']);
        self::assertSame('admin', $row['actor_type_code']);
        self::assertSame('7', $row['actor_id']);
        self::assertSame('setting', $row['ref_type']);
        self::assertSame('1', (string) $row['ref_id']);
        // Key order is not a property of the payload. MySQL's native JSON type normalises objects
        // on the way in, ordering keys by length and then bytes — so `{"from":0,"to":2}` comes
        // back as `{"to":2,"from":0}`. MariaDB stores JSON as LONGTEXT and preserves insertion
        // order, so an order-sensitive assertSame passed locally and failed on CI. Sorting makes
        // the assertion say what it means while keeping value types strict.
        /** @psalm-var array<string, int> $payload */
        $payload = json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        ksort($payload);
        self::assertSame(['from' => 0, 'to' => 2], $payload);
    }

    public function testPersistAppliesRegisteredProjectionParticipantsInsideTransaction(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $this->createProjectionProbeTable();
        $this->store()->registerProjectionParticipant(new ProbeProjectionParticipant('invflux_projection_probe'));

        $slotSpace = $definition->toSlotSpace();
        $subjectProj = $this->subject('SKU-PROJECTION');
        $this->insertInventoryState($subjectProj, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge(
                $slotSpace->slot(['state' => 'fs']),
                $slotSpace->slot(['state' => 'res']),
                'reserve',
            ),
            2,
            10,
            0,
        );

        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subjectProj,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        self::assertTrue($persisted->ok);
        $probeRows = $this->pdo()->query('SELECT resource_key, quantity_delta, movement_code FROM invflux_projection_probe ORDER BY resource_key')->fetchAll();
        self::assertSame([
            ['resource_key' => $subjectProj->id.':fs', 'quantity_delta' => -2, 'movement_code' => 'RSRV'],
            ['resource_key' => $subjectProj->id.':res', 'quantity_delta' => 2, 'movement_code' => 'RSRV'],
        ], $probeRows);
    }

    public function testRegisterProjectionParticipantRejectsDuplicateKeys(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('already registered');

        $this->store()->registerProjectionParticipant(new ProbeProjectionParticipant('invflux_projection_probe'));
        $this->store()->registerProjectionParticipant(new ProbeProjectionParticipant('invflux_projection_probe'));
    }

    public function testBootstrapRejectsMismatchedExistingSchema(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $different = new SlotSpaceDefinition('default', [
            DimensionDefinition::define('status', ['fs', 'res'], 0, 'fs'),
        ]);

        // The additive reconciler still rejects a genuinely incompatible schema — here a dimension
        // the stored schema never had — as a schema_mismatch (it only *adds*, never redefines).
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('is not present in the stored schema');

        $this->store()->bootstrap($different);
    }

    public function testBootstrapReconcilesAdditiveDimensionValueWithoutThrowing(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        // A re-bootstrap whose `state` dimension declares one extra value must reconcile (register
        // the new value), not throw — this is the values plane: a definition that only *adds* is applied.
        $this->store()->bootstrap(new SlotSpaceDefinition('default', [
            DimensionDefinition::define('state', ['fs', 'res', 'sd', 'qi'], 0, 'fs'),
        ]));

        self::assertNotNull(
            $this->store()->schema()->dimensionByName('state')?->valuesByCode('qi', includeInactive: true),
            'the value the request added over the stored schema is registered, not rejected',
        );
    }

    public function testBootstrapRejectsDimensionValueRedefinition(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        // Same code `fs`, but now carrying a structural level it did not have — a redefinition, not
        // an addition. The reconciler only *adds*; a changed attribute on an existing value throws.
        $redefined = new SlotSpaceDefinition('default', [
            new DimensionDefinition('state', 0, [
                new DimensionValueDefinition('fs', level: 'zone'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('sd'),
            ], 'fs'),
        ]);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('is redefined');

        $this->store()->bootstrap($redefined);
    }

    public function testBootstrapAbsorbsFixedToSharedDimensionTransition(): void
    {
        // Stored as a fixed-value `stt` dimension...
        $this->store()->bootstrap(new SlotSpaceDefinition('default', [
            DimensionDefinition::define('stt', ['atp', 'res', 'ctd'], 0, 'atp'),
        ]));

        // ...then re-bootstrapped with `stt` as a sharedRef (the values-plane migration). The stored
        // values simply become the shared dimension's runtime values — the transition is absorbed,
        // never a schema_mismatch.
        $this->store()->bootstrap(new SlotSpaceDefinition('default', [
            DimensionDefinition::sharedRef('stt', position: 0, valueSelector: DimensionValueSelector::root()),
        ]));

        // The persisted layer config now records stt as shared (its values live in the DB, so the
        // flat schema() accessor still reconstructs it as fixed — the sharedRef flag lives in the
        // layer snapshot, exactly as it does for `loc`).
        $snapshot = $this->store()->schemaDefinitionAtTime(new \DateTimeImmutable('+1 second'));
        /** @var array<string, array<string, mixed>> $layers */
        $layers = $snapshot['layers'];
        /** @var list<array<string, mixed>> $dims */
        $dims = $layers['default']['dimensions'] ?? [];
        $stt = null;
        foreach ($dims as $dim) {
            if ('stt' === ($dim['name'] ?? null)) {
                $stt = $dim;
                break;
            }
        }
        self::assertNotNull($stt, 'stt survives the transition as a dimension');
        self::assertTrue($stt['sharedRef'] ?? false, 'stt is recorded as shared after the fixed→shared transition');
    }

    public function testBootstrapRollsBackOnDimensionInsertFailure(): void
    {
        // Replace the converged slotspace with an incompatible one. It has to be swapped rather
        // than merely created now that the schema installer builds it in setUp — the point of the
        // test is unchanged: whatever makes bootstrap fail, it must not leave a transaction open.
        // `id` must keep its real BINARY(16) type even though every other column is dropped.
        // `invflux_inventory_ledger.from_slot_id` has a foreign key onto it, and MySQL validates
        // that the two column types match at CREATE TABLE time — `FOREIGN_KEY_CHECKS = 0` waives
        // missing parent *rows*, not type compatibility. Declaring it BIGINT UNSIGNED here made
        // the setup itself die with errno 3780 (and outside the try/catch below, so the test
        // errored rather than failed). MariaDB is lenient about this and accepted it, which is
        // why it went unnoticed until CI ran the suite against MySQL 8.4.
        //
        // Dropping the *other* columns is what makes the schema incompatible, and that is all the
        // test needs.
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo()->exec('DROP TABLE invflux_slotspace');
        $this->pdo()->exec(<<<SQL
            CREATE TABLE invflux_slotspace (
                id BINARY(16) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB
        SQL);
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');

        try {
            $this->store()->bootstrap($this->baseDefinition());
            self::fail('Expected bootstrap to fail when pre-existing slotspace table is incompatible.');
        } catch (\Throwable) {
            self::assertFalse($this->pdo()->inTransaction());
        }
    }

    /**
     * A dropped dimension index is restored by *converging*, not by bootstrapping.
     *
     * The dimension columns and their indexes are declared like everything else, so the repair
     * falls out of the normal diff — and is classified `Safe`, so an upgrade applies it unattended.
     */
    public function testConvergenceRestoresADroppedDimensionIndex(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->pdo()->exec('ALTER TABLE invflux_slotspace DROP INDEX idx_active_state');

        SchemaFixture::install(AttrecordRecord::connection());

        $indexRow = $this->fetchAssoc(<<<SQL
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'invflux_slotspace'
              AND INDEX_NAME = 'idx_active_state'
            LIMIT 1
        SQL);

        self::assertSame('idx_active_state', $indexRow['INDEX_NAME']);
    }

    public function testDimensionValueNameRoundTripsThroughStorageSchema(): void
    {
        $definition = new SlotSpaceDefinition('default', [
            new DimensionDefinition('loc', 0, [
                new DimensionValueDefinition('wh1', name: 'Lausanne Warehouse'),
                new DimensionValueDefinition('wh2', name: 'Geneva Warehouse'),
            ], 'wh1'),
        ]);

        $this->store()->bootstrap($definition);

        $schema = $this->store()->schema();
        self::assertSame('wh1', $schema->dimensions[0]->values[0]->code);
        self::assertSame('Lausanne Warehouse', $schema->dimensions[0]->values[0]->name);

        $row = $this->fetchAssoc("SELECT code, name FROM invflux_dimension_values WHERE code = 'wh1'");
        self::assertSame(['code' => 'wh1', 'name' => 'Lausanne Warehouse'], $row);
    }

    public function testDrainAndDisableDimensionValueHidesLegacyValuesFromActiveSchema(): void
    {
        $definition = new SlotSpaceDefinition('default', [
            new DimensionDefinition('state', 0, [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('sd'),
                new DimensionValueDefinition(
                    code: 'ret',
                    ownerKey: 'Addon',
                    removalTargetCode: 'fs',
                ),
            ], 'fs'),
        ]);

        $this->store()->bootstrap($definition);
        $subjectRet = $this->subject('SKU-RET');
        $this->insertInventoryState($subjectRet, ['fs' => 3, 'res' => 0, 'sd' => 0, 'ret' => 2]);

        $this->store()->drainAndDisableDimensionValue('state', 'ret', '_nil');

        self::assertSame(['state' => ['fs', 'res', 'sd']], $this->store()->schema()->dimensionsAsArray());
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE dim_state = 'ret' AND active = 1"));
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE dim_state = 'ret' AND active = 0"));
        self::assertSame([
            'fs'  => '3',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectRet)));

        $this->store()->bootstrap($this->baseDefinition());
        self::assertSame(['state' => ['fs', 'res', 'sd']], $this->store()->schema()->dimensionsAsArray());
    }

    public function testDrainAndDisableDimensionValueMapsQuantitiesIntoTargetValue(): void
    {
        $definition = new SlotSpaceDefinition('default', [
            new DimensionDefinition('state', 0, [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('sd'),
                new DimensionValueDefinition(
                    code: 'ret',
                    ownerKey: 'Addon',
                    removalTargetCode: 'fs',
                ),
            ], 'fs'),
        ]);

        $this->store()->bootstrap($definition);
        $subjectMap1 = $this->subject('SKU-MAP1');
        $subjectMap2 = $this->subject('SKU-MAP2');
        $this->insertInventoryState($subjectMap1, ['fs' => 3, 'res' => 0, 'sd' => 0, 'ret' => 2]);
        $this->insertInventoryState($subjectMap2, ['fs' => 1, 'res' => 1, 'sd' => 2, 'ret' => 1]);

        $this->store()->drainAndDisableDimensionValue(dimensionName: 'state', sourceValue: 'ret');

        self::assertSame(['state' => ['fs', 'res', 'sd']], $this->store()->schema()->dimensionsAsArray());
        self::assertSame(
            ['fs'  => '5', 'res' => '0', 'sd'  => '0'],
            $this->quantitiesByState($this->store()->inventoryBalances($subjectMap1)),
        );
        self::assertSame(
            ['fs'  => '2', 'res' => '1', 'sd'  => '2'],
            $this->quantitiesByState($this->store()->inventoryBalances($subjectMap2)),
        );

        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_dimension_values WHERE code = 'ret' AND active = 0"));

        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_inventory_state s JOIN invflux_slotspace ss ON ss.id = s.slot_id WHERE ss.dim_state = 'ret'"));
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'dimension_value_disabled'"));
        $schemaLedgerRow = $this->fetchAssoc(<<<SQL
            SELECT rt.code AS ref_type, cl.ref_id, cl.payload_json
            FROM invflux_schema_ledger cl
            LEFT JOIN invflux_ref_types rt ON rt.id = cl.ref_type_id
            WHERE cl.event_type = 'dimension_value_disabled'
            ORDER BY cl.id DESC
            LIMIT 1
        SQL);
        $dimensionValueRow = $this->fetchAssoc(<<<SQL
            SELECT id
            FROM invflux_dimension_values
            WHERE code = 'ret'
            ORDER BY id DESC
            LIMIT 1
        SQL);
        self::assertSame('dimension_value', $schemaLedgerRow['ref_type']);
        self::assertSame((string) $dimensionValueRow['id'], (string) $schemaLedgerRow['ref_id']);
        // Sorted before comparing, for the same reason as the payload assertion in
        // testRecordMetaEventWritesSeparateAuditTrail: MySQL's JSON type reorders object keys and
        // MariaDB does not, so key order is not a property this test can assert.
        /** @psalm-var array<string, string> $drainPayload */
        $drainPayload = json_decode((string) $schemaLedgerRow['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        ksort($drainPayload);
        self::assertSame(
            [
                'dimension' => 'state',
                'source'    => 'ret',
                'target'    => 'fs',
            ],
            $drainPayload,
        );

        $inventoryLedgerEntries = $this->pdo()->query('SELECT * FROM invflux_inventory_ledger')->fetchAll();
        self::assertSame(2, count($inventoryLedgerEntries));
    }

    public function testDrainAndDisableDimensionValueRunsProjectionParticipantsThroughSharedPersistencePath(): void
    {
        $definition = new SlotSpaceDefinition('default', [
            new DimensionDefinition('state', 0, [
                new DimensionValueDefinition('fs'),
                new DimensionValueDefinition('res'),
                new DimensionValueDefinition('sd'),
                new DimensionValueDefinition(
                    code: 'ret',
                    ownerKey: 'Addon',
                    removalTargetCode: 'fs',
                ),
            ], defaultValue: 'fs'),
        ]);

        $this->store()->bootstrap($definition);
        $this->createProjectionProbeTable();
        $this->store()->registerProjectionParticipant(new ProbeProjectionParticipant('invflux_projection_probe'));
        $subjectProj1 = $this->subject('SKU-PROJ1');
        $subjectProj2 = $this->subject('SKU-PROJ2');
        $this->insertInventoryState($subjectProj1, ['fs' => 3, 'res' => 0, 'sd' => 0, 'ret' => 2]);
        $this->insertInventoryState($subjectProj2, ['fs' => 1, 'res' => 1, 'sd' => 2, 'ret' => 1]);

        $this->store()->drainAndDisableDimensionValue('state', 'ret');

        $probeRows = $this->pdo()->query(<<<SQL
            SELECT resource_key, quantity_delta, movement_code
            FROM invflux_projection_probe
            ORDER BY resource_key
        SQL)->fetchAll();

        self::assertSame([
            ['resource_key' => $subjectProj1->id.':fs', 'quantity_delta' => 2, 'movement_code' => 'VALMIG'],
            ['resource_key' => $subjectProj1->id.':ret', 'quantity_delta' => -2, 'movement_code' => 'VALMIG'],
            ['resource_key' => $subjectProj2->id.':fs', 'quantity_delta' => 1, 'movement_code' => 'VALMIG'],
            ['resource_key' => $subjectProj2->id.':ret', 'quantity_delta' => -1, 'movement_code' => 'VALMIG'],
        ], $probeRows);
    }

    public function testExecuteBatchFlowFromStorageAcceptsPerSubjectQuantities(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $subjectQ1 = $this->subject('SKU-Q1');
        $subjectQ2 = $this->subject('SKU-Q2');
        $this->insertInventoryState($subjectQ1, ['fs' => 10, 'res' => 0, 'sd' => 0]);
        $this->insertInventoryState($subjectQ2, ['fs' => 4, 'res' => 0, 'sd' => 0]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
            subjectIds: [$subjectQ1, $subjectQ2],
            slotFilters: ['state' => 'fs'],
            quantitiesBySubjectId: [
                $subjectQ1->id => 2,
                $subjectQ2->id => 1,
            ],
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(2, $persisted->affectedSubjects);
        self::assertSame(4, $persisted->affectedSlots);
        self::assertSame(2, $persisted->insertedLedgerRows);
        self::assertSame([
            'fs'  => '8',
            'res' => '2',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectQ1)));
        self::assertSame([
            'fs'  => '3',
            'res' => '1',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectQ2)));
    }

    /**
     * A flow may be named instead of built — and the name has to resolve against the definition the
     * adapter **bootstrapped**, because flows are code and are never persisted: the schema rebuilt
     * from the dimension rows carries no flow map at all.
     *
     * The zero-stock subject is the point. The write-in fast path exists so a first receipt of a
     * brand-new subject posts (its delta is state-independent), and it used to be entered only when
     * the caller handed in a built `Flow`. A *named* write-in fell through to the general path,
     * whose `quantity <> 0` selection drops exactly this subject — posting nothing and reporting
     * success.
     */
    public function testExecuteBatchFlowFromStorageResolvesAFlowByNameIncludingTheWriteInFastPath(): void
    {
        $definition = $this->baseDefinition()
            ->withFlow(FlowDefinition::define('take_in')->create(['state' => '{target}']));
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        // Deliberately NOT seeded: no inventory rows at all.
        $fresh = $this->subject('SKU-BY-NAME');

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'take_in',
            subjectIds: [$fresh],
            params: ['target' => 'fs'],
            quantitiesBySubjectId: [$fresh->id => 5],
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(1, $persisted->affectedSubjects);
        // Only the target slot has a row: a brand-new subject starts with none, and a create
        // movement writes the one it touched.
        self::assertSame(['fs' => '5'], $this->quantitiesByState($this->store()->inventoryBalances($fresh)));
    }

    /** An unregistered name is a configuration error, and says so rather than moving nothing. */
    public function testExecuteBatchFlowFromStorageRefusesAnUnknownFlowName(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $subject = $this->subject('SKU-NO-SUCH-FLOW');
        $this->insertInventoryState($subject, ['fs' => 3, 'res' => 0, 'sd' => 0]);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('not registered on the bootstrapped schema');

        $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'no_such_flow',
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 1],
        ));
    }

    public function testExecuteBatchFlowFromStorageWritesLedgerRow(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $subjectContext = $this->subject('SKU-CONTEXT');
        $this->insertInventoryState($subjectContext, ['fs' => 5, 'res' => 0, 'sd' => 0]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
            subjectIds: [$subjectContext],
            slotFilters: ['state' => 'fs'],
            quantitiesBySubjectId: [$subjectContext->id => 2],
            executionContext: ['scheduler' => 'checkout'],
        ));

        self::assertTrue($persisted->ok);

        $ledger = $this->store()->ledger($subjectContext);
        self::assertCount(1, $ledger);
        self::assertSame(2, $ledger[0]->quantity);
    }

    public function testWriteInFlowRecordsTargetPreBalanceAsLedgerInitialTo(): void
    {
        // Regression guard: a write-in (`nil → slot`) into a NON-empty slot must record the target's
        // current on-hand as the ledger `initial_to`, not 0. A create-flow fast path that runs the
        // solver from an empty state (skipping the state read) yields `initial_to` 0 even though the
        // resulting stock is correct — a silent audit-trail bug on every goods receipt / reconcile
        // into an already-stocked slot.
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RCPT', 'Receipt'),
        ]);

        $subject = $this->subject('SKU-WRITEIN');
        $this->insertInventoryState($subject, ['fs' => 29, 'res' => 0, 'sd' => 0]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RCPT',
            flow: Flow::define('receipt', static fn (Flow $flow) => $flow->move(null, 'fs')),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 5],
        ));

        self::assertTrue($persisted->ok);
        // Stock is the delta applied to the authoritative row: 29 + 5 = 34 (unchanged by the fix).
        self::assertSame([
            'fs'  => '34',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subject)));

        $ledgerRow = $this->fetchAssoc(sprintf(
            'SELECT quantity, initial_from, initial_to
             FROM invflux_inventory_ledger WHERE subject_id = %d ORDER BY id DESC LIMIT 1',
            $subject->id,
        ));
        self::assertSame('5', (string) $ledgerRow['quantity']);
        self::assertNull($ledgerRow['initial_from'], 'nil source → no initial_from');
        self::assertSame('29', (string) $ledgerRow['initial_to'], 'write-in records the target pre-balance, not 0');
    }

    public function testExecuteBatchFlowFromStorageAcceptsCustomQuantityResolver(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $subjectR1 = $this->subject('SKU-R1');
        $subjectR2 = $this->subject('SKU-R2');
        $this->insertInventoryState($subjectR1, ['fs' => 10, 'res' => 0, 'sd' => 0]);
        $this->insertInventoryState($subjectR2, ['fs' => 7, 'res' => 0, 'sd' => 0]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
            subjectIds: [$subjectR1, $subjectR2],
            slotFilters: ['state' => 'fs'],
            quantityResolver: static function (SubjectId $subjectId, array $rows) use ($subjectR1, $subjectR2): int {
                self::assertNotSame([], $rows);

                return match ($subjectId->id) {
                    $subjectR1->id => 3,
                    $subjectR2->id => 5,
                    default        => throw new \LogicException('Unexpected subject id.'),
                };
            },
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(2, $persisted->affectedSubjects);
        self::assertSame(4, $persisted->affectedSlots);
        self::assertSame(2, $persisted->insertedLedgerRows);
        self::assertSame([
            'fs'  => '7',
            'res' => '3',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectR1)));
        self::assertSame([
            'fs'  => '2',
            'res' => '5',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectR2)));
    }

    public function testPersistRejectsUnregisteredMovementType(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $slotSpace = $definition->toSlotSpace();

        $subject1 = $this->subject('SKU-1');

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            1,
            1,
            0,
        );

        $this->expectException(UnknownMovementTypeException::class);
        $this->expectExceptionMessage('has not been registered');

        $this->store()->persist(new PersistMovement(
            subjectId: $subject1,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'MISS',
            movementResult: new MovementResult([$event], 0),
        ));
    }

    public function testPersistReturnsEarlyWhenMovementHasNoEffectiveDeltas(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectZero = $this->subject('SKU-ZERO');
        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            0,
            10,
            0,
        );

        $persisted = $this->store()->persist(new PersistMovement(
            subjectId: $subjectZero,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(0, $persisted->affectedSlots);
        self::assertSame(0, $persisted->insertedLedgerRows);

        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_state'));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testMigrateQuantityScaleMultipliesPersistedQuantities(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectScale = $this->subject('SKU-SCALE');
        $this->insertInventoryState($subjectScale, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            2,
            10,
            0,
        );
        $this->store()->persist(new PersistMovement(
            subjectId: $subjectScale,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        $this->store()->migrateQuantityScale(2);

        self::assertSame(2, $this->store()->quantityScale());
        self::assertSame([
            'fs'  => '800',
            'res' => '200',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectScale)));

        $ledgerRow = $this->fetchAssoc(<<<SQL
            SELECT quantity, initial_from, initial_to
            FROM invflux_inventory_ledger
            ORDER BY id DESC
            LIMIT 1
        SQL);
        self::assertSame('200', (string) $ledgerRow['quantity']);
        self::assertSame('1000', (string) $ledgerRow['initial_from']);
        self::assertSame('0', (string) $ledgerRow['initial_to']);
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'quantity_scale_changed'"));
    }

    public function testMigrateQuantityScaleRejectsUnsafeDecrease(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->setQuantityScale(2);
        $this->insertInventoryState($this->subject('SKU-UNSAFE'), ['fs' => 125, 'res' => 0, 'sd' => 0]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('lose precision');

        $this->store()->migrateQuantityScale(1);
    }

    /**
     * A subject carrying its own scale stores quantities at that grain, so the global delta must
     * not be applied to them. The migration cannot tell the two apart, so it refuses outright
     * rather than silently multiplying them by a factor that never applied.
     */
    public function testMigrateQuantityScaleRefusesWhenASubjectOverridesTheScale(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $subjectId = $this->subject('SKU-GRAIN');
        $this->insertInventoryState($subjectId, ['fs' => 5, 'res' => 0, 'sd' => 0]);
        Subject::updateWhere(['scale' => 3], 'id = ?', [$subjectId->id]);

        try {
            $this->store()->migrateQuantityScale(2);
            self::fail('Expected the migration to refuse while a subject overrides the scale.');
        } catch (ConfigurationException $e) {
            self::assertSame('subject_scale_overrides_present', $e->detailCode);
        }

        // And it refused *before* touching anything — scale and quantities both unmoved. Name the
        // state: the subject carries a row per slot (fs/res/sd) and only `fs` holds the 5, so a bare
        // `LIMIT 1` picks an arbitrary one and the assertion passes or fails by physical row order.
        self::assertSame(0, $this->store()->quantityScale());
        self::assertSame(5, $this->fetchCount(sprintf(
            "SELECT st.quantity FROM invflux_inventory_state st
             JOIN invflux_slotspace sp ON sp.id = st.slot_id
             WHERE st.subject_id = %d AND sp.dim_state = 'fs'",
            $subjectId->id,
        )));
    }

    public function testMigrateQuantityScaleReturnsEarlyWhenScaleAlreadyMatches(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->store()->migrateQuantityScale(0);

        self::assertSame(0, $this->store()->quantityScale());
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'quantity_scale_changed'"));
    }

    public function testMigrateQuantityScaleDividesPersistedQuantitiesWhenSafe(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->setQuantityScale(2);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectDivide = $this->subject('SKU-DIVIDE');
        $this->insertInventoryState($subjectDivide, ['fs' => 120, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            20,
            120,
            0,
        );
        $this->store()->persist(new PersistMovement(
            subjectId: $subjectDivide,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        $this->store()->migrateQuantityScale(1);

        self::assertSame(1, $this->store()->quantityScale());
        self::assertSame([
            'fs'  => '10',
            'res' => '2',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectDivide)));

        $ledgerRow = $this->fetchAssoc(<<<SQL
            SELECT quantity, initial_from, initial_to
            FROM invflux_inventory_ledger
            ORDER BY id DESC
            LIMIT 1
        SQL);
        self::assertSame('2', (string) $ledgerRow['quantity']);
        self::assertSame('12', (string) $ledgerRow['initial_from']);
        self::assertSame('0', (string) $ledgerRow['initial_to']);
    }

    /**
     * Helper to insert inventory state for a subject. Returns the number of inserted rows.
     *
     * @param array<non-empty-string, int> $stateQuantities
     */
    private function insertInventoryState(SubjectId $subjectId, array $stateQuantities): bool | int
    {
        $cases = [];
        foreach ($stateQuantities as $state => $quantity) {
            $cases[] = "WHEN '$state' THEN $quantity";
        }
        $casesSql = implode("\n", $cases);

        return $this->pdo()->exec(<<<SQL
            INSERT INTO invflux_inventory_state (subject_id, slot_id, quantity)
            SELECT {$subjectId->id}, id, CASE dim_state $casesSql END
            FROM invflux_slotspace
        SQL);
    }

    public function testPersistBatchAppliesMultiSubjectMovementsAtomically(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectA = $this->subject('SKU-A');
        $subjectB = $this->subject('SKU-B');
        $seedA = $this->insertInventoryState($subjectA, ['fs' => 10, 'res' => 0, 'sd' => 0]);
        $seedB = $this->insertInventoryState($subjectB, ['fs' => 3, 'res' => 0, 'sd' => 0]);

        self::assertSame(3, $seedA);
        self::assertSame(3, $seedB);

        /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
        $rows = [
            ['sku' => 'SKU-A', 'state' => 'fs', 'qty' => 10, 'request_qty' => 2],
            ['sku' => 'SKU-A', 'state' => 'res', 'qty' => 0, 'request_qty' => 2],
            ['sku' => 'SKU-B', 'state' => 'fs', 'qty' => 3, 'request_qty' => 1],
            ['sku' => 'SKU-B', 'state' => 'res', 'qty' => 0, 'request_qty' => 1],
        ];

        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): string $subjectGetter */
        $subjectGetter = static function (array $row): string {
            /** @var array{sku: string, state: string, qty: int, request_qty: int} $row */
            return $row['sku'];
        };
        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): list<array{0: array{state: non-empty-string}, 1: int}> $slotRowGetter */
        $slotRowGetter = static function (array $row): array {
            /** @var non-empty-string $state */
            $state = $row['state'];

            return [
                [['state' => $state], $row['qty']],
            ];
        };
        /** @var \Closure(list<array{sku: string, state: string, qty: int, request_qty: int}>): int $quantityGetter */
        $quantityGetter = static function (array $rows): int {
            /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
            return $rows[0]['request_qty'];
        };
        $batch = QuantityStateBatch::fromRows(
            space: $slotSpace,
            rows: $rows,
            subjectGetter: $subjectGetter,
            slotRowGetter: $slotRowGetter,
            quantityGetter: $quantityGetter,
        );
        $batch = (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $slotSpace,
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
        );

        /** @var array<string, SubjectId> $subjectMap */
        $subjectMap = ['SKU-A' => $subjectA, 'SKU-B' => $subjectB];

        $persisted = $this->store()->persistBatch(new PersistBatchMovement(
            movementBatch: $batch,
            subjectIdResolver: static fn (string $sku): SubjectId => $subjectMap[$sku]
                ?? throw new \LogicException("Unknown subject: $sku"),
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(2, $persisted->affectedSubjects);
        self::assertSame(4, $persisted->affectedSlots);
        self::assertSame(2, $persisted->insertedLedgerRows);

        self::assertSame([
            'fs'  => '8',
            'res' => '2',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectA)));
        self::assertSame([
            'fs'  => '2',
            'res' => '1',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectB)));
        self::assertSame(2, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistBatchReturnsConflictsWithoutWritingStateOrLedger(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectBatchConflict = $this->subject('SKU-BATCH-CONFLICT');
        $this->insertInventoryState($subjectBatchConflict, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
        $rows = [
            ['sku' => 'SKU-BATCH-CONFLICT', 'state' => 'fs', 'qty' => 10, 'request_qty' => 2],
            ['sku' => 'SKU-BATCH-CONFLICT', 'state' => 'res', 'qty' => 0, 'request_qty' => 2],
        ];

        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): string $subjectGetter */
        $subjectGetter = static function (array $row): string {
            /** @var array{sku: string, state: string, qty: int, request_qty: int} $row */
            return $row['sku'];
        };
        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): list<array{0: array{state: non-empty-string}, 1: int}> $slotRowGetter */
        $slotRowGetter = static function (array $row): array {
            /** @var non-empty-string $state */
            $state = $row['state'];

            return [[['state' => $state], $row['qty']]];
        };
        /** @var \Closure(list<array{sku: string, state: string, qty: int, request_qty: int}>): int $quantityGetter */
        $quantityGetter = static function (array $rows): int {
            /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
            return $rows[0]['request_qty'];
        };
        $batch = QuantityStateBatch::fromRows(
            space: $slotSpace,
            rows: $rows,
            subjectGetter: $subjectGetter,
            slotRowGetter: $slotRowGetter,
            quantityGetter: $quantityGetter,
        );
        $batch = (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $slotSpace,
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
        );

        $persisted = $this->store()->persistBatch(new PersistBatchMovement(
            movementBatch: $batch,
            subjectIdResolver: static fn (mixed $_): SubjectId => $subjectBatchConflict,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            guardsBySubjectIdAndSlotKey: [
                $subjectBatchConflict->id => [
                    'fs' => new QuantityGuard(min: 9),
                ],
            ],
        ));

        self::assertFalse($persisted->ok);
        self::assertSame(0, $persisted->affectedSubjects);
        self::assertSame(0, $persisted->affectedSlots);
        self::assertSame(0, $persisted->insertedLedgerRows);
        self::assertCount(1, $persisted->conflicts);
        self::assertSame('min_quantity', $persisted->conflicts[0]->reason);
        self::assertSame([
            'fs'  => '10',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectBatchConflict)));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistBatchReturnsEarlyWhenBatchHasNoDeltas(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $persisted = $this->store()->persistBatch(new PersistBatchMovement(
            movementBatch: new QuantityStateBatch([]),
            subjectIdResolver: static fn (mixed $_): SubjectId => new SubjectId(1),
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
        ));

        self::assertSame(0, $persisted->affectedSubjects);
        self::assertSame(0, $persisted->affectedSlots);
        self::assertSame(0, $persisted->insertedLedgerRows);
    }

    public function testPersistBatchReturnsEarlyWhenNormalizedDeltasCollapseToZero(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);

        $slotSpace = $definition->toSlotSpace();
        $subjectZeroBatch = $this->subject('SKU-ZERO-BATCH');
        $persisted = $this->store()->persistBatch(new PersistBatchMovement(
            movementBatch: new ZeroDeltaBatch($slotSpace),
            subjectIdResolver: static fn (mixed $_): SubjectId => $subjectZeroBatch,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(0, $persisted->affectedSubjects);
        self::assertSame(0, $persisted->affectedSlots);
        self::assertSame(0, $persisted->insertedLedgerRows);
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_state'));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistBatchRollsBackWhenAnExceptionOccursInsideTransaction(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $this->store()->registerActorTypes([
            new ActorTypeDefinition('plugin', 'Plugin'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectRollback = $this->subject('SKU-BATCH-ROLLBACK');
        $this->insertInventoryState($subjectRollback, ['fs' => 5, 'res' => 0, 'sd' => 0]);

        /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
        $rows = [
            ['sku' => 'SKU-BATCH-ROLLBACK', 'state' => 'fs', 'qty' => 5, 'request_qty' => 2],
            ['sku' => 'SKU-BATCH-ROLLBACK', 'state' => 'res', 'qty' => 0, 'request_qty' => 2],
        ];

        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): string $subjectGetter */
        $subjectGetter = static function (array $row): string {
            /** @var array{sku: string, state: string, qty: int, request_qty: int} $row */
            return $row['sku'];
        };
        /** @var \Closure(array{sku: string, state: string, qty: int, request_qty: int}): list<array{0: array{state: non-empty-string}, 1: int}> $slotRowGetter */
        $slotRowGetter = static function (array $row): array {
            /** @var non-empty-string $state */
            $state = $row['state'];

            return [[['state' => $state], $row['qty']]];
        };
        /** @var \Closure(list<array{sku: string, state: string, qty: int, request_qty: int}>): int $quantityGetter */
        $quantityGetter = static function (array $rows): int {
            /** @var list<array{sku: string, state: string, qty: int, request_qty: int}> $rows */
            return $rows[0]['request_qty'];
        };
        $batch = QuantityStateBatch::fromRows(
            space: $slotSpace,
            rows: $rows,
            subjectGetter: $subjectGetter,
            slotRowGetter: $slotRowGetter,
            quantityGetter: $quantityGetter,
        );
        $batch = (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $slotSpace,
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
        );

        try {
            $this->store()->persistBatch(new PersistBatchMovement(
                movementBatch: $batch,
                subjectIdResolver: static fn (mixed $_): SubjectId => $subjectRollback,
                movementTypeOwnerKey: 'InFlow',
                movementTypeCode: 'RSRV',
                reference: new EntityReference('order', 'bad-ref'),
                actor: new ActorReference('plugin', 'sync-addon'),
            ));
            self::fail('Expected persistBatch() to throw for invalid unsigned reference id.');
        } catch (ConfigurationException) {
            self::assertFalse($this->pdo()->inTransaction());
        }

        self::assertSame([
            'fs'  => '5',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($this->store()->inventoryBalances($subjectRollback)));
        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistRollsBackWhenAnExceptionOccursInsideTransaction(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subjectRollback = $this->subject('SKU-ROLLBACK');
        $this->insertInventoryState($subjectRollback, ['fs' => 5, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            2,
            5,
            0,
        );

        try {
            $this->store()->persist(new PersistMovement(
                subjectId: $subjectRollback,
                movementTypeOwnerKey: 'InFlow',
                movementTypeCode: 'RSRV',
                movementResult: new MovementResult([$event], 0),
                reference: new EntityReference('order', 'not-a-number'),
            ));
            self::fail('Expected persist() to throw for invalid unsigned reference id.');
        } catch (ConfigurationException) {
            self::assertFalse($this->pdo()->inTransaction());
        }

        $balances = $this->store()->inventoryBalances($subjectRollback);
        self::assertSame([
            'fs'  => '5',
            'res' => '0',
            'sd'  => '0',
        ], $this->quantitiesByState($balances));

        self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
    }

    public function testPersistRollsBackWhenProjectionParticipantApplyFails(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);
        $this->createProjectionProbeTable();
        $this->store()->registerProjectionParticipant(new ProbeProjectionParticipant('invflux_projection_probe', true));

        $slotSpace = $definition->toSlotSpace();
        $subjectProjRollback = $this->subject('SKU-PROJECTION-ROLLBACK');
        $this->insertInventoryState($subjectProjRollback, ['fs' => 5, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            2,
            5,
            0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Projection apply failed.');

        try {
            $this->store()->persist(new PersistMovement(
                subjectId: $subjectProjRollback,
                movementTypeOwnerKey: 'InFlow',
                movementTypeCode: 'RSRV',
                movementResult: new MovementResult([$event], 0),
            ));
        } finally {
            self::assertSame([
                'fs'  => '5',
                'res' => '0',
                'sd'  => '0',
            ], $this->quantitiesByState($this->store()->inventoryBalances($subjectProjRollback)));
            self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_inventory_ledger'));
            self::assertSame(0, $this->fetchCount('SELECT COUNT(*) FROM invflux_projection_probe'));
        }
    }

    public function testRecordMetaEventRejectsUnregisteredActorType(): void
    {
        $this->expectException(UnknownActorTypeException::class);
        $this->expectExceptionMessage('Actor type');

        $this->store()->recordMetaEvent(new MetaEvent(
            eventType: 'test',
            actor: new ActorReference('channel_api', '1'),
        ));
    }

    public function testInventoryAndLedgerFiltersWorkAndRejectUnknownDimensions(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $slotSpace = $definition->toSlotSpace();
        $subject2 = $this->subject('SKU-2');
        $this->insertInventoryState($subject2, ['fs' => 5, 'res' => 0, 'sd' => 0]);

        $event = new MovementEvent(
            new MovementEdge($slotSpace->slot(['state' => 'fs']), $slotSpace->slot(['state' => 'res'])),
            2,
            5,
            0,
        );
        $this->store()->persist(new PersistMovement(
            subjectId: $subject2,
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            movementResult: new MovementResult([$event], 0),
        ));

        $balances = $this->store()->inventoryBalances($subject2, ['state' => 'res']);
        self::assertCount(1, $balances);
        self::assertSame('res', $balances[0]->dimensions['state']);

        $ledger = $this->store()->ledger($subject2, ['state' => 'res']);
        self::assertCount(1, $ledger);

        try {
            $this->store()->inventoryBalances($subject2, ['unknown' => 'x']);
            self::fail('Expected invalid dimension filter.');
        } catch (InvalidFilterException) {
        }

        try {
            $this->store()->ledger($subject2, ['unknown' => 'x']);
            self::fail('Expected invalid dimension filter.');
        } catch (InvalidFilterException) {
        }
    }

    public function testExecuteIdempotentReplaysInProgressStateForDuplicateClaim(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $createdAt = new \DateTimeImmutable('2026-01-02 03:04:05.123456');
        self::assertTrue($this->invokePrivate('tryInsertIdempotencyClaim', [
            new IdempotencyKey('orders', 'ABC-123'),
            $createdAt,
        ]));

        $closureCalls = 0;
        $execution = $this->store()->executeIdempotent(
            new IdempotencyKey('orders', 'ABC-123'),
            static function () use (&$closureCalls): IdempotentOutcome {
                ++$closureCalls;

                return IdempotentOutcome::terminal('should_not_run');
            },
        );

        self::assertSame(0, $closureCalls);
        self::assertInstanceOf(IdempotentExecution::class, $execution);
        self::assertTrue($execution->replayed);
        self::assertFalse($execution->terminal);
        self::assertSame('in_progress', $execution->outcomeCode);
        self::assertSame([], $execution->payload);
        self::assertNull($execution->completedAt);
    }

    public function testPrivateHelpersHandleIntegerConstraintsAndMissingRows(): void
    {
        self::assertSame([], $this->invokePrivate('decodeArray', [null]));
        self::assertSame([], $this->invokePrivate('decodeAssoc', ['["a"]']));
        self::assertSame(2, $this->invokePrivate('normalizeQuantity', [2.0]));
        self::assertSame(null, $this->invokePrivate('normalizeNullableQuantity', [null]));
        self::assertSame(null, $this->invokePrivate('normalizeNullableUnsignedInteger', [null]));

        try {
            $this->invokePrivate('normalizeQuantity', [1.5]);
            self::fail('Expected normalizeQuantity to reject decimals.');
        } catch (InvalidQuantityException) {
        }

        try {
            $this->invokePrivate('normalizeNullableUnsignedInteger', ['abc']);
            self::fail('Expected unsigned integer validation to fail.');
        } catch (ConfigurationException) {
        }

        // normalizeNullableBinary16Ref — the inventory-ledger ref validator (BINARY(16) per
        // arch-uuid-identity §5.5). Accepts null, raw 16 bytes, or 32-char hex.
        self::assertNull($this->invokePrivate('normalizeNullableBinary16Ref', [null]));
        $binary16 = str_repeat("\x42", 16);
        self::assertSame($binary16, $this->invokePrivate('normalizeNullableBinary16Ref', [$binary16]));
        self::assertSame($binary16, $this->invokePrivate('normalizeNullableBinary16Ref', [bin2hex($binary16)]));
        foreach (['bad-ref', 'too-short-hex', str_repeat('a', 31), '1234'] as $invalid) {
            try {
                $this->invokePrivate('normalizeNullableBinary16Ref', [$invalid]);
                self::fail(sprintf('Expected normalizeNullableBinary16Ref to reject %s.', var_export($invalid, true)));
            } catch (ConfigurationException) {
            }
        }

        try {
            $this->store()->setQuantityScale(-1);
            self::fail('Expected negative quantity scale validation to fail.');
        } catch (ConfigurationException) {
        }

        try {
            $this->invokePrivate('requireNonEmptyString', ['', 'x']);
            self::fail('Expected requireNonEmptyString to fail.');
        } catch (PersistenceException) {
        }

        $emptyStore = new MysqlInventoryStore(new PdoMysqlSession($this->pdo()));
        self::assertNull($this->invokePrivate('trySchema', [], $emptyStore));
    }

    // -------------------------------------------------------------------------
    // Subject identity layer tests
    // -------------------------------------------------------------------------

    public function testRegisterSubjectCreatesRootSimpleProduct(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $subjectId = $this->store()->registerSubject(kind: SubjectKind::Unit);

        self::assertGreaterThan(0, $subjectId->id);

        $row = $this->fetchAssoc("SELECT id, parent_id, kind, product_id, variant_id FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame($subjectId->id, (int) $row['id']);
        self::assertNull($row['parent_id']);
        self::assertSame('unit', $row['kind']);
        self::assertSame($subjectId->id, (int) $row['product_id']);
        self::assertSame($subjectId->id, (int) $row['variant_id']);
    }

    public function testRegisterSubjectCreatesRootAggregateProduct(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $productId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);

        $row = $this->fetchAssoc("SELECT kind, product_id, variant_id FROM invflux_subjects WHERE id = {$productId->id}");
        self::assertSame('aggregate', $row['kind']);
        self::assertSame($productId->id, (int) $row['product_id']);
        self::assertNull($row['variant_id']);
    }

    public function testRegisterSubjectDefaultsToUngoverned(): void
    {
        // Governance is opt-in: registering a subject establishes its *identity*, and says
        // nothing about whether InvFlux owns its stock. A catalogue sync can therefore register
        // everything it sees without silently enrolling it into inventory.
        $this->store()->bootstrap($this->baseDefinition());

        $subjectId = $this->store()->registerSubject(kind: SubjectKind::Unit);

        $row = $this->fetchAssoc("SELECT ivfx_governed FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame(0, (int) $row['ivfx_governed']);
    }

    public function testRegisterSubjectCanOptIntoGovernance(): void
    {
        // The inverse of the default: a subject InvFlux does govern participates in movements.
        // Grouped / external products stay ungoverned — registered for identity, holding no
        // stock of their own, and skipped by the store's movement paths.
        $this->store()->bootstrap($this->baseDefinition());

        $subjectId = $this->store()->registerSubject(kind: SubjectKind::Unit, stockManaged: true);

        $row = $this->fetchAssoc("SELECT ivfx_governed FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame(1, (int) $row['ivfx_governed']);
    }

    public function testRegisterSubjectCreatesVariationUnderAggregate(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $productId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $variationId = $this->store()->registerSubject($productId, SubjectKind::Unit);

        self::assertFalse($productId->equals($variationId));

        $row = $this->fetchAssoc("SELECT parent_id, kind, product_id, variant_id FROM invflux_subjects WHERE id = {$variationId->id}");
        self::assertSame($productId->id, (int) $row['parent_id']);
        self::assertSame('unit', $row['kind']);
        self::assertSame($productId->id, (int) $row['product_id']);
        self::assertSame($variationId->id, (int) $row['variant_id']);

        // Bulk kind lookup: one query maps each existing id to its kind; a missing id is omitted.
        self::assertSame(
            [$productId->id => SubjectKind::Aggregate, $variationId->id => SubjectKind::Unit],
            $this->store()->resolveSubjectKinds([$productId->id, $variationId->id, 999_999]),
        );
    }

    public function testRegisterSubjectCreatesBatchUnderVariation(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $productId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $variationId = $this->store()->registerSubject($productId, SubjectKind::Unit);
        $batchId = $this->store()->registerSubject($variationId, SubjectKind::Batch);

        $row = $this->fetchAssoc("SELECT kind, product_id, variant_id FROM invflux_subjects WHERE id = {$batchId->id}");
        self::assertSame('batch', $row['kind']);
        self::assertSame($productId->id, (int) $row['product_id']);
        self::assertSame($variationId->id, (int) $row['variant_id']);
    }

    public function testRegisterSubjectCreatesBatchUnderSimpleProduct(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $simpleId = $this->store()->registerSubject(kind: SubjectKind::Unit);
        $batchId = $this->store()->registerSubject($simpleId, SubjectKind::Batch);

        $row = $this->fetchAssoc("SELECT kind, product_id, variant_id FROM invflux_subjects WHERE id = {$batchId->id}");
        self::assertSame('batch', $row['kind']);
        self::assertSame($simpleId->id, (int) $row['product_id']);
        self::assertSame($simpleId->id, (int) $row['variant_id']);
    }

    public function testRegisterSubjectCreatesRootSimpleKit(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $kitId = $this->store()->registerSubject(kind: SubjectKind::Kit);

        $row = $this->fetchAssoc("SELECT parent_id, kind, product_id, variant_id FROM invflux_subjects WHERE id = {$kitId->id}");
        self::assertNull($row['parent_id']);
        self::assertSame('kit', $row['kind']);
        // Kit shares Unit's self-referential FK shape (product_id = variant_id = self).
        self::assertSame($kitId->id, (int) $row['product_id']);
        self::assertSame($kitId->id, (int) $row['variant_id']);
    }

    public function testRegisterSubjectCreatesKitVariationUnderAggregate(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $productId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $kitVariationId = $this->store()->registerSubject($productId, SubjectKind::Kit);

        $row = $this->fetchAssoc("SELECT parent_id, kind, product_id, variant_id FROM invflux_subjects WHERE id = {$kitVariationId->id}");
        self::assertSame($productId->id, (int) $row['parent_id']);
        self::assertSame('kit', $row['kind']);
        self::assertSame($productId->id, (int) $row['product_id']);
        self::assertSame($kitVariationId->id, (int) $row['variant_id']);
    }

    public function testRegisterSubjectRejectsBatchUnderKit(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        // A kit holds no slots and is never a parent.
        $kitId = $this->store()->registerSubject(kind: SubjectKind::Kit);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be a child of a kit');

        $this->store()->registerSubject($kitId, SubjectKind::Batch);
    }

    public function testRegisterSubjectRejectsUnitChildOfUnit(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $parentId = $this->store()->registerSubject(kind: SubjectKind::Unit);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be a child of a unit');

        $this->store()->registerSubject($parentId, SubjectKind::Unit);
    }

    public function testRegisterSubjectRejectsAggregateWithParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $parentId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be a child of a aggregate');

        $this->store()->registerSubject($parentId, SubjectKind::Aggregate);
    }

    public function testRegisterSubjectRejectsBatchWithoutParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must have a parent');

        $this->store()->registerSubject(kind: SubjectKind::Batch);
    }

    public function testRegisterSubjectRejectsUnknownParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('not found');

        $this->store()->registerSubject(new SubjectId(999999));
    }

    public function testSubjectAggregationQueriesByProductAndVariant(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $productId = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $variation1 = $this->store()->registerSubject($productId, SubjectKind::Unit);
        $variation2 = $this->store()->registerSubject($productId, SubjectKind::Unit);
        $batch1 = $this->store()->registerSubject($variation1, SubjectKind::Batch);
        $batch2 = $this->store()->registerSubject($variation1, SubjectKind::Batch);
        $otherSimple = $this->store()->registerSubject(kind: SubjectKind::Unit);

        $pId = $productId->id;

        // All units for this product (the two variations; aggregate itself excluded)
        $unitIds = $this->fetchIntColumn("SELECT id FROM invflux_subjects WHERE product_id = $pId AND kind = 'unit' ORDER BY id");
        self::assertSame([$variation1->id, $variation2->id], $unitIds);

        // All batches for this product
        $batchIds = $this->fetchIntColumn("SELECT id FROM invflux_subjects WHERE product_id = $pId AND kind = 'batch' ORDER BY id");
        self::assertSame([$batch1->id, $batch2->id], $batchIds);

        // Batches scoped to variation1 only
        $v1Id = $variation1->id;
        $v1BatchIds = $this->fetchIntColumn("SELECT id FROM invflux_subjects WHERE variant_id = $v1Id AND kind = 'batch' ORDER BY id");
        self::assertSame([$batch1->id, $batch2->id], $v1BatchIds);

        // variation2 has no batches
        $v2Id = $variation2->id;
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_subjects WHERE variant_id = $v2Id AND kind = 'batch'"));

        // Other simple product not contaminated by this product's product_id
        self::assertSame(0, $this->fetchCount("SELECT COUNT(*) FROM invflux_subjects WHERE product_id = $pId AND id = {$otherSimple->id}"));
    }

    public function testRegisterSystemAndIdentifierTypeAndResolveIdentifier(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->store()->registerSystem('test_channel', 'Test Channel');
        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');

        $subjectId = $this->store()->registerSubject();

        $this->store()->claimIdentifier(
            subjectId: $subjectId,
            typeCode: 'sku',
            systemSlug: 'test_channel',
            value: 'WIDGET-001',
            isPrimary: true,
        );

        $resolved = $this->store()->resolveIdentifier('sku', 'test_channel', 'WIDGET-001');

        self::assertInstanceOf(SubjectId::class, $resolved);
        self::assertSame($subjectId->id, $resolved->id);
    }

    public function testResolveIdentifierReturnsNullWhenNotFound(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('test_channel', 'Test Channel');
        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');

        $result = $this->store()->resolveIdentifier('sku', 'test_channel', 'NONEXISTENT');

        self::assertNull($result);
    }

    public function testResolveIdentifierRespectsValidToExpiry(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('test_channel', 'Test Channel');
        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');

        $subjectId = $this->store()->registerSubject();

        $past = new \DateTimeImmutable('2020-01-01 00:00:00');
        $expired = new \DateTimeImmutable('2021-01-01 00:00:00');

        // Claim with a past validFrom, then immediately expire at the historical expiry time
        $this->store()->claimIdentifier(
            subjectId: $subjectId,
            typeCode: 'sku',
            systemSlug: 'test_channel',
            value: 'OLD-SKU',
            validFrom: $past,
        );
        $this->store()->expireIdentifier('sku', 'test_channel', 'OLD-SKU', validTo: $expired);

        // Querying at current time — identifier is expired
        $result = $this->store()->resolveIdentifier('sku', 'test_channel', 'OLD-SKU');

        self::assertNull($result);

        // Querying at a time within the validity window
        $duringValidity = new \DateTimeImmutable('2020-06-01 00:00:00');
        $resolved = $this->store()->resolveIdentifier('sku', 'test_channel', 'OLD-SKU', asOf: $duringValidity);

        self::assertNotNull($resolved);
        self::assertSame($subjectId->id, $resolved->id);
    }

    public function testClaimIdentifierRejectsUnknownTypeCode(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('test_channel', 'Test Channel');

        $subjectId = $this->store()->registerSubject();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown identifier type');

        $this->store()->claimIdentifier($subjectId, 'no_such_type', 'test_channel', 'val');
    }

    // -------------------------------------------------------------------------
    // Identity migration (migrateActiveIdentifiers + resolveExpiredIdentifierSubject)
    // -------------------------------------------------------------------------

    public function testMigrateActiveIdentifiersMovesEveryActiveAssignmentAndLeavesHistoryBehind(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');
        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');

        $from = $this->store()->registerSubject(kind: SubjectKind::Unit);
        $to = $this->store()->registerSubject(kind: SubjectKind::NonStocked);

        // An already-expired assignment is history, not identity — it must stay behind.
        $this->store()->claimIdentifier($from, 'sku', 'woo', 'SKU-OLD');
        $this->store()->expireIdentifier('sku', 'woo', 'SKU-OLD');
        $this->store()->claimIdentifier($from, 'woo_post_id', 'woo', '501', isPrimary: true);
        $this->store()->claimIdentifier($from, 'sku', 'woo', 'SKU-501');

        $moved = $this->store()->migrateActiveIdentifiers($from, $to);

        self::assertSame(2, $moved);
        self::assertEquals($to, $this->store()->resolveIdentifier('woo_post_id', 'woo', '501'));
        self::assertEquals($to, $this->store()->resolveIdentifier('sku', 'woo', 'SKU-501'));
        self::assertSame([], $this->store()->listSubjectIdentifiers($from), 'nothing stays active on the source');

        // The primary flag travels with the assignment.
        $primary = array_filter(
            $this->store()->listSubjectIdentifiers($to),
            static fn ($assignment) => $assignment->isPrimary,
        );
        self::assertCount(1, $primary);
        self::assertSame('501', array_values($primary)[0]->value);
    }

    public function testMigrateActiveIdentifiersReturnsZeroWhenTheSourceHoldsNothingActive(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');

        $from = $this->store()->registerSubject();
        $to = $this->store()->registerSubject();
        $this->store()->claimIdentifier($from, 'sku', 'woo', 'SKU-GONE');
        $this->store()->expireIdentifier('sku', 'woo', 'SKU-GONE');

        self::assertSame(0, $this->store()->migrateActiveIdentifiers($from, $to));
        self::assertNull($this->store()->resolveIdentifier('sku', 'woo', 'SKU-GONE'));
    }

    public function testResolveExpiredIdentifierSubjectPicksTheMostRecentPriorOfTheRequestedKind(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $unit = $this->store()->registerSubject(kind: SubjectKind::Unit);
        $aggregate = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $current = $this->store()->registerSubject(kind: SubjectKind::NonStocked);

        // The unit held the value first, the aggregate after it; a third subject holds it NOW.
        // Later holders use reclaimExpiredIdentifier — the default policy is not reusable-after-
        // expiry, and reclaim is the sanctioned bypass (the same one a kind migration rides on).
        $this->store()->claimIdentifier($unit, 'woo_post_id', 'woo', '77', validFrom: new \DateTimeImmutable('2024-01-01'));
        $this->store()->expireIdentifier('woo_post_id', 'woo', '77', validTo: new \DateTimeImmutable('2024-06-01'));
        $this->store()->reclaimExpiredIdentifier($aggregate, 'woo_post_id', 'woo', '77');
        $this->store()->expireIdentifier('woo_post_id', 'woo', '77', validTo: new \DateTimeImmutable('2025-01-01'));
        $this->store()->reclaimExpiredIdentifier($current, 'woo_post_id', 'woo', '77');

        self::assertEquals($unit, $this->store()->resolveExpiredIdentifierSubject('woo_post_id', 'woo', '77', SubjectKind::Unit));
        self::assertEquals($aggregate, $this->store()->resolveExpiredIdentifierSubject('woo_post_id', 'woo', '77', SubjectKind::Aggregate));
        // No kind filter → simply the most recent prior. The ACTIVE holder is never the answer.
        self::assertEquals($aggregate, $this->store()->resolveExpiredIdentifierSubject('woo_post_id', 'woo', '77'));
        self::assertNull($this->store()->resolveExpiredIdentifierSubject('woo_post_id', 'woo', '77', SubjectKind::Batch));
        self::assertNull($this->store()->resolveExpiredIdentifierSubject('woo_post_id', 'woo', 'never-assigned'));
    }

    public function testRetypeSubjectRewritesTheSelfReferentialFkShape(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        // Root NonStocked: its own product AND its own variant.
        $subjectId = $this->store()->registerSubject(kind: SubjectKind::NonStocked);
        $before = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame($subjectId->id, (int) $before['product_id']);
        self::assertSame($subjectId->id, (int) $before['variant_id']);

        // → Aggregate: still its own product, but an Aggregate has no variant identity.
        $this->store()->retypeSubject($subjectId, SubjectKind::Aggregate);

        self::assertSame(SubjectKind::Aggregate, $this->store()->resolveSubjectKind($subjectId));
        $after = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame($subjectId->id, (int) $after['product_id']);
        self::assertNull($after['variant_id'], 'an Aggregate is not its own variant');

        // → Unit: variant identity comes back.
        $this->store()->retypeSubject($subjectId, SubjectKind::Unit);
        $unit = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$subjectId->id}");
        self::assertSame($subjectId->id, (int) $unit['variant_id']);
    }

    public function testRetypeSubjectIsIdempotent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $subjectId = $this->store()->registerSubject(kind: SubjectKind::NonStocked);

        $this->store()->retypeSubject($subjectId, SubjectKind::NonStocked);

        self::assertSame(SubjectKind::NonStocked, $this->store()->resolveSubjectKind($subjectId));
    }

    public function testRetypeSubjectRefusesASubjectThatOwnsSlots(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $subjectId = $this->store()->registerSubject(kind: SubjectKind::Unit);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('owns slots');

        $this->store()->retypeSubject($subjectId, SubjectKind::NonStocked);
    }

    public function testRetypeSubjectRefusesASubjectWithChildren(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $parent = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $this->store()->registerSubject($parent, SubjectKind::Unit);

        // Retyping the parent would leave a Unit parented by a Unit — a tree no registration
        // path could have built.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has children');

        $this->store()->retypeSubject($parent, SubjectKind::Unit);
    }

    public function testRetypeSubjectRefusesAnIllegalKindUnderTheParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $parent = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $child = $this->store()->registerSubject($parent, SubjectKind::NonStocked);

        // An Aggregate may not hang under an Aggregate.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cannot be a child of');

        $this->store()->retypeSubject($child, SubjectKind::Aggregate);
    }

    /**
     * Movement eligibility is two questions, not one. Governance was the only gate, which is how a
     * *governed* NonStocked subject went on accepting movements — it has no slot state for one to
     * land on, whoever owns its count.
     */
    public function testAGovernedSlotlessSubjectIsSkippedByAMovementJustLikeAnUngovernedOne(): void
    {
        $definition = $this->baseDefinition();
        $this->store()->bootstrap($definition);
        $this->store()->registerMovementTypes('InFlow', [
            new MovementTypeDefinition('RSRV', 'Reservation'),
        ]);

        $unit = $this->store()->registerSubject(kind: SubjectKind::Unit, stockManaged: true);
        $nonStocked = $this->store()->registerSubject(kind: SubjectKind::NonStocked, stockManaged: true);
        $ungoverned = $this->store()->registerSubject(kind: SubjectKind::Unit);
        $this->insertInventoryState($unit, ['fs' => 10, 'res' => 0, 'sd' => 0]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: Flow::define('reserve', static fn (Flow $flow) => $flow->move('fs', 'res')),
            subjectIds: [$unit, $nonStocked, $ungoverned],
            slotFilters: ['state' => 'fs'],
            quantitiesBySubjectId: [
                $unit->id       => 2,
                $nonStocked->id => 3,
                $ungoverned->id => 4,
            ],
        ));

        // The countable line posts; the other two are skipped, not raised — an order that mixes a
        // physical product with a service is an ordinary order.
        self::assertTrue($persisted->ok);
        self::assertSame(1, $persisted->affectedSubjects);
        self::assertSame([$nonStocked->id, $ungoverned->id], $persisted->skippedUnmanagedSubjectIds);
        self::assertSame(
            ['fs' => '8', 'res' => '2', 'sd' => '0'],
            $this->quantitiesByState($this->store()->inventoryBalances($unit)),
        );
    }

    public function testResolveSubjectGovernanceMapsOnlyKnownSubjects(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $governed = $this->store()->registerSubject(stockManaged: true);
        $ungoverned = $this->store()->registerSubject();

        $map = $this->store()->resolveSubjectGovernance([$governed->id, $ungoverned->id, 999_999]);

        self::assertSame([$governed->id => true, $ungoverned->id => false], $map);
        self::assertSame([], $this->store()->resolveSubjectGovernance([]));
    }

    // -------------------------------------------------------------------------
    // Bulk SubjectRegistrar endpoints (resolveIdentifiers + resolveOrCreateManyByIdentifier)
    // -------------------------------------------------------------------------

    public function testClaimIdentifiersAttachesEveryValueInOneCall(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $a = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $b = $this->store()->registerSubject(kind: SubjectKind::Aggregate);

        $this->store()->claimIdentifiers('woo_post_id', 'woo', [
            new IdentifierClaimSpec($a, '201', isPrimary: true),
            new IdentifierClaimSpec($b, '202'),
        ]);

        $resolved = $this->store()->resolveIdentifiers('woo_post_id', 'woo', ['201', '202']);
        self::assertSame($a->id, $resolved['201']->id);
        self::assertSame($b->id, $resolved['202']->id);
    }

    public function testClaimIdentifiersIsIdempotentForAValueTheSubjectAlreadyHolds(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $subject = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $this->store()->claimIdentifier($subject, 'woo_post_id', 'woo', '301');

        // Re-claiming what it already owns must not add a second active row.
        $this->store()->claimIdentifiers('woo_post_id', 'woo', [
            new IdentifierClaimSpec($subject, '301'),
        ]);

        self::assertCount(1, $this->store()->listSubjectIdentifiers($subject));
    }

    public function testClaimIdentifiersRollsTheWholeBatchBackWhenOneValueIsHeldElsewhere(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        // The guard only fires where the policy asks for it; unconfigured, a duplicate is caught by
        // the table's unique key instead. Same for one claim or a batch — that equivalence is the
        // point of the bulk twin, so the policy is configured here to exercise the domain path.
        $this->store()->configureIdentifierAssignment(
            'woo',
            'woo_post_id',
            new IdentifierAssignmentPolicy(uniqueActiveValue: true),
        );

        $owner = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $claimant = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $this->store()->claimIdentifier($owner, 'woo_post_id', 'woo', '401');

        // All-or-nothing (arch-bulk-ops §7): the good value in the batch must not survive either.
        try {
            $this->store()->claimIdentifiers('woo_post_id', 'woo', [
                new IdentifierClaimSpec($claimant, '402'),
                new IdentifierClaimSpec($claimant, '401'),
            ]);
            self::fail('Expected the contested value to abort the batch.');
        } catch (IdentifierAlreadyClaimedException) {
            // expected
        }

        self::assertSame([], $this->store()->resolveIdentifiers('woo_post_id', 'woo', ['402']));
        self::assertCount(0, $this->store()->listSubjectIdentifiers($claimant));
    }

    public function testClaimIdentifiersRefusesOneValueForTwoSubjectsInTheSameCall(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $a = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $b = $this->store()->registerSubject(kind: SubjectKind::Aggregate);

        // Both cannot win, and picking silently would bury the caller's data defect.
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->claimIdentifiers('woo_post_id', 'woo', [
            new IdentifierClaimSpec($a, '501'),
            new IdentifierClaimSpec($b, '501'),
        ]);
    }

    public function testClaimIdentifiersAcceptsAnEmptyBatch(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $this->store()->claimIdentifiers('woo_post_id', 'woo', []);

        self::assertSame([], $this->store()->resolveIdentifiers('woo_post_id', 'woo', ['nothing']));
    }

    public function testBulkResolveIdentifiersReturnsMapKeyedByInputValue(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $subjectA = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $subjectB = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $this->store()->claimIdentifier($subjectA, 'woo_post_id', 'woo', '101', isPrimary: true);
        $this->store()->claimIdentifier($subjectB, 'woo_post_id', 'woo', '102', isPrimary: true);

        $resolved = $this->store()->resolveIdentifiers('woo_post_id', 'woo', ['101', '102', '999']);

        // Missing input ('999') is omitted, not nulled; map is keyed by the input value.
        self::assertCount(2, $resolved);
        self::assertArrayHasKey('101', $resolved);
        self::assertArrayHasKey('102', $resolved);
        self::assertArrayNotHasKey('999', $resolved);
        self::assertSame($subjectA->id, $resolved['101']->id);
        self::assertSame($subjectB->id, $resolved['102']->id);
    }

    public function testBulkResolveIdentifiersReturnsEmptyArrayForEmptyInput(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        self::assertSame([], $this->store()->resolveIdentifiers('woo_post_id', 'woo', []));
    }

    public function testListSubjectIdentifiersForManyGroupsBySubject(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');
        $this->store()->registerIdentifierType('woo_variation_post_id', 'WooCommerce Variation Post ID', 'internal');

        $productA = $this->store()->registerSubject(kind: SubjectKind::Aggregate);
        $variationB = $this->store()->registerSubject(kind: SubjectKind::Unit);
        $this->store()->claimIdentifier($productA, 'woo_post_id', 'woo', '101', isPrimary: true);
        $this->store()->claimIdentifier($variationB, 'woo_variation_post_id', 'woo', '202', isPrimary: true);

        $grouped = $this->store()->listSubjectIdentifiersForMany(
            [$productA, $variationB, new SubjectId(999999)],
            'woo',
        );

        // Keyed by subject id; the unknown subject is omitted (not keyed to an empty list).
        self::assertArrayHasKey($productA->id, $grouped);
        self::assertArrayHasKey($variationB->id, $grouped);
        self::assertArrayNotHasKey(999999, $grouped);

        self::assertCount(1, $grouped[$productA->id]);
        self::assertSame('woo_post_id', $grouped[$productA->id][0]->typeCode);
        self::assertSame('101', $grouped[$productA->id][0]->value);

        self::assertSame('woo_variation_post_id', $grouped[$variationB->id][0]->typeCode);
        self::assertSame('202', $grouped[$variationB->id][0]->value);
    }

    public function testListSubjectIdentifiersForManyReturnsEmptyArrayForEmptyInput(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        self::assertSame([], $this->store()->listSubjectIdentifiersForMany([]));
    }

    public function testResolveOrCreateManyByIdentifierCreatesNewSubjectsForMissingValues(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $claims = [
            new SubjectIdentifierClaim(value: '201', subjectKind: SubjectKind::Aggregate, isPrimary: true),
            new SubjectIdentifierClaim(value: '202', subjectKind: SubjectKind::Aggregate, isPrimary: true),
            new SubjectIdentifierClaim(value: '203', subjectKind: SubjectKind::Unit, isPrimary: true),
        ];

        $result = $this->store()->resolveOrCreateManyByIdentifier('woo_post_id', 'woo', $claims);

        self::assertCount(3, $result);
        self::assertArrayHasKey('201', $result);
        self::assertArrayHasKey('202', $result);
        self::assertArrayHasKey('203', $result);
        self::assertSame($result['201']->id, $this->store()->resolveIdentifier('woo_post_id', 'woo', '201')?->id);
        self::assertSame($result['202']->id, $this->store()->resolveIdentifier('woo_post_id', 'woo', '202')?->id);
        self::assertSame($result['203']->id, $this->store()->resolveIdentifier('woo_post_id', 'woo', '203')?->id);

        // Subject kinds were honored.
        self::assertSame(SubjectKind::Aggregate, $this->store()->resolveSubjectKind($result['201']));
        self::assertSame(SubjectKind::Unit, $this->store()->resolveSubjectKind($result['203']));

        // Self-FK fixup: a root Unit subject has product_id = variant_id = self.
        $unitRow = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$result['203']->id}");
        self::assertSame((string) $result['203']->id, (string) $unitRow['product_id']);
        self::assertSame((string) $result['203']->id, (string) $unitRow['variant_id']);

        // A root Aggregate subject has product_id = self, variant_id = null.
        $aggRow = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$result['201']->id}");
        self::assertSame((string) $result['201']->id, (string) $aggRow['product_id']);
        self::assertNull($aggRow['variant_id']);
    }

    public function testResolveOrCreateManyByIdentifierCreatesRootKitWithSelfFk(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $claims = [
            new SubjectIdentifierClaim(value: '401', subjectKind: SubjectKind::Kit, isPrimary: true),
        ];

        $result = $this->store()->resolveOrCreateManyByIdentifier('woo_post_id', 'woo', $claims);

        self::assertArrayHasKey('401', $result);
        self::assertSame(SubjectKind::Kit, $this->store()->resolveSubjectKind($result['401']));

        // Bulk self-FK fixup: Kit shares Unit's shape, so a root Kit gets product_id = variant_id = self.
        $row = $this->fetchAssoc("SELECT product_id, variant_id FROM invflux_subjects WHERE id = {$result['401']->id}");
        self::assertSame((string) $result['401']->id, (string) $row['product_id']);
        self::assertSame((string) $result['401']->id, (string) $row['variant_id']);
    }

    public function testResolveOrCreateManyByIdentifierIsIdempotent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');

        $claims = [
            new SubjectIdentifierClaim(value: '301', subjectKind: SubjectKind::Aggregate, isPrimary: true),
            new SubjectIdentifierClaim(value: '302', subjectKind: SubjectKind::Aggregate, isPrimary: true),
        ];

        $first = $this->store()->resolveOrCreateManyByIdentifier('woo_post_id', 'woo', $claims);
        $second = $this->store()->resolveOrCreateManyByIdentifier('woo_post_id', 'woo', $claims);

        // Second call returns the same subject IDs; no new rows inserted.
        self::assertSame($first['301']->id, $second['301']->id);
        self::assertSame($first['302']->id, $second['302']->id);
        $count = (int) $this->fetchAssoc(
            "SELECT COUNT(*) AS c FROM invflux_subject_identifiers WHERE value IN ('301', '302')",
        )['c'];
        self::assertSame(2, $count);
    }

    public function testResolveOrCreateManyByIdentifierLinksVariationsToProductParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('woo_post_id', 'WooCommerce Post ID', 'internal');
        $this->store()->registerIdentifierType('woo_variation_post_id', 'WooCommerce Variation Post ID', 'internal');

        // Step 1: create the variable product as an Aggregate subject.
        $productResult = $this->store()->resolveOrCreateManyByIdentifier('woo_post_id', 'woo', [
            new SubjectIdentifierClaim(value: '401', subjectKind: SubjectKind::Aggregate, isPrimary: true),
        ]);
        $parent = $productResult['401'];

        // Step 2: create three variations linked to that parent.
        $variationResult = $this->store()->resolveOrCreateManyByIdentifier('woo_variation_post_id', 'woo', [
            new SubjectIdentifierClaim(value: '411', subjectKind: SubjectKind::Unit, parentSubjectId: $parent, isPrimary: true),
            new SubjectIdentifierClaim(value: '412', subjectKind: SubjectKind::Unit, parentSubjectId: $parent, isPrimary: true),
            new SubjectIdentifierClaim(value: '413', subjectKind: SubjectKind::Unit, parentSubjectId: $parent, isPrimary: true),
        ]);

        self::assertCount(3, $variationResult);
        foreach (['411', '412', '413'] as $variationValue) {
            $variationId = $variationResult[$variationValue]->id;
            $row = $this->fetchAssoc("SELECT parent_id, product_id, variant_id, kind FROM invflux_subjects WHERE id = $variationId");
            self::assertSame((string) $parent->id, (string) $row['parent_id'], 'variation parent_id should point to product subject');
            self::assertSame((string) $parent->id, (string) $row['product_id'], 'variation product_id should inherit from parent');
            self::assertSame((string) $variationId, (string) $row['variant_id'], 'variation variant_id should be self');
            self::assertSame('unit', $row['kind']);
        }
    }

    public function testResolveOrCreateManyByIdentifierCreatesBatchSubjectsUnderUnitParent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerSystem('woo', 'WooCommerce');
        $this->store()->registerIdentifierType('batch_lot', 'Batch Lot', 'internal');

        // Existing simple-product Unit subject (parent for the batches).
        $unitParent = $this->store()->registerSubject(kind: SubjectKind::Unit);

        $batchResult = $this->store()->resolveOrCreateManyByIdentifier('batch_lot', 'woo', [
            new SubjectIdentifierClaim(value: 'LOT-A', subjectKind: SubjectKind::Batch, parentSubjectId: $unitParent),
            new SubjectIdentifierClaim(value: 'LOT-B', subjectKind: SubjectKind::Batch, parentSubjectId: $unitParent),
        ]);

        self::assertCount(2, $batchResult);
        foreach (['LOT-A', 'LOT-B'] as $value) {
            $batchId = $batchResult[$value]->id;
            $row = $this->fetchAssoc("SELECT parent_id, product_id, variant_id, kind FROM invflux_subjects WHERE id = $batchId");
            self::assertSame('batch', $row['kind']);
            self::assertSame((string) $unitParent->id, (string) $row['parent_id']);
            // Batch inherits product_id and variant_id from its parent Unit at INSERT
            // time; no self-FK fixup is required for the 'parent_inherited' bucket.
            self::assertSame((string) $unitParent->id, (string) $row['product_id']);
            self::assertSame((string) $unitParent->id, (string) $row['variant_id']);
        }
    }

    public function testClaimIdentifierRejectsUnknownSystemSlug(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerIdentifierType('sku', 'SKU');

        $subjectId = $this->store()->registerSubject();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown system slug');

        $this->store()->claimIdentifier($subjectId, 'sku', 'no_such_system', 'val');
    }

    public function testRegisterSystemIsIdempotent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->store()->registerSystem('woo', 'WooCommerce', 'invflux-for-woocommerce');
        $this->store()->registerSystem('woo', 'WooCommerce Updated', 'invflux-for-woocommerce');

        $row = $this->fetchAssoc("SELECT name FROM invflux_systems WHERE slug = 'woo'");
        self::assertSame('WooCommerce Updated', $row['name']);

        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_systems WHERE slug = 'woo'"));
    }

    public function testRegisterIdentifierTypeIsIdempotent(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->store()->registerIdentifierType('sku', 'SKU', 'internal');
        $this->store()->registerIdentifierType('sku', 'Stock-Keeping Unit', 'product');

        $row = $this->fetchAssoc("SELECT name, category FROM invflux_identifier_types WHERE code = 'sku'");
        self::assertSame('Stock-Keeping Unit', $row['name']);
        self::assertSame('product', $row['category']);

        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_identifier_types WHERE code = 'sku'"));
    }

    public function testBootstrapSeedsStandardIdentifierTypes(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        // Portable identifier types only: a SKU or an EAN means the same thing on any host.
        $standardCodes = ['sku', 'ean13', 'ean8', 'upc_a', 'upc_e', 'isbn13', 'isbn10', 'asin'];

        foreach ($standardCodes as $code) {
            self::assertSame(
                1,
                $this->fetchCount("SELECT COUNT(*) FROM invflux_identifier_types WHERE code = '$code'"),
                "Expected standard identifier type '$code' to be seeded.",
            );
        }

        // The boundary, pinned rather than merely left out of the list above: this package backs
        // both the WooCommerce and PrestaShop adapters, so a host-specific type is the adapter's
        // to register (WooIdentifierTypeRegistrar does it for Woo). Seeding one here would put
        // WooCommerce vocabulary into every PrestaShop install.
        foreach (['woo_post_id', 'woo_variation_post_id'] as $hostSpecific) {
            self::assertSame(
                0,
                $this->fetchCount("SELECT COUNT(*) FROM invflux_identifier_types WHERE code = '$hostSpecific'"),
                "Storage must not seed the host-specific identifier type '$hostSpecific'.",
            );
        }
    }

    public function testBootstrapSeedsInvfluxSystem(): void
    {
        // The 'invflux' platform-internal system is seeded by
        // MysqlDomainStore::seedReferenceTables().
        $this->store()->bootstrap($this->baseDefinition());

        $row = $this->fetchAssoc("SELECT name FROM invflux_systems WHERE slug = 'invflux'");
        self::assertSame('InvFlux', $row['name']);
    }

    public function testSubjectIdentifierForeignKeysTargetIdentityRegistries(): void
    {
        // type_id / system_id now carry DB-level FK constraints into the
        // identity-registry tables, alongside the application-side validation.
        $this->store()->bootstrap($this->baseDefinition());

        self::assertSame(
            1,
            $this->fetchCount(<<<'SQL'
                SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'invflux_subject_identifiers'
                  AND COLUMN_NAME = 'type_id'
                  AND REFERENCED_TABLE_NAME = 'invflux_identifier_types'
                SQL),
            'subject_identifiers.type_id should FK into invflux_identifier_types.',
        );
        self::assertSame(
            1,
            $this->fetchCount(<<<'SQL'
                SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'invflux_subject_identifiers'
                  AND COLUMN_NAME = 'system_id'
                  AND REFERENCED_TABLE_NAME = 'invflux_systems'
                SQL),
            'subject_identifiers.system_id should FK into invflux_systems.',
        );
    }

    // -------------------------------------------------------------------------
    // Layer support
    // -------------------------------------------------------------------------

    public function testBootstrapLayeredCreatesLayerRowsAndAssignsLayerIdToSlots(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());

        $layerRows = $this->pdo()->query(
            'SELECT slug FROM invflux_layers ORDER BY slug',
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['commercial', 'physical'], $layerRows);

        $nullLayerCount = $this->fetchCount('SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id IS NULL');
        self::assertSame(0, $nullLayerCount);

        $commercialLayerId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'commercial'");
        $physicalLayerId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'physical'");

        self::assertSame(3, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $commercialLayerId"));
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $physicalLayerId"));

        $commercialSlotKeys = $this->pdo()->query(
            "SELECT slot_key FROM invflux_slotspace WHERE layer_id = $commercialLayerId ORDER BY slot_key",
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['fs', 'res', 'sd'], $commercialSlotKeys);

        $physicalSlotKeys = $this->pdo()->query(
            "SELECT slot_key FROM invflux_slotspace WHERE layer_id = $physicalLayerId ORDER BY slot_key",
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['wh'], $physicalSlotKeys);
    }

    public function testLayerTotalsUnchangedAfterIntraLayerFlow(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('RSRV', 'Reserve')]);

        $subject = $this->subject('SKU-INTRA');
        $this->insertInventoryStateBySlotKey($subject, ['fs' => 5, 'wh' => 5]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'reserve',
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 3],
            layerName: 'commercial',
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(5, $this->fetchLayerQuantity($subject, 'commercial'));
        self::assertSame(5, $this->fetchLayerQuantity($subject, 'physical'));
    }

    public function testSingleSubjectReserveViaFastPathMovesFsToResAndCreatesResRow(): void
    {
        // A single-subject, no-hierarchy reserve takes the temp-table-free
        // fast path (persistSingleSubjectDeltaRowsFast). Only fs is seeded;
        // the res slot row doesn't exist yet, so this also exercises the
        // first-touch INSERT-IGNORE-then-relock branch for the res slot.
        $this->store()->bootstrap($this->layeredDefinition());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('RSRV', 'Reserve')]);

        $subject = $this->subject('SKU-FASTPATH');
        $this->insertInventoryStateBySlotKey($subject, ['fs' => 5]); // res deliberately absent

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'reserve',
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 3],
            layerName: 'commercial',
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(2, $this->fetchSlotQuantity($subject, 'fs'));
        self::assertSame(3, $this->fetchSlotQuantity($subject, 'res'));
        // One ledger row — a reserve is logged as a single fs→res transfer
        // (from_slot_id/to_slot_id), even though it touches two state slots.
        self::assertSame(1, $this->fetchCount(sprintf(
            'SELECT COUNT(*) FROM invflux_inventory_ledger WHERE subject_id = %d',
            $subject->id,
        )));
        // Commercial layer total unchanged (intra-layer move): 5 → 5.
        self::assertSame(5, $this->fetchLayerQuantity($subject, 'commercial'));
    }

    public function testExecuteBatchBoundaryFlowFromStoragePersistsDeltasToBothLayersInOneTransaction(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('DISP', 'Dispatch')]);

        $subject = $this->subject('SKU-BD');
        $this->insertInventoryStateBySlotKey($subject, ['sd' => 7, 'wh' => 7]);

        $persisted = $this->store()->executeBatchBoundaryFlowFromStorage(new StorageBoundaryFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'DISP',
            flow: BoundaryFlow::define(['commercial' => 'dispatch', 'physical' => 'dispatch']),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 7],
        ));

        self::assertTrue($persisted->ok);
        self::assertSame(1, $persisted->affectedSubjects);
        self::assertSame(2, $persisted->affectedSlots);
        self::assertSame(2, $persisted->insertedLedgerRows);

        self::assertSame(0, $this->fetchSlotQuantity($subject, 'sd'));
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'wh'));

        self::assertSame(0, $this->fetchLayerQuantity($subject, 'commercial'));
        self::assertSame(0, $this->fetchLayerQuantity($subject, 'physical'));

        // Each cleared slot is a source (delta < 0), so the ledger row records the
        // slot's pre-movement balance (7) as `initial_from`, and `initial_to` is
        // null — a regression guard against the batch-boundary path dropping both as NULL.
        $ledgerRow = $this->fetchAssoc(sprintf(
            'SELECT initial_from, initial_to
             FROM invflux_inventory_ledger WHERE subject_id = %d ORDER BY id DESC LIMIT 1',
            $subject->id,
        ));
        self::assertSame('7', (string) $ledgerRow['initial_from'], 'source pre-balance recorded as initial_from');
        self::assertNull($ledgerRow['initial_to'], 'a destroy has no destination slot');
    }

    public function testHealthCheckQueryReturnsNoLayerDivergenceAfterBoundaryFlow(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('DISP', 'Dispatch')]);

        $subject = $this->subject('SKU-HC');
        $this->insertInventoryStateBySlotKey($subject, ['sd' => 4, 'wh' => 4]);

        $this->store()->executeBatchBoundaryFlowFromStorage(new StorageBoundaryFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'DISP',
            flow: BoundaryFlow::define(['commercial' => 'dispatch', 'physical' => 'dispatch']),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 4],
        ));

        self::assertSame([], $this->divergentLayerSubjectIds());
    }

    // -------------------------------------------------------------------------
    // schema_snapshot ledger entries
    // -------------------------------------------------------------------------

    public function testBootstrapWritesSchemaSnapshotToSchemaLedger(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());

        self::assertSame(
            1,
            $this->fetchCount("SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'schema_snapshot'"),
        );

        $snapshot = $this->store()->schemaDefinitionAtTime(new \DateTimeImmutable('+1 second'));

        /** @var array<string, array<string, mixed>> $layers */
        $layers = $snapshot['layers'];
        self::assertArrayHasKey('commercial', $layers);
        self::assertArrayHasKey('physical', $layers);
        self::assertSame('commercial', (string) ($layers['commercial']['name'] ?? ''));
        self::assertSame('physical', (string) ($layers['physical']['name'] ?? ''));

        /** @var array<string, mixed> $commercialFlows */
        $commercialFlows = $layers['commercial']['flows'] ?? [];
        self::assertArrayHasKey('reserve', $commercialFlows);
        self::assertArrayHasKey('dispatch', $commercialFlows);

        /** @var array<string, mixed> $physicalFlows */
        $physicalFlows = $layers['physical']['flows'] ?? [];
        self::assertArrayHasKey('dispatch', $physicalFlows);
    }

    public function testSchemaDefinitionAtTimeReturnsCorrectSnapshot(): void
    {
        $this->store()->bootstrap($this->layeredDefinition());

        $before = new \DateTimeImmutable('-1 second');
        $after = new \DateTimeImmutable('+1 second');

        $snapshot = $this->store()->schemaDefinitionAtTime($after);

        self::assertArrayHasKey('layers', $snapshot);

        /** @var array<string, array<string, mixed>> $layers */
        $layers = $snapshot['layers'];
        self::assertArrayHasKey('commercial', $layers);
        self::assertSame('commercial', (string) ($layers['commercial']['name'] ?? ''));

        $this->expectException(SchemaException::class);
        $this->store()->schemaDefinitionAtTime($before);
    }

    public function testMinSlotTotalConstraintAppearsInSchemaSnapshot(): void
    {
        $definition = LayeredSlotSpaceDefinition::define([
            'commercial' => SlotSpaceDefinition::define('commercial', [
                DimensionDefinition::define('stt', ['fs', 'res', 'sd'], 0, 'fs'),
            ])->withFlow(
                FlowDefinition::define('reserve')
                    ->move('fs', 'res')
                    ->constraint(MinSlotTotal::define('fs', 5)),
            ),
        ]);

        $this->store()->bootstrap($definition);

        $snapshot = $this->store()->schemaDefinitionAtTime(new \DateTimeImmutable('+1 second'));

        /** @var array<string, array<string, mixed>> $layers */
        $layers = $snapshot['layers'];

        /** @var array<string, array<string, mixed>> $flows */
        $flows = $layers['commercial']['flows'] ?? [];

        /** @var list<array<string, mixed>> $steps */
        $steps = $flows['reserve']['steps'] ?? [];
        self::assertCount(1, $steps);

        /** @var list<array<string, mixed>> $constraints */
        $constraints = $steps[0]['constraints'] ?? [];
        self::assertCount(1, $constraints);
        self::assertSame('MinSlotTotal', (string) ($constraints[0]['type'] ?? ''));
        self::assertSame('fs', (string) ($constraints[0]['pattern'] ?? ''));
        self::assertSame(5, $constraints[0]['min']);
    }

    // -------------------------------------------------------------------------
    // Shared dimension views (commercial loc)
    // -------------------------------------------------------------------------

    public function testSharedRefAppearsInSlotSpaceDefinitionToDefinition(): void
    {
        $layered = $this->layeredDefinitionWithSharedLoc();

        /** @var list<array<string, mixed>> $commercialDims */
        $commercialDims = $layered->layers['commercial']->toDefinition()['dimensions'];
        self::assertSame('loc', $commercialDims[1]['name']);
        self::assertTrue($commercialDims[1]['sharedRef'] ?? false);
        /** @var array<string, mixed> $commercialSelector */
        $commercialSelector = $commercialDims[1]['valueSelector'] ?? [];
        self::assertSame('level', $commercialSelector['kind'] ?? null);
        self::assertSame('warehouse', $commercialSelector['level'] ?? null);

        /** @var list<array<string, mixed>> $physicalDims */
        $physicalDims = $layered->layers['physical']->toDefinition()['dimensions'];
        self::assertSame('loc', $physicalDims[0]['name']);
        self::assertTrue($physicalDims[0]['sharedRef'] ?? false);
        /** @var array<string, mixed> $physicalSelector */
        $physicalSelector = $physicalDims[0]['valueSelector'] ?? [];
        self::assertSame('leaf', $physicalSelector['kind'] ?? null);
    }

    public function testBootstrapWithSharedLocCompilesCorrectSlotsPerLayer(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh-a', level: 'warehouse'),
            new DimensionValueDefinition('wh-b', level: 'warehouse'),
        ]);

        $commercialId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'commercial'");
        $physicalId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'physical'");

        // commercial: 3 stt × 2 loc = 6 slots
        self::assertSame(6, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $commercialId AND active = 1"));
        // physical: 2 leaf loc = 2 slots
        self::assertSame(2, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $physicalId AND active = 1"));

        /** @var list<string> $commercialKeys */
        $commercialKeys = $this->pdo()->query("SELECT slot_key FROM invflux_slotspace WHERE layer_id = $commercialId AND active = 1 ORDER BY slot_key")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['fs.wh-a', 'fs.wh-b', 'res.wh-a', 'res.wh-b', 'sd.wh-a', 'sd.wh-b'], $commercialKeys);

        /** @var list<string> $physicalKeys */
        $physicalKeys = $this->pdo()->query("SELECT slot_key FROM invflux_slotspace WHERE layer_id = $physicalId AND active = 1 ORDER BY slot_key")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['wh-a', 'wh-b'], $physicalKeys);
    }

    public function testAddingWarehouseAddsSlotsInBothLayersAddingBinAddsOnlyPhysicalSlots(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $commercialId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'commercial'");
        $physicalId = $this->fetchCount("SELECT id FROM invflux_layers WHERE slug = 'physical'");

        // Flat warehouse is both warehouse-level and addressable: both layers get slots.
        self::assertSame(3, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $commercialId AND active = 1"));
        self::assertSame(1, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $physicalId AND active = 1"));

        // 'wh' is addressable, so it cannot simply gain a bin: hierarchise it first. The warehouse
        // is still 'wh' — the interposed parent keeps that level — and the stock it held moves
        // down into a bin below it, on the same slots.
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/unassigned', 'bin', 'warehouse');
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh/bin-1', parentCode: 'wh', level: 'bin')]);

        // Physical addresses leaves: the catch-all bin and the new one.
        self::assertSame(2, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $physicalId AND active = 1"));
        /** @var list<string> $physicalKeys */
        $physicalKeys = $this->pdo()->query("SELECT slot_key FROM invflux_slotspace WHERE layer_id = $physicalId AND active = 1 ORDER BY slot_key")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['wh/bin-1', 'wh/unassigned'], $physicalKeys);

        // Commercial addresses warehouses, and the warehouse did not move: same three slots,
        // same keys. Bins are below its grain and never appear.
        self::assertSame(3, $this->fetchCount("SELECT COUNT(*) FROM invflux_slotspace WHERE layer_id = $commercialId AND active = 1"));
        /** @var list<string> $commercialKeys */
        $commercialKeys = $this->pdo()->query("SELECT slot_key FROM invflux_slotspace WHERE layer_id = $commercialId AND active = 1 ORDER BY slot_key")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['fs.wh', 'res.wh', 'sd.wh'], $commercialKeys);
    }

    public function testBoundaryFlowWithDimensionScopeRestrictsToOneWarehouse(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('CLSD', 'Clear SD')]);
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh-a', level: 'warehouse'),
            new DimensionValueDefinition('wh-b', level: 'warehouse'),
        ]);

        $subject = $this->subject('SKU-SCOPE');
        $this->insertInventoryStateBySlotKey($subject, ['sd.wh-a' => 5, 'sd.wh-b' => 3, 'wh-a' => 5, 'wh-b' => 3]);

        $persisted = $this->store()->executeBatchBoundaryFlowFromStorage(new StorageBoundaryFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'CLSD',
            flow: BoundaryFlow::define(['commercial' => 'clear_sd', 'physical' => 'clear_sd']),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 5],
            dimensionScope: new DimensionScope('loc', 'wh-a'),
        ));

        self::assertTrue($persisted->ok);

        // wh-a cleared in both layers.
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'sd.wh-a'));
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'wh-a'));

        // wh-b untouched in both layers.
        self::assertSame(3, $this->fetchSlotQuantity($subject, 'sd.wh-b'));
        self::assertSame(3, $this->fetchSlotQuantity($subject, 'wh-b'));

        // Layer totals both reduced by 5, still equal (no divergence).
        self::assertSame(3, $this->fetchLayerQuantity($subject, 'commercial'));
        self::assertSame(3, $this->fetchLayerQuantity($subject, 'physical'));

        self::assertSame([], $this->divergentLayerSubjectIds());
    }

    public function testBoundaryFlowWithDimensionScopeMatchesLeafDescendantsInPhysicalLayer(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('CLSD', 'Clear SD')]);

        // Two flat warehouses.
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh', level: 'warehouse'),
            new DimensionValueDefinition('wh-2', level: 'warehouse'),
        ]);

        // Give wh a bin: hierarchisation demotes it to the catch-all wh/unassigned, and wh/bin-1
        // joins it as a sibling.
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/unassigned', 'bin', 'warehouse');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/bin-1', parentCode: 'wh', level: 'bin'),
        ]);

        // Physical leaf slots under wh: the catch-all wh/unassigned and wh/bin-1.
        // wh-2 remains a flat, addressable warehouse slot.
        $subject = $this->subject('SKU-LEAF-DESC');
        $this->insertInventoryStateBySlotKey($subject, [
            'sd.wh'         => 5,
            'sd.wh-2'       => 3,
            'wh/unassigned' => 2,
            'wh/bin-1'      => 3,
            'wh-2'          => 3,
        ]);
        // Seed the inactive physical ancestor aggregate: wh = sum(wh/unassigned, wh/bin-1) = 5.
        // In production this is maintained by ancestor fan-out; here we set it up manually
        // so the subsequent clear_sd fan-out can subtract without underflowing UNSIGNED.
        $this->insertAncestorInventoryState($subject, ['wh' => 5]);

        $persisted = $this->store()->executeBatchBoundaryFlowFromStorage(new StorageBoundaryFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'CLSD',
            flow: BoundaryFlow::define(['commercial' => 'clear_sd', 'physical' => 'clear_sd']),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 5],
            dimensionScope: new DimensionScope('loc', 'wh'),
        ));

        self::assertTrue($persisted->ok);

        // Commercial exact match cleared.
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'sd.wh'));

        // Physical descendants of wh cleared (wh/bin-1 and the catch-all wh/unassigned).
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'wh/bin-1'));
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'wh/unassigned'));

        // wh-2 and its physical slot are outside the scope and must be untouched.
        self::assertSame(3, $this->fetchSlotQuantity($subject, 'sd.wh-2'));
        self::assertSame(3, $this->fetchSlotQuantity($subject, 'wh-2'));

        // Layer totals both reduced by 5; no divergence.
        self::assertSame(3, $this->fetchLayerQuantity($subject, 'commercial'));
        self::assertSame(3, $this->fetchLayerQuantity($subject, 'physical'));

        self::assertSame([], $this->divergentLayerSubjectIds());
    }

    public function testBoundaryFlowWithDimensionScopeMatchesGrandchildDescendants(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('CLSD', 'Clear SD')]);

        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh', level: 'warehouse'),
            new DimensionValueDefinition('wh-2', level: 'warehouse'),
        ]);

        // Zones under wh: hierarchisation demotes wh to wh/unassigned at zone level, and wh/zone-a
        // joins it. The warehouse level stays on the interposed wh.
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/unassigned', 'zone', 'warehouse');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/zone-a', parentCode: 'wh', level: 'zone'),
        ]);

        // Bins under the zone: the same event one level down, so the same operation. bin-1 ends up
        // a grandchild of wh (path: wh/zone-a/bin-1/).
        $this->store()->hierarchiseDimensionValue('loc', 'wh/zone-a', 'wh/zone-a/unassigned', 'bin', 'zone');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/zone-a/bin-1', parentCode: 'wh/zone-a', level: 'bin'),
        ]);

        $subject = $this->subject('SKU-GRAND');
        $this->insertInventoryStateBySlotKey($subject, [
            'sd.wh'           => 3,
            'sd.wh-2'         => 5,
            'wh/zone-a/bin-1' => 3,
            'wh-2'            => 5,
        ]);
        // Seed inactive physical ancestor aggregates: wh/zone-a and wh each hold bin-1's 3 units.
        $this->insertAncestorInventoryState($subject, ['wh/zone-a' => 3, 'wh' => 3]);

        $persisted = $this->store()->executeBatchBoundaryFlowFromStorage(new StorageBoundaryFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'CLSD',
            flow: BoundaryFlow::define(['commercial' => 'clear_sd', 'physical' => 'clear_sd']),
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 3],
            dimensionScope: new DimensionScope('loc', 'wh'),
        ));

        self::assertTrue($persisted->ok);

        // Commercial exact match cleared.
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'sd.wh'));

        // bin-1 is two hops below wh (wh → wh/zone-a → wh/zone-a/bin-1): matching crosses levels.
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'wh/zone-a/bin-1'));

        // wh-2 and its physical slot are outside the scope.
        self::assertSame(5, $this->fetchSlotQuantity($subject, 'sd.wh-2'));
        self::assertSame(5, $this->fetchSlotQuantity($subject, 'wh-2'));

        // Layer totals both reduced by 3; no divergence.
        self::assertSame(5, $this->fetchLayerQuantity($subject, 'commercial'));
        self::assertSame(5, $this->fetchLayerQuantity($subject, 'physical'));

        self::assertSame([], $this->divergentLayerSubjectIds());
    }

    public function testHierarchisationKeepsStockAndItsLedgerOnTheSameSlots(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('RSRV', 'Reserve')]);
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $subject = $this->subject('SKU-HIER-LINEAGE');
        $this->insertInventoryStateBySlotKey($subject, ['fs.wh' => 5]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'reserve',
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 5],
            layerName: 'commercial',
        ));
        self::assertTrue($persisted->ok);

        $ledgerBefore = $this->ledgerSlotPairs($subject);
        self::assertNotSame([], $ledgerBefore, 'the movement must have written a ledger row to anchor');
        $freeSlotId = $this->slotIdForKey('fs.wh');
        $reservedSlotId = $this->slotIdForKey('res.wh');
        $physicalSlotId = $this->slotIdForKey('wh');

        // Warehouses arrive under 'wh', so the warehouse grain moves down onto wh/main with the
        // stock, and 'wh' becomes a grouping node above it.
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/main', 'warehouse', 'region');

        // The point of the operation: not one row of state or ledger is rewritten. The movement
        // recorded against 'wh' now reads as wh/main because it points at the slot, not the code.
        self::assertSame($ledgerBefore, $this->ledgerSlotPairs($subject));
        self::assertSame($freeSlotId, $this->slotIdForKey('fs.wh/main'));
        self::assertSame($reservedSlotId, $this->slotIdForKey('res.wh/main'));
        self::assertSame($physicalSlotId, $this->slotIdForKey('wh/main'));

        // The quantities came with them because they never moved.
        self::assertSame(0, $this->fetchSlotQuantity($subject, 'fs.wh/main'));
        self::assertSame(5, $this->fetchSlotQuantity($subject, 'res.wh/main'));

        // The vacated code is a new, history-free node — same key, different slot.
        self::assertNotSame($freeSlotId, $this->slotIdForKey('fs.wh'));

        // Retroactive relabelling is only truthful if it is recorded.
        self::assertSame(1, $this->fetchCount(
            "SELECT COUNT(*) FROM invflux_schema_ledger WHERE event_type = 'location_hierarchised'",
        ));
    }

    public function testHierarchisationLeavesTheCommercialGrainUntouchedWhenBinsArriveBelowIt(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $subject = $this->subject('SKU-HIER-GRAIN');
        $this->insertInventoryStateBySlotKey($subject, ['fs.wh' => 4, 'wh' => 4]);

        $commercialSlotId = $this->slotIdForKey('fs.wh');
        $physicalSlotId = $this->slotIdForKey('wh');

        // The mirror image of the test above: bins arrive, so the warehouse stays 'wh' and it is
        // the physical leaf that moves down.
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/unassigned', 'bin', 'warehouse');

        // Commercially nothing happened at all — same slot, same key, same quantity.
        self::assertSame($commercialSlotId, $this->slotIdForKey('fs.wh'));
        self::assertSame(4, $this->fetchSlotQuantity($subject, 'fs.wh'));

        // Physically the stock is in the catch-all bin, on the slot it was already on.
        self::assertSame($physicalSlotId, $this->slotIdForKey('wh/unassigned'));
        self::assertSame(4, $this->fetchSlotQuantity($subject, 'wh/unassigned'));

        // The grouping node keeps a physical row so ancestor aggregation still has one to sum
        // into, even though no layer addresses it.
        self::assertSame(0, $this->fetchCount(
            "SELECT active FROM invflux_slotspace WHERE slot_key = 'wh'",
        ));
    }

    public function testEachLayerProjectsTheDeclaredDefaultOntoTheValuesItAddresses(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        // wh-a sorts first, so a fallback to code order would answer with the wrong warehouse.
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh-a', level: 'warehouse'),
            new DimensionValueDefinition('wh-b', level: 'warehouse'),
        ]);
        $this->store()->setDimensionDefault('loc', 'wh-b');

        $this->store()->hierarchiseDimensionValue('loc', 'wh-b', 'wh-b/unassigned', 'bin', 'warehouse');

        // The default is stored by row, so it follows the demoted value down without being reset.
        self::assertSame(
            'wh-b/unassigned',
            $this->store()->layerSchema('physical')->dimensionByName('loc')?->defaultValue,
        );

        // The commercial layer cannot address a bin, so it answers with the warehouse containing
        // it — never with whichever warehouse happens to sort first.
        self::assertSame(
            'wh-b',
            $this->store()->layerSchema('commercial')->dimensionByName('loc')?->defaultValue,
        );
    }

    public function testAddDimensionValuesRefusesToGiveAnAddressableValueItsFirstChild(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('hierarchiseDimensionValue()');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/bin-1', parentCode: 'wh', level: 'bin'),
        ]);
    }

    public function testAddDimensionValuesRefusesAnAddressableParentDeclaredBesideItsChild(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('addressable: false');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('trs', level: 'external'),
            new DimensionValueDefinition('trs/inb', parentCode: 'trs', level: 'external'),
        ]);
    }

    public function testAddDimensionValuesAcceptsANonAddressableParentDeclaredBesideItsChild(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('trs', addressable: false, level: 'external'),
            new DimensionValueDefinition('trs/inb', parentCode: 'trs', level: 'external'),
        ]);

        // The child's row points at its parent, and its code carries the full path — the two
        // halves of the hierarchy, with nothing materialised beside them.
        self::assertSame(1, $this->fetchCount(
            "SELECT COUNT(*) FROM invflux_dimension_values child
               JOIN invflux_dimension_values parent ON parent.id = child.parent_id
              WHERE child.code = 'trs/inb' AND parent.code = 'trs'",
        ));
    }

    public function testAddDimensionValuesRefusesACodeThatDoesNotSitUnderItsParent(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh', addressable: false, level: 'warehouse'),
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('plus exactly one segment');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('bin-1', parentCode: 'wh', level: 'bin'),
        ]);
    }

    public function testAddDimensionValuesRefusesASkippedLevelInACode(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh', addressable: false, level: 'warehouse'),
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('plus exactly one segment');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/zone-a/bin-1', parentCode: 'wh', level: 'bin'),
        ]);
    }

    public function testAddDimensionValuesRefusesACodeTooLongForTheColumnThatStoresIt(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh', addressable: false, level: 'warehouse'),
        ]);

        // A WordPress install runs a non-strict sql_mode and would truncate this silently, leaving
        // `code` and the wider `path` disagreeing. The refusal has to come from us, not the column.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('short machine segments');
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh/'.str_repeat('a', 70), parentCode: 'wh', level: 'bin'),
        ]);
    }

    public function testHierarchiseRefusesWhenBothValuesWouldShareALevel(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('both be at level "warehouse"');
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/main', 'warehouse', 'warehouse');
    }

    public function testHierarchiseRefusesATargetMoreThanOneLevelBelow(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->addDimensionValues('loc', [new DimensionValueDefinition('wh', level: 'warehouse')]);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('exactly one parent is interposed');
        $this->store()->hierarchiseDimensionValue('loc', 'wh', 'wh/ch/main', 'warehouse', 'region');
    }

    public function testIntraLayerReserveFlowWorksWithSharedLocDimension(): void
    {
        $this->store()->bootstrap($this->layeredDefinitionWithSharedLoc());
        $this->store()->registerMovementTypes('InFlow', [new MovementTypeDefinition('RSRV', 'Reserve')]);
        $this->store()->addDimensionValues('loc', [
            new DimensionValueDefinition('wh-a', level: 'warehouse'),
            new DimensionValueDefinition('wh-b', level: 'warehouse'),
        ]);

        $subject = $this->subject('SKU-RSRV-LOC');
        $this->insertInventoryStateBySlotKey($subject, ['fs.wh-a' => 5, 'fs.wh-b' => 3]);

        $persisted = $this->store()->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
            movementTypeOwnerKey: 'InFlow',
            movementTypeCode: 'RSRV',
            flow: 'reserve',
            subjectIds: [$subject],
            quantitiesBySubjectId: [$subject->id => 8],
            layerName: 'commercial',
        ));

        self::assertTrue($persisted->ok);

        // Commercial total unchanged after intra-layer flow.
        self::assertSame(8, $this->fetchLayerQuantity($subject, 'commercial'));

        // All fs moved to res across both warehouse slots.
        $totalFs = $this->fetchSlotQuantity($subject, 'fs.wh-a') + $this->fetchSlotQuantity($subject, 'fs.wh-b');
        $totalRes = $this->fetchSlotQuantity($subject, 'res.wh-a') + $this->fetchSlotQuantity($subject, 'res.wh-b');
        self::assertSame(0, $totalFs);
        self::assertSame(8, $totalRes);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<\Nandan108\InvFlux\ReadModels\InventoryBalance> $balances
     *
     * @return array<string, string>
     */
    private function quantitiesByState(array $balances): array
    {
        $result = [];
        foreach ($balances as $balance) {
            $result[$balance->dimensions['state']] = $balance->quantity;
        }

        ksort($result);

        return $result;
    }

    private function baseDefinition(): SlotSpaceDefinition
    {
        return new SlotSpaceDefinition('default', [
            DimensionDefinition::define('state', ['fs', 'res', 'sd'], 0, 'fs'),
        ]);
    }

    /**
     * Register (or retrieve) a subject by label. Subjects are memoised per test run.
     * Requires bootstrap() to have been called first.
     */
    /**
     * A subject that holds stock — `stockManaged: true` is required, not incidental.
     *
     * Registration is ungoverned by default (opt-in), and the store silently skips ungoverned
     * subjects in `persistBatch`/`executeBatchFlowFromStorage` rather than raising: an order line
     * for something InvFlux doesn't govern is a legitimate state with nothing to do. For a test
     * that means a movement quietly becomes a no-op and the assertion fails on the *result*
     * (`0 !== 2`), pointing at the flow engine rather than at the subject's governance flag.
     */
    private function subject(string $label): SubjectId
    {
        if (!isset($this->subjects[$label])) {
            $this->subjects[$label] = $this->store()->registerSubject(stockManaged: true);
        }

        return $this->subjects[$label];
    }

    private function store(): MysqlInventoryStore
    {
        return $this->store ?? throw new \LogicException('Store was not initialized.');
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('PDO was not initialized.');
    }

    /**
     * @return array<string, scalar|null>
     */
    private function fetchAssoc(string $sql): array
    {
        $statement = $this->pdo()->query($sql);
        if (false === $statement) {
            throw new \RuntimeException('Expected a PDO statement.');
        }

        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('Expected one row, got none.');
        }

        /** @var array<string, scalar|null> $row */
        return $row;
    }

    private function fetchCount(string $sql): int
    {
        $statement = $this->pdo()->query($sql);
        if (false === $statement) {
            throw new \RuntimeException('Expected a PDO statement.');
        }

        $count = $statement->fetchColumn();
        is_scalar($count) || throw new \RuntimeException('Expected a scalar count result.');

        return (int) $count;
    }

    /** @return list<int> */
    private function fetchIntColumn(string $sql): array
    {
        $statement = $this->pdo()->query($sql);
        if (false === $statement) {
            throw new \RuntimeException('Expected a PDO statement.');
        }

        /** @var list<scalar> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('intval', $rows);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(string $method, array $args = [], ?object $target = null): mixed
    {
        $reflection = new \ReflectionMethod($target ? $target::class : MysqlInventoryStore::class, $method);

        return $reflection->invokeArgs($target ?? $this->store(), $args);
    }

    private function dropInvflowTables(): void
    {
        $tables = [
            'invflux_projection_probe',
            'invflux_diagnostic_results',
            'invflux_diagnostic_schedule',
            'invflux_idempotency',
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

        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $this->pdo()->exec("DROP TABLE IF EXISTS $table");
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createProjectionProbeTable(): void
    {
        $this->pdo()->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS invflux_projection_probe (
                resource_key VARCHAR(255) NOT NULL PRIMARY KEY,
                quantity_delta BIGINT NOT NULL,
                movement_code VARCHAR(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }

    private function layeredDefinition(): LayeredSlotSpaceDefinition
    {
        $commercial = SlotSpaceDefinition::define('commercial', [
            DimensionDefinition::define('stt', ['fs', 'res', 'sd'], 0, 'fs'),
        ])
            ->withFlow(FlowDefinition::define('reserve')->move('fs', 'res'))
            ->withFlow(FlowDefinition::define('dispatch')->destroy('sd'));

        $physical = SlotSpaceDefinition::define('physical', [
            DimensionDefinition::define('loc', ['wh'], 1, 'wh'),
        ])
            ->withFlow(FlowDefinition::define('dispatch')->destroy('wh'));

        return LayeredSlotSpaceDefinition::define([
            'commercial' => $commercial,
            'physical'   => $physical,
        ]);
    }

    private function layeredDefinitionWithSharedLoc(): LayeredSlotSpaceDefinition
    {
        $commercial = SlotSpaceDefinition::define('commercial', [
            DimensionDefinition::define('stt', ['fs', 'res', 'sd'], 0, 'fs'),
            DimensionDefinition::sharedRef('loc', position: 1, valueSelector: DimensionValueSelector::level('warehouse')),
        ])
            ->withFlow(FlowDefinition::define('reserve')->move(['stt' => 'fs'], ['stt' => 'res']))
            ->withFlow(FlowDefinition::define('clear_sd')->destroy(['stt' => 'sd']));

        $physical = SlotSpaceDefinition::define('physical', [
            DimensionDefinition::sharedRef('loc', position: 0, valueSelector: DimensionValueSelector::leaf()),
        ])
            ->withFlow(FlowDefinition::define('clear_sd')->destroy([]));

        return LayeredSlotSpaceDefinition::define([
            'commercial' => $commercial,
            'physical'   => $physical,
        ]);
    }

    /** The hex slot id behind one slot key — the identity a hierarchisation must preserve. */
    private function slotIdForKey(string $slotKey): string
    {
        $statement = $this->pdo()->prepare('SELECT HEX(id) FROM invflux_slotspace WHERE slot_key = ?');
        $statement->execute([$slotKey]);
        $id = $statement->fetchColumn();
        self::assertIsString($id, sprintf('No slot row exists for key "%s".', $slotKey));

        return $id;
    }

    /**
     * Every ledger movement for one subject as a (from, to) slot-id pair, in order.
     *
     * Compared across a schema change to assert the ledger was not rewritten: the codes a movement
     * renders under may change, the slots it points at may not.
     *
     * @return list<array{from: ?string, to: ?string}>
     */
    private function ledgerSlotPairs(SubjectId $subjectId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT HEX(from_slot_id) AS from_slot, HEX(to_slot_id) AS to_slot
               FROM invflux_inventory_ledger WHERE subject_id = ? ORDER BY id',
        );
        $statement->execute([$subjectId->id]);

        $pairs = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            /** @psalm-var mixed $from */
            $from = $row['from_slot'] ?? null;
            /** @psalm-var mixed $to */
            $to = $row['to_slot'] ?? null;
            $pairs[] = [
                'from' => \is_string($from) ? $from : null,
                'to'   => \is_string($to) ? $to : null,
            ];
        }

        return $pairs;
    }

    /** @param array<non-empty-string, int> $quantitiesBySlotKey */
    private function insertInventoryStateBySlotKey(SubjectId $subjectId, array $quantitiesBySlotKey): void
    {
        foreach ($quantitiesBySlotKey as $slotKey => $quantity) {
            if (0 === $quantity) {
                continue;
            }
            $this->pdo()->exec(sprintf(
                'INSERT INTO invflux_inventory_state (subject_id, slot_id, quantity)
                 SELECT %d, id, %d FROM invflux_slotspace WHERE slot_key = %s AND active = 1',
                $subjectId->id,
                $quantity,
                $this->pdo()->quote($slotKey),
            ));
        }
    }

    /**
     * Like insertInventoryStateBySlotKey but also inserts for inactive ancestor aggregate slots.
     * Used when tests need to pre-seed ancestor totals that would normally be built up
     * by the ancestor fan-out on previous persist() calls.
     *
     * @param array<string, int> $quantitiesBySlotKey
     */
    private function insertAncestorInventoryState(SubjectId $subjectId, array $quantitiesBySlotKey): void
    {
        foreach ($quantitiesBySlotKey as $slotKey => $quantity) {
            if (0 === $quantity) {
                continue;
            }
            $this->pdo()->exec(sprintf(
                'INSERT INTO invflux_inventory_state (subject_id, slot_id, quantity)
                 SELECT %d, id, %d FROM invflux_slotspace WHERE slot_key = %s',
                $subjectId->id,
                $quantity,
                $this->pdo()->quote($slotKey),
            ));
        }
    }

    /**
     * Subject IDs whose per-layer totals (recomputed from inventory_state,
     * addressable-leaf filtered) diverge across layers — i.e. cross-layer
     * drift. Empty = invariant holds.
     *
     * @return list<int>
     */
    private function divergentLayerSubjectIds(): array
    {
        /** @var list<int|string> $rows */
        $rows = $this->pdo()->query(
            "SELECT subject_id FROM (
                SELECT s.subject_id, l.id AS layer_id, SUM(s.quantity) AS quantity
                FROM invflux_inventory_state s
                JOIN invflux_slotspace ss ON ss.id = s.slot_id
                JOIN invflux_layers l ON l.id = ss.layer_id
                LEFT JOIN invflux_dimensions d_loc ON d_loc.name = 'loc'
                LEFT JOIN invflux_dimension_values dv_loc
                   ON dv_loc.dimension_id = d_loc.id AND dv_loc.code = ss.dim_loc
                WHERE ss.layer_id IS NOT NULL
                  AND (dv_loc.id IS NULL OR dv_loc.addressable = 1)
                GROUP BY s.subject_id, l.id
            ) per_layer
            GROUP BY subject_id
            HAVING MIN(quantity) <> MAX(quantity)",
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_map(static fn ($id): int => (int) $id, $rows);
    }

    /**
     * Recompute a subject's total quantity for one layer directly from
     * authoritative inventory_state (addressable-leaf filter, so ancestor
     * aggregate slots aren't double-counted).
     */
    private function fetchLayerQuantity(SubjectId $subjectId, string $layerSlug): int
    {
        return $this->fetchCount(sprintf(
            'SELECT COALESCE(SUM(s.quantity), 0)
             FROM invflux_layers l
             JOIN invflux_slotspace ss ON ss.layer_id = l.id AND ss.active = 1
             LEFT JOIN invflux_dimensions d_loc ON d_loc.name = \'loc\'
             LEFT JOIN invflux_dimension_values dv_loc
                ON dv_loc.dimension_id = d_loc.id AND dv_loc.code = ss.dim_loc
             LEFT JOIN invflux_inventory_state s ON s.slot_id = ss.id AND s.subject_id = %d
             WHERE l.slug = %s
               AND (dv_loc.id IS NULL OR dv_loc.addressable = 1)',
            $subjectId->id,
            $this->pdo()->quote($layerSlug),
        ));
    }

    private function fetchSlotQuantity(SubjectId $subjectId, string $slotKey): int
    {
        return $this->fetchCount(sprintf(
            'SELECT COALESCE(s.quantity, 0)
             FROM invflux_slotspace ss
             LEFT JOIN invflux_inventory_state s ON s.slot_id = ss.id AND s.subject_id = %d
             WHERE ss.slot_key = %s AND ss.active = 1',
            $subjectId->id,
            $this->pdo()->quote($slotKey),
        ));
    }

    // -----------------------------------------------------------------------
    // resolveSurfaceId / resolveActorId — registry resolvers
    //
    // The SELECT-first behaviour is what protects the SMALLINT auto-increment
    // domain on `wp_invflux_surfaces.id` (and other small-PK registry tables)
    // from being burnt by INSERT-then-collide on every call. These tests
    // verify the contract: existing rows are returned without writes, missing
    // rows are created exactly once, parent-id is set-or-corrected only when
    // the caller asserts one.
    // -----------------------------------------------------------------------

    public function testResolveSurfaceIdCreatesRowOnFirstCallAndReturnsExistingIdOnRepeat(): void
    {
        // 'plugin' surface type is seeded by MysqlDomainStore::seedReferenceTables()
        // (called from installReferenceTables() in setUp), so no registerSurfaceTypes
        // call is needed here. Same applies in tests below.
        $this->store()->bootstrap($this->baseDefinition());

        $firstId = $this->store()->resolveSurfaceId('plugin', 'woocommerce');
        self::assertGreaterThan(0, $firstId);

        $autoIncBefore = $this->surfacesAutoIncrement();

        // Ten more calls for the same (type_code, ref) — should all return
        // the same id without bumping the AUTO_INCREMENT counter.
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(
                $firstId,
                $this->store()->resolveSurfaceId('plugin', 'woocommerce'),
                'resolveSurfaceId should be idempotent for an existing (type_code, ref) pair',
            );
        }

        self::assertSame(
            $autoIncBefore,
            $this->surfacesAutoIncrement(),
            'SELECT-first must not bump AUTO_INCREMENT when the row already exists',
        );
    }

    public function testResolveSurfaceIdHonoursParentIdSetCorrectAndOmitSemantics(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $rootId = $this->store()->resolveSurfaceId('plugin', 'woocommerce');
        $childId = $this->store()->resolveSurfaceId('system', 'checkout', parentId: $rootId);

        self::assertSame($rootId, (int) $this->fetchAssoc(sprintf(
            'SELECT parent_id FROM invflux_surfaces WHERE id = %d',
            $childId,
        ))['parent_id']);

        // Re-resolve WITHOUT supplying parent_id — stored value must stay.
        self::assertSame($childId, $this->store()->resolveSurfaceId('system', 'checkout'));
        self::assertSame($rootId, (int) $this->fetchAssoc(sprintf(
            'SELECT parent_id FROM invflux_surfaces WHERE id = %d',
            $childId,
        ))['parent_id']);

        // Re-resolve WITH a different parent_id — stored value must be corrected.
        $otherRootId = $this->store()->resolveSurfaceId('plugin', 'shopify');
        self::assertSame($childId, $this->store()->resolveSurfaceId('system', 'checkout', parentId: $otherRootId));
        self::assertSame($otherRootId, (int) $this->fetchAssoc(sprintf(
            'SELECT parent_id FROM invflux_surfaces WHERE id = %d',
            $childId,
        ))['parent_id']);
    }

    public function testResolveSurfaceIdThrowsOnUnknownTypeCode(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown surface type code: not_a_real_type/');

        // 'not_a_real_type' is not in the seeded set (admin_page, api, cli,
        // plugin, system, import) nor registered by this test.
        $this->store()->resolveSurfaceId('not_a_real_type', 'woocommerce');
    }

    public function testResolveActorIdCreatesRowOnFirstCallAndReturnsExistingIdOnRepeat(): void
    {
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerActorTypes([
            new ActorTypeDefinition('plugin', 'Plugin'),
        ]);

        $firstId = $this->store()->resolveActorId('plugin', 'woocommerce');
        self::assertGreaterThan(0, $firstId);

        $autoIncBefore = $this->actorsAutoIncrement();

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(
                $firstId,
                $this->store()->resolveActorId('plugin', 'woocommerce'),
                'resolveActorId should be idempotent for an existing (type_code, ref) pair',
            );
        }

        self::assertSame(
            $autoIncBefore,
            $this->actorsAutoIncrement(),
            'SELECT-first must not bump AUTO_INCREMENT when the row already exists',
        );
    }

    public function testResolveActorIdAcceptsNullRefAsEmptyString(): void
    {
        // resolveActorId's null-ref → '' coercion is the established contract;
        // documented in the method signature. Two calls (null and '') must
        // resolve to the same row.
        $this->store()->bootstrap($this->baseDefinition());
        $this->store()->registerActorTypes([
            new ActorTypeDefinition('system', 'System'),
        ]);

        $idFromNull = $this->store()->resolveActorId('system', null);
        $idFromEmpty = $this->store()->resolveActorId('system', '');

        self::assertSame($idFromNull, $idFromEmpty);
    }

    public function testResolveActorIdThrowsOnUnknownTypeCode(): void
    {
        $this->store()->bootstrap($this->baseDefinition());

        $this->expectException(UnknownActorTypeException::class);

        // 'not_a_real_type' is not in the seeded set (admin, system, plugin)
        // nor registered by this test.
        $this->store()->resolveActorId('not_a_real_type', 'woocommerce');
    }

    private function surfacesAutoIncrement(): int
    {
        return (int) $this->pdo()->query(
            "SELECT AUTO_INCREMENT FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'invflux_surfaces'",
        )->fetchColumn();
    }

    private function actorsAutoIncrement(): int
    {
        return (int) $this->pdo()->query(
            "SELECT AUTO_INCREMENT FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'invflux_actors'",
        )->fetchColumn();
    }
}
