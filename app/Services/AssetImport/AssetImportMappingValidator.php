<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;

/**
 * ERS Phase 6A: validates a submitted column mapping against the field
 * catalog. Input is column position => destination key ('' or null means
 * "Do not import"). Only keys the catalog offers for this session and user
 * are accepted; each destination may be used once; the required
 * destinations for the session's sources must all be mapped.
 *
 * Unknown keys, forged custom-field ids and fields the user may not use
 * all get the same message. A known standard field that is unavailable
 * because its source is fixed (or switched off) says so, since that is a
 * configuration choice the user made, not a hidden record.
 */
final class AssetImportMappingValidator
{
    public function __construct(private readonly AssetImportFieldCatalog $catalog) {}

    public function validate(AssetImportSession $session, User $user, mixed $input, bool $requireComplete = true): AssetImportMappingResult
    {
        $headers = $session->headerList();
        $destinations = $this->catalog->destinations($session, $user);
        $errors = [];
        $chosen = array_fill(0, count($headers), null);

        if ($input !== null && ! is_array($input)) {
            return new AssetImportMappingResult([], ['mapping' => [trans('admin/hardware/import.mapping.invalid')]]);
        }

        foreach ((array) $input as $column => $key) {
            $index = self::columnIndex($column);
            if ($index === null || ! array_key_exists($index, $chosen)) {
                $errors['mapping'][] = trans('admin/hardware/import.mapping.invalid');

                continue;
            }

            if ($key === null || $key === '') {
                continue;
            }

            if (! is_string($key)) {
                $errors["mapping.$index"][] = trans('admin/hardware/import.mapping.unavailable');

                continue;
            }

            if (! isset($destinations[$key])) {
                $reason = $this->catalog->unavailableReason($session, $key);
                $errors["mapping.$index"][] = match ($reason) {
                    'fixed' => trans('admin/hardware/import.mapping.conflicts_with_fixed'),
                    'disabled' => trans('admin/hardware/import.mapping.source_disabled'),
                    default => trans('admin/hardware/import.mapping.unavailable'),
                };

                continue;
            }

            $chosen[$index] = $key;
        }

        $firstUse = [];
        foreach ($chosen as $index => $key) {
            if ($key === null) {
                continue;
            }
            if (isset($firstUse[$key])) {
                $errors["mapping.$index"][] = trans('admin/hardware/import.mapping.duplicate', [
                    'field' => $destinations[$key]->label,
                    'column' => $headers[$firstUse[$key]],
                ]);
                $chosen[$index] = null;

                continue;
            }
            $firstUse[$key] = $index;
        }

        if ($requireComplete) {
            foreach ($this->catalog->requiredKeys($session) as $required) {
                if (! isset($firstUse[$required]) && isset($destinations[$required])) {
                    $errors['mapping'][] = trans('admin/hardware/import.mapping.required_missing', ['field' => $destinations[$required]->label]);
                }
            }
        }

        $mapping = [];
        foreach ($headers as $index => $header) {
            $mapping[] = ['column' => $index, 'header' => $header, 'destination' => $chosen[$index]];
        }

        return new AssetImportMappingResult($mapping, $errors);
    }

    /** A column position from an int or digit-only string, else null. */
    private static function columnIndex(mixed $column): ?int
    {
        if (is_int($column)) {
            return $column >= 0 ? $column : null;
        }

        return is_string($column) && preg_match('/\A[0-9]{1,4}\z/', $column) === 1 ? (int) $column : null;
    }
}
