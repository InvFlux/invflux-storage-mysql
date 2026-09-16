<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tag;

use Nandan108\InvFlux\Domain\Tag\Tag;
use Nandan108\InvFlux\Domain\Tag\TagDefinitionRepository;

/**
 * MySQL-backed {@see TagDefinitionRepository} over the shared `invflux_tags` vocabulary.
 *
 * `all()` filters to a scope and hides retired tags (`archived_at IS NULL`), `archived()` returns
 * exactly those; `find()` / `byIds()` resolve retired tags too, so historical annotation
 * `tag_actions` deltas always render. `archive()` / `restore()` set and clear `archived_at` — the
 * vocabulary is never hard-deleted (the append-only annotation stream references it by id forever),
 * and retiring never touches the assignments, so restoring brings back a tag's history intact.
 *
 * @api
 */
final class MysqlTagDefinitionRepository implements TagDefinitionRepository
{
    #[\Override]
    public function all(string $scope): array
    {
        $list = [];
        foreach (Tag::find('`scope` = ? AND `archived_at` IS NULL', [$scope], 'ORDER BY `name` ASC') as $tag) {
            $list[] = $tag;
        }

        return $list;
    }

    #[\Override]
    public function find(int $id): ?Tag
    {
        return Tag::getOne($id);
    }

    #[\Override]
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $map = [];
        foreach (Tag::whereIn('id', $ids) as $tag) {
            $map[(int) $tag->id] = $tag;
        }

        return $map;
    }

    #[\Override]
    public function findBySlug(string $scope, string $slug): ?Tag
    {
        return Tag::findOne('`scope` = ? AND `slug` = ?', [$scope, $slug]);
    }

    #[\Override]
    public function save(Tag $tag): Tag
    {
        $tag->save();

        return $tag;
    }

    #[\Override]
    public function archived(string $scope): array
    {
        $out = [];
        foreach (Tag::find('`scope` = ? AND `archived_at` IS NOT NULL', [$scope], 'ORDER BY `name` ASC') as $tag) {
            $out[] = $tag;
        }

        return $out;
    }

    #[\Override]
    public function archive(int $id): void
    {
        $tag = Tag::getOne($id);
        if (null === $tag || null !== $tag->archived_at) {
            return;
        }
        $tag->archived_at = new \DateTimeImmutable('now');
        $tag->save();
    }

    #[\Override]
    public function restore(int $id): void
    {
        $tag = Tag::getOne($id);
        if (null === $tag || null === $tag->archived_at) {
            return;
        }
        $tag->archived_at = null;
        $tag->save();
    }
}
