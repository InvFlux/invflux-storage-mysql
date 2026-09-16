<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Worksheet;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Attribute\UniqueKey;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\ForeignKeyAction;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;

/**
 * One subject entry inside a worksheet.
 *
 * Natural uniqueness: (worksheet_id, subject_kind, subject_id) — enforced by
 * the UNIQUE KEY `unique_item` in the schema. Surrogate `id` PK enables
 * RecordSet::upsertAll() deadlock-safe bulk upsert and ascending-lock ordering.
 *
 * Lock tier 11: always locked after SubjectWorksheetRecord (tier 10).
 */
#[Table(name: 'invflux_subject_worksheet_items')]
#[LockTier(11)]
#[UniqueKey('unique_item', columns: ['worksheet_id', 'subject_kind', 'subject_id'])]
#[Index('idx_subject', columns: ['subject_kind', 'subject_id'])]
final class SubjectWorksheetItemRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::BigIntUnsigned)]
    public int $worksheet_id = 0;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $subject_kind = '';

    #[Column(ColumnType::IntUnsigned)]
    public int $subject_id = 0;

    #[Column(ColumnType::Decimal, nullable: true, precision: 15, scale: 4)]
    public ?float $quantity = null;

    /** @var array<string, mixed>|null Reserved substrate; JsonCaster auto-attaches on the array-typed Json column. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $payload_json = null;

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $created_by_actor_id = 0;

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $updated_by_actor_id = 0;

    /** Owning side of the worksheet→items relation; emits the ON DELETE CASCADE FK on worksheet_id. */
    #[Relation(
        RelationType::ManyToOne,
        class: SubjectWorksheetRecord::class,
        foreignKey: 'worksheet_id',
        onDelete: ForeignKeyAction::Cascade,
    )]
    public ?SubjectWorksheetRecord $worksheet = null;

    #[\Override]
    public function beforeSave(): void
    {
        $this->updated_at = new \DateTimeImmutable();
    }
}
