/*
 * Real Chromium acceptance for the populated, isolated Phase 2.5 runtime.
 *
 * This script receives only short-lived synthetic-role credentials in process
 * environment. It never prints identifiers, cookies, credentials, paths, or
 * upstream asset/person data. Screenshots are synthetic-only and written to
 * the ignored .codex-work tree by the owning PowerShell runner.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class BrowserGateError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

function fail(code) {
  throw new BrowserGateError(code);
}

function setting(name, pattern, minimum = 1, maximum = 1024) {
  const value = process.env[name];
  if (typeof value !== 'string' || value.length < minimum || value.length > maximum || value.includes('\u0000') || (pattern && !pattern.test(value))) {
    fail(`setting_${name.toLowerCase()}_invalid`);
  }
  return value;
}

function loopbackUrl(name, expectedPort) {
  const raw = setting(name, null, 12, 190);
  let url;
  try { url = new URL(raw); } catch { fail(`setting_${name.toLowerCase()}_invalid`); }
  if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || Number(url.port) !== expectedPort || url.username || url.password || url.search || url.hash) {
    fail(`setting_${name.toLowerCase()}_invalid`);
  }
  return url;
}

const piwigoOrigin = loopbackUrl('CLASS_ARCHIVE_PHASE2_BROWSER_PIWIGO_ORIGIN', 8090);
const compatOrigin = loopbackUrl('CLASS_ARCHIVE_PHASE2_BROWSER_COMPAT_ORIGIN', 8091);
const screenshotDir = path.resolve(setting('CLASS_ARCHIVE_PHASE2_BROWSER_SCREENSHOT_DIR', null, 8, 2048));
const chromePath = setting('CLASS_ARCHIVE_PHASE2_BROWSER_CHROME', null, 8, 1024);
const password = setting('CLASS_ARCHIVE_PHASE2_BROWSER_PASSWORD', /^[A-Za-z0-9_-]{24,190}$/);
const livingPhotoId = setting('CLASS_ARCHIVE_PHASE2_BROWSER_LIVING_PHOTO_ID', /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i, 36, 36).toLowerCase();

const roles = Object.freeze([
  ['classmate', 'fixture-classmate'],
  ['family', 'fixture-family'],
  ['teacher', 'fixture-teacher'],
  ['anonymous', 'fixture-anonymous'],
]);

let assertions = 0;
let screenshots = 0;
let browser = null;
let stage = 'initialization';
let activeRole = 'initialization';
let activeOperation = 'initialization';
let activeNetworkFailure = 'none';
const unexpectedOrigins = new Set();

function assert(condition, code) {
  assertions += 1;
  if (!condition) fail(code);
}

function safeNativeFailureClass(error) {
  // Never emit the browser's raw message: it can contain an internal URL or a
  // transient request identifier. The fixed classes below retain only enough
  // context to repair local navigation/transport behavior.
  const message = String(error?.message ?? '');
  if (/net::ERR_ABORTED/i.test(message)) return 'navigation_aborted';
  if (/execution context was destroyed/i.test(message)) return 'context_destroyed';
  if (/target page, context or browser has been closed/i.test(message)) return 'page_closed';
  if (/locator/i.test(message)) return 'locator_error';
  if (error?.name === 'TimeoutError') return 'timeout';
  if (error?.name === 'TypeError') return 'type_error';
  return 'error';
}

function safePageScope(page) {
  try {
    const current = new URL(page.url());
    if (current.origin === compatOrigin.origin) return 'compat';
    if (current.origin === piwigoOrigin.origin) return 'piwigo';
  } catch {
    // Deliberately fall through: the test must not print a transient URL.
  }
  return 'unknown';
}

function apiPath(relative) {
  return new URL(relative, compatOrigin).pathname + new URL(relative, compatOrigin).search;
}

async function screenshot(page, filename) {
  // Capture the actual interactive viewport. The upstream virtualized grid
  // intentionally reserves off-screen skeleton space; a full-document image
  // would make an otherwise decoded screen look like an empty page.
  await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: false });
  screenshots += 1;
}

async function waitForDecodedImages(page, selector, minimum, label) {
  // An <img> element can exist while its network request is still pending or
  // has failed. Screenshot/E2E evidence therefore requires actual decoded
  // pixels, not merely a skeleton cell added by the upstream Web shell.
  const decoded = await page.waitForFunction(
    ({ imageSelector, expected }) => {
      const images = Array.from(document.querySelectorAll(imageSelector));
      return images.length >= expected
        && images.slice(0, expected).every((image) => image.complete && image.naturalWidth > 0 && image.naturalHeight > 0);
    },
    { imageSelector: selector, expected: minimum },
    { timeout: 15_000 },
  ).then(() => true).catch(() => false);
  assert(decoded, `${label}_decoded_media`);
}

async function assertNoVisibleServerFailure(page, label) {
  // Immich's normal error toast keeps the detailed server text out of the
  // browser test. A visible 4xx/5xx toast is still a genuine UI failure even
  // if a direct API probe happened to succeed before the page rendered.
  const failureClass = await page.locator('body').evaluate((body) => {
    const text = body.innerText;
    if (/请求来源未被允许/i.test(text)) return 'request_source_denied';
    if (/Immich Server Error/i.test(text)) return 'upstream_server_error';
    if (/服务暂时不可用/i.test(text)) return 'service_unavailable';
    if (/Error\s+4\d\d/i.test(text)) return 'http_4xx';
    if (/Error\s+5\d\d/i.test(text)) return 'http_5xx';
    return 'none';
  });
  assert(failureClass === 'none', `${label}_visible_server_failure_${failureClass}`);
}

async function assertNoVisibleImmichWriteControls(page, label) {
  // The compatibility shell is read-only by architecture, and this assertion
  // makes the visual boundary explicit as well: an upstream UI affordance is
  // not accepted merely because the BFF would later reject its write call.
  const blocked = [
    '上传', 'upload', '显示和隐藏人物', 'show and hide people', '显示人物选项',
    'show person options', '隐藏人物', 'hide person', '合并人物', 'merge people',
    '添加到收藏夹', 'add to favorites', '从收藏夹移除', 'remove from favorites',
  ];
  const visible = await page.locator('a,button').evaluateAll((elements, blockedLabels) => {
    const blockedSet = new Set(blockedLabels);
    return elements.filter((element) => {
      const style = getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getBoundingClientRect().width > 0 && element.getBoundingClientRect().height > 0;
    })
    .map((element) => (element.getAttribute('aria-label') || element.getAttribute('title') || element.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase())
    .find((text) => blockedSet.has(text)) || '';
  }, blocked);
  assert(visible === '', `${label}_immich_write_control_visible`);
}

async function goto(page, origin, relative, code) {
  const target = new URL(relative, origin).href;
  for (let attempt = 0; attempt < 45; attempt += 1) {
    try {
      const response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 8_000 });
      if (response !== null && response.status() === 200) {
        await page.waitForTimeout(250);
        return;
      }
    } catch {
      // Docker Desktop/WSL can briefly restart the locally scoped Piwigo
      // pair after a disposable Immich reset. Wait for its normal health
      // recovery; do not mistake a transient refusal for a browser result.
    }
    await page.waitForTimeout(1_000);
  }
  fail(code);
}

async function browserFetch(page, relative, method = 'GET', body = undefined, headers = {}) {
  const safeOperation = relative.startsWith('/api/people')
    ? 'people'
    : relative.startsWith('/api/search')
      ? 'search'
      : relative.startsWith('/api/assets')
        ? 'asset'
        : relative.startsWith('/api/')
        ? 'api'
          : 'request';
  activeOperation = safeOperation;
  let transportClass = 'unknown';
  let transportScope = safePageScope(page);
  for (let attempt = 0; attempt < 30; attempt += 1) {
    try {
      const result = await page.evaluate(async ({ relative, method, body, headers }) => {
    const response = await fetch(relative, {
      method,
      headers: {
        ...(body === undefined ? {} : { 'content-type': 'application/json' }),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'manual',
    });
    const bytes = new Uint8Array(await response.arrayBuffer());
    let json = null;
    const contentType = response.headers.get('content-type') || '';
    if (contentType.toLowerCase().includes('application/json') && bytes.length <= 2 * 1024 * 1024) {
      try { json = JSON.parse(new TextDecoder().decode(bytes)); } catch { json = null; }
    }
    return {
      status: response.status,
      contentType,
      bytes: bytes.length,
      json,
      cacheControl: response.headers.get('cache-control') || '',
    };
      }, { relative, method, body, headers });
      // A disposable Immich reset can briefly restart the local Piwigo
      // reverse-proxy pair. A 503 is still fail-closed, never treated as
      // authorisation success, but it is not yet a stable user result while
      // the same local service is recovering. Retry only that bounded
      // condition; a persistent 503 remains the actual assertion outcome.
      if (result.status === 503 && attempt < 29) {
        await page.waitForTimeout(1_000);
        continue;
      }
      return result;
    } catch (error) {
      // Report only a fixed, non-sensitive browser failure class. This is
      // enough to distinguish a rejected local fetch from an execution-context
      // navigation race without printing a URL, cookie, account, or asset id.
      const message = String(error?.message ?? '');
      if (/execution context was destroyed/i.test(message)) transportClass = 'context_destroyed';
      else if (/target page, context or browser has been closed/i.test(message)) transportClass = 'page_closed';
      else if (/failed to fetch|networkerror/i.test(message)) transportClass = 'fetch_rejected';
      else transportClass = 'evaluate_error';
      transportScope = safePageScope(page);
      // The owning PowerShell runner has already verified Piwigo and the BFF
      // health before Chromium starts. A repeated rejected fetch here is not a
      // recoverable authentication result; bound the diagnostic wait so a
      // broken local upstream cannot turn one test assertion into minutes of
      // opaque retries.
      if (transportClass === 'fetch_rejected' && attempt >= 4) break;
      await page.waitForTimeout(1_000);
    }
  }
  fail(`${activeRole}_${activeOperation}_transport_${transportClass}_${transportScope}_${activeNetworkFailure}`);
}

function assertNoInternalLeak(value, code) {
  const text = JSON.stringify(value);
  // `ownerId` is an upstream Web compatibility field, but its value is a
  // deterministic presentation-only UUID generated by compatibleUser(). It
  // is not a Piwigo user, ClassIdentity principal, Account or Seat id. The
  // safety check rejects every real backend identifier/key instead of merely
  // the name of an opaque compatibility field.
  assert(!/(?:immich_asset|piwigo_image|media_reference|classmate_identity|identity_id|seat_id|account_id|user_id|\/external\/|:2283\b|immich-server)/i.test(text), code);
}

async function login(context, username, label) {
  const page = await context.newPage();
  await goto(page, piwigoOrigin, '/identification.php', `${label}_login_page`);
  const form = page.locator('form[name="login_form"]');
  assert(await form.count() === 1, `${label}_login_form`);
  await form.locator('input[name="username"]').fill(username);
  await form.locator('input[name="password"]').fill(password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
    form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
  ]);
  const session = await page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    const response = await fetch('/ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
    try { return { status: response.status, json: await response.json() }; }
    catch { return { status: response.status, json: null }; }
  });
  if (session.status !== 200) {
    if ([400, 401, 403, 404, 503].includes(session.status)) fail(`${label}_session_http_${session.status}`);
    fail(`${label}_session_http_status`);
  }
  if (session.json?.stat !== 'ok' || !session.json?.result || typeof session.json.result !== 'object') {
    fail(`${label}_session_payload`);
  }
  if (label === 'anonymous') {
    // AnonymousPresenter intentionally redacts the hidden Piwigo login name
    // even in pwg.session.getStatus. Assert the public generic identity and
    // prove that the browser payload does not contain the fixture login name.
    if (session.json.result.username !== '匿名账号' || JSON.stringify(session.json).includes(username)) {
      fail(`${label}_session_redaction`);
    }
  } else if (session.json.result.username !== username) {
    fail(`${label}_session_principal`);
  }
  assertions += 1;
  return page;
}

async function openCompatibility(page, label) {
  stage = `${label}_timeline_navigation`;
  await goto(page, compatOrigin, '/', `${label}_compatibility_page`);
  // Phase 3 owns the user-facing shell. Do not take the Timeline screenshot
  // while its module is still a blank bootstrap canvas; require the complete
  // six-destination product navigation and a decoded MediaGuard image.
  await page.waitForFunction(
    () => document.querySelectorAll('.nav-list a.nav-link').length === 6
      && document.querySelector('#main-content') !== null,
    undefined,
    { timeout: 15_000 },
  ).catch(() => null);
  stage = `${label}_timeline_nav_assertion`;
  assert(await page.locator('.nav-list a.nav-link').count() === 6
    && await page.locator('#main-content').count() === 1, `${label}_compatibility_navigation`);
  stage = `${label}_timeline_media`;
  await waitForDecodedImages(page, 'img[src*="/api/assets/"]', 4, `${label}_timeline`);
  stage = `${label}_timeline_error_surface`;
  await assertNoVisibleServerFailure(page, `${label}_timeline`);
  stage = `${label}_timeline_write_controls`;
  await assertNoVisibleImmichWriteControls(page, `${label}_timeline`);
  stage = `${label}_timeline_copy`;
  const text = await page.locator('body').innerText();
  assert(!/Sign in to Immich|登录 Immich|Register/i.test(text), `${label}_immich_login_hidden`);
  stage = `${label}_timeline_layout`;
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
  assert(overflow, `${label}_layout_overflow`);
}

async function peoplePayload(page, label) {
  const result = await browserFetch(page, '/api/people?size=500&withHidden=false');
  if (result.status !== 200) {
    // HTTP status is safe, bounded diagnostic evidence. The response body,
    // cookies and canonical ids remain deliberately absent from test output.
    if (result.status === 401 || result.status === 403 || result.status === 404 || result.status === 503) {
      fail(`${label}_people_http_${result.status}`);
    }
    fail(`${label}_people_http_status`);
  }
  assert(result.json !== null, `${label}_people_http_json`);
  const payload = result.json;
  assert(Number.isInteger(payload?.total) && Array.isArray(payload?.people) && payload.total === payload.people.length && payload.total >= 3, `${label}_people_shape`);
  for (const person of payload.people) {
    assert(typeof person?.id === 'string' && /^[0-9a-f-]{36}$/i.test(person.id) && typeof person?.thumbnailPath === 'string' && /^\/api\/people\/[0-9a-f-]{36}\/thumbnail$/i.test(person.thumbnailPath), `${label}_person_shape`);
  }
  assertNoInternalLeak(payload, `${label}_people_internal_leak`);
  return payload;
}

async function personCounts(page, payload, label) {
  const result = new Map();
  for (const person of payload.people) {
    const statistics = await browserFetch(page, `/api/people/${person.id}/statistics`);
    assert(statistics.status === 200 && Number.isInteger(statistics.json?.assets) && statistics.json.assets >= 1, `${label}_person_statistics`);
    const thumb = await browserFetch(page, person.thumbnailPath);
    if (thumb.status !== 200) {
      if (thumb.status === 403 || thumb.status === 404 || thumb.status === 503) fail(`${label}_person_thumbnail_${thumb.status}`);
      fail(`${label}_person_thumbnail_status`);
    }
    assert(/^image\//i.test(thumb.contentType), `${label}_person_thumbnail_type`);
    assert(thumb.bytes > 0, `${label}_person_thumbnail_bytes`);
    result.set(person.id.toLowerCase(), statistics.json.assets);
  }
  return result;
}

async function smartSearch(page, label) {
  const result = await browserFetch(page, '/api/search/smart', 'POST', { smartSearchDto: { query: '有人拿着篮球' } });
  if (result.status !== 200 || result.json === null) {
    if ([400, 401, 403, 404, 503].includes(result.status)) {
      fail(`${label}_search_http_${result.status}`);
    }
    fail(`${label}_search_http_status`);
  }
  assertions += 1;
  const payload = result.json;
  assert(Number.isInteger(payload?.assets?.total) && Array.isArray(payload?.assets?.items) && payload.assets.total === payload.assets.items.length && payload.assets.total >= 1, `${label}_search_shape`);
  for (const asset of payload.assets.items) {
    assert(typeof asset?.id === 'string' && /^[0-9a-f-]{36}$/i.test(asset.id) && typeof asset?.thumbnailPath === 'string' && /^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=thumbnail$/i.test(asset.thumbnailPath), `${label}_search_asset_shape`);
    assert(typeof asset?.ownerId === 'string' && /^[0-9a-f-]{36}$/i.test(asset.ownerId) && asset?.owner?.id === asset.ownerId, `${label}_search_owner_presentation_opaque`);
  }
  assertNoInternalLeak(payload, `${label}_search_internal_leak`);
  const first = payload.assets.items[0];
  const thumbnail = await browserFetch(page, first.thumbnailPath);
  assert(thumbnail.status === 200 && /^image\//i.test(thumbnail.contentType) && thumbnail.bytes > 0, `${label}_search_thumbnail`);
  const detail = await browserFetch(page, `/api/assets/${first.id}`);
  assert(detail.status === 200 && detail.json !== null, `${label}_search_viewer_asset`);
  assertNoInternalLeak(detail.json, `${label}_search_viewer_internal_leak`);
  return payload;
}

async function navigatePeopleUi(page, label) {
  const trace = [];
  const collect = (response) => {
    try {
      const url = new URL(response.url());
      if (url.origin !== compatOrigin.origin) return;
      if (url.pathname === '/api/people' || url.pathname.endsWith('/__data.json')) {
        trace.push({ people: url.pathname === '/api/people', data: url.pathname.endsWith('/__data.json'), status: response.status() });
      }
    } catch {
      // Browser telemetry is diagnostic-only. It must never change the
      // authorization result or throw a raw URL into the test report.
    }
  };
  page.on('response', collect);
  try {
    // Route navigation remains a real browser interaction but does not depend
    // on which responsive/header copy of the upstream navigation happens to
    // be visible at the current viewport.
    try {
      await page.goto(new URL('/people', compatOrigin).href, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    } catch (error) {
      fail(`${label}_people_ui_navigation_${safePageScope(page)}_${safeNativeFailureClass(error)}`);
    }
    // A populated runtime starts a genuine gateway/MediaGuard round trip for
    // each visible cover. Poll instead of assuming a fixed desktop timing.
    await page.waitForFunction(
      () => document.querySelectorAll('.people-grid .person-card img[src*="/api/assets/"]').length >= 1,
      undefined,
      { timeout: 10_000 },
    ).catch(() => null);
    const peopleSelector = '.people-grid .person-card img[src*="/api/assets/"]';
    const peopleImages = await page.locator(peopleSelector).count();
    if (peopleImages < 1) {
      const peopleOk = trace.some((entry) => entry.people && entry.status === 200);
      const peopleError = trace.some((entry) => entry.people && entry.status >= 400);
      const dataError = trace.some((entry) => entry.data && entry.status >= 400);
      // Keep the failure code synthetic and bounded; do not emit paths,
      // cookies, identities, UUIDs, or raw upstream responses.
      if (peopleError) fail(`${label}_people_ui_api_${trace.find((entry) => entry.people && entry.status >= 400)?.status ?? 'error'}`);
      if (dataError) fail(`${label}_people_ui_route_data_error`);
      if (!peopleOk) fail(`${label}_people_ui_api_absent`);
      fail(`${label}_people_ui_render_missing`);
    }
    await waitForDecodedImages(page, peopleSelector, Math.min(peopleImages, 3), `${label}_people_ui`);
    await assertNoVisibleServerFailure(page, `${label}_people_ui`);
    await assertNoVisibleImmichWriteControls(page, `${label}_people_ui`);
    assertions += 1;
  } finally {
    page.off('response', collect);
  }
}

async function navigateSearchUi(page, label) {
  const trace = [];
  const collect = (response) => {
    try {
      const url = new URL(response.url());
      if (url.origin !== compatOrigin.origin) return;
      if (url.pathname === '/api/search/smart' || url.pathname === '/api/search/metadata' || url.pathname.startsWith('/api/assets/')) {
        trace.push({ search: url.pathname.startsWith('/api/search/'), asset: url.pathname.startsWith('/api/assets/'), status: response.status() });
      }
    } catch {
      // Bounded, synthetic diagnostic telemetry only.
    }
  };
  page.on('response', collect);
  try {
    try {
      await page.goto(new URL('/search', compatOrigin).href, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    } catch (error) {
      fail(`${label}_search_ui_navigation_${safePageScope(page)}_${safeNativeFailureClass(error)}`);
    }
    // The product-owned search form exposes one semantic query field. Select
    // by its stable structure rather than translated placeholder copy.
    const input = page.locator('.search-form input.search-field[name="query"]').first();
    await input.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => null);
    assert(await input.count() === 1 && await input.isVisible(), `${label}_search_ui_input`);
    await input.fill('有人拿着篮球');
    await input.press('Enter');
    // This verifies rendered, policy-filtered results rather than merely the
    // POST request. Every image source remains a BFF route that re-enters
    // MediaGuard; no test treats an upstream Immich URL as valid delivery.
    const results = page.locator('.search-photo-grid img[src*="/api/assets/"]');
    await results.first().waitFor({ state: 'visible', timeout: 10_000 }).catch(() => null);
    if (await results.count() < 1) {
      const searchOk = trace.some((entry) => entry.search && entry.status === 200);
      const searchError = trace.some((entry) => entry.search && entry.status >= 400);
      const assetError = trace.some((entry) => entry.asset && entry.status >= 400);
      if (searchError) fail(`${label}_search_ui_api_${trace.find((entry) => entry.search && entry.status >= 400)?.status ?? 'error'}`);
      if (!searchOk) fail(`${label}_search_ui_api_absent`);
      if (assetError) fail(`${label}_search_ui_media_error`);
      fail(`${label}_search_ui_render_missing`);
    }
    const renderedResultCount = await results.count();
    await waitForDecodedImages(page, '.search-photo-grid img[src*="/api/assets/"]', Math.min(renderedResultCount, 6), `${label}_search_ui`);
    await assertNoVisibleServerFailure(page, `${label}_search_ui`);
    await assertNoVisibleImmichWriteControls(page, `${label}_search_ui`);
    assertions += 1;
  } finally {
    page.off('response', collect);
  }
}

async function openFirstSearchResultViewer(page, label) {
  const image = page.locator('.search-photo-grid img[src*="/api/assets/"]').first();
  assert(await image.count() === 1 && await image.isVisible(), `${label}_search_viewer_result`);
  const source = await image.getAttribute('src');
  const match = typeof source === 'string' ? /\/api\/assets\/([0-9a-f-]{36})\//i.exec(source) : null;
  assert(match !== null, `${label}_search_viewer_public_id`);
  const publicId = match[1].toLowerCase();
  const anchor = image.locator('xpath=ancestor::a[1]');
  const target = await anchor.count() === 1 ? anchor : image;
  try {
    await target.click();
  } catch (error) {
    fail(`${label}_search_viewer_click_${safeNativeFailureClass(error)}`);
  }
  await page.waitForFunction(
    ({ id }) => {
      const routeOpen = new RegExp(`/(?:photos|photo)/${id}$`, 'i').test(location.pathname);
      const dialogOpen = Array.from(document.querySelectorAll('[role="dialog"] img, [data-testid*="viewer"] img'))
        .some((candidate) => candidate instanceof HTMLImageElement && candidate.src.includes(`/api/assets/${id}/`));
      return routeOpen || dialogOpen;
    },
    { id: publicId },
    { timeout: 15_000 },
  ).catch(() => null);
  const viewerOpen = await page.evaluate(({ id }) => {
    const routeOpen = new RegExp(`/(?:photos|photo)/${id}$`, 'i').test(location.pathname);
    const dialogOpen = Array.from(document.querySelectorAll('[role="dialog"] img, [data-testid*="viewer"] img'))
      .some((candidate) => candidate instanceof HTMLImageElement && candidate.src.includes(`/api/assets/${id}/`));
    return routeOpen || dialogOpen;
  }, { id: publicId });
  assert(viewerOpen, `${label}_search_viewer_not_open`);
  await waitForDecodedImages(page, `img[src*="/api/assets/${publicId}/"]`, 1, `${label}_search_viewer`);
  await assertNoVisibleServerFailure(page, `${label}_search_viewer`);
  await assertNoVisibleImmichWriteControls(page, `${label}_search_viewer`);
  assertions += 1;
}

async function clickFirstPerson(page, label, people, expectedCounts) {
  const trace = [];
  const collect = (response) => {
    try {
      const url = new URL(response.url());
      if (url.origin !== compatOrigin.origin || !url.pathname.startsWith('/api/')) return;
      let kind = 'other';
      if (url.pathname === '/api/timeline/buckets') kind = 'timeline_buckets';
      else if (url.pathname === '/api/timeline/bucket') kind = 'timeline_bucket';
      else if (url.pathname === '/api/search/metadata') kind = 'person_search';
      else if (url.pathname.startsWith('/api/assets/')) kind = 'asset';
      else if (url.pathname.startsWith('/api/people/')) kind = 'person';
      trace.push({ kind, status: response.status() });
    } catch {
      // This bounded test-only trace deliberately omits URLs and ids.
    }
  };
  page.on('response', collect);
  const cardLink = page.locator('.people-grid a.person-card').first();
  try {
    assert(await cardLink.count() === 1, `${label}_person_ui_card`);
    const href = await cardLink.getAttribute('href');
    const routeMatch = typeof href === 'string' ? /^\/people\/([0-9a-f-]{36})$/i.exec(href) : null;
    assert(routeMatch !== null, `${label}_person_detail_public_id`);
    const personId = routeMatch[1].toLowerCase();
    const person = people?.people?.find((candidate) => candidate?.id?.toLowerCase() === personId);
    assert(typeof person?.id === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(person.id), `${label}_person_detail_projection_id`);
    // The custom archive-aware Person projection preserves TERM/YEAR/EVENT
    // and UNKNOWN dates rather than coercing them into Immich's required
    // fileCreatedAt day. Its count is already role-filtered at the canonical
    // Gateway and must agree with the public People statistics.
    const projection = await browserFetch(page, `/api/class-archive/people/${person.id}`);
    assert(projection.status === 200 && projection.json !== null, `${label}_person_detail_projection`);
    const expectedCount = expectedCounts?.get(person.id.toLowerCase());
    assert(Number.isInteger(projection.json?.photo_count) && Array.isArray(projection.json?.items) && projection.json.photo_count === projection.json.items.length && Number.isInteger(expectedCount) && projection.json.photo_count === expectedCount, `${label}_person_detail_projection_shape`);
    assert(projection.json.photo_count >= 1, `${label}_person_detail_projection_count`);
    assertNoInternalLeak(projection.json, `${label}_person_detail_projection_internal_leak`);
    const expectedRoute = `/people/${person.id.toLowerCase()}`;
    assert(href === expectedRoute, `${label}_person_detail_archive_link_target`);
    try {
      await Promise.all([
        page.waitForURL((url) => url.origin === compatOrigin.origin && url.pathname === expectedRoute, { timeout: 15_000 }),
        cardLink.click(),
      ]);
    } catch (error) {
      fail(`${label}_person_detail_archive_navigation_${safeNativeFailureClass(error)}`);
    }
    const personRoute = await page.evaluate(() => location.pathname);
    assert(/^\/people\/[0-9a-f-]{36}$/i.test(personRoute), `${label}_person_detail_archive_route`);
    const grid = page.locator('.person-hero').first();
    await grid.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => null);
    assert(await grid.count() === 1 && await grid.isVisible(), `${label}_person_detail_grid`);
    await page.waitForFunction(
      () => document.querySelectorAll('.photo-grid .photo-card').length >= 1,
      undefined,
      { timeout: 15_000 },
    ).catch(() => null);
    if (await page.locator('.photo-grid .photo-card').count() < 1) {
      const failure = trace.find((entry) => entry.status >= 400);
      if (failure) fail(`${label}_person_detail_${failure.kind}_${failure.status}`);
      fail(`${label}_person_detail_asset_grid_empty`);
    }
    await waitForDecodedImages(page, '.photo-grid img[src*="/api/assets/"]', Math.min(projection.json.photo_count, 4), `${label}_person_detail`);
    await assertNoVisibleServerFailure(page, `${label}_person_detail`);
  } finally {
    page.off('response', collect);
  }
}

async function examineRole(role, username, index, classmate = null) {
  activeRole = role;
  activeNetworkFailure = 'none';
  const mobile = role === 'family' && index === 0;
  const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 960 }, deviceScaleFactor: mobile ? 1 : 1.25 });
  context.on('request', (request) => {
    const endpoint = request.url();
    if (/:2283(?:\/|$)/.test(endpoint) || /immich-server/i.test(endpoint)) {
      unexpectedOrigins.add('immich');
    }
  });
  context.on('requestfailed', (request) => {
    try {
      const endpoint = new URL(request.url());
      if (endpoint.origin !== compatOrigin.origin || !endpoint.pathname.startsWith('/api/')) return;
      const error = String(request.failure()?.errorText ?? '');
      if (/ERR_CONNECTION_REFUSED/i.test(error)) activeNetworkFailure = 'connection_refused';
      else if (/ERR_CONNECTION_RESET/i.test(error)) activeNetworkFailure = 'connection_reset';
      else if (/ERR_EMPTY_RESPONSE/i.test(error)) activeNetworkFailure = 'empty_response';
      else if (/ERR_ABORTED/i.test(error)) activeNetworkFailure = 'aborted';
      else activeNetworkFailure = 'api_request_failed';
    } catch {
      activeNetworkFailure = 'api_request_failed';
    }
  });
  let page = null;
  try {
    stage = `${role}_login`;
    page = await login(context, username, role);
    stage = `${role}_timeline`;
    await openCompatibility(page, role);
    if (role === 'classmate') await screenshot(page, '01-classmate-timeline.png');
    stage = `${role}_people_contract`;
    const people = await peoplePayload(page, role);
    stage = `${role}_person_counts`;
    const counts = await personCounts(page, people, role);
    stage = `${role}_search_contract`;
    const search = await smartSearch(page, role);

    stage = `${role}_people_ui`;
    await navigatePeopleUi(page, role);
    await screenshot(page, role === 'classmate' ? '02-classmate-people.png' : `${String(index + 3).padStart(2, '0')}-${role}-people.png`);
    if (role === 'classmate') {
      stage = `${role}_person_detail`;
      await clickFirstPerson(page, role, people, counts);
      await screenshot(page, '03-classmate-person-detail.png');
    }
    stage = `${role}_search_ui`;
    await navigateSearchUi(page, role);
    await screenshot(page, role === 'classmate' ? '04-classmate-search.png' : `${String(index + 6).padStart(2, '0')}-${role}-search.png`);
    if (role === 'classmate' || role === 'family') {
      stage = `${role}_search_viewer`;
      await openFirstSearchResultViewer(page, role);
      await screenshot(page, role === 'classmate' ? '05-classmate-search-viewer.png' : '07-family-search-viewer.png');
    }

    if (role === 'family') {
      stage = `${role}_living_denials`;
      const variants = [
        ['GET', {}, 404],
        ['HEAD', {}, 404],
        ['GET', { Range: 'bytes=0-1' }, 404],
      ];
      for (const [method, headers, expected] of variants) {
        const denied = await browserFetch(page, `/api/assets/${livingPhotoId}/thumbnail?size=thumbnail`, method, undefined, headers);
        assert(denied.status === expected, 'family_living_thumbnail_denied');
      }
      const viewerDenied = await browserFetch(page, `/api/assets/${livingPhotoId}`);
      assert(viewerDenied.status === 404, 'family_living_viewer_denied');
    }
    if (classmate !== null) {
      stage = `${role}_aggregate_comparison`;
      for (const [id, familyCount] of counts) {
        const fullCount = classmate.counts.get(id);
        assert(Number.isInteger(fullCount) && familyCount <= fullCount, `${role}_person_count_scope`);
      }
      if (role === 'family') {
        assert([...counts].some(([id, count]) => count < classmate.counts.get(id)), 'family_person_count_not_filtered');
        assert(search.assets.total <= classmate.search.assets.total, 'family_search_count_not_filtered');
      } else {
        assert(counts.size === classmate.counts.size && [...counts].every(([id, count]) => classmate.counts.get(id) === count), `${role}_person_count_not_classmate_scope`);
        assert(search.assets.total === classmate.search.assets.total, `${role}_search_count_not_classmate_scope`);
      }
    }
    return { counts, search };
  } finally {
    await context.close();
  }
}

try {
  stage = 'screenshot_directory';
  await fs.mkdir(screenshotDir, { recursive: true });
  stage = 'browser_launch';
  browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
  browser.on('disconnected', () => {});
  browser.on('targetchanged', () => {});
  stage = 'classmate';
  const classmate = await examineRole('classmate', 'fixture-classmate', 0);
  stage = 'family';
  await examineRole('family', 'fixture-family', 0, classmate);
  stage = 'teacher';
  await examineRole('teacher', 'fixture-teacher', 1, classmate);
  stage = 'anonymous';
  await examineRole('anonymous', 'fixture-anonymous', 2, classmate);
  assert(unexpectedOrigins.size === 0, 'browser_direct_immich_origin');
  console.log(`IMMICH_RUNTIME_AI_BROWSER=PASS evidence=BROWSER_E2E_TESTED assertions=${assertions} screenshots=${screenshots} roles=CLASSMATE_FAMILY_TEACHER_ANONYMOUS media=MEDIAGUARD_ONLY`);
} catch (error) {
  const code = error instanceof BrowserGateError && /^[a-z0-9_]{1,96}$/.test(error.code)
    ? error.code
    : `native_${stage}_${safeNativeFailureClass(error)}`;
  console.error(`IMMICH_RUNTIME_AI_BROWSER=FAIL evidence=BROWSER_E2E_TESTED reason=${code} assertions=${assertions}`);
  process.exitCode = 1;
} finally {
  if (browser !== null) await browser.close().catch(() => {});
}
