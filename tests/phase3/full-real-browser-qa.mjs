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
  let observeHomeRequests = false;
  let homeTimelineRequests = 0;
  const context = await browser.newContext({ viewport, deviceScaleFactor: mobile ? 1 : 1.25 });
  try {
    await context.addCookies([{ name: 'pwg_id', value: credential.cookie, domain: '127.0.0.1', path: '/', httpOnly: true, secure: false, sameSite: 'Lax' }]);
    const page = await context.newPage();
    page.on('request', (request) => {
      try {
        const url = new URL(request.url());
        if (url.protocol === 'http:' && url.hostname !== '127.0.0.1') unexpectedNetwork.add(url.hostname);
        if (observeHomeRequests && url.origin === photoOrigin.origin && url.pathname === '/api/class-archive/timeline') {
          homeTimelineRequests += 1;
        }
      } catch { unexpectedNetwork.add('invalid'); }
    });

    observeHomeRequests = true;
    await go(page, '/', `${mobile ? 'mobile' : 'desktop'}_home`);
    await waitForCount(page, '.home-featured', 1, 'home_featured_missing');
    observeHomeRequests = false;
    assert(new URL(page.url()).pathname === '/home', 'home_root_redirect_invalid');
    assert(await page.getByRole('heading', { name: '首页', exact: true }).count() >= 1, 'home_heading_missing');
    for (const [selector, code] of [
      ['.home-featured', 'home_featured_missing'],
      ['.home-memory-row', 'home_memories_missing'],
      ['.home-album-row', 'home_albums_missing'],
      ['.home-people-row', 'home_people_missing'],
      ['[data-home-all-photos]', 'home_all_photos_missing'],
    ]) {
      assert(await page.locator(selector).count() === 1, code);
    }
    for (const heading of ['精选', '回忆', '班级相册', '人物']) {
      assert(await page.getByRole('heading', { name: heading, exact: true }).count() === 1, `home_${heading.length}_heading_missing`);
    }
    const allPhotos = page.getByRole('link', { name: /^查看全部 \d+ 张照片$/ });
    assert(await allPhotos.count() === 1 && await allPhotos.getAttribute('href') === '/photos', 'home_all_photos_link_invalid');
    const performanceTimelineRequest = await page.evaluate(() => performance.getEntriesByType('resource')
      .some((entry) => new URL(entry.name).pathname === '/api/class-archive/timeline'));
    assert(homeTimelineRequests === 0 && performanceTimelineRequest === false, 'home_requested_full_timeline');
    if (mobile) await assertMobileLayout(page, 'home'); else await assertDesktopLayout(page, 'home');
    await assertQuietBusinessSurface(page, 'home');
    await capture(page, mobile ? '08-home-mobile.png' : '00-home-desktop.png');

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
    const albumContract = await page.evaluate(async () => {
      const response = await fetch('/api/class-archive/albums', { credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json().catch(() => null);
      return { status: response.status, payload };
    });
    assert(albumContract.status === 200 && albumContract.payload && Array.isArray(albumContract.payload.items), 'album_contract_unavailable');
    assert(albumContract.payload.items.length === albums && albumContract.payload.items.length >= 2, 'leaf_album_card_count_mismatch');
    assert(albumContract.payload.items.every((album) => Number.isInteger(album?.directTotal) && album.directTotal > 0), 'pure_container_album_visible');
    const cardIds = await page.locator('.album-card').evaluateAll((cards) => cards.map((card) => {
      const href = card.getAttribute('href') ?? '';
      const match = /^\/albums\/([0-9a-f-]{36})$/i.exec(href);
      return match ? match[1].toLowerCase() : null;
    }));
    const contractIds = albumContract.payload.items.map((album) => typeof album?.id === 'string' ? album.id.toLowerCase() : null);
    assert(cardIds.every(Boolean) && contractIds.every(Boolean)
      && new Set(cardIds).size === cardIds.length
      && cardIds.slice().sort().join(',') === contractIds.slice().sort().join(','), 'leaf_album_projection_mismatch');
    const sourceCounts = Object.create(null);
    for (const album of albumContract.payload.items) {
      sourceCounts[album.sourceKind] = (sourceCounts[album.sourceKind] ?? 0) + 1;
    }
    assert(Number.isInteger(sourceCounts.QQ) && sourceCounts.QQ > 0, 'qq_leaf_albums_missing');
    assert(Number.isInteger(sourceCounts.GRADUATION) && sourceCounts.GRADUATION > 0, 'graduation_leaf_albums_missing');
    const filters = page.locator('.album-filter-bar[aria-label="相册来源筛选"]');
    assert(await filters.count() === 1, 'album_source_filters_missing');
    await filters.getByRole('button', { name: 'QQ 相册', exact: true }).click();
    assert(await page.locator('.album-card').count() === sourceCounts.QQ, 'qq_album_filter_count_invalid');
    await filters.getByRole('button', { name: '毕业相册', exact: true }).click();
    assert(await page.locator('.album-card').count() === sourceCounts.GRADUATION, 'graduation_album_filter_count_invalid');
    await filters.getByRole('button', { name: '全部', exact: true }).click();
    assert(await page.locator('.album-card').count() === albums, 'album_filter_restore_invalid');
    const covers = await page.locator('.album-cover img[src^="/api/assets/"]').count();
    assert(covers >= 2, 'album_covers_missing');
    await waitForDecoded(page, '.album-cover img[src^="/api/assets/"]', Math.min(3, covers), 'album_cover_decode_failed');
    const albumSurfaceText = await page.locator('body').innerText();
    assert(!albumSurfaceText.includes('高速下载 - cnzx') && !albumSurfaceText.includes('高速下载- cnzx'), 'cnzx_source_name_publicly_visible');
    const detailCard = page.locator('.album-card').first();
    const detailTitle = (await detailCard.locator('.album-title').innerText()).trim();
    assert(detailTitle.length > 0, 'leaf_album_title_missing');
    if (mobile) await assertMobileLayout(page, 'albums'); else await assertDesktopLayout(page, 'albums');
    await assertQuietBusinessSurface(page, 'albums');
    if (!mobile) await capture(page, '03-albums-desktop.png');

    await Promise.all([
      page.waitForURL((value) => value.origin === photoOrigin.origin && /^\/albums\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 30_000 }).catch(() => null),
      detailCard.click(),
    ]);
    assert(/^\/albums\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), 'leaf_album_route_invalid');
    await page.locator('.album-photo-grid').waitFor({ state: 'visible', timeout: 45_000 }).catch(() => null);
    assert(await page.getByRole('heading', { name: detailTitle, exact: true }).count() === 1, 'leaf_album_detail_title_missing');
    assert(await page.locator('.album-photo-grid').count() === 1, 'leaf_album_photo_grid_missing');
    assert(await page.locator('.album-children').count() === 0, 'leaf_album_child_navigation_visible');
    assert(!(await page.locator('body').innerText()).includes('下级相册'), 'leaf_album_child_copy_visible');
    const withinAlbum = page.getByRole('link', { name: '在此相册中搜索', exact: true });
    assert(await withinAlbum.count() === 1 && /^\/search\?album=[0-9a-f-]{36}$/i.test(await withinAlbum.getAttribute('href') ?? ''), 'leaf_album_search_link_invalid');
    if (mobile) await assertMobileLayout(page, 'leaf_album'); else await assertDesktopLayout(page, 'leaf_album');
    await assertQuietBusinessSurface(page, 'leaf_album');
    await capture(page, mobile ? '09-leaf-album-mobile.png' : '07-leaf-album-desktop.png');

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
