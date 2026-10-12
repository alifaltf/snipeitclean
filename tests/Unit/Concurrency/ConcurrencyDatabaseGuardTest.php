<?php

namespace Tests\Unit\Concurrency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\ConcurrencyDatabaseGuardException;

/**
 * ERS Phase 6C: the guard in front of the destructive concurrency tests.
 * Nothing here touches a database: every process case points at an
 * unreachable documentation address (192.0.2.0/24) and must be refused
 * before any connection is attempted or any migration is started.
 */
class ConcurrencyDatabaseGuardTest extends TestCase
{
    private const SAFE = ['database' => 'ers_concurrency_test', 'host' => '127.0.0.1', 'port' => 3306];

    private const SECRET = 'S3cret-Pa55';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private static function facts(array $overrides = [], array $connection = []): array
    {
        return array_merge([
            'config_cached' => false,
            'environment' => 'testing',
            'url_in_environment' => '',
            'default_connection' => 'mysql',
            'connection' => array_merge([
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'ers_concurrency_test',
                'username' => 'ers', 'password' => self::SECRET, 'unix_socket' => '',
            ], $connection),
        ], $overrides);
    }

    #[Test]
    public function the_exact_requested_dedicated_database_passes(): void
    {
        $this->assertSame('mysql', ConcurrencyDatabaseGuard::check(self::facts(), self::SAFE));
        $this->assertSame('mysql', ConcurrencyDatabaseGuard::check(self::facts([], ['driver' => 'mariadb']), self::SAFE));
    }

    public static function unsafeFacts(): array
    {
        return [
            'safe name requested, other database resolved' => [self::facts([], ['database' => 'snipeit']), self::SAFE, 'the resolved database is not the requested one'],
            'resolved name differs only by a suffix' => [self::facts([], ['database' => 'ers_concurrency_test_x']), self::SAFE, 'the resolved database is not the requested one'],
            'requested name is not dedicated' => [self::facts([], ['database' => 'snipeit']), ['database' => 'snipeit'] + self::SAFE, 'the requested database is not a dedicated'],
            'bare suffix requested' => [self::facts([], ['database' => '_concurrency_test']), ['database' => '_concurrency_test'] + self::SAFE, 'the requested database is not a dedicated'],
            'cached configuration' => [self::facts(['config_cached' => true]), self::SAFE, 'a cached configuration is in use'],
            'URL in the environment' => [self::facts(['url_in_environment' => 'mysql://u:'.self::SECRET.'@10.0.0.1/snipeit']), self::SAFE, 'a database URL is set in the environment'],
            'URL in the connection' => [self::facts([], ['url' => 'mysql://u:'.self::SECRET.'@10.0.0.1/snipeit']), self::SAFE, 'the connection is configured by URL'],
            'production environment' => [self::facts(['environment' => 'production']), self::SAFE, 'the application environment is not "testing"'],
            'local environment' => [self::facts(['environment' => 'local']), self::SAFE, 'the application environment is not "testing"'],
            'other default connection' => [self::facts(['default_connection' => 'sqlite_testing']), self::SAFE, 'the default connection is not the expected'],
            'missing connection' => [self::facts(['connection' => []]), self::SAFE, 'the connection is not configured'],
            'sqlite driver' => [self::facts([], ['driver' => 'sqlite']), self::SAFE, 'the connection driver is not MySQL/MariaDB'],
            'other host' => [self::facts([], ['host' => 'db.example.internal']), self::SAFE, 'the resolved host or port is not the requested one'],
            'other port' => [self::facts([], ['port' => '3307']), self::SAFE, 'the resolved host or port is not the requested one'],
            'socket' => [self::facts([], ['unix_socket' => '/run/mysqld/mysqld.sock']), self::SAFE, 'the connection uses a socket'],
            'read/write split' => [self::facts([], ['read' => ['host' => ['10.0.0.2']]]), self::SAFE, 'separate read/write hosts'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeFacts')]
    public function anything_unexpected_is_refused_without_leaking_details(array $facts, array $requested, string $reason): void
    {
        try {
            ConcurrencyDatabaseGuard::check($facts, $requested);
            $this->fail('Must be refused.');
        } catch (ConcurrencyDatabaseGuardException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
            foreach ([self::SECRET, 'mysql://', '10.0.0.1', 'db.example.internal', 'snipeit', '/run/mysqld'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_connected_database_other_than_the_requested_one_is_refused(): void
    {
        ConcurrencyDatabaseGuard::verifyLiveDatabase('ers_concurrency_test', self::SAFE);
        $this->expectException(ConcurrencyDatabaseGuardException::class);
        ConcurrencyDatabaseGuard::verifyLiveDatabase('snipeit', self::SAFE);
    }

    /** Run prepare-database.php with a controlled environment. */
    private function prepare(array $env): Process
    {
        $base = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'ers_concurrency_test',
            'DB_USERNAME' => 'guard_test',
            'DB_PASSWORD' => self::SECRET,
            'DB_SOCKET' => '',
            'DB_URL' => false,
            'DATABASE_URL' => false,
            'APP_CONFIG_CACHE' => false,
            'ERS_CONCURRENCY_DB_DATABASE' => 'ers_concurrency_test',
            'ERS_CONCURRENCY_DB_HOST' => '127.0.0.1',
            'ERS_CONCURRENCY_DB_PORT' => '3306',
        ];
        $process = new Process(['php', dirname(__DIR__, 2).'/Concurrency/prepare-database.php'], dirname(__DIR__, 3), array_merge($base, $env), null, 60);
        $process->run();

        return $process;
    }

    private function assertRefusedBeforeAnything(Process $process, string $reason): void
    {
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(3, $process->getExitCode(), $output);
        $this->assertStringContainsString($reason, $process->getErrorOutput());
        $this->assertStringNotContainsString('guard passed', $process->getOutput(), 'The migration must never start.');
        $this->assertStringNotContainsString('rebuilt', $process->getOutput());
        foreach ([self::SECRET, 'guard_test', 'mysql://', '192.0.2.'] as $secret) {
            $this->assertStringNotContainsString($secret, $output, 'Never print credentials or connection details.');
        }
    }

    #[Test]
    public function a_safe_requested_name_cannot_override_an_unsafe_resolved_database(): void
    {
        $this->assertRefusedBeforeAnything(
            $this->prepare(['DB_HOST' => '192.0.2.10', 'DB_DATABASE' => 'snipeit', 'ERS_CONCURRENCY_DB_HOST' => '192.0.2.10']),
            'the resolved database is not the requested one'
        );
    }

    #[Test]
    public function a_database_url_pointing_elsewhere_is_refused(): void
    {
        $this->assertRefusedBeforeAnything(
            $this->prepare(['DB_URL' => 'mysql://guard_test:'.self::SECRET.'@192.0.2.11:3306/snipeit']),
            'a database URL is set in the environment'
        );
        $this->assertRefusedBeforeAnything(
            $this->prepare(['DATABASE_URL' => 'mysql://guard_test:'.self::SECRET.'@192.0.2.11:3306/snipeit']),
            'a database URL is set in the environment'
        );
    }

    #[Test]
    public function a_cached_configuration_pointing_elsewhere_is_refused(): void
    {
        // A configuration cache in a temporary file (never the project's
        // bootstrap/cache), resolving to another database on another host.
        $cache = tempnam(sys_get_temp_dir(), 'ers-guard-config-').'.php';
        $this->tempFiles[] = $cache;
        $dump = new Process(['php', '-r', '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $app->bootstrapWith([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, Illuminate\Foundation\Bootstrap\LoadConfiguration::class]);
            file_put_contents(getenv("ERS_GUARD_CACHE_FILE"), "<?php return ".var_export($app->make("config")->all(), true).";");
        '], dirname(__DIR__, 3), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '192.0.2.12', 'DB_DATABASE' => 'snipeit',
            'DB_USERNAME' => 'guard_test', 'DB_PASSWORD' => self::SECRET, 'APP_CONFIG_CACHE' => false, 'ERS_GUARD_CACHE_FILE' => $cache,
        ]);
        $dump->mustRun();
        chmod($cache, 0600);
        $this->assertStringContainsString("'snipeit'", (string) file_get_contents($cache));

        // The environment asks for the safe database; the cache wins in
        // Laravel, so the guard must refuse.
        $this->assertRefusedBeforeAnything($this->prepare(['APP_CONFIG_CACHE' => $cache]), 'a cached configuration is in use');
    }

    #[Test]
    public function non_testing_environments_are_refused(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->assertRefusedBeforeAnything(
                $this->prepare(['APP_ENV' => $environment, 'DB_HOST' => '192.0.2.13', 'ERS_CONCURRENCY_DB_HOST' => '192.0.2.13']),
                'the application environment is not "testing"'
            );
        }
    }

    #[Test]
    public function mismatched_connections_hosts_and_ports_are_refused(): void
    {
        $this->assertRefusedBeforeAnything($this->prepare(['DB_CONNECTION' => 'sqlite_testing']), 'the default connection is not the expected');
        $this->assertRefusedBeforeAnything($this->prepare(['DB_HOST' => '192.0.2.14']), 'the resolved host or port is not the requested one');
        $this->assertRefusedBeforeAnything($this->prepare(['DB_PORT' => '3399']), 'the resolved host or port is not the requested one');
        $this->assertRefusedBeforeAnything($this->prepare(['DB_SOCKET' => '/tmp/ers-guard-test.sock']), 'the connection uses a socket');
    }

    #[Test]
    public function an_undedicated_or_missing_request_is_refused(): void
    {
        $this->assertRefusedBeforeAnything(
            $this->prepare(['DB_DATABASE' => 'snipeit', 'ERS_CONCURRENCY_DB_DATABASE' => 'snipeit']),
            'the requested database is not a dedicated'
        );
        $this->assertRefusedBeforeAnything($this->prepare(['ERS_CONCURRENCY_DB_DATABASE' => false]), 'No dedicated test database was requested');
    }
}
