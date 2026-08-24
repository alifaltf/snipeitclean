'use strict';

/**
 * Sheet header -> Snipe-IT custom field *display name*.
 *
 * The connector resolves each of these display names to the field's real
 * `db_column_name` by calling GET /api/v1/fields at runtime (see
 * snipeitClient.js / dryRun.js) — this table only says which sheet column
 * feeds which custom field, it does not hard-code db_column values since
 * those are assigned by Snipe-IT per-install.
 *
 * "Password" must never appear here, in either direction. That invariant
 * is enforced below (throws at require-time) and re-checked in
 * tests/passwordExclusion.test.js.
 */

const SHEET_HEADER_TO_CUSTOM_FIELD = Object.freeze({
  'Platform Type': 'Platform Type',
  'Creation / Delivery Date': 'Creation / Delivery Date',
  'License Renewal Date': 'License Renewal Date',
  'License Renewal': 'License Renewal',
  'License Cost': 'License Cost',
  'Database Instance': 'Database Instance',
  'Database Category': 'Database Category',
  'Database Location': 'Database Location',
  Port: 'Port',
  Domain: 'Domain',
  Username: 'System Username',
  'Stakeholder Name / Department': 'Stakeholder Name / Department',
  'Developer / Vendor': 'Developer / Vendor',
});

if ('Password' in SHEET_HEADER_TO_CUSTOM_FIELD) {
  throw new Error('Safety violation: "Password" must never be mapped to a Snipe-IT custom field.');
}
if (Object.values(SHEET_HEADER_TO_CUSTOM_FIELD).includes('Password')) {
  throw new Error('Safety violation: no custom field may be named/target "Password".');
}

const CUSTOM_FIELD_NAMES = Object.freeze(Object.values(SHEET_HEADER_TO_CUSTOM_FIELD));

module.exports = { SHEET_HEADER_TO_CUSTOM_FIELD, CUSTOM_FIELD_NAMES };
