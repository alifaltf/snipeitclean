<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetCategoryViewScope;
use App\Models\Company;
use App\Models\Component;
use App\Models\Group;
use App\Models\Location;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B1: hiding assets must never weaken integrity checks. A
 * category-restricted admin cannot delete records that still reference
 * assets hidden from them, and quantities count every asset. No hidden
 * asset is named or counted in the messages they get.
 */
class AssetCategoryViewIntegrityTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTree();
    }

    private function restrictedAdmin(): User
    {
        // Every global permission except Super User, but only leaf1.
        $admin = User::factory()->admin()->create();
        $this->grantTo($admin, ['leaf1']);

        return $admin->fresh();
    }

    private function grantTo(User $user, array $keys): void
    {
        $group = Group::factory()->create(['permissions' => json_encode([])]);
        foreach ($keys as $key) {
            $this->grant($group, $this->cat[$key], ['view']);
        }
        $user->groups()->attach($group->id);
        $this->flushPermissions();
    }

    #[Test]
    public function records_referenced_only_by_hidden_assets_cannot_be_deleted(): void
    {
        $admin = $this->restrictedAdmin();

        $supplier = Supplier::factory()->create();
        $manufacturer = Manufacturer::factory()->create();
        $status = Statuslabel::factory()->create();
        $location = Location::factory()->create();
        $company = Company::factory()->create();
        $hidden = $this->assetIn($this->cat['other'], [
            'supplier_id' => $supplier->id,
            'status_id' => $status->id,
            'location_id' => $location->id,
            'rtd_location_id' => $location->id,
            'company_id' => $company->id,
        ]);
        $hidden->model->update(['manufacturer_id' => $manufacturer->id]);

        $this->actingAsForApi($admin)->deleteJson(route('api.suppliers.destroy', $supplier))->assertStatusMessageIs('error');
        $this->actingAsForApi($admin)->deleteJson(route('api.manufacturers.destroy', $manufacturer))->assertStatusMessageIs('error');
        $this->actingAsForApi($admin)->deleteJson(route('api.statuslabels.destroy', $status))->assertStatusMessageIs('error');
        $this->actingAsForApi($admin)->deleteJson(route('api.locations.destroy', $location))->assertStatusMessageIs('error');
        $this->actingAsForApi($admin)->deleteJson(route('api.companies.destroy', $company))->assertStatusMessageIs('error');
        $this->actingAsForApi($admin)->deleteJson(route('api.models.destroy', $hidden->model_id))->assertStatusMessageIs('error');

        foreach ([$supplier, $manufacturer, $status, $location, $company, $hidden->model] as $record) {
            $this->assertNotSoftDeleted($record);
        }
    }

    #[Test]
    public function web_delete_screens_also_count_hidden_assets(): void
    {
        $admin = $this->restrictedAdmin();
        $supplier = Supplier::factory()->create();
        $status = Statuslabel::factory()->create();
        $location = Location::factory()->create();
        $company = Company::factory()->create();
        $hidden = $this->assetIn($this->cat['other'], [
            'supplier_id' => $supplier->id,
            'status_id' => $status->id,
            'location_id' => $location->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)->delete(route('locations.destroy', $location))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('locations.bulkdelete.store'), ['ids' => [$location->id]]);
        $this->actingAs($admin)->delete(route('statuslabels.destroy', $status))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('statuslabels.bulk.delete'), ['ids' => [$status->id]])->assertSessionHas('multi_error_messages');
        $this->actingAs($admin)->post(route('suppliers.bulk.delete'), ['ids' => [$supplier->id]])->assertSessionHas('multi_error_messages');
        $this->actingAs($admin)->delete(route('companies.destroy', $company))->assertSessionHas('error');
        $this->actingAs($admin)->delete(route('models.destroy', $hidden->model_id))->assertSessionHas('error');
        $this->actingAs($admin)->post(route('models.bulkdelete.store'), ['ids' => [$hidden->model_id]]);

        foreach ([$supplier, $status, $location, $company, $hidden->model] as $record) {
            $this->assertNotSoftDeleted($record);
        }
    }

    #[Test]
    public function integrity_messages_do_not_reveal_hidden_asset_counts(): void
    {
        $admin = $this->restrictedAdmin();
        $supplier = Supplier::factory()->create();
        foreach (range(1, 3) as $ignored) {
            $this->assetIn($this->cat['other'], ['supplier_id' => $supplier->id]);
        }

        $message = $this->actingAsForApi($admin)->deleteJson(route('api.suppliers.destroy', $supplier))->json('messages');

        $this->assertStringNotContainsString('3', (string) $message);
    }

    #[Test]
    public function a_user_holding_hidden_assets_cannot_be_deleted(): void
    {
        $admin = $this->restrictedAdmin();
        $holder = User::factory()->create();
        $this->assetIn($this->cat['other'], ['assigned_to' => $holder->id, 'assigned_type' => User::class]);

        $this->actingAs($admin)->delete(route('users.destroy', $holder))->assertSessionHasErrors();
        $this->assertNotSoftDeleted($holder);
    }

    #[Test]
    public function bulk_checkin_and_delete_refuses_users_holding_hidden_assets(): void
    {
        $admin = $this->restrictedAdmin();
        $holder = User::factory()->create();
        $hidden = $this->assetIn($this->cat['other'], ['assigned_to' => $holder->id, 'assigned_type' => User::class]);

        $this->actingAs($admin)->post(route('users/bulksave'), [
            'ids' => [$holder->id],
            'status_id' => Statuslabel::factory()->create()->id,
            'delete_user' => '1',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted($holder);
        $this->assertSame($holder->id, (int) Asset::withoutGlobalScopes()->find($hidden->id)->assigned_to);
    }

    #[Test]
    public function merging_users_holding_hidden_assets_is_refused(): void
    {
        $admin = $this->restrictedAdmin();
        $source = User::factory()->create();
        $target = User::factory()->create();
        $hidden = $this->assetIn($this->cat['other'], ['assigned_to' => $source->id, 'assigned_type' => User::class]);

        $this->actingAs($admin)->post(route('users.merge.save'), [
            'ids_to_merge' => [$source->id, $target->id],
            'merge_into_id' => $target->id,
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted($source);
        $this->assertSame($source->id, (int) Asset::withoutGlobalScopes()->find($hidden->id)->assigned_to);
    }

    #[Test]
    public function component_quantities_count_units_on_hidden_assets(): void
    {
        $admin = $this->restrictedAdmin();
        $component = Component::factory()->create(['qty' => 5]);
        $hidden = $this->assetIn($this->cat['other']);
        $component->assets()->attach($hidden->id, ['assigned_qty' => 4, 'created_by' => $admin->id]);

        $this->actingAs($admin);
        $fresh = Component::find($component->id);

        $this->assertSame(4, (int) $fresh->numCheckedOut(true));
        $this->assertSame(1, (int) $fresh->numRemaining());
        $this->assertFalse(AssetCategoryViewScope::withoutRestriction(fn () => false));
        $this->assertSame(0, Asset::query()->whereKey($hidden->id)->count(), 'The bypass must not stay switched on.');
    }

    #[Test]
    public function the_integrity_bypass_is_scoped_to_its_callback_even_when_it_throws(): void
    {
        $this->actingAs($this->restrictedAdmin());
        $hidden = $this->assetIn($this->cat['other']);

        $this->assertSame(1, AssetCategoryViewScope::withoutRestriction(fn () => Asset::query()->whereKey($hidden->id)->count()));

        try {
            AssetCategoryViewScope::withoutRestriction(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Asset::query()->whereKey($hidden->id)->count());
    }
}
