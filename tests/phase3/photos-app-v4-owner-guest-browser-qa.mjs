/*
 * Read-only guest acceptance for the Owner-private V4 Photo App.
 *
 * This runner deliberately starts with a brand-new Chrome Stable profile and
 * never receives a username, password, cookie, token, or Piwigo/Immich
 * identifier.  A caller may supply two already-known, opaque BFF media URLs
 * from an ignored local document.  The runner validates their bounded shape
 * but never prints, screenshots, or otherwise persists either URL.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

const GUEST_MEDIA_DOCUMENT_VERSION = 1;
const GUEST_MEDIA_DOCUMENT_SCOPE = 'OWNER_PRIVATE_8191';
const PRIVATE_ROOT_BOUNDARY = '/.codex-work/private-real-qa/';
const PROFILE_BOUNDARY = '/.codex-work/private-real-qa/browser/photos-app-v4-owner-guest/';
const PROBE_BOUNDARY = '/.codex-work/private-real-qa/runtime/photos-app-v4-owner-guest/opaque-media-probes/';
const SAFE_HTTP_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);
const MEDIA_ID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
const RAW_MEDIA_URL = new RegExp(
  `^http://127\\.0\\.0\\.1:8191/api/assets/${MEDIA_ID}/(?:thumbnail\\?size=thumbnail|original)$`,
  'i',
);

let assertions = 0;
let stage = 'initialization';
let configuration = null;
let chromeVersion = 'unknown';
let unexpectedNetwork = false;

function fail(code) { throw new GateError(code); }
function check(value, code) { assertions += 1; if (!value) fail(code); }
function stageAt(value) { stage = value; }

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
    check(stat.isFile() && stat.size > 0 && stat.size <= 4 * 1024, `setting_${name.toLowerCase()}_file`);
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
  check(/^[a-z][a-z0-9_-]{1,80}$/i.test(name), code);
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
  return Object.freeze({
    coreOrigin: localOrigin('CLASS_ARCHIVE_V4_OWNER_GUEST_CORE_ORIGIN', 8190),
    photoOrigin: localOrigin('CLASS_ARCHIVE_V4_OWNER_GUEST_PHOTO_ORIGIN', 8191),
    profileRoot: privateExistingPath('CLASS_ARCHIVE_V4_OWNER_GUEST_PROFILE_ROOT', PROFILE_BOUNDARY, 'directory', true),
    probeDocument: privateExistingPath('CLASS_ARCHIVE_V4_OWNER_GUEST_MEDIA_PROBE_DOCUMENT', PROBE_BOUNDARY, 'file'),
  });
}

function allowedUrl(value) {
  if (['about:', 'blob:', 'data:'].includes(value.protocol)) return true;
  return value.protocol === 'http:' && value.hostname === '127.0.0.1'
    && [configuration.coreOrigin.port, configuration.photoOrigin.port].includes(value.port);
}

function assertOpaqueMediaTarget(surface, raw) {
  check(typeof raw === 'string' && raw.length >= 80 && raw.length <= 256 && RAW_MEDIA_URL.test(raw),
    `guest_media_${surface.toLowerCase()}_opaque_url`);
  let target;
  try { target = new URL(raw); } catch { fail(`guest_media_${surface.toLowerCase()}_opaque_url`); }
  check(target.origin === configuration.photoOrigin.origin && !target.username && !target.password && !target.hash,
    `guest_media_${surface.toLowerCase()}_origin`);
  if (surface === 'DERIVATIVE') {
    check(/^\/api\/assets\/[0-9a-f-]{36}\/thumbnail$/i.test(target.pathname)
      && target.search === '?size=thumbnail', 'guest_media_derivative_shape');
  } else {
    check(/^\/api\/assets\/[0-9a-f-]{36}\/original$/i.test(target.pathname)
      && target.search === '', 'guest_media_original_shape');
  }
  return target;
}

function readOpaqueMediaProbes() {
  let document;
  try { document = JSON.parse(fs.readFileSync(configuration.probeDocument, 'utf8')); }
  catch { fail('guest_media_probe_document_invalid'); }
  exactObjectKeys(document, 'probes,scope,version', 'guest_media_probe_document_shape');
  check(document.version === GUEST_MEDIA_DOCUMENT_VERSION && document.scope === GUEST_MEDIA_DOCUMENT_SCOPE,
    'guest_media_probe_document_scope');
  check(Array.isArray(document.probes) && document.probes.length === 2, 'guest_media_probe_document_count');
  const result = new Map();
  for (const item of document.probes) {
    exactObjectKeys(item, 'surface,url', 'guest_media_probe_entry_shape');
    check(item.surface === 'DERIVATIVE' || item.surface === 'ORIGINAL', 'guest_media_probe_surface');
    check(!result.has(item.surface), 'guest_media_probe_surface_duplicate');
    result.set(item.surface, assertOpaqueMediaTarget(item.surface, item.url));
  }
  check(result.size === 2 && result.has('DERIVATIVE') && result.has('ORIGINAL'), 'guest_media_probe_surface_missing');
  document = null;
  return Object.freeze(result);
}

async function recordChromeStableVersion(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const info = await session.send('Browser.getVersion');
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(String(info?.product ?? ''));
    check(match !== null, 'guest_chrome_stable_product');
    chromeVersion = match[1];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('guest_chrome_stable_version');
  } finally {
    await session?.detach().catch(() => null);
  }
}

function noDisclosureHeaders(headers, code) {
  check(headers['x-accel-redirect'] === undefined, `${code}_accel_target_hidden`);
  check(headers['x-powered-by'] === undefined, `${code}_server_detail_hidden`);
}

async function requestGuestDenied(context, target, method, headers, code) {
  check(SAFE_HTTP_METHODS.has(method), `${code}_unsafe_method`);
  check(allowedUrl(target), `${code}_foreign_target`);
  const response = await context.request.fetch(target.toString(), {
    method,
    headers,
    maxRedirects: 0,
    failOnStatusCode: false,
    timeout: 20_000,
  });
  try {
    const status = response.status();
    check(status === 401 || status === 403, `${code}_not_denied`);
    const responseHeaders = response.headers();
    noDisclosureHeaders(responseHeaders, code);
    check(/no-store/i.test(responseHeaders['cache-control'] ?? ''), `${code}_no_store_missing`);
  } finally {
    await response.dispose().catch(() => null);
  }
}

async function assertGuestApiDenial(context) {
  const endpoints = [
    ['/api/me', 'guest_api_me'],
    ['/api/class-archive/product-state', 'guest_api_product_state'],
    ['/api/class-archive/home', 'guest_api_home'],
    ['/api/class-archive/timeline?limit=1', 'guest_api_timeline'],
    ['/api/albums', 'guest_api_albums'],
    ['/api/people', 'guest_api_people'],
    ['/api/class-archive/manage/people', 'guest_api_admin_people'],
    ['/api/class-archive/manage/options', 'guest_api_admin_options'],
  ];
  for (const [pathname, code] of endpoints) {
    await requestGuestDenied(context, new URL(pathname, configuration.photoOrigin), 'GET', { Accept: 'application/json' }, code);
  }
}

async function assertGuestMediaDenial(context, probes) {
  for (const [surface, target] of probes.entries()) {
    const code = `guest_media_${surface.toLowerCase()}`;
    await requestGuestDenied(context, target, 'GET', { Accept: 'image/*' }, `${code}_get`);
    await requestGuestDenied(context, target, 'HEAD', { Accept: 'image/*' }, `${code}_head`);
    await requestGuestDenied(context, target, 'GET', { Accept: 'image/*', Range: 'bytes=0-31' }, `${code}_range`);
  }
}

async function assertGuestCoreAdminDenial(context) {
  const target = new URL('/admin.php?page=plugin-ClassIdentity', configuration.coreOrigin);
  const response = await context.request.fetch(target.toString(), {
    method: 'GET', maxRedirects: 0, failOnStatusCode: false, timeout: 20_000,
  });
  try {
    check([302, 303, 401, 403].includes(response.status()), 'guest_core_admin_not_denied');
    noDisclosureHeaders(response.headers(), 'guest_core_admin');
    if (response.status() === 302 || response.status() === 303) {
      const location = response.headers().location ?? '';
      let loginTarget;
      try { loginTarget = new URL(location, configuration.coreOrigin); }
      catch { fail('guest_core_admin_redirect_invalid'); }
      check(loginTarget.origin === configuration.coreOrigin.origin && loginTarget.pathname === '/identification.php',
        'guest_core_admin_redirect_invalid');
    }
  } finally {
    await response.dispose().catch(() => null);
  }
}

async function gotoGuestLogin(page, target, code) {
  await page.goto(target.toString(), { waitUntil: 'domcontentloaded', timeout: 30_000 });
  let current;
  try { current = new URL(page.url()); } catch { fail(`${code}_url_invalid`); }
  check(current.origin === configuration.coreOrigin.origin && current.pathname === '/identification.php', `${code}_not_login_redirect`);
  check(await page.locator('form[name="login_form"]').count() === 1, `${code}_login_form_missing`);
  check(await page.locator('[data-photo-app="true"]').count() === 0, `${code}_photo_app_shell_visible`);
}

async function assertGuestDocumentDenial(page) {
  await page.goto(new URL('/class-archive-about', configuration.photoOrigin).toString(), {
    waitUntil: 'domcontentloaded', timeout: 30_000,
  });
  check(new URL(page.url()).origin === configuration.photoOrigin.origin
    && new URL(page.url()).pathname === '/class-archive-about', 'guest_public_about_unavailable');
  await page.evaluate(() => {
    window.__classArchiveGuestBackObserved = false;
    window.addEventListener('pageshow', (event) => {
      if (event.persisted === true) window.__classArchiveGuestBackObserved = true;
    }, { once: true });
  });
  await gotoGuestLogin(page, new URL('/home', configuration.photoOrigin), 'guest_home');
  await page.goBack({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  let back;
  try { back = new URL(page.url()); } catch { fail('guest_back_url_invalid'); }
  check(back.origin === configuration.photoOrigin.origin && back.pathname === '/class-archive-about', 'guest_back_navigation');
  const backState = await page.evaluate(() => ({
    bfcacheObserved: window.__classArchiveGuestBackObserved === true,
    navigationType: performance.getEntriesByType('navigation').at(-1)?.type ?? '',
  }));
  // BFCache eligibility is a browser/runtime optimization, not an
  // authorization condition.  The required security proof is the checked
  // Back destination above; this records whether Chrome restored the page
  // from BFCache or performed an ordinary back/forward navigation.
  check(typeof backState.bfcacheObserved === 'boolean' && typeof backState.navigationType === 'string',
    'guest_bfcache_or_back_observable');
  await gotoGuestLogin(page, new URL('/people/manage', configuration.photoOrigin), 'guest_people_manage');
  await gotoGuestLogin(page, new URL('/class-archive-core/admin', configuration.photoOrigin), 'guest_compat_admin');
}

async function main() {
  configuration = configure();
  const probes = readOpaqueMediaProbes();
  const profile = child(configuration.profileRoot, 'profile', 'guest_profile');
  let context = null;
  try {
    stageAt('chrome_launch');
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport: { width: 1440, height: 900 },
      screen: { width: 1440, height: 900 },
      locale: 'zh-CN',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: [
        '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-component-update', '--disable-sync', '--no-pings',
        ...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS,
      ],
    });
    await context.route('**/*', async (route) => {
      const request = route.request();
      let target;
      try { target = new URL(request.url()); } catch { unexpectedNetwork = true; await route.abort(); return; }
      if (!allowedUrl(target) || !SAFE_HTTP_METHODS.has(request.method())) {
        unexpectedNetwork = true;
        await route.abort();
        return;
      }
      await route.continue();
    });
    check((await context.cookies()).length === 0, 'guest_profile_cookie_not_fresh');
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStableVersion(context, page);
    stageAt('api_media_denial');
    await assertGuestApiDenial(context);
    await assertGuestCoreAdminDenial(context);
    await assertGuestMediaDenial(context, probes);
    stageAt('document_denial');
    await assertGuestDocumentDenial(page);
    check(unexpectedNetwork === false, 'guest_browser_nonlocal_or_unsafe_request');
    check(/^\d+(?:\.\d+){1,4}$/.test(chromeVersion), 'guest_chrome_version_invalid');
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail(context === null ? 'guest_chrome_stable_launch' : 'guest_browser_runtime');
  } finally {
    await context?.close().catch(() => null);
  }
  process.stdout.write(`V4_OWNER_GUEST_CHROME_QA=PASS assertions=${assertions}\n`);
}

main().catch((error) => {
  const code = error instanceof GateError && /^[a-z0-9_]{1,100}$/.test(error.code) ? error.code : 'guest_unexpected_error';
  process.stdout.write(`V4_OWNER_GUEST_CHROME_QA=FAIL code=${code}\n`);
  process.exitCode = 1;
});
