'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createClient, SnipeItError } = require('../src/snipeitClient');

function fakeFetch(handler) {
  return async (url, opts) => handler(url, opts);
}

function jsonResponse(status, body) {
  return {
    status,
    ok: status >= 200 && status < 300,
    json: async () => body,
  };
}

test('the client exposes exactly one write method (createAsset) and no update/delete method for any resource', () => {
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'token',
    fetchImpl: fakeFetch(() => jsonResponse(200, { status: 'success' })),
  });

  const methodNames = Object.keys(client);
  assert.deepEqual(
    methodNames.sort(),
    ['createAsset', 'findAssetByTag', 'findByExactName', 'listCustomFields', 'me'].sort()
  );

  for (const name of methodNames) {
    assert.doesNotMatch(name.toLowerCase(), /update|delete|patch|remove|destroy/);
  }

  // Belt-and-suspenders: these methods must not exist under any name.
  assert.equal(client.updateAsset, undefined);
  assert.equal(client.deleteAsset, undefined);
  assert.equal(client.update, undefined);
  assert.equal(client.delete, undefined);
  assert.equal(client.patch, undefined);
});

test('createAsset POSTs to /hardware with the exact payload and returns the response envelope', async () => {
  const calls = [];
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'secret-token-value',
    fetchImpl: fakeFetch((url, opts) => {
      calls.push({ url, opts });
      return jsonResponse(200, { status: 'success', payload: { id: 42, asset_tag: 'SYS-0002' } });
    }),
  });

  const payload = { asset_tag: 'SYS-0002', name: 'Payroll DB', model_id: 1 };
  const result = await client.createAsset(payload);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'https://snipeit.example.test/api/v1/hardware');
  assert.equal(calls[0].opts.method, 'POST');
  assert.equal(calls[0].opts.headers.Authorization, 'Bearer secret-token-value');
  assert.deepEqual(JSON.parse(calls[0].opts.body), payload);

  assert.equal(result.status, 'success');
  assert.equal(result.payload.id, 42);
});

test('createAsset returns the error envelope (does not throw) on a Snipe-IT validation failure', async () => {
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'token',
    fetchImpl: fakeFetch(() =>
      jsonResponse(200, { status: 'error', messages: { asset_tag: ['The asset tag has already been taken.'] } })
    ),
  });

  const result = await client.createAsset({ asset_tag: 'SYS-0002' });
  assert.equal(result.status, 'error');
  assert.ok(result.messages.asset_tag);
});

test('createAsset throws SnipeItError when the token is rejected', async () => {
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'bad-token',
    fetchImpl: fakeFetch(() => jsonResponse(401, { status: 'error', messages: 'Unauthorized' })),
  });

  await assert.rejects(() => client.createAsset({ asset_tag: 'SYS-0002' }), SnipeItError);
});

test('findAssetByTag returns null (not an error) when Snipe-IT reports no match', async () => {
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'token',
    fetchImpl: fakeFetch(() => jsonResponse(200, { status: 'error', messages: 'Asset does not exist.' })),
  });

  const result = await client.findAssetByTag('SYS-9999');
  assert.equal(result, null);
});

test('findAssetByTag returns the asset object when exactly one match is found', async () => {
  const client = createClient({
    baseUrl: 'https://snipeit.example.test',
    apiToken: 'token',
    fetchImpl: fakeFetch(() => jsonResponse(200, { id: 7, asset_tag: 'SYS-0002' })),
  });

  const result = await client.findAssetByTag('SYS-0002');
  assert.equal(result.id, 7);
});
