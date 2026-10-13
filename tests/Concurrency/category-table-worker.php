<?php

/*
 * ERS Phase 7B: worker for CategoryAssetTableMariaDbTest.
 *
 * Starts Laravel only through ConcurrencyDatabaseGuard (dedicated
 * *_concurrency_test MySQL/MariaDB database, verified first), then either
 * builds the fixture or sends one request through the HTTP kernel as the
 * fixture user, and prints one JSON line.
 */

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
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
$stateFile = $dir.'/category-table.json';

switch ($argv[1] ?? '') {
    case 'setup':
        Setting::factory()->create();
        $super = User::factory()->superuser()->create();
        Auth::setUser($super);
        $category = Category::factory()->assignableAssetCategory()->create(['name' => 'Table '.bin2hex(random_bytes(3))]);
        $plain = CustomField::factory()->create(['name' => 'Plain '.bin2hex(random_bytes(3)), 'format' => '']);
        $number = CustomField::factory()->create(['name' => 'Number '.bin2hex(random_bytes(3)), 'format' => 'NUMERIC']);
        $secret = CustomField::factory()->create(['name' => 'Secret '.bin2hex(random_bytes(3)), 'format' => '', 'field_encrypted' => 1]);
        $fieldset = CustomFieldset::factory()->create(['name' => 'Set '.bin2hex(random_bytes(3))]);
        foreach ([$plain, $number, $secret] as $order => $field) {
            $fieldset->fields()->attach($field->id, ['order' => $order + 1, 'required' => 0]);
        }
        $model = AssetModel::factory()->create(['category_id' => $category->id, 'fieldset_id' => $fieldset->id]);
        $status = Statuslabel::factory()->rtd()->create();
        [$plain, $number, $secret] = [$plain->fresh(), $number->fresh(), $secret->fresh()];
        foreach ([['T-B', 'Éclair_one', '10', 'S1'], ['T-A', 'apple 100%', '9', 'S2'], ['T-C', 'CHERRY', '100', 'S3']] as [$tag, $text, $num, $sec]) {
            $asset = Asset::factory()->create(['model_id' => $model->id, 'status_id' => $status->id, 'asset_tag' => $tag]);
            DB::table('assets')->where('id', $asset->id)->update([$plain->db_column => $text, $number->db_column => $num, $secret->db_column => Crypt::encrypt($sec)]);
        }
        // A leftover value from a model whose fieldset lacks the field (stale).
        $bare = AssetModel::factory()->create(['category_id' => $category->id, 'fieldset_id' => null]);
        $stale = Asset::factory()->create(['model_id' => $bare->id, 'status_id' => $status->id, 'asset_tag' => 'T-S']);
        DB::table('assets')->where('id', $stale->id)->update([$plain->db_column => 'ZZZ stale', $number->db_column => '1']);
        app(AssetTableLayoutService::class)->save($category, ['standard:asset_tag', 'custom_field:'.$plain->id, 'custom_field:'.$number->id, 'custom_field:'.$secret->id, 'standard:status', 'standard:model']);
        file_put_contents($stateFile, json_encode(['user' => $super->id, 'category' => $category->id, 'plain' => $plain->db_column, 'number' => $number->db_column, 'secret' => $secret->db_column]));
        echo json_encode(['ok' => true])."\n";
        break;

    case 'api': // api <query JSON>
    case 'export':
        $state = json_decode((string) file_get_contents($stateFile), true);
        $user = User::query()->findOrFail($state['user']);
        $query = array_merge(['asset_category' => $state['category']], json_decode($argv[2] ?? '{}', true) ?: []);
        if ($argv[1] === 'api') {
            Passport::actingAs($user);
            $request = Request::create('/api/v1/hardware', 'GET', $query + ['asset_table_layout' => '1'], [], [], ['HTTP_ACCEPT' => 'application/json']);
        } else {
            Auth::guard('web')->setUser($user);
            $request = Request::create(route('hardware.category-export', $query), 'GET');
        }
        $response = $app->make(HttpKernel::class)->handle($request);
        if ($argv[1] === 'api') {
            $json = json_decode((string) $response->getContent(), true);
            echo json_encode(['status' => $response->getStatusCode(), 'total' => $json['total'] ?? null, 'tags' => array_map(fn ($row) => $row['asset_tag'], $json['rows'] ?? []), 'fields' => array_column($json['layout']['columns'] ?? [], 'field')])."\n";
        } else {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
            echo json_encode(['status' => $response->getStatusCode(), 'csv' => base64_encode($body)])."\n";
        }
        break;

    case 'state':
        echo file_get_contents($stateFile)."\n";
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
