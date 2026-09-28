<?php

namespace Tests\Feature\Categories\Hierarchy;

use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Only a real Super User may write hierarchy fields. Ordinary Admins pass
 * CategoryPolicy::before() and category managers hold categories.create/
 * edit, so both must be refused by the dedicated ability, with a 403 on
 * every web and API entry point rather than a silent discard.
 */
class CategoryHierarchyAuthorizationTest extends TestCase
{
    use CreatesCategoryHierarchy;

    public static function hierarchyFields(): array
    {
        return [
            'parent_id' => ['parent_id', ''],
            'is_assignable' => ['is_assignable', '1'],
            'sort_order' => ['sort_order', '0'],
        ];
    }

    #[Test]
    public function the_ability_is_granted_to_super_users_only(): void
    {
        $this->assertTrue(Gate::forUser($this->superUser())->allows(SaveCategoryHierarchyAction::ABILITY));
        $this->assertTrue(Gate::forUser($this->superUserViaGroup())->allows(SaveCategoryHierarchyAction::ABILITY));

        $admin = $this->ordinaryAdmin();
        $this->assertTrue(Gate::forUser($admin)->allows('update', Category::class), 'sanity: admins pass CategoryPolicy');
        $this->assertFalse(Gate::forUser($admin)->allows(SaveCategoryHierarchyAction::ABILITY));

        $manager = $this->categoryManager();
        $this->assertTrue(Gate::forUser($manager)->allows('create', Category::class), 'sanity: manager can create categories');
        $this->assertTrue(Gate::forUser($manager)->allows('update', Category::class), 'sanity: manager can edit categories');
        $this->assertFalse(Gate::forUser($manager)->allows(SaveCategoryHierarchyAction::ABILITY));

        $this->assertFalse(Gate::forUser(User::factory()->create())->allows(SaveCategoryHierarchyAction::ABILITY));
    }

    #[Test]
    public function the_action_refuses_hierarchy_input_from_non_super_users(): void
    {
        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->actingAs($user);
            $category = new Category(['name' => 'Refused '.$user->id, 'category_type' => 'asset']);

            try {
                SaveCategoryHierarchyAction::run($category, ['sort_order' => 0]);
                $this->fail('Expected an AuthorizationException');
            } catch (AuthorizationException) {
                $this->assertFalse(Category::where('name', 'Refused '.$user->id)->exists());
            }
        }
    }

    #[Test]
    public function the_action_still_saves_without_hierarchy_input_for_non_super_users(): void
    {
        $this->actingAs($this->categoryManager());

        SaveCategoryHierarchyAction::run(new Category(['name' => 'Allowed', 'category_type' => 'asset']));

        $this->assertTrue(Category::where('name', 'Allowed')->exists());
    }

    #[Test]
    public function super_users_can_create_hierarchy_through_the_web_form(): void
    {
        $group = $this->group('Hardware');

        $this->actingAs($this->superUserViaGroup())
            ->post(route('categories.store'), [
                'name' => 'Laptop',
                'category_type' => 'asset',
                'parent_id' => (string) $group->id,
                'is_assignable' => '1',
                'sort_order' => '2',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', ['name' => 'Laptop', 'parent_id' => $group->id, 'is_assignable' => 1, 'sort_order' => 2]);
    }

    #[Test]
    #[DataProvider('hierarchyFields')]
    public function web_create_with_a_hierarchy_field_is_forbidden_for_admins_and_managers(string $field, string $value): void
    {
        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->actingAs($user)
                ->post(route('categories.store'), [
                    'name' => 'Forbidden',
                    'category_type' => 'asset',
                    $field => $value,
                ])
                ->assertForbidden();
        }

        $this->assertFalse(Category::where('name', 'Forbidden')->exists());
    }

    #[Test]
    #[DataProvider('hierarchyFields')]
    public function web_update_with_a_hierarchy_field_is_forbidden_for_admins_and_managers(string $field, string $value): void
    {
        $category = $this->finalCategory('Unchanged', $this->group('Parent'), 3);

        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->actingAs($user)
                ->put(route('categories.update', $category), [
                    'name' => 'Changed',
                    $field => $value,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Unchanged', 'parent_id' => $category->parent_id, 'sort_order' => 3]);
    }

    #[Test]
    public function admins_can_still_edit_ordinary_fields_and_the_hierarchy_is_kept(): void
    {
        $parent = $this->group('Parent');
        $category = $this->finalCategory('Before', $parent, 3);

        $this->actingAs($this->ordinaryAdmin())
            ->put(route('categories.update', $category), ['name' => 'After'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'After', 'parent_id' => $parent->id, 'is_assignable' => 1, 'sort_order' => 3]);
    }

    #[Test]
    #[DataProvider('hierarchyFields')]
    public function api_create_with_a_hierarchy_field_is_forbidden_for_admins_and_managers(string $field, string $value): void
    {
        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->actingAsForApi($user)
                ->postJson(route('api.categories.store'), [
                    'name' => 'Forbidden',
                    'category_type' => 'asset',
                    $field => $value,
                ])
                ->assertForbidden();
        }

        $this->assertFalse(Category::where('name', 'Forbidden')->exists());
    }

    #[Test]
    #[DataProvider('hierarchyFields')]
    public function api_update_with_a_hierarchy_field_is_forbidden_for_admins_and_managers(string $field, string $value): void
    {
        $category = $this->finalCategory('Unchanged', $this->group('Parent'), 3);

        foreach ([$this->ordinaryAdmin(), $this->categoryManager()] as $user) {
            $this->actingAsForApi($user)
                ->patchJson(route('api.categories.update', $category), [
                    'name' => 'Changed',
                    $field => $value,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Unchanged', 'sort_order' => 3]);
    }

    #[Test]
    public function a_json_null_hierarchy_field_still_counts_as_supplied(): void
    {
        $category = $this->finalCategory('Unchanged', $this->group('Parent'));

        $this->actingAsForApi($this->ordinaryAdmin())
            ->patchJson(route('api.categories.update', $category), ['parent_id' => null])
            ->assertForbidden();

        $this->assertNotNull($category->fresh()->parent_id);
    }
}
