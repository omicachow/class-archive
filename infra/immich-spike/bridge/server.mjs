import { createServer } from 'node:http';
import { lstatSync, readFileSync } from 'node:fs';
import { timingSafeEqual } from 'node:crypto';

const PORT = 8080;
const MAX_REQUEST_BYTES = 128 * 1024;
const MAX_IMMICH_RESPONSE_BYTES = 2 * 1024 * 1024;
const MAX_ASSETS = 500;
const MAX_MEMBERS = 1000;
const SEARCH_CANDIDATE_WINDOW = 500;
const MAX_SEARCH_RESULTS = 50;
const PEOPLE_PAGE_SIZE = 1000;
const MAX_PEOPLE_PAGES = 5;
const MAX_PEOPLE = PEOPLE_PAGE_SIZE * MAX_PEOPLE_PAGES;
const PEOPLE_ASSET_PAGE_SIZE = 500;
const MAX_PEOPLE_ASSET_PAGES = 40;
const FACE_LOOKUP_CONCURRENCY = 24;
const MAX_PORTRAIT_FOCUS_LOOKUPS = 48;
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

async function readJson(request, route) {
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
  const expectedKeys = route === '/v1/search' ? ['assets', 'query'] : ['assets'];
  if (!isExactKeys(value, expectedKeys) || !Array.isArray(value.assets) || value.assets.length < 1 || value.assets.length > MAX_ASSETS) {
    fail('request_shape_invalid', 400);
  }
  if (route === '/v1/search' && (typeof value.query !== 'string' || value.query.trim() === '' || value.query.length > 190 || value.query.includes('\u0000'))) {
    fail('query_invalid', 400);
  }

  const mapping = new Map();
  const canonicalIds = new Set();
  for (const item of value.assets) {
    if (!isExactKeys(item, ['class_photo_id', 'immich_asset_id'])) fail('asset_shape_invalid', 400);
    const classPhotoId = item.class_photo_id;
    const assetId = item.immich_asset_id;
    if (typeof classPhotoId !== 'string' || typeof assetId !== 'string' || !UUID_V4.test(classPhotoId) || !UUID_V4.test(assetId)) {
      fail('asset_value_invalid', 400);
    }
    if (mapping.has(assetId) || canonicalIds.has(classPhotoId)) fail('asset_duplicate', 400);
    mapping.set(assetId, classPhotoId);
    canonicalIds.add(classPhotoId);
  }
  return { allowed: mapping, query: route === '/v1/search' ? value.query.trim() : null };
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

async function allVisiblePeople() {
  const result = [];
  let page = 1;
  for (let pageCount = 0; pageCount < MAX_PEOPLE_PAGES; pageCount += 1) {
    const response = await immich(`/people?page=${page}&size=${PEOPLE_PAGE_SIZE}&withHidden=false`);
    const batch = response?.people;
    const hasNextPage = response?.hasNextPage;
    const total = response?.total;
    const hidden = response?.hidden;
    if (!Array.isArray(batch) || batch.length > PEOPLE_PAGE_SIZE || typeof hasNextPage !== 'boolean'
      || !Number.isSafeInteger(total) || total < 0 || total > MAX_PEOPLE
      || !Number.isSafeInteger(hidden) || hidden < 0 || hidden > total) {
      fail('immich_people_invalid');
    }
    result.push(...batch);
    if (result.length > MAX_PEOPLE || result.length > total || (hasNextPage && batch.length === 0)) {
      fail('immich_people_invalid');
    }
    if (!hasNextPage) return result;
    page += 1;
  }
  fail('immich_people_invalid');
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
  // Immich v3.1.0 defaults to 500 people per page. A full archive can exceed
  // that without being large, so consume its explicit page/hasNextPage
  // contract while retaining a hard 5,000-cluster ceiling. Upstream totals
  // still never cross this private boundary.
  const people = await allVisiblePeople();
  const records = new Map();
  for (const person of people) {
    const id = person?.id;
    if (typeof id !== 'string' || !UUID_V4.test(id) || records.has(id)) fail('immich_person_id_invalid');
    records.set(id, {
      id,
      classPhotoIds: [],
      classPhotoSet: new Set(),
      coverAssetId: null,
      coverClassPhotoId: null,
      portraitFocus: null,
    });
  }
  if (records.size === 0) return [];

  // One paginated metadata scan returns the person memberships already
  // attached to each asset. This replaces the previous N-person query fanout
  // (and its N x face-query tail latency) while retaining the exact same ACL
  // boundary: only asset ids present in `allowed` contribute membership,
  // count or cover selection. Upstream totals and denied ids never leave the
  // bridge.
  const seenAssets = new Set();
  let page = 1;
  for (let pageCount = 0; pageCount < MAX_PEOPLE_ASSET_PAGES; pageCount += 1) {
    const metadata = await immich('/search/metadata', 'POST', {
      withPeople: true,
      page,
      size: PEOPLE_ASSET_PAGE_SIZE,
    });
    const assets = metadata?.assets?.items;
    const nextPage = metadata?.assets?.nextPage;
    if (!Array.isArray(assets) || assets.length > PEOPLE_ASSET_PAGE_SIZE
      || !(nextPage === null || (typeof nextPage === 'string' && /^\d+$/.test(nextPage)))) {
      fail('immich_assets_invalid');
    }
    for (const asset of assets) {
      const assetId = asset?.id;
      if (typeof assetId !== 'string' || !UUID_V4.test(assetId) || seenAssets.has(assetId)) {
        fail('immich_asset_id_invalid');
      }
      seenAssets.add(assetId);
      const classPhotoId = allowed.get(assetId);
      if (classPhotoId === undefined) continue;
      const memberships = asset?.people;
      if (!Array.isArray(memberships) || memberships.length > 500) fail('immich_people_invalid');
      for (const membership of memberships) {
        const personId = membership?.id;
        if (typeof personId !== 'string' || !UUID_V4.test(personId)) fail('immich_person_id_invalid');
        const record = records.get(personId);
        if (record === undefined || record.classPhotoSet.has(classPhotoId)) continue;
        record.classPhotoSet.add(classPhotoId);
        record.classPhotoIds.push(classPhotoId);
        if (record.coverAssetId === null) {
          record.coverAssetId = assetId;
          record.coverClassPhotoId = classPhotoId;
        }
      }
    }
    if (nextPage === null) break;
    const candidatePage = Number(nextPage);
    if (!Number.isSafeInteger(candidatePage) || candidatePage <= page) fail('immich_assets_invalid');
    page = candidatePage;
    if (pageCount === MAX_PEOPLE_ASSET_PAGES - 1) fail('immich_assets_invalid');
  }

  // Face coordinates are optional presentation metadata. Resolve a bounded
  // leading set of unique authorized covers once (group photos often cover
  // many people); all remaining cards safely use the full-photo crop. This
  // keeps a cold People projection within the bridge deadline without making
  // crop availability part of membership or cover authorization.
  const coverAssets = [...new Set(
    [...records.values()].map((record) => record.coverAssetId).filter((id) => typeof id === 'string'),
  )].slice(0, MAX_PORTRAIT_FOCUS_LOOKUPS);
  const facesByAsset = new Map();
  for (let offset = 0; offset < coverAssets.length; offset += FACE_LOOKUP_CONCURRENCY) {
    const ids = coverAssets.slice(offset, offset + FACE_LOOKUP_CONCURRENCY);
    const settled = await Promise.allSettled(ids.map((assetId) => immich(`/faces?id=${encodeURIComponent(assetId)}`)));
    settled.forEach((item, index) => {
      if (item.status === 'fulfilled' && Array.isArray(item.value) && item.value.length <= 1000) {
        facesByAsset.set(ids[index], item.value);
      }
    });
  }

  const rounded = (value) => Math.round(value * 10_000) / 10_000;
  const result = [];
  for (const record of records.values()) {
    if (record.classPhotoIds.length === 0 || record.coverAssetId === null || record.coverClassPhotoId === null) continue;
    const faces = facesByAsset.get(record.coverAssetId) ?? [];
    const face = faces.find((candidate) => candidate?.person?.id === record.id);
    if (face) {
      const values = [
        face.boundingBoxX1, face.boundingBoxY1, face.boundingBoxX2, face.boundingBoxY2,
        face.imageWidth, face.imageHeight,
      ];
      if (values.every((value) => Number.isFinite(value))) {
        const [x1, y1, x2, y2, width, height] = values;
        if (width > 0 && height > 0 && x1 >= 0 && y1 >= 0 && x2 > x1 && y2 > y1 && x2 <= width && y2 <= height) {
          const faceRatio = Math.max((x2 - x1) / width, (y2 - y1) / height);
          record.portraitFocus = {
            x: rounded(((x1 + x2) / 2) / width),
            y: rounded(((y1 + y2) / 2) / height),
            zoom: rounded(Math.min(5, Math.max(1.15, 0.55 / faceRatio))),
          };
        }
      }
    }
    // This upstream UUID travels only over the private bridge to the
    // ClassArchivePerson mapper. It is never sent to the browser.
    result.push({
      immich_person_id: record.id,
      class_photo_ids: record.classPhotoIds,
      cover_class_photo_id: record.coverClassPhotoId,
      portrait_focus: record.portraitFocus,
    });
  }
  return result;
}

async function smartSearch(allowed, query) {
  // Fetch a bounded ranking window, apply the caller's canonical policy
  // membership, and only then take the product Top-K.  Capping the upstream
  // result before ACL would make a Family search depend on how many denied
  // LIVING assets happened to rank above its visible HERITAGE assets.
  const response = await immich('/search/smart', 'POST', { query, page: 1, size: SEARCH_CANDIDATE_WINDOW });
  const assetIds = assetIdsFromResponse(response);
  // Only mapped, policy-approved canonical ids leave this private boundary.
  // The public Gateway recomputes its visible count and pagination over this
  // already bounded membership list; Immich's total/cursor never cross.
  return canonicalIds(assetIds, allowed).slice(0, MAX_SEARCH_RESULTS);
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
    const route = request.url ?? '';
    if (request.method === 'GET' && route === '/healthz') {
      respond(response, 200, { status: 'ok' });
      return;
    }
    if (request.method !== 'POST' || !['/v1/people', '/v1/memories', '/v1/search'].includes(route)) {
      fail('route_not_found', 404);
    }
    authorize(request);
    const payload = await readJson(request, route);
    if (route === '/v1/search') {
      respond(response, 200, { class_photo_ids: await smartSearch(payload.allowed, payload.query) });
      return;
    }
    const items = route === '/v1/people' ? await people(payload.allowed) : await memories(payload.allowed);
    respond(response, 200, { items });
  } catch (error) {
    const status = error instanceof BridgeError ? error.status : 503;
    // Never log a request body, authorization value, Immich payload, asset id
    // or filesystem path. A generic response prevents an adapter oracle; the
    // fixed internal code remains useful for local health diagnosis.
    const code = error instanceof BridgeError ? error.code : 'unexpected';
    console.error(`CLASS_ARCHIVE_IMMICH_GATEWAY_DIAGNOSTIC status=${status} code=${code}`);
    respond(response, status, { error: status === 403 ? 'forbidden' : status === 404 ? 'not_found' : 'unavailable' });
  }
});

server.once('error', () => {
  console.error('IMMICH_GATEWAY_STARTUP=FAIL code=listen_unavailable');
  process.exit(1);
});
server.listen(PORT, '0.0.0.0');
