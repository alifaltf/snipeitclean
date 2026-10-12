<?php

namespace App\Console\Commands;

use App\Services\AssetUniqueness\AssetUniquenessException;
use App\Services\AssetUniqueness\AssetUniquenessSchema;
use Illuminate\Console\Command;

/**
 * ERS: check, and with --repair restore, the database uniqueness keys.
 *
 * Without options it only reads: which keys the settings and custom fields
 * require, which exist, interrupted changes (ledger rows still
 * "activating"/"deactivating") and ers_live_* objects ERS does not own.
 * --repair runs the same idempotent reconcile as the migration: audit
 * first (stops with duplicate counts, changing nothing), then create the
 * missing keys and remove the ERS keys no longer required. It never
 * changes asset data and never touches objects ERS does not own.
 */
class ErsAssetUniqueness extends Command
{
    protected $signature = 'ers:asset-uniqueness {--repair : Create missing keys and remove unwanted ERS keys}';

    protected $description = 'ERS: check or repair the database-enforced asset uniqueness keys.';

    public function handle(AssetUniquenessSchema $schema): int
    {
        if (! $schema->isSupported()) {
            $this->info('Database uniqueness keys are only used on MySQL/MariaDB.');

            return self::SUCCESS;
        }

        if ($this->option('repair')) {
            try {
                $report = $schema->reconcile(preflight: true);
            } catch (AssetUniquenessException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
            $this->info('Created: '.(implode(', ', $report['created']) ?: 'none'));
            $this->info('Removed: '.(implode(', ', $report['removed']) ?: 'none'));
        }

        $status = $schema->status();
        $healthy = $status['foreign'] === [];
        $rows = [];
        foreach ($status['keys'] as $key) {
            $ok = $key['wanted']
                ? ($key['column'] && $key['index'] && $key['owned'] && $key['status'] === AssetUniquenessSchema::ACTIVE)
                : (! $key['column'] && ! $key['owned']);
            $healthy = $healthy && $ok;
            $rows[] = [$key['key'], $key['label'], $key['wanted'] ? 'yes' : 'no', $key['column'] && $key['index'] ? 'yes' : ($key['column'] ? 'partial' : 'no'), $key['status'] ?? '-', $ok ? 'ok' : 'NEEDS REPAIR'];
        }
        $this->table(['Key', 'Field', 'Required', 'Enforced', 'Ledger', 'State'], $rows);
        foreach ($status['foreign'] as $column) {
            $this->warn('Not created by ERS (left untouched): '.$column);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
