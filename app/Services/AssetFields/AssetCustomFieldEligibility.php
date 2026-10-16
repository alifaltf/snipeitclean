<?php

namespace App\Services\AssetFields;

use App\Models\AssetModel;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * ERS Phase 7C: the one rule for which custom fields an asset may store.
 *
 * A custom field is eligible for an asset exactly when it belongs to the
 * CURRENT fieldset of the asset's (destination) model, the model is live
 * (not soft-deleted) and the field's database column is structurally
 * valid (a native "_snipeit_..." column name). Category table layouts are
 * presentation only and play no part in this decision; nor do grants or
 * Super Admin status (a Super Admin cannot write a field that is not in
 * the model's fieldset either).
 *
 * Used by the web and API create/edit paths, bulk edit, the CSV mapping
 * catalog, CSV validation and CSV execution, and by the category table
 * layout (which additionally requires a live model in the category), so
 * none of them can disagree about eligibility.
 *
 * The rule for a submitted custom-field key that is NOT eligible: it is
 * rejected with a validation error and nothing is written (single-asset
 * web and API create/update, and bulk edit when the bulk request changes
 * the model). A plain bulk edit spanning several models rejects a key no
 * selected asset can store, and writes an accepted key only to the assets
 * whose model stores it (see BulkAssetsController::update()).
 */
final class AssetCustomFieldEligibility
{
    /** Native custom-field column names (CustomField::convertUnicodeDbSlug()). */
    public const COLUMN_PATTERN = '/\A_snipeit_[A-Za-z0-9_]{1,55}\z/';

    /** Request keys that address a custom-field column. */
    public const KEY_PREFIX = '_snipeit_';

    /** Is $column a structurally valid custom-field column name? */
    public static function isStorableColumn(mixed $column): bool
    {
        return is_string($column) && preg_match(self::COLUMN_PATTERN, $column) === 1;
    }

    /**
     * The custom fields $model's current fieldset gives an asset, in
     * fieldset order. Empty for no model, a soft-deleted model, a model
     * without (or with a missing) fieldset. Uses the model's loaded
     * fieldset relation when present, so callers can eager-load it.
     *
     * @return Collection<int, CustomField>
     */
    public function fieldsFor(?AssetModel $model): Collection
    {
        if ($model === null || $model->trashed()) {
            return collect();
        }

        $fieldset = $model->fieldset;
        if ($fieldset === null) {
            return collect();
        }

        return $fieldset->fields
            ->filter(fn (CustomField $field) => self::isStorableColumn($field->db_column))
            ->values();
    }

    /**
     * @return list<string> eligible column names for $model
     */
    public function columnsFor(?AssetModel $model): array
    {
        return $this->fieldsFor($model)->map(fn (CustomField $field) => (string) $field->db_column)->values()->all();
    }

    /**
     * Eligible columns of several models at once (their fieldsets and
     * fields load in two queries), as a set: column => true.
     *
     * @param  iterable<AssetModel|null>  $models
     * @return array<string, true>
     */
    public function columnsForAny(iterable $models): array
    {
        $unique = [];
        foreach ($models as $model) {
            if ($model instanceof AssetModel) {
                $unique[$model->getKey()] = $model;
            }
        }
        (new EloquentCollection(array_values($unique)))->loadMissing('fieldset.fields');

        $columns = [];
        foreach ($unique as $model) {
            foreach ($this->columnsFor($model) as $column) {
                $columns[$column] = true;
            }
        }

        return $columns;
    }

    /**
     * Custom-field keys present in $input (any key starting "_snipeit_").
     *
     * @return list<string>
     */
    public static function submittedKeys(array $input): array
    {
        return array_values(array_filter(array_map('strval', array_keys($input)), fn (string $key) => str_starts_with($key, self::KEY_PREFIX)));
    }

    /**
     * Submitted custom-field keys that $model's fieldset does not give an
     * asset (unknown columns, fields of other fieldsets, fields with a
     * broken column name).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function ineligibleKeys(?AssetModel $model, array $keys): array
    {
        $eligible = array_flip($this->columnsFor($model));

        return array_values(array_filter($keys, fn (string $key) => ! isset($eligible[$key])));
    }

    /**
     * Validation errors for submitted custom-field keys $model does not
     * give an asset, keyed by the submitted key, with Snipe-IT's native
     * messages ("not found" for an unknown column, "not on this model" for
     * a real field of another fieldset). One query, only when needed.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function ineligibleKeyErrors(?AssetModel $model, array $keys): array
    {
        $ineligible = $this->ineligibleKeys($model, $keys);
        if ($ineligible === []) {
            return [];
        }

        $known = array_flip(CustomField::query()->whereIn('db_column', $ineligible)->pluck('db_column')->all());
        $errors = [];
        foreach ($ineligible as $key) {
            $errors[$key] = isset($known[$key])
                ? (string) trans('validation.custom.custom_field_not_found_on_model')
                : (string) trans('validation.custom.custom_field_not_found');
        }

        return $errors;
    }

    /** The text forms show instead of an encrypted value the user may not see. */
    public static function maskedPlaceholder(): string
    {
        return strtoupper((string) trans('admin/custom_fields/general.encrypted'));
    }

    /**
     * Is $value the masked placeholder (or the "Error Decrypting" text the
     * form shows for an unreadable stored value)? Submitting either back
     * for an encrypted field means "keep the stored value".
     */
    public static function isMaskedPlaceholder(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $value = trim($value);

        return $value === self::maskedPlaceholder() || str_starts_with($value, 'Error Decrypting:');
    }
}
