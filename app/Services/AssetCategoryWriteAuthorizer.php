<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 5B2: the single place that decides asset-category CREATE,
 * UPDATE (Edit) and DELETE for asset writes. Every enforcement point
 * (AssetPolicy, the AuthorisedAssetModel rule, the bulk paths, the model
 * pickers) asks this class; permission resolution itself stays in
 * AssetCategoryPermissionService / AssetCategoryAccess.
 *
 * Rules:
 *  - Super Admin (and no logged-in user, e.g. console) is not
 *    category-restricted, exactly like the Phase 5B1 View scope;
 *  - everyone else needs the operation granted (union of their groups,
 *    capped by the matching global Snipe-IT asset permission) on the final
 *    category of the asset model involved;
 *  - only a live (not soft-deleted) model whose category is a live, final,
 *    assignable ASSET category is ever a valid create/edit TARGET, for
 *    everyone, Super Admin included. Navigation-only, non-asset, missing and
 *    deleted categories and missing/deleted models are never fallbacks.
 *
 * No category ids or names are hard-coded.
 */
final class AssetCategoryWriteAuthorizer
{
    public function __construct(private readonly AssetCategoryPermissionService $permissions) {}

    /**
     * The access of $user (default: the logged-in user), or null when they
     * are not category-restricted (Super User, or no user at all).
     */
    public function access(?User $user = null): ?AssetCategoryAccess
    {
        if ($user === null) {
            return $this->permissions->forCurrentUser();
        }

        return $user->isSuperUser() ? null : $this->permissions->forUser($user);
    }

    /**
     * May the current user perform $operation on this EXISTING asset, judged
     * by the final category of its CURRENT (persisted) model? Used for Edit
     * and Delete of an asset. An asset whose model or category cannot be
     * resolved is Super-Admin-only.
     */
    public function allowsAsset(string $operation, Asset $asset, ?User $user = null): bool
    {
        AssetCategoryAccess::assertOperation($operation);

        $access = $this->access($user);
        if ($access === null) {
            return true;
        }

        $model = $this->currentModel($asset);
        if ($model === null || $model->trashed() || $model->category_id === null) {
            return false;
        }

        // allows() is only ever true for live final asset categories.
        return $access->allows($operation, (int) $model->category_id);
    }

    /**
     * May the current user use $modelId as the model of an asset they are
     * creating (CREATE) or moving an asset to (UPDATE)? False for anything
     * that is not a valid target, without telling the caller why, so a
     * hidden model and a missing one are indistinguishable.
     */
    public function allowsModel(string $operation, mixed $modelId): bool
    {
        AssetCategoryAccess::assertOperation($operation);

        $categoryId = $this->validTargetCategoryId($modelId);
        if ($categoryId === null) {
            return false;
        }

        $access = $this->access();

        return $access === null || $access->allows($operation, $categoryId);
    }

    /**
     * May a form pre-select $modelId (from ?model_id=, old input or the
     * asset being edited) for $operation? Only an authorised target, or the
     * asset's own current model when the user may edit that asset. Anything
     * else is simply not rendered, so a forged id reveals nothing.
     */
    public function mayPreselectModel(string $operation, mixed $modelId, ?Asset $asset = null): bool
    {
        if ($this->allowsModel($operation, $modelId)) {
            return true;
        }

        return $operation === AssetCategoryAccess::UPDATE
            && $asset !== null && $asset->exists
            && is_numeric($modelId) && (int) $modelId === $this->persistedModelId($asset)
            && $this->allowsAsset(AssetCategoryAccess::UPDATE, $asset);
    }

    /**
     * Every final category that is a valid target for $operation for the
     * current user: all live final asset categories when unrestricted,
     * otherwise only those the operation is granted on. Same rule as
     * allowsModel(), for filtering pickers in the database.
     *
     * @return list<int>
     */
    public function targetCategoryIds(string $operation): array
    {
        AssetCategoryAccess::assertOperation($operation);

        return $this->categoryIds($operation) ?? AssetCategoryTree::load()->assignableIds();
    }

    /**
     * Final categories in which the current user may perform $operation, or
     * null when they are unrestricted (then any valid final category counts).
     *
     * @return list<int>|null
     */
    public function categoryIds(string $operation): ?array
    {
        return $this->access()?->categoryIds($operation);
    }

    /**
     * The one normaliser for bulk asset selections submitted to the web bulk
     * paths. Accepts only a non-empty array whose every element is a
     * positive integer or a digit-only string; returns the ids as unique
     * integers in first-seen order. Anything else (not an array, empty,
     * nested arrays, zero, negative, decimal, boolean, null, malformed
     * strings) returns null, which callers turn into the generic
     * bulk_selection_unavailable answer BEFORE any query runs.
     *
     * @return list<int>|null
     */
    public static function normalizeSelection(mixed $requestedIds): ?array
    {
        if (! is_array($requestedIds) || $requestedIds === []) {
            return null;
        }

        $ids = [];
        foreach ($requestedIds as $id) {
            if (is_int($id)) {
                $value = $id;
            } elseif (is_string($id) && preg_match('/\A[0-9]{1,18}\z/', $id) === 1) {
                $value = (int) $id;
            } else {
                return null;
            }

            if ($value <= 0) {
                return null;
            }
            $ids[$value] = $value;
        }

        return array_values($ids);
    }

    /**
     * Did EVERY requested id resolve to an asset in $resolved (the result of
     * the caller's normal, scoped query)? False for a missing, hidden
     * (company or category scope), forged or malformed id, so bulk paths can
     * refuse the whole request with one generic answer and modify nothing.
     *
     * @param  iterable<Asset>  $resolved
     */
    public function resolvesSelection(mixed $requestedIds, iterable $resolved): bool
    {
        $requested = self::normalizeSelection($requestedIds);
        if ($requested === null) {
            return false;
        }

        $found = [];
        foreach ($resolved as $asset) {
            $found[(int) $asset->getKey()] = true;
        }

        foreach ($requested as $id) {
            if (! isset($found[$id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * May $user (default: the logged-in user) clone this asset? The single
     * rule used by the clone page AND by every Clone action shown in the UI:
     * global Create, a live (not soft-deleted) model, and category Create on
     * its final category. Super Admin bypasses grants but still needs a
     * valid target: a live final asset category. Uses the cached access and
     * the asset's loaded model, so it adds no query per table row.
     */
    public function canClone(Asset $asset, ?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User || ! $asset->exists) {
            return false;
        }

        if (! Gate::forUser($user)->allows('create', Asset::class)) {
            return false;
        }

        $model = $this->currentModel($asset);
        if ($model === null || $model->trashed() || ! is_numeric($model->category_id)) {
            return false;
        }

        // forUser() also resolves Super Admins: their access covers every
        // live final asset category and nothing else.
        return $this->permissions->forUser($user)->allows(AssetCategoryAccess::CREATE, (int) $model->category_id);
    }

    /**
     * Load the current model of every asset in one query (no per-asset
     * lookups in bulk paths). Assets keep the loaded relation, so the
     * following allowsAsset()/policy checks run without further queries.
     *
     * @param  iterable<Asset>  $assets
     */
    public function primeModels(iterable $assets): void
    {
        $assets = collect($assets);
        $ids = $assets->map(fn (Asset $asset) => $this->persistedModelId($asset))->filter()->unique()->values();
        $models = $ids->isEmpty() ? collect() : AssetModel::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($assets as $asset) {
            $asset->setRelation('model', $models->get($this->persistedModelId($asset)));
        }
    }

    /**
     * May the current user change an Asset Model's category while it still
     * has assets? Moving the model moves all of its assets, so a
     * category-restricted user needs Edit on both the old and the new final
     * category. Models without assets (including soft-deleted ones) are
     * unaffected.
     */
    public function allowsModelCategoryChange(int $modelId, mixed $fromCategoryId, mixed $toCategoryId): bool
    {
        $access = $this->access();
        if ($access === null || (string) $fromCategoryId === (string) $toCategoryId) {
            return true;
        }

        if (! DB::table('assets')->where('model_id', $modelId)->exists()) {
            return true;
        }

        return is_numeric($fromCategoryId) && is_numeric($toCategoryId)
            && $access->allows(AssetCategoryAccess::UPDATE, (int) $fromCategoryId)
            && $access->allows(AssetCategoryAccess::UPDATE, (int) $toCategoryId);
    }

    /**
     * Bulk form of allowsModelCategoryChange() for several models moving to
     * one category: a single query finds which of them still have assets.
     *
     * @param  iterable<AssetModel>  $models
     */
    public function allowsModelsCategoryChange(iterable $models, mixed $toCategoryId): bool
    {
        $access = $this->access();
        if ($access === null) {
            return true;
        }

        $models = collect($models)->filter(fn (AssetModel $model) => (string) $model->category_id !== (string) $toCategoryId);
        if ($models->isEmpty()) {
            return true;
        }

        $withAssets = DB::table('assets')->whereIn('model_id', $models->pluck('id'))->distinct()->pluck('model_id')->map(fn ($id) => (int) $id)->all();

        foreach ($models as $model) {
            if (in_array((int) $model->id, $withAssets, true)
                && ! (is_numeric($model->category_id) && is_numeric($toCategoryId)
                    && $access->allows(AssetCategoryAccess::UPDATE, (int) $model->category_id)
                    && $access->allows(AssetCategoryAccess::UPDATE, (int) $toCategoryId))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The final category of $modelId when it is a valid asset target: a
     * live model in a live, final/assignable asset category. Otherwise null.
     */
    private function validTargetCategoryId(mixed $modelId): ?int
    {
        if (is_int($modelId)) {
            $id = $modelId;
        } elseif (is_string($modelId) && preg_match('/\A[0-9]{1,18}\z/', $modelId) === 1) {
            $id = (int) $modelId;
        } else {
            return null;
        }

        if ($id <= 0) {
            return null;
        }

        $categoryId = DB::table('models')
            ->join('categories', 'categories.id', '=', 'models.category_id')
            ->where('models.id', $id)
            ->whereNull('models.deleted_at')
            ->whereNull('categories.deleted_at')
            ->where('categories.category_type', 'asset')
            ->where('categories.is_assignable', true)
            ->value('categories.id');

        return $categoryId === null ? null : (int) $categoryId;
    }

    /** The asset's persisted (current) model, never one only set in memory. */
    private function currentModel(Asset $asset): ?AssetModel
    {
        $modelId = $this->persistedModelId($asset);
        if ($modelId === null) {
            return null;
        }

        if ($asset->relationLoaded('model')) {
            $loaded = $asset->getRelation('model');
            if ($loaded === null || (int) $loaded->getKey() === $modelId) {
                return $loaded;
            }
        }

        return AssetModel::withTrashed()->find($modelId, ['id', 'category_id', 'deleted_at']);
    }

    private function persistedModelId(Asset $asset): ?int
    {
        $modelId = $asset->exists ? $asset->getOriginal('model_id') : $asset->model_id;

        return is_numeric($modelId) && (int) $modelId > 0 ? (int) $modelId : null;
    }
}
