import { chmodSync, lstatSync, readFileSync, writeFileSync } from 'node:fs';

// This fixture runs only inside the isolated Immich container. Its input and
// output are staged as owner-only files by the PowerShell runner; neither
// technical credentials nor Immich IDs are written to stdout.
const INPUT_PATH = '/tmp/class-archive-immich-people-input.json';
const OUTPUT_PATH = '/tmp/class-archive-immich-people-output.json';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const QUERIES = Object.freeze([
  ['zh_playground', '操场上的合照'],
  ['zh_basketball', '有人拿着篮球'],
  ['zh_classroom', '教室里的照片'],
  ['zh_night', '夜晚的集体照'],
  ['en_playground', 'group photo on playground'],
  ['en_basketball', 'person holding basketball'],
  ['en_classroom', 'photo in classroom'],
  ['en_night', 'group photo at night'],
]);

class FixtureError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

function fail(code) {
  throw new FixtureError(code);
}

function exactKeys(value, keys) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const actual = Object.keys(value).sort();
  return actual.length === keys.length && actual.every((key, index) => key === keys[index]);
}

function text(value, code, minimum, maximum, pattern = null) {
  if (typeof value !== 'string' || value.length < minimum || value.length > maximum || value.includes('\u0000') || (pattern && !pattern.test(value))) {
    fail(code);
  }
  return value;
}

function uuid(value, code) {
  return text(value, code, 36, 36, UUID_V4).toLowerCase();
}

function mediaReference(value, code) {
  const reference = text(value, code, 10, 512, /^(?:upload|galleries)\/[A-Za-z0-9._/-]+$/);
  if (reference.includes('//') || reference.includes('/./') || reference.includes('/../') || reference.includes('%')) fail(code);
  return reference;
}

function immichPath(reference) {
  if (reference.startsWith('upload/')) return '/external/piwigo-upload/' + reference.slice('upload/'.length);
  if (reference.startsWith('galleries/')) return '/external/piwigo-galleries/' + reference.slice('galleries/'.length);
  fail('media_reference_invalid');
}

function assertPrivateFile(path, code) {
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0 || stat.size < 1 || stat.size > 128 * 1024) fail(code);
}

async function request(operation, path, method = 'GET', body = undefined, accessToken = null) {
  const headers = { accept: 'application/json' };
  if (body !== undefined) headers['content-type'] = 'application/json';
  if (accessToken !== null) headers.authorization = `Bearer ${accessToken}`;
  let response;
  try {
    response = await fetch(`http://127.0.0.1:2283/api${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      redirect: 'error',
      signal: AbortSignal.timeout(15_000),
    });
  } catch {
    fail(`${operation}_transport`);
  }
  if (!response.ok) fail(`${operation}_http_${response.status}`);
  if (response.status === 204) return null;
  const bodyText = await response.text();
  if (bodyText.length > 2 * 1024 * 1024) fail(`${operation}_too_large`);
  try {
    return JSON.parse(bodyText);
  } catch {
    fail(`${operation}_json_invalid`);
  }
}

function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

function countAssets(response) {
  const items = response?.assets?.items;
  if (!Array.isArray(items) || items.length > 500) fail('smart_search_shape_invalid');
  const seen = new Set();
  for (const item of items) {
    const id = item?.id;
    if (typeof id !== 'string' || !UUID_V4.test(id) || seen.has(id)) fail('smart_search_asset_invalid');
    seen.add(id);
  }
  return items.length;
}

async function waitForQueue(accessToken, name, timeoutMilliseconds = 300_000) {
  const deadline = Date.now() + timeoutMilliseconds;
  let observed = false;
  while (Date.now() < deadline) {
    const response = await request(`queue_${name}`, `/queues/${name}`, 'GET', undefined, accessToken);
    const statistics = response?.statistics;
    if (!statistics || typeof statistics !== 'object') fail(`queue_${name}_shape_invalid`);
    const values = ['active', 'completed', 'delayed', 'failed', 'paused', 'waiting'].map((key) => statistics[key]);
    if (!values.every(Number.isSafeInteger)) fail(`queue_${name}_statistics_invalid`);
    if (statistics.failed > 0) fail(`queue_${name}_failed`);
    if (statistics.active > 0 || statistics.waiting > 0 || statistics.delayed > 0) observed = true;
    if (statistics.active === 0 && statistics.waiting === 0 && statistics.delayed === 0 && (observed || statistics.completed > 0)) {
      return statistics.completed;
    }
    await delay(1_000);
  }
  fail(`queue_${name}_timeout`);
}

let input;
try {
  if (process.argv.length !== 4 || process.argv[2] !== '--input-file' || process.argv[3] !== INPUT_PATH) fail('input_source_invalid');
  assertPrivateFile(INPUT_PATH, 'input_file_invalid');
  input = JSON.parse(readFileSync(INPUT_PATH, 'utf8'));
} catch {
  console.error('IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=input_invalid');
  process.exit(1);
}

try {
  if (!exactKeys(input, ['email', 'libraryName', 'name', 'password', 'photos', 'version']) || input.version !== 1 || !Array.isArray(input.photos) || input.photos.length < 12 || input.photos.length > 500) {
    fail('input_shape_invalid');
  }
  const email = text(input.email, 'email_invalid', 8, 190, /^[A-Za-z0-9._+-]+@synthetic\.invalid$/);
  const password = text(input.password, 'password_invalid', 24, 190, /^[A-Za-z0-9._~-]+$/);
  const name = text(input.name, 'name_invalid', 3, 190);
  const libraryName = text(input.libraryName, 'library_name_invalid', 3, 190);
  const photos = [];
  const canonicalIds = new Set();
  const eras = new Set();
  for (const photo of input.photos) {
    if (!exactKeys(photo, ['class_photo_id', 'era', 'media_reference'])) fail('photo_shape_invalid');
    const classPhotoId = uuid(photo.class_photo_id, 'class_photo_id_invalid');
    const era = text(photo.era, 'photo_era_invalid', 6, 9, /^(HERITAGE|LIVING)$/);
    const reference = mediaReference(photo.media_reference, 'media_reference_invalid');
    if (canonicalIds.has(classPhotoId)) fail('photo_duplicate');
    canonicalIds.add(classPhotoId);
    eras.add(era);
    photos.push({ class_photo_id: classPhotoId, era, media_reference: reference });
  }
  if (!eras.has('HERITAGE') || !eras.has('LIVING')) fail('photo_era_invalid');

  const started = Date.now();
  const admin = await request('admin_signup', '/auth/admin-sign-up', 'POST', { email, password, name });
  const adminId = uuid(admin?.id, 'admin_id_invalid');
  const login = await request('technical_login', '/auth/login', 'POST', { email, password });
  const accessToken = text(login?.accessToken, 'access_token_invalid', 32, 8192, /^[A-Za-z0-9._~-]+$/);
  if (login?.isAdmin !== true || login?.userId !== adminId) fail('technical_login_identity_invalid');

  const library = await request('library_create', '/libraries', 'POST', {
    ownerId: adminId,
    name: libraryName,
    importPaths: ['/external/piwigo-upload', '/external/piwigo-galleries'],
  }, accessToken);
  const libraryId = uuid(library?.id, 'library_id_invalid');
  if (!Array.isArray(library?.importPaths) || !library.importPaths.includes('/external/piwigo-upload') || !library.importPaths.includes('/external/piwigo-galleries')) {
    fail('library_paths_invalid');
  }
  await request('library_scan', `/libraries/${libraryId}/scan`, 'POST', undefined, accessToken);

  let indexed = false;
  for (let attempt = 0; attempt < 180; attempt += 1) {
    const stats = await request('library_statistics', `/libraries/${libraryId}/statistics`, 'GET', undefined, accessToken);
    if (Number.isSafeInteger(stats?.total) && stats.total >= photos.length) {
      indexed = true;
      break;
    }
    await delay(1_000);
  }
  if (!indexed) fail('library_scan_incomplete');

  const bindings = [];
  for (const photo of photos) {
    const response = await request('asset_search', '/search/metadata', 'POST', {
      libraryId,
      originalPath: immichPath(photo.media_reference),
      page: 1,
      size: 10,
    }, accessToken);
    const items = response?.assets?.items;
    if (!Array.isArray(items) || items.length !== 1) fail('asset_path_lookup_invalid');
    bindings.push({ class_photo_id: photo.class_photo_id, immich_asset_id: uuid(items[0]?.id, 'asset_id_invalid') });
  }
  if (new Set(bindings.map((item) => item.immich_asset_id)).size !== photos.length) fail('asset_binding_duplicate');

  const assetIds = bindings.map((item) => item.immich_asset_id);
  await request('refresh_faces', '/assets/jobs', 'POST', { assetIds, name: 'refresh-faces' }, accessToken);
  const faceJobs = await waitForQueue(accessToken, 'faceDetection');
  await request('run_facial_recognition', '/jobs/facialRecognition', 'PUT', { command: 'start', force: true }, accessToken);
  const recognitionJobs = await waitForQueue(accessToken, 'facialRecognition');
  await request('run_smart_search', '/jobs/smartSearch', 'PUT', { command: 'start', force: true }, accessToken);
  const smartJobs = await waitForQueue(accessToken, 'smartSearch');

  const people = await request('people', '/people?size=500&withHidden=false', 'GET', undefined, accessToken);
  if (!Array.isArray(people?.people) || people.people.length < 3 || people.people.length > 500) fail('face_clustering_incomplete');

  const searchCounts = [];
  for (const [nameKey, query] of QUERIES) {
    const result = await request(`smart_${nameKey}`, '/search/smart', 'POST', { query, page: 1, size: 100 }, accessToken);
    searchCounts.push({ name: nameKey, count: countAssets(result) });
  }
  if (searchCounts.every((item) => item.count === 0)) fail('smart_search_empty');

  const runtime = {
    face_jobs: faceJobs,
    person_count: people.people.length,
    recognition_jobs: recognitionJobs,
    search_counts: searchCounts,
    smart_jobs: smartJobs,
    total_milliseconds: Date.now() - started,
  };
  const output = JSON.stringify({ version: 1, access_token: accessToken, assets: bindings, runtime });
  writeFileSync(OUTPUT_PATH, output, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
  chmodSync(OUTPUT_PATH, 0o600);
  assertPrivateFile(OUTPUT_PATH, 'output_file_invalid');
  console.log(`IMMICH_PEOPLE_SEARCH_RUNTIME=PASS assets=${bindings.length} people=${runtime.person_count} face_jobs=${faceJobs} recognition_jobs=${recognitionJobs} smart_jobs=${smartJobs}`);
  process.exit(0);
} catch (error) {
  const code = error instanceof FixtureError ? error.code : 'unexpected';
  console.error(`IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}
