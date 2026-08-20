import { createServer } from 'node:http';
import { lstatSync, readFileSync } from 'node:fs';
import { timingSafeEqual } from 'node:crypto';

const PORT = 8080;
const MAX_REQUEST_BYTES = 128 * 1024;
const MAX_IMMICH_RESPONSE_BYTES = 2 * 1024 * 1024;
const MAX_ASSETS = 500;
const MAX_MEMBERS = 1000;
const IMMICH_API = 'http://immich-server:2283/api';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const BRIDGE_TOKEN = /^[A-Za-z0-9_-]{32,128}$/;
const IMMICH_TOKEN = /^[A-Za-z0-9._~-]{32,8192}$/;

class BridgeError extends Error {
  constructor(code, status = 503) {
    super(code);
    this.code = code;
    this.status = status;
  }
}

function fail(code, status = 503) {
  throw new BridgeError(code, status);
}

function isExactKeys(value, keys) {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const actual = Object.keys(value).sort();
  return actual.length === keys.length && actual.every((key, index) => key === keys[index]);
}

function loadSecret() {
  const path = process.env.IMMICH_GATEWAY_SECRET_FILE;
  if (typeof path !== 'string' || path === '' || path.length > 512 || path.includes('\u0000')) {
    fail('secret_path_invalid');
  }
  let stat;
  let raw;
  try {
    stat = lstatSync(path);
    if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 100 || stat.size > 16 * 1024 || (stat.mode & 0o022) !== 0) {
      fail('secret_file_invalid');
    }
    raw = readFileSync(path, 'utf8');
  } catch (error) {
    if (error instanceof BridgeError) throw error;
    fail('secret_file_unavailable');
  }

  let secret;
  try {
    secret = JSON.parse(raw);
  } catch {
    fail('secret_json_invalid');
  }
  if (!isExactKeys(secret, ['bridge_token', 'immich_access_token', 'version']) || secret.version !== 1) {
    fail('secret_shape_invalid');
  }
  if (!BRIDGE_TOKEN.test(secret.bridge_token) || !IMMICH_TOKEN.test(secret.immich_access_token)) {
    fail('secret_value_invalid');
  }
  return Object.freeze({ bridgeToken: secret.bridge_token, immichToken: secret.immich_access_token });
}

let secret;
try {
  secret = loadSecret();
} catch (error) {
  const code = error instanceof BridgeError && /^[a-z_]{1,64}$/.test(error.code) ? error.code : 'startup_unavailable';
  // This is deliberately the only startup diagnostic. It contains neither a
  // credential nor a filesystem path and lets the isolated test distinguish
  // a malformed handoff from a generic container exit.
  console.error(`IMMICH_GATEWAY_STARTUP=FAIL code=${code}`);
  process.exit(1);
}

function authorize(request) {
  const value = request.headers.authorization;
  if (typeof value !== 'string' || !value.startsWith('Bearer ')) fail('unauthorized', 403);
  const candidate = Buffer.from(value.slice(7), 'utf8');
  const expected = Buffer.from(secret.bridgeToken, 'utf8');
  if (candidate.length !== expected.length || !timingSafeEqual(candidate, expected)) fail('unauthorized', 403);
}

async function readJson(request) {
  const contentType = String(request.headers['content-type'] ?? '');
  if (!/^application\/json(?:\s*;|$)/i.test(contentType)) fail('content_type_invalid', 400);
  const chunks = [];
  let length = 0;
  for await (const chunk of request) {
    length += chunk.length;
    if (length > MAX_REQUEST_BYTES) fail('request_too_large', 413);
    chunks.push(chunk);
  }
  let value;
  try {
    value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    fail('request_json_invalid', 400);
  }
  if (!isExactKeys(value, ['assets']) || !Array.isArray(value.assets) || value.assets.length < 1 || value.assets.length > MAX_ASSETS) {
    fail('request_shape_invalid', 400);
  }

  const mapping = new Map();
  for (const item of value.assets) {
    if (!isExactKeys(item, ['class_photo_id', 'immich_asset_id'])) fail('asset_shape_invalid', 400);
    const classPhotoId = item.class_photo_id;
    const assetId = item.immich_asset_id;
    if (typeof classPhotoId !== 'string' || typeof assetId !== 'string' || !UUID_V4.test(classPhotoId) || !UUID_V4.test(assetId)) {
      fail('asset_value_invalid', 400);
    }
    if (mapping.has(assetId) || [...mapping.values()].includes(classPhotoId)) fail('asset_duplicate', 400);
    mapping.set(assetId, classPhotoId);
  }
  return mapping;
}

async function immich(path, method = 'GET', body = undefined) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8000);
  try {
    const response = await fetch(`${IMMICH_API}${path}`, {
      method,
      redirect: 'error',
      signal: controller.signal,
      headers: {
        accept: 'application/json',
        authorization: `Bearer ${secret.immichToken}`,
        ...(body === undefined ? {} : { 'content-type': 'application/json' }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) fail('immich_response_invalid');
    const text = await response.text();
    if (text.length > MAX_IMMICH_RESPONSE_BYTES) fail('immich_response_too_large');
    try {
      return JSON.parse(text);
    } catch {
      fail('immich_json_invalid');
    }
  } catch (error) {
    if (error instanceof BridgeError) throw error;
    fail('immich_unavailable');
  } finally {
    clearTimeout(timeout);
  }
}

function assetIdsFromResponse(value) {
  const items = value?.assets?.items;
  if (!Array.isArray(items) || items.length > MAX_MEMBERS) fail('immich_assets_invalid');
  const ids = [];
  const seen = new Set();
  for (const item of items) {
    const id = item?.id;
    if (typeof id !== 'string' || !UUID_V4.test(id) || seen.has(id)) fail('immich_asset_id_invalid');
    seen.add(id);
    ids.push(id);
  }
  return ids;
}

function canonicalIds(assetIds, allowed) {
  const ids = [];
  const seen = new Set();
  for (const assetId of assetIds) {
    const canonical = allowed.get(assetId);
    if (canonical !== undefined && !seen.has(canonical)) {
      seen.add(canonical);
      ids.push(canonical);
    }
  }
  return ids;
}

async function memories(allowed) {
  const response = await immich('/memories?size=500');
  if (!Array.isArray(response) || response.length > 500) fail('immich_memories_invalid');
  const result = [];
  let ordinal = 1;
  for (const memory of response) {
    if (!memory || typeof memory !== 'object' || !Array.isArray(memory.assets) || memory.assets.length > MAX_MEMBERS) {
      fail('immich_memory_item_invalid');
    }
    const assetIds = [];
    const seen = new Set();
    for (const asset of memory.assets) {
      const id = asset?.id;
      if (typeof id !== 'string' || !UUID_V4.test(id) || seen.has(id)) fail('immich_memory_asset_invalid');
      seen.add(id);
      assetIds.push(id);
    }
    const classPhotoIds = canonicalIds(assetIds, allowed);
    if (classPhotoIds.length > 0) {
      // Do not disclose upstream title, owner, time, person, asset or memory
      // identity. The Class Archive public API recomputes the visible count.
      result.push({ label: `回忆 ${ordinal}`, class_photo_ids: classPhotoIds });
      ordinal += 1;
    }
  }
  return result;
}

async function people(allowed) {
  const response = await immich('/people?size=500&withHidden=false');
  const people = response?.people;
  if (!Array.isArray(people) || people.length > 500) fail('immich_people_invalid');
  const result = [];
  let ordinal = 1;
  for (const person of people) {
    const id = person?.id;
    if (typeof id !== 'string' || !UUID_V4.test(id)) fail('immich_person_id_invalid');
    const assets = assetIdsFromResponse(await immich('/search/metadata', 'POST', { personIds: [id], page: 1, size: 1000 }));
    const classPhotoIds = canonicalIds(assets, allowed);
    if (classPhotoIds.length > 0) {
      // Use a local ordinal, not the upstream person name or id. Identity and
      // visible membership stay wholly inside Class Archive policy.
      result.push({ label: `人物 ${ordinal}`, class_photo_ids: classPhotoIds });
      ordinal += 1;
    }
  }
  return result;
}

function respond(response, status, value) {
  const payload = JSON.stringify(value);
  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff',
    'content-length': Buffer.byteLength(payload),
  });
  response.end(payload);
}

const server = createServer(async (request, response) => {
  try {
    if (request.method !== 'POST' || !['/v1/people', '/v1/memories'].includes(request.url ?? '')) {
      fail('route_not_found', 404);
    }
    authorize(request);
    const allowed = await readJson(request);
    const items = request.url === '/v1/people' ? await people(allowed) : await memories(allowed);
    respond(response, 200, { items });
  } catch (error) {
    const status = error instanceof BridgeError ? error.status : 503;
    // Never log a request body, authorization value, Immich payload, asset id
    // or filesystem path. A generic response prevents an adapter oracle.
    respond(response, status, { error: status === 403 ? 'forbidden' : status === 404 ? 'not_found' : 'unavailable' });
  }
});

server.once('error', () => {
  console.error('IMMICH_GATEWAY_STARTUP=FAIL code=listen_unavailable');
  process.exit(1);
});
server.listen(PORT, '0.0.0.0');
