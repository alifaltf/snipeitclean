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
 * ERS Phase 6A: save the target configuration of an import session: the
 * final category, the model source (one fixed model, or a Model column)
 * and the status, company and location sources.
 *
 * Every id is re-checked against what the user may use (see
 * AssetImportTargetCheck). Saving always returns the session to
 * target_selected so the mapping has to be saved again; a changed
 * category, or a mapping that no longer fits the new sources, is cleared.
 */
final class ConfigureAssetImportTarget
{
    public function __construct(
        private readonly AssetImportTargetCheck $targets,
        private readonly AssetImportMappingValidator $mappings,
        private readonly AssetImportSessions $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $publicId, array $input): AssetImportSession
    {
        return DB::transaction(function () use ($user, $publicId, $input) {
            $session = $this->sessions->findOpen($publicId, $user, true);

            [$target, $errors] = $this->targets->resolve($user, $input);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $categoryChanged = $session->category_id !== $target['category_id'];
            foreach ($target as $column => $value) {
                $session->{$column} = $value;
            }
            $session->state = AssetImportSession::STATE_TARGET_SELECTED;

            if ($categoryChanged || ! $this->mappingStillFits($session, $user)) {
                $session->mapping = null;
            }
            $session->mapping_file_sha256 = null;
            $session->save();

            return $session;
        });
    }

    /** Is the saved mapping (if any) still valid for the new sources? */
    private function mappingStillFits(AssetImportSession $session, User $user): bool
    {
        if ($session->mapping === null) {
            return true;
        }

        return $this->mappings->validate($session, $user, $session->mappedDestinations(), false)->passes();
    }
}
