<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Record for `invflux_movement_types` — single source of the table's DDL, and the R/W surface for
 * registry writes (MysqlInventoryStore::registerMovementTypes uses a burn-free
 * RecordSet::upsertAllByUniqueKey). Hot-path reads (resolveMovementTypeId) stay raw SQL.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are hydrated/written by attrecord.
 */
#[Table(name: 'invflux_movement_types')]
#[UniqueKey('uniq_owner_code', columns: ['owner_key', 'code'])]
final class MovementType extends Record
{
    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 15)]
    public string $owner_key = '';

    #[Column(ColumnType::VarChar, length: 32)]
    public string $code = '';

    #[Column(ColumnType::VarChar, length: 64)]
    public string $name = '';

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $description = null;

    #[Column(ColumnType::Bool, default: true)]
    public bool $active = true;
}
