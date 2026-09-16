<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Order\OrderPayment;
use Nandan108\InvFlux\Domain\Order\OrderPaymentRepository;

/**
 * MySQL-backed implementation of {@see OrderPaymentRepository}.
 *
 * @api
 */
final class MysqlOrderPaymentRepository implements OrderPaymentRepository
{
    #[\Override]
    public function findById(string $id): ?OrderPayment
    {
        return OrderPayment::getOne($id);
    }

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
        // Ids are UUIDv7, so id order is the order the payments were recorded in.
        foreach (OrderPayment::find(WhereClause::whereIn('order_id', $orderIds), [], 'ORDER BY `id` ASC') as $payment) {
            $list[] = $payment;
        }

        return $list;
    }

    /**
     * @param list<OrderPayment> $payments
     *
     * @return list<OrderPayment>
     */
    #[\Override]
    public function saveAll(array $payments): array
    {
        if ([] === $payments) {
            return [];
        }

        (new RecordSet($payments))->upsertAll();

        return $payments;
    }
}
