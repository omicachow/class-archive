/*
 * Local-only Chromium acceptance for a real Family account against the
 * blue/green full-real candidate.  It deliberately creates the account by
 * exercising the business UI: SYSTEM_ADMIN creates a test Classmate identity,
 * the Classmate claims it, issues a Family invite, and the Family accepts it.
 *
 * No credential, claim code, invitation code, real photo name, source path,
 * canonical identifier, or screenshot path is written to stdout.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) {
    super(code);
    this.code = code;
  }
}

let assertions = 0;
function assert(condition, code) {
  assertions += 1;
  if (!condition) throw new GateError(code);
}

function setting(name, pattern) {
  const value = process.env[name] ?? '';
  assert(pattern.test(value), `setting_${name.toLowerCase()}_invalid`);
  return value;
}

const coreOrigin = new URL(setting('CLASS_ARCHIVE_FULL_FAMILY_QA_CORE_ORIGIN', /^http:\/\/127\.0\.0\.1:(?:8190|8290)\/$/));
const photoOrigin = new URL(setting('CLASS_ARCHIVE_FULL_FAMILY_QA_PHOTO_ORIGIN', /^http:\/\/127\.0\.0\.1:(?:8191|8291)\/$/));
const screenshotDir = path.resolve(setting('CLASS_ARCHIVE_FULL_FAMILY_QA_SCREENSHOT_DIR', /^[^\u0000]+$/));
const profileDir = path.resolve(setting('CLASS_ARCHIVE_FULL_FAMILY_QA_PROFILE_DIR', /^[^\u0000]+$/));
const credentialPath = path.resolve(setting('CLASS_ARCHIVE_FULL_FAMILY_QA_CREDENTIAL_FILE', /^[^\u0000]+$/));
const chromePath = setting('CLASS_ARCHIVE_FULL_FAMILY_QA_CHROME', /^[^\u0000]+$/);

assert(screenshotDir.replaceAll('\\', '/').toLowerCase().includes('/.codex-work/private-real-qa/screenshots/full-real/'), 'screenshot_boundary_invalid');
assert(profileDir.replaceAll('\\', '/').toLowerCase().includes('/.codex-work/browser-profiles/'), 'profile_boundary_invalid');

let credential;
try {
  credential = JSON.parse(await fs.readFile(credentialPath, 'utf8'));
} catch {
  throw new GateError('credential_document_invalid');
}
assert(Object.keys(credential ?? {}).sort().join(',') === 'admin,cookie,environment,leaseHandle,version', 'credential_document_shape');
assert(credential.version === 1 && credential.environment === 'PRIVATE_REAL_FULL', 'credential_document_version');
assert(typeof credential.admin === 'string' && /^[^\u0000-\u001f\u007f]{1,190}$/.test(credential.admin), 'credential_admin_invalid');
assert(typeof credential.cookie === 'string' && /^[A-Za-z0-9,-]{16,128}$/.test(credential.cookie), 'credential_cookie_invalid');

const run = crypto.randomBytes(6).toString('hex');
const testClassmate = {
  roster: `FQA-C-${run.toUpperCase()}`,
  name: '全量图库验收同学',
  username: `fqa_${run}_classmate`,
  email: `fqa-${run}-classmate@class-archive.invalid`,
  password: `FqC-${crypto.randomBytes(32).toString('hex')}`,
};
const testFamily = {
  name: '全量图库验收家属',
  username: `fqa_${run}_family`,
  email: `fqa-${run}-family@class-archive.invalid`,
  password: `FqF-${crypto.randomBytes(32).toString('hex')}`,
};

const transientIdentityPrefix = 'FQA-';
let browser;
let adminContext;
let classmateContext;
let familyContext;
let createdIdentityId = 0;
let frozenIdentities = 0;
let screenshots = 0;
const unexpectedNetwork = new Set();

function core(relative) { return new URL(relative, coreOrigin).href; }
function photo(relative) { return new URL(relative, photoOrigin).href; }

function observeLocalOnly(page) {
  page.on('request', (request) => {
    try {
      const target = new URL(request.url());
      if (target.protocol !== 'http:') return;
      if (target.hostname !== '127.0.0.1' || ![coreOrigin.port, photoOrigin.port].includes(target.port)) {
        unexpectedNetwork.add(`${target.hostname}:${target.port}`);
      }
    } catch {
      unexpectedNetwork.add('invalid');
    }
  });
}

async function screenshot(page, filename) {
  await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: false });
  screenshots += 1;
}

function acceptLocalBusinessConfirmations(page) {
  page.on('dialog', async (dialog) => {
    try { await dialog.accept(); } catch { /* page is closing */ }
  });
}

async function go(page, base, relative, code, expected = [200]) {
  const response = await page.goto(new URL(relative, base).href, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  assert(response !== null && expected.includes(response.status()), `${code}_status`);
  await page.waitForTimeout(160);
  return response;
}

async function submit(page, form, code) {
  const button = form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last();
  assert(await button.count() === 1, `${code}_button_missing`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 }).catch(() => null),
    button.click(),
  ]);
  await page.waitForTimeout(180);
}

async function login(page, username, password, code) {
  await go(page, coreOrigin, 'identification.php', `${code}_login_page`);
  const form = page.locator('form[name="login_form"]');
  assert(await form.count() === 1, `${code}_login_form_missing`);
  await form.locator('input[name="username"]').fill(username);
  await form.locator('input[name="password"]').fill(password);
  await submit(page, form, `${code}_login`);
  const status = await page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    const response = await fetch('ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
    try { return { status: response.status, json: await response.json() }; } catch { return { status: response.status, json: null }; }
  });
  assert(status.status === 200 && status.json?.stat === 'ok' && status.json?.result?.username === username, `${code}_session_invalid`);
}

async function codeValues(page, count, code) {
  const values = await page.locator('code.ca-admin__code, code.ca-public__secret').allTextContents();
  assert(values.length === count, `${code}_credential_count`);
  const normalized = values.map((value) => value.trim());
  // The public one-time invitation page intentionally renders the code,
  // expiry, and seat metadata as three <code> elements.  Only the first
  // element is the bearer credential; treating the other two as tokens would
  // incorrectly reject the real flow.
  assert(/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}$/.test(normalized[0] ?? ''), `${code}_credential_shape`);
  return normalized;
}

async function isIdentityFrozenPage(page) {
  const frozen = page.locator('form:has(input[name="action"][value="unfreeze_identity"])');
  return (await frozen.count()) === 1;
}

async function freezeIdentity(page, id, code) {
  assert(Number.isSafeInteger(id) && id > 0, `${code}_id_invalid`);
  await go(page, coreOrigin, `admin.php?page=plugin-ClassIdentity-identities&identity_id=${id}`, `${code}_detail`);
  if (await isIdentityFrozenPage(page)) return false;
  const form = page.locator('form:has(input[name="action"][value="freeze_identity"])');
  assert(await form.count() === 1, `${code}_freeze_form_missing`);
  await form.locator('input[name="reason"]').fill('全量图库本地浏览器验收完成后冻结测试身份');
  await submit(page, form, `${code}_freeze`);
  assert(await isIdentityFrozenPage(page), `${code}_not_frozen`);
  frozenIdentities += 1;
  return true;
}

async function cleanPriorTransientIdentities(page) {
  await go(page, coreOrigin, 'admin.php?page=plugin-ClassIdentity-identities', 'prior_cleanup_list');
  const rows = await page.locator('tr').evaluateAll((elements, prefix) => elements.map((row) => {
    const text = row.textContent ?? '';
    const link = row.querySelector('a[href*="identity_id="]');
    return { text, href: link?.getAttribute('href') ?? '' };
  }).filter((row) => row.text.includes(prefix) && row.href), transientIdentityPrefix);
  for (const row of rows) {
    const match = row.href.match(/[?&]identity_id=(\d+)/);
    assert(match !== null, 'prior_cleanup_identity_id_missing');
    await freezeIdentity(page, Number.parseInt(match[1], 10), 'prior_cleanup');
  }
}

async function createClassmateAndClaim(adminPage) {
  await go(adminPage, coreOrigin, 'admin.php?page=plugin-ClassIdentity-identities', 'admin_members');
  const create = adminPage.locator('form:has(input[name="action"][value="create_classmate"])');
  assert(await create.count() === 1, 'create_classmate_form_missing');
  await create.locator('input[name="roster_code"]').fill(testClassmate.roster);
  await create.locator('input[name="real_name"]').fill(testClassmate.name);
  await create.locator('input[name="reason"]').fill('全量图库本地 Family 浏览器验收');
  await submit(adminPage, create, 'create_classmate');
  const claim = adminPage.locator('form:has(input[name="action"][value="reissue_claim"])');
  assert(await claim.count() === 1, 'claim_issue_form_missing');
  const id = await claim.locator('input[name="identity_id"]').getAttribute('value');
  createdIdentityId = Number.parseInt(id ?? '', 10);
  assert(Number.isSafeInteger(createdIdentityId) && createdIdentityId > 0, 'created_identity_id_invalid');
  await claim.locator('input[name="reason"]').fill('全量图库本地 Family 浏览器验收认领');
  await submit(adminPage, claim, 'claim_issue');
  const [claimCode] = await codeValues(adminPage, 1, 'claim_issue');

  classmateContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' });
  const page = await classmateContext.newPage();
  acceptLocalBusinessConfirmations(page);
  observeLocalOnly(page);
  await go(page, coreOrigin, 'index.php?/class-identity/claim', 'classmate_claim_page');
  const form = page.locator('form:has(input[name="action"][value="claim"])');
  assert(await form.count() === 1, 'classmate_claim_form_missing');
  await form.locator('input[name="roster_code"]').fill(testClassmate.roster);
  await form.locator('input[name="claim_code"]').fill(claimCode);
  await form.locator('input[name="username"]').fill(testClassmate.username);
  await form.locator('input[name="email"]').fill(testClassmate.email);
  await form.locator('input[name="password"]').fill(testClassmate.password);
  await form.locator('input[name="password_confirmation"]').fill(testClassmate.password);
  await submit(page, form, 'classmate_claim');
  assert((await page.locator('body').innerText()).includes('账号已创建'), 'classmate_claim_not_completed');
  await login(page, testClassmate.username, testClassmate.password, 'classmate');
  await go(page, coreOrigin, 'index.php?/class-identity/my', 'classmate_my');
  const invitation = page.locator('form:has(input[name="action"][value="issue_family_invitation"])');
  assert(await invitation.count() === 1, 'family_invitation_form_missing');
  await submit(page, invitation, 'issue_family_invitation');
  const [invitationCode] = await codeValues(page, 3, 'family_invitation');
  return invitationCode;
}

async function acceptFamily(invitationCode) {
  familyContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' });
  const page = await familyContext.newPage();
  acceptLocalBusinessConfirmations(page);
  observeLocalOnly(page);
  await go(page, coreOrigin, 'index.php?/class-identity/family-invite', 'family_invite_page');
  const form = page.locator('form:has(input[name="action"][value="accept_family"])');
  assert(await form.count() === 1, 'family_accept_form_missing');
  await form.locator('input[name="invitation_code"]').fill(invitationCode);
  await form.locator('input[name="real_name"]').fill(testFamily.name);
  await form.locator('select[name="relationship"]').selectOption('MOTHER');
  await form.locator('input[name="username"]').fill(testFamily.username);
  await form.locator('input[name="email"]').fill(testFamily.email);
  await form.locator('input[name="password"]').fill(testFamily.password);
  await form.locator('input[name="password_confirmation"]').fill(testFamily.password);
  await submit(page, form, 'family_accept');
  assert((await page.locator('body').innerText()).includes('家庭账号已创建'), 'family_account_not_completed');
  await login(page, testFamily.username, testFamily.password, 'family');
  return page;
}

async function noTechnicalIdentifiers(page, code) {
  const text = await page.locator('body').innerText();
  assert(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(text), `${code}_technical_copy_visible`);
  const markup = await page.locator('html').innerHTML();
  assert(!/(?:classmate_identity|identity_id|seat_id|account_id|piwigo_image|immich_asset|media_reference)/i.test(markup), `${code}_backend_identifier_visible`);
}

async function mediaResponse(page, target, method, headers = {}) {
  return page.evaluate(async ({ target: requestTarget, method: requestMethod, headers: requestHeaders }) => {
    const response = await fetch(requestTarget, { method: requestMethod, headers: requestHeaders, credentials: 'same-origin', cache: 'no-store' });
    const bytes = new Uint8Array(await response.arrayBuffer());
    return { status: response.status, type: response.headers.get('content-type') ?? '', prefix: [...bytes.slice(0, 8)] };
  }, { target, method, headers });
}

async function assertDenied(result, code) {
  // A malformed or unknown canonical ID is allowed to fail with a bounded
  // 400 as well as an authorization/not-found response.  In every case the
  // caller must receive no image bytes; URL guessing is never an allow path.
  assert([400, 401, 403, 404].includes(result.status), `${code}_unexpected_status`);
  assert(!/^image\//i.test(result.type), `${code}_image_type`);
  assert(result.prefix.join(',') !== '137,80,78,71,13,10,26,10', `${code}_png_bytes`);
}

async function browseFamily(page) {
  await go(page, photoOrigin, '/photos', 'family_photos');
  assert(await page.getByRole('heading', { name: '照片', exact: true }).count() >= 1, 'family_photos_heading');
  await page.waitForFunction(() => document.querySelectorAll('.photo-card img[src^="/api/assets/"]').length >= 6, null, { timeout: 90_000 });
  const media = page.locator('.photo-card img[src^="/api/assets/"]').first();
  const src = await media.getAttribute('src');
  assert(typeof src === 'string' && /^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=/.test(src), 'family_media_not_mediaguard');
  await page.waitForFunction(() => {
    const image = document.querySelector('.photo-card img[src^="/api/assets/"]');
    return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0;
  }, null, { timeout: 90_000 });
  await noTechnicalIdentifiers(page, 'family_photos');
  await screenshot(page, 'family-full-library-photos.png');

  const allowed = await mediaResponse(page, src, 'HEAD');
  assert(allowed.status === 200 && /^image\//i.test(allowed.type), 'family_heritage_thumbnail_not_allowed');
  const invalidId = '00000000-0000-4000-8000-000000000000';
  for (const [code, target, method, headers] of [
    ['family_guess_photo', `/api/photos/${invalidId}`, 'GET', {}],
    ['family_guess_thumb_get', `/api/assets/${invalidId}/thumbnail?size=grid&v=00000000000000000000000000000000`, 'GET', {}],
    ['family_guess_thumb_head', `/api/assets/${invalidId}/thumbnail?size=grid&v=00000000000000000000000000000000`, 'HEAD', {}],
    ['family_guess_thumb_range', `/api/assets/${invalidId}/thumbnail?size=grid&v=00000000000000000000000000000000`, 'GET', { Range: 'bytes=0-31' }],
  ]) {
    await assertDenied(await mediaResponse(page, target, method, headers), code);
  }

  await page.locator('.photo-card').first().click();
  await page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/photos\/[0-9a-f-]{36}$/.test(value.pathname), { timeout: 30_000 });
  await page.waitForFunction(() => {
    const image = document.querySelector('.viewer-image[src^="/api/assets/"]');
    return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0;
  }, null, { timeout: 90_000 });
  const viewer = await page.locator('.viewer-image').getAttribute('src');
  assert(typeof viewer === 'string' && /^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=preview/.test(viewer), 'family_viewer_not_mediaguard');
  await noTechnicalIdentifiers(page, 'family_viewer');

  await go(page, photoOrigin, '/albums', 'family_albums');
  assert(await page.getByRole('heading', { name: '相册', exact: true }).count() >= 1, 'family_albums_heading');
  await page.waitForFunction(() => document.querySelectorAll('.album-card').length >= 2, null, { timeout: 90_000 });
  const firstAlbum = page.locator('.album-card').first();
  const albumHref = await firstAlbum.getAttribute('href');
  assert(typeof albumHref === 'string' && /^\/albums\/[0-9a-f-]{36}$/i.test(albumHref), 'family_album_route_invalid');
  await Promise.all([
    page.waitForURL((value) => value.origin === photoOrigin.origin && value.pathname === albumHref, { timeout: 30_000 }),
    firstAlbum.click(),
  ]);
  await page.waitForFunction(() => document.querySelectorAll('.photo-card, .album-card').length >= 1, null, { timeout: 30_000 });
  await noTechnicalIdentifiers(page, 'family_albums');
  await screenshot(page, 'family-full-library-albums.png');

  await go(page, photoOrigin, '/people', 'family_people');
  assert(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, 'family_people_heading');
  await noTechnicalIdentifiers(page, 'family_people');

  await go(page, photoOrigin, '/search', 'family_search');
  const search = page.getByRole('searchbox', { name: '搜索照片', exact: true });
  assert(await search.count() === 1, 'family_search_input');
  await search.fill('毕业');
  await search.press('Enter');
  await page.waitForFunction(() => document.querySelector('.hybrid-results, .error-state') !== null, null, { timeout: 60_000 });
  assert(await page.locator('.hybrid-results').count() === 1, 'family_search_failed');
  await noTechnicalIdentifiers(page, 'family_search');
  await screenshot(page, 'family-full-library-search.png');
  assert(unexpectedNetwork.size === 0, 'unexpected_network_request');
}

try {
  await fs.mkdir(screenshotDir, { recursive: true });
  await fs.rm(profileDir, { recursive: true, force: true });
  browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
  adminContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' });
  await adminContext.addCookies([{ name: 'pwg_id', value: credential.cookie, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
  const adminPage = await adminContext.newPage();
  acceptLocalBusinessConfirmations(adminPage);
  observeLocalOnly(adminPage);
  await cleanPriorTransientIdentities(adminPage);
  const invitationCode = await createClassmateAndClaim(adminPage);
  const familyPage = await acceptFamily(invitationCode);
  await browseFamily(familyPage);
  await freezeIdentity(adminPage, createdIdentityId, 'new_cleanup');
  const sessionAfterFreeze = await mediaResponse(familyPage, '/api/timeline?limit=1', 'GET');
  assert([401, 403].includes(sessionAfterFreeze.status), 'family_session_not_revoked_after_freeze');
  process.stdout.write(`FULL_REAL_FAMILY_BROWSER_QA=PASS assertions=${assertions} screenshots=${screenshots} media=mediaguard_only living=covered_by_synthetic_regression cleanup_frozen=${frozenIdentities}\n`);
} catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/i.test(error.code) ? error.code : 'unexpected';
  process.stdout.write(`FULL_REAL_FAMILY_BROWSER_QA=FAIL assertions=${assertions} code=${code}\n`);
  process.exitCode = 1;
} finally {
  try {
    if (createdIdentityId > 0 && adminContext) {
      const pages = adminContext.pages();
      if (pages.length > 0) await freezeIdentity(pages[0], createdIdentityId, 'finally_cleanup');
    }
  } catch {
    process.exitCode = 1;
  }
  for (const context of [familyContext, classmateContext, adminContext]) {
    if (context) await context.close().catch(() => {});
  }
  if (browser) await browser.close().catch(() => {});
  await fs.rm(profileDir, { recursive: true, force: true }).catch(() => {});
}
