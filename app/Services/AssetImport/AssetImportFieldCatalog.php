<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 6A: the single list of destinations a column may be mapped to,
 * used both to render the mapping page and to validate what is submitted.
 *
 * Standard destinations are a fixed allowlist of asset fields (no ids,
 * audit columns, checkout data, images, departments or notes). Which of
 * them are available depends on the session's sources: Model and Model
 * Number only in Model-from-CSV mode, and Status/Company/Location only
 * when that source is "from CSV" (a fixed source is never also mapped).
 *
 * Custom fields come from fieldsets: the fixed model's fieldset, or the
 * union of the fieldsets of every live model in the selected category.
 * Encrypted fields are offered only to users who may view encrypted
 * custom fields, and are marked sensitive.
 */
final class AssetImportFieldCatalog
{
    /** Allowed standard destinations => translation key of their label. */
    public const STANDARD_FIELDS = [
        'asset_tag' => 'general.asset_tag',
        'name' => 'admin/hardware/form.name',
        'serial' => 'general.serial_number',
        'model' => 'admin/hardware/form.model',
        'model_number' => 'general.model_no',
        'status' => 'general.status',
        'company' => 'general.company',
        'location' => 'general.location',
        'supplier' => 'general.supplier',
        'purchase_date' => 'general.purchase_date',
        'purchase_cost' => 'general.purchase_cost',
        'order_number' => 'general.order_number',
        'warranty_months' => 'admin/hardware/form.warranty',
        'requestable' => 'admin/hardware/general.requestable',
        'byod' => 'general.byod',
        'asset_eol_date' => 'admin/hardware/form.eol_date',
        'next_audit_date' => 'general.next_audit_date',
    ];

    /** Standard fields that depend on a source being "from CSV". */
    private const SOURCE_FIELDS = [
        'model' => 'model_source',
        'model_number' => 'model_source',
        'status' => 'status_source',
        'company' => 'company_source',
        'location' => 'location_source',
    ];

    /**
     * Every destination available for this session and user, keyed by
     * destination key, standard fields first.
     *
     * @return array<string, AssetImportDestination>
     */
    public function destinations(AssetImportSession $session, User $user): array
    {
        $required = array_flip($this->requiredKeys($session));
        $destinations = [];

        foreach (self::STANDARD_FIELDS as $field => $labelKey) {
            if (! $this->standardFieldAvailable($session, $field)) {
                continue;
            }
            $key = AssetImportDestination::standardKey($field);
            $destinations[$key] = new AssetImportDestination(
                $key,
                trans($labelKey),
                AssetImportDestination::KIND_STANDARD,
                isset($required[$key]),
            );
        }

        $mayUseEncrypted = Gate::forUser($user)->allows('assets.view.encrypted_custom_fields');
        foreach ($this->customFields($session) as $field) {
            $encrypted = (bool) $field->field_encrypted;
            if ($encrypted && ! $mayUseEncrypted) {
                continue;
            }
            $key = AssetImportDestination::customFieldKey((int) $field->id);
            $destinations[$key] = new AssetImportDestination(
                $key,
                (string) $field->name,
                AssetImportDestination::KIND_CUSTOM_FIELD,
                false,
                $encrypted,
                (bool) $field->required_somewhere,
                (int) $field->id,
            );
        }

        return $destinations;
    }

    /**
     * Destination keys that must be mapped for this session's sources.
     *
     * @return list<string>
     */
    public function requiredKeys(AssetImportSession $session): array
    {
        $keys = [AssetImportDestination::standardKey('asset_tag')];
        foreach (['model' => 'model_source', 'status' => 'status_source', 'company' => 'company_source', 'location' => 'location_source'] as $field => $source) {
            if ($session->{$source} === AssetImportSession::SOURCE_COLUMN) {
                $keys[] = AssetImportDestination::standardKey($field);
            }
        }

        return $keys;
    }

    /**
     * Why a known standard field is not available here (a fixed source or
     * a source that is switched off), or null when it is available or not
     * a standard field at all.
     */
    public function unavailableReason(AssetImportSession $session, string $key): ?string
    {
        $prefix = AssetImportDestination::KIND_STANDARD.':';
        if (! str_starts_with($key, $prefix)) {
            return null;
        }
        $field = substr($key, strlen($prefix));
        if (! isset(self::SOURCE_FIELDS[$field]) || $this->standardFieldAvailable($session, $field)) {
            return null;
        }

        return $session->{self::SOURCE_FIELDS[$field]} === AssetImportSession::SOURCE_FIXED ? 'fixed' : 'disabled';
    }

    private function standardFieldAvailable(AssetImportSession $session, string $field): bool
    {
        if (! isset(self::SOURCE_FIELDS[$field])) {
            return true;
        }

        return $session->{self::SOURCE_FIELDS[$field]} === AssetImportSession::SOURCE_COLUMN;
    }

    /**
     * Custom fields applicable to the session's model selection, in one
     * query. required_somewhere is true when any applicable fieldset marks
     * the field required.
     *
     * @return \Illuminate\Support\Collection<int, CustomField>
     */
    private function customFields(AssetImportSession $session)
    {
        if ($session->category_id === null) {
            return collect();
        }

        $models = DB::table('models')
            ->whereNull('deleted_at')
            ->where('category_id', $session->category_id)
            ->whereNotNull('fieldset_id');

        if ($session->model_source === AssetImportSession::SOURCE_FIXED) {
            if ($session->model_id === null) {
                return collect();
            }
            $models->where('id', $session->model_id);
        } elseif ($session->model_source !== AssetImportSession::SOURCE_COLUMN) {
            return collect();
        }

        $fieldsetIds = $models->distinct()->pluck('fieldset_id');
        if ($fieldsetIds->isEmpty()) {
            return collect();
        }

        return CustomField::query()
            ->join('custom_field_custom_fieldset', 'custom_field_custom_fieldset.custom_field_id', '=', 'custom_fields.id')
            ->whereIn('custom_field_custom_fieldset.custom_fieldset_id', $fieldsetIds)
            ->groupBy('custom_fields.id', 'custom_fields.name', 'custom_fields.field_encrypted')
            ->orderBy('custom_fields.name')
            ->get([
                'custom_fields.id',
                'custom_fields.name',
                'custom_fields.field_encrypted',
                DB::raw('MAX(custom_field_custom_fieldset.required) as required_somewhere'),
            ]);
    }
}
