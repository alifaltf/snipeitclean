'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { resolveDepartment } = require('../src/departmentMapping');

test('IT Department is eligible and maps to IT Office', () => {
  const r = resolveDepartment('Jane Doe - IT Department');
  assert.equal(r.eligible, true);
  assert.equal(r.company, 'IT Department');
  assert.equal(r.location, 'IT Office');
});

test('HR Department is eligible and maps to HR Office', () => {
  const r = resolveDepartment('HR Department');
  assert.equal(r.eligible, true);
  assert.equal(r.company, 'HR Department');
  assert.equal(r.location, 'HR Office');
});

test('department matching is case-insensitive', () => {
  const r = resolveDepartment('john doe - it department');
  assert.equal(r.eligible, true);
  assert.equal(r.company, 'IT Department');
});

test('unrelated departments are ineligible with a clear reason', () => {
  const r = resolveDepartment('Finance Department');
  assert.equal(r.eligible, false);
  assert.match(r.reason, /Finance Department/);
  assert.match(r.reason, /not IT Department or HR Department/);
});

test('blank department text is ineligible', () => {
  const r = resolveDepartment('');
  assert.equal(r.eligible, false);
  assert.match(r.reason, /\(blank\)/);
});

test('overly long department text is truncated in the reason', () => {
  const longText = 'X'.repeat(200);
  const r = resolveDepartment(longText);
  assert.equal(r.eligible, false);
  assert.ok(r.reason.length < 200);
});
