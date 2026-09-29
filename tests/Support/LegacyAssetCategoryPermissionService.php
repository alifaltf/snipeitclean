<?php

namespace Tests\Support;

use App\Models\Category;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;

/**
 * TEST-ONLY resolver used by UsesLegacyAssetCategoryCompatibility.
 *
 * Replaces only the group-grant lookup: every user is treated as holding a
 * grant for every operation on every live asset category. The real
 * resolution around it is unchanged: Super Users are still unrestricted,
 * only final/assignable categories count, and the global Snipe-IT asset
 * permissions (assets.view for reading) are still the upper bound.
 *
 * Nothing is cached, because upstream tests create categories between
 * requests inside a single test.
 */
class LegacyAssetCategoryPermissionService extends AssetCategoryPermissionService
{
    public function forUser(?User $user): AssetCategoryAccess
    {
        $this->flush();

        return parent::forUser($user);
    }

    protected function grantsFor(User $user): array
    {
        return array_fill_keys(
            Category::query()->where('category_type', 'asset')->pluck('id')->all(),
            array_fill_keys(AssetCategoryAccess::OPERATIONS, true)
        );
    }
}
