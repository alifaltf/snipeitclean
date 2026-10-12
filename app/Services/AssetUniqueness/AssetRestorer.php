<?php

namespace App\Services\AssetUniqueness;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ERS: restores one soft-deleted asset, or explains why it cannot.
 *
 * A restore makes the asset's tag, serial and unique custom-field values
 * live again. Snipe-IT's model validation refuses a value another live
 * asset already uses; the ERS database keys refuse it even when another
 * writer took the value at the same moment. Either way the restore is
 * refused with the field's error, nothing is renamed or retagged, and the
 * restore runs in a transaction so its side effects (the "restore" log
 * entry) are rolled back with it.
 */
final class AssetRestorer
{
    /**
     * @return array<string, string>|null null when restored, otherwise field => message
     */
    public function restore(Asset $asset): ?array
    {
        $refused = false;
        try {
            DB::transaction(function () use ($asset, &$refused) {
                if (! $asset->restore()) {
                    // Roll back whatever the refused restore already wrote.
                    $refused = true;
                    throw new RuntimeException('The restore was refused.');
                }
            });

            return null;
        } catch (Throwable $exception) {
            $violation = UniqueConstraintViolation::detect($exception);
            if ($violation !== null) {
                return [$violation->attribute() => $violation->message()];
            }
            if (! $refused) {
                throw $exception;
            }
        }

        $errors = [];
        foreach ($asset->getErrors()?->messages() ?? [] as $field => $messages) {
            $errors[(string) $field] = (string) ($messages[0] ?? '');
        }

        return $errors !== [] ? $errors : ['asset' => trans('admin/hardware/uniqueness.restore_refused')];
    }
}
