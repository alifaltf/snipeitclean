'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { buildAssetPayload } = require('../src/assetPayload');
const { CUSTOM_FIELD_NAMES } = require('../src/customFieldMapping');

// Synthetic db_column_name values, one per approved custom field — stands
// in for what GET /api/v1/fields would return in a real Snipe-IT install.
const DB_COLUMN_BY_FIELD_NAME = {
  'Platform Type': '_snipeit_platform_type_1',
  'Creation / Delivery Date': '_snipeit_creation_delivery_date_2',
  'License Renewal Date': '_snipeit_license_renewal_date_3',
  'License Renewal': '_snipeit_license_renewal_4',
  'License Cost': '_snipeit_license_cost_5',
  'Database Instance': '_snipeit_database_instance_6',
  'Database Category': '_snipeit_database_category_7',
  'Database Location': '_snipeit_database_location_8',
  Port: '_snipeit_port_9',
  Domain: '_snipeit_domain_10',
  'System Username': '_snipeit_system_username_11',
  'Stakeholder Name / Department': '_snipeit_stakeholder_name_department_12',
  'Developer / Vendor': '_snipeit_developer_vendor_13',
};

// A record as headerMapping.rowToRecord() would actually produce: no
// "Password" key at all (it's stripped before this point), "Username"
// present under its sheet header name (maps to custom field "System
// Username").
const SAMPLE_RECORD = Object.freeze({
  'No.': '2',
  Status: 'Active',
  'System Name': 'Payroll DB',
  'Platform Type': 'Web App',
  'Creation / Delivery Date': '2024-01-15',
  'License Renewal Date': '2025-01-15',
  'License Renewal': 'Yes',
  'License Cost': '120.50',
  'Database Instance': 'PROD-DB-01',
  'Database Category': 'SQL',
  'Database Location': 'US-East',
  Port: '5432',
  Domain: 'payroll.internal.local',
  Username: 'pdbuser',
  'Stakeholder Name / Department': 'Jane Doe - IT Department',
  'Developer / Vendor': 'Acme Vendor Co',
  'Remark / Description': 'Test remark',
});

function buildSamplePayload(overrides = {}) {
  return buildAssetPayload({
    record: SAMPLE_RECORD,
    assetTag: 'SYS-0002',
    systemName: 'Payroll DB',
    notes: 'Test remark',
    modelId: 10,
    statusId: 40,
    companyId: 20,
    locationId: 30,
    dbColumnByFieldName: DB_COLUMN_BY_FIELD_NAME,
    ...overrides,
  });
}

test('buildAssetPayload maps the core asset fields', () => {
  const payload = buildSamplePayload();
  assert.equal(payload.asset_tag, 'SYS-0002');
  assert.equal(payload.name, 'Payroll DB');
  assert.equal(payload.model_id, 10);
  assert.equal(payload.status_id, 40);
  assert.equal(payload.company_id, 20);
  // Ready-to-deploy/default location — NOT location_id (current/checked-out
  // location, which doesn't apply to a brand-new asset).
  assert.equal(payload.rtd_location_id, 30);
  assert.equal('location_id' in payload, false);
  assert.equal(payload.notes, 'Test remark');
});

test('buildAssetPayload includes all 13 custom fields, keyed by their real db_column_name', () => {
  const payload = buildSamplePayload();

  assert.equal(CUSTOM_FIELD_NAMES.length, 13);
  for (const fieldName of CUSTOM_FIELD_NAMES) {
    const dbColumn = DB_COLUMN_BY_FIELD_NAME[fieldName];
    assert.ok(dbColumn in payload, `payload is missing db_column for custom field "${fieldName}"`);
  }

  assert.equal(payload._snipeit_platform_type_1, 'Web App');
  assert.equal(payload._snipeit_port_9, '5432');
  assert.equal(payload._snipeit_license_cost_5, '120.50');
});

test('Sheet "Username" maps to custom field "System Username"', () => {
  const payload = buildSamplePayload();
  assert.equal(payload._snipeit_system_username_11, 'pdbuser');
});

test('Password is structurally excluded from every create payload, even if present in the record', () => {
  const secretMarker = 'sk-super-secret-password-should-never-appear';
  const recordWithPassword = { ...SAMPLE_RECORD, Password: secretMarker };

  const payload = buildAssetPayload({
    record: recordWithPassword,
    assetTag: 'SYS-0002',
    systemName: 'Payroll DB',
    notes: 'Test remark',
    modelId: 10,
    statusId: 40,
    companyId: 20,
    locationId: 30,
    dbColumnByFieldName: DB_COLUMN_BY_FIELD_NAME,
  });

  assert.equal(JSON.stringify(payload).includes(secretMarker), false);
  assert.equal('Password' in payload, false);
  assert.equal(Object.values(payload).includes(secretMarker), false);
});

test('buildAssetPayload throws (and builds nothing) if a required custom field db_column is unresolved', () => {
  const incompleteMap = { ...DB_COLUMN_BY_FIELD_NAME, Port: null };
  assert.throws(
    () => buildSamplePayload({ dbColumnByFieldName: incompleteMap }),
    /Port/
  );
});

test('payload has exactly 7 core fields + 13 custom fields = 20 keys', () => {
  const payload = buildSamplePayload();
  assert.equal(Object.keys(payload).length, 20);
});
