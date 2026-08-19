/*
 * Real Chromium acceptance run for the localhost-only synthetic Class Archive
 * environment. Credentials and one-time codes remain in process memory only;
 * this script deliberately never writes or prints them.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const required = [
  'CLASS_ARCHIVE_BROWSER_QA_RUN_ID',
  'CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME',
  'CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD',
  'CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID',
  'CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID',
  'CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR',
];
for (const name of required) {
  if (!process.env[name]) {
    throw new Error(`BROWSER_QA: required runtime setting ${name} is absent.`);
  }
}

const runId = process.env.CLASS_ARCHIVE_BROWSER_QA_RUN_ID;
if (!/^[a-f0-9]{12}$/.test(runId)) {
  throw new Error('BROWSER_QA: invalid synthetic run namespace.');
}
const baseUrl = new URL(process.env.CLASS_ARCHIVE_BROWSER_QA_BASE_URL || 'http://127.0.0.1:8090/');
if (baseUrl.hostname !== '127.0.0.1' && baseUrl.hostname !== 'localhost') {
  throw new Error('BROWSER_QA: only a loopback origin is permitted.');
}
const screenshots = path.resolve(process.env.CLASS_ARCHIVE_BROWSER_QA_SCREENSHOT_DIR);
const profileDir = path.resolve(process.env.CLASS_ARCHIVE_BROWSER_QA_PROFILE_DIR || path.join(path.dirname(screenshots), 'browser-profile-' + runId));
const chromePath = process.env.CLASS_ARCHIVE_BROWSER_QA_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const ids = {
  heritage: Number.parseInt(process.env.CLASS_ARCHIVE_BROWSER_QA_HERITAGE_IMAGE_ID, 10),
  living: Number.parseInt(process.env.CLASS_ARCHIVE_BROWSER_QA_LIVING_IMAGE_ID, 10),
};
if (!Number.isSafeInteger(ids.heritage) || ids.heritage <= 0 || !Number.isSafeInteger(ids.living) || ids.living <= 0) {
  throw new Error('BROWSER_QA: canonical synthetic media identifiers are invalid.');
}

const admin = {
  username: process.env.CLASS_ARCHIVE_BROWSER_QA_ADMIN_USERNAME,
  password: process.env.CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD,
};
let assertions = 0;
function assert(condition, message) {
  assertions += 1;
  if (!condition) throw new Error(`BROWSER_QA: ${message}`);
}
const adminPasswordFingerprint = process.env.CLASS_ARCHIVE_BROWSER_QA_ADMIN_PASSWORD_SHA256 || '';
assert(/^[a-f0-9]{64}$/.test(adminPasswordFingerprint), 'SYSTEM_ADMIN fixture password fingerprint is unavailable.');
assert(crypto.createHash('sha256').update(admin.password, 'utf8').digest('hex') === adminPasswordFingerprint, 'SYSTEM_ADMIN password changed between CLI provisioning and Chromium process handoff.');
const suffix = runId;
function strongBrowserPassword(prefix) {
  return `${prefix}${crypto.randomBytes(32).toString('hex')}`;
}
const identities = {
  classmate: { roster: `CIT-C-${suffix.toUpperCase()}`, name: '测试同学甲', username: `cit_${suffix}_classmate`, email: `cit-${suffix}-classmate@class-archive.invalid`, password: strongBrowserPassword('Cm') },
  teacher: { roster: `CIT-T-${suffix.toUpperCase()}`, name: '测试老师甲', username: `cit_${suffix}_teacher`, email: `cit-${suffix}-teacher@class-archive.invalid`, password: strongBrowserPassword('Tc') },
  family: { name: '测试家属甲', username: `cit_${suffix}_family`, email: `cit-${suffix}-family@class-archive.invalid`, password: strongBrowserPassword('Fa') },
};
const comments = {
  heritage: `BQA-${suffix.toUpperCase()}-HERITAGE`,
  living: `BQA-${suffix.toUpperCase()}-LIVING`,
};
const approvedFilename = `BQA_${suffix}_approved.png`;
const rejectedFilename = `BQA_${suffix}_rejected.png`;
// Match the image bytes used by the real Phase 1 multipart HTTP regression:
// a known-good, standards-compliant synthetic 1×1 PNG accepted by the
// server-side MIME, image, thumbnail, and size validation pipeline.
const onePixelPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
function url(relative) { return new URL(relative, baseUrl).href; }
function registerDialogAutoAccept(page) {
  page.on('dialog', async dialog => { try { await dialog.accept(); } catch {} });
}
async function screenshot(page, filename) {
  await page.screenshot({ path: path.join(screenshots, filename), fullPage: true });
}
async function assertNoHorizontalOverflow(page, label) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  assert(!overflow, `${label} has horizontal layout overflow.`);
}
async function goto(page, relative, label, expected = [200]) {
  const response = await page.goto(url(relative), { waitUntil: 'domcontentloaded', timeout: 30_000 });
  const status = response?.status() ?? 0;
  assert(expected.includes(status), `${label} returned unexpected HTTP ${status}.`);
  await page.waitForTimeout(160);
  return response;
}
async function clickNavigation(page, label) {
  const link = page.getByRole('link', { name: label, exact: true }).first();
  assert(await link.count() === 1, `missing admin navigation label ${label}.`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }).catch(() => null),
    link.click(),
  ]);
  await page.waitForTimeout(160);
}
async function submitForm(page, form, label) {
  // HTML defaults a bare <button> to submit. Class Archive templates use
  // both explicit and implicit submit buttons, so exercise the real browser
  // behavior instead of falsely treating a valid control as absent.
  const button = form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last();
  assert(await button.count() === 1, `${label} submit button is missing.`);
  const [navigation] = await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
    button.click(),
  ]);
  await page.waitForTimeout(200);
  return navigation;
}
async function login(page, username, password, label, expectedSessionUsername = username) {
  await goto(page, 'identification.php', `${label} login page`);
  const initialCookies = await page.context().cookies(baseUrl.href);
  assert(initialCookies.some(cookie => cookie.name === 'pwg_id' && cookie.value.length >= 16), `${label} login page did not establish the required Piwigo session cookie.`);
  const form = page.locator('form[name="login_form"]');
  assert(await form.count() === 1, `${label} login form is missing.`);
  await form.locator('input[name="username"]').fill(username);
  await form.locator('input[name="password"]').fill(password);
  assert(await form.locator('input[name="username"]').inputValue() === username, `${label} login username was not retained by the browser form.`);
  assert(await form.locator('input[name="password"]').inputValue() === password, `${label} login password was not retained by the browser form.`);
  let postMatched = false;
  const captureLoginPost = request => {
    if (request.method() !== 'POST' || !request.url().endsWith('/identification.php')) return;
    const fields = new URLSearchParams(request.postData() || '');
    const usernames = fields.getAll('username');
    const passwords = fields.getAll('password');
    postMatched = usernames.length === 1
      && passwords.length === 1
      && usernames[0] === username
      && passwords[0] === password
      && fields.getAll('login').length === 1;
  };
  page.on('request', captureLoginPost);
  await submitForm(page, form, `${label} login`);
  page.off('request', captureLoginPost);
  assert(postMatched, `${label} browser did not submit the exact login form values.`);
  // Piwigo may retain identification.php as the post-login URL when no
  // redirect target was supplied. The next protected page request is the
  // authoritative proof of a session, rather than this cosmetic URL shape.
  const sessionStatus = await page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    const response = await fetch('ws.php?format=json', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
    try { return { status: response.status, json: await response.json() }; }
    catch { return { status: response.status, json: null }; }
  });
  assert(
    sessionStatus.status === 200 && sessionStatus.json?.stat === 'ok' && sessionStatus.json?.result?.username === expectedSessionUsername,
    `${label} login did not establish the expected browser session.`,
  );
}
async function logoutByNewContext(browser, options) {
  const context = await browser.newContext(options);
  const page = await context.newPage();
  registerDialogAutoAccept(page);
  return { context, page };
}
async function codeValues(page, expectedCount, label) {
  const codes = await page.locator('code.ca-admin__code, code.ca-public__secret').allTextContents();
  assert(codes.length === expectedCount, `${label} did not render the expected one-time credential count.`);
  return codes.map(value => value.trim());
}
async function fetchProtected(page, relative, method = 'GET', headers = {}) {
  return page.evaluate(async ({ target, method, headers }) => {
    const response = await fetch(target, { method, headers, credentials: 'same-origin', cache: 'no-store' });
    const buffer = await response.arrayBuffer();
    return {
      status: response.status,
      contentType: response.headers.get('content-type') || '',
      cacheControl: response.headers.get('cache-control') || '',
      bytes: buffer.byteLength,
      prefix: Array.from(new Uint8Array(buffer.slice(0, 16))),
    };
  }, { target: relative, method, headers });
}
async function sessionUsername(page) {
  return page.evaluate(async () => {
    const body = new URLSearchParams({ method: 'pwg.session.getStatus' });
    const response = await fetch('ws.php?format=json', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      cache: 'no-store',
    });
    try {
      const json = await response.json();
      return {
        status: response.status,
        stat: typeof json?.stat === 'string' ? json.stat : '',
        username: typeof json?.result?.username === 'string' ? json.result.username : '',
      };
    } catch {
      return { status: response.status, stat: '', username: '' };
    }
  });
}
function assertDeniedMedia(result, label) {
  assert([401, 403, 404].includes(result.status), `${label} was not denied.`);
  assert(!/^image\//i.test(result.contentType), `${label} returned an image MIME type.`);
  assert(result.prefix.slice(0, 8).join(',') !== '137,80,78,71,13,10,26,10', `${label} returned PNG bytes.`);
}
async function picture(page, imageId, label, expected = 200) {
  const response = await goto(page, `picture.php?/${imageId}`, label, [expected]);
  if (expected === 200) {
    const mainImage = page.locator('#theMainImage');
    if (await mainImage.count() !== 1) {
      const session = await sessionUsername(page);
      const location = new URL(page.url());
      throw new Error(
        `BROWSER_QA: ${label} did not render an authenticated photo viewer `
        + `(page=${location.pathname}, session=${session.status}/${session.stat}/${session.username || 'none'}).`,
      );
    }
    assert(true, `${label} rendered an authenticated photo viewer.`);
    const source = await mainImage.getAttribute('src');
    assert(typeof source === 'string' && source.length > 0 && !source.includes('identification.php'), `${label} did not render a protected media preview.`);
  }
  return response;
}
async function postComment(page, imageId, marker, label) {
  await picture(page, imageId, `${label} picture`);
  const form = page.locator('form').filter({ has: page.locator('textarea[name="content"]') }).first();
  assert(await form.count() === 1, `${label} comment form is missing.`);
  await form.locator('textarea[name="content"]').fill(marker);
  // The HTML picture form intentionally uses a three-second ephemeral key
  // (the WS form uses two), which blocks automated spam replay at Core level.
  await page.waitForTimeout(3_300);
  await submitForm(page, form, `${label} comment`);
  const body = await page.locator('body').innerText();
  assert(true, `${label} comment rendered after submission.`);
  const aliases = [...new Set(body.match(/匿名 [ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8,52}/g) || [])];
  assert(aliases.length >= 1, `${label} comment page did not render a context-scoped pseudonym.`);
  return aliases[0];
}
async function browserWsText(page, imageId) {
  return page.evaluate(async ({ imageId }) => {
    const form = new URLSearchParams({ method: 'pwg.images.getInfo', image_id: String(imageId), comments_page: '0', comments_per_page: '50' });
    const response = await fetch('ws.php?format=json', { method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store' });
    return { status: response.status, text: await response.text() };
  }, { imageId });
}
async function locateSubmissionRow(page, filename) {
  const rows = page.locator('tr').filter({ hasText: filename });
  assert(await rows.count() === 1, `submission row ${filename} is missing or ambiguous.`);
  return rows.first();
}

await fs.mkdir(screenshots, { recursive: true });
await fs.rm(profileDir, { recursive: true, force: true });
let browser;
const desktop = { viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' };

try {
  browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });

  // SYSTEM_ADMIN: actual login and business-control navigation.
  const adminActor = await logoutByNewContext(browser, desktop);
  const adminPage = adminActor.page;
  await goto(adminPage, 'identification.php', 'SYSTEM_ADMIN login page');
  assert((await adminPage.locator('body').innerText()).includes('登录'), 'login page is not localized to Simplified Chinese.');
  await screenshot(adminPage, '01-login.png');
  await login(adminPage, admin.username, admin.password, 'SYSTEM_ADMIN');
  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-dashboard', 'SYSTEM_ADMIN dashboard');
  assert((await adminPage.locator('body').innerText()).includes('仪表盘'), 'dashboard did not render Chinese navigation.');
  await assertNoHorizontalOverflow(adminPage, 'desktop dashboard');
  await screenshot(adminPage, '02-admin-dashboard.png');

  await clickNavigation(adminPage, '班级成员');
  assert((await adminPage.locator('body').innerText()).includes('新建同学身份'), 'members page is not localized.');
  const classmateCreate = adminPage.locator('form:has(input[name="action"][value="create_classmate"])');
  assert(await classmateCreate.count() === 1, 'Classmate creation form is missing.');
  await classmateCreate.locator('input[name="roster_code"]').fill(identities.classmate.roster);
  await classmateCreate.locator('input[name="real_name"]').fill(identities.classmate.name);
  await classmateCreate.locator('input[name="reason"]').fill('Phase 1.5 synthetic browser acceptance');
  await submitForm(adminPage, classmateCreate, 'create Classmate identity');
  const createdClassmateBody = await adminPage.locator('body').innerText();
  assert(createdClassmateBody.includes(identities.classmate.roster), 'created Classmate was not visible in its detail page.');
  await screenshot(adminPage, '03-admin-members.png');
  const claimForm = adminPage.locator('form:has(input[name="action"][value="reissue_claim"])');
  assert(await claimForm.count() === 1, 'Classmate claim issuance form is missing.');
  await claimForm.locator('input[name="reason"]').fill('Synthetic browser Claim issue');
  await submitForm(adminPage, claimForm, 'issue Classmate claim');
  const [classmateClaim] = await codeValues(adminPage, 1, 'Classmate Claim');
  assert(/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}$/.test(classmateClaim), 'Classmate Claim shape is invalid.');

  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-teachers', 'teacher page');
  const teacherCreate = adminPage.locator('form:has(input[name="action"][value="create_teacher"])');
  assert(await teacherCreate.count() === 1, 'Teacher creation form is missing.');
  await teacherCreate.locator('input[name="roster_code"]').fill(identities.teacher.roster);
  await teacherCreate.locator('input[name="real_name"]').fill(identities.teacher.name);
  await teacherCreate.locator('input[name="reason"]').fill('Phase 1.5 synthetic browser acceptance');
  await submitForm(adminPage, teacherCreate, 'create Teacher identity');
  const teacherClaimForm = adminPage.locator('form:has(input[name="action"][value="reissue_claim"])');
  assert(await teacherClaimForm.count() === 1, 'Teacher claim issuance form is missing.');
  await teacherClaimForm.locator('input[name="reason"]').fill('Synthetic browser Teacher Claim issue');
  await submitForm(adminPage, teacherClaimForm, 'issue Teacher claim');
  const [teacherClaim] = await codeValues(adminPage, 1, 'Teacher Claim');
  assert(/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}$/.test(teacherClaim), 'Teacher Claim shape is invalid.');

  for (const label of ['邀请与认领', '投稿审核', '匿名管理', '班级档案', '操作审计', '系统状态']) {
    await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-dashboard', 'admin navigation reset');
    await clickNavigation(adminPage, label);
    assert((await adminPage.locator('body').innerText()).includes(label), `admin page ${label} did not render its Chinese heading/navigation.`);
    await assertNoHorizontalOverflow(adminPage, `desktop ${label}`);
  }
  console.log('BROWSER_QA_STAGE=admin-navigation');

  // CLASSMATE: true public Claim, authenticated browsing, invitation and Anonymous activation.
  const classmateActor = await logoutByNewContext(browser, desktop);
  const classmatePage = classmateActor.page;
  await goto(classmatePage, 'index.php?/class-identity/claim', 'Classmate Claim page');
  const publicClaim = classmatePage.locator('form:has(input[name="action"][value="claim"])');
  assert(await publicClaim.count() === 1, 'public Claim form is missing.');
  await publicClaim.locator('input[name="roster_code"]').fill(identities.classmate.roster);
  await publicClaim.locator('input[name="claim_code"]').fill(classmateClaim);
  await publicClaim.locator('input[name="username"]').fill(identities.classmate.username);
  await publicClaim.locator('input[name="email"]').fill(identities.classmate.email);
  await publicClaim.locator('input[name="password"]').fill(identities.classmate.password);
  await publicClaim.locator('input[name="password_confirmation"]').fill(identities.classmate.password);
  await submitForm(classmatePage, publicClaim, 'Classmate public Claim');
  assert((await classmatePage.locator('body').innerText()).includes('账号已创建'), 'Classmate Claim did not complete.');
  await login(classmatePage, identities.classmate.username, identities.classmate.password, 'CLASSMATE');
  await picture(classmatePage, ids.heritage, 'CLASSMATE Heritage picture');
  await picture(classmatePage, ids.living, 'CLASSMATE Living picture');
  await goto(classmatePage, 'index.php?/class-identity/my', 'CLASSMATE My identity');
  assert((await classmatePage.locator('body').innerText()).includes('生成家庭邀请'), 'CLASSMATE Family invitation action is missing.');
  const familyIssue = classmatePage.locator('form:has(input[name="action"][value="issue_family_invitation"])');
  await submitForm(classmatePage, familyIssue, 'issue Family invitation');
  const [familyInvite] = await codeValues(classmatePage, 3, 'Family invitation');
  assert(/^[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}$/.test(familyInvite), 'Family invitation shape is invalid.');
  await goto(classmatePage, 'index.php?/class-identity/my', 'CLASSMATE My identity for Anonymous');
  const anonymousActivate = classmatePage.locator('form:has(input[name="action"][value="activate_anonymous"])');
  await submitForm(classmatePage, anonymousActivate, 'activate Anonymous Seat');
  const [anonymousUsername, anonymousPassword] = await codeValues(classmatePage, 2, 'Anonymous activation');
  assert(/^anon_[a-f0-9]{20}$/.test(anonymousUsername) && anonymousPassword.length >= 24, 'Anonymous credentials have an invalid shape.');
  console.log('BROWSER_QA_STAGE=classmate-claim-and-seats');

  // FAMILY: invitation registration, Heritage-only view, actual upload and
  // replay of SYSTEM_ADMIN-only pending endpoints as URL knowledge attacks.
  let familyActor = await logoutByNewContext(browser, desktop);
  let familyPage = familyActor.page;
  await goto(familyPage, 'index.php?/class-identity/family-invite', 'Family invitation page');
  const familyForm = familyPage.locator('form:has(input[name="action"][value="accept_family"])');
  assert(await familyForm.count() === 1, 'Family invite acceptance form is missing.');
  await familyForm.locator('input[name="invitation_code"]').fill(familyInvite);
  await familyForm.locator('input[name="real_name"]').fill(identities.family.name);
  await familyForm.locator('select[name="relationship"]').selectOption('MOTHER');
  await familyForm.locator('input[name="username"]').fill(identities.family.username);
  await familyForm.locator('input[name="email"]').fill(identities.family.email);
  await familyForm.locator('input[name="password"]').fill(identities.family.password);
  await familyForm.locator('input[name="password_confirmation"]').fill(identities.family.password);
  await submitForm(familyPage, familyForm, 'Family invitation acceptance');
  assert((await familyPage.locator('body').innerText()).includes('家庭账号已创建'), 'Family invitation did not complete.');
  await login(familyPage, identities.family.username, identities.family.password, 'FAMILY');
  await picture(familyPage, ids.heritage, 'FAMILY Heritage picture');
  // Piwigo's native ACL renderer may surface a protected-picture denial as
  // either a 403 page or a 401 login challenge. Both are safe denials; the
  // latter can invalidate the browser session, so re-establish the real
  // Family session before continuing the submission story.
  const familyLivingDenial = await goto(familyPage, `picture.php?/${ids.living}`, 'FAMILY Living denial', [401, 403]);
  if ((familyLivingDenial?.status() ?? 0) === 401) {
    await familyActor.context.close();
    familyActor = await logoutByNewContext(browser, desktop);
    familyPage = familyActor.page;
    await login(familyPage, identities.family.username, identities.family.password, 'FAMILY after LIVING denial');
  }
  await goto(familyPage, 'index.php?/class-identity/my', 'FAMILY My identity');
  const upload = familyPage.locator('form:has(input[name="action"][value="submit_family_photo"])');
  assert(await upload.count() === 1, 'Family submission form is missing.');
  await upload.locator('input[name="submission_file"]').setInputFiles({ name: approvedFilename, mimeType: 'image/png', buffer: onePixelPng });
  await upload.locator('input[name="suggested_date"]').fill('2008-06-01');
  await upload.locator('select[name="date_precision"]').selectOption('YEAR');
  await upload.locator('input[name="suggested_album"]').fill('合成浏览器验收');
  await upload.locator('textarea[name="description"]').fill('仅用于 localhost 合成浏览器验收。');
  await submitForm(familyPage, upload, 'Family approved-path submission');
  const familySubmissionBody = await familyPage.locator('body').innerText();
  assert(familySubmissionBody.includes('正在等待管理员审核'), 'Family submission did not become pending.');
  await screenshot(familyPage, '04-family-submit.png');

  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-submissions', 'SYSTEM_ADMIN submissions');
  const pendingRow = await locateSubmissionRow(adminPage, approvedFilename);
  const thumbnailSrc = await pendingRow.locator('img').getAttribute('src');
  assert(typeof thumbnailSrc === 'string' && thumbnailSrc.length > 0, 'pending submission thumbnail URL is missing.');
  const thumbnailUrl = new URL(thumbnailSrc, baseUrl);
  const originalUrl = new URL(thumbnailUrl);
  originalUrl.searchParams.set('action', 'submission_original');
  for (const [label, target, method, headers] of [
    ['pending thumbnail GET', thumbnailUrl.href, 'GET', {}],
    ['pending original GET', originalUrl.href, 'GET', {}],
    ['pending thumbnail HEAD', thumbnailUrl.href, 'HEAD', {}],
    ['pending original Range', originalUrl.href, 'GET', { Range: 'bytes=0-31' }],
  ]) {
    assertDeniedMedia(await fetchProtected(familyPage, target, method, headers), `FAMILY ${label}`);
  }
  // The Class Admin route deliberately fails closed by revoking an ordinary
  // account's session. Re-authenticate after this intentional URL-knowledge
  // attack; the denial itself is asserted above, and the next request proves
  // that approval changes visibility rather than relying on a stale session.
  const familySessionAfterPendingProbe = await sessionUsername(familyPage);
  if (familySessionAfterPendingProbe.username !== identities.family.username) {
    await familyActor.context.close();
    familyActor = await logoutByNewContext(browser, desktop);
    familyPage = familyActor.page;
    await login(familyPage, identities.family.username, identities.family.password, 'FAMILY after pending media denial');
  }
  await screenshot(adminPage, '05-admin-submission-review.png');
  const approve = pendingRow.locator('form:has(input[name="action"][value="approve_submission"])');
  assert(await approve.count() === 1, 'pending submission approval form is missing.');
  await approve.locator('select[name="date_precision"]').selectOption('YEAR');
  await approve.locator('input[name="archive_date"]').fill('2008-06-01');
  await approve.locator('input[name="event_label"]').fill('合成验收事件');
  await approve.locator('input[name="reason"]').fill('合成投稿通过验收');
  await submitForm(adminPage, approve, 'approve Family submission');
  assert((await adminPage.locator('body').innerText()).includes('已通过'), 'approved submission did not update its business status.');

  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-archive', 'archive management');
  const archiveRow = await locateSubmissionRow(adminPage, approvedFilename);
  const archiveText = await archiveRow.innerText();
  const approvedMatch = archiveText.match(/图片记录 #(\d+)/);
  assert(approvedMatch !== null, 'approved archive row did not expose its Piwigo image record.');
  const approvedImageId = Number.parseInt(approvedMatch[1], 10);
  assert(Number.isSafeInteger(approvedImageId) && approvedImageId > 0, 'approved Piwigo image id is invalid.');
  await screenshot(adminPage, '09-archive-management.png');
  await picture(familyPage, approvedImageId, 'FAMILY approved Heritage picture');
  await screenshot(familyPage, '06-family-approved-photo.png');
  console.log('BROWSER_QA_STAGE=family-approved');

  await goto(familyPage, 'index.php?/class-identity/my', 'FAMILY rejected submission form');
  const rejectUpload = familyPage.locator('form:has(input[name="action"][value="submit_family_photo"])');
  await rejectUpload.locator('input[name="submission_file"]').setInputFiles({ name: rejectedFilename, mimeType: 'image/png', buffer: onePixelPng });
  await rejectUpload.locator('select[name="date_precision"]').selectOption('UNKNOWN');
  await submitForm(familyPage, rejectUpload, 'Family rejected-path submission');
  console.log('BROWSER_QA_STAGE=family-reject-uploaded');
  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-submissions', 'SYSTEM_ADMIN rejected submission review');
  const rejectedRow = await locateSubmissionRow(adminPage, rejectedFilename);
  const reject = rejectedRow.locator('form:has(input[name="action"][value="reject_submission"])');
  assert(await reject.count() === 1, 'rejection form is missing.');
  await reject.locator('input[name="reason"]').fill('合成拒绝流程验收');
  await submitForm(adminPage, reject, 'reject Family submission');
  console.log('BROWSER_QA_STAGE=family-reject-reviewed');
  await goto(familyPage, 'index.php?/class-identity/my', 'FAMILY rejected submission status');
  assert((await familyPage.locator('body').innerText()).includes('已拒绝'), 'Family did not see the rejected submission status.');
  console.log('BROWSER_QA_STAGE=family-rejected');

  // TEACHER: true Claim, both eras, no Classmate-specific seat actions.
  const teacherActor = await logoutByNewContext(browser, desktop);
  const teacherPage = teacherActor.page;
  await goto(teacherPage, 'index.php?/class-identity/claim', 'Teacher Claim page');
  const teacherPublicClaim = teacherPage.locator('form:has(input[name="action"][value="claim"])');
  await teacherPublicClaim.locator('input[name="roster_code"]').fill(identities.teacher.roster);
  await teacherPublicClaim.locator('input[name="claim_code"]').fill(teacherClaim);
  await teacherPublicClaim.locator('input[name="username"]').fill(identities.teacher.username);
  await teacherPublicClaim.locator('input[name="email"]').fill(identities.teacher.email);
  await teacherPublicClaim.locator('input[name="password"]').fill(identities.teacher.password);
  await teacherPublicClaim.locator('input[name="password_confirmation"]').fill(identities.teacher.password);
  await submitForm(teacherPage, teacherPublicClaim, 'Teacher public Claim');
  assert((await teacherPage.locator('body').innerText()).includes('账号已创建'), 'Teacher Claim did not complete.');
  await login(teacherPage, identities.teacher.username, identities.teacher.password, 'TEACHER');
  await picture(teacherPage, ids.heritage, 'TEACHER Heritage picture');
  await picture(teacherPage, ids.living, 'TEACHER Living picture');
  await goto(teacherPage, 'index.php?/class-identity/my', 'TEACHER My identity');
  const teacherMyText = await teacherPage.locator('body').innerText();
  assert(!teacherMyText.includes('生成家庭邀请') && !teacherMyText.includes('激活匿名席位'), 'Teacher received Classmate-only Seat actions.');
  console.log('BROWSER_QA_STAGE=teacher-claim');

  // ANONYMOUS: distinct human-facing aliases in two contexts, with no mapping
  // in ordinary HTML or browser-fetched API data.
  const anonymousActor = await logoutByNewContext(browser, desktop);
  const anonymousPage = anonymousActor.page;
  // The presenter deliberately replaces the technical Core username in
  // session-status responses, including to the anonymous actor itself.
  await login(anonymousPage, anonymousUsername, anonymousPassword, 'ANONYMOUS', '匿名账号');
  const aliasHeritage = await postComment(anonymousPage, ids.heritage, comments.heritage, 'ANONYMOUS Heritage');
  await screenshot(anonymousPage, '07-anonymous-public-view.png');
  const anonymousHeritageApi = await browserWsText(anonymousPage, ids.heritage);
  assert(anonymousHeritageApi.status === 200, 'Anonymous browser API picture request failed.');
  for (const hidden of [identities.classmate.roster, identities.classmate.name, anonymousUsername]) {
    assert(!anonymousHeritageApi.text.includes(hidden), 'Anonymous browser API exposed an underlying identity mapping.');
  }
  const aliasLiving = await postComment(anonymousPage, ids.living, comments.living, 'ANONYMOUS Living');
  assert(aliasHeritage !== aliasLiving, 'Anonymous aliases were reused across distinct photo contexts.');
  await picture(anonymousPage, ids.heritage, 'ANONYMOUS stable Heritage context');
  assert((await anonymousPage.locator('body').innerText()).includes(aliasHeritage), 'Anonymous alias was not stable within one context.');
  const anonymousHtml = await anonymousPage.content();
  for (const hidden of [identities.classmate.roster, identities.classmate.name, anonymousUsername]) {
    assert(!anonymousHtml.includes(hidden), 'Anonymous ordinary HTML exposed an underlying identity mapping.');
  }

  // SYSTEM_ADMIN only sees the true mapping after an explicit, audited action.
  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-anonymous', 'Anonymous Governance');
  const anonymousListHtml = await adminPage.content();
  assert(!anonymousListHtml.includes(identities.classmate.roster) && !anonymousListHtml.includes(identities.classmate.name), 'Anonymous Governance list bulk-exposed the true mapping.');
  const aliasRow = adminPage.locator('tr').filter({ hasText: aliasHeritage });
  assert(await aliasRow.count() === 1, 'Anonymous Governance alias row is missing or ambiguous.');
  const resolve = aliasRow.locator('form:has(input[name="action"][value="resolve_anonymous"])');
  assert(await resolve.count() === 1, 'Anonymous Governance explicit resolution action is missing.');
  await resolve.locator('input[name="reason"]').fill('合成匿名治理验收');
  await submitForm(adminPage, resolve, 'resolve Anonymous identity');
  const resolutionText = await adminPage.locator('body').innerText();
  assert(resolutionText.includes(identities.classmate.roster) && resolutionText.includes(identities.classmate.name), 'explicit Anonymous resolution did not reveal the expected mapping.');
  await screenshot(adminPage, '08-anonymous-admin-resolve.png');
  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-audit', 'Audit log');
  assert((await adminPage.locator('body').innerText()).includes('查看匿名真实身份'), 'Anonymous resolution did not appear in the admin audit surface.');
  await goto(adminPage, 'admin.php?page=plugin-ClassIdentity-system', 'System Health');
  assert((await adminPage.locator('body').innerText()).includes('媒体访问安全验证'), 'System Health did not render Chinese media-attestation status.');
  await screenshot(adminPage, '10-system-health.png');
  console.log('BROWSER_QA_STAGE=anonymous-governance');

  // Responsive visual checks: mobile and an approximately 125% desktop scale.
  const mobile = await logoutByNewContext(browser, { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' });
  await login(mobile.page, admin.username, admin.password, 'SYSTEM_ADMIN mobile');
  await goto(mobile.page, 'admin.php?page=plugin-ClassIdentity-dashboard', 'mobile dashboard');
  await assertNoHorizontalOverflow(mobile.page, 'mobile dashboard');
  await screenshot(mobile.page, '11-mobile-admin-dashboard.png');
  await mobile.context.close();
  const zoom = await logoutByNewContext(browser, { viewport: { width: 1152, height: 900 }, deviceScaleFactor: 1.25, locale: 'zh-CN', timezoneId: 'Asia/Shanghai' });
  await login(zoom.page, identities.family.username, identities.family.password, 'FAMILY 125 percent');
  await goto(zoom.page, 'index.php?/class-identity/my', 'FAMILY 125 percent My identity');
  await assertNoHorizontalOverflow(zoom.page, '125 percent Family My identity');
  await zoom.context.close();

  for (const actor of [adminActor, classmateActor, familyActor, teacherActor, anonymousActor]) {
    await actor.context.close();
  }
  console.log(`AUTOMATED_BROWSER_QA=PASS assertions=${assertions} screenshots=11`);
} catch (error) {
  // Browser automation errors are diagnostic only. Keep the emitted summary
  // bounded and redact one-time selector.validator-shaped credentials.
  const raw = String(error?.message ?? error ?? 'unknown browser failure');
  const safe = raw
    .replace(/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}/g, '[redacted]')
    .replace(/[\r\n]+/g, ' ')
    .slice(0, 600);
  console.error(`BROWSER_QA: ${safe}`);
  process.exitCode = 1;
} finally {
  admin.password = '';
  identities.classmate.password = '';
  identities.teacher.password = '';
  identities.family.password = '';
  try { await browser?.close(); } catch {}
  try { await fs.rm(profileDir, { recursive: true, force: true }); } catch {}
}
