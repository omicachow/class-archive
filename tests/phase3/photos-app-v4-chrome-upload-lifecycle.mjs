/**
 * Synthetic-only successful-upload lifecycle for Photos App V4.
 *
 * This runner is deliberately separate from the read-safe V4 Chrome suites.
 * It exercises real browser file selection with a fresh Google Chrome Stable
 * profile, journals only opaque response UUIDs plus fixture SHA-256 values,
 * and leaves exact teardown to the sibling localhost-only fixture helper.
 * It never creates accounts, starts containers, or accepts a non-synthetic
 * credential document.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const SYNTHETIC_UPLOAD_ROOT = path.join(PROJECT_ROOT, '.codex-work', 'runtime', 'phase3-upload-lifecycle');
const MEMBER_UPLOAD_CAPTURE_BINDING = '__classArchiveV4CaptureMemberUpload';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SHA256 = /^[0-9a-f]{64}$/i;
const FIXTURE_NAMES = Object.freeze([
  Object.freeze({ role: 'classmate', era: 'HERITAGE', file: 'classmate-heritage.png' }),
  Object.freeze({ role: 'classmate', era: 'LIVING', file: 'classmate-living.png' }),
  Object.freeze({ role: 'teacher', era: 'HERITAGE', file: 'teacher-heritage.png' }),
  Object.freeze({ role: 'teacher', era: 'LIVING', file: 'teacher-living.png' }),
  Object.freeze({ role: 'family', era: 'HERITAGE', file: 'family-heritage.png' }),
]);
const FAMILY_TAMPER_FIXTURE = Object.freeze({ role: 'family', era: 'LIVING', file: 'family-tampered-living.png', tamper: true });

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

let assertions = 0;
let stage = 'initialization';
let chromeProduct = 'unknown';
let chromeVersion = 'unknown';

function fail(code) { throw new GateError(code); }
function check(value, code) {
  assertions += 1;
  if (!value) fail(code);
}
function stageAt(value) {
  stage = value;
  process.stdout.write(`V4_CHROME_UPLOAD_STAGE=${value}\n`);
}

const settings = Object.freeze({
  piwigo: process.env.CLASS_ARCHIVE_V4_UPLOAD_PIWIGO_ORIGIN,
  photos: process.env.CLASS_ARCHIVE_V4_UPLOAD_PHOTO_ORIGIN,
  credentials: process.env.CLASS_ARCHIVE_V4_UPLOAD_CREDENTIAL_FILE,
  fixtureRoot: process.env.CLASS_ARCHIVE_V4_UPLOAD_FIXTURE_ROOT,
  userDataRoot: process.env.CLASS_ARCHIVE_V4_UPLOAD_USER_DATA_ROOT,
  journal: process.env.CLASS_ARCHIVE_V4_UPLOAD_RESULT_FILE,
});

function loopbackOrigin(value, port, code) {
  let url;
  try { url = new URL(value); } catch { fail(code); }
  check(url.protocol === 'http:' && url.hostname === '127.0.0.1' && url.port === String(port)
    && url.pathname === '/' && !url.username && !url.password && !url.search && !url.hash, code);
  return url.toString();
}

function absolutePath(value, code) {
  check(typeof value === 'string' && value.length > 0 && path.isAbsolute(value) && !value.includes('\0'), code);
  return path.resolve(value);
}

function childPath(root, name, code) {
  // Browser profiles are named from a role token, whereas the wrapper-owned
  // synthetic upload fixtures are strict PNG leaf names.  Permit exactly
  // those two child shapes—not arbitrary extensions or nested paths.
  check(/^(?:[a-z0-9-]{3,120}|[a-z0-9-]{3,112}\.png)$/i.test(name), code);
  const base = absolutePath(root, code);
  const resolved = path.resolve(base, name);
  const relative = path.relative(base, resolved);
  check(relative.length > 0 && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), code);
  return resolved;
}

function readCredentials() {
  let document;
  try { document = JSON.parse(fs.readFileSync(absolutePath(settings.credentials, 'credential_path'), 'utf8')); }
  catch { fail('credential_document'); }
  check(document?.version === 1 && document?.environment === 'synthetic', 'credential_scope');
  check(Object.keys(document.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_roles');
  for (const role of ['classmate', 'family', 'teacher']) {
    const entry = document.roles[role];
    check(typeof entry?.username === 'string' && entry.username.length > 0 && entry.username.length <= 190, `credential_${role}_username`);
    check(typeof entry?.password === 'string' && entry.password.length >= 24 && entry.password.length <= 190, `credential_${role}_password`);
  }
  return document;
}

function allowedUrl(value) {
  return ['about:', 'blob:', 'data:'].includes(value.protocol)
    || (value.protocol === 'http:' && value.hostname === '127.0.0.1' && ['8090', '8091'].includes(value.port));
}

function isPiwigoFamilySubmissionPage(value) {
  const piwigo = new URL(settings.piwigo);
  // The BFF redirects this fixed core route to Piwigo's canonical query
  // routing form (`/index.php?/class-identity/my`), not a pretty pathname.
  return value.origin === piwigo.origin && value.pathname === '/index.php'
    && value.search === '?/class-identity/my';
}

function sha256File(file) {
  const target = absolutePath(file, 'fixture_file_path');
  const stat = fs.lstatSync(target);
  check(stat.isFile() && !stat.isSymbolicLink() && stat.size >= 80 && stat.size <= 4096, 'fixture_file_trusted');
  return crypto.createHash('sha256').update(fs.readFileSync(target)).digest('hex');
}

function pngFixture(file, runId, name) {
  const bytes = fs.readFileSync(file);
  const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const marker = Buffer.from(`class_archive_fixture\0${runId}-${name}`, 'utf8');
  check(bytes.length >= 80 && bytes.length <= 4096 && bytes.subarray(0, 8).equals(signature), 'fixture_png_signature');
  check(bytes.readUInt32BE(8) === 13 && bytes.subarray(12, 16).equals(Buffer.from('IHDR'))
    && bytes.readUInt32BE(16) === 1 && bytes.readUInt32BE(20) === 1, 'fixture_png_dimensions');
  check(bytes.includes(marker) && bytes.subarray(-12).equals(Buffer.from('0000000049454e44ae426082', 'hex')), 'fixture_png_marker');
}

function fixtureInputs() {
  const root = absolutePath(settings.fixtureRoot, 'fixture_root');
  const runRoot = path.dirname(root);
  const relative = path.relative(SYNTHETIC_UPLOAD_ROOT, root);
  const parts = relative.split(path.sep);
  check(parts.length === 2 && /^[a-f0-9]{16}$/i.test(parts[0]) && parts[1] === 'fixtures', 'fixture_root_scope');
  for (const trusted of [SYNTHETIC_UPLOAD_ROOT, runRoot, root]) {
    const stat = fs.lstatSync(trusted);
    check(stat.isDirectory() && !stat.isSymbolicLink(), 'fixture_root_trusted');
  }
  const stat = fs.lstatSync(root);
  check(stat.isDirectory() && !stat.isSymbolicLink(), 'fixture_root_trusted');
  const runId = parts[0].toLowerCase();
  return [...FIXTURE_NAMES.slice(0, 4), FAMILY_TAMPER_FIXTURE, FIXTURE_NAMES[4]].map((entry) => {
    const file = childPath(root, entry.file, 'fixture_child');
    pngFixture(file, runId, entry.file);
    const checksum = sha256File(file);
    check(SHA256.test(checksum), 'fixture_checksum');
    return Object.freeze({ ...entry, file, checksum: checksum.toLowerCase() });
  });
}

function writeJournal(target, value) {
  const journal = absolutePath(target, 'journal_path');
  const parent = path.dirname(journal);
  const root = absolutePath(settings.fixtureRoot, 'fixture_root');
  const workRoot = path.dirname(root);
  const relative = path.relative(workRoot, journal);
  check(relative.length > 0 && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative), 'journal_outside_private_root');
  const raw = JSON.stringify(value);
  const temporary = `${journal}.next`;
  check(!fs.existsSync(temporary), 'journal_temporary_exists');
  fs.writeFileSync(temporary, raw, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
  fs.renameSync(temporary, journal);
}

function expectedJournal(inputs) {
  return {
    version: 1,
    environment: 'synthetic',
    state: 'RUNNING',
    expected: inputs.filter((entry) => entry.tamper !== true).map(({ role, era, checksum }) => ({ role, era, checksum })),
    uploads: [],
  };
}

async function recordChromeStableVersion(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const info = await session.send('Browser.getVersion');
    const product = typeof info?.product === 'string' ? info.product : '';
    const match = /^(Chrome|HeadlessChrome)\/(\d+(?:\.\d+){1,4})$/.exec(product);
    check(match !== null && match[1] === 'Chrome', 'chrome_stable_product');
    chromeProduct = 'chrome';
    chromeVersion = match[2];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally {
    await session?.detach().catch(() => null);
  }
}

function safeObservedMemberUpload(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const status = Number.isInteger(value.status) ? value.status : 0;
  const contentType = typeof value.contentType === 'string' && value.contentType.length <= 200
    ? value.contentType
    : '';
  const rawPayload = value.payload;
  const payload = rawPayload && typeof rawPayload === 'object' && !Array.isArray(rawPayload)
    ? {
      state: rawPayload.state,
      photoId: rawPayload.photoId,
      albumId: rawPayload.albumId,
      era: rawPayload.era,
      indexPending: rawPayload.indexPending,
      derivativeWarmupPending: rawPayload.derivativeWarmupPending,
    }
    : null;
  return { status, contentType, payload };
}

async function waitForObservedMemberUpload(observations, expectedIndex, code) {
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    if (observations.length > expectedIndex) {
      const observed = safeObservedMemberUpload(observations[expectedIndex]);
      if (observed) return observed;
      fail(`${code}_capture_shape`);
    }
    await new Promise((resolve) => setTimeout(resolve, 25));
  }
  fail(`${code}_capture_timeout`);
}

async function open(role, credentials) {
  const profile = childPath(settings.userDataRoot, role, 'profile_child');
  check(!fs.existsSync(profile), `profile_${role}_fresh`);
  let context = null;
  try {
    context = await chromium.launchPersistentContext(profile, {
      // This explicitly chooses the installed Google Chrome Stable binary,
      // never bundled Chromium and never the user's normal Chrome profile.
      channel: 'chrome',
      // A successful upload is final visual/browser evidence, not a bundled
      // headless approximation. It deliberately requires an interactive
      // desktop and confirms the CDP product is Google Chrome Stable.
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
    const observedMemberUploads = [];
    await context.exposeBinding(MEMBER_UPLOAD_CAPTURE_BINDING, (_source, value) => {
      const observed = safeObservedMemberUpload(value);
      if (observed) observedMemberUploads.push(observed);
      return true;
    });
    await context.route('**/*', (route) => {
      try { return allowedUrl(new URL(route.request().url())) ? route.continue() : route.abort(); }
      catch { return route.abort(); }
    });
    // The owned UI refreshes its projection in a zero-delay task after it has
    // consumed a successful upload response. Playwright can then lose the
    // response body while the page is navigating. Capture a cloned, strictly
    // whitelisted response in the browser before returning it to the UI; this
    // preserves the real Chrome request and the UI's own JSON consumption.
    await context.addInitScript((binding) => {
      const nativeFetch = window.fetch.bind(window);
      window.fetch = async (...args) => {
        const response = await nativeFetch(...args);
        try {
          const input = args[0];
          const request = typeof Request === 'function' && input instanceof Request ? input : null;
          const rawUrl = typeof input === 'string'
            ? input
            : input instanceof URL
              ? input.href
              : request?.url;
          const method = String(args[1]?.method ?? request?.method ?? 'GET').toUpperCase();
          const url = new URL(rawUrl, window.location.href);
          if (method === 'POST' && url.origin === window.location.origin && url.pathname === '/api/class-archive/member-upload') {
            let payload = null;
            try {
              const decoded = await response.clone().json();
              if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
                payload = {
                  state: decoded.state,
                  photoId: decoded.photoId,
                  albumId: decoded.albumId,
                  era: decoded.era,
                  indexPending: decoded.indexPending,
                  derivativeWarmupPending: decoded.derivativeWarmupPending,
                };
              }
            } catch { /* The Node assertion records a bounded capture failure. */ }
            await window[binding]({
              status: response.status,
              contentType: response.headers.get('content-type') ?? '',
              payload,
            });
          }
        } catch { /* Test instrumentation must never alter the application response. */ }
        return response;
      };
    }, MEMBER_UPLOAD_CAPTURE_BINDING);
    const page = context.pages()[0] ?? await context.newPage();
    await recordChromeStableVersion(context, page);
    await page.goto(new URL('identification.php', settings.piwigo).toString(), { waitUntil: 'domcontentloaded', timeout: 30_000 });
    const form = page.locator('form[name="login_form"]');
    check(await form.count() === 1, `${role}_login_form`);
    await form.locator('input[name="username"]').fill(credentials.roles[role].username);
    await form.locator('input[name="password"]').fill(credentials.roles[role].password);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => null),
      form.locator('button[type="submit"], button:not([type]), input[type="submit"]').last().click(),
    ]);
    return { context, page, observedMemberUploads };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(context === null ? 'chrome_stable_launch' : 'chrome_session');
  }
}

async function gotoPhotos(page, role) {
  await page.goto(new URL('/photos', settings.photos).toString(), { waitUntil: 'networkidle', timeout: 30_000 });
  await page.locator('[data-photo-app="true"]').waitFor({ timeout: 15_000 });
  check(await page.locator('.photo-card').first().count() === 1, `${role}_photos_grid`);
}

async function roleState(page, role) {
  const state = await page.evaluate(async () => {
    try {
      const response = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
      return { status: response.status, body: await response.json().catch(() => null) };
    } catch { return { status: 0, body: null }; }
  });
  check(state.status === 200 && state.body?.role === role.toUpperCase()
    && typeof state.body?.csrfToken === 'string' && state.body.csrfToken.length >= 16, `${role}_product_state`);
  return state.body;
}

async function selectFile(page, locator, file, code) {
  const chooserPromise = page.waitForEvent('filechooser', { timeout: 10_000 });
  await locator.click();
  const chooser = await chooserPromise;
  check(chooser.isMultiple() === false, `${code}_single_file`);
  // This is a real browser file chooser path; no DOM value injection is used.
  await chooser.setFiles([absolutePath(file, `${code}_file`)]);
}

async function directMemberUpload(page, record, journal, observedMemberUploads) {
  stageAt(`${record.role}_${record.era.toLowerCase()}_upload`);
  const state = await roleState(page, record.role);
  check(state.canEraUpload === true && state.canFamilySubmission === false, `${record.role}_direct_upload_state`);
  const trigger = page.getByRole('button', { name: '上传', exact: true });
  check(await trigger.count() === 1, `${record.role}_upload_trigger`);
  await trigger.click();
  const dialog = page.locator('dialog.era-upload-dialog[open]');
  await dialog.waitFor({ state: 'visible', timeout: 10_000 });
  const era = dialog.locator(`input[name="era"][value="${record.era}"]`);
  check(await era.count() === 1, `${record.role}_${record.era.toLowerCase()}_era_choice`);
  await era.check();
  const album = dialog.locator('select[name="album"]');
  await album.waitFor({ state: 'visible', timeout: 5_000 });
  const options = await album.locator('option').evaluateAll((nodes) => nodes
    .map((node) => ({ value: node.value, disabled: node.disabled }))
    .filter((node) => /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(node.value) && !node.disabled));
  check(options.length >= 1, `${record.role}_${record.era.toLowerCase()}_album_options`);
  const albumId = options[0].value.toLowerCase();
  await album.selectOption(albumId);
  await selectFile(page, dialog.locator('input[type="file"][name="photo"]'), record.file, `${record.role}_${record.era.toLowerCase()}`);
  const responsePromise = page.waitForResponse((response) => {
    try {
      const url = new URL(response.url());
      return url.origin === new URL(settings.photos).origin && url.pathname === '/api/class-archive/member-upload'
        && response.request().method() === 'POST';
    } catch { return false; }
  }, { timeout: 30_000 });
  const projectionReloadPromise = page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 })
    .then(() => true)
    .catch(() => false);
  const observedIndex = observedMemberUploads.length;
  // Persist a checksum-only intent before dispatch. If the browser dies after
  // the server writes but before it returns the opaque UUID, the localhost
  // fixture can safely resolve that one preflighted checksum to its UUID and
  // clean it; it never searches a filename or a directory pattern.
  journal.uploads.push({ kind: 'PUBLISHED_INTENT', role: record.role, era: record.era, checksum: record.checksum });
  writeJournal(settings.journal, journal);
  // The dialog's async submit handler never navigates. Do not let
  // Playwright's generic post-click navigation waiting mask the actual
  // bounded upload response that this suite is asserting.
  await dialog.getByRole('button', { name: '确认上传', exact: true }).click({ noWaitAfter: true });
  const response = await responsePromise;
  const responseCode = `${record.role}_${record.era.toLowerCase()}_response`;
  const observed = await waitForObservedMemberUpload(observedMemberUploads, observedIndex, responseCode);
  await page.locator('dialog.era-upload-dialog[open]').waitFor({ state: 'hidden', timeout: 10_000 });
  check(await page.locator('dialog.era-upload-dialog[open]').count() === 0, `${record.role}_${record.era.toLowerCase()}_dialog_completed`);
  const payload = observed.payload;
  check(response.status() === 201 && observed.status === 201
    && observed.contentType.toLowerCase().startsWith('application/json')
    && payload && typeof payload === 'object' && !Array.isArray(payload), `${record.role}_${record.era.toLowerCase()}_response_status`);
  check(payload.state === 'PUBLISHED' && UUID_V4.test(payload.photoId ?? '')
    && UUID_V4.test(payload.albumId ?? '') && payload.albumId.toLowerCase() === albumId
    && payload.era === record.era && typeof payload.indexPending === 'boolean'
    && typeof payload.derivativeWarmupPending === 'boolean', `${record.role}_${record.era.toLowerCase()}_response_contract`);
  check(await projectionReloadPromise, `${record.role}_${record.era.toLowerCase()}_projection_reload`);
  await page.locator('[data-photo-app="true"]').waitFor({ timeout: 15_000 });
  const intentIndex = journal.uploads.findIndex((entry) => entry.kind === 'PUBLISHED_INTENT'
    && entry.role === record.role && entry.era === record.era && entry.checksum === record.checksum);
  check(intentIndex >= 0, `${record.role}_${record.era.toLowerCase()}_cleanup_intent`);
  const entry = { kind: 'PUBLISHED', role: record.role, era: record.era, photoId: payload.photoId.toLowerCase(), checksum: record.checksum };
  journal.uploads[intentIndex] = entry;
  writeJournal(settings.journal, journal);
  // The BFF contract deliberately exposes only the opaque ClassArchivePhoto
  // UUID. The test retains that UUID + locally calculated fixture checksum
  // solely for exact test-only cleanup; neither is printed to stdout.
  const returned = await page.evaluate(async (id) => {
    try {
      const response = await fetch(`/api/assets/${id}`, { credentials: 'same-origin', cache: 'no-store' });
      return { status: response.status, body: await response.json().catch(() => null) };
    } catch { return { status: 0, body: null }; }
  }, entry.photoId);
  check(returned.status === 200 && returned.body?.id === entry.photoId, `${record.role}_${record.era.toLowerCase()}_published_visible`);
}

async function familyPendingUpload(page, record, journal) {
  stageAt('family_heritage_pending_upload');
  const state = await roleState(page, 'family');
  check(state.canEraUpload === false && state.canFamilySubmission === true, 'family_pending_state');
  const trigger = page.getByRole('button', { name: '投稿历史照片', exact: true });
  check(await trigger.count() === 1, 'family_pending_trigger');
  const pendingDestination = page.waitForURL(isPiwigoFamilySubmissionPage, { timeout: 20_000 });
  await trigger.click({ noWaitAfter: true });
  await pendingDestination;
  const form = page.locator('form[enctype="multipart/form-data"]').filter({ has: page.locator('input[name="submission_file"]') });
  check(await form.count() === 1, 'family_pending_form');
  const era = form.locator('input[name="era"]');
  check(await era.count() === 1 && await era.inputValue() === 'HERITAGE', 'family_pending_heritage_only');
  // Write the expected checksum before submit so finally can locate a server
  // accepted Pending row even if a browser process dies after form submit.
  journal.uploads.push({ kind: 'PENDING_INTENT', role: 'family', era: 'HERITAGE', checksum: record.checksum });
  writeJournal(settings.journal, journal);
  await selectFile(page, form.locator('input[type="file"][name="submission_file"]'), record.file, 'family_heritage');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 }).catch(() => null),
    form.getByRole('button', { name: '提交审核', exact: true }).click(),
  ]);
  check((await page.locator('body').innerText()).includes('正在等待管理员审核'), 'family_pending_success_message');
  journal.uploads = journal.uploads.map((entry) => entry.kind === 'PENDING_INTENT'
    ? { kind: 'PENDING', role: 'family', era: 'HERITAGE', checksum: record.checksum }
    : entry);
  writeJournal(settings.journal, journal);
}

async function familyLivingTamper(page, record) {
  stageAt('family_living_tamper_denied');
  const state = await roleState(page, 'family');
  check(state.canEraUpload === false && state.canFamilySubmission === true, 'family_tamper_state');
  stageAt('family_living_tamper_open');
  stageAt('family_living_tamper_destination');
  const tamperDestination = page.waitForURL(isPiwigoFamilySubmissionPage, { timeout: 20_000 });
  stageAt('family_living_tamper_open_click');
  await page.getByRole('button', { name: '投稿历史照片', exact: true }).click({ noWaitAfter: true });
  stageAt('family_living_tamper_open_wait');
  await tamperDestination;
  stageAt('family_living_tamper_form');
  const form = page.locator('form[enctype="multipart/form-data"]').filter({ has: page.locator('input[name="submission_file"]') });
  check(await form.count() === 1, 'family_tamper_form');
  const era = form.locator('input[name="era"]');
  check(await era.count() === 1 && await era.inputValue() === 'HERITAGE', 'family_tamper_initial_heritage');
  // Exercise the actual Family endpoint with a real browser-selected synthetic
  // file after a caller-controlled hidden marker is changed to LIVING.
  await era.evaluate((node) => { node.value = 'LIVING'; });
  check(await era.inputValue() === 'LIVING', 'family_tamper_marker_mutated');
  stageAt('family_living_tamper_file');
  await selectFile(page, form.locator('input[type="file"][name="submission_file"]'), record.file, 'family_tamper_living');
  const responsePromise = page.waitForResponse((response) => {
    try {
      const url = new URL(response.url());
      return isPiwigoFamilySubmissionPage(url)
        && response.request().method() === 'POST';
    } catch { return false; }
  }, { timeout: 30_000 });
  // The rejected POST returns an identity page and replaces this document.
  // Keep the response and navigation observations separate so Playwright does
  // not discard one while it waits on the other.
  const rejectionNavigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 })
    .then(() => true)
    .catch(() => false);
  stageAt('family_living_tamper_dispatch');
  await form.getByRole('button', { name: '提交审核', exact: true }).click({ noWaitAfter: true });
  stageAt('family_living_tamper_response');
  const response = await responsePromise;
  stageAt('family_living_tamper_navigation');
  check(await rejectionNavigation, 'family_tamper_navigation');
  stageAt('family_living_tamper_assert');
  check(response.status() === 200, 'family_tamper_response_status');
  check((await page.locator('body').innerText()).includes('投稿资料或照片格式不符合要求'), 'family_tamper_validation_error');
}

async function run() {
  const piwigo = loopbackOrigin(settings.piwigo, 8090, 'piwigo_origin');
  const photos = loopbackOrigin(settings.photos, 8091, 'photos_origin');
  void piwigo; void photos;
  const credentials = readCredentials();
  const inputs = fixtureInputs();
  const journal = expectedJournal(inputs);
  check(!fs.existsSync(absolutePath(settings.journal, 'journal_path')), 'journal_not_fresh');
  writeJournal(settings.journal, journal);

  const byRole = new Map();
  for (const input of inputs) {
    if (!byRole.has(input.role)) byRole.set(input.role, []);
    byRole.get(input.role).push(input);
  }
  for (const role of ['classmate', 'teacher', 'family']) {
    const { context, page, observedMemberUploads } = await open(role, credentials);
    try {
      await gotoPhotos(page, role);
      for (const record of byRole.get(role) ?? []) {
        if (role === 'family' && record.tamper === true) {
          await familyLivingTamper(page, record);
          // A tampered Family form deliberately stays on Piwigo's validation
          // page. Return through the real BFF route before testing the
          // separate, valid Pending submission flow.
          await gotoPhotos(page, role);
        }
        else if (role === 'family') await familyPendingUpload(page, record, journal);
        else await directMemberUpload(page, record, journal, observedMemberUploads);
      }
    } finally {
      await context.close().catch(() => null);
    }
  }
  journal.state = 'COMPLETE';
  writeJournal(settings.journal, journal);
}

run().then(() => {
  process.stdout.write(`V4_CHROME_UPLOAD_LIFECYCLE=PASS assertions=${assertions} uploads=5 channel=chrome chrome_product=${chromeProduct} chrome_version=${chromeVersion}\n`);
}).catch((error) => {
  const code = error instanceof GateError ? error.code : 'unexpected';
  process.stdout.write(`V4_CHROME_UPLOAD_LIFECYCLE=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
});
