<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: a category table layout is presentation only. Adding,
 * removing, reordering, resetting or invalidating layout columns changes
 * no asset value, no fieldset membership, no create/edit validation and
 * no CSV mapping eligibility.
 */
class TableLayoutIndependenceTest extends TestCase
{
    use BuildsFormFieldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
    }

    private function key($field): string
    {
        return AssetTableFieldCatalog::customFieldKey((int) $field->id);
    }

    /** Everything a layout must never influence, as comparable data. */
    private function observable(User $user, $asset): array
    {
        $session = new AssetImportSession;
        $session->forceFill([
            'category_id' => $this->catA->id, 'model_source' => AssetImportSession::SOURCE_FIXED, 'model_id' => $this->modelOne->id,
            'status_source' => AssetImportSession::SOURCE_FIXED, 'company_source' => AssetImportSession::SOURCE_NONE, 'location_source' => AssetImportSession::SOURCE_NONE,
        ]);
        $fromCsv = (clone $session)->forceFill(['model_source' => AssetImportSession::SOURCE_COLUMN, 'model_id' => null]);
        $catalog = app(AssetImportFieldCatalog::class);

        $this->actingAs($user, 'web');
        $form = (string) $this->get(route('custom_fields/model', $this->modelOne->id).'?asset_operation=create')->getContent();
        $forged = $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->other->db_column => 'forged']))->getSession()->get('errors')?->keys();
        $required = $this->put(route('hardware.update', $asset), $this->editPayload($asset, ['model_id' => $this->modelTwo->id]))->getSession()->get('errors')?->keys();

        return [
            'assets' => DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'fieldsets' => DB::table('custom_field_custom_fieldset')->orderBy('custom_fieldset_id')->orderBy('custom_field_id')->get()->map(fn ($row) => (array) $row)->all(),
            'fields' => DB::table('custom_fields')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'models' => DB::table('models')->orderBy('id')->get(['id', 'fieldset_id', 'category_id'])->map(fn ($row) => (array) $row)->all(),
            'eligible' => [app(AssetCustomFieldEligibility::class)->columnsFor($this->modelOne->fresh()), app(AssetCustomFieldEligibility::class)->columnsFor($this->modelTwo->fresh())],
            'form' => preg_replace('/name="_token" value="[^"]*"/', '', $form),
            'forged' => $forged,
            'required' => $required,
            'csv fixed' => array_keys($catalog->destinations($session, $user)),
            'csv column' => array_keys($catalog->destinations($fromCsv, $user)),
        ];
    }

    #[Test]
    public function layout_changes_never_change_data_forms_or_csv_eligibility(): void
    {
        $user = $this->editor(['import' => '1']);
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'p', $this->box->db_column => 'Red']);
        $super = User::factory()->superuser()->create();
        $layouts = app(AssetTableLayoutService::class);
        $baseline = $this->observable($user, $asset);
        $this->assertContains('custom_field:'.$this->plain->id, $baseline['csv fixed']);
        $this->assertNotContains('custom_field:'.$this->other->id, $baseline['csv fixed']);
        $this->assertSame([$this->other->db_column], $baseline['forged']);
        $this->assertSame([$this->other->db_column], $baseline['required']);

        $steps = [
            'add a field only Model Two uses' => fn () => $layouts->save($this->catA->fresh(), ['standard:asset_tag', $this->key($this->other)]),
            'add more fields' => fn () => $layouts->save($this->catA->fresh(), ['standard:asset_tag', $this->key($this->other), $this->key($this->plain), $this->key($this->box)]),
            'reorder' => fn () => $layouts->save($this->catA->fresh(), [$this->key($this->box), 'standard:asset_tag', $this->key($this->plain), $this->key($this->other)]),
            'remove fields (a hidden column)' => fn () => $layouts->save($this->catA->fresh(), ['standard:asset_tag']),
            'invalidate a saved field' => function () use ($layouts) {
                $layouts->save($this->catA->fresh(), ['standard:asset_tag', $this->key($this->other)]);
                DB::table('models')->where('id', $this->modelTwo->id)->update(['fieldset_id' => null]);
                // The layout entry is now stale; restore the model afterwards
                // so only the layout differs from the baseline.
                $this->get(route('hardware.index', ['asset_category' => $this->catA->id]))->assertOk();
                DB::table('models')->where('id', $this->modelTwo->id)->update(['fieldset_id' => $this->setTwo->id]);
            },
            'reset' => fn () => $layouts->reset($this->catA->fresh()),
        ];

        foreach ($steps as $step => $change) {
            $this->actingAs($super, 'web');
            $change();
            $this->assertEquals($baseline, $this->observable($user, $asset), "After: $step");
        }
    }
}
