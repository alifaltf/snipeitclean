<?php

namespace Tests\Feature\Migrations;

use App\Models\Category;
use App\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coverage for 2026_09_28_000000_create_asset_category_permissions_table.
 *
 * The down()/up() round trip only runs on SQLite, where DDL is
 * transactional and rolls back with the test transaction (same approach as
 * CategoryHierarchyMigrationTest).
 */
class AssetCategoryPermissionsMigrationTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_09_28_000000_create_asset_category_permissions_table.php';

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    #[Test]
    public function it_creates_the_table_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('asset_category_permissions'));
        $this->assertTrue(Schema::hasColumns('asset_category_permissions', [
            'id', 'group_id', 'category_id', 'can_view', 'can_create', 'can_update', 'can_delete', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function it_has_the_unique_constraint_indexes_and_foreign_keys(): void
    {
        $indexes = collect(Schema::getIndexes('asset_category_permissions'))->keyBy('name');

        $this->assertTrue($indexes->has('acp_group_category_unique'));
        $this->assertTrue($indexes['acp_group_category_unique']['unique']);
        $this->assertSame(['group_id', 'category_id'], $indexes['acp_group_category_unique']['columns']);
        $this->assertTrue($indexes->has('acp_category_id_index'));

        $foreignKeys = collect(Schema::getForeignKeys('asset_category_permissions'))
            ->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => $fk]);

        $this->assertSame('permission_groups', $foreignKeys['group_id']['foreign_table']);
        $this->assertSame(['id'], $foreignKeys['group_id']['foreign_columns']);
        $this->assertSame('cascade', strtolower($foreignKeys['group_id']['on_delete']));
        $this->assertSame('categories', $foreignKeys['category_id']['foreign_table']);
        $this->assertSame('cascade', strtolower($foreignKeys['category_id']['on_delete']));
    }

    #[Test]
    public function flags_default_to_false_and_group_category_pairs_are_unique(): void
    {
        $group = Group::factory()->create();
        $category = Category::factory()->assignableAssetCategory()->create();

        DB::table('asset_category_permissions')->insert(['group_id' => $group->id, 'category_id' => $category->id]);
        $row = DB::table('asset_category_permissions')->first();
        foreach (['can_view', 'can_create', 'can_update', 'can_delete'] as $column) {
            $this->assertEquals(0, $row->{$column}, $column);
        }

        $this->expectException(QueryException::class);
        DB::table('asset_category_permissions')->insert(['group_id' => $group->id, 'category_id' => $category->id]);
    }

    #[Test]
    public function it_rolls_back_and_reapplies_cleanly(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL round trip is only transactional on SQLite.');
        }

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('asset_category_permissions'));
        $this->assertTrue(Schema::hasTable('permission_groups'), 'existing tables are untouched');
        $this->assertTrue(Schema::hasColumns('categories', ['parent_id', 'is_assignable', 'sort_order']), 'hierarchy columns are untouched');

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('asset_category_permissions'));
        $this->assertTrue(Schema::hasIndex('asset_category_permissions', 'acp_group_category_unique'));
    }
}
