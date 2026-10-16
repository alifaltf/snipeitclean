<?php

namespace Tests\Feature\Assets\Import;

use App\Actions\AssetImport\ExecuteAssetImport;
use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\User;
use App\Services\AssetImport\AssetImportReadiness;
use App\Services\AssetImport\Execution\AssetImportExecutionOutcome;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: the secure CSV import uses the same model/fieldset rule as
 * the asset forms (AssetCustomFieldEligibility), never the category table
 * layout, and a session whose configuration changed after mapping or
 * validation is detected, cannot run, writes nothing and can be repaired.
 */
class AssetImportFieldEligibilityTest extends TestCase
{
    use BuildsAssetImportFixture;

    private AssetModel $model;

    private AssetModel $sibling;

    private CustomField $field;

    private CustomField $siblingField;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
        $this->model = $this->modelIn('leaf1');
        $this->field = $this->customFieldOn($this->model, ['name' => $this->randomName('Colour')]);
        // A second model of the same category with its own field.
        $this->sibling = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => $this->randomName('Sibling')]);
        $this->siblingField = $this->customFieldOn($this->sibling, ['name' => $this->randomName('Shelf')]);
        $this->model->refresh();
    }

    private function mappingHtml(User $user, AssetImportSession $session): string
    {
        return (string) $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk()->getContent();
    }

    private function validatedSession(User $user, string $csv, array $mapping, array $target = []): AssetImportSession
    {
        $session = $this->configuredSession($user, $csv, $target, $mapping);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->state, 'Fixture must validate.');

        return $session;
    }

    private function execute(User $user, AssetImportSession $session): AssetImportExecutionOutcome
    {
        $this->actingAs($user, 'web');

        return app(ExecuteAssetImport::class)->handle($user, $session->public_id, ExecuteAssetImport::confirmationToken($session->fresh()));
    }

    #[Test]
    public function the_mapping_offers_only_the_target_models_live_fieldset(): void
    {
        $user = $this->importer();
        $fixed = $this->configuredSession($user, "Asset Tag,Colour\nE-1,red\n", [], [0 => 'standard:asset_tag']);
        $html = $this->mappingHtml($user, $fixed);
        $this->assertStringContainsString(e($this->field->name), $html);
        $this->assertStringNotContainsString(e($this->siblingField->name), $html, 'Another model\'s field is not offered for a fixed model.');
        $this->assertStringContainsString('standard:asset_tag', $html, 'Standard fields stay available.');

        // A table layout showing the sibling field changes nothing.
        $this->actingAs(User::factory()->superuser()->create(), 'web');
        app(AssetTableLayoutService::class)->save($this->cat['leaf1']->fresh(), ['standard:asset_tag', AssetTableFieldCatalog::customFieldKey($this->siblingField->id)]);
        $this->assertStringNotContainsString(e($this->siblingField->name), $this->mappingHtml($user, $fixed));

        // Model from the CSV: the union of the category's live models, checked per row.
        $column = $this->configuredSession($user, "Asset Tag,Model\nE-2,x\n", ['model_source' => AssetImportSession::SOURCE_COLUMN, 'model_id' => null], [0 => 'standard:asset_tag', 1 => 'standard:model']);
        $html = $this->mappingHtml($user, $column);
        $this->assertStringContainsString(e($this->field->name), $html);
        $this->assertStringContainsString(e($this->siblingField->name), $html);
    }

    #[Test]
    public function a_forged_mapping_to_an_ineligible_field_is_rejected(): void
    {
        $user = $this->importer();
        $orphan = CustomField::factory()->create(['name' => $this->randomName('Orphan'), 'format' => '']);
        $elsewhere = $this->customFieldOn($this->modelIn('leaf2'), ['name' => $this->randomName('Elsewhere')]);
        $session = $this->newSession($user, "Asset Tag,Value\nE-1,v\n");
        $this->postTarget($user, $session)->assertSessionHasNoErrors();

        foreach ([$this->siblingField, $orphan, $elsewhere] as $field) {
            $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id])
                ->assertSessionHasErrors('mapping.1');
            $this->assertNotSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
        }
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$this->field->id])->assertSessionHasNoErrors();
    }

    #[Test]
    public function required_fields_are_validated_and_encrypted_values_stay_masked(): void
    {
        $user = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $required = $this->customFieldOn($this->model, ['name' => $this->randomName('Needed')], true);
        $secret = $this->customFieldOn($this->model, ['name' => $this->randomName('Vault'), 'field_encrypted' => 1, 'format' => 'NUMERIC']);
        $session = $this->configuredSession($user, "Asset Tag,Vault\nR-1,NOT-A-NUMBER-SECRET\nR-2,12345\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$secret->id]);

        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);
        $this->assertNotEmpty(array_filter($errors[2], fn ($e) => str_starts_with($e, 'custom_field:'.$required->id.':')), 'Unmapped required field reported.');
        $this->assertNotEmpty(array_filter($errors[2], fn ($e) => str_starts_with($e, 'custom_field:'.$secret->id.':')));
        $html = (string) $this->postValidate($user, $session)->getContent().(string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->getContent();
        $this->assertStringNotContainsString('NOT-A-NUMBER-SECRET', $html.json_encode($errors));
        $this->assertStringNotContainsString('12345', $html);
    }

    #[Test]
    public function a_hidden_layout_column_does_not_block_importing_an_eligible_field(): void
    {
        $user = $this->importer();
        $this->actingAs(User::factory()->superuser()->create(), 'web');
        app(AssetTableLayoutService::class)->save($this->cat['leaf1']->fresh(), ['standard:asset_tag']);
        $session = $this->validatedSession($user, "Asset Tag,Colour\nH-1,teal\n", [0 => 'standard:asset_tag', 1 => 'custom_field:'.$this->field->id]);

        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session)->status);
        $this->assertSame('teal', DB::table('assets')->where('asset_tag', 'H-1')->value($this->field->db_column_name()));
    }

    #[Test]
    public function a_mapping_made_stale_before_validation_is_detected_and_can_be_repaired(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Colour\nS-1,red\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$this->field->id]);
        CustomFieldset::query()->find($this->model->fieldset_id)->fields()->detach($this->field->id);

        $this->assertSame(AssetImportReadiness::NEEDS_MAPPING, app(AssetImportReadiness::class)->problem($session->fresh(), $user));
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.mapping', $session->public_id));
        $html = $this->mappingHtml($user, $session);
        $this->assertStringNotContainsString(e($this->field->name), $html, 'The removed field is not offered again.');

        // Repair: map the column to nothing (or another eligible field), then validate.
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => ''])->assertSessionHasNoErrors();
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function changesAfterValidation(): array
    {
        return [
            'mapped field removed from the fieldset' => ['removeField', AssetImportExecutionOutcome::NEEDS_RECONFIGURATION],
            'mapped field deleted' => ['deleteField', AssetImportExecutionOutcome::NEEDS_RECONFIGURATION],
            'model moved to another fieldset' => ['changeFieldset', AssetImportExecutionOutcome::NEEDS_RECONFIGURATION],
            'model moved to another category' => ['moveModel', AssetImportExecutionOutcome::NEEDS_RECONFIGURATION],
            'required field added' => ['addRequired', AssetImportExecutionOutcome::REVALIDATION_FAILED],
            'category grant revoked' => ['revokeGrant', AssetImportExecutionOutcome::NEEDS_RECONFIGURATION],
        ];
    }

    private function removeField(): void
    {
        CustomFieldset::query()->find($this->model->fieldset_id)->fields()->detach($this->field->id);
    }

    private function deleteField(): void
    {
        $this->removeField();
        $this->field->delete();
    }

    private function changeFieldset(): void
    {
        DB::table('models')->where('id', $this->model->id)->update(['fieldset_id' => $this->sibling->fresh()->fieldset_id]);
    }

    private function moveModel(): void
    {
        DB::table('models')->where('id', $this->model->id)->update(['category_id' => $this->cat['leaf2']->id]);
    }

    private function addRequired(): void
    {
        $this->customFieldOn($this->model, ['name' => $this->randomName('NowRequired')], true);
    }

    private function revokeGrant(): void
    {
        AssetCategoryPermission::query()->where('category_id', $this->cat['leaf1']->id)->update(['can_create' => false]);
        $this->flushPermissions();
    }

    #[Test]
    public function changes_after_validation_stop_execution_and_write_nothing(): void
    {
        foreach (self::changesAfterValidation() as $case => [$change, $expected]) {
            $this->tearDown();
            $this->setUp();
            $user = $this->importer();
            $session = $this->validatedSession($user, "Asset Tag,Colour\nV-1,red\nV-2,blue\n", [0 => 'standard:asset_tag', 1 => 'custom_field:'.$this->field->id]);
            $before = DB::table('assets')->orderBy('id')->get(['id', 'asset_tag', 'model_id', 'updated_at'])->map(fn ($row) => (array) $row)->all();

            $this->{$change}();
            $outcome = $this->execute($user, $session);

            $this->assertSame($expected, $outcome->status, $case);
            $this->assertSame($before, DB::table('assets')->orderBy('id')->get(['id', 'asset_tag', 'model_id', 'updated_at'])->map(fn ($row) => (array) $row)->all(), "$case: no partial writes");
            $this->assertFalse(DB::table('assets')->whereIn('asset_tag', ['V-1', 'V-2'])->exists(), $case);
            $this->assertNotSame(AssetImportSession::STATE_COMPLETED, $session->fresh()->state, $case);
        }
    }

    #[Test]
    public function the_http_answer_for_a_stale_session_is_generic(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Colour\nG-1,red\n", [0 => 'standard:asset_tag', 1 => 'custom_field:'.$this->field->id]);
        $this->removeField();
        $before = Asset::withoutGlobalScopes()->count();

        $response = $this->actingAs($user, 'web')->post(route('hardware.import.execute', $session->public_id), [
            'confirm' => '1', 'confirmation' => ExecuteAssetImport::confirmationToken($session->fresh()),
        ]);
        $response->assertRedirect(route('hardware.import.mapping', $session->public_id))
            ->assertSessionHas('warning', trans('admin/hardware/import.review.mapping_needed'));
        $this->assertSame($before, Asset::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_company_scope_change_stops_the_import(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $company = Company::factory()->create();
        $user = $this->importer();
        $company->users()->save($user);
        $session = $this->validatedSession($user->fresh(), "Asset Tag\nC-1\n", [0 => 'standard:asset_tag'], ['company_source' => AssetImportSession::SOURCE_FIXED, 'company_id' => $company->id]);
        $before = Asset::withoutGlobalScopes()->count();

        // The user moves to another company: the fixed company is now out of scope.
        $company->users()->detach($user->id);
        Company::factory()->create()->users()->save($user);
        Company::flushCompanyIdsCache(); // per-request cache (one request per PHP process in production)
        $outcome = $this->execute($user->fresh(), $session);

        $this->assertSame(AssetImportExecutionOutcome::NEEDS_RECONFIGURATION, $outcome->status);
        $this->assertSame(AssetImportReadiness::NEEDS_TARGET, $outcome->problem);
        $this->assertSame($before, Asset::withoutGlobalScopes()->count());
    }
}
