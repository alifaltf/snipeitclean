<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Component;
use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\Location;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B1: asset pickers and target lookups used when checking out
 * Components, Licenses and Assets TO an asset only ever reveal assets the
 * user may view. (Checkout write authorisation itself is a later phase:
 * these tests only prove hidden assets cannot be found, listed or named.)
 */
class AssetCategoryCheckoutTargetTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    /** Everything a checkout-capable, category-restricted operator needs. */
    private const OPERATOR = [
        'assets.view' => '1',
        'assets.checkout' => '1',
        'components.view' => '1',
        'components.checkout' => '1',
        'licenses.view' => '1',
        'licenses.checkout' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function operator(array $grants = ['leaf1', 'loose'], array $permissions = self::OPERATOR): User
    {
        return $this->viewer([$grants], $permissions);
    }

    /**
     * The shared asset picker (select2 data-endpoint="hardware") that the
     * component, license and asset checkout forms all use.
     */
    private function picker(User $user, array $query = []): TestResponse
    {
        return $this->actingAsForApi($user)->getJson(route('assets.selectlist', $query))->assertOk();
    }

    /** @return list<int> */
    private function pickerIds(TestResponse $response): array
    {
        return collect($response->json('results'))->pluck('id')->sort()->values()->all();
    }

    private function assertNothingAbout(Asset $asset, TestResponse $response): void
    {
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString($asset->asset_tag, $content);
        $this->assertStringNotContainsString($asset->name, $content);
        $this->assertStringNotContainsString($asset->model->category->name, $content);
    }

    // ---------------------------------------------------------------
    // Picker searches (component, license and asset-to-asset forms)
    // ---------------------------------------------------------------

    #[Test]
    public function component_checkout_target_search_hides_unauthorised_assets(): void
    {
        $user = $this->operator();
        $component = Component::factory()->create(['qty' => 5]);

        // The component form uses the picker scoped to the component's company.
        $response = $this->picker($user, ['search' => 'Findable', 'companyId' => (string) $component->company_id]);

        $this->assertSame($this->assetIds(['leaf1', 'loose']), $this->pickerIds($response));
        $this->assertNothingAbout($this->asset['other'], $response);
    }

    #[Test]
    public function license_checkout_target_search_hides_unauthorised_assets(): void
    {
        $user = $this->operator(['leaf2']);

        $response = $this->picker($user, ['search' => 'Findable']);

        $this->assertSame($this->assetIds(['leaf2']), $this->pickerIds($response));
        foreach (['leaf1', 'direct', 'other', 'loose'] as $key) {
            $this->assertNothingAbout($this->asset[$key], $response);
        }
    }

    #[Test]
    public function asset_to_asset_checkout_target_search_hides_unauthorised_assets(): void
    {
        $user = $this->operator(['leaf1', 'leaf2']);

        // The asset checkout form excludes the asset being checked out.
        $response = $this->picker($user, ['search' => 'Findable', 'excludeId' => $this->asset['leaf1']->id]);

        $this->assertSame($this->assetIds(['leaf2']), $this->pickerIds($response));
        $this->assertNothingAbout($this->asset['other'], $response);
    }

    #[Test]
    public function super_admin_sees_every_valid_target(): void
    {
        $response = $this->picker($this->superUser(), ['search' => 'Findable']);

        $this->assertSame($this->assetIds(['leaf1', 'leaf2', 'direct', 'other', 'loose']), $this->pickerIds($response));
    }

    #[Test]
    public function picker_totals_and_pagination_only_count_authorised_assets(): void
    {
        foreach (range(1, 55) as $i) {
            $this->assetIn($this->cat['leaf1'], ['name' => 'Paged visible '.$i]);
        }
        foreach (range(1, 10) as $i) {
            $this->assetIn($this->cat['other'], ['name' => 'Paged hidden '.$i]);
        }
        $user = $this->operator(['leaf1']);

        $first = $this->picker($user, ['search' => 'Paged']);
        $second = $this->picker($user, ['search' => 'Paged', 'page' => 2]);

        $this->assertSame(55, $first->json('total_count'));
        $this->assertSame(2, $first->json('page_count'));
        $this->assertTrue($first->json('pagination.more'));
        $this->assertCount(50, $first->json('results'));
        $this->assertCount(5, $second->json('results'));
        $this->assertFalse($second->json('pagination.more'));
        $this->assertStringNotContainsString('Paged hidden', $first->getContent().$second->getContent());
    }

    #[Test]
    public function picker_keeps_company_scope_and_ignores_location(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        [$here, $there] = Location::factory()->count(2)->create();
        $inA = $this->assetIn($this->cat['leaf1'], ['name' => 'Scoped A', 'company_id' => $companyA->id, 'location_id' => $there->id]);
        $inB = $this->assetIn($this->cat['leaf1'], ['name' => 'Scoped B', 'company_id' => $companyB->id]);
        $user = $this->operator(['leaf1'], self::OPERATOR);
        $user->update(['location_id' => $here->id]);
        $companyA->users()->attach($user->id);
        Company::flushCompanyIdsCache();

        $this->settings->enableMultipleFullCompanySupport();

        $this->assertSame([$inA->id], $this->pickerIds($this->picker($user->fresh(), ['search' => 'Scoped'])));
        $this->assertNotContains($inB->id, $this->pickerIds($this->picker($user->fresh(), ['search' => 'Scoped'])));
    }

    #[Test]
    public function searching_the_picker_for_a_hidden_id_tag_or_serial_finds_nothing(): void
    {
        $user = $this->operator();
        $hidden = $this->asset['other'];

        foreach ([(string) $hidden->id, $hidden->asset_tag, $hidden->serial, $hidden->name] as $term) {
            $response = $this->picker($user, ['search' => $term]);
            $this->assertNotContains($hidden->id, $this->pickerIds($response), $term);
            $this->assertNothingAbout($hidden, $response);
        }
    }

    // ---------------------------------------------------------------
    // Directly supplied hidden target ids
    // ---------------------------------------------------------------

    #[Test]
    public function a_hidden_asset_id_is_treated_as_missing_by_component_checkout(): void
    {
        $user = $this->operator();
        $component = Component::factory()->create(['qty' => 5]);
        $hidden = $this->asset['other'];
        $missingId = Asset::withoutGlobalScopes()->max('id') + 1000;

        $hiddenResponse = $this->actingAsForApi($user)->postJson(route('api.components.checkout', $component->id), ['assigned_to' => $hidden->id, 'assigned_qty' => 1]);
        $missingResponse = $this->actingAsForApi($user)->postJson(route('api.components.checkout', $component->id), ['assigned_to' => $missingId, 'assigned_qty' => 1]);

        $this->assertNothingAbout($hidden, $hiddenResponse);
        $this->assertNotSame('success', $hiddenResponse->json('status'));
        // Same answer as an id that does not exist: no existence oracle.
        $this->assertSame($missingResponse->status(), $hiddenResponse->status());
        $this->assertSame(
            str_replace((string) $missingId, 'ID', json_encode($missingResponse->json('messages'))),
            str_replace((string) $hidden->id, 'ID', json_encode($hiddenResponse->json('messages')))
        );
        $this->assertSame(0, $component->assets()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_component_checkout_form_rejects_a_hidden_asset_like_a_missing_one(): void
    {
        $user = $this->operator();
        $component = Component::factory()->create(['qty' => 5]);
        $hidden = $this->asset['other'];

        $this->actingAs($user)
            ->post(route('components.checkout.store', $component->id), ['asset_id' => $hidden->id, 'assigned_qty' => 1])
            ->assertSessionHasErrors('asset_id');

        $this->assertSame(0, $component->assets()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_hidden_asset_id_is_treated_as_missing_by_license_checkout_and_seat_updates(): void
    {
        $user = $this->operator();
        $license = License::factory()->create(['seats' => 2]);
        $hidden = $this->asset['other'];

        $response = $this->actingAsForApi($user)->postJson(route('api.licenses.checkout', $license->id), ['target_type' => 'asset', 'asset_id' => $hidden->id]);
        $this->assertNotSame('success', $response->json('status'));
        $this->assertNothingAbout($hidden, $response);

        $seat = $license->freeSeats()->first() ?? LicenseSeat::where('license_id', $license->id)->first();
        $seatResponse = $this->actingAsForApi($user)->patchJson(route('api.licenses.seats.update', [$license->id, $seat->id]), ['asset_id' => $hidden->id]);
        $this->assertNotSame('success', $seatResponse->json('status'));
        $this->assertNothingAbout($hidden, $seatResponse);

        $this->assertSame(0, LicenseSeat::where('license_id', $license->id)->where('asset_id', $hidden->id)->count());
    }

    #[Test]
    public function a_hidden_asset_id_is_treated_as_missing_by_asset_to_asset_checkout(): void
    {
        $user = $this->operator();
        $hidden = $this->asset['other'];
        $toMove = $this->asset['leaf1'];

        $api = $this->actingAsForApi($user)->postJson(route('api.asset.checkout', $toMove), ['checkout_to_type' => 'asset', 'assigned_asset' => $hidden->id]);
        $this->assertNotSame('success', $api->json('status'));
        $this->assertNothingAbout($hidden, $api);

        $this->assertNull(Asset::withoutGlobalScopes()->find($toMove->id)->assigned_to);
    }

    #[Test]
    public function the_asset_checkout_form_rejects_a_hidden_target_asset(): void
    {
        $user = $this->operator();
        $hidden = $this->asset['other'];
        $toMove = $this->asset['leaf1'];

        $web = $this->actingAs($user)->post(route('hardware.checkout.store', $toMove), ['checkout_to_type' => 'asset', 'assigned_asset' => $hidden->id, 'status_id' => $toMove->status_id]);
        $web->assertSessionHasErrors('assigned_asset');
        $this->assertNothingAbout($hidden, $web);

        $this->assertNull(Asset::withoutGlobalScopes()->find($toMove->id)->assigned_to);
    }

    #[Test]
    public function checkout_forms_do_not_prefill_a_forged_hidden_target(): void
    {
        $user = $this->operator();
        $hidden = $this->asset['other'];
        $component = Component::factory()->create(['qty' => 5]);

        $html = $this->actingAs($user)
            ->withSession(['_old_input' => ['asset_id' => $hidden->id]])
            ->get(route('components.checkout.show', $component->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($hidden->asset_tag, $html);
        $this->assertStringNotContainsString('value="'.$hidden->id.'" selected', $html);
    }

    #[Test]
    public function authorised_targets_can_still_be_found_and_used_by_id(): void
    {
        $user = $this->operator();
        $component = Component::factory()->create(['qty' => 5]);
        $visible = $this->asset['leaf1'];

        $this->actingAsForApi($user)
            ->postJson(route('api.components.checkout', $component->id), ['assigned_to' => $visible->id, 'assigned_qty' => 1])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertSame(1, $component->assets()->count());
    }
}
