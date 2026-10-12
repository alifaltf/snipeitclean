<?php

namespace App\Services\AssetTableLayout;

/**
 * ERS Phase 7A: display metadata of one asset-table column. The key is the
 * stable identifier; the label is for display only (translated, or the
 * custom field's name from the database, to be escaped by the view).
 * Never carries asset or custom-field values.
 */
final class AssetTableColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $label,
        public readonly ?int $customFieldId = null,
        public readonly bool $encrypted = false,
    ) {}

    public function isCustomField(): bool
    {
        return $this->kind === AssetTableFieldCatalog::KIND_CUSTOM_FIELD;
    }
}
