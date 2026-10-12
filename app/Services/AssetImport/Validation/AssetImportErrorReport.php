<?php

namespace App\Services\AssetImport\Validation;

use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * ERS Phase 6B/6C: the downloadable CSV of validation errors (and of a
 * rolled-back import's diagnostics). Only four safe
 * columns (row number, asset tag, field, error), properly quoted, with
 * every text cell that starts with =, +, -, @, tab or carriage return
 * prefixed so spreadsheets never run it as a formula. Built in memory for
 * the response only; never written to disk.
 */
final class AssetImportErrorReport
{
    public function csv(AssetImportValidationResult $result): string
    {
        $rows = [];
        foreach ($result->errors() as $error) {
            $rows[] = [$error->row, $error->assetTag, $error->label, $error->message];
        }

        return $this->rows($rows);
    }

    /**
     * The same report from prepared rows: [row number, asset tag, field,
     * error]. Phase 6C uses it for the diagnostics of a rolled-back import.
     *
     * @param  iterable<array{0: int|string|null, 1: string|null, 2: string, 3: string}>  $rows
     */
    public function rows(iterable $rows): string
    {
        $writer = Writer::createFromString();
        $writer->addFormatter(new EscapeFormula);
        $writer->insertOne([
            trans('admin/hardware/import.results.report.row'),
            trans('admin/hardware/import.results.report.asset_tag'),
            trans('admin/hardware/import.results.report.field'),
            trans('admin/hardware/import.results.report.error'),
        ]);

        foreach ($rows as [$row, $assetTag, $field, $message]) {
            $writer->insertOne([(string) $row, (string) $assetTag, (string) $field, (string) $message]);
        }

        return $writer->toString();
    }
}
