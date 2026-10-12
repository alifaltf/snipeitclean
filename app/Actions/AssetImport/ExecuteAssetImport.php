<?php

namespace App\Actions\AssetImport;

use App\Models\AssetImportSession;
use App\Models\CustomField;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetImport\AssetImportDestination;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetImport\AssetImportReadiness;
use App\Services\AssetImport\AssetImportSessions;
use App\Services\AssetImport\Execution\AssetImportAssetWriter;
use App\Services\AssetImport\Execution\AssetImportConflictCheck;
use App\Services\AssetImport\Execution\AssetImportExecutionFailure;
use App\Services\AssetImport\Execution\AssetImportExecutionOutcome;
use App\Services\AssetImport\Validation\AssetImportValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * ERS Phase 6C: imports a validated session, creating every row or none.
 *
 * One database transaction holds, in order:
 *  1. the session row, loaded with AssetImportSessions::findOpen() under
 *     SELECT ... FOR UPDATE (owner, open state, expiry, private file and
 *     its SHA-256);
 *  2. the state check (validated) and the user's confirmation, which must
 *     name the validation the user reviewed;
 *  3. AssetImportReadiness (global Import and Assets Create, category
 *     Create on the live final category, target records, company scope,
 *     mapping hash and mapping validity);
 *  4. a full validation of every row (AssetImportValidator);
 *  5. the new assets, created natively (AssetImportAssetWriter), with the
 *     native action logs, order lines and settings updates;
 *  6. a locking uniqueness check of the new assets against every other
 *     asset row (AssetImportConflictCheck);
 *  7. the session marked completed with completed_at, imported_count and
 *     imported_asset_ids.
 * It commits all of that together or rolls all of it back. A failure in
 * 5 or 6 (or any unexpected error) is then recorded in a second, separate
 * transaction: state execution_failed, validated_at cleared and safe
 * diagnostics in execution_errors. Retrying needs a fresh validation.
 *
 * Repeats: a completed session is no longer open, so a second submission
 * (double click, retry, back button) gets the generic "unavailable" 404
 * and creates nothing. A concurrent submission of the same session waits
 * for the row lock and then finds it completed, or failed, likewise.
 *
 * Competing imports: on MySQL/MariaDB every execution first takes a
 * database named lock (GET_LOCK, no wait), so two imports never create
 * assets at the same time; a request that cannot get it immediately
 * changes nothing and asks the user to try again. Native asset creation does not take that lock; see
 * AssetImportConflictCheck for how it is covered.
 *
 * Side effects: only database writes happen inside the transaction (asset
 * observer: action log, order and order item, settings). Nothing is
 * mailed, notified or queued. The in-memory settings cache is reset after
 * a rollback.
 */
final class ExecuteAssetImport
{
    /** Diagnostics kept for one failed execution. */
    private const MAX_ERRORS = 5000;

    public function __construct(
        private readonly AssetImportSessions $sessions,
        private readonly AssetImportReadiness $readiness,
        private readonly AssetImportValidator $validator,
        private readonly AssetImportAssetWriter $writer,
        private readonly AssetImportConflictCheck $conflicts,
    ) {}

    /**
     * What the confirmation form must send back, or null when there is no
     * current validation to confirm. It is an HMAC (application key) over
     * the session, its file and mapping hashes and the validation nonce,
     * which every successful validation replaces and every target or
     * mapping change, failed validation and failed or completed execution
     * clears. A confirmation therefore only ever authorises the one
     * validation the user reviewed; a token from an earlier validation
     * never matches a later one, even within the same second.
     */
    public static function confirmationToken(AssetImportSession $session): ?string
    {
        if (! is_string($session->validation_nonce) || $session->validation_nonce === '') {
            return null;
        }

        return hash_hmac('sha256', implode('|', [
            'ers-asset-import-confirmation',
            $session->public_id,
            $session->validation_nonce,
            $session->file_sha256,
            (string) $session->mapping_file_sha256,
        ]), (string) config('app.key'));
    }

    public function handle(User $user, string $publicId, mixed $confirmation): AssetImportExecutionOutcome
    {
        return $this->underImportLock(function () use ($user, $publicId, $confirmation) {
            try {
                return DB::transaction(fn () => $this->execute($user, $publicId, $confirmation));
            } catch (AssetImportExecutionFailure $failure) {
                return $this->recordFailure($user, $publicId, $failure->errors);
            } catch (HttpExceptionInterface $unavailable) {
                // Session not available (generic 404): nothing was written.
                throw $unavailable;
            } catch (Throwable $exception) {
                // The message is not logged: database errors embed the bound
                // values, i.e. CSV data and possibly encrypted-field values.
                Log::error('ERS asset import execution was rolled back after an unexpected error.', [
                    'import' => Str::isUuid($publicId) ? strtolower($publicId) : null,
                    'exception' => $exception::class,
                ]);

                return $this->recordFailure($user, $publicId, [[
                    'row' => null,
                    'asset_tag' => null,
                    'field' => '',
                    'message' => trans('admin/hardware/import.execution.unexpected'),
                ]]);
            }
        });
    }

    private function execute(User $user, string $publicId, mixed $confirmation): AssetImportExecutionOutcome
    {
        $session = $this->sessions->findOpen($publicId, $user, true);

        if ($session->state !== AssetImportSession::STATE_VALIDATED || $session->validated_at === null) {
            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::NOT_VALIDATED, $session);
        }
        // Checked under the session row lock taken by findOpen() above.
        $expected = self::confirmationToken($session);
        if ($expected === null || ! is_string($confirmation) || ! hash_equals($expected, $confirmation)) {
            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::NOT_CONFIRMED, $session);
        }

        $problem = $this->readiness->problem($session, $user);
        if ($problem !== null) {
            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::NEEDS_RECONFIGURATION, $session, $problem);
        }

        $result = $this->validator->validate($session, $user);
        if (! $result->isEligible()) {
            // Committed on purpose: only the session state changes.
            $session->state = AssetImportSession::STATE_VALIDATION_FAILED;
            $session->validated_at = null;
            $session->validation_nonce = null;
            $session->save();

            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::REVALIDATION_FAILED, $session);
        }

        $created = $this->writer->create($session, $user, $result);

        $conflicts = $this->conflicts->conflicts($created, $this->uniqueChecks($session));
        if ($conflicts !== []) {
            $tags = [];
            foreach ($result->rows as $row) {
                $tags[$row->row] = $row->assetTag;
            }
            $labels = $this->uniqueLabels($session);
            $errors = [];
            foreach ($conflicts as $row => $keys) {
                foreach ($keys as $key) {
                    $errors[] = ['row' => $row, 'asset_tag' => $tags[$row] ?? null, 'field' => $labels[$key] ?? '', 'message' => trans('admin/hardware/import.execution.conflict')];
                }
            }

            throw new AssetImportExecutionFailure($errors);
        }

        $session->state = AssetImportSession::STATE_COMPLETED;
        $session->completed_at = now();
        $session->validation_nonce = null;
        $session->imported_count = count($created);
        $session->imported_asset_ids = array_values($created);
        $session->execution_errors = null;
        $session->execution_failed_at = null;
        $session->save();

        return AssetImportExecutionOutcome::completed($session, array_values($created));
    }

    /** @param  list<array{row: int|null, asset_tag: string|null, field: string, message: string}>  $errors */
    private function recordFailure(User $user, string $publicId, array $errors): AssetImportExecutionOutcome
    {
        // Native observers may have changed the cached settings row before
        // the rollback; read it again from the database.
        Setting::$_cache = null;

        return DB::transaction(function () use ($user, $publicId, $errors) {
            $session = $this->sessions->findOpen($publicId, $user, true);
            $session->state = AssetImportSession::STATE_EXECUTION_FAILED;
            $session->validated_at = null;
            $session->validation_nonce = null;
            $session->execution_errors = array_slice($errors, 0, self::MAX_ERRORS);
            $session->execution_failed_at = now();
            $session->save();

            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::EXECUTION_FAILED, $session);
        });
    }

    /**
     * Unique columns to re-check after inserting: asset tag and serial
     * (deleted assets count, as in validation), and mapped unique,
     * non-encrypted custom fields (live assets, like unique_undeleted).
     *
     * @return array<string, array{column: string, include_deleted: bool}>
     */
    private function uniqueChecks(AssetImportSession $session): array
    {
        $checks = ['standard:asset_tag' => ['column' => 'asset_tag', 'include_deleted' => true]];
        $mapped = array_filter($session->mappedDestinations());
        if (in_array(AssetImportDestination::standardKey('serial'), $mapped, true)) {
            $checks['standard:serial'] = ['column' => 'serial', 'include_deleted' => true];
        }
        foreach ($this->uniqueCustomFields($mapped) as $field) {
            $checks[AssetImportDestination::customFieldKey((int) $field->id)] = ['column' => $field->db_column_name(), 'include_deleted' => false];
        }

        return $checks;
    }

    /** @return array<string, string> destination key => label */
    private function uniqueLabels(AssetImportSession $session): array
    {
        $labels = [
            'standard:asset_tag' => trans(AssetImportFieldCatalog::STANDARD_FIELDS['asset_tag']),
            'standard:serial' => trans(AssetImportFieldCatalog::STANDARD_FIELDS['serial']),
        ];
        foreach ($this->uniqueCustomFields(array_filter($session->mappedDestinations())) as $field) {
            $labels[AssetImportDestination::customFieldKey((int) $field->id)] = (string) $field->name;
        }

        return $labels;
    }

    /**
     * @param  array<int, string>  $mapped
     * @return list<CustomField>
     */
    private function uniqueCustomFields(array $mapped): array
    {
        $ids = [];
        foreach ($mapped as $key) {
            if (preg_match('/\Acustom_field:([1-9][0-9]*)\z/', $key, $match) === 1) {
                $ids[] = (int) $match[1];
            }
        }
        if ($ids === []) {
            return [];
        }

        return CustomField::query()->whereIn('id', $ids)->get()
            ->filter(fn (CustomField $field) => (string) $field->is_unique === '1' && (string) $field->field_encrypted !== '1')
            ->values()->all();
    }

    /** Serialises imports on MySQL/MariaDB with a database named lock. */
    private function underImportLock(callable $callback): AssetImportExecutionOutcome
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $callback();
        }

        $name = 'ers_asset_import_'.substr(hash('sha256', (string) $connection->getDatabaseName()), 0, 32);
        // No waiting: a second import while one is running is told to try
        // again, and nothing is changed.
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
        if ((int) ($acquired->acquired ?? 0) !== 1) {
            return AssetImportExecutionOutcome::of(AssetImportExecutionOutcome::BUSY, null);
        }

        try {
            return $callback();
        } finally {
            try {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            } catch (Throwable) {
                // Released anyway when the connection closes.
            }
        }
    }
}
