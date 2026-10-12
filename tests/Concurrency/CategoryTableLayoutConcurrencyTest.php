<?php

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ERS Phase 7A: category asset-table layouts on a real MySQL/MariaDB
 * database, every writer in its own PHP process.
 *
 * Opt-in like the other tests in this folder: it DROPS AND RECREATES every
 * table of a dedicated *_concurrency_test database (prepare-database.php
 * and every worker verify it with ConcurrencyDatabaseGuard first). Do not
 * run it at the same time as another run against the same database.
 *
 *   ERS_CONCURRENCY_DB_DATABASE=ers_concurrency_test \
 *   ERS_CONCURRENCY_DB_USERNAME=... ERS_CONCURRENCY_DB_PASSWORD=... \
 *   vendor/bin/phpunit tests/Concurrency
 */
class CategoryTableLayoutConcurrencyTest extends TestCase
{
    private static ?string $dir = null;

    /** @var array<string, string> */
    private static array $env = [];

    public static function setUpBeforeClass(): void
    {
        $database = (string) getenv('ERS_CONCURRENCY_DB_DATABASE');
        if ($database === '') {
            return;
        }
        if (! str_ends_with($database, '_concurrency_test')) {
            throw new RuntimeException('ERS_CONCURRENCY_DB_DATABASE must name a dedicated *_concurrency_test database.');
        }

        self::$dir = sys_get_temp_dir().'/ers-layout-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
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
        $prepare = new Process(['php', __DIR__.'/prepare-database.php'], dirname(__DIR__, 2), self::$env, null, 600);
        if ($prepare->run() !== 0) {
            throw new RuntimeException('The concurrency database was not prepared: '.trim($prepare->getErrorOutput()));
        }
    }

    #[Test]
    public function a_save_holding_the_category_lock_makes_a_competing_save_wait_and_the_last_one_wins(): void
    {
        $category = (string) $this->w(['category'])['id'];

        $gate = 'save-'.bin2hex(random_bytes(4));
        $first = $this->start(['save', $category, 'standard:asset_tag,standard:name,standard:serial'], ['ERS_BARRIER_IN_SAVE' => $gate]);
        $this->reached($gate, $first);

        $second = $this->start(['save', $category, 'standard:serial,standard:status']);
        usleep(1_500_000);
        $this->assertTrue($second->isRunning(), 'The second save waits for the category lock.');

        touch(self::$dir.'/'.$gate.'.go');
        $this->assertSame(['standard:asset_tag', 'standard:name', 'standard:serial'], $this->finish($first)['saved']);
        $this->assertSame(['standard:serial', 'standard:status'], $this->finish($second)['saved']);

        $this->assertSame([['standard:serial', 1], ['standard:status', 2]], $this->w(['rows', $category])['rows'], 'Exactly the last committed layout, no mixture.');
    }

    #[Test]
    public function simultaneous_saves_and_resets_never_store_duplicates_or_a_mixture(): void
    {
        $category = (string) $this->w(['category'])['id'];
        $layouts = [
            ['standard:asset_tag', 'standard:name'],
            ['standard:name', 'standard:asset_tag', 'standard:serial'],
            ['standard:serial'],
            ['standard:status', 'standard:model', 'standard:asset_tag', 'standard:notes'],
            ['standard:asset_tag', 'standard:name'],
        ];

        for ($round = 0; $round < 3; $round++) {
            $processes = array_map(fn (array $keys) => $this->start(['save', $category, implode(',', $keys)]), $layouts);
            $processes[] = $this->start(['reset', $category]);
            foreach ($processes as $process) {
                $this->finish($process);
            }

            $rows = $this->w(['rows', $category])['rows'];
            if ($rows === []) {
                continue; // the reset committed last
            }
            $keys = array_column($rows, 0);
            $this->assertContains($keys, $layouts, 'The stored layout is exactly one submitted layout.');
            $this->assertSame(range(1, count($rows)), array_column($rows, 1));
        }
    }

    #[Test]
    public function the_database_enforces_the_constraints_and_the_migration_rolls_back_and_reapplies(): void
    {
        $schema = $this->w(['schema']);
        $this->assertTrue($schema['table']);
        $this->assertSame(['columns' => ['category_id', 'field_key'], 'unique' => true], $schema['indexes']['acc_category_field_unique']);
        $this->assertSame(['columns' => ['category_id', 'position'], 'unique' => true], $schema['indexes']['acc_category_position_unique']);
        $this->assertSame([[['category_id'], 'categories', 'cascade']], $schema['foreign']);

        $category = (string) $this->w(['category'])['id'];
        $other = (string) $this->w(['category'])['id'];
        $this->w(['save', $category, 'standard:serial']);
        $this->w(['save', $other, 'standard:serial']);

        // Duplicates are refused by the database itself.
        foreach (["INSERT INTO asset_category_columns (category_id, field_key, position) VALUES ($category, 'standard:serial', 2)",
            "INSERT INTO asset_category_columns (category_id, field_key, position) VALUES ($category, 'standard:name', 1)"] as $statement) {
            $this->assertSame(['ok' => false, 'sqlstate' => '23000', 'code' => 1062], $this->w(['sql', $statement]));
        }

        // Purging the category row cascades to its layout only.
        $this->w(['sql', "DELETE FROM categories WHERE id = $category"]);
        $this->assertSame([], $this->w(['rows', $category])['rows']);
        $this->assertSame([['standard:serial', 1]], $this->w(['rows', $other])['rows']);

        $rollback = $this->w(['artisan', 'migrate:rollback --batch=1 --path=database/migrations/2026_10_12_000000_create_asset_category_columns_table.php']);
        $this->assertSame(0, $rollback['exit'], $rollback['output']);
        $this->assertStringContainsString('2026_10_12_000000_create_asset_category_columns_table', $rollback['output']);
        $this->assertFalse($this->w(['schema'])['table']);

        $migrate = $this->w(['artisan', 'migrate']);
        $this->assertSame(0, $migrate['exit'], $migrate['output']);
        $this->assertTrue($this->w(['schema'])['table']);
    }

    private function process(array $arguments, array $env = []): Process
    {
        return new Process(['php', __DIR__.'/table-layout-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env + $env, null, 300);
    }

    private function w(array $arguments): array
    {
        $process = $this->process($arguments);
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
}
