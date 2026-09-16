<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\ActorRecord;
use Nandan108\InvFlux\Identity\RefTypeRecord;

/**
 * Record for `invflux_schema_ledger` — the append-only config/schema audit ledger. Both the
 * single source of the table's DDL and the type appended to it: single-table with a static
 * schema, so none of the raw lock discipline the inventory-state tables need applies here.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are written through attrecord by column name
 *                                        (newWith()), which Psalm cannot trace.
 */
#[Table(name: 'invflux_schema_ledger')]
#[Index('idx_event_type_recorded', columns: ['event_type', 'recorded_at', 'id'])]
#[Index('idx_actor', columns: ['actor_id', 'recorded_at', 'id'])]
#[Index('idx_ref', columns: ['ref_type_id', 'ref_id', 'recorded_at', 'id'])]
#[ForeignKey(column: 'actor_id', references: ActorRecord::class, onDelete: ForeignKeyAction::SetNull)]
#[ForeignKey(column: 'ref_type_id', references: RefTypeRecord::class, onDelete: ForeignKeyAction::Restrict)]
final class SchemaLedger extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $event_type = '';

    #[Column(ColumnType::IntUnsigned, nullable: true)]
    public ?int $actor_id = null;

    #[Column(ColumnType::SmallIntUnsigned, nullable: true)]
    public ?int $ref_type_id = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $ref_id = null;

    #[Column(ColumnType::Json)]
    public string $payload_json = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $recorded_at = null;
}
