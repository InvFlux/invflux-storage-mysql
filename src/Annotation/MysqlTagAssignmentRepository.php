<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Annotation;

use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Domain\Annotation\AnnotationTarget;
use Nandan108\InvFlux\Domain\Tag\Tag;
use Nandan108\InvFlux\Domain\Tag\TagAssignment;
use Nandan108\InvFlux\Domain\Tag\TagAssignmentRepository;

/**
 * MySQL-backed {@see TagAssignmentRepository} over the polymorphic `invflux_tag_assignments`
 * set. Idempotent without INSERT IGNORE: already-attached tags are read first and skipped;
 * only genuinely-new rows are bulk-inserted with one `upsertAll()`. Every mutation returns the
 * *actual* delta so the service records exactly what changed.
 *
 * @api
 */
final class MysqlTagAssignmentRepository implements TagAssignmentRepository
{
    #[\Override]
    public function assign(AnnotationTarget $target, array $tagIds, ?int $actorId): array
    {
        if ([] === $tagIds) {
            return [];
        }
        $have = $this->tagIdsForTarget($target);

        $added = [];
        $new = [];
        foreach ($tagIds as $tagId) {
            if (in_array($tagId, $have, true) || in_array($tagId, $added, true)) {
                continue;
            }
            $new[] = TagAssignment::newWith([
                ...$this->targetCols($target),
                'tag_id'              => $tagId,
                'created_by_actor_id' => $actorId,
            ]);
            $added[] = $tagId;
        }

        if ([] !== $new) {
            (new RecordSet($new))->upsertAll();
        }

        return $added;
    }

    #[\Override]
    public function assignMany(array $targets, array $tagIds, ?int $actorId): array
    {
        if ([] === $targets || [] === $tagIds) {
            return [];
        }

        // One read of the existing pairs across all targets, then one bulk insert of the
        // genuinely-new rows — never a per-target query loop.
        $uuidIds = [];
        $intIds = [];
        $refTypeIds = [];
        foreach ($targets as $target) {
            $refTypeIds[$target->refTypeId] = true;
            if (null !== $target->refId) {
                $uuidIds[] = $target->refId;
            } else {
                $intIds[] = (int) $target->refIntId;
            }
        }

        $have = [];
        foreach ($this->existingPairs($refTypeIds, $uuidIds, $intIds) as $pairKey) {
            $have[$pairKey] = true;
        }

        $new = [];
        $delta = [];
        foreach ($targets as $target) {
            $tk = $target->key();
            foreach ($tagIds as $tagId) {
                $pairKey = $tk.'|'.$tagId;
                if (isset($have[$pairKey])) {
                    continue;
                }
                $have[$pairKey] = true; // dedupe repeated targets/tags within this call
                $new[] = TagAssignment::newWith([
                    ...$this->targetCols($target),
                    'tag_id'              => $tagId,
                    'created_by_actor_id' => $actorId,
                ]);
                $delta[$tk][] = $tagId;
            }
        }

        if ([] !== $new) {
            (new RecordSet($new))->upsertAll();
        }

        return $delta;
    }

    #[\Override]
    public function unassign(AnnotationTarget $target, array $tagIds): array
    {
        if ([] === $tagIds) {
            return [];
        }
        [$where, $params] = $this->targetWhere($target);

        $removed = [];
        foreach ($tagIds as $tagId) {
            $row = TagAssignment::findOne($where.' AND `tag_id` = ?', [...$params, $tagId]);
            if (null !== $row) {
                $row->delete();
                $removed[] = $tagId;
            }
        }

        return $removed;
    }

    #[\Override]
    public function tagIdsForTarget(AnnotationTarget $target): array
    {
        [$where, $params] = $this->targetWhere($target);
        $ids = [];
        foreach (TagAssignment::find($where, $params) as $row) {
            $ids[] = $row->tag_id;
        }

        return $ids;
    }

    #[\Override]
    public function tagsForUuidTargets(int $refTypeId, array $binaryTargetIds): array
    {
        if ([] === $binaryTargetIds) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, \count($binaryTargetIds), '?'));

        $map = [];
        // load() = imperative post-load eager relation.
        $set = TagAssignment::find(
            sprintf('`target_ref_type_id` = ? AND `target_ref_id` IN (%s)', $placeholders),
            [$refTypeId, ...$binaryTargetIds],
        )->load('tag');
        foreach ($set as $assignment) {
            $tag = $assignment->tag;
            if (null === $tag) {
                continue;
            }
            $map[bin2hex((string) $assignment->target_ref_id)][] = $tag;
        }

        foreach ($map as &$tags) {
            usort($tags, static fn (Tag $a, Tag $b): int => strcmp($a->name, $b->name));
        }
        unset($tags);

        return $map;
    }

    #[\Override]
    public function purgeForTarget(AnnotationTarget $target): void
    {
        [$where, $params] = $this->targetWhere($target);
        foreach (TagAssignment::find($where, $params) as $row) {
            $row->delete();
        }
    }

    /**
     * Existing (target, tag) pairs for the given ref-types + id sets, as `targetKey|tagId`
     * strings (targetKey mirrors {@see AnnotationTarget::key()}). One query, no per-target loop.
     *
     * @param array<int, true> $refTypeIds
     * @param list<string>     $uuidIds
     * @param list<int>        $intIds
     *
     * @return list<string>
     */
    private function existingPairs(array $refTypeIds, array $uuidIds, array $intIds): array
    {
        $types = array_keys($refTypeIds);
        $typePh = implode(', ', array_fill(0, \count($types), '?'));

        $idClauses = [];
        /** @var list<int|string> $params */
        $params = [...$types];
        if ([] !== $uuidIds) {
            $idClauses[] = sprintf('`target_ref_id` IN (%s)', implode(', ', array_fill(0, \count($uuidIds), '?')));
            $params = [...$params, ...$uuidIds];
        }
        if ([] !== $intIds) {
            $idClauses[] = sprintf('`target_ref_int_id` IN (%s)', implode(', ', array_fill(0, \count($intIds), '?')));
            $params = [...$params, ...$intIds];
        }
        if ([] === $idClauses) {
            return [];
        }

        $where = sprintf('`target_ref_type_id` IN (%s) AND (%s)', $typePh, implode(' OR ', $idClauses));

        $pairs = [];
        foreach (TagAssignment::find($where, $params) as $row) {
            $key = null !== $row->target_ref_id
                ? $row->target_ref_type_id.':'.bin2hex($row->target_ref_id)
                : $row->target_ref_type_id.':#'.(int) $row->target_ref_int_id;
            $pairs[] = $key.'|'.$row->tag_id;
        }

        return $pairs;
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

    /**
     * @return array{target_ref_type_id: int, target_ref_id: ?string, target_ref_int_id: ?int}
     */
    private function targetCols(AnnotationTarget $target): array
    {
        return [
            'target_ref_type_id' => $target->refTypeId,
            'target_ref_id'      => $target->refId,
            'target_ref_int_id'  => $target->refIntId,
        ];
    }
}
