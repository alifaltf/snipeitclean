<?php

namespace Tests\Feature\Categories\TableLayout;

use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\User;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;

/**
 * Test data for ERS Phase 7A layouts. Category and field names are random
 * test data; nothing here (or in the application) depends on them.
 */
trait BuildsTableLayoutFixtures
{
    protected function layouts(): AssetTableLayoutService
    {
        return app(AssetTableLayoutService::class);
    }

    protected function finalAssetCategory(?Category $parent = null): Category
    {
        $factory = Category::factory()->assignableAssetCategory();
        if ($parent !== null) {
            $factory = $factory->childOf($parent);
        }

        return $factory->create(['name' => 'Final '.uniqid()]);
    }

    protected function navigationGroupCategory(): Category
    {
        return Category::factory()->navigationGroup()->create(['name' => 'Group '.uniqid()]);
    }

    /** A custom field used by a live model of $category (through a new fieldset). */
    protected function fieldUsedBy(Category $category, array $attributes = []): CustomField
    {
        $field = CustomField::factory()->create(array_merge(['name' => 'Field '.uniqid()], $attributes));
        $fieldset = CustomFieldset::factory()->create(['name' => 'Fieldset '.uniqid()]);
        $fieldset->fields()->attach($field->id, ['order' => 1, 'required' => 0]);
        AssetModel::factory()->create(['category_id' => $category->id, 'fieldset_id' => $fieldset->id]);

        return $field->fresh();
    }

    protected function customKey(CustomField $field): string
    {
        return AssetTableFieldCatalog::customFieldKey((int) $field->id);
    }

    /** @return list<string> stored keys of the category, by position */
    protected function storedKeys(Category $category): array
    {
        return DB::table('asset_category_columns')->where('category_id', $category->id)->orderBy('position')->pluck('field_key')->all();
    }

    /** @return list<int> stored positions of the category, ascending */
    protected function storedPositions(Category $category): array
    {
        return DB::table('asset_category_columns')->where('category_id', $category->id)->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();
    }

    protected function layoutAdmin(): User
    {
        return User::factory()->create(['permissions' => json_encode(['categories.edit' => '1', 'customfields.edit' => '1'])]);
    }
}
