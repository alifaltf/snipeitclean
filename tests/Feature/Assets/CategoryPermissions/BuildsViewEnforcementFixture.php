<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryPermissionService;
use Tests\Feature\Groups\AssetCategoryPermissions\BuildsPermissionFixture;

/**
 * ERS Phase 5B1 fixture: the Phase 5A random-name category tree plus one
 * asset in every live final category, and helpers to build users whose
 * access comes only from permission-group grants. These tests never use
 * the upstream compatibility trait: they run the real permission service.
 *
 * Tree (names are random, never hard-coded in the application):
 *   groupA > branch > leaf1, leaf2 ; groupA > direct ; groupA > deleted(soft)
 *   groupB > other ; loose (top-level final) ; emptyGroup ; accessory (non-asset)
 */
trait BuildsViewEnforcementFixture
{
    use BuildsPermissionFixture;

    /** @var array<string, Asset> one asset per live final category */
    protected array $asset = [];

    protected function buildAssets(): void
    {
        $this->buildTree();

        foreach (['leaf1', 'leaf2', 'direct', 'other', 'loose'] as $key) {
            $this->asset[$key] = $this->assetIn($this->cat[$key], [
                'asset_tag' => $this->randomName('TAG-'.$key),
                'serial' => $this->randomName('SER-'.$key),
                'name' => $this->randomName('Findable '.$key),
            ]);
        }
    }

    protected function assetIn(Category $category, array $attributes = []): Asset
    {
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        return Asset::factory()->create(array_merge(['model_id' => $model->id], $attributes));
    }

    /**
     * A non-Super-User whose global permissions come from $permissions and
     * whose category View grants come from one new group per entry of
     * $groups (each entry: list of category keys).
     *
     * @param  list<list<string>>  $groups
     */
    protected function viewer(array $groups, array $permissions = ['assets.view' => '1'], array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['permissions' => json_encode($permissions)], $attributes));

        foreach ($groups as $categoryKeys) {
            $group = Group::factory()->create(['permissions' => json_encode([])]);
            foreach ($categoryKeys as $key) {
                $this->grant($group, $this->cat[$key], ['view']);
            }
            $user->groups()->attach($group->id);
        }

        $this->flushPermissions();

        return $user->fresh();
    }

    protected function flushPermissions(): void
    {
        app(AssetCategoryPermissionService::class)->flush();
    }

    /** @param  list<string>  $keys */
    protected function assetIds(array $keys): array
    {
        return collect($keys)->map(fn (string $key) => $this->asset[$key]->id)->sort()->values()->all();
    }
}
