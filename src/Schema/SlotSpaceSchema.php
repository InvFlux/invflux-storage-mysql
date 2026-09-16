<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema;

use Nandan108\Attrecord\Enum\ColumnType;
use Nandan108\Attrecord\Schema\ColumnDefinition;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SlotSpace;

/**
 * The schema of `invflux_slotspace`, which is only half a declaration.
 *
 * The fixed half is the {@see SlotSpace} Record. The other half is one `dim_<name>` column plus a
 * matching index **per registered dimension** — `loc`, `stt`, and whatever an add-on registers —
 * a set that exists in the slot-space definition, not in any class.
 *
 * Describing them here puts the whole table under the same rules as every other — created on a
 * fresh install, added when the dimension set grows, and reported when they drift. Columns left
 * undescribed are invisible to schema tooling: never created by convergence, never diffed, never
 * part of the fingerprint.
 *
 * This class is also the single source of the *naming*: the raw-SQL read/write paths in
 * MysqlInventoryStore address these columns by name, so the names the schema declares and the
 * names those queries use have to be the same string.
 *
 * @api
 */
final class SlotSpaceSchema
{
    /** Width of a dimension-value column — matches `invflux_dimension_values.code`. */
    private const VALUE_LENGTH = 64;

    /** Column holding this slot's value for one dimension. */
    public static function columnFor(string $dimensionName): string
    {
        return 'dim_'.$dimensionName;
    }

    /**
     * Index covering one dimension column, active-first.
     *
     * Every read filters on `active` before the dimension value, so leading with it lets one index
     * serve both the active-slot scan and the per-dimension lookup.
     */
    public static function indexFor(string $dimensionName): string
    {
        return 'idx_active_'.$dimensionName;
    }

    /**
     * The full schema for a given set of registered dimensions.
     *
     * @param list<string> $dimensionNames dimension names across every layer, deduplicated
     */
    public static function for(array $dimensionNames): TableSchema
    {
        $columns = [];
        $indexes = [];
        foreach (array_unique($dimensionNames) as $name) {
            $column = self::columnFor($name);
            $columns[$column] = new ColumnDefinition(
                name: $column,
                // No PHP property backs these — nothing reads them off a Record instance; the
                // column name stands in so the definition is self-consistent.
                propertyName: $column,
                type: ColumnType::VarChar,
                nullable: false,
                autoIncrement: false,
                trimOnSave: null,
                length: self::VALUE_LENGTH,
                precision: null,
                scale: null,
                // A default is what makes adding a dimension a *Safe* change: existing rows get a
                // value without a backfill, so convergence can apply it unattended.
                default: '',
            );
            $indexes[self::indexFor($name)] = ['active', $column, 'id'];
        }

        return TableSchema::fromClass(SlotSpace::class)->extendedWith(columns: $columns, indexes: $indexes);
    }
}
