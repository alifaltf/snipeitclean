<?php

namespace App\Actions\AssetModels;

use App\Models\AssetModel;
use App\Rules\AssignableAssetCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 3: the single raw bulk-update path for Asset Models.
 *
 * Bulk edit writes with a query-builder UPDATE, which bypasses model
 * validation. When the update sets category_id, the category is validated
 * with the shared AssignableAssetCategory rule (share-locking the category
 * row) inside the same transaction as the UPDATE, BEFORE any row is
 * touched. A validation failure throws and updates zero models.
 */
final class BulkUpdateAssetModelsAction
{
    /**
     * @param  array<int, int|string>  $ids  asset model ids to update
     * @param  array<string, mixed>  $updates  column => value, already filtered by the caller
     * @return int number of rows updated
     *
     * @throws ValidationException
     */
    public static function run(array $ids, array $updates): int
    {
        return DB::transaction(function () use ($ids, $updates): int {
            if (array_key_exists('category_id', $updates)) {
                $problem = AssignableAssetCategory::problemFor($updates['category_id']);

                if ($problem !== null) {
                    throw ValidationException::withMessages([
                        'category_id' => AssignableAssetCategory::message($problem),
                    ]);
                }
            }

            return AssetModel::whereIn('id', $ids)->update($updates);
        });
    }
}
