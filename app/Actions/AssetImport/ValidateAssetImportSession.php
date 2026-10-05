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
 * Writes only the session's state and validated_at: validated (with
 * validated_at) when every row passed, validation_failed (validated_at
 * cleared) otherwise. No asset or master record is written. Can be run
 * again at any time. Phase 6C must not rely on this state alone: it has to
 * validate again inside the transaction that writes the assets.
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
            $session->save();

            return [$session, null, $result];
        });
    }
}
