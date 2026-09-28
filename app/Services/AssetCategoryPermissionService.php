<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 5A: the one place that resolves effective asset-category
 * permissions (see AssetCategoryAccess for the rules).
 *
 * Registered as a SCOPED singleton, so results are cached for the current
 * request/job only, per user. Per user it runs at most two queries: the
 * live asset category tree (shared by all users in the request) and one
 * join over the user's groups' grants. Never one query per category.
 */
final class AssetCategoryPermissionService
{
    /** Global Snipe-IT asset ability that caps each category operation. */
    public const GLOBAL_ABILITIES = [
        AssetCategoryAccess::VIEW => 'view',     // assets.view
        AssetCategoryAccess::CREATE => 'create', // assets.create
        AssetCategoryAccess::UPDATE => 'update', // assets.edit
        AssetCategoryAccess::DELETE => 'delete', // assets.delete
    ];

    private ?AssetCategoryTree $tree = null;

    /** @var array<int, AssetCategoryAccess> user id => access */
    private array $cache = [];

    public function forUser(?User $user): AssetCategoryAccess
    {
        $tree = $this->tree();

        if ($user === null || ! $user->exists) {
            return new AssetCategoryAccess($tree, false, [], []);
        }

        return $this->cache[$user->id] ??= $this->resolve($user, $tree);
    }

    /**
     * Drop cached results (after grants, group membership or categories
     * change within the same request).
     */
    public function flush(): void
    {
        $this->tree = null;
        $this->cache = [];
    }

    private function resolve(User $user, AssetCategoryTree $tree): AssetCategoryAccess
    {
        $gate = Gate::forUser($user);
        $globalAllowed = [];
        foreach (self::GLOBAL_ABILITIES as $operation => $ability) {
            $globalAllowed[$operation] = $gate->allows($ability, Asset::class);
        }

        if ($user->isSuperUser()) {
            return new AssetCategoryAccess($tree, true, [], $globalAllowed);
        }

        return new AssetCategoryAccess($tree, false, $this->grantsFor($user), $globalAllowed);
    }

    /**
     * Union of every grant from every group the user belongs to, loaded in
     * one query.
     *
     * @return array<int, array<string, bool>>
     */
    private function grantsFor(User $user): array
    {
        $rows = DB::table('asset_category_permissions')
            ->join('users_groups', 'users_groups.group_id', '=', 'asset_category_permissions.group_id')
            ->where('users_groups.user_id', $user->id)
            ->get([
                'asset_category_permissions.category_id',
                'asset_category_permissions.can_view',
                'asset_category_permissions.can_create',
                'asset_category_permissions.can_update',
                'asset_category_permissions.can_delete',
            ]);

        $grants = [];
        foreach ($rows as $row) {
            $id = (int) $row->category_id;
            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                $column = AssetCategoryAccess::column($operation);
                $grants[$id][$operation] = ($grants[$id][$operation] ?? false) || (bool) (int) $row->{$column};
            }
        }

        return $grants;
    }

    private function tree(): AssetCategoryTree
    {
        return $this->tree ??= AssetCategoryTree::load();
    }
}
