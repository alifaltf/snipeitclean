<?php

namespace App\Actions\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\AssetImportMappingValidator;
use App\Services\AssetImport\AssetImportSessions;
use App\Services\AssetImport\AssetImportTargetCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ERS Phase 6A: save the column mapping the user reviewed. The mapping is
 * validated against the field catalog, stored by column position and tied
 * to the stored file's SHA-256; the session moves to "mapped".
 */
final class SaveAssetImportMapping
{
    public function __construct(
        private readonly AssetImportMappingValidator $mappings,
        private readonly AssetImportSessions $sessions,
        private readonly AssetImportTargetCheck $targets,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $publicId, mixed $input): AssetImportSession
    {
        return DB::transaction(function () use ($user, $publicId, $input) {
            // findOpen() also verifies the stored file against its hash.
            $session = $this->sessions->findOpen($publicId, $user, true);

            if (! $this->targets->isValid($session, $user)) {
                throw ValidationException::withMessages(['mapping' => trans('admin/hardware/import.target.reconfigure')]);
            }

            $result = $this->mappings->validate($session, $user, $input);
            if (! $result->passes()) {
                throw ValidationException::withMessages($result->errors);
            }

            $session->mapping = $result->mapping;
            $session->mapping_file_sha256 = $session->file_sha256;
            $session->state = AssetImportSession::STATE_MAPPED;
            $session->save();

            return $session;
        });
    }
}
