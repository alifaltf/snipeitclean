<?php

namespace App\Services\AssetTableLayout;

use App\Helpers\Helper;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 7B: the plain-text value of one layout column for one asset,
 * for the category CSV export. Values follow the asset list's own
 * formatting (dates, currency, "N months", names of related records).
 *
 * Encrypted custom fields are decrypted only for users with the existing
 * "View Encrypted Custom Fields" permission; everyone else gets the same
 * "ENCRYPTED" marker the asset list shows. Missing values are empty
 * strings, and so is a custom field that is not in the fieldset of the
 * asset's current, live model. Nothing here writes to the asset.
 */
final class AssetTableValueFormatter
{
    private readonly bool $mayDecrypt;

    /** @var array<int, CustomField> */
    private array $fields = [];

    public function __construct(?bool $mayDecrypt = null)
    {
        $this->mayDecrypt = $mayDecrypt ?? Gate::allows('assets.view.encrypted_custom_fields');
    }

    public function value(Asset $asset, AssetTableColumn $column): string
    {
        $value = $column->isCustomField() ? $this->customValue($asset, $column) : $this->standardValue($asset, $column->field());

        return $value === null ? '' : (string) $value;
    }

    private function standardValue(Asset $asset, string $field): mixed
    {
        return match ($field) {
            'asset_tag', 'name', 'serial', 'order_number', 'notes' => $asset->{$field},
            'model' => $asset->model?->name,
            'model_number' => $asset->model?->model_number,
            'manufacturer' => $asset->model?->manufacturer?->name,
            'status' => $asset->status?->name,
            'company' => $asset->company?->name,
            'location' => $asset->location?->name,
            'rtd_location' => $asset->defaultLoc?->name,
            'supplier' => $asset->supplier?->name,
            'assigned_to' => $this->assignee($asset),
            'image' => $asset->getImageUrl() ?: null,
            'purchase_cost' => Helper::formatCurrencyOutput($asset->purchase_cost),
            'warranty_months' => $asset->warranty_months > 0 ? $asset->warranty_months.' '.trans('admin/hardware/form.months') : null,
            'warranty_expires' => $asset->warranty_months > 0 ? Helper::getFormattedDateObject($asset->warranty_expires, 'date', false) : null,
            'requestable', 'byod' => $asset->{$field} ? trans('general.yes') : trans('general.no'),
            'purchase_date', 'asset_eol_date', 'next_audit_date' => Helper::getFormattedDateObject($asset->{$field}, 'date', false),
            'last_checkout', 'expected_checkin', 'last_audit_date', 'created_at', 'updated_at' => Helper::getFormattedDateObject($asset->{$field}, 'datetime', false),
            default => null,
        };
    }

    private function assignee(Asset $asset): ?string
    {
        // assignedTo() is a morphTo named "assigned": eager loading
        // 'assignedTo' stores the record under "assigned" (the asset list
        // reads it the same way).
        $target = $asset->relationLoaded('assigned') ? $asset->getRelation('assigned') : $asset->assignedTo()->first();
        if ($target === null) {
            return null;
        }
        if ($target instanceof User) {
            return $target->display_name;
        }
        if ($target instanceof Asset) {
            return $target->name ?: $target->asset_tag;
        }

        return $target->name ?? null;
    }

    private function customValue(Asset $asset, AssetTableColumn $column): mixed
    {
        // Only a field of the asset's current, live model's fieldset has a
        // value for that asset (the same rule as search, filters and
        // sorting); stale data left in the column by an earlier fieldset is
        // not exported.
        $model = $asset->model;
        $fields = ($model === null || $model->trashed()) ? null : $model->fieldset?->fields;
        if ($fields === null || ! $fields->contains(fn (CustomField $field) => (int) $field->id === (int) $column->customFieldId)) {
            return null;
        }
        $value = $asset->getAttribute((string) $column->dbColumn);
        if ($value === null || $value === '') {
            return null;
        }
        if ($column->encrypted) {
            if (! $this->mayDecrypt) {
                return strtoupper(trans('admin/custom_fields/general.encrypted'));
            }
            $value = Helper::gracefulDecrypt($this->field($column), $value);
        }
        if (in_array($column->format, ['DATE', 'DATETIME'], true)) {
            return Helper::getFormattedDateObject($value, $column->format === 'DATETIME' ? 'datetime' : 'date', false);
        }

        return $value;
    }

    private function field(AssetTableColumn $column): CustomField
    {
        return $this->fields[(int) $column->customFieldId] ??= tap(new CustomField, function (CustomField $field) use ($column) {
            $field->forceFill(['id' => $column->customFieldId, 'field_encrypted' => $column->encrypted ? 1 : 0, 'db_column' => $column->dbColumn, 'format' => $column->format]);
        });
    }
}
