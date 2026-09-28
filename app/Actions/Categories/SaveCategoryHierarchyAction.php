<?php

namespace App\Actions\Categories;

use App\Models\AssetModel;
use App\Models\Category;
use App\Services\AssetCategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for categories that may carry hierarchy data
 * (ERS Feature 1, Phase 2). Every web and API create/update of a category
 * goes through run(), so the hierarchy invariants cannot be bypassed by a
 * different entry point.
 *
 * The caller prepares the category's ordinary attributes (name, EULA,
 * image, category_type, ...) WITHOUT saving it, and passes only the
 * explicit hierarchy inputs it received (see inputFrom()). run() then,
 * inside one database transaction:
 *
 *  1. checks the Super User ability when any hierarchy input is present;
 *  2. locks every live asset category row (and the category's own row) so
 *     concurrent moves cannot race each other into a cycle;
 *  3. re-reads the category's stored state from the database and rebuilds
 *     the tree from the locked rows. Client-supplied paths, depths,
 *     descendant lists and stored types are never trusted;
 *  4. validates the requested parent, node role, order and any
 *     category_type change;
 *  5. assigns parent_id / is_assignable / sort_order explicitly (never via
 *     mass assignment) and saves.
 *
 * Any validation failure throws ValidationException and any other failure
 * propagates; either way the transaction is rolled back and nothing is
 * written.
 */
final class SaveCategoryHierarchyAction
{
    /** The only hierarchy inputs this action accepts. */
    public const FIELDS = ['parent_id', 'is_assignable', 'sort_order'];

    /** The ability that guards every hierarchy write. */
    public const ABILITY = 'categories.manage_hierarchy';

    public const MAX_SORT_ORDER = 999999;

    /**
     * Extract ONLY the explicitly supplied hierarchy fields from a request.
     * A key that is present with an empty value still counts as supplied.
     *
     * @return array<string, mixed>
     */
    public static function inputFrom(Request $request): array
    {
        $input = [];
        foreach (self::FIELDS as $field) {
            if ($request->has($field)) {
                $input[$field] = $request->input($field);
            }
        }

        return $input;
    }

    /**
     * Throw an AuthorizationException (HTTP 403) when hierarchy fields are
     * supplied by anyone who is not a Super User. Hierarchy fields are
     * never silently discarded.
     *
     * @param  array<string, mixed>  $input
     */
    public static function authorizeInput(array $input): void
    {
        if (array_intersect_key($input, array_flip(self::FIELDS)) !== []) {
            Gate::authorize(self::ABILITY);
        }
    }

    /**
     * Validate and save the category together with its hierarchy fields.
     *
     * @param  array<string, mixed>  $input  hierarchy inputs from inputFrom()
     *
     * @throws ValidationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function run(Category $category, array $input = []): Category
    {
        $input = array_intersect_key($input, array_flip(self::FIELDS));
        self::authorizeInput($input);

        return DB::transaction(function () use ($category, $input): Category {
            $errors = (new self)->apply($category, $input);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if (! $category->save()) {
                throw ValidationException::withMessages($category->getErrors()->toArray());
            }

            return $category;
        });
    }

    /**
     * Resolve, validate and assign the hierarchy attributes. Must run inside
     * a transaction.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> field => translated message
     */
    private function apply(Category $category, array $input): array
    {
        // Lock every live asset category row in a stable order before any
        // structural read. All hierarchy writes (and deletes) take locks in
        // this order, so concurrent writers serialise instead of deadlocking.
        $assetRows = Category::query()
            ->assetCategories()
            ->orderBy('id')
            ->lockForUpdate()
            ->toBase()
            ->get(['id', 'name', 'parent_id', 'is_assignable', 'sort_order']);

        $current = null;
        if ($category->exists) {
            $current = Category::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->toBase()
                ->first(['id', 'category_type', 'parent_id', 'is_assignable', 'sort_order']);

            if ($current === null) {
                return ['name' => trans('admin/categories/message.does_not_exist')];
            }
        }

        $id = $current ? (int) $current->id : null;
        $storedType = $current ? (string) $current->category_type : null;
        $storedParent = $current ? self::positiveIntOrNull($current->parent_id) : null;
        $storedAssignable = $current ? (bool) (int) $current->is_assignable : true;
        $storedSort = $current ? max(0, (int) $current->sort_order) : 0;

        // 1. Parse the explicit inputs; anything not supplied keeps its stored value.
        $errors = [];
        $parentId = $storedParent;
        $assignable = $storedAssignable;
        $sortOrder = $storedSort;

        if (array_key_exists('parent_id', $input)) {
            [$ok, $value] = self::parseParent($input['parent_id']);
            if ($ok) {
                $parentId = $value;
            } else {
                $errors['parent_id'] = trans('admin/categories/message.hierarchy.invalid_parent');
            }
        }
        if (array_key_exists('is_assignable', $input)) {
            [$ok, $value] = self::parseBool($input['is_assignable']);
            if ($ok) {
                $assignable = $value;
            } else {
                $errors['is_assignable'] = trans('admin/categories/message.hierarchy.invalid_is_assignable');
            }
        }
        if (array_key_exists('sort_order', $input)) {
            [$ok, $value] = self::parseSortOrder($input['sort_order']);
            if ($ok) {
                $sortOrder = $value;
            } else {
                $errors['sort_order'] = trans('admin/categories/message.hierarchy.invalid_sort_order', ['max' => self::MAX_SORT_ORDER]);
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $type = (string) $category->category_type;
        $typeChanged = $current !== null && $storedType !== $type;

        // 2. category_type changes are only allowed for a flat, unused category.
        if ($typeChanged) {
            $inHierarchy = $storedParent !== null
                || $this->liveChildCount($id) > 0
                || ($storedType === 'asset' && ! $storedAssignable);

            if ($inHierarchy) {
                return ['category_type' => trans('admin/categories/message.update.cannot_change_category_type_hierarchy')];
            }
            if ($this->modelCountIncludingDeleted($id) > 0 || $this->storedItemCount($id) > 0) {
                return ['category_type' => trans('admin/categories/message.update.cannot_change_category_type_in_use')];
            }
        }

        // 3. Hierarchy is only for asset categories. Others always stay flat.
        if ($type !== 'asset') {
            $offending = match (true) {
                array_key_exists('parent_id', $input) && $parentId !== null => 'parent_id',
                array_key_exists('is_assignable', $input) && $assignable === false => 'is_assignable',
                array_key_exists('sort_order', $input) && $sortOrder !== 0 => 'sort_order',
                default => null,
            };

            if ($offending !== null) {
                return [$offending => trans('admin/categories/message.hierarchy.asset_only')];
            }

            $this->assign($category, null, true, 0);

            return [];
        }

        $isNew = $current === null;
        $becameAsset = $isNew || $typeChanged;

        // 4. Parent validation, only when the parent actually changes.
        if ($parentId !== null && ($becameAsset || $parentId !== $storedParent)) {
            $rows = $assetRows->keyBy(fn ($row) => (int) $row->id);
            $tree = AssetCategoryTree::fromRows($assetRows);

            if ($id !== null && $parentId === $id) {
                return ['parent_id' => trans('admin/categories/message.hierarchy.parent_is_self')];
            }

            $parentRow = $rows->get($parentId);
            if ($parentRow === null) {
                $exists = Category::query()->whereKey($parentId)->exists();

                return ['parent_id' => trans($exists
                    ? 'admin/categories/message.hierarchy.parent_not_asset'
                    : 'admin/categories/message.hierarchy.parent_not_found')];
            }

            if ((bool) (int) $parentRow->is_assignable) {
                return ['parent_id' => trans('admin/categories/message.hierarchy.parent_not_navigation')];
            }

            if ($id !== null && ($tree->isSelfOrDescendant($id, $parentId) || $this->storedAncestorsInclude($rows, $parentId, $id))) {
                return ['parent_id' => trans('admin/categories/message.hierarchy.parent_is_descendant')];
            }

            $subtreeHeight = ($id !== null && $tree->has($id)) ? $tree->height($id) : 1;
            if ($tree->depth($parentId) + $subtreeHeight > AssetCategoryTree::MAX_DEPTH) {
                return ['parent_id' => trans('admin/categories/message.hierarchy.max_depth', ['max' => AssetCategoryTree::MAX_DEPTH])];
            }
        }

        // 5. Node role conversions.
        $becomesGroup = ! $assignable && ($becameAsset || $storedAssignable);
        if ($becomesGroup && $id !== null && $this->modelCountIncludingDeleted($id) > 0) {
            return ['is_assignable' => trans('admin/categories/message.hierarchy.has_models')];
        }

        $becomesFinal = $assignable && ! $becameAsset && ! $storedAssignable;
        if ($becomesFinal && $this->liveChildCount($id) > 0) {
            return ['is_assignable' => trans('admin/categories/message.hierarchy.has_children')];
        }

        $this->assign($category, $parentId, $assignable, $sortOrder);

        return [];
    }

    /**
     * Explicit attribute assignment. Hierarchy columns are not fillable.
     */
    private function assign(Category $category, ?int $parentId, bool $assignable, int $sortOrder): void
    {
        $category->parent_id = $parentId;
        $category->is_assignable = $assignable;
        $category->sort_order = $sortOrder;
    }

    /**
     * Walk the STORED parent pointers upwards from $startId and report
     * whether $needleId is reached. Catches ancestry that the tree view
     * detached (e.g. corrupt cycles) as well as normal descendants.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function storedAncestorsInclude($rows, int $startId, int $needleId): bool
    {
        $seen = [];
        $current = $startId;
        while ($current !== null && ! isset($seen[$current])) {
            if ($current === $needleId) {
                return true;
            }
            $seen[$current] = true;
            $row = $rows->get($current);
            $current = $row ? self::positiveIntOrNull($row->parent_id) : null;
        }

        return false;
    }

    private function liveChildCount(?int $id): int
    {
        if ($id === null) {
            return 0;
        }

        return Category::query()->where('parent_id', $id)->count();
    }

    /**
     * Concurrency (ERS Phase 3): every Asset Model category write share-locks
     * the target category row (App\Rules\AssignableAssetCategory) inside the
     * transaction that writes the model. This action has already taken
     * exclusive locks on all asset category rows before this count runs, so
     * either a concurrent model write finished first (and is counted here)
     * or it is blocked until this transaction commits and then sees the
     * category's new state. The count is a LOCKING read so it always sees the
     * latest committed rows, even when this action runs inside a longer outer
     * transaction whose snapshot is older (the CSV importer). Both sides lock
     * category rows before model rows, so they cannot deadlock on each other.
     * This count only runs for conversions and type changes.
     */
    private function modelCountIncludingDeleted(?int $id): int
    {
        if ($id === null) {
            return 0;
        }

        return self::modelReferenceQuery($id)->count();
    }

    /**
     * Asset models (including soft-deleted ones) that reference a category,
     * read with a shared lock. Public so the locking strategy can be tested.
     */
    public static function modelReferenceQuery(int $categoryId): Builder
    {
        return AssetModel::withTrashed()->where('category_id', $categoryId)->sharedLock();
    }

    /**
     * Live items of the category's STORED type (assets for asset
     * categories, accessories for accessory categories, ...).
     */
    private function storedItemCount(?int $id): int
    {
        if ($id === null) {
            return 0;
        }

        return (int) Category::query()->find($id)?->itemCount();
    }

    /** @return array{0: bool, 1: ?int} */
    private static function parseParent(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [true, null];
        }
        $int = self::positiveIntOrNull($value);

        return $int === null ? [false, null] : [true, $int];
    }

    /** @return array{0: bool, 1: bool} */
    private static function parseBool(mixed $value): array
    {
        if (is_bool($value)) {
            return [true, $value];
        }
        if ($value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true')) {
            return [true, true];
        }
        if ($value === 0 || $value === '0' || (is_string($value) && strtolower($value) === 'false')) {
            return [true, false];
        }

        return [false, false];
    }

    /** @return array{0: bool, 1: int} */
    private static function parseSortOrder(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [true, 0];
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $int = (int) $value;
            if ($int >= 0 && $int <= self::MAX_SORT_ORDER) {
                return [true, $int];
            }
        }

        return [false, 0];
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
