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
  }, { selector, minimum }, { timeout: 20_000 }).then(() => true).catch(() => false);
  assert(ready, code);
}

async function assertLayout(page, mobile, code) {
  const result = await page.evaluate((mobile) => {
    const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
    const nav = document.querySelector('.mobile-nav');
    const links = nav ? [...nav.querySelectorAll('a')] : [];
    const visible = nav ? getComputedStyle(nav).display !== 'none' : false;
    const targets = links.map((item) => item.getBoundingClientRect()).map((box) => Math.min(box.width, box.height));
    return { overflow, mobileVisible: visible, mobileLinks: links.length, minimumTarget: targets.length ? Math.min(...targets) : 0 };
  }, mobile);
  assert(!result.overflow, `${code}_horizontal_overflow`);
  if (mobile) {
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
  const authenticated = await page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    try {
      const response = await fetch('/ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json();
      return response.status === 200 && payload?.stat === 'ok' && payload?.result?.is_guest === false;
    } catch { return false; }
  });
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

async function photosPage(page, role, mobile) {
  await goto(page, photoOrigin, '/photos', `${role}_photos`);
  await page.locator('.photo-card').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => null);
  assert(await page.getByRole('heading', { name: '照片', exact: true }).count() >= 1, `${role}_photos_heading`);
  assert(await page.locator('.photo-card').count() >= 1, `${role}_photos_grid`);
  const photos = await page.locator('.photo-card img[src^="/api/assets/"]').count();
  assert(photos >= 1, `${role}_photos_media`);
  await waitForDecoded(page, '.photo-card img[src^="/api/assets/"]', Math.min(4, photos), `${role}_photos_decoded`);
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
  await assertLayout(page, mobile, `${role}_viewer`);
  await assertBusinessCopy(page, `${role}_viewer`);
}

async function peoplePage(page, role, viewport) {
  const mobile = viewport === 'mobile';
  await goto(page, photoOrigin, '/people', `${role}_people`);
  await page.locator('.person-card').first().waitFor({ state: 'visible', timeout: 20_000 }).catch(() => null);
  assert(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, `${role}_people_heading`);
  assert(await page.locator('.person-card').count() >= 1, `${role}_people_nonempty`);
  const portraits = await page.locator('.person-card img[src^="/api/people/"]').count();
  assert(portraits >= 1, `${role}_people_media`);
  await waitForDecoded(page, '.person-card img[src^="/api/people/"]', Math.min(3, portraits), `${role}_people_decoded`);
  await assertLayout(page, mobile, `${role}_people`);
  await assertBusinessCopy(page, `${role}_people`);
  await screenshot(page, role, 'people', viewport);
  await page.locator('.person-card').first().click();
  await page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/people\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 20_000 }).catch(() => null);
  assert(/^\/people\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), `${role}_person_route`);
  assert(await page.locator('.person-hero').count() === 1, `${role}_person_hero`);
  await waitForDecoded(page, '.person-hero img[src^="/api/people/"]', 1, `${role}_person_portrait`);
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
  assert(!/暂时不可用/.test(await page.locator('.search-status').innerText()), `${role}_search_partial`);
  await assertLayout(page, mobile, `${role}_search`);
  await assertBusinessCopy(page, `${role}_search`);
  return page.locator('.photo-card').count();
}

async function simplePage(page, role, route, heading, surface, mobile) {
  await goto(page, photoOrigin, route, `${role}_${surface}`);
  assert(await page.getByRole('heading', { name: heading, exact: true }).count() >= 1, `${role}_${surface}_heading`);
  await assertLayout(page, mobile, `${role}_${surface}`);
  await assertBusinessCopy(page, `${role}_${surface}`);
}

async function familyDenied(page) {
  const id = credentialDocument.familyDeniedPhotoId.toLowerCase();
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
      await familyDenied(page);
      assert(baseline && timeline.total < baseline.timelineTotal, 'family_timeline_not_filtered');
      assert(baseline && searchCount <= baseline.searchCount, 'family_search_count_not_filtered');
    }
    if (role === 'teacher' || role === 'anonymous') {
      assert(baseline && timeline.total === baseline.timelineTotal, `${role}_timeline_scope_mismatch`);
    }
    return { timelineTotal: timeline.total, searchCount };
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
