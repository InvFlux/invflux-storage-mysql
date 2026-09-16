<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * DDL-only Record for `invflux_slotspace` — the registry of materialised slots (one row per
 * `loc × stt` combination in a layer). The single source of the table's DDL, and the type used
 * for the deactivation sweeps. The row **upsert** stays raw SQL in MysqlInventoryStore: it writes
 * the `dim_<name>` columns, which are not declared here (see below), so no Record can express it.
 *
 * It carries a Record because it sits in the *middle* of the foreign-key graph rather than at
 * the edge: it references `invflux_layers`, and `invflux_inventory_ledger` references it back. A
 * table in that position cannot be created outside the managed set — whichever side went first
 * would point at something that did not exist yet.
 *
 * **This declares only the fixed half of the table.** The slot space also gains a `dim_<name>`
 * column plus a matching index for every registered dimension — a set that lives in the
 * slot-space definition, not in any class. Never build this table's schema from the class alone:
 * go through {@see \Nandan108\InvFlux\Storage\Mysql\Schema\SlotSpaceSchema}, which derives the
 * complete one for a given dimension set.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist only to declare the table's DDL.
 */
#[Table(name: 'invflux_slotspace')]
#[Index('idx_active_slot', columns: ['active', 'id'])]
#[Index('idx_layer_slot', columns: ['layer_id', 'active', 'id'])]
#[ForeignKey(column: 'layer_id', references: Layer::class, onDelete: ForeignKeyAction::SetNull)]
final class SlotSpace extends Record
{
    /** 16-byte binary UUID — minted by the inventory store when a slot is materialised. */
    #[Column(ColumnType::Binary, length: 16)]
    public ?string $id = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $layer_id = null;

    #[Column(ColumnType::VarChar, length: 191)]
    #[UniqueKey('uniq_slot_key')]
    public string $slot_key = '';

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;

    #[Column(ColumnType::Json)]
    public string $metadata_json = '';
}
