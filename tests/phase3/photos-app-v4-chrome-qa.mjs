import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
class GateError extends Error { constructor(code) { super(code); this.code = code; } }
let assertions = 0;
let stage = 'initialization';
let chromeVersion = 'unknown';
let chromeProduct = 'unknown';
let screenshots = 0;
function fail(code) { throw new GateError(code); }
function check(value, code) { assertions += 1; if (!value) fail(code); }
function stageAt(value) { stage = value; process.stdout.write(`V4_CHROME_STAGE=${value}\n`); }
const settings = Object.freeze({
  piwigo: process.env.CLASS_ARCHIVE_V4_PIWIGO_ORIGIN,
  photos: process.env.CLASS_ARCHIVE_V4_PHOTO_ORIGIN,
  credentials: process.env.CLASS_ARCHIVE_V4_CREDENTIAL_FILE,
  userDataRoot: process.env.CLASS_ARCHIVE_V4_USER_DATA_ROOT,
  screenshots: process.env.CLASS_ARCHIVE_V4_SCREENSHOT_DIR,
});

function localOrigin(value, port, code) {
  let url; try { url = new URL(value); } catch { fail(code); }
  check(url.protocol === 'http:' && url.hostname === '127.0.0.1' && url.port === String(port)
    && url.pathname === '/' && !url.username && !url.password && !url.search && !url.hash, code);
  return url.toString();
}
function privatePath(value, code) { check(typeof value === 'string' && path.isAbsolute(value) && !value.includes('\0'), code); return path.resolve(value); }
function child(root, name, code) {
  const base = privatePath(root, code); const target = path.resolve(base, name);
  check(target.startsWith(`${base}${path.sep}`), code); return target;
}
function writeFailureArtifact(error, code) {
  // Browser evidence stays ignored and local, but still never persist a
  // password, cookie, token, or arbitrary URL/query value when diagnosing a
  // runner failure. The bounded category/message below is enough to repair a
  // broken acceptance harness without weakening its stdout boundary.
  try {
    const original = typeof error?.message === 'string' ? error.message : '';
    const message = original
      .replace(/https?:\/\/[^\s)'"\]]+/gi, '[url]')
      .replace(/[A-Za-z0-9._~-]{24,}/g, '[redacted]')
      .slice(0, 600);
    const artifact = {
      version: 1,
      stage,
      code,
      errorType: typeof error?.name === 'string' ? error.name.slice(0, 80) : 'Error',
      message,
    };
    fs.writeFileSync(child(settings.screenshots, 'failure.json', 'failure_artifact_child'), `${JSON.stringify(artifact)}\n`, { encoding: 'utf8', flag: 'w' });
  } catch {
    // The gate's primary failure output remains deterministic even if the
    // local-only diagnostic artifact cannot be written.
  }
}
function readCredentials() {
  let doc; try { doc = JSON.parse(fs.readFileSync(privatePath(settings.credentials, 'credential_path'), 'utf8')); } catch { fail('credential_document'); }
  check(doc?.version === 1 && doc.environment === 'synthetic', 'credential_shape');
  check(Object.keys(doc.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_roles');
  for (const role of ['classmate', 'family', 'teacher', 'anonymous']) {
    const item = doc.roles[role];
    check(typeof item?.username === 'string' && item.username.length > 0 && item.username.length <= 190, `credential_${role}`);
    check(typeof item?.password === 'string' && item.password.length >= 24 && item.password.length <= 190, `credential_${role}`);
  }
  return doc;
}
function allowed(url) {
  return ['data:', 'blob:', 'about:'].includes(url.protocol)
    || (url.protocol === 'http:' && url.hostname === '127.0.0.1' && ['8090', '8091'].includes(url.port));
}

async function recordChromeStableVersion(context, page) {
  // BrowserContext.browser() is nullable for persistent contexts. Ask the
  // running browser itself through CDP instead, so the evidence records the
  // actual channel-launched Chrome product/version rather than a host-file
  // version or Playwright's bundled Chromium revision.
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const info = await session.send('Browser.getVersion');
    const product = typeof info?.product === 'string' ? info.product : '';
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(product);
    check(match !== null, 'chrome_stable_product');
    chromeProduct = 'chrome';
    chromeVersion = match[1];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally {
    await session?.detach().catch(() => null);
  }
}

const searchFocusableSelector = [
  'a[href]:not([aria-disabled="true"]):visible',
  'button:not([disabled]):visible',
  'input:not([disabled]):visible',
  'select:not([disabled]):visible',
  'textarea:not([disabled]):visible',
  '[tabindex]:not([tabindex="-1"]):visible',
].map((selector) => `[data-search-overlay="true"] ${selector}`).join(', ');

async function open(role, viewport, credentials) {
  const profile = child(settings.userDataRoot, `${role}-${viewport.width}x${viewport.height}`, 'profile_child');
  check(!fs.existsSync(profile), 'profile_not_fresh');
  // This is deliberately Chrome Stable, never Playwright's bundled Chromium.
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome', headless: false, viewport, screen: viewport, locale: 'zh-CN',
      serviceWorkers: 'block', acceptDownloads: false,
      args: [
        '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-component-update', '--disable-sync', '--no-pings',
        ...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS,
      ],
    });
    await context.route('**/*', (route) => {
      try { return allowed(new URL(route.request().url())) ? route.continue() : route.abort(); } catch { return route.abort(); }
    });
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStableVersion(context, page);
    await page.goto(new URL('identification.php', settings.piwigo).toString(), { waitUntil: 'domcontentloaded', timeout: 30_000 });
    const form = page.locator('form[name="login_form"]');
    check(await form.count() === 1, 'login_form');
    await form.locator('input[name="username"]').fill(credentials.roles[role].username);
    await form.locator('input[name="password"]').fill(credentials.roles[role].password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
      form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
    ]);
    return { context, page };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(context === null ? 'chrome_stable_launch' : 'chrome_session');
  }
}
async function save(page, name, options = {}) {
  await page.screenshot({
    path: child(settings.screenshots, `${name}.png`, 'screenshot_child'),
    fullPage: options.fullPage !== false,
  });
  screenshots += 1;
}
async function waitForSearchImages(page, limit = 6) {
  const selector = 'dialog[data-search-overlay="true"][open] .global-search-results .search-photo-grid img';
  const count = await page.locator(selector).count();
  check(count > 0, 'search_visual_photo_results_missing');
  const ready = await page.waitForFunction(({ selector: imageSelector, limit: maxItems }) => {
    const items = Array.from(document.querySelectorAll(imageSelector)).slice(0, maxItems);
    return items.length > 0 && items.every((item) => item instanceof HTMLImageElement
      && item.complete && item.naturalWidth > 0 && item.dataset.loadState !== 'error');
  }, { selector, limit: Math.min(limit, count) }, { timeout: 15_000 }).then(() => true).catch(() => false);
  check(ready, 'search_visual_photo_results_not_ready');
}
async function textList(locator) { return locator.allTextContents().then((v) => v.map((x) => x.trim()).filter(Boolean)); }
async function focusables(page) { return page.locator(searchFocusableSelector); }
function boundedDelay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}
function deferred() {
  let resolve;
  const promise = new Promise((next) => { resolve = next; });
  return { promise, resolve };
}
function groupedSearchRequest(url, query, contextType, contextId = null) {
  try {
    const target = new URL(url);
    if (target.origin !== new URL(settings.photos).origin
      || target.pathname !== '/api/class-archive/search/grouped'
      || target.searchParams.get('q') !== query
      || target.searchParams.get('contextType') !== contextType
      || target.searchParams.has('albumId')) return false;
    if (contextId === null) return !target.searchParams.has('contextId');
    return target.searchParams.get('contextId') === contextId;
  } catch { return false; }
}

function sameOwnedRoute(current, target) {
  return current.origin === target.origin && current.pathname === target.pathname && current.search === target.search;
}

async function gotoOwned(page, target, code, expected = (current) => sameOwnedRoute(current, target)) {
  try {
    await page.goto(target.toString(), { waitUntil: 'networkidle', timeout: 30_000 });
  } catch (error) {
    // The owned single-page document can replace an in-flight navigation while
    // canonicalizing a route. Chrome reports that as ERR_ABORTED. Accept it
    // only if the exact expected, loopback-owned route has already loaded;
    // never treat an arbitrary navigation failure as a successful test.
    let current = null;
    try { current = new URL(page.url()); } catch { }
    const aborted = error instanceof Error && error.message.includes('ERR_ABORTED');
    if (!aborted || current === null || !expected(current)) throw error;
  }
  await page.locator('[data-photo-app="true"]').waitFor({ state: 'attached', timeout: 15_000 });
  let current = null;
  try { current = new URL(page.url()); } catch { }
  check(current !== null && expected(current), code);
}

async function loginAndHome(role, viewport, credentials) {
  const session = await open(role, viewport, credentials);
  try {
    const target = new URL('/home', settings.photos);
    await gotoOwned(session.page, target, 'home_route_after_login');
    return session;
  } catch (error) {
    await session.context.close().catch(() => null);
    throw error;
  }
}
async function scopeProjection(page, role) {
  const projection = await page.evaluate(async () => {
    try {
      const [stateResponse, timelineResponse] = await Promise.all([
        fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' }),
        fetch('/api/class-archive/timeline?limit=120', { credentials: 'same-origin', cache: 'no-store' }),
      ]);
      const state = await stateResponse.json(); const timeline = await timelineResponse.json();
      return { stateStatus: stateResponse.status, timelineStatus: timelineResponse.status, role: state?.role, total: timeline?.total, cacheScope: timeline?.cacheScope };
    } catch { return { stateStatus: 0, timelineStatus: 0 }; }
  });
  check(projection.stateStatus === 200 && projection.timelineStatus === 200, `${role}_projection_status`);
  check(projection.role === role.toUpperCase() && Number.isInteger(projection.total) && projection.total >= 1, `${role}_projection_identity`);
  check(typeof projection.cacheScope === 'string' && /^[a-f0-9]{32}$/i.test(projection.cacheScope), `${role}_projection_scope`);
  return { total: projection.total, cacheScope: projection.cacheScope };
}
async function navCheck(page, mobile) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  check(!overflow, mobile ? 'mobile_horizontal_overflow' : 'desktop_horizontal_overflow');
  if (!mobile) {
    check((await textList(page.locator('.nav-list .nav-link'))).join('|') === '精选集|资料库', 'desktop_navigation');
    check(await page.locator('.nav-list .nav-link[href="/search"]').count() === 0, 'desktop_search_route_hidden');
    check(await page.locator('.nav-list .nav-link[href="/my"]').count() === 0, 'desktop_my_route_hidden');
    check(await page.locator('[data-avatar-menu-trigger="true"]').count() === 1, 'desktop_avatar_menu');
  } else {
    check((await textList(page.locator('.mobile-nav a, .mobile-nav button'))).join('|') === '资料库|精选集|搜索', 'mobile_navigation');
    check(await page.locator('.mobile-nav').evaluate((el) => el.getBoundingClientRect().height >= 44), 'mobile_touch_target');
  }
}

async function avatarMenuCheck(page, role) {
  const trigger = page.locator('[data-avatar-menu-trigger="true"]');
  check(await trigger.count() === 1, `${role}_avatar_trigger_missing`);
  await trigger.click();
  const dialog = page.locator('dialog.avatar-dialog[open]');
  await dialog.waitFor({ state: 'visible', timeout: 15_000 });
  check(await dialog.count() === 1, `${role}_avatar_dialog_missing`);
  check(await dialog.evaluate((node) => node.matches(':modal') && node.getAttribute('aria-labelledby') !== null), `${role}_avatar_dialog_semantics`);
  check(await trigger.getAttribute('aria-expanded') === 'true', `${role}_avatar_expanded`);
  const menu = dialog.locator('nav.avatar-menu');
  check(await menu.count() === 1 && (await menu.getAttribute('aria-label') ?? '').trim().length > 0, `${role}_avatar_menu_semantics`);
  const paths = await dialog.locator('a.avatar-menu-link').evaluateAll((links) => links.map((link) => {
    const target = new URL(link.href);
    return `${target.pathname}${target.search}`;
  }));
  check(paths.includes('/my') && paths.includes('/class-archive-core/identity')
    && paths.includes('/class-archive-about') && paths.includes('/class-archive-core/logout'), `${role}_avatar_menu_entries`);
  await dialog.locator('.dialog-close').click();
  await dialog.waitFor({ state: 'detached', timeout: 10_000 });
  check(await trigger.getAttribute('aria-expanded') === 'false', `${role}_avatar_collapsed`);
  check(await trigger.evaluate((node) => document.activeElement === node), `${role}_avatar_focus_restore`);
}
async function homeDoesNotLoadFullLibrary(page) {
  let timelineRequests = 0;
  const listener = (request) => { try { if (new URL(request.url()).pathname === '/api/class-archive/timeline') timelineRequests += 1; } catch { } };
  page.on('request', listener);
  try { await gotoOwned(page, new URL('/home', settings.photos), 'home_route_for_library_request_check'); }
  finally { page.off('request', listener); }
  check(timelineRequests === 0, 'home_full_timeline_requested');
}
async function partialQueryCandidate(page) {
  const candidates = ['班级', '档案', '相册', '毕业', '测试'];
  const selected = await page.evaluate(async (queries) => {
    for (const query of queries) {
      try {
        const response = await fetch(`/api/class-archive/search/grouped?q=${encodeURIComponent(query)}`, { credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json();
        const structured = Array.isArray(payload?.structured) ? payload.structured.reduce((n, item) => n + (Array.isArray(item?.items) ? item.items.length : 0), 0) : 0;
        const photos = Array.isArray(payload?.photos?.items) ? payload.photos.items.length : 0;
        if (response.status === 200 && structured + photos > 0) return query;
      } catch { }
    }
    return null;
  }, candidates);
  check(typeof selected === 'string' && selected.length > 0, 'semantic_partial_structured_fixture_missing');
  return selected;
}
async function waitForSearchDialog(page, code) {
  const dialog = page.locator('dialog[data-search-overlay="true"][open]');
  await dialog.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => null);
  check(await dialog.count() === 1, code);
  return dialog;
}

async function closeSearchDialog(page, dialog, code) {
  if (await dialog.count() === 0) return;
  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'detached', timeout: 10_000 }).catch(() => null);
  if (await dialog.count() !== 0) {
    const visible = await dialog.isVisible().catch(() => false);
    fail(`${code}_${visible ? 'visible' : 'attached'}`);
  }
  // Closing an interactive overlay restores its pre-overlay history entry.
  // Wait for that asynchronous history task before the next keyboard shortcut
  // test, otherwise a rapid `/` can land on the closing document instead of
  // the restored, interactive route.
  const settled = await page.waitForFunction(() => !document.querySelector('dialog[data-search-overlay="true"][open]')
    && new URL(location.href).searchParams.get('search') !== '1', { timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  check(settled, `${code}_history_settle`);
}

async function reducedMotionCheck(page) {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  try {
    check(await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches), 'search_reduced_motion_media');
    const timing = await page.locator('body').evaluate((node) => {
      const style = getComputedStyle(node);
      return { transition: style.transitionDuration, animation: style.animationDuration };
    });
    const reduced = [timing.transition, timing.animation].every((value) => /^0(?:\.0*1)?m?s$|^0\.01m?s$/i.test(value));
    check(reduced, 'search_reduced_motion_style');
  } finally {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
  }
}

async function searchRapidInputCheck(page, dialog, input, firstQuery) {
  const secondQuery = `v4-stale-${Date.now().toString(36)}-${Math.floor(Math.random() * 1_000_000).toString(36)}`;
  const pattern = '**/api/class-archive/search/grouped*';
  const firstRouteSeen = deferred();
  const releaseFirstRoute = deferred();
  const state = { firstSeen: false, abortObserved: false, delayedFulfillRejected: false };
  const cdpRequestIds = new Set();
  const cdp = await page.context().newCDPSession(page).catch(() => null);
  if (cdp) {
    await cdp.send('Network.enable').catch(() => null);
    cdp.on('Network.requestWillBeSent', (event) => {
      if (groupedSearchRequest(event?.request?.url ?? '', firstQuery, 'ALL')) cdpRequestIds.add(event.requestId);
    });
    cdp.on('Network.loadingFailed', (event) => {
      if (cdpRequestIds.has(event?.requestId) && (event?.canceled === true || /cancel|abort/i.test(event?.errorText ?? ''))) {
        state.abortObserved = true;
      }
    });
  }
  const onFailed = (request) => {
    if (groupedSearchRequest(request.url(), firstQuery, 'ALL')) state.abortObserved = true;
  };
  const routeHandler = async (route) => {
    if (!groupedSearchRequest(route.request().url(), firstQuery, 'ALL') || state.firstSeen) {
      await route.continue();
      return;
    }
    state.firstSeen = true;
    firstRouteSeen.resolve();
    try {
      const response = await route.fetch();
      await releaseFirstRoute.promise;
      await route.fulfill({ response });
    } catch {
      // If AbortController cancels the in-flight route, Chromium may reject
      // the delayed fulfillment rather than emit a requestfailed event.
      state.delayedFulfillRejected = true;
      await route.abort('failed').catch(() => null);
    }
  };
  page.on('requestfailed', onFailed);
  await page.route(pattern, routeHandler);
  try {
    await input.fill(firstQuery);
    const started = await Promise.race([firstRouteSeen.promise.then(() => true), boundedDelay(8_000).then(() => false)]);
    check(started && state.firstSeen, 'search_rapid_first_request_missing');
    const secondResponse = page.waitForResponse((response) => response.request().method() === 'GET'
      && groupedSearchRequest(response.url(), secondQuery, 'ALL'), { timeout: 12_000 }).catch(() => null);
    await input.fill(secondQuery);
    const resolvedSecond = await secondResponse;
    check(resolvedSecond !== null && resolvedSecond.status() === 200, 'search_rapid_second_request');
    const rendered = await page.waitForFunction((query) => {
      const active = document.querySelector('dialog[data-search-overlay="true"][open] .global-search-input');
      const results = document.querySelector('dialog[data-search-overlay="true"][open] .global-search-results');
      return active instanceof HTMLInputElement && active.value === query && results instanceof HTMLElement && results.hidden === false;
    }, secondQuery, { timeout: 12_000 }).then(() => true).catch(() => false);
    check(rendered, 'search_rapid_second_render_missing');
    const before = await dialog.locator('.global-search-results').innerHTML();
    releaseFirstRoute.resolve();
    await boundedDelay(650);
    const after = await dialog.locator('.global-search-results').innerHTML();
    check(await input.inputValue() === secondQuery && before === after, 'search_rapid_stale_result_repaint');
    check(state.abortObserved || state.delayedFulfillRejected, 'search_rapid_abort_not_observed');
  } finally {
    releaseFirstRoute.resolve();
    page.off('requestfailed', onFailed);
    await page.unroute(pattern, routeHandler);
    await cdp?.detach().catch(() => null);
  }
}

async function semanticPartialCheck(page, dialog, input, query) {
  let intercepted = 0;
  const pattern = '**/api/class-archive/search/grouped*';
  const routeHandler = async (route) => {
    const response = await route.fetch();
    let payload;
    try { payload = await response.json(); } catch { await route.fulfill({ response }); return; }
    intercepted += 1;
    payload.partial = true;
    payload.semantic = { available: false, total: 0, items: [] };
    await route.fulfill({ response, contentType: 'application/json; charset=utf-8', body: JSON.stringify(payload) });
  };
  // Browser-local semantic outage injection retains an actual, policy-filtered
  // grouped response and changes only its optional semantic section.
  await page.route(pattern, routeHandler);
  try {
    await input.fill(query);
    await dialog.locator('.global-search-status').filter({ hasText: '部分搜索能力暂时不可用' }).waitFor({ timeout: 15_000 });
    check(intercepted >= 1, 'semantic_partial_not_injected');
    check(await dialog.locator('.search-structured-group, .search-photo-grid').count() >= 1, 'semantic_partial_structured_results_lost');
    check(await dialog.locator('.search-smart-unavailable').count() === 1, 'semantic_partial_unavailable_notice');
    check(await dialog.locator('.global-search-results [role="option"]').count() === 0, 'search_rich_results_listbox_misuse');
    await save(page, 'classmate-desktop-search-partial');
  } finally {
    await page.unroute(pattern, routeHandler);
  }
}

async function currentAlbumScopeCheck(page, query) {
  await gotoOwned(page, new URL('/albums', settings.photos), 'album_route_for_search_scope');
  const card = page.locator('a.album-card').first();
  await card.waitFor({ state: 'visible', timeout: 15_000 });
  const href = await card.getAttribute('href');
  const match = typeof href === 'string' ? /^\/albums\/([0-9a-f-]{36})$/i.exec(href) : null;
  check(match !== null, 'search_album_scope_fixture_missing');
  const albumId = match[1].toLowerCase();
  await Promise.all([
    page.waitForURL((value) => value.origin === new URL(settings.photos).origin && value.pathname === `/albums/${albumId}`, { timeout: 20_000 }).catch(() => null),
    card.click(),
  ]);
  const within = page.getByRole('button', { name: '在此相册中搜索', exact: true });
  await within.waitFor({ state: 'visible', timeout: 15_000 });
  await within.click();
  const dialog = await waitForSearchDialog(page, 'search_album_scope_dialog_missing');
  const input = dialog.locator('.global-search-input');
  const scope = dialog.locator('[data-scope-toggle="true"]');
  const options = await scope.locator('option').evaluateAll((nodes) => nodes.map((node) => ({ value: node.value, disabled: node.disabled })));
  const albumOption = `ALBUM:${albumId}`;
  check(await scope.count() === 1 && !(await scope.isHidden()) && options.length === 2
    && options[0]?.value === 'ALL' && options[1]?.value === albumOption && !options[1]?.disabled, 'search_album_scope_options');
  const scopedResponse = page.waitForResponse((response) => response.request().method() === 'GET'
    && groupedSearchRequest(response.url(), query, 'ALBUM', albumId), { timeout: 15_000 }).catch(() => null);
  await scope.selectOption(albumOption);
  await input.fill(query);
  const scoped = await scopedResponse;
  check(scoped !== null && scoped.status() === 200 && await scope.getAttribute('data-scope-kind') === 'ALBUM', 'search_album_scope_request');
  const context = dialog.locator('.global-search-context');
  check(await context.count() === 1 && !(await context.isHidden()), 'search_album_scope_context_visible');
  const allResponse = page.waitForResponse((response) => response.request().method() === 'GET'
    && groupedSearchRequest(response.url(), query, 'ALL'), { timeout: 15_000 }).catch(() => null);
  await scope.selectOption('ALL');
  const all = await allResponse;
  check(all !== null && all.status() === 200 && await scope.getAttribute('data-scope-kind') === 'ALL', 'search_all_library_scope_request');
  check(await context.isHidden(), 'search_all_library_scope_context_hidden');
  await waitForSearchImages(page);
  await save(page, 'classmate-desktop-album-search-scope', { fullPage: false });
  await closeSearchDialog(page, dialog, 'search_album_scope_close');
  await gotoOwned(page, new URL('/home', settings.photos), 'home_route_after_album_search_scope');
}

async function mobileSearchOverlayCheck(page) {
  const trigger = page.locator('.mobile-nav [data-global-search-trigger="true"]');
  check(await trigger.count() === 1 && !(await trigger.isHidden()), 'mobile_search_trigger_visible');
  await trigger.click();
  const dialog = await waitForSearchDialog(page, 'mobile_search_dialog_missing');
  const input = dialog.locator('.global-search-input');
  check(await dialog.evaluate((node) => node.matches(':modal') && node.getAttribute('aria-modal') === 'true'), 'mobile_search_dialog_semantics');
  check(await input.evaluate((node) => document.activeElement === node), 'mobile_search_initial_focus');
  check(await dialog.locator('.global-search-results').evaluate((node) => node.hidden === true)
    && await dialog.locator('.global-search-results .empty-state').count() === 0, 'mobile_search_empty_results_hidden');
  await closeSearchDialog(page, dialog, 'mobile_search_escape_close');
  check(await trigger.evaluate((node) => document.activeElement === node), 'mobile_search_focus_restore');
}

async function overlayCheck(page) {
  const trigger = page.locator('[data-global-search-trigger="true"]').first();
  check(await trigger.count() === 1 && await trigger.getAttribute('aria-keyshortcuts') === 'Control+K Meta+K /', 'search_trigger_semantics');
  await reducedMotionCheck(page);
  await trigger.click();
  let dialog = await waitForSearchDialog(page, 'search_dialog_missing');
  check(await dialog.evaluate((el) => el.matches(':modal') && el.getAttribute('aria-modal') === 'true'
    && typeof el.getAttribute('aria-labelledby') === 'string' && el.getAttribute('aria-labelledby').length > 0), 'search_dialog_semantics');
  const input = dialog.locator('.global-search-input');
  check(await input.evaluate((el) => document.activeElement === el), 'search_initial_focus');
  check(await input.getAttribute('aria-label') !== null && await input.getAttribute('role') === 'combobox'
    && await input.getAttribute('aria-autocomplete') === 'list', 'search_combobox_semantics');
  check(await dialog.locator('.global-search-status').getAttribute('aria-live') === 'polite'
    && await dialog.locator('[aria-live="assertive"]').count() === 0, 'search_status_announcement_semantics');
  check(await page.locator('body').evaluate((el) => !el.textContent.includes('找到一段记忆') && !el.textContent.includes('输入活动、场景或照片说明')), 'search_legacy_empty_card');
  check(await dialog.locator('.global-search-results').evaluate((el) => el.hidden === true)
    && await dialog.locator('.global-search-results .empty-state').count() === 0, 'search_empty_results_hidden');
  let backgroundBlocked = false;
  try { await page.locator('.nav-list .nav-link').first().click({ timeout: 1_000 }); } catch { backgroundBlocked = true; }
  check(backgroundBlocked && await dialog.evaluate((el) => el.open), 'search_background_interaction_blocked');
  const controls = await focusables(page); const count = await controls.count(); check(count >= 2, 'search_focusable_controls');
  await controls.nth(count - 1).focus(); await page.keyboard.press('Tab'); check(await controls.nth(0).evaluate((el) => document.activeElement === el), 'search_focus_trap_forward');
  await controls.nth(0).focus(); await page.keyboard.press('Shift+Tab'); check(await controls.nth(count - 1).evaluate((el) => document.activeElement === el), 'search_focus_trap_reverse');
  await input.focus(); await input.fill('');
  const suggestionList = dialog.locator('[role="listbox"]');
  await suggestionList.waitFor({ state: 'visible', timeout: 15_000 });
  check(await suggestionList.getAttribute('role') === 'listbox' && await suggestionList.locator('[role="option"]').count() >= 1, 'search_listbox_semantics');
  await page.keyboard.press('ArrowDown');
  const activeId = await input.getAttribute('aria-activedescendant');
  check(typeof activeId === 'string' && activeId.length > 0 && await dialog.locator(`#${activeId}`).count() === 1, 'search_combobox_arrow');
  const selectedSuggestion = await dialog.locator(`#${activeId}`).textContent();
  await page.keyboard.press('Enter');
  check(typeof selectedSuggestion === 'string' && selectedSuggestion.length > 0 && await input.inputValue() === selectedSuggestion, 'search_combobox_enter');
  await closeSearchDialog(page, dialog, 'search_escape_close');
  check(await trigger.evaluate((el) => document.activeElement === el), 'search_focus_restore');
  await page.keyboard.press('Control+K'); dialog = await waitForSearchDialog(page, 'search_ctrl_k'); await closeSearchDialog(page, dialog, 'search_ctrl_k_close');
  await page.keyboard.press('/'); dialog = await waitForSearchDialog(page, 'search_slash'); await closeSearchDialog(page, dialog, 'search_slash_close');
  await gotoOwned(page, new URL('/photos', settings.photos), 'photos_route_for_search_history');
  await gotoOwned(page, new URL('/home', settings.photos), 'home_route_for_search_history');
  await trigger.click(); dialog = await waitForSearchDialog(page, 'search_back_dialog_missing');
  await page.goBack({ waitUntil: 'networkidle', timeout: 30_000 });
  await dialog.waitFor({ state: 'detached', timeout: 10_000 }).catch(() => null);
  check(await dialog.count() === 0 && new URL(page.url()).pathname === '/home', 'search_back_closes_overlay');
  await page.goBack({ waitUntil: 'networkidle', timeout: 30_000 }); check(new URL(page.url()).pathname === '/photos', 'search_second_back_navigation');
  await gotoOwned(page, new URL('/search', settings.photos), 'search_legacy_route_navigation', (current) => current.origin === new URL(settings.photos).origin
    && current.pathname === '/home' && current.searchParams.get('search') === '1');
  dialog = await waitForSearchDialog(page, 'search_legacy_route_dialog_missing');
  check(new URL(page.url()).pathname === '/home' && new URL(page.url()).searchParams.get('search') === '1', 'search_legacy_route_open');
  await closeSearchDialog(page, dialog, 'search_legacy_route_close');
  check(new URL(page.url()).pathname === '/home' && !new URL(page.url()).searchParams.has('search'), 'search_legacy_route_cleanup');
  const query = await partialQueryCandidate(page);
  await page.keyboard.press('Control+K'); dialog = await waitForSearchDialog(page, 'search_rapid_dialog_missing');
  await searchRapidInputCheck(page, dialog, dialog.locator('.global-search-input'), query);
  await semanticPartialCheck(page, dialog, dialog.locator('.global-search-input'), query);
  await closeSearchDialog(page, dialog, 'search_partial_close');
  await currentAlbumScopeCheck(page, query);
}

async function main() {
  localOrigin(settings.piwigo, 8090, 'piwigo_origin'); localOrigin(settings.photos, 8091, 'photo_origin');
  privatePath(settings.userDataRoot, 'profile_root'); privatePath(settings.screenshots, 'screenshot_root'); fs.mkdirSync(settings.screenshots, { recursive: true });
  const credentials = readCredentials();
  stageAt('classmate_desktop'); const desktop = await loginAndHome('classmate', { width: 1440, height: 900 }, credentials); let classmate;
  try { classmate = await scopeProjection(desktop.page, 'classmate'); await navCheck(desktop.page, false); await avatarMenuCheck(desktop.page, 'classmate'); await homeDoesNotLoadFullLibrary(desktop.page); await save(desktop.page, 'classmate-desktop-home'); await overlayCheck(desktop.page); } finally { await desktop.context.close(); }
  stageAt('teacher_wide'); const wide = await loginAndHome('teacher', { width: 1920, height: 1080 }, credentials);
  try { const teacher = await scopeProjection(wide.page, 'teacher'); check(teacher.total === classmate.total, 'teacher_full_scope'); await navCheck(wide.page, false); await save(wide.page, 'teacher-wide-home'); } finally { await wide.context.close(); }
  stageAt('family_mobile'); const mobile = await loginAndHome('family', { width: 390, height: 844 }, credentials);
  try { const family = await scopeProjection(mobile.page, 'family'); check(family.total < classmate.total, 'family_heritage_only_scope'); await navCheck(mobile.page, true); await mobileSearchOverlayCheck(mobile.page); await save(mobile.page, 'family-mobile-home', { fullPage: false }); } finally { await mobile.context.close(); }
  stageAt('anonymous_mobile'); const anonymous = await loginAndHome('anonymous', { width: 390, height: 844 }, credentials);
  try { const state = await scopeProjection(anonymous.page, 'anonymous'); check(state.total === classmate.total, 'anonymous_full_scope'); await save(anonymous.page, 'anonymous-mobile-home', { fullPage: false }); } finally { await anonymous.context.close(); }
  check(/^\d+(?:\.\d+){1,4}$/.test(chromeVersion), 'chrome_stable_version');
  process.stdout.write(`V4_CHROME_QA=PASS assertions=${assertions} screenshots=${screenshots} channel=chrome chrome_product=${chromeProduct} chrome_version=${chromeVersion}\n`);
}

main().catch((error) => {
  const code = error instanceof GateError && /^[a-z0-9_]{1,100}$/.test(error.code) ? error.code : 'unexpected_error';
  writeFailureArtifact(error, code);
  process.stdout.write(`V4_CHROME_QA=FAIL stage=${stage} code=${code}\n`); process.exitCode = 1;
});
