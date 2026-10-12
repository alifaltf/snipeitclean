<?php

namespace App\Services\AssetImport\Execution;

use App\Services\AssetImport\Validation\AssetImportUniqueKeys;
use Illuminate\Support\Facades\DB;

/**
 * ERS Phase 6C: the last uniqueness check of an import, run inside the
 * import transaction after every asset was inserted and before commit.
 *
 * For each unique column it asks the database which of the new assets
 * share their value with any other asset row (another new one, an
 * existing one, or a soft-deleted one where deleted assets count). The
 * comparison is the database's own "=", so on MySQL/MariaDB the column's
 * collation decides exactly as Snipe-IT's unique_undeleted would (case,
 * accents, trailing spaces); other drivers compare LOWER() values as
 * validation does.
 *
 * The query is a locking read (LOCK IN SHARE MODE on MySQL/MariaDB). It
 * therefore sees rows that other transactions committed after this
 * transaction's snapshot was taken, which plain revalidation cannot see,
 * and it holds share locks on the rows and gaps it scanned until commit,
 * so concurrent inserts into assets wait until this import commits or
 * rolls back. The assets table has no unique index on these columns, so a
 * writer that already validated before this check and inserts after this
 * import commits is not covered (see docs/ERS_REQUIREMENTS.md 8.3).
 *
 * Bounded: one query per chunk of new asset ids per column.
 */
final class AssetImportConflictCheck
{
    private const CHUNK = 500;

    /**
     * @param  array<int, int>  $assetIdsByRow  CSV row number => new asset id
     * @param  array<string, array{column: string, include_deleted: bool}>  $checks  destination key => column
     * @return array<int, list<string>> CSV row number => destination keys that clash
     */
    public function conflicts(array $assetIdsByRow, array $checks): array
    {
        if ($assetIdsByRow === []) {
            return [];
        }

        $rowsById = array_flip($assetIdsByRow);
        $exact = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $conflicts = [];

        foreach ($checks as $key => ['column' => $column, 'include_deleted' => $includeDeleted]) {
            AssetImportUniqueKeys::guardColumn($column);
            foreach (array_chunk(array_values($assetIdsByRow), self::CHUNK) as $chunk) {
                $query = DB::table('assets as new_asset')
                    ->join('assets as other_asset', function ($join) use ($column, $exact) {
                        if ($exact) {
                            $join->on('other_asset.'.$column, '=', 'new_asset.'.$column);
                        } else {
                            $join->on(DB::raw('LOWER(other_asset.'.$column.')'), '=', DB::raw('LOWER(new_asset.'.$column.')'));
                        }
                        $join->on('other_asset.id', '<>', 'new_asset.id');
                    })
                    ->whereIn('new_asset.id', $chunk)
                    ->whereNotNull('new_asset.'.$column)
                    ->where('new_asset.'.$column, '<>', '');
                if (! $includeDeleted) {
                    $query->whereNull('other_asset.deleted_at');
                }

                foreach ($query->distinct()->sharedLock()->pluck('new_asset.id') as $id) {
                    $conflicts[$rowsById[(int) $id]][] = $key;
                }
            }
        }

        ksort($conflicts);

        return $conflicts;
    }
}
