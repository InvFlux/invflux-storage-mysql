<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Integration\Order;

use Nandan108\Attrecord\Connection;
use Nandan108\Attrecord\Dialect\MysqlDialect;
use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Session\PdoDbSession;
use Nandan108\InvFlux\Domain\Order\Order;
use Nandan108\InvFlux\Domain\Order\OrderPayment;
use Nandan108\InvFlux\Domain\Order\PaymentSource;
use Nandan108\InvFlux\Identity\RecordIdentity;
use Nandan108\InvFlux\Storage\Mysql\Order\MysqlOrderPaymentRepository;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\SchemaFixture;
use PHPUnit\Framework\TestCase;

/**
 * An order's payments: stored with every detail, read back per order or many orders at once,
 * voided ones included, and voided by an update rather than a delete.
 */
final class OrderPaymentRepositoryTest extends TestCase
{
    private const SOURCE_SYSTEM = 'payment_test';

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
        // Ids increasing in call order, as production's time-ordered ids are: the repository reads
        // payments back in id order, which one test asserts is the order they were recorded in.
        $minted = 0;
        RecordIdentity::setMinter(static function () use (&$minted): string {
            return pack('J', ++$minted).random_bytes(8);
        });
        SchemaFixture::install($connection);

        // Only this test's orders; their payments go with them (FK CASCADE).
        $this->pdo->exec(sprintf("DELETE FROM invflux_orders WHERE source_system = '%s'", self::SOURCE_SYSTEM));
    }

    public function testStoresAPaymentWithEveryDetail(): void
    {
        $orderId = $this->order('1001');
        $received = new \DateTimeImmutable('2026-09-12 14:05:00.250000');

        [$saved] = $this->repo()->saveAll([$this->payment($orderId, [
            'amount'            => '100.00',
            'currency'          => 'EUR',
            'fx_rate'           => '0.93512345',
            'method'            => 'bacs',
            'transaction_ref'   => 'SEPA-2026-0912-77',
            'received_at'       => $received,
            'difference'        => '1.20',
            'difference_reason' => 'bank_fee',
            'recorded_by'       => 3,
            'note'              => 'Arrived net of the correspondent bank fee.',
        ])]);

        self::assertNotNull($saved->id);
        $found = $this->repo()->findById($saved->id);
        self::assertNotNull($found);
        self::assertSame('100.00', $found->amount);
        self::assertSame('EUR', $found->currency);
        self::assertSame('0.93512345', $found->fx_rate);
        self::assertSame('93.51', $found->amount_order_ccy, 'derived on save and stored');
        self::assertSame(PaymentSource::MANUAL, $found->source);
        self::assertSame('SEPA-2026-0912-77', $found->transaction_ref);
        self::assertEquals($received, $found->received_at);
        self::assertSame('1.20', $found->difference);
        self::assertSame('bank_fee', $found->difference_reason);
        self::assertSame(3, $found->recorded_by);
        self::assertNotNull($found->recorded_at);
        self::assertFalse($found->isVoided());
    }

    public function testReadsManyOrdersPaymentsAtOnceVoidedOnesIncluded(): void
    {
        $first = $this->order('1002');
        $second = $this->order('1003');
        $other = $this->order('1004');

        [$kept, $mistake] = $this->repo()->saveAll([
            $this->payment($first, ['amount' => '40.00']),
            $this->payment($first, ['amount' => '60.00']),
            $this->payment($second, ['amount' => '15.00']),
            $this->payment($other, ['amount' => '99.00']),
        ]);
        $mistake->void(3, 'Recorded twice', new \DateTimeImmutable());
        $this->repo()->saveAll([$mistake]);

        $firstPayments = $this->repo()->forOrder($first);
        self::assertSame([$kept->id, $mistake->id], array_map(static fn (OrderPayment $p): ?string => $p->id, $firstPayments), 'in the order they were recorded, the voided one kept');
        self::assertSame('40.00', OrderPayment::paidTotal($firstPayments));

        self::assertCount(3, $this->repo()->forOrders([$first, $second]));
        self::assertSame([], $this->repo()->forOrders([]));
    }

    public function testAVoidIsWrittenInPlace(): void
    {
        $orderId = $this->order('1005');
        [$payment] = $this->repo()->saveAll([$this->payment($orderId)]);
        self::assertNotNull($payment->id);

        $payment->void(4, 'Wrong order', new \DateTimeImmutable('2026-09-14 08:00:00'));
        $this->repo()->saveAll([$payment]);

        $found = $this->repo()->findById($payment->id);
        self::assertNotNull($found);
        self::assertTrue($found->isVoided());
        self::assertSame(4, $found->voided_by);
        self::assertSame('Wrong order', $found->void_reason);
        self::assertCount(1, $this->repo()->forOrder($orderId), 'an update, never a second row');
    }

    public function testAnUnknownIdFindsNothingAndAnEmptySaveWritesNothing(): void
    {
        self::assertNull($this->repo()->findById(str_repeat("\0", 16)));
        self::assertSame([], $this->repo()->saveAll([]));
    }

    private function repo(): MysqlOrderPaymentRepository
    {
        return new MysqlOrderPaymentRepository();
    }

    private function order(string $externalId): string
    {
        $order = (new Order())->set(['source_system' => self::SOURCE_SYSTEM, 'external_id' => $externalId]);
        $order->save();
        \assert(null !== $order->id);

        return $order->id;
    }

    /**
     * @param array<string, mixed> $override
     */
    private function payment(string $orderId, array $override = []): OrderPayment
    {
        return (new OrderPayment())->set($override + [
            'order_id'    => $orderId,
            'amount'      => '10.00',
            'currency'    => 'CHF',
            'method'      => 'bacs',
            'source'      => PaymentSource::MANUAL,
            'received_at' => new \DateTimeImmutable('2026-09-13 09:30:00'),
        ]);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
