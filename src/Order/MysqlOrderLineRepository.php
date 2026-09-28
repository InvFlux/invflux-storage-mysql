<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\BinaryParam;
use Nandan108\Attrecord\RecordSet;
use Nandan108\InvFlux\Domain\Order\OrderCorrection;
use Nandan108\InvFlux\Domain\Order\OrderLine;
use Nandan108\InvFlux\Domain\Order\OrderLineRepository;
use Nandan108\InvFlux\Domain\Shipment\ShipmentLine;
use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * MySQL-backed implementation of {@see OrderLineRepository}.
 *
 * @api
 */
final class MysqlOrderLineRepository implements OrderLineRepository
{
    #[\Override]
    public function findByKeys(string $orderId, array $lineIds): array
    {
        if ([] === $lineIds) {
            return [];
        }

        // One statement whatever the count. Scoped by order first, so the `IN` is over the key's
        // second member within one order rather than over numbers that repeat across the store.
        $in = implode(',', array_fill(0, \count($lineIds), '?'));
        $found = [];
        foreach (OrderLine::find("`order_id` = ? AND `line_id` IN ({$in})", [new BinaryParam($orderId), ...$lineIds]) as $line) {
            $found[$line->line_id] = $line;
        }

        return $found;
    }

    #[\Override]
    public function findByExternalRefs(string $orderId, array $externalLineRefs): array
    {
        if ([] === $externalLineRefs) {
            return [];
        }

        $in = implode(',', array_fill(0, \count($externalLineRefs), '?'));
        $found = [];
        foreach (OrderLine::find("`order_id` = ? AND `external_line_ref` IN ({$in})", [new BinaryParam($orderId), ...$externalLineRefs]) as $line) {
            // A line with no host counterpart carries a null ref and cannot match an asked-for one,
            // so it is not a row this lookup can return — but the column is nullable now, and
            // keying a map by null would silently collapse every such line onto the key `''`.
            if (null === $line->external_line_ref) {
                continue;
            }
            $found[$line->external_line_ref] = $line;
        }

        return $found;
    }

    #[\Override]
    public function forOrder(string $orderId): array
    {
        // By the key's own second member. It used to order by the surrogate, which worked only
        // because that surrogate was a UUIDv7 and so happened to sort by mint time.
        $set = OrderLine::find('`order_id` = ?', [new BinaryParam($orderId)], 'ORDER BY `line_id` ASC');
        $list = [];
        foreach ($set as $line) {
            $list[] = $line;
        }

        return $list;
    }

    /**
     * @param list<OrderLine> $lines
     *
     * @return list<OrderLine>
     */
    #[\Override]
    public function saveAll(array $lines): array
    {
        if ([] === $lines) {
            return [];
        }

        (new RecordSet($lines))->upsertAll();

        return $lines;
    }

    /**
     * @param list<OrderLine> $lines
     */
    #[\Override]
    public function deleteAll(array $lines): void
    {
        if ([] === $lines) {
            return;
        }

        // RecordSet's own bulk delete, which names each row by its whole key. A `whereIn` on one
        // column cannot express this any more: line numbers repeat across orders, so a delete
        // keyed on the number alone would take line 3 of every order in the store.
        (new RecordSet($lines))->deleteAll();
    }

    /**
     * One bulk read per referring table — two queries rather than a join, because the question is
     * only "does a row exist" and each table answers it about the same key set independently.
     * Still two whatever the line count: the keys go into a row-constructor `IN`, not a loop.
     *
     * **A `whereIn` on the line number would be wrong**, which is the trap this change introduces.
     * Numbers repeat across orders, so asking "is line 3 referenced" gets an answer about every
     * order in the store — and the answer is usually yes, so the prune would stop deleting
     * anything and nothing would look broken.
     *
     * Kept in step with the RESTRICT edges declared on {@see ShipmentLine} and
     * {@see OrderCorrection}: this is the read that predicts what the database would refuse, so a
     * referring table added later has to be added here too, or a delete that this call says is
     * safe will throw.
     */
    #[\Override]
    public function referencedIds(array $lines): array
    {
        if ([] === $lines) {
            return [];
        }

        $tuples = implode(',', array_fill(0, \count($lines), '(?,?)'));
        $params = [];
        foreach ($lines as $line) {
            $params[] = $line->order_id
                ?? throw new \LogicException('referencedIds() requires lines with a populated key.');
            $params[] = $line->line_id;
        }

        $referenced = [];
        foreach (ShipmentLine::find("(`order_id`, `order_line_id`) IN ({$tuples})", $params) as $shipmentLine) {
            $referenced[OrderLine::keyOf($shipmentLine->order_id, $shipmentLine->order_line_id)] = true;
        }
        foreach (OrderCorrection::find("(`order_id`, `line_id`) IN ({$tuples})", $params) as $correction) {
            $referenced[OrderLine::keyOf($correction->order_id, $correction->line_id)] = true;
        }

        return $referenced;
    }

    #[\Override]
    public function countOutstandingForSubject(SubjectId $subjectId): int
    {
        return OrderLine::countWhere(
            '`subject_id` = ? AND `qty_outstanding` > 0',
            [$subjectId->id],
        );
    }
}
