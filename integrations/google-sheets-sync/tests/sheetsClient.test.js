'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { quoteSheetName } = require('../src/sheetsClient');

test('quotes a sheet name containing spaces', () => {
  assert.equal(quoteSheetName('Systems Inventory'), "'Systems Inventory'");
});

test('escapes embedded single quotes by doubling them', () => {
  assert.equal(quoteSheetName("Bob's Sheet"), "'Bob''s Sheet'");
});

test('still quotes simple names (harmless, always valid)', () => {
  assert.equal(quoteSheetName('Sheet1'), "'Sheet1'");
});
