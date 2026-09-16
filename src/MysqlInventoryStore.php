<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql;

use Nandan108\Attrecord\Enum\OnConflict;
use Nandan108\Attrecord\RawSql;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Contracts\Inventory\InventoryStore;
use Nandan108\InvFlux\Domain\Stock\StockAdjustment;
use Nandan108\InvFlux\Domain\Stock\StockAdjustmentLine;
use Nandan108\InvFlux\Domain\Subject\IdentifierAssignmentPolicy;
use Nandan108\InvFlux\Domain\Subject\IdentifierClaimSpec;
use Nandan108\InvFlux\Domain\Subject\IdentifierValidity;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Domain\Subject\SubjectId;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifier;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifierAssignment;
use Nandan108\InvFlux\Domain\Subject\SubjectIdentifierClaim;
use Nandan108\InvFlux\Domain\Subject\SubjectKind;
use Nandan108\InvFlux\Exceptions\ActiveIdentifierNotFoundException;
use Nandan108\InvFlux\Exceptions\AmbiguousIdentifierAssignmentException;
use Nandan108\InvFlux\Exceptions\ConfigurationException;
use Nandan108\InvFlux\Exceptions\IdentifierAlreadyClaimedException;
use Nandan108\InvFlux\Exceptions\IdentifierValueRetiredException;
use Nandan108\InvFlux\Exceptions\InvalidFilterException;
use Nandan108\InvFlux\Exceptions\InvalidQuantityException;
use Nandan108\InvFlux\Exceptions\PersistenceException;
use Nandan108\InvFlux\Exceptions\SchemaException;
use Nandan108\InvFlux\Exceptions\UnknownActorTypeException;
use Nandan108\InvFlux\Exceptions\UnknownMovementTypeException;
use Nandan108\InvFlux\Idempotency\IdempotencyKey;
use Nandan108\InvFlux\Idempotency\IdempotentExecution;
use Nandan108\InvFlux\Idempotency\IdempotentOutcome;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\ActorTypeRecord;
use Nandan108\InvFlux\Identity\IdentifierTypeRecord;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Identity\SurfaceRecord;
use Nandan108\InvFlux\Identity\SurfaceTypeRecord;
use Nandan108\InvFlux\Identity\SystemRecord;
use Nandan108\InvFlux\Layer\LayeredMovementEngine;
use Nandan108\InvFlux\Layer\LayeredQuantityState;
use Nandan108\InvFlux\Layer\LayeredSlotSpaceDefinition;
use Nandan108\InvFlux\Mutation\ActorReference;
use Nandan108\InvFlux\Mutation\EntityReference;
use Nandan108\InvFlux\Mutation\MetaEvent;
use Nandan108\InvFlux\Mutation\PersistBatchMovement;
use Nandan108\InvFlux\Mutation\PersistMovement;
use Nandan108\InvFlux\Mutation\StorageBatchFlowRequest;
use Nandan108\InvFlux\Mutation\StorageBoundaryFlowRequest;
use Nandan108\InvFlux\Mutation\SurfaceReference;
use Nandan108\InvFlux\Projection\ProjectionContext;
use Nandan108\InvFlux\Projection\ProjectionLockTarget;
use Nandan108\InvFlux\Projection\ProjectionParticipant;
use Nandan108\InvFlux\ReadModels\InventoryBalance;
use Nandan108\InvFlux\ReadModels\LedgerRecord;
use Nandan108\InvFlux\Registry\ActorTypeDefinition;
use Nandan108\InvFlux\Registry\MovementTypeDefinition;
use Nandan108\InvFlux\Results\PersistedBatchMovement;
use Nandan108\InvFlux\Results\PersistedMovement;
use Nandan108\InvFlux\Results\PersistenceConflict;
use Nandan108\InvFlux\Schema\CollapseBehavior;
use Nandan108\InvFlux\Schema\DimensionDefinition;
use Nandan108\InvFlux\Schema\DimensionKind;
use Nandan108\InvFlux\Schema\DimensionScope;
use Nandan108\InvFlux\Schema\DimensionValueDefinition;
use Nandan108\InvFlux\Schema\DimensionValueSelector;
use Nandan108\InvFlux\Schema\DimensionValueSelectorKind;
use Nandan108\InvFlux\Schema\SlotSpaceDefinition;
use Nandan108\InvFlux\Storage\Mysql\Identity\UuidV7Minter;
use Nandan108\InvFlux\Storage\Mysql\Projection\MysqlProjectionRuntime;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\ConfigState;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Dimension;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\DimensionValue;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Idempotency;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\IdentifierAssignmentPolicy as IdentifierAssignmentPolicyDdl;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\InventoryLedger;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\InventoryState;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Layer;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\MovementType;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SchemaLedger;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SlotSpace as SlotSpaceDdl;
use Nandan108\InvFlux\Storage\Mysql\Schema\SlotSpaceSchema;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;
use Nandan108\SlotFlow\Batch\BatchLedgerEntry;
use Nandan108\SlotFlow\Batch\BatchMovementEngine;
use Nandan108\SlotFlow\Batch\QuantityStateBatch;
use Nandan108\SlotFlow\Codecs\DefaultSlotKeyCodec;
use Nandan108\SlotFlow\Flow;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\QuantityState;
use Nandan108\SlotFlow\Results\LedgerEntry;
use Nandan108\SlotFlow\SlotSpace;

/**
 * @psalm-import-type TSlotPattern from SlotSpace
 *
 * @psalm-type TSlotFilters = array<non-empty-string, non-empty-string|list<non-empty-string>>
 * @psalm-type TAssocPayload = array<string, mixed>
 * @psalm-type TStorageInventoryRow = array{
 *   subject_id: int,
 *   slot_key: string,
 *   quantity: int,
 *   dimensions: array<non-empty-string, non-empty-string>
 * }
 */

/**
 * Represent one staged inventory delta row before authoritative locking.
 *
 * @internal
 */
class PersistDeltaRow
{
    public function __construct(
        public readonly int $subjectId,
        public readonly string $slotId,
        public readonly string $slotKey,
        public int $delta,
        public readonly ?int $minQuantity,
        public readonly ?int $maxQuantity,
        public readonly bool $isAncestor = false,
    ) {
    }

    public static function create(
        int $subjectId,
        string $slotId,
        string $slotKey,
        ?int $minQuantity,
        ?int $maxQuantity,
    ): self {
        return new self($subjectId, $slotId, $slotKey, 0, $minQuantity, $maxQuantity);
    }

    /**
     * @return array{subject_id: int, slot_id: string, slot_key: string, delta: int, min_quantity: ?int, max_quantity: ?int}
     */
    public function toProjectionRow(): array
    {
        return [
            'subject_id'   => $this->subjectId,
            'slot_id'      => $this->slotId,
            'slot_key'     => $this->slotKey,
            'delta'        => $this->delta,
            'min_quantity' => $this->minQuantity,
            'max_quantity' => $this->maxQuantity,
        ];
    }

    /**
     * @param list<self> $rows
     *
     * @return list<array{subject_id: int, slot_id: string, slot_key: string, delta: int, min_quantity: ?int, max_quantity: ?int}>
     */
    public static function toProjectionRows(array $rows): array
    {
        return array_map(static fn (self $row): array => $row->toProjectionRow(), $rows);
    }
}

/**
 * Represent one staged inventory delta row after authoritative state locking.
 *
 * @internal
 */
final class LockedPersistDeltaRow extends PersistDeltaRow
{
    public function __construct(
        int $subjectId,
        string $slotId,
        string $slotKey,
        public readonly int $quantity,
        int $delta,
        ?int $minQuantity,
        ?int $maxQuantity,
        bool $isAncestor = false,
    ) {
        parent::__construct($subjectId, $slotId, $slotKey, $delta, $minQuantity, $maxQuantity, $isAncestor);
    }

    public function projectedQuantity(): int
    {
        return $this->quantity + $this->delta;
    }

    /**
     * @return array{subject_id: int, slot_id: string, slot_key: string, quantity: int, delta: int, min_quantity: ?int, max_quantity: ?int}
     */
    public function toLockedProjectionRow(): array
    {
        return [
            'subject_id'   => $this->subjectId,
            'slot_id'      => $this->slotId,
            'slot_key'     => $this->slotKey,
            'quantity'     => $this->quantity,
            'delta'        => $this->delta,
            'min_quantity' => $this->minQuantity,
            'max_quantity' => $this->maxQuantity,
        ];
    }

    /**
     * @param list<self> $rows
     *
     * @return list<array{subject_id: int, slot_id: string, slot_key: string, quantity: int, delta: int, min_quantity: ?int, max_quantity: ?int}>
     */
    public static function toLockedProjectionRows(array $rows): array
    {
        return array_map(static fn (self $row): array => $row->toLockedProjectionRow(), $rows);
    }
}

/**
 * Report the internal outcome of one staged-delta persistence attempt.
 *
 * @internal
 */
final class PersistDeltaRowsOutcome
{
    /**
     * @param list<PersistenceConflict> $conflicts
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $affectedSlots,
        public readonly int $insertedLedgerRows,
        public readonly array $conflicts,
    ) {
    }

    public static function success(int $affectedSlots, int $insertedLedgerRows): self
    {
        return new self(
            ok: true,
            affectedSlots: $affectedSlots,
            insertedLedgerRows: $insertedLedgerRows,
            conflicts: [],
        );
    }

    /**
     * @param list<PersistenceConflict> $conflicts
     */
    public static function conflict(array $conflicts): self
    {
        return new self(
            ok: false,
            affectedSlots: 0,
            insertedLedgerRows: 0,
            conflicts: $conflicts,
        );
    }
}

final class MysqlInventoryStore implements InventoryStore
{
    private const INTERNAL_MOVEMENT_OWNER = 'InvFlux';
    private const INTERNAL_MOVEMENT_DRAIN_VALUE = 'VALMIG';
    private const TEMP_TABLE_DELTAS = 'tmp_invflux_deltas';
    private const TABLE_DIMENSIONS = 'invflux_dimensions';
    private const TABLE_DIMENSION_VALUES = 'invflux_dimension_values';

    /**
     * Longest dimension-value code that survives a write intact.
     *
     * Bound by two columns that must agree, not one: `invflux_dimension_values.code`, and the
     * `dim_<name>` column on `invflux_slotspace` that carries the same string onto every slot.
     * Widening either alone would move the truncation point rather than remove it.
     *
     * @see assertDimensionValueCodeFits() for why this is enforced here rather than left to MySQL
     */
    private const MAX_DIMENSION_VALUE_CODE_LENGTH = 64;
    private const TABLE_SLOTSPACE = 'invflux_slotspace';
    private const TABLE_INVENTORY_STATE = 'invflux_inventory_state';
    private const TABLE_CONFIG_STATE = 'invflux_config_state';

    /** The config-state singleton's primary key — the table holds exactly one row. */
    private const CONFIG_STATE_ID = 1;
    private const TABLE_MOVEMENT_TYPES = 'invflux_movement_types';
    private const TABLE_ACTOR_TYPES = 'invflux_actor_types';
    private const TABLE_ACTORS = 'invflux_actors';
    private const TABLE_REF_TYPES = 'invflux_ref_types';
    private const TABLE_SURFACE_TYPES = 'invflux_surface_types';
    private const TABLE_SURFACES = 'invflux_surfaces';
    private const TABLE_INVENTORY_LEDGER = 'invflux_inventory_ledger';
    private const TABLE_SCHEMA_LEDGER = 'invflux_schema_ledger';
    private const TABLE_IDEMPOTENCY = 'invflux_idempotency';
    private const TABLE_SUBJECTS = 'invflux_subjects';
    private const TABLE_IDENTIFIER_TYPES = 'invflux_identifier_types';
    private const TABLE_SYSTEMS = 'invflux_systems';
    private const TABLE_SUBJECT_IDENTIFIERS = 'invflux_subject_identifiers';
    private const TABLE_IDENTIFIER_ASSIGNMENT_POLICIES = 'invflux_identifier_assignment_policies';
    private const TABLE_LAYERS = 'invflux_layers';

    /**
     * Every Record class whose table this store owns.
     *
     * A **set**, not a sequence: creation order is derived from the declared `#[ForeignKey]`
     * graph by the schema installer, so nothing here depends on position. Listing them is
     * what makes them managed — a Record absent from this list is a table the differ never
     * sees, and therefore never creates or converges.
     *
     * `Dimension` and `DimensionValue` reference each other, which no single creation order
     * can satisfy; the installer breaks that cycle by deferring one constraint to an ALTER.
     *
     * @var list<class-string<AttrecordRecord>>
     */
    public const INVENTORY_RECORDS = [
        Subject::class,
        SubjectIdentifier::class,
        Dimension::class,
        DimensionValue::class,
        Layer::class,
        SlotSpaceDdl::class,
        ConfigState::class,
        IdentifierAssignmentPolicyDdl::class,
        MovementType::class,
        InventoryLedger::class,
        SchemaLedger::class,
        InventoryState::class,
        StockAdjustment::class,
        StockAdjustmentLine::class,
        Idempotency::class,
    ];

    private readonly UuidV7Minter $uuidMinter;

    public function __construct(
        private readonly MysqlSession $session,
        private readonly string $tablePrefix = '',
        ?UuidV7Minter $uuidMinter = null,
    ) {
        // Default to node_id 1 if none injected — keeps the constructor
        // backward-compatible for tests/scripts. Production always injects
        // the container-resolved minter with the merchant's persisted
        // federation.node_id.
        $this->uuidMinter = $uuidMinter ?? new UuidV7Minter(1);
    }

    /** @var array<string, ProjectionParticipant> */
    private array $projectionParticipants = [];

    /** @var array<string, positive-int>|null Lazy-loaded map of actor type code → id. */
    private ?array $actorTypeIdsByCode = null;

    /** Cached layered schema after bootstrap. */
    private ?LayeredSlotSpaceDefinition $layeredSchema = null;

    /** Null until first checked; true if any dimension value has a parent_id set. */
    private ?bool $hasHierarchyDimension = null;

    /** Register one first-party in-transaction projection participant. */
    public function registerProjectionParticipant(ProjectionParticipant $participant): void
    {
        $key = $participant->key();
        if ('' === $key) {
            throw new ConfigurationException('Projection participant key must be a non-empty string.', 'empty_projection_participant_key');
        }

        if (isset($this->projectionParticipants[$key])) {
            throw new ConfigurationException(
                sprintf('Projection participant key "%s" is already registered.', $key),
                'duplicate_projection_participant_key',
                ['participantKey' => $key],
            );
        }

        $this->projectionParticipants[$key] = $participant;
        ksort($this->projectionParticipants);
    }

    /** Bootstrap the InvFlux schema and initial slotspace rows. */
    #[\Override]
    public function bootstrap(LayeredSlotSpaceDefinition | SlotSpaceDefinition $definition): void
    {
        $layered = $definition instanceof LayeredSlotSpaceDefinition
            ? $definition
            : LayeredSlotSpaceDefinition::define(['default' => $definition]);

        $this->createBaseTables();

        $existing = $this->tryLayeredSchema();
        if (null !== $existing) {
            // Reconcile the requested definition against the stored schema **additively**
            // rather than demanding exact equivalence. A definition that only *adds* — a new
            // value on a fixed dimension, or a dimension that has become shared (its values now
            // registered at runtime via addDimensionValues) — is applied, not rejected. Only a
            // genuine *redefinition* (a value's parent/level/metadata changed, a shared/fixed
            // kind flip, a dimension or layer that no longer exists) is a mismatch. Values
            // present in storage but absent from the request are kept dormant, never dropped —
            // stock may sit in them. See {@see reconcileLayerAdditively()}.
            /** @var list<array{name: string, values: list<DimensionValueDefinition>}> $valueAdditions */
            $valueAdditions = [];
            foreach ($layered->layers as $layerName => $layerDef) {
                $existingLayer = $existing->layers[$layerName] ?? null;
                if (null === $existingLayer) {
                    throw new SchemaException(
                        sprintf('Requested layer "%s" is not present in the stored slot-space schema.', $layerName),
                        'schema_mismatch',
                        ['layerName' => $layerName],
                    );
                }
                foreach ($this->reconcileLayerAdditively($existingLayer, $layerDef, $layerName) as $addition) {
                    $valueAdditions[] = $addition;
                }
            }

            $this->upsertLayerRows($layered);
            $this->syncSlotspaceRowsLayered($layered);
            $this->syncConfigStateLayered($layered);
            $this->layeredSchema = $layered;

            // Register any values the request adds over the stored schema. Deferred until after
            // the layer/config sync so schema() resolves against the reconciled layer set; each
            // call re-syncs the affected slots. Empty in the common (unchanged) case.
            foreach ($valueAdditions as $addition) {
                $this->addDimensionValues($addition['name'], $addition['values']);
            }

            return;
        }

        $this->transactional(function () use ($layered): void {
            $this->upsertLayerRows($layered);
            // Track inserted dimension IDs across layers so shared dims are only inserted once.
            /** @var array<string, int> $insertedDimensionIds */
            $insertedDimensionIds = [];
            foreach ($layered->layers as $layerDef) {
                $dimensionIds = $this->insertDimensions($layerDef, $insertedDimensionIds);
                $insertedDimensionIds += $dimensionIds;
                $dimensionValueIds = $this->insertDimensionValues($layerDef, $dimensionIds);
                $this->applyDimensionDefaults($layerDef, $dimensionIds, $dimensionValueIds);
            }
        });

        $this->syncSlotspaceRowsLayered($layered);
        $this->syncConfigStateLayered($layered);
        $this->layeredSchema = $layered;
    }

    /**
     * Reconcile one requested layer against its stored counterpart, additively.
     *
     * Returns the value additions to apply (a fixed dimension whose request declares codes the
     * stored schema lacks); throws {@see SchemaException} `schema_mismatch` on a genuine
     * redefinition. The rules, per requested dimension:
     *
     *  - **absent from storage** → mismatch (a new dimension mid-life is structural, not additive);
     *  - **shared in the request** → skip: its values live in `invflux_dimension_values`, registered
     *    at runtime, so there is nothing to reconcile from the definition. This also absorbs a
     *    *fixed → shared* transition — the stored fixed values simply become the shared dimension's
     *    runtime values, and the layer-config rewrite records the new kind;
     *  - **fixed in the request but shared in storage** → mismatch (a shared → fixed flip would strand
     *    the runtime-registered values);
     *  - **fixed on both sides** → per value: a code absent from storage is a new value to register;
     *    a code present on both must match exactly (a changed parent/level/metadata is a redefinition
     *    → mismatch). Stored codes absent from the request are left dormant, never dropped.
     *
     * @return list<array{name: string, values: list<DimensionValueDefinition>}>
     */
    private function reconcileLayerAdditively(
        SlotSpaceDefinition $existing,
        SlotSpaceDefinition $requested,
        string $layerName,
    ): array {
        $existingByName = [];
        foreach ($existing->dimensions as $dim) {
            $existingByName[$dim->name] = $dim;
        }

        $additions = [];
        foreach ($requested->dimensions as $requestedDim) {
            $existingDim = $existingByName[$requestedDim->name] ?? null;
            if (null === $existingDim) {
                throw new SchemaException(
                    sprintf('Requested dimension "%s" (layer "%s") is not present in the stored schema.', $requestedDim->name, $layerName),
                    'schema_mismatch',
                    ['dimensionName' => $requestedDim->name, 'layerName' => $layerName],
                );
            }

            if ($requestedDim->isSharedRef) {
                continue;
            }

            if ($existingDim->isSharedRef) {
                throw new SchemaException(
                    sprintf('Dimension "%s" (layer "%s") is stored as shared-ref but requested as a fixed-value dimension.', $requestedDim->name, $layerName),
                    'schema_mismatch',
                    ['dimensionName' => $requestedDim->name, 'layerName' => $layerName],
                );
            }

            $existingValuesByCode = $existingDim->valuesByCode(includeInactive: true);
            $newValues = [];
            foreach ($requestedDim->values as $value) {
                $existingValue = $existingValuesByCode[$value->code] ?? null;
                if (null === $existingValue) {
                    $newValues[] = $value;
                    continue;
                }
                if ($this->dimensionValueDefinitionsDiffer($existingValue, $value)) {
                    throw new SchemaException(
                        sprintf('Dimension value "%s:%s" (layer "%s") is redefined by the requested schema; only additive changes reconcile.', $requestedDim->name, $value->code, $layerName),
                        'schema_redefinition',
                        ['dimensionName' => $requestedDim->name, 'code' => $value->code, 'layerName' => $layerName],
                    );
                }
            }

            if ([] !== $newValues) {
                $additions[] = ['name' => $requestedDim->name, 'values' => $newValues];
            }
        }

        return $additions;
    }

    /**
     * Populate the in-memory layered schema — dimensions **and flows** — from the
     * code definition, with **no DB writes**. The cheap, every-request counterpart
     * to {@see bootstrap()}, whose slotspace upserts/syncs are version-gated and so
     * skipped in steady state (see BootstrapInvFlux). Without this, a request that
     * skips provisioning falls back to {@see tryLayeredSchema()}, which reconstructs
     * the layers from the DB with **dimensions only** — so a string-keyed flow
     * execution (e.g. a correction's `correction_writeoff_ctd`) fails with
     * "Flow not defined". Sets exactly what `bootstrap()` sets (the raw definition:
     * shared-ref dims as placeholders, resolved per call), so it's consistent
     * whether or not provisioning ran this request.
     *
     * @api
     */
    public function useSchema(LayeredSlotSpaceDefinition $definition): void
    {
        $this->layeredSchema = $definition;
    }

    /** Load the currently bootstrapped slot-space definition. */
    #[\Override]
    public function schema(): SlotSpaceDefinition
    {
        return $this->trySchema()
            ?? throw new SchemaException('InvFlux schema has not been bootstrapped yet.', 'schema_not_bootstrapped');
    }

    /**
     * Add or reactivate values in one existing dimension.
     *
     * @param list<DimensionValueDefinition> $values
     */
    #[\Override]
    public function addDimensionValues(string $dimensionName, array $values): void
    {
        ([] !== $values)
            || throw new ConfigurationException('At least one dimension value is required.', 'empty_added_dimension_values');

        $schema = $this->schema();
        $dimension = $this->dimensionByName($schema, $dimensionName);

        $mergedValuesByCode = [];
        foreach ($dimension->values as $value) {
            $mergedValuesByCode[$value->code] = $value;
        }
        $storedValuesByCode = $mergedValuesByCode;

        /** @var array<string, DimensionValueDefinition> $incomingByCode */
        $incomingByCode = [];
        foreach ($values as $value) {
            $incomingByCode[$value->code] = $value;
        }

        foreach ($values as $value) {
            if (!$value->active) {
                throw new ConfigurationException(
                    sprintf('Added dimension value "%s" must be active.', $value->code),
                    'inactive_added_dimension_value',
                    ['dimensionName' => $dimensionName, 'code' => $value->code],
                );
            }

            if (null !== $value->parentCode && !isset($mergedValuesByCode[$value->parentCode])) {
                throw new ConfigurationException(
                    sprintf('Parent value "%s" for "%s" does not exist in dimension "%s".', $value->parentCode, $value->code, $dimensionName),
                    'unknown_parent_dimension_value',
                    ['dimensionName' => $dimensionName, 'parentCode' => $value->parentCode, 'code' => $value->code],
                );
            }

            $this->assertDimensionValueCodeSitsUnderParent($dimensionName, $value);

            $existing = $mergedValuesByCode[$value->code] ?? null;
            if ($existing instanceof DimensionValueDefinition) {
                $this->assertAddDimensionValueDoesNotMutateExistingDefinition($dimensionName, $existing, $value);
            }

            $mergedValuesByCode[$value->code] = $value;
        }

        $this->assertNoAddressableValueGainsAChild($dimensionName, $values, $incomingByCode, $storedValuesByCode);

        $mergedValues = $this->sortedDimensionValues(array_values($mergedValuesByCode));
        // SharedRef dims have defaultValue '' (no static default); use the first merged value.
        $defaultValue = '' !== $dimension->defaultValue
            ? $dimension->defaultValue
            : ($mergedValues[0]->code ?? '');
        $updatedDimension = new DimensionDefinition(
            $dimension->name,
            $dimension->position,
            $mergedValues,
            $defaultValue,
            $dimension->kind,
            $dimension->collapseBehavior,
            $dimension->collapseTargetValue,
            $dimension->active,
            $dimension->metadata,
        );
        $dimensionId = $this->dimensionIdByName($dimensionName);

        $this->transactional(function () use ($dimensionId, $dimensionName, $values): void {
            $this->upsertDimensionValueRows($dimensionId, $values);
            $this->insertSchemaLedgerRow(
                eventType: 'dimension_values_added',
                payload: ['dimension' => $dimensionName, 'values' => array_map(static fn (DimensionValueDefinition $value): string => $value->code, $values)],
                actorId: null,
                refType: 'dimension_value',
                refId: null,
                recordedAt: new \DateTimeImmutable(),
            );
        });

        $updatedLayered = $this->buildUpdatedLayeredSchema($dimensionName, $updatedDimension);
        $this->syncSlotspaceRowsLayered($updatedLayered);
        $this->syncConfigStateLayered($updatedLayered);
        $this->layeredSchema = $updatedLayered;

        if (!$this->hasHierarchyDimension) {
            foreach ($values as $value) {
                if (null !== $value->parentCode) {
                    $this->hasHierarchyDimension = true;
                    break;
                }
            }
        }
    }

    /**
     * Demote one value into a child position and mint a new parent above it, taking the code it
     * vacates.
     *
     * **Nothing moves.** Slot identity is the slot's surrogate id, and both `inventory_state` and
     * `inventory_ledger` reference it rather than the code, so recoding the value re-reads its
     * entire history under the longer name: a movement written as `sup → oh` reads as
     * `sup → oh/main` afterwards. Neither table is touched. The write is the value's `code`,
     * `path`, `parent_id` and `level`, the same recode across its descendants, the `dim_*` column
     * and key of its slot rows, and one INSERT for the parent.
     *
     * The relabelling is retroactive and truthful — the location did not move, it acquired a
     * longer name — but it is invisible in the data, so `location_hierarchised` records it. That
     * row is what explains a movement rendering under a code minted after it.
     *
     * @see \Nandan108\InvFlux\Contracts\Inventory\SchemaManager::hierarchiseDimensionValue() for
     *      why both levels are stated by the caller rather than inferred
     */
    #[\Override]
    public function hierarchiseDimensionValue(
        string $dimensionName,
        string $code,
        string $demotedCode,
        ?string $demotedLevel,
        ?string $parentLevel,
        ?string $demotedName = null,
        ?string $parentName = null,
    ): void {
        $dimension = $this->dimensionByName($this->schema(), $dimensionName);
        $incumbent = $this->dimensionValueByName($dimension, $code);

        $incumbent->active || throw new SchemaException(
            sprintf('Dimension value "%s:%s" is inactive and cannot be hierarchised.', $dimensionName, $code),
            'hierarchise_inactive_dimension_value',
            ['dimensionName' => $dimensionName, 'code' => $code],
        );

        $segment = str_starts_with($demotedCode, $code.'/')
            ? substr($demotedCode, \strlen($code) + 1)
            : '';
        ('' !== $segment && !str_contains($segment, '/')) || throw new SchemaException(
            sprintf(
                'Cannot hierarchise "%s:%s" into "%s": exactly one parent is interposed, so the demoted code must be "%s" plus one segment. Re-parenting a value under a different branch is a separate operation.',
                $dimensionName,
                $code,
                $demotedCode,
                $code,
            ),
            'hierarchisation_target_not_one_level_below',
            ['dimensionName' => $dimensionName, 'code' => $code, 'demotedCode' => $demotedCode],
        );

        foreach ($dimension->values as $value) {
            $value->code !== $demotedCode || throw new SchemaException(
                sprintf('Cannot hierarchise "%s:%s" into "%s": that code is already registered.', $dimensionName, $code, $demotedCode),
                'hierarchisation_target_code_taken',
                ['dimensionName' => $dimensionName, 'code' => $code, 'demotedCode' => $demotedCode],
            );
        }

        // Two values at one level is the outcome this whole operation exists to prevent: level
        // matching is depth-independent, so a layer selecting that level would match parent and
        // child alike and report two places where the merchant has one. It is also what makes each
        // layer's choice below unambiguous — at most one of the two levels can be the one a
        // selector names.
        $demotedLevel !== $parentLevel || throw new SchemaException(
            sprintf(
                'Cannot hierarchise "%s:%s": the demoted value and the interposed parent would both be at level "%s". They address different grains, so they carry different levels.',
                $dimensionName,
                $code,
                $demotedLevel ?? '(none)',
            ),
            'hierarchisation_levels_not_distinct',
            ['dimensionName' => $dimensionName, 'code' => $code, 'level' => $demotedLevel],
        );

        $dimensionId = $this->dimensionIdByName($dimensionName);

        // Planned before the transaction opens: both of these read the *schema* — which layer
        // means which of the two values, and where each layer places this dimension in a slot key
        // — rather than the rows about to be written.
        $followsByLayerId = $this->slotFollowsDemotedValueByLayer($dimensionName, $demotedLevel, $parentLevel);
        $slotPlan = $this->plannedSlotRecodes($dimensionName, $code, $demotedCode, $followsByLayerId);

        $this->transactional(function () use (
            $dimensionId,
            $dimensionName,
            $code,
            $demotedCode,
            $demotedLevel,
            $parentLevel,
            $demotedName,
            $parentName,
            $slotPlan,
        ): void {
            // Order is forced by the unique key on (dimension_id, code): the incumbent has to
            // vacate the code before the interposed parent can take it.
            $incumbentRow = DimensionValue::findOne('dimension_id = ? AND code = ?', [$dimensionId, $code])
                ?? throw new SchemaException(
                    sprintf('Dimension value "%s:%s" vanished before it could be hierarchised.', $dimensionName, $code),
                    'unknown_dimension_value',
                    ['dimensionName' => $dimensionName, 'code' => $code],
                );

            $vacatedName = $incumbentRow->name;
            $vacatedOwnerKey = $incumbentRow->owner_key;
            $parentId = $incumbentRow->parent_id;

            $this->recodeDimensionValueSubtree($dimensionId, $code, $demotedCode);

            $interposed = DimensionValue::newWith([
                'dimension_id'  => $dimensionId,
                'code'          => $code,
                'name'          => $parentName ?? $vacatedName,
                // The parent inherits the vacated owner: it stands in the position the incumbent
                // held, and whoever registered that position still answers for it.
                'owner_key'     => $vacatedOwnerKey,
                'active'        => true,
                'metadata_json' => $this->json([]),
                'parent_id'     => $parentId,
                // It has a child by construction, so it is a grouping node, never a place.
                'addressable'   => false,
                'level'         => $parentLevel,
            ]);
            $interposed->save();

            $demotedAttributes = ['parent_id' => (int) $interposed->id, 'level' => $demotedLevel];
            if (null !== $demotedName) {
                $demotedAttributes['name'] = $demotedName;
            }
            DimensionValue::updateWhere(
                $demotedAttributes,
                'dimension_id = ? AND code = ?',
                [$dimensionId, $demotedCode],
            );

            $this->applySlotRecodes($dimensionName, $slotPlan['recodes']);
            $this->mintInterposedParentSlots($dimensionName, $slotPlan['interposed']);

            $this->insertSchemaLedgerRow(
                eventType: 'location_hierarchised',
                payload: [
                    'dimension'     => $dimensionName,
                    'code'          => $code,
                    'demoted_to'    => $demotedCode,
                    'demoted_level' => $demotedLevel,
                    'parent_level'  => $parentLevel,
                    'recoded_slots' => count($slotPlan['recodes']),
                ],
                actorId: null,
                refType: 'dimension_value',
                refId: null,
                recordedAt: new \DateTimeImmutable(),
            );
        });

        $this->hasHierarchyDimension = true;

        // Materialise the interposed parent's own slot rows wherever a layer's selector admits
        // it. SharedRef values are read from the DB at sync time, so the in-memory definition
        // needs no rebuild.
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        if (null !== $layered) {
            $this->syncSlotspaceRowsLayered($layered);
            $this->syncConfigStateLayered($layered);
        }
    }

    /** Move all quantities out of one dimension value, then disable it. */
    #[\Override]
    public function drainAndDisableDimensionValue(string $dimensionName, string $sourceValue, ?string $targetValue = null): void
    {
        $schema = $this->schema();
        $dimension = $this->dimensionByName($schema, $dimensionName);
        $source = $this->dimensionValueByName($dimension, $sourceValue);
        $source->active
            || throw new SchemaException(
                sprintf('Dimension value "%s:%s" is already inactive.', $dimensionName, $sourceValue),
                'dimension_value_already_inactive',
                ['dimensionName' => $dimensionName, 'value' => $sourceValue],
            );
        $sourceValue !== $dimension->defaultValue
            || throw new SchemaException(
                sprintf('Default value "%s" cannot be disabled.', $sourceValue),
                'cannot_disable_default_dimension_value',
                ['dimensionName' => $dimensionName, 'value' => $sourceValue],
            );

        /** @var array<int<0, max>|non-empty-string, non-empty-string|null>|null $resolvedTarget */
        $resolvedTarget = $this->resolveDrainTarget($dimension, $source, $targetValue);
        $sourceValueId = $this->dimensionValueIdByName($dimensionName, $sourceValue);
        $updatedDimension = $this->dimensionWithValueState($dimension, $sourceValue, false);
        $dimensionId = $this->dimensionIdByName($dimensionName);

        $this->ensureInternalMovementTypes();

        $this->transactional(function () use ($dimensionId, $dimensionName, $sourceValue, $sourceValueId, $resolvedTarget): void {
            $recordedAt = new \DateTimeImmutable();
            $targetName = null !== $resolvedTarget
                ? $this->requireNonEmptyString((string) reset($resolvedTarget), 'target_name')
                : 'nil';

            $this->insertSchemaLedgerRow(
                eventType: 'dimension_value_disabled',
                payload: [
                    'dimension' => $dimensionName,
                    'source'    => $sourceValue,
                    'target'    => $targetName,
                ],
                actorId: null,
                refType: 'dimension_value',
                refId: (string) $sourceValueId,
                recordedAt: $recordedAt,
            );

            $this->executeBatchFlowFromStorage(new StorageBatchFlowRequest(
                movementTypeOwnerKey: self::INTERNAL_MOVEMENT_OWNER,
                movementTypeCode: self::INTERNAL_MOVEMENT_DRAIN_VALUE,
                flow: Flow::define(
                    'dimension_value_disable',
                    static fn (Flow $flow) => $flow->move(
                        [$dimensionName => $sourceValue],
                        $resolvedTarget,
                    ),
                ),
                subjectIds: null,
                slotFilters: [$dimensionName => $sourceValue],
                params: [],
                // No inventory_ledger ref: the schema_change row's id is INT-keyed, but
                // arch-uuid-identity §5.5 reserves inventory_ledger.ref_id for UUID-keyed
                // documents. The drain audit link lives in the schema_ledger row itself
                // (which carries dimension/source/target context); a dedicated typed
                // `schema_change_id` column on inventory_ledger could re-establish the
                // direct link, if a future need warrants it.
                reference: null,
                actor: null,
                executionContext: [
                    'operation' => 'dimension_value_drain',
                    'dimension' => $dimensionName,
                    'source'    => $sourceValue,
                    'target'    => $targetName,
                ],
                recordedAt: $recordedAt,
            ));

            $this->deleteZeroQuantityRowsForDimensionValue($dimensionName, $sourceValue);
            $this->disableDimensionValueRow($dimensionId, $sourceValue);
        });

        $updatedLayered = $this->buildUpdatedLayeredSchema($dimensionName, $updatedDimension);
        $this->syncSlotspaceRowsLayered($updatedLayered);
        $this->syncConfigStateLayered($updatedLayered);
        $this->layeredSchema = $updatedLayered;
    }

    /** Return the configured storage quantity scale. */
    #[\Override]
    public function quantityScale(): int
    {
        $this->createBaseTables();

        $row = ConfigState::findOne('id = ?', [self::CONFIG_STATE_ID]);
        null !== $row || throw new PersistenceException(
            'Expected one config_state row for quantity scale.',
            'missing_config_state_row',
        );

        return $row->quantity_scale;
    }

    /**
     * Record the assembled flow topology for diagnostics.
     *
     * **Write-only from the model's point of view.** Nothing reads this column back to decide
     * anything: the topology is assembled from code on every boot, so a stored copy can only ever
     * be a photograph. Its job is to answer, on a merchant's site, the question that is otherwise
     * archaeology across three plugins — which add-on owns this event, and what does its movement
     * type carry with it.
     *
     * Idempotent and safe to race: concurrent boots that computed the same topology write the same
     * bytes, and one that computed a different one is by definition newer.
     *
     * **Refuses instead of failing when the column is not there yet, and that refusal is the point.**
     * This runs at `plugins_loaded`, every boot on which the topology changed, and it is *only*
     * diagnostics — so it must never be the reason a site does not come up. It can arrive ahead of
     * its own column in two ways: a schema convergence that has not run yet (in development the
     * schema marker keys on the plugin version, so a column added to a managed Record does not
     * converge until that version moves), or one that ran and failed. Writing blind turns either
     * into an aborted boot *and* a database error emitted before headers, which is how a
     * write-only photograph takes down login. Returning `false` lets the caller leave its marker
     * unset, so the snapshot is simply retried on the first boot after the schema catches up.
     *
     * @param array<string, mixed> $topology
     *
     * @return bool whether the topology was recorded; `false` means the column does not exist yet
     *
     * @api Called by the adapter's bootstrap.
     */
    public function recordFlowTopology(array $topology): bool
    {
        if (!$this->configStateHasFlowTopologyColumn()) {
            return false;
        }

        $this->createBaseTables();

        ConfigState::updateWhere(
            ['flow_topology_json' => json_encode($topology, JSON_THROW_ON_ERROR)],
            'id = ?',
            [self::CONFIG_STATE_ID],
        );

        return true;
    }

    /**
     * Whether `invflux_config_state` has caught up with {@see ConfigState::$flow_topology_json}.
     *
     * Asked against `information_schema` rather than by attempting the write and handling the
     * failure: a failed write is reported by the host's database layer *before* anything of ours
     * can intercept it, so by the time an exception arrives the error has already been printed.
     * The only way not to emit it is not to issue it.
     *
     * Costs one row read, and only on a boot where the topology actually changed — never in steady
     * state, where the caller's marker comparison short-circuits before reaching here.
     */
    private function configStateHasFlowTopologyColumn(): bool
    {
        return (int) $this->session->fetchScalar(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = :table
                AND column_name = :column',
            ['table' => $this->table(self::TABLE_CONFIG_STATE), 'column' => 'flow_topology_json'],
        ) > 0;
    }

    /** Set the storage quantity scale before any persisted data exists. */
    #[\Override]
    public function setQuantityScale(int $scale): void
    {
        $this->assertValidScale($scale);
        $this->createBaseTables();

        $currentScale = $this->quantityScale();
        if ($currentScale === $scale) {
            return;
        }

        $this->assertStoreIsEmptyForScaleChange();

        ConfigState::updateWhere(['quantity_scale' => $scale], 'id = ?', [self::CONFIG_STATE_ID]);

        $this->insertSchemaLedgerRow(
            eventType: 'quantity_scale_set',
            payload: ['from' => $currentScale, 'to' => $scale],
            actorId: null,
            refType: null,
            refId: null,
            recordedAt: new \DateTimeImmutable(),
        );
    }

    /** Migrate persisted quantities to a new storage scale. */
    #[\Override]
    public function migrateQuantityScale(int $targetScale): void
    {
        $this->assertValidScale($targetScale);
        $this->createBaseTables();

        $currentScale = $this->quantityScale();
        if ($currentScale === $targetScale) {
            return;
        }

        $this->assertNoSubjectScaleOverrides();

        $delta = $targetScale - $currentScale;
        $factor = (int) (10 ** abs($delta));
        $recordedAt = new \DateTimeImmutable();

        $this->transactional(function () use ($currentScale, $targetScale, $delta, $factor, $recordedAt): void {
            if ($delta < 0) {
                $this->assertScaleDecreaseIsSafe($factor);
                $this->divideQuantityColumns($factor);
            } else {
                $this->multiplyQuantityColumns($factor);
            }

            ConfigState::updateWhere(['quantity_scale' => $targetScale], 'id = ?', [self::CONFIG_STATE_ID]);

            $this->insertSchemaLedgerRow(
                eventType: 'quantity_scale_changed',
                payload: ['from' => $currentScale, 'to' => $targetScale],
                actorId: null,
                refType: null,
                refId: null,
                recordedAt: $recordedAt,
            );
        });
    }

    /**
     * Register the movement types owned by one plugin or subsystem.
     *
     * @param list<MovementTypeDefinition> $movementTypes
     */
    #[\Override]
    public function registerMovementTypes(string $ownerKey, array $movementTypes): void
    {
        ('' !== $ownerKey)
            || throw new ConfigurationException('Movement type owner key must be a non-empty string.', 'empty_movement_type_owner_key');
        (strlen($ownerKey) <= 15)
            || throw new ConfigurationException('Movement type owner key must be at most 15 characters long.', 'movement_type_owner_key_too_long');

        $this->createBaseTables();

        // Burn-free upsert on the (owner_key, code) UNIQUE: existing rows update in place, new
        // ones bulk-insert, and re-registering the same definitions allocates no AUTO_INCREMENT.
        // (A plain INSERT IGNORE / ON-DUPLICATE-KEY would tick the counter on every collision,
        // exhausting this small domain when init hooks fire on every request.)
        $records = [];
        foreach ($movementTypes as $movementType) {
            $records[] = MovementType::newWith([
                'owner_key'   => $ownerKey,
                'code'        => $movementType->code,
                'name'        => $movementType->name,
                'description' => $movementType->description,
                'active'      => $movementType->active,
            ]);
        }
        (new RecordSet($records))->upsertAllByUniqueKey('uniq_owner_code');
    }

    /**
     * Register the actor types allowed in inventory and meta-ledger entries.
     *
     * @param list<ActorTypeDefinition> $actorTypes
     */
    #[\Override]
    public function registerActorTypes(array $actorTypes): void
    {
        $this->createBaseTables();
        $this->actorTypeIdsByCode = null;

        // Burn-free upsert on the `code` UNIQUE — see registerMovementTypes() for the rationale.
        $records = [];
        foreach ($actorTypes as $actorType) {
            $records[] = ActorTypeRecord::newWith([
                'code'        => $actorType->code,
                'name'        => $actorType->name,
                'description' => $actorType->description,
                'active'      => $actorType->active,
            ]);
        }
        (new RecordSet($records))->upsertAllByUniqueKey('uniq_actor_type_code');
    }

    /** Append one configuration or schema audit event to the meta-ledger. */
    #[\Override]
    public function recordMetaEvent(MetaEvent $event): void
    {
        $this->createBaseTables();

        $actorDbId = null !== $event->actor
            ? $this->resolveActorId($event->actor->typeCode, $event->actor->actorId)
            : null;

        $this->insertSchemaLedgerRow(
            eventType: $event->eventType,
            payload: $event->payload,
            actorId: $actorDbId,
            refType: $event->reference?->type,
            refId: $this->normalizeNullableUnsignedInteger($event->reference?->idAsString()),
            recordedAt: $event->recordedAt(),
        );
    }

    /** Persist one batch movement atomically into authoritative state and the inventory ledger. */
    #[\Override]
    public function persistBatch(PersistBatchMovement $movement): PersistedBatchMovement
    {
        $slotIds = $this->slotIdsByKey();
        [$deltaRows, $affectedSubjects] = $this->batchDeltaRows($movement, $slotIds);
        if ([] === $deltaRows) {
            return new PersistedBatchMovement(true, 0, 0, 0, $movement->recordedAt());
        }

        $movementTypeId = $this->resolveMovementTypeId($movement->movementTypeOwnerKey, $movement->movementTypeCode);
        $actorDbId = null !== $movement->actor
            ? $this->resolveActorId($movement->actor->typeCode, $movement->actor->actorId)
            : null;
        $refTypeId = $this->resolveRefTypeIdStrict($movement->reference?->type);
        $surfaceDbId = null !== $movement->surface
            ? $this->resolveSurfaceId($movement->surface->typeCode, $movement->surface->ref)
            : null;
        $outcome = $this->persistDeltaRows(
            $deltaRows,
            movementTypeOwnerKey: $movement->movementTypeOwnerKey,
            movementTypeCode: $movement->movementTypeCode,
            referenceType: $movement->reference?->type,
            referenceId: $movement->reference?->idAsString(),
            actorTypeCode: $movement->actor?->typeCode,
            actorId: $movement->actor?->actorId,
            recordedAt: $movement->recordedAt(),
            surface: $movement->surface,
            insertLedgerRows: fn (array $_lockedBalanceByKey): int => $this->insertLedgerRows($movement, $slotIds, $movementTypeId, $actorDbId, $refTypeId, $surfaceDbId),
        );

        return new PersistedBatchMovement(
            ok: $outcome->ok,
            affectedSubjects: $outcome->ok ? count($affectedSubjects) : 0,
            affectedSlots: $outcome->affectedSlots,
            insertedLedgerRows: $outcome->insertedLedgerRows,
            recordedAt: $movement->recordedAt(),
            conflicts: $outcome->conflicts,
        );
    }

    /** Persist one single-subject movement into authoritative state and the inventory ledger. */
    #[\Override]
    public function persist(PersistMovement $movement): PersistedMovement
    {
        $slotIds = $this->slotIdsByKey();
        $deltaRows = $this->singleDeltaRows($movement, $slotIds);
        if ([] === $deltaRows) {
            return new PersistedMovement(true, 0, 0, $movement->recordedAt());
        }

        $movementTypeId = $this->resolveMovementTypeId($movement->movementTypeOwnerKey, $movement->movementTypeCode);
        $actorDbId = null !== $movement->actor
            ? $this->resolveActorId($movement->actor->typeCode, $movement->actor->actorId)
            : null;
        $refTypeId = $this->resolveRefTypeIdStrict($movement->reference?->type);
        $surfaceDbId = null !== $movement->surface
            ? $this->resolveSurfaceId($movement->surface->typeCode, $movement->surface->ref)
            : null;
        $outcome = $this->persistDeltaRows(
            $deltaRows,
            movementTypeOwnerKey: $movement->movementTypeOwnerKey,
            movementTypeCode: $movement->movementTypeCode,
            referenceType: $movement->reference?->type,
            referenceId: $movement->reference?->idAsString(),
            actorTypeCode: $movement->actor?->typeCode,
            actorId: $movement->actor?->actorId,
            recordedAt: $movement->recordedAt(),
            surface: $movement->surface,
            insertLedgerRows: fn (array $_lockedBalanceByKey): int => $this->insertSingleLedgerRows($movement, $slotIds, $movementTypeId, $actorDbId, $refTypeId, $surfaceDbId),
        );

        return new PersistedMovement(
            ok: $outcome->ok,
            affectedSlots: $outcome->affectedSlots,
            insertedLedgerRows: $outcome->insertedLedgerRows,
            recordedAt: $movement->recordedAt(),
            conflicts: $outcome->conflicts,
        );
    }

    /**
     * Read current balances for one subject, optionally filtered by slot dimensions.
     *
     * @param TSlotFilters $slotFilters
     *
     * @return list<InventoryBalance>
     */
    #[\Override]
    public function inventoryBalances(SubjectId $subjectId, array $slotFilters = []): array
    {
        $schema = $this->schema();
        $dimensionSelect = $this->slotDimensionSelectSql($schema);
        $filterSql = $this->slotFilterSql($schema, $slotFilters, 'ss');

        $dbRows = $this->fetchAllRows(sprintf(
            'SELECT s.slot_id, s.quantity, ss.slot_key, %s
             FROM %s s
                JOIN %s ss ON ss.id = s.slot_id
             WHERE s.subject_id = :subject_id
               AND ss.active = 1%s
             ORDER BY s.slot_id',
            $dimensionSelect,
            $this->table(self::TABLE_INVENTORY_STATE),
            $this->table(self::TABLE_SLOTSPACE),
            $filterSql,
        ), [
            'subject_id' => $subjectId->id,
            ...$this->slotFilterParams($slotFilters),
        ]);

        $rows = [];
        foreach ($dbRows as $row) {
            $slotKey = $this->requireStringField($row, 'slot_key');
            $rows[] = new InventoryBalance(
                $subjectId,
                $this->requireIntField($row, 'slot_id'),
                $slotKey,
                $this->extractSlotDimensions($schema, $row),
                (string) $this->requireScalarField($row, 'quantity'),
            );
        }

        return $rows;
    }

    /**
     * Total on-hand quantity per subject for several subjects in one query — the bulk
     * counterpart of summing {@see inventoryBalances()} per subject. One `IN (…)` +
     * `GROUP BY`, scoped to the same active slots as inventoryBalances(); subjects with no
     * rows are omitted from the result.
     *
     * @param list<SubjectId> $subjectIds
     *
     * @return array<int, int> subject_id => summed on-hand quantity
     */
    #[\Override]
    public function onHandTotalsForSubjects(array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($subjectIds as $index => $subjectId) {
            $name = 'subject_id_'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $subjectId->id;
        }

        $rows = $this->fetchAllRows(sprintf(
            'SELECT s.subject_id, SUM(s.quantity) AS on_hand
             FROM %s s
                JOIN %s ss ON ss.id = s.slot_id
             WHERE ss.active = 1
               AND s.subject_id IN (%s)
             GROUP BY s.subject_id',
            $this->table(self::TABLE_INVENTORY_STATE),
            $this->table(self::TABLE_SLOTSPACE),
            implode(', ', $placeholders),
        ), $params);

        $totals = [];
        foreach ($rows as $row) {
            $totals[$this->requireIntField($row, 'subject_id')] = (int) $this->requireScalarField($row, 'on_hand');
        }

        return $totals;
    }

    /**
     * Count the subject's active slots whose quantity is non-zero — across every dimension
     * (`oh/*`, `trs/*`, `sup`, `ctd`), not just on-hand. Backs the deletion guard's
     * `non_zero_inventory` check. One aggregate query.
     */
    #[\Override]
    public function countNonZeroSlots(SubjectId $subjectId): int
    {
        return $this->fetchCountFromSql(
            sprintf(
                'SELECT COUNT(*)
                 FROM %s s
                    JOIN %s ss ON ss.id = s.slot_id
                 WHERE s.subject_id = :subject_id
                   AND ss.active = 1
                   AND s.quantity <> 0',
                $this->table(self::TABLE_INVENTORY_STATE),
                $this->table(self::TABLE_SLOTSPACE),
            ),
            ['subject_id' => $subjectId->id],
        );
    }

    /**
     * Read ledger rows for one subject, optionally filtered by slot dimensions.
     *
     * @param TSlotFilters $slotFilters
     *
     * @return list<LedgerRecord>
     */
    #[\Override]
    public function ledger(SubjectId $subjectId, array $slotFilters = [], int $limit = 100, int $offset = 0): array
    {
        $schema = $this->schema();
        $fromSelect = $this->slotDimensionSelectSql($schema, 'fs', 'from_');
        $toSelect = $this->slotDimensionSelectSql($schema, 'ts', 'to_');
        $params = ['subject_id' => $subjectId->id, 'limit_count' => $limit, 'offset_count' => $offset];
        $filterSql = $this->ledgerFilterSql($schema, $slotFilters, $params);

        $sql = sprintf(
            'SELECT l.id, l.subject_id, l.quantity,
                l.initial_from, l.initial_to,
                rt.code AS ref_type, l.ref_id,
                l.recorded_at,
                mt.owner_key AS movement_owner_key, mt.code AS movement_code,
                at.code AS actor_type_code, a.actor_ref AS actor_id,
                fs.slot_key AS from_slot_key, %s,
                ts.slot_key AS to_slot_key, %s
             FROM %s l
                JOIN %s mt ON mt.id = l.movement_type_id
                LEFT JOIN %s rt ON rt.id = l.ref_type_id
                LEFT JOIN %s a ON a.id = l.actor_id
                LEFT JOIN %s at ON at.id = a.actor_type_id
                LEFT JOIN %s fs ON fs.id = l.from_slot_id
                LEFT JOIN %s ts ON ts.id = l.to_slot_id
             WHERE l.subject_id = :subject_id%s
             ORDER BY l.id DESC
             LIMIT :limit_count OFFSET :offset_count',
            $fromSelect,
            $toSelect,
            $this->table(self::TABLE_INVENTORY_LEDGER),
            $this->table(self::TABLE_MOVEMENT_TYPES),
            $this->table(self::TABLE_REF_TYPES),
            $this->table(self::TABLE_ACTORS),
            $this->table(self::TABLE_ACTOR_TYPES),
            $this->table(self::TABLE_SLOTSPACE),
            $this->table(self::TABLE_SLOTSPACE),
            $filterSql,
        );

        $rows = [];
        foreach ($this->fetchAllRows($sql, $params) as $row) {
            $fromSlotKey = $this->nullableNonEmptyStringField($row, 'from_slot_key');
            $toSlotKey = $this->nullableNonEmptyStringField($row, 'to_slot_key');
            $rows[] = new LedgerRecord(
                id: $this->requireStringField($row, 'id'),
                subjectId: new SubjectId($this->requireIntField($row, 'subject_id')),
                movementTypeOwnerKey: $this->requireStringField($row, 'movement_owner_key'),
                movementTypeCode: $this->requireStringField($row, 'movement_code'),
                fromSlotKey: $fromSlotKey,
                fromDimensions: null === $fromSlotKey ? null : $this->extractSlotDimensions($schema, $row, 'from_'),
                toSlotKey: $toSlotKey,
                toDimensions: null === $toSlotKey ? null : $this->extractSlotDimensions($schema, $row, 'to_'),
                quantity: $this->requireIntField($row, 'quantity'),
                initialFrom: null === $this->nullableScalarField($row, 'initial_from') ? null : $this->requireIntField($row, 'initial_from'),
                initialTo: null === $this->nullableScalarField($row, 'initial_to') ? null : $this->requireIntField($row, 'initial_to'),
                referenceType: $this->nullableNonEmptyStringField($row, 'ref_type'),
                referenceId: null === $this->nullableScalarField($row, 'ref_id') ? null : (string) $this->requireScalarField($row, 'ref_id'),
                actorTypeCode: $this->nullableNonEmptyStringField($row, 'actor_type_code'),
                actorId: $this->nullableNonEmptyStringField($row, 'actor_id'),
                recordedAt: new \DateTimeImmutable($this->requireStringField($row, 'recorded_at')),
            );
        }

        return $rows;
    }

    /**
     * Seed the singleton config row.
     *
     * Every table this store owns is now Record-backed and listed in {@see INVENTORY_RECORDS},
     * created by the schema installer before this runs in an order derived from the declared
     * foreign keys. `inventory_state` was the last hold-out: its composite `(subject_id, slot_id)`
     * primary key had no way to be declared, so its DDL was hand-written and therefore invisible
     * to the differ. attrecord's `#[PrimaryKey(columns:)]` closed that, and
     * {@see InventoryState} now describes it —
     * describes only: the table's reads and writes stay raw SQL here, which is what the composite
     * key buys (it is the clustering key behind the hot range-scan read).
     */
    private function createBaseTables(): void
    {
        // The config-state singleton. Insert-or-ignore: an existing row keeps its scale and
        // dimension set, so re-running bootstrap never resets a configured store.
        (new RecordSet([ConfigState::newWith([
            'id'                     => self::CONFIG_STATE_ID,
            'quantity_scale'         => 0,
            'active_dimensions_json' => '[]',
        ])]))->insertAll(onConflict: OnConflict::Ignore);
    }

    /**
     * Reconstruct the layered slot-space definition from the DB.
     *
     * Returns null when no schema has been bootstrapped yet.
     */
    private function tryLayeredSchema(): ?LayeredSlotSpaceDefinition
    {
        if (null !== $this->layeredSchema) {
            return $this->layeredSchema;
        }

        $configRow = ConfigState::findOne('id = ?', [self::CONFIG_STATE_ID]);
        $layersJson = $configRow?->layers_json;

        if (null === $layersJson) {
            $flat = $this->trySchema();

            return null !== $flat ? LayeredSlotSpaceDefinition::define(['default' => $flat]) : null;
        }

        /** @psalm-var array<string, array<string, array<string, mixed>>> $layerDimensionNames */
        $layerDimensionNames = $this->decodeArray($layersJson);
        if ([] === $layerDimensionNames) {
            return null;
        }

        $allDimensions = $this->loadAllDimensionDefinitions();
        if ([] === $allDimensions) {
            return null;
        }

        /** @var array<string, DimensionDefinition> $byName */
        $byName = [];
        foreach ($allDimensions as $dim) {
            $byName[$dim->name] = $dim;
        }

        $layers = [];
        foreach ($layerDimensionNames as $layerName => $layerConfig) {
            $dims = [];

            foreach ($layerConfig as $dimName => $options) {
                $concrete = $byName[$dimName] ?? null;
                if (null === $concrete) {
                    continue;
                }

                if (true === ($options['sharedRef'] ?? false)) {
                    /** @var array<string, mixed> $selectorData */
                    $selectorData = $options['valueSelector'] ?? [];
                    [] !== $selectorData
                        || throw new SchemaException(
                            sprintf('SharedRef dimension "%s" is missing valueSelector in stored layer config.', $dimName),
                            'missing_shared_ref_value_selector',
                            ['dimensionName' => $dimName, 'layerName' => $layerName],
                        );
                    $dims[] = DimensionDefinition::sharedRef(
                        $dimName,
                        $concrete->position,
                        DimensionValueSelector::fromArray($selectorData),
                    );
                } else {
                    $dims[] = $concrete;
                }
            }

            if ([] !== $dims) {
                $layers[$layerName] = new SlotSpaceDefinition($layerName, $dims);
            }
        }

        if ([] === $layers) {
            return null;
        }

        $this->layeredSchema = LayeredSlotSpaceDefinition::define($layers);

        return $this->layeredSchema;
    }

    /**
     * Load all dimension definitions from the DB (combined across all layers).
     *
     * @return list<DimensionDefinition>
     */
    private function loadAllDimensionDefinitions(): array
    {
        try {
            $dimensionRows = $this->session->fetchAll(sprintf(
                'SELECT d.id, d.name, d.position_index, d.kind, d.collapse_behavior, d.collapse_target_value,
                        d.active, d.metadata_json, dv.code AS default_value
                 FROM %s d
                 LEFT JOIN %s dv ON dv.id = d.default_value
                 ORDER BY d.position_index',
                $this->table(self::TABLE_DIMENSIONS),
                $this->table(self::TABLE_DIMENSION_VALUES),
            ));
        } catch (PersistenceException) {
            return [];
        }

        $dimensions = [];
        foreach ($dimensionRows as $row) {
            $values = [];
            foreach ($this->fetchAllRows(sprintf(
                'SELECT dv.code, dv.name, dv.owner_key, dv.active,
                        dv.removal_target_code, dv.metadata_json, dv.addressable, dv.level,
                        parent_dv.code AS parent_code
                 FROM %s dv
                 LEFT JOIN %s parent_dv ON parent_dv.id = dv.parent_id
                 WHERE dv.dimension_id = :dimension_id
                 ORDER BY dv.code',
                $this->table(self::TABLE_DIMENSION_VALUES),
                $this->table(self::TABLE_DIMENSION_VALUES),
            ), ['dimension_id' => $this->requireIntField($row, 'id')]) as $valueRow) {
                $values[] = new DimensionValueDefinition(
                    code: $this->requireStringField($valueRow, 'code'),
                    name: $this->nullableNonEmptyStringField($valueRow, 'name'),
                    ownerKey: $this->requireStringField($valueRow, 'owner_key'),
                    active: (bool) $this->requireScalarField($valueRow, 'active'),
                    removalTargetCode: $this->nullableNonEmptyStringField($valueRow, 'removal_target_code'),
                    metadata: $this->decodeAssoc($this->nullableNonEmptyStringField($valueRow, 'metadata_json')),
                    parentCode: $this->nullableNonEmptyStringField($valueRow, 'parent_code'),
                    addressable: (bool) $this->requireScalarField($valueRow, 'addressable'),
                    level: $this->nullableNonEmptyStringField($valueRow, 'level'),
                );
            }

            $dimName = $this->requireNonEmptyString($this->requireStringField($row, 'name'), 'name');

            if ([] === $values && null === $this->nullableNonEmptyStringField($row, 'default_value')) {
                // Dimension is a sharedRef placeholder — it has no static values (they are
                // registered at runtime via addDimensionValues). Reconstruct as sharedRef so
                // the constructor's zero-values guard does not throw.
                $dimensions[] = DimensionDefinition::sharedRef(
                    $dimName,
                    $this->requireIntField($row, 'position_index'),
                    DimensionValueSelector::root(),
                );
                continue;
            }

            $dimensions[] = new DimensionDefinition(
                $dimName,
                $this->requireIntField($row, 'position_index'),
                $values,
                $this->nullableNonEmptyStringField($row, 'default_value')
                    ?? $this->requireNonEmptyString($values[0]->code ?? '', 'default_value'),
                DimensionKind::from($this->requireStringField($row, 'kind')),
                CollapseBehavior::from($this->requireStringField($row, 'collapse_behavior')),
                $this->nullableNonEmptyStringField($row, 'collapse_target_value'),
                (bool) $this->requireScalarField($row, 'active'),
                $this->decodeAssoc($this->nullableNonEmptyStringField($row, 'metadata_json')),
            );
        }

        return $dimensions;
    }

    /** Upsert layer rows in invflux_layers for all layers in the definition. */
    private function upsertLayerRows(LayeredSlotSpaceDefinition $def): void
    {
        // Burn-free upsert on the `slug` UNIQUE. The layers table has a small SMALLINT
        // AUTO_INCREMENT id and bootstrap() runs on every request; an INSERT IGNORE /
        // ON-DUPLICATE-KEY would bump the counter on every collision and exhaust it.
        // Matches registerActorTypes / registerMovementTypes.
        $records = [];
        foreach ($def->layers as $slug => $layerDef) {
            $records[] = Layer::newWith(['slug' => $slug, 'name' => $layerDef->name]);
        }
        (new RecordSet($records))->upsertAllByUniqueKey('uniq_layer_slug');
    }

    /** Return the primary key of one layer by its slug; throws if not found. */
    private function layerIdBySlug(string $slug): int
    {
        $id = Layer::findOne('slug = ?', [$slug])?->id;

        null !== $id
            || throw new SchemaException(
                sprintf('Layer "%s" has not been bootstrapped yet.', $slug),
                'unknown_layer',
                ['layer' => $slug],
            );

        return $id;
    }

    /**
     * Return an updated layered schema with one dimension replaced across all layers.
     *
     * Only the layer that owns the given dimension is modified.
     */
    private function buildUpdatedLayeredSchema(string $dimensionName, DimensionDefinition $updatedDimension): LayeredSlotSpaceDefinition
    {
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        null !== $layered
            || throw new SchemaException('InvFlux schema has not been bootstrapped yet.', 'schema_not_bootstrapped');

        $newLayers = $layered->layers;
        foreach ($newLayers as $layerName => $layerDef) {
            foreach ($layerDef->dimensions as $dim) {
                if ($dim->name === $dimensionName) {
                    // SharedRef dimensions load their values from DB at sync time;
                    // leave the in-memory definition unchanged.
                    if (!$dim->isSharedRef) {
                        $newLayers[$layerName] = $this->schemaWithUpdatedDimension($layerDef, $updatedDimension);
                    }
                    break; // found in this layer; check remaining layers
                }
            }
        }

        return LayeredSlotSpaceDefinition::define($newLayers);
    }

    /**
     * Synchronize slotspace rows for all layers in a layered definition.
     *
     * Marks rows as inactive that don't belong to any active layer, but does not
     * delete them, so they can be reactivated if the layer reappears.
     *
     * Concurrency note: the deactivation UPDATE acquires X-locks on every
     * matched slotspace row. Under heavy concurrent traffic (e.g. 100 VUs
     * each running bootstrap() on container init), this can deadlock with
     * the engine's FK-validation S-locks on those same rows from
     * `inventory_ledger` INSERTs. We mitigate by counting first and only
     * issuing the UPDATE when there's actually drift to repair — in the
     * steady-state production case (schema stable), every concurrent
     * bootstrap short-circuits past the UPDATE entirely.
     */
    private function syncSlotspaceRowsLayered(LayeredSlotSpaceDefinition $def): void
    {
        foreach ($def->layers as $slug => $layerDef) {
            $this->syncSlotspaceRowsForLayer($slug, $layerDef);
        }

        $activeSlugs = array_keys($def->layers);
        $placeholders = implode(', ', array_fill(0, count($activeSlugs), '?'));

        // Count-first: skip the deactivation UPDATE when no active orphan
        // rows exist. The SELECT is a cheap index scan via the FK; the
        // UPDATE would otherwise X-lock every row in the table.
        $orphan = $this->session->fetchScalar(sprintf(
            'SELECT 1 FROM %s ss
             LEFT JOIN %s l ON l.id = ss.layer_id
             WHERE ss.active = 1
               AND (l.slug IS NULL OR l.slug NOT IN (%s))
             LIMIT 1',
            $this->table(self::TABLE_SLOTSPACE),
            $this->table(self::TABLE_LAYERS),
            $placeholders,
        ), $activeSlugs);

        if (null === $orphan) {
            return;
        }

        $this->session->exec(sprintf(
            'UPDATE %s ss
             LEFT JOIN %s l ON l.id = ss.layer_id
             SET ss.active = 0
             WHERE l.slug IS NULL OR l.slug NOT IN (%s)',
            $this->table(self::TABLE_SLOTSPACE),
            $this->table(self::TABLE_LAYERS),
            $placeholders,
        ), $activeSlugs);
    }

    /**
     * Return a copy of the SlotSpaceDefinition with sharedRef dimensions replaced by
     * concrete ones loaded from the DB. Called before slot space compilation.
     */
    private function resolveSharedRefDimensions(SlotSpaceDefinition $def): SlotSpaceDefinition
    {
        $needsResolution = false;
        foreach ($def->dimensions as $dim) {
            if ($dim->isSharedRef) {
                $needsResolution = true;
                break;
            }
        }

        if (!$needsResolution) {
            return $def;
        }

        $resolvedDims = array_map(function (DimensionDefinition $dim): DimensionDefinition {
            if (!$dim->isSharedRef) {
                return $dim;
            }

            // Load active values for this dimension from the DB filtered by valueSelector:
            //   Root   → only values with parent_id IS NULL
            //   Level  → only values tagged with a specific structural level name
            //   Levels → values tagged with any of several level names — one layer addressing
            //            several kinds of place at the same grain, not several depths
            //   Leaf   → only addressable leaf values (addressable = 1)
            // Level matching is deliberately depth-independent: a value keeps matching its level
            // however many tiers are later inserted above or below it.
            $selector = $dim->valueSelector ?? DimensionValueSelector::root();
            $valueParams = ['name' => $dim->name];

            $levelPlaceholders = [];
            foreach ($selector->levels as $index => $level) {
                $placeholder = 'selector_level_'.$index;
                $levelPlaceholders[] = ':'.$placeholder;
                $valueParams[$placeholder] = $level;
            }

            $valueFilter = match ($selector->kind) {
                DimensionValueSelectorKind::Root   => 'AND dv.parent_id IS NULL',
                DimensionValueSelectorKind::Level  => 'AND dv.level = :selector_level',
                DimensionValueSelectorKind::Levels => sprintf('AND dv.level IN (%s)', implode(', ', $levelPlaceholders)),
                DimensionValueSelectorKind::Leaf   => 'AND dv.addressable = 1',
            };
            if (DimensionValueSelectorKind::Level === $selector->kind) {
                $valueParams['selector_level'] = $selector->level;
            }

            $valueRows = $this->fetchAllRows(sprintf(
                'SELECT dv.code, dv.name, dv.owner_key, dv.active,
                        dv.removal_target_code, dv.metadata_json, dv.addressable, dv.level,
                        parent_dv.code AS parent_code
                 FROM %s d
                 JOIN %s dv ON dv.dimension_id = d.id
                 LEFT JOIN %s parent_dv ON parent_dv.id = dv.parent_id
                 WHERE d.name = :name AND dv.active = 1 %s
                 ORDER BY dv.code',
                $this->table(self::TABLE_DIMENSIONS),
                $this->table(self::TABLE_DIMENSION_VALUES),
                $this->table(self::TABLE_DIMENSION_VALUES),
                $valueFilter,
            ), $valueParams);

            if ([] === $valueRows) {
                return $dim;
            }

            $values = array_map(
                fn (array $row): DimensionValueDefinition => new DimensionValueDefinition(
                    code: $this->requireStringField($row, 'code'),
                    name: $this->nullableNonEmptyStringField($row, 'name'),
                    ownerKey: $this->requireStringField($row, 'owner_key'),
                    active: (bool) $this->requireScalarField($row, 'active'),
                    removalTargetCode: $this->nullableNonEmptyStringField($row, 'removal_target_code'),
                    metadata: $this->decodeAssoc($this->nullableNonEmptyStringField($row, 'metadata_json')),
                    parentCode: $this->nullableNonEmptyStringField($row, 'parent_code'),
                    addressable: (bool) $this->requireScalarField($row, 'addressable'),
                    level: $this->nullableNonEmptyStringField($row, 'level'),
                ),
                $valueRows,
            );

            // Prefer the declared default over code order. `$values[0]` is deterministic (the
            // query orders by code) but semantically arbitrary — registering `oh/dock1` beside
            // `oh/main` would silently move the default — so it is the last resort, not the first.
            //
            // One default is declared per dimension, at the deepest grain, and each layer answers
            // by projecting it onto the values its own selector admits: the declared value if this
            // layer addresses it, otherwise its nearest ancestor that this layer does address. A
            // bin-level default projects to the warehouse containing it, so the two layers cannot
            // disagree about which warehouse the default bin is in. Because a code is its full
            // path, that walk is the code with its last segment removed, repeatedly.
            $selectableCodes = array_map(
                static fn (DimensionValueDefinition $value): string => $value->code,
                $values,
            );

            $projected = null;
            for (
                $candidate = $this->declaredDimensionDefault($dim->name);
                null !== $candidate && '' !== $candidate;
                $candidate = strrpos($candidate, '/') > 0 ? substr($candidate, 0, (int) strrpos($candidate, '/')) : null
            ) {
                if (\in_array($candidate, $selectableCodes, true)) {
                    $projected = $candidate;
                    break;
                }
            }

            return $dim->withResolvedValues($values, $projected ?? $values[0]->code);
        }, $def->dimensions);

        return new SlotSpaceDefinition($def->name, $resolvedDims, $def->metadata, $def->flows, $def->rules);
    }

    /** Synchronize slotspace rows for one named layer. */
    private function syncSlotspaceRowsForLayer(string $layerSlug, SlotSpaceDefinition $definition): void
    {
        $definition = $this->resolveSharedRefDimensions($definition);

        $layerId = $this->layerIdBySlug($layerSlug);

        $activeDimensions = $definition->activeDimensions();

        // If any active dimension has no values yet (e.g. sharedRef not yet populated),
        // the slot space is incomplete. Deactivate all existing rows and bail out.
        foreach ($activeDimensions as $dim) {
            if ([] === $dim->valuesAsList()) {
                SlotSpaceDdl::updateWhere(['active' => false], 'layer_id = ?', [$layerId]);

                return;
            }
        }

        $slotSpace = $definition->toSlotSpace();
        $columns = array_map(
            fn (DimensionDefinition $dimension): string => $this->dimensionColumn($dimension->name),
            $activeDimensions,
        );

        // `id` is included in the INSERT (BINARY(16) UUIDv7 is application-minted,
        // not auto-incremented) but NOT in the ON DUPLICATE KEY UPDATE clause —
        // existing rows keep their original id, the freshly-minted UUID is discarded
        // on conflict.
        $baseColumns = ['id', 'layer_id', 'slot_key', 'active', 'metadata_json', ...$columns];
        $placeholders = array_map(static fn (string $column): string => ':'.$column, $baseColumns);
        $updates = array_map(
            static fn (string $column): string => sprintf('%s = VALUES(%s)', $column, $column),
            ['layer_id', 'active', 'metadata_json', ...$columns],
        );
        $insertSql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)
             ON DUPLICATE KEY UPDATE %s',
            $this->table(self::TABLE_SLOTSPACE),
            implode(', ', $baseColumns),
            implode(', ', $placeholders),
            implode(', ', $updates),
        );

        $minter = $this->uuidMinter;
        $activeSlotKeys = [];
        foreach ($slotSpace->matchPartial([]) as $slot) {
            $params = [
                'id'            => $minter->mint(),
                'layer_id'      => $layerId,
                'slot_key'      => $slot->key,
                'active'        => 1,
                'metadata_json' => $this->json($slot->attributes),
            ];

            foreach ($activeDimensions as $dimension) {
                $dimensionName = $this->requireNonEmptyString($dimension->name, 'dimension.name');
                $params[$this->dimensionColumn($dimensionName)] = $slot->dimension($dimensionName);
            }

            $this->session->exec($insertSql, $params);
            $activeSlotKeys[] = $slot->key;
        }

        if ([] === $activeSlotKeys) {
            SlotSpaceDdl::updateWhere(['active' => false], 'layer_id = ?', [$layerId]);

            return;
        }

        $keyPlaceholders = implode(', ', array_fill(0, count($activeSlotKeys), '?'));

        // Count-first: skip the per-layer deactivation UPDATE when no stale
        // slot rows exist in this layer. See syncSlotspaceRowsLayered()'s
        // header comment for the concurrency rationale.
        $stale = $this->session->fetchScalar(sprintf(
            'SELECT 1 FROM %s
             WHERE layer_id = ? AND active = 1 AND slot_key NOT IN (%s)
             LIMIT 1',
            $this->table(self::TABLE_SLOTSPACE),
            $keyPlaceholders,
        ), [$layerId, ...$activeSlotKeys]);

        if (null === $stale) {
            return;
        }

        SlotSpaceDdl::updateWhere(
            ['active' => false],
            sprintf('layer_id = ? AND slot_key NOT IN (%s)', $keyPlaceholders),
            [$layerId, ...$activeSlotKeys],
        );
    }

    /** Sync the layered configuration state to invflux_config_state. */
    private function syncConfigStateLayered(LayeredSlotSpaceDefinition $def): void
    {
        $allDimensions = [];
        $layerDimensions = [];
        foreach ($def->layers as $slug => $layerDef) {
            $dimConfig = [];
            foreach ($layerDef->activeDimensions() as $dim) {
                $allDimensions[$dim->name] = $dim;
                $dimConfig[$dim->name] = $dim->isSharedRef
                    ? ['sharedRef' => true, 'valueSelector' => $dim->valueSelector?->toArray() ?? []]
                    : [];
            }

            $layerDimensions[$slug] = $dimConfig;
        }

        usort($allDimensions, static fn (DimensionDefinition $a, DimensionDefinition $b): int => $a->position <=> $b->position);

        ConfigState::updateWhere([
            'active_dimensions_json' => $this->json(array_map(
                static fn (DimensionDefinition $dim): string => $dim->name,
                $allDimensions,
            )),
            'layers_json' => $this->json($layerDimensions),
        ], 'id = ?', [self::CONFIG_STATE_ID]);

        $this->insertSchemaSnapshot($def);
    }

    /** Write a full schema snapshot to the config ledger after every schema-mutating operation. */
    private function insertSchemaSnapshot(LayeredSlotSpaceDefinition $def): void
    {
        $this->insertSchemaLedgerRow(
            eventType: 'schema_snapshot',
            payload: $def->toDefinition(),
            actorId: null,
            refType: null,
            refId: null,
            recordedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Return the full schema snapshot that was active at the given point in time.
     *
     * Queries the config ledger for the nearest preceding `schema_snapshot` entry.
     *
     * @return array<string, mixed>
     *
     * @throws SchemaException when no snapshot exists at or before the given time
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function schemaDefinitionAtTime(\DateTimeImmutable $at): array
    {
        $row = SchemaLedger::findOne(
            'event_type = ? AND recorded_at <= ?',
            ['schema_snapshot', $at->format('Y-m-d H:i:s.u')],
            orderByLimit: 'ORDER BY recorded_at DESC, id DESC',
        );

        if (null === $row) {
            throw new SchemaException('No schema snapshot found at or before the given time.', 'no_schema_snapshot');
        }

        /** @psalm-var array<string, mixed>|null $payload */
        $payload = json_decode($row->payload_json, true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Append one row to the configuration ledger.
     *
     * @param array<array-key, mixed> $payload
     */
    private function insertSchemaLedgerRow(
        string $eventType,
        array $payload,
        ?int $actorId,
        ?string $refType,
        ?string $refId,
        \DateTimeImmutable $recordedAt,
    ): void {
        SchemaLedger::newWith([
            'event_type'   => $eventType,
            'actor_id'     => $actorId,
            'ref_type_id'  => $this->resolveRefTypeIdStrict($refType),
            'ref_id'       => null === $refId ? null : (int) $refId,
            'payload_json' => $this->json($payload),
            'recorded_at'  => $recordedAt,
        ])->save();
    }

    /** Validate one requested storage scale. */
    private function assertValidScale(int $scale): void
    {
        if ($scale < 0) {
            throw new ConfigurationException('Quantity scale must be >= 0.', 'negative_quantity_scale');
        }
    }

    /**
     * Refuse a global scale migration while any subject carries its own scale.
     *
     * {@see Subject::$scale} *overrides* the global scale, so
     * such a subject's quantities are already stored at a different grain. Rescaling by the
     * global delta would multiply them by a factor that never applied to them — silent corruption,
     * and on a decrease the divisibility pre-check would consult the wrong factor too, so it could
     * pass and then truncate.
     *
     * Scoping the migration to inheriting subjects (`scale IS NULL`) would be the obvious fix, but
     * the column is substrate: its consumers (UoM conversion, kits/assemblies) are unbuilt, and
     * baking in a migration semantic for a subsystem that has not been designed is how you get a
     * silently wrong answer later. Failing loud costs nothing today — nothing sets the column, and
     * `registerSubject()` does not even expose it — and puts the decision in front of whoever
     * builds those consumers, with the context to make it.
     */
    private function assertNoSubjectScaleOverrides(): void
    {
        0 === Subject::countWhere('scale IS NOT NULL') || throw new ConfigurationException(
            'Cannot migrate the global quantity scale while subjects carry per-subject scale '
            .'overrides: their quantities are stored at their own grain and must not be rescaled '
            .'by the global delta.',
            'subject_scale_overrides_present',
        );
    }

    /** Prevent direct scale changes once persisted inventory or ledger data exists. */
    private function assertStoreIsEmptyForScaleChange(): void
    {
        $this->storeIsEmpty() || throw new ConfigurationException(
            'Quantity scale can only be set directly on an empty store. Use migrateQuantityScale() once data exists.',
            'quantity_scale_requires_migration',
        );
    }

    /** Multiply all persisted quantity columns by one factor. */
    private function multiplyQuantityColumns(int $factor): void
    {
        $this->executeSQL(<<<SQL
            UPDATE ::TABLE_INVENTORY_STATE
            SET quantity = quantity * ?
        SQL, [$factor]);

        $this->executeSQL(<<<SQL
            UPDATE ::TABLE_INVENTORY_LEDGER
            SET quantity = quantity * ?,
                initial_from = CASE WHEN initial_from IS NULL THEN NULL ELSE initial_from * ? END,
                initial_to = CASE WHEN initial_to IS NULL THEN NULL ELSE initial_to * ? END
        SQL, [$factor, $factor, $factor]);
    }

    /** Divide all persisted quantity columns by one factor after divisibility checks. */
    private function divideQuantityColumns(int $factor): void
    {
        $this->executeSQL(<<<SQL
            UPDATE ::TABLE_INVENTORY_STATE
            SET quantity = quantity DIV ?
        SQL, [$factor]);

        $this->executeSQL(<<<SQL
            UPDATE ::TABLE_INVENTORY_LEDGER
            SET quantity = quantity DIV ?,
                initial_from = CASE WHEN initial_from IS NULL THEN NULL ELSE initial_from DIV ? END,
                initial_to = CASE WHEN initial_to IS NULL THEN NULL ELSE initial_to DIV ? END
        SQL, [$factor, $factor, $factor]);
    }

    /** Ensure decreasing the scale would not lose precision in persisted quantities. */
    private function assertScaleDecreaseIsSafe(int $factor): void
    {
        $stateCount = $this->fetchCountFromSql(sprintf(
            'SELECT COUNT(*) FROM %s WHERE MOD(ABS(quantity), %d) <> 0',
            $this->table(self::TABLE_INVENTORY_STATE),
            $factor,
        ));
        $ledgerCount = $this->fetchCountFromSql(sprintf(
            'SELECT COUNT(*) FROM %s
             WHERE MOD(ABS(quantity), %d) <> 0
                OR (initial_from IS NOT NULL AND MOD(ABS(initial_from), %d) <> 0)
                OR (initial_to IS NOT NULL AND MOD(ABS(initial_to), %d) <> 0)',
            $this->table(self::TABLE_INVENTORY_LEDGER),
            $factor,
            $factor,
            $factor,
        ));

        if (0 !== $stateCount || 0 !== $ledgerCount) {
            throw new ConfigurationException(
                'Quantity scale decrease would lose precision in persisted quantities.',
                'unsafe_quantity_scale_decrease',
                ['factor' => $factor, 'inventory_rows' => $stateCount, 'ledger_rows' => $ledgerCount],
            );
        }
    }

    /**
     * @return array<string, int>
     */
    /**
     * Insert dimension metadata rows and return their ids by dimension name.
     *
     * @return array<string, int>
     */
    /**
     * @param array<string, int> $alreadyInserted dimension name → id; shared dims already inserted
     *
     * @return array<string, int>
     */
    private function insertDimensions(SlotSpaceDefinition $definition, array $alreadyInserted = []): array
    {
        $ids = [];
        $records = [];
        $namesInOrder = [];
        foreach ($definition->dimensions as $dimension) {
            if (isset($alreadyInserted[$dimension->name])) {
                $ids[$dimension->name] = $alreadyInserted[$dimension->name];
                continue;
            }
            $namesInOrder[] = $dimension->name;
            $records[] = Dimension::newWith([
                'name'                  => $dimension->name,
                'position_index'        => $dimension->position,
                'kind'                  => $dimension->kind->value,
                'collapse_behavior'     => $dimension->collapseBehavior->value,
                'collapse_target_value' => $dimension->collapseTargetValue,
                // Set by applyDimensionDefaults() once the value rows exist — the FK points at
                // invflux_dimension_values, which has nothing in it yet.
                'default_value'         => null,
                'active'                => $dimension->active,
                'metadata_json'         => $this->json($dimension->metadata),
            ]);
        }

        if ([] === $records) {
            return $ids;
        }

        // One INSERT for the whole set. attrecord back-fills the generated ids onto the records
        // in INSERT order, which is why the id map can still be built without a statement per row.
        (new RecordSet($records))->insertAll();
        foreach ($records as $i => $record) {
            $ids[$namesInOrder[$i]] = (int) $record->id;
        }

        return $ids;
    }

    /**
     * @param array<string, int> $dimensionIds
     *
     * @return array<string, array<string, int>>
     */
    /**
     * Insert dimension value rows and return ids by dimension name and code.
     *
     * @param array<string, int> $dimensionIds
     *
     * @return array<string, array<string, int>>
     */
    private function insertDimensionValues(SlotSpaceDefinition $definition, array $dimensionIds): array
    {
        $ids = [];
        $records = [];
        $coordsInOrder = [];
        foreach ($definition->dimensions as $dimension) {
            if ($dimension->isSharedRef) {
                continue; // values for shared dims are registered via addDimensionValues()
            }
            $dimensionId = $dimensionIds[$dimension->name];
            foreach ($dimension->values as $value) {
                $coordsInOrder[] = [$dimension->name, $value->code];
                $records[] = DimensionValue::newWith([
                    'dimension_id'        => $dimensionId,
                    'code'                => $value->code,
                    'name'                => $value->name,
                    'owner_key'           => $value->ownerKey,
                    'active'              => $value->active,
                    'removal_target_code' => $value->removalTargetCode,
                    'metadata_json'       => $this->json($value->metadata),
                    'level'               => $value->level,
                ]);
            }
        }

        if ([] === $records) {
            return $ids;
        }

        // One INSERT across every dimension's values, not a statement per value. Ids back-fill in
        // INSERT order, so the map builds from that same ordering.
        (new RecordSet($records))->insertAll();
        foreach ($records as $i => $record) {
            [$dimensionName, $code] = $coordsInOrder[$i];
            $ids[$dimensionName][$code] = (int) $record->id;
        }

        return $ids;
    }

    /**
     * @param array<string, int>                $dimensionIds
     * @param array<string, array<string, int>> $dimensionValueIds
     */
    /**
     * Persist the configured default value id for each dimension.
     *
     * @param array<string, int>                $dimensionIds
     * @param array<string, array<string, int>> $dimensionValueIds
     */
    private function applyDimensionDefaults(
        SlotSpaceDefinition $definition,
        array $dimensionIds,
        array $dimensionValueIds,
    ): void {
        $defaults = [];
        foreach ($definition->dimensions as $dimension) {
            if ($dimension->isSharedRef) {
                continue; // no static default; default resolved from loaded values at runtime
            }
            $defaults[$dimensionIds[$dimension->name]] = $dimensionValueIds[$dimension->name][$dimension->defaultValue];
        }

        if ([] === $defaults) {
            return;
        }

        // Hydrate, then write the whole set in one upsert. Reading first is not incidental: an
        // upsert emits a complete row (it is an INSERT … ON DUPLICATE KEY UPDATE), so a record
        // built from just id + default_value would write PHP defaults over every other column.
        //
        // `upsertAll(ignoreColumns:)` would also work here — these rows always pre-exist, so the
        // INSERT half never fires — but it means naming every *other* column, and a column added
        // to the Record later would silently be written with its PHP default. Two statements
        // that stay correct as the table grows beat one that quietly rots.
        $rows = Dimension::find('id IN ('.implode(', ', array_fill(0, count($defaults), '?')).')', array_keys($defaults));
        foreach ($rows as $row) {
            $row->default_value = $defaults[(int) $row->id];
        }
        $rows->upsertAll();
    }

    /**
     * Upsert dimension value lifecycle metadata rows.
     *
     * **Stays raw SQL**, unlike its sibling writes on this table, for two reasons that attrecord
     * cannot currently express:
     *
     * 1. `parent_id` / `addressable` are written on INSERT but deliberately **preserved**
     *    on conflict — a re-registration must not flatten an existing hierarchy. `upsertAll()`
     *    writes whatever the record carries, and `ignoreColumns:` drops a column from the insert
     *    too, so neither expresses "insert this, but leave it alone on update".
     * 2. Parent resolution is **sequential by construction**: a value's parent may be another
     *    value earlier in the same batch, and {@see resolveParentId()} reads it back from
     *    the table. Bulking the writes would resolve every parent before any row existed, so
     *    registering a parent and its child together would start failing.
     *
     * @param list<DimensionValueDefinition> $values
     */
    private function upsertDimensionValueRows(int $dimensionId, array $values): void
    {
        if ([] === $values) {
            return;
        }

        $sql = sprintf(
            'INSERT INTO %s
             (dimension_id, code, name, owner_key, active, removal_target_code, metadata_json,
              parent_id, addressable, level)
             VALUES (:dimension_id, :code, :name, :owner_key, :active, :removal_target_code, :metadata_json,
                     :parent_id, :addressable, :level)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                owner_key = VALUES(owner_key),
                active = VALUES(active),
                removal_target_code = VALUES(removal_target_code),
                metadata_json = VALUES(metadata_json),
                level = VALUES(level)',
            $this->table(self::TABLE_DIMENSION_VALUES),
        );

        foreach ($values as $value) {
            $parentId = $this->resolveParentId($dimensionId, $value->parentCode);
            $this->session->exec($sql, [
                'dimension_id'         => $dimensionId,
                'code'                 => $value->code,
                'name'                 => $value->name,
                'owner_key'            => $value->ownerKey,
                'active'               => $value->active ? 1 : 0,
                'removal_target_code'  => $value->removalTargetCode,
                'metadata_json'        => $this->json($value->metadata),
                'parent_id'            => $parentId,
                'addressable'          => $value->addressable ? 1 : 0,
                'level'                => $value->level,
            ]);
        }
    }

    /** Resolve a parent code string to its row ID, or null for a root value. */
    private function resolveParentId(int $dimensionId, ?string $parentCode): ?int
    {
        if (null === $parentCode) {
            return null;
        }

        $parent = DimensionValue::findOne('dimension_id = ? AND code = ?', [$dimensionId, $parentCode]);

        if (null === $parent) {
            throw new SchemaException(
                sprintf('Parent dimension value "%s" does not exist.', $parentCode),
                'unknown_parent_dimension_value',
                ['parentCode' => $parentCode],
            );
        }

        return (int) $parent->id;
    }

    /** Reconstruct the flat combined slot-space definition from MySQL metadata tables. */
    private function trySchema(): ?SlotSpaceDefinition
    {
        $dimensions = $this->loadAllDimensionDefinitions();

        return [] === $dimensions ? null : new SlotSpaceDefinition('default', $dimensions);
    }

    /**
     * Return slot ids (BINARY(16) UUIDv7) keyed by serialized slot key.
     *
     * @return array<string, string>
     */
    private function slotIdsByKey(): array
    {
        $slotRows = $this->fetchAllRows(sprintf(
            'SELECT id, slot_key FROM %s ORDER BY id',
            $this->table(self::TABLE_SLOTSPACE),
        ));

        $slots = [];
        foreach ($slotRows as $row) {
            $slots[$this->requireStringField($row, 'slot_key')] = $this->requireStringField($row, 'id');
        }

        ([] !== $slots)
            || throw new SchemaException('InvFlux schema has not been bootstrapped yet.', 'schema_not_bootstrapped');

        return $slots;
    }

    /** Return one declared dimension by name. */
    private function dimensionByName(SlotSpaceDefinition $schema, string $dimensionName): DimensionDefinition
    {
        foreach ($schema->dimensions as $dimension) {
            if ($dimension->name === $dimensionName) {
                return $dimension;
            }
        }

        throw new SchemaException(
            sprintf('Unknown dimension "%s".', $dimensionName),
            'unknown_dimension',
            ['dimensionName' => $dimensionName],
        );
    }

    /** Replace one dimension inside a schema definition and return the updated schema. */
    private function schemaWithUpdatedDimension(
        SlotSpaceDefinition $schema,
        DimensionDefinition $updatedDimension,
    ): SlotSpaceDefinition {
        $dimensions = array_map(
            static fn (DimensionDefinition $dimension): DimensionDefinition => $dimension->name === $updatedDimension->name
                ? $updatedDimension
                : $dimension,
            $schema->dimensions,
        );

        return new SlotSpaceDefinition($schema->name, $dimensions, $schema->metadata, $schema->flows, $schema->rules);
    }

    /** Return a copy of one dimension with one value toggled active/inactive. */
    private function dimensionWithValueState(
        DimensionDefinition $dimension,
        string $valueName,
        bool $active,
    ): DimensionDefinition {
        $matched = false;
        $updatedValues = [];
        foreach ($dimension->values as $value) {
            if ($value->code === $valueName) {
                $matched = true;
                $updatedValues[] = new DimensionValueDefinition(
                    code: $value->code,
                    name: $value->name,
                    ownerKey: $value->ownerKey,
                    active: $active,
                    removalTargetCode: $value->removalTargetCode,
                    metadata: $value->metadata,
                    parentCode: $value->parentCode,
                    addressable: $value->addressable,
                    level: $value->level,
                );

                continue;
            }

            $updatedValues[] = $value;
        }

        $matched || throw new SchemaException(
            sprintf('Unknown dimension value "%s:%s".', $dimension->name, $valueName),
            'unknown_dimension_value',
            ['dimensionName' => $dimension->name, 'value' => $valueName],
        );

        return new DimensionDefinition(
            $dimension->name,
            $dimension->position,
            $updatedValues,
            $dimension->defaultValue,
            $dimension->kind,
            $dimension->collapseBehavior,
            $dimension->collapseTargetValue,
            $dimension->active,
            $dimension->metadata,
        );
    }

    /**
     * Declare which value is a dimension's default.
     *
     * @see SchemaManager::setDimensionDefault() for why a sharedRef dimension needs this
     */
    #[\Override]
    public function setDimensionDefault(string $dimensionName, string $valueCode): void
    {
        $this->transactional(function () use ($dimensionName, $valueCode): void {
            $dimensionId = $this->dimensionIdByName($dimensionName);

            $value = DimensionValue::findOne(
                'dimension_id = ? AND code = ? AND active = 1',
                [$dimensionId, $valueCode],
            );
            null !== $value || throw new SchemaException(
                sprintf('Cannot default dimension "%s" to unknown or inactive value "%s".', $dimensionName, $valueCode),
                'unknown_dimension_value',
                ['dimensionName' => $dimensionName, 'code' => $valueCode],
            );

            // Hydrate before writing: an upsert emits a complete row, so a Record built from just
            // id + default_value would write PHP defaults over every other column (same reasoning
            // as applyDimensionDefaults()).
            $dimension = Dimension::findOne('id = ?', [$dimensionId]);
            if (null === $dimension || $dimension->default_value === $value->id) {
                return; // already correct, or vanished under us — nothing to write
            }

            $dimension->default_value = $value->id;
            $dimension->save();
        });
    }

    /**
     * The declared default value's code for one dimension, or null when none is declared.
     *
     * Read-only and tolerant: an unknown dimension yields null rather than throwing, because
     * callers use this to *prefer* a declared default, not to assert one exists.
     */
    private function declaredDimensionDefault(string $dimensionName): ?string
    {
        $row = $this->fetchAllRows(sprintf(
            'SELECT dv.code
             FROM %s d
             JOIN %s dv ON dv.id = d.default_value
             WHERE d.name = :name
             LIMIT 1',
            $this->table(self::TABLE_DIMENSIONS),
            $this->table(self::TABLE_DIMENSION_VALUES),
        ), ['name' => $dimensionName]);

        return [] === $row ? null : $this->nullableNonEmptyStringField($row[0], 'code');
    }

    /** Return the stored numeric id for one declared dimension. */
    private function dimensionIdByName(string $dimensionName): int
    {
        $id = Dimension::findOne('name = ?', [$dimensionName])?->id;
        is_scalar($id) || throw new SchemaException(
            sprintf('Unknown dimension "%s".', $dimensionName),
            'unknown_dimension',
            ['dimensionName' => $dimensionName],
        );

        return $id;
    }

    /** Return the stored numeric id for one declared dimension value. */
    private function dimensionValueIdByName(string $dimensionName, string $valueName): int
    {
        $id = $this->fetchScalarValue(sprintf(
            'SELECT dv.id
             FROM %s dv
             JOIN %s d ON d.id = dv.dimension_id
             WHERE d.name = :dimension_name
               AND dv.code = :code
            LIMIT 1',
            $this->table(self::TABLE_DIMENSION_VALUES),
            $this->table(self::TABLE_DIMENSIONS),
        ), [
            'dimension_name' => $dimensionName,
            'code'           => $valueName,
        ]);
        is_scalar($id) || throw new SchemaException(
            sprintf('Unknown dimension value "%s:%s".', $dimensionName, $valueName),
            'unknown_dimension_value',
            ['dimensionName' => $dimensionName, 'value' => $valueName],
        );

        return (int) $id;
    }

    /** Mark one stored dimension value row as inactive. */
    private function disableDimensionValueRow(int $dimensionId, string $valueName): void
    {
        DimensionValue::updateWhere(['active' => false], 'dimension_id = ? AND code = ?', [$dimensionId, $valueName]);
    }

    /**
     * Sort value definitions by stable machine code.
     *
     * @param list<DimensionValueDefinition> $values
     *
     * @return list<DimensionValueDefinition>
     */
    private function sortedDimensionValues(array $values): array
    {
        usort(
            $values,
            static fn (DimensionValueDefinition $left, DimensionValueDefinition $right): int => strcmp($left->code, $right->code),
        );

        return $values;
    }

    /**
     * Ensure a value's code fits the column that stores it, refusing loudly rather than letting
     * the database shorten it.
     *
     * **The database will not do this for us where it counts.** A WordPress install runs a
     * deliberately non-strict `sql_mode`, so an over-long code is *silently truncated* on INSERT —
     * success reported, no error raised. The integration suite runs `STRICT_TRANS_TABLES` and
     * would reject the same write, so this class of mistake is invisible to the tests by
     * construction and shows up only in the environment that matters.
     *
     * Truncation is worse than it sounds here, because it does not merely shorten the code: `path`
     * is a wider column holding the same string, so it survives intact while `code` is cut, and
     * the two silently disagree. Everything resolving a value by prefix is then answering from a
     * tree that does not exist.
     *
     * The limit is not tight in practice, because a segment is a short machine code — `oh/eu/ch/
     * ge/wh-lsn/z-A/a-03/r-12/b-0042` is nine tiers in 39 characters. Merchant-facing text belongs
     * in the value's `name`, which is a separate, wider column precisely so the code can stay
     * short. This refusal is what makes that convention real rather than assumed.
     */
    private function assertDimensionValueCodeFits(string $dimensionName, DimensionValueDefinition $value): void
    {
        if (\strlen($value->code) <= self::MAX_DIMENSION_VALUE_CODE_LENGTH) {
            return;
        }

        throw new ConfigurationException(
            sprintf(
                'Dimension value code "%s:%s" (contributed by "%s") is %d bytes, over the %d the column stores. A code is built from short machine segments — put the human-readable text in the value\'s name instead.',
                $dimensionName,
                $value->code,
                $value->ownerKey,
                \strlen($value->code),
                self::MAX_DIMENSION_VALUE_CODE_LENGTH,
            ),
            'dimension_value_code_too_long',
            [
                'dimensionName' => $dimensionName,
                'code'          => $value->code,
                'length'        => \strlen($value->code),
                'maxLength'     => self::MAX_DIMENSION_VALUE_CODE_LENGTH,
                'ownerKey'      => $value->ownerKey,
            ],
        );
    }

    /**
     * Ensure a value's code is its parent's code plus exactly one segment (a root's carries no
     * separator at all).
     *
     * A value's code **is** its full path. Codes are unique per dimension, so a bare segment
     * would collide the moment two parents each want a child of the same name — two warehouses
     * each with an `unassigned` bin is the ordinary case, not a corner. Deriving the code from
     * the parent's also makes the stored `path` a function of the code rather than a second
     * thing to keep in step with it, which is what lets a subtree be recoded by prefix.
     *
     * Refusing a *skipped* level (`oh/a/b` registered directly under `oh`) is part of the same
     * guarantee: every code between a value and the root has to name a real value, or path-prefix
     * matching answers for ancestors that do not exist.
     */
    private function assertDimensionValueCodeSitsUnderParent(string $dimensionName, DimensionValueDefinition $value): void
    {
        $this->assertDimensionValueCodeFits($dimensionName, $value);

        if (null === $value->parentCode) {
            if (str_contains($value->code, '/')) {
                throw new ConfigurationException(
                    sprintf(
                        'Dimension value "%s:%s" (contributed by "%s") has no parent, so its code must name a root: remove the "/" or declare the parent it belongs under.',
                        $dimensionName,
                        $value->code,
                        $value->ownerKey,
                    ),
                    'dimension_value_code_not_under_parent',
                    ['dimensionName' => $dimensionName, 'code' => $value->code, 'ownerKey' => $value->ownerKey],
                );
            }

            return;
        }

        $prefix = $value->parentCode.'/';
        $segment = str_starts_with($value->code, $prefix)
            ? substr($value->code, \strlen($prefix))
            : null;

        if (null === $segment || '' === $segment || str_contains($segment, '/')) {
            throw new ConfigurationException(
                sprintf(
                    'Dimension value "%s:%s" (contributed by "%s") does not sit directly under its parent "%s". A code is its full path, so it must be "%s" plus exactly one segment.',
                    $dimensionName,
                    $value->code,
                    $value->ownerKey,
                    $value->parentCode,
                    $value->parentCode,
                ),
                'dimension_value_code_not_under_parent',
                [
                    'dimensionName' => $dimensionName,
                    'code'          => $value->code,
                    'parentCode'    => $value->parentCode,
                    'ownerKey'      => $value->ownerKey,
                ],
            );
        }
    }

    /**
     * Refuse to give an addressable value its first child.
     *
     * Addressable means "stock can sit here". A child withdraws that claim and turns the value
     * into a grouping node — but leaves it wearing the level it had, and level matching is
     * depth-independent, so a layer selecting `warehouse` then matches the group *and* the
     * warehouses under it: several locations where the merchant has one. Which of the two values
     * should keep the level is not derivable here, because "a warehouse gains bins" and "the
     * on-hand root gains warehouses" are the same structural event with opposite answers.
     *
     * So this call refuses rather than guesses, and names {@see hierarchiseDimensionValue()},
     * which asks for the decision. The refusal fires whether or not the value holds stock: stock
     * is what makes the change destructive, but the level collision is what makes it wrong, and
     * only the second is always present.
     *
     * @param list<DimensionValueDefinition>          $values
     * @param array<string, DimensionValueDefinition> $incomingByCode     values declared in this call
     * @param array<string, DimensionValueDefinition> $storedValuesByCode values already registered
     */
    private function assertNoAddressableValueGainsAChild(
        string $dimensionName,
        array $values,
        array $incomingByCode,
        array $storedValuesByCode,
    ): void {
        foreach ($values as $value) {
            $parentCode = $value->parentCode;
            if (null === $parentCode) {
                continue;
            }

            // A parent declared in this same call has no stock and no history, so the fix is to
            // declare it correctly rather than to migrate anything.
            $declaredParent = $incomingByCode[$parentCode] ?? null;
            if ($declaredParent instanceof DimensionValueDefinition) {
                if ($declaredParent->addressable) {
                    throw new ConfigurationException(
                        sprintf(
                            'Dimension value "%s:%s" (contributed by "%s") is declared addressable in the same call that gives it the child "%s". A value with children is a grouping node: declare it with addressable: false.',
                            $dimensionName,
                            $parentCode,
                            $declaredParent->ownerKey,
                            $value->code,
                        ),
                        'addressable_parent_with_declared_child',
                        [
                            'dimensionName' => $dimensionName,
                            'code'          => $parentCode,
                            'childCode'     => $value->code,
                            'ownerKey'      => $declaredParent->ownerKey,
                        ],
                    );
                }

                continue;
            }

            $storedParent = $storedValuesByCode[$parentCode] ?? null;
            if ($storedParent instanceof DimensionValueDefinition && $storedParent->addressable) {
                throw new ConfigurationException(
                    sprintf(
                        'Dimension value "%s:%s" holds stock at level "%s", and "%s" (contributed by "%s") would be its first child — which would leave the level on a value stock can no longer sit in, matched alongside its own children. Call hierarchiseDimensionValue() first: it demotes "%s" into a child position, keeping its slots, stock and history, and mints a new parent above it, stating which of the two keeps level "%s".',
                        $dimensionName,
                        $parentCode,
                        $storedParent->level ?? '(none)',
                        $value->code,
                        $value->ownerKey,
                        $parentCode,
                        $storedParent->level ?? '(none)',
                    ),
                    'addressable_parent_requires_hierarchisation',
                    [
                        'dimensionName' => $dimensionName,
                        'code'          => $parentCode,
                        'childCode'     => $value->code,
                        'level'         => $storedParent->level,
                        'ownerKey'      => $value->ownerKey,
                    ],
                );
            }
        }
    }

    /** Ensure add/reactivate does not silently mutate an existing value definition. */
    private function assertAddDimensionValueDoesNotMutateExistingDefinition(
        string $dimensionName,
        DimensionValueDefinition $existing,
        DimensionValueDefinition $incoming,
    ): void {
        if ($this->dimensionValueDefinitionsDiffer($existing, $incoming)) {
            throw new ConfigurationException(
                sprintf('Dimension value "%s:%s" already exists with different metadata.', $dimensionName, $incoming->code),
                'conflicting_dimension_value_definition',
                ['dimensionName' => $dimensionName, 'code' => $incoming->code],
            );
        }
    }

    /**
     * Whether two definitions of the same value code disagree on any stored attribute — i.e. the
     * incoming one would *redefine* rather than re-declare it. Shared by the runtime
     * add-dimension-values guard and the bootstrap reconciler (which throw different exception
     * types: a conflicting runtime add vs. a schema redefinition).
     */
    private function dimensionValueDefinitionsDiffer(
        DimensionValueDefinition $a,
        DimensionValueDefinition $b,
    ): bool {
        return $a->name !== $b->name
            || $a->ownerKey !== $b->ownerKey
            || $a->removalTargetCode !== $b->removalTargetCode
            || $a->metadata !== $b->metadata
            || $a->parentCode !== $b->parentCode
            || $a->addressable !== $b->addressable
            || $a->level !== $b->level;
    }

    /**
     * Recode one value and everything under it, in one statement.
     *
     * A code is its full path, so demoting a branch is a prefix substitution on a single column —
     * no tree walk, and no per-row round trip however deep the branch. `parent_id` is untouched
     * throughout: the edges do not change, only the names on them.
     *
     * `LEFT(code, n) = ?` rather than `LIKE`: a code may legitimately contain `_`, which `LIKE`
     * would read as a wildcard and over-match a sibling branch (`wh_1` would take `wh-1` with it).
     */
    private function recodeDimensionValueSubtree(int $dimensionId, string $oldCode, string $newCode): void
    {
        // SUBSTRING is 1-indexed: cutting from len+1 drops exactly the old prefix.
        $cut = \strlen($oldCode) + 1;
        $branchPrefix = $oldCode.'/';

        DimensionValue::updateWhere(
            ['code' => new RawSql('CONCAT(?, SUBSTRING(`code`, ?))', [$newCode, $cut])],
            'dimension_id = ? AND (code = ? OR LEFT(code, ?) = ?)',
            [$dimensionId, $oldCode, \strlen($branchPrefix), $branchPrefix],
        );
    }

    /**
     * Decide, per layer, whether that layer's slot rows follow the demoted value or stay where
     * they are and become the interposed parent's.
     *
     * Both answers say the same thing — *the stock did not move* — and differ only in which of the
     * two values that layer means by the place it was already addressing. Give the on-hand root
     * its first bins and the warehouse is still the same warehouse, so a layer addressing
     * warehouses keeps its rows, ids and keys untouched while the layer addressing physical leaves
     * follows the stock down into the bin. Give that same root its first *warehouses* and it is
     * the other way round: the warehouse is now the demoted value, and the warehouse-addressing
     * layer follows it.
     *
     * Reading it off the selector is what keeps that from being a special case per add-on: a layer
     * follows whichever of the two values its own selector picks.
     *
     * @return array<int, bool> keyed by layer id; true = this layer's rows follow the demoted value
     */
    private function slotFollowsDemotedValueByLayer(
        string $dimensionName,
        ?string $demotedLevel,
        ?string $parentLevel,
    ): array {
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        if (null === $layered) {
            return [];
        }

        $followsByLayerId = [];
        foreach ($layered->layers as $slug => $layerDef) {
            $dimension = $layerDef->dimensionByName($dimensionName);
            if (null === $dimension) {
                continue;
            }

            $selector = $dimension->valueSelector ?? DimensionValueSelector::root();
            $followsByLayerId[$this->layerIdBySlug($slug)] = match ($selector->kind) {
                // The interposed parent takes the position the incumbent vacated, root included.
                // The demoted value has a parent now, so it is not a root.
                DimensionValueSelectorKind::Root => false,
                // Addressability is exactly what the demoted value keeps and the parent, having a
                // child by construction, cannot have.
                DimensionValueSelectorKind::Leaf => true,
                // The two levels are distinct, so at most one of them is named here. A layer that
                // names the parent's level means the parent; every other layer keeps its rows with
                // the value that keeps their history.
                DimensionValueSelectorKind::Level, DimensionValueSelectorKind::Levels => !(
                    null !== $parentLevel
                    && \in_array($parentLevel, $this->selectorLevelNames($selector), true)
                ),
            };
        }

        return $followsByLayerId;
    }

    /** The level names one selector matches, empty for kinds that do not match on level. */
    private function selectorLevelNames(DimensionValueSelector $selector): array
    {
        if (DimensionValueSelectorKind::Levels === $selector->kind) {
            return $selector->levels;
        }

        return null === $selector->level ? [] : [$selector->level];
    }

    /**
     * Work out the new `dim_*` value and slot key for every slot row that follows a recoded
     * branch, plus the rows the interposed parent needs minting in the layers that followed.
     *
     * Read separately from the write because it needs the *schema*: a slot key is the layer's
     * dimension values joined in position order, and which component carries this dimension
     * differs per layer — `loc` is the whole key in a single-dimension layer and the second
     * component of `stt.loc`. Rebuilding the key by position keeps that honest; substituting the
     * old code textually would also rewrite a component of another dimension that happened to
     * match it.
     *
     * The parent is minted a copy of each row the demoted value took with it, carrying the key and
     * value the branch vacated. Those rows are what ancestor aggregation reads: it sums a leaf into
     * its non-addressable ancestors by joining a slot row per ancestor code, so without them a
     * grouping node would silently stop accumulating. They are minted inactive, which is what a
     * value outside every layer's selector is; the sync activates them where one admits them.
     *
     * Rows whose layer no longer exists are skipped: their key cannot be rebuilt without a
     * dimension order, and the sync deactivates them anyway.
     *
     * @param array<int, bool> $followsByLayerId
     *
     * @return array{
     *     recodes: list<array{id: string, dimValue: string, slotKey: string}>,
     *     interposed: list<array{id: string, dimValue: string, slotKey: string}>,
     * }
     */
    private function plannedSlotRecodes(
        string $dimensionName,
        string $oldCode,
        string $newCode,
        array $followsByLayerId,
    ): array {
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        if (null === $layered) {
            return ['recodes' => [], 'interposed' => []];
        }

        /** @var array<int, int> $componentIndexByLayerId */
        $componentIndexByLayerId = [];
        foreach ($layered->layers as $slug => $layerDef) {
            $names = array_map(
                static fn (DimensionDefinition $dimension): string => $dimension->name,
                $layerDef->activeDimensions(),
            );
            $index = array_search($dimensionName, $names, true);
            if (\is_int($index)) {
                $componentIndexByLayerId[$this->layerIdBySlug($slug)] = $index;
            }
        }

        $column = $this->dimensionColumn($dimensionName);
        $branchPrefix = $oldCode.'/';

        $rows = $this->fetchAllRows(sprintf(
            'SELECT id, layer_id, slot_key, %s AS dim_value
               FROM %s
              WHERE %s = :code OR LEFT(%s, :prefix_len) = :prefix',
            $column,
            $this->table(self::TABLE_SLOTSPACE),
            $column,
            $column,
        ), ['code' => $oldCode, 'prefix_len' => \strlen($branchPrefix), 'prefix' => $branchPrefix]);

        $recodes = [];
        $interposed = [];
        foreach ($rows as $row) {
            $layerId = $row['layer_id'] ?? null;
            if (!is_numeric($layerId) || !isset($componentIndexByLayerId[(int) $layerId])) {
                continue;
            }
            if (true !== ($followsByLayerId[(int) $layerId] ?? false)) {
                continue;
            }

            $index = $componentIndexByLayerId[(int) $layerId];
            $slotKey = $this->requireStringField($row, 'slot_key');
            $dimValue = $this->requireStringField($row, 'dim_value');
            $components = explode(DefaultSlotKeyCodec::SEPARATOR, $slotKey);

            ($components[$index] ?? null) === $dimValue || throw new SchemaException(
                sprintf('Slot "%s" does not carry dimension "%s" where its layer places it.', $slotKey, $dimensionName),
                'slot_key_dimension_mismatch',
                ['slotKey' => $slotKey, 'dimensionName' => $dimensionName, 'expected' => $dimValue],
            );

            $id = $this->requireStringField($row, 'id');
            $newDimValue = $newCode.substr($dimValue, \strlen($oldCode));
            $components[$index] = $newDimValue;

            $recodes[] = [
                'id'       => $id,
                'dimValue' => $newDimValue,
                'slotKey'  => implode(DefaultSlotKeyCodec::SEPARATOR, $components),
            ];

            // Only the branch root vacates a code; its descendants keep theirs, deeper.
            if ($dimValue === $oldCode) {
                $interposed[] = ['id' => $id, 'dimValue' => $dimValue, 'slotKey' => $slotKey];
            }
        }

        return ['recodes' => $recodes, 'interposed' => $interposed];
    }

    /**
     * Write the planned slot recodes in one statement.
     *
     * The slot ids are unchanged — which is the whole point, since `inventory_state` and
     * `inventory_ledger` reference them and neither is touched by a hierarchisation.
     *
     * @param list<array{id: string, dimValue: string, slotKey: string}> $recodes
     */
    private function applySlotRecodes(string $dimensionName, array $recodes): void
    {
        if ([] === $recodes) {
            return;
        }

        $keyCases = '';
        $dimCases = '';
        $params = [];
        $idKeys = [];
        foreach ($recodes as $i => $recode) {
            $keyCases .= sprintf(' WHEN :id%d THEN :key%d', $i, $i);
            $dimCases .= sprintf(' WHEN :id%d THEN :dim%d', $i, $i);
            $params['id'.$i] = $recode['id'];
            $params['key'.$i] = $recode['slotKey'];
            $params['dim'.$i] = $recode['dimValue'];
            $idKeys[] = ':id'.$i;
        }

        $this->session->exec(sprintf(
            'UPDATE %s
                SET slot_key = CASE id%s END,
                    %s = CASE id%s END
              WHERE id IN (%s)',
            $this->table(self::TABLE_SLOTSPACE),
            $keyCases,
            $this->dimensionColumn($dimensionName),
            $dimCases,
            implode(', ', $idKeys),
        ), $params);
    }

    /**
     * Give the interposed parent the slot rows the demoted branch vacated.
     *
     * `INSERT … SELECT` from the rows just recoded, because the parent's row is that row in every
     * respect but three: a fresh id, the key and value the branch gave up, and `active = 0`. The
     * copy carries the other dimensions' values across without this having to know what they are —
     * a layer's slot is a point in every dimension it declares, not just this one.
     *
     * Runs after {@see applySlotRecodes()}, which is what frees the keys being taken back.
     *
     * @param list<array{id: string, dimValue: string, slotKey: string}> $interposed
     */
    private function mintInterposedParentSlots(string $dimensionName, array $interposed): void
    {
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        if ([] === $interposed || null === $layered) {
            return;
        }

        $targetColumn = $this->dimensionColumn($dimensionName);

        /** @var array<string, true> $dimColumnSet */
        $dimColumnSet = [];
        foreach ($layered->layers as $layerDef) {
            foreach ($layerDef->activeDimensions() as $dimension) {
                $dimColumnSet[$this->dimensionColumn($dimension->name)] = true;
            }
        }
        $dimColumns = array_keys($dimColumnSet);

        $idCases = '';
        $keyCases = '';
        $dimCases = '';
        $params = [];
        $sourceKeys = [];
        foreach ($interposed as $i => $row) {
            // The minted id is raw bytes. Bound as a plain string it types the CASE result as
            // text in the connection charset, and MySQL 8 then rejects the bytes on insert as
            // invalid UTF-8 (error 1366); MariaDB lets them through. The cast keeps the branch
            // binary, matching the BINARY(16) column it lands in.
            $idCases .= sprintf(' WHEN :src%d THEN CAST(:new%d AS BINARY)', $i, $i);
            $keyCases .= sprintf(' WHEN :src%d THEN :key%d', $i, $i);
            $dimCases .= sprintf(' WHEN :src%d THEN :dim%d', $i, $i);
            $params['src'.$i] = $row['id'];
            $params['new'.$i] = $this->uuidMinter->mint();
            $params['key'.$i] = $row['slotKey'];
            $params['dim'.$i] = $row['dimValue'];
            $sourceKeys[] = ':src'.$i;
        }

        $selected = array_map(
            static fn (string $column): string => $column === $targetColumn
                ? 'CASE id'.$dimCases.' END'
                : $column,
            $dimColumns,
        );

        $this->session->exec(sprintf(
            'INSERT INTO %s (id, layer_id, slot_key, active, metadata_json, %s)
             SELECT CASE id%s END, layer_id, CASE id%s END, 0, metadata_json, %s
               FROM %s
              WHERE id IN (%s)',
            $this->table(self::TABLE_SLOTSPACE),
            implode(', ', $dimColumns),
            $idCases,
            $keyCases,
            implode(', ', $selected),
            $this->table(self::TABLE_SLOTSPACE),
            implode(', ', $sourceKeys),
        ), $params);
    }

    /** Return true if any dimension value has a parent_id set (hierarchy exists). */
    private function hasHierarchy(): bool
    {
        if (null !== $this->hasHierarchyDimension) {
            return $this->hasHierarchyDimension;
        }

        try {
            $result = $this->fetchScalarValue(sprintf(
                'SELECT 1 FROM %s WHERE parent_id IS NOT NULL LIMIT 1',
                $this->table(self::TABLE_DIMENSION_VALUES),
            ));
        } catch (PersistenceException) {
            return $this->hasHierarchyDimension = false;
        }

        return $this->hasHierarchyDimension = (null !== $result);
    }

    /**
     * Expand delta rows with ancestor fan-out deltas for hierarchical loc values.
     *
     * Pre-transaction read: for each leaf physical slot in the delta set, look up all
     * non-addressable ancestor slots in the same layer. Accumulate per-subject ancestor
     * deltas and merge them into the returned list (marked is_ancestor = true so the
     * guard-conflict and projection passes operate on leaf rows only, skipping the
     * aggregate ancestor rows).
     *
     * @param non-empty-list<PersistDeltaRow> $deltaRows
     *
     * @return non-empty-list<PersistDeltaRow>
     */
    private function expandWithAncestorDeltas(array $deltaRows): array
    {
        if (!$this->hasHierarchy()) {
            return $deltaRows;
        }

        $locDimId = $this->fetchScalarValue(
            'SELECT id FROM ::TABLE_DIMENSIONS WHERE name = :name',
            ['name' => 'loc'],
        );

        if (null === $locDimId) {
            return $deltaRows;
        }

        $leafSlotIds = array_values(array_unique(array_map(static fn (PersistDeltaRow $row): string => $row->slotId, $deltaRows)));
        $placeholders = implode(', ', array_fill(0, count($leafSlotIds), '?'));

        try {
            $ancestorRows = $this->fetchAllRows(sprintf(
                'SELECT leaf_ss.id AS leaf_slot_id,
                        ancestor_ss.id AS ancestor_slot_id,
                        ancestor_ss.slot_key AS ancestor_slot_key
                 FROM %s leaf_ss
                 JOIN %s leaf_dv
                   ON leaf_dv.code = leaf_ss.dim_loc
                  AND leaf_dv.dimension_id = ?
                 -- A code is its full path, so ancestry is a prefix test on it. LEFT(...) rather
                 -- than LIKE because a code may contain `_`, which LIKE reads as a wildcard and
                 -- would pull in a sibling branch. Requiring the separator also excludes the
                 -- value itself, so "strictly below" needs no second predicate.
                 JOIN %s ancestor_dv
                   ON LEFT(leaf_dv.code, CHAR_LENGTH(ancestor_dv.code) + 1) = CONCAT(ancestor_dv.code, \'/\')
                  AND ancestor_dv.addressable = 0
                  AND ancestor_dv.dimension_id = leaf_dv.dimension_id
                 JOIN %s ancestor_ss
                   ON ancestor_ss.dim_loc = ancestor_dv.code
                  AND ancestor_ss.layer_id = leaf_ss.layer_id
                 WHERE leaf_ss.id IN (%s)',
                $this->table(self::TABLE_SLOTSPACE),
                $this->table(self::TABLE_DIMENSION_VALUES),
                $this->table(self::TABLE_DIMENSION_VALUES),
                $this->table(self::TABLE_SLOTSPACE),
                $placeholders,
            ), [$locDimId, ...$leafSlotIds]);
        } catch (PersistenceException) {
            return $deltaRows;
        }

        if ([] === $ancestorRows) {
            return $deltaRows;
        }

        // Build leaf_slot_id → list of ancestor {slot_id, slot_key} mappings.
        // slot_id is BINARY(16) (UUIDv7 as a 16-byte PHP string).
        /** @var array<string, list<array{slot_id: string, slot_key: string}>> $ancestorsByLeaf */
        $ancestorsByLeaf = [];
        foreach ($ancestorRows as $row) {
            $leafSlotId = (string) $row['leaf_slot_id'];
            $ancestorsByLeaf[$leafSlotId][] = [
                'slot_id'  => (string) $row['ancestor_slot_id'],
                'slot_key' => (string) $row['ancestor_slot_key'],
            ];
        }

        // Accumulate ancestor deltas keyed by subject_id:ancestor_slot_id
        /** @var array<string, PersistDeltaRow> $ancestorDeltas */
        $ancestorDeltas = [];
        foreach ($deltaRows as $deltaRow) {
            $ancestors = $ancestorsByLeaf[$deltaRow->slotId] ?? [];
            foreach ($ancestors as $ancestor) {
                $key = $deltaRow->subjectId.':'.$ancestor['slot_id'];
                if (!isset($ancestorDeltas[$key])) {
                    $ancestorDeltas[$key] = new PersistDeltaRow(
                        $deltaRow->subjectId,
                        $ancestor['slot_id'],
                        $ancestor['slot_key'],
                        0,
                        null,
                        null,
                        true,
                    );
                }
                $ancestorDeltas[$key]->delta += $deltaRow->delta;
            }
        }

        return [...$deltaRows, ...array_values($ancestorDeltas)];
    }

    /** Resolve one named value inside a dimension. */
    private function dimensionValueByName(DimensionDefinition $dimension, string $valueName): DimensionValueDefinition
    {
        foreach ($dimension->values as $value) {
            if ($value->code === $valueName) {
                return $value;
            }
        }

        throw new SchemaException(
            sprintf('Unknown dimension value "%s:%s".', $dimension->name, $valueName),
            'unknown_dimension_value',
            ['dimensionName' => $dimension->name, 'value' => $valueName],
        );
    }

    /**
     * Resolve one requested drain target to a concrete value or the `_nil` sentinel.
     *
     * @param ?non-empty-string $targetValue
     */
    private function resolveDrainTarget(
        DimensionDefinition $dimension,
        DimensionValueDefinition $source,
        ?string $targetValue,
    ): ?array {

        $resolved = $targetValue ?? $source->removalTargetCode ?? '_default';

        if ('_nil' === $resolved) {
            return null;
        }

        if ('_default' === $resolved) {
            $resolved = $dimension->defaultValue;
        }

        if ($resolved === $source->code) {
            throw new SchemaException(
                sprintf('Drain target for "%s:%s" cannot equal the source value.', $dimension->name, $source->code),
                'invalid_drain_target',
                ['dimensionName' => $dimension->name, 'sourceValue' => $source->code, 'targetValue' => $resolved],
            );
        }

        $target = $this->dimensionValueByName($dimension, $resolved);
        if (!$target->active) {
            throw new SchemaException(
                sprintf('Drain target "%s:%s" must be active.', $dimension->name, $resolved),
                'inactive_drain_target',
                ['dimensionName' => $dimension->name, 'sourceValue' => $source->code, 'targetValue' => $resolved],
            );
        }

        return [$dimension->name => $resolved];
    }

    /**
     * Load inventory from storage, execute one SlotFlow batch movement, then persist it.
     */
    #[\Override]
    public function executeBatchFlowFromStorage(StorageBatchFlowRequest $request): PersistedBatchMovement
    {
        $layerName = $request->layerName;
        $schema = null !== $layerName ? $this->layerSchema($layerName) : $this->schema();

        $layerId = null !== $layerName ? $this->layerIdBySlug($layerName) : null;

        // Storage-layer enforcement of movement eligibility: a subject participates only if
        // InvFlux governs it AND its kind owns slots. Filtered here rather than in each adapter so
        // every caller gets the behaviour automatically; silent skip rather than exception because
        // an order line for something uncounted is a legitimate order line. See
        // partitionByMovementEligibility() for both reasons and why neither throws.
        $skippedUnmanagedSubjectIds = [];
        $requestedSubjectIds = $request->subjectIds;
        if (null !== $requestedSubjectIds) {
            [$managedSubjectIds, $skippedUnmanagedSubjectIds] = $this->partitionByMovementEligibility($requestedSubjectIds);
            if ([] === $managedSubjectIds) {
                return new PersistedBatchMovement(
                    ok: true,
                    affectedSubjects: 0,
                    affectedSlots: 0,
                    insertedLedgerRows: 0,
                    recordedAt: $request->recordedAt(),
                    skippedUnmanagedSubjectIds: $skippedUnmanagedSubjectIds,
                );
            }
            $requestedSubjectIds = $managedSubjectIds;
        }

        // Write-in / create flow (every step originates from nil, e.g. goods receipt
        // `nil → atp`): the requested subjects participate even at zero current stock — a
        // create movement's delta is `+qty` regardless of current state, which
        // persistBatch() applies to the authoritative row. So we operate on the requested
        // managed subjects directly, skipping the `quantity <> 0` selection. This is what lets
        // a first receipt of a brand-new, zero-stock subject post correctly.
        // Resolve the flow BEFORE asking whether it is a write-in. A flow may arrive as an object
        // or as the name of one registered on the space, and "is this a write-in" is a property of
        // its steps — a question a name cannot answer. Gating the branch on `instanceof Flow` would
        // send every *named* write-in down the general path, where the `quantity <> 0` selection
        // below drops precisely the brand-new, zero-stock subject this branch exists to serve: a
        // first receipt would post nothing, and report success.
        $space = $schema->toSlotSpace();
        $space->subjectKeyResolver(static fn (SubjectId $subjectId): string => (string) $subjectId->id);
        $flow = $this->resolveFlow($request->flow, $layerName);

        if ($this->flowIsWriteIn($flow)) {
            return $this->executeWriteInBatchFlow(
                $request,
                $schema,
                $flow,
                $requestedSubjectIds,
                $skippedUnmanagedSubjectIds,
                $layerId,
                $space,
            );
        }

        $selectedSubjectIds = $this->selectSubjectIdsForBatchFlow($schema, $requestedSubjectIds, $request->slotFilters ?? [], $layerId);
        if ([] === $selectedSubjectIds) {
            return new PersistedBatchMovement(
                ok: true,
                affectedSubjects: 0,
                affectedSlots: 0,
                insertedLedgerRows: 0,
                recordedAt: $request->recordedAt(),
                skippedUnmanagedSubjectIds: $skippedUnmanagedSubjectIds,
            );
        }

        $inventoryRows = $this->loadInventoryRowsForSubjects($schema, $selectedSubjectIds, $layerId);
        $effectiveSlotFilters = $request->slotFilters ?? [];

        /** @var \Closure(TStorageInventoryRow): SubjectId $subjectGetter */
        $subjectGetter = function (array $row): SubjectId {
            /** @var TStorageInventoryRow $row */

            return $this->storageRowSubjectId($row);
        };
        /**
         * @var \Closure(TStorageInventoryRow): list<array{0: array<non-empty-string, non-empty-string>, 1: int}>
         */
        $slotRowGetter = function (array $row): array {
            /** @var TStorageInventoryRow $row */

            return $this->storageRowSlotTuple($row);
        };
        /** @var \Closure(list<TStorageInventoryRow>): int $quantityGetter */
        $quantityGetter = function (array $rows) use ($schema, $effectiveSlotFilters, $request): int {
            /** @var list<TStorageInventoryRow> $rows */
            $subjectId = $this->storageRowSubjectId($rows[0]);

            return $this->resolveBatchFlowQuantity(
                $schema,
                $rows,
                $effectiveSlotFilters,
                $subjectId,
                $request->quantitiesBySubjectId,
                $request->quantityResolver,
            );
        };

        $batch = QuantityStateBatch::fromRows(
            space: $space,
            rows: $inventoryRows,
            subjectGetter: $subjectGetter,
            slotRowGetter: $slotRowGetter,
            quantityGetter: $quantityGetter,
        );

        $executedBatch = (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $space,
            flow: $flow,
            context: $request->executionContext,
            params: $request->params,
        );

        $persisted = $this->persistBatch(new PersistBatchMovement(
            movementBatch: $executedBatch,
            subjectIdResolver: static fn (SubjectId $subjectId): SubjectId => $subjectId,
            movementTypeOwnerKey: $request->movementTypeOwnerKey,
            movementTypeCode: $request->movementTypeCode,
            reference: $request->reference,
            actor: $request->actor,
            surface: $request->surface,
            recordedAt: $request->recordedAt(),
        ));

        // Decorate the persistence result with the unmanaged-subject skip list.
        // persistBatch() doesn't know about that — it's a pre-engine concern —
        // so we recompose the final result here.
        return new PersistedBatchMovement(
            ok: $persisted->ok,
            affectedSubjects: $persisted->affectedSubjects,
            affectedSlots: $persisted->affectedSlots,
            insertedLedgerRows: $persisted->insertedLedgerRows,
            recordedAt: $persisted->recordedAt,
            conflicts: $persisted->conflicts,
            skippedUnmanagedSubjectIds: $skippedUnmanagedSubjectIds,
        );
    }

    /**
     * True when every step of the flow originates from nil — a create / write-in flow
     * (e.g. goods receipt `nil → atp`). Such a flow's per-slot delta is `+qty` independent
     * of current stock (the engine creates from nothing), which persistBatch() applies to the
     * authoritative (locked) row — so it can run even for a brand-new, zero-stock subject. The
     * current on-hand is still seeded into the solver so the ledger records the true
     * pre-movement balance (`initial_to`); see {@see executeWriteInBatchFlow}. A flow with any
     * non-null source reads existing stock through the main state-loading path.
     */
    /**
     * Resolve a flow given by name against the **bootstrapped** definition, not the stored schema.
     *
     * Flows are code, and deliberately not persisted: {@see trySchema()} rebuilds a definition from
     * the dimension rows alone, so the space it compiles has an empty flow map. That was invisible
     * while every caller handed in a built `Flow`; the moment one names a registered flow instead,
     * the lookup has to go to the definition the adapter bootstrapped, which is the only place the
     * flows exist.
     *
     * A name present on several layers (`clear_ctd` is defined on both) is **refused** unless the
     * request named its layer. Picking one would silently execute the wrong pattern — the physical
     * `clear_ctd` destroys everything the scope matched, the commercial one only `ctd`.
     */
    private function resolveFlow(string | Flow $flow, ?string $layerName): Flow
    {
        if ($flow instanceof Flow) {
            return $flow;
        }

        $layered = $this->layeredSchema ?? $this->tryLayeredSchema()
            ?? throw new SchemaException('InvFlux schema has not been bootstrapped yet.', 'schema_not_bootstrapped');

        $layers = null !== $layerName
            ? [$layerName => $layered->layers[$layerName]
                ?? throw new SchemaException(sprintf('Layer "%s" not found in bootstrapped schema.', $layerName), 'unknown_layer')]
            : $layered->layers;

        $found = [];
        foreach ($layers as $name => $layer) {
            if (isset($layer->flows[$flow])) {
                $found[$name] = $layer->flows[$flow];
            }
        }

        if ([] === $found) {
            throw new SchemaException(
                sprintf('Flow "%s" is not registered on the bootstrapped schema.', $flow),
                'unknown_flow',
                ['flow' => $flow, 'layer' => $layerName],
            );
        }

        if (count($found) > 1) {
            throw new SchemaException(
                sprintf(
                    'Flow "%s" is defined on several layers (%s) — name the layer on the request.',
                    $flow,
                    implode(', ', array_keys($found)),
                ),
                'ambiguous_flow',
                ['flow' => $flow, 'layers' => array_keys($found)],
            );
        }

        return reset($found)->toFlow();
    }

    private function flowIsWriteIn(Flow $flow): bool
    {
        $steps = $flow->steps();
        if ([] === $steps) {
            return false;
        }
        foreach ($steps as $step) {
            if (null !== $step->from) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run a write-in / create batch flow (see {@see flowIsWriteIn()}) over the requested
     * subjects. The movement delta is state-independent (`+qty`, applied by persistBatch() to
     * the authoritative row), so even a first receipt of a brand-new, zero-stock subject posts
     * correctly. But the ledger's audit "before" balance is NOT state-independent: the current
     * on-hand of each target slot is seeded into the solver so the recorded `initial_to` is the
     * true pre-movement quantity (a brand-new subject simply has no rows → 0, which is correct).
     * The one batched state read is worth an accurate ledger. Subjects already filtered for the
     * `ivfx_governed` contract by the caller.
     *
     * @param list<SubjectId>|null $requestedSubjectIds        governed subjects (post ivfx_governed filter)
     * @param list<int>            $skippedUnmanagedSubjectIds
     */
    private function executeWriteInBatchFlow(
        StorageBatchFlowRequest $request,
        SlotSpaceDefinition $schema,
        Flow $flow,
        ?array $requestedSubjectIds,
        array $skippedUnmanagedSubjectIds,
        ?int $layerId,
        SlotSpace $space,
    ): PersistedBatchMovement {
        if (null === $requestedSubjectIds || [] === $requestedSubjectIds) {
            throw new ConfigurationException(
                'A write-in batch flow requires an explicit, non-empty subjectIds list.',
                'write_in_requires_subject_ids',
            );
        }

        // Seed each subject's current on-hand (grouped from one batched read) so the solver
        // records the real pre-movement balance as the ledger `initial_to`. The delta stays
        // state-independent — persistBatch() applies `+qty` to the authoritative row regardless —
        // so seeding changes only the audit "before", never the resulting stock. A subject with
        // no inventory rows (brand-new) yields an empty tuple list → 0, which is the true before.
        $currentTuplesBySubject = [];
        foreach ($this->loadInventoryRowsForSubjects($schema, $requestedSubjectIds, $layerId) as $inventoryRow) {
            $subjectKey = $this->storageRowSubjectId($inventoryRow)->id;
            foreach ($this->storageRowSlotTuple($inventoryRow) as $tuple) {
                $currentTuplesBySubject[$subjectKey][] = $tuple;
            }
        }

        $batch = QuantityStateBatch::fromRows(
            space: $space,
            rows: $requestedSubjectIds,
            subjectGetter: static fn (SubjectId $subjectId): SubjectId => $subjectId,
            slotRowGetter: static fn (SubjectId $subjectId): array => $currentTuplesBySubject[$subjectId->id] ?? [],
            quantityGetter: function (array $rows) use ($request): int {
                /** @var list<SubjectId> $rows */
                return $this->resolveWriteInQuantity($rows[0], $request);
            },
        );

        $executedBatch = (new BatchMovementEngine(new MovementEngine()))->execute(
            batch: $batch,
            space: $space,
            flow: $flow,
            context: $request->executionContext,
            params: $request->params,
        );

        $persisted = $this->persistBatch(new PersistBatchMovement(
            movementBatch: $executedBatch,
            subjectIdResolver: static fn (SubjectId $subjectId): SubjectId => $subjectId,
            movementTypeOwnerKey: $request->movementTypeOwnerKey,
            movementTypeCode: $request->movementTypeCode,
            reference: $request->reference,
            actor: $request->actor,
            surface: $request->surface,
            recordedAt: $request->recordedAt(),
        ));

        return new PersistedBatchMovement(
            ok: $persisted->ok,
            affectedSubjects: $persisted->affectedSubjects,
            affectedSlots: $persisted->affectedSlots,
            insertedLedgerRows: $persisted->insertedLedgerRows,
            recordedAt: $persisted->recordedAt,
            conflicts: $persisted->conflicts,
            skippedUnmanagedSubjectIds: $skippedUnmanagedSubjectIds,
        );
    }

    /**
     * Resolve the movement quantity for one subject in a write-in batch flow, from the
     * request's `quantitiesBySubjectId` map or its `quantityResolver` (called with no
     * current-state rows, since a write-in reads none).
     */
    private function resolveWriteInQuantity(SubjectId $subjectId, StorageBatchFlowRequest $request): int
    {
        if (null !== $request->quantitiesBySubjectId && isset($request->quantitiesBySubjectId[$subjectId->id])) {
            return $request->quantitiesBySubjectId[$subjectId->id];
        }
        if (null !== $request->quantityResolver) {
            return ($request->quantityResolver)($subjectId, []);
        }

        throw new ConfigurationException(
            sprintf('Write-in batch flow is missing a quantity for subject %d.', $subjectId->id),
            'missing_write_in_quantity',
        );
    }

    /**
     * Execute a boundary flow across all layers for a batch of subjects.
     *
     * Loads per-layer inventory state, runs LayeredMovementEngine for each subject,
     * and persists the resulting deltas from all layers in a single transaction.
     */
    public function executeBatchBoundaryFlowFromStorage(StorageBoundaryFlowRequest $request): PersistedBatchMovement
    {
        $layered = $this->layeredSchema ?? $this->tryLayeredSchema();
        null !== $layered
            || throw new SchemaException('InvFlux schema has not been bootstrapped yet.', 'schema_not_bootstrapped');

        $resolvedLayers = array_map(
            fn (SlotSpaceDefinition $def): SlotSpaceDefinition => $this->resolveSharedRefDimensions($def),
            $layered->layers,
        );
        $spaces = LayeredSlotSpaceDefinition::define($resolvedLayers)->toSlotSpaces();
        foreach ($spaces as $space) {
            $space->subjectKeyResolver(static fn (SubjectId $subjectId): string => (string) $subjectId->id);
        }

        $flowLayerNames = $request->flow->layerNames();

        // Collect all relevant subject IDs (union across all layers).
        $allSubjectIds = null;
        if (null !== $request->subjectIds) {
            $seen = [];
            foreach ($flowLayerNames as $layerName) {
                $layerId = $this->layerIdBySlug($layerName);
                $layerSchema = $layered->layers[$layerName]
                    ?? throw new SchemaException(sprintf('Layer "%s" not found.', $layerName), 'unknown_layer');
                $layerSubjects = $this->selectSubjectIdsForBatchFlow($layerSchema, $request->subjectIds, [], $layerId);
                foreach ($layerSubjects as $subjectId) {
                    $seen[$subjectId->id] = $subjectId;
                }
            }

            $allSubjectIds = array_values($seen);
        }

        if (null !== $allSubjectIds && [] === $allSubjectIds) {
            return new PersistedBatchMovement(
                ok: true,
                affectedSubjects: 0,
                affectedSlots: 0,
                insertedLedgerRows: 0,
                recordedAt: $request->recordedAt(),
            );
        }

        // Load per-layer inventory rows for all subjects.
        /** @var array<string, list<TStorageInventoryRow>> $rowsByLayer */
        $rowsByLayer = [];
        foreach ($flowLayerNames as $layerName) {
            $layerSchema = $layered->layers[$layerName]
                ?? throw new SchemaException(sprintf('Layer "%s" not found.', $layerName), 'unknown_layer');
            $layerId = $this->layerIdBySlug($layerName);
            $rowsByLayer[$layerName] = $this->loadInventoryRowsForSubjects(
                $layerSchema,
                $allSubjectIds ?? $this->selectSubjectIdsForBatchFlow($layerSchema, null, [], $layerId),
                $layerId,
            );
        }

        // When a DimensionScope is present, restrict each layer's rows to the bound value
        // (or any descendant in the dimension hierarchy for non-Root sharedRef dimensions).
        if (null !== $request->dimensionScope) {
            $scope = $request->dimensionScope;
            foreach ($rowsByLayer as $layerName => $rows) {
                $layerSchema = $layered->layers[$layerName]
                    ?? throw new SchemaException(sprintf('Layer "%s" not found.', $layerName), 'unknown_layer');
                $rowsByLayer[$layerName] = array_values(array_filter(
                    $rows,
                    fn (array $row): bool => $this->rowMatchesDimensionScope($layerSchema, $row, $scope),
                ));
            }
        }

        // Group rows by subject ID so we can run LayeredMovementEngine per subject.
        /** @var array<int, array<string, list<TStorageInventoryRow>>> $perSubjectLayerRows */
        $perSubjectLayerRows = [];
        foreach ($rowsByLayer as $layerName => $rows) {
            foreach ($rows as $row) {
                $subjectId = $row['subject_id'];
                $perSubjectLayerRows[$subjectId][$layerName][] = $row;
            }
        }

        if ([] === $perSubjectLayerRows) {
            return new PersistedBatchMovement(
                ok: true,
                affectedSubjects: 0,
                affectedSlots: 0,
                insertedLedgerRows: 0,
                recordedAt: $request->recordedAt(),
            );
        }

        $engine = new LayeredMovementEngine(new MovementEngine());

        $slotIds = $this->slotIdsByKey();

        /** @var array<int, array<string, PersistDeltaRow>> $allDeltaRowsBySubject */
        $allDeltaRowsBySubject = [];

        foreach ($perSubjectLayerRows as $subjectIdInt => $layerRowMap) {
            $subjectId = new SubjectId($subjectIdInt);

            // Build per-layer QuantityState.
            /** @var array<string, QuantityState> $layerStates */
            $layerStates = [];
            foreach ($flowLayerNames as $layerName) {
                $layerRows = $layerRowMap[$layerName] ?? [];
                $layerSpace = $spaces[$layerName];

                $slotTuples = [];
                foreach ($layerRows as $row) {
                    foreach ($this->storageRowSlotTuple($row) as [$dims, $qty]) {
                        $slot = $layerSpace->slot($dims);
                        $slotTuples[] = [$slot, $qty];
                    }
                }

                $layerStates[$layerName] = new QuantityState($layerSpace, $slotTuples);
            }

            $layeredState = LayeredQuantityState::define($layerStates);

            $quantity = null !== $request->quantitiesBySubjectId
                ? ($request->quantitiesBySubjectId[$subjectIdInt] ?? 0)
                : 0;

            $layeredResult = $engine->execute($layeredState, $spaces, $request->flow, $quantity, $subjectId);

            foreach ($layeredResult->byLayer() as $layerResult) {
                foreach ($layerResult->deltas() as $delta) {
                    $slotKey = $delta->slot->key;
                    $slotId = $slotIds[$slotKey]
                        ?? throw new SchemaException(
                            sprintf('Unknown slot key "%s".', $slotKey),
                            'unknown_slot_key',
                            ['slotKey' => $slotKey],
                        );

                    $key = $subjectIdInt."\0".$slotId;
                    if (!isset($allDeltaRowsBySubject[$subjectIdInt][$key])) {
                        $allDeltaRowsBySubject[$subjectIdInt][$key] = PersistDeltaRow::create(
                            $subjectIdInt,
                            $slotId,
                            $slotKey,
                            null,
                            null,
                        );
                    }

                    $allDeltaRowsBySubject[$subjectIdInt][$key]->delta += $this->normalizeQuantity($delta->delta);
                }
            }
        }

        // Flatten non-zero delta rows.
        /** @var list<PersistDeltaRow> $deltaRows */
        $deltaRows = [];
        foreach ($allDeltaRowsBySubject as $rowsByKey) {
            foreach ($rowsByKey as $row) {
                if (0 !== $row->delta) {
                    $deltaRows[] = $row;
                }
            }
        }

        if ([] === $deltaRows) {
            return new PersistedBatchMovement(
                ok: true,
                affectedSubjects: count($perSubjectLayerRows),
                affectedSlots: 0,
                insertedLedgerRows: 0,
                recordedAt: $request->recordedAt(),
            );
        }

        $movementTypeId = $this->resolveMovementTypeId($request->movementTypeOwnerKey, $request->movementTypeCode);
        $actorDbId = null !== $request->actor
            ? $this->resolveActorId($request->actor->typeCode, $request->actor->actorId)
            : null;
        $refTypeId = $this->resolveRefTypeIdStrict($request->reference?->type);
        $referenceIdStr = $request->reference?->idAsString();
        $surfaceDbId = null !== $request->surface
            ? $this->resolveSurfaceId($request->surface->typeCode, $request->surface->ref)
            : null;

        $outcome = $this->persistDeltaRows(
            deltaRows: $deltaRows,
            movementTypeOwnerKey: $request->movementTypeOwnerKey,
            movementTypeCode: $request->movementTypeCode,
            referenceType: $request->reference?->type,
            referenceId: $referenceIdStr,
            actorTypeCode: $request->actor?->typeCode,
            actorId: $request->actor?->actorId,
            recordedAt: $request->recordedAt(),
            surface: $request->surface,
            insertLedgerRows: function (array $lockedBalanceByKey) use ($deltaRows, $movementTypeId, $actorDbId, $refTypeId, $surfaceDbId, $request, $referenceIdStr): int {
                // Append-only ledger: one bulk INSERT via attrecord insertAll().
                $refId = $this->normalizeNullableBinary16Ref($referenceIdStr);
                $recordedAt = $request->recordedAt();
                // One shared timestamp across every row of this movement — the ids' embedded
                // instant then matches the single `recorded_at` (see UuidV7Minter::mintMultiple()).
                $ids = $this->uuidMinter->mintMultiple(\count($deltaRows));
                $rows = [];
                $i = 0;
                foreach ($deltaRows as $row) {
                    // A row is either the source (delta < 0 → initial_from) or the
                    // destination (delta > 0 → initial_to); record the slot's
                    // balance read UNDER LOCK just before the delta was applied.
                    $isSource = $row->delta < 0;
                    $preBalance = $this->normalizeNullableQuantity(
                        $lockedBalanceByKey[$row->subjectId."\0".$row->slotId] ?? null,
                    );
                    $rows[] = InventoryLedger::newWith([
                        'id'               => $ids[$i++],
                        'subject_id'       => $row->subjectId,
                        'movement_type_id' => $movementTypeId,
                        'from_slot_id'     => $isSource ? $row->slotId : null,
                        'to_slot_id'       => $row->delta > 0 ? $row->slotId : null,
                        'quantity'         => abs($row->delta),
                        'initial_from'     => $isSource ? $preBalance : null,
                        'initial_to'       => $row->delta > 0 ? $preBalance : null,
                        'ref_type_id'      => $refTypeId,
                        'ref_id'           => $refId,
                        'actor_id'         => $actorDbId,
                        'surface_id'       => $surfaceDbId,
                        'recorded_at'      => $recordedAt,
                    ]);
                }

                return (new RecordSet($rows))->insertAll()?->inserted ?? 0;
            },
        );

        return new PersistedBatchMovement(
            ok: $outcome->ok,
            affectedSubjects: count($perSubjectLayerRows),
            affectedSlots: $outcome->affectedSlots,
            insertedLedgerRows: $outcome->insertedLedgerRows,
            recordedAt: $request->recordedAt(),
        );
    }

    /**
     * @param list<TStorageInventoryRow>                                $rows
     * @param TSlotFilters                                              $slotFilters
     * @param array<int, int>|null                                      $quantitiesBySubjectId
     * @param \Closure(SubjectId, list<TStorageInventoryRow>): int|null $quantityResolver
     */
    private function resolveBatchFlowQuantity(
        SlotSpaceDefinition $schema,
        array $rows,
        array $slotFilters,
        SubjectId $subjectId,
        ?array $quantitiesBySubjectId,
        ?\Closure $quantityResolver,
    ): int {
        if ($quantityResolver instanceof \Closure) {
            return $quantityResolver($subjectId, $rows);
        }

        if (null !== $quantitiesBySubjectId) {
            return $quantitiesBySubjectId[$subjectId->id] ?? 0;
        }

        return $this->batchQuantityForStorageRows($schema, $rows, $slotFilters);
    }

    /** Remove empty authoritative rows for one dimension value after it has been drained. */
    private function deleteZeroQuantityRowsForDimensionValue(string $dimensionName, string $value): void
    {
        $this->session->exec(sprintf(
            'DELETE s
             FROM %s s
             JOIN %s ss ON ss.id = s.slot_id
             WHERE ss.%s = :value
               AND s.quantity = 0',
            $this->table(self::TABLE_INVENTORY_STATE),
            $this->table(self::TABLE_SLOTSPACE),
            $this->dimensionColumn($dimensionName),
        ), ['value' => $value]);
    }

    /**
     * @param list<TStorageInventoryRow> $rows
     * @param TSlotFilters               $slotFilters
     */
    private function batchQuantityForStorageRows(
        SlotSpaceDefinition $schema,
        array $rows,
        array $slotFilters,
    ): int {
        $quantity = 0;
        foreach ($rows as $row) {
            if ([] !== $slotFilters && !$this->rowMatchesSlotFilters($schema, $row, $slotFilters)) {
                continue;
            }

            $quantity += $row['quantity'];
        }

        return $quantity;
    }

    /**
     * @param TStorageInventoryRow $row
     */
    private function storageRowSubjectId(array $row): SubjectId
    {
        return new SubjectId($row['subject_id']);
    }

    /**
     * @param TStorageInventoryRow $row
     *
     * @return list<array{0: array<non-empty-string, non-empty-string>, 1: int}>
     */
    private function storageRowSlotTuple(array $row): array
    {
        return [[$row['dimensions'], $row['quantity']]];
    }

    /** Ensure the adapter's internal schema-migration movement types exist. */
    private function ensureInternalMovementTypes(): void
    {
        $this->registerMovementTypes(self::INTERNAL_MOVEMENT_OWNER, [
            new MovementTypeDefinition(
                self::INTERNAL_MOVEMENT_DRAIN_VALUE,
                'Dimension Value Migration',
                'Move quantities out of a dimension value before schema cleanup.',
            ),
        ]);
    }

    /**
     * Split a requested-subject list into [participating, skipped] sublists.
     *
     * Two independent reasons disqualify a subject from an inventory movement, and both are
     * checked here so every caller — adapter, CLI, future integrations — gets the behaviour
     * automatically rather than re-deriving it:
     *
     *  - **not governed** (`ivfx_governed = 0`): InvFlux does not own this subject's count.
     *  - **owns no slots** (`kind` is Aggregate / Kit / NonStocked): there is no slot state for a
     *    movement to land on. Governance cannot substitute for this — a *governed* NonStocked
     *    subject is still uncountable, which is precisely the hole this closes.
     *
     * **Silent skip, not exception**, for both. An order that mixes a physical product with a
     * service or a digital download is an ordinary order; the service line simply has nothing for
     * InvFlux to count. Throwing would fail the whole booking over a line that was never
     * inventoried in the first place.
     *
     * Subjects with no `invflux_subjects` row participate — that happens when a brand-new subject
     * is referenced before reconciliation has seen it; the engine's downstream subject-existence
     * checks will surface a real error if the subject truly doesn't exist.
     *
     * @param list<SubjectId> $subjectIds
     *
     * @return array{0: list<SubjectId>, 1: list<int>} [participating SubjectIds, skipped subject_id ints]
     */
    private function partitionByMovementEligibility(array $subjectIds): array
    {
        if ([] === $subjectIds) {
            return [[], []];
        }

        $idMap = [];
        $params = [];
        $placeholders = [];
        foreach ($subjectIds as $index => $subjectId) {
            $name = 'sm_subject_'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $subjectId->id;
            $idMap[$subjectId->id] = $subjectId;
        }

        $rows = $this->fetchAllRows(sprintf(
            'SELECT id, ivfx_governed, kind FROM %s WHERE id IN (%s)',
            $this->table(self::TABLE_SUBJECTS),
            implode(', ', $placeholders),
        ), $params);

        $skipped = [];
        foreach ($rows as $row) {
            $subjectIdInt = $this->requireIntField($row, 'id');
            $ungoverned = 0 === (int) ($row['ivfx_governed'] ?? 1);
            // An unparseable kind is not a reason to skip: it means this row predates a kind the
            // code knows, and the governance answer above is still trustworthy.
            $slotless = false === (SubjectKind::tryFrom((string) ($row['kind'] ?? ''))?->ownsSlots() ?? true);
            if ($ungoverned || $slotless) {
                $skipped[] = $subjectIdInt;
            }
        }

        if ([] === $skipped) {
            return [array_values($idMap), []];
        }

        $participating = [];
        foreach ($idMap as $idInt => $subjectId) {
            if (!in_array($idInt, $skipped, true)) {
                $participating[] = $subjectId;
            }
        }

        return [$participating, $skipped];
    }

    /**
     * Select subjects that participate in one storage-backed batch flow.
     *
     * @param list<SubjectId>|null $subjectIds
     * @param TSlotFilters         $slotFilters
     * @param int|null             $layerId     when set, restrict to slots belonging to this layer
     *
     * @return list<SubjectId>
     */
    private function selectSubjectIdsForBatchFlow(
        SlotSpaceDefinition $schema,
        ?array $subjectIds,
        array $slotFilters,
        ?int $layerId = null,
    ): array {
        $params = [];
        $subjectSql = '';
        if (null !== $subjectIds) {
            if ([] === $subjectIds) {
                return [];
            }

            $placeholders = [];
            foreach ($subjectIds as $index => $subjectId) {
                $name = 'subject_id_'.$index;
                $placeholders[] = ':'.$name;
                $params[$name] = $subjectId->id;
            }

            $subjectSql = ' AND s.subject_id IN ('.implode(', ', $placeholders).')';
        }

        $layerSql = null !== $layerId ? ' AND ss.layer_id = :layer_id' : '';
        if (null !== $layerId) {
            $params['layer_id'] = $layerId;
        }

        $filterSql = [] === $slotFilters ? '' : $this->slotFilterSql($schema, $slotFilters, 'ss');
        $rows = $this->fetchAllRows(sprintf(
            'SELECT DISTINCT s.subject_id
             FROM %s s
             JOIN %s ss ON ss.id = s.slot_id
             WHERE ss.active = 1
               AND s.quantity <> 0%s%s%s
             ORDER BY s.subject_id',
            $this->table(self::TABLE_INVENTORY_STATE),
            $this->table(self::TABLE_SLOTSPACE),
            $subjectSql,
            $layerSql,
            $filterSql,
        ), [...$params, ...$this->slotFilterParams($slotFilters)]);
        $selected = [];
        foreach ($rows as $row) {
            $selected[] = new SubjectId($this->requireIntField($row, 'subject_id'));
        }

        return $selected;
    }

    /**
     * Load the full active inventory state for the given subjects.
     *
     * @param list<SubjectId> $subjectIds
     * @param int|null        $layerId    when set, restrict to slots belonging to this layer
     *
     * @return list<TStorageInventoryRow>
     */
    private function loadInventoryRowsForSubjects(SlotSpaceDefinition $schema, array $subjectIds, ?int $layerId = null): array
    {
        if ([] === $subjectIds) {
            return [];
        }

        $dimensionSelect = $this->slotDimensionSelectSql($schema, 'ss', 'dim_');
        $placeholders = [];
        $params = [];
        foreach ($subjectIds as $index => $subjectId) {
            $name = 'subject_id_'.$index;
            $placeholders[] = ':'.$name;
            $params[$name] = $subjectId->id;
        }

        $layerSql = null !== $layerId ? ' AND ss.layer_id = :layer_id' : '';
        if (null !== $layerId) {
            $params['layer_id'] = $layerId;
        }

        $fetchedRows = $this->fetchAllRows(sprintf(
            'SELECT s.subject_id, ss.slot_key, s.quantity, %s
             FROM %s s
             JOIN %s ss ON ss.id = s.slot_id
             WHERE ss.active = 1%s
               AND s.subject_id IN (%s)
             ORDER BY s.subject_id, ss.id',
            $dimensionSelect,
            $this->table(self::TABLE_INVENTORY_STATE),
            $this->table(self::TABLE_SLOTSPACE),
            $layerSql,
            implode(', ', $placeholders),
        ), $params);

        $rows = [];
        foreach ($fetchedRows as $row) {
            $rows[] = [
                'subject_id' => $this->requireIntField($row, 'subject_id'),
                'slot_key'   => $this->requireStringField($row, 'slot_key'),
                'quantity'   => $this->requireIntField($row, 'quantity'),
                'dimensions' => $this->extractSlotDimensions($schema, $row, 'dim_'),
            ];
        }

        return $rows;
    }

    /**
     * Check whether one loaded inventory row belongs to the operation subset.
     *
     * @param TStorageInventoryRow $row
     * @param TSlotFilters         $slotFilters
     */
    private function rowMatchesSlotFilters(SlotSpaceDefinition $schema, array $row, array $slotFilters): bool
    {
        unset($schema);

        foreach ($slotFilters as $dimensionName => $filterValue) {
            $actual = $row['dimensions'][$dimensionName] ?? null;
            if (is_array($filterValue)) {
                if (!in_array($actual, $filterValue, true)) {
                    return false;
                }

                continue;
            }

            if ($actual !== $filterValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether one inventory row belongs to the given DimensionScope, accounting for
     * layer-specific hierarchy resolution.
     *
     * - Exact value match always passes for every layer.
     * - For sharedRef dimensions with Level or Leaf selectors, descendant values also match: a
     *   code is its full path, so the row's value sits under the scope's when it starts with the
     *   scope's code plus a separator. Requiring the separator is what keeps `oh/main` from
     *   claiming `oh/main2`, and it costs no lookup — both codes are already in hand.
     * - Root selectors and non-sharedRef dimensions use exact match only.
     *
     * @param TStorageInventoryRow $row
     */
    private function rowMatchesDimensionScope(
        SlotSpaceDefinition $layerSchema,
        array $row,
        DimensionScope $scope,
    ): bool {
        $rowValue = $row['dimensions'][$scope->dimension] ?? null;
        if ($rowValue === $scope->value) {
            return true;
        }
        if (null === $rowValue) {
            return false;
        }

        foreach ($layerSchema->dimensions as $dim) {
            if ($dim->name !== $scope->dimension) {
                continue;
            }
            // Only sharedRef dimensions with Level or Leaf selectors allow descendant matching.
            if (!$dim->isSharedRef || DimensionValueSelectorKind::Root === $dim->valueSelector?->kind) {
                break;
            }

            if ('' === $scope->value) {
                break;
            }

            // The equal case returned above, so this is strictly-below by construction.
            return str_starts_with($rowValue, $scope->value.'/');
        }

        return false;
    }

    /**
     * Resolve and aggregate one single-subject movement into guarded delta rows.
     *
     * @param array<string, string> $slotIds
     *
     * @return list<PersistDeltaRow>
     */
    private function singleDeltaRows(PersistMovement $movement, array $slotIds): array
    {
        /** @var array<string, PersistDeltaRow> $rowsByKey */
        $rowsByKey = [];
        foreach ($movement->movementResult->deltas() as $delta) {
            $slotKey = $delta->slot->key;
            $slotId = $slotIds[$slotKey] ?? null;
            (null !== $slotId)
                || throw new SchemaException(
                    sprintf('Unknown slot key "%s". Bootstrap the schema before persisting movements.', $slotKey),
                    'unknown_slot_key',
                    ['slotKey' => $slotKey],
                );

            $key = (string) $movement->subjectId->id."\0".$slotId;
            $guard = $movement->guardFor($slotKey);
            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = PersistDeltaRow::create(
                    $movement->subjectId->id,
                    $slotId,
                    $slotKey,
                    $this->normalizeNullableGuardQuantity($guard?->min, 'minQuantity'),
                    $this->normalizeNullableGuardQuantity($guard?->max, 'maxQuantity'),
                );
            }

            $rowsByKey[$key]->delta += $this->normalizeQuantity($delta->delta);
        }

        return $this->sortedNonZeroDeltaRows($rowsByKey);
    }

    /**
     * Resolve and aggregate one batch movement into guarded delta rows.
     *
     * @param array<string, string> $slotIds
     *
     * @return array{0: list<PersistDeltaRow>, 1: array<int, true>}
     */
    private function batchDeltaRows(PersistBatchMovement $movement, array $slotIds): array
    {
        /** @var array<string, PersistDeltaRow> $rowsByKey */
        $rowsByKey = [];
        /** @var array<int, true> $affectedSubjects */
        $affectedSubjects = [];
        foreach ($movement->movementBatch->deltas() as $delta) {
            $subjectId = $movement->subjectIdFor($delta->subject);
            $slotKey = $delta->slot->key;
            $slotId = $slotIds[$slotKey] ?? null;
            (null !== $slotId)
                || throw new SchemaException(
                    sprintf('Unknown slot key "%s". Bootstrap the schema before persisting movements.', $slotKey),
                    'unknown_slot_key',
                    ['slotKey' => $slotKey],
                );

            $affectedSubjects[$subjectId->id] = true;
            $key = (string) $subjectId->id."\0".$slotId;
            $guard = $movement->guardFor($subjectId, $slotKey);
            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = PersistDeltaRow::create(
                    $subjectId->id,
                    $slotId,
                    $slotKey,
                    $this->normalizeNullableGuardQuantity($guard?->min, 'minQuantity'),
                    $this->normalizeNullableGuardQuantity($guard?->max, 'maxQuantity'),
                );
            }

            $rowsByKey[$key]->delta += $this->normalizeQuantity($delta->delta);
        }

        return [$this->sortedNonZeroDeltaRows($rowsByKey), $affectedSubjects];
    }

    /**
     * Sort guarded delta rows in deterministic subject/slot order and drop zero deltas.
     *
     * @param array<string, PersistDeltaRow> $rowsByKey
     *
     * @return list<PersistDeltaRow>
     */
    private function sortedNonZeroDeltaRows(array $rowsByKey): array
    {
        $rows = array_values(array_filter(
            $rowsByKey,
            static fn (PersistDeltaRow $row): bool => 0 !== $row->delta,
        ));

        usort(
            $rows,
            static fn (PersistDeltaRow $left, PersistDeltaRow $right): int => [$left->subjectId, $left->slotId]
                <=>
                [$right->subjectId, $right->slotId],
        );

        return $rows;
    }

    /**
     * Persist staged delta rows atomically, returning conflicts instead of partial writes.
     *
     * @param list<PersistDeltaRow>             $deltaRows
     * @param \Closure(array<string, int>): int $insertLedgerRows
     */
    private function persistDeltaRows(
        array $deltaRows,
        string $movementTypeOwnerKey,
        string $movementTypeCode,
        ?string $referenceType,
        ?string $referenceId,
        ?string $actorTypeCode,
        ?string $actorId,
        \DateTimeImmutable $recordedAt,
        \Closure $insertLedgerRows,
        ?SurfaceReference $surface = null,
    ): PersistDeltaRowsOutcome {
        if ([] === $deltaRows) {
            return PersistDeltaRowsOutcome::success(0, 0);
        }

        // Pre-transaction: expand with ancestor fan-out rows for hierarchical loc values.
        $deltaRows = $this->expandWithAncestorDeltas($deltaRows);

        // Fast path: a single-subject movement with no hierarchy (the common
        // checkout reserve/release — one subject, a couple of slots within one
        // layer, no ancestor fan-out) bypasses the temp-table machinery
        // entirely. The §3.1 spike measured that machinery (CREATE TEMP +
        // INSERT temp + insertMissing INSERT…SELECT + temp JOINs) at ~64% of
        // the engine transaction even uncontended — pure round-trip overhead
        // for a movement small enough to address directly. Multi-subject bulk
        // movements and any hierarchical (ancestor-fan-out) movement stay on
        // the temp-table path.
        $fastPath = !$this->hasHierarchy() && $this->isSingleSubjectDeltaSet($deltaRows);

        if (!$fastPath) {
            $this->prepareTemporaryDeltaTable();
            $this->clearTemporaryDeltaTable();
        }

        try {
            $outcome = $this->transactional(fn (): PersistDeltaRowsOutcome => $fastPath
                ? $this->persistSingleSubjectDeltaRowsFast(
                    $deltaRows,
                    $movementTypeOwnerKey,
                    $movementTypeCode,
                    $referenceType,
                    $referenceId,
                    $actorTypeCode,
                    $actorId,
                    $recordedAt,
                    $insertLedgerRows,
                    $surface,
                )
                : $this->persistDeltaRowsCore(
                    $deltaRows,
                    $movementTypeOwnerKey,
                    $movementTypeCode,
                    $referenceType,
                    $referenceId,
                    $actorTypeCode,
                    $actorId,
                    $recordedAt,
                    $insertLedgerRows,
                    $surface,
                ));
        } catch (PersistenceException $e) {
            if ('guard_conflict' !== $e->detailCode) {
                throw $e;
            }

            /** @var list<PersistenceConflict> $conflicts */
            $conflicts = is_array($e->context['conflicts'] ?? null)
                ? $e->context['conflicts']
                : [];

            return PersistDeltaRowsOutcome::conflict($conflicts);
        } finally {
            if (!$fastPath) {
                $this->clearTemporaryDeltaTable();
            }
        }

        // Outside the transaction: drain participant post-commit work (cache
        // invalidations, queue dispatches, anything that fires hooks). Done outside
        // try/finally so a participant exception propagates without re-running the
        // temp-table cleanup or re-raising the persist outcome.
        $this->postCommitProjectionParticipants();

        return $outcome;
    }

    /** Notify each registered participant that the outer transaction has committed. */
    private function postCommitProjectionParticipants(): void
    {
        foreach ($this->projectionParticipants as $participant) {
            $participant->postCommit();
        }
    }

    /**
     * Execute the actual staged-delta persistence logic inside an existing transaction.
     *
     * @param list<PersistDeltaRow>             $deltaRows
     * @param \Closure(array<string, int>): int $insertLedgerRows
     */
    private function persistDeltaRowsCore(
        array $deltaRows,
        string $movementTypeOwnerKey,
        string $movementTypeCode,
        ?string $referenceType,
        ?string $referenceId,
        ?string $actorTypeCode,
        ?string $actorId,
        \DateTimeImmutable $recordedAt,
        \Closure $insertLedgerRows,
        ?SurfaceReference $surface = null,
    ): PersistDeltaRowsOutcome {
        $this->insertTemporaryDeltaRows($deltaRows);
        $this->insertMissingInventoryStateRowsFromTemp();

        $lockedRows = $this->lockStateRowsFromTemp();
        (count($lockedRows) === count($deltaRows))
            || throw new PersistenceException(
                'Locked inventory row count did not match the staged delta row count.',
                'locked_row_count_mismatch',
            );

        // Ancestor rows have no guards; only check conflicts on leaf rows.
        $leafLockedRows = array_values(array_filter($lockedRows, static fn (LockedPersistDeltaRow $r): bool => !$r->isAncestor));
        $conflicts = $this->detectDeltaConflicts($leafLockedRows);
        if ([] !== $conflicts) {
            throw new PersistenceException(
                'Guard conflicts prevented the staged inventory rows from being persisted.',
                'guard_conflict',
                ['conflicts' => $conflicts],
            );
        }

        $leafDeltaRows = array_values(array_filter($deltaRows, static fn (PersistDeltaRow $r): bool => !$r->isAncestor));
        $projectionContext = new ProjectionContext(
            PersistDeltaRow::toProjectionRows($leafDeltaRows),
            LockedPersistDeltaRow::toLockedProjectionRows($leafLockedRows),
            $recordedAt,
            $movementTypeOwnerKey,
            $movementTypeCode,
            $referenceType,
            $referenceId,
            $actorTypeCode,
            $actorId,
            $surface,
            new MysqlProjectionRuntime($this->session),
        );
        $projectionLockTargets = $this->collectProjectionLockTargets($projectionContext);
        $this->lockProjectionParticipants($projectionContext, $projectionLockTargets);

        $affectedSlots = $this->applyTempStateDeltas();
        $this->applyProjectionParticipants($projectionContext);
        // Hand the ledger insert the balances read UNDER LOCK (pre-delta), so a
        // row's initial_from/initial_to is the true at-commit source/destination
        // balance — not the pre-transaction read the engine planned from.
        $ledgerCount = $insertLedgerRows($this->lockedBalanceByKey($lockedRows));

        return PersistDeltaRowsOutcome::success($affectedSlots, $ledgerCount);
    }

    /**
     * Map each locked (subject, slot) to its pre-delta balance, keyed
     * `"{subjectId}\0{slotId}"` — the ledger insert reads it for initial_from /
     * initial_to.
     *
     * @param list<LockedPersistDeltaRow> $lockedRows
     *
     * @return array<string, int>
     */
    private function lockedBalanceByKey(array $lockedRows): array
    {
        $map = [];
        foreach ($lockedRows as $row) {
            $map[$row->subjectId."\0".$row->slotId] = $row->quantity;
        }

        return $map;
    }

    /**
     * True when every delta row targets the same subject — the fast-path
     * eligibility condition (combined with !hasHierarchy() by the caller).
     *
     * @param non-empty-list<PersistDeltaRow> $deltaRows
     */
    private function isSingleSubjectDeltaSet(array $deltaRows): bool
    {
        $subjectId = $deltaRows[0]->subjectId;
        foreach ($deltaRows as $row) {
            if ($row->subjectId !== $subjectId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Temp-table-free persistence for a single-subject, non-hierarchical
     * movement. Locks the subject's affected state rows directly, checks
     * guards, applies the deltas, runs projection participants and the
     * ledger insert — same contract and same pessimistic locking as
     * persistDeltaRowsCore, minus the CREATE TEMP / INSERT temp /
     * insertMissing / temp-JOIN round-trips.
     *
     * @param non-empty-list<PersistDeltaRow>   $deltaRows        all sharing one subject_id
     * @param \Closure(array<string, int>): int $insertLedgerRows
     */
    private function persistSingleSubjectDeltaRowsFast(
        array $deltaRows,
        string $movementTypeOwnerKey,
        string $movementTypeCode,
        ?string $referenceType,
        ?string $referenceId,
        ?string $actorTypeCode,
        ?string $actorId,
        \DateTimeImmutable $recordedAt,
        \Closure $insertLedgerRows,
        ?SurfaceReference $surface = null,
    ): PersistDeltaRowsOutcome {
        $lockedRows = $this->lockSingleSubjectStateRows($deltaRows);

        $conflicts = $this->detectDeltaConflicts($lockedRows);
        if ([] !== $conflicts) {
            throw new PersistenceException(
                'Guard conflicts prevented the staged inventory rows from being persisted.',
                'guard_conflict',
                ['conflicts' => $conflicts],
            );
        }

        $projectionContext = new ProjectionContext(
            PersistDeltaRow::toProjectionRows($deltaRows),
            LockedPersistDeltaRow::toLockedProjectionRows($lockedRows),
            $recordedAt,
            $movementTypeOwnerKey,
            $movementTypeCode,
            $referenceType,
            $referenceId,
            $actorTypeCode,
            $actorId,
            $surface,
            new MysqlProjectionRuntime($this->session),
        );
        $this->lockProjectionParticipants($projectionContext, $this->collectProjectionLockTargets($projectionContext));

        $affectedSlots = $this->applySingleSubjectStateDeltas($deltaRows);
        $this->applyProjectionParticipants($projectionContext);
        $ledgerCount = $insertLedgerRows($this->lockedBalanceByKey($lockedRows));

        return PersistDeltaRowsOutcome::success($affectedSlots, $ledgerCount);
    }

    /**
     * Lock the subject's state rows for the given deltas with one
     * `SELECT … WHERE slot_id IN (…) ORDER BY slot_id FOR UPDATE`. Locks in
     * ascending slot_id order (deterministic, matching the temp-table path's
     * ORDER BY) to preserve lock-order discipline. First-touch (subject, slot)
     * pairs with no state row yet get a zero row via INSERT IGNORE, then a
     * re-lock — one extra round-trip only on a brand-new pair, which by
     * definition has no concurrent contenders.
     *
     * @param non-empty-list<PersistDeltaRow> $deltaRows all sharing one subject_id
     *
     * @return list<LockedPersistDeltaRow>
     */
    private function lockSingleSubjectStateRows(array $deltaRows): array
    {
        $subjectId = $deltaRows[0]->subjectId;
        /** @var array<string, PersistDeltaRow> $bySlotId */
        $bySlotId = [];
        foreach ($deltaRows as $row) {
            $bySlotId[$row->slotId] = $row;
        }
        $slotIds = array_keys($bySlotId);

        $locked = $this->selectSingleSubjectLocked($subjectId, $bySlotId);

        // First touch: create any missing (subject, slot) state rows as zero
        // and re-lock. INSERT IGNORE is race-safe against a concurrent first
        // insert; the re-SELECT then locks every row.
        if (count($locked) !== count($slotIds)) {
            foreach ($slotIds as $slotId) {
                $this->session->exec(sprintf(
                    'INSERT IGNORE INTO %s (subject_id, slot_id, quantity) VALUES (:subject_id, :slot_id, 0)',
                    $this->table(self::TABLE_INVENTORY_STATE),
                ), ['subject_id' => $subjectId, 'slot_id' => $slotId]);
            }
            $locked = $this->selectSingleSubjectLocked($subjectId, $bySlotId);
        }

        (count($locked) === count($slotIds))
            || throw new PersistenceException(
                'Locked inventory row count did not match the single-subject delta row count.',
                'locked_row_count_mismatch',
            );

        return $locked;
    }

    /**
     * Run the SELECT … FOR UPDATE for the subject's slots and build
     * LockedPersistDeltaRow rows, carrying each delta's guards/slot_key from
     * the matching staged row.
     *
     * @param array<string, PersistDeltaRow> $bySlotId staged rows keyed by binary slot_id
     *
     * @return list<LockedPersistDeltaRow>
     */
    private function selectSingleSubjectLocked(int $subjectId, array $bySlotId): array
    {
        $slotIds = array_keys($bySlotId);
        $placeholders = implode(', ', array_fill(0, count($slotIds), '?'));

        $rows = [];
        foreach ($this->fetchAllRows(sprintf(
            'SELECT slot_id, quantity FROM %s
             WHERE subject_id = ? AND slot_id IN (%s)
             ORDER BY slot_id
             FOR UPDATE',
            $this->table(self::TABLE_INVENTORY_STATE),
            $placeholders,
        ), [$subjectId, ...$slotIds]) as $row) {
            $slotId = $this->requireStringField($row, 'slot_id');
            $deltaRow = $bySlotId[$slotId] ?? null;
            if (null === $deltaRow) {
                continue;
            }

            $rows[] = new LockedPersistDeltaRow(
                $subjectId,
                $slotId,
                $deltaRow->slotKey,
                $this->requireIntField($row, 'quantity'),
                $deltaRow->delta,
                $deltaRow->minQuantity,
                $deltaRow->maxQuantity,
                false,
            );
        }

        return $rows;
    }

    /**
     * Apply the subject's deltas in one CASE-based UPDATE (one round-trip for
     * any number of slots) while their rows are held under the FOR UPDATE lock.
     *
     * @param non-empty-list<PersistDeltaRow> $deltaRows all sharing one subject_id
     *
     * @return int affected state rows
     */
    private function applySingleSubjectStateDeltas(array $deltaRows): int
    {
        $subjectId = $deltaRows[0]->subjectId;
        $cases = '';
        $caseParams = [];
        $slotIds = [];
        foreach ($deltaRows as $i => $row) {
            $cases .= sprintf(' WHEN :slot%d THEN :delta%d', $i, $i);
            $caseParams['slot'.$i] = $row->slotId;
            $caseParams['delta'.$i] = $row->delta;
            $slotIds['inslot'.$i] = $row->slotId;
        }
        $inPlaceholders = implode(', ', array_map(static fn (string $k): string => ':'.$k, array_keys($slotIds)));

        return $this->session->exec(sprintf(
            'UPDATE %s
                SET quantity = quantity + CASE slot_id%s END,
                    updated_at = CURRENT_TIMESTAMP
              WHERE subject_id = :subject_id AND slot_id IN (%s)',
            $this->table(self::TABLE_INVENTORY_STATE),
            $cases,
            $inPlaceholders,
        ), ['subject_id' => $subjectId, ...$caseParams, ...$slotIds]);
    }

    /** Ensure the temporary guarded-delta table exists for this connection. */
    private function prepareTemporaryDeltaTable(): void
    {
        $this->session->exec(sprintf(
            'CREATE TEMPORARY TABLE IF NOT EXISTS %s (
                subject_id INT UNSIGNED NOT NULL,
                slot_id BINARY(16) NOT NULL,
                slot_key VARCHAR(191) NOT NULL,
                delta INT NOT NULL,
                min_quantity INT NULL,
                max_quantity INT NULL,
                is_ancestor TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (subject_id, slot_id),
                KEY idx_slot (slot_id, subject_id)
            ) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=%s',
            self::TEMP_TABLE_DELTAS,
            $this->session->defaultCollation(),
        ));
    }

    /** Clear any staged rows from the temporary guarded-delta table. */
    private function clearTemporaryDeltaTable(): void
    {
        $this->session->exec(sprintf('DELETE FROM %s', self::TEMP_TABLE_DELTAS));
    }

    /**
     * Insert the current batch of guarded delta rows into the temporary staging table.
     *
     * @param list<PersistDeltaRow> $deltaRows
     */
    private function insertTemporaryDeltaRows(array $deltaRows): void
    {
        $sql = sprintf(
            'INSERT INTO %s (subject_id, slot_id, slot_key, delta, min_quantity, max_quantity, is_ancestor) VALUES %s',
            self::TEMP_TABLE_DELTAS,
            implode(', ', array_fill(0, count($deltaRows), '(?, ?, ?, ?, ?, ?, ?)')),
        );

        $params = [];
        foreach ($deltaRows as $row) {
            $params[] = $row->subjectId;
            $params[] = $row->slotId;
            $params[] = $row->slotKey;
            $params[] = $row->delta;
            $params[] = $row->minQuantity;
            $params[] = $row->maxQuantity;
            $params[] = $row->isAncestor ? 1 : 0;
        }
        $this->session->exec($sql, $params);
    }

    /**
     * Insert missing zero-quantity inventory_state rows for all staged deltas in one statement.
     *
     * The `LEFT JOIN … IS NULL` filter is a read, so two transactions materialising the SAME
     * (subject_id, slot_id) pair can both observe it missing and both insert it; the loser would
     * take a duplicate-key error on the primary key. The window is the first write a given pair
     * ever receives — in practice the first concurrent orders against a newly-managed subject —
     * which makes it rare, load-independent, and invisible to a bench that warms up first.
     *
     * `ON DUPLICATE KEY UPDATE` with a self-assignment absorbs exactly that collision and nothing
     * else. `INSERT IGNORE` would be wrong here: it downgrades every error to a warning, so a
     * genuine foreign-key violation on subject_id or slot_id would silently drop the row and
     * resurface as the `locked_row_count_mismatch` raised by the caller — the referential bug
     * reported as an unrelated lock failure.
     *
     * The `ORDER BY` makes insert-intention locks fall in the same ascending (subject, slot) order
     * the caller then locks the rows in, so the two statements cannot invert against each other.
     */
    private function insertMissingInventoryStateRowsFromTemp(): void
    {
        $state = $this->table(self::TABLE_INVENTORY_STATE);
        $deltas = self::TEMP_TABLE_DELTAS;

        $this->executeSQL(
            "INSERT INTO $state (subject_id, slot_id, quantity)
             SELECT td.subject_id, td.slot_id, 0
             FROM $deltas td
             LEFT JOIN $state s
                ON s.subject_id = td.subject_id
               AND s.slot_id = td.slot_id
             WHERE s.subject_id IS NULL
             ORDER BY td.subject_id, td.slot_id
             ON DUPLICATE KEY UPDATE $state.quantity = $state.quantity",
        );
    }

    /**
     * Lock the staged inventory rows in deterministic subject/slot order and read their guards.
     *
     * @return list<LockedPersistDeltaRow>
     */
    private function lockStateRowsFromTemp(): array
    {
        $rows = [];
        foreach ($this->fetchAllRows(sprintf(
            'SELECT s.subject_id, s.slot_id, td.slot_key, s.quantity, td.delta, td.min_quantity, td.max_quantity, td.is_ancestor
             FROM %s s
             JOIN %s td
               ON td.subject_id = s.subject_id
              AND td.slot_id = s.slot_id
             ORDER BY s.subject_id, s.slot_id
             FOR UPDATE',
            $this->table(self::TABLE_INVENTORY_STATE),
            self::TEMP_TABLE_DELTAS,
        )) as $row) {
            $rows[] = new LockedPersistDeltaRow(
                $this->requireIntField($row, 'subject_id'),
                $this->requireStringField($row, 'slot_id'),
                $this->requireStringField($row, 'slot_key'),
                $this->requireIntField($row, 'quantity'),
                $this->requireIntField($row, 'delta'),
                $this->nullableIntField($row, 'min_quantity'),
                $this->nullableIntField($row, 'max_quantity'),
                (bool) $this->requireScalarField($row, 'is_ancestor'),
            );
        }

        return $rows;
    }

    /**
     * Detect guard conflicts in the staged and locked inventory rows.
     *
     * @param list<LockedPersistDeltaRow> $lockedRows
     *
     * @return list<PersistenceConflict>
     */
    private function detectDeltaConflicts(array $lockedRows): array
    {
        $conflicts = [];
        foreach ($lockedRows as $row) {
            $currentQuantity = $row->quantity;
            $delta = $row->delta;
            $projectedQuantity = $row->projectedQuantity();

            if ($delta < 0 && null !== $row->minQuantity && $projectedQuantity < $row->minQuantity) {
                $conflicts[] = new PersistenceConflict(
                    new SubjectId($row->subjectId),
                    $row->slotKey,
                    'min_quantity',
                    (string) $currentQuantity,
                    (string) $delta,
                    (string) $projectedQuantity,
                    (string) $row->minQuantity,
                    null,
                );
            }

            if ($delta > 0 && null !== $row->maxQuantity && $projectedQuantity > $row->maxQuantity) {
                $conflicts[] = new PersistenceConflict(
                    new SubjectId($row->subjectId),
                    $row->slotKey,
                    'max_quantity',
                    (string) $currentQuantity,
                    (string) $delta,
                    (string) $projectedQuantity,
                    null,
                    (string) $row->maxQuantity,
                );
            }
        }

        return $conflicts;
    }

    /** Apply all staged state deltas in one bulk update. */
    private function applyTempStateDeltas(): int
    {
        return $this->session->exec(sprintf(
            'UPDATE %s s
             JOIN %s td
               ON td.subject_id = s.subject_id
              AND td.slot_id = s.slot_id
             SET s.quantity = s.quantity + td.delta,
                 s.updated_at = CURRENT_TIMESTAMP',
            $this->table(self::TABLE_INVENTORY_STATE),
            self::TEMP_TABLE_DELTAS,
        ));
    }

    /**
     * Collect sorted lock targets for all registered projection participants.
     *
     * @return array<string, list<ProjectionLockTarget>>
     */
    private function collectProjectionLockTargets(ProjectionContext $context): array
    {
        $targetsByParticipant = [];
        foreach ($this->projectionParticipants as $participantKey => $participant) {
            $targets = $participant->collectLockTargets($context);
            usort(
                $targets,
                static fn (ProjectionLockTarget $left, ProjectionLockTarget $right): int => [$left->resourceType, $left->resourceKey]
                    <=>
                    [$right->resourceType, $right->resourceKey],
            );
            $targetsByParticipant[$participantKey] = $targets;
        }

        return $targetsByParticipant;
    }

    /**
     * Ask all registered projection participants to acquire their declared locks in deterministic order.
     *
     * @param array<string, list<ProjectionLockTarget>> $targetsByParticipant
     */
    private function lockProjectionParticipants(ProjectionContext $context, array $targetsByParticipant): void
    {
        foreach ($this->projectionParticipants as $participantKey => $participant) {
            $targets = $targetsByParticipant[$participantKey] ?? [];
            if ([] === $targets) {
                continue;
            }

            $participant->lock($context, $targets);
        }
    }

    /** Apply all registered projection participants after authoritative state updates succeed. */
    private function applyProjectionParticipants(ProjectionContext $context): void
    {
        foreach ($this->projectionParticipants as $participant) {
            $participant->apply($context);
        }
    }

    /**
     * Insert the per-event inventory ledger rows for one persisted batch.
     *
     * @param array<string, string> $slotIdsByKey
     */
    private function insertLedgerRows(
        PersistBatchMovement $movement,
        array $slotIdsByKey,
        int $movementTypeId,
        ?int $actorDbId,
        ?int $refTypeId,
        ?int $surfaceDbId = null,
    ): int {
        // Append-only ledger: one bulk INSERT via attrecord insertAll() (throws on a duplicate
        // minted PK; no locks — the movement engine's ordered state-row FOR UPDATE stays raw).
        $refCols = $this->ledgerRefColumns($movement->reference);
        $recordedAt = $movement->recordedAt();
        $entries = $movement->movementBatch->ledgerEntries();
        // One shared timestamp across every row of this movement (see mintMultiple()).
        $ids = $this->uuidMinter->mintMultiple(\count($entries));
        $rows = [];
        $i = 0;
        foreach ($entries as $entry) {
            $rows[] = InventoryLedger::newWith([
                'id'               => $ids[$i++],
                'subject_id'       => $movement->subjectIdFor($entry->subject)->id,
                'movement_type_id' => $movementTypeId,
                'from_slot_id'     => $this->slotIdForLedgerSlot($entry, true, $slotIdsByKey),
                'to_slot_id'       => $this->slotIdForLedgerSlot($entry, false, $slotIdsByKey),
                'quantity'         => $this->normalizeQuantity($entry->quantity),
                'initial_from'     => $this->normalizeNullableQuantity($entry->initialFrom),
                'initial_to'       => $this->normalizeNullableQuantity($entry->initialTo),
                'ref_type_id'      => $refTypeId,
                'ref_id'           => $refCols['ref_id'],
                'ref_int_id'       => $refCols['ref_int_id'],
                'actor_id'         => $actorDbId,
                'surface_id'       => $surfaceDbId,
                'recorded_at'      => $recordedAt,
            ]);
        }

        return (new RecordSet($rows))->insertAll()?->inserted ?? 0;
    }

    /**
     * Insert the per-event inventory ledger rows for one persisted single-subject movement.
     *
     * @param array<string, string> $slotIdsByKey
     */
    private function insertSingleLedgerRows(
        PersistMovement $movement,
        array $slotIdsByKey,
        int $movementTypeId,
        ?int $actorDbId,
        ?int $refTypeId,
        ?int $surfaceDbId = null,
    ): int {
        // Append-only ledger: one bulk INSERT via attrecord insertAll() (see insertLedgerRows()).
        $refCols = $this->ledgerRefColumns($movement->reference);
        $recordedAt = $movement->recordedAt();
        $entries = $movement->movementResult->ledgerEntries();
        // One shared timestamp across every row of this movement (see mintMultiple()).
        $ids = $this->uuidMinter->mintMultiple(\count($entries));
        $rows = [];
        $i = 0;
        foreach ($entries as $entry) {
            $rows[] = InventoryLedger::newWith([
                'id'               => $ids[$i++],
                'subject_id'       => $movement->subjectId->id,
                'movement_type_id' => $movementTypeId,
                'from_slot_id'     => $this->slotIdForLedgerSlot($entry, true, $slotIdsByKey),
                'to_slot_id'       => $this->slotIdForLedgerSlot($entry, false, $slotIdsByKey),
                'quantity'         => $this->normalizeQuantity($entry->quantity),
                'initial_from'     => $this->normalizeNullableQuantity($entry->initialFrom),
                'initial_to'       => $this->normalizeNullableQuantity($entry->initialTo),
                'ref_type_id'      => $refTypeId,
                'ref_id'           => $refCols['ref_id'],
                'ref_int_id'       => $refCols['ref_int_id'],
                'actor_id'         => $actorDbId,
                'surface_id'       => $surfaceDbId,
                'recorded_at'      => $recordedAt,
            ]);
        }

        return (new RecordSet($rows))->insertAll()?->inserted ?? 0;
    }

    /**
     * Resolve one SlotFlow event endpoint to a slotspace id (BINARY(16) UUIDv7
     * as a 16-byte string), or null for nil.
     *
     * @param array<string, string> $slotIdsByKey
     */
    private function slotIdForLedgerSlot(LedgerEntry | BatchLedgerEntry $entry, bool $from, array $slotIdsByKey): ?string
    {
        $slot = $from ? $entry->edge->from : $entry->edge->to;

        return $slot->isNil() ? null :
            $slotIdsByKey[$slot->key] ??
                throw new SchemaException(
                    sprintf('Unknown slot key "%s" in ledger entry.', $slot->key),
                    'unknown_ledger_slot_key',
                    ['slotKey' => $slot->key],
                );
    }

    /** Build the SELECT list that exposes physical slotspace dimension columns. */
    private function slotDimensionSelectSql(
        SlotSpaceDefinition $schema,
        string $alias = 'ss',
        string $outputPrefix = '',
    ): string {
        return implode(', ', array_map(
            fn (DimensionDefinition $dimension): string => sprintf(
                '%s.%s AS %s%s',
                $alias,
                $this->dimensionColumn($dimension->name),
                $outputPrefix,
                $dimension->name,
            ),
            $schema->activeDimensions(),
        ));
    }

    /**
     * @param TSlotFilters $slotFilters
     */
    private function slotFilterSql(
        SlotSpaceDefinition $schema,
        array $slotFilters,
        string $alias,
        string $paramPrefix = 'slot_filter_',
    ): string {
        if ([] === $slotFilters) {
            return '';
        }

        $known = [];
        foreach ($schema->activeDimensions() as $dimension) {
            $known[$dimension->name] = true;
        }

        $clauses = [];
        foreach ($slotFilters as $dimensionName => $valueOrValues) {
            if (!isset($known[$dimensionName])) {
                throw new InvalidFilterException($dimensionName);
            }

            $values = is_array($valueOrValues) ? $valueOrValues : [$valueOrValues];
            $placeholders = [];
            foreach ($values as $index => $_value) {
                $placeholders[] = ':'.$paramPrefix.$dimensionName.'_'.$index;
            }

            $clauses[] = sprintf(
                '%s.%s IN (%s)',
                $alias,
                $this->dimensionColumn($dimensionName),
                implode(', ', $placeholders),
            );
        }

        return ' AND '.implode(' AND ', $clauses);
    }

    /**
     * Convert slot filter values into prepared-statement parameters.
     *
     * @param TSlotFilters $slotFilters
     *
     * @return array<string, string>
     */
    private function slotFilterParams(array $slotFilters, string $paramPrefix = 'slot_filter_'): array
    {
        $params = [];
        foreach ($slotFilters as $dimensionName => $valueOrValues) {
            $values = is_array($valueOrValues) ? $valueOrValues : [$valueOrValues];
            foreach ($values as $index => $value) {
                $params[$paramPrefix.$dimensionName.'_'.$index] = $value;
            }
        }

        return $params;
    }

    /**
     * Extract slot dimensions from one fetched slotspace row.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<non-empty-string, non-empty-string>
     */
    private function extractSlotDimensions(
        SlotSpaceDefinition $schema,
        array $row,
        string $fieldPrefix = '',
    ): array {
        $dimensions = [];
        foreach ($schema->activeDimensions() as $dimension) {
            /** @psalm-var mixed */
            $value = $row[$fieldPrefix.$dimension->name] ?? null;
            if (is_string($value) && '' !== $value) {
                $dimensionName = $this->requireNonEmptyString($dimension->name, 'dimension.name');
                $dimensions[$dimensionName] = $value;
            }
        }

        return $dimensions;
    }

    /**
     * @param TSlotFilters              $slotFilters
     * @param array<string, int|string> $params
     */
    private function ledgerFilterSql(
        SlotSpaceDefinition $schema,
        array $slotFilters,
        array &$params,
    ): string {
        if ([] === $slotFilters) {
            return '';
        }

        $known = [];
        foreach ($schema->activeDimensions() as $dimension) {
            $known[$dimension->name] = true;
        }

        $clauses = [];
        foreach ($slotFilters as $dimensionName => $valueOrValues) {
            if (!isset($known[$dimensionName])) {
                throw new InvalidFilterException($dimensionName);
            }

            $values = is_array($valueOrValues) ? $valueOrValues : [$valueOrValues];
            $placeholders = [];
            foreach ($values as $index => $value) {
                $param = 'ledger_'.$dimensionName.'_'.$index;
                $params[$param] = $value;
                $placeholders[] = ':'.$param;
            }

            $column = $this->dimensionColumn($dimensionName);
            $clauses[] = sprintf(
                '(
                    (fs.active = 1 AND fs.%1$s IN (%2$s))
                    OR
                    (ts.active = 1 AND ts.%1$s IN (%2$s))
                )',
                $column,
                implode(', ', $placeholders),
            );
        }

        return ' AND '.implode(' AND ', $clauses);
    }

    /** Resolve one registered movement type to its numeric id. */
    private function resolveMovementTypeId(string $ownerKey, string $code): int
    {
        $id = MovementType::findOne('owner_key = ? AND code = ? AND active = 1', [$ownerKey, $code])?->id;
        if (!is_scalar($id)) {
            throw new UnknownMovementTypeException($ownerKey, $code);
        }

        return $id;
    }

    /** Resolve one registered actor type to its numeric id (cached for the lifetime of this instance). */
    private function resolveActorTypeId(string $code): int
    {
        if (null === $this->actorTypeIdsByCode) {
            $rows = ActorTypeRecord::find('active = 1');
            $this->actorTypeIdsByCode = [];
            foreach ($rows as $row) {
                $_code = $row->code;
                $id = $row->id;
                /** @psalm-suppress PropertyTypeCoercion */
                $this->actorTypeIdsByCode[$_code] = $id;
            }
        }

        return $this->actorTypeIdsByCode[$code] ??
            throw new UnknownActorTypeException($code);
    }

    /**
     * Resolve or register one surface and return its invflux_surfaces id.
     *
     * When `$parentId` is provided, the surface row's `parent_id` is set or corrected
     * to that value. When omitted, an existing row's `parent_id` is preserved as-is
     * (so callers that don't carry parent context don't accidentally clobber a
     * previously-correct linkage). Surface hierarchy depth is capped at two levels
     * (`plugin:<slug>` root → leaf child); callers should resolve the root first and
     * pass its id when seeding children.
     *
     * @psalm-suppress PossiblyUnusedMethod wired in S3 (surface threading through write paths)
     */
    public function resolveSurfaceId(string $typeCode, string $ref, ?int $parentId = null): int
    {
        $surfaceTypeId = $this->resolveSurfaceTypeId($typeCode)
            ?? throw new \RuntimeException(\sprintf('Unknown surface type code: %s', $typeCode));

        // SELECT first: this resolver is called on every hot-path event/movement
        // persistence, so the row almost always exists. Unconditional INSERT ...
        // ON DUPLICATE KEY UPDATE would bump the AUTO_INCREMENT counter on every
        // call (InnoDB's default `innodb_autoinc_lock_mode=1` increments on
        // duplicate-key collisions too) — and exhaust a small AUTO_INCREMENT
        // domain in a busy site.
        $existing = SurfaceRecord::findOne(
            '`surface_type_id` = ? AND `surface_ref` = ?',
            [$surfaceTypeId, $ref],
        );

        if (null !== $existing) {
            // Preserve the previous COALESCE(VALUES(parent_id), parent_id) semantics:
            // when the caller omits parent_id, the stored value is left alone; when
            // they supply one, it's set/corrected.
            if (null !== $parentId && $existing->parent_id !== $parentId) {
                $existing->parent_id = $parentId;
                $existing->save();
            }

            return (int) $existing->id;
        }

        $new = new SurfaceRecord();
        $new->surface_type_id = $surfaceTypeId;
        $new->surface_ref = $ref;
        $new->parent_id = $parentId;
        $new->save();

        return (int) $new->id;
    }

    /**
     * The `(type, ref)` pair behind a surface id — the inverse of {@see resolveSurfaceId()}.
     *
     * Needed because a surface can arrive already resolved. A stock adjustment stores the surface
     * on its own record as an id, and its ledger movements want the same surface as a reference;
     * without this the movement either carries a null provenance, or the caller has to reconstruct
     * the pair from data it no longer has (a plugin-intercept adjustment knows the id, not the
     * plugin slug that produced it).
     *
     * Returns null for an unknown id rather than throwing: `ledger.surface_id` is
     * `ON DELETE SET NULL`, so a surface disappearing is a state the model already tolerates, and a
     * movement is not the place to discover it.
     *
     * @api Called by the adapters, which psalm does not see from here.
     */
    public function surfaceReferenceById(int $surfaceId): ?SurfaceReference
    {
        if ($surfaceId <= 0) {
            return null;
        }

        $row = $this->fetchOneRow(sprintf(
            'SELECT st.code AS type_code, s.surface_ref AS surface_ref
               FROM %s s
               INNER JOIN %s st ON st.id = s.surface_type_id
              WHERE s.id = :id',
            $this->table(self::TABLE_SURFACES),
            $this->table(self::TABLE_SURFACE_TYPES),
        ), ['id' => $surfaceId]);

        if (null === $row) {
            return null;
        }

        return new SurfaceReference(
            $this->requireStringField($row, 'type_code'),
            $this->requireStringField($row, 'surface_ref'),
        );
    }

    /**
     * The live definition for one layer — its flows over this install's registered values.
     *
     * @psalm-param non-empty-string $layerName
     */
    #[\Override]
    public function layerSchema(string $layerName): SlotSpaceDefinition
    {
        return $this->resolveSharedRefDimensions(
            ($this->layeredSchema ?? $this->tryLayeredSchema())?->layers[$layerName]
                ?? throw new SchemaException(
                    sprintf('Layer "%s" not found in bootstrapped schema.', $layerName),
                    'unknown_layer',
                    ['layer' => $layerName],
                ),
        );
    }

    public function resolveActorId(string $typeCode, ?string $actorRef): int
    {
        $actorTypeId = $this->resolveActorTypeId($typeCode);
        $ref = $actorRef ?? '';

        // SELECT first — same reasoning as resolveSurfaceId above.
        $existing = ActorRecord::findOne(
            '`actor_type_id` = ? AND `actor_ref` = ?',
            [$actorTypeId, $ref],
        );

        if (null !== $existing) {
            return (int) $existing->id;
        }

        $new = new ActorRecord();
        $new->actor_type_id = $actorTypeId;
        $new->actor_ref = $ref;
        $new->save();

        return (int) $new->id;
    }

    /**
     * Idempotently INSERT IGNORE the provided ref-type definitions.
     *
     * Matches `TypeRegistry::registerRefTypes()`. The `$ownerKey` is informational —
     * the `invflux_ref_types.code` column is UNIQUE, so two subsystems registering
     * the same code collapse onto a single row. Calling this method multiple times
     * for the same definitions is a no-op.
     */
    #[\Override]
    public function registerRefTypes(string $ownerKey, array $refTypes): void
    {
        unset($ownerKey);
        $this->createBaseTables();

        // Burn-free upsert on the `code` UNIQUE — see registerMovementTypes() for the rationale
        // (an unconditional INSERT IGNORE / ON-DUPLICATE-KEY would tick AUTO_INCREMENT on every
        // collision, exhausting this small domain when init hooks fire on every request).
        $records = [];
        foreach ($refTypes as $refType) {
            $records[] = RefTypeRecord::newWith([
                'code'           => $refType->code,
                'name'           => $refType->name,
                'identity_class' => $refType->identityClass,
            ]);
        }
        (new RecordSet($records))->upsertAllByUniqueKey('uniq_ref_type_code');
    }

    /**
     * Idempotently register the provided surface-type definitions.
     *
     * Matches `TypeRegistry::registerSurfaceTypes()`.
     */
    #[\Override]
    public function registerSurfaceTypes(string $ownerKey, array $surfaceTypes): void
    {
        unset($ownerKey);
        $this->createBaseTables();

        // Burn-free upsert on the `code` UNIQUE — see registerMovementTypes() for the rationale.
        $records = [];
        foreach ($surfaceTypes as $surfaceType) {
            $records[] = SurfaceTypeRecord::newWith([
                'code'        => $surfaceType->code,
                'name'        => $surfaceType->name,
                'description' => $surfaceType->description,
            ]);
        }
        (new RecordSet($records))->upsertAllByUniqueKey('uniq_surface_type_code');
    }

    /**
     * Resolve a ref-type code to its numeric id, or null if not registered.
     *
     * Non-throwing variant matching the `TypeRegistry` interface contract — for
     * callers (e.g. event-emit code) that want to gracefully degrade when a ref
     * type hasn't been registered. The internal strict-throwing variant used by
     * ledger writers is {@see resolveRefTypeIdStrict()}.
     */
    #[\Override]
    public function resolveRefTypeId(string $code): ?int
    {
        if ('' === $code) {
            return null;
        }

        $id = $this->fetchScalarValue(sprintf(
            'SELECT id FROM %s WHERE code = :code',
            $this->table(self::TABLE_REF_TYPES),
        ), ['code' => $code]);

        return is_scalar($id) ? (int) $id : null;
    }

    /** Resolve a surface-type code to its numeric id, or null if not registered. */
    #[\Override]
    public function resolveSurfaceTypeId(string $code): ?int
    {
        if ('' === $code) {
            return null;
        }

        $id = $this->fetchScalarValue(sprintf(
            'SELECT id FROM %s WHERE code = :code',
            $this->table(self::TABLE_SURFACE_TYPES),
        ), ['code' => $code]);

        return is_scalar($id) ? (int) $id : null;
    }

    /**
     * Resolve a ref type code to its numeric id, throwing on unknown codes.
     *
     * Internal callers (ledger writers, etc.) require strict resolution because an
     * unknown code in a movement context is a bug. External callers via the
     * {@see resolveRefTypeId()} public method get a non-throwing variant that
     * returns null for unknown codes — matching the `TypeRegistry` interface
     * contract.
     */
    private function resolveRefTypeIdStrict(?string $code): ?int
    {
        if (null === $code) {
            return null;
        }

        $id = $this->fetchScalarValue(sprintf(
            'SELECT id FROM %s WHERE code = :code',
            $this->table(self::TABLE_REF_TYPES),
        ), ['code' => $code]);

        if (!is_scalar($id)) {
            throw new ConfigurationException(
                sprintf('Unknown ref type code "%s".', $code),
                'unknown_ref_type_code',
            );
        }

        return (int) $id;
    }

    /** Convert one runtime quantity to the integer storage representation. */
    private function normalizeQuantity(int | float $quantity): int
    {
        if (is_int($quantity)) {
            return $quantity;
        }

        if ((float) (int) $quantity !== $quantity) {
            throw new InvalidQuantityException('quantity', $quantity);
        }

        return (int) $quantity;
    }

    /** Convert a nullable runtime quantity to the integer storage representation. */
    private function normalizeNullableQuantity(int | float | null $quantity): ?int
    {
        return null === $quantity ? null : $this->normalizeQuantity($quantity);
    }

    /** Convert a nullable runtime guard quantity to the integer storage representation. */
    private function normalizeNullableGuardQuantity(int | float | null $quantity, string $field): ?int
    {
        if (null === $quantity) {
            return null;
        }

        $normalized = $this->normalizeQuantity($quantity);
        if ($normalized < 0) {
            throw new InvalidQuantityException($field, $quantity);
        }

        return $normalized;
    }

    /**
     * Validate and preserve a nullable unsigned BIGINT-compatible reference id.
     *
     * Used by the config-ledger path ({@see recordMetaEvent}) where INT-keyed admin
     * entities (dimension values, settings) are still referenced via `(ref_type, ref_id)`.
     * Per arch-uuid-identity §5.5 those should eventually move to typed columns on the
     * config ledger; until that refactor, this validator remains for that single
     * call site. The inventory ledger uses {@see normalizeNullableBinary16Ref} instead.
     */
    private function normalizeNullableUnsignedInteger(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new ConfigurationException(
                sprintf('Expected an unsigned integer reference id, got "%s".', $value),
                'invalid_reference_id',
                ['referenceId' => $value],
            );
        }

        if (strlen($value) > 20 || (20 === strlen($value) && strcmp($value, '18446744073709551615') > 0)) {
            throw new ConfigurationException(
                sprintf('Expected an unsigned BIGINT reference id, got "%s".', $value),
                'reference_id_out_of_range',
                ['referenceId' => $value],
            );
        }

        return $value;
    }

    /**
     * Route a document reference to its ledger lane by the id's value type: an int id →
     * `ref_int_id` (INT UNSIGNED, admin-minted documents like POs); a string id → `ref_id`
     * (BINARY(16) UUID-keyed documents, validated).
     *
     * @return array{ref_id: ?string, ref_int_id: ?int}
     */
    private function ledgerRefColumns(?EntityReference $reference): array
    {
        if (null === $reference) {
            return ['ref_id' => null, 'ref_int_id' => null];
        }

        if (\is_int($reference->id)) {
            return ['ref_id' => null, 'ref_int_id' => $reference->id];
        }

        return ['ref_id' => $this->normalizeNullableBinary16Ref($reference->id), 'ref_int_id' => null];
    }

    /**
     * Validate a nullable inventory-ledger `ref_id`, which is `BINARY(16)` per arch-uuid-identity §5.5.
     *
     * Accepts:
     *   - `null` (no reference);
     *   - a raw 16-byte binary UUID (typical caller path — the entity's surrogate id is
     *     already binary, e.g. `OrderCorrection::$id`);
     *   - a 32-character lowercase/uppercase hex string (admin/SQL convenience — converted
     *     to its 16-byte binary form via `hex2bin`).
     *
     * `ref_id` is reserved for UUID-keyed *documents* (orders, events, corrections, …);
     * INT-keyed documents flow through the sibling `ref_int_id` column instead — the lane
     * is chosen by {@see ledgerRefColumns()} from the reference id's value type.
     */
    private function normalizeNullableBinary16Ref(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (16 === strlen($value)) {
            return $value;
        }

        if (32 === strlen($value) && ctype_xdigit($value)) {
            $binary = hex2bin($value);
            if (false !== $binary) {
                return $binary;
            }
        }

        throw new ConfigurationException(
            sprintf(
                'Expected a BINARY(16) reference id (raw 16 bytes or 32-char hex), got "%s".',
                ctype_print($value) ? $value : '0x'.bin2hex($value),
            ),
            'invalid_reference_id',
            ['referenceId' => ctype_print($value) ? $value : '0x'.bin2hex($value)],
        );
    }

    /** Return the physical slotspace column name for one dimension. */
    private function dimensionColumn(string $dimensionName): string
    {
        // Delegated, not duplicated: these columns are declared by SlotSpaceSchema and addressed
        // by the raw SQL below, so the two must agree on the name by construction.
        return SlotSpaceSchema::columnFor($dimensionName);
    }

    /** Prefix a logical table name with the configured adapter table prefix. */
    private function table(string $table): string
    {
        return $this->tablePrefix.$table;
    }

    /**
     * Prepare and execute one SQL statement after resolving ::TABLE_* placeholders.
     *
     * @param array<array-key, scalar|null> $params
     */
    private function executeSQL(string $sql, array $params = []): void
    {
        $this->session->exec($this->resolveSQLTables($sql), $params);
    }

    /** @param array<array-key, scalar|null> $params */
    private function executeSQLCount(string $sql, array $params = []): int
    {
        return $this->session->exec($this->resolveSQLTables($sql), $params);
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    private function fetchOneRow(string $sql, array $params = []): ?array
    {
        return $this->session->fetchOne($this->resolveSQLTables($sql), $params);
    }

    /**
     * @param array<array-key, scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    private function fetchAllRows(string $sql, array $params = []): array
    {
        return $this->session->fetchAll($this->resolveSQLTables($sql), $params);
    }

    /**
     * @param array<array-key, scalar|null> $params
     */
    private function fetchScalarValue(string $sql, array $params = []): string | int | float | null
    {
        return $this->session->fetchScalar($this->resolveSQLTables($sql), $params);
    }

    /** Replace ::TABLE_* placeholders with the configured physical table names. */
    private function resolveSQLTables(string $sql): string
    {
        $sql = preg_replace_callback(
            '/::(TABLE_[A-Z_]+)/',
            function (array $matches): string {
                $constant = self::class.'::'.$matches[1];
                defined($constant) || throw new PersistenceException(
                    sprintf('Unknown SQL table placeholder "%s".', $matches[1]),
                    'unknown_table_placeholder',
                    ['placeholder' => $matches[1]],
                );

                $value = constant($constant);
                is_string($value) || throw new PersistenceException(
                    sprintf('SQL table placeholder "%s" did not resolve to a string.', $matches[1]),
                    'invalid_table_placeholder',
                    ['placeholder' => $matches[1]],
                );

                return $this->table($value);
            },
            $sql,
        ) ?? throw new PersistenceException('SQL table placeholder replacement failed.', 'sql_table_resolution_failed');

        return str_replace('COLLATE=utf8mb4_unicode_ci', 'COLLATE='.$this->session->defaultCollation(), $sql);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode one JSON payload into a PHP array.
     *
     * @return array<array-key, mixed>
     */
    private function decodeArray(?string $json): array
    {
        if (null === $json || '' === $json) {
            return [];
        }

        /** @psalm-var mixed $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode one JSON payload into an associative payload map.
     *
     * @return TAssocPayload
     */
    private function decodeAssoc(?string $json): array
    {
        $decoded = $this->decodeArray($json);
        if ([] === $decoded || array_is_list($decoded)) {
            return [];
        }

        /** @var TAssocPayload $decoded */
        return $decoded;
    }

    /**
     * Execute one operation inside the shared outer transaction boundary.
     *
     * Nested calls reuse the existing transaction and only the outermost call commits or rolls back.
     *
     * @template TResult
     *
     * @param \Closure(): TResult $operation
     *
     * @return TResult
     */
    #[\Override]
    public function transactional(\Closure $operation): mixed
    {
        return $this->session->transactional($operation);
    }

    #[\Override]
    public function withAdvisoryLock(string $lockName, int $timeoutSeconds, \Closure $callback): mixed
    {
        return $this->session->withAdvisoryLock($lockName, $timeoutSeconds, $callback);
    }

    /**
     * Read the terminal outcome code for a key, or null when no completed claim exists.
     * Pure read — never creates a claim. See {@see IdempotencyStore::idempotentOutcome()}.
     */
    #[\Override]
    public function idempotentOutcome(IdempotencyKey $key): ?string
    {
        $this->createBaseTables();

        $row = $this->fetchOneRow(<<<SQL
            SELECT outcome_code, completed_at
            FROM ::TABLE_IDEMPOTENCY
            WHERE scope = :scope AND operation_key = :operation_key
            LIMIT 1
        SQL, [
            'scope'         => $key->scope,
            'operation_key' => $key->operationKey,
        ]);

        if (!is_array($row) || null === $row['completed_at']) {
            return null;
        }

        return is_string($row['outcome_code']) ? $row['outcome_code'] : null;
    }

    /** Execute one operation behind one persisted idempotency key. */
    #[\Override]
    public function executeIdempotent(IdempotencyKey $key, \Closure $operation): IdempotentExecution
    {
        $this->createBaseTables();

        return $this->transactional(function () use ($key, $operation): IdempotentExecution {
            $createdAt = new \DateTimeImmutable();

            if (!$this->tryInsertIdempotencyClaim($key, $createdAt)) {
                return $this->loadIdempotentExecution($key);
            }

            $outcome = $operation();
            if (!$outcome instanceof IdempotentOutcome) {
                throw new PersistenceException(
                    'Idempotent operation closure must return an IdempotentOutcome.',
                    'invalid_idempotent_outcome',
                );
            }

            if (!$outcome->terminal) {
                $this->deleteIdempotencyClaim($key);

                return new IdempotentExecution(
                    replayed: false,
                    terminal: false,
                    outcomeCode: $outcome->outcomeCode,
                    payload: $outcome->payload,
                    completedAt: null,
                );
            }

            $completedAt = new \DateTimeImmutable();
            $this->completeIdempotencyClaim($key, $outcome, $completedAt);

            return new IdempotentExecution(
                replayed: false,
                terminal: true,
                outcomeCode: $outcome->outcomeCode,
                payload: $outcome->payload,
                completedAt: $completedAt,
            );
        });
    }

    private function tryInsertIdempotencyClaim(IdempotencyKey $key, \DateTimeImmutable $createdAt): bool
    {
        // INSERT IGNORE rather than INSERT-and-catch-duplicate-key: on the
        // replay path, the unique-key collision is the EXPECTED outcome, not
        // an error. The old try/catch worked but bubbled the SQL error up
        // through wpdb's error renderer, polluting WP_DEBUG_LOG with a
        // scary-looking "Duplicate entry" line on every idempotent replay.
        // INSERT IGNORE silently no-ops the conflict; we detect the outcome
        // via affected_rows (1 = inserted, 0 = duplicate-key).
        $affected = $this->executeSQLCount(<<<SQL
            INSERT IGNORE INTO ::TABLE_IDEMPOTENCY
            (scope, operation_key, outcome_code, payload_json, created_at, completed_at)
            VALUES (:scope, :operation_key, :outcome_code, :payload_json, :created_at, :completed_at)
        SQL, [
            'scope'         => $key->scope,
            'operation_key' => $key->operationKey,
            'outcome_code'  => null,
            'payload_json'  => $this->json([]),
            'created_at'    => $createdAt->format('Y-m-d H:i:s.u'),
            'completed_at'  => null,
        ]);

        return 1 === $affected;
    }

    private function completeIdempotencyClaim(
        IdempotencyKey $key,
        IdempotentOutcome $outcome,
        \DateTimeImmutable $completedAt,
    ): void {
        Idempotency::updateWhere([
            'outcome_code' => $outcome->outcomeCode,
            'payload_json' => $this->json($outcome->payload),
            'completed_at' => $completedAt,
        ], 'scope = ? AND operation_key = ?', [
            $key->scope,
            $key->operationKey,
        ]);
    }

    private function deleteIdempotencyClaim(IdempotencyKey $key): void
    {
        $this->executeSQL(<<<SQL
            DELETE FROM ::TABLE_IDEMPOTENCY
            WHERE scope = :scope AND operation_key = :operation_key
        SQL, [
            'scope'         => $key->scope,
            'operation_key' => $key->operationKey,
        ]);
    }

    private function loadIdempotentExecution(IdempotencyKey $key): IdempotentExecution
    {
        $row = $this->fetchOneRow(<<<SQL
            SELECT outcome_code, payload_json, completed_at
            FROM ::TABLE_IDEMPOTENCY
            WHERE scope = :scope
              AND operation_key = :operation_key
            LIMIT 1
        SQL, [
            'scope'         => $key->scope,
            'operation_key' => $key->operationKey,
        ]);
        is_array($row) || throw new PersistenceException(
            sprintf('Missing idempotency row for scope "%s" and key "%s".', $key->scope, $key->operationKey),
            'missing_idempotency_row',
            ['scope' => $key->scope, 'operationKey' => $key->operationKey],
        );

        if (null === $row['completed_at']) {
            return new IdempotentExecution(
                replayed: true,
                terminal: false,
                outcomeCode: 'in_progress',
                payload: [],
                completedAt: null,
            );
        }

        return new IdempotentExecution(
            replayed: true,
            terminal: true,
            outcomeCode: $this->requireStringField($row, 'outcome_code'),
            payload: $this->decodeAssoc($this->nullableNonEmptyStringField($row, 'payload_json')),
            completedAt: new \DateTimeImmutable($this->requireStringField($row, 'completed_at')),
        );
    }

    /** Return the current row count for one logical table. */
    private function tableRowCount(string ...$tables): int
    {
        $count = 0;
        foreach ($tables as $table) {
            $sql = sprintf('SELECT COUNT(*) FROM %s', $this->table($table));
            $count += $this->fetchCountFromSql($sql);
        }

        return $count;
    }

    private function storeIsEmpty(): bool
    {
        return 0 === $this->tableRowCount(
            self::TABLE_INVENTORY_STATE,
            self::TABLE_INVENTORY_LEDGER,
        );
    }

    /**
     * Execute one COUNT(*) query and return its integer result.
     *
     * @param array<string, scalar|null> $params
     */
    private function fetchCountFromSql(string $sql, array $params = []): int
    {
        $count = $this->session->fetchScalar($sql, $params);
        is_scalar($count) || throw new PersistenceException('Expected count query to return a scalar value.', 'invalid_count_result');

        return (int) $count;
    }

    /**
     * Require one fetched field to be a non-empty string.
     *
     * @param array<array-key, mixed> $row
     *
     * @return non-empty-string
     */
    private function requireStringField(array $row, string $field): string
    {
        $value = $row[$field] ?? '';

        (is_string($value) && '' !== $value)
            || throw new PersistenceException(
                sprintf('Expected non-empty string field "%s".', $field),
                'missing_string_field',
                ['field' => $field],
            );

        return $value;
    }

    /**
     * Return one fetched field as a nullable non-empty string.
     *
     * @param array<array-key, mixed> $row
     *
     * @return ?non-empty-string
     */
    private function nullableNonEmptyStringField(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        if (null === $value) {
            return null;
        }

        is_scalar($value)
            || throw new PersistenceException(
                sprintf('Expected scalar field "%s".', $field),
                'invalid_scalar_field',
                ['field' => $field],
            );

        $value = (string) $value;

        '' !== $value || throw new PersistenceException(
            sprintf('Expected non-empty string field "%s".', $field),
            'empty_string_field',
            ['field' => $field],
        );

        return $value;
    }

    /**
     * Require one fetched field to be scalar.
     *
     * @param array<array-key, mixed> $row
     */
    private function requireScalarField(array $row, string $field): int | float | string | bool
    {
        $value = $row[$field] ?? null;

        is_scalar($value)
            || throw new PersistenceException(
                sprintf('Expected scalar field "%s".', $field),
                'invalid_scalar_field',
                ['field' => $field],
            );

        return $value;
    }

    /**
     * Return one fetched field as nullable scalar.
     *
     * @param array<array-key, mixed> $row
     */
    private function nullableScalarField(array $row, string $field): int | float | string | bool | null
    {
        $value = $row[$field] ?? null;

        if (null === $value) {
            return null;
        }

        is_scalar($value)
            || throw new PersistenceException(
                sprintf('Expected nullable scalar field "%s".', $field),
                'invalid_nullable_scalar_field',
                ['field' => $field],
            );

        return $value;
    }

    /**
     * Require one fetched field to be an integer-like scalar.
     *
     * @param array<array-key, mixed> $row
     */
    private function requireIntField(array $row, string $field): int
    {
        $value = $this->requireScalarField($row, $field);

        return (int) $value;
    }

    /**
     * Return one fetched field as a nullable integer-like scalar.
     *
     * @param array<array-key, mixed> $row
     */
    private function nullableIntField(array $row, string $field): ?int
    {
        $value = $this->nullableScalarField($row, $field);

        return null === $value ? null : (int) $value;
    }

    /**
     * Require one string value to be non-empty for downstream typed APIs.
     *
     * @return non-empty-string
     */
    private function requireNonEmptyString(string $value, string $label): string
    {
        if ('' === $value) {
            throw new PersistenceException(
                sprintf('Expected non-empty string for %s.', $label),
                'empty_string_value',
                ['label' => $label],
            );
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // SubjectRegistrar
    // -------------------------------------------------------------------------

    #[\Override]
    public function registerSubject(?SubjectId $parentId = null, SubjectKind $kind = SubjectKind::Unit, bool $stockManaged = false): SubjectId
    {
        $parentProductId = null;
        $parentVariantId = null;

        if (null !== $parentId) {
            $parentRow = $this->fetchOneRow(
                sprintf('SELECT kind, product_id, variant_id FROM %s WHERE id = :id', $this->table(self::TABLE_SUBJECTS)),
                ['id' => $parentId->id],
            );
            if (!is_array($parentRow)) {
                throw new PersistenceException(
                    sprintf('Parent subject %d not found.', $parentId->id),
                    'subject_parent_not_found',
                    ['parentId' => $parentId->id],
                );
            }
            $parentKind = SubjectKind::from((string) $parentRow['kind']);
            if (!$kind->canBeChildOf($parentKind)) {
                throw new ConfigurationException(
                    sprintf(
                        'A %s subject cannot be a child of a %s subject.',
                        $kind->value,
                        $parentKind->value,
                    ),
                    'subject_kind_parent_mismatch',
                    ['kind' => $kind->value, 'parentKind' => $parentKind->value, 'parentId' => $parentId->id],
                );
            }
            $parentProductId = isset($parentRow['product_id']) ? (int) $parentRow['product_id'] : null;
            $parentVariantId = isset($parentRow['variant_id']) ? (int) $parentRow['variant_id'] : null;
        } elseif (SubjectKind::Batch === $kind) {
            throw new ConfigurationException(
                'A batch subject must have a parent unit subject.',
                'subject_batch_requires_parent',
            );
        }

        // Determine product_id and variant_id for the new subject.
        // Root subjects (aggregate, or a variant-level kind standing alone) are self-referential —
        // computed after INSERT. Child subjects inherit from the parent.
        $isRoot = null === $parentId;
        $productId = $isRoot ? null : $parentProductId;
        $variantId = match (true) {
            $isRoot                   => null,                // filled by post-INSERT UPDATE
            $kind->isVariantLevel()   => null,                // filled by post-INSERT UPDATE
            default                   => $parentVariantId,    // batch: inherit parent's variant_id
        };

        $subject = Subject::newWith([
            'parent_id'     => $parentId?->id,
            'kind'          => $kind,
            'product_id'    => $productId,
            'variant_id'    => $variantId,
            'ivfx_governed' => $stockManaged,
            'created_at'    => new \DateTimeImmutable(),
        ]);
        $subject->save();

        $id = (int) $subject->id;

        // Self-referential FKs: a standalone variant-level subject is its own product AND variant;
        // a root aggregate is its own product; a variant-level child is its own variant.
        $selfCols = match (true) {
            $isRoot && $kind->isVariantLevel()          => ['product_id' => $id, 'variant_id' => $id],
            $isRoot && SubjectKind::Aggregate === $kind => ['product_id' => $id],
            $kind->isVariantLevel()                     => ['variant_id' => $id],
            default                                     => [],
        };

        if ([] !== $selfCols) {
            Subject::updateWhere($selfCols, 'id = ?', [$id]);
        }

        return new SubjectId($id);
    }

    #[\Override]
    public function retypeSubject(SubjectId $subjectId, SubjectKind $newKind): void
    {
        $this->session->transactional(function () use ($subjectId, $newKind): void {
            $row = $this->fetchOneRow(
                sprintf('SELECT kind, parent_id FROM %s WHERE id = :id FOR UPDATE', $this->table(self::TABLE_SUBJECTS)),
                ['id' => $subjectId->id],
            );
            if (!is_array($row)) {
                throw new PersistenceException(
                    sprintf('Subject %d not found.', $subjectId->id),
                    'subject_not_found',
                    ['subjectId' => $subjectId->id],
                );
            }

            $currentKind = SubjectKind::from((string) $row['kind']);
            if ($currentKind === $newKind) {
                return; // Idempotent.
            }

            // Guard 1 — nothing to strand. Only a kind that owns no slots may be retyped: a Unit or
            // Batch carries slot state and ledger history that the new kind may not be able to
            // hold, and moving the identity to a fresh subject is the mechanism for that case.
            if ($currentKind->ownsSlots()) {
                throw new ConfigurationException(
                    sprintf('A %s subject owns slots and cannot be retyped; migrate its identity instead.', $currentKind->value),
                    'subject_retype_owns_slots',
                    ['subjectId' => $subjectId->id, 'kind' => $currentKind->value],
                );
            }

            // Guard 2 — no children. Only an Aggregate has product-level children, and every
            // variant-level kind is an illegal parent for them (canBeChildOf), so a retype here
            // would silently produce a tree no registration path could ever have built.
            $childCount = $this->fetchOneRow(
                sprintf('SELECT COUNT(*) AS c FROM %s WHERE parent_id = :id', $this->table(self::TABLE_SUBJECTS)),
                ['id' => $subjectId->id],
            );
            if (is_array($childCount) && (int) $childCount['c'] > 0) {
                throw new ConfigurationException(
                    sprintf('Subject %d has children and cannot be retyped.', $subjectId->id),
                    'subject_retype_has_children',
                    ['subjectId' => $subjectId->id, 'children' => (int) $childCount['c']],
                );
            }

            // Guard 3 — no inventory history. Guard 1 should make this unreachable, but a subject
            // that accumulated ledger rows while a slot-less kind (the very hole the movement
            // eligibility filter closes) must not have that history reinterpreted under a kind
            // that counts. Cheap, and it fails loudly instead of corrupting silently.
            $ledgerCount = $this->fetchOneRow(
                sprintf('SELECT COUNT(*) AS c FROM %s WHERE subject_id = :id', $this->table(self::TABLE_INVENTORY_LEDGER)),
                ['id' => $subjectId->id],
            );
            if (is_array($ledgerCount) && (int) $ledgerCount['c'] > 0) {
                throw new ConfigurationException(
                    sprintf('Subject %d has ledger history and cannot be retyped.', $subjectId->id),
                    'subject_retype_has_ledger',
                    ['subjectId' => $subjectId->id, 'ledgerRows' => (int) $ledgerCount['c']],
                );
            }

            // Guard 4 — the new kind must be legal where this subject sits.
            $parentIdRaw = $row['parent_id'] ?? null;
            $parentId = null !== $parentIdRaw ? (int) $parentIdRaw : null;
            if (null !== $parentId) {
                $parentRow = $this->fetchOneRow(
                    sprintf('SELECT kind FROM %s WHERE id = :id', $this->table(self::TABLE_SUBJECTS)),
                    ['id' => $parentId],
                );
                $parentKind = is_array($parentRow) ? SubjectKind::from((string) $parentRow['kind']) : null;
                if (null === $parentKind || !$newKind->canBeChildOf($parentKind)) {
                    throw new ConfigurationException(
                        sprintf(
                            'A %s subject cannot be a child of a %s subject.',
                            $newKind->value,
                            $parentKind?->value ?? 'missing',
                        ),
                        'subject_kind_parent_mismatch',
                        ['kind' => $newKind->value, 'parentKind' => $parentKind?->value, 'parentId' => $parentId],
                    );
                }
            } elseif (SubjectKind::Batch === $newKind) {
                throw new ConfigurationException(
                    'A batch subject must have a parent unit subject.',
                    'subject_batch_requires_parent',
                );
            }

            // The self-referential FK shape is a function of the kind, so it changes with it —
            // mirroring registerSubject(). A root variant-level subject is its own product AND
            // variant; a root Aggregate is its own product and has no variant. Getting this wrong
            // is invisible until a join silently returns nothing.
            $isRoot = null === $parentId;
            $selfCols = match (true) {
                $isRoot && $newKind->isVariantLevel()          => ['product_id' => $subjectId->id, 'variant_id' => $subjectId->id],
                $isRoot && SubjectKind::Aggregate === $newKind => ['product_id' => $subjectId->id, 'variant_id' => null],
                $newKind->isVariantLevel()                     => ['variant_id' => $subjectId->id],
                default                                        => [],
            };

            Subject::updateWhere(['kind' => $newKind] + $selfCols, 'id = ?', [$subjectId->id]);
        });
    }

    #[\Override]
    public function claimIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        bool $isPrimary = false,
        ?\DateTimeImmutable $validFrom = null,
    ): void {
        $this->session->transactional(function () use ($subjectId, $typeCode, $systemSlug, $value, $scopeActor, $isPrimary, $validFrom): void {
            [$typeId, $systemId, $scopeActorId, $scopeActorTypeId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $policy = $this->loadIdentifierPolicy($systemId, $typeId, $scopeActorTypeId);
            $scopeActorKey = $scopeActorId ?? 0;
            $now = new \DateTimeImmutable();
            $openEnded = IdentifierValidity::OPEN_ENDED;

            // Lock existing rows for this (system, type, scope, value)
            $existingRows = $this->fetchAllRows(
                sprintf(
                    'SELECT subject_id, valid_to FROM %s
                     WHERE system_id = :system_id
                       AND type_id = :type_id
                       AND scope_actor_key = :scope_key
                       AND value = :value
                     FOR UPDATE',
                    $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                ),
                ['system_id' => $systemId, 'type_id' => $typeId, 'scope_key' => $scopeActorKey, 'value' => $value],
            );

            foreach ($existingRows as $row) {
                $rowSubjectId = (int) $row['subject_id'];
                $isActive = (string) $row['valid_to'] === $openEnded;

                if ($rowSubjectId === $subjectId->id && $isActive) {
                    // Same subject already owns this active identifier — idempotent.
                    return;
                }

                if ($rowSubjectId !== $subjectId->id) {
                    if ($isActive && $policy->uniqueActiveValue) {
                        throw new IdentifierAlreadyClaimedException(
                            sprintf('Identifier %s/%s/%s is already claimed by subject %d.', $systemSlug, $typeCode, $value, $rowSubjectId),
                            'identifier_already_claimed',
                            ['system' => $systemSlug, 'type' => $typeCode, 'value' => $value, 'owner_subject_id' => $rowSubjectId],
                        );
                    }

                    if (!$policy->reusableAfterExpiry) {
                        throw new IdentifierValueRetiredException(
                            sprintf('Identifier %s/%s/%s was previously assigned to subject %d and cannot be re-claimed.', $systemSlug, $typeCode, $value, $rowSubjectId),
                            'identifier_value_retired',
                            ['system' => $systemSlug, 'type' => $typeCode, 'value' => $value, 'prior_subject_id' => $rowSubjectId],
                        );
                    }
                }
            }

            SubjectIdentifier::newWith([
                'subject_id'     => $subjectId->id,
                'type_id'        => $typeId,
                'system_id'      => $systemId,
                'value'          => $value,
                'is_primary'     => $isPrimary ? true : null,
                'scope_actor_id' => $scopeActorId,
                'valid_from'     => $validFrom ?? $now,
                'valid_to'       => IdentifierValidity::openEnded(),
            ])->save();
        });
    }

    /**
     * The list-shaped twin of {@see claimIdentifier()} — one round-trip for the whole batch.
     *
     * Same shape as the single-item form, and deliberately the same *semantics*: it is one
     * transaction, it locks the candidate rows before deciding, and one offending value rolls the
     * whole batch back. What it is not is a loop — the row-at-a-time version costs a transaction,
     * two lookups, a locking SELECT and an INSERT per value, so claiming a few thousand supplier
     * codes ran to tens of thousands of round-trips.
     *
     * The flow is the reference one from arch-bulk-ops §4.4: resolve the shared lookups once, take
     * one locking SELECT over every candidate value, partition in memory, and insert what survives
     * in a single multi-row write.
     *
     * @param list<IdentifierClaimSpec> $claims
     */
    #[\Override]
    public function claimIdentifiers(
        string $typeCode,
        string $systemSlug,
        array $claims,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $validFrom = null,
    ): void {
        if ([] === $claims) {
            return;
        }

        // A value appearing twice in one call cannot be honoured for both subjects, and choosing
        // silently would bury a caller's data defect under a result that looks fine.
        $seen = [];
        foreach ($claims as $assignment) {
            if (isset($seen[$assignment->value]) && $seen[$assignment->value] !== $assignment->subjectId->id) {
                throw new \InvalidArgumentException(sprintf(
                    'claimIdentifiers(): value "%s" is claimed by two different subjects (%d and %d) in one call.',
                    $assignment->value,
                    $seen[$assignment->value],
                    $assignment->subjectId->id,
                ));
            }
            $seen[$assignment->value] = $assignment->subjectId->id;
        }

        $this->session->transactional(function () use ($typeCode, $systemSlug, $claims, $scopeActor, $validFrom): void {
            [$typeId, $systemId, $scopeActorId, $scopeActorTypeId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $policy = $this->loadIdentifierPolicy($systemId, $typeId, $scopeActorTypeId);
            $scopeActorKey = $scopeActorId ?? 0;
            $now = new \DateTimeImmutable();
            $openEnded = IdentifierValidity::OPEN_ENDED;

            /** @var list<string> $values */
            $values = array_values(array_unique(array_map(
                static fn (IdentifierClaimSpec $a): string => $a->value,
                $claims,
            )));

            // One locking read over every candidate, in place of one per value.
            $placeholders = [];
            $params = ['system_id' => $systemId, 'type_id' => $typeId, 'scope_key' => $scopeActorKey];
            foreach ($values as $i => $value) {
                $placeholders[] = ':v'.$i;
                $params['v'.$i] = $value;
            }

            $existingRows = $this->fetchAllRows(
                sprintf(
                    'SELECT subject_id, value, valid_to FROM %s
                     WHERE system_id = :system_id
                       AND type_id = :type_id
                       AND scope_actor_key = :scope_key
                       AND value IN (%s)
                     FOR UPDATE',
                    $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                    implode(', ', $placeholders),
                ),
                $params,
            );

            /** @var array<string, list<array{subject_id: int, active: bool}>> $byValue */
            $byValue = [];
            foreach ($existingRows as $row) {
                $byValue[(string) $row['value']][] = [
                    'subject_id' => (int) $row['subject_id'],
                    'active'     => (string) $row['valid_to'] === $openEnded,
                ];
            }

            $records = [];
            foreach ($claims as $assignment) {
                $alreadyOwned = false;

                foreach ($byValue[$assignment->value] ?? [] as $row) {
                    if ($row['subject_id'] === $assignment->subjectId->id) {
                        if ($row['active']) {
                            // The same subject already holds it — idempotent, as for one claim.
                            $alreadyOwned = true;
                        }
                        continue;
                    }

                    if ($row['active'] && $policy->uniqueActiveValue) {
                        throw new IdentifierAlreadyClaimedException(
                            sprintf('Identifier %s/%s/%s is already claimed by subject %d.', $systemSlug, $typeCode, $assignment->value, $row['subject_id']),
                            'identifier_already_claimed',
                            ['system' => $systemSlug, 'type' => $typeCode, 'value' => $assignment->value, 'owner_subject_id' => $row['subject_id']],
                        );
                    }

                    if (!$policy->reusableAfterExpiry) {
                        throw new IdentifierValueRetiredException(
                            sprintf('Identifier %s/%s/%s was previously assigned to subject %d and cannot be re-claimed.', $systemSlug, $typeCode, $assignment->value, $row['subject_id']),
                            'identifier_value_retired',
                            ['system' => $systemSlug, 'type' => $typeCode, 'value' => $assignment->value, 'prior_subject_id' => $row['subject_id']],
                        );
                    }
                }

                if ($alreadyOwned) {
                    continue;
                }

                $records[] = SubjectIdentifier::newWith([
                    'subject_id'     => $assignment->subjectId->id,
                    'type_id'        => $typeId,
                    'system_id'      => $systemId,
                    'value'          => $assignment->value,
                    'is_primary'     => $assignment->isPrimary ? true : null,
                    'scope_actor_id' => $scopeActorId,
                    'valid_from'     => $validFrom ?? $now,
                    'valid_to'       => IdentifierValidity::openEnded(),
                ]);
            }

            if ([] !== $records) {
                (new RecordSet($records))->upsertAll();
            }
        });
    }

    /** @psalm-suppress PossiblyUnusedReturnValue callers outside this package use the count */
    #[\Override]
    public function expireIdentifier(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $validTo = null,
    ): int {
        return $this->session->transactional(function () use ($typeCode, $systemSlug, $value, $scopeActor, $validTo): int {
            [$typeId, $systemId, $scopeActorId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $scopeActorKey = $scopeActorId ?? 0;
            $validTo ??= new \DateTimeImmutable();
            $openEnded = IdentifierValidity::OPEN_ENDED;

            return SubjectIdentifier::updateWhere(
                ['valid_to' => $validTo],
                'system_id = ? AND type_id = ? AND scope_actor_key = ? AND value = ? AND valid_to = ?',
                [$systemId, $typeId, $scopeActorKey, $value, $openEnded],
            );
        });
    }

    #[\Override]
    public function replaceSubjectIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $oldValue,
        string $newValue,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $changedAt = null,
    ): void {
        $this->session->transactional(function () use ($subjectId, $typeCode, $systemSlug, $oldValue, $newValue, $scopeActor, $changedAt): void {
            $changedAt ??= new \DateTimeImmutable();
            [$typeId, $systemId, $scopeActorId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $scopeActorKey = $scopeActorId ?? 0;
            $openEnded = IdentifierValidity::OPEN_ENDED;

            // Expire old value for this subject
            $expired = SubjectIdentifier::updateWhere(
                ['valid_to' => $changedAt],
                'subject_id = ? AND system_id = ? AND type_id = ? AND scope_actor_key = ? AND value = ? AND valid_to = ?',
                [$subjectId->id, $systemId, $typeId, $scopeActorKey, $oldValue, $openEnded],
            );

            if (0 === $expired) {
                throw new ActiveIdentifierNotFoundException(
                    sprintf('No active assignment found for %s/%s/%s on subject %d.', $systemSlug, $typeCode, $oldValue, $subjectId->id),
                    'active_identifier_not_found',
                    ['system' => $systemSlug, 'type' => $typeCode, 'value' => $oldValue, 'subject_id' => $subjectId->id],
                );
            }

            // Claim new value
            $this->claimIdentifier($subjectId, $typeCode, $systemSlug, $newValue, $scopeActor, validFrom: $changedAt);
        });
    }

    #[\Override]
    public function reclaimExpiredIdentifier(
        SubjectId $subjectId,
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
    ): void {
        $this->session->transactional(function () use ($subjectId, $typeCode, $systemSlug, $value, $scopeActor): void {
            [$typeId, $systemId, $scopeActorId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $scopeActorKey = $scopeActorId ?? 0;
            $openEnded = IdentifierValidity::OPEN_ENDED;
            $now = new \DateTimeImmutable();

            // Check uniqueActiveValue — still enforced
            $activeRow = $this->fetchOneRow(
                sprintf(
                    'SELECT subject_id FROM %s
                     WHERE system_id = :system_id AND type_id = :type_id
                       AND scope_actor_key = :scope_key AND value = :value
                       AND valid_to = :open_ended
                     LIMIT 1',
                    $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                ),
                ['system_id' => $systemId, 'type_id' => $typeId, 'scope_key' => $scopeActorKey, 'value' => $value, 'open_ended' => $openEnded],
            );

            if (is_array($activeRow)) {
                if ((int) $activeRow['subject_id'] === $subjectId->id) {
                    return; // Already active — idempotent
                }

                $policy = $this->loadIdentifierPolicy($systemId, $typeId, null);
                if ($policy->uniqueActiveValue) {
                    throw new IdentifierAlreadyClaimedException(
                        sprintf('Identifier %s/%s/%s is currently claimed by subject %d.', $systemSlug, $typeCode, $value, (int) $activeRow['subject_id']),
                        'identifier_already_claimed',
                        ['system' => $systemSlug, 'type' => $typeCode, 'value' => $value, 'owner_subject_id' => (int) $activeRow['subject_id']],
                    );
                }
            }

            // Insert new open-ended assignment (bypasses reusableAfterExpiry)
            SubjectIdentifier::newWith([
                'subject_id'     => $subjectId->id,
                'type_id'        => $typeId,
                'system_id'      => $systemId,
                'value'          => $value,
                'scope_actor_id' => $scopeActorId,
                'valid_from'     => $now,
                'valid_to'       => IdentifierValidity::openEnded(),
            ])->save();
        });
    }

    #[\Override]
    public function migrateActiveIdentifiers(SubjectId $from, SubjectId $to): int
    {
        return $this->session->transactional(function () use ($from, $to): int {
            $openEnded = IdentifierValidity::OPEN_ENDED;
            $now = new \DateTimeImmutable();

            // Pin the exact rows to move under lock; both statements below key on these ids, so a
            // concurrent claim on $from can neither be swept along nor half-moved.
            $rows = $this->fetchAllRows(
                sprintf(
                    'SELECT id FROM %s WHERE subject_id = :from_id AND valid_to = :open_ended FOR UPDATE',
                    $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                ),
                ['from_id' => $from->id, 'open_ended' => $openEnded],
            );
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            if ([] === $ids) {
                return 0;
            }
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            // Expire on $from BEFORE re-creating on $to: the active-window unique key
            // (…, value, valid_to) admits one open row per value, and the other order would
            // momentarily hold two.
            SubjectIdentifier::updateWhere(
                ['valid_to' => $now],
                sprintf('id IN (%s)', $placeholders),
                $ids,
            );

            // Re-create each assignment on $to preserving type/system/value/scope/primary
            // (scope_actor_key is STORED-generated — never in the column list). INSERT … SELECT is
            // a multi-table write, one of the four sanctioned raw-SQL shapes. INSERT IGNORE
            // tolerates exactly one duplicate: $to already actively holds the same
            // (system, type, scope, value) — then there is nothing to move, and the $from row
            // staying expired is the correct end state either way.
            return $this->session->exec(
                sprintf(
                    'INSERT IGNORE INTO %1$s
                        (subject_id, type_id, system_id, value, is_primary, scope_actor_id, valid_from, valid_to)
                     SELECT ?, type_id, system_id, value, is_primary, scope_actor_id, ?, ?
                       FROM %1$s
                      WHERE id IN (%2$s)',
                    $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                    $placeholders,
                ),
                array_merge([$to->id, $now->format('Y-m-d H:i:s.u'), $openEnded], $ids),
            );
        });
    }

    #[\Override]
    public function resolveExpiredIdentifierSubject(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?SubjectKind $ofKind = null,
        ?ActorReference $scopeActor = null,
    ): ?SubjectId {
        [$typeId, $systemId, $scopeActorId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
        $scopeActorKey = $scopeActorId ?? 0;

        // Most recent holder wins: a post that changed kind twice has two priors, and revival wants
        // the one whose tenure ended last. JOIN on subjects for the kind filter (multi-table read —
        // sanctioned raw-SQL shape).
        $params = [
            'system_id'  => $systemId,
            'type_id'    => $typeId,
            'scope_key'  => $scopeActorKey,
            'value'      => $value,
            'open_ended' => IdentifierValidity::OPEN_ENDED,
        ];
        $kindClause = '';
        if (null !== $ofKind) {
            $kindClause = ' AND s.kind = :kind';
            $params['kind'] = $ofKind->value;
        }

        $row = $this->fetchOneRow(
            sprintf(
                'SELECT si.subject_id
                   FROM %s si
                   JOIN %s s ON s.id = si.subject_id
                  WHERE si.system_id = :system_id
                    AND si.type_id = :type_id
                    AND si.scope_actor_key = :scope_key
                    AND si.value = :value
                    AND si.valid_to <> :open_ended%s
                  ORDER BY si.valid_to DESC
                  LIMIT 1',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                $this->table(self::TABLE_SUBJECTS),
                $kindClause,
            ),
            $params,
        );

        return is_array($row) ? new SubjectId((int) $row['subject_id']) : null;
    }

    #[\Override]
    public function resolveIdentifier(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): ?SubjectId {
        $assignment = $this->resolveIdentifierAssignment($typeCode, $systemSlug, $value, $scopeActor, $asOf);

        return $assignment?->subjectId;
    }

    #[\Override]
    public function resolveIdentifierAssignment(
        string $typeCode,
        string $systemSlug,
        string $value,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): ?SubjectIdentifierAssignment {
        $asOf ??= new \DateTimeImmutable();
        $asOfStr = $asOf->format('Y-m-d H:i:s.u');

        $scopeActorKey = null !== $scopeActor ? $this->resolveActorKey($scopeActor) : 0;

        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT si.subject_id, si.is_primary, si.scope_actor_id, si.valid_from, si.valid_to
                 FROM %s si
                 JOIN %s it ON it.id = si.type_id
                 JOIN %s s  ON s.id  = si.system_id
                 WHERE it.code = :type_code
                   AND s.slug  = :system_slug
                   AND si.scope_actor_key = :scope_key
                   AND si.value = :value
                   AND si.valid_from <= :as_of
                   AND si.valid_to > :as_of_to
                 LIMIT 2',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                $this->table(self::TABLE_IDENTIFIER_TYPES),
                $this->table(self::TABLE_SYSTEMS),
            ),
            [
                'type_code'   => $typeCode,
                'system_slug' => $systemSlug,
                'scope_key'   => $scopeActorKey,
                'value'       => $value,
                'as_of'       => $asOfStr,
                'as_of_to'    => $asOfStr,
            ],
        );

        if ([] === $rows) {
            return null;
        }

        if (2 === count($rows)) {
            throw new AmbiguousIdentifierAssignmentException(
                sprintf('Multiple active assignments found for %s/%s/%s.', $systemSlug, $typeCode, $value),
                'ambiguous_identifier_assignment',
                ['system' => $systemSlug, 'type' => $typeCode, 'value' => $value],
            );
        }

        $row = $rows[0];

        return new SubjectIdentifierAssignment(
            subjectId: new SubjectId((int) $row['subject_id']),
            systemSlug: $systemSlug,
            typeCode: $typeCode,
            value: $value,
            scopeActor: $scopeActor,
            isPrimary: 1 === (int) ($row['is_primary'] ?? 0),
            validFrom: new \DateTimeImmutable((string) $row['valid_from']),
            validTo: new \DateTimeImmutable((string) $row['valid_to']),
        );
    }

    #[\Override]
    public function listSubjectIdentifiers(
        SubjectId $subjectId,
        ?string $systemSlug = null,
        ?\DateTimeImmutable $asOf = null,
    ): array {
        $asOf ??= new \DateTimeImmutable();
        $asOfStr = $asOf->format('Y-m-d H:i:s.u');

        $systemFilter = null !== $systemSlug ? 'AND s.slug = :system_slug' : '';

        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT si.subject_id, s.slug AS system_slug, it.code AS type_code,
                        si.value, si.is_primary, si.scope_actor_id, si.valid_from, si.valid_to,
                        at.code AS scope_actor_type, a.actor_ref AS scope_actor_ref
                 FROM %s si
                 JOIN %s it ON it.id = si.type_id
                 JOIN %s s  ON s.id  = si.system_id
                 LEFT JOIN %s a  ON a.id = si.scope_actor_id
                 LEFT JOIN %s at ON at.id = a.actor_type_id
                 WHERE si.subject_id = :subject_id
                   AND si.valid_from <= :as_of
                   AND si.valid_to > :as_of_to
                   %s
                 ORDER BY si.valid_from DESC',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                $this->table(self::TABLE_IDENTIFIER_TYPES),
                $this->table(self::TABLE_SYSTEMS),
                $this->table(self::TABLE_ACTORS),
                $this->table(self::TABLE_ACTOR_TYPES),
                $systemFilter,
            ),
            array_filter([
                'subject_id'  => $subjectId->id,
                'as_of'       => $asOfStr,
                'as_of_to'    => $asOfStr,
                'system_slug' => $systemSlug,
            ], static fn ($v): bool => null !== $v),
        );

        return array_map(function (array $row): SubjectIdentifierAssignment {
            $scopeActor = null;
            if (null !== $row['scope_actor_type'] && null !== $row['scope_actor_ref']) {
                $scopeActor = new ActorReference((string) $row['scope_actor_type'], (string) $row['scope_actor_ref']);
            }

            return new SubjectIdentifierAssignment(
                subjectId: new SubjectId((int) $row['subject_id']),
                systemSlug: (string) $row['system_slug'],
                typeCode: (string) $row['type_code'],
                value: (string) $row['value'],
                scopeActor: $scopeActor,
                isPrimary: 1 === (int) ($row['is_primary'] ?? 0),
                validFrom: new \DateTimeImmutable((string) $row['valid_from']),
                validTo: new \DateTimeImmutable((string) $row['valid_to']),
            );
        }, $rows);
    }

    /**
     * Bulk twin of {@see listSubjectIdentifiers()} — one round-trip over a `subject_id IN (…)`
     * set, results grouped by subject id.
     *
     * @param list<SubjectId> $subjectIds
     *
     * @return array<int, list<SubjectIdentifierAssignment>> keyed by subject id
     */
    #[\Override]
    public function listSubjectIdentifiersForMany(
        array $subjectIds,
        ?string $systemSlug = null,
        ?\DateTimeImmutable $asOf = null,
    ): array {
        // De-duplicate + normalize to ints; empty input short-circuits.
        $ids = array_values(array_unique(array_map(static fn (SubjectId $s): int => $s->id, $subjectIds)));
        if ([] === $ids) {
            return [];
        }

        $asOf ??= new \DateTimeImmutable();
        $asOfStr = $asOf->format('Y-m-d H:i:s.u');

        $inParams = [];
        $inPlaceholders = [];
        foreach ($ids as $i => $id) {
            $key = 'sid'.$i;
            $inPlaceholders[] = ':'.$key;
            $inParams[$key] = $id;
        }

        $systemFilter = null !== $systemSlug ? 'AND s.slug = :system_slug' : '';

        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT si.subject_id, s.slug AS system_slug, it.code AS type_code,
                        si.value, si.is_primary, si.scope_actor_id, si.valid_from, si.valid_to,
                        at.code AS scope_actor_type, a.actor_ref AS scope_actor_ref
                 FROM %s si
                 JOIN %s it ON it.id = si.type_id
                 JOIN %s s  ON s.id  = si.system_id
                 LEFT JOIN %s a  ON a.id = si.scope_actor_id
                 LEFT JOIN %s at ON at.id = a.actor_type_id
                 WHERE si.subject_id IN (%s)
                   AND si.valid_from <= :as_of
                   AND si.valid_to > :as_of_to
                   %s
                 ORDER BY si.subject_id ASC, si.valid_from DESC',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                $this->table(self::TABLE_IDENTIFIER_TYPES),
                $this->table(self::TABLE_SYSTEMS),
                $this->table(self::TABLE_ACTORS),
                $this->table(self::TABLE_ACTOR_TYPES),
                implode(', ', $inPlaceholders),
                $systemFilter,
            ),
            array_filter([
                ...$inParams,
                'as_of'       => $asOfStr,
                'as_of_to'    => $asOfStr,
                'system_slug' => $systemSlug,
            ], static fn ($v): bool => null !== $v),
        );

        /** @var array<int, list<SubjectIdentifierAssignment>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $scopeActor = null;
            if (null !== $row['scope_actor_type'] && null !== $row['scope_actor_ref']) {
                $scopeActor = new ActorReference((string) $row['scope_actor_type'], (string) $row['scope_actor_ref']);
            }

            $subjectId = (int) $row['subject_id'];
            $grouped[$subjectId][] = new SubjectIdentifierAssignment(
                subjectId: new SubjectId($subjectId),
                systemSlug: (string) $row['system_slug'],
                typeCode: (string) $row['type_code'],
                value: (string) $row['value'],
                scopeActor: $scopeActor,
                isPrimary: 1 === (int) ($row['is_primary'] ?? 0),
                validFrom: new \DateTimeImmutable((string) $row['valid_from']),
                validTo: new \DateTimeImmutable((string) $row['valid_to']),
            );
        }

        return $grouped;
    }

    #[\Override]
    public function resolveSubjectKind(SubjectId $id): ?SubjectKind
    {
        $row = $this->session->fetchOne(
            sprintf('SELECT kind FROM %s WHERE id = :id', $this->table(self::TABLE_SUBJECTS)),
            ['id' => $id->id],
        );

        if (null === $row) {
            return null;
        }

        return SubjectKind::tryFrom((string) ($row['kind'] ?? ''));
    }

    #[\Override]
    public function resolveSubjectKinds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $names[] = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        $rows = $this->session->fetchAll(
            sprintf('SELECT id, kind FROM %s WHERE id IN (%s)', $this->table(self::TABLE_SUBJECTS), implode(', ', $names)),
            $params,
        );

        $kinds = [];
        foreach ($rows as $row) {
            $kind = SubjectKind::tryFrom((string) ($row['kind'] ?? ''));
            if (null !== $kind) {
                $kinds[(int) $row['id']] = $kind;
            }
        }

        return $kinds;
    }

    #[\Override]
    public function resolveSubjectGovernance(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $names[] = ":id{$i}";
            $params["id{$i}"] = $id;
        }

        $rows = $this->session->fetchAll(
            sprintf('SELECT id, ivfx_governed FROM %s WHERE id IN (%s)', $this->table(self::TABLE_SUBJECTS), implode(', ', $names)),
            $params,
        );

        $governance = [];
        foreach ($rows as $row) {
            $governance[(int) $row['id']] = 1 === (int) ($row['ivfx_governed'] ?? 0);
        }

        return $governance;
    }

    // -------------------------------------------------------------------------
    // SubjectRegistrar — bulk endpoints
    // -------------------------------------------------------------------------

    #[\Override]
    public function resolveIdentifiers(
        string $typeCode,
        string $systemSlug,
        array $values,
        ?ActorReference $scopeActor = null,
        ?\DateTimeImmutable $asOf = null,
    ): array {
        if ([] === $values) {
            return [];
        }

        $asOf ??= new \DateTimeImmutable();
        $asOfStr = $asOf->format('Y-m-d H:i:s.u');
        $scopeActorKey = null !== $scopeActor ? $this->resolveActorKey($scopeActor) : 0;
        $uniqueValues = array_values(array_unique($values));

        $placeholders = implode(', ', array_fill(0, count($uniqueValues), '?'));
        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT si.value, si.subject_id
                 FROM %s si
                 JOIN %s it ON it.id = si.type_id
                 JOIN %s s  ON s.id  = si.system_id
                 WHERE it.code = ?
                   AND s.slug  = ?
                   AND si.scope_actor_key = ?
                   AND si.value IN ('.$placeholders.')
                   AND si.valid_from <= ?
                   AND si.valid_to > ?',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
                $this->table(self::TABLE_IDENTIFIER_TYPES),
                $this->table(self::TABLE_SYSTEMS),
            ),
            [$typeCode, $systemSlug, $scopeActorKey, ...$uniqueValues, $asOfStr, $asOfStr],
        );

        /** @var array<string, SubjectId> $resolved */
        $resolved = [];
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            if (isset($seen[$value])) {
                throw new AmbiguousIdentifierAssignmentException(
                    sprintf('Multiple active assignments found for %s/%s/%s.', $systemSlug, $typeCode, $value),
                    'ambiguous_identifier_assignment',
                    ['system' => $systemSlug, 'type' => $typeCode, 'value' => $value],
                );
            }
            $seen[$value] = true;
            $resolved[$value] = new SubjectId((int) $row['subject_id']);
        }

        return $resolved;
    }

    #[\Override]
    public function resolveOrCreateManyByIdentifier(
        string $typeCode,
        string $systemSlug,
        array $claims,
        ?ActorReference $scopeActor = null,
    ): array {
        if ([] === $claims) {
            return [];
        }

        return $this->session->transactional(function () use ($typeCode, $systemSlug, $claims, $scopeActor): array {
            [$typeId, $systemId, $scopeActorId, $scopeActorTypeId] = $this->resolveIdentifierLookups($typeCode, $systemSlug, $scopeActor);
            $policy = $this->loadIdentifierPolicy($systemId, $typeId, $scopeActorTypeId);
            $scopeActorKey = $scopeActorId ?? 0;

            // Bulk-resolve existing assignments.
            $values = array_map(static fn (SubjectIdentifierClaim $c): string => $c->value, $claims);
            $existing = $this->resolveIdentifiers($typeCode, $systemSlug, $values, $scopeActor);

            // Partition into reuse vs create.
            /** @var list<SubjectIdentifierClaim> $missing */
            $missing = [];
            /** @var array<string, SubjectId> $result */
            $result = [];
            /** @var array<string, true> $seenValue */
            $seenValue = [];
            foreach ($claims as $claim) {
                // Duplicate input values: first claim wins; subsequent duplicates skip.
                // The output map still receives one entry per unique value.
                if (isset($seenValue[$claim->value])) {
                    continue;
                }
                $seenValue[$claim->value] = true;

                if (isset($existing[$claim->value])) {
                    $result[$claim->value] = $existing[$claim->value];
                } else {
                    $missing[] = $claim;
                }
            }

            if ([] === $missing) {
                return $result;
            }

            // Enforce reusableAfterExpiry policy across the missing set in one query.
            if (!$policy->reusableAfterExpiry) {
                $retired = $this->findRetiredIdentifierValues(
                    $typeId,
                    $systemId,
                    $scopeActorKey,
                    array_map(static fn (SubjectIdentifierClaim $c): string => $c->value, $missing),
                );
                if ([] !== $retired) {
                    throw new IdentifierValueRetiredException(
                        sprintf(
                            'Identifier value(s) retired for %s/%s: %s.',
                            $systemSlug,
                            $typeCode,
                            implode(', ', $retired),
                        ),
                        'identifier_value_retired',
                        ['system' => $systemSlug, 'type' => $typeCode, 'values' => $retired],
                    );
                }
            }

            // Bulk-load parent subject metadata for kind validation + denormalized FK inheritance.
            $parentIdSet = [];
            foreach ($missing as $claim) {
                if (null !== $claim->parentSubjectId) {
                    $parentIdSet[$claim->parentSubjectId->id] = true;
                }
            }
            $parentInfo = [] !== $parentIdSet
                ? $this->loadParentSubjectInfo(array_keys($parentIdSet))
                : [];

            foreach ($missing as $claim) {
                $this->validateBulkClaimAgainstParent($claim, $parentInfo);
            }

            // Bulk-INSERT subjects, then bulk-INSERT identifier rows.
            $newIds = $this->bulkInsertSubjects($missing, $parentInfo);
            $this->bulkInsertSubjectIdentifiers($typeId, $systemId, $scopeActorId, $missing, $newIds);

            foreach ($missing as $i => $claim) {
                $result[$claim->value] = new SubjectId($newIds[$i]);
            }

            return $result;
        });
    }

    /**
     * Find which of the given values have at least one historical assignment that
     * is no longer active (used to enforce `reusableAfterExpiry = false`).
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function findRetiredIdentifierValues(int $typeId, int $systemId, int $scopeKey, array $values): array
    {
        if ([] === $values) {
            return [];
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT DISTINCT value FROM %s
                 WHERE system_id = ?
                   AND type_id = ?
                   AND scope_actor_key = ?
                   AND value IN ('.$placeholders.')
                   AND valid_to <= ?',
                $this->table(self::TABLE_SUBJECT_IDENTIFIERS),
            ),
            [$systemId, $typeId, $scopeKey, ...$values, $now],
        );

        return array_map(static fn (array $r): string => (string) $r['value'], $rows);
    }

    /**
     * Bulk-load (kind, product_id, variant_id) for a set of parent subject IDs.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{kind: SubjectKind, product_id: ?int, variant_id: ?int}>
     */
    private function loadParentSubjectInfo(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->fetchAllRows(
            sprintf(
                'SELECT id, kind, product_id, variant_id FROM %s WHERE id IN ('.$placeholders.')',
                $this->table(self::TABLE_SUBJECTS),
            ),
            $ids,
        );

        $info = [];
        foreach ($rows as $row) {
            $info[(int) $row['id']] = [
                'kind'       => SubjectKind::from((string) $row['kind']),
                'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
                'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
            ];
        }

        return $info;
    }

    /**
     * Validate kind/parent compatibility for one claim. Mirrors registerSubject()'s
     * inline checks but reads pre-fetched parent metadata to keep the bulk path
     * to O(1) parent SELECTs.
     *
     * @param array<int, array{kind: SubjectKind, product_id: ?int, variant_id: ?int}> $parentInfo
     */
    private function validateBulkClaimAgainstParent(SubjectIdentifierClaim $claim, array $parentInfo): void
    {
        if (null === $claim->parentSubjectId) {
            if (SubjectKind::Batch === $claim->subjectKind) {
                throw new ConfigurationException(
                    'A batch subject must have a parent unit subject.',
                    'subject_batch_requires_parent',
                );
            }

            return;
        }

        $parentId = $claim->parentSubjectId->id;
        if (!isset($parentInfo[$parentId])) {
            throw new PersistenceException(
                sprintf('Parent subject %d not found.', $parentId),
                'subject_parent_not_found',
                ['parentId' => $parentId],
            );
        }

        $parentKind = $parentInfo[$parentId]['kind'];
        if (!$claim->subjectKind->canBeChildOf($parentKind)) {
            // The claim's identifier value is in the message, not only in the context array. This
            // is thrown from a bulk resolve that can be carrying tens of thousands of claims, so
            // "a unit cannot be a child of a unit" on its own says only that one row in the batch
            // is wrong — and leaves the reader to find it across the whole catalogue.
            throw new ConfigurationException(
                sprintf(
                    'A %s subject cannot be a child of a %s subject (identifier "%s", parent subject %d). '
                    .'A parent that has become variable needs its identity moved to an aggregate — '
                    .'re-save the parent product to trigger it.',
                    $claim->subjectKind->value,
                    $parentKind->value,
                    $claim->value,
                    $parentId,
                ),
                'subject_kind_parent_mismatch',
                [
                    'kind'       => $claim->subjectKind->value,
                    'parentKind' => $parentKind->value,
                    'parentId'   => $parentId,
                    'value'      => $claim->value,
                ],
            );
        }
    }

    /**
     * Bulk-insert N subject rows in one `INSERT ... VALUES (...), (...)` statement.
     * Returns the assigned IDs in the same order as the input claims.
     *
     * Relies on InnoDB's consecutive-ID allocation for bulk INSERT with a known
     * row count: `LAST_INSERT_ID()` returns the first ID, the next N-1 rows have
     * IDs `[first+1 .. first+N-1]`. This guarantee holds for innodb_autoinc_lock_mode
     * 0, 1, and 2 when the row count is pre-determined (i.e. not INSERT ... SELECT).
     *
     * Self-referential FK columns (product_id / variant_id for root subjects, plus
     * variant_id for unit children of aggregates) are filled in by follow-up
     * bulk UPDATEs grouped by which columns need self-setting.
     *
     * @param list<SubjectIdentifierClaim>                                             $claims
     * @param array<int, array{kind: SubjectKind, product_id: ?int, variant_id: ?int}> $parentInfo
     *
     * @return list<int> assigned subject IDs in the same order as $claims
     */
    private function bulkInsertSubjects(array $claims, array $parentInfo): array
    {
        $now = new \DateTimeImmutable();

        // Build Subject Record instances. PK left null so RecordSet::upsertAll
        // takes its plain multi-row INSERT path and we read assigned IDs from
        // the resulting SaveResult.
        $records = [];
        foreach ($claims as $claim) {
            $parentId = $claim->parentSubjectId?->id;
            $isRoot = null === $parentId;
            // Unit and Kit share the self-referential FK shape (variant_id = self);
            // only Batch inherits its parent unit's variant_id.
            $isSelfVariant = $claim->subjectKind->isUnit() || $claim->subjectKind->isKit();
            $parentProductId = null !== $parentId ? ($parentInfo[$parentId]['product_id'] ?? null) : null;
            $parentVariantId = null !== $parentId ? ($parentInfo[$parentId]['variant_id'] ?? null) : null;

            $records[] = Subject::newWith([
                'parent_id'  => $parentId,
                'kind'       => $claim->subjectKind,
                'product_id' => $isRoot ? null : $parentProductId,
                'variant_id' => match (true) {
                    $isRoot || $isSelfVariant => null,      // filled by self-FK UPDATE
                    default                   => $parentVariantId, // batch: inherit from parent unit
                },
                // Ungoverned by default (opt-in): bulk-materialised subjects hold no governance
                // decision — they behave as vanilla WooCommerce until deliberately adopted. Stated
                // explicitly rather than leaning on the Record property default.
                'ivfx_governed' => false,
                'created_at'    => $now,
            ]);
        }

        $saveResult = (new RecordSet($records))->upsertAll();
        if (null === $saveResult || [] === $saveResult->insertedIds) {
            throw new \LogicException('RecordSet::upsertAll did not return inserted IDs for new subjects.');
        }

        /** @var list<int> $ids */
        $ids = array_map(static fn (int | string $id): int => (int) $id, $saveResult->insertedIds);

        // Group new IDs by self-FK shape and emit one bulk UPDATE per non-empty group.
        //
        // Self-FK fixup is required when the canonical row layout (see Subject docblock)
        // assigns *self* to product_id and/or variant_id — we INSERT those columns as
        // NULL and patch them up afterwards because we don't know the AUTO_INCREMENT
        // value at INSERT time.
        //
        // The `default` bucket catches Batch (and any other future shape) where both
        // product_id and variant_id are inherited from the parent at INSERT time
        // (see the bulk-insert loop above); no fixup is needed for that group.
        $idsByType = [];
        foreach ($claims as $i => $claim) {
            $id = $ids[$i];
            $isRoot = null === $claim->parentSubjectId;
            $kind = $claim->subjectKind;
            $type = match (true) {
                // Unit and Kit share the self-FK shape (see bulk-insert loop above).
                $isRoot && ($kind->isUnit() || $kind->isKit()) => 'root_unit',      // simple product/kit: product_id = variant_id = self
                $isRoot && $kind->isAggregate()                => 'root_aggregate', // variable product: product_id = self, variant_id stays null
                $kind->isUnit() || $kind->isKit()              => 'child_unit',   // variation/variation-kit: variant_id = self (product_id inherited)
                default                                        => 'parent_inherited', // batch and any future shape: nothing to do
            };
            $idsByType[$type][] = $id;
        }

        $selfId = new RawSql('`id`');
        $this->bulkSelfFkUpdate(['product_id' => $selfId, 'variant_id' => $selfId], $idsByType['root_unit'] ?? []);
        $this->bulkSelfFkUpdate(['product_id' => $selfId], $idsByType['root_aggregate'] ?? []);
        $this->bulkSelfFkUpdate(['variant_id' => $selfId], $idsByType['child_unit'] ?? []);
        // 'parent_inherited' intentionally has no UPDATE — see comment above.

        return $ids;
    }

    /**
     * Emit one bulk UPDATE setting self-referential FK columns for a homogeneous
     * group of newly-inserted subject IDs. No-op when the group is empty.
     *
     * The SET assigns a column to the row's own `id`, which is why the values are {@see RawSql}
     * rather than bound parameters — the value is not known until the row exists.
     *
     * @param array<string, RawSql> $set column => self-referential expression
     * @param list<int>             $ids
     */
    private function bulkSelfFkUpdate(array $set, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        Subject::updateWhere($set, sprintf('id IN (%s)', $placeholders), $ids);
    }

    /**
     * Bulk-insert subject_identifier rows for newly-created subjects, all sharing
     * one (type, system, scopeActor) tuple. One `INSERT ... VALUES (...), (...)`
     * regardless of N.
     *
     * @param list<SubjectIdentifierClaim> $claims
     * @param list<int>                    $subjectIds
     */
    private function bulkInsertSubjectIdentifiers(
        int $typeId,
        int $systemId,
        ?int $scopeActorId,
        array $claims,
        array $subjectIds,
    ): void {
        $now = new \DateTimeImmutable();
        $openEnded = new \DateTimeImmutable(IdentifierValidity::OPEN_ENDED);

        $records = [];
        foreach ($claims as $i => $claim) {
            $records[] = SubjectIdentifier::newWith([
                'subject_id'     => $subjectIds[$i],
                'type_id'        => $typeId,
                'system_id'      => $systemId,
                'value'          => $claim->value,
                'is_primary'     => $claim->isPrimary,
                'scope_actor_id' => $scopeActorId,
                'valid_from'     => $now,
                'valid_to'       => $openEnded,
            ]);
        }

        (new RecordSet($records))->upsertAll();
    }

    // -------------------------------------------------------------------------
    // SystemRegistrar
    // -------------------------------------------------------------------------

    #[\Override]
    public function registerSystem(string $slug, string $name, ?string $plugin = null): void
    {
        (new RecordSet([SystemRecord::newWith([
            'slug'   => $slug,
            'name'   => $name,
            'plugin' => $plugin,
        ])]))->upsertAllByUniqueKey('uniq_system_slug');
    }

    // -------------------------------------------------------------------------
    // IdentifierTypeRegistrar
    // -------------------------------------------------------------------------

    #[\Override]
    public function registerIdentifierType(string $code, string $name, ?string $category = null): void
    {
        (new RecordSet([IdentifierTypeRecord::newWith([
            'code'     => $code,
            'name'     => $name,
            'category' => $category,
        ])]))->upsertAllByUniqueKey('uniq_identifier_type_code');
    }

    #[\Override]
    public function configureIdentifierAssignment(
        string $systemSlug,
        string $typeCode,
        IdentifierAssignmentPolicy $policy,
        ?string $scopeActorTypeCode = null,
    ): void {
        $typeId = $this->fetchScalarValue(
            sprintf('SELECT id FROM %s WHERE code = :code', $this->table(self::TABLE_IDENTIFIER_TYPES)),
            ['code' => $typeCode],
        );
        if (null === $typeId) {
            throw new ConfigurationException(
                sprintf('Unknown identifier type code "%s".', $typeCode),
                'unknown_identifier_type',
                ['code' => $typeCode],
            );
        }

        $systemId = $this->fetchScalarValue(
            sprintf('SELECT id FROM %s WHERE slug = :slug', $this->table(self::TABLE_SYSTEMS)),
            ['slug' => $systemSlug],
        );
        if (null === $systemId) {
            throw new ConfigurationException(
                sprintf('Unknown system slug "%s".', $systemSlug),
                'unknown_system',
                ['slug' => $systemSlug],
            );
        }

        $actorTypeId = null;
        if (null !== $scopeActorTypeCode) {
            $actorTypeId = $this->fetchScalarValue(
                sprintf('SELECT id FROM %s WHERE code = :code', $this->table(self::TABLE_ACTOR_TYPES)),
                ['code' => $scopeActorTypeCode],
            );
            if (null === $actorTypeId) {
                throw new ConfigurationException(
                    sprintf('Unknown actor type code "%s".', $scopeActorTypeCode),
                    'unknown_actor_type',
                    ['code' => $scopeActorTypeCode],
                );
            }
        }

        // Burn-free upsert on the composite UNIQUE (system_id, type_id, scope_actor_type_key).
        // The conflict key includes the STORED generated column scope_actor_type_key =
        // IFNULL(scope_actor_type_id, 0); PHP won't compute it, so we set it explicitly to match
        // the value the DB stores, letting the burn-free SELECT-then-UPDATE/INSERT find the row.
        $scopeKey = null !== $actorTypeId ? (int) $actorTypeId : 0;
        $row = IdentifierAssignmentPolicyDdl::newWith([
            'system_id'             => (int) $systemId,
            'type_id'               => (int) $typeId,
            'scope_actor_type_id'   => null !== $actorTypeId ? (int) $actorTypeId : null,
            'unique_active_value'   => $policy->uniqueActiveValue,
            'reusable_after_expiry' => $policy->reusableAfterExpiry,
            'lifecycle_anchor'      => $policy->lifecycleAnchor,
            'mutable_alias'         => $policy->mutableAlias,
        ]);
        $row->scope_actor_type_key = $scopeKey;
        $row->upsertByUniqueKey(
            'uniq_identifier_policy',
            ['unique_active_value', 'reusable_after_expiry', 'lifecycle_anchor', 'mutable_alias'],
            preserveAutoIncrement: true,
        );
    }

    // -------------------------------------------------------------------------
    // Private identifier helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve system_id, type_id, optional scope_actor_id, and scope_actor_type_id for an identifier operation.
     *
     * @return array{int, int, int|null, int|null}
     */
    private function resolveIdentifierLookups(
        string $typeCode,
        string $systemSlug,
        ?ActorReference $scopeActor,
    ): array {
        $typeId = $this->fetchScalarValue(
            sprintf('SELECT id FROM %s WHERE code = :code', $this->table(self::TABLE_IDENTIFIER_TYPES)),
            ['code' => $typeCode],
        );
        if (null === $typeId) {
            throw new ConfigurationException(
                sprintf('Unknown identifier type code "%s".', $typeCode),
                'unknown_identifier_type',
                ['code' => $typeCode],
            );
        }

        $systemId = $this->fetchScalarValue(
            sprintf('SELECT id FROM %s WHERE slug = :slug', $this->table(self::TABLE_SYSTEMS)),
            ['slug' => $systemSlug],
        );
        if (null === $systemId) {
            throw new ConfigurationException(
                sprintf('Unknown system slug "%s". Register the system before adding identifiers.', $systemSlug),
                'unknown_system',
                ['slug' => $systemSlug],
            );
        }

        $scopeActorId = null;
        $scopeActorTypeId = null;

        if (null !== $scopeActor) {
            $row = $this->fetchOneRow(
                sprintf(
                    'SELECT a.id, at.id AS type_id FROM %s a
                     JOIN %s at ON at.id = a.actor_type_id
                     WHERE at.code = :type_code AND a.actor_ref = :actor_ref',
                    $this->table(self::TABLE_ACTORS),
                    $this->table(self::TABLE_ACTOR_TYPES),
                ),
                ['type_code' => $scopeActor->typeCode, 'actor_ref' => $scopeActor->actorId],
            );

            if (!is_array($row)) {
                throw new ConfigurationException(
                    sprintf('Actor %s/%s not found.', $scopeActor->typeCode, (string) $scopeActor->actorId),
                    'unknown_actor',
                    ['type_code' => $scopeActor->typeCode, 'actor_id' => $scopeActor->actorId],
                );
            }

            $scopeActorId = (int) $row['id'];
            $scopeActorTypeId = (int) $row['type_id'];
        }

        return [(int) $typeId, (int) $systemId, $scopeActorId, $scopeActorTypeId];
    }

    /**
     * Load the assignment policy for (system, type, optional actor type), falling back to
     * the unscoped policy when no actor-type-specific row exists, then to defaults.
     */
    private function loadIdentifierPolicy(int $systemId, int $typeId, ?int $scopeActorTypeId): IdentifierAssignmentPolicy
    {
        $scopeKey = $scopeActorTypeId ?? 0;

        // Try scoped policy first, then fall back to unscoped (scope_actor_type_key = 0)
        $row = $this->fetchOneRow(
            sprintf(
                'SELECT unique_active_value, reusable_after_expiry, lifecycle_anchor, mutable_alias
                 FROM %s
                 WHERE system_id = :system_id
                   AND type_id = :type_id
                   AND scope_actor_type_key IN (:scope_key, 0)
                 ORDER BY scope_actor_type_key DESC
                 LIMIT 1',
                $this->table(self::TABLE_IDENTIFIER_ASSIGNMENT_POLICIES),
            ),
            ['system_id' => $systemId, 'type_id' => $typeId, 'scope_key' => $scopeKey],
        );

        if (!is_array($row)) {
            return new IdentifierAssignmentPolicy();
        }

        return new IdentifierAssignmentPolicy(
            uniqueActiveValue: 1 === (int) $row['unique_active_value'],
            reusableAfterExpiry: 1 === (int) $row['reusable_after_expiry'],
            lifecycleAnchor: 1 === (int) $row['lifecycle_anchor'],
            mutableAlias: 1 === (int) $row['mutable_alias'],
        );
    }

    /**
     * Resolve a scope ActorReference to its invflux_actors PK key (0 = unscoped).
     */
    private function resolveActorKey(?ActorReference $actor): int
    {
        if (null === $actor) {
            return 0;
        }

        $id = $this->fetchScalarValue(
            sprintf(
                'SELECT a.id FROM %s a
                 JOIN %s at ON at.id = a.actor_type_id
                 WHERE at.code = :type_code AND a.actor_ref = :actor_ref',
                $this->table(self::TABLE_ACTORS),
                $this->table(self::TABLE_ACTOR_TYPES),
            ),
            ['type_code' => $actor->typeCode, 'actor_ref' => $actor->actorId],
        );

        return null !== $id ? (int) $id : 0;
    }
}
