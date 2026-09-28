<?php

namespace Tests\Unit\Models;

use App\Models\AssetModel;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryHierarchyTest extends TestCase
{
    #[Test]
    public function new_categories_default_to_flat_assignable_nodes(): void
    {
        $category = Category::factory()->forAssets()->create()->refresh();

        $this->assertNull($category->parent_id);
        $this->assertTrue($category->is_assignable);
        $this->assertSame(0, $category->sort_order);
        $this->assertFalse($category->isNavigationOnly());
        $this->assertTrue($category->acceptsAssetModels());
    }

    #[Test]
    public function hierarchy_columns_are_not_mass_assignable(): void
    {
        $group = Category::factory()->navigationGroup()->create();
        $category = Category::factory()->forAssets()->create();

        $category->fill([
            'name' => 'Renamed',
            'parent_id' => $group->id,
            'is_assignable' => false,
            'sort_order' => 42,
        ])->save();

        $category->refresh();
        $this->assertSame('Renamed', $category->name);
        $this->assertNull($category->parent_id);
        $this->assertTrue($category->is_assignable);
        $this->assertSame(0, $category->sort_order);
    }

    #[Test]
    public function parent_and_children_relationships_resolve(): void
    {
        $group = Category::factory()->navigationGroup()->create();
        $second = Category::factory()->childOf($group)->create(['name' => 'Bravo Leaf', 'sort_order' => 0]);
        $first = Category::factory()->childOf($group)->create(['name' => 'Alpha Leaf', 'sort_order' => 0]);
        $last = Category::factory()->childOf($group)->create(['name' => 'Aardvark Leaf', 'sort_order' => 9]);

        $this->assertTrue($first->parent->is($group));
        $this->assertNull($group->parent);
        $this->assertSame(
            [$first->id, $second->id, $last->id],
            $group->children->pluck('id')->all(),
            'children order by sort_order, then name'
        );
    }

    #[Test]
    public function soft_deleted_nodes_are_hidden_from_relationships(): void
    {
        $group = Category::factory()->navigationGroup()->create();
        $child = Category::factory()->childOf($group)->create();
        $deletedChild = Category::factory()->childOf($group)->create();
        $deletedChild->delete();

        $this->assertSame([$child->id], $group->children()->pluck('id')->all());

        $group->delete();
        $this->assertNull($child->fresh()->parent, 'a soft-deleted parent resolves to null');
        $this->assertSame($group->id, $child->fresh()->parent_id, 'the stored pointer is left untouched');
    }

    #[Test]
    public function navigation_groups_do_not_accept_asset_models(): void
    {
        $group = Category::factory()->navigationGroup()->create();

        $this->assertTrue($group->isNavigationOnly());
        $this->assertFalse($group->acceptsAssetModels());
    }

    #[Test]
    public function trashed_final_categories_do_not_accept_asset_models(): void
    {
        $category = Category::factory()->assignableAssetCategory()->create();
        $category->delete();

        $this->assertFalse($category->acceptsAssetModels());
    }

    #[Test]
    public function non_asset_categories_are_never_navigation_nodes(): void
    {
        // Even a malformed flag must not change accessory/license/etc. behaviour.
        $license = Category::factory()->forLicenses()->create(['is_assignable' => false]);

        $this->assertFalse($license->isNavigationOnly());
        $this->assertFalse($license->acceptsAssetModels(), 'not an asset category');
    }

    #[Test]
    public function unsaved_categories_are_treated_as_assignable(): void
    {
        $category = new Category(['category_type' => 'asset']);

        $this->assertFalse($category->isNavigationOnly());
        $this->assertTrue($category->acceptsAssetModels());
    }

    #[Test]
    public function scopes_filter_by_hierarchy_role(): void
    {
        $group = Category::factory()->navigationGroup()->create();
        $leaf = Category::factory()->childOf($group)->create();
        $rootLeaf = Category::factory()->assignableAssetCategory()->create();
        $accessory = Category::factory()->forAccessories()->create();

        // Restrict to rows created here: migrations seed a default license category.
        $created = [$group->id, $leaf->id, $rootLeaf->id, $accessory->id];
        $query = fn () => Category::query()->whereIn('id', $created);

        $this->assertEqualsCanonicalizing(
            [$group->id, $leaf->id, $rootLeaf->id],
            $query()->assetCategories()->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$leaf->id, $rootLeaf->id, $accessory->id],
            $query()->assignable()->pluck('id')->all()
        );
        $this->assertSame([$group->id], $query()->navigationOnly()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$group->id, $rootLeaf->id, $accessory->id],
            $query()->rootNodes()->pluck('id')->all()
        );
    }

    #[Test]
    public function scopes_remain_unambiguous_when_joined(): void
    {
        $leaf = Category::factory()->assignableAssetCategory()->create();
        AssetModel::factory()->create(['category_id' => $leaf->id]);

        $ids = Category::assetCategories()
            ->assignable()
            ->join('models', 'models.category_id', '=', 'categories.id')
            ->pluck('categories.id')
            ->all();

        $this->assertSame([$leaf->id], $ids);
    }

    #[Test]
    public function invalid_hierarchy_values_fail_model_validation(): void
    {
        $category = Category::factory()->forAssets()->make();
        $category->parent_id = 'not-a-number';
        $this->assertFalse($category->isValid());
        $this->assertTrue($category->getErrors()->has('parent_id'));

        $category = Category::factory()->forAssets()->make();
        $category->sort_order = -1;
        $this->assertFalse($category->isValid());
        $this->assertTrue($category->getErrors()->has('sort_order'));
    }

    #[Test]
    public function empty_parent_input_is_stored_as_null(): void
    {
        $category = Category::factory()->forAssets()->make();
        $category->parent_id = '';

        $this->assertNull($category->parent_id);
        $this->assertTrue($category->save());
    }
}
