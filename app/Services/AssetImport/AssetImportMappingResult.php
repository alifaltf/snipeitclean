<?php

namespace App\Services\AssetImport;

/** ERS Phase 6A: a validated mapping (one entry per column) and its errors. */
final class AssetImportMappingResult
{
    /**
     * @param  list<array{column: int, header: string, destination: string|null}>  $mapping
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly array $mapping,
        public readonly array $errors,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
