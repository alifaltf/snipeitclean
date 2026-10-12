# ERS: database-backed asset uniqueness

Status: **implemented (Phase 6C, decisions A–F approved), awaiting
independent review.** Enforced on MySQL/MariaDB by migration
`2026_10_08_000100_enforce_live_asset_uniqueness`. SQLite (the Unit/Feature
test database) has no keys; there the application checks apply as before.

## 1. Rules

| # | Rule | Scope | Enforced by |
|---|---|---|---|
| R1 | Asset tag unique among **live** assets | every writer | database key (always) |
| R2 | A deleted asset's tag is never reused by the ERS import | ERS import only (decision C) | import validation + locking post-insert check |
| R3 | Serial unique among live assets **only while** Settings → "unique serial" is on | every writer | database key, present exactly while the setting is on (decision B) |
| R3a | The ERS import rejects duplicate non-empty serials (file, live or deleted) whatever the setting | ERS import only | import validation + post-insert check |
| R4 | NULL, `''` and spaces-only serials / custom values mean "no value" and never clash | every writer | key is NULL for them |
| R5 | A non-encrypted custom field marked unique is unique among live assets | every writer | database key per field (decision E) |
| R6 | A field cannot newly become encrypted **and** unique; existing ones are kept, unenforced; the import refuses their values | every write path / ERS import | model rule, encryption command, import (decision D) |
| R7 | Restoring an asset whose unique value is taken is refused with that field's error; never retagged | web, API, bulk restore | database key + `AssetRestorer` (decision F) |

Equality is the source column's collation (default `utf8mb4_unicode_ci`:
case, accents and trailing spaces ignored, `ß` = `ss`). A NO PAD collation
keeps trailing spaces significant, and so does its key.

## 2. Keys

Each key is a VIRTUAL generated column, NULL whenever the rule does not
apply, plus a UNIQUE index on it (a UNIQUE index accepts any number of
NULLs). All names start with `ers_live_`; the definition is built from
`information_schema` (type, character set, collation, pad attribute) when
the key is created.

| Key | Column / index | Generated value |
|---|---|---|
| tag | `ers_live_asset_tag` / `ers_live_asset_tag_unique` | `IF(deleted_at IS NULL, asset_tag, NULL)`, same type, character set and collation as `asset_tag` |
| serial | `ers_live_serial` / `ers_live_serial_unique` | `IF(deleted_at IS NULL AND TRIM(serial) <> '', serial, NULL)`, same type/collation as `serial` |
| custom field *n* (text) | `ers_live_cf_<n>` / `ers_live_cf_<n>_unique` | `IF(deleted_at IS NULL AND TRIM(col) <> '', SHA2(WEIGHT_STRING(v COLLATE <col collation>), 256), NULL)` as `CHAR(64) ascii`, where `v` is `TRIM(TRAILING ' ' FROM col)` for PAD SPACE collations and `col` for NO PAD |
| custom field *n* (DATE/DATETIME) | as above | `IF(deleted_at IS NULL, col, NULL)` |

`WEIGHT_STRING` is the collation's sort key: two values have the same
weight exactly when the collation says they are equal, so full-length TEXT
values (up to 65,535 bytes) are compared in full, with no prefix
truncation. **Hash collisions:** two different weights with the same SHA-256
would make the second value be refused as a duplicate (a false refusal,
never a missed duplicate). No such collision is known; the risk is
accepted and documented here.

**Ledger** (`ers_asset_uniqueness_keys`, migration `2026_10_08_000000`):
one row per ERS key (`key`, `source_column`, `column_name`, `index_name`,
`status` = activating / active / deactivating, `definition`). It is
written **before** each schema change, so after an interruption the
repair command knows what ERS was doing. An `ers_live_*` column or index
without a ledger row is **foreign**: it is reported and never altered,
dropped or reused; the change that needs that name is refused.

**Serialisation:** every schema transition runs under the MySQL named lock
`ers_asset_uniqueness_<hash of database name>` (re-entrant in-process,
30 s wait, then "another uniqueness change is in progress"). Schema changes
are refused inside an open transaction. The index is built with
`LOCK=SHARED`: concurrent writers wait for the build, and a duplicate
written before it fails the build (reported as a conflict, nothing kept).

**DDL is not transactional.** MySQL/MariaDB commit each `ALTER TABLE`
implicitly, so a key change is never rolled back by `DB::transaction`.
Consistency comes from ordering, the ledger and compensation (section 4).

## 3. Writer coverage

The database checks every INSERT/UPDATE, so every writer is covered,
including code paths that skip validation. A duplicate that slips past a
writer's own check fails with error 1062 on an `ers_live_*` index.

| Writer | Result of a database-key conflict |
|---|---|
| Web create / clone / update, audit, checkout/checkin, API create, API bulk update | exception handler → that field's validation error (web: back with input and the error; API: `status: error`, `messages.<field>`, HTTP 200 as for other validation errors) |
| API update (`applyAssetUpdate`) | caught in the controller: the asset's errors, nothing saved |
| Web bulk edit | caught per asset: reported with the other failures |
| Web restore, API restore, web bulk restore | `AssetRestorer` (restore in its own transaction): field error; bulk reports each refused asset (tag + message) and shows success only if something was restored |
| Native importer (`AssetImporter`, create and update) | each save in a savepoint; the row is reported as an import error; `update()` results are now checked |
| ERS import | row diagnostic ("This Asset Tag is already used by another asset", no value) and the whole import rolls back |
| `snipeit:regenerate-tags` | all or nothing (section 5) |
| `snipeit:customfield-encryption` | refuses unique fields; a failed save rolls the command back |

Recognition is strict (`UniqueConstraintViolation`): SQLSTATE 23000,
error 1062, and an index name read from the end of the driver message
that is one of the ERS index names. Every other database error is left
alone. Only the index name is logged, never the value or the SQL.

**Deadlocks and lock-wait timeouts** (MySQL 1213 / SQLSTATE 40001, 1205):
when a writer and an import wait on each other's key entries, the database
rolls one of them back. These are not uniqueness errors and are not shown
as such: `ConcurrentWriteConflict` turns them into "Another change was
being saved at the same time, so the database stopped this request. Check
what was saved, then try again." (web: back with input; API: standard
error payload) and logs the error code only. Previously such a request
ended in a 500 page whose log entry contained the SQL and values.

## 4. Settings and custom-field transitions

All transitions hold the named lock for their whole duration and re-read
the current state from the database under it.

**Custom-field configuration** (`CustomField::save()` and `delete()`, which
every path uses: the custom-field form/Livewire editor, the API, field
creation including Snipe-IT's nested save that records the new column,
renames including Snipe-IT's nested save after the column rename, deletion,
and `snipeit:customfield-encryption`) holds the lock from loading the
persisted field through validation, key activation, the metadata write,
key removal and compensation. Nested saves re-enter the same lock.

- **Fresh state, merge or refuse:** under the lock the persisted row is
  re-read. Attributes the request did not change take the persisted
  values; an attribute it did change is kept unless another request has
  meanwhile persisted a different value for that same attribute, in which
  case nothing is saved ("changed by someone else, reload"). Validation
  (including "not both encrypted and unique"), the key decision and the
  column to rename all use the merged, current state. So two requests that
  separately enable "unique" and "encrypted" can never both succeed, and a
  stale rename never renames a column that no longer exists.
- **Compensation follows persisted metadata:** after an update, and after
  any failed save, the field's key is reconciled with the metadata that is
  actually persisted (`reconcileFieldKey`): a key the persisted field no
  longer needs is removed; a key it needs is never removed (for example
  when a save fails after its metadata was written, or a failed save of an
  unrelated change on a unique field). Ledger renames are recorded there
  too, so nested saves need no per-request bookkeeping.
- **Deletion** re-reads the persisted column under the lock, so a
  concurrent rename is not missed.
- **Encryption command:** finds the field under the lock, refuses a unique
  field, and encrypts the values and sets the flag in one transaction
  while still holding the lock (the field save inside it re-enters the
  lock; the transaction contains no schema change).
- Before the ledger table exists (earlier migrations creating Snipe-IT's
  own fields) these transitions do nothing; there are no ERS keys yet.

**Switching a rule on** (unique serial on; custom field made unique and not
encrypted; new unique field created):

1. audit (read-only count of duplicate values) → refuse with counts if any;
2. ledger `activating`, `ADD COLUMN`, `ADD UNIQUE INDEX … LOCK=SHARED`,
   ledger `active`;
3. only then save the setting / field metadata;
4. if the save returns false or throws (including a database error after
   the key was built, or a later model listener refusing the update), the
   key is removed again — for settings by `saveSettings`, for custom fields
   only if the persisted metadata does not need it (see above).

A failed `ALTER` (lock-wait timeout, server error, a duplicate committed
during the build) removes the half-built column and the ledger row and
reports "could not be changed, nothing was saved" (or the conflict
counts). If even that clean-up fails, the ledger stays `activating` and the
repair command finishes it. So a rule is never reported as on while the
database does not enforce it.

**Switching a rule off** (unique serial off; unique flag removed; field
encrypted is refused while unique): metadata first, then the key is
removed (ledger `deactivating`, `DROP COLUMN`, ledger row deleted). If the
removal fails, the database keeps enforcing the old, stricter rule; the
user is told the change is incomplete and to run the repair command.

**Rename:** MariaDB/MySQL rewrite a generated column's expression when its
source column is renamed, so the key follows the column; the ledger's
`source_column`/`definition` are updated.

**Delete:** a column used by a generated column cannot be dropped alone, so
the field's column and its key column are dropped in one `ALTER`, then the
ledger row is deleted. A failure leaves both in place (and the field row,
because Snipe-IT's deleting hook fails).

**Creation failure:** if the key for a new unique field cannot be created
(for example a foreign `ers_live_cf_<n>` column exists), the new empty
column and the field row are removed again and the error is shown; the
foreign object is untouched. (A failure of Snipe-IT's own `ADD COLUMN`
for a new field is upstream behaviour and unchanged.)

## 5. Tag regeneration (`snipeit:regenerate-tags`)

One transaction: every live asset is locked (`SELECT … FOR UPDATE`, other
writers wait); the new tags are computed with the unchanged formula; a
preflight compares them with each other as the database would and refuses
duplicates; every tag is first set to a unique temporary value
(`__ers_regen_<nonce>_<id>`), then to its new value (through the model when
it changes, so the history entry shows old → new; directly when unchanged,
so no history entry is written, as before); a final check refuses if any
temporary value remains; then the next auto-tag base is saved. Any error
rolls back every tag and the base. The output never contains the exception
message. `--no-backup` skips `backup:run` (default still backs up first).

## 6. Audit, status and repair

- `php artisan ers:asset-uniqueness` — read-only status per required key
  (required, enforced, ledger, state) and any foreign `ers_live_*` object;
  exit code 1 if anything needs attention.
- `php artisan ers:asset-uniqueness --repair` — audits every key it would
  create (refuses with counts, before any change), creates missing required
  keys, finishes interrupted `activating`/`deactivating` entries, removes
  ERS keys that are no longer required; idempotent; never touches foreign
  objects.
- `docs/sql/ers-asset-uniqueness-audit.sql` — SELECT-only queries listing
  the asset ids in each duplicate group for a person to resolve. Nothing is
  ever retagged, merged or deleted automatically.

## 7. Migration and rollback

1. Run the audit SQL on a copy; resolve every group by hand.
2. `php artisan migrate` (maintenance window: each index build reads the
   assets table once and makes writers wait for its duration).
   - `2026_10_08_000000` creates the ledger table.
   - `2026_10_08_000100` audits every key it must create and **stops with
     per-rule counts and no change** if any duplicate exists; then creates
     the tag key, the serial key if "unique serial" is on, and one key per
     unique non-encrypted custom field.
3. `php artisan ers:asset-uniqueness` must report every key as ok.

**Partial runs:** if the migration stops part-way (a duplicate written
during an index build, a server error, an interrupted process), keys
already created stay, the failing key's column is removed, and Laravel does
not record the migration. Running `migrate` again (or `--repair`) skips
complete keys, finishes or rebuilds partial ones, and creates the rest.

**Rollback:** find the migration's batch with `php artisan migrate:status`,
then `php artisan migrate:rollback --batch=<batch>
--path=database/migrations/2026_10_08_000100_enforce_live_asset_uniqueness.php`.
It removes every ERS-owned key (ledger rows only; foreign objects
untouched) and leaves all asset data unchanged; the application checks
remain as before. Rolling back
`database/migrations/2026_10_08_000000_create_ers_asset_uniqueness_keys_table.php`
the same way then drops the (empty) ledger table. Name the file and its
batch rather than using `--step`: later ERS migrations (Phase 7A onwards)
may be newer and would be rolled back instead.
Restoring the keys later is a normal `migrate`, with the same audit.

## 8. Remaining limitations

- Serial duplicates are allowed while "unique serial" is off (Snipe-IT's
  rule; decision B). The ERS import still refuses them (R3a).
- Existing encrypted unique fields are **not** enforced: encrypted values
  use a random IV and cannot be compared by the database or the
  application. New ones cannot be created; turning encryption on for a
  unique field is refused.
- Reuse of a deleted asset's tag by native writers is allowed (decision C).
- A custom-field key covers the whole column, i.e. all live assets whose
  row holds a value, regardless of fieldset — slightly stricter than
  Snipe-IT's fieldset-scoped rule if values are left on assets whose model
  no longer uses the field.
- SHA-256 collisions on custom-field keys could falsely refuse a distinct
  value (section 2).
- A writer that collides with an ERS import while the import holds its
  rows can be chosen as a deadlock victim; it is told to try again
  (section 3), never given a duplicate.
- Schema changes are not transactional; the guarantee is the ordering and
  repair described in sections 4 and 7.
- Changing `settings.unique_serial` or `custom_fields.is_unique` /
  `field_encrypted` directly in the database, or restoring a database
  backup taken with a different configuration, bypasses the transitions:
  run `php artisan ers:asset-uniqueness` afterwards and `--repair` if it
  reports anything.

## 9. Tests

- SQLite (`tests/Feature/Assets/Uniqueness/AssetUniquenessTest.php`):
  rule D on model, API and encryption command; legacy fields preserved;
  violation recognition (MariaDB and MySQL 8 formats, value never used);
  handler rendering and logging; other database errors not disguised;
  deadlock/lock-wait handling; restore, bulk restore; native importer
  update failure; tag regeneration success, swaps, unchanged tags, and
  failure without partial renumbering.
- MariaDB (`tests/Concurrency/AssetUniquenessEnforcementTest.php`, opt-in,
  guarded dedicated database, one process per writer): web/API creates
  and API updates racing a held insert/update (winner commit → field error
  after waiting; winner rollback → success); native writers against the ERS
  import (never a duplicate; refused or "try again"; import row diagnostic
  and full rollback); tag/serial/custom keys with case, accents, `ß`,
  trailing and leading spaces, NO PAD, 65,535-byte values, blank/NULL;
  serial setting on/off with duplicates, failed save, racing an
  uncommitted duplicate; real DDL failure (lock-wait timeout) saving
  nothing; settings and field metadata saves failing in the database
  (trigger) after the key was built, the key removed again; custom-field
  configuration races with barriers (stale "unique" vs "encrypted" in both
  orders, the encryption command and a unique change serialised by the
  lock in both orders, disjoint stale changes merged and a stale rename
  refused, delete after a concurrent rename, compensation after failures
  before and after the metadata write including a rename, concurrent
  creation of unique fields), asserting persisted flags and actual key
  column, index and ledger state; custom-field create/rename/encrypt/unique off/on/delete and
  creation failure with a foreign object; legacy encrypted unique field
  preserved; restore conflicts on every path and racing an insert;
  interrupted transitions repaired, foreign objects untouched; migration
  refusal, partial retry and rollback; regeneration swap and failure via a
  database trigger without partial renumbering.
