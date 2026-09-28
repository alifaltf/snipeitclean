<?php

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Services\AssetCategoryTree;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Database-backed tests for AssetCategoryTree::load(). The structure below
 * mirrors the approved ERS initial hierarchy purely as realistic test data;
 * application code never references these names or their IDs.
 */
class AssetCategoryTreeLoadTest extends TestCase
{
    /** @return array<string, Category> keyed by name */
    private function createErsHierarchy(): array
    {
        $nodes = [];
        $nodes['Fixed'] = Category::factory()->navigationGroup()->create(['name' => 'Fixed']);

        $structure = [
            'Hardware' => ['Laptop', 'Desktop', 'Monitor', 'Phone'],
            'Furniture' => ['Chair'],
            'Equipment' => ['Drone', 'Project Equipment'],
            'Software' => ['Digital Systems'],
        ];

        $groupOrder = 0;
        foreach ($structure as $group => $leaves) {
            $nodes[$group] = Category::factory()->navigationGroup()->childOf($nodes['Fixed'])
                ->create(['name' => $group, 'sort_order' => $groupOrder++]);

            $leafOrder = 0;
            foreach ($leaves as $leaf) {
                $nodes[$leaf] = Category::factory()->assignableAssetCategory()->childOf($nodes[$group])
                    ->create(['name' => $leaf, 'sort_order' => $leafOrder++]);
            }
        }

        return $nodes;
    }

    private static function names(array $nodes): array
    {
        return array_map(fn ($node) => $node->name, $nodes);
    }

    #[Test]
    public function it_loads_an_empty_tree_from_an_empty_database(): void
    {
        $this->assertSame(0, AssetCategoryTree::load()->count());
    }

    #[Test]
    public function it_loads_the_initial_ers_hierarchy(): void
    {
        $nodes = $this->createErsHierarchy();

        $tree = AssetCategoryTree::load();

        $this->assertSame(13, $tree->count());
        $this->assertSame(['Fixed'], self::names($tree->roots()));
        $this->assertSame(
            ['Hardware', 'Furniture', 'Equipment', 'Software'],
            self::names($tree->children($nodes['Fixed']->id))
        );
        $this->assertSame(
            ['Laptop', 'Desktop', 'Monitor', 'Phone'],
            self::names($tree->children($nodes['Hardware']->id))
        );
        $this->assertSame(
            ['Fixed', 'Equipment', 'Project Equipment'],
            self::names($tree->path($nodes['Project Equipment']->id))
        );
        $this->assertSame(3, $tree->depth($nodes['Digital Systems']->id));
        $this->assertSame(3, $tree->height($nodes['Fixed']->id));
        $this->assertTrue($tree->isNavigationOnly($nodes['Software']->id));
        $this->assertTrue($tree->isAssignable($nodes['Digital Systems']->id));
        $this->assertCount(8, $tree->assignableIdsWithin($nodes['Fixed']->id));
        $this->assertEqualsCanonicalizing(
            [$nodes['Drone']->id, $nodes['Project Equipment']->id],
            $tree->assignableIdsWithin($nodes['Equipment']->id)
        );
        $this->assertSame([], $tree->detached());
    }

    #[Test]
    public function it_loads_the_whole_tree_with_a_single_query(): void
    {
        $this->createErsHierarchy();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $tree = AssetCategoryTree::load();
        $tree->flatten();
        $tree->assignableIds();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    #[Test]
    public function non_asset_categories_are_excluded(): void
    {
        $assetLeaf = Category::factory()->assignableAssetCategory()->create();
        $license = Category::factory()->forLicenses()->create();
        Category::factory()->forAccessories()->create();
        Category::factory()->forConsumables()->create();
        Category::factory()->forComponents()->create();

        $tree = AssetCategoryTree::load();

        $this->assertSame(1, $tree->count());
        $this->assertTrue($tree->has($assetLeaf->id));
        $this->assertFalse($tree->has($license->id));
    }

    #[Test]
    public function asset_nodes_pointing_at_a_non_asset_parent_are_detached(): void
    {
        $license = Category::factory()->forLicenses()->create(['is_assignable' => false]);
        $leaf = Category::factory()->assignableAssetCategory()->create(['parent_id' => $license->id]);

        $tree = AssetCategoryTree::load();

        $this->assertSame([$leaf->id => AssetCategoryTree::DETACHED_MISSING_PARENT], $tree->detached());
        $this->assertTrue($tree->node($leaf->id)->isRoot());
    }

    #[Test]
    public function soft_deleted_nodes_are_excluded_and_their_children_detached(): void
    {
        $group = Category::factory()->navigationGroup()->create();
        $subGroup = Category::factory()->navigationGroup()->childOf($group)->create();
        $leaf = Category::factory()->assignableAssetCategory()->childOf($subGroup)->create();
        $deletedLeaf = Category::factory()->assignableAssetCategory()->childOf($subGroup)->create();

        $deletedLeaf->delete();
        $group->delete();

        $tree = AssetCategoryTree::load();

        $this->assertFalse($tree->has($group->id));
        $this->assertFalse($tree->has($deletedLeaf->id));
        $this->assertSame([$subGroup->id => AssetCategoryTree::DETACHED_MISSING_PARENT], $tree->detached());
        $this->assertSame([$leaf->id], $tree->assignableIdsWithin($subGroup->id));
    }

    #[Test]
    public function malformed_stored_data_never_breaks_loading(): void
    {
        // Rows written directly to the table (bypassing application rules)
        // to simulate corruption: a cycle and a leaf with a child.
        $a = Category::factory()->navigationGroup()->create();
        $b = Category::factory()->navigationGroup()->create();
        $leaf = Category::factory()->assignableAssetCategory()->create();
        $underLeaf = Category::factory()->assignableAssetCategory()->create();

        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);
        DB::table('categories')->where('id', $b->id)->update(['parent_id' => $a->id]);
        DB::table('categories')->where('id', $underLeaf->id)->update(['parent_id' => $leaf->id]);

        $tree = AssetCategoryTree::load();

        $this->assertSame(4, $tree->count());
        $this->assertSame([
            $a->id => AssetCategoryTree::DETACHED_CYCLE,
            $b->id => AssetCategoryTree::DETACHED_CYCLE,
            $underLeaf->id => AssetCategoryTree::DETACHED_PARENT_NOT_NAVIGATION,
        ], $tree->detached());
        $this->assertCount(4, $tree->roots());
    }
}
