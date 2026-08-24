import { chmodSync, createReadStream, lstatSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

// This executable is copied into the private, internal-only Immich server by
// private-qa-immich.ps1.  It is never mounted into or run by public CI.
const INPUT_PATH = '/tmp/class-archive-private-qa-immich-runtime-input.json';
const OUTPUT_PATH = '/tmp/class-archive-private-qa-immich-runtime-output.json';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

class RuntimeError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

function fail(code) {
  throw new RuntimeError(code);
}

function exactKeys(value, expected) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const actual = Object.keys(value).sort();
  return actual.length === expected.length && actual.every((key, index) => key === [...expected].sort()[index]);
}

function text(value, code, min, max, pattern = null) {
  if (typeof value !== 'string' || value.length < min || value.length > max || value.includes('\0') || (pattern && !pattern.test(value))) fail(code);
  return value;
}

function uuid(value, code) {
  return text(value, code, 36, 36, UUID).toLowerCase();
}

function assertPrivateFile(path, code, max = 2 * 1024 * 1024) {
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0 || stat.nlink !== 1 || stat.size < 16 || stat.size > max) fail(code);
}

function mediaReference(value) {
  const reference = text(value, 'media_reference_invalid', 10, 512, /^(?:upload|galleries)\/[A-Za-z0-9._/-]+$/);
  if (reference.includes('//') || reference.includes('/./') || reference.includes('/../') || reference.includes('%')) fail('media_reference_invalid');
  return reference;
}

function immichPath(reference) {
  if (reference.startsWith('upload/')) return `/external/piwigo-upload/${reference.slice('upload/'.length)}`;
  if (reference.startsWith('galleries/')) return `/external/piwigo-galleries/${reference.slice('galleries/'.length)}`;
  fail('media_reference_invalid');
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function fileSha256(path) {
  return await new Promise((resolve, reject) => {
    const hash = createHash('sha256');
    const stream = createReadStream(path, { highWaterMark: 1024 * 1024 });
    stream.on('error', () => reject(new RuntimeError('mounted_original_unavailable')));
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
  });
}

async function request(operation, path, method = 'GET', body = undefined, token = null, timeout = 30_000) {
  const headers = { accept: 'application/json' };
  if (body !== undefined) headers['content-type'] = 'application/json';
  if (token !== null) headers.authorization = `Bearer ${token}`;
  let response;
  try {
    response = await fetch(`http://127.0.0.1:2283/api${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      redirect: 'error',
      signal: AbortSignal.timeout(timeout),
    });
  } catch {
    fail(`${operation}_transport`);
  }
  if (!response.ok) fail(`${operation}_http_${response.status}`);
  if (response.status === 204) return null;
  const raw = await response.text();
  if (raw.length > 2 * 1024 * 1024) fail(`${operation}_response_too_large`);
  try {
    return JSON.parse(raw);
  } catch {
    fail(`${operation}_response_invalid`);
  }
}

function queueActive(statistics, code) {
  if (!statistics || typeof statistics !== 'object') fail(`${code}_shape_invalid`);
  const values = ['active', 'completed', 'delayed', 'failed', 'paused', 'waiting'].map((key) => statistics[key]);
  if (!values.every(Number.isSafeInteger) || statistics.failed > 0) fail(`${code}_statistics_invalid`);
  return statistics.active > 0 || statistics.waiting > 0 || statistics.delayed > 0;
}

async function waitForQueue(token, name, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let observed = false;
  let idleObservations = 0;
  while (Date.now() < deadline) {
    const result = await request(`queue_${name}`, `/queues/${name}`, 'GET', undefined, token);
    const statistics = result?.statistics;
    if (!statistics || typeof statistics !== 'object') fail(`queue_${name}_shape_invalid`);
    const values = ['active', 'completed', 'delayed', 'failed', 'paused', 'waiting'].map((key) => statistics[key]);
    if (!values.every(Number.isSafeInteger) || statistics.failed > 0) fail(`queue_${name}_statistics_invalid`);
    if (queueActive(statistics, `queue_${name}`)) {
      observed = true;
      idleObservations = 0;
    } else {
      idleObservations += 1;
    }
    // Completed counters may be pruned to zero immediately. The triggering
    // endpoint has already returned at this point, so five consecutive idle
    // observations are a bounded, fail-closed completion signal; later
    // People/Search result checks still have to succeed.
    if (!queueActive(statistics, `queue_${name}`) && (observed || statistics.completed > 0 || idleObservations >= 5)) return statistics.completed;
    await delay(1_000);
  }
  fail(`queue_${name}_timeout`);
}

async function startQueueIfIdle(token, name) {
  const before = await request(`queue_before_${name}`, `/queues/${name}`, 'GET', undefined, token);
  if (queueActive(before?.statistics, `queue_before_${name}`)) return;
  let response;
  try {
    response = await fetch(`http://127.0.0.1:2283/api/jobs/${name}`, {
      method: 'PUT',
      headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${token}` },
      body: JSON.stringify({ command: 'start', force: true }),
      redirect: 'error',
      signal: AbortSignal.timeout(30_000),
    });
  } catch {
    fail(`run_${name}_transport`);
  }
  if (response.ok) return;
  if (response.status === 400) {
    const after = await request(`queue_after_${name}`, `/queues/${name}`, 'GET', undefined, token);
    if (queueActive(after?.statistics, `queue_after_${name}`)) return;
  }
  fail(`run_${name}_http_${response.status}`);
}

let input;
try {
  if (process.argv.length !== 4 || process.argv[2] !== '--input-file' || process.argv[3] !== INPUT_PATH) fail('input_source_invalid');
  assertPrivateFile(INPUT_PATH, 'input_file_invalid');
  input = JSON.parse(readFileSync(INPUT_PATH, 'utf8'));
} catch (error) {
  const code = error instanceof RuntimeError ? error.code : 'input_invalid';
  console.error(`PRIVATE_QA_IMMICH_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}

try {
  if (!exactKeys(input, ['version', 'scope', 'mode', 'catalog_digest', 'email', 'password', 'name', 'library_name', 'photos'])
    || input.version !== 1 || input.scope !== 'PRIVATE_REAL_DATA_QA') fail('input_shape_invalid');
  const mode = text(input.mode, 'mode_invalid', 6, 7, /^(INITIAL|RESUME)$/);
  const catalogDigest = text(input.catalog_digest, 'catalog_digest_invalid', 64, 64, /^[0-9a-f]{64}$/);
  const email = text(input.email, 'email_invalid', 8, 190, /^[A-Za-z0-9._+-]+@private\.invalid$/);
  const password = text(input.password, 'password_invalid', 32, 190, /^[A-Za-z0-9._~-]+$/);
  const name = text(input.name, 'name_invalid', 3, 190);
  const libraryName = text(input.library_name, 'library_name_invalid', 3, 190);
  if (!Array.isArray(input.photos) || input.photos.length < 1 || input.photos.length > 500) fail('catalog_count_invalid');

  const photos = [];
  const canonicalIds = new Set();
  const paths = new Set();
  for (const item of input.photos) {
    if (!exactKeys(item, ['class_photo_id', 'era', 'media_reference', 'sha256'])) fail('photo_shape_invalid');
    const classPhotoId = uuid(item.class_photo_id, 'class_photo_id_invalid');
    const era = text(item.era, 'era_invalid', 6, 9, /^(HERITAGE|LIVING)$/);
    const reference = mediaReference(item.media_reference);
    const sha256 = text(item.sha256, 'sha256_invalid', 64, 64, /^[0-9a-f]{64}$/);
    const path = immichPath(reference);
    if (canonicalIds.has(classPhotoId) || paths.has(path)) fail('catalog_duplicate');
    canonicalIds.add(classPhotoId);
    paths.add(path);
    photos.push({ class_photo_id: classPhotoId, era, media_reference: reference, sha256, path });
  }

  const started = Date.now();
  const verifyStarted = Date.now();
  for (const photo of photos) {
    const actual = await fileSha256(photo.path);
    if (actual !== photo.sha256) fail('mounted_original_hash_mismatch');
  }
  const verifyMs = Date.now() - verifyStarted;

  let adminId;
  if (mode === 'INITIAL') {
    const admin = await request('admin_signup', '/auth/admin-sign-up', 'POST', { email, password, name });
    adminId = uuid(admin?.id, 'admin_id_invalid');
  }
  const login = await request('technical_login', '/auth/login', 'POST', { email, password });
  const accessToken = text(login?.accessToken, 'access_token_invalid', 32, 8192, /^[A-Za-z0-9._~-]+$/);
  const loginAdminId = uuid(login?.userId, 'login_user_id_invalid');
  if (login?.isAdmin !== true || (adminId !== undefined && loginAdminId !== adminId)) fail('technical_identity_invalid');
  adminId = loginAdminId;

  let library;
  let scanMs = 0;
  if (mode === 'INITIAL') {
    library = await request('library_create', '/libraries', 'POST', {
      ownerId: adminId,
      name: libraryName,
      importPaths: ['/external/piwigo-upload', '/external/piwigo-galleries'],
    }, accessToken);
    const createdLibraryId = uuid(library?.id, 'library_id_invalid');
    const scanStarted = Date.now();
    await request('library_scan', `/libraries/${createdLibraryId}/scan`, 'POST', undefined, accessToken);
    let indexed = false;
    for (let attempt = 0; attempt < 900; attempt += 1) {
      const stats = await request('library_statistics', `/libraries/${createdLibraryId}/statistics`, 'GET', undefined, accessToken);
      if (!Number.isSafeInteger(stats?.total) || stats.total < 0 || stats.total > photos.length) fail('library_statistics_invalid');
      if (stats.total === photos.length) {
        indexed = true;
        break;
      }
      await delay(1_000);
    }
    if (!indexed) fail('library_scan_incomplete');
    scanMs = Date.now() - scanStarted;
  } else {
    const libraries = await request('libraries_list', '/libraries', 'GET', undefined, accessToken);
    if (!Array.isArray(libraries) || libraries.length !== 1) fail('resume_library_count_invalid');
    library = libraries[0];
  }

  const libraryId = uuid(library?.id, 'library_id_invalid');
  if (uuid(library?.ownerId, 'library_owner_id_invalid') !== adminId
    || library?.name !== libraryName
    || !Array.isArray(library?.importPaths)
    || library.importPaths.length !== 2
    || !library.importPaths.includes('/external/piwigo-upload')
    || !library.importPaths.includes('/external/piwigo-galleries')) fail('library_identity_invalid');

  const libraryStats = await request('library_statistics_final', `/libraries/${libraryId}/statistics`, 'GET', undefined, accessToken);
  if (!Number.isSafeInteger(libraryStats?.total) || libraryStats.total !== photos.length
    || !Number.isSafeInteger(libraryStats?.photos) || !Number.isSafeInteger(libraryStats?.videos)
    || libraryStats.photos + libraryStats.videos !== photos.length) fail('library_statistics_mismatch');

  // Immich v3.1.0's legacy `originalPath` metadata filter is a substring
  // predicate, not an exact lookup.  Enumerate the one expected library via
  // the official stable large-assets API (bounded to 1000).  Polling this
  // metadata-backed endpoint is also a stronger completion proof than Redis
  // queue counters, whose completed values may be pruned to zero after a
  // previous interrupted run.
  let items = [];
  for (let attempt = 0; attempt < 900; attempt += 1) {
    items = await request(
      'library_asset_inventory',
      `/search/large-assets?libraryId=${encodeURIComponent(libraryId)}&minFileSize=0&size=1000`,
      'POST',
      undefined,
      accessToken,
    );
    if (!Array.isArray(items) || items.length > photos.length) fail('asset_inventory_count_invalid');
    if (items.length === photos.length) break;
    await delay(1_000);
  }
  if (!Array.isArray(items) || items.length !== photos.length) fail('asset_inventory_count_invalid');

  const assetsByPath = new Map();
  const inventoryIds = new Set();
  for (const item of items) {
    const assetId = uuid(item?.id, 'asset_id_invalid');
    const originalPath = text(item?.originalPath, 'asset_path_invalid', 2, 1024);
    // AssetResponseDto does not expose the internal `isExternal` column in
    // v3.1.0. A non-null exact libraryId, exact owner and a path inside the
    // two validated external import roots provide the equivalent proof.
    if (uuid(item?.libraryId, 'asset_library_id_invalid') !== libraryId
      || uuid(item?.ownerId, 'asset_owner_id_invalid') !== adminId
      || inventoryIds.has(assetId) || assetsByPath.has(originalPath)) fail('asset_inventory_ambiguous');
    inventoryIds.add(assetId);
    assetsByPath.set(originalPath, assetId);
  }
  if (assetsByPath.size !== paths.size || [...assetsByPath.keys()].some((path) => !paths.has(path))) fail('asset_inventory_unexpected_path');

  const bindings = photos.map((photo) => {
    const assetId = assetsByPath.get(photo.path);
    if (assetId === undefined) fail('asset_inventory_missing_path');
    return { class_photo_id: photo.class_photo_id, immich_asset_id: assetId };
  });
  if (bindings.length !== photos.length || new Set(bindings.map((item) => item.immich_asset_id)).size !== photos.length) fail('asset_binding_duplicate');

  const assetIds = bindings.map((item) => item.immich_asset_id);
  let faceJobs = 0;
  let recognitionJobs = 0;
  let smartJobs = 0;
  let faceMs = 0;
  let recognitionMs = 0;
  let searchIndexMs = 0;
  if (mode === 'INITIAL') {
    const faceStarted = Date.now();
    await request('refresh_faces', '/assets/jobs', 'POST', { assetIds, name: 'refresh-faces' }, accessToken, 60_000);
    faceJobs = await waitForQueue(accessToken, 'faceDetection', 1_800_000);
    faceMs = Date.now() - faceStarted;

    const recognitionStarted = Date.now();
    await startQueueIfIdle(accessToken, 'facialRecognition');
    recognitionJobs = await waitForQueue(accessToken, 'facialRecognition', 1_800_000);
    recognitionMs = Date.now() - recognitionStarted;

    const searchIndexStarted = Date.now();
    await startQueueIfIdle(accessToken, 'smartSearch');
    smartJobs = await waitForQueue(accessToken, 'smartSearch', 1_800_000);
    searchIndexMs = Date.now() - searchIndexStarted;
  }

  const people = await request('people', '/people?size=500&withHidden=false', 'GET', undefined, accessToken);
  if (!Array.isArray(people?.people) || people.people.length < 1) fail('people_response_invalid');
  const searchQueries = [
    ['zh_classroom', '教室里的照片'],
    ['zh_playground', '操场上的合照'],
    ['zh_graduation', '毕业时拍的照片'],
    ['zh_night', '晚上的集体照'],
    ['en_classroom', 'photos in a classroom'],
    ['en_playground', 'group photo on a playground'],
    ['en_graduation', 'graduation photos'],
    ['en_night', 'group photo at night'],
  ];
  const searchCounts = {};
  const searchStarted = Date.now();
  for (const [key, query] of searchQueries) {
    const result = await request(`search_${key}`, '/search/smart', 'POST', { query, page: 1, size: 50 }, accessToken, 60_000);
    const items = result?.assets?.items;
    if (!Array.isArray(items) || items.length > 50) fail('search_response_invalid');
    searchCounts[key] = items.length;
  }
  const searchMs = Date.now() - searchStarted;
  if (Object.values(searchCounts).some((count) => !Number.isSafeInteger(count) || count < 1)) fail('search_runtime_empty');

  const output = {
    version: 1,
    scope: 'PRIVATE_REAL_DATA_QA',
    catalog_digest: catalogDigest,
    access_token: accessToken,
    assets: bindings,
    metrics: {
      asset_count: photos.length,
      people_count: people.people.length,
      face_jobs: faceJobs,
      recognition_jobs: recognitionJobs,
      smart_jobs: smartJobs,
      reused_existing_indexes: mode === 'RESUME',
      search_counts: searchCounts,
      timings_ms: {
        mounted_sha256: verifyMs,
        library_scan: scanMs,
        face_detection: faceMs,
        face_recognition: recognitionMs,
        smart_index: searchIndexMs,
        smart_queries: searchMs,
        total: Date.now() - started,
      },
    },
  };
  writeFileSync(OUTPUT_PATH, JSON.stringify(output), { encoding: 'utf8', mode: 0o600, flag: 'wx' });
  chmodSync(OUTPUT_PATH, 0o600);
  assertPrivateFile(OUTPUT_PATH, 'output_file_invalid');
  console.log(`PRIVATE_QA_IMMICH_RUNTIME=PASS assets=${photos.length} people=${people.people.length} face_jobs=${faceJobs} recognition_jobs=${recognitionJobs} smart_jobs=${smartJobs}`);
} catch (error) {
  const code = error instanceof RuntimeError ? error.code : 'unexpected';
  console.error(`PRIVATE_QA_IMMICH_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}
