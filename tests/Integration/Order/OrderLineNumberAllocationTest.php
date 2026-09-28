<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Order;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Storage\Mysql\Order\MysqlOrderRepository;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * Line numbers are reserved in blocks off the order's `next_line_no` counter, never reused, and
 * bounded by the `line_id` column's range.
 */
final class OrderLineNumberAllocationTest extends TestCase
{
    private const SOURCE_SYSTEM = 'line_no_test';

    private ?\PDO $pdo = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->env('INVFLOW_DB_HOST', '127.0.0.1'),
                $this->env('INVFLOW_DB_PORT', '33067'),
                $this->env('INVFLOW_DB_NAME', 'invflux_test'),
            ),
            $this->env('INVFLOW_DB_USER', 'invflux'),
            $this->env('INVFLOW_DB_PASS', 'invflux'),
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        );

        $connection = new Connection(new PdoDbSession($this->pdo), new MysqlDialect());
        AttrecordRecord::setTablePrefix('');
        AttrecordRecord::setConnection($connection);
        RecordIdentity::setMinter(static fn (): string => random_bytes(16));
        SchemaFixture::install($connection);

        $this->pdo->exec(sprintf("DELETE FROM invflux_orders WHERE source_system = '%s'", self::SOURCE_SYSTEM));
    }

    public function testConsecutiveReservationsHandOutConsecutiveBlocks(): void
    {
        $orderId = $this->order('2001');
        $repo = new MysqlOrderRepository();

        self::assertSame(1, $repo->allocateLineNumbers($orderId, 3), 'a new order starts at line 1');
        self::assertSame(4, $repo->allocateLineNumbers($orderId, 2), 'the next block starts after the last');
        self::assertSame(6, $this->nextLineNo($orderId));
    }

    public function testAnUnknownOrderIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no such order');

        (new MysqlOrderRepository())->allocateLineNumbers(random_bytes(16), 1);
    }

    public function testAReservationPastTheCeilingIsRefusedAndLeavesTheCounterAlone(): void
    {
        $orderId = $this->order('2002');
        $this->setNextLineNo($orderId, 65534);
        $repo = new MysqlOrderRepository();

        self::assertSame(65534, $repo->allocateLineNumbers($orderId, 1), 'filling exactly to the ceiling is allowed');

        try {
            $repo->allocateLineNumbers($orderId, 1);
            self::fail('a reservation past the ceiling must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exhausted its line numbers', $e->getMessage());
        }

        self::assertSame(65535, $this->nextLineNo($orderId), 'the refused reservation did not move the counter');
    }

    private function order(string $externalId): string
    {
        $order = (new Order())->set(['source_system' => self::SOURCE_SYSTEM, 'external_id' => $externalId]);
        $order->save();
        \assert(null !== $order->id);

        return $order->id;
    }

    private function nextLineNo(string $orderId): int
    {
        $statement = $this->pdo()->prepare('SELECT next_line_no FROM invflux_orders WHERE id = ?');
        $statement->execute([$orderId]);

        return (int) $statement->fetchColumn();
    }

    private function setNextLineNo(string $orderId, int $value): void
    {
        $this->pdo()->prepare('UPDATE invflux_orders SET next_line_no = ? WHERE id = ?')->execute([$value, $orderId]);
    }

    private function pdo(): \PDO
    {
        \assert(null !== $this->pdo);

        return $this->pdo;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
