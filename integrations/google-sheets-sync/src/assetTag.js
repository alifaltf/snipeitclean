'use strict';

/**
 * Asset-tag formatting: "No." column -> Snipe-IT asset_tag.
 * Example: No. 2 -> "SYS-0002".
 *
 * Kept dependency-free and pure so it can be unit tested without any
 * network access or Snipe-IT/Google credentials.
 */

const ASSET_TAG_PREFIX = 'SYS-';
const ASSET_TAG_DIGITS = 4;
const ASSET_TAG_MAX = 10 ** ASSET_TAG_DIGITS - 1;

class AssetTagError extends Error {}

/**
 * @param {string|number} no - raw value of the sheet's "No." column
 * @returns {string} formatted asset tag, e.g. "SYS-0002"
 */
function formatAssetTag(no) {
  const trimmed = String(no ?? '').trim();

  if (trimmed === '') {
    throw new AssetTagError('"No." value is blank — cannot derive an asset tag.');
  }

  if (!/^\d+$/.test(trimmed)) {
    throw new AssetTagError(`"No." value ${JSON.stringify(trimmed)} is not a positive whole number.`);
  }

  const num = Number.parseInt(trimmed, 10);

  if (num <= 0) {
    throw new AssetTagError(`"No." value ${num} must be greater than zero.`);
  }

  if (num > ASSET_TAG_MAX) {
    throw new AssetTagError(
      `"No." value ${num} exceeds the ${ASSET_TAG_DIGITS}-digit asset-tag capacity (max ${ASSET_TAG_MAX}).`
    );
  }

  return ASSET_TAG_PREFIX + String(num).padStart(ASSET_TAG_DIGITS, '0');
}

module.exports = {
  formatAssetTag,
  AssetTagError,
  ASSET_TAG_PREFIX,
  ASSET_TAG_DIGITS,
};
