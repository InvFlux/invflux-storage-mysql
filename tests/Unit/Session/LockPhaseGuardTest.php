<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Session;

use Nandan108\InvFlux\Exceptions\PersistenceException;
use Nandan108\InvFlux\Storage\Mysql\Session\LockPhaseGuard;
use Nandan108\InvFlux\Storage\Mysql\Session\RetryingMysqlSession;
use Nandan108\InvFlux\Storage\Mysql\Tests\Support\FakeMysqlSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see LockPhaseGuard}'s classification and phase lifecycle.
 *
 * The boundary this guard protects is proven against a real database in
 * `Tests\Integration\LockOrderBoundaryTest`; what is worth testing here is the part that has no
 * database in it — which statements count as which phase, and when the phase resets.
 */
final class LockPhaseGuardTest extends TestCase
{
    private const ENTITY_LOCK = 'SELECT * FROM `invflux_pos` WHERE `id` IN (?) ORDER BY `id` ASC FOR UPDATE';
    private const INVENTORY_LOCK = 'SELECT slot_id, quantity FROM wp_invflux_inventory_state WHERE subject_id = ? FOR UPDATE';

    public function testEntityLockBeforeInventoryLockIsAllowed(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());

        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll(self::ENTITY_LOCK);
            $guard->fetchAll(self::INVENTORY_LOCK);
        });

        $this->expectNotToPerformAssertions();
    }

    public function testEntityLockAfterInventoryLockThrows(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());

        try {
            $guard->transactional(function () use ($guard): void {
                $guard->fetchAll(self::INVENTORY_LOCK);
                $guard->fetchAll(self::ENTITY_LOCK);
            });
            self::fail('Expected the inverted lock order to be rejected.');
        } catch (PersistenceException $e) {
            self::assertSame('lock_order_violation', $e->detailCode);
            self::assertStringContainsString('invflux_pos', (string) ($e->context['sql'] ?? ''));
        }
    }

    /**
     * The host table prefix must not hide an inventory lock — if it did, the guard would classify
     * the inventory read as an entity read and the ordering would be enforced backwards.
     */
    public function testInventoryTableIsRecognisedUnderAnyHostPrefix(): void
    {
        foreach (['invflux_inventory_state', 'wp_invflux_inventory_state', 'wp_7_invflux_inventory_state'] as $table) {
            $guard = new LockPhaseGuard(new FakeMysqlSession());

            $this->expectViolation($guard, sprintf('SELECT quantity FROM %s WHERE subject_id = 1 FOR UPDATE', $table));
        }
    }

    /** Non-locking reads are not phase transitions, however much they mention the table. */
    public function testPlainReadsAndWritesAreNotPhaseTransitions(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());

        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll('SELECT quantity FROM wp_invflux_inventory_state WHERE subject_id = 1');
            $guard->exec('UPDATE wp_invflux_inventory_state SET quantity = 5 WHERE subject_id = 1');
            // Still the entity phase, so this remains legal.
            $guard->fetchAll(self::ENTITY_LOCK);
        });

        $this->expectNotToPerformAssertions();
    }

    /** A locking read outside any transaction cannot join a cycle, and must not latch the phase on. */
    public function testLocksOutsideATransactionAreIgnored(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());

        $guard->fetchAll(self::INVENTORY_LOCK);

        // If the phase had latched, this would now throw.
        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll(self::ENTITY_LOCK);
        });

        $this->expectNotToPerformAssertions();
    }

    /** Each outermost transaction starts clean; a previous one's inventory phase must not leak. */
    public function testPhaseResetsBetweenTransactions(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());

        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll(self::INVENTORY_LOCK);
        });

        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll(self::ENTITY_LOCK);
        });

        $this->expectNotToPerformAssertions();
    }

    /**
     * The placement invariant, as a test.
     *
     * With `Retrying(Guard(session))` a retry re-enters the guard's `transactional()` and gets a
     * clean phase. Wrapped the other way round the flag would survive the failed attempt and the
     * retry would be rejected for a violation that never happened — so this asserts the ordering
     * the class docblock insists on, rather than trusting it.
     *
     * @psalm-suppress UnevaluatedCode Psalm reads the retry loop's conditional `throw` as
     *                                 unconditional and concludes nothing after the call runs. It
     *                                 does — the closure throws on the first attempt only, and the
     *                                 assertion below is what proves the second one happened.
     */
    public function testRetryAttemptGetsACleanPhase(): void
    {
        $guard = new LockPhaseGuard(new FakeMysqlSession());
        $retrying = new RetryingMysqlSession(
            $guard,
            static fn (\Throwable $e): bool => $e instanceof PersistenceException && 'deadlock' === $e->detailCode,
            maxAttempts: 3,
            baseDelayUs: 0,
            maxDelayUs: 0,
        );

        $attempts = 0;
        /** @psalm-var bool $loseTheTransaction the closure flips this, so it is not the constant psalm folds it to */
        $loseTheTransaction = true;
        $retrying->transactional(function () use ($guard, &$attempts, &$loseTheTransaction): void {
            ++$attempts;

            if ($loseTheTransaction) {
                // Attempt 1 reaches the inventory phase, then loses the transaction to a deadlock.
                $loseTheTransaction = false;
                $guard->fetchAll(self::INVENTORY_LOCK);

                throw new PersistenceException('rolled back', 'deadlock');
            }

            // Attempt 2 starts over in the correct order. This entity lock is legal *only* if the
            // phase from attempt 1 was cleared — which is the invariant under test.
            $guard->fetchAll(self::ENTITY_LOCK);
            $guard->fetchAll(self::INVENTORY_LOCK);
        });

        self::assertSame(2, $attempts, 'The operation should have been retried exactly once.');
    }

    /** Production records instead of throwing — a possible deadlock beats a certain fatal. */
    public function testNonThrowingModeRecordsAndLetsTheStatementThrough(): void
    {
        $seen = [];
        $guard = new LockPhaseGuard(
            new FakeMysqlSession(),
            throwOnViolation: false,
            onViolation: static function (string $message, string $sql) use (&$seen): void {
                $seen[] = [$message, $sql];
            },
        );

        $guard->transactional(function () use ($guard): void {
            $guard->fetchAll(self::INVENTORY_LOCK);
            $guard->fetchAll(self::ENTITY_LOCK);
        });

        self::assertCount(1, $seen);
        self::assertStringContainsString('Lock-order violation', $seen[0][0]);
    }

    private function expectViolation(LockPhaseGuard $guard, string $inventoryLockSql): void
    {
        try {
            $guard->transactional(function () use ($guard, $inventoryLockSql): void {
                $guard->fetchAll($inventoryLockSql);
                $guard->fetchAll(self::ENTITY_LOCK);
            });
            self::fail(sprintf('Expected a violation after the inventory lock: %s', $inventoryLockSql));
        } catch (PersistenceException $e) {
            self::assertSame('lock_order_violation', $e->detailCode);
        }
    }
}
