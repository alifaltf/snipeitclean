<?php

/*
 * ERS Phase 6C: rebuilds the dedicated concurrency-test database.
 *
 * Destructive (migrate:fresh drops every table), so it runs only after
 * ConcurrencyDatabaseGuard has verified, in this same process, the
 * configuration Laravel resolved and the database the server reports; the
 * migration then runs explicitly on that verified connection.
 *
 * Exit codes: 0 rebuilt, 3 refused by the guard (nothing touched),
 * 1 any other error. Never prints credentials or connection details.
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Concurrency\ConcurrencyDatabaseGuardException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

try {
    $connection = ConcurrencyDatabaseGuard::bootstrapVerified($app);
} catch (ConcurrencyDatabaseGuardException $refused) {
    fwrite(STDERR, $refused->getMessage()."\n");
    exit(3);
} catch (Throwable $error) {
    fwrite(STDERR, 'The guard could not verify the database ('.$error::class.").\n");
    exit(3);
}

echo "guard passed\n";
$status = $app->make(Kernel::class)->call('migrate:fresh', ['--database' => $connection, '--force' => true]);
echo $status === 0 ? "rebuilt\n" : "migration failed\n";
exit($status === 0 ? 0 : 1);
