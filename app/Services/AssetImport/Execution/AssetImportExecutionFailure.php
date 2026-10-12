<?php

namespace App\Services\AssetImport\Execution;

use RuntimeException;

/**
 * ERS Phase 6C: an import execution must stop and roll back. Carries only
 * safe diagnostics: CSV row number, field label, message and the row's
 * asset tag (as shown in the Phase 6B report); never cell values, raw
 * rows, SQL or stack traces.
 */
final class AssetImportExecutionFailure extends RuntimeException
{
    /** @param  list<array{row: int|null, asset_tag: string|null, field: string, message: string}>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The asset import was rolled back.');
    }

    public static function forRow(int $row, ?string $assetTag, string $field, string $message): self
    {
        return new self([['row' => $row, 'asset_tag' => $assetTag, 'field' => $field, 'message' => $message]]);
    }
}
