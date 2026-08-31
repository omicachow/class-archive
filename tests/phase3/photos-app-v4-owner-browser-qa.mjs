/*
 * Owner-private V4 role acceptance over one leased, already-frozen FQA
 * Classmate aggregate. The companion broker is the only component allowed to
 * rotate credentials or toggle the Identity; this browser process performs no
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

const roles = Object.freeze(['classmate', 'family', 'anonymous']);
const fullRoles = Object.freeze(['classmate', 'anonymous']);
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const forbiddenIdentityKeys = new Set([
  'classmateid', 'classmateidentity', 'classmateidentityid', 'identityid', 'seatid', 'accountid',
  'userid', 'underlyinguserid', 'underlyingaccountid', 'underlyingidentityid',
  'principalid', 'pseudonymsubject', 'pseudonymsubjectid',
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
const credentialPath = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE', '/.codex-work/private-real-qa/runtime/photos-app-v4-owner-fqa-lease/');
const profileRoot = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT', '/.codex-work/private-real-qa/browser/photos-app-v4-owner-fqa-lease/');
const screenshotDir = privatePath('CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR', '/.codex-work/private-real-qa/screenshots/photos-app-v4/');

let credentialDocument;
try { credentialDocument = JSON.parse(fs.readFileSync(credentialPath, 'utf8')); }
catch { fail('credential_document_invalid'); }
check(credentialDocument?.version === 3 && credentialDocument.environment === 'PRIVATE_REAL_FULL_OWNER_V4_FQA_LEASE'
  && credentialDocument.run === runId, 'credential_document_scope');
check(Object.keys(credentialDocument ?? {}).sort().join(',') === 'environment,lease,recovery_plan,roles,run,version', 'credential_document_shape');
check(credentialDocument.lease?.roster === 'FQA-C-99CA3B3B6AF1' && credentialDocument.lease?.roles === 3, 'credential_lease_scope');
check(Object.keys(credentialDocument.roles ?? {}).sort().join(',') === 'anonymous,classmate,family', 'credential_role_shape');
check(Object.keys(credentialDocument.recovery_plan ?? {}).join(',') === 'ANONYMOUS,CLASSMATE,FAMILY', 'credential_recovery_plan_shape');
for (const role of ['ANONYMOUS', 'CLASSMATE', 'FAMILY']) {
  const value = credentialDocument.recovery_plan[role];
  check(value?.role === role
    && /^[a-f0-9]{64}$/.test(value?.before_password_sha256 ?? '')
    && /^[a-f0-9]{64}$/.test(value?.lease_password_sha256 ?? '')
    && /^[a-f0-9]{64}$/.test(value?.closed_password_sha256 ?? '')
    && typeof value?.closed_password_hash === 'string' && value.closed_password_hash.length > 0
    && !Object.hasOwn(value, 'password') && !Object.hasOwn(value, 'browser_password')
    && !Object.hasOwn(value, 'before_password_hash') && !Object.hasOwn(value, 'lease_password_hash'),
  `credential_recovery_${role.toLowerCase()}_invalid`);
}
// Copy only the three one-time browser credentials. Recovery verifiers stay
// in broker-owned memory/file handling and are never passed to page.evaluate,
// page content, screenshots, console output, or browser storage.
const credentials = Object.freeze({
  roles: Object.freeze(Object.fromEntries(roles.map((role) => [role, Object.freeze({
    username: credentialDocument.roles?.[role]?.username,
    password: credentialDocument.roles?.[role]?.password,
  })]))),
});
credentialDocument = null;
for (const role of roles) {
  const value = credentials.roles[role];
  const usernameValid = role === 'classmate'
    ? value?.username === 'fqa_99ca3b3b6af1_classmate'
    : role === 'family'
      ? value?.username === 'fqa_99ca3b3b6af1_family'
      : /^anon_[a-f0-9]{20}$/.test(value?.username ?? '');
  check(usernameValid && typeof value?.password === 'string'
    && /^[A-Za-z0-9_-]{32,190}$/.test(value.password), `credential_${role}_invalid`);
}
const leasedUsernames = Object.freeze(roles.map((role) => credentials.roles[role].username));

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

async function assertHomeReady(page, role) {
  check(await page.locator('[data-home-all-photos="true"]').waitFor({ state: 'visible', timeout: 30_000 })
    .then(() => true).catch(() => false), `home_projection_${role}`);
  check(await page.locator('.photo-loading').count() === 0, `home_loading_${role}`);
  check(await page.waitForFunction(() => [...document.images]
    .some((image) => image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0),
  undefined, { timeout: 30_000 }).then(() => true).catch(() => false), `home_real_image_${role}`);
}

const SAFE_HTTP_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
// Keep the source ASCII-only so Windows PowerShell 5.1 can attest the exact
// probe text without depending on the host ANSI code page. JavaScript decodes
// this to the same "毕业 graduation" query at runtime.
const SEMANTIC_PROBE_QUERY = '\u6bd5\u4e1a graduation';

// This harness is a read-only owner acceptance run.  Do not make the older
// "only /api" distinction here: a state-changing Piwigo/Core POST is just as
// capable of mutating the owner library as a BFF POST.  Every unsafe request
// is denied unless it is one of the two intentionally bounded probes below.
function isUnsafeRequest(request) {
  return !SAFE_HTTP_METHODS.has(request.method());
}
function isAllowedLoginPost(request, target, role) {
  if (request.method() !== 'POST' || target.origin !== coreOrigin.origin || target.pathname !== '/identification.php') return false;
  const contentType = request.headers()['content-type'] ?? '';
  if (!contentType.toLowerCase().startsWith('application/x-www-form-urlencoded')) return false;
  let fields;
  try { fields = new URLSearchParams(request.postData() ?? ''); }
  catch { return false; }
  const credential = credentials.roles[role];
  return fields.getAll('username').length === 1 && fields.get('username') === credential.username
    && fields.getAll('password').length === 1 && fields.get('password') === credential.password
    && fields.getAll('login').length === 1;
}
function isAllowedFamilyDeniedCommentProbe(request, target, role, enabled) {
  if (!(role === 'family' && enabled && request.method() === 'POST'
    && target.origin === photoOrigin.origin && target.pathname === '/api/class-archive/comments/create')) return false;
  const contentType = request.headers()['content-type'] ?? '';
  if (!contentType.toLowerCase().startsWith('application/json')) return false;
  try {
    const payload = JSON.parse(request.postData() ?? '');
    return uuid.test(payload?.photoUuid ?? '') && payload?.parentId === null
      && payload?.body === 'family-readonly-owner-acceptance'
      && typeof payload?.csrfToken === 'string' && payload.csrfToken.length >= 16;
  } catch { return false; }
}
function isAllowedSmartSearchProbe(request, target) {
  if (!(request.method() === 'POST' && target.origin === photoOrigin.origin
    && target.pathname === '/api/search/smart' && target.search === '')) return false;
  const contentType = request.headers()['content-type'] ?? '';
  const mediaType = contentType.split(';', 1)[0].trim().toLowerCase();
  if (mediaType !== 'application/json') return false;
  try {
    const payload = JSON.parse(request.postData() ?? '');
    return payload && typeof payload === 'object' && !Array.isArray(payload)
      && Object.keys(payload).length === 1 && payload.query === SEMANTIC_PROBE_QUERY;
  } catch { return false; }
}
function isBusinessMutation(request) {
  let target;
  try { target = new URL(request.url()); } catch { return true; }
  return isUnsafeRequest(request) && target.pathname.startsWith('/api/')
    && !isAllowedSmartSearchProbe(request, target);
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
      if (isUnsafeRequest(route.request())) {
        const allowedLogin = isAllowedLoginPost(route.request(), target, role);
        const allowedDeniedProbe = isAllowedFamilyDeniedCommentProbe(route.request(), target, role, familyDeniedCommentProbe);
        const allowedSmartSearch = isAllowedSmartSearchProbe(route.request(), target);
        if (!allowedLogin && !allowedDeniedProbe && !allowedSmartSearch) {
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
    await assertHomeReady(page, role);
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
function normalizedIdentityKey(value) {
  return String(value).toLowerCase().replaceAll('_', '').replaceAll('-', '');
}
function normalizedTextIncludes(value, query) {
  return typeof value === 'string' && value.toLocaleLowerCase('zh-CN').includes(query.toLocaleLowerCase('zh-CN'));
}
function assertNoPhotoIds(payload, forbiddenIds, code) {
  let leaked = false;
  const serialized = JSON.stringify(payload ?? null).toLowerCase();
  for (const id of forbiddenIds) { if (serialized.includes(id)) { leaked = true; break; } }
  check(!leaked, code);
}
function assertAnonymousRedaction(payload, code) {
  let leakedKey = false;
  recursiveWalk(payload, (key) => { if (forbiddenIdentityKeys.has(normalizedIdentityKey(key))) leakedKey = true; });
  const encoded = JSON.stringify(payload ?? null).toLowerCase();
  check(!leakedKey && !leasedUsernames.some((username) => encoded.includes(username.toLowerCase())), code);
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
    // Preserve the explicit terminal null. Nullish coalescing would replace a
    // valid `nextCursor: null` with an absent snake-case alias (undefined).
    const nextCursor = Object.hasOwn(payload, 'nextCursor') ? payload.nextCursor : payload.next_cursor;
    check(Number.isInteger(total) && total > 0 && Number.isInteger(count) && count >= 0 && count <= 240
      && Array.isArray(groups) && typeof hasMore === 'boolean', `${role}_timeline_shape`);
    if (expectedTotal === null) expectedTotal = total;
    check(total === expectedTotal, `${role}_timeline_total_stable`);
    let pageCount = 0;
    for (const group of groups) {
      check(typeof group?.key === 'string' && typeof group?.label === 'string' && typeof group?.kind === 'string'
        && Array.isArray(group?.items) && Number.isInteger(group?.count) && group.items.length === group.count,
      `${role}_timeline_group_shape`);
      for (const photo of group.items) {
        const id = canonicalId(photo?.id, `${role}_timeline_photo_id`);
        check(!photos.has(id), `${role}_timeline_duplicate`);
        photos.set(id, {
          timelineKey: group.key,
          timelineLabel: group.label,
          timelineKind: group.kind,
        });
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
  const inspect = (item, itemCode) => {
    check(Array.isArray(item?.photoIds) && Number.isInteger(item?.photoCount)
      && item.photoCount === item.photoIds.length, `${itemCode}_card_shape`);
    const members = new Set();
    for (const rawId of item.photoIds) {
      const id = canonicalId(rawId, `${itemCode}_photo_id`);
      check(!members.has(id) && allowedIds.has(id), `${itemCode}_photo_scope`);
      members.add(id);
    }
    if (item.coverPhotoId !== null) {
      const cover = canonicalId(item.coverPhotoId, `${itemCode}_cover`);
      check(members.has(cover) && allowedIds.has(cover), `${itemCode}_cover_scope`);
    }
  };
  for (const item of payload.items) inspect(item, code);
  for (const entry of (payload?.preferences?.hidden ?? [])) {
    check(entry?.item && typeof entry.item === 'object', `${code}_hidden_shape`);
    inspect(entry.item, `${code}_hidden`);
  }
}
function assertPins(payload, allowedIds, code) {
  check(Array.isArray(payload?.items), `${code}_items`);
  for (const [index, pin] of payload.items.entries()) {
    check(pin?.ordinal === index && pin?.item && Array.isArray(pin.item.photoIds)
      && Number.isInteger(pin.item.photoCount) && pin.item.photoCount === pin.item.photoIds.length, `${code}_shape`);
    const members = new Set();
    for (const rawId of pin.item.photoIds) {
      const id = canonicalId(rawId, `${code}_photo_id`);
      check(!members.has(id) && allowedIds.has(id), `${code}_photo_scope`);
      members.add(id);
    }
    if (pin.item.coverPhotoId !== null) {
      const cover = canonicalId(pin.item.coverPhotoId, `${code}_cover`);
      check(members.has(cover) && allowedIds.has(cover), `${code}_cover_scope`);
    }
  }
}
function albumMap(payload, allowedIds, code) {
  check(Number.isInteger(payload?.total) && Array.isArray(payload?.items) && payload.total === payload.items.length, `${code}_shape`);
  const result = new Map();
  for (const item of payload.items) {
    const id = canonicalId(item?.id, `${code}_id`);
    check(!result.has(id) && Number.isInteger(item?.total) && item.total > 0, `${code}_item`);
    assertAllowedPhotoId(item?.coverPhotoId, allowedIds, `${code}_cover_scope`);
    result.set(id, {
      total: item.total,
      cover: item.coverPhotoId.toLowerCase(),
      searchText: [item.name, item.displayAlias, item.description, item.eventLabel, item.dateLabel]
        .filter((value) => typeof value === 'string').join('\n'),
    });
  }
  return result;
}
function peopleMap(payload, allowedIds, code) {
  check(payload?.hasNextPage === false && payload?.hidden === 0 && Number.isInteger(payload?.total) && Array.isArray(payload?.people)
    && payload.total === payload.people.length, `${code}_shape`);
  const result = new Map();
  for (const item of payload.people) {
    const id = canonicalId(item?.id, `${code}_id`);
    const count = item?.photoCount ?? item?.photo_count ?? item?.total;
    const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
    check(!result.has(id) && Number.isInteger(count) && count > 0, `${code}_item`);
    if (cover !== null) assertAllowedPhotoId(cover, allowedIds, `${code}_cover_scope`);
    const label = item?.name ?? item?.label;
    check(typeof label === 'string' && label !== '', `${code}_label`);
    result.set(id, { count, cover: cover === null ? null : cover.toLowerCase(), label });
  }
  return result;
}
function assertSpotlight(payload, allowedIds, albums, code) {
  check(typeof payload?.active === 'boolean' && Number.isInteger(payload?.total) && Array.isArray(payload?.items)
    && (payload.active ? payload.total > 0 && payload.total === payload.items.length
      : payload.total === 0 && payload.items.length === 0 && payload.item === null), `${code}_shape`);
  const ids = new Set();
  for (const item of payload.items) {
    const id = canonicalId(item?.id, `${code}_id`);
    const albumId = canonicalId(item?.albumId, `${code}_album_id`);
    const cover = canonicalId(item?.coverPhotoId, `${code}_cover`);
    check(!ids.has(id) && albums.has(albumId) && allowedIds.has(cover)
      && albums.get(albumId).cover === cover, `${code}_cover_scope`);
    ids.add(id);
  }
  if (payload.active) {
    const selectedId = canonicalId(payload?.item?.id, `${code}_selected_id`);
    check(ids.has(selectedId), `${code}_selected_scope`);
  }
}
function assertSearch(payload, allowedIds, albums, people, timeline, query, code) {
  check(typeof payload?.query === 'string' && typeof payload?.partial === 'boolean'
    && payload.query === query
    && payload?.photos && Array.isArray(payload.photos.items)
    && Number.isInteger(payload.photos.total) && Number.isInteger(payload.photos.count)
    && payload.photos.total >= payload.photos.count && payload.photos.count === payload.photos.items.length
    && typeof payload.has_more === 'boolean', `${code}_shape`);
  for (const photo of payload.photos.items) assertAllowedPhotoId(photo?.id, allowedIds, `${code}_photo_scope`);
  for (const sectionName of ['people', 'albums', 'events', 'archiveTime', 'semantic']) {
    const section = payload[sectionName];
    check(section && typeof section.available === 'boolean' && Number.isInteger(section.total)
      && section.total >= 0 && Array.isArray(section.items)
      && section.items.length === Math.min(section.total, 24),
      `${code}_${sectionName}_shape`);
    const sectionIds = new Set();
    for (const item of section.items) {
      const cover = item?.coverPhotoId ?? item?.cover_photo_id ?? null;
      if (cover !== null) assertAllowedPhotoId(cover, allowedIds, `${code}_${sectionName}_cover_scope`);
      if (sectionName === 'semantic') assertAllowedPhotoId(item?.id, allowedIds, `${code}_semantic_scope`);
      if (sectionName === 'people') {
        const id = canonicalId(item?.id, `${code}_people_id`);
        const count = item?.photoCount ?? item?.photo_count ?? item?.total;
        check(!sectionIds.has(id) && people.has(id) && Number.isInteger(count) && count === people.get(id).count,
          `${code}_people_count_scope`);
        sectionIds.add(id);
      }
      if (sectionName === 'albums') {
        const id = canonicalId(item?.id, `${code}_albums_id`);
        const count = item?.photoCount ?? item?.photo_count ?? item?.total ?? item?.count;
        check(!sectionIds.has(id) && albums.has(id) && Number.isInteger(count) && count === albums.get(id).total,
          `${code}_albums_count_scope`);
        sectionIds.add(id);
      }
      if (sectionName === 'semantic') {
        const id = canonicalId(item?.id, `${code}_semantic_id`);
        check(!sectionIds.has(id), `${code}_semantic_duplicate`);
        sectionIds.add(id);
      }
    }
    if (sectionName === 'people') {
      const expected = [...people.values()].filter((item) => normalizedTextIncludes(item.label, query)).length;
      check(section.total === expected, `${code}_people_total_scope`);
    }
    if (sectionName === 'albums') {
      const expected = [...albums.values()].filter((item) => normalizedTextIncludes(item.searchText, query)).length;
      check(section.total === expected, `${code}_albums_total_scope`);
    }
    if (sectionName === 'events') {
      const expected = new Map();
      for (const value of timeline.values()) {
        if (normalizedTextIncludes(value.eventLabel, query)) expected.set(value.eventLabel, (expected.get(value.eventLabel) ?? 0) + 1);
      }
      check(section.total === expected.size, `${code}_events_total_scope`);
      for (const item of section.items) {
        const count = item?.photoCount ?? item?.photo_count ?? item?.total ?? item?.count;
        check(typeof item?.name === 'string' && Number.isInteger(count) && expected.get(item.name) === count,
          `${code}_events_count_scope`);
      }
    }
    if (sectionName === 'archiveTime') {
      const expected = new Map();
      for (const value of timeline.values()) {
        if (!normalizedTextIncludes(value.timelineLabel, query)) continue;
        const key = `${value.timelineKind}\u0000${value.timelineLabel}`;
        expected.set(key, (expected.get(key) ?? 0) + 1);
      }
      check(section.total === expected.size, `${code}_archive_time_total_scope`);
      for (const item of section.items) {
        const count = item?.photoCount ?? item?.photo_count ?? item?.total ?? item?.count;
        const key = `${item?.kind}\u0000${item?.label}`;
        check(typeof item?.kind === 'string' && typeof item?.label === 'string'
          && Number.isInteger(count) && expected.get(key) === count, `${code}_archive_time_count_scope`);
      }
    }
    if (sectionName === 'semantic') check(section.total <= allowedIds.size, `${code}_semantic_total_scope`);
  }
}

async function exactSemanticSearch(page, allowedIds, forbiddenIds, role) {
  const result = await page.evaluate(async ({ origin, query }) => {
    const response = await fetch(new URL('/api/search/smart', origin), {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ query }),
    });
    let body = null;
    try { body = await response.json(); } catch { }
    return { status: response.status, body };
  }, { origin: photoOrigin.origin, query: SEMANTIC_PROBE_QUERY });
  const assets = result?.body?.assets;
  check(result?.status === 200 && assets && Number.isInteger(assets.total)
    && Number.isInteger(assets.count) && Array.isArray(assets.items)
    && assets.total === assets.items.length && assets.count === assets.items.length,
  `${role}_semantic_exact_count_shape`);
  const ids = new Set();
  for (const item of assets.items) {
    const id = canonicalId(item?.id, `${role}_semantic_exact_id`);
    check(!ids.has(id) && allowedIds.has(id), `${role}_semantic_exact_scope`);
    ids.add(id);
  }
  if (role === 'family') assertNoPhotoIds(result.body, forbiddenIds, 'family_semantic_exact_living_leak');
  return ids;
}
function assertSuggestions(payload, albums, people, code) {
  check(typeof payload?.query === 'string', `${code}_shape`);
  for (const sectionName of ['people', 'albums', 'events', 'archiveTime']) {
    const section = payload?.[sectionName];
    check(section && Number.isInteger(section.total) && section.total >= 0
      && Array.isArray(section.items) && section.total >= section.items.length, `${code}_${sectionName}_shape`);
    for (const item of section.items) {
      if (sectionName === 'people') check(people.has(canonicalId(item?.id, `${code}_people_id`)), `${code}_people_scope`);
      if (sectionName === 'albums') check(albums.has(canonicalId(item?.id, `${code}_albums_id`)), `${code}_albums_scope`);
    }
  }
}
async function completeSearchPhotoIds(page, initial, allowedIds, role, query = '毕业') {
  const seen = new Set();
  let payload = initial;
  let pages = 0;
  const expectedTotal = payload.photos.total;
  while (true) {
    check(payload.photos.total === expectedTotal && payload.photos.count === payload.photos.items.length, `${role}_search_page_shape`);
    for (const photo of payload.photos.items) {
      const id = canonicalId(photo?.id, `${role}_search_page_id`);
      check(allowedIds.has(id) && !seen.has(id), `${role}_search_page_scope`);
      seen.add(id);
    }
    pages += 1;
    check(pages <= Math.ceil(Math.max(1, expectedTotal) / 120) + 1, `${role}_search_page_bound`);
    if (!payload.has_more) {
      check(payload.next_cursor === null, `${role}_search_final_cursor`);
      break;
    }
    check(typeof payload.next_cursor === 'string', `${role}_search_next_cursor`);
    payload = await requiredJson(
      page,
      `/api/class-archive/search/grouped?q=${encodeURIComponent(query)}&contextType=ALL&limit=120&cursor=${encodeURIComponent(payload.next_cursor)}`,
      `${role}_search_page`,
    );
  }
  check(seen.size === expectedTotal, `${role}_search_exact_total`);
  return seen;
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

async function albumDetailsComplete(page, albums, allowedIds, forbiddenIds, role) {
  for (const [albumId, summary] of albums) {
    const seen = new Set();
    let cursor = null;
    let pageCount = 0;
    do {
      const params = new URLSearchParams({ limit: '240' });
      if (cursor !== null) params.set('cursor', cursor);
      const payload = await requiredJson(page, `/api/class-archive/albums/${albumId}?${params}`, `${role}_album_detail`);
      const items = payload.items;
      check(Array.isArray(items) && Number.isInteger(payload.total) && Number.isInteger(payload.count)
        && payload.total === summary.total && payload.count === items.length
        && typeof payload.has_more === 'boolean', `${role}_album_detail_shape`);
      for (const photo of items) {
        const id = canonicalId(photo?.id, `${role}_album_detail_id`);
        check(!seen.has(id), `${role}_album_detail_duplicate`);
        seen.add(id);
        assertAllowedPhotoId(id, allowedIds, `${role}_album_detail_scope`);
      }
      if (role === 'family') assertNoPhotoIds(payload, forbiddenIds, 'family_album_detail_living_leak');
      cursor = payload.has_more ? payload.next_cursor : null;
      check((payload.has_more && typeof cursor === 'string') || (!payload.has_more && cursor === null), `${role}_album_detail_cursor`);
      pageCount += 1;
      check(pageCount <= Math.ceil(Math.max(1, payload.total) / 240) + 1, `${role}_album_detail_page_bound`);
    } while (cursor !== null);
    check(seen.size === summary.total, `${role}_album_detail_exact_total`);
    check(seen.has(summary.cover), `${role}_album_detail_cover_membership`);
  }
  check(albums.size > 0, `${role}_album_detail_missing`);
}
async function peopleDetailsComplete(page, people, allowedIds, forbiddenIds, role) {
  for (const [personId, summary] of people) {
    const payload = await requiredJson(page, `/api/class-archive/people/${personId}`, `${role}_person_detail`);
    const items = payload.items ?? payload.photos;
    const count = payload.photoCount ?? payload.photo_count ?? payload.total;
    check(Array.isArray(items) && Number.isInteger(count) && count === items.length && count === summary.count, `${role}_person_detail_shape`);
    const itemIds = new Set();
    for (const photo of items) {
      const id = canonicalId(photo?.id, `${role}_person_detail_id`);
      check(!itemIds.has(id) && allowedIds.has(id), `${role}_person_detail_scope`);
      itemIds.add(id);
    }
    if (summary.cover !== null) check(itemIds.has(summary.cover), `${role}_person_detail_cover_membership`);
    if (role === 'family') assertNoPhotoIds(payload, forbiddenIds, 'family_person_detail_living_leak');
  }
  check(people.size > 0, `${role}_person_detail_missing`);
}

async function assertAnonymousBrowserRedaction(page) {
  const surfaces = await page.evaluate(() => {
    const hydrationNames = [
      '__INITIAL_STATE__', '__PRELOADED_STATE__', '__NEXT_DATA__', '__NUXT__',
      '__APOLLO_STATE__', '__HYDRATION_DATA__', '__CLASS_ARCHIVE_STATE__',
    ];
    const hydration = {};
    for (const name of hydrationNames) {
      if (!Object.hasOwn(globalThis, name)) continue;
      try { hydration[name] = JSON.stringify(globalThis[name]); }
      catch { hydration[name] = '[unserializable]'; }
    }
    const dynamicHydrationNames = Object.getOwnPropertyNames(globalThis)
      .filter((name) => /^__.*(?:state|data|hydrat)/i.test(name));
    for (const name of dynamicHydrationNames) {
      if (Object.hasOwn(hydration, name)) continue;
      try { hydration[name] = JSON.stringify(globalThis[name]); }
      catch { hydration[name] = '[unserializable]'; }
    }
    return {
      document: document.documentElement.outerHTML,
      hydration,
      globals: dynamicHydrationNames,
    };
  });
  assertAnonymousRedaction(surfaces.hydration, 'anonymous_hydration_identity_leak');
  assertAnonymousRedaction(surfaces.globals, 'anonymous_hydration_global_identity_leak');
  const markup = String(surfaces.document ?? '');
  const compactMarkup = markup.toLowerCase().replaceAll('_', '').replaceAll('-', '')
    .replaceAll('%5f', '').replaceAll('%2d', '').replaceAll('&#95;', '').replaceAll('&#45;', '')
    .replaceAll('&#x5f;', '').replaceAll('&#x2d;', '').replaceAll('&lowbar;', '')
    .replaceAll('\\u005f', '').replaceAll('\\u002d', '');
  check(![...forbiddenIdentityKeys].some((key) => compactMarkup.includes(key))
    && !leasedUsernames.some((username) => compactMarkup.includes(normalizedIdentityKey(username))), 'anonymous_html_identity_leak');
}

async function assertBrowserSurface(page, role) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  check(!overflow, `${role}_horizontal_overflow`);
  const body = await page.locator('body').innerText();
  check(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(body), `${role}_technical_copy_visible`);
  if (role === 'anonymous') await assertAnonymousBrowserRedaction(page);
}

async function viewer(page, role, photoId) {
  const target = new URL(`/photos/${photoId}`, photoOrigin);
  let response = null;
  try {
    response = await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  } catch (error) {
    // Chrome can report ERR_ABORTED when the compatibility shell replaces the
    // document navigation with its route transition. Accept that transport
    // detail only after the exact route and the authorized viewer surface are
    // independently proven below; redirects, login pages, and failed media
    // still fail closed.
    if (!/net::ERR_ABORTED/i.test(String(error?.message ?? ''))) throw error;
    await page.waitForFunction((expected) => {
      const current = new URL(window.location.href);
      return current.origin === expected.origin && current.pathname === expected.pathname
        && document.readyState !== 'loading';
    }, { origin: target.origin, pathname: target.pathname }, { timeout: 15_000 }).catch(() => null);
  }
  check(new URL(page.url()).origin === target.origin && new URL(page.url()).pathname === target.pathname, `${role}_viewer_route`);
  if (response !== null) check(response.status() === 200, `${role}_viewer_status`);
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
    [`/api/assets/${livingId}`, { method: 'GET', headers: { Range: 'bytes=0-31' } }],
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

async function completeCommentDigest(page, photoId, code) {
  const items = [];
  let cursor = null;
  let expectedTotal = null;
  let pages = 0;
  do {
    const params = new URLSearchParams({ limit: '200' });
    if (cursor !== null) params.set('cursor', cursor);
    const payload = await requiredJson(page, `/api/class-archive/comments/${photoId}?${params}`, code);
    check(Number.isInteger(payload?.total) && Array.isArray(payload?.items) && typeof payload?.hasMore === 'boolean', `${code}_shape`);
    expectedTotal ??= payload.total;
    check(payload.total === expectedTotal, `${code}_stable_total`);
    items.push(...payload.items);
    cursor = payload.hasMore ? payload.nextCursor : null;
    check((payload.hasMore && typeof cursor === 'string') || (!payload.hasMore && cursor === null), `${code}_cursor`);
    pages += 1;
    check(pages <= Math.ceil(Math.max(1, expectedTotal) / 200) + 1, `${code}_page_bound`);
  } while (cursor !== null);
  check(items.length === expectedTotal, `${code}_complete`);
  return JSON.stringify({ total: expectedTotal, items });
}

async function assertFamilyCommentServerDenied(session, photoId) {
  const beforeDigest = await completeCommentDigest(session.page, photoId, 'family_comments_before');
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
  const afterDigest = await completeCommentDigest(session.page, photoId, 'family_comments_after');
  check(afterDigest === beforeDigest, 'family_comment_denial_no_write');
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
  const resultVisible = await dialog.locator('.hybrid-results').waitFor({ state: 'visible', timeout: 45_000 })
    .then(() => true).catch(() => false);
  check(resultVisible && await dialog.locator('.error-state').count() === 0
    && await dialog.locator('.photo-loading').count() === 0
    && await dialog.locator('.hybrid-results .search-section').count() > 0
    && await dialog.locator('.hybrid-results .empty-state').count() === 0, `${role}_search_results`);
  await save(page, `${role}-search`);
  await page.keyboard.press('Escape');
  check(await page.locator('dialog[data-search-overlay="true"][open]').count() === 0, `${role}_search_escape`);
}

async function inspectRole(session, role, expectedIds, forbiddenLivingIds) {
  const { page } = session;
  stageAt(`${role}_read_projections`);
  const state = await requiredJson(page, '/api/class-archive/product-state', `${role}_state`);
  check(state.role === role.toUpperCase(), `${role}_state_role`);
  check(state.canEraUpload === (role === 'classmate'), `${role}_state_member_upload`);
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
  const semanticIds = await exactSemanticSearch(page, expectedIds, forbiddenLivingIds, role);
  assertSpotlight(spotlight, expectedIds, albums, `${role}_spotlight`);
  assertSuggestions(suggestions, albums, people, `${role}_suggestions`);
  assertSearch(grouped, expectedIds, albums, people, timeline, '毕业', `${role}_search`);
  check(grouped.semantic.total === semanticIds.size, `${role}_semantic_grouped_exact_total`);
  for (const item of grouped.semantic.items) {
    check(semanticIds.has(canonicalId(item?.id, `${role}_semantic_grouped_id`)), `${role}_semantic_grouped_set_mismatch`);
  }
  const searchIds = await completeSearchPhotoIds(page, grouped, expectedIds, role);
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
  await albumDetailsComplete(page, albums, expectedIds, forbiddenLivingIds, role);
  await peopleDetailsComplete(page, people, expectedIds, forbiddenLivingIds, role);
  await page.goto(new URL('/people', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  check(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, `${role}_people_surface`);
  await assertBrowserSurface(page, role);
  await save(page, `${role}-people`);
  await openSearch(page, role);
  return { state, timeline, home, albums, people, spotlight, suggestions, grouped, searchIds };
}

async function closeRoleContext(session, role) {
  check(session?.context !== null && session?.context !== undefined, `${role}_context_cleanup_missing`);
  await session.context.close();
  check(session.context.pages().length === 0, `${role}_context_cleanup_incomplete`);
}

async function main() {
  const results = new Map();
  let passRecord = null;
  stageAt('classmate_login');
  const classmateSession = await openRole('classmate', { width: 1440, height: 900 });
  let familySession = null;
  let classmateTimeline;
  try {
    classmateTimeline = await timelineCatalog(classmateSession.page, 'classmate_truth');
    const fullIds = new Set(classmateTimeline.keys());
    // The presentation timeline deliberately omits the internal HERITAGE /
    // LIVING enum. Derive the authoritative visible sets by comparing two
    // independently authenticated server projections instead of asking the
    // browser payload to expose an internal policy field.
    stageAt('family_scope_truth');
    familySession = await openRole('family', { width: 390, height: 844 });
    const familyTruthTimeline = await timelineCatalog(familySession.page, 'family_truth');
    const heritageIds = new Set(familyTruthTimeline.keys());
    check([...heritageIds].every((id) => fullIds.has(id)), 'family_truth_not_subset_of_full');
    const livingIds = new Set([...fullIds].filter((id) => !heritageIds.has(id)));
    check(heritageIds.size > 0 && heritageIds.size + livingIds.size === fullIds.size, 'owner_era_partition_invalid');
    const classmate = await inspectRole(classmateSession, 'classmate', fullIds, livingIds);
    results.set('classmate', classmate);
    const knownLiving = livingIds.size > 0 ? livingIds.values().next().value : null;
    const fullViewerPhoto = knownLiving ?? fullIds.values().next().value;
    await viewer(classmateSession.page, 'classmate', fullViewerPhoto);
    await classmateSession.page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    await assertHomeReady(classmateSession.page, 'classmate');
    await assertBrowserSurface(classmateSession.page, 'classmate');
    await save(classmateSession.page, 'classmate-home');

    stageAt('family_login');
    try {
      const family = await inspectRole(familySession, 'family', heritageIds, livingIds);
      results.set('family', family);
      if (knownLiving !== null) await assertFamilyKnownLivingDenied(familySession.page, knownLiving);
      const visibleHeritage = heritageIds.values().next().value;
      stageAt('family_viewer');
      await viewer(familySession.page, 'family', visibleHeritage);
      stageAt('family_comment_denial');
      await assertFamilyCommentServerDenied(familySession, visibleHeritage);
      stageAt('family_home');
      await familySession.page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await assertHomeReady(familySession.page, 'family');
      await assertBrowserSurface(familySession.page, 'family');
      await save(familySession.page, 'family-home');
    } finally {
      const session = familySession;
      familySession = null;
      await closeRoleContext(session, 'family');
    }

    for (const role of ['anonymous']) {
      stageAt(`${role}_login`);
      const session = await openRole(role, { width: 390, height: 844 });
      try {
        const expectedIds = fullIds;
        const inspection = await inspectRole(session, role, expectedIds, livingIds);
        results.set(role, inspection);
        await viewer(session.page, role, fullViewerPhoto);
        await session.page.goto(new URL('/home', photoOrigin).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
        await assertHomeReady(session.page, role);
        await assertBrowserSurface(session.page, role);
        await save(session.page, `${role}-home`);
      } finally { await closeRoleContext(session, role); }
    }

    stageAt('cross_role_scope_comparison');
    for (const role of fullRoles) {
      const result = results.get(role);
      check(result?.home?.scope === 'FULL', `${role}_full_scope`);
      check(setEquals(new Set(result.timeline.keys()), fullIds), `${role}_full_timeline`);
      compareMapsExact(result.albums, results.get('classmate').albums, 'total', `${role}_album_counts`);
      compareMapsExact(result.albums, results.get('classmate').albums, 'cover', `${role}_album_covers`);
      compareMapsExact(result.people, results.get('classmate').people, 'count', `${role}_people_counts`);
      compareMapsExact(result.people, results.get('classmate').people, 'cover', `${role}_people_covers`);
    }
    const family = results.get('family');
    check(family?.home?.scope === 'HERITAGE_ONLY' && setEquals(new Set(family.timeline.keys()), heritageIds), 'family_heritage_only_scope');
    compareMapsSubset(family.albums, results.get('classmate').albums, 'total', 'family_album_counts');
    compareMapsSubset(family.people, results.get('classmate').people, 'count', 'family_people_counts');
    check(unexpectedNetwork.size === 0, 'unexpected_network_request');
    check(forbiddenBusinessMutations.size === 0, 'forbidden_business_mutation_attempt');
    check(successfulBusinessWrites === 0, 'business_write_observed');
    const livingScopeEvidence = livingIds.size > 0 ? 'present_and_tested' : 'not_present_private_library';
    passRecord = `V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=${assertions} screenshots=${screenshots} roles=3 full_photos=${fullIds.size} heritage_photos=${heritageIds.size} living_photos=${livingIds.size} living_scope=${livingScopeEvidence} channel=chrome chrome_product=chrome chrome_version=${chromeVersion} writes=0`;
  } finally {
    if (familySession !== null) {
      const session = familySession;
      familySession = null;
      await closeRoleContext(session, 'family');
    }
    await closeRoleContext(classmateSession, 'classmate');
  }
  check(typeof passRecord === 'string', 'browser_pass_record_missing');
  process.stdout.write(`${passRecord}\n`);
}

try { await main(); }
catch (error) {
  try {
    const diagnosticPath = child(screenshotDir, 'failure.local.json', 'failure_diagnostic_path');
    fs.writeFileSync(diagnosticPath, JSON.stringify({
      name: typeof error?.name === 'string' ? error.name : 'Error',
      message: typeof error?.message === 'string' ? error.message : '',
      stack: typeof error?.stack === 'string' ? error.stack : '',
      stage,
    }, null, 2), { encoding: 'utf8', flag: 'wx', mode: 0o600 });
  } catch { /* The diagnostic must never replace the original fail-closed result. */ }
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/i.test(error.code) ? error.code : 'unexpected';
  process.stdout.write(`V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
}
