<?php

namespace Tests\Feature\Assets\CategoryNavigation;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use Illuminate\Support\Str;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;

/**
 * Builds an asset category hierarchy with RANDOM names (so nothing in the
 * application can depend on specific names or ids):
 *
 *   groupA (sort 1)
 *     branch (group)
 *       leaf1 (final)          asset: leaf1Asset
 *       leaf2 (final)          asset: leaf2Asset
 *     directFinal (final)      asset: directAsset
 *     deletedFinal (final, soft-deleted)
 *   groupB (sort 0)
 *     otherFinal (final)       asset: otherAsset     <- unrelated branch
 *   ungrouped (final, root)    asset: ungroupedAsset
 *   emptyGroup (group)
 *     emptyChild (group)                              <- no assignable descendants
 *   accessoryCategory (non-asset)
 */
trait BuildsAssetCategoryFixture
{
    use CreatesCategoryHierarchy;

    /** @var array<string, Category> */
    protected array $cat = [];

    /** @var array<string, Asset> */
    protected array $asset = [];

    protected function randomName(string $prefix): string
    {
        return $prefix.' '.Str::random(8);
    }

    protected function assetIn(Category $category, array $attributes = []): Asset
    {
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        return Asset::factory()->create(array_merge(['model_id' => $model->id], $attributes));
    }

    protected function buildFixture(): void
    {
        $this->cat['groupA'] = $this->group($this->randomName('Group A'), null, 1);
        $this->cat['branch'] = $this->group($this->randomName('Branch'), $this->cat['groupA']);
        $this->cat['leaf1'] = $this->finalCategory($this->randomName('Leaf 1'), $this->cat['branch'], 0);
        $this->cat['leaf2'] = $this->finalCategory($this->randomName('Leaf 2'), $this->cat['branch'], 1);
        $this->cat['directFinal'] = $this->finalCategory($this->randomName('Direct'), $this->cat['groupA']);
        $this->cat['deletedFinal'] = $this->finalCategory($this->randomName('Deleted'), $this->cat['groupA']);

        $this->cat['groupB'] = $this->group($this->randomName('Group B'), null, 0);
        $this->cat['otherFinal'] = $this->finalCategory($this->randomName('Other'), $this->cat['groupB']);

        $this->cat['ungrouped'] = $this->finalCategory($this->randomName('Ungrouped'), null, 5);

        $this->cat['emptyGroup'] = $this->group($this->randomName('Empty'), null, 2);
        $this->cat['emptyChild'] = $this->group($this->randomName('Empty child'), $this->cat['emptyGroup']);

        $this->cat['accessoryCategory'] = Category::factory()->forAccessories()->create(['name' => $this->randomName('Accessory')]);

        $this->asset['leaf1'] = $this->assetIn($this->cat['leaf1']);
        $this->asset['leaf2'] = $this->assetIn($this->cat['leaf2']);
        $this->asset['directFinal'] = $this->assetIn($this->cat['directFinal']);
        $this->asset['otherFinal'] = $this->assetIn($this->cat['otherFinal']);
        $this->asset['ungrouped'] = $this->assetIn($this->cat['ungrouped']);

        // Soft-delete the category only after its (now empty) use is set up.
        $this->cat['deletedFinal']->delete();
    }

    /** @param  list<string>  $keys */
    protected function assetIds(array $keys): array
    {
        return collect($keys)->map(fn (string $key) => $this->asset[$key]->id)->sort()->values()->all();
    }
}
