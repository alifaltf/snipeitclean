<?php

namespace Tests\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ERS Phase 6C: imports racing each other and a native-style writer, run
 * as separate PHP processes against a real MySQL/MariaDB database, so row
 * locks, named locks and transaction isolation are the database's own.
 *
 * Opt-in (not part of the Unit/Feature suites). It DROPS AND RECREATES
 * every table in the target database. prepare-database.php (and every
 * worker) first verifies with ConcurrencyDatabaseGuard that Laravel
 * resolved exactly the requested dedicated database, whose name must end
 * with "_concurrency_test", and refuses otherwise:
 *
 *   ERS_CONCURRENCY_DB_DATABASE=ers_concurrency_test \
 *   ERS_CONCURRENCY_DB_HOST=127.0.0.1 ERS_CONCURRENCY_DB_PORT=3306 \
 *   ERS_CONCURRENCY_DB_USERNAME=... ERS_CONCURRENCY_DB_PASSWORD=... \
 *   vendor/bin/phpunit tests/Concurrency
 *
 * What it proves and what it does not: see docs/ERS_REQUIREMENTS.md 8.3.
 * The database key on live asset tags (docs/ERS_ASSET_UNIQUENESS.md) is
 * what stops a native writer that checks before an import commits and
 * inserts after it; AssetUniquenessEnforcementTest covers those races.
 */
class AssetImportConcurrencyTest extends TestCase
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

        self::$dir = sys_get_temp_dir().'/ers-concurrency-'.bin2hex(random_bytes(6));
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
            // What the guard compares the resolved configuration against.
            'ERS_CONCURRENCY_DB_DATABASE' => $database,
            'ERS_CONCURRENCY_DB_HOST' => $host,
            'ERS_CONCURRENCY_DB_PORT' => $port,
            'ERS_CONCURRENCY_DIR' => self::$dir,
        ];

        // Guard and migration run in one process, on the verified connection.
        $prepare = new Process(['php', __DIR__.'/prepare-database.php'], dirname(__DIR__, 2), self::$env, null, 600);
        if ($prepare->run() !== 0) {
            throw new RuntimeException('The concurrency database was not prepared: '.trim($prepare->getErrorOutput()));
        }
        self::worker(['setup']);
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

    private static function workerProcess(array $arguments): Process
    {
        return new Process(['php', __DIR__.'/asset-import-worker.php', ...$arguments], dirname(__DIR__, 2), self::$env, null, 300);
    }

    private static function worker(array $arguments): string
    {
        return trim(self::workerProcess($arguments)->mustRun()->getOutput());
    }

    /** @return array{0: string, 1: string} public id, confirmation token */
    private function session(string $prefix, int $from, int $to): array
    {
        $csv = self::$dir.'/'.$prefix.'-'.bin2hex(random_bytes(4)).'.csv';
        $lines = ['Asset Tag'];
        for ($i = $from; $i <= $to; $i++) {
            $lines[] = $prefix.'-'.$i;
        }
        file_put_contents($csv, implode("\n", $lines)."\n");

        return explode(' ', self::worker(['session', $csv]));
    }

    /**
     * Start every command at once and wait for all of them.
     *
     * @param  list<list<string>>  $commands
     * @return list<string>
     */
    private function concurrently(array $commands): array
    {
        $processes = array_map(fn ($command) => self::workerProcess($command), $commands);
        foreach ($processes as $process) {
            $process->start();
        }

        return array_map(fn (Process $process) => trim($process->wait() === 0 ? $process->getOutput() : throw new RuntimeException($process->getErrorOutput())), $processes);
    }

    #[Test]
    public function simultaneous_submissions_of_one_session_import_it_once(): void
    {
        [$id, $token] = $this->session('SAME', 1, 200);

        $results = array_map(fn ($line) => json_decode($line, true), $this->concurrently([
            ['execute', $id, $token, '1500'],
            ['execute', $id, $token],
            ['execute', $id, $token],
        ]));

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(1, count(array_keys($statuses, 'completed', true)), json_encode($results));
        $this->assertSame(200, array_sum(array_column($results, 'created')));
        foreach ($statuses as $status) {
            $this->assertContains($status, ['completed', 'busy', 'unavailable']);
        }
        $this->assertSame('200', self::worker(['count-prefix', 'SAME-']));

        // A later resubmission (retry, back button) finds nothing to run.
        $this->assertSame('unavailable', json_decode(self::worker(['execute', $id, $token]), true)['status']);
        $this->assertSame('200', self::worker(['count-prefix', 'SAME-']));
    }

    #[Test]
    public function competing_imports_never_create_the_same_tag_twice(): void
    {
        [$first, $firstToken] = $this->session('OVL', 1, 150);
        // Overlaps OVL-101..150, differing only in case.
        [$second, $secondToken] = $this->session('ovl', 101, 250);

        $results = array_map(fn ($line) => json_decode($line, true), $this->concurrently([
            ['execute', $first, $firstToken, '1500'],
            ['execute', $second, $secondToken],
        ]));
        $this->assertSame(1, count(array_keys(array_column($results, 'status'), 'completed', true)), json_encode($results));

        // Whichever lost, running it now is refused by validation.
        $loser = $results[0]['status'] === 'completed' ? [$second, $secondToken] : [$first, $firstToken];
        $retry = json_decode(self::worker(['execute', ...$loser]), true);
        $this->assertSame('revalidation_failed', $retry['status']);

        foreach ([101, 125, 150] as $n) {
            $this->assertSame('1', self::worker(['count', 'OVL-'.$n]), "OVL-$n exists once (case-insensitive)");
        }
    }

    #[Test]
    public function a_tag_committed_by_another_writer_during_the_import_rolls_the_import_back(): void
    {
        [$id, $token] = $this->session('MID', 1, 100);

        [$import, $native] = $this->concurrently([
            ['execute', $id, $token, '2000'],
            ['native-insert', 'mid-100', '500'],
        ]);

        $this->assertSame('inserted', $native);
        $result = json_decode($import, true);
        $this->assertSame('execution_failed', $result['status'], $import);
        $this->assertSame(101, $result['errors'][0]['row']);
        $this->assertSame('1', self::worker(['count-prefix', 'MID-']), 'Only the other writer\'s row remains.');
    }
}
