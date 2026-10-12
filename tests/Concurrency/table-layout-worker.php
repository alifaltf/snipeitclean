<?php

/*
 * ERS Phase 7A: one worker process for CategoryTableLayoutConcurrencyTest.
 *
 * Starts Laravel only through ConcurrencyDatabaseGuard (dedicated
 * *_concurrency_test MySQL/MariaDB database, verified before anything
 * runs), performs one action through AssetTableLayoutService and prints
 * one JSON line.
 *
 * With ERS_BARRIER_IN_SAVE=<name>, a save stops right after it has locked
 * the category and deleted the old layout (transaction open), creates
 * <name>.ready in ERS_CONCURRENCY_DIR and waits for <name>.go.
 */

use App\Models\Category;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

$dir = (string) getenv('ERS_CONCURRENCY_DIR');
$out = function (array $data): void {
    echo json_encode($data)."\n";
};

$barrier = (string) getenv('ERS_BARRIER_IN_SAVE');
if ($barrier !== '') {
    $reached = false;
    DB::listen(function (QueryExecuted $query) use (&$reached, $barrier, $dir) {
        if ($reached || ! str_starts_with(strtolower($query->sql), 'delete from `asset_category_columns`')) {
            return;
        }
        $reached = true;
        touch($dir.'/'.$barrier.'.ready');
        $deadline = microtime(true) + 60;
        while (! file_exists($dir.'/'.$barrier.'.go')) {
            if (microtime(true) > $deadline) {
                fwrite(STDERR, "Barrier timed out.\n");
                exit(4);
            }
            usleep(10_000);
        }
    });
}

$layouts = app(AssetTableLayoutService::class);

switch ($argv[1] ?? '') {
    case 'category': // category  (a new final asset category)
        $category = Category::factory()->assignableAssetCategory()->create(['name' => 'Concurrency layout '.bin2hex(random_bytes(4))]);
        $out(['id' => $category->id]);
        break;

    case 'save': // save <category id> <key,key,...>
        try {
            $keys = $layouts->save(Category::query()->findOrFail((int) $argv[2]), explode(',', $argv[3]));
            $out(['saved' => $keys]);
        } catch (ValidationException $exception) {
            $out(['saved' => null, 'errors' => $exception->errors()]);
        }
        break;

    case 'reset': // reset <category id>
        $layouts->reset(Category::query()->findOrFail((int) $argv[2]));
        $out(['reset' => true]);
        break;

    case 'rows': // rows <category id>
        $out(['rows' => DB::table('asset_category_columns')->where('category_id', (int) $argv[2])->orderBy('position')->get(['field_key', 'position'])->map(fn ($row) => [$row->field_key, (int) $row->position])->all()]);
        break;

    case 'sql': // sql <statement>  (never prints the error: it contains values)
        try {
            DB::statement($argv[2]);
            $out(['ok' => true, 'sqlstate' => null]);
        } catch (Illuminate\Database\QueryException $exception) {
            $out(['ok' => false, 'sqlstate' => $exception->errorInfo[0] ?? null, 'code' => $exception->errorInfo[1] ?? null]);
        }
        break;

    case 'artisan': // artisan "<command and options>"
        $parts = preg_split('/\s+/', trim($argv[2]));
        $parameters = ['command' => array_shift($parts)];
        foreach ($parts as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, true);
            $parameters[$name] = $value;
        }
        $input = new ArrayInput($parameters);
        $input->setInteractive(false);
        $buffer = new BufferedOutput;
        $status = $app->make(ConsoleKernel::class)->handle($input, $buffer);
        $out(['exit' => $status, 'output' => mb_substr($buffer->fetch(), 0, 4000)]);
        break;

    case 'schema': // schema  (columns, indexes and foreign keys of the layout table)
        $out([
            'table' => DB::getSchemaBuilder()->hasTable('asset_category_columns'),
            'indexes' => DB::getSchemaBuilder()->hasTable('asset_category_columns') ? collect(DB::getSchemaBuilder()->getIndexes('asset_category_columns'))->mapWithKeys(fn ($i) => [$i['name'] => ['columns' => $i['columns'], 'unique' => $i['unique']]])->all() : [],
            'foreign' => DB::getSchemaBuilder()->hasTable('asset_category_columns') ? collect(DB::getSchemaBuilder()->getForeignKeys('asset_category_columns'))->map(fn ($k) => [$k['columns'], $k['foreign_table'], strtolower((string) $k['on_delete'])])->all() : [],
        ]);
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
