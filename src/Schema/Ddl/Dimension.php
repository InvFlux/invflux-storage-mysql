<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;

/**
 * Record for `invflux_dimensions` — both the single source of the table's DDL and the type
 * read/written through it.
 *
 * `default_value` points back at {@see DimensionValue}, which points here via `dimension_id` —
 * a genuine cycle, so no creation order satisfies both constraints inline. The schema installer
 * defers one edge (creates the table without it, adds it once both tables exist); this Record
 * declares the constraint either way, so it is part of the model rather than something applied
 * imperatively afterwards and forever seen as undeclared drift.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist only to declare the table's DDL.
 */
#[Table(name: 'invflux_dimensions')]
#[ForeignKey(column: 'default_value', references: DimensionValue::class, onDelete: ForeignKeyAction::Restrict)]
final class Dimension extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    #[UniqueKey('uniq_dimension_name')]
    public string $name = '';

    #[Column(ColumnType::IntUnsigned)]
    #[UniqueKey('uniq_dimension_position')]
    public int $position_index = 0;

    #[Column(ColumnType::Enum, enumValues: ['partition', 'reveal'])]
    public string $kind = 'partition';

    #[Column(ColumnType::Enum, enumValues: ['aggregate', 'drop_hidden', 'forbid_if_nonempty', 'archive_then_drop', 'map_to_value'])]
    public string $collapse_behavior = 'aggregate';

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $collapse_target_value = null;

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $default_value = null;

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;

    #[Column(ColumnType::Json)]
    public string $metadata_json = '';
}
