# ERS Asset Management Requirements

Status: Approved Draft V1
Source: ERS Snipe-IT requirements meeting and subsequent clarification.

## 1. Purpose

ERS requires one standardized system to manage fixed assets, identify who holds each asset, track check-out, return and transfer, and retain complete movement history.

## 2. Asset Hierarchy

The hierarchy must be configurable through the website and database:

- Type
  - Category
    - Subcategory

Initial structure:

- Fixed
  - Hardware
    - Laptop
    - Desktop
    - Monitor
    - Phone
  - Furniture
    - Chair
  - Equipment
    - Drone
    - Project Equipment
  - Software
    - Digital Systems

Parent levels are navigation groups. The asset table appears only after selecting a final subcategory such as Laptop.

New types, categories and subcategories must not require source-code changes.

## 3. Category Tables and Forms

Each final subcategory has:

- Its own asset table
- Its own visible columns
- Its own custom fieldset
- Its own required fields
- Its own create and edit forms
- Its own CSV import entry point

Example: Laptop shows Laptop fields; Chair shows Chair fields.

### 3.1 Approved Phase 7 decisions: category asset-table layouts

- Each final asset category may have its own asset-table column layout. Navigation groups cannot have layouts.
- No category names or IDs are hardcoded; layouts are configured through the website and stored in the database.
- A layout is an ordered list of approved standard asset fields and eligible custom fields. A custom field is eligible for a final category only while at least one live asset model in that category uses it through its fieldset.
- Hiding a column only removes it from the layout. It never deletes or changes asset data or custom-field values.
- A final category that has not been configured uses a safe default layout: Asset Tag, Name, Model, Serial, Status. New final categories therefore start with the default.
- New custom fields are never added to existing layouts automatically.
- Super Admin may always configure layouts. Anyone else needs both existing global permissions "Edit Categories" (`categories.edit`) and "Edit Custom Fields" (`customfields.edit`); the global Admin permission, which Snipe-IT's category and custom-field policies already accept, also qualifies. Asset-category View/Create/Edit/Delete grants, company scope and location never grant layout administration. The same rule applies to the configuration page, saving, reordering and restoring the default.
- Encrypted-field visibility remains controlled by Snipe-IT's existing "View Encrypted Custom Fields" permission. The configuration page shows field names only, never values.
- Phase 7A stores and manages the configuration only (`asset_category_columns`, one row per category and column with its position; the category's own page "Asset Table Columns", reached from the category edit page).
- Phase 7B applies the layouts to asset tables, the asset API (on request), searching, sorting and CSV export (details below).
- Phase 7C makes forms, fieldsets and the CSV import follow one custom-field eligibility rule (details below).

Phase 7A details:

- The approved standard fields are kept in one list (`AssetTableFieldCatalog`). Columns that every asset table has (selection, actions, check-in/check-out) are not configurable, and the category column is not offered.
- Saving replaces the category's complete layout in one database transaction after locking the category row and re-checking the category and every column; the last save to commit wins and is stored exactly. The database refuses a repeated column or position within a category.
- Restoring the default deletes only that category's layout rows. Deleting a category, or turning it into a navigation group or another category type, removes its layout; a purged category's layout is also removed by the foreign key.
- If a model or fieldset changes later, stored columns that are no longer eligible are ignored when the layout is read (they are not shown); if none remains, the default layout is used. Stored rows are only replaced when the layout is saved again.
- Rollback: `php artisan migrate:rollback --batch=<batch> --path=database/migrations/2026_10_12_000000_create_asset_category_columns_table.php` (batch from `php artisan migrate:status`) drops the layout table; every final category then uses the default layout again. No asset or custom-field data is stored there.

Phase 7B details (applying the layouts):

- Selecting a final category in the asset list shows the selection checkbox, that category's effective columns in their saved order, then check-in/check-out and actions. Each column keeps the asset list's existing formatting. The table keeps its own browser-saved state per category and layout. The unfiltered asset list, navigation groups and status lists without a final category keep Snipe-IT's normal columns.
- The asset API applies a layout only when asked: `GET /api/v1/hardware?asset_category=<final category id>&asset_table_layout=1`. The response then also contains `layout` (`category_id`, `explicit`, and `columns` with key, field, title, kind, sortable, searchable, encrypted). Without `asset_table_layout=1`, or for a navigation group or a category the user may not view, the API behaves exactly as before. Rows keep their existing shape.
- In layout mode, only the layout's own columns can narrow, order or count the list:
  - Search looks only at the layout's searchable columns, matched literally anywhere in the value. Per-column filters (`filter` JSON, combined with `filter_operator` `and`/`or`) accept only those columns.
  - The list filters (`status_type`, `status_id`, `company_id`, `model_id`, `location_id`, `rtd_location_id`, `supplier_id`, `manufacturer_id`, `assigned_to`/`assigned_type`, `asset_tag`, `serial`, `order_number`, `asset_eol_date`, `byod`, `requestable`, and exact custom-field values) apply only when their field is a column of the layout; a filter on a hidden field is ignored and changes neither the rows nor the total. `status_type=Deleted` still lists deleted assets; `category_id` and `depreciation_id` do not apply (the category is the selected one). Without a usable status filter, archived assets follow the "show archived" setting as in the normal list. Values must be single values of the expected shape (ids are positive integers); anything else is ignored.
  - Sorting accepts only the layout's sortable columns (allowlist in `AssetTableFieldCatalog::STANDARD_QUERY`; custom fields by their own column, numeric fields as numbers); anything else falls back to newest first.
  - Encrypted custom fields are never filtered, searched or sorted.
  - A custom field only has a value for an asset whose current, live model's fieldset contains it. A value left in the column by an earlier fieldset never matches a search or filter, sorts as no value (first ascending, last descending) and is not exported.
  - Totals and pagination are counted after all filters. Outside layout mode the API's filters work exactly as before.
- "Export CSV" on a final category's list (`/hardware/category-export?asset_category=<id>`) exports that category's effective columns in order, with the list's formatting, for exactly the assets and order the table shows. The link carries the page's list filters (company, status type, status, order number), and when clicked also the table's current search, column filters and sorting. The server applies them through the same code and rules as the table (`AssetTableLayoutQuery::applyListFilters`, `applySearch`, `applySort`), validating every value again. Company scope and category View permissions apply; deleted assets appear only in the "Deleted" list. The export is refused (not found) for navigation groups and categories the user may not view. Every cell and header is protected against spreadsheet formulas, whatever `CSV_ESCAPE_FORMULAS` says. The browser-side table export is switched off on category lists so this safe export is the one offered.
- Encrypted custom-field values are shown, returned and exported only to users with "View Encrypted Custom Fields"; everyone else gets "ENCRYPTED", never the value or its ciphertext. Configuration pages and table definitions contain field names only.
- Related records and custom-field definitions are loaded once per page or export chunk, so the number of database queries does not grow with the number of assets.
- Nothing here changes asset data, forms, fieldsets or the secure CSV import.

Phase 7C details (forms, fieldsets and CSV):

- One rule decides which custom fields an asset may store (`AssetCustomFieldEligibility`): the fields of the CURRENT fieldset of the asset's (destination) model, if that model is live and the field has a native custom-field column (`_snipeit_...`). The web and API create/edit forms, bulk edit, the CSV mapping catalog, CSV validation and CSV execution all use it; the category table layout uses the same structural check. A Super Admin cannot write a field outside this rule either.
- Table layouts are presentation only. Adding, removing, reordering, resetting or invalidating layout columns never changes asset values, fieldset membership, form fields, form validation or CSV mapping eligibility. A hidden layout column does not stop a field being imported, and a layout column never makes a field editable or importable.
- Submitted custom-field keys outside the rule are rejected with a validation error and nothing is written (web and API create and edit, for every user). Bulk edit: with a model change, only the new model's fields may be set or cleared; without one, a field must belong to at least one selected asset's model and is written only to those assets; any other field refuses the whole request before anything is written (web and API).
- Model changes (single and bulk) are authorised by Phase 5 (category Edit on the destination) and validated against the DESTINATION fieldset, including its required fields. Previously the web edit and bulk edit validated against the old fieldset; this was corrected. A failed change writes nothing for that asset. Values of fields the new fieldset lacks stay in the database (Snipe-IT never deletes them) but are not shown, returned, searched, sorted or exported; they appear again if the asset later moves to a model whose fieldset has the field. Bulk edit keeps Snipe-IT's per-asset results: a failing asset is not changed, the others are saved.
- Required fields are marked in the forms and enforced on create and edit (including fields that became required later). Encrypted fields: a user without "View Encrypted Custom Fields" sees a disabled "ENCRYPTED" placeholder for every field type, never plaintext, and never changes or clears the value. Submitting the placeholder (or the "Error Decrypting" text shown for an unreadable value) keeps the stored value for every user, on web, API and bulk paths, so the literal word "ENCRYPTED" cannot be stored in an encrypted field.
- Fieldset changes: a field added to a fieldset becomes editable and importable at once; a removed field, a fieldset that is detached or missing, a deleted field, and a field that becomes encrypted are handled without errors and without deleting stored values. Stale layout entries are skipped as in Phase 7A.
- CSV import: the mapping offers only fields of the fixed model's fieldset (or, with the model taken from the CSV, of the category's live models, checked per row); a forged mapping is refused. Any change after mapping or validation (mapped field removed or deleted, model moved to another fieldset or category, required field added, grants, global permissions or company scope revoked) makes the session ineligible: validation detects it, and Phase 6C execution re-checks readiness and revalidates every row inside its transaction, so nothing is written. The user gets the generic "review the target/mapping" or "validate again" message and can repair the mapping and validate again.
- No migration. Eligibility for several models loads in a fixed number of queries; bulk edit loads all fieldsets once.
- Limitations: Snipe-IT's own checkbox-option rule cannot validate an encrypted checkbox value, and the field editor does not allow such fields. Snipe-IT's bulk edit writes encrypted values only for admins (unchanged).

## 4. Custom Fields

Super Admin can create, configure, order, archive and remove custom fields through the website.

Custom fields can be assigned to specific subcategories and can be configured for:

- Required or optional
- Field type and validation
- Dropdown options
- Table visibility
- Search
- CSV import
- Export
- API visibility
- Group/user visibility

Removing a field from a fieldset preserves existing data. Permanent deletion requires a warning and explicit confirmation.

## 5. Permissions

Permissions are configurable through the website and stored in the database.

### 5.1 Asset Category Permissions

Asset category permissions are assigned only to Snipe-IT permission groups. They are not assigned directly to individual users.

For every final (assignable) asset category, Super Admin can grant a permission group:

- View
- Create
- Edit
- Delete

A user receives the union of the grants from every permission group they belong to.

Existing global Snipe-IT permissions are always the upper bound. A category grant never gives a user an operation that their global Snipe-IT permissions do not allow.

Users who are not Super Admins have no access to a category unless one of their permission groups grants it (default deny).

Super Admin has full access.

Navigation groups do not store grants. Access to a navigation group is derived from the final categories beneath it that have been granted.

New final categories receive no grants automatically. Until a Super Admin grants a new category to a permission group, only Super Admins have access to it.

Check Out, Check In, Audit and CSV Import are not category-level permissions in this approved phase. They remain controlled by the existing global Snipe-IT permissions unless category-level control is separately approved later.

### 5.2 Access Checks

A user must pass normal Snipe-IT permission, category permission and organisational-scope checks.

Permissions must apply to the website, direct URLs, search, reports, exports, CSV imports and REST API.

## 6. Organisational Scope

Companies, departments, users and user groups must be configured.

Users only access assets within their permitted organisational scope.

Location remains asset information only and does not restrict access.

## 7. IT Laptop Data

Zaki represents IT and provides the initial Laptop spreadsheet containing approximately 450-460 records.

Before importing:

- Finalize the required Laptop fields
- Identify IT-restricted fields
- Configure Laptop custom fields
- Configure field visibility
- Test a small sample
- Validate duplicates
- Import the approved full dataset

IT-specific permissions belong to an IT group, not directly to Zaki's account.

## 8. CSV Import

The system uses CSV only. There is no Google Sheets API or synchronization.

Import flow:

1. Upload one CSV
2. Select an authorised final subcategory and the model, status, company and location sources
3. Manually map CSV columns
4. Review the mapping
5. Validate
6. Preview
7. Confirm import

The mapping interface supports drag-and-drop, dropdown mapping, ignored columns and sample values.

CSV headers do not need to match Snipe-IT field names.

Initial import rules:

- One subcategory per CSV
- Create-only
- All-or-nothing
- No silent overwrite
- Duplicate asset tags rejected
- Duplicate serial numbers rejected
- No automatic master-data creation
- Downloadable error report

### 8.1 Approved Phase 6 decisions

- CSV files only (comma-separated, UTF-8). No Google Sheets integration.
- The native Snipe-IT importer (`/import` and its API endpoints) is restricted to Super Admin. Other users import assets through the secure asset import (`/hardware/import`). Command-line imports are unchanged.
- Starting an import requires the global CSV Import permission, the global Assets Create permission and category Create on the selected final category. Category View is not required. There is no separate category-level Import permission.
- Super Admin bypasses category grants but can still only select live, final asset categories.
- Two model modes: one fixed existing model for every row, or a mapped Model column (optionally with Model Number) resolved per row against the live models of the selected category.
- Status comes from one fixed existing status label or a mapped column. Company and location are optional and come from one fixed existing record or a mapped column; Full Multiple Company Support scoping applies.
- Import state (file, hash, target, mapping) is kept in a secure server-side session owned by the importing user, addressed by a random identifier, and expires after 24 hours if not completed. Mappings are stored by column position and tied to the file's SHA-256.
- No master data is ever created automatically: models, categories, status labels, companies, departments, locations, users, suppliers, manufacturers, custom fields and fieldsets must already exist.
- Validation and execution are all-or-nothing (later phases).
- Limits: 10 MB per file and 5,000 data rows.
- Dates must use ISO format `YYYY-MM-DD`.
- No automatic asset tags: every row needs an asset tag.
- Asset tags of deleted assets cannot be reused.
- Duplicate non-empty serial numbers are rejected (checked during validation in Phase 6B).
- Column mapping is done with drag-and-drop plus an accessible dropdown. Suggestions are limited to exact matches of Snipe-IT field labels and must be reviewed and saved by the user.
- Reusable mapping templates are deferred to a later phase.
- No pre-import backup step.

### 8.2 Phase 6B validation and preview

- Validation checks every mapped row and writes nothing except the import session's state: `validated` (with `validated_at`) only when every row passes, otherwise `validation_failed`. Saving the target or mapping again clears the validation. Validation can be repeated at any time.
- Phase 6C must validate again inside the transaction that creates assets; a recorded validation is never sufficient on its own.
- Row numbers count CSV records: the header is row 1 and the first data row is row 2.
- Asset tags are required, unique within the file and never reuse the tag of a live or deleted asset. Non-empty serial numbers follow the same rule. Comparisons ignore letter case.
- Dates must be `YYYY-MM-DD`; purchase cost must be a plain number with at most two decimals; warranty must be a whole number of months from 0 to 240; booleans accept only `1`, `0`, `true`, `false`, `yes`, `no`, ignoring letter case and surrounding spaces; any other non-empty value is an error. A blank or unmapped Requestable/BYOD value is left out, so the asset keeps its default. A required boolean custom field still needs a value.
- Notes can be mapped. Line breaks inside a quoted cell are kept; only surrounding spaces are trimmed. Snipe-IT's own Notes rule applies, and the text must also fit the database column (65,535 bytes). The preview shows at most the first 500 characters of a note; the full text is validated and kept.
- Models, status labels, companies, locations and suppliers are matched by exact name (ignoring letter case and extra spaces). Missing or ambiguous names are errors; nothing is created. Models only match live models of the selected category; companies and locations only match records within the user's company scope, and a location must belong to the asset's company where Snipe-IT requires it.
- Custom fields are validated against the fieldset of each row's own model. A value for a field that does not apply to that model is an error. Encrypted values are always masked.
- Encrypted custom fields marked unique cannot be filled by import: their values are encrypted with a random component, so neither Snipe-IT nor the import can check them against existing assets without decrypting every stored value. Any non-empty value mapped to such a field is a row error, so the import cannot proceed; leave the column empty and enter the value on the asset afterwards. A required encrypted unique field therefore blocks import for that model.
- The result page shows totals, a paginated preview and every error. A CSV error report (row number, asset tag, field, error) can be downloaded; it is generated on request, never stored, and protected against spreadsheet formulas.

### 8.3 Phase 6C import execution

- The results page offers the import only when the session is validated and every row still passes. The user ticks a confirmation and submits a POST form (CSRF-protected) that carries a token naming the validation they reviewed; a confirmation from an older page (before revalidation or a configuration change) is refused.
- Every check is repeated inside one database transaction before anything is written: session owner, open state, expiry, private file and its SHA-256, mapping hash and validity, global Import and Assets Create, category Create on the live final category, target records, company scope, and a full validation of every row.
- Rows are created as new assets only, through Snipe-IT's own model and save-time validation, as the native "create asset" form does: the location fills both the default and the current location, the company goes through the native company check, next audit date gets the native default when not imported, encrypted custom fields are encrypted once, blank optional values keep their defaults, the importer is recorded as creator, and the native "create" log entry records the importer as its source. No existing asset is changed and no master data is created.
- All or nothing: the assets, their native log entries and order lines, and the completed state (`completed_at`, number and ids of the created assets) commit together. Any failure (validation, native save, a uniqueness clash, an unexpected error) rolls everything back; the session then shows "execution failed" with safe diagnostics (row, asset tag, field, message; never cell values) and a downloadable CSV of them. A retry needs a fresh validation.
- A completed session is no longer available: submitting it again (double click, retry, back button) creates nothing and gets the generic "not available" answer. On MySQL/MariaDB imports run one at a time (database named lock); a second import while one runs is refused without changes.
- Uniqueness is compared as the database compares it (with `utf8mb4_unicode_ci`: case, accents, trailing spaces). After inserting, the import re-checks tag, serial and unique custom-field clashes with a locking query that also sees rows committed by other writers during the import.
- The confirmation is bound to a random validation version stored with the session: every successful validation creates a new one, and changing the target or mapping, a failed validation, and a failed or completed import clear it. A confirmation from an earlier validation never authorises a later one, even within the same second.
- Database-backed uniqueness (decisions A–F, `docs/ERS_ASSET_UNIQUENESS.md`): on MySQL/MariaDB the database itself refuses a second live asset with the same tag, the same non-blank serial while "unique serial" is on, or the same value of a unique non-encrypted custom field, for every writer (web, API, native importer, ERS import, commands), compared with the column's collation. A writer that checked before the import committed and inserts afterwards now waits and is refused. Conflicts are shown as the field's error (web, API), a row diagnostic with a full rollback (ERS import), an import error (native importer) or a reported, never-retagged restore failure (restore, bulk restore); a writer the database stops because of a concurrent transaction is told to try again. Keys are created only after an audit finds no duplicates (counts only; nothing is changed automatically), follow the "unique serial" setting and the custom-field lifecycle, and are checked and repaired with `php artisan ers:asset-uniqueness [--repair]`. A field can no longer newly become both encrypted and unique; existing such fields are kept, unenforced. Not reusing deleted tags and refusing duplicate serials whatever the setting remain ERS-import rules. Remaining limitations are listed in section 8 of that document.
- After completion the import page shows the number of assets created and lists the importer's new asset tags, linked when the user may view them.

## 9. Fixed Assets and Accessories

Individually tracked or high-value items are Assets.

Examples:

- Laptop
- Desktop
- Drone
- Expensive equipment

Low-value quantity-based items are Accessories.

Example: Mouse with total, checked-out and available quantities.

An item with a unique serial number must not have duplicate asset records.

Consumables are removed from the ERS user interface.

## 10. Employees and Check-Out

Employees can receive assets without login access.

Managers perform check-out, return and transfer in Snipe-IT.

The system records:

- Asset holder
- Check-out date
- Return date
- Transfer
- Status
- Full history
- Employee receipt or confirmation

## 11. HRMS Integration

HRMS integration is a later phase and uses the Snipe-IT REST API, not direct database access.

Snipe-IT remains the asset and check-out source of truth.

Snipe-IT users are mapped to HRMS employees through stable user IDs.

## 12. Security and Audit

Requirements include:

- Two named Super Admins
- No shared administrator accounts
- Least-privilege groups
- Administrator 2FA
- Separate integration accounts
- No credentials committed to Git
- Full asset and CSV-import audit history

## 13. Docker Deployment

The production system uses Docker Compose with:

- Snipe-IT application
- Private MariaDB
- Persistent database storage
- Persistent uploaded-file storage
- HTTPS
- Server-side environment configuration
- Backup and rollback procedures
- A tested, pinned image/build

## 14. Branding and Licensing

ERS branding may be applied.

The interface retains an appropriate Snipe-IT/AGPL licence notice, no-warranty notice and source-code link.

## 15. Explicitly Out of Scope

- Google Sheets API
- Google service account
- Google synchronization
- Location-based access restrictions
- Old hardcoded Hardware/Software prototype
- Automatic CSV overwrite/update
- Direct HRMS database access