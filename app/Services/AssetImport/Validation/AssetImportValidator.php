<?php

namespace App\Services\AssetImport\Validation;

use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssetImport\AssetImportCsvInspector;
use App\Services\AssetImport\AssetImportDestination;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetImport\AssetImportSessions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use League\Csv\Reader;
use RuntimeException;

/**
 * ERS Phase 6B: validates every mapped row of an import session without
 * writing anything. Phase 6C runs it again inside its own transaction.
 *
 * Callers must first load the session through AssetImportSessions::findOpen()
 * (owner, expiry, file integrity) and check AssetImportReadiness (target and
 * mapping still valid for this user). This class then:
 *
 *  - streams the stored CSV with the Phase 6A dialect (header = row 1, the
 *    first data row is row 2, empty lines skipped as at upload);
 *  - loads every reference it may need once (live models of the selected
 *    category, status labels, the user's in-scope companies and locations,
 *    suppliers, the fieldsets of those models) and checks existing asset
 *    tags, serials and unique custom fields in bounded bulk queries, so the
 *    number of queries does not grow with the number of rows;
 *  - checks each row strictly: required asset tag, no duplicates in the file
 *    or among live or deleted assets (compared like the database column
 *    compares them, see AssetImportUniqueKeys), ISO dates, plain
 *    decimal and integer numbers, a fixed boolean vocabulary (a blank
 *    optional boolean is left out so the asset's default applies), exact
 *    name matches that are neither missing nor ambiguous, Full Multiple
 *    Company Support, and the custom fields of the model each row resolves
 *    to (non-empty values for encrypted unique fields are refused, see
 *    validateCustomFields());
 *  - finally applies Snipe-IT's own Asset rules (lengths, formats) to the
 *    parsed values, minus the database-backed rules it already did in bulk.
 *
 * Nothing is created: unknown models, statuses, companies, locations and
 * suppliers are row errors. No raw values are logged; error messages never
 * contain cell values; encrypted custom fields are masked for display.
 */
final class AssetImportValidator
{
    /** Booleans accepted in the CSV (compared after trimming, ignoring case). */
    public const TRUE_VALUES = ['1', 'true', 'yes'];

    public const FALSE_VALUES = ['0', 'false', 'no'];

    /** Largest stored length of the asset string columns (Schema::defaultStringLength). */
    private const STRING_COLUMN_LENGTH = 191;

    /** Largest value a TEXT column (assets.notes) stores, in bytes. */
    private const TEXT_COLUMN_BYTES = 65535;

    /** Longest Notes text shown in one preview cell; the full value is still validated and kept. */
    private const NOTES_PREVIEW_LENGTH = 500;

    /** Standard destination => Asset attribute it fills (simple values only). */
    private const ASSET_ATTRIBUTES = [
        'asset_tag' => 'asset_tag',
        'name' => 'name',
        'serial' => 'serial',
        'purchase_date' => 'purchase_date',
        'purchase_cost' => 'purchase_cost',
        'order_number' => 'order_number',
        'warranty_months' => 'warranty_months',
        'requestable' => 'requestable',
        'byod' => 'byod',
        'asset_eol_date' => 'asset_eol_date',
        'next_audit_date' => 'next_audit_date',
        'notes' => 'notes',
    ];

    private const DATE_FIELDS = ['purchase_date', 'asset_eol_date', 'next_audit_date'];

    private const BOOLEAN_FIELDS = ['requestable', 'byod'];

    public function __construct(
        private readonly AssetImportFieldCatalog $catalog,
        private readonly AssetImportUniqueKeys $uniqueKeys,
    ) {}

    public function validate(AssetImportSession $session, User $user): AssetImportValidationResult
    {
        $context = $this->context($session, $user);
        $records = $this->readRows($session, $context['columns']);

        $tags = $this->uniqueState($records, 'standard:asset_tag', 'asset_tag', true);
        $serials = $this->uniqueState($records, 'standard:serial', 'serial', true);
        $uniqueFields = $this->uniqueCustomFieldState($context, $records);

        $rows = [];
        foreach ($records as [$rowNumber, $values]) {
            $rows[] = $this->validateRow($context, $rowNumber, $values, $tags, $serials, $uniqueFields);
        }

        return new AssetImportValidationResult($rows, CarbonImmutable::now());
    }

    // ------------------------------------------------------------------
    // Preparation (fixed number of queries)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function context(AssetImportSession $session, User $user): array
    {
        $destinations = $this->catalog->destinations($session, $user);

        $columns = [];
        foreach ($session->mappedDestinations() as $column => $key) {
            if ($key !== null && isset($destinations[$key])) {
                $columns[$key] = $column;
            }
        }

        $categoryId = (int) $session->category_id;
        $models = $session->model_source === AssetImportSession::SOURCE_FIXED
            ? AssetModel::query()->whereKey($session->model_id)->where('category_id', $categoryId)->get(['id', 'name', 'model_number', 'fieldset_id'])
            : AssetModel::query()->where('category_id', $categoryId)->get(['id', 'name', 'model_number', 'fieldset_id']);

        $fieldsets = [];
        $fieldsetIds = $models->pluck('fieldset_id')->filter()->unique()->values();
        if ($fieldsetIds->isNotEmpty()) {
            foreach (CustomFieldset::query()->with('fields')->whereIn('id', $fieldsetIds)->get() as $fieldset) {
                foreach ($fieldset->fields as $field) {
                    $fieldsets[$fieldset->id][$field->id] = ['field' => $field, 'required' => (string) $field->pivot->required === '1'];
                }
            }
        }

        $mappedFields = [];
        $mappedFieldIds = [];
        foreach ($columns as $key => $column) {
            if ($destinations[$key]->kind === AssetImportDestination::KIND_CUSTOM_FIELD) {
                $mappedFieldIds[$destinations[$key]->customFieldId] = $key;
            }
        }
        if ($mappedFieldIds !== []) {
            foreach (CustomField::query()->whereIn('id', array_keys($mappedFieldIds))->get() as $field) {
                $mappedFields[$field->id] = ['key' => $mappedFieldIds[$field->id], 'field' => $field];
            }
        }

        $settings = Setting::getSettings();

        return [
            'session' => $session,
            'destinations' => $destinations,
            'columns' => $columns,
            'models' => $models,
            'modelIndex' => new AssetImportReferenceIndex($models, fn ($m) => AssetImportReferenceIndex::normalise($m->name)),
            'modelNumberIndex' => new AssetImportReferenceIndex($models, fn ($m) => AssetImportReferenceIndex::normalise($m->name).'|'.AssetImportReferenceIndex::normalise($m->model_number)),
            'fieldsets' => $fieldsets,
            'mappedFields' => $mappedFields,
            'status' => $session->status_source === AssetImportSession::SOURCE_FIXED ? Statuslabel::query()->find($session->status_id, ['id', 'name']) : null,
            'statusIndex' => isset($columns['standard:status']) ? $this->index(Statuslabel::query()->get(['id', 'name'])) : null,
            // Companies and locations come from the normal company-scoped
            // queries, so records outside the user's scope never match.
            'company' => $session->company_source === AssetImportSession::SOURCE_FIXED ? Company::query()->find($session->company_id, ['id', 'name']) : null,
            'companyIndex' => isset($columns['standard:company']) ? $this->index(Company::query()->get(['id', 'name'])) : null,
            'location' => $session->location_source === AssetImportSession::SOURCE_FIXED ? Location::query()->find($session->location_id, ['id', 'name', 'company_id', 'parent_id']) : null,
            'locationIndex' => isset($columns['standard:location']) ? $this->index(Location::query()->get(['id', 'name', 'company_id', 'parent_id'])) : null,
            'supplierIndex' => isset($columns['standard:supplier']) ? $this->index(Supplier::query()->get(['id', 'name'])) : null,
            // Snipe-IT's own fmcs_company rule decides, once, whether this
            // user may leave the company empty.
            'nullCompanyAllowed' => Validator::make(['company_id' => null], ['company_id' => 'fmcs_company'])->passes(),
            'locationCompanies' => ($settings->full_multiple_companies_support == '1' && $settings->scope_locations_fmcs == '1') ? $this->locationCompanies() : null,
            'floater' => (bool) $settings->null_company_is_floater,
            'assetRules' => $this->assetRules(),
        ];
    }

    private function index(iterable $records): AssetImportReferenceIndex
    {
        return new AssetImportReferenceIndex($records, fn ($record) => AssetImportReferenceIndex::normalise($record->name));
    }

    /**
     * Effective company of every location (its own, else the nearest
     * ancestor's), computed in memory from one query. Same answer as
     * Location::effectiveFmcsCompanyId(), which the fmcs_location rule uses,
     * without one query per row.
     *
     * @return array<int, int|null>
     */
    private function locationCompanies(): array
    {
        $rows = DB::table('locations')->get(['id', 'parent_id', 'company_id'])->keyBy('id');
        $effective = [];
        foreach ($rows as $id => $row) {
            $seen = [];
            $current = $row;
            $company = null;
            while ($current !== null && ! isset($seen[$current->id])) {
                $seen[$current->id] = true;
                if ($current->company_id) {
                    $company = (int) $current->company_id;
                    break;
                }
                $current = $current->parent_id ? $rows->get($current->parent_id) : null;
            }
            $effective[(int) $id] = $company;
        }

        return $effective;
    }

    /**
     * Snipe-IT's Asset rules for the simple fields, without the rules that
     * query the database per value (uniqueness, exists, company scope),
     * which this validator checks in bulk instead, plus the stored column
     * length of the string columns.
     *
     * @return array<string, list<mixed>>
     */
    private function assetRules(): array
    {
        $native = (new Asset)->getRules();
        $rules = [];
        foreach (self::ASSET_ATTRIBUTES as $attribute) {
            $rules[$attribute] = array_values(array_filter(
                (array) ($native[$attribute] ?? ['nullable']),
                fn ($rule) => ! is_string($rule) || ! preg_match('/^(unique_undeleted|exists:|fmcs_|required)/', $rule)
            ));
        }
        foreach (['asset_tag', 'name', 'serial', 'order_number'] as $attribute) {
            $rules[$attribute][] = 'max:'.self::STRING_COLUMN_LENGTH;
        }

        return $rules;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * @param  array<string, int>  $columns  destination key => column position
     * @return list<array{0: int, 1: array<string, string>}> [row number, destination => trimmed value]
     */
    private function readRows(AssetImportSession $session, array $columns): array
    {
        $stream = AssetImportSessions::disk()->readStream($session->storage_path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The import file could not be read.');
        }

        $max = (int) config('asset_import.max_data_rows');
        $rows = [];
        try {
            $header = true;
            foreach (AssetImportCsvInspector::configure(Reader::createFromStream($stream))->getRecords() as $record) {
                if ($header) {
                    $header = false;

                    continue;
                }
                if (count($rows) >= $max) {
                    throw new RuntimeException('The import file has more rows than allowed.');
                }

                $cells = array_values($record);
                $values = [];
                foreach ($columns as $key => $column) {
                    $values[$key] = trim((string) ($cells[$column] ?? ''));
                }
                $rows[] = [count($rows) + 2, $values];
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    /**
     * How unique values (asset tags, serials, unique custom fields) of one
     * destination compare, in bulk: the comparison key of every non-empty
     * value (the column's own collation on MySQL/MariaDB, see
     * AssetImportUniqueKeys), the rows per key in the file, and the keys
     * already used in assets.$column (deleted assets too when
     * $includeDeleted). Callers report only "already used", never which
     * asset.
     *
     * @return array{keys: array<string, string>, rows: array<string, list<int>>, existing: array<string, true>}
     */
    private function uniqueState(array $records, string $destination, string $column, bool $includeDeleted): array
    {
        $values = [];
        foreach ($records as [, $cells]) {
            if (($cells[$destination] ?? '') !== '') {
                $values[] = $cells[$destination];
            }
        }

        $keys = $this->uniqueKeys->keys($column, $values);
        $rows = [];
        foreach ($records as [$row, $cells]) {
            $value = $cells[$destination] ?? '';
            if ($value !== '') {
                $rows[$keys[$value]][] = $row;
            }
        }

        return [
            'keys' => $keys,
            'rows' => $rows,
            'existing' => $this->uniqueKeys->existing($column, array_keys($keys), $includeDeleted),
        ];
    }

    /**
     * For mapped unique, non-encrypted custom fields: rows per value in the
     * file and values already used by live assets (Snipe-IT's own
     * unique_undeleted semantics), in bulk. Encrypted unique fields are left
     * out on purpose: their non-empty values are refused per row (see
     * validateCustomFields()), so no stored secret is ever decrypted here.
     *
     * @return array<int, array{keys: array<string, string>, rows: array<string, list<int>>, existing: array<string, true>}>
     */
    private function uniqueCustomFieldState(array $context, array $records): array
    {
        $state = [];
        foreach ($context['mappedFields'] as $id => ['key' => $key, 'field' => $field]) {
            if ((string) $field->is_unique !== '1' || (string) $field->field_encrypted === '1') {
                continue;
            }
            $state[$id] = $this->uniqueState($records, $key, $field->db_column_name(), false);
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // One row
    // ------------------------------------------------------------------

    private function validateRow(array $c, int $row, array $values, array $tags, array $serials, array $uniqueFields): AssetImportValidatedRow
    {
        $fullTag = $values['standard:asset_tag'] ?? '';
        // The tag shown next to errors is cut to a safe display length.
        $tag = $fullTag !== '' ? mb_substr($fullTag, 0, 255) : null;
        $errors = [];
        $attributes = [];
        $display = [];
        $fail = function (string $key, string $message, array $replace = [], ?string $label = null) use (&$errors, $row, $c, $tag) {
            $errors[] = new AssetImportRowError($row, $key, $label ?? $this->label($c, $key), trans('admin/hardware/import.validation.'.$message, $replace), $tag);
        };
        $value = fn (string $field) => $values['standard:'.$field] ?? null;

        // Asset tag: required, unique in the file and among all assets.
        if ($tag === null) {
            $fail('standard:asset_tag', 'required');
        } else {
            $normalised = $tags['keys'][$fullTag];
            if (count($tags['rows'][$normalised]) > 1) {
                $fail('standard:asset_tag', 'duplicate_in_file', ['rows' => implode(', ', $tags['rows'][$normalised])]);
            }
            if (isset($tags['existing'][$normalised])) {
                $fail('standard:asset_tag', 'asset_tag_exists');
            }
            $attributes['asset_tag'] = $fullTag;
        }

        // Serial: optional; when present unique in the file and among all assets.
        $serial = $value('serial');
        if ($serial !== null && $serial !== '') {
            $normalised = $serials['keys'][$serial];
            if (count($serials['rows'][$normalised]) > 1) {
                $fail('standard:serial', 'duplicate_in_file', ['rows' => implode(', ', $serials['rows'][$normalised])]);
            }
            if (isset($serials['existing'][$normalised])) {
                $fail('standard:serial', 'serial_exists');
            }
            $attributes['serial'] = $serial;
        }

        foreach (['name', 'order_number'] as $field) {
            if (($value($field) ?? '') !== '') {
                $attributes[$field] = $value($field);
            }
        }

        // Notes: free text, line breaks kept (only the outer whitespace is
        // trimmed, like every cell). Snipe-IT's own rule (max:65535
        // characters) applies below; the TEXT column also holds at most
        // 65,535 bytes, which multi-byte text can reach first.
        $notes = $value('notes') ?? '';
        if ($notes !== '') {
            $attributes['notes'] = $notes;
            // Longer than 65,535 characters is reported by the native rule.
            if (strlen($notes) > self::TEXT_COLUMN_BYTES && mb_strlen($notes) <= self::TEXT_COLUMN_BYTES) {
                $fail('standard:notes', 'too_long_bytes', ['max' => number_format(self::TEXT_COLUMN_BYTES)]);
            }
            $display['standard:notes'] = mb_strlen($notes) > self::NOTES_PREVIEW_LENGTH
                ? mb_substr($notes, 0, self::NOTES_PREVIEW_LENGTH).'…'
                : $notes;
        }

        $model = $this->resolveModel($c, $values, $fail);
        if ($model !== null) {
            $attributes['model_id'] = (int) $model->id;
            $display['standard:model'] = $model->model_number ? $model->name.' ('.$model->model_number.')' : $model->name;
        }

        $status = $this->resolveFixedOrColumn($c, 'status', $c['status'], $c['statusIndex'], $values, true, $fail);
        if ($status !== null) {
            $attributes['status_id'] = (int) $status->id;
            $display['standard:status'] = $status->name;
        }

        $company = $this->resolveFixedOrColumn($c, 'company', $c['company'], $c['companyIndex'], $values, false, $fail);
        if ($company !== null) {
            $attributes['company_id'] = (int) $company->id;
            $display['standard:company'] = $company->name;
        } elseif (! $c['nullCompanyAllowed'] && ! $this->hasError($errors, 'standard:company')) {
            $fail('standard:company', 'company_required');
        }

        $location = $this->resolveFixedOrColumn($c, 'location', $c['location'], $c['locationIndex'], $values, false, $fail);
        if ($location !== null) {
            $attributes['location_id'] = (int) $location->id;
            $display['standard:location'] = $location->name;
            if ($company !== null && ! $this->locationFitsCompany($c, (int) $location->id, (int) $company->id)) {
                $fail('standard:location', 'location_company_mismatch');
            }
        }

        if ($c['supplierIndex'] !== null && ($value('supplier') ?? '') !== '') {
            $supplier = $c['supplierIndex']->find(AssetImportReferenceIndex::normalise($value('supplier')));
            if (is_string($supplier)) {
                $fail('standard:supplier', $supplier === AssetImportReferenceIndex::AMBIGUOUS ? 'ambiguous' : 'not_found');
            } else {
                $attributes['supplier_id'] = (int) $supplier->id;
                $display['standard:supplier'] = $supplier->name;
            }
        }

        foreach (self::DATE_FIELDS as $field) {
            $raw = $value($field) ?? '';
            if ($raw === '') {
                continue;
            }
            if (self::isIsoDate($raw)) {
                $attributes[$field] = $raw;
            } else {
                $fail('standard:'.$field, 'date');
            }
        }

        // Plain decimals only: no signs, thousands separators, currency
        // symbols or exponents, at most two decimals (the column's scale).
        $cost = $value('purchase_cost') ?? '';
        if ($cost !== '') {
            if (preg_match('/\A[0-9]{1,15}(\.[0-9]{1,2})?\z/', $cost) === 1) {
                $attributes['purchase_cost'] = $cost;
            } else {
                $fail('standard:purchase_cost', 'decimal');
            }
        }

        $warranty = $value('warranty_months') ?? '';
        if ($warranty !== '') {
            if (preg_match('/\A[0-9]{1,3}\z/', $warranty) === 1 && (int) $warranty <= 240) {
                $attributes['warranty_months'] = (int) $warranty;
            } else {
                $fail('standard:warranty_months', 'warranty');
            }
        }

        // Optional booleans: an unmapped or blank cell is left out of the
        // attributes so the asset keeps its default; anything else must be
        // one of the accepted words.
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (($value($field) ?? '') === '') {
                continue;
            }
            $parsed = self::parseBoolean($value($field));
            if ($parsed === null) {
                $fail('standard:'.$field, 'boolean');
            } else {
                $attributes[$field] = $parsed;
            }
        }

        $this->applyAssetRules($c, $attributes, $fail);

        foreach ($attributes as $attribute => $parsed) {
            $key = 'standard:'.$attribute;
            if (isset($c['destinations'][$key]) && ! isset($display[$key])) {
                $display[$key] = in_array($attribute, self::BOOLEAN_FIELDS, true)
                    ? trans($parsed ? 'general.yes' : 'general.no')
                    : (string) $parsed;
            }
        }

        $this->validateCustomFields($c, $model, $values, $uniqueFields, $attributes, $display, $fail);

        return new AssetImportValidatedRow($row, $tag, $attributes, $display, $errors);
    }

    /** Fixed model, or the row's Model (and Model Number) among the category's live models. */
    private function resolveModel(array $c, array $values, callable $fail): ?AssetModel
    {
        $session = $c['session'];
        if ($session->model_source === AssetImportSession::SOURCE_FIXED) {
            $model = $c['models']->first();
            if ($model === null) {
                $fail('standard:model', 'not_found');
            }

            return $model;
        }

        $name = $values['standard:model'] ?? '';
        if ($name === '') {
            $fail('standard:model', 'required');

            return null;
        }

        $number = $values['standard:model_number'] ?? '';
        $found = $number !== ''
            ? $c['modelNumberIndex']->find(AssetImportReferenceIndex::normalise($name).'|'.AssetImportReferenceIndex::normalise($number))
            : $c['modelIndex']->find(AssetImportReferenceIndex::normalise($name));

        if (is_string($found)) {
            $fail('standard:model', $found === AssetImportReferenceIndex::AMBIGUOUS ? 'model_ambiguous' : 'model_not_found');

            return null;
        }

        return $found;
    }

    /**
     * A fixed record (already checked on the target step), or the row's
     * value looked up in the in-scope index. Blank optional values are null.
     */
    private function resolveFixedOrColumn(array $c, string $field, ?object $fixed, ?AssetImportReferenceIndex $index, array $values, bool $required, callable $fail): ?object
    {
        $source = $c['session']->{$field.'_source'};
        if ($source === AssetImportSession::SOURCE_FIXED) {
            if ($fixed === null) {
                $fail('standard:'.$field, 'not_found');
            }

            return $fixed;
        }

        if ($source !== AssetImportSession::SOURCE_COLUMN || $index === null) {
            return null;
        }

        $raw = $values['standard:'.$field] ?? '';
        if ($raw === '') {
            if ($required) {
                $fail('standard:'.$field, 'required');
            }

            return null;
        }

        $found = $index->find(AssetImportReferenceIndex::normalise($raw));
        if (is_string($found)) {
            $fail('standard:'.$field, $found === AssetImportReferenceIndex::AMBIGUOUS ? 'ambiguous' : 'not_found');

            return null;
        }

        return $found;
    }

    /** Snipe-IT's fmcs_location rule, using the preloaded location companies. */
    private function locationFitsCompany(array $c, int $locationId, int $companyId): bool
    {
        if ($c['locationCompanies'] === null) {
            return true;
        }

        $effective = $c['locationCompanies'][$locationId] ?? null;

        return $effective === null ? $c['floater'] : $effective === $companyId;
    }

    /** Snipe-IT's Asset rules on the values parsed so far (one Validator per row). */
    private function applyAssetRules(array $c, array $attributes, callable $fail): void
    {
        $present = array_intersect_key($attributes, array_flip(self::ASSET_ATTRIBUTES));
        if ($present === []) {
            return;
        }

        $labels = [];
        foreach (array_keys($present) as $attribute) {
            $labels[$attribute] = $this->label($c, 'standard:'.$attribute);
        }

        $validator = Validator::make($present, array_intersect_key($c['assetRules'], $present), [], $labels);
        foreach ($validator->errors()->messages() as $attribute => $messages) {
            foreach ($messages as $message) {
                $fail('standard:'.$attribute, 'native', ['message' => $message]);
            }
        }
    }

    /**
     * Custom fields of the model this row resolved to. A non-empty value
     * mapped to a field that model does not use is an error; required
     * fields need a value; values must match the field's format and, for
     * list fields, its options. Encrypted values are masked for display.
     */
    private function validateCustomFields(array $c, ?AssetModel $model, array $values, array $uniqueFields, array &$attributes, array &$display, callable $fail): void
    {
        $applicable = $model !== null && $model->fieldset_id ? ($c['fieldsets'][$model->fieldset_id] ?? []) : [];

        foreach ($c['mappedFields'] as $id => ['key' => $key, 'field' => $field]) {
            $raw = $values[$key] ?? '';
            if ($raw !== '' && $model !== null && ! isset($applicable[$id])) {
                $fail($key, 'field_not_applicable', ['model' => $model->name]);
            }
        }

        if ($model === null) {
            return;
        }

        foreach ($applicable as $id => ['field' => $field, 'required' => $required]) {
            $key = AssetImportDestination::customFieldKey((int) $id);
            $raw = isset($c['mappedFields'][$id]) ? ($values[$key] ?? '') : '';
            $encrypted = (string) $field->field_encrypted === '1';

            if ($raw === '') {
                if ($required) {
                    $fail($key, isset($c['mappedFields'][$id]) ? 'required' : 'required_unmapped', [], (string) $field->name);
                }

                continue;
            }

            // Snipe-IT encrypts the value (random IV) before its own
            // unique_undeleted rule runs, so it never finds a clash, and the
            // plain value cannot be compared with stored ciphertext. Rather
            // than accept a value whose uniqueness cannot be checked, it is
            // refused; the value itself never reaches attributes or display.
            if ($encrypted && (string) $field->is_unique === '1') {
                $fail($key, 'encrypted_unique_unsupported', [], (string) $field->name);

                continue;
            }

            $error = $this->customFieldError($field, $raw);
            if ($error !== null) {
                $fail($key, $error, [], (string) $field->name);

                continue;
            }

            if (isset($uniqueFields[$id])) {
                $normalised = $uniqueFields[$id]['keys'][$raw];
                if (count($uniqueFields[$id]['rows'][$normalised] ?? []) > 1) {
                    $fail($key, 'duplicate_in_file', ['rows' => implode(', ', $uniqueFields[$id]['rows'][$normalised])]);
                }
                if (isset($uniqueFields[$id]['existing'][$normalised])) {
                    $fail($key, 'custom_field_exists');
                }
            }

            $attributes[$field->db_column_name()] = self::isBooleanFormat($field) ? (string) self::parseBoolean($raw) : $raw;
            $display[$key] = $encrypted ? trans('admin/hardware/import.validation.masked') : $raw;
        }
    }

    /** The translation key of the first problem with a custom-field value, or null. */
    private function customFieldError(CustomField $field, string $value): ?string
    {
        $element = (string) $field->element;
        if (in_array($element, ['listbox', 'radio', 'checkbox'], true)) {
            $options = array_map('strval', array_keys(array_filter($field->formatFieldValuesAsArray(), fn ($label, $option) => $option !== '', ARRAY_FILTER_USE_BOTH)));
            $chosen = $element === 'checkbox' ? array_map('trim', explode(',', $value)) : [$value];
            foreach ($chosen as $option) {
                if (! in_array($option, $options, true)) {
                    return 'option';
                }
            }

            return null;
        }

        $format = (string) ($field->getRawOriginal('format') ?? '');
        if ($format === 'date') {
            return self::isIsoDate($value) ? null : 'date';
        }
        if ($format === 'numeric') {
            return preg_match('/\A-?[0-9]{1,15}(\.[0-9]{1,10})?\z/', $value) === 1 ? null : 'numeric';
        }
        if ($format === 'boolean') {
            return self::parseBoolean($value) === null ? 'boolean' : null;
        }

        $rules = ['string', 'max:65535'];
        if ($format !== '' && $format !== 'present') {
            $rules[] = $format;
        }

        return Validator::make(['value' => $value], ['value' => $rules])->fails() ? 'format' : null;
    }

    private static function isBooleanFormat(CustomField $field): bool
    {
        return (string) ($field->getRawOriginal('format') ?? '') === 'boolean';
    }

    // ------------------------------------------------------------------
    // Strict parsing helpers
    // ------------------------------------------------------------------

    /** A real calendar date written exactly as YYYY-MM-DD. */
    public static function isIsoDate(string $value): bool
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * 1 or 0 for the accepted boolean words (1/0, true/false, yes/no,
     * ignoring case and outer spaces); null for anything else, blank
     * included. Callers decide what a blank cell means.
     */
    public static function parseBoolean(string $value): ?int
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($value, self::FALSE_VALUES, true)) {
            return 0;
        }

        return in_array($value, self::TRUE_VALUES, true) ? 1 : null;
    }

    /** The field's label: its destination label, or the standard field's name. */
    private function label(array $c, string $key): string
    {
        if (isset($c['destinations'][$key])) {
            return $c['destinations'][$key]->label;
        }

        $field = substr($key, strlen(AssetImportDestination::KIND_STANDARD) + 1);

        return isset(AssetImportFieldCatalog::STANDARD_FIELDS[$field]) ? trans(AssetImportFieldCatalog::STANDARD_FIELDS[$field]) : $key;
    }

    private function hasError(array $errors, string $key): bool
    {
        foreach ($errors as $error) {
            if ($error->field === $key) {
                return true;
            }
        }

        return false;
    }
}
