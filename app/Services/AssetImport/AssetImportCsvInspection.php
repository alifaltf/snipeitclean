<?php

namespace App\Services\AssetImport;

/** ERS Phase 6A: what the inspector learned about a valid CSV. */
final class AssetImportCsvInspection
{
    /**
     * @param  list<string>  $headers  trimmed header cells, in column order
     */
    public function __construct(
        public readonly array $headers,
        public readonly int $rowCount,
    ) {}
}
