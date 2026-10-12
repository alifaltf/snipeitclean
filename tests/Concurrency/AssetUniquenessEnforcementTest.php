<?php

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ERS: the live asset uniqueness keys (asset tag, serial when unique
 * serials are on, unique custom fields) on a real MySQL/MariaDB database,
 * with every writer in its own PHP process so row, gap, metadata and named
 * locks are the database's own.
 *
 * Opt-in, like AssetImportConcurrencyTest: it DROPS AND RECREATES every
 * table of a dedicated *_concurrency_test database before EVERY test
 * (prepare-database.php and every worker verify the database first with
 * ConcurrencyDatabaseGuard). Do not run it at the same time as another run
 * against the same database.
 *
 *   ERS_CONCURRENCY_DB_DATABASE=ers_concurrency_test \
 *   ERS_CONCURRENCY_DB_USERNAME=... ERS_CONCURRENCY_DB_PASSWORD=... \
 *   vendor/bin/phpunit tests/Concurrency
 *
 * "hold" keeps one statement's transaction open for a while, then commits
 * or rolls back: the competing writer validates while the held value is
 * still invisible to it, so only the database key can stop the duplicate.
 */
class AssetUniquenessEnforcementTest extends TestCase
{
    private static ?string $dir = null;

    /** @var array<string, string> */
    private static array $env = [];

    /** @var array{user: int, model: int, status: int} */
    private array $state;

    public static function setUpBeforeClass(): void
    {
        $database = (string) getenv('ERS_CONCURRENCY_DB_DATABASE');
        if ($database === '') {
            return;
        }
        if (! str_ends_with($database, '_concurrency_test')) {
            throw new RuntimeException('ERS_CONCURRENCY_DB_DATABASE must name a dedicated *_concurrency_test database.');
        }

        self::$dir = sys_get_temp_dir().'/ers-uniqueness-'.bin2hex(random_bytes(6));
        mkdir(self::$dir.'/files', 0700, true);
        $host = (string) (getenv('ERS_CONCURRENCY_DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('ERS_CONCURRENCY_DB_PORT') ?: '3306');
        self::$env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) getenv('ERS_CONCURRENCY_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('ERS_CONCURRENCY_DB_PASSWORD'),
            'DB_SOCKET' => '',
            'ERS_CONCURRENCY_DB_DATABASE' => $database,
            'ERS_CONCURRENCY_DB_HOST' => $host,
            'ERS_CONCURRENCY_DB_PORT' => $port,
            'ERS_CONCURRENCY_DIR' => self::$dir,
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== null) {
            (new Process(['rm', '-rf', self::$dir]))->run();
        }
    }

    protected function setUp(): void
    {
        if (self::$dir === null) {
            $this->markTestSkipped('Set ERS_CONCURRENCY_DB_* to a dedicated MySQL/MariaDB *_concurrency_test database.');
        }
        // A fresh database per test: migrations (including the ERS key
        // migration) and the fixtures.
        $prepare = new Process(['php', __DIR__.'/prepare-database.php'], dirname(__DIR__, 2), self::$env, null, 600);
        if ($prepare->run() !== 0) {
            throw new RuntimeException('The concurrency database was not prepared: '.trim($prepare->getErrorOutput()));
        }
        (new Process(['php', __DIR__.'/asset-import-worker.php', 'setup'], dirname(__DIR__, 2), self::$env, null, 120))->mustRun();
        $this->state = json_decode((string) file_get_contents(self::$dir.'/state.json'), true);
    }

    // ------------------------------------------------------------------
    // Native writers racing each other
    // ------------------------------------------------------------------

    #[Test]
    public function a_native_create_waits_for_a_held_tag_and_loses_on_commit_or_wins_on_rollback(): void
    {
        foreach (['web-create', 'api-create'] as $writer) {
            foreach (['commit', 'rollback'] as $outcome) {
                $tag = 'RACE-'.$writer.'-'.$outcome;
                $hold = $this->start(['hold', '2500', $outcome, $this->insert(['asset_tag' => $tag])]);
                usleep(800_000);
                $started = microtime(true);
                // Differs only in case: the same tag for the database.
                $result = $this->w([$writer, strtolower($tag)]);
                $waited = microtime(true) - $started;
                $this->assertSame(['result' => $outcome], $this->finish($hold));

                $this->assertGreaterThan(1.0, $waited, "$writer waited for the held tag ($outcome)");
                if ($outcome === 'commit') {
                    $this->assertSame(['asset_tag'], $result['errors'], "$writer: field error after the holder committed");
                } else {
                    $this->assertSame([], $result['errors'], "$writer: created after the holder rolled back");
                }
                $this->assertSame(1, $this->live("asset_tag = '".$tag."'"), "$writer/$outcome: exactly one live asset");
            }
        }
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function competing_updates_cannot_give_two_assets_the_same_tag(): void
    {
        $this->w(['api-create', 'UPD-A']);
        $this->w(['api-create', 'UPD-B']);
        $a = $this->id('UPD-A');

        foreach (['commit' => 'UPD-C', 'rollback' => 'UPD-D'] as $outcome => $tag) {
            $hold = $this->start(['hold', '2500', $outcome, "UPDATE assets SET asset_tag = '".$tag."' WHERE asset_tag = 'UPD-B'"]);
            usleep(800_000);
            $result = $this->w(['api-update', (string) $a, strtolower($tag)]);
            $this->finish($hold);

            if ($outcome === 'commit') {
                $this->assertSame(['asset_tag'], $result['errors']);
                $this->assertSame('UPD-A', $this->scalar('SELECT asset_tag FROM assets WHERE id = '.$a));
                $this->sql("UPDATE assets SET asset_tag = 'UPD-B' WHERE asset_tag = 'UPD-C'");
            } else {
                $this->assertSame([], $result['errors']);
                $this->assertSame(strtolower($tag), $this->scalar('SELECT asset_tag FROM assets WHERE id = '.$a));
            }
        }
    }

    #[Test]
    public function native_writers_competing_with_the_ers_import_never_create_a_duplicate(): void
    {
        // The import holds its first tag (uncommitted) for 2.5 s; a native
        // create of the same tag waits on the key, then is refused or, if
        // the database picks it as a deadlock victim, told to try again.
        // Never a duplicate, never a 500.
        [$id, $token] = $this->importSession('IMPA', 50);
        $import = $this->startImport($id, $token, 2500);
        usleep(800_000);
        $web = $this->w(['web-create', 'impa-1']);
        $api = $this->w(['api-create', 'IMPA-2']);
        $result = $this->finishJson($import);

        $this->assertSame('completed', $result['status']);
        $this->assertSame(302, $web['http']);
        $this->assertTrue($web['errors'] === ['asset_tag'] || in_array('busy', $web['flash'], true), json_encode($web));
        $this->assertSame(200, $api['http']);
        $this->assertContains($api['errors'], [['asset_tag'], ['busy']]);
        $this->assertSame(1, $this->live("asset_tag = 'IMPA-1'"));
        $this->assertSame(1, $this->live("asset_tag = 'IMPA-2'"));
        $this->assertSame(50, $this->live("asset_tag LIKE 'IMPA-%'"));

        // Winner rollback: another writer commits IMPB-50 while the import
        // is paused, so the import's own row 51 is refused by the key (row
        // diagnostic, whole import rolled back). The native create of
        // IMPB-1 waiting on the import then either succeeds or is told to
        // try again; the import never leaves a partial result.
        [$id, $token] = $this->importSession('IMPB', 50);
        $import = $this->startImport($id, $token, 2500);
        usleep(400_000);
        $this->assertSame(['result' => 'commit'], $this->w(['hold', '1', 'commit', $this->insert(['asset_tag' => 'impb-50'])]));
        usleep(300_000);
        $native = $this->w(['api-create', 'IMPB-1']);
        $result = $this->finishJson($import);

        $this->assertSame('execution_failed', $result['status'], json_encode($result));
        $this->assertSame(51, $result['errors'][0]['row']);
        $this->assertStringNotContainsStringIgnoringCase('impb-50', json_encode($result['errors'][0]['message']), 'The row error names no value.');
        $this->assertContains($native['errors'], [[], ['busy']]);
        $this->assertSame($native['errors'] === [] ? 2 : 1, $this->live("asset_tag LIKE 'IMPB-%'"));
        $this->assertSame(1, $this->live("asset_tag = 'IMPB-50'"));
    }

    #[Test]
    public function the_tag_key_compares_like_the_column_and_ignores_deleted_assets(): void
    {
        $this->assertTrue($this->rawInsert(['asset_tag' => 'Straße'])['ok']);
        $this->assertSame('asset_tag', $this->rawInsert(['asset_tag' => 'STRASSE'])['violation'], 'ß = ss');
        $this->assertTrue($this->rawInsert(['asset_tag' => 'café'])['ok']);
        $this->assertSame('asset_tag', $this->rawInsert(['asset_tag' => 'CAFE'])['violation'], 'accents and case');
        $this->assertSame('asset_tag', $this->rawInsert(['asset_tag' => 'cafe   '])['violation'], 'trailing spaces (PAD SPACE)');
        $this->assertTrue($this->rawInsert(['asset_tag' => ' cafe'])['ok'], 'leading spaces are significant');

        // A deleted asset's tag can be used again by a native writer (the
        // no-reuse rule is the ERS import's only).
        $this->sql("UPDATE assets SET deleted_at = NOW() WHERE asset_tag = 'café'");
        $this->assertSame([], $this->w(['api-create', 'CAFÉ'])['errors']);
        $this->assertSame(2, $this->live("asset_tag = 'cafe'", true));
    }

    // ------------------------------------------------------------------
    // Serial numbers follow "unique serial"
    // ------------------------------------------------------------------

    #[Test]
    public function serial_uniqueness_follows_the_setting(): void
    {
        // Off: duplicates are allowed.
        $this->w(['api-create', 'S-1', 'SER-1']);
        $this->assertSame([], $this->w(['api-create', 'S-2', 'ser-1'])['errors']);

        // On with duplicates: refused with a count, nothing changed.
        $this->assertSame(['saved' => false, 'warning' => null, 'error' => 'conflicts'], $this->w(['serial', 'on']));
        $this->assertSame('0', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertFalse($this->key('serial')['column']);

        // Resolved: switched on, the key exists before the setting says so.
        $this->sql("UPDATE assets SET serial = 'SER-2' WHERE asset_tag = 'S-2'");
        $this->assertSame(['saved' => true, 'warning' => null, 'error' => null], $this->w(['serial', 'on']));
        $this->assertSame('1', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertSame('active', $this->key('serial')['status']);

        $this->assertSame(['serial'], $this->w(['api-create', 'S-3', 'Sér-2'])['errors']);
        $this->assertSame('serial', $this->rawInsert(['asset_tag' => 'S-4', 'serial' => 'SER-1  '])['violation']);
        // Blank and NULL serials never conflict.
        foreach (['S-5' => '', 'S-6' => '', 'S-7' => '   ', 'S-8' => null, 'S-9' => null] as $tag => $serial) {
            $this->assertTrue($this->rawInsert(['asset_tag' => $tag, 'serial' => $serial])['ok'], "blank serial for $tag");
        }

        // Off: the key is removed after the setting is saved; duplicates again allowed.
        $this->assertSame(['saved' => true, 'warning' => null, 'error' => null], $this->w(['serial', 'off']));
        $this->assertFalse($this->key('serial')['column']);
        $this->assertSame([], $this->w(['api-create', 'S-10', 'SER-1'])['errors']);

        // A settings save that fails after the key was created removes the key again.
        $this->sql("UPDATE assets SET serial = 'SER-10' WHERE asset_tag = 'S-10'");
        $this->assertSame(['saved' => false, 'warning' => null, 'error' => null], $this->w(['serial', 'on', 'fail-save']));
        $this->assertSame('0', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertFalse($this->key('serial')['column']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM ers_asset_uniqueness_keys WHERE `key` = 'serial'"));
    }

    #[Test]
    public function switching_unique_serials_on_while_a_duplicate_is_being_written(): void
    {
        $this->w(['api-create', 'T-1', 'SER-1']);

        // The duplicate commits: the setting is refused, nothing enforced.
        $hold = $this->start(['hold', '3000', 'commit', $this->insert(['asset_tag' => 'T-2', 'serial' => 'ser-1'])]);
        usleep(800_000);
        $this->assertSame('conflicts', $this->w(['serial', 'on'])['error']);
        $this->finish($hold);
        $this->assertSame('0', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertFalse($this->key('serial')['column']);
        $this->sql("DELETE FROM assets WHERE asset_tag = 'T-2'");

        // The duplicate rolls back: the setting is saved and enforced.
        $hold = $this->start(['hold', '3000', 'rollback', $this->insert(['asset_tag' => 'T-3', 'serial' => 'ser-1'])]);
        usleep(800_000);
        $this->assertTrue($this->w(['serial', 'on'])['saved']);
        $this->finish($hold);
        $this->assertSame('active', $this->key('serial')['status']);
        $this->assertSame('serial', $this->rawInsert(['asset_tag' => 'T-4', 'serial' => 'SER-1'])['violation']);
    }

    #[Test]
    public function a_failed_schema_change_or_metadata_save_leaves_nothing_enforced(): void
    {
        $this->w(['field-create', 'Badge', '0']);

        // A real DDL failure: another transaction holds the table and the
        // change gives up after one second.
        foreach ([['serial', 'on'], ['field-update', '2', 'is_unique', '1']] as $command) {
            $hold = $this->start(['hold', '4000', 'commit', 'SELECT id FROM assets LIMIT 1']);
            usleep(800_000);
            $result = $this->w($command, ['ERS_TEST_LOCK_WAIT_TIMEOUT' => '1']);
            $this->finish($hold);
            $this->assertFalse($result['saved'], json_encode($result));
        }
        $this->assertSame('0', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertSame('0', (string) $this->scalar('SELECT is_unique FROM custom_fields WHERE id = 2'));
        $this->assertSame(['asset_tag'], array_column($this->w(['status'])['keys'], 'key'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ers_asset_uniqueness_keys'));
        $this->assertDatabaseKeysHealthy();

        // The key is created, then the metadata save fails in the database
        // (a trigger refuses it): the key is removed again.
        $this->sql("CREATE TRIGGER ers_test_field_save BEFORE UPDATE ON custom_fields FOR EACH ROW BEGIN IF NEW.is_unique <> OLD.is_unique THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'; END IF; END");
        $this->sql("CREATE TRIGGER ers_test_settings_save BEFORE UPDATE ON settings FOR EACH ROW BEGIN IF NEW.unique_serial <> OLD.unique_serial THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'; END IF; END");
        $this->assertSame(['saved' => false, 'error' => 'exception'], $this->w(['field-update', '2', 'is_unique', '1']));
        $this->assertSame('exception', $this->w(['serial', 'on'])['error']);
        $this->sql('DROP TRIGGER ers_test_field_save');
        $this->sql('DROP TRIGGER ers_test_settings_save');
        $this->assertSame('0', (string) $this->scalar('SELECT unique_serial FROM settings'));
        $this->assertSame('0', (string) $this->scalar('SELECT is_unique FROM custom_fields WHERE id = 2'));
        $this->assertSame(['asset_tag'], array_column($this->w(['status'])['keys'], 'key'));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ers_asset_uniqueness_keys'));
        $this->assertDatabaseKeysHealthy();
    }

    // ------------------------------------------------------------------
    // Unique custom fields
    // ------------------------------------------------------------------

    #[Test]
    public function custom_field_keys_compare_values_like_their_column(): void
    {
        $this->w(['field-create', 'Badge', '1']);
        $column = '_snipeit_badge_2';
        $this->assertSame('active', $this->key('custom_field:2')['status']);

        $this->assertTrue($this->rawInsert(['asset_tag' => 'C-1', $column => 'Straße'])['ok']);
        $this->assertSame($column, $this->rawInsert(['asset_tag' => 'C-2', $column => 'STRASSE'])['violation']);
        $this->assertTrue($this->rawInsert(['asset_tag' => 'C-3', $column => 'café'])['ok']);
        $this->assertSame($column, $this->rawInsert(['asset_tag' => 'C-4', $column => 'CAFE  '])['violation']);
        foreach (['C-5' => '', 'C-6' => '', 'C-7' => '  ', 'C-8' => null, 'C-9' => null] as $tag => $value) {
            $this->assertTrue($this->rawInsert(['asset_tag' => $tag, $column => $value])['ok'], "blank value for $tag");
        }

        // Full-length TEXT values (65,535 bytes) are compared in full.
        $this->assertTrue($this->rawInsertSql('L-1', $column, "REPEAT('a', 65535)")['ok']);
        $this->assertTrue($this->rawInsertSql('L-2', $column, "CONCAT(REPEAT('a', 65534), 'b')")['ok'], 'differs in the last byte');
        $this->assertSame($column, $this->rawInsertSql('L-3', $column, "REPEAT('A', 65535)")['violation']);
        $this->assertTrue($this->rawInsertSql('L-4', $column, "CONCAT(' ', REPEAT('a', 65534))")['ok']);

        // Through the API (the field in the model's fieldset), racing a held value.
        $this->w(['fieldset', '2']);
        $hold = $this->start(['hold', '2500', 'commit', $this->insert(['asset_tag' => 'C-10', $column => 'Ünique'])]);
        usleep(800_000);
        $result = $this->w(['api-create', 'C-11', '', $column.'=unique ']);
        $this->finish($hold);
        $this->assertSame([$column], $result['errors']);

        // A NO PAD column: trailing spaces are significant, as for the column.
        $this->w(['field-create', 'NoPad', '0']);
        $this->sql('ALTER TABLE assets MODIFY _snipeit_nopad_3 TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL');
        $this->rawInsert(['asset_tag' => 'N-1', '_snipeit_nopad_3' => 'X']);
        $this->rawInsert(['asset_tag' => 'N-2', '_snipeit_nopad_3' => 'X ']);
        $this->assertTrue($this->w(['field-update', '3', 'is_unique', '1'])['saved']);
        $this->assertSame('_snipeit_nopad_3', $this->rawInsert(['asset_tag' => 'N-3', '_snipeit_nopad_3' => 'x'])['violation']);
        $this->assertTrue($this->rawInsert(['asset_tag' => 'N-4', '_snipeit_nopad_3' => 'X  '])['ok']);
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function a_unique_custom_field_keeps_its_key_through_its_lifecycle(): void
    {
        $this->w(['field-create', 'Badge', '1']);
        $this->rawInsert(['asset_tag' => 'B-1', '_snipeit_badge_2' => 'b-1']);

        // Renamed: the key follows the column, the ledger is updated.
        $this->assertSame('_snipeit_badge_number_2', $this->w(['field-update', '2', 'name', 'Badge Number'])['db_column']);
        $this->assertSame('_snipeit_badge_number_2', $this->scalar("SELECT source_column FROM ers_asset_uniqueness_keys WHERE `key` = 'custom_field:2'"));
        $this->assertSame('_snipeit_badge_number_2', $this->rawInsert(['asset_tag' => 'B-2', '_snipeit_badge_number_2' => 'B-1'])['violation']);

        // Encrypted + unique is refused; nothing changes.
        $this->assertSame(['is_unique'], $this->w(['field-update', '2', 'field_encrypted', '1'])['errors']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], (array) $this->row('SELECT is_unique, field_encrypted FROM custom_fields WHERE id = 2'));
        $this->assertSame('b-1', $this->scalar("SELECT _snipeit_badge_number_2 FROM assets WHERE asset_tag = 'B-1'"));
        $command = $this->w(['artisan', 'snipeit:customfield-encryption fieldname=_snipeit_badge_number_2']);
        $this->assertSame(1, $command['exit']);
        $this->assertSame('b-1', $this->scalar("SELECT _snipeit_badge_number_2 FROM assets WHERE asset_tag = 'B-1'"));

        // Unique off: the key is removed, duplicates allowed.
        $this->assertTrue($this->w(['field-update', '2', 'is_unique', '0'])['saved']);
        $this->assertFalse($this->key('custom_field:2')['column']);
        $this->assertTrue($this->rawInsert(['asset_tag' => 'B-3', '_snipeit_badge_number_2' => 'B-1'])['ok']);

        // Unique on with duplicates: refused, the flag stays off.
        $this->assertSame(['is_unique'], $this->w(['field-update', '2', 'is_unique', '1'])['errors']);
        $this->assertSame('0', (string) $this->scalar('SELECT is_unique FROM custom_fields WHERE id = 2'));
        $this->assertFalse($this->key('custom_field:2')['column']);

        // Racing an uncommitted duplicate: refused once it commits.
        $this->sql("DELETE FROM assets WHERE asset_tag = 'B-3'");
        $hold = $this->start(['hold', '3000', 'commit', $this->insert(['asset_tag' => 'B-4', '_snipeit_badge_number_2' => 'B-1'])]);
        usleep(800_000);
        $this->assertSame(['is_unique'], $this->w(['field-update', '2', 'is_unique', '1'])['errors']);
        $this->finish($hold);
        $this->assertFalse($this->key('custom_field:2')['column']);
        $this->sql("DELETE FROM assets WHERE asset_tag = 'B-4'");
        $this->assertTrue($this->w(['field-update', '2', 'is_unique', '1'])['saved']);

        // Deleted: the column and its key go together, the ledger row too.
        $this->assertTrue($this->w(['field-delete', '2'])['deleted']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME IN ('_snipeit_badge_number_2', 'ers_live_cf_2')"));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM ers_asset_uniqueness_keys WHERE `key` = 'custom_field:2'"));

        // A new unique field whose key cannot be created is not created at all.
        $this->sql('ALTER TABLE assets ADD COLUMN ers_live_cf_3 CHAR(64) NULL');
        $result = $this->w(['field-create', 'Card', '1']);
        $this->assertSame(['saved' => false, 'error' => 'foreign_object', 'exists' => false], $result);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = '_snipeit_card_3'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'ers_live_cf_3'"), 'not ERS\'s: untouched');
    }

    #[Test]
    public function an_existing_encrypted_unique_field_and_its_data_are_preserved(): void
    {
        $this->assertSame(['is_unique'], $this->w(['field-create', 'Secret', '1', '1'])['errors'], 'new encrypted+unique fields are refused');

        // One that already exists (legacy data) keeps working, unenforced.
        $this->w(['field-create', 'Legacy', '0', '1']);
        $this->sql('UPDATE custom_fields SET is_unique = 1 WHERE id = 2');
        $this->rawInsert(['asset_tag' => 'E-1', '_snipeit_legacy_2' => 'cipher-a']);
        $this->rawInsert(['asset_tag' => 'E-2', '_snipeit_legacy_2' => 'cipher-a']);

        $this->assertSame([], $this->w(['repair'])['report']['created']);
        $this->assertFalse($this->key('custom_field:2')['column'] ?? false);
        $this->assertTrue($this->w(['field-update', '2', 'help_text', 'kept'])['saved']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 1], (array) $this->row('SELECT is_unique, field_encrypted FROM custom_fields WHERE id = 2'));
        $this->assertSame(2, $this->live("_snipeit_legacy_2 = 'cipher-a'"));
    }

    // ------------------------------------------------------------------
    // Custom-field configuration changes racing each other
    // (barriers: a worker stops at a named point until the test releases it)
    // ------------------------------------------------------------------

    #[Test]
    public function unique_and_encrypted_enabled_from_stale_forms_never_both_persist(): void
    {
        // A opened the field (plain), then B encrypts it; A's "unique" is refused.
        $this->w(['field-create', 'Badge', '0']);
        $a = $this->start(['field-update', '2', 'is_unique', '1'], ['ERS_BARRIER_AFTER_LOAD' => $gate = $this->barrier('load')]);
        $this->reached($gate, $a);
        $this->assertTrue($this->w(['field-update', '2', 'field_encrypted', '1'])['saved']);
        $this->release($gate);
        $this->assertSame(['is_unique'], $this->finish($a)['errors']);
        $this->assertSame(['is_unique' => 0, 'field_encrypted' => 1], $this->flags(2));
        $this->assertSame(self::NO_KEY, $this->fieldKey(2));

        // The other way round: A opened it, B makes it unique; A's encryption is refused.
        $this->w(['field-create', 'Card', '0']);
        $a = $this->start(['field-update', '3', 'field_encrypted', '1'], ['ERS_BARRIER_AFTER_LOAD' => $gate = $this->barrier('load')]);
        $this->reached($gate, $a);
        $this->assertTrue($this->w(['field-update', '3', 'is_unique', '1'])['saved']);
        $this->release($gate);
        $this->assertSame(['is_unique'], $this->finish($a)['errors']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], $this->flags(3));
        $this->assertSame(self::KEY, $this->fieldKey(3));
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function the_encryption_command_and_a_unique_change_are_serialised_by_the_lock(): void
    {
        $this->w(['field-create', 'Badge', '0']);
        $this->rawInsert(['asset_tag' => 'ENC-1', '_snipeit_badge_2' => 'plain-1']);

        // A makes the field unique and is held inside its save (key built,
        // metadata written, lock still held); the command must wait.
        $a = $this->start(['field-update', '2', 'is_unique', '1'], ['ERS_BARRIER_IN_SAVE' => $gate = $this->barrier('save')]);
        $this->reached($gate, $a);
        $command = $this->start(['artisan', 'snipeit:customfield-encryption fieldname=_snipeit_badge_2']);
        usleep(1_500_000);
        $this->assertTrue($command->isRunning(), 'The command waits for the schema lock.');
        $this->release($gate);
        $this->assertTrue($this->finish($a)['saved']);
        $this->assertSame(1, $this->finish($command)['exit']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], $this->flags(2));
        $this->assertSame(self::KEY, $this->fieldKey(2));
        $this->assertSame('plain-1', $this->scalar("SELECT _snipeit_badge_2 FROM assets WHERE asset_tag = 'ENC-1'"), 'Nothing was encrypted.');

        // The command first (held inside its field save, values encrypted,
        // transaction open); the unique change waits, then is refused.
        $this->w(['field-create', 'Card', '0']);
        $this->rawInsert(['asset_tag' => 'ENC-2', '_snipeit_card_3' => 'plain-2']);
        $command = $this->start(['artisan', 'snipeit:customfield-encryption fieldname=_snipeit_card_3'], ['ERS_BARRIER_IN_SAVE' => $gate = $this->barrier('save')]);
        $this->reached($gate, $command);
        $b = $this->start(['field-update', '3', 'is_unique', '1']);
        usleep(1_500_000);
        $this->assertTrue($b->isRunning(), 'The unique change waits for the schema lock.');
        $this->release($gate);
        $this->assertSame(0, $this->finish($command)['exit']);
        $this->assertSame(['is_unique'], $this->finish($b)['errors']);
        $this->assertSame(['is_unique' => 0, 'field_encrypted' => 1], $this->flags(3));
        $this->assertSame(self::NO_KEY, $this->fieldKey(3));
        $this->assertNotSame('plain-2', $this->scalar("SELECT _snipeit_card_3 FROM assets WHERE asset_tag = 'ENC-2'"), 'Encrypted once.');
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function stale_saves_merge_other_changes_or_are_refused(): void
    {
        // A opened a unique field; B switches "unique" off; A then saves an
        // unrelated change: merged, and A does not switch "unique" back on.
        $this->w(['field-create', 'Badge', '1']);
        $a = $this->start(['field-update', '2', 'help_text', 'From A'], ['ERS_BARRIER_AFTER_LOAD' => $gate = $this->barrier('load')]);
        $this->reached($gate, $a);
        $this->assertTrue($this->w(['field-update', '2', 'is_unique', '0'])['saved']);
        $this->release($gate);
        $this->assertTrue($this->finish($a)['saved']);
        $this->assertSame(['is_unique' => 0, 'field_encrypted' => 0], $this->flags(2));
        $this->assertSame('From A', $this->scalar('SELECT help_text FROM custom_fields WHERE id = 2'));
        $this->assertSame(self::NO_KEY, $this->fieldKey(2));

        // Both rename the same field: the later, stale rename is refused;
        // the column and its key follow the first one.
        $this->w(['field-create', 'Card', '1']);
        $a = $this->start(['field-update', '3', 'name', 'Card Three'], ['ERS_BARRIER_AFTER_LOAD' => $gate = $this->barrier('load')]);
        $this->reached($gate, $a);
        $this->assertSame('_snipeit_card_two_3', $this->w(['field-update', '3', 'name', 'Card Two'])['db_column']);
        $this->release($gate);
        $this->assertSame(['name'], $this->finish($a)['errors']);
        $this->assertSame(['name' => 'Card Two', 'db_column' => '_snipeit_card_two_3'], (array) $this->row('SELECT name, db_column FROM custom_fields WHERE id = 3'));
        $this->assertSame(self::KEY, $this->fieldKey(3));
        $this->assertSame('_snipeit_card_two_3', $this->scalar("SELECT source_column FROM ers_asset_uniqueness_keys WHERE `key` = 'custom_field:3'"));
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function deleting_after_a_concurrent_rename_drops_the_current_column_and_key(): void
    {
        $this->w(['field-create', 'Badge', '1']);
        $a = $this->start(['field-delete', '2'], ['ERS_BARRIER_AFTER_LOAD' => $gate = $this->barrier('load')]);
        $this->reached($gate, $a);
        $this->assertTrue($this->w(['field-update', '2', 'name', 'Badge Two'])['saved']);
        $this->release($gate);
        $this->assertTrue($this->finish($a)['deleted']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM custom_fields WHERE id = 2'));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME IN ('_snipeit_badge_2', '_snipeit_badge_two_2', 'ers_live_cf_2')"));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ers_asset_uniqueness_keys WHERE `key` = \'custom_field:2\''));
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function compensation_follows_the_persisted_metadata(): void
    {
        $this->w(['field-create', 'Badge', '0']);
        $this->w(['field-create', 'Card', '1']);

        // Fails before its metadata is written: the key built for it goes.
        $this->sql("CREATE TRIGGER ers_test_unique_save BEFORE UPDATE ON custom_fields FOR EACH ROW BEGIN IF NEW.is_unique <> OLD.is_unique OR NEW.help_text <=> 'fail' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'; END IF; END");
        $this->assertSame('exception', $this->w(['field-update', '2', 'is_unique', '1'])['error']);
        $this->assertSame(['is_unique' => 0, 'field_encrypted' => 0], $this->flags(2));
        $this->assertSame(self::NO_KEY, $this->fieldKey(2));

        // A failed save of an unrelated change never removes a key the
        // persisted field needs.
        $this->assertSame('exception', $this->w(['field-update', '3', 'help_text', 'fail'])['error']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], $this->flags(3));
        $this->assertSame(self::KEY, $this->fieldKey(3));
        $this->sql('DROP TRIGGER ers_test_unique_save');

        // Fails AFTER its metadata was written: the persisted field is
        // unique, so its key stays.
        $this->assertSame('exception', $this->w(['field-update', '2', 'is_unique', '1'], ['ERS_FAIL_AFTER_PERSIST' => '1'])['error']);
        $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], $this->flags(2));
        $this->assertSame(self::KEY, $this->fieldKey(2));

        // A rename (nested save) failing after it was written: the key and
        // the ledger follow the persisted column.
        $this->assertSame('exception', $this->w(['field-update', '3', 'name', 'Card Two'], ['ERS_FAIL_AFTER_PERSIST' => '1'])['error']);
        $this->assertSame('_snipeit_card_two_3', $this->scalar('SELECT db_column FROM custom_fields WHERE id = 3'));
        $this->assertSame(self::KEY, $this->fieldKey(3));
        $this->assertSame('_snipeit_card_two_3', $this->scalar("SELECT source_column FROM ers_asset_uniqueness_keys WHERE `key` = 'custom_field:3'"));
        $this->assertTrue($this->rawInsert(['asset_tag' => 'CMP-1', '_snipeit_card_two_3' => 'x'])['ok']);
        $this->assertSame('_snipeit_card_two_3', $this->rawInsert(['asset_tag' => 'CMP-2', '_snipeit_card_two_3' => 'X'])['violation']);
        $this->assertDatabaseKeysHealthy();
    }

    #[Test]
    public function creating_unique_fields_concurrently_keeps_flags_and_keys_consistent(): void
    {
        // The first creation is held inside its nested save (column added,
        // key built, db_column written); the second waits for the lock.
        $a = $this->start(['field-create', 'Badge', '1'], ['ERS_BARRIER_IN_SAVE' => $gate = $this->barrier('save')]);
        $this->reached($gate, $a);
        $b = $this->start(['field-create', 'Card', '1']);
        usleep(1_500_000);
        $this->assertTrue($b->isRunning(), 'The second creation waits for the schema lock.');
        $this->release($gate);
        $this->assertTrue($this->finish($a)['saved']);
        $this->assertTrue($this->finish($b)['saved']);
        foreach ([2, 3] as $field) {
            $this->assertSame(['is_unique' => 1, 'field_encrypted' => 0], $this->flags($field));
            $this->assertSame(self::KEY, $this->fieldKey($field));
        }
        $this->assertDatabaseKeysHealthy();
    }

    // ------------------------------------------------------------------
    // Restore
    // ------------------------------------------------------------------

    #[Test]
    public function restoring_an_asset_whose_tag_is_taken_is_refused_on_every_path(): void
    {
        $this->w(['api-create', 'R-1']);
        $deleted = $this->id('R-1');
        $this->sql('UPDATE assets SET deleted_at = NOW() WHERE id = '.$deleted);
        $this->w(['api-create', 'r-1']);

        $web = $this->w(['web-restore', (string) $deleted]);
        $this->assertSame(['asset_tag'], $web['errors']);
        $this->assertSame(['asset_tag'], $this->w(['api-restore', (string) $deleted])['errors']);
        $this->assertSame(['restored' => false, 'fields' => ['asset_tag']], $this->w(['restore', (string) $deleted]));
        $this->assertNotNull($this->scalar('SELECT deleted_at FROM assets WHERE id = '.$deleted));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM action_logs WHERE action_type = 'restore'"));

        // Bulk: what can be restored is, the rest is reported; never retagged.
        $this->w(['api-create', 'R-2']);
        $free = $this->id('R-2');
        $this->sql('UPDATE assets SET deleted_at = NOW() WHERE id = '.$free);
        $bulk = $this->w(['bulk-restore', $deleted.','.$free]);
        $this->assertContains('success', $bulk['flash']);
        $this->assertContains('error', $bulk['flash']);
        $this->assertNull($this->scalar('SELECT deleted_at FROM assets WHERE id = '.$free));
        $this->assertNotNull($this->scalar('SELECT deleted_at FROM assets WHERE id = '.$deleted));
        $this->assertSame('R-1', $this->scalar('SELECT asset_tag FROM assets WHERE id = '.$deleted));

        // Racing a held insert of the same tag.
        $this->w(['api-create', 'R-3']);
        $racer = $this->id('R-3');
        $this->sql('UPDATE assets SET deleted_at = NOW() WHERE id = '.$racer);
        foreach (['commit' => false, 'rollback' => true] as $outcome => $restored) {
            $hold = $this->start(['hold', '2500', $outcome, $this->insert(['asset_tag' => 'r-3'])]);
            usleep(800_000);
            $result = $this->w(['restore', (string) $racer]);
            $this->finish($hold);
            $this->assertSame($restored, $result['restored'], $outcome);
            $this->assertSame(1, $this->live("asset_tag = 'R-3'"));
            if ($outcome === 'commit') {
                $this->sql("DELETE FROM assets WHERE BINARY asset_tag = 'r-3'");
            }
        }
    }

    // ------------------------------------------------------------------
    // Recovery
    // ------------------------------------------------------------------

    #[Test]
    public function interrupted_transitions_are_repaired_and_foreign_objects_left_alone(): void
    {
        // Interrupted "unique serial on" (setting saved, index never built).
        $this->sql('UPDATE settings SET unique_serial = 1');
        $this->sql("INSERT INTO ers_asset_uniqueness_keys (`key`, source_column, column_name, index_name, status, created_at, updated_at) VALUES ('serial', 'serial', 'ers_live_serial', 'ers_live_serial_unique', 'activating', NOW(), NOW())");
        $this->sql("ALTER TABLE assets ADD COLUMN ers_live_serial varchar(191) GENERATED ALWAYS AS (IF(deleted_at IS NULL AND TRIM(serial) <> '', serial, NULL)) VIRTUAL");
        // Interrupted "unique off" of a field (flag saved, key not yet removed).
        $this->w(['field-create', 'Old', '1']);
        $this->sql('UPDATE custom_fields SET is_unique = 0 WHERE id = 2');
        $this->sql("UPDATE ers_asset_uniqueness_keys SET status = 'deactivating' WHERE `key` = 'custom_field:2'");
        // Not ERS's.
        $this->sql('ALTER TABLE assets ADD COLUMN ers_live_decoy INT NULL');

        $check = $this->w(['artisan', 'ers:asset-uniqueness']);
        $this->assertSame(1, $check['exit']);
        $this->assertStringContainsString('NEEDS REPAIR', $check['output']);

        $this->assertSame(['created' => ['serial'], 'removed' => ['custom_field:2'], 'unchanged' => ['asset_tag']], $this->w(['repair'])['report']);
        $this->assertSame(['created' => [], 'removed' => [], 'unchanged' => ['asset_tag', 'serial']], $this->w(['repair'])['report'], 'idempotent');
        $this->assertTrue($this->rawInsert(['asset_tag' => 'I-1', 'serial' => 'X'])['ok']);
        $this->assertSame('serial', $this->rawInsert(['asset_tag' => 'I-2', 'serial' => 'x'])['violation']);
        $this->assertSame(['ers_live_decoy'], $this->w(['status'])['foreign']);

        // A foreign object where ERS would put a key: refused, untouched.
        $this->sql('ALTER TABLE assets DROP COLUMN ers_live_serial');
        $this->sql("DELETE FROM ers_asset_uniqueness_keys WHERE `key` = 'serial'");
        $this->sql('ALTER TABLE assets ADD COLUMN ers_live_serial INT NULL');
        $this->assertSame('foreign_object', $this->w(['repair'])['error']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'ers_live_serial'"));
    }

    #[Test]
    public function the_migration_refuses_duplicates_retries_partial_runs_and_rolls_back(): void
    {
        $this->assertSame(0, $this->w(['artisan', 'migrate:rollback --step=1'])['exit']);
        $this->assertFalse($this->key('asset_tag')['column']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ers_asset_uniqueness_keys'));

        // Duplicates: refused with counts only, nothing created, not recorded.
        $this->rawInsert(['asset_tag' => 'DUP-SECRET']);
        $this->rawInsert(['asset_tag' => 'dup-secret ']);
        $migrate = $this->w(['artisan', 'migrate']);
        $this->assertNotSame(0, $migrate['exit']);
        $this->assertStringContainsString('1 value is used by more than one live asset', $migrate['output']);
        $this->assertStringNotContainsStringIgnoringCase('dup-secret', $migrate['output']);
        $this->assertFalse($this->key('asset_tag')['column']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM migrations WHERE migration = '2026_10_08_000100_enforce_live_asset_uniqueness'"));

        // A partial earlier run (column added, index never built): retried.
        $this->sql("DELETE FROM assets WHERE BINARY asset_tag = 'dup-secret '");
        $this->sql("INSERT INTO ers_asset_uniqueness_keys (`key`, source_column, column_name, index_name, status, created_at, updated_at) VALUES ('asset_tag', 'asset_tag', 'ers_live_asset_tag', 'ers_live_asset_tag_unique', 'activating', NOW(), NOW())");
        $this->sql('ALTER TABLE assets ADD COLUMN ers_live_asset_tag varchar(191) GENERATED ALWAYS AS (IF(deleted_at IS NULL, asset_tag, NULL)) VIRTUAL');
        $this->assertSame(0, $this->w(['artisan', 'migrate'])['exit']);
        $this->assertSame('active', $this->key('asset_tag')['status']);
        $this->assertTrue($this->key('asset_tag')['index']);
        $this->assertSame('asset_tag', $this->rawInsert(['asset_tag' => 'Dup-Secret'])['violation']);
        $this->assertSame(0, $this->w(['artisan', 'migrate'])['exit']);
    }

    #[Test]
    public function tag_regeneration_is_all_or_nothing(): void
    {
        // The new tags swap between assets: only possible via temporary values.
        foreach (['SS3', 'SS2', 'ss1'] as $tag) {
            $this->rawInsert(['asset_tag' => $tag]);
        }
        $this->sql("UPDATE settings SET auto_increment_prefix = 'S', zerofill_count = 0, next_auto_tag_base = 50");
        $before = $this->tags();

        // A failure part-way (a trigger refusing one of the writes) leaves no tag changed.
        $this->sql("CREATE TRIGGER ers_test_regenerate_failure BEFORE UPDATE ON assets FOR EACH ROW BEGIN IF NEW.asset_tag = 'SS3' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by test'; END IF; END");
        $failed = $this->w(['artisan', 'snipeit:regenerate-tags --no-backup --start=1', 'yes']);
        $this->assertSame(1, $failed['exit']);
        $this->assertStringNotContainsString('refused by test', $failed['output']);
        $this->assertSame($before, $this->tags());
        $this->assertSame('50', (string) $this->scalar('SELECT next_auto_tag_base FROM settings'));

        $this->sql('DROP TRIGGER ers_test_regenerate_failure');
        $this->assertSame(0, $this->w(['artisan', 'snipeit:regenerate-tags --no-backup --start=1', 'yes'])['exit']);
        $this->assertSame(['SS1', 'SS2', 'SS3'], array_values($this->tags()));
        $this->assertSame(0, $this->live("asset_tag LIKE '\\_\\_ers\\_regen%'"));
        $this->assertSame('4', (string) $this->scalar('SELECT next_auto_tag_base FROM settings'));
        $this->assertDatabaseKeysHealthy();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Wait until a worker reached the barrier called $name. */
    private function reached(string $name, Process $process): void
    {
        $deadline = microtime(true) + 60;
        while (! file_exists(self::$dir.'/'.$name.'.ready')) {
            if (! $process->isRunning()) {
                throw new RuntimeException('The worker ended before its barrier: '.trim($process->getErrorOutput().$process->getOutput()));
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier '.$name.' not reached.');
            }
            usleep(10_000);
        }
    }

    private function release(string $name): void
    {
        touch(self::$dir.'/'.$name.'.go');
    }

    /** A unique barrier name for this test run. */
    private function barrier(string $label): string
    {
        return $label.'-'.bin2hex(random_bytes(4));
    }

    /** @return array{is_unique: int, field_encrypted: int} */
    private function flags(int $field): array
    {
        return array_map('intval', (array) $this->row('SELECT is_unique, field_encrypted FROM custom_fields WHERE id = '.$field));
    }

    /** Whether the field's key column AND unique index exist, and its ledger row. */
    private function fieldKey(int $field): array
    {
        $state = $this->key('custom_field:'.$field);

        return ['column' => $state['column'], 'index' => $state['index'], 'owned' => $state['owned']];
    }

    private const NO_KEY = ['column' => false, 'index' => false, 'owned' => false];

    private const KEY = ['column' => true, 'index' => true, 'owned' => true];

    private function process(array $arguments, array $env = []): Process
    {
        return new Process(['php', __DIR__.'/uniqueness-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env + $env, null, 300);
    }

    /** Run one worker command and decode its JSON line. */
    private function w(array $arguments, array $env = []): array
    {
        $process = $this->process($arguments, $env);
        if ($process->run() !== 0) {
            throw new RuntimeException('Worker failed: '.trim($process->getErrorOutput()));
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function start(array $arguments, array $env = []): Process
    {
        $process = $this->process($arguments, $env);
        $process->start();

        return $process;
    }

    private function finish(Process $process): array
    {
        if ($process->wait() !== 0) {
            throw new RuntimeException('Worker failed: '.trim($process->getErrorOutput()));
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function importWorker(array $arguments): Process
    {
        return new Process(['php', __DIR__.'/asset-import-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env, null, 300);
    }

    /** @return array{0: string, 1: string} */
    private function importSession(string $prefix, int $rows): array
    {
        $csv = self::$dir.'/'.$prefix.'.csv';
        $lines = ['Asset Tag'];
        for ($i = 1; $i <= $rows; $i++) {
            $lines[] = $prefix.'-'.$i;
        }
        file_put_contents($csv, implode("\n", $lines)."\n");

        return explode(' ', trim($this->importWorker(['session', $csv])->mustRun()->getOutput()));
    }

    private function startImport(string $id, string $token, int $pauseMs): Process
    {
        $process = $this->importWorker(['execute', $id, $token, (string) $pauseMs]);
        $process->start();

        return $process;
    }

    private function finishJson(Process $process): array
    {
        return $this->finish($process);
    }

    private function sql(string $statement): array
    {
        $result = $this->w(['sql', $statement]);
        if (! $result['ok']) {
            throw new RuntimeException('Statement failed: '.json_encode($result));
        }

        return $result['rows'];
    }

    private function row(string $query): ?array
    {
        return $this->sql($query)[0] ?? null;
    }

    private function scalar(string $query): mixed
    {
        $row = $this->row($query);

        return $row === null ? null : reset($row);
    }

    private function live(string $where, bool $withDeleted = false): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM assets WHERE ('.$where.')'.($withDeleted ? '' : ' AND deleted_at IS NULL'));
    }

    private function id(string $tag): int
    {
        return (int) $this->scalar("SELECT id FROM assets WHERE deleted_at IS NULL AND asset_tag = '".$tag."' ORDER BY id DESC LIMIT 1");
    }

    /** @return array<int, string> id => tag, live assets */
    private function tags(): array
    {
        $tags = [];
        foreach ($this->sql('SELECT id, asset_tag FROM assets WHERE deleted_at IS NULL ORDER BY id') as $row) {
            $tags[$row['id']] = $row['asset_tag'];
        }

        return $tags;
    }

    /** An INSERT of one fixture asset (values are test literals). */
    private function insert(array $values): string
    {
        $values += ['model_id' => $this->state['model'], 'status_id' => $this->state['status']];
        $sql = array_map(fn ($value) => match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            default => "'".str_replace("'", "''", (string) $value)."'",
        }, $values);

        return 'INSERT INTO assets (`'.implode('`, `', array_keys($values)).'`, created_at, updated_at) VALUES ('.implode(', ', $sql).', NOW(), NOW())';
    }

    private function rawInsert(array $values): array
    {
        return $this->w(['sql', $this->insert($values)]);
    }

    private function rawInsertSql(string $tag, string $column, string $expression): array
    {
        return $this->w(['sql', 'INSERT INTO assets (asset_tag, `'.$column.'`, model_id, status_id, created_at, updated_at) VALUES (\''.$tag.'\', '.$expression.', '.$this->state['model'].', '.$this->state['status'].', NOW(), NOW())']);
    }

    /** @return array{column: bool, index: bool, owned: bool, status: ?string}|array{} */
    private function key(string $key): array
    {
        foreach ($this->w(['status'])['keys'] as $state) {
            if ($state['key'] === $key) {
                return $state;
            }
        }

        return ['column' => false, 'index' => false, 'owned' => false, 'status' => null];
    }

    private function assertDatabaseKeysHealthy(): void
    {
        $check = $this->w(['artisan', 'ers:asset-uniqueness']);
        $this->assertSame(0, $check['exit'], $check['output']);
    }
}
