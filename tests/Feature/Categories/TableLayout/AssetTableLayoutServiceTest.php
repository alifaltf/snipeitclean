<?php

namespace Tests\Feature\Categories\TableLayout;

use App\Actions\Categories\DestroyCategoryAction;
use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Services\AssetTableLayout\AssetTableColumn;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutForm;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssetTableLayoutServiceTest extends TestCase
{
    use BuildsTableLayoutFixtures;

    private function assertRejected(callable $callback, string $messageKey, array $replace = []): void
    {
        try {
            $callback();
            $this->fail('Expected the layout to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame([AssetTableLayoutService::ERROR_KEY => [trans($messageKey, $replace)]], $exception->errors());
        }
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    #[Test]
    public function the_catalogue_offers_translated_standard_fields_and_a_safe_default(): void
    {
        $this->assertSame(['standard:asset_tag', 'standard:name', 'standard:model', 'standard:serial', 'standard:status'], AssetTableFieldCatalog::DEFAULT_KEYS);
        foreach (AssetTableFieldCatalog::DEFAULT_KEYS as $key) {
            $this->assertTrue(AssetTableFieldCatalog::isStandardKey($key));
        }
        foreach (AssetTableFieldCatalog::standardColumns() as $column) {
            $this->assertSame(AssetTableFieldCatalog::KIND_STANDARD, $column->kind);
            $this->assertNotSame('', $column->label);
            $this->assertStringNotContainsString('/', $column->label, 'Every label is translated.');
        }
        $this->assertSame(trans('admin/hardware/table.asset_tag'), AssetTableFieldCatalog::standardColumn('standard:asset_tag')->label);

        $this->assertSame(['kind' => 'custom_field', 'id' => 12], AssetTableFieldCatalog::parse('custom_field:12'));
        foreach (['standard:category', 'standard:id', 'standard:actions', 'custom_field:0', 'custom_field:-1', 'custom_field:01', 'custom_field:99999999999', 'Standard:name', 'standard:name ', ' standard:name', 'name', '', 'custom_field:1.5'] as $bad) {
            $this->assertNull(AssetTableFieldCatalog::parse($bad), $bad);
        }
        foreach ([null, 1, ['standard:name'], (object) []] as $bad) {
            $this->assertNull(AssetTableFieldCatalog::parse($bad));
        }
    }

    // ------------------------------------------------------------------
    // Effective layout
    // ------------------------------------------------------------------

    #[Test]
    public function an_unconfigured_final_category_uses_the_default_layout(): void
    {
        $category = $this->finalAssetCategory();

        $this->assertFalse($this->layouts()->hasExplicitLayout($category));
        $this->assertSame(AssetTableFieldCatalog::DEFAULT_KEYS, $this->layouts()->effectiveKeys($category));
        $this->assertSame([trans('admin/hardware/table.asset_tag'), trans('general.name'), trans('admin/hardware/form.model'), trans('admin/hardware/form.serial'), trans('admin/hardware/table.status')],
            array_map(fn (AssetTableColumn $c) => $c->label, $this->layouts()->effectiveColumns($category)));
    }

    #[Test]
    public function an_explicit_layout_is_returned_in_its_saved_order(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $keys = ['standard:serial', $this->customKey($field), 'standard:asset_tag', 'standard:location'];

        $this->assertSame($keys, $this->layouts()->save($category, $keys));

        $this->assertTrue($this->layouts()->hasExplicitLayout($category));
        $this->assertSame($keys, $this->storedKeys($category));
        $this->assertSame([1, 2, 3, 4], $this->storedPositions($category));
        $this->assertSame($keys, $this->layouts()->effectiveKeys($category));
        $custom = $this->layouts()->effectiveColumns($category)[1];
        $this->assertSame($field->name, $custom->label, 'Custom-field labels come from the database.');
        $this->assertSame((int) $field->id, $custom->customFieldId);
        $this->assertSame($keys, $category->assetTableColumns()->pluck('field_key')->all());
    }

    #[Test]
    public function selectable_columns_are_the_standard_fields_and_the_categorys_eligible_custom_fields(): void
    {
        $category = $this->finalAssetCategory();
        $used = $this->fieldUsedBy($category);
        $encrypted = $this->fieldUsedBy($category, ['field_encrypted' => 1]);
        $elsewhere = $this->fieldUsedBy($this->finalAssetCategory());
        $unused = CustomField::factory()->create();

        $selectable = $this->layouts()->selectableColumns($category);
        $keys = array_map(fn (AssetTableColumn $c) => $c->key, $selectable);

        $standard = array_map(fn (AssetTableColumn $c) => $c->key, AssetTableFieldCatalog::standardColumns());
        $this->assertSame($standard, array_slice($keys, 0, count($standard)));
        $this->assertContains($this->customKey($used), $keys);
        $this->assertContains($this->customKey($encrypted), $keys);
        $this->assertNotContains($this->customKey($elsewhere), $keys, 'A field used only in another category is not offered.');
        $this->assertNotContains($this->customKey($unused), $keys, 'A field in no fieldset is not offered.');

        $encryptedColumn = collect($selectable)->firstWhere('key', $this->customKey($encrypted));
        $this->assertTrue($encryptedColumn->encrypted);
        $this->assertTrue($encryptedColumn->isCustomField());
    }

    #[Test]
    public function a_field_is_eligible_only_through_a_live_model_of_the_category(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $model = AssetModel::query()->where('category_id', $category->id)->firstOrFail();

        $this->assertTrue($this->layouts()->eligibleCustomFields($category->id)->has($field->id));

        $model->delete(); // soft delete
        $this->assertFalse($this->layouts()->eligibleCustomFields($category->id)->has($field->id), 'Deleted models do not count.');
    }

    // ------------------------------------------------------------------
    // Saving: rejection
    // ------------------------------------------------------------------

    #[Test]
    public function malformed_unknown_and_duplicate_keys_are_rejected(): void
    {
        $category = $this->finalAssetCategory();
        $service = $this->layouts();

        $this->assertRejected(fn () => $service->save($category, 'standard:name'), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, ['a' => 'standard:name']), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, [['standard:name']]), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, [(object) ['key' => 'standard:name']]), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, [12]), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, ['custom_field:abc']), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, ['standard:name; drop table']), 'admin/categories/table_layout.message.invalid');
        $this->assertRejected(fn () => $service->save($category, ['standard:password']), 'admin/categories/table_layout.message.unknown_standard');
        $this->assertRejected(fn () => $service->save($category, ['standard:category']), 'admin/categories/table_layout.message.unknown_standard');
        $this->assertRejected(fn () => $service->save($category, ['standard:name', 'standard:name']), 'admin/categories/table_layout.message.duplicate');
        $this->assertRejected(fn () => $service->save($category, []), 'admin/categories/table_layout.message.empty');
        $this->assertRejected(
            fn () => $service->save($category, array_map(fn ($i) => 'custom_field:'.$i, range(1, AssetTableFieldCatalog::MAX_COLUMNS + 1))),
            'admin/categories/table_layout.message.too_many', ['max' => AssetTableFieldCatalog::MAX_COLUMNS]);

        $this->assertSame([], $this->storedKeys($category), 'Nothing was stored.');
    }

    #[Test]
    public function custom_fields_that_are_missing_or_not_used_in_the_category_are_rejected(): void
    {
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        $foreign = $this->fieldUsedBy($other);
        $deleted = $this->fieldUsedBy($category);
        $deletedKey = $this->customKey($deleted);
        $deleted->delete();
        $unused = CustomField::factory()->create();

        foreach ([$this->customKey($foreign), $deletedKey, $this->customKey($unused), 'custom_field:2147483647'] as $key) {
            $this->assertRejected(fn () => $this->layouts()->save($category, ['standard:asset_tag', $key]), 'admin/categories/table_layout.message.not_eligible');
        }
        $this->assertSame([], $this->storedKeys($category));
    }

    #[Test]
    public function navigation_groups_non_asset_and_deleted_categories_cannot_have_a_layout(): void
    {
        $group = $this->navigationGroupCategory();
        $accessories = Category::factory()->forAccessories()->create();
        $deleted = $this->finalAssetCategory();
        $deleted->delete();

        foreach ([$group, $accessories, $deleted, new Category] as $category) {
            $this->assertFalse($this->layouts()->isConfigurable($category));
            $this->assertSame([], $this->layouts()->effectiveColumns($category));
            $this->assertSame([], $this->layouts()->selectableColumns($category));
            $this->assertRejected(fn () => $this->layouts()->save($category, ['standard:asset_tag']), 'admin/categories/table_layout.message.not_configurable');
            $this->assertRejected(fn () => $this->layouts()->reset($category), 'admin/categories/table_layout.message.not_configurable');
        }
        $this->assertSame(0, DB::table('asset_category_columns')->count());

        // Rows planted directly for a navigation group are never used.
        DB::table('asset_category_columns')->insert(['category_id' => $group->id, 'field_key' => 'standard:serial', 'position' => 1]);
        $this->assertSame([], $this->layouts()->effectiveColumns($group));
        $this->assertFalse($this->layouts()->hasExplicitLayout($group));
    }

    #[Test]
    public function a_category_whose_stored_state_changed_is_rejected_at_save_time(): void
    {
        $category = $this->finalAssetCategory();
        // Another request turned it into a navigation group after it was loaded.
        DB::table('categories')->where('id', $category->id)->update(['is_assignable' => false]);

        $this->assertRejected(fn () => $this->layouts()->save($category, ['standard:asset_tag']), 'admin/categories/table_layout.message.not_configurable');
        $this->assertSame([], $this->storedKeys($category));
    }

    // ------------------------------------------------------------------
    // Saving: replacement, stale keys, reset
    // ------------------------------------------------------------------

    #[Test]
    public function saving_again_replaces_the_whole_layout_last_save_wins(): void
    {
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        $this->layouts()->save($other, ['standard:notes']);

        $this->layouts()->save($category, ['standard:asset_tag', 'standard:name', 'standard:serial']);
        $this->layouts()->save($category, ['standard:serial', 'standard:asset_tag']);
        $this->layouts()->save($category, ['standard:serial', 'standard:asset_tag']); // repeated submission

        $this->assertSame(['standard:serial', 'standard:asset_tag'], $this->storedKeys($category));
        $this->assertSame([1, 2], $this->storedPositions($category));
        $this->assertSame(['standard:notes'], $this->storedKeys($other), 'Other categories are untouched.');
    }

    #[Test]
    public function stored_keys_that_become_ineligible_are_ignored_not_shown(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $this->layouts()->save($category, ['standard:asset_tag', $this->customKey($field), 'standard:serial']);

        // The model stops using the fieldset: the column disappears from the
        // effective layout; the stored row and the field's data are kept.
        AssetModel::query()->where('category_id', $category->id)->update(['fieldset_id' => null]);
        $this->assertSame(['standard:asset_tag', 'standard:serial'], $this->layouts()->effectiveKeys($category));
        $this->assertSame(3, DB::table('asset_category_columns')->where('category_id', $category->id)->count());
        $this->assertTrue(CustomField::query()->whereKey($field->id)->exists());

        // Stored keys that are invalid altogether (planted) are ignored too.
        DB::table('asset_category_columns')->where('category_id', $category->id)->delete();
        DB::table('asset_category_columns')->insert([
            ['category_id' => $category->id, 'field_key' => 'standard:password', 'position' => 1],
            ['category_id' => $category->id, 'field_key' => 'custom_field:999999', 'position' => 2],
        ]);
        $this->assertSame(AssetTableFieldCatalog::DEFAULT_KEYS, $this->layouts()->effectiveKeys($category), 'Nothing usable left: the default applies.');
    }

    #[Test]
    public function reset_deletes_only_this_categorys_layout_and_restores_the_default(): void
    {
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        $this->layouts()->save($category, ['standard:serial']);
        $this->layouts()->save($other, ['standard:notes']);

        $this->layouts()->reset($category);
        $this->layouts()->reset($category); // repeated reset is harmless

        $this->assertSame([], $this->storedKeys($category));
        $this->assertSame(AssetTableFieldCatalog::DEFAULT_KEYS, $this->layouts()->effectiveKeys($category));
        $this->assertSame(['standard:notes'], $this->storedKeys($other));
    }

    #[Test]
    public function new_custom_fields_are_not_added_to_existing_layouts(): void
    {
        $category = $this->finalAssetCategory();
        $this->layouts()->save($category, ['standard:asset_tag']);
        $this->fieldUsedBy($category);

        $this->assertSame(['standard:asset_tag'], $this->layouts()->effectiveKeys($category));
        $this->assertSame(AssetTableFieldCatalog::DEFAULT_KEYS, $this->layouts()->effectiveKeys($this->finalAssetCategory()));
    }

    #[Test]
    public function layouts_never_change_assets_or_custom_field_values(): void
    {
        $category = $this->finalAssetCategory();
        $field = $this->fieldUsedBy($category);
        $model = AssetModel::query()->where('category_id', $category->id)->firstOrFail();
        $asset = Asset::factory()->create(['model_id' => $model->id]);
        DB::table('assets')->where('id', $asset->id)->update([$field->db_column => 'kept value']);
        $before = (array) DB::table('assets')->where('id', $asset->id)->first();
        $fieldBefore = (array) DB::table('custom_fields')->where('id', $field->id)->first();

        $this->layouts()->save($category, ['standard:asset_tag', $this->customKey($field)]);
        $this->layouts()->save($category, ['standard:asset_tag']); // hide the field
        $this->layouts()->reset($category);

        $this->assertSame($before, (array) DB::table('assets')->where('id', $asset->id)->first());
        $this->assertSame($fieldBefore, (array) DB::table('custom_fields')->where('id', $field->id)->first());
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    #[Test]
    public function deleting_a_category_removes_only_its_layout(): void
    {
        $category = $this->finalAssetCategory();
        $other = $this->finalAssetCategory();
        $this->layouts()->save($category, ['standard:serial']);
        $this->layouts()->save($other, ['standard:serial']);

        DestroyCategoryAction::run($category);

        $this->assertSame([], $this->storedKeys($category));
        $this->assertSame(['standard:serial'], $this->storedKeys($other));
    }

    #[Test]
    public function converting_a_category_to_a_navigation_group_or_another_type_removes_its_layout(): void
    {
        $this->actingAs($this->superUser());
        $group = $this->finalAssetCategory();
        $this->layouts()->save($group, ['standard:serial']);
        SaveCategoryHierarchyAction::run($group->fresh(), ['is_assignable' => '0']);
        $this->assertSame([], $this->storedKeys($group));
        $this->assertFalse($this->layouts()->isConfigurable($group->fresh()));

        $retyped = $this->finalAssetCategory();
        $this->layouts()->save($retyped, ['standard:serial']);
        $retyped = $retyped->fresh();
        $retyped->category_type = 'accessory';
        SaveCategoryHierarchyAction::run($retyped);
        $this->assertSame([], $this->storedKeys($retyped));

        // An ordinary update of a final category keeps its layout.
        $kept = $this->finalAssetCategory();
        $this->layouts()->save($kept, ['standard:serial']);
        $kept = $kept->fresh();
        $kept->notes = 'changed';
        SaveCategoryHierarchyAction::run($kept);
        $this->assertSame(['standard:serial'], $this->storedKeys($kept));
    }

    // ------------------------------------------------------------------
    // Form parsing
    // ------------------------------------------------------------------

    #[Test]
    public function the_form_orders_shown_columns_by_number_then_row_order(): void
    {
        $keys = AssetTableLayoutForm::keys([
            ['key' => 'standard:asset_tag', 'show' => '1', 'position' => '2'],
            ['key' => 'standard:name', 'position' => '1'],               // not shown
            ['key' => 'standard:serial', 'show' => '1', 'position' => '1'],
            ['key' => 'standard:status', 'show' => '1', 'position' => ' 2 '],
            ['key' => 'standard:model'],
        ]);

        $this->assertSame(['standard:serial', 'standard:asset_tag', 'standard:status'], $keys);
    }

    #[Test]
    public function the_form_rejects_malformed_rows_and_reports_bad_order_numbers_per_row(): void
    {
        $invalid = [trans('admin/categories/table_layout.message.invalid')];
        foreach ([
            null, 'x', [], ['a' => ['key' => 'standard:name']], [['key' => ['standard:name']]], [['key' => 'standard:name', 'show' => 'yes', 'position' => '1']],
            [['key' => 'standard:name', 'show' => '1', 'position' => ['1']]], [['key' => 'standard:name', 'extra' => '1']], ['standard:name'], [['show' => '1', 'position' => '1']],
        ] as $rows) {
            try {
                AssetTableLayoutForm::keys($rows);
                $this->fail('Accepted '.json_encode($rows));
            } catch (ValidationException $exception) {
                $this->assertSame(['columns' => $invalid], $exception->errors());
            }
        }

        try {
            AssetTableLayoutForm::keys([['key' => 'standard:name', 'show' => '1', 'position' => '1'], ['key' => 'standard:name', 'position' => '2']]);
            $this->fail('Accepted a repeated key.');
        } catch (ValidationException $exception) {
            $this->assertSame(['columns' => [trans('admin/categories/table_layout.message.duplicate')]], $exception->errors());
        }

        try {
            AssetTableLayoutForm::keys([
                ['key' => 'standard:name', 'show' => '1', 'position' => '0'],
                ['key' => 'standard:serial', 'show' => '1', 'position' => ''],
                ['key' => 'standard:status', 'show' => '1', 'position' => '1000'],
                ['key' => 'standard:model', 'show' => '1', 'position' => '1.5'],
                ['key' => 'standard:notes', 'position' => 'junk'], // not shown: ignored
            ]);
            $this->fail('Accepted bad order numbers.');
        } catch (ValidationException $exception) {
            $this->assertSame(['columns.0.position', 'columns.1.position', 'columns.2.position', 'columns.3.position'], array_keys($exception->errors()));
        }
    }

    #[Test]
    public function the_layout_code_names_no_category(): void
    {
        $files = [
            'app/Services/AssetTableLayout/AssetTableFieldCatalog.php',
            'app/Services/AssetTableLayout/AssetTableLayoutService.php',
            'app/Services/AssetTableLayout/AssetTableLayoutAccess.php',
            'app/Services/AssetTableLayout/AssetTableLayoutForm.php',
            'app/Services/AssetTableLayout/AssetTableColumn.php',
            'app/Models/AssetCategoryColumn.php',
            'app/Http/Controllers/CategoryTableLayoutController.php',
            'resources/views/categories/table-layout.blade.php',
            'resources/lang/en-US/admin/categories/table_layout.php',
            'database/migrations/2026_10_12_000000_create_asset_category_columns_table.php',
        ];
        foreach ($files as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertNotFalse($source, $file);
            foreach (['Laptop', 'Desktop', 'Monitor', 'Phone', 'Chair', 'Hardware', 'Software', 'Fixed', 'Furniture', 'Equipment', 'Drone'] as $name) {
                // Case-sensitive: "admin/hardware/..." translation keys are Snipe-IT's namespace, not a category.
                $this->assertDoesNotMatchRegularExpression('/\b'.$name.'\b/', $source, "$file mentions $name");
            }
            // No category id literals either (e.g. category_id = 3, ->find(5)).
            $this->assertDoesNotMatchRegularExpression('/category_id[\'"]?\s*(=>|==|=|,)\s*[0-9]/', $source, "$file hardcodes a category id");
        }
    }

    private function superUser(): \App\Models\User
    {
        return \App\Models\User::factory()->superuser()->create();
    }
}
