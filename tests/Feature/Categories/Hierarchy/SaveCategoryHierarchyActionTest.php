<?php

namespace Tests\Feature\Categories\Hierarchy;

use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Models\Accessory;
use App\Models\AssetModel;
use App\Models\Category;
use App\Services\AssetCategoryTree;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Structural rules enforced by the centralized hierarchy write action.
 * Runs as a Super User; authorization is covered separately.
 */
class SaveCategoryHierarchyActionTest extends TestCase
{
    use CreatesCategoryHierarchy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->superUser());
    }

    private function newAssetCategory(string $name): Category
    {
        $category = new Category;
        $category->name = $name;
        $category->category_type = 'asset';

        return $category;
    }

    private function save(Category $category, array $input): Category
    {
        return SaveCategoryHierarchyAction::run($category, $input);
    }

    /** Assert run() fails with exactly this translated message on $field. */
    private function assertRejected(Category $category, array $input, string $field, string $key, array $replace = []): void
    {
        try {
            $this->save($category, $input);
        } catch (ValidationException $e) {
            $this->assertSame([trans($key, $replace)], $e->errors()[$field] ?? null, 'Unexpected errors: '.json_encode($e->errors()));

            return;
        }

        $this->fail("Expected a validation error on {$field}.");
    }

    private function stored(Category $category): Category
    {
        return Category::withTrashed()->findOrFail($category->id);
    }

    // ---------------------------------------------------------------
    // Create, edit, move, reorder
    // ---------------------------------------------------------------

    #[Test]
    public function it_creates_a_top_level_navigation_group(): void
    {
        $group = $this->save($this->newAssetCategory('Fixed'), ['parent_id' => '', 'is_assignable' => '0', 'sort_order' => '3']);

        $stored = $this->stored($group);
        $this->assertNull($stored->parent_id);
        $this->assertFalse($stored->is_assignable);
        $this->assertSame(3, $stored->sort_order);
        $this->assertTrue($stored->isNavigationOnly());
    }

    #[Test]
    public function it_creates_a_final_child_under_a_group(): void
    {
        $group = $this->group('Hardware');

        $laptop = $this->save($this->newAssetCategory('Laptop'), ['parent_id' => (string) $group->id, 'is_assignable' => '1']);

        $stored = $this->stored($laptop);
        $this->assertSame($group->id, $stored->parent_id);
        $this->assertTrue($stored->is_assignable);
        $this->assertSame(['Hardware', 'Laptop'], array_map(fn ($n) => $n->name, AssetCategoryTree::load()->path($laptop->id)));
    }

    #[Test]
    public function new_categories_without_hierarchy_input_are_flat_final_categories(): void
    {
        $category = $this->save($this->newAssetCategory('Plain'), []);

        $stored = $this->stored($category);
        $this->assertNull($stored->parent_id);
        $this->assertTrue($stored->is_assignable);
        $this->assertSame(0, $stored->sort_order);
    }

    #[Test]
    public function editing_other_fields_keeps_the_existing_hierarchy(): void
    {
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group, 4);

        $laptop->name = 'Laptops';
        $this->save($laptop, []);

        $stored = $this->stored($laptop);
        $this->assertSame('Laptops', $stored->name);
        $this->assertSame($group->id, $stored->parent_id);
        $this->assertSame(4, $stored->sort_order);
    }

    #[Test]
    public function it_moves_a_subtree_to_another_group_and_to_the_top_level(): void
    {
        $fixed = $this->group('Fixed');
        $other = $this->group('Other');
        $hardware = $this->group('Hardware', $fixed);
        $laptop = $this->finalCategory('Laptop', $hardware);

        $this->save($hardware, ['parent_id' => $other->id]);
        $this->assertSame($other->id, $this->stored($hardware)->parent_id);
        $this->assertSame(['Other', 'Hardware', 'Laptop'], array_map(fn ($n) => $n->name, AssetCategoryTree::load()->path($laptop->id)));

        $this->save($this->stored($hardware), ['parent_id' => null]);
        $this->assertNull($this->stored($hardware)->parent_id);
    }

    #[Test]
    public function it_reorders_siblings(): void
    {
        $group = $this->group('Hardware');
        $a = $this->finalCategory('A', $group, 0);
        $b = $this->finalCategory('B', $group, 1);

        $this->save($a, ['sort_order' => 5]);

        $this->assertSame([$b->id, $a->id], array_map(fn ($n) => $n->id, AssetCategoryTree::load()->children($group->id)));
    }

    // ---------------------------------------------------------------
    // Parent validation
    // ---------------------------------------------------------------

    public static function malformedParentValues(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-3],
            'text' => ['abc'],
            'decimal string' => ['1.5'],
            'float' => [2.0],
            'array' => [[1]],
            'boolean' => [true],
        ];
    }

    #[Test]
    #[DataProvider('malformedParentValues')]
    public function it_rejects_malformed_parent_values(mixed $value): void
    {
        $this->assertRejected($this->newAssetCategory('X'), ['parent_id' => $value], 'parent_id', 'admin/categories/message.hierarchy.invalid_parent');
        $this->assertFalse(Category::where('name', 'X')->exists());
    }

    #[Test]
    public function it_rejects_a_missing_parent(): void
    {
        $this->assertRejected($this->newAssetCategory('X'), ['parent_id' => 999999], 'parent_id', 'admin/categories/message.hierarchy.parent_not_found');
    }

    #[Test]
    public function it_rejects_a_soft_deleted_parent(): void
    {
        $group = $this->group('Gone');
        $group->delete();

        $this->assertRejected($this->newAssetCategory('X'), ['parent_id' => $group->id], 'parent_id', 'admin/categories/message.hierarchy.parent_not_found');
    }

    #[Test]
    public function it_rejects_a_non_asset_parent(): void
    {
        $accessoryCategory = Category::factory()->forAccessories()->create(['is_assignable' => false]);

        $this->assertRejected($this->newAssetCategory('X'), ['parent_id' => $accessoryCategory->id], 'parent_id', 'admin/categories/message.hierarchy.parent_not_asset');
    }

    #[Test]
    public function it_rejects_a_final_category_as_parent(): void
    {
        $laptop = $this->finalCategory('Laptop');

        $this->assertRejected($this->newAssetCategory('X'), ['parent_id' => $laptop->id], 'parent_id', 'admin/categories/message.hierarchy.parent_not_navigation');
    }

    #[Test]
    public function it_rejects_the_category_as_its_own_parent(): void
    {
        $group = $this->group('Hardware');

        $this->assertRejected($group, ['parent_id' => $group->id], 'parent_id', 'admin/categories/message.hierarchy.parent_is_self');
        $this->assertNull($this->stored($group)->parent_id);
    }

    #[Test]
    public function it_rejects_a_descendant_as_parent(): void
    {
        $top = $this->group('Top');
        $child = $this->group('Child', $top);
        $grandchild = $this->group('Grandchild', $child);

        $this->assertRejected($top, ['parent_id' => $child->id], 'parent_id', 'admin/categories/message.hierarchy.parent_is_descendant');
        $this->assertRejected($this->stored($top), ['parent_id' => $grandchild->id], 'parent_id', 'admin/categories/message.hierarchy.parent_is_descendant');
        $this->assertNull($this->stored($top)->parent_id);
    }

    #[Test]
    public function it_rejects_a_descendant_that_the_tree_view_had_detached(): void
    {
        // Nine stored levels: the ninth is detached (max_depth) in the tree
        // view, but its stored ancestry still leads back to the root.
        $chain = $this->groupChain(8);
        $ninth = Category::factory()->navigationGroup()->childOf($chain[7])->create(['name' => 'Level 9']);
        $this->assertArrayHasKey($ninth->id, AssetCategoryTree::load()->detached());

        $this->assertRejected($chain[0], ['parent_id' => $ninth->id], 'parent_id', 'admin/categories/message.hierarchy.parent_is_descendant');
    }

    // ---------------------------------------------------------------
    // Maximum depth
    // ---------------------------------------------------------------

    #[Test]
    public function it_allows_a_new_category_at_the_maximum_depth_but_not_deeper(): void
    {
        $chain = $this->groupChain(AssetCategoryTree::MAX_DEPTH);

        $this->save($this->newAssetCategory('At limit'), ['parent_id' => $chain[6]->id]);
        $this->assertSame(AssetCategoryTree::MAX_DEPTH, AssetCategoryTree::load()->depth(Category::where('name', 'At limit')->value('id')));

        $this->assertRejected($this->newAssetCategory('Too deep'), ['parent_id' => $chain[7]->id], 'parent_id', 'admin/categories/message.hierarchy.max_depth', ['max' => AssetCategoryTree::MAX_DEPTH]);
        $this->assertFalse(Category::where('name', 'Too deep')->exists());
    }

    #[Test]
    public function the_depth_limit_includes_the_height_of_a_moved_subtree(): void
    {
        $chain = $this->groupChain(6);

        // A three-level subtree: Branch > Twig > Leaf
        $branch = $this->group('Branch');
        $twig = $this->group('Twig', $branch);
        $this->finalCategory('Leaf', $twig);

        // Under level 6 the leaf would sit at depth 9.
        $this->assertRejected($branch, ['parent_id' => $chain[5]->id], 'parent_id', 'admin/categories/message.hierarchy.max_depth', ['max' => AssetCategoryTree::MAX_DEPTH]);
        $this->assertNull($this->stored($branch)->parent_id);

        // Under level 5 the leaf sits exactly at depth 8.
        $this->save($this->stored($branch), ['parent_id' => $chain[4]->id]);
        $this->assertSame($chain[4]->id, $this->stored($branch)->parent_id);
    }

    // ---------------------------------------------------------------
    // Node role conversions
    // ---------------------------------------------------------------

    #[Test]
    public function final_to_group_is_blocked_by_an_active_model(): void
    {
        $laptop = $this->finalCategory('Laptop');
        AssetModel::factory()->create(['category_id' => $laptop->id]);

        $this->assertRejected($laptop, ['is_assignable' => '0'], 'is_assignable', 'admin/categories/message.hierarchy.has_models');
        $this->assertTrue($this->stored($laptop)->is_assignable);
    }

    #[Test]
    public function final_to_group_is_blocked_by_a_soft_deleted_model(): void
    {
        $laptop = $this->finalCategory('Laptop');
        AssetModel::factory()->create(['category_id' => $laptop->id])->delete();

        $this->assertRejected($laptop, ['is_assignable' => '0'], 'is_assignable', 'admin/categories/message.hierarchy.has_models');
        $this->assertTrue($this->stored($laptop)->is_assignable);
    }

    #[Test]
    public function final_to_group_is_allowed_without_models(): void
    {
        $category = $this->finalCategory('Empty');

        $this->save($category, ['is_assignable' => false]);

        $this->assertFalse($this->stored($category)->is_assignable);
    }

    #[Test]
    public function group_to_final_is_blocked_while_it_has_live_children(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Laptop', $group);

        $this->assertRejected($group, ['is_assignable' => '1'], 'is_assignable', 'admin/categories/message.hierarchy.has_children');
        $this->assertFalse($this->stored($group)->is_assignable);
    }

    #[Test]
    public function group_to_final_ignores_soft_deleted_children(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Old', $group)->delete();

        $this->save($group, ['is_assignable' => 'true']);

        $this->assertTrue($this->stored($group)->is_assignable);
    }

    #[Test]
    public function it_rejects_malformed_role_and_sort_order_values(): void
    {
        $category = $this->finalCategory('X');

        $this->assertRejected($category, ['is_assignable' => 'maybe'], 'is_assignable', 'admin/categories/message.hierarchy.invalid_is_assignable');
        $this->assertRejected($category, ['sort_order' => -1], 'sort_order', 'admin/categories/message.hierarchy.invalid_sort_order', ['max' => SaveCategoryHierarchyAction::MAX_SORT_ORDER]);
        $this->assertRejected($category, ['sort_order' => 'first'], 'sort_order', 'admin/categories/message.hierarchy.invalid_sort_order', ['max' => SaveCategoryHierarchyAction::MAX_SORT_ORDER]);
        $this->assertRejected($category, ['sort_order' => SaveCategoryHierarchyAction::MAX_SORT_ORDER + 1], 'sort_order', 'admin/categories/message.hierarchy.invalid_sort_order', ['max' => SaveCategoryHierarchyAction::MAX_SORT_ORDER]);
    }

    // ---------------------------------------------------------------
    // category_type changes and non-asset categories
    // ---------------------------------------------------------------

    #[Test]
    public function category_type_cannot_change_when_the_category_has_a_parent(): void
    {
        $child = $this->finalCategory('Child', $this->group('Parent'));
        $child->category_type = 'accessory';

        $this->assertRejected($child, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_hierarchy');
        $this->assertSame('asset', $this->stored($child)->category_type);
    }

    #[Test]
    public function category_type_cannot_change_when_the_category_has_children(): void
    {
        $group = $this->group('Parent');
        $this->finalCategory('Child', $group);
        $group->category_type = 'license';

        $this->assertRejected($group, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_hierarchy');
    }

    #[Test]
    public function category_type_cannot_change_for_an_empty_navigation_group(): void
    {
        $group = $this->group('Lonely group');
        $group->category_type = 'consumable';

        $this->assertRejected($group, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_hierarchy');
    }

    #[Test]
    public function category_type_cannot_change_when_models_exist_even_without_assets(): void
    {
        // Regression: itemCount() only counts assets, so a category with
        // models but no assets used to be convertible.
        $category = $this->finalCategory('Has models');
        AssetModel::factory()->create(['category_id' => $category->id]);
        $this->assertSame(0, $category->itemCount());
        $category->category_type = 'accessory';

        $this->assertRejected($category, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_in_use');
        $this->assertSame('asset', $this->stored($category)->category_type);
    }

    #[Test]
    public function category_type_cannot_change_when_only_soft_deleted_models_exist(): void
    {
        $category = $this->finalCategory('Deleted models');
        AssetModel::factory()->create(['category_id' => $category->id])->delete();
        $category->category_type = 'accessory';

        $this->assertRejected($category, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_in_use');
    }

    #[Test]
    public function category_type_cannot_change_when_non_asset_items_exist(): void
    {
        $accessory = Accessory::factory()->create();
        $category = $accessory->category;
        $category->category_type = 'asset';

        $this->assertRejected($category, [], 'category_type', 'admin/categories/message.update.cannot_change_category_type_in_use');
        $this->assertSame('accessory', $this->stored($category)->category_type);
    }

    #[Test]
    public function a_flat_unused_category_can_still_change_type_both_ways(): void
    {
        $category = $this->finalCategory('Flexible', null, 7);

        $category->category_type = 'accessory';
        $this->save($category, []);
        $stored = $this->stored($category);
        $this->assertSame('accessory', $stored->category_type);
        $this->assertSame(0, $stored->sort_order, 'non-asset categories are stored flat');

        $stored->category_type = 'asset';
        $group = $this->group('Destination');
        $this->save($stored, ['parent_id' => $group->id]);
        $this->assertSame('asset', $this->stored($category)->category_type);
        $this->assertSame($group->id, $this->stored($category)->parent_id);
    }

    #[Test]
    public function hierarchy_structure_is_rejected_for_non_asset_categories(): void
    {
        $group = $this->group('Hardware');

        foreach (['accessory', 'consumable', 'component', 'license'] as $type) {
            $category = new Category(['name' => "New {$type}", 'category_type' => $type]);
            $this->assertRejected($category, ['parent_id' => $group->id], 'parent_id', 'admin/categories/message.hierarchy.asset_only');
            $this->assertRejected($category, ['is_assignable' => '0'], 'is_assignable', 'admin/categories/message.hierarchy.asset_only');
            $this->assertRejected($category, ['sort_order' => '2'], 'sort_order', 'admin/categories/message.hierarchy.asset_only');
        }

        $this->assertFalse(Category::where('name', 'like', 'New %')->exists(), 'rejected categories must not be saved');
    }

    #[Test]
    public function flat_default_values_are_accepted_for_non_asset_categories(): void
    {
        $category = new Category(['name' => 'Mice', 'category_type' => 'accessory']);

        $this->save($category, ['parent_id' => '', 'is_assignable' => '1', 'sort_order' => '0']);

        $stored = $this->stored($category);
        $this->assertNull($stored->parent_id);
        $this->assertTrue($stored->is_assignable);
        $this->assertFalse($stored->isNavigationOnly());
    }

    // ---------------------------------------------------------------
    // Atomicity and mass assignment
    // ---------------------------------------------------------------

    #[Test]
    public function a_failure_during_save_rolls_back_every_change(): void
    {
        $from = $this->group('From');
        $to = $this->group('To');
        $category = $this->finalCategory('Original', $from, 1);

        Category::saved(function () {
            throw new RuntimeException('Simulated failure after the row was written');
        });

        $category->name = 'Renamed';
        try {
            $this->save($category, ['parent_id' => $to->id, 'sort_order' => 9]);
            $this->fail('Expected the simulated failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after the row was written', $e->getMessage());
        }

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Original', 'parent_id' => $from->id, 'sort_order' => 1]);
    }

    #[Test]
    public function a_model_validation_failure_writes_nothing(): void
    {
        $to = $this->group('To');
        $category = $this->finalCategory('Original');

        $category->name = '';
        try {
            $this->save($category, ['parent_id' => $to->id]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Original', 'parent_id' => null]);
    }

    #[Test]
    public function hierarchy_fields_are_not_mass_assignable(): void
    {
        $group = $this->group('Hardware');

        foreach (SaveCategoryHierarchyAction::FIELDS as $field) {
            $this->assertFalse((new Category)->isFillable($field), "{$field} must not be fillable");
        }

        $category = $this->finalCategory('Laptop');
        $category->fill(['name' => 'Laptop 2', 'parent_id' => $group->id, 'is_assignable' => false, 'sort_order' => 99]);
        $category->save();

        $stored = $this->stored($category);
        $this->assertSame('Laptop 2', $stored->name);
        $this->assertNull($stored->parent_id);
        $this->assertTrue($stored->is_assignable);
        $this->assertSame(0, $stored->sort_order);
    }
}
