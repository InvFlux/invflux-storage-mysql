<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Diagnostics;

use Nandan108\InvFlux\Diagnostics\DiagnosticCheck;
use Nandan108\InvFlux\Diagnostics\DiagnosticResult;
use Nandan108\InvFlux\Diagnostics\DiagnosticStatus;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;

/**
 * Detect cross-layer authority drift: for each subject the summed quantity
 * across all slots must be equal across every layer (commercial total ==
 * physical total). A mismatch means a movement updated one layer but not
 * the other — an engine lock/cascade bug, or external interference with
 * `inventory_state`.
 *
 * **Authority drift, not cache drift.**
 *
 * Both layer totals are authoritative reads of `inventory_state`; if they
 * disagree the system can't know which side is right, so this check is
 * notify-only — `repair()` returns null. It does NOT auto-correct.
 *
 * Recomputes totals directly from `inventory_state` on every run. There is
 * no `invflux_layer_totals` cache to compare against — that write-through
 * cache was removed (it cost a round-trip on every movement and was read
 * only here; this check already recomputed the authoritative totals
 * regardless, so the cache added nothing but hot-path overhead). The
 * recompute is a `GROUP BY subject_id, layer_id` aggregate over indexed
 * columns — cheap, and this check runs hourly by default.
 *
 * @internal
 */
final class LayerTotalsCheck implements DiagnosticCheck
{
    /** Create the check over one MySQL session and optional WordPress table prefix. */
    public function __construct(
        private readonly MysqlSession $session,
        private readonly string $tablePrefix = '',
    ) {
    }

    /** Return this check's stable schedule/result key. */
    #[\Override]
    public function key(): string
    {
        return 'layer_totals';
    }

    /** Return the default hourly check frequency. */
    #[\Override]
    public function defaultFrequencySeconds(): int
    {
        return 3600;
    }

    /** Recompute per-subject layer totals from authoritative state and flag cross-layer mismatches. */
    #[\Override]
    public function run(): DiagnosticResult
    {
        $started = microtime(true);
        $actual = $this->actualTotals();

        /** @var array<int, array<string, int>> $bySubject */
        $bySubject = [];
        foreach ($actual as $key => $quantity) {
            [$subjectId, $layerSlug] = self::parseLookupKey($key);
            $bySubject[$subjectId][$layerSlug] = $quantity;
        }

        $findings = [];
        foreach ($bySubject as $subjectId => $totals) {
            if (count($totals) <= 1 || count(array_unique(array_values($totals))) <= 1) {
                continue;
            }

            $findings[] = [
                'type'       => 'cross_layer_drift',
                'subject_id' => $subjectId,
                'totals'     => $totals,
                'min'        => min($totals),
                'max'        => max($totals),
                'delta'      => max($totals) - min($totals),
            ];
        }

        return new DiagnosticResult(
            [] === $findings ? DiagnosticStatus::Ok : DiagnosticStatus::Unresolvable,
            $findings,
            $this->durationMs($started),
        );
    }

    /**
     * Cross-layer drift is authority drift — the system cannot know which
     * layer is correct, so this check never auto-repairs. Notify only.
     */
    #[\Override]
    public function repair(DiagnosticResult $result): ?DiagnosticResult
    {
        unset($result);

        return null;
    }

    /**
     * @return array<string, int> key "subject_id:layer_slug" => quantity
     */
    private function actualTotals(): array
    {
        $rows = $this->session->fetchAll($this->actualTotalsSql());

        $totals = [];
        foreach ($rows as $row) {
            $totals[((int) $row['subject_id']).':'.((string) $row['layer_slug'])] = (int) $row['quantity'];
        }

        return $totals;
    }

    private function actualTotalsSql(): string
    {
        $locJoin = $this->hasColumn('invflux_slotspace', 'dim_loc')
            ? sprintf(
                'LEFT JOIN %s d_loc ON d_loc.name = \'loc\'
                 LEFT JOIN %s dv_loc ON dv_loc.dimension_id = d_loc.id AND dv_loc.code = ss.dim_loc',
                $this->table('invflux_dimensions'),
                $this->table('invflux_dimension_values'),
            )
            : '';
        $locWhere = $this->hasColumn('invflux_slotspace', 'dim_loc')
            ? 'AND (dv_loc.id IS NULL OR dv_loc.addressable = 1)'
            : '';

        return sprintf(
            'SELECT s.subject_id, l.id AS layer_id, l.slug AS layer_slug, SUM(s.quantity) AS quantity
             FROM %s s
             JOIN %s ss ON ss.id = s.slot_id
             JOIN %s l ON l.id = ss.layer_id
             %s
             WHERE ss.layer_id IS NOT NULL
               %s
             GROUP BY s.subject_id, l.id, l.slug',
            $this->table('invflux_inventory_state'),
            $this->table('invflux_slotspace'),
            $this->table('invflux_layers'),
            $locJoin,
            $locWhere,
        );
    }

    /**
     * Split a "subject_id:layer_slug" lookup key into its typed parts.
     *
     * Keys are always built locally from the SQL projection (subject_id is a positive
     * integer, layer_slug is a non-empty alphanumeric slug). A malformed key would
     * indicate a programming bug in this class, not a runtime data issue.
     *
     * @return array{int, string}
     */
    private static function parseLookupKey(string $key): array
    {
        $parts = explode(':', $key, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new \LogicException(sprintf(
                'Layer-totals lookup key "%s" malformed: expected "subject_id:layer_slug".',
                $key,
            ));
        }

        return [(int) $parts[0], $parts[1]];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return null !== $this->session->fetchOne(sprintf(
            'SHOW COLUMNS FROM %s LIKE :column_name',
            $this->table($table),
        ), ['column_name' => $column]);
    }

    private function durationMs(float $started): int
    {
        // microtime(true) - $started is always non-negative within one method call,
        // so no defensive max(0, …) is needed. Psalm's strict binary operands mode
        // refuses int/float mixing — `1000.0` is the same value, expressed as float.
        return (int) round((microtime(true) - $started) * 1000.0);
    }

    private function table(string $table): string
    {
        return '`'.str_replace('`', '``', $this->tablePrefix.$table).'`';
    }
}
