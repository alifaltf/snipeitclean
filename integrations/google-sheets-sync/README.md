# Google Sheets → Snipe-IT sync

Standalone Node.js connector that reads a Google Sheet of "Digital System"
assets and maps each row into Snipe-IT.

- **Phase 1 — dry run (`npm run dry-run`).** Read-only preview. Never writes
  to Snipe-IT or to the Sheet.
- **Phase 2 — create-only apply (`npm run apply-create`).** ⚠️ **This is a
  write operation.** It can create brand-new Snipe-IT assets for a hand-picked
  list of asset tags you pass explicitly on the command line. See
  "Running the apply/create-only command" further down this file before
  using it.

Both commands live in the same connector and share almost all of their code
(header/field mapping, department eligibility, reference-data resolution).
The only Snipe-IT write path anywhere in this connector is
`snipeitClient.createAsset()` — there is still no update or delete method
defined anywhere in this codebase, in either phase.

## Safety guarantees

- **The Snipe-IT client has exactly one write method.**
  `src/snipeitClient.js` defines `createAsset()` (a single
  `POST /api/v1/hardware`) and nothing else that writes — no update, no
  delete, for any resource. This is structural, not a runtime flag:
  `tests/snipeitClient.test.js` asserts the client object has no such method
  under any name.
- **The dry run cannot write, full stop.** `bin/dry-run.js` requires
  `SYNC_MODE=dry-run` and only ever calls the client's read methods.
- **The apply/create command requires three independent safeguards before
  it will send a single POST request**, checked together, before any
  network call at all:
  1. `SYNC_MODE=apply` in the environment file.
  2. An explicit, non-empty `--tags` selection — nothing is ever "discovered"
     and created automatically.
  3. The exact confirmation argument `--confirm CREATE_DUMMY_ASSETS`.

  If any one of these is missing, the command exits immediately without
  loading Sheets/Snipe-IT data and without writing anything
  (`src/applyRun.js`'s `assertSafeguards()`, and re-checked structurally in
  `bin/apply-create.js` before config is even loaded). See
  `tests/applyRun.test.js` for the tests proving this.
- **Only the tags you explicitly select are ever evaluated.** A row whose
  asset tag isn't in `--tags` is skipped before any other check runs on it —
  it is never evaluated for department eligibility, never validated, and
  never duplicate-checked. It cannot end up created by accident.
- **Never overwrites an existing asset.** Immediately before creating each
  selected row, the connector re-checks `GET /api/v1/hardware/bytag/{tag}`.
  If the tag already exists, that row is skipped — never updated, never
  recreated.
- **Rows outside `IT Department` / `HR Department` are never created**, even
  if their tag is explicitly selected — *unless* you explicitly pass
  `--fallback-to-it` (see
  [Optional: `--fallback-to-it`](#optional---fallback-to-it) below), which is
  off by default and never creates a new company or location.
- **Invalid rows are reported, not created.** Date and numeric custom fields
  (`Creation / Delivery Date`, `License Renewal Date`, `Port`, `License
  Cost`) are validated before any create attempt; a row that fails
  validation is reported with the reason and skipped.
- **The "Password" column is never read into memory**, and cannot reach a
  create payload even in principle. `src/headerMapping.js` drops it before
  any other module sees a row (`EXCLUDED_HEADERS`); the custom-field mapping
  table (`src/customFieldMapping.js`) throws at require-time if "Password" is
  ever added to it; and `src/assetPayload.js` only ever reads the 13 fields
  in that table. `tests/passwordExclusion.test.js`,
  `tests/assetPayload.test.js`, and `tests/applyRun.test.js` all assert this.
- **Google Sheets access is read-only scoped** (`spreadsheets.readonly`) —
  see `src/sheetsClient.js`. Nothing in either phase writes to the Sheet.
- **No secrets are printed.** Logging only ever shows `[set]`/`[missing]` for
  the API token and Google credentials path — never their values, and never
  usernames. See `src/logger.js`.
- **`connector.env` and the Google service-account JSON key live outside this
  repo** and are never copied in. The connector loads them directly from
  their external path via `dotenv`.
- Everything in this integration lives under `integrations/google-sheets-sync/`
  and does not touch the main Snipe-IT application.

## Prerequisites

- Node.js >= 18 (for built-in `fetch`)
- A Snipe-IT personal access token
- A Google Cloud service account with **read-only** access to the target
  spreadsheet ("Viewer" — sharing the sheet with the service account's email
  address is enough), and its JSON key file saved somewhere outside this repo
- An environment file (see `.env.example`) saved somewhere **outside this
  repo**, e.g. `connector.env`, containing:

  ```
  SNIPEIT_BASE_URL=...
  SNIPEIT_API_TOKEN=...
  GOOGLE_APPLICATION_CREDENTIALS=...   (path to the service-account JSON key)
  GOOGLE_SPREADSHEET_ID=...
  GOOGLE_SHEET_NAME=...
  SYNC_MODE=dry-run
  ```

  `SYNC_MODE` must be exactly `dry-run` or `apply` — `bin/dry-run.js` requires
  `dry-run` and `bin/apply-create.js` requires `apply`; each command refuses
  to run under the other's mode. Only switch this file to `SYNC_MODE=apply`
  when you actually intend to run the apply/create command.

## Setup

```bash
cd integrations/google-sheets-sync
npm install
```

This installs only `dotenv` and `googleapis` (plus their transitive deps),
scoped to this folder — nothing is installed into the main Snipe-IT project.

## Running the tests

```bash
npm test
```

Runs Node's built-in test runner (`node --test`), which auto-discovers
`tests/*.test.js`. No network
access, Snipe-IT instance, or Google credentials are required — the client
and Sheets access are fully fake/mocked in tests that need them, and the
apply/create command is never actually invoked by the test suite:

- `tests/assetTag.test.js` — `No.` → `SYS-####` formatting
- `tests/headerMapping.test.js` — header-row detection + header→column mapping
- `tests/departmentMapping.test.js` — department eligibility → company/location
- `tests/sheetsClient.test.js` — A1-notation sheet-name quoting (covers tab names with spaces, e.g. "Systems Inventory")
- `tests/passwordExclusion.test.js` — asserts the Password column can never
  reach a mapped record or a custom-field target, and that all 13 (not 12)
  required custom fields are present in the mapping
- `tests/fieldValidation.test.js` — date/numeric field validation
- `tests/assetPayload.test.js` — the `POST /hardware` payload builder: core
  fields, all 13 custom fields keyed by their real `db_column_name`, and
  structural exclusion of Password
- `tests/snipeitClient.test.js` — the client exposes exactly one write method
  (`createAsset`) and no update/delete method for any resource; `createAsset`
  POSTs the exact payload and surfaces both success and validation-error
  responses without throwing
- `tests/config.test.js` — `SYNC_MODE` handling for both `dry-run` and
  `apply`, and `requireSyncMode()`'s per-command enforcement
- `tests/applyRun.test.js` — the full apply/create orchestration: missing
  safeguards block every write path with zero network calls; only
  explicitly selected tags are ever evaluated; duplicates are skipped and
  never overwritten; ineligible departments are never created even if
  selected; invalid rows are reported, not created; unselected tags never
  reach the Snipe-IT API
- `tests/fallbackDepartment.test.js` — the `--fallback-to-it` flag: it does
  nothing unless explicitly enabled; when enabled it resolves unknown
  departments to company `IT Department` / location `IT Office`; the row's
  original `Stakeholder Name / Department` text is preserved in the custom
  field, never replaced; `IT Department`/`HR Department` rows keep their own
  mapping even with the flag on; duplicates are still skipped; no new
  company/location is ever looked up

## Running the dry run

By default the connector looks for the environment file at:

```
C:\Users\Alif Altaf\snipeit-secrets\connector.env
```

Override with `--config <path>` or the `CONNECTOR_ENV_PATH` environment
variable if your file lives elsewhere (note: **not** `--env-file` — that flag
is reserved by Node.js itself since v20.6 and would silently bypass this
connector's own config loading/validation):

```bash
npm run dry-run
# or
node bin/dry-run.js --config "C:\path\to\connector.env"
```

The dry run:

1. Loads and validates the environment file (without ever printing it).
2. Verifies Snipe-IT API access (`GET /api/v1/users/me`).
3. Verifies Google Sheets access and reads the configured tab.
4. Detects the header row (a row containing both "Status" and "System Name" —
   row 1 may be a warning banner and is skipped automatically) and validates
   all required headers are present.
5. Resolves the `Digital System` model, the `IT Department`/`HR Department`
   companies, `IT Office`/`HR Office` locations, the four status labels, and
   each custom field's `db_column_name` from Snipe-IT.
6. For every data row: checks department eligibility, formats the asset tag,
   maps the status, checks for an existing asset with the same tag via
   `GET /api/v1/hardware/bytag/{tag}`, and records the outcome.
7. Prints a sanitized table (row, asset tag, system name, status, company,
   location, result) and a summary (`rows read / eligible / existing /
   skipped / errors`). No usernames, passwords, tokens, or credential values
   are ever printed.

## Sheet structure this expects

Row 1 may contain a warning banner; the real header row is auto-detected.
Required headers (18 columns):

```
No. | Status | System Name | Platform Type | Creation / Delivery Date |
License Renewal Date | License Renewal | License Cost | Database Instance |
Database Category | Database Location | Port | Domain | Username | Password |
Stakeholder Name / Department | Developer / Vendor | Remark / Description
```

`Password` is present in the sheet but is never read past header mapping.

## Field mapping

| Sheet column | Snipe-IT target |
| --- | --- |
| `No.` | `asset_tag` (`SYS-####`) |
| `System Name` | asset name |
| `Status` | status label (`Active`/`Under Maintenance`/`Inactive`/`Pending`) |
| `Stakeholder Name / Department` | company + location resolution, **and** custom field `Stakeholder Name / Department` |
| `Remark / Description` | notes |
| `Platform Type`, `Creation / Delivery Date`, `License Renewal Date`, `License Renewal`, `License Cost`, `Database Instance`, `Database Category`, `Database Location`, `Port`, `Domain`, `Developer / Vendor` | matching custom field of the same name |
| `Username` | custom field **`System Username`** |
| `Password` | never mapped, stored, or logged |

Only rows whose department resolves to `IT Department` → company **IT
Department** / location **IT Office**, or `HR Department` → company **HR
Department** / location **HR Office**, are eligible. Everything else is
skipped with a sanitized reason — unless the apply/create command is run
with `--fallback-to-it`, see below.

## Running the apply/create-only command ⚠️ WRITE OPERATION

`bin/apply-create.js` (`npm run apply-create`) **creates new assets in
Snipe-IT.** It does not update or delete anything, and it only ever touches
the exact asset tags you pass it — but it is a real write, not a preview.
Read the [safety guarantees](#safety-guarantees) above before running it
against a production Snipe-IT instance.

### What it requires

All three of the following, together, or it exits without writing anything:

1. `SYNC_MODE=apply` set in your `connector.env` (the same file used for the
   dry run — `SYNC_MODE=dry-run` there will make this command refuse to run).
2. `--tags`, a comma-separated, explicit list of asset tags to create
   (e.g. `--tags SYS-0002,SYS-0003`). Nothing outside this list is ever
   touched, regardless of what else is in the sheet.
3. `--confirm CREATE_DUMMY_ASSETS` — this exact string, verbatim.

There's also one **optional** flag, off by default:
`--fallback-to-it` — see
[Optional: `--fallback-to-it`](#optional---fallback-to-it) below.

### Usage

```bash
node bin/apply-create.js --tags SYS-0002,SYS-0003 --confirm CREATE_DUMMY_ASSETS
# or, with a non-default connector.env location:
node bin/apply-create.js --config "C:\path\to\connector.env" --tags SYS-0002,SYS-0003 --confirm CREATE_DUMMY_ASSETS
```

(`npm run apply-create` also works, but npm eats `--` flags unless you add
an extra `--` before them, so calling `node bin/apply-create.js` directly, as
above, is simpler.)

### What it does, per selected tag

1. Loads and validates the environment file, and requires `SYNC_MODE=apply`.
2. Verifies Snipe-IT and Google Sheets access — no write happens before this
   succeeds.
3. Resolves the `Digital System` model, `IT Department`/`HR Department`
   companies and locations, status labels, and every custom field's real
   `db_column_name` from Snipe-IT (never hard-coded IDs).
4. For each row whose asset tag is in `--tags` (rows for any other tag are
   never evaluated at all):
   - Confirms the row's department is `IT Department` or `HR Department`;
     otherwise, by default, it's skipped, never created. If
     `--fallback-to-it` was passed, an unrecognized department instead
     falls back to company `IT Department` / location `IT Office` (see
     [Optional: `--fallback-to-it`](#optional---fallback-to-it)) — the row's
     original department text is still preserved in its custom field.
   - Validates `Creation / Delivery Date`, `License Renewal Date`, `Port`,
     and `License Cost`; an invalid row is reported and skipped, never
     created.
   - Re-checks `GET /api/v1/hardware/bytag/{tag}` immediately before
     creating. If the tag already exists, the row is skipped — never
     overwritten.
   - Builds the `POST /api/v1/hardware` payload (asset tag, name, model,
     status, company, `rtd_location_id`, notes, and all 13 mapped custom
     fields — `Password` is structurally impossible to include) and creates
     the asset.
5. Prints a sanitized per-row result and summary — same sanitization rules
   as the dry run (no usernames, passwords, tokens, or credential values).
6. Any tag you selected that wasn't found in the sheet at all is reported,
   not silently ignored.

### What it will never do

- Update or delete an existing Snipe-IT asset (no such method exists in
  `src/snipeitClient.js`).
- Create a row for a tag you didn't explicitly list in `--tags`.
- Create a row for a department other than `IT Department`/`HR Department`
  — unless `--fallback-to-it` was explicitly passed, and even then it only
  ever assigns the row to the existing `IT Department`/`IT Office`, never a
  new department.
- Create a new Snipe-IT company or location for the fallback. It only ever
  reuses the same `IT Department` company / `IT Office` location that are
  already resolved for ordinary IT Department rows — no extra lookup, no
  creation.
- Overwrite a duplicate asset tag.
- Overwrite a row's original `Stakeholder Name / Department` value with
  `"IT Department"` — even when the fallback is used, the custom field
  keeps exactly what was in the sheet.
- Write to the Google Sheet.
- Log or transmit the `Password` column, or any username/token/credential
  value.

### Optional: `--fallback-to-it`

By default, a selected row whose `Stakeholder Name / Department` doesn't
resolve to `IT Department` or `HR Department` is skipped — this is
unchanged, and is still exactly what happens if you don't pass this flag.

Pass `--fallback-to-it` to instead let those rows through, assigned to:

- Snipe-IT company: **IT Department**
- Snipe-IT location: **IT Office**

using the same company/location records already resolved for ordinary `IT
Department` rows — no new company or location is ever looked up or created.

What does **not** change when you use this flag:

- The row's `Stakeholder Name / Department` custom field still contains its
  **original** sheet value (e.g. `"Marketing - Alex Kim"`), never
  `"IT Department"`. Only the Snipe-IT company/location assignment falls
  back — the data you're recording about the row does not.
- `IT Department` and `HR Department` rows are completely unaffected and
  keep their own normal mapping.
- Every other safeguard (`SYNC_MODE=apply`, explicit `--tags`,
  `--confirm CREATE_DUMMY_ASSETS`), the duplicate re-check, and field
  validation all still apply exactly as before.

The report always says explicitly when this happened — a row created (or
skipped as a duplicate, or reported invalid) under the fallback shows
`[IT FALLBACK APPLIED — original department: "..."]` in its Result column,
and the summary shows an `IT fallback used: N` count.

```bash
node bin/apply-create.js --tags SYS-0004 --confirm CREATE_DUMMY_ASSETS --fallback-to-it
```

See `tests/fallbackDepartment.test.js` for the tests proving all of the
above.
