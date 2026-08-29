/*
 * Owner-private V4 role acceptance over the four pre-existing full-v3
 * fixture principals. The companion PowerShell wrapper is the only component
 * allowed to rotate their passwords; this browser process performs no
 * successful business write and never creates an identity, seat, claim,
 * invitation, account, media, comment, or AI job.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

const roles = Object.freeze(['classmate', 'family', 'teacher', 'anonymous']);
const fullRoles = Object.freeze(['classmate', 'teacher', 'anonymous']);
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const fixtureUsernames = Object.freeze(['fixture-classmate', 'fixture-family', 'fixture-teacher', 'fixture-anonymous']);
const forbiddenIdentityKeys = new Set([
  'classmateid', 'classmate_id', 'identityid', 'identity_id', 'seatid', 'seat_id',
  'accountid', 'account_id', 'userid', 'user_id', 'underlyinguserid', 'underlying_user_id',
  'principalid', 'principal_id', 'pseudonymsubject', 'pseudonym_subject',
]);

let assertions = 0;
let screenshots = 0;
let stage = 'initialization';
let chromeVersion = 'unknown';
let successfulBusinessWrites = 0;
const unexpectedNetwork = new Set();
const forbiddenBusinessMutations = new Set();

function fail(code) { throw new GateError(code); }
function check(value, code) { assertions += 1; if (!value) fail(code); }
function stageAt(value) { stage = value; process.stdout.write(`V4_OWNER_EXISTING_FIXTURE_STAGE=${value}\n`); }
function setting(name, pattern) {
  const value = process.env[name] ?? '';
  check(pattern.test(value), `setting_${name.toLowerCase()}_invalid`);
  return value;
}
function localOrigin(name, port) {
  let value;
  try { value = new URL(setting(name, /^http:\/\/127\.0\.0\.1:[0-9]{2,5}\/$/)); }
  catch { fail(`setting_${name.toLowerCase()}_invalid`); }
  check(value.protocol === 'http:' && value.hostname === '127.0.0.1' && value.port === String(port)
    && value.pathname === '/' && !value.username && !value.password && !value.search && !value.hash,
  `setting_${name.toLowerCase()}_invalid`);
  return value;
}
function privatePath(name, requiredBoundary) {
  const value = path.resolve(setting(name, /^[^\u0000]{8,2048}$/));
  check(value.replaceAll('\\', '/').toLowerCase().includes(requiredBoundary), `setting_${name.toLowerCase()}_boundary`);
  return value;
}
function child(root, name, code) {
  check(/^[a-z0-9][a-z0-9_.-]{1,80}$/i.test(name), code);
  const target = path.resolve(root, name);
  const relative = path.relative(root, target);
  check(relative.length > 0 && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), code);
  return target;
}

const runId = setting('CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID', /^[a-f0-9]{24}$/);
const coreOrigin = localOrigin('CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN', 8190);
const photoOrigin = localOrigin('CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN', 8191);
const credentialPath = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE', '/.codex-work/private-real-qa/runtime/photos-app-v4-owner-existing-fixtures/');
const profileRoot = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT', '/.codex-work/private-real-qa/browser/photos-app-v4-owner-existing-fixtures/');
const screenshotDir = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR', '/.codex-work/private-real-qa/screenshots/photos-app-v4/');

let credentials;
try { credentials = JSON.parse(fs.readFileSync(credentialPath, 'utf8')); }
catch { fail('credential_document_invalid'); }
check(credentials?.version === 1 && credentials.environment === 'PRIVATE_REAL_FULL_OWNER_V4_EXISTING_FIXTURES'
  && credentials.run === runId, 'credential_document_scope');
check(Object.keys(credentials ?? {}).sort().join(',') === 'environment,roles,run,version', 'credential_document_shape');
check(Object.keys(credentials.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_role_shape');
for (const role of roles) {
  const expectedUsername = `fixture-${role}`;
  const value = credentials.roles[role];
  check(value?.username === expectedUsername && typeof value?.password === 'string'
    && /^[A-Za-z0-9_-]{32,190}$/.test(value.password), `credential_${role}_invalid`);
}

const CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS = Object.freeze([
  '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1, EXCLUDE ::1',
  '--host-resolver-retry-attempts=0',
  '--proxy-server=http://127.0.0.1:9',
  '--proxy-bypass-list=localhost,127.0.0.1,::1',
  '--disable-quic',
  '--disable-extensions',
  '--disable-background-networking',
  '--disable-component-update',
  '--disable-sync',
  '--no-pings',
  '--webrtc-ip-handling-policy=disable_non_proxied_udp',
]);

function allowedUrl(value) {
  if (['about:', 'blob:', 'data:'].includes(value.protocol)) return true;
  return value.protocol === 'http:' && value.hostname === '127.0.0.1'
    && [coreOrigin.port, photoOrigin.port].includes(value.port);
}
function isBusinessMutation(request) {
  let target;
  try { target = new URL(request.url()); } catch { return true; }
  return !['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && target.pathname.startsWith('/api/');
}
async function recordChromeStable(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const result = await session.send('Browser.getVersion');
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(result?.product ?? '');
    check(match !== null, 'chrome_stable_product');
    chromeVersion = match[1];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally { await session?.detach().catch(() => null); }
}

async function openRole(role, viewport) {
  const profile = child(profileRoot, `${role}-${viewport.width}x${viewport.height}`, 'profile_child_invalid');
  check(!fs.existsSync(profile), `profile_${role}_not_fresh`);
  let context = null;
  let familyDeniedCommentProbe = false;
  try {
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport,
      screen: viewport,
      locale: 'zh-CN',
      timezoneId: 'Asia/Shanghai',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: ['--no-first-run', '--no-default-browser-check', ...CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS],
    });
    await context.route('**/*', (route) => {
      let target;
      try { target = new URL(route.request().url()); }
      catch { unexpectedNetwork.add('invalid'); return route.abort(); }
      if (!allowedUrl(target)) { unexpectedNetwork.add('external'); return route.abort(); }
      if (isBusinessMutation(route.request())) {
        const allowedDeniedProbe = role === 'family' && familyDeniedCommentProbe
          && route.request().method() === 'POST' && target.pathname === '/api/class-archive/comments/create';
        if (!allowedDeniedProbe) {
          forbiddenBusinessMutations.add(`${role}:${route.request().method()}:${target.pathname}`);
          return route.abort();
        }
      }
      return route.continue();
    });
    context.on('response', (response) => {
      if (isBusinessMutation(response.request()) && response.status() >= 200 && response.status() < 300) {
        successfulBusinessWrites += 1;
      }
    });
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStable(context, page);
    const home = new URL('/home', photoOrigin);
    const login = new URL('/class-archive-core/login', photoOrigin);
    await page.goto(login.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    const form = page.locator('form[name="login_form"]');
    check(await form.count() === 1, `login_form_${role}`);
    await form.locator('input[name="username"]').fill(credentials.roles[role].username);
    await form.locator('input[name="password"]').fill(credentials.roles[role].password);
    const reached = page.waitForURL((value) => value.origin === home.origin && value.pathname === '/home', { timeout: 45_000 })
      .then(() => true).catch(() => false);
    await form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click();
    check(await reached, `login_bridge_${role}`);
    check(await page.locator('[data-photo-app="true"]').waitFor({ state: 'attached', timeout: 30_000 }).then(() => true).catch(() => false), `home_shell_${role}`);
    check(await page.locator('[data-home-all-photos="true"]').waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false), `home_projection_${role}`);
    return {
      context,
      page,
      beginFamilyDeniedCommentProbe() { familyDeniedCommentProbe = true; },
      endFamilyDeniedCommentProbe() { familyDeniedCommentProbe = false; },
    };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(`chrome_${role}_unexpected`);
  }
}

async function save(page, name) {
  await page.screenshot({ path: child(screenshotDir, `${name}.png`, 'screenshot_child_invalid'), fullPage: false });
  screenshots += 1;
}

async function browserFetch(page, target, init = {}) {
  const result = await page.evaluate(async ({ relative, options }) => {
    try {
      const response = await fetch(relative, { credentials: 'same-origin', cache: 'no-store', ...options });
      const raw = options?.method === 'HEAD' ? '' : await response.text();
      let json = null;
      if (raw !== '') { try { json = JSON.parse(raw); } catch { /* bounded non-JSON response */ } }
      return { status: response.status, json, text: raw };
    } catch { return { status: 0, json: null, text: '' }; }
  }, { relative: target, options: init });
  check(Number.isInteger(result?.status), 'browser_fetch_shape');
  return result;
}

async function requiredJson(page, target, code) {
  const result = await browserFetch(page, target);
  if (!(result.status === 200 && result.json && typeof result.json === 'object' && !Array.isArray(result.json))) {
    fail(`${code}_http_${Number.isInteger(result.status) ? result.status : 0}`);
  }
  return result.json;
}
function canonicalId(value, code) {
  check(typeof value === 'string' && uuid.test(value), code);
  return value.toLowerCase();
}
function setEquals(left, right) { return left.size === right.size && [...left].every((value) => right.has(value)); }
function recursiveWalk(value, visitor, depth = 0) {
  check(depth <= 64, 'projection_json_depth');
  if (value === null || typeof value !== 'object') return;
  if (Array.isArray(value)) { for (const item of value) recursiveWalk(item, visitor, depth + 1); return; }
  for (const [key, nested] of Object.entries(value)) {
    visitor(key, nested);
    recursiveWalk(nested, visitor, depth + 1);
  }
}
function assertNoPhotoIds(payload, forbiddenIds, code) {
  let leaked = false;
  const serialized = JSON.stringify(payload ?? null).toLowerCase();
  for (const id of forbiddenIds) { if (serialized.includes(id)) { leaked = true; break; } }
  check(!leaked, code);
}
function assertAnonymousRedaction(payload, code) {
  let leakedKey = false;
  recursiveWalk(payload, (key) => { if (forbiddenIdentityKeys.has(key.toLowerCase())) leakedKey = true; });
  const encoded = JSON.stringify(payload ?? null).toLowerCase();
  check(!leakedKey && !fixtureUsernames.some((username) => encoded.includes(username)), code);
}
function assertAllowedPhotoId(value, allowedIds, code) {
  check(allowedIds.has(canonicalId(value, code)), code);
}

async function timelineCatalog(page, role) {
  const photos = new Map();
  const cursors = new Set();
  let cursor = null;
  let expectedTotal = null;
  let pages = 0;
  do {
    pages += 1;
    check(pages <= 32, `${role}_timeline_page_budget`);
    const suffix = cursor === null ? '?limit=240' : `?limit=240&cursor=${encodeURIComponent(cursor)}`;
    const payload = await requiredJson(page, `/api/class-archive/timeline${suffix}`, `${role}_timeline`);
    const total = payload.total;
    const count = payload.count;
    const groups = payload.groups;
    const hasMore = payload.hasMore ?? payload.has_more;
    const nextCursor = payload.nextCursor ?? payload.next_cursor;
    check(Number.isInteger(total) && total > 0 && Number.isInteger(count) && count >= 0 && count <= 240
      && Array.isArray(groups) && typeof hasMore === 'boolean', `${role}_timeline_shape`);
    if (expectedTotal === null) expectedTotal = total;
    check(total === expectedTotal, `${role}_timeline_total_stable`);
    let pageCount = 0;
    for (const group of groups) {
      check(Array.isArray(group?.items) && Number.isInteger(group?.count) && group.items.length === group.count, `${role}_timeline_group_shape`);
      for (const photo of group.items) {
        const id = canonicalId(photo?.id, `${role}_timeline_photo_id`);
        check(!photos.has(id), `${role}_timeline_duplicate`);
        check(['HERITAGE', 'LIVING'].includes(photo?.era), `${role}_timeline_era_invalid`);
        photos.set(id, photo.era);
        pageCount += 1;
      }
    }
    check(pageCount === count, `${role}_timeline_page_count`);
    if (!hasMore) { check(nextCursor === null, `${role}_timeline_terminal_cursor`); break; }
    check(typeof nextCursor === 'string' && /^[A-Za-z0-9_-]{48}$/.test(nextCursor) && !cursors.has(nextCursor), `${role}_timeline_cursor`);
    cursors.add(nextCursor);
    cursor = nextCursor;
  } while (true);
  check(photos.size === expectedTotal, `${role}_timeline_catalog_complete`);
  return photos;
}

function assertCollection(payload, role, allowedIds, code) {
  check(payload?.scope === (role === 'family' ? 'HERITAGE_ONLY' : 'FULL'), `${code}_scope`);
  check(Array.isArray(payload?.items), `${code}_items`);
  for (const item of payload.items) {
    check(Array.isArray(item?.photoIds) && Number.isInteger(item?.photoCount)
      && item.photoCount === item.photoIds.length, `${code}_card_shape`);
    for (const id of item.photoIds) assertAllowedPhotoId(id, allowedIds, `${code}_photo_scope`);
    if (item.coverPhotoId !== null) assertAllowedPhotoId(item.coverPhotoId, allowedIds, `${code}_cover_scope`);
  }
}
function assertPins(payload, allowedIds, code) {
  check(Array.isArray(payload?.items), `${code}_items`);
  for (const [index, pin] of payload.items.entries()) {
    check(pin?.ordinal === index && pin?.item && Array.isArray(pin.item.photoIds), `${code}_shape`);
    for (const id of pin.item.photoIds) assertAllowedPhotoId(id, allowedIds, `${code}_photo_scope`);
    if (pin.item.coverPhotoId !== null) assertAllowedPhotoId(pin.item.coverPhotoId, allowedIds, `${code}_cover_scope`);
  }
}
function albumMap(payload, allowedIds, code) {
  check(Number.isInteger(payload?.total) && Array.isArray(payload?.items) && payload.total === payload.items.length, `${code}_shape`);
  const result = new Map();
  for (const item of payload.items) {
    const id = canonicalId(item?.id, `${code}_id`);
    check(!result.has(id) && Number.isInteger(item?.total) && item.total > 0, `${code}_item`);
    assertAllowedPhotoId(item?.coverPhotoId, allowedIds, `${code}_cover_scope`);
    result.set(id, { total: item.total, cover: item.coverPhotoId.toLowerCase() });
  }
  return result;
}
function peopleMap(payload, allowedIds, code) {
  check(payload?.hasNextPage === false && Number.isInteger(payload?.total) && Array.isArray(payload?.people)
    && payload.total === payload.people.length, `${code}_shape`);
  const result = new Map();
  for (const item of payload.people) {
    const id = canonicalId(item?.id, `${code}_id`);
    const count = item?.photoCount ?? item?.photo_count ?? item?.total;
    const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
    check(!result.has(id) && Number.isInteger(count) && count > 0, `${code}_item`);
    if (cover !== null) assertAllowedPhotoId(cover, allowedIds, `${code}_cover_scope`);
    result.set(id, { count, cover: cover === null ? null : cover.toLowerCase() });
  }
  return result;
}
function assertSpotlight(payload, allowedIds, code) {
  check(typeof payload?.active === 'boolean' && Number.isInteger(payload?.total) && Array.isArray(payload?.items), `${code}_shape`);
  for (const item of payload.items) assertAllowedPhotoId(item?.coverPhotoId, allowedIds, `${code}_cover_scope`);
}
function assertSearch(payload, allowedIds, code) {
  check(typeof payload?.query === 'string' && payload?.photos && Array.isArray(payload.photos.items)
    && Number.isInteger(payload.photos.total) && Number.isInteger(payload.photos.count)
    && payload.photos.total >= payload.photos.count && payload.photos.count === payload.photos.items.length, `${code}_shape`);
  for (const photo of payload.photos.items) assertAllowedPhotoId(photo?.id, allowedIds, `${code}_photo_scope`);
  for (const sectionName of ['people', 'albums', 'events', 'archiveTime', 'semantic']) {
    const section = payload[sectionName];
    check(section && Number.isInteger(section.total) && Array.isArray(section.items) && section.total >= section.items.length,
      `${code}_${sectionName}_shape`);
    for (const item of section.items) {
      const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
      if (cover !== null) assertAllowedPhotoId(cover, allowedIds, `${code}_${sectionName}_cover_scope`);
      if (sectionName === 'semantic') assertAllowedPhotoId(item?.id, allowedIds, `${code}_semantic_scope`);
    }
  }
}
function compareMapsExact(actual, expected, valueKey, code) {
  check(actual.size === expected.size, `${code}_size`);
  for (const [id, value] of expected) {
    check(actual.has(id) && actual.get(id)[valueKey] === value[valueKey], `${code}_value`);
  }
}
function compareMapsSubset(actual, full, valueKey, code) {
  for (const [id, value] of actual) {
    check(full.has(id) && value[valueKey] <= full.get(id)[valueKey], `${code}_value`);
  }
}

async function albumDetailSamples(page, albums, allowedIds, forbiddenIds, role) {
  let sampled = 0;
  for (const [albumId, summary] of albums) {
    if (sampled >= 12) break;
    const payload = await requiredJson(page, `/api/class-archive/albums/${albumId}?limit=240`, `${role}_album_detail`);
    const items = payload.items;
    check(Array.isArray(items) && Number.isInteger(payload.total) && payload.total === summary.total, `${role}_album_detail_shape`);
    for (const photo of items) assertAllowedPhotoId(photo?.id, allowedIds, `${role}_album_detail_scope`);
    if (role === 'family') assertNoPhotoIds(payload, forbiddenIds, 'family_album_detail_living_leak');
    sampled += 1;
  }
  check(sampled > 0, `${role}_album_detail_sample_missing`);
}
async function peopleDetailSamples(page, people, allowedIds, forbiddenIds, role) {
  let sampled = 0;
  for (const [personId, summary] of people) {
    if (sampled >= 12) break;
    const payload = await requiredJson(page, `/api/class-archive/people/${personId}`, `${role}_person_detail`);
    const items = payload.items ?? payload.photos;
    const count = payload.photoCount ?? payload.photo_count ?? payload.total;
    check(Array.isArray(items) && Number.isInteger(count) && count === items.length && count === summary.count, `${role}_person_detail_shape`);
    for (const photo of items) assertAllowedPhotoId(photo?.id, allowedIds, `${role}_person_detail_scope`);
    if (role === 'family') assertNoPhotoIds(payload, forbiddenIds, 'family_person_detail_living_leak');
    sampled += 1;
  }
  check(sampled > 0, `${role}_person_detail_sample_missing`);
}

async function assertBrowserSurface(page, role) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  check(!overflow, `${role}_horizontal_overflow`);
  const body = await page.locator('body').innerText();
  check(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(body), `${role}_technical_copy_visible`);
  if (role === 'anonymous') {
    const markup = await page.locator('[data-photo-app="true"]').innerHTML();
    check(!/(?:classmate_id|identity_id|seat_id|account_id|user_id|principal_id|pseudonym_subject)/i.test(markup)
      && !fixtureUsernames.some((username) => markup.includes(username)), 'anonymous_html_identity_leak');
  }
}

async function viewer(page, role, photoId) {
  const response = await page.goto(new URL(`/photos/${photoId}`, photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  check(response?.status() === 200, `${role}_viewer_status`);
  const image = page.locator('.viewer-image');
  check(await image.waitFor({ state: 'visible', timeout: 45_000 }).then(() => true).catch(() => false), `${role}_viewer_missing`);
  const decoded = await image.evaluate((node) => node instanceof HTMLImageElement && node.complete && node.naturalWidth > 0 && node.naturalHeight > 0);
  check(decoded, `${role}_viewer_decode`);
  const source = await image.getAttribute('src');
  check(new RegExp(`^/api/assets/${photoId}/thumbnail\\?size=preview(?:&v=[a-f0-9]{32})?$`, 'i').test(source ?? ''), `${role}_viewer_mediaguard_path`);
  check(!/(?:immich|original|_data|galleries|upload)/i.test(source ?? ''), `${role}_viewer_direct_media_path`);
  const comments = page.locator('.viewer-comments');
  check(await comments.count() === 1, `${role}_comments_surface`);
  if (role === 'family') {
    check(await comments.locator('.comment-composer').count() === 0 && await comments.locator('.comment-readonly').count() === 1,
      'family_comment_readonly_surface');
  }
  await assertBrowserSurface(page, role);
  await save(page, `${role}-viewer`);
}

async function assertFamilyKnownLivingDenied(page, livingId) {
  const probes = [
    [`/api/assets/${livingId}`, { method: 'GET' }],
    [`/api/assets/${livingId}`, { method: 'HEAD' }],
    [`/api/assets/${livingId}/thumbnail?size=grid`, { method: 'GET' }],
    [`/api/assets/${livingId}/thumbnail?size=grid`, { method: 'HEAD' }],
    [`/api/assets/${livingId}/thumbnail?size=preview`, { method: 'GET' }],
    [`/api/assets/${livingId}/thumbnail?size=preview`, { method: 'HEAD' }],
    [`/api/assets/${livingId}/thumbnail?size=preview`, { method: 'GET', headers: { Range: 'bytes=0-31' } }],
  ];
  for (const [target, init] of probes) {
    const result = await browserFetch(page, target, init);
    check(result.status === 404, 'family_known_living_media_denied');
  }
  const response = await page.goto(new URL(`/photos/${livingId}`, photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  const markup = await page.locator('html').innerHTML();
  check(response?.status() === 404 && await page.locator('[data-photo-app="true"]').count() === 0
    && !markup.toLowerCase().includes(livingId), 'family_known_living_viewer_denied');
}

async function assertFamilyCommentServerDenied(session, photoId) {
  const before = await requiredJson(session.page, `/api/class-archive/comments/${photoId}?limit=100`, 'family_comments_before');
  const beforeDigest = JSON.stringify(before);
  session.beginFamilyDeniedCommentProbe();
  let denied;
  try {
    denied = await session.page.evaluate(async ({ id }) => {
      try {
        const stateResponse = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
        const state = await stateResponse.json().catch(() => null);
        const response = await fetch('/api/class-archive/comments/create', {
          method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrfToken: state?.csrfToken, photoUuid: id, parentId: null, body: 'family-readonly-owner-acceptance' }),
        });
        return { state: stateResponse.status, status: response.status };
      } catch { return { state: 0, status: 0 }; }
    }, { id: photoId });
  } finally { session.endFamilyDeniedCommentProbe(); }
  check(denied?.state === 200 && denied?.status === 403, 'family_comment_server_denied');
  const after = await requiredJson(session.page, `/api/class-archive/comments/${photoId}?limit=100`, 'family_comments_after');
  check(JSON.stringify(after) === beforeDigest, 'family_comment_denial_no_write');
}

async function openSearch(page, role) {
  await page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  const trigger = page.locator('.search-trigger').first();
  check(await trigger.count() === 1, `${role}_search_trigger`);
  await trigger.focus();
  await page.keyboard.press('Control+K');
  const dialog = page.locator('dialog[data-search-overlay="true"][open]');
  check(await dialog.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false), `${role}_search_dialog`);
  const input = dialog.getByRole('combobox', { name: '搜索照片', exact: true });
  check(await input.count() === 1 && await input.evaluate((node) => document.activeElement === node), `${role}_search_focus`);
  await input.fill('毕业');
  await input.press('Enter');
  check(await dialog.locator('.hybrid-results, .error-state').waitFor({ state: 'visible', timeout: 45_000 }).then(() => true).catch(() => false), `${role}_search_results`);
  await save(page, `${role}-search`);
  await page.keyboard.press('Escape');
  check(await page.locator('dialog[data-search-overlay="true"][open]').count() === 0, `${role}_search_escape`);
}

async function inspectRole(session, role, expectedIds, forbiddenLivingIds) {
  const { page } = session;
  stageAt(`${role}_read_projections`);
  const state = await requiredJson(page, '/api/class-archive/product-state', `${role}_state`);
  check(state.role === role.toUpperCase(), `${role}_state_role`);
  check(state.canEraUpload === (role === 'classmate' || role === 'teacher'), `${role}_state_member_upload`);
  check(state.canFamilySubmission === (role === 'family'), `${role}_state_family_upload`);
  const timeline = await timelineCatalog(page, role);
  check(setEquals(new Set(timeline.keys()), expectedIds), `${role}_timeline_exact_scope`);
  const home = await requiredJson(page, '/api/class-archive/collections/home', `${role}_home`);
  const pins = await requiredJson(page, '/api/class-archive/collections/pins', `${role}_pins`);
  const albumsPayload = await requiredJson(page, '/api/class-archive/albums', `${role}_albums`);
  const peoplePayload = await requiredJson(page, '/api/people', `${role}_people`);
  const spotlight = await requiredJson(page, '/api/class-archive/spotlight', `${role}_spotlight`);
  const suggestions = await requiredJson(page, '/api/class-archive/search/suggestions?q=%E6%AF%95%E4%B8%9A', `${role}_suggestions`);
  const grouped = await requiredJson(page, '/api/class-archive/search/grouped?q=%E6%AF%95%E4%B8%9A&contextType=ALL&limit=120', `${role}_search`);
  assertCollection(home, role, expectedIds, `${role}_home`);
  assertPins(pins, expectedIds, `${role}_pins`);
  const albums = albumMap(albumsPayload, expectedIds, `${role}_albums`);
  const people = peopleMap(peoplePayload, expectedIds, `${role}_people`);
  assertSpotlight(spotlight, expectedIds, `${role}_spotlight`);
  assertSearch(grouped, expectedIds, `${role}_search`);
  if (role === 'family') {
    for (const [name, payload] of Object.entries({ home, pins, albumsPayload, peoplePayload, spotlight, suggestions, grouped })) {
      assertNoPhotoIds(payload, forbiddenLivingIds, `family_${name}_living_leak`);
    }
  }
  if (role === 'anonymous') {
    for (const payload of [state, home, pins, albumsPayload, peoplePayload, spotlight, suggestions, grouped]) {
      assertAnonymousRedaction(payload, 'anonymous_api_identity_leak');
    }
  }
  await albumDetailSamples(page, albums, expectedIds, forbiddenLivingIds, role);
  await peopleDetailSamples(page, people, expectedIds, forbiddenLivingIds, role);
  await page.goto(new URL('/people', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  check(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, `${role}_people_surface`);
  await assertBrowserSurface(page, role);
  await save(page, `${role}-people`);
  await openSearch(page, role);
  return { state, timeline, home, albums, people, grouped };
}

async function main() {
  const results = new Map();
  stageAt('classmate_login');
  const classmateSession = await openRole('classmate', { width: 1440, height: 900 });
  let classmateTimeline;
  try {
    classmateTimeline = await timelineCatalog(classmateSession.page, 'classmate_truth');
    const fullIds = new Set(classmateTimeline.keys());
    const heritageIds = new Set([...classmateTimeline].filter(([, era]) => era === 'HERITAGE').map(([id]) => id));
    const livingIds = new Set([...classmateTimeline].filter(([, era]) => era === 'LIVING').map(([id]) => id));
    check(heritageIds.size > 0 && livingIds.size > 0 && heritageIds.size + livingIds.size === fullIds.size, 'owner_both_eras_required');
    const classmate = await inspectRole(classmateSession, 'classmate', fullIds, livingIds);
    results.set('classmate', classmate);
    const knownLiving = livingIds.values().next().value;
    await viewer(classmateSession.page, 'classmate', knownLiving);
    await classmateSession.page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await assertBrowserSurface(classmateSession.page, 'classmate');
    await save(classmateSession.page, 'classmate-home');

    for (const role of ['family', 'teacher', 'anonymous']) {
      stageAt(`${role}_login`);
      const session = await openRole(role, role === 'family' || role === 'anonymous' ? { width: 390, height: 844 } : { width: 1920, height: 1080 });
      try {
        const expectedIds = role === 'family' ? heritageIds : fullIds;
        const inspection = await inspectRole(session, role, expectedIds, livingIds);
        results.set(role, inspection);
        if (role === 'family') {
          await assertFamilyKnownLivingDenied(session.page, knownLiving);
          const visibleHeritage = heritageIds.values().next().value;
          await viewer(session.page, role, visibleHeritage);
          await assertFamilyCommentServerDenied(session, visibleHeritage);
        } else {
          await viewer(session.page, role, knownLiving);
        }
        await session.page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        await assertBrowserSurface(session.page, role);
        await save(session.page, `${role}-home`);
      } finally { await session.context.close().catch(() => null); }
    }

    stageAt('cross_role_scope_comparison');
    for (const role of fullRoles) {
      const result = results.get(role);
      check(result?.home?.scope === 'FULL', `${role}_full_scope`);
      check(setEquals(new Set(result.timeline.keys()), fullIds), `${role}_full_timeline`);
      compareMapsExact(result.albums, results.get('classmate').albums, 'total', `${role}_album_counts`);
      compareMapsExact(result.people, results.get('classmate').people, 'count', `${role}_people_counts`);
    }
    const family = results.get('family');
    check(family?.home?.scope === 'HERITAGE_ONLY' && setEquals(new Set(family.timeline.keys()), heritageIds), 'family_heritage_only_scope');
    compareMapsSubset(family.albums, results.get('classmate').albums, 'total', 'family_album_counts');
    compareMapsSubset(family.people, results.get('classmate').people, 'count', 'family_people_counts');
    check(unexpectedNetwork.size === 0, 'unexpected_network_request');
    check(forbiddenBusinessMutations.size === 0, 'forbidden_business_mutation_attempt');
    check(successfulBusinessWrites === 0, 'business_write_observed');
    process.stdout.write(`V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=${assertions} screenshots=${screenshots} roles=4 full_photos=${fullIds.size} heritage_photos=${heritageIds.size} living_photos=${livingIds.size} channel=chrome chrome_product=chrome chrome_version=${chromeVersion} writes=0\n`);
  } finally { await classmateSession.context.close().catch(() => null); }
}

try { await main(); }
catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/i.test(error.code) ? error.code : 'unexpected';
  process.stdout.write(`V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
}
