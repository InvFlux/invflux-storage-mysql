<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Worksheet;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\LockTier;
use Nandan108\Attrecord\Attribute\Relation;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Enum\RelationType;
use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\RecordSet;

/**
 * Persisted worksheet header: owner, visibility, optional workflow binding.
 *
 * Basic tables (`invflux_subject_worksheets` + `invflux_subject_worksheet_items`)
 * ship in the Essentials tier and back the bulk-import/paste working set in the Workbench.
 * Pro adds `invflux_subject_worksheet_access` (sharing) and workflow ownership.
 *
 * Lock tier 10: always locked before SubjectWorksheetItemRecord (tier 11).
 */
#[Table(name: 'invflux_subject_worksheets')]
#[LockTier(10)]
#[Index('idx_owner', columns: ['owner_actor_id'])]
#[Index('idx_status_visibility', columns: ['status', 'visibility'])]
#[Index('idx_workflow', columns: ['workflow_type', 'workflow_doc_id'])]
final class SubjectWorksheetRecord extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::Binary, length: 16, nullable: true)]
    public ?string $uuid = null;

    #[Column(ColumnType::VarChar, length: 255, nullable: true)]
    public ?string $name = null;

    #[Column(ColumnType::Text, nullable: true)]
    public ?string $description = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $owner_actor_id = 0;

    /** 'private' | 'shared' | 'site' */
    #[Column(ColumnType::VarChar, length: 16, default: 'private')]
    public string $visibility = 'private';

    /** 'active' | 'archived' */
    #[Column(ColumnType::VarChar, length: 16, default: 'active')]
    public string $status = 'active';

    #[Column(ColumnType::VarChar, length: 64, nullable: true)]
    public ?string $workflow_type = null;

    #[Column(ColumnType::BigIntUnsigned, nullable: true)]
    public ?int $workflow_doc_id = null;

    /** @var array<string, mixed>|null Reserved substrate; JsonCaster auto-attaches on the array-typed Json column. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $payload_json = null;

    /** @var array<string, mixed>|null Reserved substrate; JsonCaster auto-attaches on the array-typed Json column. */
    #[Column(ColumnType::Json, nullable: true)]
    public ?array $metadata_json = null;

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $created_by_actor_id = 0;

    #[Column(ColumnType::DateTime, nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[Column(ColumnType::IntUnsigned)]
    public int $updated_by_actor_id = 0;

    /** @var RecordSet<SubjectWorksheetItemRecord>|null */
    #[Relation(RelationType::OneToMany, class: SubjectWorksheetItemRecord::class, foreignKey: 'worksheet_id')]
    public ?RecordSet $items = null;

    #[\Override]
    public function beforeSave(): void
    {
        $this->updated_at = new \DateTimeImmutable();
    }
}
