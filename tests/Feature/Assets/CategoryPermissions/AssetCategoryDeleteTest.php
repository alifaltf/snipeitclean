<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2: deleting an asset needs global assets.delete, category
 * View and category Delete on its final category, plus company scope.
 * Restore is a delete-level action: every restore path and Restore action
 * needs global assets.delete plus category Delete. Purge is
 * Super-Admin-only.
 */
class AssetCategoryDeleteTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function apiDelete(User $user, Asset $asset): TestResponse
    {
        return $this->actingAsForApi($user)->deleteJson(route('api.assets.destroy', $asset->id));
    }

    private function isDeleted(Asset $asset): bool
    {
        return $this->rawAsset($asset)->deleted_at !== null;
    }

    // ---------------------------------------------------------------
    // 23-26
    // ---------------------------------------------------------------

    #[Test]
    public function global_delete_plus_category_delete_allows_deletion_on_web_and_api(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'delete'], 'leaf2' => ['view', 'delete']]]);

        $this->apiDelete($user, $this->asset['leaf1'])->assertOk()->assertStatusMessageIs('success');
        $this->actingAs($user, 'web')->delete(route('hardware.destroy', $this->asset['leaf2']))->assertRedirect(route('hardware.index'));

        $this->assertTrue($this->isDeleted($this->asset['leaf1']));
        $this->assertTrue($this->isDeleted($this->asset['leaf2']));
    }

    #[Test]
    public function global_delete_without_category_delete_is_denied_with_403(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create', 'update']]]);

        $this->apiDelete($user, $this->asset['leaf1'])->assertForbidden();
        $this->actingAs($user, 'web')->delete(route('hardware.destroy', $this->asset['leaf1']))->assertForbidden();

        $this->assertFalse($this->isDeleted($this->asset['leaf1']));
    }

    #[Test]
    public function category_delete_without_global_delete_is_denied(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'delete']]], ['assets.view' => '1', 'assets.edit' => '1']);

        $this->apiDelete($user, $this->asset['leaf1'])->assertForbidden();
        $this->actingAs($user, 'web')->delete(route('hardware.destroy', $this->asset['leaf1']))->assertForbidden();

        $this->assertFalse($this->isDeleted($this->asset['leaf1']));
    }

    #[Test]
    public function a_user_with_view_but_not_delete_can_read_but_cannot_delete(): void
    {
        $user = $this->writer([['leaf1' => ['view']]]);

        $this->actingAsForApi($user)->getJson(route('api.assets.show', $this->asset['leaf1']))->assertOk();
        $this->apiDelete($user, $this->asset['leaf1'])->assertForbidden();

        $this->assertFalse($this->isDeleted($this->asset['leaf1']));
    }

    // ---------------------------------------------------------------
    // 27-30
    // ---------------------------------------------------------------

    #[Test]
    public function bulk_delete_rejects_the_entire_request_when_one_asset_is_unauthorised(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'delete'], 'leaf2' => ['view']]]);
        $ids = [$this->asset['leaf1']->id, $this->asset['leaf2']->id];

        $this->actingAs($user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'delete'])->assertForbidden();
        $this->actingAs($user, 'web')->post(route('hardware.bulkdelete.store'), ['ids' => $ids])->assertForbidden();

        $this->assertFalse($this->isDeleted($this->asset['leaf1']), 'No partial delete.');
        $this->assertFalse($this->isDeleted($this->asset['leaf2']));
    }

    #[Test]
    public function bulk_delete_succeeds_when_every_asset_is_authorised(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'delete']], ['leaf2' => ['view', 'delete']]]);

        $this->actingAs($user, 'web')->post(route('hardware.bulkdelete.store'), ['ids' => [$this->asset['leaf1']->id, $this->asset['leaf2']->id]])
            ->assertSessionHas('success');

        $this->assertTrue($this->isDeleted($this->asset['leaf1']));
        $this->assertTrue($this->isDeleted($this->asset['leaf2']));
    }

    #[Test]
    public function hidden_assets_still_return_not_found_on_delete(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'delete']]]);
        $hidden = $this->asset['other'];

        $api = $this->apiDelete($user, $hidden);
        $this->assertNotSame('success', $api->json('status'));
        $this->assertSame(
            $this->actingAsForApi($user)->deleteJson(route('api.assets.destroy', 999999))->json('messages'),
            $api->json('messages')
        );
        $this->assertNotSame(200, $this->actingAs($user, 'web')->delete(route('hardware.destroy', $hidden))->status());

    }

    #[Test]
    public function super_admin_bypass_works_for_delete_including_super_admin_only_assets(): void
    {
        $admin = $this->superUser();
        $broken = $this->assetIn($this->cat['leaf1']);
        AssetModel::find($broken->model_id)->delete();

        $this->apiDelete($admin, $this->asset['other'])->assertOk()->assertStatusMessageIs('success');
        $this->apiDelete($admin, $broken)->assertOk()->assertStatusMessageIs('success');

        $this->assertTrue($this->isDeleted($this->asset['other']));
        $this->assertTrue($this->isDeleted($broken));
    }

    // ---------------------------------------------------------------
    // 31-33: restore, purge, Super-Admin-only assets
    // ---------------------------------------------------------------

    #[Test]
    public function restore_needs_global_and_category_delete_on_every_restore_path(): void
    {
        // Admins are category-restricted for restore too (non-admin users
        // are covered in AssetCategoryRestoreCloneConsistencyTest).
        foreach (['leaf1', 'leaf2', 'direct'] as $key) {
            $this->asset[$key]->delete();
        }
        $denied = User::factory()->admin()->create();
        $this->viewerGroup($denied, ['leaf1' => ['view', 'update'], 'leaf2' => ['view', 'update'], 'direct' => ['view', 'update']]);
        $allowed = User::factory()->admin()->create();
        $this->viewerGroup($allowed, ['leaf1' => ['view', 'delete'], 'leaf2' => ['view', 'delete'], 'direct' => ['view', 'delete']]);

        $this->actingAsForApi($denied)->postJson(route('api.assets.restore', $this->asset['leaf1']->id))->assertForbidden();
        $this->assertTrue($this->isDeleted($this->asset['leaf1']));

        $this->actingAsForApi($allowed)->postJson(route('api.assets.restore', $this->asset['leaf1']->id))->assertOk()->assertStatusMessageIs('success');
        $this->assertFalse($this->isDeleted($this->asset['leaf1']));
    }

    #[Test]
    public function web_restore_paths_need_category_delete(): void
    {
        foreach (['leaf2', 'direct'] as $key) {
            $this->asset[$key]->delete();
        }
        $denied = User::factory()->admin()->create();
        $this->viewerGroup($denied, ['leaf2' => ['view', 'update'], 'direct' => ['view', 'delete']]);

        $this->actingAs($denied, 'web')->post(route('restore/hardware', $this->asset['leaf2']->id))->assertForbidden();
        // Bulk restore is all-or-nothing: 'direct' is allowed, 'leaf2' is not.
        $this->actingAs($denied, 'web')->post(route('hardware/bulkrestore'), ['ids' => [$this->asset['direct']->id, $this->asset['leaf2']->id]])->assertForbidden();
        $this->assertTrue($this->isDeleted($this->asset['leaf2']));
        $this->assertTrue($this->isDeleted($this->asset['direct']));

        $allowed = User::factory()->create(['permissions' => json_encode(self::ALL_ASSET_PERMISSIONS)]);
        $this->viewerGroup($allowed, ['leaf2' => ['view', 'delete'], 'direct' => ['view', 'delete']]);
        $this->actingAs($allowed, 'web')->post(route('hardware/bulkrestore'), ['ids' => [$this->asset['direct']->id, $this->asset['leaf2']->id]])->assertSessionHas('success');
        $this->assertFalse($this->isDeleted($this->asset['leaf2']));
        $this->assertFalse($this->isDeleted($this->asset['direct']));
    }

    #[Test]
    public function purge_is_super_admin_only(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete']]], self::ALL_ASSET_PERMISSIONS + ['admin' => '1']);

        $this->actingAs($user, 'web')->post(route('settings.purge.save'), ['confirm_purge' => 'DELETE'])->assertForbidden();
        $this->actingAs($user, 'web')->get(route('settings.purge.index'))->assertForbidden();
    }

    #[Test]
    public function super_admin_only_assets_cannot_be_edited_or_deleted_by_anyone_else(): void
    {
        $broken = $this->assetIn($this->cat['leaf1'], ['name' => 'Broken']);
        AssetModel::find($broken->model_id)->delete();
        $user = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete']]], self::ALL_ASSET_PERMISSIONS + ['admin' => '1']);

        $this->assertNotSame('success', $this->apiDelete($user, $broken)->json('status'));
        $this->assertNotSame('success', $this->actingAsForApi($user)->patchJson(route('api.assets.update', $broken->id), ['name' => 'x'])->json('status'));

        $this->assertFalse($this->isDeleted($broken));
        $this->assertSame('Broken', $this->rawAsset($broken)->name);
    }

    /** @param  array<string, list<string>>  $grants */
    private function viewerGroup(User $user, array $grants): void
    {
        $group = \App\Models\Group::factory()->create(['permissions' => json_encode([])]);
        foreach ($grants as $key => $operations) {
            $this->grant($group, $this->cat[$key], $operations);
        }
        $user->groups()->attach($group->id);
        $this->flushPermissions();
    }
}
