<?php

/*
 * ERS Phase 6C: one worker process for AssetImportConcurrencyTest.
 *
 * Runs the real import actions, and only after ConcurrencyDatabaseGuard
 * has verified that Laravel resolved exactly the dedicated
 * *_concurrency_test database requested by ERS_CONCURRENCY_DB_*. Uploaded
 * files go to ERS_CONCURRENCY_DIR, never to storage/.
 *
 * Usage: php asset-import-worker.php <command> [arguments]
 *   setup                              super user, category, model, status
 *   session <csv-path>                 upload, configure, map, validate; prints "<id> <token>"
 *   execute <id> <token> [sleep-ms]    import; optional pause after the first new asset
 *   native-insert <tag> [delay-ms]     check-then-insert of one asset, autocommit
 *   count <tag>                        rows equal to <tag> under the column collation
 *   count-prefix <prefix>              rows whose tag starts with <prefix>
 */

use App\Actions\AssetImport\ConfigureAssetImportTarget;
use App\Actions\AssetImport\CreateAssetImportSession;
use App\Actions\AssetImport\ExecuteAssetImport;
use App\Actions\AssetImport\SaveAssetImportMapping;
use App\Actions\AssetImport\ValidateAssetImportSession;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\ConcurrencyDatabaseGuardException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

// Same guard as prepare-database.php, before anything reads or writes.
try {
    ConcurrencyDatabaseGuard::bootstrapVerified($app);
} catch (Throwable $refused) {
    fwrite(STDERR, ($refused instanceof ConcurrencyDatabaseGuardException ? $refused->getMessage() : 'The guard could not verify the database.')."\n");
    exit(3);
}

$dir = getenv('ERS_CONCURRENCY_DIR');
if (! is_string($dir) || ! is_dir($dir)) {
    fwrite(STDERR, "Refusing to run: ERS_CONCURRENCY_DIR is missing.\n");
    exit(2);
}
config([
    'filesystems.disks.ers_concurrency' => ['driver' => 'local', 'root' => $dir.'/files'],
    'asset_import.disk' => 'ers_concurrency',
]);

$stateFile = $dir.'/state.json';
$state = fn () => json_decode((string) file_get_contents($stateFile), true);
$login = function () use ($state): User {
    $user = User::query()->findOrFail($state()['user']);
    Auth::setUser($user);

    return $user;
};

switch ($argv[1] ?? '') {
    case 'setup':
        Setting::factory()->create();
        $user = User::factory()->superuser()->create();
        Auth::setUser($user);
        $category = Category::factory()->create(['name' => 'Concurrency Laptops', 'category_type' => 'asset']);
        $model = AssetModel::factory()->create(['category_id' => $category->id, 'name' => 'Concurrency Model']);
        $status = Statuslabel::factory()->rtd()->create(['name' => 'Concurrency Ready']);
        file_put_contents($stateFile, json_encode(['user' => $user->id, 'category' => $category->id, 'model' => $model->id, 'status' => $status->id]));
        echo "ok\n";
        break;

    case 'session':
        $user = $login();
        $ids = $state();
        $session = app(CreateAssetImportSession::class)->handle($user, new UploadedFile($argv[2], 'concurrency.csv', 'text/csv', null, true));
        app(ConfigureAssetImportTarget::class)->handle($user, $session->public_id, [
            'category_id' => $ids['category'], 'model_source' => 'fixed', 'model_id' => $ids['model'],
            'status_source' => 'fixed', 'status_id' => $ids['status'], 'company_source' => 'none', 'location_source' => 'none',
        ]);
        app(SaveAssetImportMapping::class)->handle($user, $session->public_id, [0 => 'standard:asset_tag']);
        [$session, $problem, $result] = app(ValidateAssetImportSession::class)->handle($user, $session->public_id);
        if ($problem !== null || ! $result->isEligible()) {
            fwrite(STDERR, "The fixture session did not validate.\n");
            exit(1);
        }
        echo $session->public_id.' '.ExecuteAssetImport::confirmationToken($session->fresh())."\n";
        break;

    case 'execute':
        $user = $login();
        $sleep = (int) ($argv[4] ?? 0);
        if ($sleep > 0) {
            $first = true;
            Asset::created(function () use (&$first, $sleep) {
                if ($first) {
                    $first = false;
                    usleep($sleep * 1000);
                }
            });
        }
        try {
            $outcome = app(ExecuteAssetImport::class)->handle($user, $argv[2], $argv[3]);
            echo json_encode(['status' => $outcome->status, 'created' => count($outcome->assetIds), 'errors' => $outcome->session?->fresh()?->execution_errors])."\n";
        } catch (NotFoundHttpException) {
            echo json_encode(['status' => 'unavailable', 'created' => 0, 'errors' => null])."\n";
        }
        break;

    case 'native-insert':
        usleep(((int) ($argv[3] ?? 0)) * 1000);
        $ids = $state();
        // Like Snipe-IT's unique_undeleted followed by the insert.
        if (! DB::table('assets')->where('asset_tag', $argv[2])->whereNull('deleted_at')->exists()) {
            DB::table('assets')->insert(['asset_tag' => $argv[2], 'model_id' => $ids['model'], 'status_id' => $ids['status'], 'created_at' => now(), 'updated_at' => now()]);
            echo "inserted\n";
        } else {
            echo "exists\n";
        }
        break;

    case 'count':
        echo DB::table('assets')->where('asset_tag', $argv[2])->count()."\n";
        break;

    case 'count-prefix':
        echo DB::table('assets')->where('asset_tag', 'like', $argv[2].'%')->count()."\n";
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
