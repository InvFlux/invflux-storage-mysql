<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Record for `invflux_layers` — single source of the table's DDL, and the R/W surface for registry
 * writes (MysqlInventoryStore::registerLayer uses a burn-free RecordSet::upsertAllByUniqueKey).
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are hydrated/written by attrecord.
 */
#[Table(name: 'invflux_layers')]
final class Layer extends Record
{
    #[Column(ColumnType::SmallIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 50)]
    #[UniqueKey('uniq_layer_slug')]
    public string $slug = '';

    #[Column(ColumnType::VarChar, length: 100)]
    public string $name = '';
}
