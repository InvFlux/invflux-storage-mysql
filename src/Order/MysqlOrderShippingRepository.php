<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Order\OrderShipping;
use Nandan108\InvFlux\Domain\Order\OrderShippingRepository;

/**
 * MySQL-backed implementation of {@see OrderShippingRepository}.
 *
 * @api
 */
final class MysqlOrderShippingRepository implements OrderShippingRepository
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
        foreach (OrderShipping::find(WhereClause::whereIn('order_id', $orderIds), [], 'ORDER BY `id` ASC') as $arrangement) {
            $list[] = $arrangement;
        }

        return $list;
    }

    /**
     * @param list<OrderShipping> $arrangements
     *
     * @return list<OrderShipping>
     */
    #[\Override]
    public function saveAll(array $arrangements): array
    {
        if ([] === $arrangements) {
            return [];
        }

        (new RecordSet($arrangements))->upsertAll();

        return $arrangements;
    }

    /**
     * @param list<OrderShipping> $arrangements
     */
    #[\Override]
    public function deleteAll(array $arrangements): void
    {
        if ([] === $arrangements) {
            return;
        }

        OrderShipping::deleteWhere(WhereClause::whereIn('id', array_map(
            static fn (OrderShipping $arrangement): string => $arrangement->id
                ?? throw new \LogicException('deleteAll() requires persisted arrangements with populated ids.'),
            $arrangements,
        )));
    }
}
