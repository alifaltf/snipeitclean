<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Group;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B1: asset-category View enforcement on the REST API.
 */
class AssetCategoryViewApiTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function index(User $user, array $query = []): TestResponse
    {
        return $this->actingAsForApi($user)
            ->getJson(route('api.assets.index', $query))
            ->assertOk();
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return collect($response->json('rows'))->pluck('id')->sort()->values()->all();
    }

    private function assertApiNotFound(TestResponse $response, Asset $asset): void
    {
        $this->assertContains($response->status(), [200, 403, 404]);
        $this->assertNotSame('success', $response->json('status'));
        $this->assertStringNotContainsString($asset->asset_tag, $response->getContent());
        $this->assertStringNotContainsString($asset->serial, $response->getContent());
        $this->assertStringNotContainsString($asset->model->category->name, $response->getContent());
    }

    // ---------------------------------------------------------------
    // Who sees what
    // ---------------------------------------------------------------

    #[Test]
    public function super_admin_sees_every_asset(): void
    {
        $response = $this->index($this->superUser());

        $this->assertSame($this->assetIds(['leaf1', 'leaf2', 'direct', 'other', 'loose']), $this->ids($response));
        $this->assertSame(5, $response->json('total'));
    }

    #[Test]
    public function super_admin_via_group_sees_every_asset(): void
    {
        $this->assertSame(5, $this->index($this->superUserViaGroup())->json('total'));
    }

    #[Test]
    public function ordinary_admin_without_grants_sees_no_assets(): void
    {
        $response = $this->index($this->ordinaryAdmin());

        $this->assertSame([], $response->json('rows'));
        $this->assertSame(0, $response->json('total'));
    }

    #[Test]
    public function zero_authorised_categories_returns_no_assets_even_with_every_global_permission(): void
    {
        $user = $this->viewer([[]], ['assets.view' => '1', 'assets.edit' => '1', 'assets.create' => '1', 'assets.delete' => '1']);

        $this->assertSame(0, $this->index($user)->json('total'));
    }

    #[Test]
    public function a_single_group_grant_shows_only_that_category(): void
    {
        $response = $this->index($this->viewer([['leaf1']]));

        $this->assertSame($this->assetIds(['leaf1']), $this->ids($response));
        $this->assertSame(1, $response->json('total'));
    }

    #[Test]
    public function grants_from_several_groups_are_unioned(): void
    {
        $response = $this->index($this->viewer([['leaf1'], ['other', 'loose']]));

        $this->assertSame($this->assetIds(['leaf1', 'other', 'loose']), $this->ids($response));
    }

    #[Test]
    public function ordinary_admin_with_a_grant_sees_only_granted_assets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->viewerGroupFor($admin, ['direct']);

        $this->assertSame($this->assetIds(['direct']), $this->ids($this->index($admin)));
    }

    #[Test]
    public function missing_global_assets_view_overrides_a_category_grant(): void
    {
        // Grant exists but global assets.view is missing: the global
        // permission is the upper bound, so nothing is readable.
        $user = $this->viewer([['leaf1', 'leaf2']], ['assets.checkout' => '1', 'assets.edit' => '1']);

        $this->actingAsForApi($user)->getJson(route('api.assets.index'))->assertForbidden();
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.show', $this->asset['leaf1'])), $this->asset['leaf1']);

        $this->actingAs($user);
        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function a_grant_on_a_navigation_group_row_never_authorises_its_descendants(): void
    {
        // Grant rows can only be written for final categories through the
        // application, but a forged row on a group must not broaden access.
        $user = $this->viewer([['groupA']]);

        $this->assertSame(0, $this->index($user)->json('total'));
    }

    // ---------------------------------------------------------------
    // Direct access, lookups and search
    // ---------------------------------------------------------------

    #[Test]
    public function direct_api_access_to_an_unauthorised_asset_is_not_found(): void
    {
        $user = $this->viewer([['leaf1']]);
        $hidden = $this->asset['other'];

        $this->actingAsForApi($user)->getJson(route('api.assets.show', $this->asset['leaf1']))
            ->assertOk()->assertJsonPath('id', $this->asset['leaf1']->id);

        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.show', $hidden)), $hidden);
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.history', $hidden)), $hidden);
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.show.bytag', ['any' => $hidden->asset_tag])), $hidden);
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.show.byserial', ['any' => $hidden->serial])), $hidden);
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.licenselist', $hidden)), $hidden);
    }

    #[Test]
    public function tag_and_serial_lookup_find_authorised_assets(): void
    {
        $user = $this->viewer([['leaf1']]);
        $visible = $this->asset['leaf1'];

        $this->assertStringContainsString($visible->asset_tag, $this->actingAsForApi($user)->getJson(route('api.assets.show.bytag', ['any' => $visible->asset_tag]))->assertOk()->getContent());
        $this->assertStringContainsString($visible->serial, $this->actingAsForApi($user)->getJson(route('api.assets.show.byserial', ['any' => $visible->serial]))->assertOk()->getContent());
    }

    #[Test]
    public function search_only_matches_authorised_assets(): void
    {
        $user = $this->viewer([['leaf2', 'loose']]);

        $this->assertSame($this->assetIds(['leaf2', 'loose']), $this->ids($this->index($user, ['search' => 'Findable'])));
        $this->assertSame([], $this->ids($this->index($user, ['search' => $this->asset['other']->asset_tag])));
        $this->assertSame([], $this->ids($this->index($user, ['search' => $this->cat['other']->name])));
        $this->assertSame([], $this->ids($this->index($user, ['filter' => json_encode(['asset_tag' => $this->asset['other']->asset_tag])])));
    }

    #[Test]
    public function asset_selectlist_only_offers_authorised_assets(): void
    {
        $user = $this->viewer([['leaf1']], ['assets.view' => '1', 'assets.checkout' => '1']);

        $response = $this->actingAsForApi($user)->getJson(route('assets.selectlist', ['search' => 'Findable']))->assertOk();

        $this->assertSame([$this->asset['leaf1']->id], collect($response->json('results'))->pluck('id')->all());
    }

    // ---------------------------------------------------------------
    // Totals, pagination, filters
    // ---------------------------------------------------------------

    #[Test]
    public function totals_and_pagination_are_computed_after_filtering(): void
    {
        foreach (range(1, 4) as $i) {
            $this->assetIn($this->cat['leaf1'], ['name' => 'Findable extra '.$i]);
            $this->assetIn($this->cat['other'], ['name' => 'Findable hidden '.$i]);
        }
        $user = $this->viewer([['leaf1']]);

        $first = $this->index($user, ['limit' => 2, 'offset' => 0, 'sort' => 'id', 'order' => 'asc']);
        $second = $this->index($user, ['limit' => 2, 'offset' => 2, 'sort' => 'id', 'order' => 'asc']);
        $last = $this->index($user, ['limit' => 2, 'offset' => 4, 'sort' => 'id', 'order' => 'asc']);

        $this->assertSame(5, $first->json('total'));
        $this->assertCount(2, $first->json('rows'));
        $this->assertCount(2, $second->json('rows'));
        $this->assertCount(1, $last->json('rows'));
        $all = array_merge($this->ids($first), $this->ids($second), $this->ids($last));
        $this->assertCount(5, array_unique($all));
        $this->assertSame(5, Asset::query()->whereIn('id', $all)->whereHas('model', fn ($q) => $q->where('category_id', $this->cat['leaf1']->id))->count());
    }

    #[Test]
    public function forged_or_invalid_filters_never_broaden_access(): void
    {
        $user = $this->viewer([['leaf1']]);
        $only = $this->assetIds(['leaf1']);

        foreach ([
            ['asset_category' => $this->cat['groupB']->id],
            ['asset_category' => $this->cat['other']->id],
            ['asset_category' => [$this->cat['other']->id]],
            ['asset_category' => $this->cat['other']->id.','.$this->cat['leaf1']->id],
            ['asset_category' => 'abc'],
            ['asset_category' => $this->cat['deleted']->id],
            ['category_id' => $this->cat['other']->id],
            ['model_id' => $this->asset['other']->model_id],
            ['filter' => json_encode(['category' => $this->cat['other']->name])],
            ['filter' => json_encode(['model' => $this->asset['other']->model->name])],
            ['status' => 'all'],
            ['deleted' => 'true'],
        ] as $query) {
            $ids = $this->ids($this->index($user, $query));
            $this->assertSame([], array_diff($ids, $only), 'Query broadened access: '.json_encode($query));
        }

        // A granted node still narrows as before.
        $this->assertSame($only, $this->ids($this->index($user, ['asset_category' => $this->cat['groupA']->id])));
    }

    #[Test]
    public function company_scoping_still_applies_alongside_category_scoping(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $leaf1InA = $this->assetIn($this->cat['leaf1'], ['company_id' => $companyA->id]);
        $leaf1InB = $this->assetIn($this->cat['leaf1'], ['company_id' => $companyB->id]);
        $leaf2InA = $this->assetIn($this->cat['leaf2'], ['company_id' => $companyA->id]);

        $user = $this->viewer([['leaf1']]);
        $companyA->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $this->settings->enableMultipleFullCompanySupport();

        $ids = $this->ids($this->index($user));
        $this->assertContains($leaf1InA->id, $ids);
        $this->assertNotContains($leaf1InB->id, $ids);
        $this->assertNotContains($leaf2InA->id, $ids);
        $this->assertApiNotFound($this->actingAsForApi($user)->getJson(route('api.assets.show', $leaf1InB)), $leaf1InB);
    }

    #[Test]
    public function location_never_restricts_access(): void
    {
        [$here, $there] = Location::factory()->count(2)->create();
        $atThere = $this->assetIn($this->cat['leaf1'], ['location_id' => $there->id, 'rtd_location_id' => $there->id]);
        $user = $this->viewer([['leaf1']], ['assets.view' => '1'], ['location_id' => $here->id]);

        $this->assertContains($atThere->id, $this->ids($this->index($user)));
        $this->actingAsForApi($user)->getJson(route('api.assets.show', $atThere))->assertOk()->assertJsonPath('id', $atThere->id);
        $this->assertSame([$atThere->id], $this->ids($this->index($user, ['location_id' => $there->id])));
    }

    // ---------------------------------------------------------------
    // Category and model pickers
    // ---------------------------------------------------------------

    #[Test]
    public function category_and_model_selectlists_do_not_leak_unauthorised_categories(): void
    {
        $user = $this->viewer([['leaf1']], ['assets.view' => '1', 'assets.edit' => '1']);

        $categories = $this->actingAsForApi($user)->getJson(route('api.categories.selectlist', ['item_type' => 'asset']))->assertOk();
        $this->assertSame([$this->cat['leaf1']->id], collect($categories->json('results'))->pluck('id')->all());
        $this->assertStringNotContainsString($this->cat['other']->name, $categories->getContent());

        $models = $this->actingAsForApi($user)->getJson(route('api.models.selectlist'))->assertOk();
        $this->assertSame([$this->asset['leaf1']->model_id], collect($models->json('results'))->pluck('id')->all());

        // Super Admin still gets every final category.
        $all = $this->actingAsForApi($this->superUser())->getJson(route('api.categories.selectlist', ['item_type' => 'asset']))->assertOk();
        $this->assertEqualsCanonicalizing($this->finalIds(), collect($all->json('results'))->pluck('id')->all());
    }

    // ---------------------------------------------------------------
    // History and activity
    // ---------------------------------------------------------------

    #[Test]
    public function activity_report_excludes_actions_on_unauthorised_assets(): void
    {
        $user = $this->viewer([['leaf1']], ['assets.view' => '1', 'reports.view' => '1']);
        $admin = $this->superUser();
        foreach (['leaf1', 'other'] as $key) {
            Actionlog::factory()->create(['item_id' => $this->asset[$key]->id, 'created_by' => $admin->id, 'action_type' => 'uploaded']);
        }

        $response = $this->actingAsForApi($user)->getJson(route('api.activity.index', ['limit' => 200]))->assertOk();
        $itemIds = collect($response->json('rows'))->where('item.type', 'asset')->pluck('item.id')->unique()->values()->all();

        $this->assertContains($this->asset['leaf1']->id, $itemIds);
        $this->assertNotContains($this->asset['other']->id, $itemIds);
        $this->assertStringNotContainsString($this->asset['other']->asset_tag, $response->getContent());
        $this->assertSame(count($response->json('rows')), min(200, (int) $response->json('total')));
    }

    // ---------------------------------------------------------------
    // Performance
    // ---------------------------------------------------------------

    #[Test]
    public function the_restriction_adds_no_per_row_queries(): void
    {
        $user = $this->viewer([['leaf1', 'leaf2']]);
        $count = function () use ($user): int {
            $this->flushPermissions();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->index($user, ['limit' => 50]);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(); // warm up (settings, translations, tree)
        $small = $count();
        foreach (range(1, 10) as $i) {
            $this->assetIn($this->cat[$i % 2 ? 'leaf1' : 'leaf2']);
            $this->assetIn($this->cat['other']);
        }
        $large = $count();

        $this->assertSame($small, $large, "Query count grew with rows: {$small} -> {$large}");
    }

    private function viewerGroupFor(User $user, array $keys): void
    {
        $group = Group::factory()->create(['permissions' => json_encode([])]);
        foreach ($keys as $key) {
            $this->grant($group, $this->cat[$key], ['view']);
        }
        $user->groups()->attach($group->id);
        $this->flushPermissions();
    }
}
