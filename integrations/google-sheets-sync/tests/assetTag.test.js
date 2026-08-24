'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { formatAssetTag, AssetTagError } = require('../src/assetTag');

test('formats a small number with zero-padding (No. 2 -> SYS-0002)', () => {
  assert.equal(formatAssetTag(2), 'SYS-0002');
});

test('formats from a string with surrounding whitespace', () => {
  assert.equal(formatAssetTag(' 7 '), 'SYS-0007');
});

test('formats a 4-digit number without truncation', () => {
  assert.equal(formatAssetTag(9999), 'SYS-9999');
});

test('formats 1 as SYS-0001', () => {
  assert.equal(formatAssetTag(1), 'SYS-0001');
});

test('rejects zero', () => {
  assert.throws(() => formatAssetTag(0), AssetTagError);
});

test('rejects negative numbers', () => {
  assert.throws(() => formatAssetTag(-5), AssetTagError);
});

test('rejects non-numeric input', () => {
  assert.throws(() => formatAssetTag('abc'), AssetTagError);
});

test('rejects decimal input', () => {
  assert.throws(() => formatAssetTag('2.5'), AssetTagError);
});

test('rejects numbers exceeding 4-digit capacity', () => {
  assert.throws(() => formatAssetTag(10000), AssetTagError);
});

test('rejects blank input', () => {
  assert.throws(() => formatAssetTag(''), AssetTagError);
  assert.throws(() => formatAssetTag(null), AssetTagError);
  assert.throws(() => formatAssetTag(undefined), AssetTagError);
});
