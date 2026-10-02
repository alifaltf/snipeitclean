<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2: bulk asset writes are all-or-nothing. When ANY submitted id
 * does not resolve through the user's normal scoped query (missing, hidden
 * by category or company scope, forged), the whole request is refused with
 * one generic answer before anything is written, so hidden and missing ids
 * are indistinguishable and no visible asset in the batch is modified.
 */
class AssetCategoryBulkSelectionTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    private User $user;

    /** @var array<string, int> */
    private array $outsiders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();

        // Full rights on leaf1 and leaf2 only.
        $this->user = $this->writer([['leaf1' => ['view', 'update', 'delete'], 'leaf2' => ['view', 'update', 'delete']]]);

        $companyB = Company::factory()->create();
        $companyA = Company::factory()->create();
        $companyA->users()->attach($this->user->id);
        Company::flushCompanyIdsCache();

        $this->outsiders = [
            'hidden by category' => $this->asset['other']->id,
            'hidden by company' => $this->assetIn($this->cat['leaf1'], ['company_id' => $companyB->id])->id,
            'nonexistent' => 999999,
        ];
        foreach (['leaf1', 'leaf2'] as $key) {
            $this->asset[$key]->update(['company_id' => $companyA->id]);
        }
        $this->settings->enableMultipleFullCompanySupport();
    }

    /** @return array<int, array> raw rows of every asset, keyed by id */
    private function snapshot(): array
    {
        return Asset::withoutGlobalScopes()->getQuery()->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    private function batch(string $outsider): array
    {
        return [$this->asset['leaf1']->id, $this->asset['leaf2']->id, $this->outsiders[$outsider]];
    }

    #[Test]
    public function api_bulk_update_refuses_mixed_batches_identically_and_changes_nothing(): void
    {
        $before = $this->snapshot();
        $answers = [];

        foreach (array_keys($this->outsiders) as $outsider) {
            $response = $this->actingAsForApi($this->user)
                ->patchJson(route('api.assets.bulk-update'), ['ids' => $this->batch($outsider), 'notes' => 'mixed'])
                ->assertOk();
            $answers[$outsider] = [$response->status(), $response->json()];
        }

        $this->assertSame($before, $this->snapshot(), 'No asset may change.');
        $this->assertCount(1, array_unique(array_map('json_encode', $answers)), 'Hidden and missing ids must answer identically.');
        $this->assertSame(trans('admin/hardware/message.bulk_selection_unavailable'), $answers['nonexistent'][1]['messages']);
    }

    #[Test]
    public function api_bulk_update_with_a_visible_but_unauthorised_asset_is_a_403_and_changes_nothing(): void
    {
        $this->settings->disableMultipleFullCompanySupport();
        $viewOnly = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);
        $before = $this->snapshot();

        $this->actingAsForApi($viewOnly)
            ->patchJson(route('api.assets.bulk-update'), ['ids' => [$this->asset['leaf1']->id, $this->asset['leaf2']->id], 'notes' => 'nope'])
            ->assertForbidden();

        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function web_bulk_update_refuses_mixed_batches_identically_and_changes_nothing(): void
    {
        $before = $this->snapshot();
        $messages = [];

        foreach (array_keys($this->outsiders) as $outsider) {
            $response = $this->actingAs($this->user, 'web')->post(route('hardware/bulksave'), ['ids' => $this->batch($outsider), 'notes' => 'mixed']);
            $response->assertRedirect();
            $messages[$outsider] = session('error');
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(array_fill_keys(array_keys($this->outsiders), trans('admin/hardware/message.bulk_selection_unavailable')), $messages);
    }

    #[Test]
    public function web_bulk_delete_refuses_mixed_batches_identically_and_deletes_nothing(): void
    {
        $before = $this->snapshot();
        $messages = [];

        foreach (array_keys($this->outsiders) as $outsider) {
            $this->actingAs($this->user, 'web')->post(route('hardware.bulkdelete.store'), ['ids' => $this->batch($outsider)])->assertRedirect();
            $messages[$outsider] = session('error');
            $this->actingAs($this->user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $this->batch($outsider), 'bulk_actions' => 'delete'])->assertRedirect();
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(array_fill_keys(array_keys($this->outsiders), trans('admin/hardware/message.bulk_selection_unavailable')), $messages);
    }

    #[Test]
    public function web_bulk_restore_refuses_mixed_batches_identically_and_restores_nothing(): void
    {
        $this->asset['leaf1']->delete();
        $this->asset['leaf2']->delete();
        Asset::withoutGlobalScopes()->whereKey($this->outsiders['hidden by category'])->update(['deleted_at' => now()]);
        $before = $this->snapshot();
        $messages = [];

        foreach (array_keys($this->outsiders) as $outsider) {
            $this->actingAs($this->user, 'web')->post(route('hardware/bulkrestore'), ['ids' => $this->batch($outsider)])->assertRedirect(route('hardware.index'));
            $messages[$outsider] = session('error');
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(array_fill_keys(array_keys($this->outsiders), trans('admin/hardware/message.bulk_selection_unavailable')), $messages);
    }

    #[Test]
    public function the_web_bulk_edit_form_refuses_mixed_batches(): void
    {
        foreach (array_keys($this->outsiders) as $outsider) {
            $this->actingAs($this->user, 'web')
                ->post(route('hardware.bulkedit.show'), ['ids' => $this->batch($outsider), 'bulk_actions' => 'edit'])
                ->assertRedirect()
                ->assertSessionHas('error', trans('admin/hardware/message.bulk_selection_unavailable'));
        }
    }

    #[Test]
    public function fully_resolvable_authorised_batches_still_work(): void
    {
        $ids = [$this->asset['leaf1']->id, $this->asset['leaf2']->id];

        $this->actingAsForApi($this->user)->patchJson(route('api.assets.bulk-update'), ['ids' => $ids, 'notes' => 'all good'])
            ->assertOk()->assertJsonPath('status', 'success');
        $this->actingAs($this->user, 'web')->post(route('hardware.bulkdelete.store'), ['ids' => $ids])->assertSessionHas('success');

        $this->assertNotNull($this->rawAsset($this->asset['leaf1'])->deleted_at);
        $this->assertSame('all good', $this->rawAsset($this->asset['leaf2'])->notes);
    }
}
