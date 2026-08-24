'use strict';

/**
 * Minimal Snipe-IT v1 API client using Node's built-in fetch.
 *
 * SAFETY: this module exposes exactly one write method — createAsset(),
 * which issues a single POST /api/v1/hardware (asset create). There is no
 * update or delete method defined anywhere in this file, for any resource.
 * That's a structural safeguard, not just a runtime flag: even if every
 * runtime safeguard in src/applyRun.js were somehow bypassed, this client
 * still has no way to modify or remove an existing Snipe-IT record — only
 * to read (me/find/list/findAssetByTag) or create a brand-new asset.
 */

class SnipeItError extends Error {}

function createClient({ baseUrl, apiToken, fetchImpl = fetch }) {
  if (!baseUrl) throw new SnipeItError('SNIPEIT_BASE_URL is required.');
  if (!apiToken) throw new SnipeItError('SNIPEIT_API_TOKEN is required.');

  const root = `${baseUrl.replace(/\/+$/, '')}/api/v1`;

  async function get(path) {
    let res;
    try {
      res = await fetchImpl(root + path, {
        method: 'GET',
        headers: {
          Authorization: `Bearer ${apiToken}`,
          Accept: 'application/json',
        },
      });
    } catch (err) {
      throw new SnipeItError(`Could not reach Snipe-IT at ${baseUrl} (${err.code || err.message}).`);
    }

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      // non-JSON body; json stays null and status check below still applies
    }

    if (res.status === 401 || res.status === 403) {
      throw new SnipeItError(`Snipe-IT rejected the API token (HTTP ${res.status}) for GET ${path}.`);
    }
    if (!res.ok) {
      throw new SnipeItError(`Snipe-IT API GET ${path} failed with HTTP ${res.status}.`);
    }
    return json;
  }

  /**
   * POST helper. Unlike get(), a non-2xx HTTP status with a parseable JSON
   * body is NOT thrown here — Snipe-IT's own validation-failure responses
   * for /hardware can come back as either HTTP 200 or a 4xx with
   * {status:"error", messages:{...}}, and the caller (createAsset) needs
   * that body to report which field failed. Only a genuinely unreadable
   * failure (network error, auth rejection, or a non-JSON error body) is
   * thrown from here.
   */
  async function post(path, body) {
    let res;
    try {
      res = await fetchImpl(root + path, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${apiToken}`,
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
      });
    } catch (err) {
      throw new SnipeItError(`Could not reach Snipe-IT at ${baseUrl} (${err.code || err.message}).`);
    }

    let json = null;
    try {
      json = await res.json();
    } catch (_) {
      // non-JSON body; json stays null
    }

    if (res.status === 401 || res.status === 403) {
      throw new SnipeItError(`Snipe-IT rejected the API token (HTTP ${res.status}) for POST ${path}.`);
    }
    if (!res.ok && json === null) {
      throw new SnipeItError(`Snipe-IT API POST ${path} failed with HTTP ${res.status} and no readable body.`);
    }
    return json;
  }

  return {
    /** GET /users/me — cheap way to verify the token works at all. */
    me: () => get('/users/me'),

    /** GET /{resource}?name=<exact> — used for models/companies/locations/statuslabels/categories. */
    async findByExactName(resource, name) {
      const json = await get(`/${resource}?name=${encodeURIComponent(name)}&limit=5`);
      return (json && json.rows) || [];
    },

    /** GET /fields — all custom fields, including db_column_name. */
    async listCustomFields() {
      const json = await get('/fields?limit=500');
      return (json && json.rows) || [];
    },

    /**
     * GET /hardware/bytag/{tag} — Snipe-IT returns HTTP 200 with
     * {status:"error", ...} when nothing matches (not a 404), and the
     * asset object directly when exactly one match is found.
     */
    async findAssetByTag(tag) {
      const json = await get(`/hardware/bytag/${encodeURIComponent(tag)}`);
      if (!json || json.status === 'error') return null;
      return json;
    },

    /**
     * POST /hardware — creates exactly one new asset. This is the ONLY
     * write method this client exposes; there is no updateAsset or
     * deleteAsset anywhere in this module. Returns the raw Snipe-IT
     * response envelope ({status:'success', payload:{...}} or
     * {status:'error', messages:{...}}) so the caller can report exactly
     * what happened without this client guessing at field-level meaning.
     */
    async createAsset(payload) {
      const json = await post('/hardware', payload);
      return json;
    },
  };
}

module.exports = { createClient, SnipeItError };
