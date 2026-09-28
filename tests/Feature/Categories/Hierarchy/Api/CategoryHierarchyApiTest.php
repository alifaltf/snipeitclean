<?php

namespace Tests\Feature\Categories\Hierarchy\Api;

use App\Models\AssetModel;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

class CategoryHierarchyApiTest extends TestCase
{
    use CreatesCategoryHierarchy;

    #[Test]
    public function super_users_can_create_and_move_categories_through_the_api(): void
    {
        $user = $this->superUser();
        $fixed = $this->group('Fixed');

        $this->actingAsForApi($user)
            ->postJson(route('api.categories.store'), [
                'name' => 'Hardware',
                'category_type' => 'asset',
                'parent_id' => $fixed->id,
                'is_assignable' => false,
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $hardware = Category::where('name', 'Hardware')->firstOrFail();
        $this->assertSame($fixed->id, $hardware->parent_id);
        $this->assertFalse($hardware->is_assignable);
        $this->assertSame(2, $hardware->sort_order);

        $this->actingAsForApi($user)
            ->patchJson(route('api.categories.update', $hardware), ['parent_id' => null])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertNull($hardware->fresh()->parent_id);
    }

    #[Test]
    public function api_validation_uses_the_same_rules_and_writes_nothing(): void
    {
        $user = $this->superUser();
        $top = $this->group('Top');
        $child = $this->group('Child', $top);
        $laptop = $this->finalCategory('Laptop');
        AssetModel::factory()->create(['category_id' => $laptop->id]);

        $this->actingAsForApi($user)
            ->patchJson(route('api.categories.update', $top), ['parent_id' => $child->id])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.parent_id.0', trans('admin/categories/message.hierarchy.parent_is_descendant'));

        $this->actingAsForApi($user)
            ->patchJson(route('api.categories.update', $laptop), ['is_assignable' => false])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.is_assignable.0', trans('admin/categories/message.hierarchy.has_models'));

        $this->actingAsForApi($user)
            ->postJson(route('api.categories.store'), ['name' => 'Orphan', 'category_type' => 'asset', 'parent_id' => $laptop->id])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.parent_id.0', trans('admin/categories/message.hierarchy.parent_not_navigation'));

        $this->actingAsForApi($user)
            ->postJson(route('api.categories.store'), ['name' => 'Cables', 'category_type' => 'accessory', 'parent_id' => $top->id])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.parent_id.0', trans('admin/categories/message.hierarchy.asset_only'));

        $this->assertNull($top->fresh()->parent_id);
        $this->assertTrue($laptop->fresh()->is_assignable);
        $this->assertFalse(Category::whereIn('name', ['Orphan', 'Cables'])->exists());
    }

    #[Test]
    public function api_writes_without_hierarchy_fields_are_unchanged_for_admins(): void
    {
        $admin = $this->ordinaryAdmin();

        $this->actingAsForApi($admin)
            ->postJson(route('api.categories.store'), ['name' => 'Plain', 'category_type' => 'asset'])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $parent = $this->group('Parent');
        $category = $this->finalCategory('Child', $parent, 5);

        $this->actingAsForApi($admin)
            ->patchJson(route('api.categories.update', $category), ['name' => 'Child renamed', 'notes' => 'n'])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Child renamed', 'parent_id' => $parent->id, 'sort_order' => 5]);
        $this->assertDatabaseHas('categories', ['name' => 'Plain', 'parent_id' => null, 'is_assignable' => 1]);
    }

    #[Test]
    public function the_api_still_refuses_any_category_type_change(): void
    {
        $category = $this->finalCategory('Typed');

        $this->actingAsForApi($this->superUser())
            ->patchJson(route('api.categories.update', $category), ['category_type' => 'accessory'])
            ->assertOk()
            ->assertStatusMessageIs('error');

        $this->assertSame('asset', $category->fresh()->category_type);
    }

    #[Test]
    public function api_output_includes_hierarchy_fields(): void
    {
        $fixed = $this->group('Fixed');
        $laptop = $this->finalCategory('Laptop', $fixed, 3);
        Category::factory()->forAccessories()->create(['name' => 'Mice']);

        $this->actingAsForApi($this->ordinaryAdmin())
            ->getJson(route('api.categories.show', $laptop))
            ->assertOk()
            ->assertJsonPath('parent.id', $fixed->id)
            ->assertJsonPath('parent.name', 'Fixed')
            ->assertJsonPath('is_assignable', true)
            ->assertJsonPath('sort_order', 3);

        $rows = collect($this->actingAsForApi($this->ordinaryAdmin())
            ->getJson(route('api.categories.index', ['limit' => 50]))
            ->assertOk()
            ->json('rows'))->keyBy('name');

        $this->assertNull($rows['Fixed']['parent']);
        $this->assertFalse($rows['Fixed']['is_assignable']);
        $this->assertSame($fixed->id, $rows['Laptop']['parent']['id']);
        $this->assertTrue($rows['Mice']['is_assignable']);
        $this->assertSame(0, $rows['Mice']['sort_order']);
    }
}
