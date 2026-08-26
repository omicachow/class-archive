/*
 * Real Chromium owner acceptance for the full private library. It uses an
 * already authenticated, short-lived SYSTEM_ADMIN session created by the
 * PowerShell wrapper. The script never writes IDs, page text, cookies, source
 * names, image URLs, or response bodies to stdout.
 */

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
const assert = (value, code) => {
  assertions += 1;
  if (!value) throw new GateError(code);
};
const setting = (name, minimum = 1, maximum = 2048) => {
  const value = process.env[name];
  assert(typeof value === 'string' && value.length >= minimum && value.length <= maximum && !value.includes('\0'), `setting_${name.toLowerCase()}_invalid`);
  return value;
};

const mode = setting('CLASS_ARCHIVE_FULL_QA_MODE', 5, 7);
assert(mode === 'staging' || mode === 'owner', 'mode_invalid');
const expected = mode === 'staging' ? { core: 8290, photo: 8291 } : { core: 8190, photo: 8191 };
function origin(name, port) {
  let url;
  try { url = new URL(setting(name, 12, 190)); } catch { throw new GateError(`setting_${name.toLowerCase()}_invalid`); }
  assert(url.protocol === 'http:' && url.hostname === '127.0.0.1' && Number(url.port) === port
    && !url.username && !url.password && !url.search && !url.hash && url.pathname === '/', `setting_${name.toLowerCase()}_invalid`);
  return url;
}

const coreOrigin = origin('CLASS_ARCHIVE_FULL_QA_CORE_ORIGIN', expected.core);
const photoOrigin = origin('CLASS_ARCHIVE_FULL_QA_PHOTO_ORIGIN', expected.photo);
const screenshotDir = path.resolve(setting('CLASS_ARCHIVE_FULL_QA_SCREENSHOT_DIR', 8));
const profileDir = path.resolve(setting('CLASS_ARCHIVE_FULL_QA_PROFILE_DIR', 8));
const chromePath = setting('CLASS_ARCHIVE_FULL_QA_CHROME', 8);
const credentialPath = path.resolve(setting('CLASS_ARCHIVE_FULL_QA_CREDENTIAL_FILE', 8));
assert(screenshotDir.replaceAll('\\', '/').toLowerCase().includes('/.codex-work/private-real-qa/screenshots/full-real/'), 'screenshot_boundary_invalid');

let credential;
try { credential = JSON.parse(await fs.readFile(credentialPath, 'utf8')); } catch { throw new GateError('credential_document_invalid'); }
assert(Object.keys(credential ?? {}).sort().join(',') === 'admin,cookie,environment,leaseHandle,version', 'credential_document_shape');
assert(credential.version === 1 && credential.environment === 'PRIVATE_REAL_FULL', 'credential_document_version');
assert(typeof credential.admin === 'string' && /^[^\u0000-\u001f\u007f]{1,190}$/.test(credential.admin), 'credential_admin_invalid');
assert(typeof credential.cookie === 'string' && /^[A-Za-z0-9,-]{16,128}$/.test(credential.cookie), 'credential_cookie_invalid');
assert(typeof credential.leaseHandle === 'string' && /^[a-f0-9]{24}$/.test(credential.leaseHandle), 'credential_lease_invalid');

const relative = (pathname) => new URL(pathname, photoOrigin).href;
async function go(page, pathname, code) {
  let response;
  try { response = await page.goto(relative(pathname), { waitUntil: 'domcontentloaded', timeout: 45_000 }); }
  catch { throw new GateError(`${code}_transport`); }
  assert(response !== null && response.status() === 200, `${code}_status`);
  const atTop = await page.waitForFunction(() => window.scrollY <= 1, null, { timeout: 15_000 }).then(() => true).catch(() => false);
  assert(atTop, `${code}_scroll_position`);
}

async function waitForDecoded(page, selector, minimum, code) {
  const ready = await page.waitForFunction(({ selector: selected, minimum: count }) => {
    const items = [...document.querySelectorAll(selected)];
    return items.length >= count && items.slice(0, count).every((image) => image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0 && image.naturalHeight > 0);
  }, { selector, minimum }, { timeout: 120_000 }).then(() => true).catch(() => false);
  assert(ready, code);
}

async function waitForCount(page, selector, minimum, code) {
  const ready = await page.waitForFunction(({ selector: selected, minimum: count }) => (
    document.querySelectorAll(selected).length >= count
  ), { selector, minimum }, { timeout: 60_000 }).then(() => true).catch(() => false);
  assert(ready, code);
}

async function capture(page, filename) {
  await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: false });
  screenshots += 1;
}

async function assertQuietBusinessSurface(page, code) {
  const text = await page.locator('body').innerText();
  assert(!/(?:HERITAGE|LIVING|ownerId|assetId|personId|CLIP|embedding|Gateway|MediaGuard|Piwigo|Immich)/i.test(text), `${code}_technical_copy_visible`);
  const markup = await page.locator('html').innerHTML();
  assert(!/(?:classmate_identity|identity_id|seat_id|account_id|piwigo_image|immich_asset|media_reference)/i.test(markup), `${code}_backend_identifier_visible`);
}

async function assertMobileLayout(page, code) {
  const layout = await page.evaluate(() => {
    const nav = document.querySelector('.mobile-nav');
    const links = nav ? [...nav.querySelectorAll('a')] : [];
    const minTarget = links.length ? Math.min(...links.map((item) => {
      const box = item.getBoundingClientRect();
      return Math.min(box.width, box.height);
    })) : 0;
    return { overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, visible: nav ? getComputedStyle(nav).display !== 'none' : false, links: links.length, minTarget };
  });
  assert(!layout.overflow, `${code}_horizontal_overflow`);
  assert(layout.visible && layout.links === 6 && layout.minTarget >= 44, `${code}_mobile_navigation`);
}

async function assertDesktopLayout(page, code) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  assert(!overflow, `${code}_horizontal_overflow`);
}

async function browse(viewport, mobile) {
  const unexpectedNetwork = new Set();
  const context = await browser.newContext({ viewport, deviceScaleFactor: mobile ? 1 : 1.25 });
  try {
    await context.addCookies([{ name: 'pwg_id', value: credential.cookie, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
    const page = await context.newPage();
    page.on('request', (request) => {
      try {
        const url = new URL(request.url());
        if (url.protocol === 'http:' && url.hostname !== '127.0.0.1') unexpectedNetwork.add(url.hostname);
      } catch { unexpectedNetwork.add('invalid'); }
    });

    await go(page, '/photos', `${mobile ? 'mobile' : 'desktop'}_photos`);
    assert(await page.getByRole('heading', { name: '照片', exact: true }).count() >= 1, 'photos_heading_missing');
    await waitForCount(page, '.photo-card', 9, 'photos_grid_too_small');
    const cards = await page.locator('.photo-card').count();
    assert(cards >= 9, 'photos_grid_count_invalid');
    const images = await page.locator('.photo-card img[src^="/api/assets/"]').count();
    assert(images >= 9, 'photos_media_missing');
    await waitForDecoded(page, '.photo-card img[src^="/api/assets/"]', 6, 'photos_decode_failed');
    assert(await page.locator('.photo-card img[data-load-state="error"]').count() === 0, 'photos_media_error');
    if (mobile) await assertMobileLayout(page, 'photos'); else await assertDesktopLayout(page, 'photos');
    await assertQuietBusinessSurface(page, 'photos');
    await capture(page, mobile ? '06-photos-mobile.png' : '01-photos-desktop.png');

    if (!mobile) {
      await page.locator('.photo-card').first().click();
      await page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/photos\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 30_000 }).catch(() => null);
      assert(/^\/photos\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), 'viewer_route_invalid');
      await waitForDecoded(page, '.viewer-image[src^="/api/assets/"]', 1, 'viewer_decode_failed');
      const source = await page.locator('.viewer-image').getAttribute('src');
      assert(/^\/api\/assets\/[0-9a-f-]{36}\/thumbnail\?size=preview&v=[a-f0-9]{32}$/i.test(source ?? ''), 'viewer_not_mediaguard_path');
      await assertQuietBusinessSurface(page, 'viewer');
      await capture(page, '04-viewer-desktop.png');
    }

    await go(page, '/albums', `${mobile ? 'mobile' : 'desktop'}_albums`);
    assert(await page.getByRole('heading', { name: '相册', exact: true }).count() >= 1, 'albums_heading_missing');
    await waitForCount(page, '.album-card', 2, 'album_hierarchy_missing');
    const albums = await page.locator('.album-card').count();
    assert(albums >= 2, 'album_hierarchy_count_invalid');
    const covers = await page.locator('.album-cover img[src^="/api/assets/"]').count();
    assert(covers >= 2, 'album_covers_missing');
    await waitForDecoded(page, '.album-cover img[src^="/api/assets/"]', Math.min(3, covers), 'album_cover_decode_failed');
    if (mobile) await assertMobileLayout(page, 'albums'); else await assertDesktopLayout(page, 'albums');
    await assertQuietBusinessSurface(page, 'albums');
    if (!mobile) await capture(page, '03-albums-desktop.png');

    await go(page, '/search', `${mobile ? 'mobile' : 'desktop'}_search`);
    assert(await page.getByRole('heading', { name: '搜索', exact: true }).count() >= 1, 'search_heading_missing');
    await waitForCount(page, '.search-discovery', 1, 'search_discovery_missing');
    assert(await page.getByRole('searchbox', { name: '搜索照片', exact: true }).count() === 1, 'search_input_missing');
    assert(await page.locator('.search-discovery').count() === 1, 'search_discovery_missing');
    assert(await page.locator('.search-suggestion').count() === 6, 'search_suggestions_missing');
    assert(!(await page.locator('body').innerText()).includes('找到一段记忆'), 'search_legacy_large_empty_card_present');
    assert(await page.locator('.search-results, .search-result-panel, .search-empty-card').count() === 0, 'search_results_visible_before_query');
    if (mobile) await assertMobileLayout(page, 'search'); else await assertDesktopLayout(page, 'search');
    await assertQuietBusinessSurface(page, 'search');
    await capture(page, mobile ? '05-search-empty-mobile.png' : '02-search-empty-desktop.png');

    assert(unexpectedNetwork.size === 0, 'unexpected_network_request');
  } finally {
    await context.close();
  }
}

let browser;
try {
  browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--no-first-run', '--no-default-browser-check'] });
  await browse({ width: 1440, height: 900 }, false);
  await browse({ width: 390, height: 844 }, true);
  process.stdout.write(`FULL_REAL_BROWSER_QA=PASS assertions=${assertions} screenshots=${screenshots} browser=chromium mode=${mode} media=mediaguard_only\n`);
} catch (error) {
  const code = error instanceof GateError && /^[a-z0-9_]{1,96}$/i.test(error.code) ? error.code : 'unexpected';
  process.stdout.write(`FULL_REAL_BROWSER_QA=FAIL assertions=${assertions} code=${code}\n`);
  process.exitCode = 1;
} finally {
  if (browser) await browser.close().catch(() => {});
}
