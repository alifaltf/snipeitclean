<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2: editing an asset needs global assets.edit, category View
 * (Phase 5B1) and category Edit on its CURRENT final category, plus company
 * scope; moving it to another model also needs category Edit on the
 * DESTINATION category. Web, API, API bulk and web bulk all enforce it.
 */
class AssetCategoryEditTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function apiUpdate(User $user, Asset $asset, array $data): TestResponse
    {
        return $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset->id), $data);
    }

    /** The full web edit form payload for an asset, with overrides. */
    private function webUpdate(User $user, Asset $asset, array $data): TestResponse
    {
        return $this->actingAs($user, 'web')->put(route('hardware.update', $asset->id), array_merge([
            'model_id' => $asset->model_id,
            'status_id' => $asset->status_id,
            'asset_tags' => [1 => $asset->asset_tag],
            'serials' => [1 => $asset->serial],
            'name' => $asset->name,
        ], $data));
    }

    private function assertUnchanged(Asset $asset): void
    {
        $row = $this->rawAsset($asset);
        $this->assertSame($asset->name, $row->name, 'name changed');
        $this->assertSame((int) $asset->model_id, (int) $row->model_id, 'model changed');
    }

    // ---------------------------------------------------------------
    // 12-16
    // ---------------------------------------------------------------

    #[Test]
    public function global_edit_plus_category_edit_allows_editing_on_web_and_api(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update']]]);
        $asset = $this->asset['leaf1'];

        $this->apiUpdate($user, $asset, ['name' => 'Renamed by API'])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame('Renamed by API', $this->rawAsset($asset)->name);

        $this->webUpdate($user, $asset->fresh(), ['name' => 'Renamed by web'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Renamed by web', $this->rawAsset($asset)->name);
    }

    #[Test]
    public function global_edit_without_category_edit_is_denied_with_403_on_web_and_api(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create', 'delete']]]);
        $asset = $this->asset['leaf1'];

        $this->apiUpdate($user, $asset, ['name' => 'Nope'])->assertForbidden();
        $this->webUpdate($user, $asset, ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertForbidden();

        $this->assertUnchanged($asset);
    }

    #[Test]
    public function category_edit_without_global_edit_is_denied(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update']]], ['assets.view' => '1', 'assets.create' => '1']);
        $asset = $this->asset['leaf1'];

        $this->apiUpdate($user, $asset, ['name' => 'Nope'])->assertForbidden();
        $this->webUpdate($user, $asset, ['name' => 'Nope'])->assertForbidden();

        $this->assertUnchanged($asset);
    }

    #[Test]
    public function a_user_with_view_but_not_edit_can_read_but_cannot_update(): void
    {
        $user = $this->writer([['leaf1' => ['view']]]);
        $asset = $this->asset['leaf1'];

        $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset))->assertOk()->assertJsonPath('id', $asset->id);
        $this->apiUpdate($user, $asset, ['name' => 'Nope'])->assertForbidden();

        $page = $this->actingAs($user, 'web')->get(route('hardware.show', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('hardware.edit', $asset), $page, 'The edit button must not be offered.');

        $this->assertUnchanged($asset);
    }

    #[Test]
    public function changing_ordinary_fields_needs_edit_on_the_current_category_only(): void
    {
        $user = $this->writer([['leaf2' => ['view', 'update']]]);

        $this->apiUpdate($user, $this->asset['leaf2'], ['name' => 'Ordinary', 'notes' => 'changed'])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame('changed', $this->rawAsset($this->asset['leaf2'])->notes);
    }

    // ---------------------------------------------------------------
    // 17-19: moving between categories
    // ---------------------------------------------------------------

    #[Test]
    public function moving_between_categories_needs_edit_on_both(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view', 'update']]]);
        $asset = $this->asset['leaf1'];
        $destination = $this->asset['leaf2']->model_id;

        $this->apiUpdate($user, $asset, ['model_id' => $destination])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame($destination, (int) $this->rawAsset($asset)->model_id);

        // And back again through the web form.
        $this->webUpdate($user, $asset->fresh(), ['model_id' => $this->asset['leaf1']->model_id])->assertSessionHasNoErrors();
        $this->assertSame($this->asset['leaf1']->model_id, (int) $this->rawAsset($asset)->model_id);
    }

    #[Test]
    public function edit_on_current_but_not_destination_rejects_the_move(): void
    {
        // Destination 'leaf2' is viewable but not editable; 'other' is hidden.
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view', 'create']]]);
        $asset = $this->asset['leaf1'];

        foreach (['leaf2', 'other'] as $key) {
            $api = $this->apiUpdate($user, $asset, ['model_id' => $this->asset[$key]->model_id]);
            $this->assertNotSame('success', $api->json('status'), $key);
            $this->assertArrayHasKey('model_id', (array) $api->json('messages'), $key);
            $this->webUpdate($user, $asset, ['model_id' => $this->asset[$key]->model_id])->assertSessionHasErrors('model_id');
        }

        // A hidden destination answers exactly like a missing model.
        $this->assertSame(
            $this->apiUpdate($user, $asset, ['model_id' => 999999])->json('messages.model_id'),
            $this->apiUpdate($user, $asset, ['model_id' => $this->asset['other']->model_id])->json('messages.model_id')
        );

        $this->assertUnchanged($asset);
    }

    #[Test]
    public function edit_on_destination_but_not_current_rejects_the_move(): void
    {
        $user = $this->writer([['leaf1' => ['view'], 'leaf2' => ['view', 'update']]]);
        $asset = $this->asset['leaf1'];

        $this->apiUpdate($user, $asset, ['model_id' => $this->asset['leaf2']->model_id])->assertForbidden();
        $this->webUpdate($user, $asset, ['model_id' => $this->asset['leaf2']->model_id])->assertForbidden();

        $this->assertUnchanged($asset);
    }

    #[Test]
    public function an_invalid_destination_is_rejected_even_for_super_admin(): void
    {
        $navigationModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $navigationModel->id)->update(['category_id' => $this->cat['groupA']->id]);
        $deletedModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $deletedModel->delete();
        $asset = $this->asset['leaf1'];

        foreach ([$navigationModel, $deletedModel] as $model) {
            $this->assertNotSame('success', $this->apiUpdate($this->superUser(), $asset, ['model_id' => $model->id])->json('status'));
        }

        $this->assertUnchanged($asset);
    }

    // ---------------------------------------------------------------
    // 20: bulk
    // ---------------------------------------------------------------

    #[Test]
    public function api_bulk_edit_rejects_the_entire_request_when_one_asset_is_unauthorised(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);

        $this->actingAsForApi($user)
            ->patchJson(route('api.assets.bulk-update'), ['ids' => [$this->asset['leaf1']->id, $this->asset['leaf2']->id], 'notes' => 'bulk'])
            ->assertForbidden();

        $this->assertNotSame('bulk', $this->rawAsset($this->asset['leaf1'])->notes, 'No partial update.');
        $this->assertNotSame('bulk', $this->rawAsset($this->asset['leaf2'])->notes);
    }

    #[Test]
    public function api_bulk_edit_rejects_an_unauthorised_destination_for_every_asset(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);
        $leaf1Model = $this->asset['leaf1']->model_id;

        $response = $this->actingAsForApi($user)
            ->patchJson(route('api.assets.bulk-update'), ['ids' => [$this->asset['leaf1']->id], 'model_id' => $this->asset['leaf2']->model_id]);

        $this->assertSame('error', $response->json('status'));
        $this->assertSame($leaf1Model, (int) $this->rawAsset($this->asset['leaf1'])->model_id);
    }

    #[Test]
    public function api_bulk_edit_succeeds_when_every_asset_is_authorised(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update']], ['leaf2' => ['view', 'update']]]);

        $this->actingAsForApi($user)
            ->patchJson(route('api.assets.bulk-update'), ['ids' => [$this->asset['leaf1']->id, $this->asset['leaf2']->id], 'notes' => 'bulk ok'])
            ->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame('bulk ok', $this->rawAsset($this->asset['leaf2'])->notes);
    }

    #[Test]
    public function web_bulk_edit_rejects_the_entire_request_when_one_asset_is_unauthorised(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);
        $ids = [$this->asset['leaf1']->id, $this->asset['leaf2']->id];

        $this->actingAs($user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'edit'])->assertForbidden();
        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => $ids, 'notes' => 'bulk'])->assertForbidden();

        $this->assertNotSame('bulk', $this->rawAsset($this->asset['leaf1'])->notes);
    }

    #[Test]
    public function web_bulk_edit_rejects_an_unauthorised_destination_and_allows_an_authorised_one(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view', 'update'], 'direct' => ['view']]]);
        $ids = [$this->asset['leaf1']->id, $this->asset['leaf2']->id];

        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => $ids, 'model_id' => $this->asset['direct']->model_id])
            ->assertSessionHasErrors('model_id');
        $this->assertSame($this->asset['leaf1']->model_id, (int) $this->rawAsset($this->asset['leaf1'])->model_id);

        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => $ids, 'model_id' => $this->asset['leaf2']->model_id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->asset['leaf2']->model_id, (int) $this->rawAsset($this->asset['leaf1'])->model_id);
    }

    // ---------------------------------------------------------------
    // 21-22
    // ---------------------------------------------------------------

    #[Test]
    public function hidden_assets_still_return_not_found_on_every_update_path(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update']]]);
        $hidden = $this->asset['other'];

        $api = $this->apiUpdate($user, $hidden, ['name' => 'Nope']);
        $this->assertContains($api->status(), [200, 404]);
        $this->assertNotSame('success', $api->json('status'));
        $this->assertStringNotContainsString($hidden->asset_tag, $api->getContent());
        $this->assertNotSame(200, $this->actingAs($user, 'web')->get(route('hardware.edit', $hidden))->status());

        $this->assertUnchanged($hidden);
    }

    #[Test]
    public function the_edit_form_uses_the_edit_picker_which_offers_only_editable_models(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);

        $html = $this->actingAs($user, 'web')->get(route('hardware.edit', $this->asset['leaf1']))->assertOk()->getContent();
        $this->assertStringContainsString('data-endpoint="models/update"', $html);

        $ids = collect($this->actingAsForApi($user)->getJson(route('api.models.selectlist.for', ['operation' => 'update']))->json('results'))->pluck('id')->all();
        $this->assertSame([$this->asset['leaf1']->model_id], $ids);
    }

    #[Test]
    public function company_scoping_remains_enforced_on_edit(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $inB = $this->assetIn($this->cat['leaf1'], ['company_id' => $companyB->id, 'name' => 'Company B asset']);
        $user = $this->writer([['leaf1' => ['view', 'update']]]);
        $companyA->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $this->settings->enableMultipleFullCompanySupport();

        $this->assertNotSame('success', $this->apiUpdate($user, $inB, ['name' => 'Nope'])->json('status'));
        $this->assertSame('Company B asset', $this->rawAsset($inB)->name);
    }

    #[Test]
    public function super_admin_can_edit_and_move_any_asset_including_super_admin_only_ones(): void
    {
        $admin = $this->superUser();
        $broken = $this->assetIn($this->cat['leaf1'], ['name' => 'Broken']);
        AssetModel::find($broken->model_id)->delete();

        $this->apiUpdate($admin, $this->asset['other'], ['model_id' => $this->asset['loose']->model_id])->assertOk()->assertStatusMessageIs('success');
        $this->apiUpdate($admin, $broken, ['name' => 'Fixed by admin', 'model_id' => $this->asset['leaf1']->model_id])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame('Fixed by admin', $this->rawAsset($broken)->name);
    }

    // ---------------------------------------------------------------
    // Model category change (indirect move of every asset of the model)
    // ---------------------------------------------------------------

    #[Test]
    public function moving_an_asset_model_with_assets_needs_edit_on_both_categories(): void
    {
        $model = AssetModel::find($this->asset['leaf1']->model_id);
        // (Upstream's API model update request also requires models.create.)
        $modelPermissions = ['assets.view' => '1', 'assets.edit' => '1', 'models.view' => '1', 'models.create' => '1', 'models.edit' => '1'];

        $onlyCurrent = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]], $modelPermissions);
        $this->actingAsForApi($onlyCurrent)->patchJson(route('api.models.update', $model), ['category_id' => $this->cat['leaf2']->id])->assertOk();
        $this->assertSame($this->cat['leaf1']->id, (int) DB::table('models')->where('id', $model->id)->value('category_id'));

        $both = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view', 'update']]], $modelPermissions);
        $this->actingAsForApi($both)->patchJson(route('api.models.update', $model), ['category_id' => $this->cat['leaf2']->id])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame($this->cat['leaf2']->id, (int) DB::table('models')->where('id', $model->id)->value('category_id'));

        // A model without assets can be moved with no category grant at all.
        $empty = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $none = $this->writer([], $modelPermissions);
        $this->actingAsForApi($none)->patchJson(route('api.models.update', $empty), ['category_id' => $this->cat['other']->id])->assertOk()->assertStatusMessageIs('success');
    }

    #[Test]
    public function model_bulk_edit_cannot_move_models_with_assets_without_edit_on_both_categories(): void
    {
        $model = AssetModel::find($this->asset['leaf1']->model_id);
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]], ['assets.view' => '1', 'models.view' => '1', 'models.edit' => '1']);

        $this->actingAs($user, 'web')->post(route('models.bulkedit.store'), ['ids' => [$model->id], 'category_id' => $this->cat['leaf2']->id])
            ->assertSessionHasErrors('category_id');

        $this->assertSame($this->cat['leaf1']->id, (int) DB::table('models')->where('id', $model->id)->value('category_id'));
    }
}
