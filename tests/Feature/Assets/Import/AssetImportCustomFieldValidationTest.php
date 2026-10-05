<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6B: custom fields are validated against the fieldset of the
 * model each row resolves to. Required fields, formats, regexes and list
 * options are enforced; a value for a field the row's model does not use is
 * an error, never silently dropped; encrypted values are always masked.
 */
class AssetImportCustomFieldValidationTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function msg(string $key, array $replace = []): string
    {
        return trans('admin/hardware/import.validation.'.$key, $replace);
    }

    #[Test]
    public function required_fields_need_a_value_and_formats_are_enforced(): void
    {
        $user = $this->importer();
        $model = $this->modelIn('leaf1');
        $ram = $this->customFieldOn($model, ['name' => 'RAM', 'format' => 'NUMERIC'], true);
        $mac = $this->customFieldOn($model, ['name' => 'MAC', 'format' => 'MAC']);
        $code = $this->customFieldOn($model, ['name' => 'Code', 'format' => 'regex:/^[A-Z]{3}-[0-9]{2}$/']);
        $bought = $this->customFieldOn($model, ['name' => 'Bought', 'format' => 'DATE', 'element' => 'date_picker']);
        $os = $this->customFieldOn($model, ['name' => 'OS', 'element' => 'listbox', 'field_values' => "Windows\nLinux"]);
        $flags = $this->customFieldOn($model, ['name' => 'Flags', 'element' => 'checkbox', 'field_values' => "A\nB\nC"]);

        $csv = "Asset Tag,RAM,MAC,Code,Bought,OS,Flags\n"
            ."T-1,16,aa:bb:cc:dd:ee:ff,ABC-12,2026-01-31,Linux,\"A, C\"\n"
            ."T-2,,,,,,\n"
            ."T-3,16GB,not-a-mac,abc-12,31/01/2026,macOS,\"A, D\"\n";
        $session = $this->configuredSession($user, $csv, [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$ram->id, 2 => 'custom_field:'.$mac->id, 3 => 'custom_field:'.$code->id, 4 => 'custom_field:'.$bought->id, 5 => 'custom_field:'.$os->id, 6 => 'custom_field:'.$flags->id]);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame('16', $result->rows[0]->attributes[$ram->db_column_name()]);
        $this->assertSame(['custom_field:'.$ram->id.': '.$this->msg('required')], $errors[3]);
        $this->assertSame([
            'custom_field:'.$ram->id.': '.$this->msg('numeric'),
            'custom_field:'.$mac->id.': '.$this->msg('format'),
            'custom_field:'.$code->id.': '.$this->msg('format'),
            'custom_field:'.$bought->id.': '.$this->msg('date'),
            'custom_field:'.$os->id.': '.$this->msg('option'),
            'custom_field:'.$flags->id.': '.$this->msg('option'),
        ], $errors[4]);
    }

    #[Test]
    public function a_required_field_that_is_not_mapped_fails_every_row_of_that_model(): void
    {
        $user = $this->importer();
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Asset Owner Code'], true);
        $session = $this->configuredSession($user, "Asset Tag\nT-1\n", [], [0 => 'standard:asset_tag']);

        $result = $this->validationOf($user, $session);

        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('required_unmapped')], $this->errorsByRow($result)[2]);
        $this->assertSame('Asset Owner Code', $result->rows[0]->errors[0]->label);
    }

    #[Test]
    public function each_row_uses_the_fieldset_of_its_own_model_and_rejects_fields_that_do_not_apply(): void
    {
        $user = $this->importer();
        $laptop = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Laptop X']);
        $dock = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => 'Dock Y']);
        $ram = $this->customFieldOn($laptop, ['name' => 'RAM', 'format' => 'NUMERIC'], true);
        $ports = $this->customFieldOn($dock, ['name' => 'Ports', 'format' => 'NUMERIC'], true);

        $csv = "Asset Tag,Model,RAM,Ports\n"
            ."T-1,Laptop X,16,\n"
            ."T-2,Dock Y,,4\n"
            ."T-3,Laptop X,16,4\n"
            ."T-4,Dock Y,,\n";
        $session = $this->configuredSession($user, $csv, ['model_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:model', 2 => 'custom_field:'.$ram->id, 3 => 'custom_field:'.$ports->id]);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame([], $errors[3]);
        $this->assertArrayNotHasKey($ram->db_column_name(), $result->rows[1]->attributes);
        $this->assertSame(['custom_field:'.$ports->id.': '.$this->msg('field_not_applicable', ['model' => 'Laptop X'])], $errors[4], 'Never silently dropped.');
        $this->assertSame(['custom_field:'.$ports->id.': '.$this->msg('required')], $errors[5]);
        $this->assertSame('—', trans('admin/hardware/import.results.not_applicable'));
    }

    #[Test]
    public function unique_custom_fields_are_unique_in_the_file_and_among_live_assets(): void
    {
        $user = $this->importer();
        $model = $this->modelIn('leaf1');
        $field = $this->customFieldOn($model, ['name' => 'Licence Key', 'is_unique' => 1]);
        $this->asset['leaf1']->forceFill([$field->db_column_name() => 'TAKEN'])->saveQuietly();

        $session = $this->configuredSession($user, "Asset Tag,Licence Key\nT-1,K-1\nT-2,k-1\nT-3,taken\nT-4,K-4\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('duplicate_in_file', ['rows' => '2, 3'])], $errors[2]);
        $this->assertSame($errors[2], $errors[3]);
        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('custom_field_exists')], $errors[4]);
        $this->assertSame([], $errors[5]);
    }

    #[Test]
    public function encrypted_values_are_masked_everywhere_and_never_logged(): void
    {
        $user = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $secret = 'TopSecret-'.Str::random(10);
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Vault', 'field_encrypted' => 1, 'format' => 'regex:/^TopSecret-[A-Za-z0-9]+$/']);
        Log::spy();

        $csv = "Asset Tag,Vault\nT-1,$secret\nT-1,$secret-bad!\n";
        $session = $this->configuredSession($user, $csv, [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $result = $this->validationOf($user, $session);

        $this->assertSame(trans('admin/hardware/import.validation.masked'), $result->rows[0]->display['custom_field:'.$field->id]);
        $this->assertSame($secret, $result->rows[0]->attributes[$field->db_column_name()], 'Phase 6C receives the plain value to encrypt.');
        foreach ($result->errors() as $error) {
            $this->assertStringNotContainsString('TopSecret', $error->message.$error->label.$error->assetTag);
        }

        $this->postValidate($user, $session);
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('TopSecret', $html);
        $this->assertStringContainsString(e(trans('admin/hardware/import.validation.masked')), $html);

        $report = (string) $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('TopSecret', $report);

        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'TopSecret'))]);
        Log::shouldNotHaveReceived('debug', [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'TopSecret'))]);
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'TopSecret'))]);
    }

    #[Test]
    public function encrypted_unique_fields_refuse_every_non_empty_value_and_never_expose_it(): void
    {
        $user = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Vault Key', 'field_encrypted' => 1, 'is_unique' => 1]);
        $existing = 'Existing-'.Str::random(12);
        $shared = 'Shared-'.Str::random(12);
        $fresh = 'Fresh-'.Str::random(12);
        // Stored the way Snipe-IT stores it: encrypted with a random IV.
        $this->asset['leaf1']->forceFill([$field->db_column_name() => Crypt::encrypt($existing)])->saveQuietly();
        Log::spy();

        $csv = "Asset Tag,Vault Key\nT-1,$shared\nT-2,$shared\nT-3,$existing\nT-4,$fresh\nT-5,\nT-6,   \n";
        $session = $this->configuredSession($user, $csv, [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $refused = ['custom_field:'.$field->id.': '.$this->msg('encrypted_unique_unsupported')];
        $this->assertSame($refused, $errors[2], 'In-file duplicate is refused.');
        $this->assertSame($refused, $errors[3]);
        $this->assertSame($refused, $errors[4], 'Clash with an existing encrypted value is refused.');
        $this->assertSame($refused, $errors[5], 'Even a value that would be unique is refused: it cannot be checked.');
        $this->assertSame([], $errors[6], 'Blank stays allowed.');
        $this->assertSame([], $errors[7], 'Whitespace-only is blank.');
        $this->assertFalse($result->isEligible(), 'Unsupported validation is never eligible.');
        foreach ($result->rows as $row) {
            $this->assertArrayNotHasKey($field->db_column_name(), $row->attributes);
            $this->assertArrayNotHasKey('custom_field:'.$field->id, $row->display);
        }
        foreach ($result->errors() as $error) {
            $this->assertStringNotContainsString('Shared-', $error->message.$error->label.$error->assetTag);
        }

        $this->postValidate($user, $session);
        $this->assertSame(AssetImportSession::STATE_VALIDATION_FAILED, $session->fresh()->state);
        $this->assertNull($session->fresh()->validated_at);
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertOk()->getContent();
        $report = (string) $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))->assertOk()->getContent();
        foreach ([$existing, $shared, $fresh] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
            $this->assertStringNotContainsString($secret, $report);
        }
        $this->assertStringContainsString(e($this->msg('encrypted_unique_unsupported')), $html);

        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($m) => is_string($m) && preg_match('/Existing-|Shared-|Fresh-/', $m))]);
        }
        $this->assertSame($existing, Crypt::decrypt($this->asset['leaf1']->fresh()->{$field->db_column_name()}), 'Stored value untouched.');
    }

    #[Test]
    public function a_required_encrypted_unique_field_still_needs_a_value(): void
    {
        $user = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Vault Key', 'field_encrypted' => 1, 'is_unique' => 1], true);

        $session = $this->configuredSession($user, "Asset Tag,Vault Key\nT-1,\nT-2,Secret-1\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('required')], $errors[2]);
        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('encrypted_unique_unsupported')], $errors[3]);
    }

    #[Test]
    public function required_boolean_custom_fields_reject_blanks(): void
    {
        $user = $this->importer();
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Insured', 'format' => 'BOOLEAN'], true);

        $session = $this->configuredSession($user, "Asset Tag,Insured\nT-1,\nT-2,   \nT-3,maybe\nT-4,No\nT-5,yes\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('required')], $errors[2], 'Blank is not false.');
        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('required')], $errors[3], 'Whitespace-only is blank.');
        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('boolean')], $errors[4]);
        $this->assertSame([], $errors[5]);
        $this->assertSame('0', $result->rows[3]->attributes[$field->db_column_name()], 'A valid false is kept.');
        $this->assertSame('1', $result->rows[4]->attributes[$field->db_column_name()]);
    }

    #[Test]
    public function validation_creates_no_custom_fields_or_fieldsets(): void
    {
        $user = $this->importer();
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Colour']);
        $fields = CustomField::query()->count();
        $fieldsets = CustomFieldset::query()->count();

        $session = $this->configuredSession($user, "Asset Tag,Colour\nT-1,Red\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $this->postValidate($user, $session);

        $this->assertSame([$fields, $fieldsets], [CustomField::query()->count(), CustomFieldset::query()->count()]);
    }
}
