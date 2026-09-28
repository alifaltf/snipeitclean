<?php

namespace Tests\Feature\Groups\AssetCategoryPermissions;

use App\Actions\Categories\DestroyCategoryAction;
use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Models\AssetCategoryPermission;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grants can only exist on live, final asset categories and never outlive
 * their group (ERS Phase 5A).
 */
class AssetCategoryPermissionLifecycleTest extends TestCase
{
    use BuildsPermissionFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTree();
    }

    private function grantCount(int $categoryId): int
    {
        return AssetCategoryPermission::query()->where('category_id', $categoryId)->count();
    }

    #[Test]
    public function deleting_a_group_removes_its_grants(): void
    {
        $group = $this->groupWithAssetPermissions();
        $other = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf1'], ['view']);
        $this->grant($other, $this->cat['leaf1'], ['view']);

        $this->actingAs($this->superUser())->delete(route('groups.destroy', $group))->assertSessionHas('success');

        $this->assertSame(0, AssetCategoryPermission::query()->where('group_id', $group->id)->count());
        $this->assertSame(1, AssetCategoryPermission::query()->where('group_id', $other->id)->count());
    }

    #[Test]
    public function deleting_a_category_removes_its_grants(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['loose'], ['view']);

        DestroyCategoryAction::run($this->cat['loose']);

        $this->assertSame(0, $this->grantCount($this->cat['loose']->id));
    }

    #[Test]
    public function converting_a_final_category_into_a_navigation_group_removes_its_grants(): void
    {
        $this->actingAs($this->superUser());
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['loose'], ['view', 'update']);

        SaveCategoryHierarchyAction::run($this->cat['loose'], ['is_assignable' => '0']);
        $this->assertSame(0, $this->grantCount($this->cat['loose']->id));

        // Converting back does not silently restore the old grants.
        SaveCategoryHierarchyAction::run($this->cat['loose']->fresh(), ['is_assignable' => '1']);
        $member = User::factory()->create();
        $member->groups()->attach($group->id);
        $this->assertFalse(app(\App\Services\AssetCategoryPermissionService::class)->forUser($member->fresh())->allows('view', $this->cat['loose']->id));
    }

    #[Test]
    public function changing_a_category_to_a_non_asset_type_removes_its_grants(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['loose'], ['view']);

        $category = $this->cat['loose'];
        $category->category_type = 'accessory';
        SaveCategoryHierarchyAction::run($category);

        $this->assertSame(0, $this->grantCount($category->id));
    }

    #[Test]
    public function ordinary_category_edits_keep_grants(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf1'], ['view']);

        $category = $this->cat['leaf1'];
        $category->name = $this->randomName('Renamed');
        SaveCategoryHierarchyAction::run($category);

        $this->assertSame(1, $this->grantCount($category->id));
    }

    #[Test]
    public function grant_rows_cannot_be_mass_assigned(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        (new AssetCategoryPermission)->fill(['group_id' => 1, 'category_id' => 1, 'can_view' => true]);
    }
}
