<?php

namespace Tests\Feature\Categories\TableLayout;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryPermissionService;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryTableLayoutHttpTest extends TestCase
{
    use BuildsTableLayoutFixtures;

    /** Form rows for $shown (in order) plus hidden rows for $hidden. */
    private function form(array $shown, array $hidden = []): array
    {
        $rows = [];
        foreach ($shown as $index => $key) {
            $rows[] = ['key' => $key, 'show' => '1', 'position' => (string) ($index + 1)];
        }
        foreach ($hidden as $key) {
            $rows[] = ['key' => $key, 'position' => ''];
        }

        return ['columns' => $rows];
    }

    private function superUser(): User
    {
        return User::factory()->superuser()->create();
    }

    /** @return array<string, User> users who must not administer layouts */
    private function unauthorisedUsers(Category $category): array
    {
        $grantedEverything = User::factory()->create(['permissions' => json_encode(['assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1', 'assets.delete' => '1'])]);
        $group = Group::create(['name' => 'Category grants '.uniqid(), 'permissions' => json_encode([])]);
        $grant = new AssetCategoryPermission;
        $grant->group_id = $group->id;
        $grant->category_id = $category->id;
        $grant->can_view = $grant->can_create = $grant->can_update = $grant->can_delete = true;
        $grant->save();
        $grantedEverything->groups()->attach($group->id);
        app(AssetCategoryPermissionService::class)->flush();

        return [
            'no permissions' => User::factory()->create(),
            'categories.edit only' => User::factory()->create(['permissions' => json_encode(['categories.view' => '1', 'categories.edit' => '1'])]),
            'customfields.edit only' => User::factory()->create(['permissions' => json_encode(['customfields.view' => '1', 'customfields.edit' => '1'])]),
            'categories.view + customfields.view' => User::factory()->create(['permissions' => json_encode(['categories.view' => '1', 'customfields.view' => '1'])]),
            'asset-category grants' => $grantedEverything->fresh(),
        ];
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    #[Test]
    public function super_users_admins_and_category_plus_custom_field_editors_may_configure_layouts(): void
    {
        $category = $this->finalAssetCategory();
        $users = [
            'super user' => $this->superUser(),
            'admin' => User::factory()->admin()->create(),
            'categories.edit + customfields.edit' => $this->layoutAdmin(),
        ];

        foreach ($users as $label => $user) {
            $this->actingAs($user)->get(route('categories.table-layout.edit', $category))->assertOk();
            $this->actingAs($user)->put(route('categories.table-layout.update', $category), $this->form(['standard:serial', 'standard:asset_tag']))
                ->assertRedirect(route('categories.table-layout.edit', $category))
                ->assertSessionHas('success');
            $this->assertSame(['standard:serial', 'standard:asset_tag'], $this->storedKeys($category), $label);
            $this->actingAs($user)->delete(route('categories.table-layout.reset', $category))->assertSessionHas('success');
            $this->assertSame([], $this->storedKeys($category), $label);
        }
    }

    #[Test]
    public function everyone_else_is_denied_on_every_endpoint(): void
    {
        $category = $this->finalAssetCategory();
        $this->layouts()->save($category, ['standard:notes']);

        foreach ($this->unauthorisedUsers($category) as $label => $user) {
            $this->actingAs($user)->get(route('categories.table-layout.edit', $category))->assertForbidden();
            $this->actingAs($user)->put(route('categories.table-layout.update', $category), $this->form(['standard:serial']))->assertForbidden();
            $this->actingAs($user)->delete(route('categories.table-layout.reset', $category))->assertForbidden();
            $this->assertSame(['standard:notes'], $this->storedKeys($category), $label);
        }

        // Guests are sent to log in.
        auth()->logout();
        $this->get(route('categories.table-layout.edit', $category))->assertRedirect();
        $this->put(route('categories.table-layout.update', $category), $this->form(['standard:serial']))->assertRedirect();
        $this->assertSame(['standard:notes'], $this->storedKeys($category));
    }

    #[Test]
    public function the_category_edit_page_links_to_the_layout_only_for_final_asset_categories_and_authorised_users(): void
    {
        $final = $this->finalAssetCategory();
        $group = $this->navigationGroupCategory();
        $accessories = Category::factory()->forAccessories()->create();
        $super = $this->superUser();

        $this->actingAs($super)->get(route('categories.edit', $final))->assertOk()
            ->assertSee('id="ers-category-table-layout"', false)
            ->assertSee(route('categories.table-layout.edit', $final), false);
        foreach ([$group, $accessories] as $category) {
            $this->actingAs($super)->get(route('categories.edit', $category))->assertOk()
                ->assertDontSee('id="ers-category-table-layout"', false);
        }
        $this->actingAs($super)->get(route('categories.create'))->assertOk()->assertDontSee('id="ers-category-table-layout"', false);

        // A category editor without custom-field editing sees no link.
        $categoryEditor = User::factory()->create(['permissions' => json_encode(['categories.view' => '1', 'categories.edit' => '1'])]);
        $this->actingAs($categoryEditor)->get(route('categories.edit', $final))->assertOk()->assertDontSee('id="ers-category-table-layout"', false);
    }

    #[Test]
    public function navigation_groups_other_types_and_deleted_categories_have_no_layout_page(): void
    {
        $group = $this->navigationGroupCategory();
        $accessories = Category::factory()->forAccessories()->create();
        $deleted = $this->finalAssetCategory();
        $deleted->delete();
        $super = $this->superUser();

        foreach ([$group, $accessories] as $category) {
            $this->actingAs($super)->get(route('categories.table-layout.edit', $category->id))->assertNotFound();
            $this->actingAs($super)->put(route('categories.table-layout.update', $category->id), $this->form(['standard:serial']))->assertNotFound();
            $this->actingAs($super)->delete(route('categories.table-layout.reset', $category->id))->assertNotFound();
        }

        // Deleted and unknown categories get Snipe-IT's usual "does not
        // exist" answer for every category route (route model binding).
        foreach ([$deleted->id, 999999] as $id) {
            $this->actingAs($super)->get(route('categories.table-layout.edit', $id))->assertRedirect(route('categories.index'))->assertSessionHas('error');
            $this->actingAs($super)->put(route('categories.table-layout.update', $id), $this->form(['standard:serial']))->assertRedirect(route('categories.index'));
            $this->actingAs($super)->delete(route('categories.table-layout.reset', $id))->assertRedirect(route('categories.index'));
        }
        $this->assertSame(0, DB::table('asset_category_columns')->count());
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    #[Test]
    public function the_page_shows_the_effective_layout_eligible_fields_and_the_help(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $encrypted = $this->fieldUsedBy($category, ['field_encrypted' => 1]);
        $elsewhere = $this->fieldUsedBy($this->finalAssetCategory());

        $response = $this->actingAs($this->superUser())->get(route('categories.table-layout.edit', $category))->assertOk();

        $response->assertSee(trans('admin/categories/table_layout.using_default'))
            ->assertSee(trans('admin/categories/table_layout.help_hidden'))
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="'.$this->customKey($field).'"', false)
            ->assertSee('value="'.$this->customKey($encrypted).'"', false)
            ->assertDontSee('value="'.$this->customKey($elsewhere).'"', false)
            ->assertSee($field->name)
            ->assertDontSee($elsewhere->name)
            ->assertSee(trans('admin/categories/table_layout.encrypted'))
            ->assertDontSee('id="ers-table-layout-reset"', false);

        // The default columns are pre-selected, in order, before the others.
        $content = $response->getContent();
        $positions = array_map(fn ($key) => strpos($content, 'value="'.$key.'"'), AssetTableFieldCatalog::DEFAULT_KEYS);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertLessThan(strpos($content, 'value="standard:notes"'), end($positions));
    }

    #[Test]
    public function labels_are_escaped_and_no_values_are_shown(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category, ['name' => '<script>alert("x")</script> Tag']);
        $model = AssetModel::query()->where('category_id', $category->id)->firstOrFail();
        $asset = Asset::factory()->create(['model_id' => $model->id]);
        DB::table('assets')->where('id', $asset->id)->update([$field->db_column => 'SECRET-CELL-VALUE']);
        $this->layouts()->save($category, ['standard:asset_tag', $this->customKey($field)]);

        $response = $this->actingAs($this->superUser())->get(route('categories.table-layout.edit', $category))->assertOk();

        $response->assertDontSee('<script>alert("x")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Tag', false)
            ->assertDontSee('SECRET-CELL-VALUE');
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    #[Test]
    public function saving_stores_the_order_hides_unselected_fields_and_reorders(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $user = $this->superUser();

        $this->actingAs($user)->put(route('categories.table-layout.update', $category), $this->form(
            [$this->customKey($field), 'standard:asset_tag', 'standard:location'],
            ['standard:name', 'standard:serial'],
        ))->assertSessionHasNoErrors();
        $this->assertSame([$this->customKey($field), 'standard:asset_tag', 'standard:location'], $this->storedKeys($category));

        // Reorder with order numbers out of row order, ties kept in row order.
        $this->actingAs($user)->put(route('categories.table-layout.update', $category), ['columns' => [
            ['key' => 'standard:asset_tag', 'show' => '1', 'position' => '5'],
            ['key' => $this->customKey($field), 'show' => '1', 'position' => '1'],
            ['key' => 'standard:location', 'show' => '1', 'position' => '1'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame([$this->customKey($field), 'standard:location', 'standard:asset_tag'], $this->storedKeys($category));
        $this->assertSame([1, 2, 3], $this->storedPositions($category));

        $page = $this->actingAs($user)->get(route('categories.table-layout.edit', $category))->assertOk();
        $page->assertSee(trans('admin/categories/table_layout.using_explicit'))->assertSee('id="ers-table-layout-reset"', false);
    }

    #[Test]
    public function validation_errors_keep_the_submitted_input(): void
    {
        $category = $this->finalAssetCategory();
        $this->layouts()->save($category, ['standard:notes']);
        $user = $this->superUser();
        $edit = route('categories.table-layout.edit', $category);

        $this->actingAs($user)->from($edit)->put(route('categories.table-layout.update', $category), ['columns' => [
            ['key' => 'standard:serial', 'show' => '1', 'position' => '7'],
            ['key' => 'standard:asset_tag', 'show' => '1', 'position' => 'abc'],
            ['key' => 'standard:notes', 'position' => '1'],
        ]])->assertRedirect($edit)->assertSessionHasErrors(['columns.1.position']);
        $this->assertSame(['standard:notes'], $this->storedKeys($category), 'Nothing was saved.');

        $page = $this->actingAs($user)->get($edit)->assertOk();
        $page->assertSee(trans('admin/categories/table_layout.message.position_invalid', ['max' => 999]))
            ->assertSee('value="7"', false)
            ->assertSee('value="abc"', false);
        $content = $page->getContent();
        $this->assertLessThan(strpos($content, 'value="standard:asset_tag"'), strpos($content, 'value="standard:serial"'), 'Submitted row order is kept.');
        $this->assertMatchesRegularExpression('/name="columns\[0\]\[show\]"\s+value="1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/name="columns\[2\]\[show\]"\s+value="1"[^>]*checked/', $content, 'The unticked column stays unticked.');

        // Nothing selected.
        $this->actingAs($user)->from($edit)->put(route('categories.table-layout.update', $category), $this->form([], ['standard:serial']))
            ->assertSessionHasErrors(['columns' => trans('admin/categories/table_layout.message.empty')]);
        $this->assertSame(['standard:notes'], $this->storedKeys($category));
    }

    #[Test]
    public function forged_keys_and_payloads_are_rejected(): void
    {
        $category = $this->finalAssetCategory();
        $foreign = $this->fieldUsedBy($this->finalAssetCategory());
        $this->layouts()->save($category, ['standard:notes']);
        $user = $this->superUser();

        $cases = [
            'cross-category custom field' => [$this->form(['standard:asset_tag', $this->customKey($foreign)]), 'not_eligible'],
            'unknown standard field' => [$this->form(['standard:password']), 'unknown_standard'],
            'malformed key' => [$this->form(['custom_field:x']), 'invalid'],
            'duplicate key' => [['columns' => [['key' => 'standard:serial', 'show' => '1', 'position' => '1'], ['key' => 'standard:serial', 'show' => '1', 'position' => '2']]], 'duplicate'],
            'not a list' => [['columns' => 'standard:serial'], 'invalid'],
            'nested array key' => [['columns' => [['key' => ['standard:serial'], 'show' => '1', 'position' => '1']]], 'invalid'],
            'missing payload' => [[], 'invalid'],
        ];
        foreach ($cases as $label => [$payload, $message]) {
            $this->actingAs($user)->put(route('categories.table-layout.update', $category), $payload)
                ->assertSessionHasErrors(['columns' => trans('admin/categories/table_layout.message.'.$message)]);
            $this->assertSame(['standard:notes'], $this->storedKeys($category), $label);
        }
    }

    #[Test]
    public function csrf_and_http_methods_are_enforced(): void
    {
        $category = $this->finalAssetCategory();
        $user = $this->superUser();

        foreach (['categories.table-layout.edit' => ['GET', 'HEAD'], 'categories.table-layout.update' => ['PUT'], 'categories.table-layout.reset' => ['DELETE']] as $name => $methods) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame($methods, $route->methods(), $name);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
        }
        $this->actingAs($user)->post(route('categories.table-layout.update', $category), $this->form(['standard:serial']))->assertStatus(405);
        $this->assertSame([], $this->storedKeys($category));

        // The web group's CSRF middleware, with the test-suite bypass off,
        // rejects a PUT or DELETE without a token.
        $csrf = new class(app(), app('encrypter')) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        foreach (['PUT' => route('categories.table-layout.update', $category), 'DELETE' => route('categories.table-layout.reset', $category)] as $method => $url) {
            $request = Request::create($url, $method, $this->form(['standard:serial']));
            $request->setLaravelSession(app('session.store'));
            try {
                $csrf->handle($request, fn () => response('should not run'));
                $this->fail($method.' passed without a CSRF token.');
            } catch (TokenMismatchException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function saving_a_layout_does_not_touch_assets_or_custom_field_values(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $model = AssetModel::query()->where('category_id', $category->id)->firstOrFail();
        $asset = Asset::factory()->create(['model_id' => $model->id]);
        DB::table('assets')->where('id', $asset->id)->update([$field->db_column => 'kept']);
        $assets = DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $fields = DB::table('custom_fields')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $user = $this->superUser();

        $this->actingAs($user)->put(route('categories.table-layout.update', $category), $this->form(['standard:asset_tag'], [$this->customKey($field)]))->assertSessionHasNoErrors();
        $this->actingAs($user)->delete(route('categories.table-layout.reset', $category))->assertSessionHasNoErrors();

        $this->assertSame($assets, DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($fields, DB::table('custom_fields')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
}
