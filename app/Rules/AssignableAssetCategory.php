<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * ERS Phase 3: the single definition of "a category an Asset Model may use".
 *
 * A category is assignable to an Asset Model only when it
 *  - exists,
 *  - is not soft-deleted,
 *  - has category_type = asset, and
 *  - is a final/assignable node (is_assignable = true), i.e. not an ERS
 *    navigation group.
 *
 * The category row is read with a SHARED lock. Inside a transaction (every
 * Asset Model save and both bulk-edit paths run one) the lock is held until
 * the model row is written, so a concurrent hierarchy change (which takes
 * exclusive locks on asset category rows first) cannot turn the category
 * into a navigation group, change its type or delete it in between. Outside
 * a transaction the lock is released immediately and the check is still a
 * correct point-in-time validation.
 *
 * No category ids or names are hard-coded.
 */
final class AssignableAssetCategory implements ValidationRule
{
    public const INVALID = 'invalid';

    public const MISSING = 'missing';

    public const DELETED = 'deleted';

    public const NOT_ASSET = 'not_asset';

    public const NAVIGATION = 'navigation';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problemFor($value);

        if ($problem !== null) {
            $fail(self::message($problem));
        }
    }

    /**
     * Why $value cannot be used as an Asset Model category, or null when it
     * can. Reads (and share-locks) the category row.
     *
     * @return self::INVALID|self::MISSING|self::DELETED|self::NOT_ASSET|self::NAVIGATION|null
     */
    public static function problemFor(mixed $value): ?string
    {
        $id = self::normalizeId($value);
        if ($id === null) {
            return self::INVALID;
        }

        $row = self::lockingQuery($id)
            ->toBase()
            ->first(['id', 'category_type', 'is_assignable', 'deleted_at']);

        if ($row === null) {
            return self::MISSING;
        }
        if ($row->deleted_at !== null) {
            return self::DELETED;
        }
        if ($row->category_type !== 'asset') {
            return self::NOT_ASSET;
        }
        if (! (bool) (int) $row->is_assignable) {
            return self::NAVIGATION;
        }

        return null;
    }

    public static function passes(mixed $value): bool
    {
        return self::problemFor($value) === null;
    }

    /** Translated, user-facing message for a problem code. */
    public static function message(string $problem): string
    {
        return trans('admin/models/message.category_rule.'.$problem);
    }

    /**
     * The share-locked read of one category row, including soft-deleted
     * rows so "deleted" and "missing" can be told apart.
     */
    public static function lockingQuery(int $id): Builder
    {
        return Category::withTrashed()->whereKey($id)->sharedLock();
    }

    /** Positive integers and digit strings only; anything else is invalid. */
    private static function normalizeId(mixed $value): ?int
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
