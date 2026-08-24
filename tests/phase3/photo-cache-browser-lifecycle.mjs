/*
 * Focused real-Chromium lifecycle proof for the owned Photo UI. All responses
 * are synthetic and intercepted in-process; no account, private photo, server
 * mutation or external network is involved.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const uiRoot = path.join(root, 'infra', 'immich-spike', 'photo-ui');
const revision = 'phase33a-lifecycle-test';
const [htmlSource, cssSource, appSource, i18nSource] = await Promise.all([
  fs.readFile(path.join(uiRoot, 'index.html'), 'utf8'),
  fs.readFile(path.join(uiRoot, 'app.css'), 'utf8'),
  fs.readFile(path.join(uiRoot, 'app.js'), 'utf8'),
  fs.readFile(path.join(uiRoot, 'i18n.js'), 'utf8'),
]);
const versioned = (source) => source.replaceAll('__PHOTO_UI_ASSET_REV__', revision);

class GateError extends Error {}
let assertions = 0;
function check(condition, code) {
  assertions += 1;
  if (!condition) throw new GateError(code);
}

const livingPhotoId = '10000000-0000-4000-8000-000000000001';
const photoRevision = '1'.repeat(32);
const scopes = {
  classmate: 'a'.repeat(32),
  family: 'b'.repeat(32),
};
const presentationEpochs = {
  classmate: '1'.repeat(64),
  family: '2'.repeat(64),
};
let actor = 'classmate';
let productStateStatus = 200;
let productStateDelayMs = 0;
let timelineStatus = 200;
let timelineDelayMs = 0;

const timeline = () => actor === 'family' ? { total: 0, groups: [] } : {
  total: 1,
  groups: [{
    label: '毕业后动态',
    total: 1,
    items: [{
      id: livingPhotoId,
      title: '浏览权限边界测试照片',
      width: 1200,
      height: 800,
      media_revision: photoRevision,
      archive_date: { label: '2025年', precision: '仅确定到年份', sourceLabel: '管理员确认' },
    }],
  }],
};

const json = (route, status, payload) => route.fulfill({
  status,
  contentType: 'application/json; charset=utf-8',
  headers: { 'Cache-Control': 'private, no-cache, max-age=0, must-revalidate' },
  body: JSON.stringify(payload),
});
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
async function waitForPathname(page, expectedPathname, timeoutMs = 10_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      if (new URL(page.url()).pathname === expectedPathname) return;
    } catch { }
    await delay(50);
  }
  throw new GateError(`navigation_timeout_${expectedPathname}`);
}
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

const programFiles = process.env.ProgramFiles;
const executablePath = process.env.CLASS_ARCHIVE_PHASE3_BROWSER_CHROME
  ?? (typeof programFiles === 'string' && programFiles !== ''
    ? path.join(programFiles, 'Google', 'Chrome', 'Application', 'chrome.exe')
    : null);
if (executablePath === null) throw new GateError('browser_executable_path_unavailable');
let browser;
try {
  browser = await chromium.launch({ executablePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
  const context = await browser.newContext({ viewport: { width: 1200, height: 800 }, locale: 'zh-CN' });
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/photos') {
      await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: versioned(htmlSource) });
      return;
    }
    if (url.pathname === '/control' || url.pathname === '/auth/login') {
      await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>control</title>' });
      return;
    }
    if (url.pathname === '/photo-ui/app.css') {
      await route.fulfill({ status: 200, contentType: 'text/css; charset=utf-8', body: versioned(cssSource) });
      return;
    }
    if (url.pathname === '/photo-ui/app.js') {
      await route.fulfill({ status: 200, contentType: 'text/javascript; charset=utf-8', body: versioned(appSource) });
      return;
    }
    if (url.pathname === '/photo-ui/i18n.js') {
      await route.fulfill({ status: 200, contentType: 'text/javascript; charset=utf-8', body: i18nSource });
      return;
    }
    if (url.pathname === '/api/class-archive/product-state') {
      await delay(productStateDelayMs);
      if (productStateStatus !== 200) {
        await json(route, productStateStatus, { error: 'synthetic-deny' });
        return;
      }
      await json(route, 200, {
        role: actor === 'family' ? 'FAMILY' : 'CLASSMATE',
        canManage: false,
        canSpotlight: actor === 'classmate',
        csrfToken: '',
        presentationEpoch: presentationEpochs[actor],
        cacheScope: scopes[actor],
      });
      return;
    }
    if (url.pathname === '/api/class-archive/timeline') {
      await delay(timelineDelayMs);
      if (timelineStatus !== 200) {
        await json(route, timelineStatus, { error: 'synthetic-projection-unavailable' });
        return;
      }
      await json(route, 200, timeline());
      return;
    }
    if (url.pathname === '/api/class-archive/spotlight') {
      await json(route, 200, { active: false, item: null });
      return;
    }
    if (/^\/api\/assets\/[0-9a-f-]{36}\/thumbnail$/i.test(url.pathname)) {
      await route.fulfill({ status: 200, contentType: 'image/png', body: pixel });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'text/plain; charset=utf-8', body: 'not found' });
  });

  const photoPage = await context.newPage();
  // Playwright creates independent headless top-level pages whose native
  // visibilityState remains "visible" even after another Page is focused.
  // Keep the real Chromium document/event/rendering path, but provide a
  // deterministic tab-visibility signal so the private first-frame barrier is
  // executable in CI and on the Windows desktop runner.
  await photoPage.addInitScript(() => {
    let controlledVisibility = 'visible';
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => controlledVisibility,
    });
    window.__setClassArchiveTestVisibility = (nextVisibility) => {
      controlledVisibility = nextVisibility;
      document.dispatchEvent(new Event('visibilitychange'));
    };
  });
  const setPhotoVisibility = (visibility) => photoPage.evaluate((nextVisibility) => {
    window.__setClassArchiveTestVisibility(nextVisibility);
  }, visibility);
  await photoPage.goto('http://127.0.0.1:8091/photos', { waitUntil: 'domcontentloaded' });
  await photoPage.locator('.photo-card').waitFor({ state: 'visible' });
  check(await photoPage.locator('.photo-card').count() === 1, 'classmate_living_fixture_missing');

  const controlPage = await context.newPage();
  await controlPage.goto('http://127.0.0.1:8091/control');
  await controlPage.bringToFront();
  await setPhotoVisibility('hidden');
  check(await photoPage.evaluate(() => document.documentElement.dataset.sessionRevalidating === 'true'
    && getComputedStyle(document.getElementById('app')).visibility === 'hidden'), 'background_tab_pixels_not_concealed');

  actor = 'family';
  productStateDelayMs = 450;
  await photoPage.bringToFront();
  await setPhotoVisibility('visible');
  check(await photoPage.evaluate(() => document.documentElement.dataset.sessionRevalidating === 'true'
    && getComputedStyle(document.getElementById('app')).visibility === 'hidden'), 'account_switch_first_frame_not_concealed');
  await photoPage.locator('.empty-state').waitFor({ state: 'visible', timeout: 10_000 });
  check(await photoPage.locator('.photo-card').count() === 0, 'family_received_old_living_card');

  actor = 'classmate';
  productStateDelayMs = 0;
  await photoPage.reload({ waitUntil: 'domcontentloaded' });
  await photoPage.locator('.photo-card').waitFor({ state: 'visible' });
  await controlPage.bringToFront();
  await setPhotoVisibility('hidden');
  productStateStatus = 401;
  productStateDelayMs = 450;
  await photoPage.bringToFront();
  await setPhotoVisibility('visible');
  check(await photoPage.evaluate(() => document.documentElement.dataset.sessionRevalidating === 'true'
    && getComputedStyle(document.getElementById('app')).visibility === 'hidden'), 'freeze_first_frame_not_concealed');
  // The 401 path can replace an in-flight navigation twice (the fetch helper
  // and the visible-session gate both fail closed). Observe the final URL
  // without treating the superseded request's ERR_ABORTED as a product error.
  await waitForPathname(photoPage, '/auth/login');
  check(new URL(photoPage.url()).pathname === '/auth/login', 'freeze_did_not_leave_private_document');

  productStateStatus = 200;
  productStateDelayMs = 0;
  timelineStatus = 200;
  actor = 'classmate';
  await photoPage.goto('http://127.0.0.1:8091/photos', { waitUntil: 'domcontentloaded' });
  await photoPage.locator('.photo-card').waitFor({ state: 'visible' });
  presentationEpochs.classmate = '3'.repeat(64);
  scopes.classmate = 'c'.repeat(32);
  timelineStatus = 503;
  timelineDelayMs = 450;
  await photoPage.reload({ waitUntil: 'domcontentloaded' });
  await delay(80);
  check(await photoPage.locator('.photo-card').count() === 0, 'changed_projection_epoch_painted_prior_cache');
  await photoPage.locator('.error-state').waitFor({ state: 'visible', timeout: 10_000 });
  check(await photoPage.locator('.photo-card').count() === 0, 'projection_failure_retained_stale_photo');
  check(await photoPage.locator('.error-state').count() === 1, 'projection_failure_safe_state_missing');

  await context.close();
  process.stdout.write(`PHOTO_CACHE_BROWSER_LIFECYCLE=PASS assertions=${assertions} browser=chromium lifecycle=controlled-visibility data=SYNTHETIC_ONLY\n`);
} catch (error) {
  const code = error instanceof GateError ? error.message : 'browser_lifecycle_unexpected';
  process.stderr.write(`PHOTO_CACHE_BROWSER_LIFECYCLE=FAIL code=${code}\n`);
  process.stderr.write(`${error?.stack ?? error}\n`);
  process.exitCode = 1;
} finally {
  if (browser) await browser.close();
}
