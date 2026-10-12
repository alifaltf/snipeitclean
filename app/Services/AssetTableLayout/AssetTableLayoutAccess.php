<?php

namespace App\Services\AssetTableLayout;

use App\Models\Category;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 7A: who may administer category asset-table layouts.
 *
 * - A Super User always may.
 * - Anyone else needs both existing global permissions "Edit Categories"
 *   (categories.edit) and "Edit Custom Fields" (customfields.edit),
 *   resolved through the existing CategoryPolicy and CustomFieldPolicy
 *   at class level. Those policies also accept the global Admin
 *   permission, so an Admin qualifies as well.
 *
 * Asset-category grants (View/Create/Edit/Delete of a category's assets),
 * company scope and location never grant this. The same rule guards the
 * configuration page, saving (including reordering and hiding) and
 * resetting.
 */
final class AssetTableLayoutAccess
{
    public const ABILITY = 'categories.manage_asset_table_layout';

    public static function allows(User $user): bool
    {
        if ($user->isSuperUser()) {
            return true;
        }

        $gate = Gate::forUser($user);

        return $gate->allows('update', Category::class) && $gate->allows('update', CustomField::class);
    }
}
