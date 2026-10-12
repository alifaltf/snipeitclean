<?php

namespace App\Services\AssetUniqueness;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ERS: keeps settings / custom-field metadata and the database keys
 * consistent when a uniqueness rule is switched on or off.
 *
 * Schema changes commit on their own, so the two writes (metadata and
 * key) are ordered, never "transactional":
 *
 *  - switching a rule ON: the key is created first, under the schema lock,
 *    and the metadata is saved only if that succeeded; if the metadata
 *    save then fails, the key is removed again. A rule is therefore never
 *    reported as on while the database does not enforce it;
 *  - switching a rule OFF: the metadata is saved first, then the key is
 *    removed. If removing fails, the database keeps enforcing the old,
 *    stricter rule; the change is reported as incomplete and the repair
 *    command finishes it.
 *
 * Every transition holds the schema lock for its whole duration, so two
 * administrators changing settings or fields are serialised, and the
 * current state is re-read from the database under the lock.
 */
final class AssetUniquenessTransitions
{
    public function __construct(private readonly AssetUniquenessSchema $schema) {}

    /**
     * Save the settings, switching the serial key with "unique serial".
     *
     * @param  callable(): bool  $save
     * @return array{saved: bool, warning: ?string}
     *
     * @throws AssetUniquenessException when unique serials cannot be enforced (nothing saved)
     */
    public function saveSettings(Setting $setting, callable $save): array
    {
        if (! $this->schema->isReady()) {
            return ['saved' => (bool) $save(), 'warning' => null];
        }

        return $this->schema->withLock(function () use ($setting, $save) {
            $before = (string) DB::table('settings')->where('id', $setting->id)->value('unique_serial') === '1';
            $after = (string) $setting->unique_serial === '1';

            if ($before === $after) {
                return ['saved' => (bool) $save(), 'warning' => null];
            }

            if ($after) {
                $this->schema->activate(LiveUniqueKey::serial(), trans('general.serial_number'));
                try {
                    $saved = (bool) $save();
                } catch (Throwable $exception) {
                    $this->undoActivation(LiveUniqueKey::serial());

                    throw $exception;
                }
                if (! $saved) {
                    $this->undoActivation(LiveUniqueKey::serial());
                }

                return ['saved' => $saved, 'warning' => null];
            }

            if (! (bool) $save()) {
                return ['saved' => false, 'warning' => null];
            }
            try {
                $this->schema->deactivate(LiveUniqueKey::serial());
            } catch (AssetUniquenessException) {
                return ['saved' => true, 'warning' => trans('admin/hardware/uniqueness.disable_incomplete')];
            }

            return ['saved' => true, 'warning' => null];
        });
    }

    /**
     * Before an existing custom field is updated: when the update makes
     * it need a key, create the key now. Returns an error message (and the
     * update must not be saved) when the key cannot be created.
     */
    public function beforeFieldUpdate(CustomField $field): ?string
    {
        if (! $this->schema->isReady() || ! AssetUniquenessSchema::fieldWantsKey($field) || $this->wantedKeyBefore($field)) {
            return null;
        }
        // The column as it is now (a rename in the same save happens after
        // this, and MariaDB carries the key's expression along).
        $column = (string) ($field->getOriginal('db_column') ?: $field->db_column);
        try {
            $this->schema->activate(LiveUniqueKey::customField((int) $field->id, $column), (string) $field->name);
        } catch (AssetUniquenessException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Make a field's key match the field's PERSISTED metadata, read under
     * the schema lock: remove a key the persisted field does not need (after
     * "unique" was switched off, the field was encrypted, or a save that had
     * created the key failed before its metadata was written), and keep the
     * ledger in step with a rename. A key the persisted field needs is never
     * removed. Used after every field update and as the compensation of a
     * failed save. Returns a warning when a key could not be removed.
     */
    public function reconcileFieldKey(int $fieldId): ?string
    {
        if (! $this->schema->isReady()) {
            return null;
        }

        return $this->schema->withLock(function () use ($fieldId) {
            $row = DB::table('custom_fields')->where('id', $fieldId)->first();
            $ledgerSource = DB::table(AssetUniquenessSchema::LEDGER)->where('key', 'custom_field:'.$fieldId)->value('source_column');
            $column = (string) ($row->db_column ?? '') !== '' ? (string) $row->db_column : (string) $ledgerSource;
            if ($column === '') {
                return null;
            }
            $key = LiveUniqueKey::customField($fieldId, $column);
            $state = $this->schema->state($key);
            $wanted = $row !== null && (string) $row->is_unique === '1' && (string) $row->field_encrypted !== '1' && (string) $row->db_column !== '';

            if ($wanted) {
                if ($state['owned'] && $ledgerSource !== null && $ledgerSource !== $column) {
                    $this->schema->recordSourceRename($key);
                }
                if (! ($state['column'] && $state['index'] && $state['owned'])) {
                    Log::error('ERS asset uniqueness: a unique custom field has no complete key; run the repair command.', ['custom_field' => $fieldId]);
                }

                return null;
            }
            if (! $state['owned'] || ! $state['column']) {
                if ($state['owned']) {
                    DB::table(AssetUniquenessSchema::LEDGER)->where('key', $key->key)->delete();
                }

                return null;
            }
            try {
                $this->schema->deactivate($key);
            } catch (Throwable $exception) {
                Log::error('ERS asset uniqueness: a custom field key could not be removed; run the repair command.', ['custom_field' => $fieldId, 'exception' => $exception::class]);

                return trans('admin/hardware/uniqueness.disable_incomplete');
            }

            return null;
        });
    }

    /**
     * After a new custom field and its column were created: create its key.
     * On failure the new field and its column are removed again and the
     * exception is rethrown, so creation is never reported with the rule
     * unenforced.
     */
    public function afterFieldCreated(CustomField $field): void
    {
        if (! $this->schema->isReady() || ! AssetUniquenessSchema::fieldWantsKey($field)) {
            return;
        }
        try {
            $this->schema->activate(LiveUniqueKey::customField((int) $field->id, (string) $field->db_column), (string) $field->name);
        } catch (AssetUniquenessException $exception) {
            $this->removeNewField($field);

            throw $exception;
        }
    }

    /** Whether the field needed a key before the pending change. */
    public function wantedKeyBefore(CustomField $field): bool
    {
        if (! $field->exists) {
            return false;
        }

        return (string) $field->getOriginal('is_unique') === '1'
            && (string) $field->getOriginal('field_encrypted') !== '1'
            && (string) $field->getOriginal('db_column') !== '';
    }

    /**
     * Remove a just-created field whose key could not be created: its
     * (empty) column first, then the row, without the deleting hook (which
     * would refuse because of the object that made the key fail).
     */
    private function removeNewField(CustomField $field): void
    {
        try {
            $column = (string) ($field->db_column ?: $field->convertUnicodeDbSlug());
            ColumnCollation::guard($column);
            ColumnCollation::forget();
            if (ColumnCollation::of($column, true) !== null) {
                DB::statement('ALTER TABLE `'.(new Asset)->getTable().'` DROP COLUMN `'.$column.'`');
                ColumnCollation::forget();
            }
            $field->deleteQuietly();
        } catch (Throwable $exception) {
            Log::error('ERS asset uniqueness: a new custom field whose key failed could not be removed.', ['custom_field' => $field->id, 'exception' => $exception::class]);
        }
    }

    private function undoActivation(LiveUniqueKey $key): void
    {
        try {
            $this->schema->deactivate($key);
        } catch (Throwable) {
            Log::error('ERS asset uniqueness: a key created for a change that was not saved could not be removed; run the repair command.', ['key' => $key->key]);
        }
    }
}
