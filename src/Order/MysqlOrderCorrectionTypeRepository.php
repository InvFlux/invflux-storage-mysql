<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\InvFlux\Domain\Order\OrderCorrectionType;
use Nandan108\InvFlux\Domain\Order\OrderCorrectionTypeRepository;

/**
 * MySQL-backed implementation of {@see OrderCorrectionTypeRepository}.
 *
 * The underlying registry is seeded at install by {@see MysqlOrderStore}; this
 * repository is read-only at runtime.
 *
 * @api
 */
final class MysqlOrderCorrectionTypeRepository implements OrderCorrectionTypeRepository
{
    #[\Override]
    public function findByCode(string $code): ?OrderCorrectionType
    {
        return OrderCorrectionType::findOne('`code` = ?', [$code]);
    }

    #[\Override]
    public function all(): array
    {
        $set = OrderCorrectionType::find('1', [], 'ORDER BY `code` ASC');
        $list = [];
        foreach ($set as $type) {
            $list[] = $type;
        }

        return $list;
    }
}
