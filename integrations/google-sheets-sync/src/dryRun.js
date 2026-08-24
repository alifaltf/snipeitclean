'use strict';

const { formatAssetTag, AssetTagError } = require('./assetTag');
const {
  findHeaderRowIndex,
  buildHeaderMap,
  validateHeaders,
  rowToRecord,
  isBlankRow,
} = require('./headerMapping');
const { resolveDepartment, ELIGIBLE_DEPARTMENTS, DEPARTMENT_CONFIG } = require('./departmentMapping');
const { resolveStatus, STATUS_MAP } = require('./statusMapping');
const { CUSTOM_FIELD_NAMES } = require('./customFieldMapping');
const { buildReportRow } = require('./report');

class RowError extends Error {}

const ASSET_MODEL_NAME = 'Digital System';
const ASSET_CATEGORY_NAME = 'Digital Systems';

/**
 * Resolves the fixed Snipe-IT reference data this connector depends on:
 * the asset model, the two eligible companies, their two locations, the
 * four status labels, and the db_column for each custom field we care
 * about. Called once per run, before the per-row loop.
 */
async function resolveReferenceData(snipeitClient, logger) {
  const problems = [];

  const models = await snipeitClient.findByExactName('models', ASSET_MODEL_NAME);
  const model = models[0] || null;
  if (!model) {
    problems.push(`Asset model "${ASSET_MODEL_NAME}" was not found in Snipe-IT.`);
  } else if (model.category && model.category.name && model.category.name !== ASSET_CATEGORY_NAME) {
    logger.warn(
      `Model "${ASSET_MODEL_NAME}" has category "${model.category.name}", expected "${ASSET_CATEGORY_NAME}".`
    );
  }

  const companies = {};
  for (const dept of ELIGIBLE_DEPARTMENTS) {
    const name = DEPARTMENT_CONFIG[dept].company;
    const rows = await snipeitClient.findByExactName('companies', name);
    companies[dept] = rows[0] || null;
    if (!companies[dept]) problems.push(`Company "${name}" was not found in Snipe-IT.`);
  }

  const locations = {};
  for (const dept of ELIGIBLE_DEPARTMENTS) {
    const name = DEPARTMENT_CONFIG[dept].location;
    const rows = await snipeitClient.findByExactName('locations', name);
    locations[dept] = rows[0] || null;
    if (!locations[dept]) problems.push(`Location "${name}" was not found in Snipe-IT.`);
  }

  const statuses = {};
  for (const label of Object.values(STATUS_MAP)) {
    const rows = await snipeitClient.findByExactName('statuslabels', label);
    statuses[label] = rows[0] || null;
    if (!statuses[label]) problems.push(`Status label "${label}" was not found in Snipe-IT.`);
  }

  const customFields = await snipeitClient.listCustomFields();
  const dbColumnByFieldName = {};
  for (const name of CUSTOM_FIELD_NAMES) {
    const field = customFields.find((f) => f.name === name);
    dbColumnByFieldName[name] = field ? field.db_column_name : null;
    if (!field) problems.push(`Custom field "${name}" was not found in Snipe-IT.`);
  }

  return { model, companies, locations, statuses, dbColumnByFieldName, problems };
}

/**
 * Runs the full dry-run: verify access, read + validate the sheet,
 * resolve reference data, then evaluate every row. Never calls a
 * create/update/delete Snipe-IT endpoint (snipeitClient doesn't expose
 * one) and never writes to the sheet (sheetsClient is read-only scoped).
 */
async function runDryRun({ config, sheetsClient, snipeitClient, logger }) {
  logger.info(`Verifying Snipe-IT access at ${config.snipeitBaseUrl} ...`);
  const me = await snipeitClient.me();
  logger.info(`Snipe-IT auth OK (authenticated user id: ${me && me.id !== undefined ? me.id : 'unknown'}).`);

  logger.info(`Verifying Google Sheets access (sheet "${config.sheetName}") ...`);
  const rows = await sheetsClient.fetchSheetValues();
  logger.info(`Sheet read OK — ${rows.length} raw row(s) retrieved.`);

  const headerRowIdx = findHeaderRowIndex(rows);
  if (headerRowIdx === -1) {
    throw new RowError('Could not detect the header row (expected a row containing both "Status" and "System Name").');
  }
  const headerMap = buildHeaderMap(rows[headerRowIdx]);
  const headerCheck = validateHeaders(headerMap);
  if (!headerCheck.valid) {
    throw new RowError(`Sheet is missing required header(s): ${headerCheck.missing.join(', ')}`);
  }
  logger.info(`Header row detected at sheet row ${headerRowIdx + 1}.`);

  logger.info('Resolving model, statuses, companies, locations, and custom fields from Snipe-IT ...');
  const ref = await resolveReferenceData(snipeitClient, logger);
  if (ref.problems.length) {
    for (const p of ref.problems) logger.warn(p);
  }

  const dataRows = rows.slice(headerRowIdx + 1);
  const results = [];
  const summary = { rowsRead: 0, eligible: 0, wouldCreate: 0, existing: 0, skipped: 0, errors: 0 };

  for (let i = 0; i < dataRows.length; i++) {
    const sheetRowNumber = headerRowIdx + 2 + i; // 1-based row number in the actual sheet
    const rawRow = dataRows[i];
    if (isBlankRow(rawRow)) continue; // not counted as "read" — nothing to evaluate

    summary.rowsRead++;
    const record = rowToRecord(rawRow, headerMap); // Password is never present here

    const systemNamePreview = record['System Name'];

    try {
      const assetTag = formatAssetTag(record['No.']);
      const systemName = String(record['System Name'] ?? '').trim();
      if (!systemName) throw new RowError('Missing "System Name".');

      const statusResult = resolveStatus(record['Status']);
      if (!statusResult.valid) throw new RowError(statusResult.reason);

      const dept = resolveDepartment(record['Stakeholder Name / Department']);
      if (!dept.eligible) {
        results.push(
          buildReportRow({
            rowNumber: sheetRowNumber,
            assetTag,
            systemName,
            status: statusResult.label,
            company: '',
            location: '',
            result: dept.reason,
          })
        );
        summary.skipped++;
        continue;
      }

      const company = ref.companies[dept.department];
      const location = ref.locations[dept.department];
      if (!company || !location || !ref.model || !ref.statuses[statusResult.label]) {
        throw new RowError(
          'Cannot evaluate row — required Snipe-IT reference data (model/company/location/status) is missing. See warnings above.'
        );
      }

      summary.eligible++;

      const existing = await snipeitClient.findAssetByTag(assetTag);
      if (existing) {
        results.push(
          buildReportRow({
            rowNumber: sheetRowNumber,
            assetTag,
            systemName,
            status: statusResult.label,
            company: company.name,
            location: location.name,
            result: `Skipped — asset tag already exists in Snipe-IT (id ${existing.id}).`,
          })
        );
        summary.existing++;
        continue;
      }

      summary.wouldCreate++;
      results.push(
        buildReportRow({
          rowNumber: sheetRowNumber,
          assetTag,
          systemName,
          status: statusResult.label,
          company: company.name,
          location: location.name,
          result: 'Would create (dry-run only — no write performed).',
        })
      );
    } catch (err) {
      summary.errors++;
      results.push(
        buildReportRow({
          rowNumber: sheetRowNumber,
          assetTag: err instanceof AssetTagError ? '' : undefined,
          systemName: systemNamePreview,
          status: '',
          company: '',
          location: '',
          result: `Error — ${err.message}`,
        })
      );
    }
  }

  return { results, summary, reference: ref };
}

module.exports = { runDryRun, resolveReferenceData, RowError, ASSET_MODEL_NAME, ASSET_CATEGORY_NAME };
