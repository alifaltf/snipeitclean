'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  validateDateField,
  validateNumericField,
  validateRowFields,
} = require('../src/fieldValidation');

test('validateDateField: blank value is valid (optional field)', () => {
  assert.equal(validateDateField('', 'Creation / Delivery Date').valid, true);
  assert.equal(validateDateField('   ', 'Creation / Delivery Date').valid, true);
});

test('validateDateField: accepts ISO YYYY-MM-DD', () => {
  const r = validateDateField('2024-01-15', 'Creation / Delivery Date');
  assert.equal(r.valid, true);
});

test('validateDateField: accepts M/D/YYYY', () => {
  const r = validateDateField('1/15/2024', 'License Renewal Date');
  assert.equal(r.valid, true);
});

test('validateDateField: rejects an impossible calendar date', () => {
  const r = validateDateField('2024-13-40', 'Creation / Delivery Date');
  assert.equal(r.valid, false);
  assert.match(r.reason, /not a recognizable date/);
});

test('validateDateField: rejects free text', () => {
  const r = validateDateField('sometime next quarter', 'License Renewal Date');
  assert.equal(r.valid, false);
  assert.match(r.reason, /License Renewal Date/);
});

test('validateNumericField: blank value is valid (optional field)', () => {
  assert.equal(validateNumericField('', 'Port', { integer: true, min: 1, max: 65535 }).valid, true);
});

test('validateNumericField: Port must be an integer within range', () => {
  assert.equal(validateNumericField('5432', 'Port', { integer: true, min: 1, max: 65535 }).valid, true);
  assert.equal(validateNumericField('0', 'Port', { integer: true, min: 1, max: 65535 }).valid, false);
  assert.equal(validateNumericField('70000', 'Port', { integer: true, min: 1, max: 65535 }).valid, false);
  assert.equal(validateNumericField('5432.5', 'Port', { integer: true, min: 1, max: 65535 }).valid, false);
  assert.equal(validateNumericField('not-a-number', 'Port', { integer: true, min: 1, max: 65535 }).valid, false);
});

test('validateNumericField: License Cost allows decimals but not negatives', () => {
  assert.equal(validateNumericField('120.50', 'License Cost', { integer: false, min: 0 }).valid, true);
  assert.equal(validateNumericField('0', 'License Cost', { integer: false, min: 0 }).valid, true);
  assert.equal(validateNumericField('-5', 'License Cost', { integer: false, min: 0 }).valid, false);
});

test('validateRowFields: collects every problem across all date/numeric fields, not just the first', () => {
  const record = {
    'Creation / Delivery Date': 'garbage',
    'License Renewal Date': '2024-01-15',
    Port: '999999',
    'License Cost': '-10',
  };
  const result = validateRowFields(record);
  assert.equal(result.valid, false);
  assert.equal(result.errors.length, 3);
});

test('validateRowFields: a fully blank/valid row passes with no errors', () => {
  const result = validateRowFields({});
  assert.equal(result.valid, true);
  assert.deepEqual(result.errors, []);
});
