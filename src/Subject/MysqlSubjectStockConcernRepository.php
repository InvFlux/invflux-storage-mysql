<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Subject;

use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Domain\Subject\StockConcern;
use Nandan108\InvFlux\Domain\Subject\SubjectStockConcern;
use Nandan108\InvFlux\Domain\Subject\SubjectStockConcernRepository;

/**
 * MySQL-backed implementation of {@see SubjectStockConcernRepository}.
 *
 * Writes go through the attrecord Record (so the `beforeSave` hook
 * stamps `detected_at` / `updated_at` consistently). Deletes are a
 * single DELETE keyed on the natural PK.
 *
 * The write in {@see upsertBits()} is a real upsert rather than a
 * read-then-insert, because concurrent writers are ordinary here: the
 * drainer runs on `shutdown`, so any two overlapping requests that both
 * touch a subject race for the same row, and whichever loses hits a
 * duplicate-key error on the natural PK.
 *
 * @api
 */
final class MysqlSubjectStockConcernRepository implements SubjectStockConcernRepository
{
    #[\Override]
    public function findBySubjectId(int $subjectId): ?SubjectStockConcern
    {
        return SubjectStockConcern::findOne('`subject_id` = ?', [$subjectId]);
    }

    #[\Override]
    public function upsertBits(
        int $subjectId,
        int $bits,
        int $deficitQty = 0,
        int $demandQty = 0,
        int $ctdQty = 0,
    ): void {
        if (0 === $bits) {
            throw new \InvalidArgumentException(
                'SubjectStockConcernRepository::upsertBits requires non-zero bits; '
                .'use clearFor() to remove the row.',
            );
        }

        // The repository API stays integer-mask-based (the drainer computes with `|=` and the SQL read
        // paths are bitwise); the Record carries a typed set via #[BitmaskCaster], so bridge int → set
        // here and let the caster fold it back to the SMALLINT on save.
        $concerns = StockConcern::fromMask($bits);

        $existing = $this->findBySubjectId($subjectId);

        if (null !== $existing
            && StockConcern::mask($existing->bits) === $bits
            && $existing->deficit_qty === $deficitQty
            // The operands too, not only their difference: a correction can move a deficit of 1
            // from `5 − 4` to `4 − 3`, and skipping that write would leave a stale equation on
            // screen beside a correct total.
            && $existing->demand_qty === $demandQty
            && $existing->ctd_qty === $ctdQty
        ) {
            // Spare the write — `updated_at` would otherwise tick on a
            // no-op recompute. The drainer fires whenever an
            // engine event lands; most ticks are repeats. A changed
            // deficit magnitude (same bit, different N) still writes.
            return;
        }

        // Built complete rather than filled in afterwards: `newWith()` validates what it is handed,
        // and `bits` may not be empty — a row with no concern violates the "no row = no concern"
        // invariant, so a two-step construction throws before the second step arrives.
        if (null === $existing) {
            $record = SubjectStockConcern::newWith([
                'subject_id'  => $subjectId,
                'bits'        => $concerns,
                'deficit_qty' => $deficitQty,
                'demand_qty'  => $demandQty,
                'ctd_qty'     => $ctdQty,
            ]);
        } else {
            $record = $existing;
            $record->bits = $concerns;
            $record->deficit_qty = $deficitQty;
            $record->demand_qty = $demandQty;
            $record->ctd_qty = $ctdQty;
        }

        // Upsert, not insert — and the read above cannot be promoted into a guard, because the
        // gap between it and the write is precisely where the other request inserts. This method
        // is reached from `shutdown`, so two overlapping requests that touched the same subject
        // arrive here together, both see no row, and the loser gets
        // `Duplicate entry '<subject_id>' for key 'PRIMARY'`. Observed in the wild.
        //
        // `detected_at` is excluded from the UPDATE and only from the UPDATE: it means *first*
        // seen, so it has to be written when the row is created and left alone forever after —
        // letting `beforeSave()`'s stamp reach the SET clause would reset the age of a concern
        // every time its magnitude changed. `updated_at` is not excluded and still ticks.
        (new RecordSet([$record]))->upsertAll(ignoreColumns: ['update' => ['detected_at']]);
    }

    #[\Override]
    public function clearFor(int $subjectId): void
    {
        $existing = $this->findBySubjectId($subjectId);
        if (null === $existing) {
            return;
        }
        SubjectStockConcern::deleteWhere('`subject_id` = ?', [$subjectId]);
    }
}
