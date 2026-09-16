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
 * Record for `invflux_dimension_values` — both the single source of the table's DDL and the type
 * read/written through it. One write stays raw SQL:
 * {@see \Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore::upsertDimensionValueRows()} documents why.
 *
 * **Hierarchy is carried by `code` and `parent_id`, and nothing else.** A value's `code` is its
 * full slash path — a child's is its parent's plus one segment — so ancestry is a prefix test on
 * `code` and needs no materialised path column beside it. `parent_id` is what makes the edge a
 * real foreign relationship rather than a naming convention; `code` is what makes reading the
 * tree a string operation. Merchant-readable text lives in `name`, which is why segments can stay
 * short enough for the whole path to fit `code`.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist only to declare the table's DDL.
 */
#[Table(name: 'invflux_dimension_values')]
#[UniqueKey('uniq_dimension_code', columns: ['dimension_id', 'code'])]
#[Index('idx_dimension_level', columns: ['dimension_id', 'level', 'active'])]
#[ForeignKey(column: 'dimension_id', references: Dimension::class, onDelete: ForeignKeyAction::Cascade)]
final class DimensionValue extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $dimension_id = 0;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 191, nullable: true)]
    public ?string $name = null;

    #[Column(ColumnType::VarChar, length: 32, default: 'core')]
    public string $owner_key = 'core';

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $removal_target_code = null;

    #[Column(ColumnType::Json)]
    public string $metadata_json = '';

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $parent_id = null;

    #[Column(ColumnType::Bool, default: true)]
    public bool $addressable = true;

    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $level = null;
}
