/*
 * Photos App V4 owner-private Chrome Stable role journey.
 *
 * This is intentionally a bounded, local acceptance harness rather than a
 * fixture importer. It creates one run-scoped Classmate and Teacher through
 * the real admin/claim forms, then creates the Family and Anonymous accounts
 * through that Classmate's real seat flows. All bearer values remain in this
 * process; finally freezes only the two identities created by this run.
 *
 * It never reads source folders, mounts host media, starts Docker, alters
 * albums/photos/AI data, or prints page text, credentials, URLs, identifiers,
 * or screenshot paths. Mutating photo upload and comment-write exercises stay
 * in their dedicated, cleanup-aware acceptance modules.
 */

import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

let assertions = 0;
let screenshots = 0;
let stage = 'initialization';
let chromeVersion = 'unknown';
const contexts = [];
const unexpectedNetwork = new Set();
const created = { classmateIdentityId: 0, teacherIdentityId: 0 };
let cleanup = 'failed';

function fail(code) { throw new GateError(code); }
function check(value, code) {
  assertions += 1;
  if (!value) fail(code);
}
function stageAt(value) {
  stage = value;
  process.stdout.write(`V4_OWNER_CHROME_STAGE=${value}\n`);
}
function setting(name, pattern) {
  const value = process.env[name] ?? '';
  check(pattern.test(value), `setting_${name.toLowerCase()}_invalid`);
  return value;
}
function strictOrigin(name, port) {
  let value;
  try { value = new URL(setting(name, /^http:\/\/127\.0\.0\.1:[0-9]{2,5}\/$/)); }
  catch { fail(`setting_${name.toLowerCase()}_invalid`); }
  check(value.protocol === 'http:' && value.hostname === '127.0.0.1' && value.port === String(port)
    && value.pathname === '/' && !value.username && !value.password && !value.search && !value.hash,
  `setting_${name.toLowerCase()}_invalid`);
  return value;
}
function absolutePath(name, boundary) {
  const value = path.resolve(setting(name, /^[^\u0000]{8,2048}$/));
  const portable = value.replaceAll('\\', '/').toLowerCase();
  check(portable.includes(boundary), `setting_${name.toLowerCase()}_boundary`);
  return value;
}
function child(root, name, code) {
  check(/^[a-z][a-z0-9-]{2,48}$/i.test(name), code);
  const resolved = path.resolve(root, name);
  const relative = path.relative(root, resolved);
  check(relative.length > 0 && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), code);
  return resolved;
}

const runId = setting('CLASS_ARCHIVE_V4_OWNER_RUN_ID', /^[a-f0-9]{24}$/);
const coreOrigin = strictOrigin('CLASS_ARCHIVE_V4_OWNER_CORE_ORIGIN', 8190);
const photoOrigin = strictOrigin('CLASS_ARCHIVE_V4_OWNER_PHOTO_ORIGIN', 8191);
const credentialPath = absolutePath('CLASS_ARCHIVE_V4_OWNER_CREDENTIAL_FILE', '/.codex-work/private-real-qa/runtime/photos-app-v4-owner/');
const profileRoot = absolutePath('CLASS_ARCHIVE_V4_OWNER_PROFILE_ROOT', '/.codex-work/private-real-qa/browser/photos-app-v4-owner/');
const screenshotDir = absolutePath('CLASS_ARCHIVE_V4_OWNER_SCREENSHOT_DIR', '/.codex-work/private-real-qa/screenshots/photos-app-v4/');
check(process.env.CLASS_ARCHIVE_V4_OWNER_PROVISION === '1', 'explicit_temporary_role_provisioning_required');

let credential;
try { credential = JSON.parse(await fs.readFile(credentialPath, 'utf8')); }
catch { fail('credential_document_invalid'); }
check(Object.keys(credential ?? {}).sort().join(',') === 'admin,cookie,environment,leaseHandle,run,version', 'credential_document_shape');
check(credential.version === 1 && credential.environment === 'PRIVATE_REAL_FULL_OWNER_V4' && credential.run === runId, 'credential_document_scope');
check(typeof credential.admin === 'string' && /^[A-Za-z0-9_.@+-]{1,100}$/.test(credential.admin), 'credential_admin_invalid');
check(typeof credential.cookie === 'string' && /^[A-Za-z0-9,-]{16,128}$/.test(credential.cookie), 'credential_cookie_invalid');
check(typeof credential.leaseHandle === 'string' && /^[a-f0-9]{24}$/.test(credential.leaseHandle), 'credential_lease_invalid');

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

const runToken = crypto.randomBytes(8).toString('hex');
const fixture = Object.freeze({
  classmate: Object.freeze({
    roster: `V4O-C-${runId.slice(0, 12).toUpperCase()}`,
    name: '本地验收同学',
    username: `v4o_${runId.slice(0, 12)}_classmate`,
    email: `v4o-${runId.slice(0, 12)}-classmate@class-archive.invalid`,
    password: `Vc-${runToken}-${crypto.randomBytes(24).toString('hex')}`,
  }),
  teacher: Object.freeze({
    roster: `V4O-T-${runId.slice(0, 12).toUpperCase()}`,
    name: '本地验收教师',
    username: `v4o_${runId.slice(0, 12)}_teacher`,
    email: `v4o-${runId.slice(0, 12)}-teacher@class-archive.invalid`,
    password: `Vt-${runToken}-${crypto.randomBytes(24).toString('hex')}`,
  }),
  family: Object.freeze({
    name: '本地验收家属',
    username: `v4o_${runId.slice(0, 12)}_family`,
    email: `v4o-${runId.slice(0, 12)}-family@class-archive.invalid`,
    password: `Vf-${runToken}-${crypto.randomBytes(24).toString('hex')}`,
  }),
});

function allowedUrl(value) {
  if (['about:', 'blob:', 'data:'].includes(value.protocol)) return true;
  return value.protocol === 'http:' && value.hostname === '127.0.0.1'
    && [coreOrigin.port, photoOrigin.port].includes(value.port);
}
function observeLocalOnly(page) {
  page.on('request', (request) => {
    try {
      const target = new URL(request.url());
      if (!allowedUrl(target)) unexpectedNetwork.add(`${target.protocol}//${target.hostname}:${target.port}`);
    } catch { unexpectedNetwork.add('invalid'); }
  });
}
function acceptLocalBusinessConfirmations(page) {
  page.on('dialog', async (dialog) => { try { await dialog.accept(); } catch { /* close race */ } });
}
async function recordChromeStable(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const result = await session.send('Browser.getVersion');
    const match = /^(Chrome|HeadlessChrome)\/(\d+(?:\.\d+){1,4})$/.exec(result?.product ?? '');
    check(match !== null && match[1] === 'Chrome', 'chrome_stable_product');
    chromeVersion = match[2];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally { await session?.detach().catch(() => null); }
}
async function openChromeRole(role, viewport = { width: 1440, height: 900 }) {
  const profile = child(profileRoot, role, 'profile_child_invalid');
  check(!(await fs.stat(profile).then(() => true).catch(() => false)), `profile_${role}_not_fresh`);
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      // Exact requested browser boundary: installed Google Chrome Stable.
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
    contexts.push(context);
    await context.route('**/*', (route) => {
      try { return allowedUrl(new URL(route.request().url())) ? route.continue() : route.abort(); }
      catch { return route.abort(); }
    });
    const page = context.pages()[0] ?? await context.newPage();
    observeLocalOnly(page);
    acceptLocalBusinessConfirmations(page);
    await recordChromeStable(context, page);
    return { context, page };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail('chrome_stable_launch');
  }
}
async function go(page, base, relative, code, statuses = [200]) {
  let response;
  try { response = await page.goto(new URL(relative, base).href, { waitUntil: 'domcontentloaded', timeout: 45_000 }); }
  catch { fail(`${code}_transport`); }
  check(response !== null && statuses.includes(response.status()), `${code}_status`);
  return response;
}
async function submit(page, form, code) {
  const button = form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last();
  check(await button.count() === 1, `${code}_button_missing`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 }).catch(() => null),
    button.click(),
  ]);
  await page.waitForTimeout(120);
}
async function codeValue(page, expectedCount, code) {
  const values = (await page.locator('code.ca-admin__code, code.ca-public__secret').allTextContents()).map((value) => value.trim());
  check(values.length === expectedCount, `${code}_count`);
  check(/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}$/.test(values[0] ?? ''), `${code}_shape`);
  return values[0];
}
async function login(page, account, role, presentedName = account.username) {
  await go(page, coreOrigin, 'identification.php', `${role}_login_page`);
  const form = page.locator('form[name="login_form"]');
  check(await form.count() === 1, `${role}_login_form_missing`);
  await form.locator('input[name="username"]').fill(account.username);
  await form.locator('input[name="password"]').fill(account.password);
  await submit(page, form, `${role}_login`);
  const status = await page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    const response = await fetch('ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
    return { status: response.status, payload: await response.json().catch(() => null) };
  });
  check(status.status === 200 && status.payload?.stat === 'ok' && status.payload?.result?.username === presentedName, `${role}_session_invalid`);
}
async function createAdminPage() {
  const opened = await openChromeRole('admin');
  await opened.context.addCookies([{ name: 'pwg_id', value: credential.cookie, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
  await go(opened.page, coreOrigin, 'admin.php?page=plugin-ClassIdentity-dashboard', 'admin_dashboard');
  const state = await opened.page.evaluate(async () => {
    const response = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
    return { status: response.status, payload: await response.json().catch(() => null) };
  });
  check(state.status === 200 && state.payload?.role === 'SYSTEM_ADMIN' && state.payload?.canManage === true, 'admin_scope_invalid');
  return opened.page;
}
async function issueClaim(adminPage, identityId, code) {
  const form = adminPage.locator('form:has(input[name="action"][value="reissue_claim"])');
  check(await form.count() === 1, `${code}_form_missing`);
  check((await form.locator('input[name="identity_id"]').getAttribute('value')) === String(identityId), `${code}_identity_binding`);
  await form.locator('input[name="reason"]').fill('本地 V4 Chrome 验收的一次性认领');
  await submit(adminPage, form, code);
  return codeValue(adminPage, 1, code);
}
async function createClassmateAndClaim(adminPage) {
  stageAt('provision_classmate');
  await go(adminPage, coreOrigin, 'admin.php?page=plugin-ClassIdentity-identities', 'classmate_admin_list');
  const form = adminPage.locator('form:has(input[name="action"][value="create_classmate"])');
  check(await form.count() === 1, 'classmate_create_form_missing');
  await form.locator('input[name="roster_code"]').fill(fixture.classmate.roster);
  await form.locator('input[name="real_name"]').fill(fixture.classmate.name);
  await form.locator('input[name="reason"]').fill('本地 V4 Chrome 临时验收身份');
  await submit(adminPage, form, 'classmate_create');
  const identityId = Number.parseInt(await adminPage.locator('input[name="identity_id"]').first().getAttribute('value') ?? '', 10);
  check(Number.isSafeInteger(identityId) && identityId > 0, 'classmate_identity_id_invalid');
  created.classmateIdentityId = identityId;
  const claim = await issueClaim(adminPage, identityId, 'classmate_claim_issue');

  const opened = await openChromeRole('classmate');
  const page = opened.page;
  await go(page, coreOrigin, 'index.php?/class-identity/claim', 'classmate_claim_page');
  const claimForm = page.locator('form:has(input[name="action"][value="claim"])');
  check(await claimForm.count() === 1, 'classmate_claim_form_missing');
  await claimForm.locator('input[name="roster_code"]').fill(fixture.classmate.roster);
  await claimForm.locator('input[name="claim_code"]').fill(claim);
  await claimForm.locator('input[name="username"]').fill(fixture.classmate.username);
  await claimForm.locator('input[name="email"]').fill(fixture.classmate.email);
  await claimForm.locator('input[name="password"]').fill(fixture.classmate.password);
  await claimForm.locator('input[name="password_confirmation"]').fill(fixture.classmate.password);
  await submit(page, claimForm, 'classmate_claim');
  check((await page.locator('body').innerText()).includes('账号已创建'), 'classmate_claim_not_completed');
  await login(page, fixture.classmate, 'classmate');
  return page;
}
async function createTeacherAndClaim(adminPage) {
  stageAt('provision_teacher');
  await go(adminPage, coreOrigin, 'admin.php?page=plugin-ClassIdentity-teachers', 'teacher_admin_list');
  const form = adminPage.locator('form:has(input[name="action"][value="create_teacher"])');
  check(await form.count() === 1, 'teacher_create_form_missing');
  await form.locator('input[name="roster_code"]').fill(fixture.teacher.roster);
  await form.locator('input[name="real_name"]').fill(fixture.teacher.name);
  await form.locator('input[name="reason"]').fill('本地 V4 Chrome 临时验收身份');
  await submit(adminPage, form, 'teacher_create');
  const identityId = Number.parseInt(await adminPage.locator('input[name="identity_id"]').first().getAttribute('value') ?? '', 10);
  check(Number.isSafeInteger(identityId) && identityId > 0, 'teacher_identity_id_invalid');
  created.teacherIdentityId = identityId;
  const claim = await issueClaim(adminPage, identityId, 'teacher_claim_issue');
  const opened = await openChromeRole('teacher', { width: 1920, height: 1080 });
  const page = opened.page;
  await go(page, coreOrigin, 'index.php?/class-identity/claim', 'teacher_claim_page');
  const claimForm = page.locator('form:has(input[name="action"][value="claim"])');
  check(await claimForm.count() === 1, 'teacher_claim_form_missing');
  await claimForm.locator('input[name="roster_code"]').fill(fixture.teacher.roster);
  await claimForm.locator('input[name="claim_code"]').fill(claim);
  await claimForm.locator('input[name="username"]').fill(fixture.teacher.username);
  await claimForm.locator('input[name="email"]').fill(fixture.teacher.email);
  await claimForm.locator('input[name="password"]').fill(fixture.teacher.password);
  await claimForm.locator('input[name="password_confirmation"]').fill(fixture.teacher.password);
  await submit(page, claimForm, 'teacher_claim');
  check((await page.locator('body').innerText()).includes('账号已创建'), 'teacher_claim_not_completed');
  await login(page, fixture.teacher, 'teacher');
  return page;
}
async function createFamilyAndAnonymous(classmatePage) {
  stageAt('provision_family_anonymous');
  await go(classmatePage, coreOrigin, 'index.php?/class-identity/my', 'classmate_my');
  const invite = classmatePage.locator('form:has(input[name="action"][value="issue_family_invitation"])');
  check(await invite.count() === 1, 'family_invitation_form_missing');
  await submit(classmatePage, invite, 'family_invitation_issue');
  const invitation = await codeValue(classmatePage, 3, 'family_invitation_issue');
  await go(classmatePage, coreOrigin, 'index.php?/class-identity/my', 'classmate_my_anonymous');
  const activate = classmatePage.locator('form:has(input[name="action"][value="activate_anonymous"])');
  check(await activate.count() === 1, 'anonymous_activation_form_missing');
  await submit(classmatePage, activate, 'anonymous_activation');
  const anonymousValues = (await classmatePage.locator('code.ca-public__secret').allTextContents()).map((value) => value.trim());
  check(anonymousValues.length === 2 && /^anon_[a-f0-9]{20}$/.test(anonymousValues[0] ?? '') && /^[A-Za-z0-9_-]{24,128}$/.test(anonymousValues[1] ?? ''), 'anonymous_credential_shape');

  const family = await openChromeRole('family', { width: 390, height: 844 });
  await go(family.page, coreOrigin, 'index.php?/class-identity/family-invite', 'family_invite_page');
  const familyForm = family.page.locator('form:has(input[name="action"][value="accept_family"])');
  check(await familyForm.count() === 1, 'family_accept_form_missing');
  await familyForm.locator('input[name="invitation_code"]').fill(invitation);
  await familyForm.locator('input[name="real_name"]').fill(fixture.family.name);
  await familyForm.locator('select[name="relationship"]').selectOption('MOTHER');
  await familyForm.locator('input[name="username"]').fill(fixture.family.username);
  await familyForm.locator('input[name="email"]').fill(fixture.family.email);
  await familyForm.locator('input[name="password"]').fill(fixture.family.password);
  await familyForm.locator('input[name="password_confirmation"]').fill(fixture.family.password);
  await submit(family.page, familyForm, 'family_accept');
  check((await family.page.locator('body').innerText()).includes('家庭账号已创建'), 'family_accept_not_completed');
  await login(family.page, fixture.family, 'family');

  const anonymous = await openChromeRole('anonymous', { width: 390, height: 844 });
  await login(anonymous.page, { username: anonymousValues[0], password: anonymousValues[1] }, 'anonymous', '匿名账号');
  return { familyPage: family.page, anonymousPage: anonymous.page };
}
async function productState(page, code) {
  const result = await page.evaluate(async () => {
    const response = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
    return { status: response.status, payload: await response.json().catch(() => null) };
  });
  check(result.status === 200 && result.payload && typeof result.payload === 'object' && !Array.isArray(result.payload), `${code}_unavailable`);
  return result.payload;
}
async function assertQuietSurface(page, code) {
  const text = await page.locator('body').innerText();
  check(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(text), `${code}_technical_copy_visible`);
  const markup = await page.locator('html').innerHTML();
  check(!/(?:classmate_identity|identity_id|seat_id|account_id|piwigo_image|immich_asset|media_reference)/i.test(markup), `${code}_backend_identifier_visible`);
}
async function save(page, name) {
  await page.screenshot({ path: path.join(screenshotDir, name), fullPage: false });
  screenshots += 1;
}
async function decodedPhoto(page, code) {
  await page.waitForFunction(() => {
    const image = document.querySelector('.photo-card img[src^="/api/assets/"]');
    return image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0;
  }, null, { timeout: 120_000 }).catch(() => null);
  const image = page.locator('.photo-card img[src^="/api/assets/"]').first();
  check(await image.count() === 1, `${code}_thumbnail_missing`);
  const source = await image.getAttribute('src');
  check(typeof source === 'string' && /^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=/.test(source), `${code}_not_mediaguard_path`);
  return source;
}
async function openSearch(page, code) {
  const trigger = page.locator('.search-trigger').first();
  check(await trigger.count() === 1, `${code}_trigger_missing`);
  await trigger.focus();
  await page.keyboard.press('Control+K');
  const dialog = page.locator('dialog[data-search-overlay="true"][open]');
  await dialog.waitFor({ state: 'visible', timeout: 30_000 }).catch(() => null);
  check(await dialog.count() === 1, `${code}_dialog_missing`);
  const input = dialog.getByRole('combobox', { name: '搜索照片', exact: true });
  check(await input.count() === 1 && await input.evaluate((node) => document.activeElement === node), `${code}_focus_missing`);
  return { dialog, input };
}
async function browseRole(page, role, expectedRole) {
  stageAt(`browse_${role}`);
  await go(page, photoOrigin, '/home', `${role}_home`);
  const state = await productState(page, `${role}_state`);
  check(state.role === expectedRole, `${role}_role_wrong`);
  if (role === 'classmate' || role === 'teacher') {
    check(state.canEraUpload === true && state.canFamilySubmission === false, `${role}_era_capability`);
  } else if (role === 'family') {
    check(state.canEraUpload === false && state.canFamilySubmission === true, 'family_capability');
  } else {
    check(state.canEraUpload === false && state.canFamilySubmission === false, 'anonymous_capability');
  }
  check(await page.getByRole('heading', { name: '精选集', exact: true }).count() >= 1, `${role}_home_missing`);
  await assertQuietSurface(page, `${role}_home`);
  await save(page, `${role}-home.png`);

  await go(page, photoOrigin, '/photos', `${role}_photos`);
  check(await page.getByRole('heading', { name: '资料库', exact: true }).count() >= 1, `${role}_photos_heading`);
  await page.waitForFunction(() => document.querySelectorAll('.photo-card').length >= 1, null, { timeout: 120_000 }).catch(() => null);
  const source = await decodedPhoto(page, `${role}_photos`);
  await assertQuietSurface(page, `${role}_photos`);
  await save(page, `${role}-photos.png`);

  const first = page.locator('.photo-card').first();
  const href = await first.getAttribute('href');
  check(typeof href === 'string' && /^\/photos\/[0-9a-f-]{36}$/i.test(href), `${role}_viewer_href`);
  await go(page, photoOrigin, href, `${role}_viewer`);
  const viewer = page.locator('.viewer-image[src^="/api/assets/"]');
  await viewer.waitFor({ state: 'visible', timeout: 90_000 }).catch(() => null);
  check(await viewer.count() === 1, `${role}_viewer_missing`);
  const viewerSrc = await viewer.getAttribute('src');
  check(typeof viewerSrc === 'string' && /^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=preview/.test(viewerSrc), `${role}_viewer_not_mediaguard`);
  const comments = page.getByRole('button', { name: '打开评论', exact: true });
  check(await comments.count() === 1, `${role}_comments_control_missing`);
  await comments.click();
  check(await comments.getAttribute('aria-expanded') === 'true', `${role}_comments_not_open`);
  if (role === 'family') {
    check(await page.locator('.viewer-comments > .comment-composer').count() === 0, 'family_comment_composer_visible');
    check(await page.locator('.comment-readonly').count() === 1, 'family_comment_readonly_missing');
  }
  await assertQuietSurface(page, `${role}_viewer`);
  await save(page, `${role}-viewer.png`);

  await go(page, photoOrigin, '/albums', `${role}_albums`);
  check(await page.getByRole('heading', { name: '相册', exact: true }).count() >= 1, `${role}_albums_heading`);
  await page.waitForFunction(() => document.querySelectorAll('.album-card').length >= 1, null, { timeout: 90_000 }).catch(() => null);
  await assertQuietSurface(page, `${role}_albums`);

  await go(page, photoOrigin, '/people', `${role}_people`);
  check(await page.getByRole('heading', { name: '人物', exact: true }).count() >= 1, `${role}_people_heading`);
  await assertQuietSurface(page, `${role}_people`);

  await go(page, photoOrigin, '/home', `${role}_search_home`);
  const search = await openSearch(page, `${role}_search`);
  await search.input.fill('毕业');
  await search.input.press('Enter');
  await page.waitForFunction(() => document.querySelector('.global-search-results .hybrid-results, .global-search-results .error-state') !== null, null, { timeout: 60_000 }).catch(() => null);
  check(await search.dialog.locator('.hybrid-results, .error-state').count() >= 1, `${role}_search_results_missing`);
  await assertQuietSurface(page, `${role}_search`);
  await page.keyboard.press('Escape');
  check(await page.locator('dialog[data-search-overlay="true"][open]').count() === 0, `${role}_search_escape`);

  // Browser requests must use the checked gateway media path. The check is
  // intentionally scoped to an authorized visible derivative; the synthetic
  // V4 scope suite owns a known-LIVING URL denial oracle.
  const response = await page.evaluate(async (target) => {
    const value = await fetch(target, { method: 'HEAD', credentials: 'same-origin', cache: 'no-store' });
    return { status: value.status, type: value.headers.get('content-type') ?? '' };
  }, source);
  check(response.status === 200 && /^image\//i.test(response.type), `${role}_authorized_thumbnail_denied`);
}
async function isFrozen(adminPage) {
  return (await adminPage.locator('form:has(input[name="action"][value="unfreeze_identity"])').count()) === 1;
}
async function freezeIdentity(adminPage, identityId, code) {
  if (!(Number.isSafeInteger(identityId) && identityId > 0)) return;
  await go(adminPage, coreOrigin, `admin.php?page=plugin-ClassIdentity-identities&identity_id=${identityId}`, `${code}_detail`);
  if (await isFrozen(adminPage)) return;
  const form = adminPage.locator('form:has(input[name="action"][value="freeze_identity"])');
  check(await form.count() === 1, `${code}_form_missing`);
  await form.locator('input[name="reason"]').fill('本地 V4 Chrome 验收结束，冻结临时身份');
  await submit(adminPage, form, code);
  check(await isFrozen(adminPage), `${code}_not_frozen`);
}
async function cleanupIdentities(adminPage) {
  await freezeIdentity(adminPage, created.teacherIdentityId, 'cleanup_teacher');
  await freezeIdentity(adminPage, created.classmateIdentityId, 'cleanup_classmate');
  cleanup = 'frozen';
}

let adminPage = null;
try {
  stageAt('admin_session');
  adminPage = await createAdminPage();
  const classmatePage = await createClassmateAndClaim(adminPage);
  const teacherPage = await createTeacherAndClaim(adminPage);
  const { familyPage, anonymousPage } = await createFamilyAndAnonymous(classmatePage);

  await browseRole(classmatePage, 'classmate', 'CLASSMATE');
  await browseRole(teacherPage, 'teacher', 'TEACHER');
  await browseRole(familyPage, 'family', 'FAMILY');
  await browseRole(anonymousPage, 'anonymous', 'ANONYMOUS');
  check(unexpectedNetwork.size === 0, 'unexpected_network_request');
  await cleanupIdentities(adminPage);
  process.stdout.write(`V4_OWNER_CHROME_QA=PASS assertions=${assertions} screenshots=${screenshots} channel=chrome chrome_product=chrome chrome_version=${chromeVersion} cleanup=${cleanup}\n`);
} catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,120}$/i.test(error.code) ? error.code : 'unexpected';
  process.stdout.write(`V4_OWNER_CHROME_QA=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
} finally {
  try {
    if (adminPage !== null && cleanup !== 'frozen') await cleanupIdentities(adminPage);
  } catch { process.exitCode = 1; cleanup = 'failed'; }
  for (const context of contexts.reverse()) await context.close().catch(() => { process.exitCode = 1; });
}
