<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Location;

use Nandan108\Attrecord\Connection;
use Nandan108\InvFlux\Domain\Location\LocationLink;
use Nandan108\InvFlux\Domain\Location\LocationProductLink;
use Nandan108\InvFlux\Domain\Location\SubjectLocationReader;
use Nandan108\InvFlux\Domain\Location\SubjectLocations;
use Nandan108\InvFlux\Storage\Mysql\Session\MysqlSession;

/**
 * MySQL-backed {@see SubjectLocationReader}: one statement for any number of subjects.
 *
 * Raw SQL because it is a three-table read — subjects, their links, the linked locations. Its two
 * halves are the variation rule: a subject's own links, and its parent's links for a subject that has
 * none of its own. The parent is `invflux_subjects.parent_id`, so a variation inherits from its variable
 * product and a product with no parent inherits nothing.
 *
 * @api
 */
final class MysqlSubjectLocationReader implements SubjectLocationReader
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = '',
        private readonly ?MysqlSession $session = null,
    ) {
    }

    #[\Override]
    public function effectiveFor(array $subjectIds): array
    {
        $subjectIds = array_values(array_unique($subjectIds));
        if ([] === $subjectIds) {
            return [];
        }
        $in = implode(',', array_fill(0, \count($subjectIds), '?'));
        $subjects = $this->table('invflux_subjects');
        $links = $this->table('invflux_subject_locations');
        $locations = $this->table('invflux_locations');
        $columns = 'sl.location_id, sl.`preference`, sl.note, l.path, l.name, l.sort_key, l.archived_at';

        $session = $this->session ?? $this->connection->session;
        $rows = $session->fetchAll(
            "SELECT s.id AS subject_id, 0 AS inherited, {$columns}
               FROM `{$subjects}` s
               JOIN `{$links}` sl ON sl.subject_id = s.id
               JOIN `{$locations}` l ON l.id = sl.location_id
              WHERE s.id IN ({$in})
             UNION ALL
             SELECT s.id AS subject_id, 1 AS inherited, {$columns}
               FROM `{$subjects}` s
               JOIN `{$links}` sl ON sl.subject_id = s.parent_id
               JOIN `{$locations}` l ON l.id = sl.location_id
              WHERE s.id IN ({$in})
                AND NOT EXISTS (SELECT 1 FROM `{$links}` own WHERE own.subject_id = s.id)
             ORDER BY subject_id, `preference`",
            [...$subjectIds, ...$subjectIds],
        );

        /** @var array<int, list<LocationLink>> $bySubject */
        $bySubject = [];
        $inherited = [];
        foreach ($rows as $row) {
            $subjectId = (int) $row['subject_id'];
            $bySubject[$subjectId][] = new LocationLink(
                locationId: (int) $row['location_id'],
                path: (string) $row['path'],
                name: null === $row['name'] ? null : (string) $row['name'],
                sortKey: (string) $row['sort_key'],
                rank: (int) $row['preference'],
                note: null === $row['note'] ? null : (string) $row['note'],
                archived: null !== $row['archived_at'],
            );
            $inherited[$subjectId] = 1 === (int) $row['inherited'];
        }

        $known = [];
        foreach ($session->fetchAll("SELECT id FROM `{$subjects}` WHERE id IN ({$in})", $subjectIds) as $row) {
            $known[] = (int) $row['id'];
        }

        $out = [];
        foreach ($known as $subjectId) {
            $out[$subjectId] = new SubjectLocations($subjectId, $bySubject[$subjectId] ?? [], $inherited[$subjectId] ?? false);
        }

        return $out;
    }

    /** Raw SQL for a grouped count — attrecord's single-scalar counts would take one query per location. */
    #[\Override]
    public function countsByLocation(): array
    {
        $session = $this->session ?? $this->connection->session;
        $out = [];
        foreach ($session->fetchAll(sprintf(
            'SELECT location_id, COUNT(*) AS n FROM `%s` GROUP BY location_id',
            $this->table('invflux_subject_locations'),
        )) as $row) {
            $out[(int) $row['location_id']] = (int) $row['n'];
        }

        return $out;
    }

    /** Raw SQL because the order is the locations' path order: a two-table read. */
    #[\Override]
    public function linksAt(array $locationIds, int $limit, int $offset = 0): array
    {
        $locationIds = array_values(array_unique($locationIds));
        if ([] === $locationIds) {
            return ['total' => 0, 'links' => []];
        }
        $in = implode(',', array_fill(0, \count($locationIds), '?'));
        $links = $this->table('invflux_subject_locations');
        $locations = $this->table('invflux_locations');
        $session = $this->session ?? $this->connection->session;

        $total = (int) ($session->fetchAll(
            "SELECT COUNT(*) AS n FROM `{$links}` WHERE location_id IN ({$in})",
            $locationIds,
        )[0]['n'] ?? 0);

        $rows = $session->fetchAll(
            sprintf(
                "SELECT sl.subject_id, sl.location_id, sl.`preference`, sl.note, l.path
                   FROM `{$links}` sl
                   JOIN `{$locations}` l ON l.id = sl.location_id
                  WHERE sl.location_id IN ({$in})
                  ORDER BY l.sort_key, sl.`preference`, sl.subject_id
                  LIMIT %d OFFSET %d",
                max(0, $limit),
                max(0, $offset),
            ),
            $locationIds,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = new LocationProductLink(
                subjectId: (int) $row['subject_id'],
                locationId: (int) $row['location_id'],
                path: (string) $row['path'],
                rank: (int) $row['preference'],
                note: null === $row['note'] ? null : (string) $row['note'],
            );
        }

        return ['total' => $total, 'links' => $out];
    }

    private function table(string $name): string
    {
        return str_replace('`', '``', $this->tablePrefix.$name);
    }
}
