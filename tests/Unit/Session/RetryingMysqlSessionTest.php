<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Session;

use Nandan108\InvFlux\Storage\Mysql\Session\RetryingMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\FakeMysqlSession;
use PHPUnit\Framework\TestCase;

final class RetryingMysqlSessionTest extends TestCase
{
    public function testDefaultCollationDelegatesToWrappedSession(): void
    {
        $inner = new FakeMysqlSession(collation: 'utf8mb4_general_ci');
        $session = new RetryingMysqlSession($inner);

        self::assertSame('utf8mb4_general_ci', $session->defaultCollation());
    }

    public function testRetriesUntilTheTransactionSucceeds(): void
    {
        $inner = new FakeMysqlSession(retryable: true);
        $conflict = new \RuntimeException('Lock wait timeout exceeded', 1205);
        $inner->conflicts = [$conflict, $conflict];

        $session = new RetryingMysqlSession($inner, maxAttempts: 5, baseDelayUs: 0, maxDelayUs: 0);

        self::assertSame('committed', $session->transactional(static fn (): string => 'committed'));
        self::assertSame(3, $inner->transactionalCalls);
    }

    public function testStopsAndRethrowsWhenPolicyRejectsTheError(): void
    {
        $inner = new FakeMysqlSession(retryable: false);
        $inner->conflicts = [new \RuntimeException('fatal, not transient')];

        $session = new RetryingMysqlSession($inner, maxAttempts: 5, baseDelayUs: 0, maxDelayUs: 0);

        try {
            $session->transactional(static fn (): mixed => null);
            self::fail('Expected the non-retryable error to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('fatal, not transient', $e->getMessage());
        }

        self::assertSame(1, $inner->transactionalCalls, 'a rejected error must not be retried');
    }

    public function testInjectedPolicyOverridesTheSessionClassifier(): void
    {
        // The wrapped session would classify this as retryable, but the injected policy vetoes it
        // (e.g. a deadlock outside production) — so it runs exactly once and propagates.
        $inner = new FakeMysqlSession(retryable: true);
        $inner->conflicts = [new \RuntimeException('Deadlock found', 1213)];

        $session = new RetryingMysqlSession(
            $inner,
            retryable: static fn (\Throwable $t): bool => $t instanceof \LogicException, // never true here → veto
            maxAttempts: 5,
            baseDelayUs: 0,
            maxDelayUs: 0,
        );

        try {
            $session->transactional(static fn (): mixed => null);
            self::fail('Expected the vetoed error to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Deadlock found', $e->getMessage());
        }

        self::assertSame(1, $inner->transactionalCalls);
    }

    public function testAnInvokablePolicyObjectIsAcceptedWithoutAdaptation(): void
    {
        // `$retryable` takes any callable, so DeadlockAwareRetryPolicy — an invokable object, and
        // the pairing this seam exists for — goes in as-is rather than via `$policy(...)`.
        $inner = new FakeMysqlSession(retryable: false);
        $conflict = new \RuntimeException('Deadlock found', 1213);
        $inner->conflicts = [$conflict, $conflict];

        $policy = new class {
            public function __invoke(\Throwable $t): bool
            {
                return 1213 === $t->getCode();
            }
        };

        $session = new RetryingMysqlSession($inner, retryable: $policy, maxAttempts: 5, baseDelayUs: 0, maxDelayUs: 0);

        self::assertSame('committed', $session->transactional(static fn (): string => 'committed'));
        self::assertSame(3, $inner->transactionalCalls, 'the object policy must drive the retry loop');
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $inner = new FakeMysqlSession(retryable: true);
        $conflict = new \RuntimeException('Lock wait timeout exceeded', 1205);
        $inner->conflicts = [$conflict, $conflict, $conflict];

        $session = new RetryingMysqlSession($inner, maxAttempts: 3, baseDelayUs: 0, maxDelayUs: 0);

        try {
            $session->transactional(static fn (): mixed => null);
            self::fail('Expected exhaustion after max attempts.');
        } catch (\RuntimeException $e) {
            self::assertSame('Lock wait timeout exceeded', $e->getMessage());
        }

        self::assertSame(3, $inner->transactionalCalls);
    }
}
