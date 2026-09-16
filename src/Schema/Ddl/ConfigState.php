<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Record for `invflux_config_state` — the single-row inventory configuration. Both the single
 * source of the table's DDL and the type read/written through it: a static-schema, single-table
 * singleton needs none of the raw lock discipline the inventory-state tables do.
 *
 * The PK is a provided TINYINT (always 1), not auto-increment.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are written through attrecord by column name
 *                                        (newWith() / updateWhere()), which Psalm cannot trace.
 */
#[Table(name: 'invflux_config_state')]
final class ConfigState extends Record
{
    #[Column(ColumnType::TinyIntUnsigned)]
    public int $id = 0;

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $quantity_scale = 0;

    #[Column(ColumnType::Json)]
    public string $active_dimensions_json = '';

    #[Column(ColumnType::Json, nullable: true)]
    public ?string $layers_json = null;

    /**
     * The assembled flow topology — which flows exist per layer, which contributor bound each
     * event, and to what. **Diagnostics only, and never read back as a source of truth**: the
     * topology is assembled from code on every boot, so this row is a photograph of it, not the
     * thing itself. Reading it back would make a stale row authoritative over the code that
     * produced it.
     *
     * It answers the question that is otherwise archaeology across three plugins: which add-on
     * owns `order.dispatched` on this install, and what does its movement type drag along.
     */
    #[Column(ColumnType::Json, nullable: true)]
    public ?string $flow_topology_json = null;

    #[Column(
        ColumnType::DateTime,
        precision: 6,
        defaultExpr: 'CURRENT_TIMESTAMP(6)',
        onUpdate: 'CURRENT_TIMESTAMP(6)',
    )]
    public ?\DateTimeImmutable $updated_at = null;
}
