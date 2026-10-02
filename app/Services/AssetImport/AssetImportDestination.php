<?php

namespace App\Services\AssetImport;

/**
 * ERS Phase 6A: one place a CSV column can be imported into. The key is a
 * stable server-side identifier ("standard:serial", "custom_field:12");
 * the label is for display only and is never trusted as an identifier.
 */
final class AssetImportDestination
{
    public const KIND_STANDARD = 'standard';

    public const KIND_CUSTOM_FIELD = 'custom_field';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind,
        public readonly bool $required = false,
        public readonly bool $sensitive = false,
        public readonly bool $requiredByFieldset = false,
        public readonly ?int $customFieldId = null,
    ) {}

    public static function standardKey(string $field): string
    {
        return self::KIND_STANDARD.':'.$field;
    }

    public static function customFieldKey(int $id): string
    {
        return self::KIND_CUSTOM_FIELD.':'.$id;
    }
}
