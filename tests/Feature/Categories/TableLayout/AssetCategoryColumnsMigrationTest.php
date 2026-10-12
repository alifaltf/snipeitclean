<?php

namespace Tests\Feature\Categories\TableLayout;

use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetCategoryColumnsMigrationTest extends TestCase
{
    use BuildsTableLayoutFixtures;

    private const MIGRATION = 'database/migrations/2026_10_12_000000_create_asset_category_columns_table.php';

    private function row(Category $category, string $key, int $position): array
    {
        return ['category_id' => $category->id, 'field_key' => $key, 'position' => $position, 'created_at' => now(), 'updated_at' => now()];
    }

    #[Test]
    public function the_table_has_the_expected_columns_and_nothing_else(): void
    {
        $this->assertTrue(Schema::hasTable('asset_category_columns'));
        $columns = Schema::getColumnListing('asset_category_columns');
        sort($columns);
        $this->assertSame(['category_id', 'created_at', 'field_key', 'id', 'position', 'updated_at'], $columns);
    }

    #[Test]
    public function a_category_cannot_store_the_same_field_or_position_twice(): void
    {
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        DB::table('asset_category_columns')->insert($this->row($category, 'standard:asset_tag', 1));

        foreach ([$this->row($category, 'standard:asset_tag', 2), $this->row($category, 'standard:name', 1)] as $duplicate) {
            try {
                DB::table('asset_category_columns')->insert($duplicate);
                $this->fail('The database accepted a duplicate.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Other categories are independent.
        DB::table('asset_category_columns')->insert($this->row($other, 'standard:asset_tag', 1));
        $this->assertSame(2, DB::table('asset_category_columns')->count());
    }

    #[Test]
    public function the_category_foreign_key_cascades_on_delete(): void
    {
        $keys = collect(Schema::getForeignKeys('asset_category_columns'));
        $this->assertCount(1, $keys);
        $key = $keys->first();
        $this->assertSame(['category_id'], $key['columns']);
        $this->assertSame('categories', $key['foreign_table']);
        $this->assertSame(['id'], $key['foreign_columns']);
        $this->assertSame('cascade', strtolower($key['on_delete']));

        $indexes = collect(Schema::getIndexes('asset_category_columns'))->keyBy('name');
        $this->assertTrue($indexes['acc_category_field_unique']['unique']);
        $this->assertSame(['category_id', 'field_key'], $indexes['acc_category_field_unique']['columns']);
        $this->assertTrue($indexes['acc_category_position_unique']['unique']);
        $this->assertSame(['category_id', 'position'], $indexes['acc_category_position_unique']['columns']);
        $this->assertSame(['category_id'], $indexes['acc_category_id_index']['columns']);
    }

    #[Test]
    public function purging_a_category_removes_only_its_layout_where_foreign_keys_are_enforced(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite test connections do not enforce foreign keys; the declaration is checked above.');
        }
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        DB::table('asset_category_columns')->insert([$this->row($category, 'standard:asset_tag', 1), $this->row($other, 'standard:asset_tag', 1)]);

        $category->forceDelete();

        $this->assertSame(0, DB::table('asset_category_columns')->where('category_id', $category->id)->count());
        $this->assertSame(1, DB::table('asset_category_columns')->where('category_id', $other->id)->count());
    }

    #[Test]
    public function the_migration_rolls_back_and_reapplies_cleanly(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MySQL/MariaDB commit DDL inside the test transaction; rollback is covered by tests/Concurrency/CategoryTableLayoutConcurrencyTest.');
        }
        $migration = require base_path(self::MIGRATION);

        $migration->down();
        $this->assertFalse(Schema::hasTable('asset_category_columns'));
        $this->assertTrue(Schema::hasTable('categories'), 'Nothing else is dropped.');

        $migration->up();
        $this->assertTrue(Schema::hasTable('asset_category_columns'));
    }
}
