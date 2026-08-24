#!/usr/bin/env node
'use strict';

const { loadConfig, requireSyncMode, ConfigError } = require('../src/config');
const { createClient } = require('../src/snipeitClient');
const { fetchSheetValues } = require('../src/sheetsClient');
const { runApplyCreate, SafeguardError, REQUIRED_CONFIRMATION } = require('../src/applyRun');
const { printApplyReport } = require('../src/report');
const logger = require('../src/logger');

function parseArgs(argv) {
  // Deliberately NOT named --env-file: Node.js >= 20.6 reserves that flag
  // for its own native env-file loading (see bin/dry-run.js).
  const opts = { tags: [], confirm: undefined, envPath: undefined, fallbackToIt: false };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--config' && argv[i + 1]) {
      opts.envPath = argv[i + 1];
      i++;
    } else if (argv[i] === '--tags' && argv[i + 1]) {
      opts.tags = argv[i + 1]
        .split(',')
        .map((t) => t.trim().toUpperCase())
        .filter((t) => t !== '');
      i++;
    } else if (argv[i] === '--confirm' && argv[i + 1]) {
      opts.confirm = argv[i + 1];
      i++;
    } else if (argv[i] === '--fallback-to-it') {
      // Opt-in only, no value expected. Without this flag present,
      // behavior is byte-for-byte unchanged from before this flag existed:
      // unrecognized departments are skipped.
      opts.fallbackToIt = true;
    }
  }
  // De-duplicate while preserving order.
  opts.tags = [...new Set(opts.tags)];
  return opts;
}

async function main() {
  const { envPath, tags, confirm, fallbackToIt } = parseArgs(process.argv.slice(2));

  // Structural pre-check: fail fast, before ANY file/network I/O, on the
  // two safeguards that don't require config at all. SYNC_MODE is checked
  // right after config loads below, and all three are re-checked together
  // inside runApplyCreate() before any network call — this is defense in
  // depth, not the only check.
  if (tags.length === 0 || confirm !== REQUIRED_CONFIRMATION) {
    logger.error(
      'Refusing to run — this command requires:\n' +
        '  --tags <comma-separated asset tags>   (explicit selection, e.g. SYS-0002,SYS-0003)\n' +
        `  --confirm ${REQUIRED_CONFIRMATION}\n` +
        '  and SYNC_MODE=apply in the environment file.\n' +
        'Exiting without loading config or writing anything.'
    );
    process.exitCode = 2;
    return;
  }

  const config = loadConfig({ envPath });
  requireSyncMode(config, 'apply');

  logger.info('Phase 2 — CREATE-ONLY APPLY MODE. This WILL write new assets to Snipe-IT.');
  logger.info(`Config loaded from: ${config.envPath}`);
  logger.info(`Snipe-IT base URL: ${config.snipeitBaseUrl}`);
  logger.info(`Google Sheet: spreadsheet ${config.spreadsheetId}, tab "${config.sheetName}"`);
  logger.info(`Explicitly selected asset tag(s): ${tags.join(', ')}`);
  if (fallbackToIt) {
    logger.info(
      'IT fallback ENABLED (--fallback-to-it): among the selected tags, any row whose department is not ' +
        'IT Department or HR Department will use company "IT Department" / location "IT Office" instead of ' +
        'being skipped. The row\'s original Stakeholder Name / Department value is still preserved in its custom field.'
    );
  }

  const snipeitClient = createClient({
    baseUrl: config.snipeitBaseUrl,
    apiToken: config.snipeitApiToken,
  });

  const sheetsClient = {
    fetchSheetValues: () =>
      fetchSheetValues({
        spreadsheetId: config.spreadsheetId,
        sheetName: config.sheetName,
        credentialsPath: config.googleCredentialsPath,
      }),
  };

  const { results, summary } = await runApplyCreate({
    config,
    sheetsClient,
    snipeitClient,
    logger,
    selectedTags: tags,
    confirmation: confirm,
    fallbackToIt,
  });
  printApplyReport(results, summary);

  if (summary.errors > 0 || summary.invalid > 0 || summary.notFoundInSheet > 0) {
    logger.warn(
      `Completed with ${summary.errors} error(s), ${summary.invalid} invalid row(s), ` +
        `${summary.notFoundInSheet} tag(s) not found — see table above.`
    );
    process.exitCode = 1;
  } else {
    logger.info(`Apply complete. Created ${summary.created} asset(s).`);
  }
}

main().catch((err) => {
  if (err instanceof ConfigError || err instanceof SafeguardError) {
    logger.error(err.message);
  } else {
    logger.error(err.message || String(err));
  }
  process.exitCode = 2;
});
