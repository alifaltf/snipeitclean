<?php

namespace App\Services\AssetUniqueness;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ERS: how MySQL/MariaDB compares the values of one column: its type,
 * character set, collation and whether the collation ignores trailing
 * spaces (PAD SPACE) or not (NO PAD). Read from information_schema of the
 * current database; null on other drivers.
 */
final class ColumnCollation
{
    /** @var array<string, array{data_type: string, column_type: string, charset: ?string, collation: ?string, pad: bool}|null> */
    private static array $cache = [];

    public static function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public static function guard(string $identifier): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException('Unexpected identifier.');
        }

        return $identifier;
    }

    /**
     * @return array{data_type: string, column_type: string, charset: ?string, collation: ?string, pad: bool}|null
     *                                                                                                             null when the column does not exist or the driver is not MySQL/MariaDB
     */
    public static function of(string $column, bool $fresh = false): ?array
    {
        self::guard($column);
        if (! self::isMysql()) {
            return null;
        }
        $cacheKey = DB::connection()->getDatabaseName().'.'.$column;
        if (! $fresh && array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::connection()->getTablePrefix().'assets', $column]
        );
        if ($row === null) {
            return self::$cache[$cacheKey] = null;
        }

        $charset = is_string($row->charset_name) ? self::guard($row->charset_name) : null;
        $collation = is_string($row->collation_name) ? self::guard($row->collation_name) : null;

        return self::$cache[$cacheKey] = [
            'data_type' => strtolower((string) $row->data_type),
            'column_type' => (string) $row->column_type,
            'charset' => $charset,
            'collation' => $collation,
            'pad' => $collation !== null && self::padsSpace($collation),
        ];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    /** PAD SPACE collations ignore trailing spaces when comparing; NO PAD ones do not. */
    public static function padsSpace(string $collation): bool
    {
        try {
            // MySQL 8 reports it (its 0900 collations are NO PAD); MariaDB
            // has no such column and names NO PAD collations "*nopad*".
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
