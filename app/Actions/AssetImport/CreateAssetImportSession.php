<?php

namespace App\Actions\AssetImport;

use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\AssetImportCsvInspector;
use App\Services\AssetImport\AssetImportFileException;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * ERS Phase 6A: inspect an uploaded CSV, store it privately under a random
 * name and open an import session for its owner. Writes only the stored
 * file and the session row; if creating the row fails the file is removed.
 */
final class CreateAssetImportSession
{
    public function __construct(private readonly AssetImportCsvInspector $inspector) {}

    /**
     * @throws AssetImportFileException when the file is not an acceptable CSV
     */
    public function handle(User $user, UploadedFile $file): AssetImportSession
    {
        $localPath = $file->getRealPath();
        if ($localPath === false) {
            throw new AssetImportFileException(trans('admin/hardware/import.upload.empty'));
        }

        $inspection = $this->inspector->inspect($localPath);
        $hash = hash_file('sha256', $localPath);
        $size = filesize($localPath);

        $directory = trim((string) config('asset_import.directory'), '/');
        $name = Str::random(40).'.csv';
        $disk = AssetImportSessions::disk();

        $stored = $disk->putFileAs($directory, $file, $name);
        if ($stored === false) {
            throw new RuntimeException('The uploaded import file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($user, $file, $stored, $hash, $size, $inspection) {
                $session = new AssetImportSession;
                $session->public_id = (string) Str::uuid();
                $session->created_by = $user->id;
                $session->original_filename = self::displayName($file->getClientOriginalName());
                $session->storage_path = $stored;
                $session->file_sha256 = $hash;
                $session->file_size = $size;
                $session->headers = $inspection->headers;
                $session->row_count = $inspection->rowCount;
                $session->state = AssetImportSession::STATE_UPLOADED;
                $session->expires_at = now()->addHours((int) config('asset_import.session_lifetime_hours'));
                $session->save();

                return $session;
            });
        } catch (Throwable $e) {
            $disk->delete($stored);

            throw $e;
        }
    }

    /** The client's file name, for display only: no path, no control characters. */
    private static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit($name !== '' ? $name : 'import.csv', 255, '');
    }
}
