'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { REQUIRED_HEADERS } = require('../src/headerMapping');
const { CUSTOM_FIELD_NAMES } = require('../src/customFieldMapping');
const { runApplyCreate, SafeguardError, REQUIRED_CONFIRMATION } = require('../src/applyRun');

// Synthetic db_column_name values, standing in for what a real Snipe-IT
// install's GET /api/v1/fields would return.
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

const SECRET_MARKER = 'sk-should-never-appear-in-any-payload-or-log';

function baseRecord(overrides = {}) {
  return {
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
    Password: SECRET_MARKER,
    'Stakeholder Name / Department': 'Jane Doe - IT Department',
    'Developer / Vendor': 'Acme Vendor Co',
    'Remark / Description': 'Test remark',
    ...overrides,
  };
}

function rowFromRecord(record) {
  return REQUIRED_HEADERS.map((h) => (h in record ? record[h] : ''));
}

function makeSheetsClient(dataRecords) {
  const rows = [REQUIRED_HEADERS, ...dataRecords.map(rowFromRecord)];
  return { fetchSheetValues: async () => rows };
}

const STATUS_ORDER = ['Active', 'Under Maintenance', 'Inactive', 'Pending'];

function makeSnipeitClient({ existingTags = new Set(), createImpl } = {}) {
  const calls = { me: 0, findByExactName: [], findAssetByTag: [], createAsset: [] };
  let nextId = 100;
  const client = {
    me: async () => {
      calls.me++;
      return { id: 1 };
    },
    findByExactName: async (resource, name) => {
      calls.findByExactName.push({ resource, name });
      if (resource === 'models' && name === 'Digital System') {
        return [{ id: 10, name, category: { name: 'Digital Systems' } }];
      }
      if (resource === 'companies') {
        return [{ id: name === 'IT Department' ? 20 : 21, name }];
      }
      if (resource === 'locations') {
        return [{ id: name === 'IT Office' ? 30 : 31, name }];
      }
      if (resource === 'statuslabels') {
        const idx = STATUS_ORDER.indexOf(name);
        return idx === -1 ? [] : [{ id: 40 + idx, name }];
      }
      return [];
    },
    listCustomFields: async () =>
      CUSTOM_FIELD_NAMES.map((name) => ({ name, db_column_name: DB_COLUMN_BY_FIELD_NAME[name] })),
    findAssetByTag: async (tag) => {
      calls.findAssetByTag.push(tag);
      return existingTags.has(tag) ? { id: 999, asset_tag: tag } : null;
    },
    createAsset: async (payload) => {
      calls.createAsset.push(payload);
      if (createImpl) return createImpl(payload);
      return { status: 'success', payload: { id: nextId++, asset_tag: payload.asset_tag } };
    },
  };
  return { client, calls };
}

const logger = { info: () => {}, warn: () => {}, error: () => {} };
const baseConfig = { syncMode: 'apply', snipeitBaseUrl: 'https://snipeit.example.test', sheetName: 'Sheet1' };

test('missing safeguards prevent any write — and no network call is ever made', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  await assert.rejects(
    () =>
      runApplyCreate({
        config: { ...baseConfig, syncMode: 'dry-run' }, // wrong SYNC_MODE
        sheetsClient,
        snipeitClient: client,
        logger,
        selectedTags: ['SYS-0002'],
        confirmation: REQUIRED_CONFIRMATION,
      }),
    SafeguardError
  );

  await assert.rejects(
    () =>
      runApplyCreate({
        config: baseConfig,
        sheetsClient,
        snipeitClient: client,
        logger,
        selectedTags: [], // no explicit selection
        confirmation: REQUIRED_CONFIRMATION,
      }),
    SafeguardError
  );

  await assert.rejects(
    () =>
      runApplyCreate({
        config: baseConfig,
        sheetsClient,
        snipeitClient: client,
        logger,
        selectedTags: ['SYS-0002'],
        confirmation: 'yes please', // wrong confirmation text
      }),
    SafeguardError
  );

  assert.equal(calls.me, 0, 'no auth check should have happened');
  assert.equal(calls.findAssetByTag.length, 0, 'no duplicate check should have happened');
  assert.equal(calls.createAsset.length, 0, 'no create should have happened');
});

test('only explicitly selected asset tags are eligible — other rows are never touched', async () => {
  const sheetsClient = makeSheetsClient([
    baseRecord({ 'No.': '1', 'System Name': 'Not Selected System' }),
    baseRecord({ 'No.': '2', 'System Name': 'Payroll DB' }),
  ]);
  const { client, calls } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.selected, 1);
  assert.equal(summary.foundInSheet, 1);
  assert.equal(summary.created, 1);
  assert.equal(calls.createAsset.length, 1);
  assert.equal(calls.createAsset[0].asset_tag, 'SYS-0002');

  assert.equal(calls.findAssetByTag.includes('SYS-0001'), false);
  assert.ok(!results.some((r) => r.assetTag === 'SYS-0001'));
});

test('duplicates are skipped and never overwritten', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient({ existingTags: new Set(['SYS-0002']) });

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.skippedDuplicate, 1);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 0);
  assert.match(results[0].result, /already exists/);
  assert.match(results[0].result, /Not overwritten/);
});

test('the create payload includes all 13 custom fields, and Password never reaches it', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(calls.createAsset.length, 1);
  const payload = calls.createAsset[0];

  assert.equal(CUSTOM_FIELD_NAMES.length, 13);
  for (const fieldName of CUSTOM_FIELD_NAMES) {
    assert.ok(DB_COLUMN_BY_FIELD_NAME[fieldName] in payload, `missing db_column for "${fieldName}"`);
  }

  assert.equal('Password' in payload, false);
  assert.equal(JSON.stringify(payload).includes(SECRET_MARKER), false);
});

test('never creates a row from a non-eligible department, even if its tag is explicitly selected', async () => {
  const sheetsClient = makeSheetsClient([
    baseRecord({ 'Stakeholder Name / Department': 'Finance Department' }),
  ]);
  const { client, calls } = makeSnipeitClient();

  const { summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.skippedIneligible, 1);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 0);
});

test('invalid date/numeric fields are reported, not created', async () => {
  const sheetsClient = makeSheetsClient([baseRecord({ Port: 'not-a-port' })]);
  const { client, calls } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.invalid, 1);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 0);
  assert.match(results[0].result, /Invalid/);
});

test('a selected tag not found in the sheet is reported, not silently dropped', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002', 'SYS-9999'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.notFoundInSheet, 1);
  assert.ok(results.some((r) => r.assetTag === 'SYS-9999' && /Not found/.test(r.result)));
});

test('a Snipe-IT create-time validation error is reported per-row and does not throw the whole run', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient({
    createImpl: () => ({ status: 'error', messages: { asset_tag: ['The asset tag has already been taken.'] } }),
  });

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
  });

  assert.equal(summary.errors, 1);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 1);
  assert.match(results[0].result, /rejected the create/);
});

test('applyRun.js source never calls an update/delete method on snipeitClient', () => {
  const src = fs.readFileSync(require.resolve('../src/applyRun.js'), 'utf8');
  assert.doesNotMatch(src, /snipeitClient\.(update|delete|patch|remove)/i);
});

test('REQUIRED_CONFIRMATION is the exact expected string', () => {
  assert.equal(REQUIRED_CONFIRMATION, 'CREATE_DUMMY_ASSETS');
});
