<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Identity;

use Nandan108\InvFlux\Storage\Mysql\Identity\UuidV7Minter;
use PHPUnit\Framework\TestCase;

final class UuidV7MinterTest extends TestCase
{
    public function testMintsSixteenByteBinaryString(): void
    {
        $minter = new UuidV7Minter(1);
        $uuid = $minter->mint();

        $this->assertSame(16, \strlen($uuid));
    }

    public function testEmbedsVersionNibbleSevenInByte6(): void
    {
        $minter = new UuidV7Minter(1);
        $uuid = $minter->mint();

        // High nibble of byte 6 must be 0111 (version 7).
        $this->assertSame(0x70, \ord($uuid[6]) & 0xF0);
    }

    public function testEmbedsVariantBitsInByte8(): void
    {
        $minter = new UuidV7Minter(1);
        $uuid = $minter->mint();

        // Top 2 bits of byte 8 must be 10 (RFC 9562 variant).
        $this->assertSame(0x80, \ord($uuid[8]) & 0xC0);
    }

    public function testEmbeddedNodeIdMatchesConfiguredValue(): void
    {
        foreach ([1, 47, 256, 1024, 2047, 4095] as $nodeId) {
            $minter = new UuidV7Minter($nodeId);
            $uuid = $minter->mint();

            $this->assertSame(
                $nodeId,
                UuidV7Minter::nodeIdFrom($uuid),
                "node_id roundtrip failed for {$nodeId}",
            );
        }
    }

    public function testClosureNodeIdSourceIsNotResolvedAtConstruction(): void
    {
        // Construction must not invoke the source — a service that holds the
        // minter but never mints should pay nothing. A throwing source proves it:
        // the build is silent, and only mint() triggers (and surfaces) resolution.
        $minter = new UuidV7Minter(static function (): int {
            throw new \LogicException('node-id source resolved too early');
        });

        $this->expectException(\LogicException::class);
        $minter->mint();
    }

    public function testClosureNodeIdSourceIsResolvedOnlyOnceAndRoundtrips(): void
    {
        $spy = new class {
            public int $calls = 0;

            public function count(): int
            {
                return $this->calls;
            }
        };
        $minter = new UuidV7Minter(function () use ($spy): int {
            ++$spy->calls;

            return 1234;
        });

        $uuid = $minter->mint();
        $minter->mint();
        $minter->mint();

        $this->assertSame(1234, UuidV7Minter::nodeIdFrom($uuid), 'lazy node id must reach the UUID');
        $this->assertSame(1, $spy->count(), 'node-id source must be memoised after first mint');
    }

    public function testClosureNodeIdSourceIsValidatedOnUse(): void
    {
        $minter = new UuidV7Minter(static fn (): int => 4096);

        $this->expectException(\InvalidArgumentException::class);
        $minter->mint();
    }

    public function testTimestampApproximatesNow(): void
    {
        $before = (int) (microtime(true) * 1000.0);
        $uuid = (new UuidV7Minter(1))->mint();
        $after = (int) (microtime(true) * 1000.0);

        $ts = UuidV7Minter::timestampMsFrom($uuid);

        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testConsecutiveMintsAreUnique(): void
    {
        $minter = new UuidV7Minter(1);
        $samples = [];
        for ($i = 0; $i < 1000; ++$i) {
            $samples[] = $minter->mint();
        }

        $this->assertCount(1000, array_unique($samples));
    }

    public function testConsecutiveMintsAreApproximatelyOrdered(): void
    {
        // UUIDv7 sorts lexicographically by ms timestamp. Mints across many
        // ms boundaries should be non-decreasing when sorted byte-wise.
        $minter = new UuidV7Minter(1);
        $samples = [];
        for ($i = 0; $i < 50; ++$i) {
            $samples[] = $minter->mint();
            usleep(1000); // 1ms gap
        }

        $sorted = $samples;
        sort($sorted);
        $this->assertSame($samples, $sorted, 'UUIDv7s minted with 1ms gaps must sort in insertion order');
    }

    public function testMintMultipleReturnsRequestedCountOfUniqueValidIds(): void
    {
        $minter = new UuidV7Minter(847);
        $ids = $minter->mintMultiple(64);

        $this->assertCount(64, $ids);
        $this->assertCount(64, array_unique($ids), 'batch ids must be unique');
        foreach ($ids as $id) {
            $this->assertSame(16, \strlen($id));
            $this->assertSame(0x70, \ord($id[6]) & 0xF0, 'version 7 nibble');
            $this->assertSame(0x80, \ord($id[8]) & 0xC0, 'variant bits');
            $this->assertSame(847, UuidV7Minter::nodeIdFrom($id), 'node id preserved');
        }
    }

    public function testMintMultipleSharesOneTimestampAcrossTheBatch(): void
    {
        // The whole point: every id of one batch embeds the SAME 48-bit ms — one clock read —
        // so a movement's ledger rows all carry the movement's single instant.
        $ids = (new UuidV7Minter(1))->mintMultiple(500);

        $stamps = array_map(
            static fn (string $id): int => UuidV7Minter::timestampMsFrom($id),
            $ids,
        );
        $this->assertCount(1, array_unique($stamps), 'all batch ids must share one timestamp');
    }

    public function testMintMultipleReturnsEmptyForNonPositiveCount(): void
    {
        $minter = new UuidV7Minter(1);
        $this->assertSame([], $minter->mintMultiple(0));
        $this->assertSame([], $minter->mintMultiple(-5));
    }

    public function testRejectsZeroNodeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UuidV7Minter(0);
    }

    public function testRejectsNegativeNodeId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UuidV7Minter(-1);
    }

    public function testRejectsNodeIdAbove12BitMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UuidV7Minter(4096);
    }

    public function testNodeIdFromRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UuidV7Minter::nodeIdFrom('short');
    }

    public function testTimestampMsFromRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UuidV7Minter::timestampMsFrom('short');
    }

    public function testDifferentNodeIdsAreDistinguishableInOutput(): void
    {
        $u1 = (new UuidV7Minter(1))->mint();
        $u2 = (new UuidV7Minter(847))->mint();
        $u3 = (new UuidV7Minter(4095))->mint();

        $this->assertSame(1, UuidV7Minter::nodeIdFrom($u1));
        $this->assertSame(847, UuidV7Minter::nodeIdFrom($u2));
        $this->assertSame(4095, UuidV7Minter::nodeIdFrom($u3));
    }
}
