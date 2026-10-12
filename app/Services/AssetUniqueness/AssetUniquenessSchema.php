<?php

namespace App\Services\AssetUniqueness;

use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ERS: creates, removes and checks the database uniqueness keys described
 * in LiveUniqueKey (MySQL/MariaDB only; every method is a no-op on other
 * drivers, where the application rules still apply).
 *
 * Schema changes (DDL) commit implicitly on MySQL/MariaDB and cannot be
 * rolled back by a transaction, so this class never relies on one:
 *
 *  - every change runs under one database named lock, so changes never
 *    overlap (settings, custom fields, migrations, the repair command);
 *  - it refuses to run inside an open transaction (the DDL would silently
 *    commit the caller's work);
 *  - before any DDL it records the intent in the ERS ledger
 *    (ers_asset_uniqueness_keys: activating / active / deactivating). An
 *    object named ers_live_* without a ledger row is not ERS-owned and is
 *    never touched; a ledger row still "activating" or "deactivating" marks
 *    an interrupted change, which reconcile() repairs idempotently;
 *  - activation first counts duplicates (read-only audit), then adds the
 *    key column and builds the UNIQUE index with LOCK=SHARED: writes to
 *    assets wait while the index is built, and the build itself fails if
 *    any duplicate exists, so no write can slip in between the check and
 *    enforcement. On failure the half-made key column is removed again.
 */
final class AssetUniquenessSchema
{
    public const LEDGER = 'ers_asset_uniqueness_keys';

    public const ACTIVATING = 'activating';

    public const ACTIVE = 'active';

    public const DEACTIVATING = 'deactivating';

    private static int $lockDepth = 0;

    public function isSupported(): bool
    {
        return ColumnCollation::isMysql();
    }

    private static bool $ledgerSeen = false;

    /**
     * Supported and installed: the ledger table exists. Before migration
     * 2026_10_08_000000 has run (for example while earlier migrations
     * create Snipe-IT's own custom fields) there are no ERS keys, so the
     * field and settings transitions have nothing to do.
     */
    public function isReady(): bool
    {
        if (! $this->isSupported()) {
            return false;
        }
        if (! self::$ledgerSeen) {
            self::$ledgerSeen = Schema::hasTable(self::LEDGER);
        }

        return self::$ledgerSeen;
    }

    /** After the ledger table was dropped (its migration rolled back). */
    public static function forgetReadiness(): void
    {
        self::$ledgerSeen = false;
    }

    /**
     * Run $callback holding the schema lock (re-entrant within this
     * process). Throws LOCKED when another change holds it longer than
     * $waitSeconds.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLock(callable $callback, int $waitSeconds = 30): mixed
    {
        if (! $this->isSupported()) {
            return $callback();
        }

        $name = 'ers_asset_uniqueness_'.substr(hash('sha256', (string) DB::connection()->getDatabaseName()), 0, 32);
        if (self::$lockDepth === 0) {
            $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, $waitSeconds])->acquired ?? 0);
            if ($acquired !== 1) {
                throw new AssetUniquenessException(AssetUniquenessException::LOCKED, trans('admin/hardware/uniqueness.locked'));
            }
        }
        self::$lockDepth++;
        try {
            return $callback();
        } finally {
            self::$lockDepth--;
            if (self::$lockDepth === 0) {
                try {
                    DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
                } catch (Throwable) {
                    // Released anyway when the connection closes.
                }
            }
        }
    }

    /** Whether a custom field must have a database key (unique, not encrypted, has a column). */
    public static function fieldWantsKey(CustomField $field): bool
    {
        return (string) $field->is_unique === '1'
            && (string) $field->field_encrypted !== '1'
            && is_string($field->db_column) && $field->db_column !== '';
    }

    /** @return array<string, LiveUniqueKey> every key the current settings and custom fields require */
    public function desiredKeys(): array
    {
        $keys = [LiveUniqueKey::TAG => LiveUniqueKey::tag()];
        if ((string) DB::table('settings')->orderBy('id')->value('unique_serial') === '1') {
            $keys[LiveUniqueKey::SERIAL] = LiveUniqueKey::serial();
        }
        foreach (CustomField::query()->get() as $field) {
            if (self::fieldWantsKey($field) && ColumnCollation::of((string) $field->db_column, true) !== null) {
                $key = LiveUniqueKey::customField((int) $field->id, (string) $field->db_column);
                $keys[$key->key] = $key;
            }
        }

        return $keys;
    }

    public function keyFor(string $key): ?LiveUniqueKey
    {
        if ($key === LiveUniqueKey::TAG) {
            return LiveUniqueKey::tag();
        }
        if ($key === LiveUniqueKey::SERIAL) {
            return LiveUniqueKey::serial();
        }
        if (preg_match('/\Acustom_field:([1-9][0-9]*)\z/', $key, $m) === 1) {
            $column = CustomField::query()->whereKey((int) $m[1])->value('db_column')
                ?? DB::table(self::LEDGER)->where('key', $key)->value('source_column');

            return is_string($column) && $column !== '' ? LiveUniqueKey::customField((int) $m[1], $column) : null;
        }

        return null;
    }

    /**
     * Number of distinct values that are used by more than one live asset
     * under this key's rule (0 means the index can be built). Read-only.
     */
    public function audit(LiveUniqueKey $key): int
    {
        if (! $this->isSupported()) {
            return 0;
        }
        $source = ColumnCollation::of($key->sourceColumn, true);
        if ($source === null) {
            return 0;
        }
        $expression = $key->expression($source);

        return (int) DB::selectOne(
            'SELECT COUNT(*) AS duplicate_values FROM (SELECT 1 FROM '.$this->table().' WHERE ('.$expression.') IS NOT NULL GROUP BY ('.$expression.') HAVING COUNT(*) > 1) duplicates'
        )->duplicate_values;
    }

    /**
     * What exists for a key: its column, its index, and whether ERS owns
     * them (ledger row present).
     *
     * @return array{column: bool, index: bool, owned: bool, status: ?string}
     */
    public function state(LiveUniqueKey $key): array
    {
        if (! $this->isSupported()) {
            return ['column' => false, 'index' => false, 'owned' => false, 'status' => null];
        }
        $column = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->tableName(), $key->column]
        )->n > 0;
        $index = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 AND COLUMN_NAME = ?',
            [$this->tableName(), $key->index, $key->column]
        )->n > 0;
        $status = DB::table(self::LEDGER)->where('key', $key->key)->value('status');

        return ['column' => $column, 'index' => $index, 'owned' => $status !== null, 'status' => $status];
    }

    /** Create the key (idempotent). Throws AssetUniquenessException when it cannot be enforced. */
    public function activate(LiveUniqueKey $key, ?string $label = null): void
    {
        if (! $this->isSupported()) {
            return;
        }
        $this->refuseInsideTransaction();

        $this->withLock(function () use ($key, $label) {
            $state = $this->state($key);
            if (($state['column'] || $state['index']) && ! $state['owned']) {
                throw $this->foreign($key, $label);
            }
            if ($state['column'] && $state['index']) {
                $this->ledger($key, self::ACTIVE);

                return;
            }

            $conflicts = $this->audit($key);
            if ($conflicts > 0) {
                if ($state['column']) {
                    $this->dropKeyColumn($key);
                }
                DB::table(self::LEDGER)->where('key', $key->key)->delete();
                throw $this->conflicts([$label ?? $this->label($key) => $conflicts]);
            }

            $source = ColumnCollation::of($key->sourceColumn, true);
            if ($source === null) {
                throw new AssetUniquenessException(AssetUniquenessException::SCHEMA_CHANGE_FAILED, trans('admin/hardware/uniqueness.schema_change_failed'));
            }
            $definition = $key->definition($source);
            $this->ledger($key, self::ACTIVATING, $definition);

            try {
                if (! $state['column']) {
                    DB::statement('ALTER TABLE '.$this->table().' ADD COLUMN `'.$key->column.'` '.$definition);
                }
                // LOCK=SHARED: writers wait while the index is built; the build
                // fails if any duplicate exists, including one written just
                // before the lock was taken.
                DB::statement('ALTER TABLE '.$this->table().' ADD UNIQUE INDEX `'.$key->index.'` (`'.$key->column.'`), LOCK=SHARED');
            } catch (Throwable $exception) {
                $duplicate = UniqueConstraintViolation::isDuplicateKey($exception);
                $this->cleanUpFailedActivation($key);
                if ($duplicate) {
                    throw $this->conflicts([$label ?? $this->label($key) => max(1, $this->audit($key))]);
                }
                Log::error('ERS asset uniqueness: adding a key failed.', ['key' => $key->key, 'exception' => $exception::class]);

                throw new AssetUniquenessException(AssetUniquenessException::SCHEMA_CHANGE_FAILED, trans('admin/hardware/uniqueness.schema_change_failed'));
            } finally {
                ColumnCollation::forget();
            }

            $this->ledger($key, self::ACTIVE, $definition);
        });
    }

    /** Remove the key (idempotent). Only ERS-owned objects are removed. */
    public function deactivate(LiveUniqueKey $key, ?string $label = null): void
    {
        if (! $this->isSupported()) {
            return;
        }
        $this->refuseInsideTransaction();

        $this->withLock(function () use ($key, $label) {
            $state = $this->state($key);
            if (($state['column'] || $state['index']) && ! $state['owned']) {
                throw $this->foreign($key, $label);
            }
            if ($state['column']) {
                $this->ledger($key, self::DEACTIVATING);
                try {
                    $this->dropKeyColumn($key);
                } catch (Throwable $exception) {
                    Log::error('ERS asset uniqueness: removing a key failed.', ['key' => $key->key, 'exception' => $exception::class]);

                    throw new AssetUniquenessException(AssetUniquenessException::SCHEMA_CHANGE_FAILED, trans('admin/hardware/uniqueness.schema_change_failed'));
                } finally {
                    ColumnCollation::forget();
                }
            }
            DB::table(self::LEDGER)->where('key', $key->key)->delete();
        });
    }

    /**
     * Drop a custom field's assets column together with its key in ONE
     * ALTER TABLE (MariaDB refuses to drop a column a generated column
     * uses, and a single statement either does both or neither). Returns
     * false when nothing could be dropped.
     */
    public function dropCustomFieldColumn(int $fieldId, string $dbColumn): bool
    {
        ColumnCollation::guard($dbColumn);
        if (! $this->isSupported()) {
            return false;
        }
        $this->refuseInsideTransaction();

        return $this->withLock(function () use ($fieldId, $dbColumn) {
            $key = LiveUniqueKey::customField($fieldId, $dbColumn);
            $state = $this->state($key);
            if ($state['column'] && ! $state['owned']) {
                throw $this->foreign($key, null);
            }
            if ($state['column']) {
                $this->ledger($key, self::DEACTIVATING);
                DB::statement('ALTER TABLE '.$this->table().' DROP COLUMN `'.$key->column.'`, DROP COLUMN `'.$dbColumn.'`');
            } else {
                DB::statement('ALTER TABLE '.$this->table().' DROP COLUMN `'.$dbColumn.'`');
            }
            DB::table(self::LEDGER)->where('key', $key->key)->delete();
            ColumnCollation::forget();

            return true;
        });
    }

    /**
     * Make the schema match the settings and custom fields: create every
     * missing key, remove every ERS key no longer wanted, finish or undo
     * interrupted changes. Idempotent. With $preflight, every key that
     * still has to be created is audited first and nothing is changed if
     * any of them has duplicates.
     *
     * @return array{created: list<string>, removed: list<string>, unchanged: list<string>}
     */
    public function reconcile(bool $preflight = true): array
    {
        $report = ['created' => [], 'removed' => [], 'unchanged' => []];
        if (! $this->isSupported()) {
            return $report;
        }
        $this->refuseInsideTransaction();

        return $this->withLock(function () use ($preflight, $report) {
            $desired = $this->desiredKeys();

            if ($preflight) {
                $conflicts = [];
                foreach ($desired as $key) {
                    $state = $this->state($key);
                    if (($state['column'] || $state['index']) && ! $state['owned']) {
                        throw $this->foreign($key, null);
                    }
                    if (! ($state['column'] && $state['index']) && ($count = $this->audit($key)) > 0) {
                        $conflicts[$this->label($key)] = $count;
                    }
                }
                if ($conflicts !== []) {
                    throw $this->conflicts($conflicts);
                }
            }

            foreach ($desired as $key) {
                $state = $this->state($key);
                if ($state['column'] && $state['index'] && $state['status'] === self::ACTIVE) {
                    $report['unchanged'][] = $key->key;

                    continue;
                }
                $this->activate($key);
                $report['created'][] = $key->key;
            }

            foreach (DB::table(self::LEDGER)->pluck('key') as $owned) {
                if (! isset($desired[$owned]) && ($key = $this->keyFor($owned)) !== null) {
                    $this->deactivate($key);
                    $report['removed'][] = $owned;
                } elseif (! isset($desired[$owned]) && $key === null) {
                    DB::table(self::LEDGER)->where('key', $owned)->delete();
                }
            }

            return $report;
        });
    }

    /**
     * Read-only overview for the check command: every wanted or owned key
     * with what exists, plus ers_live_* columns ERS does not own.
     *
     * @return array{keys: list<array{key: string, label: string, wanted: bool, column: bool, index: bool, owned: bool, status: ?string}>, foreign: list<string>}
     */
    public function status(): array
    {
        if (! $this->isSupported()) {
            return ['keys' => [], 'foreign' => []];
        }
        $desired = $this->desiredKeys();
        $keys = $desired;
        foreach (DB::table(self::LEDGER)->pluck('key') as $owned) {
            if (! isset($keys[$owned]) && ($key = $this->keyFor($owned)) !== null) {
                $keys[$owned] = $key;
            }
        }

        $rows = [];
        $ownedColumns = [];
        foreach ($keys as $name => $key) {
            $state = $this->state($key);
            if ($state['owned']) {
                $ownedColumns[] = $key->column;
            }
            $rows[] = ['key' => $name, 'label' => $this->label($key), 'wanted' => isset($desired[$name])] + $state;
        }

        $foreign = [];
        $columns = DB::select("SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE 'ers\\_live\\_%'", [$this->tableName()]);
        foreach ($columns as $column) {
            if (! in_array($column->name, $ownedColumns, true)) {
                $foreign[] = (string) $column->name;
            }
        }

        return ['keys' => $rows, 'foreign' => $foreign];
    }

    public function label(LiveUniqueKey $key): string
    {
        return match (true) {
            $key->key === LiveUniqueKey::TAG => trans('general.asset_tag'),
            $key->key === LiveUniqueKey::SERIAL => trans('general.serial_number'),
            default => (string) (CustomField::query()->whereKey($key->customFieldId)->value('name') ?? $key->sourceColumn),
        };
    }

    /**
     * After the source column of an active key was renamed (MariaDB/MySQL
     * rewrite the generated expression themselves): record the new source
     * column and the expression the database now holds. Bookkeeping only.
     */
    public function recordSourceRename(LiveUniqueKey $key): void
    {
        if (! $this->isSupported()) {
            return;
        }
        ColumnCollation::forget();
        $source = ColumnCollation::of($key->sourceColumn, true);
        $values = ['source_column' => $key->sourceColumn, 'updated_at' => now()];
        if ($source !== null) {
            $values['definition'] = $key->definition($source);
        }
        DB::table(self::LEDGER)->where('key', $key->key)->update($values);
    }

    private function cleanUpFailedActivation(LiveUniqueKey $key): void
    {
        try {
            if ($this->state($key)['column']) {
                $this->dropKeyColumn($key);
            }
            DB::table(self::LEDGER)->where('key', $key->key)->delete();
        } catch (Throwable $exception) {
            // The ledger keeps "activating": reconcile() finishes the cleanup.
            Log::error('ERS asset uniqueness: cleaning up a failed key failed; run the repair command.', ['key' => $key->key, 'exception' => $exception::class]);
        }
    }

    private function dropKeyColumn(LiveUniqueKey $key): void
    {
        DB::statement('ALTER TABLE '.$this->table().' DROP COLUMN `'.$key->column.'`');
    }

    private function ledger(LiveUniqueKey $key, string $status, ?string $definition = null): void
    {
        $values = ['source_column' => $key->sourceColumn, 'column_name' => $key->column, 'index_name' => $key->index, 'status' => $status, 'updated_at' => now()];
        if ($definition !== null) {
            $values['definition'] = $definition;
        }
        if (DB::table(self::LEDGER)->where('key', $key->key)->exists()) {
            DB::table(self::LEDGER)->where('key', $key->key)->update($values);
        } else {
            DB::table(self::LEDGER)->insert($values + ['key' => $key->key, 'created_at' => now()]);
        }
    }

    private function refuseInsideTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw new AssetUniquenessException(AssetUniquenessException::SCHEMA_CHANGE_FAILED, trans('admin/hardware/uniqueness.inside_transaction'));
        }
    }

    private function foreign(LiveUniqueKey $key, ?string $label): AssetUniquenessException
    {
        return new AssetUniquenessException(AssetUniquenessException::FOREIGN_OBJECT, trans('admin/hardware/uniqueness.foreign_object', ['field' => $label ?? $this->label($key)]));
    }

    /** @param  array<string, int>  $conflicts */
    private function conflicts(array $conflicts): AssetUniquenessException
    {
        $parts = [];
        foreach ($conflicts as $label => $count) {
            $parts[] = trans_choice('admin/hardware/uniqueness.conflict_count', $count, ['field' => $label, 'count' => $count]);
        }

        return new AssetUniquenessException(AssetUniquenessException::CONFLICTS, trans('admin/hardware/uniqueness.conflicts', ['details' => implode('; ', $parts)]), $conflicts);
    }

    private function tableName(): string
    {
        return ColumnCollation::guard(DB::connection()->getTablePrefix().'assets');
    }

    private function table(): string
    {
        return '`'.$this->tableName().'`';
    }
}
