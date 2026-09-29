<?php

namespace App\Actions\Categories;

use App\Exceptions\CategoryStillHasChildCategories;
use App\Exceptions\ItemStillHasAccessories;
use App\Exceptions\ItemStillHasAssetModels;
use App\Exceptions\ItemStillHasAssets;
use App\Exceptions\ItemStillHasComponents;
use App\Exceptions\ItemStillHasConsumables;
use App\Exceptions\ItemStillHasLicenses;
use App\Models\AssetCategoryPermission;
use App\Models\AssetCategoryViewScope;
use App\Models\Category;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DestroyCategoryAction
{
    /**
     * @throws CategoryStillHasChildCategories
     * @throws ItemStillHasAssets
     * @throws ItemStillHasAssetModels
     * @throws ItemStillHasComponents
     * @throws ItemStillHasAccessories
     * @throws ItemStillHasLicenses
     * @throws ItemStillHasConsumables
     */
    public static function run(Category $category): bool
    {
        return DB::transaction(fn (): bool => self::destroy($category));
    }

    /**
     * @throws CategoryStillHasChildCategories
     */
    private static function destroy(Category $category): bool
    {
        // ERS hierarchy: lock the row (same lock the hierarchy write action
        // takes) so a child cannot be attached while we check, then refuse
        // to delete any category that still has live child categories.
        Category::query()->whereKey($category->getKey())->lockForUpdate()->first(['id']);

        if (Category::query()->where('parent_id', $category->getKey())->exists()) {
            throw new CategoryStillHasChildCategories($category);
        }

        // ERS Phase 5B1: integrity check, so count every associated asset,
        // including ones hidden from this user by asset-category permissions.
        AssetCategoryViewScope::withoutRestriction(fn () => $category->loadCount([
            'assets as assets_count',
            'accessories as accessories_count',
            'consumables as consumables_count',
            'components as components_count',
            'licenses as licenses_count',
            'models as models_count',
        ]));

        if ($category->assets_count > 0) {
            throw new ItemStillHasAssets($category);
        }
        if ($category->accessories_count > 0) {
            throw new ItemStillHasAccessories($category);
        }
        if ($category->consumables_count > 0) {
            throw new ItemStillHasConsumables($category);
        }
        if ($category->components_count > 0) {
            throw new ItemStillHasComponents($category);
        }
        if ($category->licenses_count > 0) {
            throw new ItemStillHasLicenses($category);
        }
        if ($category->models_count > 0) {
            throw new ItemStillHasAssetModels($category);
        }

        Storage::disk('public')->delete('categories'.'/'.$category->image);

        // ERS Phase 5A: grants never outlive a live category.
        AssetCategoryPermission::query()->where('category_id', $category->getKey())->delete();
        app(AssetCategoryPermissionService::class)->flush();

        $category->delete();

        return true;
    }
}
