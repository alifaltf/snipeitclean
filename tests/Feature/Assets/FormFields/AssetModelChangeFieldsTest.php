<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: changing an asset's model (web, API, bulk). The
 * destination must pass Phase 5 authorization; only the destination
 * fieldset's fields are accepted and its required fields are enforced; a
 * failed change writes nothing. Values stored for fields of the old
 * fieldset stay in the database (Snipe-IT never deletes them) but are not
 * shown, returned or editable while the new model's fieldset lacks them.
 */
class AssetModelChangeFieldsTest extends TestCase
{
    use BuildsFormFieldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
    }

    private function oldAsset(): Asset
    {
        return $this->assetOf($this->modelOne, [
            $this->plain->db_column => 'shared',
            $this->secret->db_column => 'OLD-SECRET',
            $this->box->db_column => 'Red',
        ], ['name' => 'Before']);
    }

    #[Test]
    public function web_change_saves_destination_fields_and_keeps_old_values_hidden(): void
    {
        $asset = $this->oldAsset();
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);

        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [
            'model_id' => $this->modelTwo->id,
            $this->other->db_column => 'required value',
            $this->plain->db_column => 'shared 2',
        ]))->assertSessionHasNoErrors();

        $stored = $this->stored($asset);
        $this->assertSame($this->modelTwo->id, $stored['model_id']);
        $this->assertSame(['required value', 'shared 2'], [$stored['other'], $stored['plain']]);
        $this->assertSame(['OLD-SECRET', 'Red'], [$stored['secret'], $stored['box']], 'Old values are not destroyed.');

        // ...but not exposed: edit form, API, category table.
        $html = (string) $this->get(route('hardware.edit', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('OLD-SECRET', $html);
        $this->assertStringNotContainsString($this->box->db_column, $html);
        $json = $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('OLD-SECRET', $json);
        $this->assertStringNotContainsString($this->box->db_column, $json);
    }

    #[Test]
    public function web_change_without_a_required_destination_field_changes_nothing(): void
    {
        $asset = $this->oldAsset();
        $user = $this->editor();

        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [
            'model_id' => $this->modelTwo->id, 'name' => 'After', $this->plain->db_column => 'shared 2',
        ]))->assertSessionHasErrors($this->other->db_column);

        $raw = Asset::withoutGlobalScopes()->find($asset->id);
        $this->assertSame([$this->modelOne->id, 'Before', 'shared'], [(int) $raw->model_id, $raw->name, $this->stored($asset)['plain']], 'No partial update.');
    }

    #[Test]
    public function web_change_rejects_fields_of_the_old_fieldset(): void
    {
        $asset = $this->oldAsset();
        $this->actingAs($this->editor(), 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [
            'model_id' => $this->modelTwo->id, $this->other->db_column => 'x', $this->box->db_column => ['Blue'],
        ]))->assertSessionHasErrors($this->box->db_column);

        $this->assertSame([$this->modelOne->id, 'Red', null], [$this->stored($asset)['model_id'], $this->stored($asset)['box'], $this->stored($asset)['other']]);
    }

    #[Test]
    public function the_destination_needs_category_edit(): void
    {
        $asset = $this->oldAsset();
        // Edit on catA only: Model B (catB) is not a permitted destination.
        $user = $this->member(['assets.view' => '1', 'assets.edit' => '1'], [[[$this->catA, ['view', 'update']], [$this->catB, ['view']]]]);

        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['model_id' => $this->modelB->id]))
            ->assertSessionHasErrors('model_id');
        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), ['model_id' => $this->modelB->id]);
        $this->assertSame('error', $response->json('status'));
        $this->assertSame($this->modelOne->id, $this->stored($asset)['model_id']);

        // Super Admin may move it, but structure still applies.
        $super = User::factory()->superuser()->create();
        $this->actingAs($super, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['model_id' => $this->modelTwo->id]))
            ->assertSessionHasErrors($this->other->db_column);
        $this->assertSame($this->modelOne->id, $this->stored($asset)['model_id']);
    }

    #[Test]
    public function api_change_follows_the_same_rules(): void
    {
        $asset = $this->oldAsset();
        $user = $this->editor();

        // Required destination field missing: refused, nothing written.
        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), ['model_id' => $this->modelTwo->id, 'name' => 'After']);
        $this->assertSame('error', $response->json('status'));
        $this->assertSame([$this->modelOne->id, 'Before'], [$this->stored($asset)['model_id'], Asset::withoutGlobalScopes()->find($asset->id)->name]);

        // An old-fieldset field: refused.
        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), ['model_id' => $this->modelTwo->id, $this->other->db_column => 'v', $this->box->db_column => 'Blue']);
        $this->assertSame('error', $response->json('status'));
        $this->assertArrayHasKey($this->box->db_column, (array) $response->json('messages'));
        $this->assertSame($this->modelOne->id, $this->stored($asset)['model_id']);

        // Valid change.
        $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), ['model_id' => $this->modelTwo->id, $this->other->db_column => 'v'])
            ->assertJsonPath('status', 'success');
        $this->assertSame([$this->modelTwo->id, 'v', 'Red'], [$this->stored($asset)['model_id'], $this->stored($asset)['other'], $this->stored($asset)['box']]);
    }

    #[Test]
    public function changing_back_shows_the_kept_values_again(): void
    {
        $asset = $this->oldAsset();
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['model_id' => $this->modelTwo->id, $this->other->db_column => 'v']))->assertSessionHasNoErrors();
        $this->put(route('hardware.update', $asset), $this->editPayload($asset->fresh(), ['model_id' => $this->modelOne->id, $this->box->db_column => ['Red']]))->assertSessionHasNoErrors();

        $json = $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset))->assertOk()->json('custom_fields');
        $this->assertSame('OLD-SECRET', $json[$this->secret->name]['value']);
        $this->assertArrayNotHasKey($this->other->name, $json, 'Model Two\'s field is hidden again.');
        $this->assertSame('v', $this->stored($asset)['other'], '...and kept.');
    }

    #[Test]
    public function bulk_model_change_enforces_destination_rules(): void
    {
        $a = $this->oldAsset();
        $b = $this->oldAsset();
        $user = $this->editor();

        // Required destination field missing: every asset refused, none moved.
        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => [$a->id, $b->id], 'model_id' => $this->modelTwo->id]);
        $this->assertSame([$this->modelOne->id, $this->modelOne->id], [$this->stored($a)['model_id'], $this->stored($b)['model_id']]);

        // A field of the OLD fieldset with a model change: whole request refused.
        $this->post(route('hardware/bulksave'), ['ids' => [$a->id, $b->id], 'model_id' => $this->modelTwo->id, $this->other->db_column => 'v', $this->box->db_column => 'Blue'])
            ->assertSessionHas('error', trans('admin/hardware/message.bulk_custom_field_ineligible'));
        $this->assertSame([$this->modelOne->id, 'Red', null], [$this->stored($a)['model_id'], $this->stored($a)['box'], $this->stored($a)['other']]);

        // Clearing an old-fieldset field with a model change: refused too.
        $this->post(route('hardware/bulksave'), ['ids' => [$a->id], 'model_id' => $this->modelTwo->id, $this->other->db_column => 'v', 'null'.$this->box->db_column => '1'])
            ->assertSessionHas('error', trans('admin/hardware/message.bulk_custom_field_ineligible'));
        $this->assertSame('Red', $this->stored($a)['box']);

        // Valid: destination field supplied.
        $this->post(route('hardware/bulksave'), ['ids' => [$a->id, $b->id], 'model_id' => $this->modelTwo->id, $this->other->db_column => 'v'])->assertSessionHasNoErrors();
        foreach ([$a, $b] as $asset) {
            $this->assertSame([$this->modelTwo->id, 'v', 'Red', 'OLD-SECRET'], [$this->stored($asset)['model_id'], $this->stored($asset)['other'], $this->stored($asset)['box'], $this->stored($asset)['secret']]);
        }
    }

    #[Test]
    public function bulk_destination_needs_category_edit(): void
    {
        $asset = $this->oldAsset();
        $user = $this->member(['assets.view' => '1', 'assets.edit' => '1'], [[[$this->catA, ['view', 'update']], [$this->catB, ['view']]]]);

        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => [$asset->id], 'model_id' => $this->modelB->id])->assertSessionHasErrors('model_id');
        $this->assertSame($this->modelOne->id, $this->stored($asset)['model_id']);
    }

    #[Test]
    public function bulk_edit_without_a_model_change_applies_a_field_only_where_it_is_eligible(): void
    {
        $one = $this->oldAsset();
        $two = $this->assetOf($this->modelTwo, [$this->other->db_column => 'o']);
        $user = $this->editor();

        // Box belongs to Model One only: written to $one, never to $two.
        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => [$one->id, $two->id], $this->box->db_column => 'Blue'])->assertSessionHasNoErrors();
        $this->assertSame('Blue', $this->stored($one)['box']);
        $this->assertNull($this->stored($two)['box']);

        // A field no selected asset can store refuses the whole request.
        $this->post(route('hardware/bulksave'), ['ids' => [$one->id, $two->id], 'name' => 'Bulk', $this->orphan->db_column => 'x'])
            ->assertSessionHas('error', trans('admin/hardware/message.bulk_custom_field_ineligible'));
        $this->assertNull(DB::table('assets')->whereIn('id', [$one->id, $two->id])->whereNotNull($this->orphan->db_column)->value('id'));
        $this->assertNotSame('Bulk', Asset::withoutGlobalScopes()->find($one->id)->name);
    }

    #[Test]
    public function api_bulk_edit_follows_the_same_rules(): void
    {
        $one = $this->oldAsset();
        $two = $this->assetOf($this->modelTwo, [$this->other->db_column => 'o']);
        $user = $this->editor();

        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.bulk-update'), ['ids' => [$one->id, $two->id], 'name' => 'Bulk', $this->orphan->db_column => 'x']);
        $this->assertSame('error', $response->json('status'));
        $this->assertSame('Before', Asset::withoutGlobalScopes()->find($one->id)->name);

        $this->actingAsForApi($user)->patchJson(route('api.assets.bulk-update'), ['ids' => [$one->id, $two->id], $this->box->db_column => 'Blue'])->assertJsonPath('status', 'success');
        $this->assertSame(['Blue', null], [$this->stored($one)['box'], $this->stored($two)['box']]);

        // With a model change the destination decides; its required field is enforced per asset.
        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.bulk-update'), ['ids' => [$one->id], 'model_id' => $this->modelTwo->id, $this->box->db_column => 'Red']);
        $this->assertSame('error', $response->json('status'));
        $response = $this->actingAsForApi($user)->patchJson(route('api.assets.bulk-update'), ['ids' => [$one->id], 'model_id' => $this->modelTwo->id]);
        $this->assertSame('error', $response->json('status'));
        $this->assertSame($this->modelOne->id, $this->stored($one)['model_id']);
    }

    #[Test]
    public function bulk_custom_fields_load_without_per_asset_queries(): void
    {
        $user = $this->editor();
        $count = function (int $assets) use ($user): int {
            $ids = [];
            for ($i = 0; $i < $assets; $i++) {
                $ids[] = $this->oldAsset()->id;
            }
            $this->actingAs($user, 'web');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->post(route('hardware/bulksave'), ['ids' => $ids, $this->orphan->db_column => 'x']);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // A refused request costs the same for 2 and 12 assets (after one
        // warm-up request fills the per-request permission caches).
        $count(1);
        $this->assertSame($count(2), $count(12));
    }
}
