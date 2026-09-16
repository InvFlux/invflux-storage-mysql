<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;

/**
 * `invflux_diagnostic_schedule` — one row per registered check: whether it runs, how often, and
 * when it is next due.
 *
 * Hand-written `CREATE TABLE IF NOT EXISTS` until now, for historical reasons rather than
 * technical ones — a single-column key and no hot path — which left it invisible to the differ.
 *
 * @psalm-suppress PossiblyUnusedProperty Written through attrecord; read back by hydration.
 */
#[Table(name: 'invflux_diagnostic_schedule', primaryKey: 'check_key')]
#[Index('idx_next_run', columns: ['enabled', 'next_run_at'])]
final class DiagnosticSchedule extends Record
{
    #[Column(ColumnType::VarChar, length: 64)]
    public string $check_key = '';

    #[Column(ColumnType::Bool, default: 1)]
    public bool $enabled = true;

    #[Column(ColumnType::IntUnsigned)]
    public int $frequency_seconds = 0;

    #[Column(ColumnType::DateTime, precision: 6, nullable: true)]
    public ?\DateTimeImmutable $last_run_at = null;

    #[Column(ColumnType::DateTime, precision: 6, defaultExpr: 'CURRENT_TIMESTAMP(6)')]
    public ?\DateTimeImmutable $next_run_at = null;

    #[Column(
        ColumnType::DateTime,
        precision: 6,
        defaultExpr: 'CURRENT_TIMESTAMP(6)',
        onUpdate: 'CURRENT_TIMESTAMP(6)',
    )]
    public ?\DateTimeImmutable $updated_at = null;
}
