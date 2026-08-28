import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

// This is intentionally a separate Chrome gate.  It proves that every V4
// read projection is scoped by the server, instead of expanding the primary
// browser, deep-browser, or upload lifecycle runners.
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

let assertions = 0;
let screenshots = 0;
let chromeVersion = 'unknown';
let stage = 'initialization';

function fail(code) { throw new GateError(code); }
function check(value, code) { assertions += 1; if (!value) fail(code); }
function stageAt(value) { stage = value; process.stdout.write(`V4_SCOPE_STAGE=${value}\n`); }

const settings = Object.freeze({
  piwigo: process.env.CLASS_ARCHIVE_V4_SCOPE_PIWIGO_ORIGIN,
  photos: process.env.CLASS_ARCHIVE_V4_SCOPE_PHOTO_ORIGIN,
  credentials: process.env.CLASS_ARCHIVE_V4_SCOPE_CREDENTIAL_FILE,
  truth: process.env.CLASS_ARCHIVE_V4_SCOPE_TRUTH_FILE,
  userDataRoot: process.env.CLASS_ARCHIVE_V4_SCOPE_USER_DATA_ROOT,
  screenshots: process.env.CLASS_ARCHIVE_V4_SCOPE_SCREENSHOT_DIR,
  requirePeople: process.env.CLASS_ARCHIVE_V4_SCOPE_REQUIRE_PEOPLE !== '0',
});

const roles = Object.freeze(['classmate', 'family', 'teacher', 'anonymous']);
const fullRoles = Object.freeze(['classmate', 'teacher', 'anonymous']);
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function localOrigin(value, port, code) {
  let url;
  try { url = new URL(value); } catch { fail(code); }
  check(url.protocol === 'http:' && url.hostname === '127.0.0.1' && url.port === String(port)
    && url.pathname === '/' && !url.username && !url.password && !url.search && !url.hash, code);
  return url.toString();
}

function privatePath(value, code) {
  check(typeof value === 'string' && path.isAbsolute(value) && !value.includes('\0'), code);
  return path.resolve(value);
}

function child(root, name, code) {
  const base = privatePath(root, code);
  const target = path.resolve(base, name);
  check(target.startsWith(`${base}${path.sep}`), code);
  return target;
}

function readCredentials() {
  let document;
  try {
    document = JSON.parse(fs.readFileSync(privatePath(settings.credentials, 'credential_path'), 'utf8'));
  } catch {
    fail('credential_document');
  }
  check(document?.version === 1 && document.environment === 'synthetic', 'credential_shape');
  check(Object.keys(document.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_roles');
  for (const role of roles) {
    const credential = document.roles[role];
    check(typeof credential?.username === 'string' && credential.username.length > 0 && credential.username.length <= 190,
      `credential_${role}_username`);
    check(typeof credential?.password === 'string' && credential.password.length >= 24 && credential.password.length <= 190,
      `credential_${role}_password`);
  }
  check(typeof document.familyDeniedPhotoId === 'string' && uuid.test(document.familyDeniedPhotoId), 'family_denied_photo_id');
  return document;
}

function readScopeTruth() {
  let document;
  try {
    document = JSON.parse(fs.readFileSync(privatePath(settings.truth, 'scope_truth_path'), 'utf8'));
  } catch {
    fail('scope_truth_document');
  }
  check(document?.version === 1 && document.environment === 'synthetic', 'scope_truth_scope');
  check(Object.keys(document ?? {}).sort().join(',') === 'environment,heritagePhotoIds,livingPhotoIds,unknownArchivePhotoIds,unknownPhotoId,version', 'scope_truth_shape');
  const heritage = uniqueIds(document.heritagePhotoIds, 'scope_truth_heritage');
  const living = uniqueIds(document.livingPhotoIds, 'scope_truth_living');
  const unknownArchive = uniqueIds(document.unknownArchivePhotoIds, 'scope_truth_unknown_archive');
  const unknown = canonicalId(document.unknownPhotoId, 'scope_truth_unknown');
  check(heritage.size + living.size === 72, 'scope_truth_synthetic_catalog_total');
  check(living.has(unknown), 'scope_truth_unknown_must_be_living');
  check(!heritage.has(unknown), 'scope_truth_unknown_cross_era');
  for (const id of heritage) check(!living.has(id), 'scope_truth_cross_era');
  check(heritage.size > 0 && living.size > 1, 'scope_truth_both_eras');
  check(unknownArchive.size > 0 && unknownArchive.has(unknown), 'scope_truth_unknown_archive_fixture');
  for (const id of unknownArchive) check(heritage.has(id) || living.has(id), 'scope_truth_unknown_archive_catalog');
  const fullVisible = new Set([...heritage, ...living].filter((id) => id !== unknown));
  check(fullVisible.size === 71 && fullVisible.size + 1 === heritage.size + living.size, 'scope_truth_unknown_remove');
  return { heritage, living, unknownArchive, unknown, fullVisible };
}

function allowed(url) {
  return ['data:', 'blob:', 'about:'].includes(url.protocol)
    || (url.protocol === 'http:' && url.hostname === '127.0.0.1' && ['8090', '8091'].includes(url.port));
}

async function recordChromeStableVersion(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const version = await session.send('Browser.getVersion');
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(typeof version?.product === 'string' ? version.product : '');
    check(match !== null, 'chrome_stable_product');
    chromeVersion = match[1];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally {
    await session?.detach().catch(() => null);
  }
}

async function open(role, credentials) {
  const viewport = { width: 1440, height: 900 };
  const profile = child(settings.userDataRoot, `${role}-1440x900`, 'profile_child');
  check(!fs.existsSync(profile), `profile_fresh_${role}`);
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport,
      screen: viewport,
      locale: 'zh-CN',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: [
        '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-component-update', '--disable-sync', '--no-pings',
        ...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS,
      ],
    });
    await context.route('**/*', (route) => {
      try { return allowed(new URL(route.request().url())) ? route.continue() : route.abort(); }
      catch { return route.abort(); }
    });
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStableVersion(context, page);
    await page.goto(new URL('identification.php', settings.piwigo).toString(), { waitUntil: 'domcontentloaded', timeout: 30_000 });
    const form = page.locator('form[name="login_form"]');
    check(await form.count() === 1, `login_form_${role}`);
    await form.locator('input[name="username"]').fill(credentials.roles[role].username);
    await form.locator('input[name="password"]').fill(credentials.roles[role].password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
      form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
    ]);
    await page.goto(new URL('/home', settings.photos).toString(), { waitUntil: 'networkidle', timeout: 30_000 });
    await page.locator('[data-photo-app="true"]').waitFor({ timeout: 15_000 });
    return { context, page };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(context === null ? `chrome_stable_launch_${role}` : `chrome_session_${role}`);
  }
}

async function save(page, name) {
  await page.screenshot({ path: child(settings.screenshots, `${name}.png`, 'screenshot_child'), fullPage: true });
  screenshots += 1;
}

async function browserFetch(page, target) {
  const result = await page.evaluate(async (relative) => {
    try {
      const response = await fetch(relative, { credentials: 'same-origin', cache: 'no-store' });
      const raw = await response.text();
      let json = null;
      try { json = JSON.parse(raw); } catch { /* a non-JSON error remains bounded to its HTTP code */ }
      return { status: response.status, json };
    } catch {
      return { status: 0, json: null };
    }
  }, target);
  check(Number.isInteger(result?.status), 'browser_fetch_shape');
  return result;
}

async function requiredJson(page, target, code) {
  const result = await browserFetch(page, target);
  if (!(result.status === 200 && result.json !== null && typeof result.json === 'object' && !Array.isArray(result.json))) {
    // A bounded HTTP status is safe diagnostic evidence and distinguishes a
    // policy/API availability failure from a response-shape failure without
    // recording any URL, body, identifier, or session material.
    fail(`${code}_http_${Number.isInteger(result.status) ? result.status : 0}`);
  }
  return result.json;
}

function canonicalId(value, code) {
  check(typeof value === 'string' && uuid.test(value), code);
  return value.toLowerCase();
}

function uniqueIds(values, code) {
  check(Array.isArray(values), code);
  const set = new Set();
  for (const value of values) {
    const id = canonicalId(value, code);
    check(!set.has(id), `${code}_duplicate`);
    set.add(id);
  }
  return set;
}

async function timelineCatalog(page, label) {
  const ids = new Set();
  const cursors = new Set();
  let cursor = null;
  let expectedTotal = null;
  let pages = 0;
  let firstPayload = null;
  do {
    pages += 1;
    check(pages <= 4, `${label}_timeline_page_budget`);
    const suffix = cursor === null ? '?limit=240' : `?limit=240&cursor=${encodeURIComponent(cursor)}`;
    const payload = await requiredJson(page, `/api/class-archive/timeline${suffix}`, `${label}_timeline_status`);
    if (firstPayload === null) firstPayload = payload;
    check(Number.isInteger(payload?.total) && payload.total >= 0 && Number.isInteger(payload?.count)
      && payload.count >= 0 && payload.count <= 240 && Array.isArray(payload?.groups)
      && typeof payload?.hasMore === 'boolean', `${label}_timeline_shape`);
    if (expectedTotal === null) expectedTotal = payload.total;
    check(payload.total === expectedTotal, `${label}_timeline_total_stable`);
    let pageCount = 0;
    for (const group of payload.groups) {
      check(Number.isInteger(group?.count) && group.count >= 0 && Array.isArray(group?.items)
        && group.items.length === group.count, `${label}_timeline_group_shape`);
      for (const photo of group.items) {
        const id = canonicalId(photo?.id, `${label}_timeline_photo`);
        check(!ids.has(id), `${label}_timeline_duplicate`);
        ids.add(id);
        pageCount += 1;
      }
    }
    check(pageCount === payload.count, `${label}_timeline_page_count`);
    if (!payload.hasMore) {
      check(payload.nextCursor === null, `${label}_timeline_terminal_cursor`);
      break;
    }
    check(typeof payload.nextCursor === 'string' && /^[A-Za-z0-9_-]{48}$/.test(payload.nextCursor)
      && !cursors.has(payload.nextCursor), `${label}_timeline_cursor`);
    cursors.add(payload.nextCursor);
    cursor = payload.nextCursor;
  } while (true);
  check(expectedTotal !== null && ids.size === expectedTotal, `${label}_timeline_catalog_complete`);
  return { ids, payload: firstPayload };
}

function recursiveStrings(value, visit, depth = 0) {
  check(depth <= 64, 'projection_json_depth');
  if (typeof value === 'string') { visit(value); return; }
  if (value === null || typeof value !== 'object') return;
  if (Array.isArray(value)) {
    for (const item of value) recursiveStrings(item, visit, depth + 1);
    return;
  }
  for (const item of Object.values(value)) recursiveStrings(item, visit, depth + 1);
}

function assertNoKnownLiving(payload, knownLivingIds, label) {
  let found = false;
  recursiveStrings(payload, (value) => { if (knownLivingIds.has(value.toLowerCase())) found = true; });
  check(!found, `${label}_living_id_leak`);
}

function assertNoForbiddenPhoto(payload, forbiddenPhotoId, label) {
  let found = false;
  recursiveStrings(payload, (value) => { if (value.toLowerCase() === forbiddenPhotoId) found = true; });
  check(!found, `${label}_unknown_photo_leak`);
}

function assertCardShape(item, allowedIds, label) {
  check(item !== null && typeof item === 'object' && !Array.isArray(item), `${label}_card_object`);
  check(Object.hasOwn(item, 'photoIds'), `${label}_photo_membership_missing`);
  const ids = uniqueIds(item.photoIds, `${label}_photo_ids`);
  check(Number.isInteger(item.photoCount) && item.photoCount === ids.size, `${label}_photo_count`);
  for (const id of ids) check(allowedIds.has(id), `${label}_photo_scope`);
  if (item.coverPhotoId !== null) {
    const cover = canonicalId(item.coverPhotoId, `${label}_cover`);
    check(ids.has(cover) && allowedIds.has(cover), `${label}_cover_scope`);
  }
}

function assertCollectionSnapshot(payload, role, allowedIds, label) {
  check(payload?.scope === (role === 'family' ? 'HERITAGE_ONLY' : 'FULL'), `${label}_scope`);
  check(Array.isArray(payload?.items) && payload.items.length > 0 && payload.items.length <= 1000, `${label}_items`);
  for (const item of payload.items) assertCardShape(item, allowedIds, `${label}_item`);
  for (const entry of (payload?.preferences?.hidden ?? [])) {
    check(entry?.item && typeof entry.item === 'object', `${label}_hidden_shape`);
    assertCardShape(entry.item, allowedIds, `${label}_hidden_item`);
  }
}

function cardPhotoIds(payload) {
  const ids = new Set();
  for (const item of (payload?.items ?? [])) {
    for (const id of (item?.photoIds ?? [])) if (typeof id === 'string' && uuid.test(id)) ids.add(id.toLowerCase());
  }
  return ids;
}

function assertPins(payload, allowedIds, label) {
  check(Array.isArray(payload?.items) && payload.items.length <= 100, `${label}_items`);
  for (const [index, pin] of payload.items.entries()) {
    check(Number.isInteger(pin?.ordinal) && pin.ordinal === index, `${label}_ordinal`);
    check(pin?.item && typeof pin.item === 'object', `${label}_item`);
    assertCardShape(pin.item, allowedIds, `${label}_item_scope`);
  }
}

function assertAlbumList(payload, allowedIds, label) {
  check(Number.isInteger(payload?.total) && payload.total >= 0 && Array.isArray(payload?.items)
    && payload.total === payload.items.length, `${label}_shape`);
  const albums = new Map();
  for (const album of payload.items) {
    const id = canonicalId(album?.id, `${label}_id`);
    check(!albums.has(id), `${label}_duplicate`);
    const cover = album?.coverPhotoId ?? album?.cover_photo_id ?? null;
    if (cover !== null) check(allowedIds.has(canonicalId(cover, `${label}_cover`)), `${label}_cover_scope`);
    const total = album?.total ?? album?.assetCount ?? album?.photoCount ?? album?.photo_count
      ?? album?.count ?? album?.directCount ?? album?.direct_count;
    check(Number.isInteger(total) && total >= 0, `${label}_count`);
    albums.set(id, { total, cover });
  }
  return albums;
}

function assertPeopleList(payload, allowedIds, label) {
  // `/api/people` is the intentionally bounded Immich-compatible public
  // list.  The Class Archive detail projection remains separate below.
  check(payload?.hasNextPage === false && Number.isInteger(payload?.hidden) && payload.hidden === 0
    && Number.isInteger(payload?.total) && payload.total >= 0
    && Array.isArray(payload?.people) && payload.total === payload.people.length, `${label}_shape`);
  const people = new Map();
  for (const person of payload.people) {
    const id = canonicalId(person?.id, `${label}_id`);
    check(!people.has(id), `${label}_duplicate`);
    const count = person?.photoCount ?? person?.photo_count ?? person?.total;
    check(Number.isInteger(count) && count >= 1, `${label}_count`);
    const cover = person?.coverPhotoId ?? person?.cover_photo_id;
    check(cover === null || cover === undefined || allowedIds.has(canonicalId(cover, `${label}_cover`)), `${label}_cover_scope`);
    people.set(id, { count, cover: cover ?? null });
  }
  return people;
}

function assertSpotlight(payload, allowedIds, label) {
  check(typeof payload?.active === 'boolean' && Number.isInteger(payload?.total) && payload.total >= 0
    && Array.isArray(payload?.items), `${label}_shape`);
  check(payload.active ? payload.total === payload.items.length : payload.total === 0 && payload.items.length === 0, `${label}_count`);
  for (const item of payload.items) {
    check(allowedIds.has(canonicalId(item?.coverPhotoId, `${label}_cover`)), `${label}_cover_scope`);
  }
}

function assertGroupedSearch(payload, allowedIds, label) {
  check(typeof payload?.query === 'string' && typeof payload?.partial === 'boolean'
    && payload?.photos && typeof payload.photos === 'object' && Array.isArray(payload.photos.items)
    && Number.isInteger(payload.photos.total) && Number.isInteger(payload.photos.count)
    && Number.isInteger(payload.photos.limit) && payload.photos.limit === 120
    && payload.photos.count === payload.photos.items.length
    && payload.photos.total === payload.photos.items.length,
  `${label}_shape`);
  for (const photo of payload.photos.items) check(allowedIds.has(canonicalId(photo?.id, `${label}_photo`)), `${label}_photo_scope`);
  for (const sectionName of ['people', 'albums', 'events', 'archiveTime', 'semantic']) {
    const section = payload[sectionName];
    check(section && typeof section.available === 'boolean' && Number.isInteger(section.total) && section.total >= 0
      && Array.isArray(section.items) && section.total >= section.items.length && section.items.length <= 12, `${label}_${sectionName}_shape`);
    for (const item of section.items) {
      const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
      if (cover !== null) check(allowedIds.has(canonicalId(cover, `${label}_${sectionName}_cover`)), `${label}_${sectionName}_cover_scope`);
      const count = item?.photoCount ?? item?.photo_count ?? item?.total ?? item?.count;
      if (count !== undefined) check(Number.isInteger(count) && count >= 0, `${label}_${sectionName}_count`);
      if (sectionName === 'semantic') check(allowedIds.has(canonicalId(item?.id, `${label}_semantic_photo`)), `${label}_semantic_scope`);
    }
  }
  return payload;
}

function assertSuggestions(payload, allowedIds, label) {
  check(payload !== null && typeof payload === 'object' && !Array.isArray(payload), `${label}_shape`);
  for (const category of ['people', 'albums', 'events', 'archiveTime']) {
    const group = payload[category];
    check(group && Number.isInteger(group.total) && group.total >= 0 && Array.isArray(group.items)
      && group.total >= group.items.length && group.items.length <= 12, `${label}_${category}`);
    for (const item of group.items) {
      const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
      if (cover !== null) check(allowedIds.has(canonicalId(cover, `${label}_${category}_cover`)), `${label}_${category}_cover_scope`);
    }
  }
}

function assertUnknownArchiveAggregate(payload, allowedIds, unknownArchiveIds, label) {
  const expected = [...unknownArchiveIds].filter((id) => allowedIds.has(id)).length;
  const section = payload?.archiveTime;
  const matches = Array.isArray(section?.items)
    ? section.items.filter((item) => item?.label === '日期未知' && item?.kind === 'UNKNOWN')
    : [];
  check(expected > 0 && matches.length === 1, `${label}_bucket`);
  check(matches[0].photoCount === expected, `${label}_count`);
}

function idSet(entries) { return entries instanceof Set ? new Set(entries) : new Set(entries.keys()); }
function setEquals(left, right) { return left.size === right.size && [...left].every((value) => right.has(value)); }

async function inspectRole(page, role, allowedIds, knownLivingIds, unknownArchiveIds, unknownPhotoId, query) {
  stageAt(`${role}_projection_reads`);
  const state = await requiredJson(page, '/api/class-archive/product-state', `${role}_state_status`);
  check(state.role === role.toUpperCase(), `${role}_state_role`);
  const timelineRead = await timelineCatalog(page, role);
  const timeline = timelineRead.payload;
  check(timelineRead.ids.size === allowedIds.size, `${role}_timeline_total`);
  check(setEquals(timelineRead.ids, allowedIds), `${role}_timeline_exact`);
  const home = await requiredJson(page, '/api/class-archive/collections/home', `${role}_home_status`);
  const pins = await requiredJson(page, '/api/class-archive/collections/pins', `${role}_pins_status`);
  const albumsPayload = await requiredJson(page, '/api/class-archive/albums', `${role}_albums_status`);
  const peoplePayload = await requiredJson(page, '/api/people', `${role}_people_status`);
  const spotlight = await requiredJson(page, '/api/class-archive/spotlight', `${role}_spotlight_status`);
  const suggestions = await requiredJson(page, '/api/class-archive/search/suggestions?q=%E8%AE%B0%E5%BF%86', `${role}_suggestions_status`);
  const grouped = await requiredJson(page, `/api/class-archive/search/grouped?q=${encodeURIComponent(query)}&contextType=ALL&limit=120`, `${role}_search_status`);
  const unknownArchiveGrouped = await requiredJson(page, '/api/class-archive/search/grouped?q=%E6%97%A5%E6%9C%9F%E6%9C%AA%E7%9F%A5&contextType=ALL&limit=120', `${role}_unknown_archive_search_status`);

  assertCollectionSnapshot(home, role, allowedIds, `${role}_home`);
  assertPins(pins, allowedIds, `${role}_pins`);
  const albums = assertAlbumList(albumsPayload, allowedIds, `${role}_albums`);
  const people = assertPeopleList(peoplePayload, allowedIds, `${role}_people`);
  assertSpotlight(spotlight, allowedIds, `${role}_spotlight`);
  assertSuggestions(suggestions, allowedIds, `${role}_suggestions`);
  assertGroupedSearch(grouped, allowedIds, `${role}_search`);
  assertGroupedSearch(unknownArchiveGrouped, allowedIds, `${role}_unknown_archive_search`);
  assertUnknownArchiveAggregate(unknownArchiveGrouped, allowedIds, unknownArchiveIds, `${role}_unknown_archive_search`);
  if (role === 'classmate') check(grouped.photos.total === knownLivingIds.size, 'classmate_living_search_fixture');
  if (role === 'family') check(grouped.photos.total === 0, 'family_living_search_count_denied');
  if (role !== 'family') {
    const homeIds = cardPhotoIds(home);
    check([...homeIds].some((id) => knownLivingIds.has(id)), `${role}_full_home_living_card`);
  }

  // Every album/person detail must be drawn from its already scoped list and
  // rechecked again at its individual browser route.
  let albumDetailHasLiving = false;
  for (const albumId of albums.keys()) {
    const detail = await requiredJson(page, `/api/class-archive/albums/${albumId}?limit=240`, `${role}_album_detail_status`);
    assertNoForbiddenPhoto(detail, unknownPhotoId, `${role}_album_detail_unknown`);
    check(Number.isInteger(detail.total) && Array.isArray(detail.items) && detail.total === detail.items.length
      && detail.total === albums.get(albumId).total, `${role}_album_detail_shape`);
    for (const photo of detail.items) {
      const photoId = canonicalId(photo?.id, `${role}_album_detail_photo`);
      check(allowedIds.has(photoId), `${role}_album_detail_scope`);
      if (knownLivingIds.has(photoId)) albumDetailHasLiving = true;
    }
    const cover = detail.coverPhotoId ?? detail.cover_photo_id ?? null;
    if (cover !== null) check(allowedIds.has(canonicalId(cover, `${role}_album_detail_cover`)), `${role}_album_detail_cover_scope`);
    if (role === 'family') assertNoKnownLiving(detail, knownLivingIds, 'family_album_detail');
  }
  if (role !== 'family') check(albumDetailHasLiving, `${role}_full_album_living_card`);
  for (const personId of people.keys()) {
    const detail = await requiredJson(page, `/api/class-archive/people/${personId}`, `${role}_person_detail_status`);
    assertNoForbiddenPhoto(detail, unknownPhotoId, `${role}_person_detail_unknown`);
    const photoItems = detail.items ?? detail.photos;
    const count = detail.photoCount ?? detail.photo_count ?? detail.total;
    check(Array.isArray(photoItems) && Number.isInteger(count) && count === photoItems.length && count >= 1, `${role}_person_detail_shape`);
    check(people.get(personId).count === count, `${role}_person_list_detail_count`);
    for (const photo of photoItems) check(allowedIds.has(canonicalId(photo?.id, `${role}_person_detail_photo`)), `${role}_person_detail_scope`);
    const cover = detail.coverPhotoId ?? detail.cover_photo_id ?? null;
    if (cover !== null) check(allowedIds.has(canonicalId(cover, `${role}_person_detail_cover`)), `${role}_person_detail_cover_scope`);
    if (role === 'family') assertNoKnownLiving(detail, knownLivingIds, 'family_person_detail');
  }

  for (const person of grouped.people.items) {
    const personId = canonicalId(person?.id, `${role}_search_person_id`);
    const count = person?.photoCount ?? person?.photo_count ?? person?.total;
    check(Number.isInteger(count) && people.has(personId) && people.get(personId).count === count,
      `${role}_search_person_count_scope`);
  }
  for (const album of grouped.albums.items) {
    const albumId = canonicalId(album?.id, `${role}_search_album_id`);
    const count = album?.photoCount ?? album?.photo_count ?? album?.total ?? album?.count;
    check(Number.isInteger(count) && albums.has(albumId) && albums.get(albumId).total === count,
      `${role}_search_album_count_scope`);
  }

  const endpoints = { timeline, home, pins, albums: albumsPayload, people: peoplePayload, spotlight, suggestions, grouped, unknownArchiveGrouped };
  for (const [endpoint, payload] of Object.entries(endpoints)) {
    assertNoForbiddenPhoto(payload, unknownPhotoId, `${role}_${endpoint}`);
  }
  if (role === 'family') {
    const familyEndpoints = endpoints;
    for (const [endpoint, payload] of Object.entries(familyEndpoints)) {
      // This is deliberately an exact canonical-photo-id test. Snapshot,
      // album and person ids are distinct opaque domains and must not be
      // compared to the catalog's photo UUID set.
      assertNoKnownLiving(payload, knownLivingIds, `family_${endpoint}`);
    }
  }
  return { state, timeline, home, pins, albums, people, spotlight, suggestions, grouped, unknownArchiveGrouped, allowedIds };
}

async function directDeniedPhotoProbe(page, photoId) {
  const result = await browserFetch(page, `/api/assets/${photoId}`);
  check(result.status === 404, 'family_known_living_photo_denied');
}

async function unknownDeniedPhotoProbe(page, role, photoId) {
  const result = await browserFetch(page, `/api/assets/${photoId}`);
  check(result.status === 404, `${role}_unknown_photo_denied`);
}

async function verifyHomeBrowserSurface(page, role) {
  check(await page.locator('[data-photo-app="true"]').count() === 1, `${role}_home_app_shell`);
  check(await page.locator('[data-home-all-photos="true"]').count() === 1, `${role}_home_library_entry`);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  check(!overflow, `${role}_home_horizontal_overflow`);
}

async function main() {
  localOrigin(settings.piwigo, 8090, 'piwigo_origin');
  localOrigin(settings.photos, 8091, 'photo_origin');
  privatePath(settings.userDataRoot, 'profile_root');
  privatePath(settings.screenshots, 'screenshot_root');
  const credentials = readCredentials();
  const truth = readScopeTruth();
  const results = new Map();
  const heritageIds = truth.heritage;
  const livingIds = new Set([...truth.living].filter((id) => id !== truth.unknown));
  const expectedFull = truth.fullVisible;
  check(livingIds.has(credentials.familyDeniedPhotoId.toLowerCase()), 'family_denied_photo_must_be_living');

  for (const role of roles) {
    stageAt(`${role}_login`);
    const session = await open(role, credentials);
    try {
      await verifyHomeBrowserSurface(session.page, role);
      const expected = role === 'family' ? heritageIds : expectedFull;
      const inspection = await inspectRole(session.page, role, expected, livingIds, truth.unknownArchive, truth.unknown, '后来');
      results.set(role, { catalog: expected, inspection });
      if (role === 'family') await directDeniedPhotoProbe(session.page, credentials.familyDeniedPhotoId.toLowerCase());
      await unknownDeniedPhotoProbe(session.page, role, truth.unknown);
      await save(session.page, `scope-${role}-home`);
    } finally {
      await session.context.close().catch(() => null);
    }
  }

  stageAt('cross_role_projection_comparison');
  for (const role of fullRoles) {
    const actual = idSet(results.get(role).catalog);
    check(setEquals(actual, expectedFull), `${role}_full_catalog_exact`);
    check(results.get(role).inspection.home.scope === 'FULL', `${role}_full_home_scope`);
  }
  const family = results.get('family');
  check(setEquals(idSet(family.catalog), heritageIds), 'family_heritage_catalog_exact');
  check(family.inspection.home.scope === 'HERITAGE_ONLY', 'family_heritage_home_scope');
  for (const [personId, familyPerson] of family.inspection.people) {
    const fullPerson = results.get('classmate').inspection.people.get(personId);
    check(fullPerson !== undefined && familyPerson.count <= fullPerson.count, 'family_person_count_scope');
  }
  if (settings.requirePeople) {
    check(results.get('classmate').inspection.people.size > 0, 'scope_people_fixture_missing');
    check(family.inspection.people.size > 0, 'scope_family_people_fixture_missing');
  }

  // Keep summaries free from principals, photo UUIDs, paths, source names and
  // any raw HTTP payload.  Detailed evidence remains only in ignored images.
  process.stdout.write(`V4_SCOPE_PROJECTION=PASS assertions=${assertions} screenshots=${screenshots} chrome_version=${chromeVersion} people_required=${settings.requirePeople ? 'yes' : 'no'}\n`);
}

try {
  await main();
} catch (error) {
  const code = error instanceof GateError ? error.code : 'unexpected';
  process.stderr.write(`V4_SCOPE_PROJECTION=FAIL stage=${stage} assertions=${assertions} code=${code}\n`);
  process.exitCode = 1;
}
