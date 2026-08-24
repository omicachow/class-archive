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
const productOnly = process.env.CLASS_ARCHIVE_PHASE3_PRODUCT_ONLY === '1';

const normalizedScreenshot = screenshotDir.replaceAll('\\', '/').toLowerCase();
const expectedScreenshotFragment = environment === 'private'
  ? '/.codex-work/private-real-qa/screenshots/phase3-2/'
  : '/.codex-work/screenshots/phase3/';
assert(normalizedScreenshot.includes(expectedScreenshotFragment), 'screenshot_boundary_invalid');

let credentialDocument;
try { credentialDocument = JSON.parse(await fs.readFile(credentialPath, 'utf8')); }
catch { fail('credential_document_invalid'); }
const exactRootKeys = Object.keys(credentialDocument ?? {}).sort().join(',');
assert(exactRootKeys === 'environment,familyDeniedPhotoId,roles,version', 'credential_document_shape');
assert([1, 2].includes(credentialDocument.version) && credentialDocument.environment === environment, 'credential_document_version');
assert(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(credentialDocument.familyDeniedPhotoId), 'credential_denied_photo_id');

const roleNames = ['classmate', 'family', 'teacher', 'anonymous'];
const expectedRoleNames = environment === 'private' && credentialDocument.version === 2 ? [...roleNames, 'admin'] : roleNames;
assert(Object.keys(credentialDocument.roles ?? {}).sort().join(',') === [...expectedRoleNames].sort().join(','), 'credential_roles_shape');
const credentials = {};
for (const role of expectedRoleNames) {
  const item = credentialDocument.roles[role];
  const expectedKeys = role === 'admin' ? 'cookie,leaseHandle,username' : 'password,username';
  assert(Object.keys(item ?? {}).sort().join(',') === expectedKeys, `credential_${role}_shape`);
  assert(typeof item.username === 'string' && /^[^\u0000-\u001f\u007f]{1,190}$/.test(item.username), `credential_${role}_username`);
  if (role === 'admin') {
    assert(typeof item.cookie === 'string' && /^[A-Za-z0-9,-]{16,128}$/.test(item.cookie), 'credential_admin_cookie');
    assert(typeof item.leaseHandle === 'string' && /^[a-f0-9]{24}$/.test(item.leaseHandle), 'credential_admin_lease');
  } else {
    assert(typeof item.password === 'string' && item.password.length >= 24 && item.password.length <= 190 && !/[\u0000-\u001f\u007f]/.test(item.password), `credential_${role}_password`);
  }
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
    assert(result.mobileVisible && result.mobileLinks === 5, `${code}_mobile_navigation`);
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
  if (role === 'admin' && typeof credentials.admin?.cookie === 'string') {
    await context.addCookies([{
      name: 'pwg_id', value: credentials.admin.cookie,
      domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax',
    }]);
  }
  const page = await context.newPage();
  await goto(page, piwigoOrigin, '/identification.php', `${role}_login_page`);
  if (role !== 'admin') {
    const form = page.locator('form[name="login_form"]');
    assert(await form.count() === 1, `${role}_login_form`);
    await form.locator('input[name="username"]').fill(credentials[role].username);
    await form.locator('input[name="password"]').fill(credentials[role].password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
      form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
    ]);
  }
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
        && payload?.result?.status === (expectedRole === 'SYSTEM_ADMIN' ? 'webmaster' : 'normal')
        && typeof payload?.result?.pwg_token === 'string'
        && payload.result.pwg_token.length >= 16;
      if (!sessionValid) return false;
      const principal = await fetch('/api/me', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      const me = await principal.json();
      return principal.status === 200 && me?.role === expectedRole;
    } catch { return false; }
  }, { expectedUsername: credentials[role].username, expectedRole: role === 'admin' ? 'SYSTEM_ADMIN' : role.toUpperCase() });
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
  if (environment === 'synthetic') {
    // The canonical 72-photo fixture deliberately has no persistent Immich
    // index. Runtime People/Search is proven by the separate fictional
    // Immich fixture; this baseline must instead prove a safe, explicit empty
    // state after that disposable index has been removed.
    assert(payload?.available === false && payload?.total === 0
      && Array.isArray(payload?.items) && payload.items.length === 0, `${role}_people_safe_empty_shape`);
    return new Map();
  }
  assert(payload?.available === true && Number.isInteger(payload?.total) && Array.isArray(payload?.items)
    && payload.total === payload.items.length && payload.total >= 1, `${role}_people_contract_shape`);
  const counts = new Map();
  let focusedPortraits = 0;
  for (const item of payload.items) {
    assert(item && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(item.id)
      && Number.isInteger(item.photo_count) && item.photo_count >= 1
      && typeof item.cover_photo_id === 'string' && visible.has(item.cover_photo_id.toLowerCase()), `${role}_people_count_or_cover_scope`);
    assert(!counts.has(item.id.toLowerCase()), `${role}_people_duplicate`);
    const focus = item.portrait_focus;
    if (focus != null) {
      assert(focus && Number.isFinite(focus.x) && Number.isFinite(focus.y) && Number.isFinite(focus.zoom)
        && focus.x >= 0 && focus.x <= 1 && focus.y >= 0 && focus.y <= 1
        && focus.zoom >= 1 && focus.zoom <= 6, `${role}_people_portrait_focus`);
      focusedPortraits += 1;
    }
    counts.set(item.id.toLowerCase(), item.photo_count);
  }
  if (environment === 'private') {
    // ML-derived covers retain face focus. A manually chosen cover may not
    // have a face crop and deliberately falls back to the UI's safe centered
    // presentation instead of inventing coordinates.
    assert(focusedPortraits >= 1, `${role}_people_portrait_focus_coverage`);
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
    if (item.portrait_focus != null) {
      assert(detail?.portrait_focus && detail.cover_photo_id === item.cover_photo_id, `${role}_person_detail_portrait_focus`);
    }
    assert(detail.items.every((photo) => photo && typeof photo.id === 'string' && visible.has(photo.id.toLowerCase())), `${role}_person_detail_visibility_scope`);
  }
  return counts;
}

async function canonicalSearchContract(context, role, timeline) {
  const query = environment === 'private' ? '教室' : '有人拿着篮球';
  const visible = new Set(timeline.ids.map((id) => id.toLowerCase()));
  if (environment === 'synthetic') {
    let unavailable;
    try {
      unavailable = await context.request.get(safeUrl(`/api/search/smart?q=${encodeURIComponent(query)}`, piwigoOrigin), {
        failOnStatusCode: false,
        timeout: 30_000,
        headers: { Accept: 'application/json' },
      });
    } catch { fail(`${role}_search_fail_closed_transport`); }
    assert(unavailable.status() === 503, `${role}_search_fail_closed_status`);
    const unavailableText = await unavailable.text();
    await unavailable.dispose().catch(() => {});
    assert(!/(?:piwigo_image|immich_asset|media_reference|identity_id|seat_id|account_id|user_id|\/upload\/|\/galleries\/)/i.test(unavailableText), `${role}_search_fail_closed_leak`);

    const hybrid = await canonicalJson(context, `/api/search/hybrid?q=${encodeURIComponent(query)}`, role, 'hybrid_search_contract');
    const sections = ['people', 'albums', 'events', 'archiveTime', 'photos'];
    assert(sections.every((key) => Number.isInteger(hybrid?.[key]?.total)
      && Array.isArray(hybrid?.[key]?.items)
      && hybrid[key].total === hybrid[key].items.length), `${role}_hybrid_search_shape`);
    assert(hybrid?.smart?.available === false && hybrid?.smart?.total === 0
      && Array.isArray(hybrid?.smart?.items) && hybrid.smart.items.length === 0, `${role}_hybrid_search_safe_degradation`);
    assert(hybrid?.partial === true, `${role}_hybrid_search_partial_marker`);
    assert(hybrid.photos.items.every((photo) => typeof photo?.id === 'string' && visible.has(photo.id.toLowerCase())), `${role}_hybrid_search_visibility_scope`);
    return { total: 0, ids: [] };
  }
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
  for (const label of mobile ? ['照片', '人物', '搜索', '相册', '我的'] : ['照片', '人物', '搜索', '相册', '回忆', '我的']) {
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
  assert(/^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=preview&v=[a-f0-9]{32}$/i.test(src ?? ''), `${role}_viewer_media_path`);
  const info = page.getByRole('button', { name: '照片信息', exact: true });
  assert(await info.count() === 1, `${role}_viewer_info_button`);
  await info.click();
  assert(await info.getAttribute('aria-expanded') === 'true', `${role}_viewer_info_panel`);
  if (mobile) {
    const navHidden = await page.locator('.viewer-next').evaluate((button) => {
      const style = getComputedStyle(button);
      return style.pointerEvents === 'none' && Number(style.opacity) === 0;
    });
    assert(navHidden, `${role}_viewer_info_navigation_overlap`);
  }
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
  if (environment === 'synthetic') {
    assert(await page.locator('.person-card').count() === 0, `${role}_people_safe_empty_cards`);
    assert(await page.getByRole('heading', { name: '还没有可查看的人物', exact: true }).count() === 1, `${role}_people_safe_empty_copy`);
    await assertLayout(page, mobile, `${role}_people_empty`);
    await assertBusinessCopy(page, `${role}_people_empty`);
    await screenshot(page, role, 'people', viewport);
    return;
  }
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
  if (environment === 'synthetic') {
    await page.locator('.search-status').filter({ hasText: '部分搜索能力暂时不可用' }).waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await page.locator('.photo-card').count() === 0, `${role}_search_safe_empty_results`);
    assert(await page.locator('.search-status').getByText('部分搜索能力暂时不可用，已显示安全可确认的结果。', { exact: true }).count() === 1
      || (await page.locator('.search-status').innerText()).includes('部分搜索能力暂时不可用'), `${role}_search_safe_partial_copy`);
    assert(await page.getByRole('heading', { name: '没有找到相关照片', exact: true }).count() === 1, `${role}_search_safe_empty_copy`);
    await assertLayout(page, mobile, `${role}_search_empty`);
    await assertBusinessCopy(page, `${role}_search_empty`);
    return 0;
  }
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

async function submitOpenDialog(page, reasonText) {
  const dialog = page.locator('dialog[open]').last();
  assert(await dialog.count() === 1, 'product_dialog_missing');
  const reason = dialog.locator('textarea').last();
  if (await reason.count()) await reason.fill(reasonText);
  const submit = dialog.locator('button.primary-button').last();
  assert(await submit.count() === 1, 'product_dialog_submit_missing');
  const mutationResponse = page.waitForResponse((response) => {
    const request = response.request();
    return request.method() === 'POST' && response.url().startsWith(photoOrigin.origin)
      && new URL(response.url()).pathname.startsWith('/api/class-archive/');
  }, { timeout: 30_000 });
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 })
    .then(() => true)
    .catch(() => false);
  await submit.click();
  const response = await mutationResponse.catch(() => null);
  assert(response !== null && response.status() >= 200 && response.status() < 300, 'product_dialog_mutation_failed');
  // Every successful product mutation used by this E2E deliberately reloads
  // the route. Wait for that concrete navigation instead of a timer measured
  // from the click: the server mutation itself can take longer than the toast
  // delay, which previously let the next locator race the old document.
  assert(await navigation, 'product_dialog_navigation_missing');
  await page.waitForFunction(() => document.readyState === 'complete', null, { timeout: 15_000 }).catch(() => null);
}

async function createManagedPerson(page, label) {
  if (await page.locator('.manage-person-row').filter({ hasText: label }).count()) return;
  stageAt('admin_people_create_open');
  const open = page.getByRole('button', { name: '新建人物', exact: true });
  assert(await open.count() === 1, 'admin_person_create_button');
  await open.click().catch(() => fail('admin_person_create_open'));
  stageAt('admin_people_create_form');
  const dialog = page.locator('dialog[open]').last();
  await dialog.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
  assert(await dialog.count() === 1, 'admin_person_create_dialog');
  const name = dialog.getByLabel('显示名称', { exact: true });
  assert(await name.count() === 1, 'admin_person_create_name');
  await name.fill(label).catch(() => fail('admin_person_create_name_fill'));
  stageAt('admin_people_create_submit');
  await submitOpenDialog(page, '本地浏览器人物整理验收');
  stageAt('admin_people_create_verify');
  const createdRow = page.locator('.manage-person-row').filter({ hasText: label }).first();
  await createdRow.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
  assert(await createdRow.count() === 1, 'admin_person_create_result');
}

async function adminProductJourney() {
  if (environment !== 'private' || !credentials.admin) return;
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.25 });
  let page;
  try {
    stageAt('admin_desktop_login');
    page = await login(context, 'admin');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      page.on('pageerror', (error) => process.stderr.write(`PAGE_ERROR ${error.message}\n`));
      page.on('console', (message) => {
        if (message.type() === 'error') process.stderr.write(`PAGE_CONSOLE_ERROR ${message.text()}\n`);
      });
      page.on('response', async (response) => {
        if (response.request().method() !== 'POST' || !response.url().includes('/api/class-archive/')) return;
        const body = await response.text().catch(() => '');
        process.stderr.write(`MUTATION_RESPONSE ${response.status()} ${new URL(response.url()).pathname} ${body.slice(0, 500)}\n`);
      });
    }
    stageAt('admin_people_manage');
    await goto(page, photoOrigin, '/people/manage', 'admin_people_manage');
    await page.locator('.manage-toolbar').waitFor({ state: 'visible', timeout: 120_000 }).catch(() => null);
    assert(await page.locator('.manage-toolbar').count() === 1, 'admin_people_manage_loaded');
    assert(await page.getByRole('heading', { name: '人物整理', exact: true }).count() === 1, 'admin_people_manage_heading');
    await screenshot(page, 'admin', 'people-manage-start', 'desktop');
    stageAt('admin_people_create_a');
    await createManagedPerson(page, '本地验收人物甲');
    stageAt('admin_people_create_b');
    await createManagedPerson(page, '本地验收人物乙');

    // Recover a previous interrupted run before exercising the reversible
    // merge again. This keeps repeated private QA deterministic.
    const priorMerge = page.locator('.merge-history-row').filter({ hasText: '本地验收人物甲' }).filter({ hasText: '本地验收人物乙' });
    if (await priorMerge.count()) {
      stageAt('admin_people_prior_merge_revert');
      await priorMerge.first().getByRole('button', { name: '撤销合并', exact: true }).click();
      await submitOpenDialog(page, '恢复上一次本地浏览器验收状态');
    }

    const selectPerson = async (label) => {
      const row = page.locator('.manage-person-row').filter({ hasText: label }).first();
      assert(await row.count() === 1, 'admin_person_selection_row');
      await row.locator('input[type="checkbox"]').check();
    };
    await selectPerson('本地验收人物甲');
    await selectPerson('本地验收人物乙');
    stageAt('admin_people_merge');
    await page.getByRole('button', { name: '合并所选人物', exact: true }).click();
    const mergeDialog = page.locator('dialog[open]').last();
    await mergeDialog.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await mergeDialog.count() === 1, 'admin_person_merge_dialog');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      process.stderr.write(`MERGE_DIALOG_TEXT ${(await mergeDialog.innerText()).replace(/\s+/g, ' ')}\n`);
    }
    const mergeTarget = mergeDialog.getByLabel(/^保留为/);
    assert(await mergeTarget.count() === 1, 'admin_person_merge_target');
    await mergeTarget.selectOption({ label: '本地验收人物乙' });
    await submitOpenDialog(page, '验证人物合并可以撤销');
    const mergeRow = page.locator('.merge-history-row').filter({ hasText: '本地验收人物甲' }).filter({ hasText: '本地验收人物乙' }).first();
    await mergeRow.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await mergeRow.count() === 1, 'admin_person_merge_result');
    stageAt('admin_people_merge_revert');
    await mergeRow.getByRole('button', { name: '撤销合并', exact: true }).click();
    await submitOpenDialog(page, '撤销本地浏览器人物合并验收');
    const revertedRow = page.locator('.manage-person-row').filter({ hasText: '本地验收人物甲' }).first();
    await revertedRow.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await revertedRow.count() === 1, 'admin_person_merge_reverted');

    await selectPerson('本地验收人物乙');
    stageAt('admin_people_hide');
    await page.getByRole('button', { name: '隐藏所选', exact: true }).click();
    await submitOpenDialog(page, '验证人物隐藏状态');
    let visibilityRow = page.locator('.manage-person-row').filter({ hasText: '本地验收人物乙' }).first();
    await visibilityRow.getByText('已隐藏', { exact: true }).waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await visibilityRow.getByText('已隐藏', { exact: true }).count() === 1, 'admin_person_hidden');
    await visibilityRow.locator('input[type="checkbox"]').check();
    stageAt('admin_people_show');
    await page.getByRole('button', { name: '恢复显示', exact: true }).click();
    await submitOpenDialog(page, '恢复人物显示状态');
    visibilityRow = page.locator('.manage-person-row').filter({ hasText: '本地验收人物乙' }).first();
    await visibilityRow.getByText('正常显示', { exact: true }).waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await visibilityRow.getByText('正常显示', { exact: true }).count() === 1, 'admin_person_visible');

    // A real cluster is curated only with synthetic labels. No ClassIdentity
    // link is selected and no real name is written to a public artifact.
    const populatedRow = page.locator('.manage-person-row')
      .filter({ hasNotText: '本地验收人物' })
      .filter({ hasText: /[1-9]\d* 张照片/ })
      .first();
    assert(await populatedRow.count() === 1, 'admin_populated_person_missing');
    stageAt('admin_people_cover');
    await populatedRow.getByRole('button', { name: '编辑', exact: true }).click();
    let editor = page.locator('dialog[open]').last();
    const photos = editor.locator('.manage-photo-choice');
    await photos.first().waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await photos.count() >= 1, 'admin_person_photo_grid');
    const displayName = editor.getByLabel('显示名称', { exact: true });
    if (!(await displayName.inputValue()).trim()) await displayName.fill('本地未命名人物');
    const coverRadios = editor.locator('input[type="radio"][name="personCover"]');
    await coverRadios.nth(Math.min(1, (await coverRadios.count()) - 1)).check();
    await submitOpenDialog(page, '验证人物封面选择');

    // Repeated private QA runs may leave an older curated row with the same
    // synthetic label after its only photo was deliberately moved away. Pick
    // the freshly curated, still-populated row instead of relying on DOM order.
    const namedRow = page.locator('.manage-person-row')
      .filter({ hasText: '本地未命名人物' })
      .filter({ hasText: /[1-9]\d* 张照片/ })
      .first();
    await namedRow.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await namedRow.count() === 1, 'admin_person_cover_saved');
    stageAt('admin_people_move');
    await namedRow.getByRole('button', { name: '编辑', exact: true }).click();
    editor = page.locator('dialog[open]').last();
    const movePhoto = editor.locator('.manage-photo-choice').first();
    await movePhoto.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await movePhoto.count() === 1, 'admin_person_move_photo_missing');
    await movePhoto.locator('input[type="checkbox"]').check();
    await editor.getByRole('button', { name: '修正照片归属', exact: true }).click();
    const moveDialog = page.locator('dialog[open]').last();
    await moveDialog.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await moveDialog.count() === 1, 'admin_person_move_dialog');
    const moveTarget = moveDialog.getByLabel(/^移到其他人物/);
    const existingTarget = moveTarget.locator('option', { hasText: '本地归属修正人物' });
    if (await existingTarget.count()) {
      await moveTarget.selectOption({ label: '本地归属修正人物' });
    } else {
      await moveTarget.selectOption({ label: '建立新人物并移动' });
      await moveDialog.getByLabel('新人物名称', { exact: true }).fill('本地归属修正人物');
    }
    await submitOpenDialog(page, '验证人物照片归属修正');
    const movedRow = page.locator('.manage-person-row').filter({ hasText: '本地归属修正人物' }).first();
    await movedRow.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await movedRow.count() === 1, 'admin_person_move_result');
    assert(await page.getByText('可能相似，需人工核对', { exact: true }).count() >= 1, 'admin_near_duplicate_review');
    await screenshot(page, 'admin', 'people-manage', 'desktop');

    stageAt('admin_bulk_archive');
    await goto(page, photoOrigin, '/photos', 'admin_bulk_photos');
    await page.getByRole('button', { name: '整理照片', exact: true }).first().click();
    const firstGroup = page.locator('.timeline-section').first();
    const cards = firstGroup.locator('.photo-card');
    assert(await cards.count() >= 2, 'admin_bulk_same_bucket_missing');
    await cards.nth(0).click();
    await cards.nth(1).click({ modifiers: ['Shift'] });
    const selectionBar = page.locator('.selection-bar');
    assert(await selectionBar.getByText(/已选 2 张/).count() === 1, 'admin_bulk_selection_count');
    await selectionBar.getByRole('button', { name: '整理照片', exact: true }).click();
    let bulkDialog = page.locator('dialog[open]').last();
    await bulkDialog.getByLabel(/^日期精度/).selectOption('EVENT_ONLY');
    await bulkDialog.getByLabel('新活动或学期', { exact: true }).fill('本地验收活动');
    const albumChoice = bulkDialog.locator('fieldset').filter({ hasText: '加入相册' }).getByText('Phase 3.2 本地验收相册', { exact: true });
    assert(await albumChoice.count() === 1, 'admin_bulk_album_choice');
    await albumChoice.click();
    await submitOpenDialog(page, '验证安全批量档案整理');
    const photoHeading = page.getByRole('heading', { name: '照片', exact: true });
    await photoHeading.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await photoHeading.count() === 1, 'admin_bulk_result');

    // The high-risk Era control must demand a separate explicit confirmation.
    const organizeAgain = page.getByRole('button', { name: '整理照片', exact: true }).first();
    await organizeAgain.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await organizeAgain.count() === 1, 'admin_bulk_second_open');
    await organizeAgain.click();
    await page.locator('.timeline-section').first().locator('.photo-card').first().click();
    await page.locator('.selection-bar').getByRole('button', { name: '整理照片', exact: true }).click();
    bulkDialog = page.locator('dialog[open]').last();
    await bulkDialog.getByLabel(/^档案范围/).selectOption('LIVING');
    const eraConfirm = bulkDialog.getByText('我已确认切换档案范围会影响可见权限。', { exact: true });
    assert(await eraConfirm.isVisible(), 'admin_bulk_era_confirmation_visible');
    await bulkDialog.getByRole('button', { name: '关闭对话框', exact: true }).click();

    stageAt('admin_album_detail');
    await goto(page, photoOrigin, '/albums', 'admin_albums');
    const albumCard = page.locator('.album-card').filter({ hasText: 'Phase 3.2 本地验收相册' }).first();
    assert(await albumCard.count() === 1, 'admin_community_album_visible');
    await Promise.all([
      page.waitForURL(/\/albums\/[0-9a-f-]{36}$/i, { timeout: 30_000 }),
      albumCard.click(),
    ]);
    const albumHeading = page.getByRole('heading', { name: 'Phase 3.2 本地验收相册', exact: true });
    await albumHeading.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await albumHeading.count() === 1, 'admin_album_detail_heading');
    const albumPhotos = page.locator('.album-photo-grid .photo-card');
    assert(await albumPhotos.count() >= 3, 'admin_album_detail_photos');
    await albumPhotos.first().click({ modifiers: ['Control'] });
    await page.locator('.selection-bar').getByRole('button', { name: '设为相册封面', exact: true }).click();
    await submitOpenDialog(page, '验证相册封面更新');
    await screenshot(page, 'admin', 'album-detail', 'desktop');
    await assertBusinessCopy(page, 'admin_product');
  } finally {
    await context.close();
  }
}

async function spotlightJourney() {
  if (environment !== 'private' || !credentials.admin) return;
  // A prior interrupted QA run may have left its 24-hour record active. Clear
  // it through the real audited admin action so this journey remains
  // repeatable without touching the database directly.
  const cleanupContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  try {
    const cleanupPage = await login(cleanupContext, 'admin');
    await goto(cleanupPage, photoOrigin, '/photos', 'admin_spotlight_preflight');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      const cleanupState = await cleanupPage.evaluate(async () => {
        const response = await fetch('/api/class-archive/spotlight', { credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json().catch(() => ({}));
        return { status: response.status, active: payload?.active === true, hasItem: payload?.item != null };
      });
      process.stderr.write(`ADMIN_SPOTLIGHT_PREFLIGHT ${JSON.stringify(cleanupState)}\n`);
    }
    const staleCancel = cleanupPage.getByRole('button', { name: '提前取消精选', exact: true });
    // Spotlight is fetched after the document shell. Give the authorized
    // control-plane projection a bounded chance to render before deciding
    // there is no interrupted-run state to clean up.
    await staleCancel.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => null);
    if (await staleCancel.count()) {
      stageAt('admin_spotlight_preflight_cancel');
      await staleCancel.click();
      await submitOpenDialog(cleanupPage, '清理上一次本地浏览器验收中断状态');
      await cleanupPage.locator('.spotlight-hero').waitFor({ state: 'detached', timeout: 30_000 }).catch(() => null);
      assert(await cleanupPage.locator('.spotlight-hero').count() === 0, 'admin_spotlight_preflight_cleanup');
    }
  } finally {
    await cleanupContext.close();
  }
  const memberContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  let memberPage;
  let albumId = null;
  try {
    stageAt('classmate_spotlight');
    memberPage = await login(memberContext, 'classmate');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      memberPage.on('response', async (response) => {
        if (response.request().method() !== 'POST' || !response.url().includes('/api/class-archive/')) return;
        const body = await response.text().catch(() => '');
        process.stderr.write(`MEMBER_MUTATION_RESPONSE ${response.status()} ${new URL(response.url()).pathname} ${body.slice(0, 500)}\n`);
      });
    }
    const forbidden = await memberContext.request.get(safeUrl('/people/manage'), { failOnStatusCode: false });
    assert(forbidden.status() === 403, 'classmate_people_manage_not_forbidden');
    await forbidden.dispose();
    await goto(memberPage, photoOrigin, '/albums', 'classmate_spotlight_albums');
    const albumCard = memberPage.locator('.album-card').filter({ hasText: 'Phase 3.2 本地验收相册' }).first();
    assert(await albumCard.count() === 1, 'classmate_owned_community_album_missing');
    await Promise.all([
      memberPage.waitForURL(/\/albums\/[0-9a-f-]{36}$/i, { timeout: 30_000 }),
      albumCard.click(),
    ]);
    albumId = new URL(memberPage.url()).pathname.split('/').pop();
    assert(/^[0-9a-f-]{36}$/i.test(albumId ?? ''), 'classmate_spotlight_album_id_invalid');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      const diagnostic = await memberPage.evaluate(async () => {
        const state = await (await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' })).json();
        const album = await (await fetch(location.pathname.replace(/^\/albums/, '/api/class-archive/albums'), { credentials: 'same-origin', cache: 'no-store' })).json();
        return { role: state.role, canSpotlight: state.canSpotlight, owned: album.owned, albumCanSpotlight: album.canSpotlight };
      });
      process.stderr.write(`SPOTLIGHT_DIAGNOSTIC ${JSON.stringify(diagnostic)}\n`);
    }
    const create = memberPage.getByRole('button', { name: '设为 24 小时精选', exact: true });
    await create.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await create.count() === 1, 'classmate_spotlight_create_missing');
    await create.click();
    await submitOpenDialog(memberPage, '本地浏览器精选验收');
    if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1') {
      const postCreateState = await memberPage.evaluate(async () => {
        const [stateResponse, spotlightResponse] = await Promise.all([
          fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' }),
          fetch('/api/class-archive/spotlight', { credentials: 'same-origin', cache: 'no-store' }),
        ]);
        const spotlight = await spotlightResponse.json().catch(() => ({}));
        return {
          productStatus: stateResponse.status,
          spotlightStatus: spotlightResponse.status,
          spotlightActive: spotlight?.active === true,
          hasItem: spotlight?.item != null,
          activeLabels: [...document.querySelectorAll('.light-status')]
            .filter((node) => node.textContent?.trim() === '当前正在精选展示').length,
          createButtons: [...document.querySelectorAll('button')]
            .filter((node) => node.textContent?.trim() === '设为 24 小时精选').length,
        };
      });
      process.stderr.write(`SPOTLIGHT_POST_CREATE ${JSON.stringify(postCreateState)}\n`);
    }
    const activeStatus = memberPage.getByText('当前正在精选展示', { exact: true });
    await activeStatus.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
    assert(await activeStatus.count() === 1, 'classmate_spotlight_active');
    await goto(memberPage, photoOrigin, '/photos', 'classmate_spotlight_home');
    assert(await memberPage.locator('.spotlight-hero').count() === 1, 'classmate_spotlight_hero');
    await screenshot(memberPage, 'classmate', 'spotlight', 'desktop');
    const duplicateStatus = await memberPage.evaluate(async (albumId) => {
      const state = await (await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' })).json();
      const response = await fetch('/api/class-archive/spotlight/create', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Class-Archive-CSRF': state.csrfToken },
        body: JSON.stringify({ csrfToken: state.csrfToken, albumId, durationHours: 24, reason: '重复精选保护验收' }),
      });
      return response.status;
    }, albumId);
    assert(duplicateStatus === 409, 'classmate_spotlight_single_active');
  } finally {
    await memberContext.close();
  }

  const adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  try {
    stageAt('admin_spotlight_cancel');
    const adminPage = await login(adminContext, 'admin');
    await goto(adminPage, photoOrigin, '/photos', 'admin_spotlight_home');
    assert(await adminPage.locator('.spotlight-hero').count() === 1, 'admin_spotlight_visible');
    await adminPage.getByRole('button', { name: '提前取消精选', exact: true }).click();
    await submitOpenDialog(adminPage, '管理员提前取消本地验收精选');
    await adminPage.locator('.spotlight-hero').waitFor({ state: 'detached', timeout: 30_000 }).catch(() => null);
    assert(await adminPage.locator('.spotlight-hero').count() === 0, 'admin_spotlight_cancel_result');
  } finally {
    await adminContext.close();
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
  if (!productOnly) {
    const classmate = await runRole('classmate', 'desktop');
    await runRole('family', 'mobile', classmate);
    await runRole('teacher', 'desktop', classmate);
    await runRole('anonymous', 'mobile', classmate);
  }
  await adminProductJourney();
  await spotlightJourney();
  assert(unexpectedNetwork.size === 0, 'browser_external_network_detected');
  process.stdout.write(`PHOTO_UI_BROWSER_QA=PASS environment=${environment} assertions=${assertions} screenshots=${screenshots}\n`);
} catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/.test(error.code) ? error.code : 'unexpected_error';
  if (process.env.CLASS_ARCHIVE_BROWSER_DEBUG === '1' && error instanceof Error) {
    process.stderr.write(`${error.stack ?? error.message}\n`);
  }
  process.stdout.write(`PHOTO_UI_BROWSER_QA=FAIL code=${code}\n`);
  process.exitCode = 1;
} finally {
  if (browser) await browser.close().catch(() => {});
}
