<?php

use App\Services\AssetUniqueness\AssetUniquenessSchema;
use App\Services\AssetUniqueness\LiveUniqueKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ERS: database-enforced uniqueness among live assets (MySQL/MariaDB only).
 *
 * Creates, through AssetUniquenessSchema::reconcile(), the ERS-owned keys
 * the current configuration requires:
 *  - asset tag: always;
 *  - serial: only while Settings -> "unique serial" is on;
 *  - every custom field that is unique and not encrypted.
 *
 * Before any schema change it audits every key it still has to create
 * (read-only) and stops with per-rule duplicate counts, changing nothing,
 * if existing data would violate one. Existing data is never retagged,
 * merged or deleted.
 *
 * Partial runs: each key is two statements (add column, add index) and
 * schema changes commit one by one. If a later statement fails (for
 * example a duplicate written concurrently while an index was built, or a
 * server error), keys created before it remain, the failed key's column
 * is removed again, and Laravel does not record this migration as run.
 * Running the migration again (or "php artisan ers:asset-uniqueness
 * --repair") is safe: existing ERS keys are detected by name and ledger
 * entry and skipped, never duplicated. down() removes every ERS-owned key
 * and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        app(AssetUniquenessSchema::class)->reconcile(preflight: true);
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $schema = app(AssetUniquenessSchema::class);
        foreach (DB::table(AssetUniquenessSchema::LEDGER)->pluck('key') as $owned) {
            $key = $schema->keyFor($owned) ?? ($owned === LiveUniqueKey::TAG ? LiveUniqueKey::tag() : null);
            if ($key !== null) {
                $schema->deactivate($key);
            } else {
                DB::table(AssetUniquenessSchema::LEDGER)->where('key', $owned)->delete();
            }
        }
    }
};
