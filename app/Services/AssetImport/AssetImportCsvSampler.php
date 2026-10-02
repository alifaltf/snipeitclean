<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use League\Csv\Reader;
use Throwable;

/**
 * ERS Phase 6A: a few sample values per column to help the user map
 * columns. Read from the session's private stored file as a stream (only
 * the header and the first `asset_import.sample_rows` data rows are read),
 * with the same CSV dialect as upload inspection, so values stay with the
 * right column even for quoted commas and multi-line cells.
 *
 * Values are display-only: whitespace (including line breaks) is collapsed,
 * each value is cut to `asset_import.sample_value_length` characters, and
 * nothing is stored or interpreted. Views must render them escaped. The
 * caller must load the session through AssetImportSessions::findOpen(),
 * which checks owner, expiry and file integrity first.
 */
final class AssetImportCsvSampler
{
    /**
     * @return list<list<string>> sample rows, each with one value per header column
     */
    public function samples(AssetImportSession $session): array
    {
        $limit = max(0, (int) config('asset_import.sample_rows'));
        $columns = count($session->headerList());
        if ($limit === 0 || $columns === 0) {
            return [];
        }

        $stream = AssetImportSessions::disk()->readStream($session->storage_path);
        if (! is_resource($stream)) {
            return [];
        }

        $rows = [];
        try {
            $header = true;
            foreach (AssetImportCsvInspector::configure(Reader::createFromStream($stream))->getRecords() as $record) {
                if ($header) {
                    $header = false;

                    continue;
                }

                $values = array_values($record);
                $row = [];
                for ($column = 0; $column < $columns; $column++) {
                    $row[] = self::display($values[$column] ?? '');
                }
                $rows[] = $row;

                if (count($rows) >= $limit) {
                    break;
                }
            }
        } catch (Throwable) {
            // The file passed inspection at upload and its hash was just
            // checked, so this is unexpected; show no samples rather than fail.
            return [];
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    /** One line of plain text, cut to the configured length. */
    public static function display(mixed $value): string
    {
        $max = max(1, (int) config('asset_import.sample_value_length'));
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8').'…' : $text;
    }
}
