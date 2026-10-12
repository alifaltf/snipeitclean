<?php

namespace App\Actions\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\AssetImportReadiness;
use App\Services\AssetImport\AssetImportSessions;
use App\Services\AssetImport\Validation\AssetImportValidationResult;
use App\Services\AssetImport\Validation\AssetImportValidator;
use Illuminate\Support\Facades\DB;

/**
 * ERS Phase 6B: validate every row of a session and record the outcome.
 *
 * Writes only the session's state, validated_at and validation_nonce:
 * validated (with validated_at and a fresh nonce) when every row passed,
 * validation_failed (both cleared) otherwise; it also clears the diagnostics of a rolled-back
 * execution (Phase 6C), which is how a failed import is retried. No asset
 * or master record is written. Can be run again at any time. Execution
 * never relies on this state alone: ExecuteAssetImport validates again
 * inside the transaction that writes the assets.
 */
final class ValidateAssetImportSession
{
    public function __construct(
        private readonly AssetImportSessions $sessions,
        private readonly AssetImportReadiness $readiness,
        private readonly AssetImportValidator $validator,
    ) {}

    /**
     * Returns [session, readiness problem or null, result or null].
     *
     * @return array{0: AssetImportSession, 1: string|null, 2: AssetImportValidationResult|null}
     */
    public function handle(User $user, string $publicId): array
    {
        return DB::transaction(function () use ($user, $publicId) {
            $session = $this->sessions->findOpen($publicId, $user, true);

            $problem = $this->readiness->problem($session, $user);
            if ($problem !== null) {
                return [$session, $problem, null];
            }

            $result = $this->validator->validate($session, $user);

            $session->state = $result->isEligible()
                ? AssetImportSession::STATE_VALIDATED
                : AssetImportSession::STATE_VALIDATION_FAILED;
            $session->validated_at = $result->isEligible() ? $result->validatedAt : null;
            // A new version for every successful validation, even one in the
            // same second with the same file, target and mapping.
            $session->validation_nonce = $result->isEligible() ? AssetImportSession::newValidationNonce() : null;
            // A new validation supersedes a rolled-back execution's diagnostics.
            $session->execution_errors = null;
            $session->execution_failed_at = null;
            $session->save();

            return [$session, null, $result];
        });
    }
}
