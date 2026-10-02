<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2: creating an asset needs global assets.create AND category
 * Create on the final category of the selected model, plus company scope.
 * Applies to web create, REST API create and clone.
 */
class AssetCategoryCreateTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function apiCreate(User $user, mixed $modelId, array $extra = []): TestResponse
    {
        return $this->actingAsForApi($user)->postJson(route('api.assets.store'), array_merge([
            'asset_tag' => 'NEW-'.Str::random(8),
            'model_id' => $modelId,
            'status_id' => $this->readyStatus()->id,
        ], $extra));
    }

    private function webCreate(User $user, mixed $modelId, array $extra = []): TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.store'), array_merge([
            'asset_tags' => [1 => 'WEB-'.Str::random(8)],
            'model_id' => $modelId,
            'status_id' => $this->readyStatus()->id,
        ], $extra));
    }

    private function modelOf(string $key): int
    {
        return $this->asset[$key]->model_id;
    }

    // ---------------------------------------------------------------
    // 1-6: who may create
    // ---------------------------------------------------------------

    #[Test]
    public function global_create_plus_category_create_allows_creation_on_web_and_api(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create']]]);
        $before = $this->assetCount();

        $this->apiCreate($user, $this->modelOf('leaf1'))->assertOk()->assertStatusMessageIs('success');
        $this->webCreate($user, $this->modelOf('leaf1'))->assertSessionHasNoErrors()->assertSessionHas('success-unescaped');

        $this->assertSame($before + 2, $this->assetCount());
    }

    #[Test]
    public function global_create_without_category_create_is_denied(): void
    {
        // View and Edit on the category, but no Create.
        $user = $this->writer([['leaf1' => ['view', 'update', 'delete']]]);
        $before = $this->assetCount();

        $api = $this->apiCreate($user, $this->modelOf('leaf1'));
        $this->assertNotSame('success', $api->json('status'));
        $this->assertArrayHasKey('model_id', (array) $api->json('messages'));
        $this->webCreate($user, $this->modelOf('leaf1'))->assertSessionHasErrors('model_id');

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function category_create_without_global_create_is_denied(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create']]], ['assets.view' => '1', 'assets.edit' => '1']);
        $before = $this->assetCount();

        $this->apiCreate($user, $this->modelOf('leaf1'))->assertForbidden();
        $this->webCreate($user, $this->modelOf('leaf1'))->assertForbidden();
        $this->actingAs($user, 'web')->get(route('hardware.create'))->assertForbidden();

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function no_grants_means_default_deny_even_for_an_ordinary_admin(): void
    {
        $before = $this->assetCount();

        foreach ([$this->writer([]), User::factory()->admin()->create()] as $user) {
            $this->assertNotSame('success', $this->apiCreate($user, $this->modelOf('leaf1'))->json('status'));
            $this->webCreate($user, $this->modelOf('leaf1'))->assertSessionHasErrors('model_id');
        }

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function a_create_grant_from_any_one_of_several_groups_allows_creation(): void
    {
        $user = $this->writer([['leaf1' => ['view']], ['other' => ['view', 'create']], ['loose' => ['view']]]);

        $this->apiCreate($user, $this->modelOf('other'))->assertOk()->assertStatusMessageIs('success');
        $this->assertNotSame('success', $this->apiCreate($user, $this->modelOf('leaf1'))->json('status'));
    }

    #[Test]
    public function super_admin_can_create_in_every_valid_final_category(): void
    {
        $admin = $this->superUser();

        foreach (['leaf1', 'leaf2', 'direct', 'other', 'loose'] as $key) {
            $this->apiCreate($admin, $this->modelOf($key))->assertOk()->assertStatusMessageIs('success');
        }
    }

    // ---------------------------------------------------------------
    // 7: model picker
    // ---------------------------------------------------------------

    #[Test]
    public function the_create_model_picker_only_offers_models_the_user_may_create_with_before_pagination(): void
    {
        foreach (range(1, 55) as $i) {
            AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Pickable '.$i]);
            AssetModel::factory()->create(['category_id' => $this->cat['other']->id, 'name' => 'Pickable hidden '.$i]);
        }
        $user = $this->writer([['leaf1' => ['view', 'create']], ['other' => ['view']]]);

        $first = $this->actingAsForApi($user)->getJson(route('api.models.selectlist.for', ['operation' => 'create', 'search' => 'Pickable']))->assertOk();
        $second = $this->actingAsForApi($user)->getJson(route('api.models.selectlist.for', ['operation' => 'create', 'search' => 'Pickable', 'page' => 2]))->assertOk();

        $this->assertSame(55, $first->json('total_count'));
        $this->assertCount(50, $first->json('results'));
        $this->assertCount(5, $second->json('results'));
        $this->assertStringNotContainsString('Pickable hidden', $first->getContent().$second->getContent());

        // The general (View) picker still shows the viewable 'other' models.
        $this->assertSame(110, $this->actingAsForApi($user)->getJson(route('api.models.selectlist', ['search' => 'Pickable']))->json('total_count'));
    }

    #[Test]
    public function the_create_form_uses_the_create_picker_and_never_preselects_a_forged_model(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create']]]);
        $hidden = AssetModel::find($this->modelOf('other'));

        $html = $this->actingAs($user, 'web')->get(route('hardware.create', ['model_id' => $hidden->id]))->assertOk()->getContent();

        $this->assertStringContainsString('data-endpoint="models/create"', $html);
        $this->assertStringNotContainsString($hidden->name, $html);
    }

    // ---------------------------------------------------------------
    // 8-9: forged and invalid targets
    // ---------------------------------------------------------------

    #[Test]
    public function a_forged_unauthorised_model_id_is_rejected_like_a_missing_one(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create']]]);
        $before = $this->assetCount();

        $hidden = $this->apiCreate($user, $this->modelOf('other'))->json('messages.model_id');
        $missing = $this->apiCreate($user, 999999)->json('messages.model_id');

        $this->assertNotEmpty($hidden);
        $this->assertSame($missing, $hidden, 'A hidden model must look exactly like a missing one.');
        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function navigation_non_asset_deleted_category_and_deleted_model_targets_are_rejected_for_everyone(): void
    {
        $navigationModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $navigationModel->id)->update(['category_id' => $this->cat['groupA']->id]);
        $nonAssetModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $nonAssetModel->id)->update(['category_id' => $this->cat['accessory']->id]);
        $deletedCategoryModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $deletedCategoryModel->id)->update(['category_id' => $this->cat['deleted']->id]);
        $deletedModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $deletedModel->delete();

        // A user granted everything, including forged grant rows on the
        // navigation group, the deleted category and the non-asset category.
        $user = $this->writer([
            ['leaf1' => ['view', 'create'], 'groupA' => ['view', 'create'], 'deleted' => ['view', 'create'], 'accessory' => ['view', 'create']],
        ]);
        $before = $this->assetCount();

        foreach ([$this->superUser(), $user] as $actor) {
            foreach ([$navigationModel, $nonAssetModel, $deletedCategoryModel, $deletedModel] as $model) {
                $this->assertNotSame('success', $this->apiCreate($actor, $model->id)->json('status'), 'model '.$model->id);
            }
        }

        $this->assertSame($before, $this->assetCount());
    }

    // ---------------------------------------------------------------
    // Clone
    // ---------------------------------------------------------------

    #[Test]
    public function cloning_needs_category_create_on_the_source_assets_category(): void
    {
        $viewer = $this->writer([['leaf1' => ['view'], 'leaf2' => ['view', 'create']]]);

        $this->actingAs($viewer, 'web')->get(route('clone/hardware', $this->asset['leaf1']))->assertForbidden();
        $this->actingAs($viewer, 'web')->get(route('clone/hardware', $this->asset['leaf2']))->assertOk();
        // A hidden source stays not-found.
        $this->assertNotSame(200, $this->actingAs($viewer, 'web')->get(route('clone/hardware', $this->asset['other']))->status());
        $this->actingAs($this->superUser(), 'web')->get(route('clone/hardware', $this->asset['other']))->assertOk();
    }

    // ---------------------------------------------------------------
    // 10-11: company and location
    // ---------------------------------------------------------------

    #[Test]
    public function company_scoping_remains_enforced_on_create(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = $this->writer([['leaf1' => ['view', 'create']]]);
        $companyA->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $this->settings->enableMultipleFullCompanySupport();

        $response = $this->apiCreate($user, $this->modelOf('leaf1'), ['company_id' => $companyB->id]);

        // FMCS forces the actor's own company (upstream behaviour) - never B.
        $this->assertNotSame($companyB->id, Asset::withoutGlobalScopes()->where('asset_tag', $response->json('payload.asset_tag'))->value('company_id'));
    }

    #[Test]
    public function location_does_not_affect_create_permission(): void
    {
        [$here, $there] = Location::factory()->count(2)->create();
        $user = $this->writer([['leaf1' => ['view', 'create']]], self::ALL_ASSET_PERMISSIONS, ['location_id' => $here->id]);

        $this->apiCreate($user, $this->modelOf('leaf1'), ['rtd_location_id' => $there->id, 'location_id' => $there->id])
            ->assertOk()->assertStatusMessageIs('success');
        $this->assertNotSame('success', $this->apiCreate($user, $this->modelOf('other'), ['rtd_location_id' => $here->id])->json('status'));
    }
}
