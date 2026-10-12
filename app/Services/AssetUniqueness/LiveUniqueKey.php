<?php

namespace App\Services\AssetUniqueness;

use RuntimeException;

/**
 * ERS: one database-enforced uniqueness rule on the assets table, as a
 * VIRTUAL generated "live key" column plus a UNIQUE index on it.
 *
 * The key is NULL whenever the rule does not apply (deleted asset, blank
 * value), and a UNIQUE index accepts any number of NULLs, so only live,
 * non-blank values are constrained, exactly like Snipe-IT's
 * unique_undeleted. Equality is the source column's own:
 *
 *  - asset tag / serial (VARCHAR): the value itself, in the source column's
 *    character set and collation, so the index compares exactly as the
 *    column does (case, accents, expansions, and trailing spaces only for
 *    PAD SPACE collations);
 *  - text custom fields (TEXT, too long for a full-value index): SHA-256 of
 *    the value's collation weight (WEIGHT_STRING), trailing spaces removed
 *    first only for PAD SPACE collations. Two values have the same weight
 *    exactly when the collation says they are equal, so equal keys mean
 *    equal values. Limitation: two different weights with the same SHA-256
 *    (a collision) would be refused as duplicates; a duplicate is never
 *    missed;
 *  - date/datetime custom fields: the value itself.
 *
 * Every object is ERS-owned and named "ers_live_*"; nothing else in the
 * schema uses that prefix.
 */
final class LiveUniqueKey
{
    public const PREFIX = 'ers_live_';

    public const TAG = 'asset_tag';

    public const SERIAL = 'serial';

    private function __construct(
        /** asset_tag, serial or custom_field:<id> */
        public readonly string $key,
        /** source column on assets */
        public readonly string $sourceColumn,
        public readonly string $column,
        public readonly string $index,
        public readonly ?int $customFieldId = null,
    ) {}

    public static function tag(): self
    {
        return new self(self::TAG, 'asset_tag', self::PREFIX.'asset_tag', self::PREFIX.'asset_tag_unique');
    }

    public static function serial(): self
    {
        return new self(self::SERIAL, 'serial', self::PREFIX.'serial', self::PREFIX.'serial_unique');
    }

    public static function customField(int $id, string $dbColumn): self
    {
        if ($id <= 0) {
            throw new RuntimeException('Unexpected custom field id.');
        }

        return new self('custom_field:'.$id, ColumnCollation::guard($dbColumn), self::PREFIX.'cf_'.$id, self::PREFIX.'cf_'.$id.'_unique', $id);
    }

    /** The key named by an ERS index, or null for any other index name. */
    public static function keyForIndex(string $index): ?string
    {
        return match (true) {
            $index === self::PREFIX.'asset_tag_unique' => self::TAG,
            $index === self::PREFIX.'serial_unique' => self::SERIAL,
            preg_match('/\A'.self::PREFIX.'cf_([1-9][0-9]{0,9})_unique\z/', $index, $m) === 1 => 'custom_field:'.$m[1],
            default => null,
        };
    }

    /**
     * Column definition (type + generation expression), from the source
     * column's actual type and collation.
     *
     * @param  array{data_type: string, column_type: string, charset: ?string, collation: ?string, pad: bool}  $source
     */
    public function definition(array $source): string
    {
        $src = '`'.$this->sourceColumn.'`';
        $live = '`deleted_at` IS NULL';

        if (in_array($source['data_type'], ['date', 'datetime', 'timestamp'], true)) {
            return strtoupper($source['data_type']).' GENERATED ALWAYS AS (IF('.$live.', '.$src.', NULL)) VIRTUAL';
        }
        if ($source['charset'] === null || $source['collation'] === null) {
            throw new RuntimeException('The source column is not a text column.');
        }
        $notBlank = 'TRIM('.$src.") <> ''";

        if ($this->customFieldId === null) {
            if (! in_array($source['data_type'], ['varchar', 'char'], true)) {
                throw new RuntimeException('Unexpected source column type.');
            }
            $condition = $this->key === self::SERIAL ? $live.' AND '.$notBlank : $live;

            return $source['column_type'].' CHARACTER SET '.$source['charset'].' COLLATE '.$source['collation']
                .' GENERATED ALWAYS AS (IF('.$condition.', '.$src.', NULL)) VIRTUAL';
        }

        $value = $source['pad'] ? "TRIM(TRAILING ' ' FROM ".$src.')' : $src;

        return 'CHAR(64) CHARACTER SET ascii GENERATED ALWAYS AS (IF('.$live.' AND '.$notBlank
            .', SHA2(WEIGHT_STRING('.$value.' COLLATE '.$source['collation'].'), 256), NULL)) VIRTUAL';
    }

    /**
     * The key expression on the base table, used by the read-only audit to
     * count values that would violate the index (before it exists).
     *
     * @param  array{data_type: string, column_type: string, charset: ?string, collation: ?string, pad: bool}  $source
     */
    public function expression(array $source): string
    {
        $definition = $this->definition($source);
        if (preg_match('/GENERATED ALWAYS AS \((.*)\) VIRTUAL\z/s', $definition, $m) !== 1) {
            throw new RuntimeException('Unexpected key definition.');
        }

        return $m[1];
    }
}
