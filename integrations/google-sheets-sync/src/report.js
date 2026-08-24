'use strict';

function sanitizeCell(value, maxLen = 100) {
  const s = String(value ?? '').replace(/[\r\n\t]+/g, ' ').trim();
  return s.length > maxLen ? `${s.slice(0, maxLen)}…` : s;
}

/**
 * Builds one sanitized report row. Only the columns the spec asks for are
 * included: row number, asset tag, system name, status, company,
 * location, result. Nothing username/password/token-shaped ever goes in.
 */
function buildReportRow({ rowNumber, assetTag, systemName, status, company, location, result }) {
  return {
    row: rowNumber,
    assetTag: sanitizeCell(assetTag, 20),
    systemName: sanitizeCell(systemName, 60),
    status: sanitizeCell(status, 30),
    company: sanitizeCell(company, 40),
    location: sanitizeCell(location, 40),
    // Wide enough to hold the base outcome text plus the IT-fallback note
    // (which itself echoes an 80-char-capped original department value)
    // without truncating away the closing context.
    result: sanitizeCell(result, 220),
  };
}

function padRight(str, len) {
  const s = String(str ?? '');
  return s.length >= len ? s : s + ' '.repeat(len - s.length);
}

const REPORT_COLUMNS = [
  ['row', 'Row'],
  ['assetTag', 'Asset Tag'],
  ['systemName', 'System Name'],
  ['status', 'Status'],
  ['company', 'Company'],
  ['location', 'Location'],
  ['result', 'Result'],
];

function printTable(rows, banner, log) {
  const widths = REPORT_COLUMNS.map(([key, label]) =>
    Math.max(label.length, ...rows.map((r) => String(r[key] ?? '').length), 4)
  );

  log('');
  log(banner);
  log(REPORT_COLUMNS.map(([, label], i) => padRight(label, widths[i])).join('  '));
  log(widths.map((w) => '-'.repeat(w)).join('  '));
  for (const row of rows) {
    log(REPORT_COLUMNS.map(([key], i) => padRight(row[key], widths[i])).join('  '));
  }
}

function printReport(rows, summary, log = console.log) {
  printTable(rows, '=== Dry-run report (no Snipe-IT writes were made) ===', log);

  log('');
  log('=== Summary ===');
  log(`Rows read:     ${summary.rowsRead}`);
  log(`Eligible:      ${summary.eligible} (would-create: ${summary.wouldCreate}, existing/duplicate: ${summary.existing})`);
  log(`Skipped:       ${summary.skipped} (ineligible department)`);
  log(`Errors:        ${summary.errors}`);
  log('');
}

/**
 * Prints the apply/create-only report. Same sanitized-row table as
 * printReport, but with a summary shape that matches what apply actually
 * tracks (selected tags, duplicates skipped, rows actually created, ...)
 * instead of the dry-run "would-create" language.
 */
function printApplyReport(rows, summary, log = console.log) {
  printTable(rows, '=== APPLY report — writes were attempted for selected tags only ===', log);

  log('');
  log('=== Summary ===');
  log(`Selected tags:      ${summary.selected}`);
  log(`Found in sheet:     ${summary.foundInSheet} (not found: ${summary.notFoundInSheet})`);
  log(`Eligible:           ${summary.eligible}`);
  log(`Created:            ${summary.created}`);
  log(`Skipped (duplicate):${' '.repeat(1)}${summary.skippedDuplicate}`);
  log(`Skipped (dept):     ${summary.skippedIneligible}`);
  log(`Invalid:            ${summary.invalid}`);
  log(`Errors:             ${summary.errors}`);
  if (summary.fallbackUsed) {
    log(`IT fallback used:   ${summary.fallbackUsed} row(s) — see "[IT FALLBACK APPLIED ...]" in the Result column above.`);
  } else {
    log(`IT fallback used:   0`);
  }
  log('');
}

module.exports = { buildReportRow, printReport, printApplyReport, sanitizeCell };
