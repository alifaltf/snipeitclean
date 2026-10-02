<?php

namespace App\Services\AssetImport;

/**
 * ERS Phase 6A: suggests a destination for a CSV column only when the
 * header is exactly the destination's current Snipe-IT label after
 * trimming, collapsing spaces and ignoring case. There is no synonym list.
 * A label shared by two destinations suggests nothing. Suggestions only
 * pre-fill the form; nothing is saved until the user saves the mapping.
 */
final class AssetImportMappingSuggester
{
    /**
     * @param  list<string>  $headers
     * @param  array<string, AssetImportDestination>  $destinations
     * @return array<int, string> column position => destination key
     */
    public function suggest(array $headers, array $destinations): array
    {
        $byLabel = [];
        foreach ($destinations as $key => $destination) {
            $byLabel[AssetImportCsvInspector::normalise($destination->label)][] = $key;
        }

        $suggestions = [];
        foreach ($headers as $index => $header) {
            $keys = $byLabel[AssetImportCsvInspector::normalise($header)] ?? [];
            if (count($keys) === 1) {
                $suggestions[$index] = $keys[0];
            }
        }

        return $suggestions;
    }
}
