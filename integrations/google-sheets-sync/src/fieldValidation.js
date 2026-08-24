'use strict';

/**
 * Pure, dependency-free validation for the sheet fields that feed
 * Snipe-IT's date and numeric custom fields. No I/O, no Snipe-IT/Sheets
 * access — safe to unit test directly.
 *
 * A row that fails any of these checks is reported as invalid and is
 * never sent to Snipe-IT (see src/applyRun.js).
 */

// Sheet headers (== the keys used in customFieldMapping.js's
// SHEET_HEADER_TO_CUSTOM_FIELD table) that must parse as a date.
const DATE_FIELDS = Object.freeze(['Creation / Delivery Date', 'License Renewal Date']);

// Sheet headers that must parse as a number, with the constraints below.
const NUMERIC_FIELD_RULES = Object.freeze({
  Port: { integer: true, min: 1, max: 65535 },
  'License Cost': { integer: false, min: 0 },
});

const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const SLASH_DATE_RE = /^\d{1,2}\/\d{1,2}\/\d{4}$/;

/**
 * A field left blank is treated as "not provided" and is NOT an error —
 * Phase 2 only rejects a row for a value that is present but malformed.
 *
 * @param {string} value - raw cell value
 * @param {string} fieldLabel - used only in the returned reason text
 * @returns {{valid: true} | {valid: false, reason: string}}
 */
function validateDateField(value, fieldLabel) {
  const trimmed = String(value ?? '').trim();
  if (trimmed === '') return { valid: true };

  let isoCandidate = null;
  if (ISO_DATE_RE.test(trimmed)) {
    isoCandidate = trimmed;
  } else if (SLASH_DATE_RE.test(trimmed)) {
    // Sheet dates in slash form are month/day/year (US convention). Build
    // an unambiguous ISO string ourselves rather than handing the
    // original "M/D/YYYY" text to Date.parse, whose behavior for
    // non-ISO formats is implementation-defined.
    const [month, day, year] = trimmed.split('/');
    isoCandidate = `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
  }

  if (isoCandidate && ISO_DATE_RE.test(isoCandidate) && !Number.isNaN(Date.parse(isoCandidate))) {
    return { valid: true };
  }

  return {
    valid: false,
    reason: `"${fieldLabel}" value "${trimmed}" is not a recognizable date (expected YYYY-MM-DD or M/D/YYYY).`,
  };
}

/**
 * @param {string} value - raw cell value
 * @param {string} fieldLabel - used only in the returned reason text
 * @param {{integer?: boolean, min?: number, max?: number}} rules
 * @returns {{valid: true} | {valid: false, reason: string}}
 */
function validateNumericField(value, fieldLabel, rules = {}) {
  const trimmed = String(value ?? '').trim();
  if (trimmed === '') return { valid: true };

  const num = Number(trimmed);
  if (!Number.isFinite(num)) {
    return { valid: false, reason: `"${fieldLabel}" value "${trimmed}" is not a valid number.` };
  }
  if (rules.integer && !Number.isInteger(num)) {
    return { valid: false, reason: `"${fieldLabel}" value "${trimmed}" must be a whole number.` };
  }
  if (rules.min !== undefined && num < rules.min) {
    return { valid: false, reason: `"${fieldLabel}" value ${num} is below the minimum of ${rules.min}.` };
  }
  if (rules.max !== undefined && num > rules.max) {
    return { valid: false, reason: `"${fieldLabel}" value ${num} exceeds the maximum of ${rules.max}.` };
  }
  return { valid: true };
}

/**
 * Validates every date/numeric field on a mapped row record. Returns
 * every problem found (not just the first) so a single report row can
 * explain everything wrong with it.
 *
 * @param {Object<string,string>} record - output of headerMapping.rowToRecord
 * @returns {{valid: boolean, errors: string[]}}
 */
function validateRowFields(record) {
  const errors = [];

  for (const field of DATE_FIELDS) {
    const result = validateDateField(record[field], field);
    if (!result.valid) errors.push(result.reason);
  }

  for (const [field, rules] of Object.entries(NUMERIC_FIELD_RULES)) {
    const result = validateNumericField(record[field], field, rules);
    if (!result.valid) errors.push(result.reason);
  }

  return { valid: errors.length === 0, errors };
}

module.exports = {
  DATE_FIELDS,
  NUMERIC_FIELD_RULES,
  validateDateField,
  validateNumericField,
  validateRowFields,
};
