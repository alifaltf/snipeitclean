<?php

namespace App\Services\AssetImport\Validation;

/**
 * ERS Phase 6B: one problem with one CSV row. Holds only safe, structured
 * information: the CSV row number (header = row 1), the destination key and
 * label, a translated message and the row's asset tag. Never the raw row,
 * never a custom-field value.
 */
final class AssetImportRowError
{
    public function __construct(
        public readonly int $row,
        public readonly string $field,
        public readonly string $label,
        public readonly string $message,
        public readonly ?string $assetTag = null,
    ) {}
}
