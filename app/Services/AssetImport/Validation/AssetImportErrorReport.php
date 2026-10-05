<?php

namespace App\Services\AssetImport\Validation;

use League\Csv\EscapeFormula;
use League\Csv\Writer;

/**
 * ERS Phase 6B: the downloadable CSV of validation errors. Only four safe
 * columns (row number, asset tag, field, error), properly quoted, with
 * every text cell that starts with =, +, -, @, tab or carriage return
 * prefixed so spreadsheets never run it as a formula. Built in memory for
 * the response only; never written to disk.
 */
final class AssetImportErrorReport
{
    public function csv(AssetImportValidationResult $result): string
    {
        $writer = Writer::createFromString();
        $writer->addFormatter(new EscapeFormula);
        $writer->insertOne([
            trans('admin/hardware/import.results.report.row'),
            trans('admin/hardware/import.results.report.asset_tag'),
            trans('admin/hardware/import.results.report.field'),
            trans('admin/hardware/import.results.report.error'),
        ]);

        foreach ($result->errors() as $error) {
            $writer->insertOne([(string) $error->row, (string) $error->assetTag, $error->label, $error->message]);
        }

        return $writer->toString();
    }
}
