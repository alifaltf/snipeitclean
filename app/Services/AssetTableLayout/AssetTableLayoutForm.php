<?php

namespace App\Services\AssetTableLayout;

use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 7A: reads the non-JavaScript layout form.
 *
 * The form submits one row per selectable column:
 *
 *     columns[<i>][key]      = column key (hidden)
 *     columns[<i>][show]     = 1 when the column is shown (checkbox)
 *     columns[<i>][position] = order number, 1..MAX_POSITION
 *
 * The shown columns are ordered by their order number; equal numbers keep
 * the order of the rows. Anything else (a non-list, a row that is not an
 * array, unknown row fields, a key repeated across rows, a value other
 * than "1" for show) rejects the whole submission. Keys are validated
 * against the catalogue and the database by AssetTableLayoutService.
 */
final class AssetTableLayoutForm
{
    public const INPUT = 'columns';

    public const MAX_POSITION = 999;

    /** Most rows a submission may contain. */
    private const MAX_ROWS = 1000;

    /**
     * @return list<string> the shown keys, in display order
     *
     * @throws ValidationException
     */
    public static function keys(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            self::reject([self::INPUT => trans('admin/categories/table_layout.message.invalid')]);
        }

        $shown = [];
        $seen = [];
        $errors = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['key', 'show', 'position']) !== []) {
                self::reject([self::INPUT => trans('admin/categories/table_layout.message.invalid')]);
            }
            $key = $row['key'] ?? null;
            if (! is_string($key) || $key === '') {
                self::reject([self::INPUT => trans('admin/categories/table_layout.message.invalid')]);
            }
            if (isset($seen[$key])) {
                self::reject([self::INPUT => trans('admin/categories/table_layout.message.duplicate')]);
            }
            $seen[$key] = true;

            $show = $row['show'] ?? null;
            if ($show !== null && $show !== '1') {
                self::reject([self::INPUT => trans('admin/categories/table_layout.message.invalid')]);
            }
            $position = $row['position'] ?? null;
            if ($position !== null && ! is_string($position)) {
                self::reject([self::INPUT => trans('admin/categories/table_layout.message.invalid')]);
            }
            if ($show === null) {
                continue;
            }

            $number = self::position($position);
            if ($number === null) {
                $errors[self::INPUT.'.'.$index.'.position'] = trans('admin/categories/table_layout.message.position_invalid', ['max' => self::MAX_POSITION]);

                continue;
            }
            $shown[] = ['key' => $key, 'position' => $number, 'index' => $index];
        }

        if ($errors !== []) {
            self::reject($errors);
        }

        usort($shown, fn (array $a, array $b) => [$a['position'], $a['index']] <=> [$b['position'], $b['index']]);

        return array_map(fn (array $entry) => $entry['key'], $shown);
    }

    private static function position(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;
        if (! is_string($value) || preg_match('/\A[0-9]{1,3}\z/', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number >= 1 && $number <= self::MAX_POSITION ? $number : null;
    }

    /** @throws ValidationException */
    private static function reject(array $errors): never
    {
        throw ValidationException::withMessages($errors);
    }
}
