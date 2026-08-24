'use strict';

/**
 * "Stakeholder Name / Department" -> company + location eligibility.
 *
 * Only rows whose department text contains "IT Department" or
 * "HR Department" (case-insensitive) are eligible for sync. Everything
 * else is skipped with a clear (but sanitized/length-capped) reason.
 */

const DEPARTMENT_CONFIG = Object.freeze({
  'IT Department': Object.freeze({ company: 'IT Department', location: 'IT Office' }),
  'HR Department': Object.freeze({ company: 'HR Department', location: 'HR Office' }),
});

const ELIGIBLE_DEPARTMENTS = Object.freeze(Object.keys(DEPARTMENT_CONFIG));

function sanitizeForReport(text, maxLen = 80) {
  const s = String(text ?? '').replace(/[\r\n\t]+/g, ' ').trim();
  return s.length > maxLen ? `${s.slice(0, maxLen)}…` : s;
}

/**
 * @param {string} stakeholderText - raw "Stakeholder Name / Department" cell
 * @returns {{eligible: true, department: string, company: string, location: string}
 *         | {eligible: false, reason: string}}
 */
function resolveDepartment(stakeholderText) {
  const displayValue = sanitizeForReport(stakeholderText);
  const lower = displayValue.toLowerCase();

  const matchKey = ELIGIBLE_DEPARTMENTS.find((key) => lower.includes(key.toLowerCase()));

  if (!matchKey) {
    return {
      eligible: false,
      reason: `Skipped — department "${displayValue || '(blank)'}" is not IT Department or HR Department.`,
    };
  }

  const { company, location } = DEPARTMENT_CONFIG[matchKey];
  return { eligible: true, department: matchKey, company, location };
}

module.exports = { DEPARTMENT_CONFIG, ELIGIBLE_DEPARTMENTS, resolveDepartment, sanitizeForReport };
