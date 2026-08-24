<?php

namespace Tests\Feature\Categories\Ui;

use App\Models\Category;
use App\Models\User;
use Tests\TestCase;

/**
 * Feature tests for the database-backed "Asset Group" (ers_asset_group)
 * field on the category create/edit form (see
 * resources/views/categories/edit.blade.php, App\Models\Category, and
 * App\Http\Controllers\CategoriesController).
 *
 * This field is what App\View\Composers\SidebarComposer and
 * App\Http\Controllers\Assets\AssetsController@index use to decide
 * whether an asset category shows up under the virtual "Hardware" or
 * "Software" group in the Assets sidebar — see
 * tests/Feature/Assets/ErsAssetsSidebarTest.php for sidebar-resolution
 * coverage. This file covers the form itself: rendering, validation, and
 * persistence.
 */
class ErsAssetGroupCategoryFormTest extends TestCase
{
    // ------------------------------------------------------------------
    // Rendering: the field exists, with the right options, on both
    // Create and Edit
    // ------------------------------------------------------------------

    public function test_create_page_shows_the_asset_group_field_with_hardware_and_software_options(): void
    {
        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('categories.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ers_asset_group"', $html);
        $this->assertStringContainsString('name="ers_asset_group"', $html);

        $this->assertMatchesRegularExpression('/<option value="hardware"[^>]*>\s*Hardware\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="software"[^>]*>\s*Software\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value=""[^>]*>\s*Select Asset Group\s*<\/option>/', $html);
    }

    public function test_edit_page_shows_the_asset_group_field(): void
    {
        $category = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('categories.edit', $category))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ers_asset_group"', $html);
        $this->assertMatchesRegularExpression('/<option value="hardware"[^>]*>\s*Hardware\s*<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="software"[^>]*>\s*Software\s*<\/option>/', $html);
    }

    /**
     * On Edit, the currently-saved group must come back pre-selected —
     * proven by checking which <option> carries the "selected" attribute,
     * not just that the field is present.
     */
    public function test_edit_page_shows_the_existing_saved_selection(): void
    {
        $hardwareCategory = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('categories.edit', $hardwareCategory))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="hardware" selected[^>]*>\s*Hardware\s*<\/option>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="software" selected[^>]*>\s*Software\s*<\/option>/', $html);

        $softwareCategory = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'software',
        ]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('categories.edit', $softwareCategory))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="software" selected[^>]*>\s*Software\s*<\/option>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="hardware" selected[^>]*>\s*Hardware\s*<\/option>/', $html);
    }

    /**
     * A legacy (pre-existing / ungrouped) asset category must render the
     * placeholder as selected, never Hardware or Software.
     */
    public function test_edit_page_shows_placeholder_selected_for_an_ungrouped_asset_category(): void
    {
        $category = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => null,
        ]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('categories.edit', $category))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="" selected[^>]*>\s*Select Asset Group\s*<\/option>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="hardware" selected[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="software" selected[^>]*>/', $html);
    }

    // ------------------------------------------------------------------
    // Create: required for asset, stored correctly, rejects garbage
    // ------------------------------------------------------------------

    public function test_creating_an_asset_category_requires_an_asset_group(): void
    {
        $this->assertFalse(Category::where('name', 'Ungrouped Asset Attempt')->exists());

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->from(route('categories.create'))
            ->post(route('categories.store'), [
                'name' => 'Ungrouped Asset Attempt',
                'category_type' => 'asset',
                // ers_asset_group intentionally omitted.
            ]);

        $response->assertSessionHasErrors(['ers_asset_group']);
        $response->assertRedirect(route('categories.create'));

        $this->assertFalse(Category::where('name', 'Ungrouped Asset Attempt')->exists());
    }

    public function test_creating_a_hardware_asset_category_stores_it_correctly(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('categories.store'), [
                'name' => 'New Hardware Category',
                'category_type' => 'asset',
                'ers_asset_group' => 'hardware',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => 'New Hardware Category',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);
    }

    public function test_creating_a_software_asset_category_stores_it_correctly(): void
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('categories.store'), [
                'name' => 'New Software Category',
                'category_type' => 'asset',
                'ers_asset_group' => 'software',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'name' => 'New Software Category',
            'category_type' => 'asset',
            'ers_asset_group' => 'software',
        ]);
    }

    public function test_creating_an_asset_category_with_an_invalid_asset_group_is_rejected(): void
    {
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->from(route('categories.create'))
            ->post(route('categories.store'), [
                'name' => 'Bad Group Attempt',
                'category_type' => 'asset',
                'ers_asset_group' => 'firmware', // not hardware/software
            ]);

        $response->assertSessionHasErrors(['ers_asset_group']);
        $response->assertRedirect(route('categories.create'));

        $this->assertFalse(Category::where('name', 'Bad Group Attempt')->exists());
    }

    /**
     * An array-shaped ers_asset_group (e.g. a hand-crafted
     * ?ers_asset_group[]=hardware submission) must be rejected rather
     * than crashing the request or being silently coerced to something
     * accepted.
     */
    public function test_creating_an_asset_category_with_array_shaped_asset_group_is_rejected(): void
    {
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->from(route('categories.create'))
            ->post(route('categories.store'), [
                'name' => 'Array Shaped Attempt',
                'category_type' => 'asset',
                'ers_asset_group' => ['hardware'],
            ]);

        $response->assertSessionHasErrors(['ers_asset_group']);

        $this->assertFalse(Category::where('name', 'Array Shaped Attempt')->exists());
    }

    /**
     * License, Accessory, Consumable, and Component categories must save
     * ers_asset_group as null, even if a value was (incorrectly, or via
     * a manipulated request) submitted for one.
     */
    public function test_non_asset_categories_save_asset_group_as_null_even_if_submitted(): void
    {
        $types = ['license', 'accessory', 'consumable', 'component'];

        foreach ($types as $type) {
            $name = ucfirst($type).' Category For ERS Test';

            $this->actingAs(User::factory()->superuser()->create())
                ->post(route('categories.store'), [
                    'name' => $name,
                    'category_type' => $type,
                    // A stray value a manipulated client might send —
                    // must be silently forced to null, not stored, and
                    // must not block the save.
                    'ers_asset_group' => 'hardware',
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('categories.index'));

            $this->assertDatabaseHas('categories', [
                'name' => $name,
                'category_type' => $type,
                'ers_asset_group' => null,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Update: required for asset, preserves/updates correctly, moves
    // between groups
    // ------------------------------------------------------------------

    public function test_updating_an_asset_category_requires_an_asset_group(): void
    {
        $category = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->from(route('categories.edit', $category))
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'category_type' => 'asset',
                // ers_asset_group intentionally omitted — clearing it via
                // the UI (Type stays Asset) must be rejected.
            ]);

        $response->assertSessionHasErrors(['ers_asset_group']);
        $response->assertRedirect(route('categories.edit', $category));

        // The category must be untouched — still 'hardware', not nulled out.
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'ers_asset_group' => 'hardware',
        ]);
    }

    public function test_updating_an_asset_categorys_group_from_hardware_to_software_persists(): void
    {
        $category = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'category_type' => 'asset',
                'ers_asset_group' => 'software',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'ers_asset_group' => 'software',
        ]);
    }

    /**
     * End-to-end: moving a category's group via the real HTTP update
     * endpoint actually relocates it in the rendered Assets sidebar —
     * complementing the lower-level (direct model save) proof in
     * ErsAssetsSidebarTest.
     */
    public function test_updating_the_group_via_http_moves_the_category_between_virtual_sidebar_groups(): void
    {
        $category = Category::factory()->create([
            'name' => 'Movable Via HTTP',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $admin = User::factory()->superuser()->create();

        $html = $this->actingAs($admin)->get(route('hardware.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="ers-hardware-'.$category->id.'-sidenav-option"', $html);

        $this->actingAs($admin)
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'category_type' => 'asset',
                'ers_asset_group' => 'software',
            ])
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($admin)->get(route('hardware.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="ers-hardware-'.$category->id.'-sidenav-option"', $html);
        $this->assertStringContainsString('id="ers-software-'.$category->id.'-sidenav-option"', $html);
    }

    public function test_updating_an_asset_category_with_an_invalid_asset_group_is_rejected(): void
    {
        $category = Category::factory()->create([
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->from(route('categories.edit', $category))
            ->put(route('categories.update', $category), [
                'name' => $category->name,
                'category_type' => 'asset',
                'ers_asset_group' => 'not-a-real-group',
            ])
            ->assertSessionHasErrors(['ers_asset_group']);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'ers_asset_group' => 'hardware',
        ]);
    }

    /**
     * Changing a category's type AWAY from Asset (e.g. to Accessory) must
     * clear ers_asset_group server-side, regardless of whatever the
     * client submitted for it — the server never trusts the browser to
     * have cleared it via the JS enhancement.
     */
    public function test_changing_category_type_away_from_asset_clears_the_group_server_side(): void
    {
        $category = Category::factory()->create([
            'name' => 'Type Change Category',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);
        // No assets/models attached, so the type-change guard allows this.

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('categories.update', $category), [
                'name' => 'Type Change Category',
                'category_type' => 'accessory',
                // Simulates a client that (bypassing the JS clear) still
                // sends a stale ers_asset_group value.
                'ers_asset_group' => 'hardware',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'category_type' => 'accessory',
            'ers_asset_group' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // Validation error pages preserve submitted (or existing) values
    // ------------------------------------------------------------------

    /**
     * When a create submission fails validation for a DIFFERENT reason
     * (here: the name is already taken by another asset category — a
     * pre-existing Watson uniqueness rule, unrelated to ers_asset_group),
     * the redirected-back Create page must still show the group the user
     * had actually picked, via old('ers_asset_group') — not silently
     * reset to the placeholder. This is what requirement 4's "preserve
     * submitted value after validation errors" means in practice.
     */
    public function test_create_page_preserves_the_submitted_asset_group_after_an_unrelated_validation_error(): void
    {
        Category::factory()->create([
            'name' => 'Duplicate Name Category',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $admin = User::factory()->superuser()->create();

        $response = $this->actingAs($admin)
            ->from(route('categories.create'))
            ->post(route('categories.store'), [
                'name' => 'Duplicate Name Category', // collides on name + category_type
                'category_type' => 'asset',
                'ers_asset_group' => 'software',
            ]);

        $response->assertSessionHasErrors(['name']);
        $response->assertSessionDoesntHaveErrors(['ers_asset_group']);
        $response->assertRedirect(route('categories.create'));

        // Follow the redirect in the SAME test client so the flashed
        // session (errors + old input) carries over, exactly like a real
        // browser following a 302.
        $html = $this->actingAs($admin)->get(route('categories.create'))->getContent();

        $this->assertMatchesRegularExpression('/<option value="software" selected[^>]*>\s*Software\s*<\/option>/', $html);
    }

    public function test_permission_required_to_view_asset_group_field(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('categories.create'))
            ->assertForbidden();
    }
}
