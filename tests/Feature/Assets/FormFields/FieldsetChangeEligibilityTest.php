<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\CustomField;
use App\Models\User;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: fieldset and custom-field changes after assets exist. The
 * forms always use the CURRENT eligibility, stored values are never
 * deleted, values that are no longer eligible are not shown, searched,
 * sorted, exported or returned, stale configuration never crashes, and a
 * Super Admin cannot write a structurally invalid field either.
 */
class FieldsetChangeEligibilityTest extends TestCase
{
    use BuildsFormFieldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
    }

    private function eligibleColumns(\App\Models\AssetModel $model): array
    {
        return app(AssetCustomFieldEligibility::class)->columnsFor($model->fresh());
    }

    /** Table API rows for catA as $user, keyed by asset tag. */
    private function tableRows(User $user, array $query = []): array
    {
        $rows = $this->actingAsForApi($user)->getJson(route('api.assets.index', array_merge(['asset_category' => $this->catA->id, 'asset_table_layout' => '1'], $query)))->assertOk()->json('rows');

        return collect($rows)->keyBy('asset_tag')->all();
    }

    #[Test]
    public function a_field_added_to_the_fieldset_becomes_eligible_everywhere(): void
    {
        $asset = $this->assetOf($this->modelOne);
        $user = $this->editor();
        $this->assertNotContains($this->orphan->db_column, $this->eligibleColumns($this->modelOne));
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->orphan->db_column => 'x']))->assertSessionHasErrors($this->orphan->db_column);

        $this->setOne->fields()->attach($this->orphan->id, ['order' => 9, 'required' => 0]);

        $this->assertContains($this->orphan->db_column, $this->eligibleColumns($this->modelOne));
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->orphan->db_column => 'now allowed']))->assertSessionHasNoErrors();
        $this->assertSame('now allowed', $this->stored($asset)['orphan']);
    }

    #[Test]
    public function a_field_removed_from_the_fieldset_is_kept_but_never_shown_or_accepted(): void
    {
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'needle-kept'], ['asset_tag' => 'KEEP-1']);
        app(AssetTableLayoutService::class)->save($this->catA->fresh(), ['standard:asset_tag', AssetTableFieldCatalog::customFieldKey($this->plain->id)]);
        // Plain stays eligible in catA through Model Two, so the layout keeps the column.
        $this->setOne->fields()->detach($this->plain->id);
        $user = $this->editor();

        $this->assertSame('needle-kept', $this->stored($asset)['plain'], 'Not deleted.');
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('needle-kept', $html);
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->plain->db_column => 'x']))->assertSessionHasErrors($this->plain->db_column);

        $json = $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('needle-kept', $json);

        // A Model Two asset still shows its live value (control).
        $this->assetOf($this->modelTwo, [$this->plain->db_column => 'needle-live', $this->other->db_column => 'o'], ['asset_tag' => 'LIVE-1']);
        $rows = $this->tableRows($user);
        $this->assertSame('needle-live', $rows['LIVE-1']['custom_fields'][$this->plain->name]['value']);
        $this->assertNull($rows['KEEP-1']['custom_fields'][$this->plain->name]['value'] ?? null, 'Not returned as a layout value.');
        $this->assertSame(['LIVE-1'], array_keys($this->tableRows($user, ['search' => 'needle'])), 'Not searchable.');
        $this->assertSame(['KEEP-1', 'LIVE-1'], array_keys($this->tableRows($user, ['sort' => $this->plain->db_column, 'order' => 'asc'])), 'Sorted as empty.');
        $export = $this->actingAs($user, 'web')->get(route('hardware.category-export', ['asset_category' => $this->catA->id]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('needle-kept', $export, 'Not exported.');
        $this->assertStringContainsString('needle-live', $export);
    }

    #[Test]
    public function a_model_moved_to_another_fieldset_uses_the_new_one(): void
    {
        $asset = $this->assetOf($this->modelOne, [$this->box->db_column => 'Red']);
        DB::table('models')->where('id', $this->modelOne->id)->update(['fieldset_id' => $this->setTwo->id]);
        $user = $this->editor();

        $this->assertEqualsCanonicalizing([$this->other->db_column, $this->plain->db_column], $this->eligibleColumns($this->modelOne));
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->box->db_column => ['Blue']]))->assertSessionHasErrors($this->box->db_column);
        // The new fieldset's required field applies to the next edit.
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'x']))->assertSessionHasErrors($this->other->db_column);
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->other->db_column => 'o']))->assertSessionHasNoErrors();
        $this->assertSame(['Red', 'o'], [$this->stored($asset)['box'], $this->stored($asset)['other']]);
    }

    #[Test]
    public function a_detached_or_missing_fieldset_offers_no_fields_and_does_not_crash(): void
    {
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'kept']);
        $user = $this->editor();
        $super = User::factory()->superuser()->create();

        foreach (['detached' => null, 'missing' => 999999] as $case => $fieldsetId) {
            DB::table('models')->where('id', $this->modelOne->id)->update(['fieldset_id' => $fieldsetId]);

            $this->assertSame([], $this->eligibleColumns($this->modelOne), $case);
            $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk();
            $this->get(route('custom_fields/model', $this->modelOne->id).'?asset_operation=update')->assertOk();
            foreach ([$user, $super] as $actor) {
                $this->actingAs($actor, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->plain->db_column => 'x']))->assertSessionHasErrors($this->plain->db_column);
            }
            $this->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'Renamed '.$case]))->assertSessionHasNoErrors();
            $this->assertSame('kept', $this->stored($asset)['plain'], $case);
            $this->tableRows($user);
            $this->actingAs($user, 'web')->get(route('hardware.index', ['asset_category' => $this->catA->id]))->assertOk();
        }
    }

    #[Test]
    public function a_deleted_field_leaves_a_stale_layout_entry_that_is_skipped(): void
    {
        $asset = $this->assetOf($this->modelOne, [], ['asset_tag' => 'DEL-1']);
        app(AssetTableLayoutService::class)->save($this->catA->fresh(), ['standard:asset_tag', AssetTableFieldCatalog::customFieldKey($this->box->id)]);
        $this->setOne->fields()->detach($this->box->id);
        $this->box->delete();
        $user = $this->editor();

        $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk();
        $this->get(route('hardware.index', ['asset_category' => $this->catA->id]))->assertOk();
        $columns = $this->actingAsForApi($user)->getJson(route('api.assets.index', ['asset_category' => $this->catA->id, 'asset_table_layout' => '1']))->assertOk()->json('layout.columns');
        $this->assertSame(['asset_tag'], array_column($columns, 'field'));
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'After delete']))->assertSessionHasNoErrors();
    }

    #[Test]
    public function an_encryption_change_masks_the_existing_value_and_protects_it(): void
    {
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'was-plain-text']);
        // The field becomes encrypted after values were stored as plain text.
        DB::table('custom_fields')->where('id', $this->plain->id)->update(['field_encrypted' => 1]);
        $user = $this->editor();

        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString('was-plain-text', $html);
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'x']))->assertSessionHasNoErrors();
        $this->assertSame('was-plain-text', DB::table('assets')->where('id', $asset->id)->value($this->plain->db_column), 'Not overwritten or cleared.');
    }

    #[Test]
    public function a_structurally_invalid_field_is_never_eligible_even_for_a_super_admin(): void
    {
        // A custom field row whose column is not a native custom-field
        // column (e.g. pointing at a standard asset column).
        $broken = CustomField::factory()->create(['name' => 'Broken '.uniqid(), 'format' => '']);
        DB::table('custom_fields')->where('id', $broken->id)->update(['db_column' => 'notes']);
        $this->setOne->fields()->attach($broken->id, ['order' => 8, 'required' => 0]);
        $asset = $this->assetOf($this->modelOne, [], ['notes' => 'original notes']);
        $super = User::factory()->superuser()->create();

        $this->assertNotContains('notes', $this->eligibleColumns($this->modelOne));
        $html = (string) $this->actingAs($super, 'web')->get(route('custom_fields/model', $this->modelOne->id).'?asset_operation=update')->assertOk()->getContent();
        $this->assertStringNotContainsString($broken->name, $html);
        $this->assertArrayNotHasKey($broken->id, app(AssetTableLayoutService::class)->eligibleCustomFields($this->catA->id)->all());
        $this->put(route('hardware.update', $asset), $this->editPayload($asset, ['notes' => 'original notes']))->assertSessionHasNoErrors();
        $this->assertSame('original notes', \App\Models\Asset::withoutGlobalScopes()->find($asset->id)->notes);
    }
}
