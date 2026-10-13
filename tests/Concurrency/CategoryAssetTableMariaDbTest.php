<?php

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ERS Phase 7B: the category asset table's database-side search, sorting
 * and export on a real MySQL/MariaDB database (collation, LIKE escaping,
 * numeric custom-field sorting, scope subqueries).
 *
 * Opt-in like the other tests in this folder: it DROPS AND RECREATES every
 * table of a dedicated *_concurrency_test database (verified with
 * ConcurrencyDatabaseGuard first).
 */
class CategoryAssetTableMariaDbTest extends TestCase
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
        self::$dir = sys_get_temp_dir().'/ers-category-table-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
        $host = (string) (getenv('ERS_CONCURRENCY_DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('ERS_CONCURRENCY_DB_PORT') ?: '3306');
        self::$env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) getenv('ERS_CONCURRENCY_DB_USERNAME'), 'DB_PASSWORD' => (string) getenv('ERS_CONCURRENCY_DB_PASSWORD'), 'DB_SOCKET' => '',
            'ERS_CONCURRENCY_DB_DATABASE' => $database, 'ERS_CONCURRENCY_DB_HOST' => $host, 'ERS_CONCURRENCY_DB_PORT' => $port, 'ERS_CONCURRENCY_DIR' => self::$dir,
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
        $process = new Process(['php', __DIR__.'/category-table-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env, null, 300);
        if ($process->run() !== 0) {
            throw new RuntimeException('Worker failed: '.trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    private function api(array $query): array
    {
        return json_decode(self::worker(['api', json_encode($query)]), true);
    }

    #[Test]
    public function search_is_database_side_case_and_accent_insensitive_and_literal(): void
    {
        $this->assertSame(['T-B'], $this->api(['search' => 'eclair'])['tags'], 'accent and case insensitive (column collation)');
        $this->assertSame(['T-B'], $this->api(['search' => 'r_o'])['tags'], 'underscore is literal');
        $this->assertSame(['T-A'], $this->api(['search' => '100%'])['tags'], 'percent is literal');
        $this->assertSame([], $this->api(['search' => 'S1'])['tags'], 'encrypted values are never searched');
        $this->assertSame(1, $this->api(['filter' => json_encode([self::$state['plain'] => 'cherry'])])['total']);
        $this->assertSame(4, $this->api([])['total']);
        $this->assertSame([], $this->api(['search' => 'stale'])['tags'], 'a leftover value never matches');
    }

    #[Test]
    public function sorting_by_layout_columns_works_on_the_database(): void
    {
        $this->assertSame(['T-A', 'T-B', 'T-C', 'T-S'], $this->api(['sort' => 'asset_tag', 'order' => 'asc'])['tags']);
        // The stale asset has no value: first ascending, last descending
        // (NULL), never placed by its leftover text or number.
        $this->assertSame(['T-S', 'T-A', 'T-B', 'T-C'], $this->api(['sort' => self::$state['number'], 'order' => 'asc'])['tags'], 'numeric custom field sorts as numbers');
        $this->assertSame(['T-C', 'T-B', 'T-A', 'T-S'], $this->api(['sort' => self::$state['number'], 'order' => 'desc'])['tags']);
        $this->assertSame(['T-S', 'T-A', 'T-C', 'T-B'], $this->api(['sort' => 'custom_fields.'.self::$state['plain'], 'order' => 'asc'])['tags']);
        $this->assertSame(['T-B', 'T-C', 'T-A', 'T-S'], $this->api(['sort' => self::$state['plain'], 'order' => 'desc'])['tags']);
        $this->assertCount(4, $this->api(['sort' => 'status', 'order' => 'asc'])['tags']);
        $this->assertCount(4, $this->api(['sort' => 'model', 'order' => 'desc'])['tags']);
        $this->assertCount(4, $this->api(['sort' => self::$state['secret']])['tags'], 'encrypted: ignored, default order');
        $this->assertSame(['asset_tag', self::$state['plain'], self::$state['number'], self::$state['secret'], 'status', 'model'], $this->api([])['fields']);
    }

    #[Test]
    public function the_export_runs_on_the_database_with_escaped_values(): void
    {
        $result = json_decode(self::worker(['export', json_encode(['sort' => 'asset_tag', 'order' => 'asc'])]), true);
        $this->assertSame(200, $result['status']);
        $csv = base64_decode($result['csv']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        $this->assertCount(5, $lines);
        $this->assertStringStartsWith('T-A,"apple 100%",9,S2', $lines[1], 'a Super User sees decrypted values');
        $this->assertStringStartsWith('T-B,Éclair_one,10,S1', $lines[2]);
        $this->assertStringStartsWith('T-S,,,,', $lines[4], 'no leftover values exported');
    }
}
