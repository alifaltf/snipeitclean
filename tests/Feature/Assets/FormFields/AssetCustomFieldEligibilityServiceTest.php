<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\AssetModel;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: the shared eligibility service itself, its query cost,
 * and that the Phase 7C code names no category.
 */
class AssetCustomFieldEligibilityServiceTest extends TestCase
{
    use BuildsFormFieldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
    }

    #[Test]
    public function eligibility_comes_from_the_live_model_and_its_current_fieldset(): void
    {
        $service = app(AssetCustomFieldEligibility::class);

        $this->assertSame([$this->plain->db_column, $this->secret->db_column, $this->box->db_column], $service->columnsFor($this->modelOne));
        $this->assertSame([$this->other->db_column, $this->plain->db_column], $service->columnsFor($this->modelTwo));
        $this->assertSame([], $service->columnsFor($this->bare));
        $this->assertSame([], $service->columnsFor(null));

        $this->modelOne->delete();
        $this->assertSame([], $service->columnsFor(AssetModel::withTrashed()->find($this->modelOne->id)), 'A soft-deleted model stores nothing.');

        $this->assertSame([$this->box->db_column, '_snipeit_unknown_1'], $service->ineligibleKeys($this->modelTwo, [$this->box->db_column, '_snipeit_unknown_1', $this->other->db_column]));
        $this->assertSame(['_snipeit_a', '_snipeit_b'], AssetCustomFieldEligibility::submittedKeys(['name' => 'x', '_snipeit_a' => 1, '_snipeit_b' => null, 'snipeit_c' => 1]));
    }

    #[Test]
    public function only_native_custom_field_columns_are_storable(): void
    {
        foreach (['_snipeit_colour_12', '_snipeit_a_b_c_3'] as $valid) {
            $this->assertTrue(AssetCustomFieldEligibility::isStorableColumn($valid), $valid);
        }
        foreach ([null, '', 'notes', 'asset_tag', 'company_id', '_snipeit_', '_snipeit_bad-col', '_snipeit_x;drop', '_snipeit_'.str_repeat('a', 56), ['_snipeit_a']] as $invalid) {
            $this->assertFalse(AssetCustomFieldEligibility::isStorableColumn($invalid), json_encode($invalid));
        }
    }

    #[Test]
    public function the_masked_placeholder_is_recognised(): void
    {
        $this->assertTrue(AssetCustomFieldEligibility::isMaskedPlaceholder(strtoupper(trans('admin/custom_fields/general.encrypted'))));
        $this->assertTrue(AssetCustomFieldEligibility::isMaskedPlaceholder(' '.AssetCustomFieldEligibility::maskedPlaceholder().' '));
        $this->assertTrue(AssetCustomFieldEligibility::isMaskedPlaceholder('Error Decrypting: The payload is invalid.'));
        foreach (['', 'encrypted value', null, ['ENCRYPTED'], 0] as $value) {
            $this->assertFalse(AssetCustomFieldEligibility::isMaskedPlaceholder($value), json_encode($value));
        }
    }

    #[Test]
    public function several_models_load_in_a_fixed_number_of_queries(): void
    {
        $service = app(AssetCustomFieldEligibility::class);
        $count = function (int $models) use ($service): int {
            $ids = [];
            for ($i = 0; $i < $models; $i++) {
                $ids[] = AssetModel::factory()->create(['category_id' => $this->catA->id, 'fieldset_id' => $i % 2 ? $this->setOne->id : $this->setTwo->id])->id;
            }
            $loaded = AssetModel::query()->whereIn('id', $ids)->get();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->columnsForAny($loaded);
            foreach ($loaded as $model) {
                $service->columnsFor($model);
            }
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(20));
    }

    #[Test]
    public function the_phase_7c_code_names_no_category(): void
    {
        foreach ([
            'app/Services/AssetFields/AssetCustomFieldEligibility.php',
            'app/Http/Requests/Traits/MayContainCustomFields.php',
            'resources/views/models/custom_fields_form.blade.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            foreach (['Laptop', 'Desktop', 'Monitor', 'Phone', 'Chair', 'Furniture', 'Hardware', 'Fixed', 'Digital Systems'] as $name) {
                $this->assertDoesNotMatchRegularExpression('/\b'.$name.'\b/', $source, "$file mentions $name");
            }
            $this->assertDoesNotMatchRegularExpression('/(category_id|asset_category)[\'"]?\s*(=>|==|=)\s*[0-9]/', $source, "$file hardcodes a category id");
        }
    }
}
