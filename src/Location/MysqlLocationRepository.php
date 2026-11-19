<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Location;

use Nandan108\InvFlux\Domain\Location\Location;
use Nandan108\InvFlux\Domain\Location\LocationRepository;
use Nandan108\InvFlux\Domain\Location\LocationTree;

/**
 * MySQL-backed {@see LocationRepository}: single-table reads, so attrecord's.
 *
 * @api
 */
final class MysqlLocationRepository implements LocationRepository
{
    #[\Override]
    public function tree(): LocationTree
    {
        return new LocationTree(Location::find());
    }

    #[\Override]
    public function find(int $id): ?Location
    {
        return Location::getOne($id);
    }

    #[\Override]
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $map = [];
        foreach (Location::whereIn('id', $ids) as $location) {
            $map[(int) $location->id] = $location;
        }

        return $map;
    }
}
