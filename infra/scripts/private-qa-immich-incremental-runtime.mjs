import { createHash } from 'node:crypto';
import { lstatSync, readFileSync } from 'node:fs';

// Explicit operator-only Immich delta runner. It executes in the existing
// metadata gateway container, reads that container's already-published
// technical credential, and talks only to the internal Immich API. It has no
// original/database mount and ordinary Class Archive GET routes never invoke
// this file.
const INPUT_PATH = '/tmp/class-archive-private-qa-immich-incremental-plan.json';
const SECRET_PATH = '/run/secrets/bridge.json';
const API = 'http://immich-server:2283/api';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

class IncrementalError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

function fail(code) {
  throw new IncrementalError(code);
}

function exactKeys(value, expected) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  const actual = Object.keys(value).sort();
  const wanted = [...expected].sort();
  return actual.length === wanted.length && actual.every((key, index) => key === wanted[index]);
}

function text(value, code, min, max, pattern = null) {
  if (typeof value !== 'string' || value.length < min || value.length > max || value.includes('\0')
    || (pattern && !pattern.test(value))) fail(code);
  return value;
}

function uuid(value, code) {
  return text(value, code, 36, 36, UUID).toLowerCase();
}

function privateJson(path, code, maximum) {
  const stat = lstatSync(path);
  if (!stat.isFile() || stat.isSymbolicLink() || (stat.mode & 0o077) !== 0 || stat.nlink !== 1
    || stat.size < 16 || stat.size > maximum) fail(code);
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    fail(code);
  }
}

function emitEvidence(value) {
  const raw = Buffer.from(JSON.stringify(value), 'utf8');
  if (raw.length < 16 || raw.length > 1024 * 1024) fail('output_invalid');
  const sha256 = createHash('sha256').update(raw).digest('hex');
  // The gateway root filesystem remains read-only and /tmp remains a private
  // tmpfs.  A single fixed stdout envelope is the only evidence egress.  The
  // owner operator validates its byte count, digest and canonical Base64
  // before writing it to an ignored owner-only host path.
  process.stdout.write(`CLASS_ARCHIVE_IMMICH_EVIDENCE_V1 bytes=${raw.length} sha256=${sha256} base64=${raw.toString('base64')}\n`);
}

function digest(value) {
  return createHash('sha256').update(JSON.stringify(value), 'utf8').digest('hex');
}

function mediaReference(value) {
  const reference = text(value, 'media_reference_invalid', 10, 512, /^(?:upload|galleries)\/[A-Za-z0-9._/-]+$/);
  if (reference.includes('//') || reference.includes('/./') || reference.includes('/../') || reference.includes('%')) {
    fail('media_reference_invalid');
  }
  return reference;
}

function immichPath(reference) {
  if (reference.startsWith('upload/')) return `/external/piwigo-upload/${reference.slice(7)}`;
  if (reference.startsWith('galleries/')) return `/external/piwigo-galleries/${reference.slice(10)}`;
  fail('media_reference_invalid');
}

function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function request(operation, path, method, token, body = undefined, timeout = 30_000) {
  let response;
  try {
    response = await fetch(`${API}${path}`, {
      method,
      headers: {
        accept: 'application/json',
        authorization: `Bearer ${token}`,
        ...(body === undefined ? {} : { 'content-type': 'application/json' }),
      },
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

async function inventory(token, libraryId, maximumAssets) {
  const result = [];
  let page = 1;
  for (let pageCount = 0; pageCount <= Math.ceil(maximumAssets / 1000); pageCount += 1) {
    const response = await request(
      `inventory_${page}`,
      '/search/metadata',
      'POST',
      token,
      { libraryId, page, size: 1000 },
    );
    const items = response?.assets?.items;
    if (!Array.isArray(items) || items.length > 1000) fail('inventory_invalid');
    result.push(...items);
    if (result.length > maximumAssets) fail('inventory_invalid');
    const next = response?.assets?.nextPage;
    if (next === null || next === undefined || items.length === 0) return result;
    const parsed = Number.parseInt(String(next), 10);
    if (!Number.isSafeInteger(parsed) || parsed <= page || parsed > Math.ceil(maximumAssets / 1000) + 1) {
      fail('inventory_invalid');
    }
    page = parsed;
  }
  fail('inventory_invalid');
}

function inventoryByPath(items, libraryId, maximumAssets) {
  if (!Array.isArray(items) || items.length > maximumAssets) fail('inventory_invalid');
  const byPath = new Map();
  const ids = new Set();
  for (const item of items) {
    const id = uuid(item?.id, 'asset_id_invalid');
    const path = text(item?.originalPath, 'asset_path_invalid', 2, 1024);
    const updatedAt = text(item?.updatedAt, 'asset_updated_at_invalid', 10, 64);
    if (uuid(item?.libraryId, 'asset_library_invalid') !== libraryId || byPath.has(path) || ids.has(id)) {
      fail('inventory_ambiguous');
    }
    byPath.set(path, { id, updated_at: updatedAt });
    ids.add(id);
  }
  return byPath;
}

function baselineRuntimeDigest(plan, byPath, photoById) {
  const markers = [];
  for (const marker of plan.baseline) {
    const photo = photoById.get(marker.class_photo_id);
    const item = photo ? byPath.get(photo.path) : null;
    if (!item || item.id !== marker.immich_asset_id) fail('baseline_asset_changed');
    markers.push({ class_photo_id: marker.class_photo_id, immich_asset_id: item.id, updated_at: item.updated_at });
  }
  return digest(markers);
}

function queueActive(statistics, code) {
  if (!statistics || typeof statistics !== 'object') fail(`${code}_shape_invalid`);
  const values = ['active', 'completed', 'delayed', 'failed', 'paused', 'waiting'].map((key) => statistics[key]);
  if (!values.every(Number.isSafeInteger) || statistics.failed > 0) fail(`${code}_statistics_invalid`);
  return statistics.active > 0 || statistics.waiting > 0 || statistics.delayed > 0;
}

async function startMissingOnlyQueue(token, name) {
  const before = await request(`queue_before_${name}`, `/queues/${name}`, 'GET', token);
  if (queueActive(before?.statistics, `queue_before_${name}`)) return;
  // force=false is the central non-regression boundary: Immich v3.1.0's
  // QueueAll repositories select only assets missing the relevant face or
  // smart-search state. force=true would be a forbidden whole-library pass.
  // A queue may become active between the read and PUT.  Immich reports that
  // race as 400; accept it only after a second authenticated read proves the
  // exact queue is now active.  All other non-2xx responses remain fatal.
  let response;
  try {
    response = await fetch(`${API}/jobs/${name}`, {
      method: 'PUT',
      headers: {
        accept: 'application/json',
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({ command: 'start', force: false }),
      redirect: 'error',
      signal: AbortSignal.timeout(30_000),
    });
  } catch {
    fail(`run_${name}_transport`);
  }
  if (response.ok) return;
  if (response.status !== 400) fail(`run_${name}_http_${response.status}`);
  const raced = await request(`queue_race_${name}`, `/queues/${name}`, 'GET', token);
  if (!queueActive(raced?.statistics, `queue_race_${name}`)) fail(`run_${name}_http_400`);
}

async function waitQueue(token, name, timeout) {
  const deadline = Date.now() + timeout;
  let idle = 0;
  while (Date.now() < deadline) {
    const response = await request(`queue_${name}`, `/queues/${name}`, 'GET', token);
    const statistics = response?.statistics;
    if (queueActive(statistics, `queue_${name}`)) {
      idle = 0;
    } else {
      idle += 1;
      if (idle >= 5) return statistics;
    }
    await delay(1000);
  }
  fail(`queue_${name}_timeout`);
}

async function requireQueueIdle(token, name) {
  const response = await request(`queue_precondition_${name}`, `/queues/${name}`, 'GET', token);
  if (queueActive(response?.statistics, `queue_precondition_${name}`)) fail(`queue_precondition_${name}_busy`);
}

async function allPeople(token, maximum) {
  const people = [];
  let page = 1;
  for (let attempts = 0; attempts < Math.ceil(maximum / 1000); attempts += 1) {
    const result = await request(`people_${page}`, `/people?page=${page}&size=1000&withHidden=false`, 'GET', token);
    if (!Array.isArray(result?.people) || typeof result?.hasNextPage !== 'boolean') fail('people_invalid');
    people.push(...result.people);
    if (people.length > maximum) fail('people_invalid');
    if (!result.hasNextPage) return people;
    page += 1;
  }
  fail('people_invalid');
}

let stage = 'input';
try {
  if (process.argv.length !== 2) fail('arguments_invalid');
  const plan = privateJson(INPUT_PATH, 'input_invalid', 8 * 1024 * 1024);
  const secret = privateJson(SECRET_PATH, 'secret_invalid', 16 * 1024);
  if (!exactKeys(secret, ['version', 'bridge_token', 'immich_access_token']) || secret.version !== 1) fail('secret_invalid');
  const token = text(secret.immich_access_token, 'secret_invalid', 32, 8192, /^[A-Za-z0-9._~-]+$/);
  secret.bridge_token = null;
  secret.immich_access_token = null;

  stage = 'contract';
  if (!exactKeys(plan, [
    'version', 'scope', 'catalog_digest', 'catalog_count', 'baseline_count', 'delta_count',
    'baseline_digest', 'delta_digest', 'photos', 'baseline', 'delta', 'models',
  ]) || plan.version !== 1 || plan.scope !== 'PRIVATE_REAL_FULL'
    || !Number.isSafeInteger(plan.catalog_count) || plan.catalog_count < 2 || plan.catalog_count > 5000
    || !Number.isSafeInteger(plan.baseline_count) || plan.baseline_count < 1
    || !Number.isSafeInteger(plan.delta_count) || plan.delta_count < 1 || plan.delta_count > 512
    || plan.baseline_count + plan.delta_count !== plan.catalog_count
    || !Array.isArray(plan.photos) || plan.photos.length !== plan.catalog_count
    || !Array.isArray(plan.baseline) || plan.baseline.length !== plan.baseline_count
    || !Array.isArray(plan.delta) || plan.delta.length !== plan.delta_count
    || text(plan.catalog_digest, 'catalog_digest_invalid', 64, 64, /^[0-9a-f]{64}$/) === ''
    || text(plan.baseline_digest, 'baseline_digest_invalid', 64, 64, /^[0-9a-f]{64}$/) !== digest(plan.baseline)
    || text(plan.delta_digest, 'delta_digest_invalid', 64, 64, /^[0-9a-f]{64}$/) !== digest(plan.delta)) {
    fail('input_contract_invalid');
  }
  if (!exactKeys(plan.models, [
    'face_model_name', 'face_model_revision', 'search_model_name', 'search_model_revision',
  ])) fail('model_invalid');
  const expectedModels = {
    face_model_name: text(plan.models.face_model_name, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/),
    face_model_revision: text(plan.models.face_model_revision, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/),
    search_model_name: text(plan.models.search_model_name, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/),
    search_model_revision: text(plan.models.search_model_revision, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/),
  };

  const photoById = new Map();
  const pathSet = new Set();
  for (const raw of plan.photos) {
    if (!exactKeys(raw, ['class_photo_id', 'era', 'media_reference', 'sha256'])) fail('photo_invalid');
    const id = uuid(raw.class_photo_id, 'photo_invalid');
    const reference = mediaReference(raw.media_reference);
    const photo = {
      class_photo_id: id,
      era: text(raw.era, 'photo_invalid', 6, 9, /^(HERITAGE|LIVING)$/),
      sha256: text(raw.sha256, 'photo_invalid', 64, 64, /^[0-9a-f]{64}$/),
      path: immichPath(reference),
    };
    if (photoById.has(id) || pathSet.has(photo.path)) fail('photo_duplicate');
    photoById.set(id, photo);
    pathSet.add(photo.path);
  }
  const baselineIds = new Set();
  for (const marker of plan.baseline) {
    if (!exactKeys(marker, ['class_photo_id', 'sha256', 'immich_asset_id', 'indexed_at', 'updated_at'])) fail('baseline_invalid');
    const id = uuid(marker.class_photo_id, 'baseline_invalid');
    const photo = photoById.get(id);
    if (!photo || photo.sha256 !== text(marker.sha256, 'baseline_invalid', 64, 64, /^[0-9a-f]{64}$/)
      || baselineIds.has(id)) fail('baseline_invalid');
    marker.class_photo_id = id;
    marker.immich_asset_id = uuid(marker.immich_asset_id, 'baseline_invalid');
    text(marker.indexed_at, 'baseline_invalid', 10, 64);
    text(marker.updated_at, 'baseline_invalid', 10, 64);
    baselineIds.add(id);
  }
  const deltaIds = new Set();
  for (const marker of plan.delta) {
    if (!exactKeys(marker, ['class_photo_id', 'piwigo_image_id', 'sha256', 'trigger_kind', 'previous_immich_asset_id'])) fail('delta_invalid');
    const id = uuid(marker.class_photo_id, 'delta_invalid');
    if (!Number.isSafeInteger(marker.piwigo_image_id) || marker.piwigo_image_id < 1
      || marker.piwigo_image_id > 2_147_483_647) fail('delta_invalid');
    const photo = photoById.get(id);
    if (!photo || photo.sha256 !== text(marker.sha256, 'delta_invalid', 64, 64, /^[0-9a-f]{64}$/)
      || baselineIds.has(id) || deltaIds.has(id)
      || !['NEW_PHOTO', 'PIXEL_CHANGED'].includes(marker.trigger_kind)) fail('delta_invalid');
    marker.class_photo_id = id;
    marker.previous_immich_asset_id = marker.previous_immich_asset_id === null
      ? null : uuid(marker.previous_immich_asset_id, 'delta_invalid');
    deltaIds.add(id);
  }
  if (baselineIds.size + deltaIds.size !== photoById.size) fail('partition_invalid');

  stage = 'identity';
  const me = await request('me', '/users/me', 'GET', token);
  const ownerId = uuid(me?.id, 'technical_identity_invalid');
  if (me?.isAdmin !== true) fail('technical_identity_invalid');
  const libraries = await request('libraries', '/libraries', 'GET', token);
  if (!Array.isArray(libraries) || libraries.length !== 1) fail('library_invalid');
  const library = libraries[0];
  const libraryId = uuid(library?.id, 'library_invalid');
  if (uuid(library?.ownerId, 'library_invalid') !== ownerId
    || !Array.isArray(library?.importPaths) || library.importPaths.length !== 2
    || !library.importPaths.includes('/external/piwigo-upload')
    || !library.importPaths.includes('/external/piwigo-galleries')) fail('library_invalid');

  stage = 'queue_precondition';
  for (const queue of [
    'library', 'metadataExtraction', 'thumbnailGeneration', 'faceDetection', 'facialRecognition', 'smartSearch',
  ]) {
    await requireQueueIdle(token, queue);
  }

  stage = 'pre_inventory';
  const beforeItems = await inventory(token, libraryId, plan.catalog_count);
  if (beforeItems.length < plan.baseline_count || beforeItems.length > plan.catalog_count) fail('pre_inventory_count_invalid');
  const beforeByPath = inventoryByPath(beforeItems, libraryId, plan.catalog_count);
  const baselineBefore = baselineRuntimeDigest(plan, beforeByPath, photoById);
  for (const marker of plan.delta) {
    const item = beforeByPath.get(photoById.get(marker.class_photo_id).path);
    if (item && marker.previous_immich_asset_id !== null && item.id !== marker.previous_immich_asset_id) fail('delta_previous_asset_changed');
    // A prior operator attempt may have completed the internal library scan
    // before the Class Archive control-plane commit. The exact catalog path
    // remains sufficient to resume; it is still a delta until its original
    // checksum-bound Class Archive job reaches COMPLETE.
  }

  stage = 'scan';
  await request('library_scan', `/libraries/${libraryId}/scan`, 'POST', token);
  let scanComplete = false;
  for (let attempt = 0; attempt < 900; attempt += 1) {
    const statistics = await request('library_statistics', `/libraries/${libraryId}/statistics`, 'GET', token);
    if (!Number.isSafeInteger(statistics?.total) || statistics.total < beforeItems.length
      || statistics.total > plan.catalog_count) fail('library_statistics_invalid');
    if (statistics.total === plan.catalog_count) {
      scanComplete = true;
      break;
    }
    await delay(1000);
  }
  if (!scanComplete) fail('library_scan_incomplete');

  // Let the import cascade finish before asking missing-only AI queues to
  // inspect their durable status.  Otherwise a new asset can exist before its
  // preview, briefly looking ineligible for face detection.
  const libraryQueue = await waitQueue(token, 'library', 1_800_000);
  const metadataQueue = await waitQueue(token, 'metadataExtraction', 1_800_000);
  const thumbnailQueue = await waitQueue(token, 'thumbnailGeneration', 1_800_000);

  stage = 'queues';
  await startMissingOnlyQueue(token, 'faceDetection');
  const faceQueue = await waitQueue(token, 'faceDetection', 1_800_000);
  // AssetDetectFaces enqueues its newly-created face IDs itself.  Its
  // QueueAll guard observes those waiting jobs and skips the otherwise global
  // unassigned-face scan.  Starting FacialRecognition QueueAll here after the
  // queue becomes idle would be broader than the explicit photo delta.
  const recognitionQueue = await waitQueue(token, 'facialRecognition', 1_800_000);
  await startMissingOnlyQueue(token, 'smartSearch');
  const searchQueue = await waitQueue(token, 'smartSearch', 1_800_000);

  stage = 'post_inventory';
  const afterItems = await inventory(token, libraryId, plan.catalog_count);
  if (afterItems.length !== plan.catalog_count) fail('post_inventory_count_invalid');
  const afterByPath = inventoryByPath(afterItems, libraryId, plan.catalog_count);
  if (afterByPath.size !== photoById.size || [...afterByPath.keys()].some((path) => !pathSet.has(path))) {
    fail('post_inventory_unexpected_asset');
  }
  const baselineAfter = baselineRuntimeDigest(plan, afterByPath, photoById);
  if (baselineBefore !== baselineAfter) fail('baseline_runtime_changed');
  const bindings = [];
  for (const photo of photoById.values()) {
    const asset = afterByPath.get(photo.path);
    if (!asset) fail('post_inventory_missing_asset');
    bindings.push({ class_photo_id: photo.class_photo_id, immich_asset_id: asset.id });
  }
  if (new Set(bindings.map((item) => item.immich_asset_id)).size !== plan.catalog_count) fail('binding_duplicate');

  stage = 'result_probe';
  const people = await allPeople(token, 5000);
  if (people.length < 1) fail('people_empty');
  const search = await request('search_probe', '/search/smart', 'POST', token, { query: 'group photo', page: 1, size: 10 }, 60_000);
  if (!Array.isArray(search?.assets?.items)) fail('search_invalid');

  stage = 'output';
  const queueIdle = [libraryQueue, metadataQueue, thumbnailQueue, faceQueue, recognitionQueue, searchQueue].every(
    (queue) => queue.active === 0 && queue.waiting === 0 && queue.delayed === 0 && queue.failed === 0,
  );
  if (!queueIdle) fail('queue_not_idle');
  const model = await request('system_config', '/system-config', 'GET', token);
  const faceModel = text(model?.machineLearning?.facialRecognition?.modelName, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/);
  const searchModel = text(model?.machineLearning?.clip?.modelName, 'model_invalid', 1, 190, /^[A-Za-z0-9._:@\/-]+$/);
  if (faceModel !== expectedModels.face_model_name || searchModel !== expectedModels.search_model_name) fail('model_mismatch');
  const evidence = {
    version: 1,
    scope: plan.scope,
    catalog_digest: plan.catalog_digest,
    baseline_digest: plan.baseline_digest,
    delta_digest: plan.delta_digest,
    runtime_mode: 'INCREMENTAL',
    asset_count: plan.catalog_count,
    baseline_count: plan.baseline_count,
    delta_count: plan.delta_count,
    people_count: people.length,
    face_model_name: faceModel,
    face_model_revision: expectedModels.face_model_revision,
    search_model_name: searchModel,
    search_model_revision: expectedModels.search_model_revision,
    face_queue_idle: true,
    recognition_queue_idle: true,
    search_queue_idle: true,
    library_queue_idle: true,
    metadata_queue_idle: true,
    thumbnail_queue_idle: true,
    force_full: false,
    baseline_runtime_digest_before: baselineBefore,
    baseline_runtime_digest_after: baselineAfter,
    old_asset_changes: 0,
    assets: bindings,
  };
  emitEvidence(evidence);
  process.stdout.write(`PRIVATE_QA_IMMICH_INCREMENTAL=PASS assets=${plan.catalog_count} baseline=${plan.baseline_count} delta=${plan.delta_count} old_changed=0 force_full=0\n`);
} catch (error) {
  const safeStage = /^[a-z_]{1,48}$/.test(stage) ? stage : 'unknown';
  const code = error instanceof IncrementalError ? error.code : `unexpected_${safeStage}`;
  console.log(`PRIVATE_QA_IMMICH_INCREMENTAL=FAIL reason=${code}`);
  process.exit(1);
}
