<?php

namespace Tests\Concurrency;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/**
 * ERS Phase 6C: decides whether the concurrency tests may destroy and
 * rebuild a database. Fails closed: anything unexpected stops the run
 * before any connection is opened or any table is touched.
 *
 * The decision is made from the configuration Laravel actually resolved,
 * never from the variables the caller asked for:
 *
 *  - no cached configuration (it ignores the environment and could point
 *    anywhere), and no connection URL in the environment or the resolved
 *    connection (it overrides host and database);
 *  - APP_ENV is exactly "testing";
 *  - the default connection is the expected one and its driver is
 *    MySQL/MariaDB;
 *  - the resolved database name equals the explicitly requested one
 *    exactly, and that name ends with "_concurrency_test";
 *  - the resolved host and port equal the explicitly requested ones;
 *  - finally, after connecting, the server's own DATABASE() equals it.
 *
 * Messages name the failed check only: never credentials, URLs, hosts or
 * database names.
 */
final class ConcurrencyDatabaseGuard
{
    public const SUFFIX = '_concurrency_test';

    public const CONNECTION = 'mysql';

    /**
     * What the caller explicitly asked for, from ERS_CONCURRENCY_DB_*.
     *
     * @return array{database: string, host: string, port: int}
     */
    public static function requestedFromEnvironment(): array
    {
        $database = (string) getenv('ERS_CONCURRENCY_DB_DATABASE');
        if ($database === '') {
            throw new ConcurrencyDatabaseGuardException('No dedicated test database was requested.');
        }

        return [
            'database' => $database,
            'host' => (string) (getenv('ERS_CONCURRENCY_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('ERS_CONCURRENCY_DB_PORT') ?: 3306),
        ];
    }

    /**
     * Load only the environment and configuration (no service providers,
     * no database connection) and check them. Returns the verified
     * connection name.
     *
     * @param  array{database: string, host: string, port: int}  $requested
     */
    public static function verifyConfiguration(Application $app, array $requested): string
    {
        $app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);

        $config = $app->make('config');
        $default = (string) $config->get('database.default');

        return self::check([
            'config_cached' => $app->configurationIsCached(),
            'environment' => (string) $config->get('app.env'),
            'url_in_environment' => (string) (getenv('DB_URL') ?: getenv('DATABASE_URL') ?: ''),
            'default_connection' => $default,
            'connection' => (array) $config->get('database.connections.'.$default, []),
        ], $requested);
    }

    /**
     * The pure decision, separated so every rule can be tested without a
     * database.
     *
     * @param  array{config_cached: bool, environment: string, url_in_environment: string, default_connection: string, connection: array<string, mixed>}  $facts
     * @param  array{database: string, host: string, port: int}  $requested
     */
    public static function check(array $facts, array $requested): string
    {
        $fail = fn (string $why) => throw new ConcurrencyDatabaseGuardException('Refusing to touch the database: '.$why.'.');

        if (! str_ends_with($requested['database'], self::SUFFIX) || strlen($requested['database']) <= strlen(self::SUFFIX)) {
            $fail('the requested database is not a dedicated *'.self::SUFFIX.' database');
        }
        if ($facts['config_cached']) {
            $fail('a cached configuration is in use');
        }
        if ($facts['environment'] !== 'testing') {
            $fail('the application environment is not "testing"');
        }
        if ($facts['url_in_environment'] !== '') {
            $fail('a database URL is set in the environment');
        }
        if ($facts['default_connection'] !== self::CONNECTION) {
            $fail('the default connection is not the expected MySQL/MariaDB connection');
        }

        $connection = $facts['connection'];
        if ($connection === []) {
            $fail('the connection is not configured');
        }
        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $fail('the connection driver is not MySQL/MariaDB');
        }
        if (! empty($connection['url'])) {
            $fail('the connection is configured by URL');
        }
        if (! empty($connection['unix_socket'])) {
            $fail('the connection uses a socket instead of the requested host and port');
        }
        if (! empty($connection['read']) || ! empty($connection['write'])) {
            $fail('the connection has separate read/write hosts');
        }
        if (($connection['database'] ?? null) !== $requested['database']) {
            $fail('the resolved database is not the requested one');
        }
        if (($connection['host'] ?? null) !== $requested['host'] || (int) ($connection['port'] ?? 0) !== $requested['port']) {
            $fail('the resolved host or port is not the requested one');
        }

        return $facts['default_connection'];
    }

    /**
     * The only way the concurrency scripts start Laravel: environment and
     * configuration first, the checks above, and only then the rest of the
     * bootstrap (providers may query the database) and a live check of
     * the connected database. Returns the verified connection name, which
     * callers must use explicitly.
     */
    public static function bootstrapVerified(Application $app): string
    {
        $requested = self::requestedFromEnvironment();
        $connection = self::verifyConfiguration($app, $requested);

        $app->bootstrapWith([
            \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
            \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
            \Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
            \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
            \Illuminate\Foundation\Bootstrap\BootProviders::class,
        ]);

        $database = $app->make('db')->connection($connection);
        self::verifyLiveDatabase((string) $database->selectOne('SELECT DATABASE() AS name')->name, $requested);
        if ($app->make('db')->getDefaultConnection() !== $connection) {
            throw new ConcurrencyDatabaseGuardException('Refusing to touch the database: the default connection changed while booting.');
        }

        return $connection;
    }

    /** After connecting: the server must report the requested database. */
    public static function verifyLiveDatabase(string $liveDatabase, array $requested): void
    {
        if ($liveDatabase !== $requested['database']) {
            throw new ConcurrencyDatabaseGuardException('Refusing to touch the database: the connected database is not the requested one.');
        }
    }
}
