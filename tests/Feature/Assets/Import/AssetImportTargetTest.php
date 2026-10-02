<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: the target step. Only authorised live final asset
 * categories, live models of the selected category and in-scope status,
 * company and location records are accepted; every other id gets the same
 * generic message, so hidden and missing records look identical.
 */
class AssetImportTargetTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function unavailable(): string
    {
        return trans('admin/hardware/import.target.unavailable');
    }

    #[Test]
    public function only_authorised_final_categories_are_accepted_and_every_refusal_looks_the_same(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['view']]]);
        $session = $this->newSession($user);

        $forged = [
            'navigation group' => $this->cat['groupA']->id,
            'navigation branch' => $this->cat['branch']->id,
            'deleted category' => $this->cat['deleted']->id,
            'non-asset category' => $this->cat['accessory']->id,
            'final category without Create' => $this->cat['leaf2']->id,
            'hidden final category' => $this->cat['other']->id,
            'nonexistent' => 999999,
            'malformed' => '1 OR 1=1',
            'array' => [$this->cat['leaf1']->id],
            'zero' => 0,
        ];

        foreach ($forged as $case => $categoryId) {
            $this->postTarget($user, $session, ['category_id' => $categoryId])->assertSessionHasErrors(['category_id' => $this->unavailable()]);
            $this->assertNull($session->fresh()->category_id, $case);
            $this->assertSame(AssetImportSession::STATE_UPLOADED, $session->fresh()->state, $case);
        }
    }

    #[Test]
    public function the_category_selector_lists_only_authorised_final_categories(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'direct' => ['create'], 'leaf2' => ['view', 'update']]]);
        $session = $this->newSession($user);

        $page = $this->actingAs($user, 'web')->get(route('hardware.import.target', $session->public_id))->assertOk();

        $page->assertSee('<option value="'.$this->cat['leaf1']->id.'"', false);
        $page->assertSee('<option value="'.$this->cat['direct']->id.'"', false);
        foreach (['leaf2', 'other', 'loose', 'groupA', 'branch', 'deleted', 'accessory'] as $key) {
            $page->assertDontSee('<option value="'.$this->cat[$key]->id.'"', false);
        }
        // Categories the user cannot see at all are not named anywhere
        // (leaf2 is visible through its View grant in the navigation).
        foreach (['other', 'loose', 'deleted', 'accessory'] as $key) {
            $page->assertDontSee($this->cat[$key]->name);
        }
    }

    #[Test]
    public function the_model_selector_lists_only_live_models_of_the_chosen_authorised_category(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->newSession($user);
        $deleted = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id, 'name' => $this->randomName('Deleted model')]);
        $deleted->delete();

        $leaf1 = $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf1']->id]))->assertOk();
        $leaf1->assertSee($this->modelIn('leaf1')->name)->assertDontSee($this->modelIn('leaf2')->name)->assertDontSee($deleted->name);

        $leaf2 = $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf2']->id]))->assertOk();
        $leaf2->assertSee($this->modelIn('leaf2')->name)->assertDontSee($this->modelIn('leaf1')->name);

        // An unauthorised ?category= lists nothing from that category.
        $hidden = $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['other']->id]))->assertOk();
        $hidden->assertDontSee($this->modelIn('other')->name)->assertDontSee($this->cat['other']->name);
    }

    #[Test]
    public function a_fixed_model_must_be_a_live_model_of_the_selected_category(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->newSession($user);
        $deleted = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
        $deleted->delete();

        foreach ([
            'model of another authorised category' => $this->asset['leaf2']->model_id,
            'model of a hidden category' => $this->asset['other']->model_id,
            'deleted model' => $deleted->id,
            'nonexistent model' => 999999,
            'missing model' => '',
            'malformed model' => 'abc',
        ] as $case => $modelId) {
            $this->postTarget($user, $session, ['model_id' => $modelId])->assertSessionHasErrors(['model_id' => $this->unavailable()]);
            $this->assertNull($session->fresh()->model_id, $case);
        }

        $this->postTarget($user, $session)->assertRedirect(route('hardware.import.mapping', $session->public_id));
        $this->assertSame($this->asset['leaf1']->model_id, $session->fresh()->model_id);
    }

    #[Test]
    public function the_model_column_mode_saves_no_model(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);

        $this->postTarget($user, $session, ['model_source' => AssetImportSession::SOURCE_COLUMN, 'model_id' => $this->asset['other']->model_id])
            ->assertRedirect(route('hardware.import.mapping', $session->public_id));

        $session->refresh();
        $this->assertSame(AssetImportSession::SOURCE_COLUMN, $session->model_source);
        $this->assertNull($session->model_id);
        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->state);
    }

    #[Test]
    public function sources_must_be_one_of_the_allowed_values(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);

        $this->postTarget($user, $session, [
            'model_source' => 'bogus',
            'status_source' => ['fixed'],
            'company_source' => 'fixed; drop',
            'location_source' => null,
        ])->assertSessionHasErrors(['model_source', 'status_source', 'company_source', 'location_source']);

        // A status source is required; "none" is not allowed for status or model.
        $this->postTarget($user, $session, ['status_source' => AssetImportSession::SOURCE_NONE, 'model_source' => AssetImportSession::SOURCE_NONE])
            ->assertSessionHasErrors(['status_source', 'model_source']);
        $this->assertNull($session->fresh()->category_id);
    }

    #[Test]
    public function a_fixed_status_must_exist(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);
        $deleted = \App\Models\Statuslabel::factory()->create();
        $deleted->delete();

        foreach ([999999, $deleted->id, 'x', ''] as $statusId) {
            $this->postTarget($user, $session, ['status_id' => $statusId])->assertSessionHasErrors(['status_id' => $this->unavailable()]);
        }
    }

    #[Test]
    public function fixed_companies_and_locations_respect_full_multiple_company_support(): void
    {
        $user = $this->importer();
        $mine = Company::factory()->create();
        $foreign = Company::factory()->create();
        $mine->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $myLocation = Location::factory()->create(['company_id' => $mine->id]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreign->id]);
        $this->settings->enableMultipleFullCompanySupport();
        $session = $this->newSession($user);

        $page = $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf1']->id]))->assertOk();
        $page->assertSee($mine->name)->assertDontSee($foreign->name)->assertDontSee($foreignLocation->name);

        foreach ([$foreign->id, 999999] as $companyId) {
            $this->postTarget($user, $session, ['company_source' => 'fixed', 'company_id' => $companyId])
                ->assertSessionHasErrors(['company_id' => $this->unavailable()]);
        }
        foreach ([$foreignLocation->id, 999999] as $locationId) {
            $this->postTarget($user, $session, ['location_source' => 'fixed', 'location_id' => $locationId])
                ->assertSessionHasErrors(['location_id' => $this->unavailable()]);
        }

        $this->postTarget($user, $session, ['company_source' => 'fixed', 'company_id' => $mine->id, 'location_source' => 'fixed', 'location_id' => $myLocation->id])
            ->assertRedirect(route('hardware.import.mapping', $session->public_id));
        $this->assertSame([$mine->id, $myLocation->id], [$session->fresh()->company_id, $session->fresh()->location_id]);
    }

    #[Test]
    public function a_fixed_location_must_fit_a_fixed_company_when_locations_are_company_scoped(): void
    {
        $admin = $this->superUser();
        $first = Company::factory()->create();
        $second = Company::factory()->create();
        $location = Location::factory()->create(['company_id' => $second->id]);
        $this->settings->enableMultipleFullCompanySupport()->set(['scope_locations_fmcs' => 1]);
        $session = $this->newSession($admin);

        $this->postTarget($admin, $session, ['company_source' => 'fixed', 'company_id' => $first->id, 'location_source' => 'fixed', 'location_id' => $location->id])
            ->assertSessionHasErrors(['location_id' => trans('admin/hardware/import.target.location_company_mismatch')]);

        $this->postTarget($admin, $session, ['company_source' => 'fixed', 'company_id' => $second->id, 'location_source' => 'fixed', 'location_id' => $location->id])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function changing_the_category_clears_the_model_and_the_mapping(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->mappedSession($user);
        $this->assertNotNull($session->mapping);

        $this->postTarget($user, $session, ['category_id' => $this->cat['leaf2']->id, 'model_id' => $this->asset['leaf2']->model_id])->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame($this->cat['leaf2']->id, $session->category_id);
        $this->assertSame($this->asset['leaf2']->model_id, $session->model_id);
        $this->assertNull($session->mapping);
        $this->assertNull($session->mapping_file_sha256);
        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->state);
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertRedirect(route('hardware.import.mapping', $session->public_id));
    }

    #[Test]
    public function a_source_change_that_makes_the_mapping_incompatible_clears_it(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);
        $this->postTarget($user, $session, ['status_source' => 'column']);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => 'standard:status'])->assertSessionHasNoErrors();

        // Status becomes fixed: the mapped Status column no longer fits.
        $this->postTarget($user, $session)->assertSessionHasNoErrors();
        $this->assertNull($session->fresh()->mapping);

        // A compatible change keeps the saved mapping as a draft, but it
        // must be saved again before review.
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial'])->assertSessionHasNoErrors();
        $this->postTarget($user, $session, ['company_source' => 'column'])->assertSessionHasNoErrors();
        $session->refresh();
        $this->assertSame([0 => 'standard:asset_tag', 1 => 'standard:serial', 2 => null, 3 => null], $session->mappedDestinations());
        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->state);
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertRedirect(route('hardware.import.mapping', $session->public_id));
    }

    #[Test]
    public function a_model_moved_out_of_the_category_or_a_revoked_grant_sends_the_user_back_to_the_target_step(): void
    {
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->mappedSession($user);

        DB::table('models')->where('id', $session->model_id)->update(['category_id' => $this->cat['leaf2']->id]);

        foreach (['hardware.import.mapping', 'hardware.import.review'] as $route) {
            $this->actingAs($user, 'web')->get(route($route, $session->public_id))->assertRedirect(route('hardware.import.target', $session->public_id));
        }
        $this->postMapping($user, $session, [0 => 'standard:asset_tag'])->assertSessionHasErrors('mapping');
    }
}
