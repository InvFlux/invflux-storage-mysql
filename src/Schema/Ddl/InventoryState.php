<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\PrimaryKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Domain\Subject\Subject;

/**
 * DDL-only Record for `invflux_inventory_state` — the hot-path state row, one per
 * `subject × slot`. **Describes the table; never reads or writes it.**.
 *
 * The composite key is not incidental, and is the reason this table was the last one outside the
 * managed schema. `(subject_id, slot_id)` is InnoDB's clustering key, so the dominant read —
 * `WHERE subject_id IN (…) ORDER BY subject_id, slot_id` — is a sequential range scan already in
 * sort order: no filesort, no secondary lookup. A surrogate single-column key would scatter each
 * subject's slots across the B-tree and add a pointer to both indexes, to store an id nothing
 * queries by. So the table keeps its shape and the tooling learned composite keys instead
 * (attrecord's `#[PrimaryKey(columns:)]`), which is what lets this Record exist at all.
 *
 * Because it is composite, every attrecord CRUD path refuses this class outright — `save()`,
 * `delete()`, the bulk writers, `LockSet::acquire()`. That is the intended contract, not a
 * limitation to work around: all reads and writes stay raw SQL in
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore}, including the ordered
 * temp-table + `SELECT … FOR UPDATE` locking, whose row set is derived rather than enumerated
 * and so does not fit `LockSet`'s shape regardless of the key.
 *
 * What membership buys: the DDL has one source, and convergence can *see* the table. A table
 * stood up by a hand-written `CREATE TABLE IF NOT EXISTS` is one the differ cannot compare against
 * anything — it sits outside the managed set and drifts unobserved.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist only to declare the table's DDL.
 */
#[Table(name: 'invflux_inventory_state')]
#[PrimaryKey(columns: ['subject_id', 'slot_id'])]
#[Index('idx_slot_subject', columns: ['slot_id', 'subject_id'])]
#[ForeignKey(column: 'subject_id', references: Subject::class, onDelete: ForeignKeyAction::Cascade)]
#[ForeignKey(column: 'slot_id', references: 'invflux_slotspace', onDelete: ForeignKeyAction::Cascade)]
final class InventoryState extends Record
{
    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    /** 16-byte binary UUIDv7 slot id. */
    #[Column(ColumnType::Binary, length: 16)]
    public string $slot_id = '';

    #[Column(ColumnType::IntUnsigned, default: 0)]
    public int $quantity = 0;

    #[Column(
        ColumnType::Timestamp,
        defaultExpr: 'CURRENT_TIMESTAMP',
        onUpdate: 'CURRENT_TIMESTAMP',
    )]
    public ?\DateTimeImmutable $updated_at = null;
}
