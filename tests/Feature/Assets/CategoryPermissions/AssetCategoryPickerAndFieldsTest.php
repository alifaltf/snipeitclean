<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\User;
use App\Rules\AuthorisedAssetModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2: the operation-specific model pickers and the custom-field
 * loader used by the asset create/edit forms follow EXACTLY the rule that
 * AuthorisedAssetModel enforces on the server: valid targets in categories
 * with category Create (create) or category Edit (update). View is not
 * required for these; the general picker stays View-filtered.
 */
class AssetCategoryPickerAndFieldsTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    /** @return list<int> */
    private function pickerIds(User $user, ?string $operation): array
    {
        $route = $operation === null
            ? route('api.models.selectlist', ['limit' => 500])
            : route('api.models.selectlist.for', ['operation' => $operation]);

        return collect($this->actingAsForApi($user)->getJson($route)->assertOk()->json('results'))->pluck('id')->sort()->values()->all();
    }

    private function modelOf(string $key): int
    {
        return $this->asset[$key]->model_id;
    }

    // ---------------------------------------------------------------
    // Pickers
    // ---------------------------------------------------------------

    #[Test]
    public function a_user_with_create_but_no_view_gets_those_models_in_the_create_picker_and_can_create(): void
    {
        $user = $this->writer([['leaf1' => ['create']]]);

        $this->assertSame([$this->modelOf('leaf1')], $this->pickerIds($user, 'create'));
        $this->assertSame([], $this->pickerIds($user, null), 'The general picker stays View-filtered.');
        $this->assertSame([], $this->pickerIds($user, 'update'));

        $this->actingAsForApi($user)->postJson(route('api.assets.store'), [
            'asset_tag' => 'NOVIEW-'.Str::random(6),
            'model_id' => $this->modelOf('leaf1'),
            'status_id' => $this->readyStatus()->id,
        ])->assertOk()->assertStatusMessageIs('success');
    }

    #[Test]
    public function an_edit_only_destination_without_view_is_offered_by_the_edit_picker_and_accepted(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['update']]]);
        $asset = $this->asset['leaf1'];

        $this->assertSame([$this->modelOf('leaf1'), $this->modelOf('leaf2')], $this->pickerIds($user, 'update'));
        $this->assertSame([$this->modelOf('leaf1')], $this->pickerIds($user, null));

        $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset->id), ['model_id' => $this->modelOf('leaf2')])
            ->assertOk()->assertStatusMessageIs('success');
        $this->assertSame($this->modelOf('leaf2'), (int) $this->rawAsset($asset)->model_id);
    }

    #[Test]
    public function picker_contents_match_the_server_side_rule_for_every_model(): void
    {
        // Add invalid targets next to the valid ones.
        $navigation = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $navigation->id)->update(['category_id' => $this->cat['groupA']->id]);
        $deletedCategory = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $deletedCategory->id)->update(['category_id' => $this->cat['deleted']->id]);
        $nonAsset = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        DB::table('models')->where('id', $nonAsset->id)->update(['category_id' => $this->cat['accessory']->id]);

        $user = $this->writer([
            ['leaf1' => ['view', 'create'], 'leaf2' => ['update'], 'groupA' => ['create', 'update'], 'deleted' => ['create', 'update']],
            ['other' => ['create', 'update'], 'accessory' => ['create', 'update']],
        ]);

        foreach (['create' => AuthorisedAssetModel::forCreate(), 'update' => AuthorisedAssetModel::forUpdate()] as $operation => $rule) {
            $offered = $this->pickerIds($user, $operation);
            $this->actingAs($user);
            foreach (AssetModel::withTrashed()->pluck('id') as $modelId) {
                $passes = Validator::make(['model_id' => $modelId], ['model_id' => [$rule]])->passes();
                $this->assertSame($passes, in_array($modelId, $offered, true), "{$operation} model {$modelId}");
            }
        }

        foreach (['create', 'update'] as $operation) {
            $offered = $this->pickerIds($this->superUser(), $operation);
            $this->assertNotContains($navigation->id, $offered);
            $this->assertNotContains($deletedCategory->id, $offered);
            $this->assertNotContains($nonAsset->id, $offered);
        }
    }

    // ---------------------------------------------------------------
    // Custom fields loader
    // ---------------------------------------------------------------

    /** Give every fixture model one custom field with a random name. */
    private function fieldNames(): array
    {
        $names = [];
        foreach (['leaf1', 'leaf2', 'other'] as $key) {
            $fieldset = CustomFieldset::factory()->create();
            $field = CustomField::factory()->create(['name' => 'Field '.$key.' '.Str::random(8)]);
            $fieldset->fields()->attach($field, ['order' => 1, 'required' => false]);
            AssetModel::query()->whereKey($this->modelOf($key))->update(['fieldset_id' => $fieldset->id]);
            $names[$key] = $field->name;
        }

        return $names;
    }

    private function fields(User $user, string $key, array $query = []): string
    {
        return (string) $this->actingAs($user, 'web')
            ->get(route('custom_fields/model', ['modelId' => $this->modelOf($key)] + $query))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_create_and_edit_forms_only_get_fields_of_models_allowed_for_their_operation(): void
    {
        $names = $this->fieldNames();
        $user = $this->writer([['leaf1' => ['create'], 'leaf2' => ['view', 'update']]]);

        $this->assertStringContainsString($names['leaf1'], $this->fields($user, 'leaf1', ['asset_operation' => 'create']));
        $this->assertStringNotContainsString($names['leaf2'], $this->fields($user, 'leaf2', ['asset_operation' => 'create']));
        $this->assertStringNotContainsString($names['other'], $this->fields($user, 'other', ['asset_operation' => 'create']));

        $this->assertStringContainsString($names['leaf2'], $this->fields($user, 'leaf2', ['asset_operation' => 'update']));
        $this->assertStringNotContainsString($names['leaf1'], $this->fields($user, 'leaf1', ['asset_operation' => 'update']));
        $this->assertStringNotContainsString($names['other'], $this->fields($user, 'other', ['asset_operation' => 'update']));

        // Super Admin gets every valid model's fields.
        $this->assertStringContainsString($names['other'], $this->fields($this->superUser(), 'other', ['asset_operation' => 'create']));
    }

    #[Test]
    public function an_invalid_or_array_operation_fails_safely_with_no_fields(): void
    {
        $names = $this->fieldNames();
        $user = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete']]]);

        foreach ([['asset_operation' => 'delete'], ['asset_operation' => 'CREATE'], ['asset_operation' => ''], ['asset_operation' => ['create']], ['asset_operation' => ['update', 'create']]] as $query) {
            $this->assertStringNotContainsString($names['leaf1'], $this->fields($user, 'leaf1', $query), json_encode($query));
        }
    }

    #[Test]
    public function other_custom_field_requests_keep_the_view_rule(): void
    {
        $names = $this->fieldNames();
        $user = $this->writer([['leaf1' => ['view'], 'leaf2' => ['create', 'update']]]);

        $this->assertStringContainsString($names['leaf1'], $this->fields($user, 'leaf1'));
        $this->assertStringNotContainsString($names['leaf2'], $this->fields($user, 'leaf2'));
        $this->assertStringNotContainsString($names['other'], $this->fields($user, 'other'));
    }

    #[Test]
    public function the_asset_forms_send_their_operation_to_the_field_loader(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create', 'update']]]);

        $this->assertStringContainsString('custom_fields?asset_operation=create', $this->actingAs($user, 'web')->get(route('hardware.create'))->assertOk()->getContent());
        $this->assertStringContainsString('custom_fields?asset_operation=update', $this->actingAs($user, 'web')->get(route('hardware.edit', $this->asset['leaf1']))->assertOk()->getContent());
    }
}
