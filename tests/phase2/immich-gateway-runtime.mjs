import { chmodSync, lstatSync, readFileSync, writeFileSync } from 'node:fs';

const INPUT_PATH = '/tmp/class-archive-immich-gateway-input.json';
const OUTPUT_PATH = '/tmp/class-archive-immich-gateway-output.json';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

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
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0 || stat.size < 1 || stat.size > 64 * 1024) fail(code);
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
      signal: AbortSignal.timeout(10_000),
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

let input;
try {
  if (process.argv.length !== 4 || process.argv[2] !== '--input-file' || process.argv[3] !== INPUT_PATH) fail('input_source_invalid');
  assertPrivateFile(INPUT_PATH, 'input_file_invalid');
  input = JSON.parse(readFileSync(INPUT_PATH, 'utf8'));
} catch (error) {
  console.error('IMMICH_GATEWAY_RUNTIME=FAIL reason=input_invalid');
  process.exit(1);
}

try {
  if (!exactKeys(input, ['email', 'libraryName', 'name', 'password', 'photos', 'version']) || input.version !== 1 || !Array.isArray(input.photos) || input.photos.length < 2 || input.photos.length > 500) {
    fail('input_shape_invalid');
  }
  const email = text(input.email, 'email_invalid', 8, 190, /^[A-Za-z0-9._+-]+@synthetic\.invalid$/);
  const password = text(input.password, 'password_invalid', 24, 190, /^[A-Za-z0-9._~-]+$/);
  const name = text(input.name, 'name_invalid', 3, 190);
  const libraryName = text(input.libraryName, 'library_name_invalid', 3, 190);
  const photos = [];
  const eras = new Set();
  const canonicalIds = new Set();
  for (const photo of input.photos) {
    if (!exactKeys(photo, ['class_photo_id', 'era', 'media_reference'])) fail('photo_shape_invalid');
    const classPhotoId = uuid(photo.class_photo_id, 'class_photo_id_invalid');
    // HERITAGE is eight characters while LIVING is six; the strict lexical
    // allowlist below is the authority, so accept the actual shortest form.
    const era = text(photo.era, 'photo_era_invalid', 6, 9, /^(HERITAGE|LIVING)$/);
    const reference = mediaReference(photo.media_reference, 'media_reference_invalid');
    if (canonicalIds.has(classPhotoId)) fail('photo_duplicate');
    canonicalIds.add(classPhotoId);
    eras.add(era);
    photos.push({ class_photo_id: classPhotoId, era, media_reference: reference });
  }
  if (!eras.has('HERITAGE') || !eras.has('LIVING')) fail('photo_era_invalid');

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
  for (let attempt = 0; attempt < 120; attempt += 1) {
    const stats = await request('library_statistics', `/libraries/${libraryId}/statistics`, 'GET', undefined, accessToken);
    if (Number.isSafeInteger(stats?.total) && stats.total >= photos.length) {
      indexed = true;
      break;
    }
    await delay(1000);
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
    const assetId = uuid(items[0]?.id, 'asset_id_invalid');
    bindings.push({ class_photo_id: photo.class_photo_id, immich_asset_id: assetId });
  }
  if (new Set(bindings.map((item) => item.immich_asset_id)).size !== photos.length) fail('asset_binding_duplicate');

  const memoryPair = [photos.find((photo) => photo.era === 'HERITAGE'), photos.find((photo) => photo.era === 'LIVING')];
  if (memoryPair.some((photo) => !photo)) fail('memory_pair_invalid');
  const bindingsByCanonical = new Map(bindings.map((item) => [item.class_photo_id, item.immich_asset_id]));
  const memoryAssetIds = memoryPair.map((photo) => bindingsByCanonical.get(photo.class_photo_id));
  if (memoryAssetIds.some((id) => typeof id !== 'string')) fail('memory_pair_invalid');

  const memory = await request('memory_create', '/memories', 'POST', {
    type: 'on_this_day',
    data: { year: 2026 },
    memoryAt: new Date().toISOString(),
    assetIds: memoryAssetIds,
    isSaved: false,
  }, accessToken);
  if (!Array.isArray(memory?.assets) || memory.assets.length !== 2) fail('memory_create_invalid');

  const output = JSON.stringify({ version: 1, access_token: accessToken, assets: bindings });
  writeFileSync(OUTPUT_PATH, output, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
  chmodSync(OUTPUT_PATH, 0o600);
  assertPrivateFile(OUTPUT_PATH, 'output_file_invalid');
  // Do not print any technical token, user id, library id, asset id, path or
  // upstream response. The PowerShell runner handles and deletes the private
  // result only long enough to bind the narrow bridge.
  console.log(`IMMICH_GATEWAY_RUNTIME=PASS assets=${bindings.length} memory=1`);
  process.exit(0);
} catch (error) {
  const code = error instanceof FixtureError ? error.code : 'unexpected';
  console.error(`IMMICH_GATEWAY_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}
