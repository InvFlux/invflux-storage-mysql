<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema\Ddl;

use Nandan108\Attrecord\Attribute\Column;
use Nandan108\Attrecord\Attribute\Index;
use Nandan108\Attrecord\Attribute\Table;
use Nandan108\Attrecord\Caster\EnumCaster;
use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Record;
use Nandan108\InvFlux\Settings\ReplicationPolicy;

/**
 * `invflux_settings` — one row per named setting, keyed by name.
 *
 * Unlike the other Records in this namespace this one is **not** DDL-only: the store reads and
 * writes through it. The table was hand-written `CREATE TABLE IF NOT EXISTS` for historical
 * reasons rather than technical ones — single-column primary key, no hot path, no composite
 * key — which meant it sat outside the managed schema and the differ could not see it drift.
 *
 * The `ENUM` member lists are derived from {@see ReplicationPolicy} by the caster rather than
 * spelled out in DDL, so adding a policy is a change in one place. (Widening an enum is a Safe
 * change the differ now applies on every backend.)
 *
 * @psalm-suppress PossiblyUnusedProperty Written through attrecord; read back by hydration.
 */
#[Table(name: 'invflux_settings', primaryKey: 'name')]
#[Index('idx_replication', columns: ['replication_policy'])]
final class Setting extends Record
{
    #[Column(ColumnType::VarChar, length: 128)]
    public string $name = '';

    /** JSON-encoded setting value; the store owns encoding and decoding. */
    #[Column(ColumnType::Json)]
    public string $value_json = '';

    /** Effective policy — the merchant's choice when one is allowed, else the definition's default. */
    #[Column(ColumnType::Enum)]
    #[EnumCaster(ReplicationPolicy::class)]
    public ReplicationPolicy $replication_policy = ReplicationPolicy::Local;

    /** True when the definition forbids the merchant from choosing, so the policy is not theirs to change. */
    #[Column(ColumnType::Bool, default: 0)]
    public bool $policy_locked = false;

    /**
     * The merchant's explicit choice, or null when they never made one or are not allowed to.
     * Kept distinct from `replication_policy` so a locked-then-unlocked setting can restore what
     * the merchant had asked for rather than silently inheriting the default.
     */
    #[Column(ColumnType::Enum, nullable: true)]
    #[EnumCaster(ReplicationPolicy::class)]
    public ?ReplicationPolicy $merchant_choice = null;
}
