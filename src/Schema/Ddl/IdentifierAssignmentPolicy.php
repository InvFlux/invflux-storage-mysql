<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\ForeignKey;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\GeneratedColumnMode;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Identity\ActorTypeRecord;
use Nandan108\InvFlux\Identity\IdentifierTypeRecord;
use Nandan108\InvFlux\Identity\SystemRecord;

/**
 * Record for `invflux_identifier_assignment_policies` — single source of the table's DDL, and the
 * R/W surface for the policy upsert (MysqlInventoryStore::configureIdentifierAssignment uses a
 * burn-free Record::upsertByUniqueKey on the composite UNIQUE). Policy load stays raw SQL.
 *
 * The original heredoc table had no PRIMARY KEY (only the composite UNIQUE on
 * `(system_id, type_id, scope_actor_type_key)`, with an InnoDB hidden clustered key). attrecord
 * needs a single-column PK, so a surrogate `id` AUTO_INCREMENT PK is added here — transparent to
 * the upsert/load, which key off the composite UNIQUE (the natural key); the hidden clustered key
 * simply becomes explicit.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns are hydrated/written by attrecord.
 */
#[Table(name: 'invflux_identifier_assignment_policies')]
#[UniqueKey('uniq_identifier_policy', columns: ['system_id', 'type_id', 'scope_actor_type_key'])]
#[ForeignKey(column: 'system_id', references: SystemRecord::class)]
#[ForeignKey(column: 'type_id', references: IdentifierTypeRecord::class)]
#[ForeignKey(column: 'scope_actor_type_id', references: ActorTypeRecord::class)]
final class IdentifierAssignmentPolicy extends Record
{
    #[Column(ColumnType::IntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $system_id = 0;

    #[Column(ColumnType::SmallIntUnsigned)]
    public int $type_id = 0;

    #[Column(ColumnType::TinyIntUnsigned, nullable: true)]
    public ?int $scope_actor_type_id = null;

    #[Column(
        ColumnType::TinyIntUnsigned,
        generatedAs: 'IFNULL(scope_actor_type_id, 0)',
        generatedMode: GeneratedColumnMode::Stored,
    )]
    public int $scope_actor_type_key = 0;

    #[Column(ColumnType::Bool, default: false)]
    public bool $unique_active_value = false;

    #[Column(ColumnType::Bool, default: true)]
    public bool $reusable_after_expiry = true;

    #[Column(ColumnType::Bool, default: false)]
    public bool $lifecycle_anchor = false;

    #[Column(ColumnType::Bool, default: true)]
    public bool $mutable_alias = true;
}
