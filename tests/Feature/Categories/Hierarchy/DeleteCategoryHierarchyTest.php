<?php

namespace Tests\Feature\Categories\Hierarchy;

use App\Actions\Categories\DestroyCategoryAction;
use App\Exceptions\CategoryStillHasChildCategories;
use App\Models\AssetModel;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteCategoryHierarchyTest extends TestCase
{
    use CreatesCategoryHierarchy;

    #[Test]
    public function the_action_refuses_to_delete_a_group_with_live_children(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Laptop', $group);

        $this->expectException(CategoryStillHasChildCategories::class);

        try {
            DestroyCategoryAction::run($group);
        } finally {
            $this->assertNotSoftDeleted($group);
        }
    }

    #[Test]
    public function a_group_whose_children_are_all_deleted_can_be_deleted(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Laptop', $group)->delete();

        $this->actingAs($this->superUser())
            ->delete(route('categories.destroy', $group))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($group);
    }

    #[Test]
    public function the_web_delete_explains_that_children_must_be_moved_first(): void
    {
        $group = $this->group('Hardware');
        $this->group('Computers', $group);

        $this->actingAs($this->superUser())
            ->delete(route('categories.destroy', $group))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('error', trans('admin/categories/message.delete.has_child_categories'));

        $this->assertNotSoftDeleted($group);
    }

    #[Test]
    public function bulk_delete_applies_the_same_protection(): void
    {
        $group = $this->group('Hardware');
        $child = $this->finalCategory('Laptop', $group);
        $emptyGroup = $this->group('Empty');
        $withModel = $this->finalCategory('Has model');
        AssetModel::factory()->create(['category_id' => $withModel->id]);

        $this->actingAs($this->superUser())
            ->post(route('categories.bulk.delete'), ['ids' => [$group->id, $emptyGroup->id, $withModel->id]])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('multi_error_messages', function (array $messages) use ($group) {
                return in_array(trans('admin/categories/message.delete.has_child_categories_named', ['item_name' => $group->name]), $messages, true)
                    && count($messages) === 2;
            });

        $this->assertNotSoftDeleted($group);
        $this->assertNotSoftDeleted($child);
        $this->assertNotSoftDeleted($withModel);
        $this->assertSoftDeleted($emptyGroup);
    }

    #[Test]
    public function bulk_delete_of_a_parent_and_all_its_children_follows_the_submitted_order(): void
    {
        $group = $this->group('Hardware');
        $child = $this->finalCategory('Laptop', $group);

        // Parent first: refused because the child is still live at that point.
        $this->actingAs($this->superUser())
            ->post(route('categories.bulk.delete'), ['ids' => [$group->id, $child->id]])
            ->assertSessionHas('multi_error_messages');

        $this->assertNotSoftDeleted($group);
        $this->assertSoftDeleted($child);
    }

    #[Test]
    public function the_api_delete_explains_that_children_must_be_moved_first(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Laptop', $group);

        $this->actingAsForApi($this->superUser())
            ->deleteJson(route('api.categories.destroy', $group))
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages', trans('admin/categories/message.delete.has_child_categories'));

        $this->assertNotSoftDeleted($group);
    }

    #[Test]
    public function existing_model_checks_still_apply(): void
    {
        $category = $this->finalCategory('Laptop');
        AssetModel::factory()->create(['category_id' => $category->id]);

        $this->actingAsForApi($this->superUser())
            ->deleteJson(route('api.categories.destroy', $category))
            ->assertOk()
            ->assertStatusMessageIs('error');

        $this->assertNotSoftDeleted($category);
    }

    #[Test]
    public function the_listing_marks_groups_with_children_as_not_deletable(): void
    {
        $group = $this->group('Hardware');
        $this->finalCategory('Laptop', $group);
        $empty = $this->group('Empty');

        $rows = collect($this->actingAsForApi($this->superUser())
            ->getJson(route('api.categories.index'))
            ->assertOk()
            ->json('rows'))->keyBy('id');

        $this->assertFalse($rows[$group->id]['available_actions']['delete']);
        $this->assertTrue($rows[$empty->id]['available_actions']['delete']);
        $this->assertTrue($rows[$group->id]['available_actions']['update']);
        $this->assertFalse(Category::find($group->id)->isDeletable());
    }
}
