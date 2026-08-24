'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { loadConfig, requireSyncMode, ConfigError, KNOWN_SYNC_MODES } = require('../src/config');

function writeTempEnv(overrides = {}) {
  const vars = {
    SNIPEIT_API_TOKEN: 'test-token',
    GOOGLE_APPLICATION_CREDENTIALS: '/tmp/fake-creds.json',
    GOOGLE_SPREADSHEET_ID: 'test-sheet-id',
    GOOGLE_SHEET_NAME: 'Systems Inventory',
    SNIPEIT_BASE_URL: 'https://snipeit.example.test',
    SYNC_MODE: 'dry-run',
    ...overrides,
  };
  const contents = Object.entries(vars)
    .map(([k, v]) => `${k}=${v}`)
    .join('\n');
  const filePath = path.join(os.tmpdir(), `connector-test-${process.pid}-${Math.floor(Math.random() * 1e9)}.env`);
  fs.writeFileSync(filePath, contents, 'utf8');
  return filePath;
}

test('loadConfig accepts SYNC_MODE=dry-run', () => {
  const envPath = writeTempEnv({ SYNC_MODE: 'dry-run' });
  try {
    const config = loadConfig({ envPath });
    assert.equal(config.syncMode, 'dry-run');
  } finally {
    fs.unlinkSync(envPath);
  }
});

test('loadConfig accepts SYNC_MODE=apply', () => {
  const envPath = writeTempEnv({ SYNC_MODE: 'apply' });
  try {
    const config = loadConfig({ envPath });
    assert.equal(config.syncMode, 'apply');
  } finally {
    fs.unlinkSync(envPath);
  }
});

test('loadConfig rejects an unknown SYNC_MODE', () => {
  const envPath = writeTempEnv({ SYNC_MODE: 'production-yolo' });
  try {
    assert.throws(() => loadConfig({ envPath }), ConfigError);
  } finally {
    fs.unlinkSync(envPath);
  }
});

test('KNOWN_SYNC_MODES is exactly dry-run and apply', () => {
  assert.deepEqual([...KNOWN_SYNC_MODES].sort(), ['apply', 'dry-run']);
});

test('requireSyncMode passes when the mode matches', () => {
  assert.doesNotThrow(() => requireSyncMode({ syncMode: 'apply' }, 'apply'));
});

test('requireSyncMode throws ConfigError when the mode does not match', () => {
  assert.throws(() => requireSyncMode({ syncMode: 'dry-run' }, 'apply'), ConfigError);
  assert.throws(() => requireSyncMode({ syncMode: 'apply' }, 'dry-run'), ConfigError);
});

test('requireSyncMode rejects an unrecognized expected mode (defensive check)', () => {
  assert.throws(() => requireSyncMode({ syncMode: 'apply' }, 'anything-goes'), ConfigError);
});
