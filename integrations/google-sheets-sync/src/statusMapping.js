'use strict';

/**
 * Sheet "Status" value -> Snipe-IT status label name.
 * Phase 1 mapping is 1:1 by design, but is still validated against an
 * allow-list so unexpected values fail loudly instead of silently.
 */

const STATUS_MAP = Object.freeze({
  active: 'Active',
  'under maintenance': 'Under Maintenance',
  inactive: 'Inactive',
  pending: 'Pending',
});

/**
 * @param {string} sheetStatus
 * @returns {{valid: true, label: string} | {valid: false, reason: string}}
 */
function resolveStatus(sheetStatus) {
  const trimmed = String(sheetStatus ?? '').trim();
  const label = STATUS_MAP[trimmed.toLowerCase()];

  if (!label) {
    return {
      valid: false,
      reason: `Unrecognized status "${trimmed || '(blank)'}" — expected one of: Active, Under Maintenance, Inactive, Pending.`,
    };
  }

  return { valid: true, label };
}

module.exports = { STATUS_MAP, resolveStatus };
