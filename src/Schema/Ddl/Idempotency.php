<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * Record for `invflux_idempotency` — both the single source of the table's DDL and the type
 * read/written through it. Claim insert + expiry sweep stay raw SQL in
 * MysqlInventoryStore; this class is the single source of the table's DDL.
 *
 * @psalm-suppress PossiblyUnusedProperty Columns exist only to declare the table's DDL.
 */
#[Table(name: 'invflux_idempotency')]
#[UniqueKey('uniq_scope_operation_key', columns: ['scope', 'operation_key'])]
#[Index('idx_completed_at', columns: ['completed_at'])]
final class Idempotency extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 32)]
    public string $scope = '';

    #[Column(ColumnType::VarChar, length: 191)]
    public string $operation_key = '';

    #[Column(ColumnType::VarChar, length: 32, nullable: true)]
    public ?string $outcome_code = null;

    #[Column(ColumnType::Json)]
    public string $payload_json = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $completed_at = null;
}
