<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Identity;

/**
 * Mints UUIDv7 identifiers with a 12-bit `node_id` embedded in the
 * `rand_a` field.
 *
 * The full specification — bit layout, ordering properties, node_id assignment,
 * recovery helpers — is recorded in the adapter's architecture docs.
 *
 * Bit layout:
 *
 * ```
 * |                       unix_ts_ms (48 bits)                    |
 * |       ts cont.        |ver=7  |        node_id (12)           |
 * |var|                  random (62 bits)                         |
 * |                       random cont.                            |
 * ```
 *
 * The 12-bit `node_id` occupies byte 6 low nibble + byte 7. The
 * resulting UUID is a valid RFC 9562 UUIDv7 to any external parser —
 * the structure within `rand_a` is a private InvFlux interpretation
 * that v7 explicitly permits (§6.2 "implementation-defined" bits).
 *
 * No state; each call to `mint()` independently produces a fresh
 * UUID. Concurrent callers on the same node may produce same-ms
 * UUIDs whose relative ordering depends only on the random tail —
 * acceptable since events within the same ms on the same node have
 * no inherent chronological order.
 */
final class UuidV7Minter
{
    /**
     * @param int|\Closure(): int $nodeId Range 1..4095 — the install's permanent
     *                                    federation node identity, persisted in
     *                                    invflux_settings.federation.node_id. The value 0
     *                                    is reserved as an "uninitialised" sentinel.
     *
     *                    Pass a closure to defer resolution (e.g. an option read)
     *                    until the first {@see mint()} — a request that never mints
     *                    never pays for it. An int is validated eagerly; a closure's
     *                    result is validated on first use.
     */
    public function __construct(int | \Closure $nodeId)
    {
        if ($nodeId instanceof \Closure) {
            $this->nodeIdSource = $nodeId;

            return;
        }

        $this->nodeId = self::validateNodeId($nodeId);
    }

    /** @var \Closure(): int|null Deferred node-id source; null once resolved or when an int was given. */
    private ?\Closure $nodeIdSource = null;

    /** Resolved + validated node id; null until first {@see nodeId()} call in the lazy path. */
    private ?int $nodeId = null;

    /** Resolve (once) and return the validated node id. */
    private function nodeId(): int
    {
        if (null === $this->nodeId) {
            /** @var \Closure(): int $source */
            $source = $this->nodeIdSource;
            $this->nodeId = self::validateNodeId($source());
            $this->nodeIdSource = null;
        }

        return $this->nodeId;
    }

    private static function validateNodeId(int $nodeId): int
    {
        if ($nodeId < 1 || $nodeId > 0x0FFF) {
            throw new \InvalidArgumentException(\sprintf(
                'UuidV7Minter node_id must be in range [1, 4095]; got %d.',
                $nodeId,
            ));
        }

        return $nodeId;
    }

    /**
     * Mint one UUIDv7 with the configured node_id embedded in rand_a.
     *
     * @return string 16-byte binary string
     */
    public function mint(): string
    {
        return $this->mintAtMs($this->nowMs());
    }

    /**
     * Mint `$count` UUIDv7 ids that all share **one** 48-bit ms timestamp — a single clock read
     * for the whole batch. Each id still has its own 62-bit random tail, so they stay unique, but
     * every id embeds the same instant. Use this wherever a set of rows represents *one logical
     * event at one moment* (e.g. all inventory-ledger rows of a single movement), so the ids'
     * embedded time matches the row's shared `recorded_at` instead of drifting per-row across a
     * millisecond boundary.
     *
     * @return list<string> exactly `$count` 16-byte binary ids (empty when `$count <= 0`)
     */
    public function mintMultiple(int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $ms = $this->nowMs();
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $this->mintAtMs($ms);
        }

        return $ids;
    }

    /** Current time as a 48-bit ms count. `(int)(microtime(true) * 1000.0)` is fine into year 8910. */
    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000.0);
    }

    /** Build one UUIDv7 with the given ms timestamp, the configured node_id, and a fresh rand_b. */
    private function mintAtMs(int $ms): string
    {
        // 8 bytes of randomness — we'll use 62 bits for rand_b (top 2 bits
        // of byte 8 are the variant marker 0b10).
        $randB = random_bytes(8);

        // Resolve node_id once (deferred option read happens here, not at construction).
        $nodeId = $this->nodeId();

        // Pack the 6-byte big-endian timestamp.
        $bytes = \pack('J', $ms);
        // pack('J',…) produces 8 bytes; we want bytes 2..7 (the low 48 bits).
        $bytes = \substr($bytes, 2, 6);

        // Byte 6: version nibble (0111) || high 4 bits of node_id.
        $bytes .= \chr(0x70 | (($nodeId >> 8) & 0x0F));

        // Byte 7: low 8 bits of node_id.
        $bytes .= \chr($nodeId & 0xFF);

        // Byte 8: variant (10) || top 6 bits of rand_b[0].
        $bytes .= \chr(0x80 | (\ord($randB[0]) & 0x3F));

        // Bytes 9..15: rand_b[1..7].
        $bytes .= \substr($randB, 1, 7);

        return $bytes;
    }

    /**
     * Recover the embedded 12-bit node_id from a UUIDv7 minted by this
     * minter (or any UUID that follows the same rand_a encoding).
     *
     * @param string $uuidBytes 16-byte binary UUID
     *
     * @return int the recovered node_id (0..4095)
     */
    public static function nodeIdFrom(string $uuidBytes): int
    {
        if (16 !== \strlen($uuidBytes)) {
            throw new \InvalidArgumentException(\sprintf(
                'UUID bytes must be exactly 16; got %d.',
                \strlen($uuidBytes),
            ));
        }

        return ((\ord($uuidBytes[6]) & 0x0F) << 8) | \ord($uuidBytes[7]);
    }

    /**
     * Recover the embedded ms timestamp from a UUIDv7. Useful for
     * debug / display surfaces.
     *
     * @param string $uuidBytes 16-byte binary UUID
     *
     * @return int unix ms since epoch
     */
    public static function timestampMsFrom(string $uuidBytes): int
    {
        if (16 !== \strlen($uuidBytes)) {
            throw new \InvalidArgumentException(\sprintf(
                'UUID bytes must be exactly 16; got %d.',
                \strlen($uuidBytes),
            ));
        }

        // Reconstruct the 48-bit timestamp from bytes 0..5.
        // pack('J', $x) wants 8 bytes; prepend two zero bytes for the high 16 bits.
        $padded = "\x00\x00".\substr($uuidBytes, 0, 6);
        $unpacked = \unpack('J', $padded);
        if (false === $unpacked || !isset($unpacked[1])) {
            return 0;
        }

        return (int) $unpacked[1];
    }
}
