<?php

namespace App\Actions\StatusLabels;

use App\Exceptions\ItemStillHasAssets;
use App\Models\AssetCategoryViewScope;
use App\Models\Statuslabel;

class DestroyStatuslabelAction
{
    /**
     * @throws ItemStillHasAssets
     */
    public static function run(Statuslabel $statuslabel): bool
    {
        // ERS Phase 5B1: integrity check, so count every associated asset,
        // including ones hidden from this user by asset-category permissions.
        AssetCategoryViewScope::withoutRestriction(fn () => $statuslabel->loadCount(['assets as assets_count']));

        if ($statuslabel->assets_count > 0) {
            throw new ItemStillHasAssets($statuslabel);
        }

        $statuslabel->delete();

        return true;
    }
}
