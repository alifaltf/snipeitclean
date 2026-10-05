<?php

namespace App\Services\AssetImport\Validation;

/**
 * ERS Phase 6B: one CSV data row after validation.
 *
 * $attributes holds the resolved values Phase 6C will write (record ids,
 * parsed dates/numbers/booleans and plain custom-field values keyed by
 * their database column). $display holds what the preview may show:
 * destination label => display string, with encrypted custom fields
 * already masked. Neither is ever logged or stored.
 */
final class AssetImportValidatedRow
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $display
     * @param  list<AssetImportRowError>  $errors
     */
    public function __construct(
        public readonly int $row,
        public readonly ?string $assetTag,
        public readonly array $attributes,
        public readonly array $display,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
