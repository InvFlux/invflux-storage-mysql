<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Session;

use Nandan108\InvFlux\Storage\Mysql\Session\DeadlockAwareRetryPolicy;
use Nandan108\InvFlux\Storage\Mysql\Session\DeadlockTelemetry;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\FakeMysqlSession;
use PHPUnit\Framework\TestCase;

final class DeadlockAwareRetryPolicyTest extends TestCase
{
    public function testNonTransientErrorIsNeverRetried(): void
    {
        $policy = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: false), retryDeadlocks: true);

        self::assertFalse($policy(new \RuntimeException('some unrelated error')));
    }

    public function testLockWaitTimeoutIsRetriedInEveryEnvironment(): void
    {
        $conflict = new \RuntimeException('Lock wait timeout exceeded; try restarting transaction', 1205);

        $prod = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: true);
        $dev = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: false);

        self::assertTrue($prod($conflict));
        self::assertTrue($dev($conflict));
    }

    public function testDeadlockIsRetriedOnlyInProduction(): void
    {
        $deadlock = new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction', 1213);

        $prod = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: true);
        $dev = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: false);

        self::assertTrue($prod($deadlock), 'production keeps serving by retrying');
        self::assertFalse($dev($deadlock), 'non-production surfaces the discipline slip loudly');
    }

    public function testDeadlockIsRecordedToTelemetryEvenWhenNotRetried(): void
    {
        $telemetry = new RecordingDeadlockTelemetry();
        $deadlock = new \RuntimeException('Deadlock found when trying to get lock', 1213);

        // dev: not retried, but must still be traced.
        $policy = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: false, telemetry: $telemetry);

        self::assertFalse($policy($deadlock));
        self::assertSame([$deadlock], $telemetry->recorded);
    }

    public function testNonDeadlockConflictIsNotRecorded(): void
    {
        $telemetry = new RecordingDeadlockTelemetry();
        $policy = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: true, telemetry: $telemetry);

        $policy(new \RuntimeException('Lock wait timeout exceeded', 1205));

        self::assertSame([], $telemetry->recorded);
    }

    public function testDeadlockDetectedViaMessageOnWrappedPreviousException(): void
    {
        // wpdb / PDO surface the driver error wrapped: the "Deadlock found" text rides on an inner
        // exception, and the outer wrapper carries an opaque message.
        $inner = new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
        $outer = new \RuntimeException('pdo_error', 0, $inner);

        $policy = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: false);

        self::assertFalse($policy($outer), 'still recognised as a deadlock through the wrapper');
    }

    public function testDeadlockDetectedViaErrnoOnWrappedPreviousException(): void
    {
        $inner = new \RuntimeException('opaque driver text', 1213);
        $outer = new \RuntimeException('wrapper', 0, $inner);

        $prod = new DeadlockAwareRetryPolicy(new FakeMysqlSession(retryable: true), retryDeadlocks: true);

        self::assertTrue($prod($outer));
    }
}

final class RecordingDeadlockTelemetry implements DeadlockTelemetry
{
    /** @var list<\Throwable> */
    public array $recorded = [];

    #[\Override]
    public function recordDeadlock(\Throwable $conflict): void
    {
        $this->recorded[] = $conflict;
    }
}
