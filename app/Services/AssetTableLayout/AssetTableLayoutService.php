<?php

namespace App\Services\AssetTableLayout;

use App\Models\AssetCategoryColumn;
use App\Models\Category;
use App\Models\CustomField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 7A: the only reader and writer of category asset-table layouts.
 *
 * A layout belongs to a final asset category (a live asset category that
 * is not a navigation group). It is an ordered list of column keys (see
 * AssetTableFieldCatalog): approved standard fields, and custom fields
 * that at least one live asset model of the category uses through its
 * fieldset ("eligible").
 *
 * - Effective layout: the category's stored keys, in order, keeping only
 *   keys that are still valid and eligible now; a category without stored
 *   keys (or whose stored keys have all become ineligible) uses
 *   AssetTableFieldCatalog::DEFAULT_KEYS. Navigation groups, non-asset and
 *   deleted categories have no layout.
 * - Saving replaces the complete layout in one transaction, after locking
 *   the category row and re-checking the category and every key against
 *   the database. Concurrent saves of one category are serialised by that
 *   lock: the last one to commit wins and is stored exactly; the database
 *   keys (category, field) and (category, position) are unique, so no
 *   interleaving can store duplicates.
 * - Resetting deletes only the category's layout rows.
 *
 * Nothing here reads or writes assets or custom-field values; hiding a
 * column only removes its key from the layout.
 */
final class AssetTableLayoutService
{
    /** Validation error key for layout errors. */
    public const ERROR_KEY = 'columns';

    /** Whether the category can have a layout (a live, final asset category). */
    public function isConfigurable(?Category $category): bool
    {
        return $category !== null
            && $category->exists
            && ! $category->trashed()
            && $category->category_type === 'asset'
            && $category->is_assignable !== false;
    }

    /** Whether the category has stored layout rows. */
    public function hasExplicitLayout(Category $category): bool
    {
        return $this->isConfigurable($category)
            && AssetCategoryColumn::query()->where('category_id', $category->getKey())->exists();
    }

    /**
     * The columns the category's asset table shows, in order.
     *
     * @return list<AssetTableColumn> empty when the category has no layout
     */
    public function effectiveColumns(Category $category): array
    {
        if (! $this->isConfigurable($category)) {
            return [];
        }

        $stored = AssetCategoryColumn::query()
            ->where('category_id', $category->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('field_key')
            ->all();

        $eligible = $this->eligibleCustomFields((int) $category->getKey());
        $columns = $this->resolve($stored, $eligible);

        return $columns !== [] ? $columns : $this->resolve(AssetTableFieldCatalog::DEFAULT_KEYS, $eligible);
    }

    /**
     * Keys of the effective layout.
     *
     * @return list<string>
     */
    public function effectiveKeys(Category $category): array
    {
        return array_map(fn (AssetTableColumn $column) => $column->key, $this->effectiveColumns($category));
    }

    /**
     * Every column that may be selected for the category: the approved
     * standard fields, then its eligible custom fields by name.
     *
     * @return list<AssetTableColumn>
     */
    public function selectableColumns(Category $category): array
    {
        if (! $this->isConfigurable($category)) {
            return [];
        }

        $columns = AssetTableFieldCatalog::standardColumns();
        foreach ($this->eligibleCustomFields((int) $category->getKey()) as $field) {
            $columns[] = $this->customColumn($field);
        }

        return $columns;
    }

    /**
     * Validate and store the complete ordered layout of a category,
     * replacing any previous layout.
     *
     * @return list<string> the stored keys, in order
     *
     * @throws ValidationException
     */
    public function save(Category $category, mixed $keys): array
    {
        $keys = $this->normalise($keys);

        return DB::transaction(function () use ($category, $keys): array {
            $this->lockConfigurableCategory($category);

            $eligible = $this->eligibleCustomFields((int) $category->getKey());
            foreach ($keys as $key) {
                $parsed = AssetTableFieldCatalog::parse($key);
                if ($parsed['kind'] === AssetTableFieldCatalog::KIND_CUSTOM_FIELD && ! $eligible->has($parsed['id'])) {
                    self::reject('admin/categories/table_layout.message.not_eligible');
                }
            }

            AssetCategoryColumn::query()->where('category_id', $category->getKey())->delete();

            $now = now();
            $rows = [];
            foreach ($keys as $index => $key) {
                $rows[] = [
                    'category_id' => (int) $category->getKey(),
                    'field_key' => $key,
                    'position' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            AssetCategoryColumn::query()->insert($rows);

            return $keys;
        });
    }

    /**
     * Restore the default layout by deleting the category's layout rows.
     *
     * @throws ValidationException when the category cannot have a layout
     */
    public function reset(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $this->lockConfigurableCategory($category);
            AssetCategoryColumn::query()->where('category_id', $category->getKey())->delete();
        });
    }

    /**
     * Remove a category's layout rows without any checks (the category was
     * deleted or stopped being a final asset category).
     */
    public function forget(int $categoryId): void
    {
        AssetCategoryColumn::query()->where('category_id', $categoryId)->delete();
    }

    /**
     * Structural validation of submitted keys, without the database:
     * a non-empty list of strings, each a well-formed key of an approved
     * standard field or a custom field, no duplicates, at most
     * MAX_COLUMNS entries.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function normalise(mixed $keys): array
    {
        if (! is_array($keys) || ! array_is_list($keys)) {
            self::reject('admin/categories/table_layout.message.invalid');
        }
        if ($keys === []) {
            self::reject('admin/categories/table_layout.message.empty');
        }
        if (count($keys) > AssetTableFieldCatalog::MAX_COLUMNS) {
            self::reject('admin/categories/table_layout.message.too_many', ['max' => AssetTableFieldCatalog::MAX_COLUMNS]);
        }

        $seen = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                self::reject('admin/categories/table_layout.message.invalid');
            }
            if (AssetTableFieldCatalog::parse($key) === null) {
                self::reject(str_starts_with($key, AssetTableFieldCatalog::KIND_STANDARD.':') && preg_match('/\Astandard:[a-z][a-z0-9_]{0,48}\z/', $key) === 1
                    ? 'admin/categories/table_layout.message.unknown_standard'
                    : 'admin/categories/table_layout.message.invalid');
            }
            if (isset($seen[$key])) {
                self::reject('admin/categories/table_layout.message.duplicate');
            }
            $seen[$key] = true;
        }

        return $keys;
    }

    /**
     * Custom fields at least one live asset model of the category uses
     * through its fieldset, by name.
     *
     * @return Collection<int, CustomField> keyed by custom field id
     */
    public function eligibleCustomFields(int $categoryId): Collection
    {
        $fieldsetIds = DB::table('models')
            ->whereNull('deleted_at')
            ->where('category_id', $categoryId)
            ->whereNotNull('fieldset_id')
            ->distinct()
            ->pluck('fieldset_id');

        if ($fieldsetIds->isEmpty()) {
            return collect();
        }

        return CustomField::query()
            ->whereIn('custom_fields.id', DB::table('custom_field_custom_fieldset')
                ->whereIn('custom_fieldset_id', $fieldsetIds)
                ->select('custom_field_id'))
            ->whereNotNull('custom_fields.db_column')
            ->orderBy('custom_fields.name')
            ->orderBy('custom_fields.id')
            ->get(['custom_fields.id', 'custom_fields.name', 'custom_fields.field_encrypted', 'custom_fields.db_column', 'custom_fields.format'])
            // ERS Phase 7B: only fields with a usable column on assets (a
            // field whose column was never created cannot be shown).
            ->filter(fn (CustomField $field) => preg_match('/\A[A-Za-z0-9_]{1,64}\z/', (string) $field->db_column) === 1)
            ->keyBy(fn (CustomField $field) => (int) $field->id);
    }

    /**
     * Columns for the keys that are valid and eligible now, in order.
     *
     * @param  iterable<string>  $keys
     * @param  Collection<int, CustomField>  $eligible
     * @return list<AssetTableColumn>
     */
    private function resolve(iterable $keys, Collection $eligible): array
    {
        $columns = [];
        $seen = [];
        foreach ($keys as $key) {
            $parsed = AssetTableFieldCatalog::parse($key);
            if ($parsed === null || isset($seen[$key])) {
                continue;
            }
            if ($parsed['kind'] === AssetTableFieldCatalog::KIND_STANDARD) {
                $columns[] = AssetTableFieldCatalog::standardColumn($key);
            } elseif ($eligible->has($parsed['id'])) {
                $columns[] = $this->customColumn($eligible->get($parsed['id']));
            } else {
                continue;
            }
            $seen[$key] = true;
        }

        return $columns;
    }

    private function customColumn(CustomField $field): AssetTableColumn
    {
        return new AssetTableColumn(
            AssetTableFieldCatalog::customFieldKey((int) $field->id),
            AssetTableFieldCatalog::KIND_CUSTOM_FIELD,
            (string) $field->name,
            (int) $field->id,
            (string) $field->field_encrypted === '1',
            $field->db_column !== null && $field->db_column !== '' ? (string) $field->db_column : null,
            $field->format !== null ? (string) $field->format : null,
        );
    }

    /**
     * Lock the category row and check, against the locked row, that it is
     * a live, final asset category. Hierarchy changes lock the same rows,
     * so the category cannot become a navigation group, change type or be
     * deleted while the layout is written.
     *
     * @throws ValidationException
     */
    private function lockConfigurableCategory(Category $category): void
    {
        $row = $category->exists ? Category::query()
            ->whereKey($category->getKey())
            ->lockForUpdate()
            ->toBase()
            ->first(['id', 'category_type', 'is_assignable']) : null;

        if ($row === null || $row->category_type !== 'asset' || ! (bool) (int) $row->is_assignable) {
            self::reject('admin/categories/table_layout.message.not_configurable');
        }
    }

    /** @throws ValidationException */
    private static function reject(string $message, array $replace = []): never
    {
        throw ValidationException::withMessages([self::ERROR_KEY => trans($message, $replace)]);
    }
}
