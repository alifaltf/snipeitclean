<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\AssetCategoryPermissionService;

/**
 * ERS Phase 5B1 TEST-ONLY compatibility for upstream Snipe-IT tests that
 * were written before asset-category permissions existed.
 *
 * Off by default: a test class gets it ONLY by writing
 * `use UsesLegacyAssetCategoryCompatibility;`. Every other test (all ERS
 * tests, including the Phase 5B1 security tests) runs against the real
 * AssetCategoryPermissionService with strict default deny.
 *
 * When enabled, Laravel's setUpTraits() calls the method below, which binds
 * LegacyAssetCategoryPermissionService in that test's container only. It
 * gives every non-Super-User a category View/Create/Update/Delete grant on
 * every live asset category, i.e. what an organisation gets by granting a
 * group every category. Nothing else changes: the category scope still
 * runs, global Snipe-IT permissions (assets.view first of all) are still
 * the upper bound, and company scoping still applies.
 *
 * withAssetView() is for upstream tests whose actor relied on reading
 * assets WITHOUT the global assets.view permission (checkout-only users,
 * end users accepting or listing their own assets, report-only users).
 * Phase 5B1 makes assets.view mandatory for every asset read, so those
 * tests now give the actor assets.view explicitly.
 *
 * Production never loads this: it lives under tests/ (autoload-dev) and
 * nothing in app/, config/ or routes/ refers to it.
 */
trait UsesLegacyAssetCategoryCompatibility
{
    protected function setUpUsesLegacyAssetCategoryCompatibility(): void
    {
        $this->app->scoped(AssetCategoryPermissionService::class, LegacyAssetCategoryPermissionService::class);
    }

    /** Add the global assets.view permission to a test actor (see above). */
    protected function withAssetView(User $user): User
    {
        $permissions = json_decode((string) $user->permissions, true) ?: [];
        $permissions['assets.view'] = '1';
        User::withoutGlobalScopes()->whereKey($user->getKey())->update(['permissions' => json_encode($permissions)]);

        return $user->fresh();
    }
}
