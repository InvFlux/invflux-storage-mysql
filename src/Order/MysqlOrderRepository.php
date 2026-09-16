<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Order\OrderRepository;

/**
 * MySQL-backed implementation of {@see OrderRepository}.
 *
 * Thin attrecord wrapper — domain types ARE the Records, so this class
 * delegates to {@see Order}'s static finders. attrecord's default
 * Connection is configured once at adapter wire-up time
 * (see Plugin::buildContainer()).
 *
 * @api
 */
final class MysqlOrderRepository implements OrderRepository
{
    #[\Override]
    public function findById(string $id): ?Order
    {
        return Order::getOne($id);
    }

    #[\Override]
    public function findByExternalRef(string $sourceSystem, string $externalId): ?Order
    {
        return Order::findOne(
            '`source_system` = ? AND `external_id` = ?',
            [$sourceSystem, $externalId],
        );
    }

    #[\Override]
    public function save(Order $order): Order
    {
        $order->save();

        return $order;
    }
}
