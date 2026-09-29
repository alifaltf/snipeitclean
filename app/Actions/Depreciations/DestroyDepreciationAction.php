<?php

namespace App\Actions\Depreciations;

use App\Exceptions\ItemStillHasAssetModels;
use App\Exceptions\ItemStillHasAssets;
use App\Exceptions\ItemStillHasLicenses;
use App\Models\AssetCategoryViewScope;
use App\Models\Depreciation;

class DestroyDepreciationAction
{
    /**
     * @throws ItemStillHasAssets
     * @throws ItemStillHasAssetModels
     * @throws ItemStillHasLicenses
     */
    public static function run(Depreciation $depreciation): bool
    {
        // ERS Phase 5B1: integrity check, so count every associated asset,
        // including ones hidden from this user by asset-category permissions.
        AssetCategoryViewScope::withoutRestriction(fn () => $depreciation->loadCount([
            'assets as assets_count',
            'models as models_count',
            'licenses as licenses_count',
        ]));

        if ($depreciation->assets_count > 0) {
            throw new ItemStillHasAssets($depreciation);
        }

        if ($depreciation->models_count > 0) {
            throw new ItemStillHasAssetModels($depreciation);
        }

        if ($depreciation->licenses_count > 0) {
            throw new ItemStillHasLicenses($depreciation);
        }

        // Depreciation is not soft-deleted; this is a hard delete.
        $depreciation->delete();

        return true;
    }
}
