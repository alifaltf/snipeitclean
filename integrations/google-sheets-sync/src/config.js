'use strict';

const dotenv = require('dotenv');

// The user's connector.env lives OUTSIDE this repo, on purpose (see README).
// This default matches the path given for this project; override with
// --config or CONNECTOR_ENV_PATH for any other machine/checkout.
const DEFAULT_ENV_PATH = 'C:\\Users\\Alif Altaf\\snipeit-secrets\\connector.env';

const REQUIRED_VARS = [
  'SNIPEIT_API_TOKEN',
  'GOOGLE_APPLICATION_CREDENTIALS',
  'GOOGLE_SPREADSHEET_ID',
  'GOOGLE_SHEET_NAME',
  'SNIPEIT_BASE_URL',
  'SYNC_MODE',
];

// The only SYNC_MODE values this connector understands at all. Anything
// else in connector.env is rejected at load time regardless of which
// command is being run.
const KNOWN_SYNC_MODES = ['dry-run', 'apply'];

class ConfigError extends Error {}

/**
 * Loads connector.env from an external path (never copied into the repo)
 * and returns a plain config object. Values are read into memory only;
 * nothing here prints or logs the actual secret values.
 *
 * Note: loadConfig() itself does NOT enforce which SYNC_MODE a given
 * command requires — call requireSyncMode(config, expected) right after
 * loadConfig() in each bin/*.js entrypoint for that. Keeping the two
 * separate lets bin/dry-run.js and bin/apply-create.js each demand their
 * own exact mode without either command being able to loosen the other's
 * requirement.
 *
 * @param {{envPath?: string}} opts
 */
function loadConfig(opts = {}) {
  const resolvedPath = opts.envPath || process.env.CONNECTOR_ENV_PATH || DEFAULT_ENV_PATH;

  const result = dotenv.config({ path: resolvedPath, override: true });
  if (result.error) {
    throw new ConfigError(
      `Could not load environment file at "${resolvedPath}" (${result.error.code || result.error.message}). ` +
      'Pass --config <path> or set CONNECTOR_ENV_PATH if it lives somewhere else.'
    );
  }

  const missing = REQUIRED_VARS.filter((key) => !process.env[key] || process.env[key].trim() === '');
  if (missing.length) {
    throw new ConfigError(`Missing required variable(s) in ${resolvedPath}: ${missing.join(', ')}`);
  }

  if (!KNOWN_SYNC_MODES.includes(process.env.SYNC_MODE)) {
    throw new ConfigError(
      `SYNC_MODE is "${process.env.SYNC_MODE}", but must be one of: ${KNOWN_SYNC_MODES.join(', ')}.`
    );
  }

  return Object.freeze({
    envPath: resolvedPath,
    snipeitBaseUrl: process.env.SNIPEIT_BASE_URL,
    snipeitApiToken: process.env.SNIPEIT_API_TOKEN,
    googleCredentialsPath: process.env.GOOGLE_APPLICATION_CREDENTIALS,
    spreadsheetId: process.env.GOOGLE_SPREADSHEET_ID,
    sheetName: process.env.GOOGLE_SHEET_NAME,
    syncMode: process.env.SYNC_MODE,
  });
}

/**
 * Enforces that a loaded config's SYNC_MODE matches exactly what the
 * calling command requires. bin/dry-run.js calls this with 'dry-run';
 * bin/apply-create.js calls this with 'apply'. This is one of the
 * safeguards checked before bin/apply-create.js does anything else.
 *
 * @param {ReturnType<typeof loadConfig>} config
 * @param {'dry-run'|'apply'} expected
 */
function requireSyncMode(config, expected) {
  if (!KNOWN_SYNC_MODES.includes(expected)) {
    throw new ConfigError(`requireSyncMode: unknown expected mode "${expected}".`);
  }
  if (config.syncMode !== expected) {
    throw new ConfigError(
      `SYNC_MODE is "${config.syncMode}", but this command requires SYNC_MODE=${expected}. Refusing to continue.`
    );
  }
}

module.exports = { loadConfig, requireSyncMode, ConfigError, DEFAULT_ENV_PATH, REQUIRED_VARS, KNOWN_SYNC_MODES };
