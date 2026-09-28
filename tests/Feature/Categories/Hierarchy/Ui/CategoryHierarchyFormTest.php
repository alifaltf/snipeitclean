<?php

namespace Tests\Feature\Categories\Hierarchy\Ui;

use App\Models\AssetModel;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

class CategoryHierarchyFormTest extends TestCase
{
    use CreatesCategoryHierarchy;

    private const CONTROLS = ['name="parent_id"', 'name="is_assignable"', 'name="sort_order"', 'id="category-hierarchy-fields"'];

    private function assertHasControls($response): void
    {
        foreach (self::CONTROLS as $needle) {
            $response->assertSee($needle, false);
        }
    }

    private function assertLacksControls($response): void
    {
        foreach (self::CONTROLS as $needle) {
            $response->assertDontSee($needle, false);
        }
    }

    #[Test]
    public function super_users_see_the_hierarchy_controls_on_create_and_asset_edit(): void
    {
        $user = $this->superUser();

        $create = $this->actingAs($user)->get(route('categories.create'))->assertOk();
        $this->assertHasControls($create);
        $create->assertSee(trans('admin/categories/general.node_role_navigation'))
            ->assertSee(trans('admin/categories/general.node_role_final'))
            ->assertSee(trans('admin/categories/general.node_role_help'));

        $edit = $this->actingAs($user)->get(route('categories.edit', $this->finalCategory('Laptop')))->assertOk();
        $this->assertHasControls($edit);
    }

    #[Test]
    public function admins_and_category_managers_do_not_see_the_hierarchy_controls(): void
    {
        $category = $this->finalCategory('Laptop', $this->group('Hardware'));

        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->assertLacksControls($this->actingAs($user)->get(route('categories.create'))->assertOk());
            $this->assertLacksControls($this->actingAs($user)->get(route('categories.edit', $category))->assertOk());
        }
    }

    #[Test]
    public function non_asset_edit_forms_are_unchanged_for_super_users(): void
    {
        $user = $this->superUser();

        foreach (['forAccessories', 'forConsumables', 'forComponents', 'forLicenses'] as $state) {
            $category = Category::factory()->{$state}()->create();
            $response = $this->actingAs($user)->get(route('categories.edit', $category))->assertOk();
            $this->assertLacksControls($response);
            $response->assertSee('name="name"', false);
        }
    }

    #[Test]
    public function parent_choices_are_navigation_groups_labelled_with_their_full_path(): void
    {
        $fixed = $this->group('Fixed');
        $hardware = $this->group('Hardware', $fixed);
        $laptop = $this->finalCategory('Laptop', $hardware);
        $mice = Category::factory()->forAccessories()->create(['name' => 'Mice']);

        $response = $this->actingAs($this->superUser())->get(route('categories.create'))->assertOk();

        $response->assertSee('<option value="'.$fixed->id.'" >Fixed</option>', false)
            ->assertSee('<option value="'.$hardware->id.'" >Fixed &gt; Hardware</option>', false)
            ->assertSee(trans('admin/categories/general.parent_group_none'))
            ->assertDontSee('<option value="'.$laptop->id.'" >', false)
            ->assertDontSee('<option value="'.$mice->id.'" >', false);
    }

    #[Test]
    public function editing_excludes_the_category_and_its_descendants_from_parent_choices(): void
    {
        $fixed = $this->group('Fixed');
        $hardware = $this->group('Hardware', $fixed);
        $computers = $this->group('Computers', $hardware);
        $this->group('Furniture', $fixed);

        $response = $this->actingAs($this->superUser())->get(route('categories.edit', $hardware))->assertOk();

        $response->assertSee('<option value="'.$fixed->id.'" selected>Fixed</option>', false)
            ->assertSee('Fixed &gt; Furniture', false)
            ->assertDontSee('<option value="'.$hardware->id.'"', false)
            ->assertDontSee('<option value="'.$computers->id.'"', false);
    }

    #[Test]
    public function the_form_creates_and_moves_categories_for_super_users(): void
    {
        $user = $this->superUser();
        $fixed = $this->group('Fixed');

        $this->actingAs($user)->post(route('categories.store'), [
            'name' => 'Hardware',
            'category_type' => 'asset',
            'is_assignable' => '0',
            'parent_id' => (string) $fixed->id,
            'sort_order' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('categories.index'));

        $hardware = Category::where('name', 'Hardware')->firstOrFail();
        $this->assertTrue($hardware->isNavigationOnly());
        $this->assertSame($fixed->id, $hardware->parent_id);

        $this->actingAs($user)->put(route('categories.update', $hardware), [
            'name' => 'Hardware',
            'is_assignable' => '0',
            'parent_id' => '',
            'sort_order' => '4',
        ])->assertSessionHasNoErrors()->assertRedirect(route('categories.index'));

        $hardware->refresh();
        $this->assertNull($hardware->parent_id);
        $this->assertSame(4, $hardware->sort_order);
    }

    #[Test]
    public function validation_failures_redirect_back_with_errors_and_old_input(): void
    {
        $user = $this->superUser();
        $top = $this->group('Top');
        $child = $this->group('Child', $top);
        $other = $this->group('Other');

        $this->actingAs($user)
            ->from(route('categories.edit', $top))
            ->put(route('categories.update', $top), [
                'name' => 'Top renamed',
                'is_assignable' => '0',
                'parent_id' => (string) $child->id,
                'sort_order' => '6',
            ])
            ->assertRedirect(route('categories.edit', $top))
            ->assertSessionHasErrors(['parent_id' => trans('admin/categories/message.hierarchy.parent_is_descendant')])
            ->assertSessionHasInput('parent_id', (string) $child->id)
            ->assertSessionHasInput('sort_order', '6')
            ->assertSessionHasInput('name', 'Top renamed');

        $this->assertDatabaseHas('categories', ['id' => $top->id, 'name' => 'Top', 'parent_id' => null]);

        // Redisplay: the old parent choice, role and order are kept.
        $response = $this->actingAs($user)
            ->withSession(['_old_input' => ['name' => 'Top renamed', 'parent_id' => (string) $other->id, 'is_assignable' => '0', 'sort_order' => '6']])
            ->get(route('categories.edit', $top))
            ->assertOk();

        $response->assertSee('<option value="'.$other->id.'" selected>Other</option>', false)
            ->assertSee('value="6"', false)
            ->assertSee('value="Top renamed"', false);
        $this->assertMatchesRegularExpression('/name="is_assignable"\s+value="0"[^>]*checked/', $response->getContent());
    }

    #[Test]
    public function non_asset_categories_can_still_be_created_through_the_form(): void
    {
        $this->actingAs($this->superUser())
            ->post(route('categories.store'), ['name' => 'Mice', 'category_type' => 'accessory'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', ['name' => 'Mice', 'category_type' => 'accessory', 'parent_id' => null, 'is_assignable' => 1]);
    }

    #[Test]
    public function the_web_update_blocks_changing_the_type_of_a_category_with_models_but_no_assets(): void
    {
        $category = $this->finalCategory('Models only');
        AssetModel::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->superUser())
            ->from(route('categories.edit', $category))
            ->put(route('categories.update', $category), ['name' => 'Models only', 'category_type' => 'accessory'])
            ->assertRedirect(route('categories.edit', $category))
            ->assertSessionHasErrors(['category_type' => trans('admin/categories/message.update.cannot_change_category_type_in_use')]);

        $this->assertSame('asset', $category->fresh()->category_type);
    }
}
