<?php

namespace Tests\Feature\Assets\Import;

use App\Actions\AssetImport\ExecuteAssetImport;
use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\Execution\AssetImportExecutionOutcome;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6C: on MySQL/MariaDB, uniqueness follows the database
 * collation (utf8mb4_unicode_ci by default: case-, accent- and
 * trailing-space-insensitive, with expansions such as ß = ss), exactly like
 * Snipe-IT's own unique_undeleted rule. Only meaningful on those drivers;
 * skipped elsewhere. Run with DB_CONNECTION=mysql against a disposable
 * test database.
 */
class AssetImportCollationTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Collation behaviour needs MySQL/MariaDB.');
        }
        $this->setUpImportFixture();
    }

    private function msg(string $key, array $replace = []): string
    {
        return trans('admin/hardware/import.validation.'.$key, $replace);
    }

    private function execute(User $user, AssetImportSession $session): AssetImportExecutionOutcome
    {
        $this->actingAs($user, 'web');

        return app(ExecuteAssetImport::class)->handle($user, $session->public_id, ExecuteAssetImport::confirmationToken($session->fresh()));
    }

    #[Test]
    public function the_database_collation_is_the_one_under_test(): void
    {
        $collation = DB::selectOne("SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'asset_tag'")->c;
        $this->assertSame('utf8mb4_unicode_ci', $collation);
        $this->assertSame(1, (int) DB::selectOne("SELECT 'Àbc ' = 'abc' AS same")->same);
    }

    #[Test]
    public function existing_tags_and_serials_match_like_the_database_compares_them(): void
    {
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'abc', 'serial' => 'strasse-1']);
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'pad-1 ', 'serial' => 'masse-9']);
        $gone = $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'Öko', 'serial' => 'DEL-1']);
        $gone->delete();
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Serial Number\nÀBC,STRASSE-1\noko,x1\nother-1,MAßE-9\nother-2,dél-1\nPAD-1,x3\nfresh,x4\n", [], [0 => 'standard:asset_tag', 1 => 'standard:serial']);

        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame(['standard:asset_tag: '.$this->msg('asset_tag_exists'), 'standard:serial: '.$this->msg('serial_exists')], $errors[2], 'Accent and case.');
        $this->assertSame(['standard:asset_tag: '.$this->msg('asset_tag_exists')], $errors[3], 'Accent, deleted asset.');
        $this->assertSame(['standard:serial: '.$this->msg('serial_exists')], $errors[4], 'ß = ss expansion.');
        $this->assertSame(['standard:serial: '.$this->msg('serial_exists')], $errors[5], 'Deleted serial, accent.');
        $this->assertSame(['standard:asset_tag: '.$this->msg('asset_tag_exists')], $errors[6], 'Stored with a trailing space (PAD SPACE).');
        $this->assertSame([], $errors[7]);
    }

    #[Test]
    public function in_file_duplicates_match_like_the_database_compares_them(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag\nresume\nRÉSUMÉ\nmasse\nmaße\nunique-1\n", [], [0 => 'standard:asset_tag']);

        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame(['standard:asset_tag: '.$this->msg('duplicate_in_file', ['rows' => '2, 3'])], $errors[2]);
        $this->assertSame($errors[2], $errors[3]);
        $this->assertSame(['standard:asset_tag: '.$this->msg('duplicate_in_file', ['rows' => '4, 5'])], $errors[4]);
        $this->assertSame([], $errors[6]);
    }

    #[Test]
    public function unique_custom_fields_match_like_the_database_compares_them(): void
    {
        $user = $this->importer();
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Licence', 'is_unique' => 1]);
        $this->asset['leaf1']->forceFill([$field->db_column_name() => 'Café-1'])->saveQuietly();
        $session = $this->configuredSession($user, "Asset Tag,Licence\nT-1,CAFE-1\nT-2,lic-2\nT-3,LÏC-2\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);

        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('custom_field_exists')], $errors[2]);
        $this->assertSame(['custom_field:'.$field->id.': '.$this->msg('duplicate_in_file', ['rows' => '3, 4'])], $errors[3]);
    }

    #[Test]
    public function execution_never_creates_a_collation_duplicate(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag\nCOL-1\nCOL-2\n", [], [0 => 'standard:asset_tag']);
        $this->postValidate($user, $session);
        // Added after validation by another writer, differing only by
        // accent and case, as a deleted asset (Snipe-IT's own rule ignores
        // deleted assets; the ERS rule does not).
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'çol-2'])->delete();
        $before = Asset::withoutGlobalScopes()->withTrashed()->count();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::REVALIDATION_FAILED, $outcome->status);
        $this->assertSame($before, Asset::withoutGlobalScopes()->withTrashed()->count());
    }

    #[Test]
    public function the_post_insert_check_compares_with_the_database_collation(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag\nPI-1\nPI-2\n", [], [0 => 'standard:asset_tag']);
        $this->postValidate($user, $session);
        // Inserted while the import runs (after its validation): a deleted
        // asset whose tag equals PI-2 only under the collation.
        Asset::created(function (Asset $asset) {
            if ($asset->asset_tag === 'PI-1') {
                DB::table('assets')->insert(['asset_tag' => 'pí-2 ', 'model_id' => $asset->model_id, 'status_id' => $asset->status_id, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $before = Asset::withoutGlobalScopes()->withTrashed()->count();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $outcome->status);
        $this->assertSame($before, Asset::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(3, $session->fresh()->execution_errors[0]['row']);
    }

    #[Test]
    public function a_valid_import_commits_on_the_real_database(): void
    {
        $user = $this->importer();
        $notes = "Multi\nline ünïcödé";
        $session = $this->configuredSession($user, "Asset Tag,Notes\nOK-Ä1,\"$notes\"\nOK-Ä2,\n", [], [0 => 'standard:asset_tag', 1 => 'standard:notes']);
        $this->postValidate($user, $session);

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $outcome->status);
        $this->assertSame($notes, Asset::withoutGlobalScopes()->where('asset_tag', 'OK-Ä1')->value('notes'));
        $this->assertSame(AssetImportSession::STATE_COMPLETED, $session->fresh()->state);
    }
}
