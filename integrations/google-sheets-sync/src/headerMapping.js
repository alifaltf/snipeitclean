'use strict';

/**
 * Header detection + mapping for the Google Sheet.
 *
 * Sheet layout (per spec):
 *   Row 1: may contain a warning banner (ignored)
 *   Row 2: real headers (but we don't hard-code the row number — we
 *          detect it by finding a row that contains both "Status" and
 *          "System Name", scanning the first few rows).
 *
 * The "Password" column is intentionally never surfaced past this module:
 * rowToRecord() drops it before the rest of the connector ever sees a row,
 * so no downstream code can accidentally log, store, or map it.
 */

const REQUIRED_HEADERS = [
  'No.',
  'Status',
  'System Name',
  'Platform Type',
  'Creation / Delivery Date',
  'License Renewal Date',
  'License Renewal',
  'License Cost',
  'Database Instance',
  'Database Category',
  'Database Location',
  'Port',
  'Domain',
  'Username',
  'Password',
  'Stakeholder Name / Department',
  'Developer / Vendor',
  'Remark / Description',
];

// Headers that must NEVER end up in a mapped record, regardless of what
// else changes in this file. rowToRecord() enforces this directly.
const EXCLUDED_HEADERS = new Set(['Password']);

function normalize(value) {
  return String(value ?? '').trim().toLowerCase();
}

/**
 * Scan the first `maxRowsToScan` rows for the one containing both
 * "Status" and "System Name" (case-insensitive, trimmed). That row is
 * treated as the header row; everything above it (e.g. a warning banner
 * in row 1) is ignored.
 *
 * @param {Array<Array<string>>} rows
 * @param {number} maxRowsToScan
 * @returns {number} index of the header row, or -1 if not found
 */
function findHeaderRowIndex(rows, maxRowsToScan = 5) {
  const limit = Math.min(rows.length, maxRowsToScan);
  for (let i = 0; i < limit; i++) {
    const cells = (rows[i] || []).map(normalize);
    if (cells.includes('status') && cells.includes('system name')) {
      return i;
    }
  }
  return -1;
}

/**
 * @param {Array<string>} headerRow
 * @returns {Object<string, number>} header name -> column index
 */
function buildHeaderMap(headerRow) {
  const map = {};
  (headerRow || []).forEach((cell, idx) => {
    const name = String(cell ?? '').trim();
    if (name && !(name in map)) {
      map[name] = idx;
    }
  });
  return map;
}

/**
 * @param {Object<string, number>} headerMap
 * @returns {{valid: boolean, missing: string[]}}
 */
function validateHeaders(headerMap) {
  const missing = REQUIRED_HEADERS.filter((h) => !(h in headerMap));
  return { valid: missing.length === 0, missing };
}

/**
 * Converts one raw sheet row into a { headerName: value } record.
 * Any header in EXCLUDED_HEADERS (currently just "Password") is skipped
 * entirely — its value is never read into the resulting object.
 *
 * @param {Array<string>} rowValues
 * @param {Object<string, number>} headerMap
 */
function rowToRecord(rowValues, headerMap) {
  const record = {};
  for (const [header, idx] of Object.entries(headerMap)) {
    if (EXCLUDED_HEADERS.has(header)) continue;
    record[header] = rowValues[idx] !== undefined ? rowValues[idx] : '';
  }
  return record;
}

function isBlankRow(rowValues) {
  return !rowValues || rowValues.every((c) => String(c ?? '').trim() === '');
}

module.exports = {
  REQUIRED_HEADERS,
  EXCLUDED_HEADERS,
  normalize,
  findHeaderRowIndex,
  buildHeaderMap,
  validateHeaders,
  rowToRecord,
  isBlankRow,
};
