<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\RecordSet;
use Nandan108\Attrecord\WhereClause;
use Nandan108\InvFlux\Domain\Order\OrderLine;
use Nandan108\InvFlux\Domain\Order\OrderLineRepository;
use Nandan108\InvFlux\Domain\Subject\SubjectId;

/**
 * MySQL-backed implementation of {@see OrderLineRepository}.
 *
 * @api
 */
final class MysqlOrderLineRepository implements OrderLineRepository
{
    #[\Override]
    public function findById(string $id): ?OrderLine
    {
        return OrderLine::getOne($id);
    }

    #[\Override]
    public function findByExternalRef(string $orderId, string $externalLineRef): ?OrderLine
    {
        return OrderLine::findOne(
            '`order_id` = ? AND `external_line_ref` = ?',
            [$orderId, $externalLineRef],
        );
    }

    #[\Override]
    public function forOrder(string $orderId): array
    {
        $set = OrderLine::find('`order_id` = ?', [$orderId], 'ORDER BY `id` ASC');
        $list = [];
        foreach ($set as $line) {
            $list[] = $line;
        }

        return $list;
    }

    #[\Override]
    public function save(OrderLine $line): OrderLine
    {
        $line->save();

        return $line;
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

        OrderLine::deleteWhere(WhereClause::whereIn('id', array_map(
            static fn (OrderLine $line): string => $line->id
                ?? throw new \LogicException('deleteAll() requires persisted lines with populated ids.'),
            $lines,
        )));
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
