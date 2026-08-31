/*
 * Independent owner-private V4 acceptance for the pre-provisioned FQA-T
 * Teacher fixture. This runner receives exactly one short-lived browser
 * credential document from the companion lease broker; it never provisions an
 * identity, changes a seat, uploads media, comments, or otherwise writes to
 * the Owner library. The PowerShell wrapper owns the broker, watchdog,
 * credential-file ACL, snapshot comparison, and terminal refreeze.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

const PERSISTENT_RUN = '3e2f1a94b0c74d81952e6f0a';
const BROWSER_CREDENTIAL_ENV = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT';
const BROWSER_CREDENTIAL_ROOT_KEYS = 'environment,lease,roles,run,version';
const BROWSER_CREDENTIAL_LEASE_KEYS = 'role,roster';
const BROWSER_CREDENTIAL_ROLE_KEYS = 'password,username';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const PRIVATE_ROOT_BOUNDARY = '/.codex-work/private-real-qa/';
const CREDENTIAL_BOUNDARY = '/.codex-work/private-real-qa/runtime/photos-app-v4-owner-teacher-lease/';
const PROFILE_BOUNDARY = '/.codex-work/private-real-qa/browser/photos-app-v4-owner-teacher-lease/';
const SCREENSHOT_BOUNDARY = '/.codex-work/private-real-qa/screenshots/photos-app-v4/';
const SAFE_HTTP_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const SEARCH_CANDIDATES = Object.freeze(['\u73ed\u7ea7', '\u76f8\u518c', '\u6bd5\u4e1a', '\u7167\u7247']);
const CHROME_OWNER_TEACHER_LOCALHOST_ONLY_LAUNCH_ARGS = Object.freeze([
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

let assertions = 0;
let screenshots = 0;
let stage = 'initialization';
let chromeVersion = 'unknown';
let configuration = null;
let browserCredential = null;
let loginStillAllowed = true;
let activeSemanticQuery = null;
const unexpectedNetwork = new Set();
const forbiddenBusinessMutations = new Set();
let successfulBusinessWrites = 0;

function fail(code) { throw new GateError(code); }
function check(value, code) { assertions += 1; if (!value) fail(code); }
function stageAt(value) { stage = value; process.stdout.write(`V4_OWNER_TEACHER_FIXTURE_STAGE=${value}\n`); }

function setting(name, pattern) {
  const value = process.env[name] ?? '';
  check(pattern.test(value), `setting_${name.toLowerCase()}_invalid`);
  return value;
}

function normalizedPath(value) {
  return String(value).replaceAll('\\', '/').replace(/^\/\?\//, '/').toLowerCase();
}

function withinBoundary(value, boundary) {
  return normalizedPath(value).includes(boundary);
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

function privateExistingPath(name, boundary, kind, requireEmpty = false) {
  const raw = setting(name, /^[^\u0000]{8,2048}$/);
  check(path.isAbsolute(raw), `setting_${name.toLowerCase()}_absolute`);
  const resolved = path.resolve(raw);
  check(withinBoundary(resolved, PRIVATE_ROOT_BOUNDARY) && withinBoundary(resolved, boundary),
    `setting_${name.toLowerCase()}_boundary`);
  let stat;
  let real;
  try {
    stat = fs.lstatSync(resolved);
    real = path.resolve(fs.realpathSync.native(resolved));
  } catch { fail(`setting_${name.toLowerCase()}_missing`); }
  check(!stat.isSymbolicLink() && withinBoundary(real, PRIVATE_ROOT_BOUNDARY) && withinBoundary(real, boundary),
    `setting_${name.toLowerCase()}_reparse`);
  if (kind === 'file') {
    check(stat.isFile() && stat.size > 0 && stat.size <= 16 * 1024, `setting_${name.toLowerCase()}_file`);
  } else {
    check(stat.isDirectory(), `setting_${name.toLowerCase()}_directory`);
    if (requireEmpty) {
      let entries;
      try { entries = fs.readdirSync(real); } catch { fail(`setting_${name.toLowerCase()}_read`); }
      check(entries.length === 0, `setting_${name.toLowerCase()}_not_fresh`);
    }
  }
  return real;
}

function child(root, name, code) {
  check(/^[a-z0-9][a-z0-9_.-]{1,100}$/i.test(name), code);
  const target = path.resolve(root, name);
  const relative = path.relative(root, target);
  check(relative.length > 0 && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), code);
  check(!fs.existsSync(target), `${code}_exists`);
  return target;
}

function exactObjectKeys(value, expected, code) {
  check(value !== null && typeof value === 'object' && !Array.isArray(value), code);
  check(Object.keys(value).sort().join(',') === expected, code);
}

function configure() {
  const runId = setting('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID', /^[a-f0-9]{24}$/);
  check(runId === PERSISTENT_RUN, 'teacher_fixture_persistent_run_required');
  const value = {
    runId,
    coreOrigin: localOrigin('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CORE_ORIGIN', 8190),
    photoOrigin: localOrigin('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PHOTO_ORIGIN', 8191),
    credentialPath: privateExistingPath('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CREDENTIAL_FILE', CREDENTIAL_BOUNDARY, 'file'),
    profileRoot: privateExistingPath('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PROFILE_ROOT', PROFILE_BOUNDARY, 'directory', true),
    screenshotDir: privateExistingPath('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_SCREENSHOT_DIR', SCREENSHOT_BOUNDARY, 'directory', true),
  };
  return Object.freeze(value);
}

function readBrowserCredential() {
  let document;
  try { document = JSON.parse(fs.readFileSync(configuration.credentialPath, 'utf8')); }
  catch { fail('credential_document_invalid'); }
  check(document?.version === 1 && document.environment === BROWSER_CREDENTIAL_ENV && document.run === configuration.runId,
    'credential_document_scope');
  // The runner accepts only the one browser-facing credential. Recovery
  // verifiers, hashes, claims, and fixture internals are rejected by exact
  // shape before any value can reach Chrome, a screenshot, or stdout.
  exactObjectKeys(document, BROWSER_CREDENTIAL_ROOT_KEYS, 'credential_document_shape');
  exactObjectKeys(document.lease, BROWSER_CREDENTIAL_LEASE_KEYS, 'credential_lease_shape');
  check(document.lease?.role === 'TEACHER'
    && document.lease?.roster === `FQA-T-${configuration.runId.toUpperCase()}`,
  'credential_lease_scope');
  exactObjectKeys(document.roles, 'teacher', 'credential_role_shape');
  const value = document.roles.teacher;
  exactObjectKeys(value, BROWSER_CREDENTIAL_ROLE_KEYS, 'credential_teacher_shape');
  check(value?.username === `fqa_t_${configuration.runId}_teacher`
    && typeof value?.password === 'string' && /^[A-Za-z0-9_-]{64}$/.test(value.password),
  'credential_teacher_invalid');
  const credential = Object.freeze({ username: value.username, password: value.password });
  document = null;
  return credential;
}

function allowedUrl(value) {
  if (['about:', 'blob:', 'data:'].includes(value.protocol)) return true;
  return value.protocol === 'http:' && value.hostname === '127.0.0.1'
    && [configuration.coreOrigin.port, configuration.photoOrigin.port].includes(value.port);
}

function isUnsafeRequest(request) {
  return !SAFE_HTTP_METHODS.has(request.method());
}

function isAllowedLoginPost(request, target) {
  if (!loginStillAllowed || request.method() !== 'POST'
    || target.origin !== configuration.coreOrigin.origin || target.pathname !== '/identification.php') return false;
  const contentType = request.headers()['content-type'] ?? '';
  if (!contentType.toLowerCase().startsWith('application/x-www-form-urlencoded')) return false;
  let fields;
  try { fields = new URLSearchParams(request.postData() ?? ''); }
  catch { return false; }
  return fields.getAll('username').length === 1 && fields.get('username') === browserCredential.username
    && fields.getAll('password').length === 1 && fields.get('password') === browserCredential.password
    && fields.getAll('login').length === 1;
}

function isAllowedSmartSearchProbe(request, target) {
  if (!(typeof activeSemanticQuery === 'string' && activeSemanticQuery.length > 0
    && request.method() === 'POST' && target.origin === configuration.photoOrigin.origin
    && target.pathname === '/api/search/smart' && target.search === '')) return false;
  const contentType = request.headers()['content-type'] ?? '';
  if (contentType.split(';', 1)[0].trim().toLowerCase() !== 'application/json') return false;
  try {
    const payload = JSON.parse(request.postData() ?? '');
    return payload && typeof payload === 'object' && !Array.isArray(payload)
      && Object.keys(payload).length === 1 && payload.query === activeSemanticQuery;
  } catch { return false; }
}

function isSuccessfulBusinessMutation(response) {
  const request = response.request();
  if (!isUnsafeRequest(request) || response.status() < 200 || response.status() >= 300) return false;
  let target;
  try { target = new URL(request.url()); } catch { return true; }
  return target.origin === configuration.photoOrigin.origin
    && target.pathname.startsWith('/api/') && target.pathname !== '/api/search/smart';
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

async function gotoOwned(page, target, code) {
  try {
    await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  } catch (error) {
    const aborted = /net::ERR_ABORTED/i.test(String(error?.message ?? ''));
    const current = (() => { try { return new URL(page.url()); } catch { return null; } })();
    if (!aborted || current === null || current.origin !== target.origin || current.pathname !== target.pathname) throw error;
  }
  const current = new URL(page.url());
  check(current.origin === target.origin && current.pathname === target.pathname, code);
}

async function gotoCoreLoginBridge(page) {
  const bridge = new URL('/class-archive-core/login', configuration.photoOrigin);
  await page.goto(bridge.href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  // The photo BFF intentionally delegates the credential form to the
  // localhost-only Piwigo core origin. This is a successful bridge, not a
  // cross-origin escape: both origins are explicitly allowlisted and the
  // subsequent POST is constrained to this exact form action.
  const current = new URL(page.url());
  check(current.origin === configuration.coreOrigin.origin
    && current.pathname === '/identification.php', 'teacher_login_route');
}

async function assertHomeReady(page) {
  check(await page.locator('[data-home-all-photos="true"]').waitFor({ state: 'visible', timeout: 30_000 })
    .then(() => true).catch(() => false), 'teacher_home_projection');
  check(await page.locator('.photo-loading').count() === 0, 'teacher_home_loading');
  check(await page.waitForFunction(() => [...document.images]
    .some((image) => image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0),
  undefined, { timeout: 30_000 }).then(() => true).catch(() => false), 'teacher_home_image');
}

async function openTeacher() {
  const profile = child(configuration.profileRoot, 'teacher-1440x900', 'profile_child_invalid');
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport: { width: 1440, height: 900 },
      screen: { width: 1440, height: 900 },
      locale: 'zh-CN',
      timezoneId: 'Asia/Shanghai',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: ['--no-first-run', '--no-default-browser-check', ...CHROME_OWNER_TEACHER_LOCALHOST_ONLY_LAUNCH_ARGS],
    });
    await context.route('**/*', (route) => {
      let target;
      try { target = new URL(route.request().url()); }
      catch { unexpectedNetwork.add('invalid'); return route.abort(); }
      if (!allowedUrl(target)) { unexpectedNetwork.add('external'); return route.abort(); }
      if (isUnsafeRequest(route.request())
        && !isAllowedLoginPost(route.request(), target)
        && !isAllowedSmartSearchProbe(route.request(), target)) {
        forbiddenBusinessMutations.add(`${route.request().method()}:${target.pathname}`);
        return route.abort();
      }
      return route.continue();
    });
    context.on('response', (response) => {
      if (isSuccessfulBusinessMutation(response)) successfulBusinessWrites += 1;
    });
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStable(context, page);
    await gotoCoreLoginBridge(page);
    const form = page.locator('form[name="login_form"]');
    check(await form.count() === 1, 'teacher_login_form');
    await form.locator('input[name="username"]').fill(browserCredential.username);
    await form.locator('input[name="password"]').fill(browserCredential.password);
    const reached = page.waitForURL((value) => value.origin === configuration.photoOrigin.origin && value.pathname === '/home', { timeout: 45_000 })
      .then(() => true).catch(() => false);
    await form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click();
    check(await reached, 'teacher_login_bridge');
    loginStillAllowed = false;
    browserCredential = Object.freeze({ username: '', password: '' });
    check(await page.locator('[data-photo-app="true"]').waitFor({ state: 'attached', timeout: 30_000 })
      .then(() => true).catch(() => false), 'teacher_photo_shell');
    await assertHomeReady(page);
    return { context, page };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(context === null ? 'chrome_stable_launch' : 'teacher_chrome_unexpected');
  }
}

async function save(page, name) {
  await page.screenshot({ path: child(configuration.screenshotDir, `${name}.png`, 'screenshot_child_invalid'), fullPage: false });
  screenshots += 1;
}

async function browserFetch(page, target, init = {}) {
  check(typeof target === 'string' && /^\/(?!\/)[^\u0000]{1,512}$/.test(target), 'browser_fetch_target');
  const result = await page.evaluate(async ({ relative, options }) => {
    try {
      const response = await fetch(relative, { credentials: 'same-origin', cache: 'no-store', ...options });
      const contentType = response.headers.get('content-type') ?? '';
      let raw = '';
      if ((options?.method ?? 'GET') !== 'HEAD' && contentType.toLowerCase().includes('application/json')) {
        raw = await response.text();
      }
      let json = null;
      if (raw !== '') { try { json = JSON.parse(raw); } catch { /* deliberate bounded parse failure */ } }
      return { status: response.status, contentType, json };
    } catch { return { status: 0, contentType: '', json: null }; }
  }, { relative: target, options: init });
  check(Number.isInteger(result?.status) && typeof result?.contentType === 'string', 'browser_fetch_shape');
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
  check(typeof value === 'string' && UUID.test(value), code);
  return value.toLowerCase();
}

async function assertProductState(page) {
  const state = await requiredJson(page, '/api/class-archive/product-state', 'teacher_product_state');
  check(state.role === 'TEACHER', 'teacher_role');
  check(state.canEraUpload === true && state.canFamilySubmission === false && state.canManage === false
    && state.canSpotlight === true, 'teacher_capability_projection');
  check(typeof state.cacheScope === 'string' && /^[a-f0-9]{32}$/i.test(state.cacheScope), 'teacher_cache_scope');
  check(typeof state.csrfToken === 'string' && state.csrfToken.length >= 16, 'teacher_csrf_projection');
  return state;
}

async function firstTimelinePhoto(page) {
  const timeline = await requiredJson(page, '/api/class-archive/timeline?limit=120', 'teacher_timeline');
  check(Number.isInteger(timeline.total) && timeline.total > 0 && Array.isArray(timeline.groups), 'teacher_timeline_shape');
  for (const group of timeline.groups) {
    if (!Array.isArray(group?.items)) continue;
    for (const item of group.items) return canonicalId(item?.id, 'teacher_timeline_photo_id');
  }
  fail('teacher_timeline_photo_missing');
}

async function assertTeacherAffordances(page) {
  const trigger = page.locator('[data-avatar-menu-trigger="true"]');
  check(await trigger.count() === 1, 'teacher_avatar_trigger');
  await trigger.click();
  const dialog = page.locator('dialog.avatar-dialog[open]');
  check(await dialog.waitFor({ state: 'visible', timeout: 20_000 }).then(() => true).catch(() => false), 'teacher_avatar_dialog');
  const menuPaths = await dialog.locator('a.avatar-menu-link').evaluateAll((links) => links.map((link) => {
    const target = new URL(link.href);
    return target.pathname;
  }));
  check(menuPaths.includes('/my') && menuPaths.includes('/class-archive-about') && menuPaths.includes('/class-archive-core/logout'),
    'teacher_avatar_expected_entries');
  check(!menuPaths.includes('/class-archive-core/admin') && !menuPaths.includes('/people/manage')
    && !menuPaths.includes('/class-archive-core/identity'), 'teacher_avatar_privileged_or_seat_entry');
  await dialog.locator('.dialog-close').click();
  check(await dialog.waitFor({ state: 'detached', timeout: 10_000 }).then(() => true).catch(() => false), 'teacher_avatar_close');

  await gotoOwned(page, new URL('/my', configuration.photoOrigin), 'teacher_my_route');
  check(await page.locator('a.profile-link').first().waitFor({ state: 'visible', timeout: 30_000 })
    .then(() => true).catch(() => false), 'teacher_my_profile_ready');
  const profileLinks = await page.locator('a.profile-link').evaluateAll((links) => links.map((link) => {
    const target = new URL(link.href);
    return { path: target.pathname, text: (link.textContent ?? '').trim() };
  }));
  const profilePaths = profileLinks.map((entry) => entry.path);
  check(profilePaths.includes('/class-archive-core/identity') && profilePaths.includes('/class-archive-core/home')
    && profilePaths.includes('/class-archive-about'), 'teacher_my_expected_entries');
  check(!profilePaths.includes('/class-archive-core/admin') && !profilePaths.includes('/people/manage')
    && !profileLinks.some((entry) => /(?:\u9080\u8bf7\u5bb6\u5ead|\u533f\u540d\u5e2d|\u6211\u7684\u6295\u7a3f|\u7ba1\u7406\u63a7\u5236\u53f0)/.test(entry.text)),
  'teacher_family_anonymous_admin_affordance');
  const managePeople = await browserFetch(page, '/api/class-archive/manage/people');
  const manageOptions = await browserFetch(page, '/api/class-archive/manage/options');
  check(managePeople.status === 403 && manageOptions.status === 403, 'teacher_manage_api_denied');
}

async function assertLibraryAndAlbums(page) {
  await gotoOwned(page, new URL('/photos', configuration.photoOrigin), 'teacher_library_route');
  const cards = page.locator('a.photo-card');
  check(await cards.first().waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false), 'teacher_library_cards');
  await save(page, 'teacher-library');

  await gotoOwned(page, new URL('/albums', configuration.photoOrigin), 'teacher_albums_route');
  const album = page.locator('a.album-card').first();
  check(await album.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false), 'teacher_album_cards');
  const href = await album.getAttribute('href');
  const match = typeof href === 'string' ? /^\/albums\/([0-9a-f-]{36})$/i.exec(href) : null;
  check(match !== null, 'teacher_album_route_shape');
  await gotoOwned(page, new URL(`/albums/${canonicalId(match[1], 'teacher_album_id')}`, configuration.photoOrigin), 'teacher_album_detail_route');
  check(await page.locator('a.photo-card').first().waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false),
    'teacher_album_detail_cards');
  await save(page, 'teacher-albums');
}

async function assertPeople(page) {
  await gotoOwned(page, new URL('/people', configuration.photoOrigin), 'teacher_people_route');
  const person = page.locator('a.person-card').first();
  check(await person.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false), 'teacher_people_cards');
  check(await page.locator('a[href="/people/manage"]').count() === 0, 'teacher_people_manage_affordance');
  const href = await person.getAttribute('href');
  const match = typeof href === 'string' ? /^\/people\/([0-9a-f-]{36})$/i.exec(href) : null;
  check(match !== null, 'teacher_person_route_shape');
  await gotoOwned(page, new URL(`/people/${canonicalId(match[1], 'teacher_person_id')}`, configuration.photoOrigin), 'teacher_person_detail_route');
  check(await page.locator('a.photo-card').first().waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false),
    'teacher_person_detail_cards');
  await save(page, 'teacher-people');
}

function groupedSearchCount(payload) {
  const structured = ['people', 'albums', 'events', 'archiveTime']
    .reduce((total, key) => total + (Array.isArray(payload?.[key]?.items) ? payload[key].items.length : 0), 0);
  const photos = Array.isArray(payload?.photos?.items) ? payload.photos.items.length : 0;
  const semantic = Array.isArray(payload?.semantic?.items) ? payload.semantic.items.length : 0;
  return structured + photos + semantic;
}

async function chooseSearchQuery(page) {
  for (const candidate of SEARCH_CANDIDATES) {
    const query = encodeURIComponent(candidate);
    const payload = await requiredJson(page, `/api/class-archive/search/grouped?q=${query}&contextType=ALL&limit=24`, 'teacher_search_candidate');
    if (groupedSearchCount(payload) > 0) return candidate;
  }
  fail('teacher_search_fixture_missing');
}

async function assertSearch(page) {
  await gotoOwned(page, new URL('/home', configuration.photoOrigin), 'teacher_search_home_route');
  const trigger = page.locator('[data-global-search-trigger="true"]').first();
  check(await trigger.count() === 1, 'teacher_search_trigger');
  const query = await chooseSearchQuery(page);
  await trigger.click();
  const dialog = page.locator('dialog[data-search-overlay="true"][open]');
  check(await dialog.waitFor({ state: 'visible', timeout: 20_000 }).then(() => true).catch(() => false), 'teacher_search_dialog');
  const input = dialog.locator('.global-search-input');
  check(await input.count() === 1 && await input.evaluate((node) => document.activeElement === node), 'teacher_search_focus');
  activeSemanticQuery = query;
  try {
    await input.fill(query);
    await input.press('Enter');
    const results = dialog.locator('.global-search-results');
    check(await results.waitFor({ state: 'visible', timeout: 45_000 }).then(() => true).catch(() => false), 'teacher_search_results');
    check(await dialog.locator('.error-state').count() === 0 && await dialog.locator('.photo-loading').count() === 0,
      'teacher_search_safe_render');
    await save(page, 'teacher-search');
  } finally {
    await page.keyboard.press('Escape').catch(() => null);
    activeSemanticQuery = null;
  }
  check(await page.locator('dialog[data-search-overlay="true"][open]').count() === 0, 'teacher_search_close');
}

async function assertMediaAndViewer(page, photoId) {
  const asset = await requiredJson(page, `/api/assets/${photoId}`, 'teacher_asset');
  check(canonicalId(asset?.id, 'teacher_asset_id') === photoId
    && asset?.thumbnailPath === `/api/assets/${photoId}/thumbnail?size=thumbnail`,
  'teacher_asset_canonical_media_path');
  const preview = await browserFetch(page, `/api/assets/${photoId}/thumbnail?size=preview`, { method: 'HEAD' });
  const original = await browserFetch(page, `/api/assets/${photoId}/original`, { method: 'HEAD' });
  check(preview.status === 200 && /^image\//i.test(preview.contentType), 'teacher_preview_head_mediaguard');
  check(original.status === 200 && /^image\//i.test(original.contentType), 'teacher_original_head_mediaguard');

  await gotoOwned(page, new URL(`/photos/${photoId}`, configuration.photoOrigin), 'teacher_viewer_route');
  const image = page.locator('.viewer-image');
  check(await image.waitFor({ state: 'visible', timeout: 45_000 }).then(() => true).catch(() => false), 'teacher_viewer_image');
  check(await image.evaluate((node) => node instanceof HTMLImageElement && node.complete && node.naturalWidth > 0 && node.naturalHeight > 0),
    'teacher_viewer_decode');
  const source = await image.getAttribute('src');
  let mediaSource;
  try { mediaSource = new URL(source ?? '', configuration.photoOrigin); }
  catch { fail('teacher_viewer_source_invalid'); }
  check(mediaSource.origin === configuration.photoOrigin.origin
    && mediaSource.pathname === `/api/assets/${photoId}/thumbnail`
    && mediaSource.searchParams.get('size') === 'preview'
    && [...mediaSource.searchParams.keys()].every((key) => key === 'size' || key === 'v'),
  'teacher_viewer_mediaguard_path');
  check(await page.locator('.viewer-comments').count() === 1 && await page.locator('.comment-composer').count() === 1,
    'teacher_viewer_comment_affordance');
  await save(page, 'teacher-viewer');
}

async function closeTeacherContext(session) {
  check(session?.context !== null && session?.context !== undefined, 'teacher_context_cleanup_missing');
  await session.context.close();
  check(session.context.pages().length === 0, 'teacher_context_cleanup_incomplete');
}

function writeFailureArtifact(code) {
  if (configuration === null) return;
  try {
    const diagnosticPath = child(configuration.screenshotDir, 'failure.local.json', 'failure_diagnostic_path');
    fs.writeFileSync(diagnosticPath, JSON.stringify({ version: 1, stage, code }), {
      encoding: 'utf8', flag: 'wx', mode: 0o600,
    });
  } catch {
    // A local diagnostic cannot replace the deterministic fail-closed result.
  }
}

async function main() {
  configuration = configure();
  browserCredential = readBrowserCredential();
  let session = null;
  let passRecord = null;
  try {
    stageAt('teacher_login');
    session = await openTeacher();
    const { page } = session;
    stageAt('teacher_product_state');
    await assertProductState(page);
    const photoId = await firstTimelinePhoto(page);
    stageAt('teacher_home');
    await assertHomeReady(page);
    check(await page.getByRole('button', { name: '\u4e0a\u4f20', exact: true }).count() === 1, 'teacher_upload_affordance');
    await save(page, 'teacher-home');
    stageAt('teacher_role_affordances');
    await assertTeacherAffordances(page);
    stageAt('teacher_library_albums');
    await assertLibraryAndAlbums(page);
    stageAt('teacher_people');
    await assertPeople(page);
    stageAt('teacher_search');
    await assertSearch(page);
    stageAt('teacher_media_viewer');
    await assertMediaAndViewer(page, photoId);
    check(unexpectedNetwork.size === 0, 'teacher_unexpected_network');
    check(forbiddenBusinessMutations.size === 0, 'teacher_forbidden_business_mutation');
    check(successfulBusinessWrites === 0, 'teacher_business_write_observed');
    passRecord = `V4_OWNER_TEACHER_FIXTURE_CHROME_QA=PASS assertions=${assertions} screenshots=${screenshots} role=TEACHER channel=chrome chrome_product=chrome chrome_version=${chromeVersion} browse=home_library_albums_people_search_viewer media=mediaguard_api_paths writes=0`;
  } finally {
    if (session !== null) await closeTeacherContext(session);
    browserCredential = null;
    activeSemanticQuery = null;
  }
  check(typeof passRecord === 'string', 'teacher_browser_pass_record_missing');
  process.stdout.write(`${passRecord}\n`);
}

try { await main(); }
catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/i.test(error.code) ? error.code : 'unexpected';
  writeFailureArtifact(code);
  process.stdout.write(`V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
}
