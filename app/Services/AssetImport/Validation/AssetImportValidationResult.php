<?php

namespace App\Services\AssetImport\Validation;

use Carbon\CarbonImmutable;

/**
 * ERS Phase 6B: the outcome of validating every row of an import session.
 * All-or-nothing: the import is eligible only when there is at least one
 * row and every row passed. Phase 6C reuses this result (computed again
 * inside its own transaction) to decide whether and what to write.
 */
final class AssetImportValidationResult
{
    /**
     * @param  list<AssetImportValidatedRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly CarbonImmutable $validatedAt,
    ) {}

    public function totalRows(): int
    {
        return count($this->rows);
    }

    public function validRows(): int
    {
        return count(array_filter($this->rows, fn (AssetImportValidatedRow $row) => $row->isValid()));
    }

    public function invalidRows(): int
    {
        return $this->totalRows() - $this->validRows();
    }

    public function errorCount(): int
    {
        return array_sum(array_map(fn (AssetImportValidatedRow $row) => count($row->errors), $this->rows));
    }

    public function isEligible(): bool
    {
        return $this->totalRows() > 0 && $this->invalidRows() === 0;
    }

    /** @return list<AssetImportRowError> every error, in row order */
    public function errors(): array
    {
        return array_merge([], ...array_map(fn (AssetImportValidatedRow $row) => $row->errors, $this->rows));
    }
}
