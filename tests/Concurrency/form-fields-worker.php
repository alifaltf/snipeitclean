<?php

/*
 * ERS Phase 7C: worker for AssetFormFieldsMariaDbTest.
 *
 * Starts Laravel only through ConcurrencyDatabaseGuard (dedicated
 * *_concurrency_test MySQL/MariaDB database, verified first), then either
 * builds the fixture or runs one step as the fixture's Super Admin, and
 * prints one JSON line. Never prints credentials or stored secrets.
 */

use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetFields\AssetCustomFieldEligibility;
use App\Services\AssetImport\AssetImportFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
$stateFile = $dir.'/form-fields.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];

/** Stored custom values of an asset (plain fields only). */
$stored = function (int $assetId) use (&$state): array {
    $row = (array) DB::table('assets')->where('id', $assetId)->first();

    return ['model_id' => (int) $row['model_id'], 'name' => $row['name'], 'plain' => $row[$state['plain']] ?? null, 'other' => $row[$state['other']] ?? null, 'box' => $row[$state['box']] ?? null];
};

switch ($argv[1] ?? '') {
    case 'setup':
        Setting::factory()->create();
        $super = User::factory()->superuser()->create();
        Auth::setUser($super);
        $category = Category::factory()->assignableAssetCategory()->create(['name' => 'Fields '.bin2hex(random_bytes(3))]);
        $plain = CustomField::factory()->create(['name' => 'Plain '.bin2hex(random_bytes(3)), 'format' => '']);
        $other = CustomField::factory()->create(['name' => 'Other '.bin2hex(random_bytes(3)), 'format' => '']);
        $box = CustomField::factory()->create(['name' => 'Box '.bin2hex(random_bytes(3)), 'format' => '']);
        $one = CustomFieldset::factory()->create(['name' => 'One '.bin2hex(random_bytes(3))]);
        $one->fields()->attach($plain->id, ['order' => 1, 'required' => 0]);
        $one->fields()->attach($box->id, ['order' => 2, 'required' => 0]);
        $two = CustomFieldset::factory()->create(['name' => 'Two '.bin2hex(random_bytes(3))]);
        $two->fields()->attach($other->id, ['order' => 1, 'required' => 1]);
        $two->fields()->attach($plain->id, ['order' => 2, 'required' => 0]);
        $modelOne = AssetModel::factory()->create(['category_id' => $category->id, 'fieldset_id' => $one->id]);
        $modelTwo = AssetModel::factory()->create(['category_id' => $category->id, 'fieldset_id' => $two->id]);
        $status = Statuslabel::factory()->rtd()->create();
        $assets = [];
        foreach (['F-1', 'F-2'] as $tag) {
            $asset = Asset::factory()->create(['model_id' => $modelOne->id, 'status_id' => $status->id, 'asset_tag' => $tag, 'name' => 'Before']);
            DB::table('assets')->where('id', $asset->id)->update([$plain->fresh()->db_column => 'p-'.$tag, $box->fresh()->db_column => 'b-'.$tag]);
            $assets[] = $asset->id;
        }
        // A broken field row (not a native custom-field column) in fieldset one.
        $broken = CustomField::factory()->create(['name' => 'Broken '.bin2hex(random_bytes(3)), 'format' => '']);
        DB::table('custom_fields')->where('id', $broken->id)->update(['db_column' => 'notes']);
        $one->fields()->attach($broken->id, ['order' => 3, 'required' => 0]);
        file_put_contents($stateFile, json_encode([
            'user' => $super->id, 'category' => $category->id, 'one' => $modelOne->id, 'two' => $modelTwo->id, 'status' => $status->id,
            'plain' => $plain->fresh()->db_column, 'other' => $other->fresh()->db_column, 'box' => $box->fresh()->db_column,
            'plain_id' => $plain->id, 'other_id' => $other->id, 'box_id' => $box->id, 'broken_id' => $broken->id, 'assets' => $assets,
        ]));
        echo json_encode(['ok' => true])."\n";
        break;

    case 'eligibility':
        $user = User::query()->findOrFail($state['user']);
        Auth::setUser($user);
        $session = new AssetImportSession;
        $session->forceFill(['category_id' => $state['category'], 'model_source' => AssetImportSession::SOURCE_FIXED, 'model_id' => $state['one'], 'status_source' => AssetImportSession::SOURCE_FIXED, 'company_source' => AssetImportSession::SOURCE_NONE, 'location_source' => AssetImportSession::SOURCE_NONE]);
        $column = (clone $session)->forceFill(['model_source' => AssetImportSession::SOURCE_COLUMN, 'model_id' => null]);
        $catalog = app(AssetImportFieldCatalog::class);
        $custom = fn (array $keys) => array_values(array_filter($keys, fn ($key) => str_starts_with($key, 'custom_field:')));
        echo json_encode([
            'form_one' => app(AssetCustomFieldEligibility::class)->columnsFor(AssetModel::query()->find($state['one'])),
            'form_two' => app(AssetCustomFieldEligibility::class)->columnsFor(AssetModel::query()->find($state['two'])),
            'csv_fixed' => $custom(array_keys($catalog->destinations($session, $user))),
            'csv_column' => $custom(array_keys($catalog->destinations($column, $user))),
            'layout' => array_map('intval', app(AssetTableLayoutService::class)->eligibleCustomFields((int) $state['category'])->keys()->all()),
        ])."\n";
        break;

    case 'update': // update <asset index> <payload JSON>
        $user = User::query()->findOrFail($state['user']);
        $assetId = (int) $state['assets'][(int) ($argv[2] ?? 0)];
        $asset = Asset::query()->findOrFail($assetId);
        Auth::guard('web')->setUser($user);
        $payload = array_merge(['asset_tags' => [1 => $asset->asset_tag], 'model_id' => $asset->model_id, 'status_id' => $asset->status_id, 'name' => $asset->name], json_decode($argv[3] ?? '{}', true) ?: []);
        $request = Request::create(route('hardware.update', $asset), 'PUT', $payload);
        $request->setLaravelSession($app->make('session')->driver());
        $response = $app->make(HttpKernel::class)->handle($request);
        $errors = $request->session()->get('errors');
        echo json_encode(['status' => $response->getStatusCode(), 'errors' => $errors ? array_keys($errors->getMessages()) : [], 'stored' => $stored($assetId)])."\n";
        break;

    case 'bulk': // bulk <payload JSON>
        $user = User::query()->findOrFail($state['user']);
        Auth::guard('web')->setUser($user);
        $request = Request::create(route('hardware/bulksave'), 'POST', array_merge(['ids' => $state['assets']], json_decode($argv[2] ?? '{}', true) ?: []));
        $request->setLaravelSession($app->make('session')->driver());
        $response = $app->make(HttpKernel::class)->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'error' => $request->session()->get('error'), 'bulk_errors' => array_keys((array) $request->session()->get('bulk_asset_errors')), 'stored' => array_map($stored, $state['assets'])])."\n";
        break;

    case 'state':
        echo json_encode($state)."\n";
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
