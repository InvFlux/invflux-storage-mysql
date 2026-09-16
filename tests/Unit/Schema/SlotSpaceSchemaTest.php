<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Storage\Mysql\Tests\Unit\Schema;

use Nandan108\Attrecord\Record as AttrecordRecord;
use Nandan108\Attrecord\Schema\TableSchema;
use Nandan108\InvFlux\Storage\Mysql\Schema\SlotSpaceSchema;
use Nandan108\InvFlux\Storage\Mysql\Schema\StorageSchema;
use PHPUnit\Framework\TestCase;

/**
 * The half of `invflux_slotspace` that no class can declare: one column and index per registered
 * dimension.
 */
final class SlotSpaceSchemaTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        AttrecordRecord::setTablePrefix('');
        TableSchema::clearCache();
    }

    public function testEachDimensionContributesAColumnAndAnIndex(): void
    {
        $schema = SlotSpaceSchema::for(['stt', 'loc']);

        self::assertContains('dim_stt', $schema->columnNames());
        self::assertContains('dim_loc', $schema->columnNames());
        // Active-first: every read filters on `active` before the dimension value.
        self::assertSame(['active', 'dim_stt', 'id'], $schema->indexes['idx_active_stt']);
        self::assertSame(['active', 'dim_loc', 'id'], $schema->indexes['idx_active_loc']);
    }

    public function testTheDeclaredHalfIsStillThere(): void
    {
        $schema = SlotSpaceSchema::for(['stt']);

        self::assertSame('invflux_slotspace', $schema->tableName);
        self::assertSame('id', $schema->pk);
        self::assertContains('slot_key', $schema->columnNames());
        self::assertArrayHasKey('uniq_slot_key', $schema->uniqueKeys);
        self::assertNotSame([], $schema->foreignKeys, 'the layer FK survives derivation');
    }

    /**
     * A dimension appears in more than one layer (`loc` is in both the commercial and physical
     * layers) but is one column — so the caller may pass duplicates without producing a collision.
     */
    public function testDuplicateDimensionNamesCollapse(): void
    {
        $schema = SlotSpaceSchema::for(['loc', 'loc', 'stt']);

        self::assertSame(
            1,
            count(array_filter($schema->columnNames(), static fn (string $c): bool => 'dim_loc' === $c)),
        );
    }

    /**
     * Adding a dimension has to be a *Safe* change for an upgrade to apply it unattended, which
     * requires a default: existing rows need a value without a backfill.
     */
    public function testDimensionColumnsCarryADefaultSoTheyCanBeAddedUnattended(): void
    {
        $column = SlotSpaceSchema::for(['stt'])->column('dim_stt');

        self::assertFalse($column->nullable);
        self::assertSame('', $column->default);
    }

    public function testNoDimensionsYieldsJustTheDeclaredTable(): void
    {
        $bare = SlotSpaceSchema::for([]);

        self::assertSame(
            TableSchema::fromClass(\Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SlotSpace::class)->columnNames(),
            $bare->columnNames(),
        );
    }

    /**
     * The model set swaps the class out for the built schema — nothing else changes, and the
     * count stays put, because it is the same table either way.
     */
    public function testModelsSubstitutesTheBuiltSlotSpaceSchema(): void
    {
        $models = StorageSchema::models(['stt', 'loc']);

        self::assertCount(count(StorageSchema::records()), $models);

        $built = array_values(array_filter($models, static fn (mixed $m): bool => $m instanceof TableSchema));
        self::assertCount(1, $built, 'exactly one model is a built schema');
        self::assertSame('invflux_slotspace', $built[0]->tableName);
        self::assertNotContains(
            \Nandan108\InvFlux\Storage\Mysql\Schema\Ddl\SlotSpace::class,
            $models,
            'the class must not also appear — it would plan the same table twice, without its dimension columns',
        );
    }
}
