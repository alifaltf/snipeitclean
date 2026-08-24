'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { REQUIRED_HEADERS } = require('../src/headerMapping');
const { CUSTOM_FIELD_NAMES } = require('../src/customFieldMapping');
const { runApplyCreate, REQUIRED_CONFIRMATION } = require('../src/applyRun');

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
    Password: 'sk-should-never-appear-in-any-payload-or-log',
    'Stakeholder Name / Department': 'Finance Department - Sam Lee',
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

test('fallback does nothing unless --fallback-to-it is explicitly enabled', async () => {
  // Unknown department (Finance Department), fallbackToIt omitted entirely
  // — must behave exactly like before this feature existed: skipped.
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    // fallbackToIt intentionally omitted
  });

  assert.equal(summary.skippedIneligible, 1);
  assert.equal(summary.fallbackUsed, 0);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 0);
  assert.match(results[0].result, /not IT Department or HR Department/);
});

test('fallback does nothing when fallbackToIt is explicitly false', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  const { summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: false,
  });

  assert.equal(summary.skippedIneligible, 1);
  assert.equal(summary.fallbackUsed, 0);
  assert.equal(calls.createAsset.length, 0);
});

test('with --fallback-to-it, an unknown department resolves to company IT Department / location IT Office', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  assert.equal(summary.skippedIneligible, 0);
  assert.equal(summary.fallbackUsed, 1);
  assert.equal(summary.created, 1);
  assert.equal(calls.createAsset.length, 1);

  const payload = calls.createAsset[0];
  assert.equal(payload.company_id, 20); // IT Department id in the fake client
  assert.equal(payload.rtd_location_id, 30); // IT Office id in the fake client

  assert.equal(results[0].company, 'IT Department');
  assert.equal(results[0].location, 'IT Office');
  assert.match(results[0].result, /IT FALLBACK APPLIED/);
});

test('the fallback report clearly names the row\'s original department, and never claims it was "IT Department"', async () => {
  const sheetsClient = makeSheetsClient([baseRecord({ 'Stakeholder Name / Department': 'Marketing - Alex Kim' })]);
  const { client } = makeSnipeitClient();

  const { results } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  assert.match(results[0].result, /IT FALLBACK APPLIED/);
  assert.match(results[0].result, /Marketing - Alex Kim/);
});

test('original Stakeholder Name / Department value is preserved in the custom-field payload, not replaced with "IT Department"', async () => {
  const originalDept = 'Marketing - Alex Kim';
  const sheetsClient = makeSheetsClient([baseRecord({ 'Stakeholder Name / Department': originalDept })]);
  const { client, calls } = makeSnipeitClient();

  await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  const payload = calls.createAsset[0];
  const dbColumn = DB_COLUMN_BY_FIELD_NAME['Stakeholder Name / Department'];
  assert.equal(payload[dbColumn], originalDept);
  assert.notEqual(payload[dbColumn], 'IT Department');
});

test('HR and IT rows keep their exact original mappings even when --fallback-to-it is on', async () => {
  const sheetsClient = makeSheetsClient([
    baseRecord({ 'No.': '2', 'Stakeholder Name / Department': 'Jane Doe - IT Department' }),
    baseRecord({ 'No.': '3', 'System Name': 'HR System', 'Stakeholder Name / Department': 'Pat Roe - HR Department' }),
  ]);
  const { client, calls } = makeSnipeitClient();

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002', 'SYS-0003'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  // Neither row was unrecognized, so fallback must not have been invoked.
  assert.equal(summary.fallbackUsed, 0);
  assert.equal(summary.created, 2);
  assert.ok(!results.some((r) => /IT FALLBACK APPLIED/.test(r.result)));

  const itPayload = calls.createAsset.find((p) => p.asset_tag === 'SYS-0002');
  const hrPayload = calls.createAsset.find((p) => p.asset_tag === 'SYS-0003');
  assert.equal(itPayload.company_id, 20);
  assert.equal(itPayload.rtd_location_id, 30);
  assert.equal(hrPayload.company_id, 21);
  assert.equal(hrPayload.rtd_location_id, 31);
});

test('duplicates are still skipped (never overwritten) when the fallback applies', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient({ existingTags: new Set(['SYS-0002']) });

  const { results, summary } = await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  assert.equal(summary.fallbackUsed, 1);
  assert.equal(summary.skippedDuplicate, 1);
  assert.equal(summary.created, 0);
  assert.equal(calls.createAsset.length, 0);
  assert.match(results[0].result, /already exists/);
  assert.match(results[0].result, /Not overwritten/);
});

test('fallback never looks up or creates a new company/location — only IT Department/IT Office, already resolved', async () => {
  const sheetsClient = makeSheetsClient([baseRecord()]);
  const { client, calls } = makeSnipeitClient();

  await runApplyCreate({
    config: baseConfig,
    sheetsClient,
    snipeitClient: client,
    logger,
    selectedTags: ['SYS-0002'],
    confirmation: REQUIRED_CONFIRMATION,
    fallbackToIt: true,
  });

  // resolveReferenceData always resolves both IT and HR company/location up
  // front (regardless of fallback), and the fallback path must not trigger
  // any additional findByExactName call beyond that fixed, known set.
  const companyLookups = calls.findByExactName.filter((c) => c.resource === 'companies').map((c) => c.name);
  const locationLookups = calls.findByExactName.filter((c) => c.resource === 'locations').map((c) => c.name);
  assert.deepEqual([...new Set(companyLookups)].sort(), ['HR Department', 'IT Department']);
  assert.deepEqual([...new Set(locationLookups)].sort(), ['HR Office', 'IT Office']);
});

test('no update or delete Snipe-IT endpoint is reachable — including via the fallback code path', () => {
  const src = fs.readFileSync(require.resolve('../src/applyRun.js'), 'utf8');
  assert.doesNotMatch(src, /snipeitClient\.(update|delete|patch|remove)/i);
});
