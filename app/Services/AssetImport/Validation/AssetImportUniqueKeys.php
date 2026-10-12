<?php

namespace App\Services\AssetImport\Validation;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ERS Phase 6C: when two values of an assets column count as "the same".
 *
 * Snipe-IT's uniqueness (unique_undeleted) compares values in the database,
 * so on MySQL/MariaDB the column's collation decides: with the default
 * utf8mb4_unicode_ci, "abc", "ABC", "Àbc", "abc " and "straße"/"strasse"
 * are all equal. Comparing lower-cased strings in PHP misses accents,
 * expansions and padding, so on MySQL/MariaDB every key comes from the
 * database itself: the collation weight of the value (WEIGHT_STRING), with
 * trailing spaces removed first for PAD SPACE collations, which ignore
 * them. Two values have the same key exactly when the column's collation
 * says they are equal. Keys are computed in bulk (one query per chunk of
 * values), never per row.
 *
 * Other drivers (SQLite in tests) keep the Phase 6B comparison: trimmed and
 * lower-cased, matched against LOWER(column).
 *
 * The execution additionally re-checks uniqueness with a locking query
 * after inserting (AssetImportConflictCheck); these keys make validation
 * agree with that check.
 */
final class AssetImportUniqueKeys
{
    private const CHUNK = 500;

    /** @var array<string, array{charset: string, collation: string, pad: bool}|null> */
    private array $columns = [];

    /** The Phase 6B key, used where the database cannot provide one. */
    public static function fallbackKey(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * The comparison key of each value as stored in assets.$column.
     *
     * @param  list<string>  $values
     * @return array<string, string> value => key
     */
    public function keys(string $column, array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        if ($values === []) {
            return [];
        }

        $collation = $this->collation($column);
        if ($collation === null) {
            return array_combine($values, array_map(self::fallbackKey(...), $values));
        }

        $expression = 'CONVERT(? USING '.$collation['charset'].')';
        if ($collation['pad']) {
            $expression = "TRIM(TRAILING ' ' FROM ".$expression.')';
        }
        $select = 'HEX(WEIGHT_STRING('.$expression.' COLLATE '.$collation['collation'].'))';

        $keys = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $parts = [];
            foreach (array_keys($chunk) as $index) {
                $parts[] = 'SELECT '.$index.' AS i, '.$select.' AS k';
            }
            foreach (DB::select(implode(' UNION ALL ', $parts), $chunk) as $row) {
                $keys[$chunk[(int) $row->i]] = (string) $row->k;
            }
        }

        return $keys;
    }

    /**
     * Keys of the values that assets.$column already holds, among live
     * assets and, when $includeDeleted, soft-deleted ones too. Unscoped on
     * purpose: uniqueness is global; callers only say "already used".
     *
     * @param  list<string>  $values
     * @return array<string, true> key => true
     */
    public function existing(string $column, array $values, bool $includeDeleted): array
    {
        self::guardColumn($column);
        $values = array_values(array_unique(array_filter(array_map('strval', $values), fn ($v) => $v !== '')));
        $exact = $this->collation($column) !== null;

        $found = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $query = DB::table('assets');
            if ($exact) {
                // Compared with the column's own collation, like unique_undeleted.
                $query->whereIn($column, $chunk);
            } else {
                $query->whereIn(DB::raw('LOWER('.$column.')'), array_map(self::fallbackKey(...), $chunk));
            }
            if (! $includeDeleted) {
                $query->whereNull('deleted_at');
            }
            $stored = $query->pluck($column)->map(fn ($value) => (string) $value)->all();
            foreach ($this->keys($column, $stored) as $key) {
                $found[$key] = true;
            }
        }

        return $found;
    }

    /** Whether keys for this column come from the database collation. */
    public function isExact(string $column): bool
    {
        return $this->collation($column) !== null;
    }

    public static function guardColumn(string $column): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $column) !== 1) {
            throw new RuntimeException('Unexpected column name.');
        }
    }

    /** @return array{charset: string, collation: string, pad: bool}|null */
    private function collation(string $column): ?array
    {
        self::guardColumn($column);
        if (array_key_exists($column, $this->columns)) {
            return $this->columns[$column];
        }

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->columns[$column] = null;
        }

        $row = $connection->selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix().'assets', $column]
        );
        if ($row === null || ! is_string($row->collation_name) || ! is_string($row->charset_name)
            || preg_match('/\A[A-Za-z0-9_]+\z/', $row->collation_name.$row->charset_name) !== 1) {
            throw new RuntimeException('The collation of an assets column could not be determined.');
        }

        return $this->columns[$column] = [
            'charset' => $row->charset_name,
            'collation' => $row->collation_name,
            'pad' => $this->padsSpace($row->collation_name),
        ];
    }

    /** PAD SPACE collations ignore trailing spaces when comparing. */
    private function padsSpace(string $collation): bool
    {
        try {
            // MySQL 8 reports it (its 0900 collations are NO PAD); MariaDB
            // has no such column and marks NO PAD collations by name.
            $row = DB::selectOne('SELECT PAD_ATTRIBUTE AS pad FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?', [$collation]);
            if ($row !== null && is_string($row->pad)) {
                return strtoupper($row->pad) === 'PAD SPACE';
            }
        } catch (Throwable) {
            // Column not available (MariaDB): fall through.
        }

        return ! str_contains(strtolower($collation), 'nopad');
    }
}
