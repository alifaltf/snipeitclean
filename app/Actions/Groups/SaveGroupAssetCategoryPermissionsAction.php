<?php

namespace App\Actions\Groups;

use App\Models\AssetCategoryPermission;
use App\Models\Category;
use App\Models\Group;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 5A: the only writer of asset_category_permissions.
 *
 * The group form submits
 *
 *     asset_category_permissions[<final category id>][view|create|update|delete] = 1
 *
 * plus a hidden asset_category_permissions_submitted marker, so an empty
 * matrix (everything unchecked) still means "remove all grants", while a
 * request without the matrix leaves grants untouched.
 *
 * Nothing from the client is trusted:
 *  - the payload must be exactly that shape; any unknown operation, any
 *    value other than "1", a non-array row or a malformed id rejects the
 *    whole save;
 *  - every category id must be a live, final asset category, checked
 *    against the database under a shared lock inside the transaction;
 *    navigation groups, deleted, non-asset and unknown ids are rejected;
 *  - the saved grants are exactly the submitted checked boxes: the group's
 *    previous rows are replaced, so unchecked/omitted permissions go away.
 *
 * Only a Super User may submit the matrix.
 */
final class SaveGroupAssetCategoryPermissionsAction
{
    public const INPUT = 'asset_category_permissions';

    public const MARKER = 'asset_category_permissions_submitted';

    public const ABILITY = 'groups.manage_asset_category_permissions';

    /**
     * Read and structurally validate the matrix. Null means the matrix was
     * not submitted (leave the group's grants unchanged).
     *
     * @return array<int, array<string, true>>|null category id => granted operations
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws ValidationException
     */
    public static function fromRequest(Request $request): ?array
    {
        if (! $request->has(self::MARKER) && ! $request->has(self::INPUT)) {
            return null;
        }

        Gate::authorize(self::ABILITY);

        return self::parse($request->input(self::INPUT));
    }

    /**
     * @return array<int, array<string, true>>
     *
     * @throws ValidationException
     */
    public static function parse(mixed $input): array
    {
        // An all-unchecked form submits no matrix at all.
        if ($input === null) {
            return [];
        }
        if (! is_array($input)) {
            self::reject();
        }

        $matrix = [];
        foreach ($input as $key => $operations) {
            $categoryId = self::positiveId($key);
            if ($categoryId === null || ! is_array($operations)) {
                self::reject();
            }

            $granted = [];
            foreach ($operations as $operation => $value) {
                if (! is_string($operation) || ! in_array($operation, AssetCategoryAccess::OPERATIONS, true) || $value !== '1') {
                    self::reject();
                }
                $granted[$operation] = true;
            }

            if ($granted !== []) {
                $matrix[$categoryId] = $granted;
            }
        }

        return $matrix;
    }

    /**
     * Replace the group's grants with $matrix, atomically. Call inside the
     * transaction that saves the rest of the group so a failure here rolls
     * the whole group save back.
     *
     * @param  array<int, array<string, true>>  $matrix  from parse()
     *
     * @throws ValidationException
     */
    public static function replace(Group $group, array $matrix): void
    {
        DB::transaction(function () use ($group, $matrix) {
            $requested = array_map('intval', array_keys($matrix));

            // Resolve the requested ids against the database, share-locking
            // the rows so a concurrent hierarchy change (which locks asset
            // category rows exclusively) cannot turn one into a navigation
            // group or delete it before the grants are written.
            $valid = $requested === [] ? [] : Category::query()
                ->assetCategories()
                ->where('is_assignable', true)
                ->whereIn('id', $requested)
                ->sharedLock()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (array_diff($requested, $valid) !== []) {
                self::reject();
            }

            AssetCategoryPermission::query()->where('group_id', $group->id)->delete();

            $now = now();
            $rows = [];
            foreach ($matrix as $categoryId => $granted) {
                $row = ['group_id' => $group->id, 'category_id' => (int) $categoryId, 'created_at' => $now, 'updated_at' => $now];
                foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                    $row[AssetCategoryAccess::column($operation)] = isset($granted[$operation]);
                }
                $rows[] = $row;
            }

            if ($rows !== []) {
                AssetCategoryPermission::query()->insert($rows);
            }

            app(AssetCategoryPermissionService::class)->flush();
        });
    }

    /**
     * Grants of a group as category id => operation => true, for the form.
     *
     * @return array<int, array<string, true>>
     */
    public static function currentMatrix(Group $group): array
    {
        if (! $group->exists) {
            return [];
        }

        $matrix = [];
        foreach ($group->assetCategoryPermissions()->get() as $permission) {
            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                if ($permission->{AssetCategoryAccess::column($operation)}) {
                    $matrix[$permission->category_id][$operation] = true;
                }
            }
        }

        return $matrix;
    }

    /**
     * @throws ValidationException
     */
    private static function reject(): never
    {
        throw ValidationException::withMessages([
            self::INPUT => trans('admin/groups/asset_category_permissions.invalid'),
        ]);
    }

    private static function positiveId(mixed $key): ?int
    {
        if (is_int($key)) {
            return $key > 0 ? $key : null;
        }
        if (is_string($key) && preg_match('/\A[0-9]{1,18}\z/', $key) === 1) {
            $id = (int) $key;

            return $id > 0 ? $id : null;
        }

        return null;
    }
}
