<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Assets\CategoryPermissions\BuildsWriteEnforcementFixture;

/**
 * ERS Phase 6A test fixture: the Phase 5 category tree (random names, one
 * model and asset per live final category), a faked private disk, and
 * helpers to drive the secure import as a real user. Access comes only
 * from permission-group grants; no compatibility bypass is used.
 */
trait BuildsAssetImportFixture
{
    use BuildsWriteEnforcementFixture;

    /** Global permissions an ordinary importer needs. */
    protected const IMPORTER_PERMISSIONS = ['import' => '1', 'assets.view' => '1', 'assets.create' => '1'];

    /** Every table Phase 6A must never write to. */
    protected const PROTECTED_TABLES = [
        'assets', 'models', 'categories', 'status_labels', 'companies', 'locations', 'suppliers',
        'manufacturers', 'users', 'departments', 'custom_fields', 'custom_fieldsets',
        'custom_field_custom_fieldset', 'asset_category_permissions', 'imports', 'action_logs',
    ];

    protected const CSV = "Asset Tag,Serial Number,Laptop Model,Notes\nT-1,S-1,Model A,first\nT-2,S-2,Model B,second\n";

    protected Statuslabel $status;

    protected function setUpImportFixture(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->buildAssets();
        $this->status = $this->readyStatus();
    }

    /** An ordinary importer with category grants per group (e.g. [['leaf1' => ['create']]]). */
    protected function importer(array $groups = [['leaf1' => ['create']]], array $extra = []): User
    {
        return $this->writer($groups, self::IMPORTER_PERMISSIONS + $extra);
    }

    protected function csvFile(string $content = self::CSV, string $name = 'assets.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    protected function upload(User $user, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.import.store'), ['csv_file' => $file ?? $this->csvFile()]);
    }

    protected function newSession(User $user, string $content = self::CSV): AssetImportSession
    {
        $this->upload($user, $this->csvFile($content))->assertRedirect();

        return AssetImportSession::query()->where('created_by', $user->id)->latest('id')->firstOrFail();
    }

    protected function modelIn(string $key): AssetModel
    {
        return AssetModel::query()->findOrFail($this->asset[$key]->model_id);
    }

    /** Default target: leaf1, its fixed model, one fixed status, no company or location. */
    protected function targetInput(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->cat['leaf1']->id,
            'model_source' => AssetImportSession::SOURCE_FIXED,
            'model_id' => $this->asset['leaf1']->model_id,
            'status_source' => AssetImportSession::SOURCE_FIXED,
            'status_id' => $this->status->id,
            'company_source' => AssetImportSession::SOURCE_NONE,
            'location_source' => AssetImportSession::SOURCE_NONE,
        ], $overrides);
    }

    protected function postTarget(User $user, AssetImportSession $session, array $overrides = []): TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.import.target.update', $session->public_id), $this->targetInput($overrides));
    }

    protected function postMapping(User $user, AssetImportSession $session, array $mapping): TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.import.mapping.update', $session->public_id), ['mapping' => $mapping]);
    }

    /** Upload, configure the default target and save a minimal valid mapping. */
    protected function mappedSession(User $user): AssetImportSession
    {
        $session = $this->newSession($user);
        $this->postTarget($user, $session)->assertRedirect(route('hardware.import.mapping', $session->public_id));
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial'])
            ->assertRedirect(route('hardware.import.review', $session->public_id));

        return $session->fresh();
    }

    /**
     * Upload $csv, save the target (defaults overridden by $target) and the
     * mapping (column position => destination key); the session is ready to
     * validate.
     */
    protected function configuredSession(User $user, string $csv, array $target, array $mapping): AssetImportSession
    {
        $session = $this->newSession($user, $csv);
        $this->postTarget($user, $session, $target)->assertSessionHasNoErrors();
        $this->postMapping($user, $session, $mapping)->assertSessionHasNoErrors();

        return $session->fresh();
    }

    protected function postValidate(User $user, AssetImportSession $session): TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.import.validate', $session->public_id));
    }

    /** Run the Phase 6B validator directly as $user. */
    protected function validationOf(User $user, AssetImportSession $session): \App\Services\AssetImport\Validation\AssetImportValidationResult
    {
        $this->actingAs($user, 'web');

        return app(\App\Services\AssetImport\Validation\AssetImportValidator::class)->validate($session->fresh(), $user);
    }

    /** @return array<int, list<string>> row number => "field: message" errors */
    protected function errorsByRow(\App\Services\AssetImport\Validation\AssetImportValidationResult $result): array
    {
        $errors = [];
        foreach ($result->rows as $row) {
            $errors[$row->row] = array_map(fn ($e) => $e->field.': '.$e->message, $row->errors);
        }

        return $errors;
    }

    /** A custom field in a new fieldset assigned to $model. */
    protected function customFieldOn(AssetModel $model, array $attributes = [], bool $required = false): CustomField
    {
        $field = CustomField::factory()->create(array_merge(['name' => $this->randomName('Field'), 'format' => ''], $attributes));
        $this->assertNotNull($field->id, 'The custom field fixture was not saved: '.json_encode($field->getErrors()?->toArray()));
        $model->refresh();
        $fieldset = $model->fieldset_id ? CustomFieldset::query()->find($model->fieldset_id) : CustomFieldset::factory()->create();
        $fieldset->fields()->attach($field, ['order' => 1, 'required' => $required]);
        DB::table('models')->where('id', $model->id)->update(['fieldset_id' => $fieldset->id]);

        return $field;
    }

    /** @return array<string, array> full contents of every protected table */
    protected function protectedSnapshot(): array
    {
        $snapshot = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    /** @return list<string> stored import files */
    protected function storedFiles(): array
    {
        return AssetImportSessions::disk()->allFiles(config('asset_import.directory'));
    }
}
