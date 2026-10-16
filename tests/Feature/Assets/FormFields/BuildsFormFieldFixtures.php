<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * ERS Phase 7C test data, all with random names (the application never
 * refers to them):
 *
 *  - a navigation group holding two final categories, $catA and $catB;
 *  - fieldset $setOne: $plain (optional), $secret (encrypted, optional),
 *    $box (checkbox, optional); fieldset $setTwo: $other (required) and
 *    $plain again (shared);
 *  - $modelOne (catA, setOne), $modelTwo (catA, setTwo), $modelB (catB,
 *    setOne), $bare (catA, no fieldset);
 *  - $orphan: a custom field in no fieldset.
 *
 * Users get global permissions plus category grants from permission
 * groups only.
 */
trait BuildsFormFieldFixtures
{
    protected Category $nav;

    protected Category $catA;

    protected Category $catB;

    protected CustomField $plain;

    protected CustomField $secret;

    protected CustomField $box;

    protected CustomField $other;

    protected CustomField $orphan;

    protected CustomFieldset $setOne;

    protected CustomFieldset $setTwo;

    protected AssetModel $modelOne;

    protected AssetModel $modelTwo;

    protected AssetModel $modelB;

    protected AssetModel $bare;

    protected Statuslabel $ready;

    protected function buildFormFields(): void
    {
        $this->nav = Category::factory()->navigationGroup()->create(['name' => 'Nav '.uniqid()]);
        $this->catA = Category::factory()->assignableAssetCategory()->childOf($this->nav)->create(['name' => 'A '.uniqid()]);
        $this->catB = Category::factory()->assignableAssetCategory()->childOf($this->nav)->create(['name' => 'B '.uniqid()]);

        $this->plain = CustomField::factory()->create(['name' => 'Plain '.uniqid(), 'format' => '']);
        $this->secret = CustomField::factory()->create(['name' => 'Secret '.uniqid(), 'format' => '', 'field_encrypted' => 1]);
        $this->box = CustomField::factory()->create(['name' => 'Box '.uniqid(), 'format' => '', 'element' => 'checkbox', 'field_values' => "Red\r\nBlue"]);
        $this->other = CustomField::factory()->create(['name' => 'Other '.uniqid(), 'format' => '']);
        $this->orphan = CustomField::factory()->create(['name' => 'Orphan '.uniqid(), 'format' => '']);

        $this->setOne = CustomFieldset::factory()->create(['name' => 'One '.uniqid()]);
        $this->setOne->fields()->attach($this->plain->id, ['order' => 1, 'required' => 0]);
        $this->setOne->fields()->attach($this->secret->id, ['order' => 2, 'required' => 0]);
        $this->setOne->fields()->attach($this->box->id, ['order' => 3, 'required' => 0]);
        $this->setTwo = CustomFieldset::factory()->create(['name' => 'Two '.uniqid()]);
        $this->setTwo->fields()->attach($this->other->id, ['order' => 1, 'required' => 1]);
        $this->setTwo->fields()->attach($this->plain->id, ['order' => 2, 'required' => 0]);

        $this->modelOne = AssetModel::factory()->create(['category_id' => $this->catA->id, 'fieldset_id' => $this->setOne->id, 'name' => 'M1 '.uniqid()]);
        $this->modelTwo = AssetModel::factory()->create(['category_id' => $this->catA->id, 'fieldset_id' => $this->setTwo->id, 'name' => 'M2 '.uniqid()]);
        $this->modelB = AssetModel::factory()->create(['category_id' => $this->catB->id, 'fieldset_id' => $this->setOne->id, 'name' => 'MB '.uniqid()]);
        $this->bare = AssetModel::factory()->create(['category_id' => $this->catA->id, 'fieldset_id' => null, 'name' => 'Bare '.uniqid()]);

        foreach (['plain', 'secret', 'box', 'other', 'orphan'] as $name) {
            $this->{$name} = $this->{$name}->fresh();
        }
        $this->ready = Statuslabel::factory()->readyToDeploy()->create(['name' => 'Ready '.uniqid()]);
    }

    /**
     * A non-Super-User with $permissions and one permission group per
     * entry of $groups, each mapping a Category to operations
     * (view/create/update/delete).
     *
     * @param  list<array<int, array{0: Category, 1: list<string>}>>  $groups
     */
    protected function member(array $permissions, array $groups): User
    {
        $user = User::factory()->create(['permissions' => json_encode($permissions)]);
        foreach ($groups as $grants) {
            $group = Group::create(['name' => 'G '.uniqid(), 'permissions' => json_encode([])]);
            foreach ($grants as [$category, $operations]) {
                $row = new AssetCategoryPermission;
                $row->group_id = $group->id;
                $row->category_id = $category->id;
                foreach ($operations as $operation) {
                    $row->{'can_'.$operation} = true;
                }
                $row->save();
            }
            $user->groups()->attach($group->id);
        }
        app(AssetCategoryPermissionService::class)->flush();

        return $user->fresh();
    }

    /** Global asset permissions plus View/Create/Edit on both final categories. */
    protected function editor(array $extra = []): User
    {
        return $this->member(
            array_merge(['assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1'], $extra),
            [[[$this->catA, ['view', 'create', 'update']], [$this->catB, ['view', 'create', 'update']]]],
        );
    }

    /** An asset of $model with raw stored custom values (encrypted ones encrypted here). */
    protected function assetOf(AssetModel $model, array $values = [], array $attributes = []): Asset
    {
        // Created with the fieldset-less model, then moved, so a required
        // field of $model does not block the fixture.
        $asset = Asset::factory()->create(array_merge(['model_id' => $this->bare->id, 'status_id' => $this->ready->id], $attributes));
        DB::table('assets')->where('id', $asset->id)->update(['model_id' => $model->id]);
        if ($values !== []) {
            $raw = [];
            foreach ($values as $column => $value) {
                $raw[$column] = $column === $this->secret->db_column && $value !== null ? Crypt::encrypt($value) : $value;
            }
            DB::table('assets')->where('id', $asset->id)->update($raw);
        }

        return $asset->fresh();
    }

    /** Stored custom values of an asset, bypassing every scope (encrypted decrypted). */
    protected function stored(Asset $asset): array
    {
        $row = (array) Asset::withoutGlobalScopes()->getQuery()->where('id', $asset->id)->first();
        $values = [];
        foreach (['plain', 'secret', 'box', 'other', 'orphan'] as $name) {
            $value = $row[$this->{$name}->db_column] ?? null;
            $values[$name] = $name === 'secret' && $value !== null ? Crypt::decrypt($value) : $value;
        }
        $values['model_id'] = (int) $row['model_id'];

        return $values;
    }

    /** The standard web edit form submission for $asset, plus $fields. */
    protected function editPayload(Asset $asset, array $fields = []): array
    {
        return array_merge([
            'asset_tags' => [1 => $asset->asset_tag],
            'model_id' => $asset->model_id,
            'status_id' => $asset->status_id,
            'redirect_option' => 'index',
        ], $fields);
    }

    /** The standard web create form submission, plus $fields. */
    protected function createPayload(AssetModel $model, array $fields = [], string $tag = ''): array
    {
        return array_merge([
            'asset_tags' => [1 => $tag !== '' ? $tag : 'T-'.uniqid()],
            'model_id' => $model->id,
            'status_id' => $this->ready->id,
            'redirect_option' => 'index',
        ], $fields);
    }

    protected function companyMember(Company $company, User $user): User
    {
        $company->users()->save($user);

        return $user->fresh();
    }
}
