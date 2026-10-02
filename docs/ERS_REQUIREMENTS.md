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