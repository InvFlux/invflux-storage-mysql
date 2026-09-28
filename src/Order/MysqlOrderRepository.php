<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Order;

use Nandan108\Attrecord\BinaryParam;
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
    /** `OrderLine::$line_id` is SMALLINT UNSIGNED; this is its largest value. */
    private const LINE_NUMBER_CEILING = 65535;

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

    /**
     * Reserve `$count` consecutive line numbers on the order and return the first.
     *
     * The increment and the read-back run in one transaction (joining the caller's, when there is
     * one). The `UPDATE` takes the order row's write lock and holds it until commit, so a
     * concurrent reservation on the same order waits, then increments past this one — each session
     * reads back its own block. Reading *before* incrementing would not be safe this way: two
     * sessions could read the same value before either wrote.
     *
     * The ceiling is part of the `UPDATE`'s predicate rather than checked afterwards. WordPress
     * does not run strict mode, so an increment past the column's range does not fail: it
     * **clamps** `next_line_no` and reports success, which a check made after the fact cannot tell
     * apart from an exact fill. With the ceiling in the predicate the statement never exceeds it;
     * zero rows affected then means either no such order or an exhausted counter, and one read
     * tells which.
     *
     * Plain SQL that every supported backend accepts, including MySQL-to-SQLite translation.
     */
    #[\Override]
    public function allocateLineNumbers(string $orderId, int $count): int
    {
        if ($count <= 0) {
            throw new \InvalidArgumentException('allocateLineNumbers() needs a positive count.');
        }

        $connection = Order::connection();
        $quoted = $connection->dialect->quoteIdentifier(Order::schema()->tableName);
        $session = $connection->session;
        $id = new BinaryParam($orderId);

        return $session->transactional(function () use ($session, $quoted, $id, $orderId, $count): int {
            $affected = $session->exec(
                "UPDATE {$quoted} SET `next_line_no` = `next_line_no` + ? WHERE `id` = ? AND `next_line_no` + ? <= ?",
                [$count, $id, $count, self::LINE_NUMBER_CEILING],
            );

            $next = $session->fetchScalar("SELECT `next_line_no` FROM {$quoted} WHERE `id` = ?", [$id]);
            if (null === $next) {
                throw new \LogicException(sprintf(
                    'Cannot allocate line numbers for order %s: no such order.',
                    bin2hex($orderId),
                ));
            }

            // The counter never goes backwards, so `line_id`'s SMALLINT is a *lifetime* budget per
            // order rather than a limit on how many lines it may hold at once — that is the price of
            // never reusing a number, and it is the right trade.
            if (0 === $affected) {
                throw new \RuntimeException(sprintf(
                    'Order %s has exhausted its line numbers: %d allocated over its lifetime, ceiling %d. '
                    .'Numbers are never reused, so this is a lifetime total, not a count of live lines.',
                    bin2hex($orderId),
                    (int) $next,
                    self::LINE_NUMBER_CEILING,
                ));
            }

            return (int) $next - $count;
        });
    }
}
