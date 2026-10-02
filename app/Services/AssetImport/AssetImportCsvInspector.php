<?php

namespace App\Services\AssetImport;

use League\Csv\Reader;
use Throwable;

/**
 * ERS Phase 6A: checks an uploaded file is a well-formed, comma-separated,
 * UTF-8 CSV with a usable header row, reading it as a stream (never the
 * whole file in memory). Cell values are only counted, never interpreted:
 * nothing that looks like a spreadsheet formula is evaluated.
 *
 * Rules: not empty; UTF-8 (a UTF-8 byte-order mark is allowed) with no NUL
 * bytes; balanced quotes; a header row whose cells are all non-empty,
 * within the length limit and unique after trimming, collapsing spaces and
 * ignoring case; every data row has exactly as many cells as the header;
 * at least one and at most `asset_import.max_data_rows` data rows.
 * Completely empty lines are skipped, as in the native importer.
 */
final class AssetImportCsvInspector
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @throws AssetImportFileException
     */
    public function inspect(string $path): AssetImportCsvInspection
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.empty'));
        }

        $this->assertTextEncoding($path);

        $maxRows = (int) config('asset_import.max_data_rows');
        $headers = null;
        $rows = 0;

        try {
            $reader = self::configure(Reader::createFromPath($path, 'r'));

            foreach ($reader->getRecords() as $record) {
                if ($headers === null) {
                    $headers = $this->headers($record);

                    continue;
                }

                if (count($record) !== count($headers)) {
                    throw new AssetImportFileException(trans('admin/hardware/import.upload.malformed'));
                }

                if (++$rows > $maxRows) {
                    throw new AssetImportFileException(trans('admin/hardware/import.upload.too_many_rows', ['max' => $maxRows]));
                }
            }
        } catch (AssetImportFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.malformed'));
        }

        if ($headers === null) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.no_header'));
        }

        if ($rows === 0) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.no_rows'));
        }

        return new AssetImportCsvInspection($headers, $rows);
    }

    /**
     * The CSV dialect of the secure import: comma-separated, double-quoted,
     * no escape character (RFC 4180), UTF-8 byte-order mark skipped. Every
     * reader of an import file uses this, so upload inspection and later
     * reads (samples, validation) always split columns the same way.
     */
    public static function configure(Reader $reader): Reader
    {
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setEscape('');
        $reader->skipInputBOM();

        return $reader;
    }

    /** Header names after the same normalisation used to find duplicates. */
    public static function normalise(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Streams the file line by line: UTF-8 only, no NUL bytes, and an even
     * number of quote characters overall (an odd count means a quoted cell
     * is never closed).
     */
    private function assertTextEncoding(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.empty'));
        }

        $quotes = 0;
        $first = true;
        try {
            while (($line = fgets($handle)) !== false) {
                if ($first && str_starts_with($line, self::BOM)) {
                    $line = substr($line, strlen(self::BOM));
                }
                $first = false;

                if (str_contains($line, "\0")) {
                    throw new AssetImportFileException(trans('admin/hardware/import.upload.not_csv'));
                }
                if (! mb_check_encoding($line, 'UTF-8')) {
                    throw new AssetImportFileException(trans('admin/hardware/import.upload.not_utf8'));
                }
                $quotes += substr_count($line, '"');
            }
        } finally {
            fclose($handle);
        }

        if ($quotes % 2 !== 0) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.malformed'));
        }
    }

    /**
     * @param  array<int, string|null>  $record
     * @return list<string>
     */
    private function headers(array $record): array
    {
        $maxColumns = (int) config('asset_import.max_columns');
        $maxLength = (int) config('asset_import.max_header_length');

        $headers = array_map(fn ($cell) => trim((string) $cell), array_values($record));

        if ($headers === [] || implode('', $headers) === '') {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.no_header'));
        }

        if (count($headers) === 1 && preg_match('/[;\t|]/', $headers[0]) === 1) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.not_comma_separated'));
        }

        if (count($headers) > $maxColumns) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.too_many_columns', ['max' => $maxColumns]));
        }

        $seen = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                throw new AssetImportFileException(trans('admin/hardware/import.upload.blank_header', ['column' => $index + 1]));
            }
            if (mb_strlen($header, 'UTF-8') > $maxLength) {
                throw new AssetImportFileException(trans('admin/hardware/import.upload.header_too_long', ['column' => $index + 1, 'max' => $maxLength]));
            }
            $normalised = self::normalise($header);
            if (isset($seen[$normalised])) {
                throw new AssetImportFileException(trans('admin/hardware/import.upload.duplicate_header', [
                    'first' => $seen[$normalised] + 1,
                    'second' => $index + 1,
                ]));
            }
            $seen[$normalised] = $index;
        }

        return $headers;
    }
}
