<?php

namespace App\Services\AssetTableLayout;

/**
 * ERS Phase 7A: the single list of standard asset fields a category's
 * asset table may show, and the format of every column key.
 *
 * Keys:
 *  - "standard:<field>" for an approved standard field, where <field> is
 *    the field name the asset table and API already use;
 *  - "custom_field:<id>" for a custom field (positive id).
 *
 * Labels are the translations the native asset table uses for the same
 * fields. Columns that every asset table always has (selection checkbox,
 * actions, check-in/check-out) are not configurable and not listed. The
 * category column is not offered: every row of a category table has the
 * same category. Nothing here refers to a particular category.
 */
final class AssetTableFieldCatalog
{
    public const KIND_STANDARD = 'standard';

    public const KIND_CUSTOM_FIELD = 'custom_field';

    /** Approved standard fields => translation key of their label, in offer order. */
    public const STANDARD_FIELDS = [
        'asset_tag' => 'admin/hardware/table.asset_tag',
        'name' => 'general.name',
        'model' => 'admin/hardware/form.model',
        'serial' => 'admin/hardware/form.serial',
        'status' => 'admin/hardware/table.status',
        'model_number' => 'admin/models/table.modelnumber',
        'manufacturer' => 'general.manufacturer',
        'company' => 'general.company',
        'assigned_to' => 'admin/hardware/form.checkedout_to',
        'location' => 'admin/hardware/table.location',
        'rtd_location' => 'admin/hardware/form.default_location',
        'image' => 'general.image',
        'supplier' => 'general.supplier',
        'order_number' => 'general.order_number',
        'purchase_date' => 'general.purchase_date',
        'purchase_cost' => 'general.purchase_cost',
        'warranty_months' => 'admin/hardware/form.warranty',
        'warranty_expires' => 'admin/hardware/form.warranty_expires',
        'asset_eol_date' => 'admin/hardware/form.eol_date',
        'requestable' => 'admin/hardware/general.requestable',
        'byod' => 'general.byod',
        'last_checkout' => 'admin/hardware/table.checkout_date',
        'expected_checkin' => 'admin/hardware/form.expected_checkin',
        'last_audit_date' => 'general.last_audit',
        'next_audit_date' => 'general.next_audit_date',
        'notes' => 'general.notes',
        'created_at' => 'general.created_at',
        'updated_at' => 'general.updated_at',
    ];

    /** The layout of a final category that has not been configured. */
    public const DEFAULT_KEYS = [
        'standard:asset_tag',
        'standard:name',
        'standard:model',
        'standard:serial',
        'standard:status',
    ];

    /** Most columns one layout may have. */
    public const MAX_COLUMNS = 100;

    private const STANDARD_PATTERN = '/\Astandard:([a-z][a-z0-9_]{0,48})\z/';

    private const CUSTOM_FIELD_PATTERN = '/\Acustom_field:([1-9][0-9]{0,9})\z/';

    public static function standardKey(string $field): string
    {
        return self::KIND_STANDARD.':'.$field;
    }

    public static function customFieldKey(int $id): string
    {
        return self::KIND_CUSTOM_FIELD.':'.$id;
    }

    /**
     * Parse a key without consulting the database.
     *
     * @return array{kind: string, field?: string, id?: int}|null null when malformed
     *                                                            or not an approved standard field
     */
    public static function parse(mixed $key): ?array
    {
        if (! is_string($key)) {
            return null;
        }
        if (preg_match(self::STANDARD_PATTERN, $key, $match) === 1) {
            return isset(self::STANDARD_FIELDS[$match[1]]) ? ['kind' => self::KIND_STANDARD, 'field' => $match[1]] : null;
        }
        if (preg_match(self::CUSTOM_FIELD_PATTERN, $key, $match) === 1 && (int) $match[1] <= 2147483647) {
            return ['kind' => self::KIND_CUSTOM_FIELD, 'id' => (int) $match[1]];
        }

        return null;
    }

    public static function isStandardKey(mixed $key): bool
    {
        return (self::parse($key)['kind'] ?? null) === self::KIND_STANDARD;
    }

    /** @return list<AssetTableColumn> every approved standard column, in offer order */
    public static function standardColumns(): array
    {
        $columns = [];
        foreach (self::STANDARD_FIELDS as $field => $labelKey) {
            $columns[] = new AssetTableColumn(self::standardKey($field), self::KIND_STANDARD, trans($labelKey));
        }

        return $columns;
    }

    public static function standardColumn(string $key): ?AssetTableColumn
    {
        $parsed = self::parse($key);
        if (($parsed['kind'] ?? null) !== self::KIND_STANDARD) {
            return null;
        }

        return new AssetTableColumn($key, self::KIND_STANDARD, trans(self::STANDARD_FIELDS[$parsed['field']]));
    }
}
