<?php

namespace App\Services\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ERS Phase 6A: the single way every session-specific step loads an import
 * session.
 *
 * findOpen() returns a session only when all of these hold: the id is a
 * UUID, the session belongs to the user (Super Admin included: sessions
 * are never shared), it is open (uploaded, target_selected or mapped), it
 * has not expired, and its private stored file still exists with the
 * SHA-256 recorded at upload. Anything else gets the same generic 404, so
 * nothing reveals whether an id exists, whose it is or why it failed.
 * Because the integrity check lives here, a step cannot load a session
 * without it.
 */
final class AssetImportSessions
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('asset_import.disk'));
    }

    /**
     * @throws NotFoundHttpException
     */
    public function findOpen(mixed $publicId, User $user, bool $lock = false): AssetImportSession
    {
        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            throw self::unavailable();
        }

        $query = AssetImportSession::query()
            ->openFor($user)
            ->where('public_id', strtolower($publicId));

        if ($lock) {
            $query->lockForUpdate();
        }

        $session = $query->first();
        if ($session === null || ! $this->fileIsIntact($session)) {
            throw self::unavailable();
        }

        return $session;
    }

    /**
     * Does the stored file still exist with the hash recorded at upload?
     * Streams the file; never loads it whole.
     */
    private function fileIsIntact(AssetImportSession $session): bool
    {
        $disk = self::disk();
        if (! $disk->exists($session->storage_path)) {
            return false;
        }

        $stream = $disk->readStream($session->storage_path);
        if (! is_resource($stream)) {
            return false;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_equals($session->file_sha256, hash_final($context));
        } finally {
            fclose($stream);
        }
    }

    public static function unavailable(): NotFoundHttpException
    {
        return new NotFoundHttpException(trans('admin/hardware/import.session_unavailable'));
    }
}
