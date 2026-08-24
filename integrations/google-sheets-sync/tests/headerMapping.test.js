'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  REQUIRED_HEADERS,
  findHeaderRowIndex,
  buildHeaderMap,
  validateHeaders,
  rowToRecord,
  isBlankRow,
} = require('../src/headerMapping');

test('detects the header row when it follows a warning banner in row 1', () => {
  const rows = [
    ['DO NOT EDIT BELOW THIS LINE'],
    REQUIRED_HEADERS,
    ['1', 'Active', 'Test System'],
  ];
  assert.equal(findHeaderRowIndex(rows), 1);
});

test('detects the header row when it is the first row', () => {
  const rows = [REQUIRED_HEADERS, ['1', 'Active', 'Test System']];
  assert.equal(findHeaderRowIndex(rows), 0);
});

test('returns -1 when no row contains both Status and System Name', () => {
  assert.equal(findHeaderRowIndex([['a', 'b'], ['c', 'd']]), -1);
});

test('header detection is case-insensitive and trims whitespace', () => {
  const rows = [[' status ', ' SYSTEM NAME ', 'No.']];
  assert.equal(findHeaderRowIndex(rows), 0);
});

test('buildHeaderMap maps each header to its column index', () => {
  const map = buildHeaderMap(['No.', 'Status', 'System Name']);
  assert.deepEqual(map, { 'No.': 0, Status: 1, 'System Name': 2 });
});

test('validateHeaders passes when all required headers are present', () => {
  const map = buildHeaderMap(REQUIRED_HEADERS);
  const result = validateHeaders(map);
  assert.equal(result.valid, true);
  assert.deepEqual(result.missing, []);
});

test('validateHeaders reports every missing header', () => {
  const map = buildHeaderMap(['No.', 'Status']);
  const result = validateHeaders(map);
  assert.equal(result.valid, false);
  assert.ok(result.missing.includes('System Name'));
  assert.ok(result.missing.includes('Password'));
});

test('rowToRecord maps values by header name', () => {
  const map = buildHeaderMap(['No.', 'Status', 'System Name']);
  const record = rowToRecord(['5', 'Active', 'Payroll DB'], map);
  assert.deepEqual(record, { 'No.': '5', Status: 'Active', 'System Name': 'Payroll DB' });
});

test('isBlankRow treats an all-empty row as blank', () => {
  assert.equal(isBlankRow(['', '  ', undefined]), true);
  assert.equal(isBlankRow(['', 'x']), false);
  assert.equal(isBlankRow(undefined), true);
});
