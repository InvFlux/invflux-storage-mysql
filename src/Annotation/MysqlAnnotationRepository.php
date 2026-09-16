<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Annotation;

use Nandan108\InvFlux\Domain\Annotation\Annotation;
use Nandan108\InvFlux\Domain\Annotation\AnnotationRepository;
use Nandan108\InvFlux\Domain\Annotation\AnnotationTarget;

/**
 * MySQL-backed {@see AnnotationRepository}. Append-only (no update/delete) — an edit or
 * delete is a new version row (see the core service). Uses attrecord statics; the polymorphic
 * target is matched on `target_ref_type_id` + whichever of `target_ref_id` / `target_ref_int_id`
 * the target carries.
 *
 * @api
 */
final class MysqlAnnotationRepository implements AnnotationRepository
{
    #[\Override]
    public function append(Annotation $annotation): Annotation
    {
        $annotation->save();

        return $annotation;
    }

    #[\Override]
    public function forTarget(AnnotationTarget $target, int $limit = 500): array
    {
        [$where, $params] = $this->targetWhere($target);
        $list = [];
        foreach (Annotation::find(
            $where,
            $params,
            sprintf('ORDER BY `occurred_at` ASC, `id` ASC LIMIT %d', max(1, $limit)),
        ) as $row) {
            $list[] = $row;
        }

        return $list;
    }

    #[\Override]
    public function forThread(string $threadId): array
    {
        $list = [];
        foreach (Annotation::find('`thread_id` = ?', [$threadId], 'ORDER BY `version` ASC') as $row) {
            $list[] = $row;
        }

        return $list;
    }

    #[\Override]
    public function liveNotesForUuidTargets(int $refTypeId, array $binaryTargetIds, int $recentPerTarget = 3): array
    {
        if ([] === $binaryTargetIds) {
            return [];
        }

        // Fetch this ref-type's annotations for the targets, then fold in PHP: per (target,
        // thread) the highest-version row's action decides live-ness, while a body seen at *any*
        // version decides whether the thread is a note at all — and the newest version carrying
        // one is what a preview shows. Per-target volume is tiny (a handful of notes), so no
        // GROUP-BY round trip is warranted.
        $placeholders = implode(', ', array_fill(0, \count($binaryTargetIds), '?'));
        /** @var array<string, array<string, array{deleted: bool, body: ?Annotation}>> $byTargetThread */
        $byTargetThread = [];
        foreach (Annotation::find(
            sprintf('`target_ref_type_id` = ? AND `target_ref_id` IN (%s)', $placeholders),
            [$refTypeId, ...$binaryTargetIds],
            'ORDER BY `version` ASC',
        ) as $row) {
            $targetHex = bin2hex((string) $row->target_ref_id);
            $threadHex = bin2hex((string) $row->thread_id);
            $seen = $byTargetThread[$targetHex][$threadHex] ?? ['deleted' => false, 'body' => null];
            $byTargetThread[$targetHex][$threadHex] = [
                // Ascending version, so the last row of a thread to arrive is its newest — which
                // makes the last assignment the current state, and the last row *with* a body the
                // current text.
                'deleted' => Annotation::ACTION_DELETED === $row->action,
                'body'    => null !== $row->body ? $row : $seen['body'],
            ];
        }

        $out = [];
        foreach ($byTargetThread as $targetHex => $threads) {
            $notes = [];
            foreach ($threads as $thread) {
                if (!$thread['deleted'] && null !== $thread['body']) {
                    $notes[] = $thread['body'];
                }
            }
            if ([] === $notes) {
                continue;
            }
            // Compared as DateTimeImmutable rather than by `getTimestamp()`, which truncates to
            // whole seconds: `occurred_at` is DATETIME(6), and several notes written in one burst
            // (a picker clearing a backlog) share a second while differing by microseconds. Second
            // resolution would make their order arbitrary, so a preview showing the newest three
            // could show the oldest three.
            usort($notes, static function (Annotation $a, Annotation $b): int {
                if (null === $a->occurred_at || null === $b->occurred_at) {
                    return (null === $b->occurred_at ? 0 : 1) <=> (null === $a->occurred_at ? 0 : 1);
                }

                return $b->occurred_at <=> $a->occurred_at;
            });
            $out[$targetHex] = [
                // The whole count, deliberately not the length of `recent` below.
                'count'  => \count($notes),
                'recent' => $recentPerTarget > 0 ? \array_slice($notes, 0, $recentPerTarget) : [],
            ];
        }

        return $out;
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function targetWhere(AnnotationTarget $target): array
    {
        if (null !== $target->refId) {
            return ['`target_ref_type_id` = ? AND `target_ref_id` = ?', [$target->refTypeId, $target->refId]];
        }

        return ['`target_ref_type_id` = ? AND `target_ref_int_id` = ?', [$target->refTypeId, (int) $target->refIntId]];
    }
}
