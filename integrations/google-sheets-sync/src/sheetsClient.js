'use strict';

const { google } = require('googleapis');

const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

class SheetsError extends Error {}

/**
 * Quotes a sheet name for use in an A1-notation range, per Google's rules:
 * wrap in single quotes and double any embedded single quotes. Always safe
 * to apply (Google accepts a quoted name even when quoting isn't strictly
 * required), and necessary for names containing spaces — e.g. a tab named
 * "Systems Inventory" must be sent as 'Systems Inventory', not the bare
 * name, or the API can mis-parse the range.
 */
function quoteSheetName(name) {
  return `'${String(name).replace(/'/g, "''")}'`;
}

/**
 * Reads all values from the configured sheet. Read-only scope only — this
 * connector has no code path that can write to the spreadsheet.
 *
 * @param {{spreadsheetId: string, sheetName: string, credentialsPath: string}} opts
 * @returns {Promise<Array<Array<string>>>}
 */
async function fetchSheetValues({ spreadsheetId, sheetName, credentialsPath }) {
  if (!spreadsheetId) throw new SheetsError('GOOGLE_SPREADSHEET_ID is required.');
  if (!sheetName) throw new SheetsError('GOOGLE_SHEET_NAME is required.');
  if (!credentialsPath) throw new SheetsError('GOOGLE_APPLICATION_CREDENTIALS is required.');

  const auth = new google.auth.GoogleAuth({
    keyFile: credentialsPath,
    scopes: [READONLY_SCOPE],
  });

  const sheets = google.sheets({ version: 'v4', auth });

  let res;
  try {
    res = await sheets.spreadsheets.values.get({
      spreadsheetId,
      range: quoteSheetName(sheetName),
      valueRenderOption: 'UNFORMATTED_VALUE',
      dateTimeRenderOption: 'FORMATTED_STRING',
    });
  } catch (err) {
    const status = err.code || err.response?.status;
    throw new SheetsError(`Could not read Google Sheet "${sheetName}" (${status || err.message}).`);
  }

  return res.data.values || [];
}

module.exports = { fetchSheetValues, SheetsError, READONLY_SCOPE, quoteSheetName };
