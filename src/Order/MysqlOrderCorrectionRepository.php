<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\InvFlux\Domain\Order\OrderCorrection;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionRepository;

/**
 * MySQL-backed implementation of {@see OrderCorrectionRepository}.
 *
 * All reads/writes go through attrecord's `OrderCorrection` statics, so this class
 * needs no session/prefix of its own.
 *
 * @api
 */
final class MysqlOrderCorrectionRepository implements OrderCorrectionRepository
{
    #[\Override]
    public function findById(string $id): ?OrderCorrection
    {
        return OrderCorrection::getOne($id);
    }

    #[\Override]
    public function forOrder(string $orderId): array
    {
        $set = OrderCorrection::find(
            '`order_id` = ?',
            [$orderId],
            'ORDER BY `created_at` ASC, `id` ASC',
        );
        $list = [];
        foreach ($set as $correction) {
            $list[] = $correction;
        }

        return $list;
    }

    #[\Override]
    public function unprocessed(int $limit = 100): array
    {
        $set = OrderCorrection::find(
            '`processed_at` IS NULL',
            [],
            sprintf('ORDER BY `created_at` ASC, `id` ASC LIMIT %d', max(1, $limit)),
        );
        $list = [];
        foreach ($set as $correction) {
            $list[] = $correction;
        }

        return $list;
    }

    #[\Override]
    public function save(OrderCorrection $correction): OrderCorrection
    {
        $correction->save();

        return $correction;
    }

    #[\Override]
    public function delete(string $id): void
    {
        $correction = OrderCorrection::getOne($id);
        $correction?->delete();
    }
}
