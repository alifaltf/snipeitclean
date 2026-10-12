<?php

namespace App\Services\AssetImport\Execution;

use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetImport\Validation\AssetImportValidatedRow;
use App\Services\AssetImport\Validation\AssetImportValidationResult;
use App\Services\AssetUniqueness\UniqueConstraintViolation;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 6C: creates one new asset per validated row, the way Snipe-IT's
 * own "create asset" form does (AssetsController::store), inside the
 * caller's transaction:
 *
 *  - only new Asset models are saved; nothing existing is updated, and no
 *    model, status, company, location, supplier, user, custom field or
 *    fieldset is created (the row attributes hold ids resolved during
 *    validation);
 *  - the company goes through Company::getIdForCurrentUser(), the
 *    location fills both rtd_location_id (default location) and
 *    location_id (no checkout), next_audit_date gets the native default
 *    from the audit interval when not imported, created_by is the
 *    importer, and the asset is flagged as imported so its "create" log
 *    entry records the importer as the source;
 *  - custom fields of the row's model are set from the validated plain
 *    values; encrypted ones are encrypted here exactly once with
 *    Crypt::encrypt(), as the native form does;
 *  - Snipe-IT's model validation runs at save (including the fieldset's
 *    rules); a false or failed save stops the import.
 *
 * Unset optional values (blank booleans, Notes, ...) are left to the
 * model/database defaults. The native observer then writes the "create"
 * action log, the order line and the next auto-tag base, all in the same
 * transaction.
 */
final class AssetImportAssetWriter
{
    /** Validated attributes copied to the new asset as they are. */
    private const ATTRIBUTES = [
        'asset_tag', 'name', 'serial', 'order_number', 'notes', 'purchase_date', 'purchase_cost',
        'warranty_months', 'asset_eol_date', 'next_audit_date', 'supplier_id', 'status_id',
        'requestable', 'byod',
    ];

    /** Asset attribute => standard field whose label names it in errors. */
    private const ATTRIBUTE_FIELDS = [
        'asset_tag' => 'asset_tag', 'name' => 'name', 'serial' => 'serial', 'order_number' => 'order_number',
        'notes' => 'notes', 'purchase_date' => 'purchase_date', 'purchase_cost' => 'purchase_cost',
        'warranty_months' => 'warranty_months', 'asset_eol_date' => 'asset_eol_date',
        'next_audit_date' => 'next_audit_date', 'supplier_id' => 'supplier', 'status_id' => 'status',
        'requestable' => 'requestable', 'byod' => 'byod', 'model_id' => 'model', 'company_id' => 'company',
        'location_id' => 'location', 'rtd_location_id' => 'location',
    ];

    /**
     * @return array<int, int> CSV row number => new asset id
     *
     * @throws AssetImportExecutionFailure
     */
    public function create(AssetImportSession $session, User $user, AssetImportValidationResult $result): array
    {
        $modelIds = array_values(array_unique(array_filter(array_map(fn ($row) => $row->attributes['model_id'] ?? null, $result->rows))));
        $models = AssetModel::query()->with('fieldset.fields')->whereIn('id', $modelIds)->get()->keyBy('id');
        $settings = Setting::getSettings();
        $mayEncrypt = Gate::forUser($user)->allows('assets.view.encrypted_custom_fields');

        $created = [];
        foreach ($result->rows as $row) {
            $created[$row->row] = $this->createOne($row, $models, $settings, $user, $mayEncrypt);
        }

        return $created;
    }

    private function createOne(AssetImportValidatedRow $row, Collection $models, ?Setting $settings, User $user, bool $mayEncrypt): int
    {
        $attributes = $row->attributes;
        $model = $models->get($attributes['model_id'] ?? 0);
        if (! $model instanceof AssetModel) {
            throw AssetImportExecutionFailure::forRow($row->row, $row->assetTag, $this->standardLabel('model'), trans('admin/hardware/import.validation.model_not_found'));
        }

        $asset = new Asset;
        $asset->model()->associate($model);
        foreach (self::ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $asset->{$attribute} = $attributes[$attribute];
            }
        }

        try {
            $asset->company_id = Company::getIdForCurrentUser($attributes['company_id'] ?? null);
        } catch (ValidationException $exception) {
            throw $this->failure($row, $exception->validator->errors(), []);
        }

        if (array_key_exists('location_id', $attributes)) {
            $asset->rtd_location_id = $attributes['location_id'];
            $asset->location_id = $attributes['location_id'];
        }
        if (! array_key_exists('next_audit_date', $attributes) && ! empty($settings?->audit_interval)) {
            $asset->next_audit_date = Carbon::now()->addMonths((int) $settings->audit_interval)->toDateString();
        }
        $asset->created_by = $user->id;
        $asset->setImported(true);

        $customLabels = [];
        foreach ($model->fieldset?->fields ?? [] as $field) {
            /** @var CustomField $field */
            $column = $field->db_column_name();
            $customLabels[$column] = (string) $field->name;
            if (! array_key_exists($column, $attributes)) {
                continue;
            }
            if ((string) $field->field_encrypted === '1') {
                if (! $mayEncrypt) {
                    throw AssetImportExecutionFailure::forRow($row->row, $row->assetTag, (string) $field->name, trans('admin/hardware/import.execution.encrypted_not_allowed'));
                }
                $asset->{$column} = Crypt::encrypt((string) $attributes[$column]);
            } else {
                $asset->{$column} = $attributes[$column];
            }
        }

        // isValid() runs the asset rules; save() adds the fieldset's rules
        // and validates again (see Asset::save()), returning false when
        // anything fails.
        try {
            $saved = $asset->isValid() && $asset->save();
        } catch (ValidationException $exception) {
            throw $this->failure($row, $exception->validator->errors(), $customLabels);
        } catch (QueryException $exception) {
            // A database uniqueness key refused the value (another writer
            // took it after validation): this row's error; the caller's
            // transaction rolls back every asset of the import.
            $violation = UniqueConstraintViolation::detect($exception);
            if ($violation === null) {
                throw $exception;
            }
            throw AssetImportExecutionFailure::forRow($row->row, $row->assetTag, $violation->label(), $violation->message());
        }
        if (! $saved || ! $asset->exists) {
            throw $this->failure($row, $asset->getErrors(), $customLabels);
        }

        return (int) $asset->id;
    }

    /** @param  array<string, string>  $customLabels  db column => field name */
    private function failure(AssetImportValidatedRow $row, ?MessageBag $messages, array $customLabels): AssetImportExecutionFailure
    {
        $errors = [];
        foreach ($messages?->messages() ?? [] as $attribute => $list) {
            $label = isset(self::ATTRIBUTE_FIELDS[$attribute])
                ? $this->standardLabel(self::ATTRIBUTE_FIELDS[$attribute])
                : ($customLabels[$attribute] ?? trans('admin/hardware/import.execution.field_other'));
            foreach ($list as $message) {
                $errors[] = ['row' => $row->row, 'asset_tag' => $row->assetTag, 'field' => $label, 'message' => (string) $message];
            }
        }
        if ($errors === []) {
            $errors[] = ['row' => $row->row, 'asset_tag' => $row->assetTag, 'field' => '', 'message' => trans('admin/hardware/import.execution.save_failed')];
        }

        return new AssetImportExecutionFailure($errors);
    }

    private function standardLabel(string $field): string
    {
        return trans(AssetImportFieldCatalog::STANDARD_FIELDS[$field]);
    }
}
