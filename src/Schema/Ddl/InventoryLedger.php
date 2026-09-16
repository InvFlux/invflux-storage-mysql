<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\AppendOnly;
use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\RefTypeRecord;
use Nandan108\InvFlux\Identity\SurfaceRecord;

/**
 * Record for `invflux_inventory_ledger` — the **append-only** stock-movement ledger. Both the
 * table's DDL source of truth AND the type inserted into it.
 *
 * **Write-once ({@see AppendOnly}).** Rows are appended by {@see
 * \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore} via `RecordSet::insertAll()` (one plain
 * multi-row INSERT, no upsert, throws on a duplicate minted PK) — the movement engine's ordered
 * `SELECT … FOR UPDATE` on inventory *state* rows stays raw SQL, but the ledger append acquires no
 * such locks, so it carries no lock-ordering concern and belongs on the bulk attrecord path. attrecord
 * forbids any update/delete on this type at runtime; reads (finders) are permitted.
 *
 * All foreign keys are declared via #[ForeignKey] rather than #[Relation]: this Record has no
 * relations to hydrate. Record-backed targets use the class form (rename-safe — the table
 * name + PK derive from the target Record); only `invflux_slotspace` (dynamic schema, no
 * Record) uses the table-name form.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are populated via newWith() (reflection) for the
 *                                        insertAll() append; Psalm cannot see those dynamic writes.
 */
#[Table(name: 'invflux_inventory_ledger')]
#[Index('idx_subject_recorded', columns: ['subject_id', 'recorded_at', 'id'])]
#[Index('idx_movement_type', columns: ['movement_type_id', 'recorded_at', 'id'])]
#[Index('idx_ref', columns: ['ref_type_id', 'ref_id', 'recorded_at', 'id'])]
#[Index('idx_ref_int', columns: ['ref_type_id', 'ref_int_id', 'recorded_at', 'id'])]
#[Index('idx_actor', columns: ['actor_id', 'recorded_at', 'id'])]
#[Index('idx_surface', columns: ['surface_id', 'recorded_at', 'id'])]
#[Index('idx_from_slot_recorded', columns: ['from_slot_id', 'recorded_at', 'id'])]
#[Index('idx_to_slot_recorded', columns: ['to_slot_id', 'recorded_at', 'id'])]
#[ForeignKey(column: 'subject_id', references: Subject::class, onDelete: ForeignKeyAction::Restrict)]
#[ForeignKey(column: 'movement_type_id', references: MovementType::class, onDelete: ForeignKeyAction::Restrict)]
#[ForeignKey(column: 'ref_type_id', references: RefTypeRecord::class, onDelete: ForeignKeyAction::Restrict)]
#[ForeignKey(column: 'actor_id', references: ActorRecord::class, onDelete: ForeignKeyAction::SetNull)]
#[ForeignKey(column: 'surface_id', references: SurfaceRecord::class, onDelete: ForeignKeyAction::SetNull)]
#[ForeignKey(column: 'from_slot_id', references: 'invflux_slotspace', onDelete: ForeignKeyAction::SetNull)]
#[ForeignKey(column: 'to_slot_id', references: 'invflux_slotspace', onDelete: ForeignKeyAction::SetNull)]
final class InventoryLedger extends Record implements AppendOnly
{
    /** 16-byte binary UUIDv7 — minted by the inventory store before insertAll(). */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $movement_type_id = 0;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $from_slot_id = null;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $to_slot_id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $quantity = 0;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $initial_from = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $initial_to = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $ref_type_id = null;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $ref_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $ref_int_id = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $actor_id = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $surface_id = null;

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $recorded_at = null;
}
