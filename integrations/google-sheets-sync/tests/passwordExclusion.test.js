'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  REQUIRED_HEADERS,
  EXCLUDED_HEADERS,
  buildHeaderMap,
  rowToRecord,
} = require('../src/headerMapping');
const { SHEET_HEADER_TO_CUSTOM_FIELD, CUSTOM_FIELD_NAMES } = require('../src/customFieldMapping');

test('"Password" is declared in the excluded-headers set', () => {
  assert.ok(EXCLUDED_HEADERS.has('Password'));
});

test('rowToRecord never includes a Password key, even though the column exists in the sheet', () => {
  const headerMap = buildHeaderMap(REQUIRED_HEADERS);
  const passwordColIdx = headerMap['Password'];
  assert.notEqual(passwordColIdx, undefined, 'test setup sanity check');

  const secretMarker = 'sk-super-secret-value-should-never-appear';
  const row = REQUIRED_HEADERS.map((_, i) => (i === passwordColIdx ? secretMarker : `value-${i}`));

  const record = rowToRecord(row, headerMap);

  assert.equal('Password' in record, false);
  assert.equal(JSON.stringify(record).includes(secretMarker), false);
});

test('the custom-field mapping table never references Password in either direction', () => {
  assert.equal('Password' in SHEET_HEADER_TO_CUSTOM_FIELD, false);
  assert.equal(CUSTOM_FIELD_NAMES.includes('Password'), false);
});

test('every mapped custom field target is one of the thirteen approved names, and all thirteen are present', () => {
  // Exact-set equality in both directions: this catches both an
  // unexpected extra target AND a required field silently dropped from
  // customFieldMapping.js (the bug this test previously had — its title
  // said "twelve" while the Set below actually had thirteen entries, and
  // the assertion only checked one direction, so a dropped field would
  // have passed silently instead of failing this test).
  const approved = new Set([
    'Platform Type',
    'Creation / Delivery Date',
    'License Renewal Date',
    'License Renewal',
    'License Cost',
    'Database Instance',
    'Database Category',
    'Database Location',
    'Port',
    'Domain',
    'System Username',
    'Stakeholder Name / Department',
    'Developer / Vendor',
  ]);

  assert.equal(approved.size, 13, 'test setup sanity check — approved list itself must have 13 entries');
  assert.equal(CUSTOM_FIELD_NAMES.length, 13, `expected exactly 13 mapped custom fields, found ${CUSTOM_FIELD_NAMES.length}`);

  const actual = new Set(CUSTOM_FIELD_NAMES);
  assert.equal(actual.size, 13, 'CUSTOM_FIELD_NAMES must not contain duplicates');

  for (const target of actual) {
    assert.ok(approved.has(target), `unexpected custom field target: ${target}`);
  }
  for (const name of approved) {
    assert.ok(actual.has(name), `required custom field is missing from the mapping: ${name}`);
  }
});
