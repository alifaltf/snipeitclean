<?php

/*
 * ERS: one worker process for AssetUniquenessEnforcementTest.
 *
 * Starts Laravel only through ConcurrencyDatabaseGuard (dedicated
 * *_concurrency_test MySQL/MariaDB database, verified before anything
 * runs), then performs one action through the same code a user's request
 * would use, and prints one JSON line. Never prints values from errors.
 *
 * Fixtures (user, category, model, status) come from
 * "asset-import-worker.php setup" (state.json in ERS_CONCURRENCY_DIR).
 */

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetUniqueness\AssetRestorer;
use App\Services\AssetUniqueness\AssetUniquenessException;
use App\Services\AssetUniqueness\AssetUniquenessSchema;
use App\Services\AssetUniqueness\AssetUniquenessTransitions;
use App\Services\AssetUniqueness\UniqueConstraintViolation;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\ConcurrencyDatabaseGuardException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

try {
    ConcurrencyDatabaseGuard::bootstrapVerified($app);
} catch (Throwable $refused) {
    fwrite(STDERR, ($refused instanceof ConcurrencyDatabaseGuardException ? $refused->getMessage() : 'The guard could not verify the database.')."\n");
    exit(3);
}

// Lets a test make a schema change give up quickly while another
// transaction holds the table (a real DDL failure, not a simulated one).
if (($timeout = (int) getenv('ERS_TEST_LOCK_WAIT_TIMEOUT')) > 0) {
    DB::statement('SET SESSION lock_wait_timeout = '.$timeout);
}

$dir = (string) getenv('ERS_CONCURRENCY_DIR');
$state = json_decode((string) @file_get_contents($dir.'/state.json'), true) ?: [];
$out = fn (array $data) => print json_encode($data)."\n";
$user = fn () => User::query()->findOrFail($state['user']);

/** Send a request through the HTTP kernel as the fixture user; returns [status, error keys, flash kinds]. */
$http = function (string $method, string $uri, array $data, bool $api) use ($app, $user): array {
    $actor = $user();
    if ($api) {
        Passport::actingAs($actor);
        $request = Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($data));
    } else {
        Auth::guard('web')->setUser($actor);
        $request = Request::create($uri, $method, $data);
    }
    $response = $app->make(HttpKernel::class)->handle($request);
    $json = json_decode((string) $response->getContent(), true);
    $errors = [];
    if ($api && is_array($json) && ($json['status'] ?? null) === 'error') {
        $messages = $json['messages'] ?? null;
        $errors = is_array($messages) ? array_keys($messages) : ($messages === trans('admin/hardware/uniqueness.busy') ? ['busy'] : ['general']);
    }
    $flash = [];
    if (! $api && $app->bound('session.store')) {
        $session = $app['session.store'];
        if (($bag = $session->get('errors')) !== null) {
            $errors = $bag->getBag('default')->keys();
        }
        $flash = array_values(array_filter(['success', 'error'], fn ($kind) => $session->has($kind)));
        if ($session->get('error') === trans('admin/hardware/uniqueness.busy')) {
            $flash[] = 'busy';
        }
    }

    return [$response->getStatusCode(), $errors, $flash];
};

$violationOf = fn (Throwable $e) => UniqueConstraintViolation::detect($e)?->attribute();

/*
 * Barriers for deterministic interleavings: with ERS_BARRIER_<POINT>=<name>
 * the worker creates <name>.ready in ERS_CONCURRENCY_DIR when it reaches
 * that point and waits until the test creates <name>.go.
 *   AFTER_LOAD: a custom field was loaded, before the request's change is
 *               applied and saved (the request works from that snapshot);
 *   IN_SAVE:    inside a custom-field save, after its metadata was written
 *               and before the schema lock is released.
 * ERS_FAIL_AFTER_PERSIST=1 throws right after a custom field's metadata
 * was written (the save then fails with its change persisted).
 */
$barrier = function (string $point) use ($dir): void {
    $name = (string) getenv('ERS_BARRIER_'.$point);
    if ($name === '') {
        return;
    }
    touch($dir.'/'.$name.'.ready');
    $deadline = microtime(true) + 60;
    while (! file_exists($dir.'/'.$name.'.go')) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "Barrier timed out.\n");
            exit(4);
        }
        usleep(10_000);
    }
};
new CustomField; // boot the model first: these listeners run after its own
CustomField::updated(function () use ($barrier) {
    if (getenv('ERS_FAIL_AFTER_PERSIST') === '1') {
        throw new RuntimeException('Injected failure after the field metadata was written.');
    }
    $barrier('IN_SAVE');
});

switch ($argv[1] ?? '') {
    case 'web-create': // web-create <tag> [serial]
        [$status, $errors, $flash] = $http('POST', route('hardware.store'), [
            'model_id' => $state['model'], 'status_id' => $state['status'],
            'asset_tags' => [1 => $argv[2]], 'serials' => [1 => $argv[3] ?? ''],
        ], false);
        $out(['http' => $status, 'errors' => $errors, 'flash' => $flash]);
        break;

    case 'api-create': // api-create <tag> [serial] [column=value ...]
        $extra = [];
        foreach (array_slice($argv, 4) as $pair) {
            [$column, $value] = explode('=', $pair, 2);
            $extra[$column] = $value;
        }
        [$status, $errors] = $http('POST', '/api/v1/hardware', array_filter([
            'model_id' => $state['model'], 'status_id' => $state['status'], 'asset_tag' => $argv[2], 'serial' => ($argv[3] ?? '') === '' ? null : $argv[3],
        ], fn ($v) => $v !== null) + $extra, true);
        $out(['http' => $status, 'errors' => $errors]);
        break;

    case 'api-update': // api-update <id> <tag>
        [$status, $errors] = $http('PATCH', '/api/v1/hardware/'.(int) $argv[2], ['asset_tag' => $argv[3]], true);
        $out(['http' => $status, 'errors' => $errors]);
        break;

    case 'web-restore': // web-restore <id>
        [$status, $errors, $flash] = $http('POST', route('restore/hardware', (int) $argv[2]), [], false);
        $out(['http' => $status, 'errors' => $errors, 'flash' => $flash]);
        break;

    case 'api-restore': // api-restore <id>
        [$status, $errors] = $http('POST', '/api/v1/hardware/'.(int) $argv[2].'/restore', [], true);
        $out(['http' => $status, 'errors' => $errors]);
        break;

    case 'bulk-restore': // bulk-restore <id,id,...>
        [$status, $errors, $flash] = $http('POST', route('hardware/bulkrestore'), ['ids' => array_map('intval', explode(',', $argv[2]))], false);
        $out(['http' => $status, 'errors' => $errors, 'flash' => $flash]);
        break;

    case 'hold': // hold <ms> <commit|rollback> <sql>  (one statement in a transaction held open)
        DB::beginTransaction();
        try {
            DB::statement($argv[4]);
            usleep((int) $argv[2] * 1000);
            $argv[3] === 'commit' ? DB::commit() : DB::rollBack();
            $out(['result' => $argv[3]]);
        } catch (Throwable $e) {
            DB::rollBack();
            $out(['result' => 'error', 'violation' => $violationOf($e)]);
        }
        break;

    case 'sql': // sql <statement>  (returns rows for SELECT)
        try {
            $rows = str_starts_with(strtoupper(ltrim($argv[2])), 'SELECT') ? DB::select($argv[2]) : (DB::statement($argv[2]) ? [] : []);
            $out(['ok' => true, 'rows' => $rows]);
        } catch (Throwable $e) {
            $out(['ok' => false, 'violation' => $violationOf($e), 'duplicate' => UniqueConstraintViolation::isDuplicateKey($e)]);
        }
        break;

    case 'serial': // serial <on|off> [fail-save]
        Auth::setUser($user());
        $setting = Setting::query()->orderBy('id')->firstOrFail();
        $setting->unique_serial = $argv[2] === 'on' ? 1 : 0;
        $failSave = ($argv[3] ?? '') === 'fail-save';
        try {
            $result = app(AssetUniquenessTransitions::class)->saveSettings($setting, fn () => $failSave ? false : $setting->save());
            $out($result + ['error' => null]);
        } catch (AssetUniquenessException $e) {
            $out(['saved' => false, 'warning' => null, 'error' => $e->reason]);
        } catch (Throwable $e) {
            $out(['saved' => false, 'warning' => null, 'error' => 'exception', 'duplicate' => UniqueConstraintViolation::isDuplicateKey($e)]);
        }
        break;

    case 'field-create': // field-create <name> <unique> [encrypted]
        Auth::setUser($user());
        $field = new CustomField(['name' => $argv[2], 'element' => 'text', 'format' => 'ANY', 'is_unique' => (int) $argv[3], 'field_encrypted' => (int) ($argv[4] ?? 0)]);
        try {
            $saved = $field->save();
            $out(['saved' => $saved, 'id' => $field->id, 'db_column' => $field->db_column, 'errors' => $saved ? [] : $field->getErrors()->keys()]);
        } catch (AssetUniquenessException $e) {
            $out(['saved' => false, 'error' => $e->reason, 'exists' => CustomField::query()->where('name', $argv[2])->exists()]);
        }
        break;

    case 'fieldset': // fieldset <field id>  (adds the field to the fixture model's fieldset)
        Auth::setUser($user());
        $model = AssetModel::query()->findOrFail($state['model']);
        $fieldset = $model->fieldset ?? CustomFieldset::query()->create(['name' => 'Concurrency fieldset '.bin2hex(random_bytes(3))]);
        $fieldset->fields()->syncWithoutDetaching([(int) $argv[2] => ['order' => 1, 'required' => 0]]);
        $model->fieldset_id = $fieldset->id;
        $model->save();
        $out(['fieldset' => $fieldset->id]);
        break;

    case 'field-update': // field-update <id> <attribute> <value>
        Auth::setUser($user());
        $field = CustomField::query()->findOrFail((int) $argv[2]);
        $barrier('AFTER_LOAD');
        $field->{$argv[3]} = $argv[4];
        try {
            $saved = $field->save();
            $out(['saved' => $saved, 'errors' => $saved ? [] : $field->getErrors()->keys(), 'db_column' => $field->fresh()?->db_column]);
        } catch (AssetUniquenessException $e) {
            $out(['saved' => false, 'error' => $e->reason]);
        } catch (Throwable $e) {
            $out(['saved' => false, 'error' => 'exception']);
        }
        break;

    case 'field-delete': // field-delete <id>
        Auth::setUser($user());
        try {
            $field = CustomField::query()->findOrFail((int) $argv[2]);
            $barrier('AFTER_LOAD');
            $out(['deleted' => (bool) $field->delete()]);
        } catch (Throwable $e) {
            $out(['deleted' => false, 'error' => $e::class]);
        }
        break;

    case 'restore': // restore <id>
        Auth::setUser($user());
        $asset = Asset::withTrashed()->findOrFail((int) $argv[2]);
        $errors = app(AssetRestorer::class)->restore($asset);
        $out(['restored' => $errors === null, 'fields' => array_keys($errors ?? [])]);
        break;

    case 'status':
        $out(app(AssetUniquenessSchema::class)->status());
        break;

    case 'repair':
        try {
            $out(['report' => app(AssetUniquenessSchema::class)->reconcile(preflight: true), 'error' => null]);
        } catch (AssetUniquenessException $e) {
            $out(['report' => null, 'error' => $e->reason, 'conflicts' => array_values($e->conflicts)]);
        }
        break;

    case 'artisan': // artisan <command> [answer]  (e.g. "migrate" or "migrate:rollback --step=1")
        $parts = preg_split('/\s+/', trim($argv[2]));
        $parameters = ['command' => array_shift($parts)];
        foreach ($parts as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, true);
            $parameters[$name] = $value;
        }
        $input = new ArrayInput($parameters);
        if (isset($argv[3])) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $argv[3]."\n");
            rewind($stream);
            $input->setStream($stream);
            $input->setInteractive(true);
        } else {
            $input->setInteractive(false);
        }
        $buffer = new BufferedOutput;
        $status = $app->make(ConsoleKernel::class)->handle($input, $buffer);
        $out(['exit' => $status, 'output' => mb_substr($buffer->fetch(), 0, 4000)]);
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
