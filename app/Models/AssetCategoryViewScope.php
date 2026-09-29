<?php

namespace App\Models;

use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * ERS Phase 5B1: asset-category VIEW enforcement.
 *
 * A global scope on Asset, applied exactly like Snipe-IT's CompanyableScope:
 * to every Eloquent asset query made for a logged-in user who is not a
 * Super User (lists, API, route-model binding, relations, counts, reports,
 * exports, search, dashboards). It adds one database constraint, so the
 * filtering happens before totals, pagination, exports or transformation:
 *
 *     assets.model_id IN (SELECT id FROM models
 *                         WHERE deleted_at IS NULL AND category_id IN (<authorised>))
 *
 * <authorised> comes from AssetCategoryPermissionService (the single
 * resolver): the user's group grants, capped by the global assets.view
 * permission. With no authorised categories the query matches nothing.
 * Assets whose model is null, missing or soft-deleted, or whose category is
 * missing, soft-deleted, non-asset or navigation-only, are Super-Admin-only.
 *
 * Unchanged: guests and console/queue jobs (no logged-in user) and Super
 * Users are not restricted, the same as company scoping. Company scoping
 * and every other scope still apply alongside this one.
 *
 * There are no exceptions: assets assigned to the logged-in user are
 * category-restricted too, including on their own account pages.
 *
 * Integrity checks: withoutRestriction() runs a callback with this scope
 * (and AssetReferenceCategoryScope) switched off. It exists ONLY for
 * server-side integrity decisions that must count every asset, e.g. "this
 * location/supplier/user still has assets, refuse to delete it" or "units
 * of this component already checked out". Never use it to load data that
 * is shown or returned to the user.
 */
final class AssetCategoryViewScope implements Scope
{
    /** > 0 while an integrity check runs through withoutRestriction(). */
    private static int $unrestrictedDepth = 0;

    public function apply(Builder $builder, Model $model): void
    {
        $restriction = self::restriction();
        if ($restriction === null) {
            return;
        }

        $builder->where(fn ($query) => self::constrainAssets($query->getQuery(), $model->getTable(), $restriction));
    }

    /**
     * "exists:assets,id" for request validation, limited to assets the
     * current user may view, so a hidden asset id fails exactly like an id
     * that does not exist (no existence oracle for checkout targets).
     */
    public static function existsRule(): Exists
    {
        return Rule::exists('assets', 'id')->where(function (QueryBuilder $query) {
            $viewable = self::viewableAssetIds();
            if ($viewable !== null) {
                $query->whereIn('id', $viewable);
            }
        });
    }

    /**
     * Re-apply the category restriction to an asset query that removed
     * global scopes (e.g. checkout-target lookups that call
     * withoutGlobalScopes() to tell soft-deleted or other-company targets
     * apart). A hidden asset id then simply finds nothing.
     */
    public static function restrict(Builder $builder): Builder
    {
        $restriction = self::restriction();
        if ($restriction !== null) {
            $table = $builder->getModel()->getTable();
            $builder->where(fn ($query) => self::constrainAssets($query->getQuery(), $table, $restriction));
        }

        return $builder;
    }

    /**
     * Ids of assets the current user may view, as a subquery for other
     * models that reference assets. Null when the user is unrestricted.
     * (Category rule only: those models keep their own company scoping.)
     */
    public static function viewableAssetIds(): ?QueryBuilder
    {
        $restriction = self::restriction();
        if ($restriction === null) {
            return null;
        }

        return DB::table('assets')
            ->select('assets.id')
            ->where(fn (QueryBuilder $query) => self::constrainAssets($query, 'assets', $restriction));
    }

    /**
     * Run an integrity check with category restrictions switched off (see
     * the class docblock). Nesting and exceptions are handled.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutRestriction(Closure $callback): mixed
    {
        self::$unrestrictedDepth++;

        try {
            return $callback();
        } finally {
            self::$unrestrictedDepth--;
        }
    }

    /**
     * @return list<int>|null authorised final category ids, or null = unrestricted
     */
    private static function restriction(): ?array
    {
        if (self::$unrestrictedDepth > 0) {
            return null;
        }

        // Same guard as CompanyableScope: no logged-in user (console, queue,
        // login screen) or a Super User means no category scoping.
        $access = app(AssetCategoryPermissionService::class)->forCurrentUser();
        if ($access === null) {
            return null;
        }

        return $access->categoryIds(AssetCategoryAccess::VIEW);
    }

    /**
     * @param  list<int>  $categoryIds
     */
    private static function constrainAssets(QueryBuilder $query, string $table, array $categoryIds): void
    {
        // Viewable only when the asset's model exists, is NOT soft-deleted and
        // belongs to an authorised category. $categoryIds only ever contains
        // live (not soft-deleted) final/assignable ASSET categories, so a
        // null/missing/soft-deleted model, a model without a category, and a
        // missing, soft-deleted, non-asset or navigation-only category all
        // match nothing: such assets are Super-Admin-only. An empty list
        // compiles to "0 = 1": no categories, no assets.
        $query->whereIn(
            $table.'.model_id',
            DB::table('models')
                ->select('models.id')
                ->whereNull('models.deleted_at')
                ->whereIn('models.category_id', $categoryIds)
        );
    }
}
