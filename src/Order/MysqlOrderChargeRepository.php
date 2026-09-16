<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Order\OrderCharge;
use Nandan108\InvFlux\Domain\Order\OrderChargeRepository;

/**
 * MySQL-backed implementation of {@see OrderChargeRepository}.
 *
 * @api
 */
final class MysqlOrderChargeRepository implements OrderChargeRepository
{
    #[\Override]
    public function forOrder(string $orderId): array
    {
        return $this->forOrders([$orderId]);
    }

    #[\Override]
    public function forOrders(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        $list = [];
        foreach (OrderCharge::find(WhereClause::whereIn('order_id', $orderIds), [], 'ORDER BY `id` ASC') as $charge) {
            $list[] = $charge;
        }

        return $list;
    }

    /**
     * @param list<OrderCharge> $charges
     *
     * @return list<OrderCharge>
     */
    #[\Override]
    public function saveAll(array $charges): array
    {
        if ([] === $charges) {
            return [];
        }

        (new RecordSet($charges))->upsertAll();

        return $charges;
    }

    /**
     * @param list<OrderCharge> $charges
     */
    #[\Override]
    public function deleteAll(array $charges): void
    {
        if ([] === $charges) {
            return;
        }

        OrderCharge::deleteWhere(WhereClause::whereIn('id', array_map(
            static fn (OrderCharge $charge): string => $charge->id
                ?? throw new \LogicException('deleteAll() requires persisted charges with populated ids.'),
            $charges,
        )));
    }
}
