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
        // ERS Phase 7B: the custom field's column on assets and its format
        // (for table formatting, searching and sorting). Null for standard
        // fields.
        public readonly ?string $dbColumn = null,
        public readonly ?string $format = null,
    ) {}

    /**
     * ERS Phase 7B: the field name the asset table, the API rows and the
     * sort/search parameters use for this column: the standard field name,
     * or the custom field's column.
     */
    public function field(): string
    {
        if ($this->isCustomField()) {
            return (string) $this->dbColumn;
        }

        return substr($this->key, strlen(AssetTableFieldCatalog::KIND_STANDARD.':'));
    }

    public function isCustomField(): bool
    {
        return $this->kind === AssetTableFieldCatalog::KIND_CUSTOM_FIELD;
    }
}
