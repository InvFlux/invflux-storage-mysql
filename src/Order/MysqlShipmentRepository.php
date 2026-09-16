<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Domain\Shipment\Shipment;
use Nandan108\InvFlux\Domain\Shipment\ShipmentLine;
use Nandan108\InvFlux\Domain\Shipment\ShipmentRepository;

/**
 * MySQL-backed implementation of {@see ShipmentRepository}.
 *
 * `persist()` saves the shipment first (minting its id via beforeSave), then
 * re-points every line at that id and bulk-inserts them in one `insertAll()`
 * (lines are write-once — {@see ShipmentLine} is AppendOnly).
 * Meant to run inside the caller's dispatch transaction so the shipment, the
 * `oh.ctd → nil` inventory decrement, and the stamped cost commit atomically.
 *
 * @api
 */
final class MysqlShipmentRepository implements ShipmentRepository
{
    #[\Override]
    public function persist(Shipment $shipment, array $lines): void
    {
        $shipment->save();

        if ([] === $lines) {
            return;
        }

        foreach ($lines as $line) {
            $line->shipment_id = $shipment->id;
        }
        // Shipment lines are write-once (ShipmentLine is AppendOnly): insertAll() is one plain
        // INSERT that throws on a duplicate minted PK. upsertAll() would take its keyed-upsert path
        // here (the PK is minted in beforeSave, before upsertAll's insert/upsert partition), silently
        // swallowing a duplicate via INSERT IGNORE — wrong for an immutable dispatch record.
        (new RecordSet($lines))->insertAll();
    }

    #[\Override]
    public function forOrder(string $orderId): array
    {
        $set = Shipment::find('`order_id` = ?', [$orderId], 'ORDER BY `created_at` ASC, `id` ASC');
        $list = [];
        foreach ($set as $shipment) {
            $list[] = $shipment;
        }

        return $list;
    }

    #[\Override]
    public function linesForShipment(string $shipmentId): array
    {
        $set = ShipmentLine::find('`shipment_id` = ?', [$shipmentId], 'ORDER BY `id` ASC');
        $list = [];
        foreach ($set as $line) {
            $list[] = $line;
        }

        return $list;
    }
}
