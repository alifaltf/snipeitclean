<?php

namespace Tests\Feature\Groups\AssetCategoryPermissions;

use App\Models\AssetCategoryPermission;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use Illuminate\Support\Str;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;

/**
 * Random-named asset category tree for permission tests:
 *
 *   groupA
 *     branch
 *       leaf1, leaf2        (final)
 *     direct                (final)
 *   groupB
 *     other                 (final)
 *   loose                   (final, root)
 *   emptyGroup              (no final descendants)
 *   deleted                 (final, soft-deleted)
 *   accessory               (non-asset)
 */
trait BuildsPermissionFixture
{
    use CreatesCategoryHierarchy;

    /** @var array<string, Category> */
    protected array $cat = [];

    protected function randomName(string $prefix): string
    {
        return $prefix.' '.Str::random(8);
    }

    protected function buildTree(): void
    {
        $this->cat['groupA'] = $this->group($this->randomName('Group A'));
        $this->cat['branch'] = $this->group($this->randomName('Branch'), $this->cat['groupA']);
        $this->cat['leaf1'] = $this->finalCategory($this->randomName('Leaf 1'), $this->cat['branch'], 0);
        $this->cat['leaf2'] = $this->finalCategory($this->randomName('Leaf 2'), $this->cat['branch'], 1);
        $this->cat['direct'] = $this->finalCategory($this->randomName('Direct'), $this->cat['groupA'], 5);
        $this->cat['groupB'] = $this->group($this->randomName('Group B'), null, 1);
        $this->cat['other'] = $this->finalCategory($this->randomName('Other'), $this->cat['groupB']);
        $this->cat['loose'] = $this->finalCategory($this->randomName('Loose'), null, 2);
        $this->cat['emptyGroup'] = $this->group($this->randomName('Empty'), null, 3);
        $this->cat['deleted'] = $this->finalCategory($this->randomName('Deleted'), $this->cat['groupA']);
        $this->cat['deleted']->delete();
        $this->cat['accessory'] = Category::factory()->forAccessories()->create(['name' => $this->randomName('Accessory')]);
    }

    /** @return list<int> every live final asset category in the fixture */
    protected function finalIds(): array
    {
        return array_map(fn (string $key) => $this->cat[$key]->id, ['leaf1', 'leaf2', 'direct', 'other', 'loose']);
    }

    /** A group whose global permissions allow every asset operation. */
    protected function groupWithAssetPermissions(): Group
    {
        return Group::factory()->create(['permissions' => json_encode([
            'assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1', 'assets.delete' => '1',
        ])]);
    }

    protected function userWithAssetPermissions(): User
    {
        return User::factory()->create(['permissions' => json_encode([
            'assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1', 'assets.delete' => '1',
        ])]);
    }

    /** Write a grant row directly (test setup only). */
    protected function grant(Group $group, Category $category, array $operations): void
    {
        // Explicit assignment: the model allows no mass assignment.
        $row = AssetCategoryPermission::query()->where('group_id', $group->id)->where('category_id', $category->id)->first()
            ?? new AssetCategoryPermission;
        $row->group_id = $group->id;
        $row->category_id = $category->id;
        foreach (AssetCategoryAccess::OPERATIONS as $operation) {
            $row->{AssetCategoryAccess::column($operation)} = in_array($operation, $operations, true) || (bool) $row->{AssetCategoryAccess::column($operation)};
        }
        $row->save();
    }
}
