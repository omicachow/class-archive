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
// A deliberately small, reproducible relevance sheet for the committed
// fictional scene fixture.  It evaluates retrieval rather than claiming
// face/scene recognition accuracy from a healthy container.  The 72 baseline
// archive assets are intentionally treated as neither expected nor
// acceptable: if they occupy a top result they lower precision instead of
// being silently filtered out of the measurement.
const RELEVANCE = Object.freeze({
  zh_playground: Object.freeze({ language: 'zh', relevant: Object.freeze(['PLAYGROUND']), acceptable: Object.freeze(['OUTDOOR']) }),
  zh_basketball: Object.freeze({ language: 'zh', relevant: Object.freeze(['CLASSROOM']), acceptable: Object.freeze([]) }),
  zh_classroom: Object.freeze({ language: 'zh', relevant: Object.freeze(['CLASSROOM']), acceptable: Object.freeze([]) }),
  zh_night: Object.freeze({ language: 'zh', relevant: Object.freeze(['NIGHT']), acceptable: Object.freeze([]) }),
  en_playground: Object.freeze({ language: 'en', relevant: Object.freeze(['PLAYGROUND']), acceptable: Object.freeze(['OUTDOOR']) }),
  en_basketball: Object.freeze({ language: 'en', relevant: Object.freeze(['CLASSROOM']), acceptable: Object.freeze([]) }),
  en_classroom: Object.freeze({ language: 'en', relevant: Object.freeze(['CLASSROOM']), acceptable: Object.freeze([]) }),
  en_night: Object.freeze({ language: 'en', relevant: Object.freeze(['NIGHT']), acceptable: Object.freeze([]) }),
});
// Keep the upstream oracle semantically identical to the product boundary.
// The bridge observes this bounded ranking window, filters it through the
// caller's canonical policy membership, and only then takes the product Top-K.
const SEARCH_CANDIDATE_LIMIT = 500;
const SEARCH_RESULT_LIMIT = 50;
const SEARCH_QUALITY_K = 5;

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

function extractAssetIds(response, code = 'smart_search') {
  const items = response?.assets?.items;
  if (!Array.isArray(items) || items.length > 1000) fail(`${code}_shape_invalid`);
  const seen = new Set();
  const ids = [];
  for (const item of items) {
    const id = item?.id;
    if (typeof id !== 'string' || !UUID_V4.test(id) || seen.has(id)) fail(`${code}_asset_invalid`);
    seen.add(id);
    ids.push(id.toLowerCase());
  }
  return ids;
}

function classifySearchQuality(meanPrecisionAt5, top5HitRate) {
  if (!Number.isFinite(meanPrecisionAt5) || !Number.isFinite(top5HitRate)) fail('search_quality_metric_invalid');
  if (meanPrecisionAt5 >= 0.8 && top5HitRate >= 0.9) return 'EXCELLENT';
  if (meanPrecisionAt5 >= 0.5 && top5HitRate >= 0.75) return 'GOOD';
  if (meanPrecisionAt5 >= 0.2 && top5HitRate >= 0.5) return 'FAIR';
  return 'POOR';
}

function measureSearchQuality(searchResults, assetFixtureMeta) {
  const groups = { zh: [], en: [] };
  for (const result of searchResults) {
    const specification = RELEVANCE[result.name];
    if (!specification || !Array.isArray(result.asset_ids)) fail('search_quality_specification_invalid');
    const relevantKinds = new Set(specification.relevant);
    const acceptableKinds = new Set(specification.acceptable);
    const expectedRelevant = Array.from(assetFixtureMeta.values()).filter((item) => relevantKinds.has(item.fixture_kind)).length;
    if (expectedRelevant < 1) fail('search_quality_expected_relevant_missing');
    const top = result.asset_ids.slice(0, SEARCH_QUALITY_K);
    let relevantHits = 0;
    let acceptableHits = 0;
    for (const assetId of top) {
      const fixture = assetFixtureMeta.get(assetId);
      if (!fixture) continue;
      if (relevantKinds.has(fixture.fixture_kind)) relevantHits += 1;
      else if (acceptableKinds.has(fixture.fixture_kind)) acceptableHits += 1;
    }
    const metric = {
      name: result.name,
      result_count: result.asset_ids.length,
      expected_relevant: expectedRelevant,
      precision_at_5: relevantHits / SEARCH_QUALITY_K,
      recall_at_5: relevantHits / expectedRelevant,
      top_5_hit: relevantHits > 0,
      acceptable_at_5: acceptableHits,
    };
    groups[specification.language].push(metric);
  }
  const summarize = (entries) => {
    if (!Array.isArray(entries) || entries.length < 1) fail('search_quality_language_missing');
    const meanPrecisionAt5 = entries.reduce((sum, item) => sum + item.precision_at_5, 0) / entries.length;
    const meanRecallAt5 = entries.reduce((sum, item) => sum + item.recall_at_5, 0) / entries.length;
    const top5HitRate = entries.filter((item) => item.top_5_hit).length / entries.length;
    return {
      queries: entries,
      mean_precision_at_5: meanPrecisionAt5,
      mean_recall_at_5: meanRecallAt5,
      top_5_hit_rate: top5HitRate,
      quality: classifySearchQuality(meanPrecisionAt5, top5HitRate),
    };
  };
  return { version: 1, top_k: SEARCH_QUALITY_K, zh: summarize(groups.zh), en: summarize(groups.en) };
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

function queueIsActive(statistics, code) {
  if (!statistics || typeof statistics !== 'object') fail(`${code}_shape_invalid`);
  const values = ['active', 'completed', 'delayed', 'failed', 'paused', 'waiting'].map((key) => statistics[key]);
  if (!values.every(Number.isSafeInteger) || statistics.failed > 0) fail(`${code}_statistics_invalid`);
  return statistics.active > 0 || statistics.waiting > 0 || statistics.delayed > 0;
}

async function startQueueIfIdle(accessToken, name) {
  // Library scanning and face refresh can enqueue follow-up jobs themselves.
  // Do not turn that valid, already-active real pipeline into a false 400
  // failure by issuing a duplicate legacy queue-start request.
  const before = await request(`queue_before_${name}`, `/queues/${name}`, 'GET', undefined, accessToken);
  if (queueIsActive(before?.statistics, `queue_before_${name}`)) return;
  let response;
  try {
    response = await fetch(`http://127.0.0.1:2283/api/jobs/${name}`, {
      method: 'PUT',
      headers: { accept: 'application/json', 'content-type': 'application/json', authorization: `Bearer ${accessToken}` },
      body: JSON.stringify({ command: 'start', force: true }),
      redirect: 'error',
      signal: AbortSignal.timeout(15_000),
    });
  } catch {
    fail(`run_${name}_transport`);
  }
  if (response.ok) return;
  // A producer can enqueue the queue after the GET above.  v3.1.0 returns
  // 400 for that duplicate start; accept it only after a fresh API read proves
  // the intended queue is genuinely active.  Any other response remains a
  // fail-closed test failure.
  if (response.status === 400) {
    const after = await request(`queue_after_${name}`, `/queues/${name}`, 'GET', undefined, accessToken);
    if (queueIsActive(after?.statistics, `queue_after_${name}`)) return;
  }
  fail(`run_${name}_http_${response.status}`);
}

let input;
const runtimeMetrics = { assets: 0, people: 0 };
if (process.argv.length !== 4) {
  console.error('IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=input_argv_length_invalid');
  process.exit(1);
}
if (process.argv[2] !== '--input-file') {
  console.error('IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=input_flag_invalid');
  process.exit(1);
}
if (process.argv[3] !== INPUT_PATH) {
  console.error('IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=input_path_invalid');
  process.exit(1);
}
try {
  assertPrivateFile(INPUT_PATH, 'input_file_invalid');
} catch (error) {
  const code = error instanceof FixtureError ? error.code : 'input_file_invalid';
  console.error(`IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}
try {
  input = JSON.parse(readFileSync(INPUT_PATH, 'utf8'));
} catch {
  console.error('IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=input_json_invalid');
  process.exit(1);
}

try {
  if (!exactKeys(input, ['catalog', 'email', 'expectedCatalogAssets', 'libraryName', 'name', 'password', 'photos', 'version'])) fail('input_keys_invalid');
  if (input.version !== 1) fail('input_version_invalid');
  if (!Array.isArray(input.photos) || input.photos.length < 12 || input.photos.length > 500) fail('input_photos_invalid');
  if (!Array.isArray(input.catalog) || input.catalog.length < input.photos.length || input.catalog.length > 1000) fail('input_catalog_invalid');
  if (!Number.isSafeInteger(input.expectedCatalogAssets) || input.expectedCatalogAssets !== input.catalog.length || input.expectedCatalogAssets > 1000) fail('input_catalog_count_invalid');
  const email = text(input.email, 'email_invalid', 8, 190, /^[A-Za-z0-9._+-]+@synthetic\.invalid$/);
  const password = text(input.password, 'password_invalid', 24, 190, /^[A-Za-z0-9._~-]+$/);
  const name = text(input.name, 'name_invalid', 3, 190);
  const libraryName = text(input.libraryName, 'library_name_invalid', 3, 190);
  const photos = [];
  const canonicalIds = new Set();
  const eras = new Set();
  for (const photo of input.photos) {
    if (!exactKeys(photo, ['class_photo_id', 'era', 'fixture_kind', 'fixture_subject', 'media_reference'])) fail('photo_shape_invalid');
    const classPhotoId = uuid(photo.class_photo_id, 'class_photo_id_invalid');
    const era = text(photo.era, 'photo_era_invalid', 6, 9, /^(HERITAGE|LIVING)$/);
    const reference = mediaReference(photo.media_reference, 'media_reference_invalid');
    if (canonicalIds.has(classPhotoId)) fail('photo_duplicate');
    canonicalIds.add(classPhotoId);
    eras.add(era);
    const fixtureKind = text(photo.fixture_kind, 'fixture_kind_invalid', 3, 32, /^(PORTRAIT|PLAYGROUND|CLASSROOM|NIGHT|OUTDOOR)$/);
    const fixtureSubject = text(photo.fixture_subject, 'fixture_subject_invalid', 1, 16, /^(A|B|C|SCENE)$/);
    photos.push({ class_photo_id: classPhotoId, era, media_reference: reference, fixture_kind: fixtureKind, fixture_subject: fixtureSubject });
  }
  if (!eras.has('HERITAGE') || !eras.has('LIVING')) fail('photo_era_invalid');

  // The face fixture is deliberately only 32 images, while the isolated
  // technical library reads the complete canonical 72+32 corpus. Bind every
  // indexed canonical photo so a Gateway People/Search request does not turn
  // a partial external index into a silently partial aggregation. The Piwigo
  // side restores these temporary bindings exactly during fixture cleanup.
  const catalog = [];
  const catalogIds = new Set();
  const fixtureById = new Map(photos.map((photo) => [photo.class_photo_id, photo]));
  for (const photo of input.catalog) {
    if (!exactKeys(photo, ['class_photo_id', 'era', 'media_reference'])) fail('catalog_photo_shape_invalid');
    const classPhotoId = uuid(photo.class_photo_id, 'catalog_class_photo_id_invalid');
    const era = text(photo.era, 'catalog_era_invalid', 6, 9, /^(HERITAGE|LIVING)$/);
    const reference = mediaReference(photo.media_reference, 'catalog_media_reference_invalid');
    if (catalogIds.has(classPhotoId)) fail('catalog_photo_duplicate');
    catalogIds.add(classPhotoId);
    const fixture = fixtureById.get(classPhotoId);
    if (fixture && (fixture.era !== era || fixture.media_reference !== reference)) fail('catalog_fixture_mismatch');
    catalog.push({ class_photo_id: classPhotoId, era, media_reference: reference });
  }
  if (catalog.length !== input.expectedCatalogAssets || photos.some((photo) => !catalogIds.has(photo.class_photo_id))) fail('catalog_fixture_missing');

  const started = Date.now();
  const admin = await request('admin_signup', '/auth/admin-sign-up', 'POST', { email, password, name });
  const adminId = uuid(admin?.id, 'admin_id_invalid');
  const login = await request('technical_login', '/auth/login', 'POST', { email, password });
  const accessToken = text(login?.accessToken, 'access_token_invalid', 32, 8192, /^[A-Za-z0-9._~-]+$/);
  if (login?.isAdmin !== true || login?.userId !== adminId) fail('technical_login_identity_invalid');

  // The upstream default is intentionally general-purpose.  These three
  // fictional, near-identical-per-subject crops need a tighter deterministic
  // cluster boundary so two distinct synthetic male portraits cannot be
  // merged merely because they share broad visual traits.  This exists only
  // in the disposable technical-user Immich database; ClassIdentity and
  // ClassArchivePolicy do not consume or trust this setting.
  const systemConfig = await request('system_config_get', '/system-config', 'GET', undefined, accessToken);
  if (!systemConfig?.machineLearning?.facialRecognition || typeof systemConfig.machineLearning.facialRecognition !== 'object') {
    fail('system_config_shape_invalid');
  }
  systemConfig.machineLearning.facialRecognition.maxDistance = 0.35;
  systemConfig.machineLearning.facialRecognition.minFaces = 3;
  const updatedSystemConfig = await request('system_config_update', '/system-config', 'PUT', systemConfig, accessToken);
  if (
    updatedSystemConfig?.machineLearning?.facialRecognition?.maxDistance !== 0.35
    || updatedSystemConfig?.machineLearning?.facialRecognition?.minFaces !== 3
  ) {
    fail('system_config_update_invalid');
  }

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
  let libraryAssetCount = 0;
  for (let attempt = 0; attempt < 180; attempt += 1) {
    const stats = await request('library_statistics', `/libraries/${libraryId}/statistics`, 'GET', undefined, accessToken);
    if (!Number.isSafeInteger(stats?.total) || stats.total < 0 || stats.total > 1000) fail('library_statistics_invalid');
    libraryAssetCount = stats.total;
    if (libraryAssetCount > input.expectedCatalogAssets) fail('library_scan_unexpected_assets');
    if (libraryAssetCount === input.expectedCatalogAssets) {
      indexed = true;
      break;
    }
    await delay(1_000);
  }
  if (!indexed) fail('library_scan_incomplete');

  const bindings = [];
  for (const photo of catalog) {
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
  if (new Set(bindings.map((item) => item.immich_asset_id)).size !== catalog.length) fail('asset_binding_duplicate');

  const bindingByClassPhotoId = new Map(bindings.map((item) => [item.class_photo_id, item]));
  const fixtureBindings = photos.map((photo) => bindingByClassPhotoId.get(photo.class_photo_id));
  if (fixtureBindings.some((item) => !item) || fixtureBindings.length !== photos.length) fail('fixture_asset_binding_missing');
  const fixtureAssetIds = fixtureBindings.map((item) => item.immich_asset_id);
  const fixtureAssetMeta = new Map();
  for (const photo of photos) {
    const binding = bindingByClassPhotoId.get(photo.class_photo_id);
    if (!binding || fixtureAssetMeta.has(binding.immich_asset_id)) fail('fixture_asset_metadata_invalid');
    fixtureAssetMeta.set(binding.immich_asset_id, { fixture_kind: photo.fixture_kind, fixture_subject: photo.fixture_subject, era: photo.era });
  }
  runtimeMetrics.assets = fixtureAssetIds.length;
  const faceStarted = Date.now();
  await request('refresh_faces', '/assets/jobs', 'POST', { assetIds: fixtureAssetIds, name: 'refresh-faces' }, accessToken);
  const faceJobs = await waitForQueue(accessToken, 'faceDetection');
  const faceMilliseconds = Date.now() - faceStarted;
  const recognitionStarted = Date.now();
  await startQueueIfIdle(accessToken, 'facialRecognition');
  const recognitionJobs = await waitForQueue(accessToken, 'facialRecognition');
  const recognitionMilliseconds = Date.now() - recognitionStarted;
  const smartIndexStarted = Date.now();
  await startQueueIfIdle(accessToken, 'smartSearch');
  const smartJobs = await waitForQueue(accessToken, 'smartSearch');
  const smartIndexMilliseconds = Date.now() - smartIndexStarted;

  const peopleQueryStarted = Date.now();
  const people = await request('people', '/people?size=500&withHidden=false', 'GET', undefined, accessToken);
  runtimeMetrics.people = Array.isArray(people?.people) ? people.people.length : 0;
  if (!Array.isArray(people?.people) || people.people.length < 3 || people.people.length > 500) fail('face_clustering_incomplete');

  const personMemberships = [];
  for (const person of people.people) {
    const personId = uuid(person?.id, 'person_id_invalid');
    const result = await request('person_assets', '/search/metadata', 'POST', {
      personIds: [personId], page: 1, size: 1000,
    }, accessToken);
    const members = extractAssetIds(result, 'person_assets');
    if (members.length < 1) fail('person_assets_empty');
    personMemberships.push({ immich_person_id: personId, asset_ids: members });
  }
  const peopleQueryMilliseconds = Date.now() - peopleQueryStarted;

  const smartQueryStarted = Date.now();
  const searchResults = [];
  for (const [nameKey, query] of QUERIES) {
    const result = await request(`smart_${nameKey}`, '/search/smart', 'POST', { query, page: 1, size: SEARCH_CANDIDATE_LIMIT }, accessToken);
    const ids = extractAssetIds(result);
    searchResults.push({ name: nameKey, query, asset_ids: ids, count: ids.length });
  }
  if (searchResults.every((item) => item.count === 0)) fail('smart_search_empty');
  const smartQueryMilliseconds = Date.now() - smartQueryStarted;
  const searchQuality = measureSearchQuality(searchResults, fixtureAssetMeta);

  const runtime = {
    face_jobs: faceJobs,
    library_asset_count: libraryAssetCount,
    person_count: people.people.length,
    recognition_jobs: recognitionJobs,
    search_results: searchResults,
    search_candidate_limit: SEARCH_CANDIDATE_LIMIT,
    search_result_limit: SEARCH_RESULT_LIMIT,
    search_quality: searchQuality,
    smart_jobs: smartJobs,
    timings_ms: {
      face_detection: faceMilliseconds,
      face_recognition: recognitionMilliseconds,
      smart_search_index: smartIndexMilliseconds,
      people_query: peopleQueryMilliseconds,
      smart_search_queries: smartQueryMilliseconds,
    },
    total_milliseconds: Date.now() - started,
  };
  const output = JSON.stringify({ version: 1, access_token: accessToken, assets: bindings, fixture_assets: fixtureBindings, people: personMemberships, runtime });
  writeFileSync(OUTPUT_PATH, output, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
  chmodSync(OUTPUT_PATH, 0o600);
  assertPrivateFile(OUTPUT_PATH, 'output_file_invalid');
  console.log(`IMMICH_PEOPLE_SEARCH_RUNTIME=PASS assets=${fixtureBindings.length} catalog_assets=${bindings.length} people=${runtime.person_count} face_jobs=${faceJobs} recognition_jobs=${recognitionJobs} smart_jobs=${smartJobs}`);
  console.log(`IMMICH_SMART_SEARCH_QUALITY=TESTED chinese=${searchQuality.zh.quality} english=${searchQuality.en.quality} top_k=${searchQuality.top_k}`);
  console.log(`IMMICH_SMART_SEARCH_METRICS=RUNTIME_TESTED zh_p5=${searchQuality.zh.mean_precision_at_5.toFixed(3)} zh_r5=${searchQuality.zh.mean_recall_at_5.toFixed(3)} zh_hit5=${searchQuality.zh.top_5_hit_rate.toFixed(3)} en_p5=${searchQuality.en.mean_precision_at_5.toFixed(3)} en_r5=${searchQuality.en.mean_recall_at_5.toFixed(3)} en_hit5=${searchQuality.en.top_5_hit_rate.toFixed(3)}`);
  console.log(`IMMICH_ML_RUNTIME_METRICS=RUNTIME_TESTED face_detection_ms=${faceMilliseconds} face_recognition_ms=${recognitionMilliseconds} smart_index_ms=${smartIndexMilliseconds} people_query_ms=${peopleQueryMilliseconds} smart_queries_ms=${smartQueryMilliseconds} total_ms=${runtime.total_milliseconds}`);
  process.exit(0);
} catch (error) {
  const code = error instanceof FixtureError ? error.code : 'unexpected';
  // Counts are synthetic-run diagnostics only: no asset, person, user, path
  // or token identifier is written to stdout/stderr.
  console.error(`IMMICH_PEOPLE_SEARCH_RUNTIME_METRICS=assets=${runtimeMetrics.assets} people=${runtimeMetrics.people}`);
  console.error(`IMMICH_PEOPLE_SEARCH_RUNTIME=FAIL reason=${code}`);
  process.exit(1);
}
