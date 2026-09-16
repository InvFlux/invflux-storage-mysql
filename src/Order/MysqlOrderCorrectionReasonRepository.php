<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\InvFlux\Domain\Order\OrderCorrectionReason;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionReasonRepository;

/**
 * MySQL-backed implementation of {@see OrderCorrectionReasonRepository}.
 *
 * The underlying registry is seeded at install by {@see MysqlOrderStore}; this
 * repository is read-only at runtime.
 *
 * @api
 */
final class MysqlOrderCorrectionReasonRepository implements OrderCorrectionReasonRepository
{
    #[\Override]
    public function findById(int $id): ?OrderCorrectionReason
    {
        return OrderCorrectionReason::getOne($id);
    }

    #[\Override]
    public function findByCode(string $code): ?OrderCorrectionReason
    {
        return OrderCorrectionReason::findOne('`code` = ?', [$code]);
    }

    #[\Override]
    public function all(): array
    {
        $set = OrderCorrectionReason::find('1', [], 'ORDER BY `code` ASC');
        $list = [];
        foreach ($set as $reason) {
            $list[] = $reason;
        }

        return $list;
    }
}
