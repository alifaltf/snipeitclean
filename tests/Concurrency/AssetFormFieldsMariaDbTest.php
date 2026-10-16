<?php

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ERS Phase 7C: the shared custom-field eligibility rule on a real
 * MySQL/MariaDB database: the CSV catalog's grouped query (strict GROUP
 * BY), forms, layouts and CSV agreeing, real schema columns created by
 * custom fields, and model changes (single and bulk) that must write
 * nothing when the destination's required field is missing.
 *
 * Opt-in like the other tests in this folder: it DROPS AND RECREATES every
 * table of a dedicated *_concurrency_test database (verified with
 * ConcurrencyDatabaseGuard first).
 */
class AssetFormFieldsMariaDbTest extends TestCase
{
    private static ?string $dir = null;

    /** @var array<string, string> */
    private static array $env = [];

    private static array $state = [];

    public static function setUpBeforeClass(): void
    {
        $database = (string) getenv('ERS_CONCURRENCY_DB_DATABASE');
        if ($database === '') {
            return;
        }
        if (! str_ends_with($database, '_concurrency_test')) {
            throw new RuntimeException('ERS_CONCURRENCY_DB_DATABASE must name a dedicated *_concurrency_test database.');
        }
        self::$dir = sys_get_temp_dir().'/ers-form-fields-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
        $host = (string) (getenv('ERS_CONCURRENCY_DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('ERS_CONCURRENCY_DB_PORT') ?: '3306');
        self::$env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) getenv('ERS_CONCURRENCY_DB_USERNAME'), 'DB_PASSWORD' => (string) getenv('ERS_CONCURRENCY_DB_PASSWORD'), 'DB_SOCKET' => '',
            'ERS_CONCURRENCY_DB_DATABASE' => $database, 'ERS_CONCURRENCY_DB_HOST' => $host, 'ERS_CONCURRENCY_DB_PORT' => $port, 'ERS_CONCURRENCY_DIR' => self::$dir,
            'SESSION_DRIVER' => 'array',
        ];
        $prepare = new Process(['php', __DIR__.'/prepare-database.php'], dirname(__DIR__, 2), self::$env, null, 600);
        if ($prepare->run() !== 0) {
            throw new RuntimeException('The concurrency database was not prepared: '.trim($prepare->getErrorOutput()));
        }
        self::worker(['setup']);
        self::$state = json_decode(self::worker(['state']), true);
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
    }

    private static function worker(array $arguments): string
    {
        $process = new Process(['php', __DIR__.'/form-fields-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env, null, 300);
        if ($process->run() !== 0) {
            throw new RuntimeException('Worker failed: '.trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    private function json(array $arguments): array
    {
        return json_decode(self::worker($arguments), true);
    }

    #[Test]
    public function forms_layouts_and_the_csv_catalog_agree(): void
    {
        $s = self::$state;
        $result = $this->json(['eligibility']);

        $this->assertSame([$s['plain'], $s['box']], $result['form_one'], 'The broken field row is never eligible.');
        $this->assertSame([$s['other'], $s['plain']], $result['form_two']);
        $this->assertEqualsCanonicalizing(['custom_field:'.$s['plain_id'], 'custom_field:'.$s['box_id']], $result['csv_fixed']);
        $this->assertEqualsCanonicalizing(['custom_field:'.$s['plain_id'], 'custom_field:'.$s['box_id'], 'custom_field:'.$s['other_id']], $result['csv_column']);
        $this->assertEqualsCanonicalizing([$s['plain_id'], $s['box_id'], $s['other_id']], $result['layout']);
        $this->assertNotContains($s['broken_id'], $result['layout']);
    }

    #[Test]
    public function single_and_bulk_model_changes_write_nothing_on_failure(): void
    {
        $s = self::$state;

        // Forged field: rejected, nothing written.
        $r = $this->json(['update', '0', json_encode(['name' => 'After', $s['other'] => 'forged'])]);
        $this->assertSame([$s['other']], $r['errors']);
        $this->assertSame(['model_id' => $s['one'], 'name' => 'Before', 'plain' => 'p-F-1', 'other' => null, 'box' => 'b-F-1'], $r['stored']);

        // Model change without the destination's required field: nothing written.
        $r = $this->json(['update', '0', json_encode(['model_id' => $s['two'], 'name' => 'After'])]);
        $this->assertSame([$s['other']], $r['errors']);
        $this->assertSame($s['one'], $r['stored']['model_id']);
        $this->assertSame('Before', $r['stored']['name']);

        // Bulk with an old-fieldset field and a model change: whole request refused.
        $r = $this->json(['bulk', json_encode(['model_id' => $s['two'], $s['other'] => 'v', $s['box'] => 'x'])]);
        $this->assertNotNull($r['error']);
        $this->assertSame([$s['one'], $s['one']], array_column($r['stored'], 'model_id'));

        // Bulk change without the required field: every asset refused.
        $r = $this->json(['bulk', json_encode(['model_id' => $s['two']])]);
        $this->assertSame([$s['one'], $s['one']], array_column($r['stored'], 'model_id'));

        // Valid bulk change: destination field written, old values kept.
        $r = $this->json(['bulk', json_encode(['model_id' => $s['two'], $s['other'] => 'v'])]);
        $this->assertNull($r['error']);
        $this->assertSame([$s['two'], $s['two']], array_column($r['stored'], 'model_id'));
        $this->assertSame(['v', 'v'], array_column($r['stored'], 'other'));
        $this->assertSame(['b-F-1', 'b-F-2'], array_column($r['stored'], 'box'));
    }
}
