<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Schema;

use Nandan108\Attrecord\Record;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\InvFlux\Storage\Mysql\MysqlDomainStore;
use Nandan108\InvFlux\Storage\Mysql\MysqlInventoryStore;
use Nandan108\InvFlux\Storage\Mysql\Order\MysqlOrderStore;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\DiagnosticResultRow;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\DiagnosticSchedule;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\Setting;
use Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SlotSpace;

/**
 * Every Record class whose table this package owns, gathered from the three stores that declare
 * them.
 *
 * This is the *model set* handed to the schema installer, not an install script: the installer
 * derives creation order from the declared `#[ForeignKey]` graph, so nothing here is ordered and
 * nothing here executes SQL. Membership is the only thing that matters — a Record missing from
 * this list is a table nothing will create, and one the differ will never look at.
 *
 * A host application adds its own Records on top (the WooCommerce adapter aggregates this list
 * with its adapter-owned ones), which is why the set is exposed rather than converged here: only
 * the outermost layer knows the full set, and converging a partial one twice would leave the
 * first pass unable to resolve foreign keys into the second.
 *
 * Not included: the handful of tables with no Record — `invflux_slotspace` and
 * `invflux_inventory_state` (hand-written DDL in {@see MysqlInventoryStore}). They are invisible
 * to the differ, which therefore never proposes altering or dropping them.
 *
 * @api
 */
final class StorageSchema
{
    /**
     * @return list<class-string<Record>>
     */
    public static function records(): array
    {
        return [
            ...MysqlDomainStore::REFERENCE_RECORDS,
            ...MysqlInventoryStore::INVENTORY_RECORDS,
            ...MysqlDomainStore::PROCUREMENT_RECORDS,
            ...MysqlDomainStore::DOMAIN_RECORDS,
            ...MysqlOrderStore::ORDER_RECORDS,
            // Owned by no store's constant: the settings table belongs to MysqlSettingsStore,
            // which is a plain repository rather than one of the bootstrapping stores.
            Setting::class,
            DiagnosticSchedule::class,
            DiagnosticResultRow::class,
        ];
    }

    /**
     * The model set to converge: every Record above, with `invflux_slotspace` replaced by the
     * schema for the given dimensions.
     *
     * The slot space is the one table whose shape is not fully knowable from a class — it carries
     * a column per registered dimension. Passing the dimension set here is what lets those columns
     * be created and converged like any other, instead of being bolted on afterwards by an `ALTER`
     * the differ cannot see.
     *
     * @param list<string> $dimensionNames dimension names across every layer of the slot-space definition
     *
     * @return list<class-string<Record>|TableSchema>
     */
    public static function models(array $dimensionNames): array
    {
        $models = [];
        foreach (self::records() as $class) {
            $models[] = SlotSpace::class === $class
                ? SlotSpaceSchema::for($dimensionNames)
                : $class;
        }

        return $models;
    }
}
