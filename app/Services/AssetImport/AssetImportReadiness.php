<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;

/**
 * ERS Phase 6B: is a session's configuration complete and still allowed?
 *
 * Used by the review, validate, results and error-report steps (and by
 * Phase 6C before it writes). The target must still be valid for the user
 * (category Create on a live final category, live fixed records in scope),
 * the mapping must be saved, recorded against the current file hash and
 * still valid against the field catalog. The session itself must already
 * have been loaded through AssetImportSessions::findOpen(), which checks
 * owner, expiry and file integrity.
 */
final class AssetImportReadiness
{
    public const NEEDS_TARGET = 'target';

    public const NEEDS_MAPPING = 'mapping';

    public function __construct(
        private readonly AssetImportTargetCheck $targets,
        private readonly AssetImportMappingValidator $mappings,
    ) {}

    /** Null when ready, otherwise the step the user must revisit. */
    public function problem(AssetImportSession $session, User $user): ?string
    {
        if (! $this->targets->isValid($session, $user)) {
            return self::NEEDS_TARGET;
        }

        if (! in_array($session->state, AssetImportSession::MAPPED_STATES, true)
            || ! is_string($session->mapping_file_sha256)
            || ! hash_equals($session->file_sha256, $session->mapping_file_sha256)
            || ! $this->mappings->validate($session, $user, $session->mappedDestinations())->passes()) {
            return self::NEEDS_MAPPING;
        }

        return null;
    }
}
