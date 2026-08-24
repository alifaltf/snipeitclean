#!/usr/bin/env node
'use strict';

const { loadConfig, requireSyncMode, ConfigError } = require('../src/config');
const { createClient } = require('../src/snipeitClient');
const { fetchSheetValues } = require('../src/sheetsClient');
const { runDryRun } = require('../src/dryRun');
const { printReport } = require('../src/report');
const logger = require('../src/logger');

function parseArgs(argv) {
  // Deliberately NOT named --env-file: Node.js >= 20.6 reserves that flag
  // for its own native env-file loading and intercepts it before this
  // script ever runs, which silently bypasses our config loader/validation.
  const opts = {};
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--config' && argv[i + 1]) {
      opts.envPath = argv[i + 1];
      i++;
    }
  }
  return opts;
}

async function main() {
  const { envPath } = parseArgs(process.argv.slice(2));
  const config = loadConfig({ envPath });
  // Unchanged Phase 1 behavior: this command still refuses to run unless
  // SYNC_MODE=dry-run, even though config.js now also accepts "apply" for
  // bin/apply-create.js. This check is what preserves that.
  requireSyncMode(config, 'dry-run');

  logger.info('Phase 1 — DRY RUN ONLY. No Snipe-IT writes will be made, no Sheet writes will be made.');
  logger.info(`Config loaded from: ${config.envPath}`);
  logger.info(`Snipe-IT base URL: ${config.snipeitBaseUrl}`);
  logger.info(`Google Sheet: spreadsheet ${config.spreadsheetId}, tab "${config.sheetName}"`);

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

  const { results, summary } = await runDryRun({ config, sheetsClient, snipeitClient, logger });
  printReport(results, summary);

  if (summary.errors > 0) {
    logger.warn(`Completed with ${summary.errors} row error(s) — see table above.`);
    process.exitCode = 1;
  } else {
    logger.info('Dry run complete.');
  }
}

main().catch((err) => {
  if (err instanceof ConfigError) {
    logger.error(err.message);
  } else {
    logger.error(err.message || String(err));
  }
  process.exitCode = 2;
});
