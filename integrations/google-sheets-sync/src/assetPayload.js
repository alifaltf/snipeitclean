'use strict';

const { SHEET_HEADER_TO_CUSTOM_FIELD } = require('./customFieldMapping');

/**
 * Builds the exact POST /api/v1/hardware body for one row. This is the
 * single place in the whole connector where a create payload is assembled
 * — src/applyRun.js calls this and sends the result to
 * snipeitClient.createAsset() unmodified.
 *
 * SAFETY (structural, not just by convention):
 *   - The only source of custom-field values is `record`, keyed by sheet
 *     header, walked through SHEET_HEADER_TO_CUSTOM_FIELD (13 entries).
 *     That table throws at require-time if "Password" is ever added to
 *     either side of it (see customFieldMapping.js), and this function
 *     never reads any `record` key outside that table plus the small,
 *     explicitly-named set of core fields below. There is no code path
 *     by which a "Password" value could reach this payload.
 *   - Every custom-field key in the returned payload uses the field's
 *     real `db_column_name`, resolved from Snipe-IT at runtime
 *     (dbColumnByFieldName) — never guessed or hard-coded.
 *
 * @param {{
 *   record: Object<string,string>,
 *   assetTag: string,
 *   systemName: string,
 *   notes: string,
 *   modelId: number,
 *   statusId: number,
 *   companyId: number,
 *   locationId: number,
 *   dbColumnByFieldName: Object<string,string|null>,
 * }} args
 * @returns {Object} the POST /hardware request body
 */
function buildAssetPayload({
  record,
  assetTag,
  systemName,
  notes,
  modelId,
  statusId,
  companyId,
  locationId,
  dbColumnByFieldName,
}) {
  const payload = {
    asset_tag: assetTag,
    name: systemName,
    model_id: modelId,
    status_id: statusId,
    company_id: companyId,
    // Snipe-IT's "default/ready-to-deploy" location field for a newly
    // created asset — NOT location_id, which is the current/checked-out
    // location and doesn't apply to a brand-new, never-checked-out asset.
    rtd_location_id: locationId,
    notes: notes || '',
  };

  const missingDbColumns = [];
  for (const [sheetHeader, customFieldName] of Object.entries(SHEET_HEADER_TO_CUSTOM_FIELD)) {
    const dbColumn = dbColumnByFieldName ? dbColumnByFieldName[customFieldName] : null;
    if (!dbColumn) {
      missingDbColumns.push(customFieldName);
      continue;
    }
    const rawValue = record ? record[sheetHeader] : undefined;
    payload[dbColumn] = rawValue !== undefined ? String(rawValue).trim() : '';
  }

  if (missingDbColumns.length) {
    throw new Error(
      `Cannot build asset payload — db_column_name unresolved for custom field(s): ${missingDbColumns.join(', ')}.`
    );
  }

  return payload;
}

module.exports = { buildAssetPayload };
