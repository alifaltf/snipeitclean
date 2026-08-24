'use strict';

/**
 * Tiny logging wrapper whose only job is to make it structurally awkward
 * to accidentally print a secret. It does not know what a "secret" is —
 * callers are expected to only ever pass sanitized/safe strings — but
 * describeConfig() below is the one place allowed to touch config, and it
 * never reads the actual token/credential values, only whether they're set.
 */

function info(msg) {
  console.log(`[google-sheets-sync] ${msg}`);
}

function warn(msg) {
  console.warn(`[google-sheets-sync] WARN: ${msg}`);
}

function error(msg) {
  console.error(`[google-sheets-sync] ERROR: ${msg}`);
}

/**
 * Safe-to-print summary of a loaded config: presence flags only, never
 * values, for the fields the safety rules classify as sensitive.
 */
function describeConfig(config) {
  return {
    envPath: config.envPath,
    snipeitBaseUrl: config.snipeitBaseUrl,
    snipeitApiToken: config.snipeitApiToken ? '[set]' : '[missing]',
    googleCredentialsPath: config.googleCredentialsPath ? '[set]' : '[missing]',
    spreadsheetId: config.spreadsheetId,
    sheetName: config.sheetName,
    syncMode: config.syncMode,
  };
}

module.exports = { info, warn, error, describeConfig };
