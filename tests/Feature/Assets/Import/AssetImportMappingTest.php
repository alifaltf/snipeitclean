<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\AssetModel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: manual column mapping. Destinations come only from the
 * server-side field catalog; required and conditional mappings, fixed
 * source conflicts and duplicate destinations are enforced on the server;
 * label suggestions are only pre-filled and never saved by themselves.
 */
class AssetImportMappingTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    /** A session at the mapping step with the given target sources. */
    private function sessionWithTarget($user, array $target = []): AssetImportSession
    {
        $session = $this->newSession($user);
        $this->postTarget($user, $session, $target)->assertSessionHasNoErrors();

        return $session->fresh();
    }

    private function mappingPage($user, AssetImportSession $session)
    {
        return $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk();
    }

    #[Test]
    public function the_mapping_page_lists_every_header_and_only_catalog_destinations(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);

        $page = $this->mappingPage($user, $session);
        foreach (['Asset Tag', 'Serial Number', 'Laptop Model', 'Notes'] as $index => $header) {
            $page->assertSee('name="mapping['.$index.']"', false)->assertSee($header);
        }
        foreach (['standard:asset_tag', 'standard:serial', 'standard:name', 'standard:purchase_date', 'standard:next_audit_date'] as $key) {
            $page->assertSee('value="'.$key.'"', false);
        }
        // Fixed model/status and switched-off company/location are not offered.
        foreach (['standard:model', 'standard:model_number', 'standard:status', 'standard:company', 'standard:location'] as $key) {
            $page->assertDontSee('value="'.$key.'"', false);
        }
        foreach (['id', 'created_by', 'assigned_to', 'last_checkout', 'checkout_counter', 'deleted_at', 'image', 'notes', 'department'] as $field) {
            $page->assertDontSee('value="standard:'.$field.'"', false);
        }
    }

    #[Test]
    public function asset_tag_must_be_mapped(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);

        $this->postMapping($user, $session, [1 => 'standard:serial'])
            ->assertSessionHasErrors(['mapping' => trans('admin/hardware/import.mapping.required_missing', ['field' => trans('general.asset_tag')])]);

        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->fresh()->state);
        $this->assertNull($session->fresh()->mapping);
    }

    #[Test]
    public function columns_are_required_for_every_source_read_from_the_csv(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user, [
            'model_source' => 'column', 'status_source' => 'column', 'company_source' => 'column', 'location_source' => 'column',
        ]);

        $response = $this->postMapping($user, $session, [0 => 'standard:asset_tag']);
        foreach (['admin/hardware/form.model', 'general.status', 'general.company', 'general.location'] as $label) {
            $this->assertContains(trans('admin/hardware/import.mapping.required_missing', ['field' => trans($label)]), session('errors')->get('mapping'));
        }
        $response->assertSessionHasErrors('mapping');

        $this->postMapping($user, $session, [
            0 => 'standard:asset_tag', 1 => 'standard:model', 2 => 'standard:status', 3 => 'standard:company',
        ])->assertSessionHasErrors('mapping');

        // Model Number stays optional.
        $session = $this->sessionWithTarget($user, ['model_source' => 'column']);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => 'standard:model'])->assertSessionHasNoErrors();
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
    }

    #[Test]
    public function a_fixed_or_switched_off_source_cannot_also_be_mapped_from_a_column(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);

        foreach ([
            'standard:status' => 'conflicts_with_fixed',
            'standard:model' => 'conflicts_with_fixed',
            'standard:model_number' => 'conflicts_with_fixed',
            'standard:company' => 'source_disabled',
            'standard:location' => 'source_disabled',
        ] as $key => $message) {
            $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => $key])
                ->assertSessionHasErrors(['mapping.2' => trans('admin/hardware/import.mapping.'.$message)]);
        }
        $this->assertNull($session->fresh()->mapping);
    }

    #[Test]
    public function a_destination_can_be_used_only_once(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);

        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial', 3 => 'standard:serial'])
            ->assertSessionHasErrors(['mapping.3' => trans('admin/hardware/import.mapping.duplicate', ['field' => trans('general.serial_number'), 'column' => 'Serial Number'])]);
        $this->assertNull($session->fresh()->mapping);
    }

    #[Test]
    public function unknown_or_forged_destinations_are_rejected_with_one_generic_message(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);
        $elsewhere = $this->customFieldOn($this->modelIn('other'));

        foreach ([
            'standard:id', 'standard:created_by', 'standard:assigned_to', 'standard:notes', 'standard:image',
            'standard:department', 'standard:deleted_at', 'standard:category', 'asset_tag', 'Asset Tag',
            'custom_field:999999', 'custom_field:'.$elsewhere->id, 'custom_field:abc', 'custom_field:', 'raw:serial',
        ] as $key) {
            $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => $key])
                ->assertSessionHasErrors(['mapping.1' => trans('admin/hardware/import.mapping.unavailable')]);
        }

        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => ['standard:serial']])
            ->assertSessionHasErrors(['mapping.1' => trans('admin/hardware/import.mapping.unavailable')]);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 9 => 'standard:serial'])->assertSessionHasErrors('mapping');
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', '-1' => 'standard:serial'])->assertSessionHasErrors('mapping');
        $this->actingAs($user, 'web')->post(route('hardware.import.mapping.update', $session->public_id), ['mapping' => 'standard:asset_tag'])
            ->assertSessionHasErrors('mapping');

        $this->assertNull($session->fresh()->mapping);
    }

    #[Test]
    public function fixed_model_mode_offers_only_that_models_fieldset(): void
    {
        $user = $this->importer([['leaf1' => ['create']]]);
        $own = $this->customFieldOn($this->modelIn('leaf1'), [], true);
        $sibling = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $siblingField = $this->customFieldOn($sibling);
        $otherCategory = $this->customFieldOn($this->modelIn('leaf2'));
        $session = $this->sessionWithTarget($user);

        $this->mappingPage($user, $session)
            ->assertSee('value="custom_field:'.$own->id.'"', false)
            ->assertDontSee('value="custom_field:'.$siblingField->id.'"', false)
            ->assertDontSee('value="custom_field:'.$otherCategory->id.'"', false);

        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$siblingField->id])
            ->assertSessionHasErrors(['mapping.1' => trans('admin/hardware/import.mapping.unavailable')]);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$own->id])->assertSessionHasNoErrors();
    }

    #[Test]
    public function model_column_mode_offers_the_union_of_live_models_fieldsets_in_the_category(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $own = $this->customFieldOn($this->modelIn('leaf1'));
        $sibling = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $siblingField = $this->customFieldOn($sibling);
        $deletedModel = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $deletedField = $this->customFieldOn($deletedModel);
        $deletedModel->delete();
        $otherCategory = $this->customFieldOn($this->modelIn('leaf2'));
        $session = $this->sessionWithTarget($user, ['model_source' => 'column']);

        $this->mappingPage($user, $session)
            ->assertSee('value="custom_field:'.$own->id.'"', false)
            ->assertSee('value="custom_field:'.$siblingField->id.'"', false)
            ->assertDontSee('value="custom_field:'.$deletedField->id.'"', false)
            ->assertDontSee('value="custom_field:'.$otherCategory->id.'"', false);

        foreach ([$deletedField, $otherCategory] as $field) {
            $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => 'standard:model', 1 => 'custom_field:'.$field->id])
                ->assertSessionHasErrors(['mapping.1' => trans('admin/hardware/import.mapping.unavailable')]);
        }
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => 'standard:model', 1 => 'custom_field:'.$siblingField->id])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function encrypted_custom_fields_need_the_encrypted_field_permission_and_are_marked_sensitive(): void
    {
        $plain = $this->importer();
        $allowed = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $encrypted = $this->customFieldOn($this->modelIn('leaf1'), ['field_encrypted' => 1, 'name' => 'Secret '.$this->randomName('x')]);

        $session = $this->sessionWithTarget($plain);
        $this->mappingPage($plain, $session)->assertDontSee('value="custom_field:'.$encrypted->id.'"', false)->assertDontSee($encrypted->name);
        $this->postMapping($plain, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$encrypted->id])
            ->assertSessionHasErrors(['mapping.1' => trans('admin/hardware/import.mapping.unavailable')]);

        $session = $this->sessionWithTarget($allowed);
        $this->mappingPage($allowed, $session)
            ->assertSee('value="custom_field:'.$encrypted->id.'"', false)
            ->assertSee($encrypted->name.' ('.trans('admin/hardware/import.mapping.sensitive').')');
        $this->postMapping($allowed, $session, [0 => 'standard:asset_tag', 1 => 'custom_field:'.$encrypted->id])->assertSessionHasNoErrors();

        // The review page shows the configuration only, never cell values.
        $this->actingAs($allowed, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk()
            ->assertSee(trans('admin/hardware/import.mapping.sensitive'))
            ->assertDontSee('S-1')->assertDontSee('S-2');
    }

    #[Test]
    public function exact_label_matches_are_suggested_but_never_saved_by_themselves(): void
    {
        $user = $this->importer();
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'RAM Size']);
        $session = $this->newSession($user, "  asset   TAG ,Serial Number,Tag,ram size,Serial No\nT-1,S-1,x,8GB,y\n");
        $this->postTarget($user, $session);

        $page = $this->mappingPage($user, $session)->assertSee(trans('admin/hardware/import.mapping.suggested'));
        $content = $page->getContent();
        $this->assertMatchesRegularExpression('/name="mapping\[0\]".*?value="standard:asset_tag" selected/s', $content);
        $this->assertMatchesRegularExpression('/name="mapping\[1\]".*?value="standard:serial" selected/s', $content);
        $this->assertMatchesRegularExpression('/name="mapping\[3\]".*?value="custom_field:'.$field->id.'" selected/s', $content);
        // No synonyms: "Tag" and "Serial No" are not suggested.
        foreach ([2, 4] as $column) {
            preg_match('/name="mapping\['.$column.'\]".*?<\/select>/s', $content, $select);
            $this->assertStringNotContainsString('selected', str_replace('selected="selected" value=""', '', $select[0]));
        }

        $this->assertNull($session->fresh()->mapping);
        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->fresh()->state);
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))
            ->assertRedirect(route('hardware.import.mapping', $session->public_id));
    }

    #[Test]
    public function a_saved_mapping_leads_to_a_configuration_only_review(): void
    {
        $user = $this->importer();
        $session = $this->sessionWithTarget($user);

        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial', 2 => ''])
            ->assertRedirect(route('hardware.import.review', $session->public_id));

        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->state);
        $this->assertSame($session->file_sha256, $session->mapping_file_sha256);

        $page = $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk();
        $page->assertSee($session->original_filename)
            ->assertSee($this->cat['leaf1']->name)
            ->assertSee($this->modelIn('leaf1')->name)
            ->assertSee($this->status->name)
            ->assertSee('Laptop Model')
            ->assertSee('Notes')
            ->assertSee(trans('admin/hardware/import.review.next_phase'))
            ->assertDontSee('T-1')
            ->assertDontSee('Model A')
            ->assertDontSee('S-2');
        $this->assertStringNotContainsStringIgnoringCase('confirm', strip_tags($page->getContent()));
    }
}
