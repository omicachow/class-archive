/*
 * Real Chromium acceptance for the owned Phase 3 Photo UI. It is intentionally
 * runtime-agnostic: the PowerShell owner binds it to either the canonical
 * synthetic pair (8090/8091) or isolated private-QA pair (8190/8191).
 *
 * This script never logs credentials, cookies, URLs, photo/person ids, source
 * names, page text, or response bodies. Real-data screenshots have generic
 * filenames and can only be written below the ignored private QA directory.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}
const fail = (code) => { throw new GateError(code); };
let assertions = 0;
const assert = (condition, code) => {
  assertions += 1;
  if (!condition) fail(code);
};

function setting(name, minimum = 1, maximum = 2048) {
  const value = process.env[name];
  if (typeof value !== 'string' || value.length < minimum || value.length > maximum || value.includes('\0')) fail(`setting_${name.toLowerCase()}_invalid`);
  return value;
}

const environment = setting('CLASS_ARCHIVE_PHASE3_ENVIRONMENT', 7, 9);
if (!['synthetic', 'private'].includes(environment)) fail('environment_invalid');
const expected = environment === 'private' ? { piwigo: 8190, photo: 8191 } : { piwigo: 8090, photo: 8091 };

function origin(name, port) {
  let value;
  try { value = new URL(setting(name, 12, 190)); } catch { fail(`setting_${name.toLowerCase()}_invalid`); }
  if (value.protocol !== 'http:' || value.hostname !== '127.0.0.1' || Number(value.port) !== port
    || value.username || value.password || value.search || value.hash || value.pathname !== '/') {
    fail(`setting_${name.toLowerCase()}_invalid`);
  }
  return value;
}

const piwigoOrigin = origin('CLASS_ARCHIVE_PHASE3_PIWIGO_ORIGIN', expected.piwigo);
const photoOrigin = origin('CLASS_ARCHIVE_PHASE3_PHOTO_ORIGIN', expected.photo);
const screenshotDir = path.resolve(setting('CLASS_ARCHIVE_PHASE3_SCREENSHOT_DIR', 8));
const profileDir = path.resolve(setting('CLASS_ARCHIVE_PHASE3_PROFILE_DIR', 8));
const chromePath = setting('CLASS_ARCHIVE_PHASE3_CHROME', 8);
const credentialPath = path.resolve(setting('CLASS_ARCHIVE_PHASE3_CREDENTIAL_FILE', 8));

const normalizedScreenshot = screenshotDir.replaceAll('\\', '/').toLowerCase();
const expectedScreenshotFragment = environment === 'private'
  ? '/.codex-work/private-real-qa/screenshots/phase3/'
  : '/.codex-work/screenshots/phase3/';
assert(normalizedScreenshot.includes(expectedScreenshotFragment), 'screenshot_boundary_invalid');

let credentialDocument;
try { credentialDocument = JSON.parse(await fs.readFile(credentialPath, 'utf8')); }
catch { fail('credential_document_invalid'); }
const exactRootKeys = Object.keys(credentialDocument ?? {}).sort().join(',');
assert(exactRootKeys === 'environment,familyDeniedPhotoId,roles,version', 'credential_document_shape');
assert(credentialDocument.version === 1 && credentialDocument.environment === environment, 'credential_document_version');
assert(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(credentialDocument.familyDeniedPhotoId), 'credential_denied_photo_id');

const roleNames = ['classmate', 'family', 'teacher', 'anonymous'];
assert(Object.keys(credentialDocument.roles ?? {}).sort().join(',') === [...roleNames].sort().join(','), 'credential_roles_shape');
const credentials = {};
for (const role of roleNames) {
  const item = credentialDocument.roles[role];
  assert(Object.keys(item ?? {}).sort().join(',') === 'password,username', `credential_${role}_shape`);
  assert(typeof item.username === 'string' && /^[^\u0000-\u001f\u007f]{1,190}$/.test(item.username), `credential_${role}_username`);
  assert(typeof item.password === 'string' && item.password.length >= 24 && item.password.length <= 190 && !/[\u0000-\u001f\u007f]/.test(item.password), `credential_${role}_password`);
  credentials[role] = item;
}

let browser = null;
let screenshots = 0;
let stage = 'initialization';
const unexpectedNetwork = new Set();

function stageAt(value) {
  stage = value;
  process.stdout.write(`PHOTO_UI_BROWSER_STAGE=${value}\n`);
}

function safeUrl(relative, root = photoOrigin) {
  return new URL(relative, root).href;
}

async function screenshot(page, role, surface, viewport) {
  const filename = `${role}-${viewport}-${surface}.png`;
  await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: false });
  screenshots += 1;
}

async function waitForDecoded(page, selector, minimum, code) {
  const ready = await page.waitForFunction(({ selector, minimum }) => {
    const images = [...document.querySelectorAll(selector)];
    return images.length >= minimum && images.slice(0, minimum)
      .every((image) => image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0);
  }, { selector, minimum }, { timeout: environment === 'private' ? 120_000 : 20_000 }).then(() => true).catch(() => false);
  assertions += 1;
  if (ready) return;
  const diagnostic = await page.evaluate(async ({ selector, minimum }) => {
    const images = [...document.querySelectorAll(selector)];
    if (images.length < minimum) return 'missing';
    if (images.some((image) => image.dataset.loadState === 'error')) return 'terminal_error';
    if (images.some((image) => image.dataset.loadState === 'retrying')) return 'retrying';
    const image = images.find((item) => !(item.complete && item.naturalWidth > 0 && item.naturalHeight > 0)) ?? images[0];
    const source = image?.getAttribute('src');
    if (typeof source !== 'string' || !source.startsWith('/api/')) return 'source';
    try {
      const response = await fetch(source, { credentials: 'same-origin', cache: 'no-store' });
      if (response.status !== 200) return `http_${response.status}`;
      if (!(response.headers.get('content-type') ?? '').toLowerCase().startsWith('image/')) return 'content_type';
      return 'decode';
    } catch { return 'transport'; }
  }, { selector, minimum }).catch(() => 'probe');
  fail(`${code}_${/^[a-z0-9_]{1,40}$/.test(diagnostic) ? diagnostic : 'unknown'}`);
}

async function assertLayout(page, mobile, code, requireMobileNavigation = true) {
  const result = await page.evaluate((mobile) => {
    const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
    const nav = document.querySelector('.mobile-nav');
    const links = nav ? [...nav.querySelectorAll('a')] : [];
    const visible = nav ? getComputedStyle(nav).display !== 'none' : false;
    const targets = links.map((item) => item.getBoundingClientRect()).map((box) => Math.min(box.width, box.height));
    return { overflow, mobileVisible: visible, mobileLinks: links.length, minimumTarget: targets.length ? Math.min(...targets) : 0 };
  }, mobile);
  assert(!result.overflow, `${code}_horizontal_overflow`);
  if (mobile && requireMobileNavigation) {
    assert(result.mobileVisible && result.mobileLinks === 6, `${code}_mobile_navigation`);
    assert(result.minimumTarget >= 44, `${code}_mobile_touch_target`);
  }
}

async function assertBusinessCopy(page, code) {
  const text = await page.locator('body').innerText();
  assert(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(text), `${code}_technical_copy_visible`);
  assert(!/(?:classmate_identity|identity_id|seat_id|account_id|piwigo_image|immich_asset|media_reference)/i.test(await page.locator('html').innerHTML()), `${code}_backend_identifier_visible`);
}

async function goto(page, root, relative, code) {
  let response;
  try { response = await page.goto(safeUrl(relative, root), { waitUntil: 'domcontentloaded', timeout: 30_000 }); }
  catch { fail(`${code}_transport`); }
  assert(response !== null && response.status() === 200, `${code}_http_status`);
  await page.waitForTimeout(180);
}

async function login(context, role) {
  const page = await context.newPage();
  await goto(page, piwigoOrigin, '/identification.php', `${role}_login_page`);
  const form = page.locator('form[name="login_form"]');
  assert(await form.count() === 1, `${role}_login_form`);
  await form.locator('input[name="username"]').fill(credentials[role].username);
  await form.locator('input[name="password"]').fill(credentials[role].password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
    form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
  ]);
  const authenticated = await page.evaluate(async ({ expectedUsername, expectedRole }) => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    try {
      const response = await fetch('/ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json();
      // Piwigo 16's public response does not expose the older `is_guest`
      // boolean. Bind the session to the exact fixture principal instead of
      // treating any non-error response as authenticated.
      const sessionValid = response.status === 200 && payload?.stat === 'ok'
        && (expectedRole === 'ANONYMOUS' || payload?.result?.username === expectedUsername)
        && payload?.result?.status === 'normal'
        && typeof payload?.result?.pwg_token === 'string'
        && payload.result.pwg_token.length >= 16;
      if (!sessionValid) return false;
      const principal = await fetch('/api/me', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      const me = await principal.json();
      return principal.status === 200 && me?.role === expectedRole;
    } catch { return false; }
  }, { expectedUsername: credentials[role].username, expectedRole: role.toUpperCase() });
  assert(authenticated, `${role}_session_not_authenticated`);
  return page;
}

async function timelineContract(page, role) {
  const result = await page.evaluate(async () => {
    try {
      const response = await fetch('/api/class-archive/timeline', { credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json();
      const ids = Array.isArray(payload?.groups) ? payload.groups.flatMap((group) => Array.isArray(group?.items) ? group.items.map((item) => item?.id) : []) : [];
      return { status: response.status, total: payload?.total, ids, leak: /(?:piwigo_image|immich_asset|media_reference|identity_id|seat_id|account_id)/i.test(JSON.stringify(payload)) };
    } catch { return { status: 0, total: null, ids: [], leak: true }; }
  });
  assert(result.status === 200 && Number.isInteger(result.total) && result.total >= 1 && result.ids.length === result.total, `${role}_timeline_contract`);
  assert(!result.leak, `${role}_timeline_contract_leak`);
  assert(result.ids.every((id) => /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id)), `${role}_timeline_public_ids`);
  return result;
}

async function canonicalJson(context, relative, role, operation) {
  let response;
  try {
    response = await context.request.get(safeUrl(relative, piwigoOrigin), {
      failOnStatusCode: false,
      timeout: 30_000,
      headers: { Accept: 'application/json' },
    });
  } catch {
    fail(`${role}_${operation}_transport`);
  }
  assert(response.status() === 200, `${role}_${operation}_http_status`);
  assert((response.headers()['content-type'] ?? '').toLowerCase().startsWith('application/json'), `${role}_${operation}_content_type`);
  let payload;
  try { payload = await response.json(); } catch { fail(`${role}_${operation}_json`); }
  await response.dispose().catch(() => {});
  assert(!/(?:piwigo_image|immich_asset|media_reference|identity_id|seat_id|account_id|user_id|\/upload\/|\/galleries\/)/i.test(JSON.stringify(payload)), `${role}_${operation}_internal_leak`);
  return payload;
}

async function canonicalPeopleContract(context, role, timeline) {
  const visible = new Set(timeline.ids.map((id) => id.toLowerCase()));
  const payload = await canonicalJson(context, '/api/people', role, 'people_contract');
  assert(payload?.available === true && Number.isInteger(payload?.total) && Array.isArray(payload?.items)
    && payload.total === payload.items.length && payload.total >= 1, `${role}_people_contract_shape`);
  const counts = new Map();
  for (const item of payload.items) {
    assert(item && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(item.id)
      && Number.isInteger(item.photo_count) && item.photo_count >= 1
      && typeof item.cover_photo_id === 'string' && visible.has(item.cover_photo_id.toLowerCase()), `${role}_people_count_or_cover_scope`);
    assert(!counts.has(item.id.toLowerCase()), `${role}_people_duplicate`);
    const focus = item.portrait_focus;
    if (environment === 'private') {
      assert(focus && Number.isFinite(focus.x) && Number.isFinite(focus.y) && Number.isFinite(focus.zoom)
        && focus.x >= 0 && focus.x <= 1 && focus.y >= 0 && focus.y <= 1
        && focus.zoom >= 1 && focus.zoom <= 6, `${role}_people_portrait_focus`);
    }
    counts.set(item.id.toLowerCase(), item.photo_count);
  }
  // Validate a bounded deterministic sample of Person -> Photo projections.
  // List counts and every cover are checked above. Each person detail performs
  // a fresh policy + Immich projection, so four clusters per role exercises
  // the browser path without making a 171-cluster private catalog an
  // accidental exhaustive performance test.
  for (const item of payload.items.slice(0, 4)) {
    const detail = await canonicalJson(context, `/api/people/${item.id}`, role, 'person_detail_contract');
    assert(detail?.id === item.id && detail?.photo_count === item.photo_count && Array.isArray(detail?.items)
      && detail.items.length === item.photo_count, `${role}_person_detail_count_scope`);
    if (environment === 'private') {
      assert(detail?.portrait_focus && detail.cover_photo_id === item.cover_photo_id, `${role}_person_detail_portrait_focus`);
    }
    assert(detail.items.every((photo) => photo && typeof photo.id === 'string' && visible.has(photo.id.toLowerCase())), `${role}_person_detail_visibility_scope`);
  }
  return counts;
}

async function canonicalSearchContract(context, role, timeline) {
  const query = environment === 'private' ? '教室' : '有人拿着篮球';
  const visible = new Set(timeline.ids.map((id) => id.toLowerCase()));
  const payload = await canonicalJson(context, `/api/search/smart?q=${encodeURIComponent(query)}`, role, 'search_contract');
  assert(payload?.available === true && Number.isInteger(payload?.total) && Array.isArray(payload?.items)
    && payload.total === payload.items.length && payload.total >= 1, `${role}_search_contract_shape`);
  const ids = payload.items.map((item) => item?.id?.toLowerCase());
  assert(ids.every((id) => typeof id === 'string' && visible.has(id)), `${role}_search_result_visibility_scope`);
  assert(new Set(ids).size === ids.length, `${role}_search_result_duplicate`);
  return { total: payload.total, ids };
}

function assertPeopleScope(role, counts, baseline) {
  assert(baseline instanceof Map, `${role}_people_baseline_missing`);
  for (const [id, count] of counts) {
    assert(baseline.has(id), `${role}_people_unexpected_cluster`);
    const expected = baseline.get(id);
    assert(role === 'family' ? count <= expected : count === expected, `${role}_people_count_scope_mismatch`);
  }
  if (role !== 'family') assert(counts.size === baseline.size, `${role}_people_total_scope_mismatch`);
}

async function photosPage(page, role, mobile) {
  await goto(page, photoOrigin, '/photos', `${role}_photos`);
  await page.locator('.photo-card').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => null);
  assert(await page.getByRole('heading', { name: '照片', exact: true }).count() >= 1, `${role}_photos_heading`);
  assert(await page.locator('.photo-card').count() >= 1, `${role}_photos_grid`);
  const photos = await page.locator('.photo-card img[src^="/api/assets/"]').count();
  assert(photos >= 1, `${role}_photos_media`);
  await waitForDecoded(page, '.photo-card img[src^="/api/assets/"]', Math.min(9, photos), `${role}_photos_decoded`);
  assert(await page.locator('.photo-card img[data-load-state="error"]').count() === 0, `${role}_photos_image_error`);
  for (const label of ['照片', '人物', '搜索', '相册', '回忆', '我的']) {
    assert(await page.getByRole('link', { name: label, exact: true }).count() >= 1, `${role}_nav_${label.length}`);
  }
  await assertLayout(page, mobile, `${role}_photos`);
  await assertBusinessCopy(page, `${role}_photos`);
  return timelineContract(page, role);
}

async function viewer(page, role, mobile) {
  const first = page.locator('.photo-card').first();
  await first.click();
  await page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/photos\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 20_000 }).catch(() => null);
  assert(/^\/photos\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), `${role}_viewer_route`);
  await waitForDecoded(page, '.viewer-image[src^="/api/assets/"]', 1, `${role}_viewer_decoded`);
  assert(await page.locator('.viewer-image[data-load-state="error"]').count() === 0, `${role}_viewer_image_error`);
  const src = await page.locator('.viewer-image').getAttribute('src');
  assert(/^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=preview$/i.test(src ?? ''), `${role}_viewer_media_path`);
  const info = page.getByRole('button', { name: '照片信息', exact: true });
  assert(await info.count() === 1, `${role}_viewer_info_button`);
  await info.click();
  assert(await info.getAttribute('aria-expanded') === 'true', `${role}_viewer_info_panel`);
  const zoom = page.getByRole('button', { name: '放大', exact: true });
  assert(await zoom.count() === 1, `${role}_viewer_zoom_button`);
  await zoom.click();
  assert((await page.locator('.viewer-image').getAttribute('style') ?? '').includes('scale(1.25)'), `${role}_viewer_zoom`);
  await assertLayout(page, mobile, `${role}_viewer`, false);
  if (mobile) {
    assert(await page.locator('.mobile-nav').count() === 0, `${role}_viewer_immersive_navigation`);
    const closeTarget = await page.getByRole('button', { name: '关闭', exact: true }).evaluate((button) => {
      const box = button.getBoundingClientRect();
      return Math.min(box.width, box.height);
    });
    assert(closeTarget >= 44, `${role}_viewer_close_touch_target`);
  }
  await assertBusinessCopy(page, `${role}_viewer`);
}

async function peoplePage(page, role, viewport) {
  const mobile = viewport === 'mobile';
  await goto(page, photoOrigin, '/people', `${role}_people`);
  await page.locator('.person-card').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => null);
  assert(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, `${role}_people_heading`);
  assert(await page.locator('.person-card').count() >= 1, `${role}_people_nonempty`);
  const portraits = await page.locator('.person-card img[src^="/api/assets/"]').count();
  assert(portraits >= 1, `${role}_people_media`);
  await waitForDecoded(page, '.person-card img[src^="/api/assets/"]', Math.min(3, portraits), `${role}_people_decoded`);
  if (environment === 'private') {
    assert(await page.locator('.person-card img[style*="--portrait-zoom"]').count() >= 1, `${role}_people_focus_applied`);
  }
  const cardLayout = await page.locator('.person-card').evaluateAll((cards) => cards.slice(0, 6).map((card) => {
    const photo = card.querySelector('.person-photo')?.getBoundingClientRect();
    const name = card.querySelector('.person-name')?.getBoundingClientRect();
    const count = card.querySelector('.person-count')?.getBoundingClientRect();
    const box = card.getBoundingClientRect();
    return photo && name && count
      ? { ordered: name.top >= photo.bottom - 1 && count.top >= name.bottom - 1, contained: count.bottom <= box.bottom + 1 }
      : { ordered: false, contained: false };
  }));
  assert(cardLayout.length >= 1 && cardLayout.every((item) => item.ordered && item.contained), `${role}_people_card_layout`);
  await assertLayout(page, mobile, `${role}_people`);
  await assertBusinessCopy(page, `${role}_people`);
  await screenshot(page, role, 'people', viewport);
  await page.locator('.person-card').first().click();
  await page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/people\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 20_000 }).catch(() => null);
  assert(/^\/people\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), `${role}_person_route`);
  await page.locator('.person-hero').waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
  assert(await page.locator('.person-hero').count() === 1, `${role}_person_hero`);
  await waitForDecoded(page, '.person-hero img[src^="/api/assets/"]', 1, `${role}_person_portrait`);
  await waitForDecoded(page, '.photo-card img[src^="/api/assets/"]', 1, `${role}_person_photos`);
  await assertBusinessCopy(page, `${role}_person_detail`);
  await screenshot(page, role, 'person-detail', viewport);
}

async function searchPage(page, role, mobile) {
  await goto(page, photoOrigin, '/search', `${role}_search`);
  const input = page.getByRole('searchbox', { name: '搜索照片', exact: true });
  assert(await input.count() === 1, `${role}_search_input`);
  await input.fill(environment === 'private' ? '教室' : '有人拿着篮球');
  await page.getByRole('button', { name: '搜索', exact: true }).click();
  await page.locator('.photo-card').first().waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
  assert(await page.locator('.photo-card').count() >= 1, `${role}_search_results`);
  const images = await page.locator('.photo-card img[src^="/api/assets/"]').count();
  assert(images >= 1, `${role}_search_media`);
  await waitForDecoded(page, '.photo-card img[src^="/api/assets/"]', Math.min(3, images), `${role}_search_decoded`);
  const columns = await page.locator('.search-photo-grid .photo-card').evaluateAll((cards) => {
    const values = cards.slice(0, 12).map((card) => Math.round(card.getBoundingClientRect().left));
    return new Set(values).size;
  });
  assert(columns >= 2, `${role}_search_multicolumn_layout`);
  if (environment === 'private') {
    assert(await page.locator('.photo-card').count() <= 50, `${role}_search_result_bound`);
  }
  assert(!/暂时不可用/.test(await page.locator('.search-status').innerText()), `${role}_search_partial`);
  await assertLayout(page, mobile, `${role}_search`);
  await assertBusinessCopy(page, `${role}_search`);
  return page.locator('.photo-card').count();
}

async function simplePage(page, role, route, heading, surface, mobile) {
  await goto(page, photoOrigin, route, `${role}_${surface}`);
  assert(await page.getByRole('heading', { name: heading, exact: true }).count() >= 1, `${role}_${surface}_heading`);
  if (route === '/albums') {
    const covers = await page.locator('.album-cover img[src^="/api/assets/"]').count();
    assert(covers >= 1, `${role}_albums_cover`);
    await waitForDecoded(page, '.album-cover img[src^="/api/assets/"]', Math.min(3, covers), `${role}_albums_cover_decoded`);
  }
  if (route === '/memories' && environment === 'private') {
    const covers = await page.locator('.memory-card > img[src^="/api/assets/"]').count();
    assert(covers >= 1, `${role}_memories_cover`);
    await waitForDecoded(page, '.memory-card > img[src^="/api/assets/"]', Math.min(3, covers), `${role}_memories_cover_decoded`);
  }
  await assertLayout(page, mobile, `${role}_${surface}`);
  await assertBusinessCopy(page, `${role}_${surface}`);
}

async function familyDenied(page, context, timeline) {
  const id = credentialDocument.familyDeniedPhotoId.toLowerCase();
  assert(timeline.ids.map((item) => item.toLowerCase()).includes(id) === false, 'family_living_id_present_in_timeline');
  const results = await page.evaluate(async (id) => {
    const probes = [
      ['GET', `/api/assets/${id}`],
      ['GET', `/api/assets/${id}/thumbnail?size=thumbnail`],
      ['HEAD', `/api/assets/${id}/thumbnail?size=thumbnail`],
      ['GET', `/api/assets/${id}/thumbnail?size=preview`, { Range: 'bytes=0-1' }],
    ];
    const statuses = [];
    for (const [method, path, headers = {}] of probes) {
      try { statuses.push((await fetch(path, { method, headers, credentials: 'same-origin', cache: 'no-store' })).status); }
      catch { statuses.push(0); }
    }
    return statuses;
  }, id);
  assert(results.length === 4 && results.every((status) => status === 404), 'family_living_direct_media_denied');
  for (const relative of [`/api/photos/${id}`, `/api/photos/${id}/media/thumbnail`]) {
    let response;
    try { response = await context.request.get(safeUrl(relative, piwigoOrigin), { failOnStatusCode: false, timeout: 30_000 }); }
    catch { fail('family_living_canonical_transport'); }
    assert(response.status() === 404, 'family_living_canonical_media_denied');
    await response.dispose().catch(() => {});
  }
}

async function runRole(role, viewport, baseline = null) {
  const mobile = viewport === 'mobile';
  const context = await browser.newContext({
    viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 },
    deviceScaleFactor: mobile ? 1 : 1.25,
  });
  context.on('request', (request) => {
    try {
      const target = new URL(request.url());
      if (!['http:', 'https:'].includes(target.protocol)) return;
      if (target.origin !== piwigoOrigin.origin && target.origin !== photoOrigin.origin) unexpectedNetwork.add('external');
      if (target.port === '2283' || /immich-server/i.test(target.hostname)) unexpectedNetwork.add('immich');
    } catch { unexpectedNetwork.add('invalid'); }
  });
  let page;
  try {
    stageAt(`${role}_${viewport}_login`);
    page = await login(context, role);
    stageAt(`${role}_${viewport}_photos`);
    const timeline = await photosPage(page, role, mobile);
    const peopleCounts = await canonicalPeopleContract(context, role, timeline);
    const smartSearch = await canonicalSearchContract(context, role, timeline);
    await screenshot(page, role, 'photos', viewport);
    stageAt(`${role}_${viewport}_viewer`);
    await viewer(page, role, mobile);
    await screenshot(page, role, 'viewer', viewport);
    stageAt(`${role}_${viewport}_people`);
    await peoplePage(page, role, viewport);
    stageAt(`${role}_${viewport}_search`);
    const searchCount = await searchPage(page, role, mobile);
    await screenshot(page, role, 'search', viewport);

    if (role === 'classmate' || role === 'family') {
      stageAt(`${role}_${viewport}_albums`);
      await simplePage(page, role, '/albums', '相册', 'albums', mobile);
      await screenshot(page, role, 'albums', viewport);
    }
    if (role === 'classmate') {
      stageAt(`${role}_${viewport}_memories`);
      await simplePage(page, role, '/memories', '回忆', 'memories', mobile);
      await screenshot(page, role, 'memories', viewport);
    }
    if (role === 'classmate' || role === 'family') {
      stageAt(`${role}_${viewport}_my`);
      await simplePage(page, role, '/my', '我的', 'my', mobile);
      await screenshot(page, role, 'my', viewport);
    }
    if (role === 'family') {
      stageAt(`${role}_${viewport}_denials`);
      await familyDenied(page, context, timeline);
      assert(baseline && timeline.total < baseline.timelineTotal, 'family_timeline_not_filtered');
      assert(baseline && baseline.timelineIds.includes(credentialDocument.familyDeniedPhotoId.toLowerCase()), 'classmate_living_denial_fixture_not_visible');
      assert(baseline && searchCount <= baseline.searchCount, 'family_search_count_not_filtered');
      assert(baseline && smartSearch.total <= baseline.smartSearch.total, 'family_smart_search_count_not_filtered');
      assertPeopleScope(role, peopleCounts, baseline?.peopleCounts);
    }
    if (role === 'teacher' || role === 'anonymous') {
      assert(baseline && timeline.total === baseline.timelineTotal, `${role}_timeline_scope_mismatch`);
      assert(baseline && smartSearch.total === baseline.smartSearch.total
        && smartSearch.ids.join(',') === baseline.smartSearch.ids.join(','), `${role}_smart_search_scope_mismatch`);
      assertPeopleScope(role, peopleCounts, baseline?.peopleCounts);
    }
    return { timelineTotal: timeline.total, timelineIds: timeline.ids.map((id) => id.toLowerCase()), searchCount, peopleCounts, smartSearch };
  } finally {
    await context.close();
  }
}

try {
  await fs.mkdir(screenshotDir, { recursive: true });
  await fs.mkdir(profileDir, { recursive: true });
  browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
  const classmate = await runRole('classmate', 'desktop');
  await runRole('family', 'mobile', classmate);
  await runRole('teacher', 'desktop', classmate);
  await runRole('anonymous', 'mobile', classmate);
  assert(unexpectedNetwork.size === 0, 'browser_external_network_detected');
  process.stdout.write(`PHOTO_UI_BROWSER_QA=PASS environment=${environment} assertions=${assertions} screenshots=${screenshots}\n`);
} catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/.test(error.code) ? error.code : 'unexpected_error';
  process.stdout.write(`PHOTO_UI_BROWSER_QA=FAIL code=${code}\n`);
  process.exitCode = 1;
} finally {
  if (browser) await browser.close().catch(() => {});
}
