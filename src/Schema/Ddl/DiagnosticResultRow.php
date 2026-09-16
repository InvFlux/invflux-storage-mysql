<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Diagnostics\DiagnosticStatus;

/**
 * `invflux_diagnostic_results` — the append-ish run log, one row per check execution.
 *
 * Named `…Row` rather than `DiagnosticResult` to avoid colliding with the core value object of
 * that name, which this table stores rather than replaces.
 *
 * Not marked `AppendOnly`: rows are updated in place when a run is notified, and the retention
 * sweep deletes old ones.
 *
 * @psalm-suppress PossiblyUnusedProperty Written through attrecord; read back by hydration.
 */
#[Table(name: 'invflux_diagnostic_results')]
#[Index('idx_check_ran', columns: ['check_key', 'ran_at'])]
#[Index('idx_status_ran', columns: ['status', 'ran_at'])]
final class DiagnosticResultRow extends Record
{
    #[Column(ColumnType::BigIntUnsigned, autoIncrement: true)]
    public ?int $id = null;

    #[Column(ColumnType::VarChar, length: 64)]
    public string $check_key = '';

    #[Column(ColumnType::DateTime, precision: 6)]
    public ?\DateTimeImmutable $ran_at = null;

    /** ENUM member list derived from the caster's enum, so adding a status is a one-place change. */
    #[Column(ColumnType::Enum)]
    #[EnumCaster(DiagnosticStatus::class)]
    public DiagnosticStatus $status = DiagnosticStatus::Ok;

    #[Column(ColumnType::Json)]
    public string $findings_json = '';

    #[Column(ColumnType::SmallIntUnsigned, default: 0)]
    public int $duration_ms = 0;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $notified_at = null;
}
