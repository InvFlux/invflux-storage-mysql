<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\BinaryParam;
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

        // Both halves of the parentage are stamped here, not left to the caller. `shipment_id` is
        // the shipment we just saved and only exists now; `order_id` is copied from that shipment
        // rather than trusted from the line, so the two cannot disagree — and the schema will not
        // let them, since `(shipment_id, order_id)` is a foreign key against the shipment's own
        // `(id, order_id)`. Copying it is how that constraint is satisfied by construction instead
        // of by a writer remembering.
        foreach ($lines as $line) {
            $line->shipment_id = $shipment->id;
            $line->order_id = $shipment->order_id;
        }
        // Shipment lines are write-once (ShipmentLine is AppendOnly): insertAll() is one plain
        // INSERT that throws on a duplicate key. upsertAll() would take its keyed-upsert path here
        // and silently swallow a duplicate via INSERT IGNORE — wrong for an immutable dispatch
        // record, and now a duplicate means "this shipment already ships that line", which is
        // precisely the thing the key exists to refuse.
        (new RecordSet($lines))->insertAll();
    }

    #[\Override]
    public function forOrder(string $orderId): array
    {
        $set = Shipment::find('`order_id` = ?', [new BinaryParam($orderId)], 'ORDER BY `created_at` ASC, `id` ASC');
        $list = [];
        foreach ($set as $shipment) {
            $list[] = $shipment;
        }

        return $list;
    }

    #[\Override]
    public function linesForShipment(string $shipmentId): array
    {
        // Ordered by the line's number within its order — the key's second member, and the only
        // stable ordering left now that the surrogate id is gone.
        $set = ShipmentLine::find('`shipment_id` = ?', [new BinaryParam($shipmentId)], 'ORDER BY `order_line_id` ASC');
        $list = [];
        foreach ($set as $line) {
            $list[] = $line;
        }

        return $list;
    }
}
