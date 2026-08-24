'use strict';

const { formatAssetTag, AssetTagError } = require('./assetTag');
const {
  findHeaderRowIndex,
  buildHeaderMap,
  validateHeaders,
  rowToRecord,
  isBlankRow,
} = require('./headerMapping');
const { resolveDepartment, sanitizeForReport, DEPARTMENT_CONFIG } = require('./departmentMapping');
const { resolveStatus } = require('./statusMapping');
const { validateRowFields } = require('./fieldValidation');
const { buildAssetPayload } = require('./assetPayload');
const { resolveReferenceData, RowError } = require('./dryRun');
const { buildReportRow } = require('./report');

class SafeguardError extends Error {}

// Exact confirmation string an operator must pass via --confirm. Chosen to
// be unmistakably about creating non-production/dummy assets so it can't
// be pasted in by accident.
const REQUIRED_CONFIRMATION = 'CREATE_DUMMY_ASSETS';

// The department key used when --fallback-to-it resolves an unrecognized
// department. Sourced from DEPARTMENT_CONFIG (not a separate hard-coded
// company/location pair) so it can never drift from the IT Department ->
// IT Office mapping already resolved by resolveReferenceData() — no new
// Snipe-IT company or location is ever looked up or created for fallback.
const FALLBACK_DEPARTMENT_KEY = 'IT Department';
if (!DEPARTMENT_CONFIG[FALLBACK_DEPARTMENT_KEY]) {
  throw new Error(`Safety violation: fallback department key "${FALLBACK_DEPARTMENT_KEY}" is not in DEPARTMENT_CONFIG.`);
}

/**
 * All three safeguards required by the spec, checked together, BEFORE any
 * network call is made (including snipeitClient.me()). If any is missing,
 * this throws and runApplyCreate() never reaches the Snipe-IT/Sheets I/O
 * below it — so "exit without writing" holds even if a future caller
 * skips the CLI-level pre-check in bin/apply-create.js.
 *
 * @param {{config: object, selectedTags: string[], confirmation: string}} args
 */
function assertSafeguards({ config, selectedTags, confirmation }) {
  const problems = [];

  if (!config || config.syncMode !== 'apply') {
    problems.push(`SYNC_MODE must be "apply" (found "${config ? config.syncMode : undefined}").`);
  }
  if (!Array.isArray(selectedTags) || selectedTags.length === 0) {
    problems.push('At least one asset tag must be explicitly selected (--tags SYS-0002,SYS-0003).');
  }
  if (confirmation !== REQUIRED_CONFIRMATION) {
    problems.push(`Confirmation argument must be exactly "${REQUIRED_CONFIRMATION}" (--confirm ${REQUIRED_CONFIRMATION}).`);
  }

  if (problems.length) {
    throw new SafeguardError(`Refusing to run — missing safeguard(s):\n  - ${problems.join('\n  - ')}`);
  }
}

/**
 * Runs the create-only apply flow: verify access, read + validate the
 * sheet, resolve reference data, then — for ONLY the explicitly selected
 * asset tags — validate, re-check for an existing duplicate immediately
 * before creating, and create sequentially.
 *
 * Rows whose asset tag is not in `selectedTags` are skipped before any
 * other evaluation (department, status, validation, duplicate-check) ever
 * runs on them — they are never eligible, structurally, regardless of
 * their department or data.
 *
 * By default, a selected row whose department doesn't resolve to IT
 * Department or HR Department is skipped (unchanged Phase 2 behavior).
 * When `fallbackToIt` is true, such a row instead uses the already-resolved
 * IT Department company / IT Office location (no new company/location is
 * ever looked up or created) — but its custom-field payload still carries
 * the row's ORIGINAL "Stakeholder Name / Department" text, never
 * overwritten with "IT Department". Every report row this applies to says
 * so explicitly, and summary.fallbackUsed counts them.
 *
 * Never calls an update or delete endpoint — snipeitClient exposes none.
 *
 * @param {{
 *   config: object,
 *   sheetsClient: {fetchSheetValues: Function},
 *   snipeitClient: object,
 *   logger: object,
 *   selectedTags: string[],
 *   confirmation: string,
 *   fallbackToIt?: boolean,
 * }} args
 */
async function runApplyCreate({
  config,
  sheetsClient,
  snipeitClient,
  logger,
  selectedTags,
  confirmation,
  fallbackToIt = false,
}) {
  assertSafeguards({ config, selectedTags, confirmation });

  const wanted = new Set(selectedTags);
  const seen = new Set();

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
  const summary = {
    selected: wanted.size,
    foundInSheet: 0,
    eligible: 0,
    created: 0,
    skippedDuplicate: 0,
    skippedIneligible: 0,
    invalid: 0,
    notFoundInSheet: 0,
    errors: 0,
    fallbackUsed: 0,
  };

  for (let i = 0; i < dataRows.length; i++) {
    const sheetRowNumber = headerRowIdx + 2 + i;
    const rawRow = dataRows[i];
    if (isBlankRow(rawRow)) continue;

    const record = rowToRecord(rawRow, headerMap); // Password is never present here

    let assetTag;
    try {
      assetTag = formatAssetTag(record['No.']);
    } catch (err) {
      // Can't identify this row's tag at all — it can never match an
      // explicitly-selected tag, so it's simply not in scope for apply.
      continue;
    }

    // Structural gate: everything below this line only ever runs for a
    // row whose asset tag was explicitly selected via --tags. No other
    // row is evaluated for department eligibility, validation, or
    // duplicate status.
    if (!wanted.has(assetTag)) continue;

    seen.add(assetTag);
    summary.foundInSheet++;

    const systemName = String(record['System Name'] ?? '').trim();
    const notes = String(record['Remark / Description'] ?? '').trim();

    let usedFallback = false;
    let fallbackNote = '';

    try {
      if (!systemName) throw new RowError('Missing "System Name".');

      const statusResult = resolveStatus(record['Status']);
      if (!statusResult.valid) throw new RowError(statusResult.reason);

      const dept = resolveDepartment(record['Stakeholder Name / Department']);

      // Which DEPARTMENT_CONFIG entry to resolve company/location from.
      // Either the row's own recognized department, or — only when
      // --fallback-to-it was explicitly passed — the fixed IT Department
      // entry. No other department text is ever accepted, and no new
      // company/location is looked up for the fallback case: it reuses
      // the exact same ref.companies['IT Department'] /
      // ref.locations['IT Department'] already resolved above for the
      // normal IT Department path.
      let companyKey;
      if (dept.eligible) {
        companyKey = dept.department;
      } else if (fallbackToIt) {
        companyKey = FALLBACK_DEPARTMENT_KEY;
        usedFallback = true;
        summary.fallbackUsed++;
        fallbackNote = ` [IT FALLBACK APPLIED — original department: "${sanitizeForReport(record['Stakeholder Name / Department']) || '(blank)'}"]`;
      } else {
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
        summary.skippedIneligible++;
        continue;
      }

      const companyConfigName = DEPARTMENT_CONFIG[companyKey].company;
      const locationConfigName = DEPARTMENT_CONFIG[companyKey].location;

      const fieldCheck = validateRowFields(record);
      if (!fieldCheck.valid) {
        results.push(
          buildReportRow({
            rowNumber: sheetRowNumber,
            assetTag,
            systemName,
            status: statusResult.label,
            company: companyConfigName,
            location: locationConfigName,
            result: `Invalid — ${fieldCheck.errors.join(' | ')}${fallbackNote}`,
          })
        );
        summary.invalid++;
        continue;
      }

      const company = ref.companies[companyKey];
      const location = ref.locations[companyKey];
      const statusRow = ref.statuses[statusResult.label];
      if (!company || !location || !ref.model || !statusRow) {
        throw new RowError(
          'Cannot process row — required Snipe-IT reference data (model/company/location/status) is missing. See warnings above.'
        );
      }

      summary.eligible++;

      // Duplicate check happens HERE, immediately before the create call
      // below — not reused from any earlier lookup — so nothing can slip
      // in between the check and the write.
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
            result: `Skipped — asset tag already exists in Snipe-IT (id ${existing.id}). Not overwritten.${fallbackNote}`,
          })
        );
        summary.skippedDuplicate++;
        continue;
      }

      // record still carries the row's ORIGINAL "Stakeholder Name /
      // Department" text — buildAssetPayload reads that custom field
      // straight from `record`, so the fallback path never substitutes
      // "IT Department" into it, fallback or not.
      const payload = buildAssetPayload({
        record,
        assetTag,
        systemName,
        notes,
        modelId: ref.model.id,
        statusId: statusRow.id,
        companyId: company.id,
        locationId: location.id,
        dbColumnByFieldName: ref.dbColumnByFieldName,
      });

      const createResponse = await snipeitClient.createAsset(payload);

      if (createResponse && createResponse.status === 'success') {
        const newId = createResponse.payload && createResponse.payload.id;
        summary.created++;
        results.push(
          buildReportRow({
            rowNumber: sheetRowNumber,
            assetTag,
            systemName,
            status: statusResult.label,
            company: company.name,
            location: location.name,
            result: `Created (Snipe-IT id ${newId !== undefined ? newId : 'unknown'}).${fallbackNote}`,
          })
        );
        logger.info(
          `Created ${assetTag} — "${systemName}" (Snipe-IT id ${newId !== undefined ? newId : 'unknown'}).${
            usedFallback ? ' [IT fallback applied]' : ''
          }`
        );
      } else {
        const messages =
          createResponse && createResponse.messages
            ? JSON.stringify(createResponse.messages)
            : 'Snipe-IT did not return a success response.';
        summary.errors++;
        results.push(
          buildReportRow({
            rowNumber: sheetRowNumber,
            assetTag,
            systemName,
            status: statusResult.label,
            company: company.name,
            location: location.name,
            result: `Error — Snipe-IT rejected the create: ${messages}${fallbackNote}`,
          })
        );
      }
    } catch (err) {
      summary.errors++;
      results.push(
        buildReportRow({
          rowNumber: sheetRowNumber,
          assetTag,
          systemName,
          status: '',
          company: '',
          location: '',
          result: `Error — ${err.message}${fallbackNote}`,
        })
      );
    }
  }

  for (const tag of wanted) {
    if (!seen.has(tag)) {
      summary.notFoundInSheet++;
      results.push(
        buildReportRow({
          rowNumber: '',
          assetTag: tag,
          systemName: '',
          status: '',
          company: '',
          location: '',
          result: 'Not found — this tag was selected via --tags but no matching row exists in the sheet.',
        })
      );
    }
  }

  return { results, summary, reference: ref };
}

module.exports = {
  runApplyCreate,
  assertSafeguards,
  SafeguardError,
  REQUIRED_CONFIRMATION,
};
