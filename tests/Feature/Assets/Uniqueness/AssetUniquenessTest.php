<?php

namespace Tests\Feature\Assets\Uniqueness;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Import;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetUniqueness\ConcurrentWriteConflict;
use App\Services\AssetUniqueness\UniqueConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Importing\AssetsImportFileBuilder as ImportFileBuilder;
use Tests\Support\Importing\CleansUpImportFiles;
use Tests\TestCase;

/**
 * ERS: application side of database-enforced asset uniqueness, on the
 * test database (SQLite, where the database keys themselves are not
 * created; their behaviour is covered against MariaDB in
 * tests/Concurrency/AssetUniquenessEnforcementTest).
 */
class AssetUniquenessTest extends TestCase
{
    use CleansUpImportFiles;

    /**
     * On MySQL/MariaDB, making a field unique creates a database key, a
     * schema change that is refused inside the test's transaction (and
     * would commit it). Those paths are tested in tests/Concurrency.
     */
    private function requiresSchemaChangesOutsideTheTestTransaction(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Creates unique custom fields; covered on MySQL/MariaDB by tests/Concurrency/AssetUniquenessEnforcementTest.');
        }
    }

    private static function duplicateKey(string $driverMessage, int $code = 1062, string $state = '23000'): QueryException
    {
        $pdo = new PDOException('SQLSTATE['.$state.']: Integrity constraint violation: '.$code.' '.$driverMessage);
        $pdo->errorInfo = [$state, $code, $driverMessage];

        return new QueryException('mysql', 'insert into `assets` (`asset_tag`) values (?)', ['SECRET-VALUE'], $pdo);
    }

    // ------------------------------------------------------------------
    // D. Encrypted + unique custom fields
    // ------------------------------------------------------------------

    #[Test]
    public function a_field_cannot_newly_become_both_encrypted_and_unique(): void
    {
        $this->requiresSchemaChangesOutsideTheTestTransaction();
        $field = CustomField::factory()->make(['name' => 'Vault', 'field_encrypted' => 1, 'is_unique' => 1]);
        $this->assertFalse($field->save());
        $this->assertSame([trans('admin/hardware/uniqueness.encrypted_unique')], $field->getErrors()->get('is_unique'));
        $this->assertSame(0, CustomField::query()->where('name', 'Vault')->count());

        $unique = CustomField::factory()->create(['name' => 'Licence', 'is_unique' => 1]);
        $unique->field_encrypted = 1;
        $this->assertFalse($unique->save());
        $this->assertSame(0, (int) $unique->fresh()->field_encrypted);

        $encrypted = CustomField::factory()->create(['name' => 'Secret', 'field_encrypted' => 1]);
        $encrypted->is_unique = 1;
        $this->assertFalse($encrypted->save());
        $this->assertSame(0, (int) $encrypted->fresh()->is_unique);
    }

    #[Test]
    public function existing_encrypted_unique_fields_and_their_data_are_preserved(): void
    {
        $field = CustomField::factory()->create(['name' => 'Legacy Vault', 'field_encrypted' => 1]);
        DB::table('custom_fields')->where('id', $field->id)->update(['is_unique' => 1]);
        $field->refresh();
        $asset = Asset::factory()->create();
        $cipher = Crypt::encrypt('kept-secret');
        DB::table('assets')->where('id', $asset->id)->update([$field->db_column => $cipher]);

        $field->help_text = 'still editable';
        $this->assertTrue($field->save(), json_encode($field->getErrors()?->toArray()));

        $field->refresh();
        $this->assertSame(1, (int) $field->is_unique, 'Flags are not changed automatically.');
        $this->assertSame(1, (int) $field->field_encrypted);
        $this->assertSame($cipher, DB::table('assets')->where('id', $asset->id)->value($field->db_column), 'Stored values are not decrypted or cleared.');
    }

    #[Test]
    public function the_api_refuses_encrypted_unique_fields(): void
    {
        $this->actingAsForApi(User::factory()->superuser()->create());

        $this->postJson(route('api.customfields.store'), ['name' => 'Api Vault', 'element' => 'text', 'format' => 'ANY', 'field_encrypted' => 1, 'is_unique' => 1])
            ->assertStatusMessageIs('error');
        $this->assertSame(0, CustomField::query()->where('name', 'Api Vault')->count());

        // The API never changes encryption on update (upstream rule); an
        // encrypted field cannot be made unique through it either.
        $field = CustomField::factory()->create(['name' => 'Api Secret', 'field_encrypted' => 1]);
        $this->patchJson(route('api.customfields.update', $field), ['is_unique' => 1])->assertStatusMessageIs('error');
        $this->assertSame(0, (int) $field->fresh()->is_unique);
    }

    #[Test]
    public function the_encryption_command_refuses_unique_fields_and_changes_nothing(): void
    {
        $this->requiresSchemaChangesOutsideTheTestTransaction();
        $field = CustomField::factory()->create(['name' => 'Serial Code', 'is_unique' => 1]);
        $asset = Asset::factory()->create();
        DB::table('assets')->where('id', $asset->id)->update([$field->db_column => 'PLAIN-1']);

        $this->artisan('snipeit:customfield-encryption', ['fieldname' => $field->db_column])
            ->expectsOutput(trans('admin/hardware/uniqueness.encryption_refused_unique'))
            ->assertExitCode(1);

        $this->assertSame(0, (int) $field->fresh()->field_encrypted);
        $this->assertSame('PLAIN-1', DB::table('assets')->where('id', $asset->id)->value($field->db_column));
    }

    // ------------------------------------------------------------------
    // Recognising ERS duplicate-key errors
    // ------------------------------------------------------------------

    #[Test]
    public function only_duplicates_on_ers_keys_are_recognised(): void
    {
        $this->requiresSchemaChangesOutsideTheTestTransaction();
        $field = CustomField::factory()->create(['name' => 'Licence', 'is_unique' => 1]);

        $tag = UniqueConstraintViolation::detect(self::duplicateKey("Duplicate entry 'SECRET-VALUE' for key 'ers_live_asset_tag_unique'"));
        $this->assertSame(['asset_tag', trans('general.asset_tag')], [$tag->attribute(), $tag->label()]);

        $serial = UniqueConstraintViolation::detect(self::duplicateKey("Duplicate entry 'SECRET-VALUE' for key 'assets.ers_live_serial_unique'"));
        $this->assertSame('serial', $serial->attribute(), 'MySQL 8 prefixes the table name.');

        $custom = UniqueConstraintViolation::detect(self::duplicateKey("Duplicate entry '0f3a' for key 'ers_live_cf_{$field->id}_unique'"));
        $this->assertSame([$field->db_column, 'Licence'], [$custom->attribute(), $custom->label()]);

        foreach ([
            "Duplicate entry 'x' for key 'PRIMARY'",
            "Duplicate entry 'x' for key 'users_username_unique'",
            "Duplicate entry 'x' for key 'ers_live_cf_abc_unique'",
            // A value that looks like an ERS key name cannot fake the index.
            "Duplicate entry 'v' for key 'ers_live_serial_unique' x' for key 'imports_name_unique'",
        ] as $message) {
            $this->assertNull(UniqueConstraintViolation::detect(self::duplicateKey($message)), $message);
        }
        $this->assertNull(UniqueConstraintViolation::detect(self::duplicateKey("Cannot add or update a child row for key 'ers_live_asset_tag_unique'", 1452)));
        $this->assertNull(UniqueConstraintViolation::detect(new RuntimeException("Duplicate entry 'x' for key 'ers_live_asset_tag_unique'")));

        foreach ([$tag, $serial, $custom] as $violation) {
            $this->assertStringNotContainsString('SECRET-VALUE', $violation->message());
        }
    }

    #[Test]
    public function an_ers_duplicate_becomes_a_field_error_and_is_logged_without_the_value(): void
    {
        $exception = self::duplicateKey("Duplicate entry 'SECRET-VALUE' for key 'ers_live_asset_tag_unique'");
        Route::middleware('web')->post('/_ers_uniqueness_test', fn () => throw $exception);
        Route::middleware('api')->post('/api/_ers_uniqueness_test', fn () => throw $exception);
        Log::spy();
        $user = User::factory()->superuser()->create();

        $this->actingAs($user)->from('/hardware/create')->post('/_ers_uniqueness_test')
            ->assertRedirect('/hardware/create')
            ->assertSessionHasErrors(['asset_tag' => trans('admin/hardware/uniqueness.taken', ['attribute' => trans('general.asset_tag')])]);

        $this->actingAsForApi($user)->postJson('/api/_ers_uniqueness_test')
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('messages.asset_tag.0', trans('admin/hardware/uniqueness.taken', ['attribute' => trans('general.asset_tag')]));

        Log::shouldHaveReceived('info')->with('ERS asset uniqueness: a database key refused a duplicate value.', ['constraint' => 'ers_live_asset_tag_unique']);
        Log::shouldNotHaveReceived('error');
        foreach (['warning', 'info', 'debug', 'notice'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'SECRET-VALUE')), \Mockery::any()]);
        }
    }

    #[Test]
    public function other_database_errors_are_not_disguised_as_uniqueness_errors(): void
    {
        $exception = self::duplicateKey("Duplicate entry 'x' for key 'users_username_unique'");
        Route::middleware('web')->post('/_ers_uniqueness_other', fn () => throw $exception);
        $this->withoutExceptionHandling([QueryException::class]);

        $response = $this->actingAs(User::factory()->superuser()->create())->post('/_ers_uniqueness_other');

        $response->assertStatus(500);
        $response->assertSessionDoesntHaveErrors();
    }

    #[Test]
    public function a_write_stopped_by_a_concurrent_transaction_is_reported_as_busy_not_as_a_duplicate(): void
    {
        $deadlock = self::duplicateKey('Deadlock found when trying to get lock; try restarting transaction', 1213, '40001');
        $timeout = self::duplicateKey('Lock wait timeout exceeded; try restarting transaction', 1205, 'HY000');
        $this->assertSame(ConcurrentWriteConflict::DEADLOCK, ConcurrentWriteConflict::detect($deadlock));
        $this->assertSame(ConcurrentWriteConflict::LOCK_WAIT_TIMEOUT, ConcurrentWriteConflict::detect($timeout));
        $this->assertNull(UniqueConstraintViolation::detect($deadlock));
        $this->assertNull(ConcurrentWriteConflict::detect(self::duplicateKey("Duplicate entry 'x' for key 'ers_live_asset_tag_unique'")));
        $this->assertNull(ConcurrentWriteConflict::detect(self::duplicateKey('Something else', 1213, 'HY000')));

        Route::middleware('web')->post('/_ers_busy_test', fn () => throw $deadlock);
        Route::middleware('api')->post('/api/_ers_busy_test', fn () => throw $timeout);
        Log::spy();
        $user = User::factory()->superuser()->create();

        $this->actingAs($user)->from('/hardware/create')->post('/_ers_busy_test', ['asset_tags' => [1 => 'KEEP']])
            ->assertRedirect('/hardware/create')
            ->assertSessionHas('error', trans('admin/hardware/uniqueness.busy'))
            ->assertSessionHasInput('asset_tags')
            ->assertSessionDoesntHaveErrors();

        $this->actingAsForApi($user)->postJson('/api/_ers_busy_test')
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('messages', trans('admin/hardware/uniqueness.busy'));

        Log::shouldHaveReceived('warning')->with('ERS: a write was stopped by a concurrent database transaction and rolled back.', ['code' => 1213]);
        Log::shouldHaveReceived('warning')->with('ERS: a write was stopped by a concurrent database transaction and rolled back.', ['code' => 1205]);
        Log::shouldNotHaveReceived('error');
    }

    // ------------------------------------------------------------------
    // F. Restore conflicts
    // ------------------------------------------------------------------

    #[Test]
    public function restoring_an_asset_whose_tag_is_taken_is_refused_with_the_field_error(): void
    {
        $user = User::factory()->superuser()->create();
        $deleted = Asset::factory()->create(['asset_tag' => 'RESTORE-1']);
        $deleted->delete();
        Asset::factory()->create(['asset_tag' => 'RESTORE-1']);
        $logs = Actionlog::withoutGlobalScopes()->where('item_id', $deleted->id)->where('action_type', 'restore')->count();

        $this->actingAs($user)->from(route('hardware.index'))->post(route('restore/hardware', $deleted->id))
            ->assertRedirect()
            ->assertSessionHasErrors('asset_tag')
            ->assertSessionHas('error');
        $this->assertNotNull($deleted->fresh()->deleted_at, 'Not restored.');
        $this->assertSame('RESTORE-1', $deleted->fresh()->asset_tag, 'Never retagged.');
        $this->assertSame($logs, Actionlog::withoutGlobalScopes()->where('item_id', $deleted->id)->where('action_type', 'restore')->count(), 'No restore log for a refused restore.');

        $this->actingAsForApi($user)->postJson(route('api.assets.restore', $deleted->id))
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['asset_tag']]);
        $this->assertNotNull($deleted->fresh()->deleted_at);
    }

    #[Test]
    public function bulk_restore_reports_every_refused_asset(): void
    {
        $user = User::factory()->superuser()->create();
        $blocked = Asset::factory()->create(['asset_tag' => 'BULK-1']);
        $blocked->delete();
        Asset::factory()->create(['asset_tag' => 'BULK-1']);
        $free = Asset::factory()->create(['asset_tag' => 'BULK-2']);
        $free->delete();

        $response = $this->actingAs($user)->post(route('hardware/bulkrestore'), ['ids' => [$blocked->id, $free->id]])
            ->assertRedirect(route('hardware.index'));

        $this->assertNull($free->fresh()->deleted_at, 'The free one is restored.');
        $this->assertNotNull($blocked->fresh()->deleted_at, 'The blocked one stays deleted.');
        $response->assertSessionHas('success');
        $error = session('error');
        $this->assertStringContainsString(trans_choice('admin/hardware/uniqueness.restore_partial', 1, ['count' => 1]), $error);
        $this->assertStringContainsString('BULK-1', $error);

        $alone = Asset::factory()->create(['asset_tag' => 'BULK-3']);
        $alone->delete();
        Asset::factory()->create(['asset_tag' => 'BULK-3']);
        $this->actingAs($user)->post(route('hardware/bulkrestore'), ['ids' => [$alone->id]])
            ->assertSessionMissing('success');
    }

    // ------------------------------------------------------------------
    // Native importer: failed updates are reported
    // ------------------------------------------------------------------

    #[Test]
    public function a_native_import_update_that_fails_validation_is_reported_not_counted_as_updated(): void
    {
        $this->settings->enableUniqueSerialNumbers();
        Asset::factory()->create(['serial' => 'TAKEN-SERIAL']);
        $asset = Asset::factory()->create(['serial' => 'OWN-SERIAL'])->refresh();
        $builder = ImportFileBuilder::times(1)->replace(['tag' => $asset->asset_tag, 'serialNumber' => 'TAKEN-SERIAL']);
        $import = Import::factory()->asset()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->actingAsForApi(User::factory()->superuser()->create());
        $response = $this->postJson(route('api.imports.importFile', $import->id), ['import-update' => true, 'import-type' => 'asset']);

        $response->assertJsonPath('status', 'import-errors');
        $this->assertStringContainsString('serial', json_encode($response->json('messages')));
        $this->assertSame('OWN-SERIAL', $asset->fresh()->serial, 'Nothing of the refused row was written.');
    }

    // ------------------------------------------------------------------
    // Tag regeneration: all or nothing
    // ------------------------------------------------------------------

    #[Test]
    public function tag_regeneration_renumbers_every_live_asset(): void
    {
        $assets = Asset::factory()->count(3)->create();
        // Set after creating: creating assets advances the base.
        $this->settings->set(['auto_increment_prefix' => 'R-', 'zerofill_count' => 3, 'next_auto_tag_base' => 7]);
        Setting::$_cache = null;

        $this->artisan('snipeit:regenerate-tags', ['--no-backup' => true])
            ->expectsConfirmation('This will regenerate all of the asset tags within your system. This action is data-destructive and should be used with caution. Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(['R-007', 'R-008', 'R-009'], Asset::withoutGlobalScopes()->whereIn('id', $assets->pluck('id'))->orderBy('id')->pluck('asset_tag')->all());
        $this->assertSame('10', (string) Setting::query()->value('next_auto_tag_base'), 'Stored as a number, as before.');
    }

    #[Test]
    public function tag_regeneration_swaps_tags_and_leaves_unchanged_ones_alone(): void
    {
        $first = Asset::factory()->create(['asset_tag' => 'SS3']);
        $second = Asset::factory()->create(['asset_tag' => 'SS2']);
        $third = Asset::factory()->create(['asset_tag' => 'ss1']);
        $this->settings->set(['auto_increment_prefix' => 'S', 'zerofill_count' => 0, 'next_auto_tag_base' => 50]);
        Setting::$_cache = null;
        $updates = fn (Asset $asset) => Actionlog::withoutGlobalScopes()->where('item_type', Asset::class)->where('item_id', $asset->id)->where('action_type', 'update')->count();

        $this->artisan('snipeit:regenerate-tags', ['--no-backup' => true, '--start' => 1])
            ->expectsConfirmation('This will regenerate all of the asset tags within your system. This action is data-destructive and should be used with caution. Do you wish to continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(['SS1', 'SS2', 'SS3'], [$first->fresh()->asset_tag, $second->fresh()->asset_tag, $third->fresh()->asset_tag]);
        $this->assertSame(0, $updates($second), 'An unchanged tag writes no history, as before.');
        $this->assertSame(1, $updates($first));
        $this->assertSame(1, $updates($third), 'A case-only change is a change.');
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('asset_tag', 'like', '%ers_regen%')->count());
    }

    #[Test]
    public function a_failed_tag_regeneration_changes_no_tag(): void
    {
        $assets = Asset::factory()->count(4)->create();
        $this->settings->set(['auto_increment_prefix' => 'R-', 'zerofill_count' => 3, 'next_auto_tag_base' => 7]);
        Setting::$_cache = null;
        $before = Asset::withoutGlobalScopes()->orderBy('id')->pluck('asset_tag', 'id')->all();
        $third = $assets[2]->id;
        Asset::updating(function (Asset $asset) use ($third) {
            if ($asset->id === $third) {
                throw new RuntimeException('SQL with SECRET-VALUE');
            }
        });

        $this->artisan('snipeit:regenerate-tags', ['--no-backup' => true])
            ->expectsConfirmation('This will regenerate all of the asset tags within your system. This action is data-destructive and should be used with caution. Do you wish to continue?', 'yes')
            ->expectsOutput(trans('admin/hardware/uniqueness.regenerate.failed'))
            ->doesntExpectOutputToContain('SECRET-VALUE')
            ->assertExitCode(1);

        $this->assertSame($before, Asset::withoutGlobalScopes()->orderBy('id')->pluck('asset_tag', 'id')->all(), 'No asset is partially renumbered (nor left with a temporary tag).');
        $this->assertSame('7', (string) DB::table('settings')->value('next_auto_tag_base'));
    }
}
