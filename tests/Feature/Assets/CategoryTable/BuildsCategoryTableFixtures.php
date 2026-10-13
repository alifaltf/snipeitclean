<?php

namespace Tests\Feature\Assets\CategoryTable;

use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryPermissionService;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ERS Phase 7B test data: a navigation group with a final category whose
 * model uses a fieldset with a plain and an encrypted custom field. All
 * names are random test data; the application never refers to them.
 */
trait BuildsCategoryTableFixtures
{
    protected Category $group;

    protected Category $category;

    protected AssetModel $model;

    protected CustomField $plainField;

    protected CustomField $encryptedField;

    protected function buildCategoryTable(): void
    {
        $this->group = Category::factory()->navigationGroup()->create(['name' => 'Group '.uniqid()]);
        $this->category = Category::factory()->assignableAssetCategory()->childOf($this->group)->create(['name' => 'Final '.uniqid()]);
        $this->plainField = CustomField::factory()->create(['name' => 'Plain '.uniqid(), 'format' => '']);
        $this->encryptedField = CustomField::factory()->create(['name' => 'Secret '.uniqid(), 'format' => '', 'field_encrypted' => 1]);
        $fieldset = CustomFieldset::factory()->create(['name' => 'Fieldset '.uniqid()]);
        $fieldset->fields()->attach($this->plainField->id, ['order' => 1, 'required' => 0]);
        $fieldset->fields()->attach($this->encryptedField->id, ['order' => 2, 'required' => 0]);
        $this->model = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => $fieldset->id, 'name' => 'Model '.uniqid()]);
        $this->plainField = $this->plainField->fresh();
        $this->encryptedField = $this->encryptedField->fresh();
    }

    protected function layout(array $keys): void
    {
        app(AssetTableLayoutService::class)->save($this->category->fresh(), $keys);
    }

    protected function key(CustomField $field): string
    {
        return AssetTableFieldCatalog::customFieldKey((int) $field->id);
    }

    /** An asset of the fixture model, with optional plain/encrypted values. */
    protected function asset(array $attributes = [], ?string $plain = null, ?string $secret = null, ?AssetModel $model = null): Asset
    {
        $asset = Asset::factory()->create(array_merge(['model_id' => ($model ?? $this->model)->id], $attributes));
        $values = [];
        if ($plain !== null) {
            $values[$this->plainField->db_column] = $plain;
        }
        if ($secret !== null) {
            $values[$this->encryptedField->db_column] = Crypt::encrypt($secret);
        }
        if ($values !== []) {
            DB::table('assets')->where('id', $asset->id)->update($values);
        }

        return $asset->fresh();
    }

    /** A non-Super-User with global asset view and category View on $categories. */
    protected function viewer(array $extraPermissions = [], Category ...$categories): User
    {
        $user = User::factory()->create(['permissions' => json_encode(array_merge(['assets.view' => '1'], $extraPermissions))]);
        $group = Group::create(['name' => 'Grants '.uniqid(), 'permissions' => json_encode([])]);
        foreach ($categories as $category) {
            $grant = new AssetCategoryPermission;
            $grant->group_id = $group->id;
            $grant->category_id = $category->id;
            $grant->can_view = true;
            $grant->save();
        }
        $user->groups()->attach($group->id);
        app(AssetCategoryPermissionService::class)->flush();

        return $user->fresh();
    }

    protected function layoutQuery(array $extra = []): array
    {
        return array_merge(['asset_category' => $this->category->id, 'asset_table_layout' => '1'], $extra);
    }
}
