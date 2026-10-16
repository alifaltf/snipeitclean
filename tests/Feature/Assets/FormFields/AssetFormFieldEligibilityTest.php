<?php

namespace Tests\Feature\Assets\FormFields;

use App\Models\Asset;
use App\Models\Company;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C: the asset create and edit forms (web and API) offer and
 * store only the custom fields of the selected model's CURRENT fieldset.
 * A custom-field key outside it is rejected with a validation error and
 * nothing is written, on every path, for every user (Super Admin too).
 * Category table layouts never add a field.
 */
class AssetFormFieldEligibilityTest extends TestCase
{
    use BuildsFormFieldFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFormFields();
    }

    private function fieldsHtml(\App\Models\AssetModel $model, string $operation = 'create'): string
    {
        return (string) $this->get(route('custom_fields/model', $model->id).'?asset_operation='.$operation)->assertOk()->getContent();
    }

    #[Test]
    public function the_form_offers_only_the_selected_models_fieldset_even_when_the_layout_shows_more(): void
    {
        // The category layout shows a field (Other) that only Model Two uses.
        app(AssetTableLayoutService::class)->save($this->catA->fresh(), [
            'standard:asset_tag', AssetTableFieldCatalog::customFieldKey($this->other->id), AssetTableFieldCatalog::customFieldKey($this->plain->id),
        ]);
        $this->actingAs($this->editor(), 'web');

        $one = $this->fieldsHtml($this->modelOne);
        foreach ([$this->plain, $this->secret, $this->box] as $field) {
            $this->assertStringContainsString($field->db_column, $one);
        }
        $this->assertStringNotContainsString($this->other->db_column, $one, 'A layout column never adds a form field.');
        $this->assertStringNotContainsString($this->orphan->db_column, $one);

        $two = $this->fieldsHtml($this->modelTwo);
        $this->assertStringContainsString($this->other->db_column, $two);
        $this->assertStringNotContainsString($this->box->db_column, $two);
        $this->assertSame('', trim($this->fieldsHtml($this->bare)), 'A model without a fieldset has no custom fields.');
    }

    #[Test]
    public function required_fields_are_marked_and_encrypted_fields_follow_the_permission(): void
    {
        $this->actingAs($this->editor(), 'web');
        $two = $this->fieldsHtml($this->modelTwo);
        $this->assertMatchesRegularExpression('/'.preg_quote(e($this->other->name), '/').'\s*<span class="text-danger" aria-hidden="true">\*<\/span>/', $two);
        $this->assertStringContainsString('name="'.$this->other->db_column.'"', $two);
        $this->assertMatchesRegularExpression('/name="'.$this->other->db_column.'"[^>]*required/', $two);
        $this->assertDoesNotMatchRegularExpression('/'.preg_quote(e($this->plain->name), '/').'\s*<span class="text-danger"/', $two, 'Optional fields are not marked.');

        // Without the encrypted permission the encrypted field is masked,
        // disabled and not submitted; with it, it is an input.
        $one = $this->fieldsHtml($this->modelOne);
        $this->assertStringNotContainsString('name="'.$this->secret->db_column.'"', $one);
        $this->assertStringContainsString(AssetCustomFieldEligibility::maskedPlaceholder(), $one);
        $this->actingAs($this->editor(['assets.view.encrypted_custom_fields' => '1']), 'web');
        $this->assertStringContainsString('name="'.$this->secret->db_column.'"', $this->fieldsHtml($this->modelOne));
    }

    #[Test]
    public function web_create_stores_eligible_fields(): void
    {
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);
        $this->actingAs($user, 'web')->post(route('hardware.store'), $this->createPayload($this->modelOne, [
            $this->plain->db_column => 'plain value',
            $this->secret->db_column => 'secret value',
            $this->box->db_column => ['Blue'],
        ], 'WEB-OK'))->assertSessionHasNoErrors()->assertRedirect();

        $asset = Asset::withoutGlobalScopes()->where('asset_tag', 'WEB-OK')->sole();
        $this->assertSame(['plain' => 'plain value', 'secret' => 'secret value', 'box' => 'Blue', 'other' => null, 'orphan' => null, 'model_id' => $this->modelOne->id], $this->stored($asset));
    }

    #[Test]
    public function api_create_stores_eligible_fields(): void
    {
        $user = $this->editor(['assets.view.encrypted_custom_fields' => '1']);
        $this->actingAsForApi($user)->postJson(route('api.assets.store'), [
            'asset_tag' => 'API-OK', 'model_id' => $this->modelTwo->id, 'status_id' => $this->ready->id,
            $this->other->db_column => 'other value', $this->plain->db_column => 'shared',
        ])->assertOk()->assertJsonPath('status', 'success');

        $asset = Asset::withoutGlobalScopes()->where('asset_tag', 'API-OK')->sole();
        $this->assertSame('other value', $this->stored($asset)['other']);
        $this->assertSame('shared', $this->stored($asset)['plain']);
    }

    /** @return array<string, string> forged custom-field keys for an asset of Model One */
    private function forgedKeys(): array
    {
        return [
            'other fieldset' => $this->other->db_column,
            'no fieldset' => $this->orphan->db_column,
            'unknown column' => '_snipeit_no_such_field_999',
        ];
    }

    #[Test]
    public function web_create_rejects_a_field_outside_the_fieldset_and_creates_nothing(): void
    {
        foreach ([$this->editor(), \App\Models\User::factory()->superuser()->create()] as $user) {
            foreach ($this->forgedKeys() as $case => $key) {
                $before = Asset::withoutGlobalScopes()->count();
                $this->actingAs($user, 'web')->post(route('hardware.store'), $this->createPayload($this->modelOne, [$this->plain->db_column => 'x', $key => 'forged']))
                    ->assertSessionHasErrors($key);
                $this->assertSame($before, Asset::withoutGlobalScopes()->count(), "web create, $case");
            }
        }
    }

    #[Test]
    public function api_create_rejects_a_field_outside_the_fieldset_and_creates_nothing(): void
    {
        foreach ([$this->editor(), \App\Models\User::factory()->superuser()->create()] as $user) {
            foreach ($this->forgedKeys() as $case => $key) {
                $before = Asset::withoutGlobalScopes()->count();
                $response = $this->actingAsForApi($user)->postJson(route('api.assets.store'), [
                    'asset_tag' => 'F-'.uniqid(), 'model_id' => $this->modelOne->id, 'status_id' => $this->ready->id, $key => 'forged',
                ]);
                $this->assertSame('error', $response->json('status'), "api create, $case");
                $this->assertArrayHasKey($key, (array) $response->json('messages'));
                $this->assertSame($before, Asset::withoutGlobalScopes()->count(), "api create, $case");
            }
        }
    }

    #[Test]
    public function web_and_api_edit_reject_a_field_outside_the_fieldset_and_change_nothing(): void
    {
        foreach ([$this->editor(), \App\Models\User::factory()->superuser()->create()] as $user) {
            $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'kept'], ['name' => 'Before']);
            foreach ($this->forgedKeys() as $case => $key) {
                $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'After', $this->plain->db_column => 'changed', $key => 'forged']))
                    ->assertSessionHasErrors($key);
                $response = $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), ['name' => 'After', $this->plain->db_column => 'changed', $key => 'forged']);
                $this->assertSame('error', $response->json('status'), "api edit, $case");
                $this->actingAs($user, 'web');

                $fresh = $asset->fresh();
                $this->assertSame('Before', Asset::withoutGlobalScopes()->find($asset->id)->name, $case);
                $this->assertSame('kept', $this->stored($fresh)['plain'], $case);
                if (str_starts_with($key, '_snipeit_') && $key !== '_snipeit_no_such_field_999') {
                    $this->assertNull(DB::table('assets')->where('id', $asset->id)->value($key), $case);
                }
            }
        }
    }

    #[Test]
    public function web_and_api_edit_keep_and_change_eligible_values(): void
    {
        $user = $this->editor();
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'p0', $this->box->db_column => 'Red']);

        // The edit form loads the stored eligible values.
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.edit', $asset))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="p0"[^>]*name="'.$this->plain->db_column.'"/', $html);
        $this->assertMatchesRegularExpression('/value="Red" name="'.$this->box->db_column.'\[\]"\s+checked/', $html);
        $this->assertStringNotContainsString($this->other->db_column, $html);

        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->plain->db_column => 'p1', $this->box->db_column => ['Red', 'Blue']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['p1', 'Red, Blue'], [$this->stored($asset)['plain'], $this->stored($asset)['box']]);

        $this->actingAsForApi($user)->patchJson(route('api.assets.update', $asset), [$this->plain->db_column => 'p2'])->assertJsonPath('status', 'success');
        $this->assertSame(['p2', 'Red, Blue'], [$this->stored($asset)['plain'], $this->stored($asset)['box']], 'An omitted field keeps its value (API).');
    }

    #[Test]
    public function newly_required_fields_are_enforced_on_edit(): void
    {
        $user = $this->editor();
        $asset = $this->assetOf($this->modelOne, [$this->plain->db_column => 'p0']);
        DB::table('custom_field_custom_fieldset')->where('custom_fieldset_id', $this->setOne->id)->where('custom_field_id', $this->plain->id)->update(['required' => 1]);

        $this->actingAs($user, 'web')->put(route('hardware.update', $asset), $this->editPayload($asset, ['name' => 'New', $this->plain->db_column => '']))
            ->assertSessionHasErrors($this->plain->db_column);
        $this->assertSame('p0', $this->stored($asset)['plain']);
        $this->assertNotSame('New', Asset::withoutGlobalScopes()->find($asset->id)->name, 'A failed save changes nothing.');

        $this->put(route('hardware.update', $asset), $this->editPayload($asset, [$this->plain->db_column => 'p1']))->assertSessionHasNoErrors();
        $this->assertSame('p1', $this->stored($asset)['plain']);
    }

    #[Test]
    public function the_destination_must_be_an_authorised_final_category_model(): void
    {
        $user = $this->member(['assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1'], [[[$this->catA, ['view', 'create', 'update']]]]);
        $before = Asset::withoutGlobalScopes()->count();

        // A model of a category without a grant (default deny).
        $this->actingAs($user, 'web')->post(route('hardware.store'), $this->createPayload($this->modelB))->assertSessionHasErrors('model_id');
        // A model filed under a navigation group (not a destination).
        $navModel = \App\Models\AssetModel::factory()->create(['category_id' => $this->catA->id]);
        DB::table('models')->where('id', $navModel->id)->update(['category_id' => $this->nav->id]);
        $this->post(route('hardware.store'), $this->createPayload($navModel->fresh()))->assertSessionHasErrors('model_id');
        // No global Create: refused even with the category grant.
        $noCreate = $this->member(['assets.view' => '1'], [[[$this->catA, ['view', 'create']]]]);
        $this->actingAs($noCreate, 'web')->post(route('hardware.store'), $this->createPayload($this->modelOne))->assertForbidden();
        // Grants combine across groups (union).
        $union = $this->member(['assets.view' => '1', 'assets.create' => '1'], [[[$this->catA, ['view']]], [[$this->catA, ['create']]]]);
        $this->actingAs($union, 'web')->post(route('hardware.store'), $this->createPayload($this->modelOne, [], 'UNION-1'))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Asset::withoutGlobalScopes()->count());
    }

    #[Test]
    public function company_scope_still_applies_to_edits(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        [$mine, $theirs] = Company::factory()->count(2)->create();
        $user = $this->companyMember($mine, $this->editor());
        $foreign = $this->assetOf($this->modelOne, [$this->plain->db_column => 'theirs'], ['company_id' => $theirs->id]);

        $response = $this->actingAs($user, 'web')->put(route('hardware.update', $foreign), $this->editPayload($foreign, [$this->plain->db_column => 'mine']));
        $this->assertContains($response->status(), [302, 403, 404]);
        $this->assertSame('theirs', $this->stored($foreign)['plain']);
    }
}
