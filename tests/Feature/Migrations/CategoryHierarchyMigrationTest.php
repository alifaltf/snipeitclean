<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for 2026_09_25_000000_add_hierarchy_columns_to_categories_table.
 *
 * The schema/default assertions run on every driver. The down()/up()
 * round trip only runs on SQLite, where DDL is transactional and is rolled
 * back with the test transaction; on MySQL/MariaDB DDL commits implicitly
 * and would break RefreshDatabase isolation, so rollback there is verified
 * manually against a backup copy instead.
 */
class CategoryHierarchyMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_09_25_000000_add_hierarchy_columns_to_categories_table.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function insertLegacyCategory(string $name, string $type): int
    {
        // Only pre-existing columns: simulates a row written before this
        // migration, so the new columns must take their defaults.
        return DB::table('categories')->insertGetId([
            'name' => $name,
            'category_type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_adds_the_hierarchy_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('categories', ['parent_id', 'is_assignable', 'sort_order']));
        $this->assertTrue(Schema::hasIndex('categories', 'categories_parent_id_index'));
        $this->assertTrue(Schema::hasIndex('categories', 'categories_type_parent_index'));
    }

    #[Test]
    public function existing_rows_become_flat_assignable_categories_without_classification(): void
    {
        $ids = [];
        foreach (['asset', 'accessory', 'consumable', 'component', 'license'] as $type) {
            $ids[$type] = $this->insertLegacyCategory("Legacy $type", $type);
        }

        foreach ($ids as $type => $id) {
            $row = DB::table('categories')->find($id);
            $this->assertNull($row->parent_id, "$type parent_id");
            $this->assertEquals(1, $row->is_assignable, "$type is_assignable");
            $this->assertEquals(0, $row->sort_order, "$type sort_order");
            $this->assertSame($type, $row->category_type, "$type category_type unchanged");
        }
    }

    #[Test]
    public function it_rolls_back_and_reapplies_cleanly_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Schema round trip only runs on SQLite (transactional DDL).');
        }

        $id = $this->insertLegacyCategory('Round Trip', 'asset');
        DB::table('categories')->where('id', $id)->update(['sort_order' => 7, 'is_assignable' => false]);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('categories', 'parent_id'));
        $this->assertFalse(Schema::hasColumn('categories', 'is_assignable'));
        $this->assertFalse(Schema::hasColumn('categories', 'sort_order'));
        $this->assertFalse(Schema::hasIndex('categories', 'categories_parent_id_index'));
        $this->assertFalse(Schema::hasIndex('categories', 'categories_type_parent_index'));
        $this->assertSame('Round Trip', DB::table('categories')->find($id)->name, 'other data survives rollback');

        $this->migration()->up();

        $row = DB::table('categories')->find($id);
        $this->assertNull($row->parent_id);
        $this->assertEquals(1, $row->is_assignable, 'hierarchy data is discarded by rollback, defaults re-apply');
        $this->assertEquals(0, $row->sort_order);
        $this->assertTrue(Schema::hasIndex('categories', 'categories_type_parent_index'));
    }
}
