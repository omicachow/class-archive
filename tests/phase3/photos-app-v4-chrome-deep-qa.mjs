/*
 * Focused Chrome Stable acceptance companion for the V4 Photo App.
 *
 * This is deliberately synthetic-only and read-safe: it validates Viewer,
 * comments and the Era-first upload boundary with a fresh, persistent Chrome
 * Stable profile. It never provisions identities, starts services, reads a
 * normal Chrome profile, uploads a file, or creates a comment itself. A
 * separate synthetic-only PHP fixture creates two run-scoped Anonymous
 * comments before the browser starts and removes them in the PowerShell
 * wrapper's finally block. That makes the public pseudonym assertion
 * non-vacuous without letting browser code mutate product state.
 */

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS } from './photos-app-v4-chrome-localhost-guard.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

class GateError extends Error {
  constructor(code) { super(code); this.code = code; }
}

let assertions = 0;
let stage = 'initialization';
let chromeProduct = 'unknown';
let chromeVersion = 'unknown';
let screenshots = 0;

function fail(code) { throw new GateError(code); }
function check(value, code) {
  assertions += 1;
  if (!value) fail(code);
}
function stageAt(value) {
  stage = value;
  process.stdout.write(`V4_CHROME_DEEP_STAGE=${value}\n`);
}

const settings = {
  piwigo: process.env.CLASS_ARCHIVE_V4_DEEP_PIWIGO_ORIGIN,
  photos: process.env.CLASS_ARCHIVE_V4_DEEP_PHOTO_ORIGIN,
  credentials: process.env.CLASS_ARCHIVE_V4_DEEP_CREDENTIAL_FILE,
  viewerFixture: process.env.CLASS_ARCHIVE_V4_DEEP_VIEWER_FIXTURE_FILE,
  userDataRoot: process.env.CLASS_ARCHIVE_V4_DEEP_USER_DATA_ROOT,
  screenshots: process.env.CLASS_ARCHIVE_V4_DEEP_SCREENSHOT_DIR,
};

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{12}$/i;

function isUuid(value) {
  return typeof value === 'string' && UUID_V4.test(value);
}

function loopbackOrigin(value, port, code) {
  let url;
  try { url = new URL(value); } catch { fail(code); }
  check(url.protocol === 'http:' && url.hostname === '127.0.0.1' && url.port === String(port)
    && url.pathname === '/' && !url.username && !url.password && !url.search && !url.hash, code);
  return url.toString();
}

function privatePath(value, code) {
  check(typeof value === 'string' && path.isAbsolute(value) && !value.includes('\0'), code);
  return path.resolve(value);
}

function inside(root, target, code) {
  const base = privatePath(root, code);
  const resolved = path.resolve(target);
  const relative = path.relative(base, resolved);
  check(relative.length > 0 && !relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative), code);
  return resolved;
}

function child(root, name, code) {
  check(/^[a-z0-9-]{3,80}$/i.test(name), code);
  return inside(root, path.join(privatePath(root, code), name), code);
}

function readCredentials() {
  let document;
  try { document = JSON.parse(fs.readFileSync(privatePath(settings.credentials, 'credential_path'), 'utf8')); }
  catch { fail('credential_document'); }
  check(document?.version === 1 && document.environment === 'synthetic', 'credential_scope');
  check(isUuid(document?.familyDeniedPhotoId), 'credential_family_living_photo');
  check(Object.keys(document.roles ?? {}).sort().join(',') === 'anonymous,classmate,family,teacher', 'credential_roles');
  for (const role of ['classmate', 'family', 'teacher', 'anonymous']) {
    const entry = document.roles[role];
    check(typeof entry?.username === 'string' && entry.username.length > 0 && entry.username.length <= 190, `credential_${role}_username`);
    check(typeof entry?.password === 'string' && entry.password.length >= 24 && entry.password.length <= 190, `credential_${role}_password`);
  }
  return document;
}

function readViewerFixture() {
  let document;
  try { document = JSON.parse(fs.readFileSync(privatePath(settings.viewerFixture, 'viewer_fixture_path'), 'utf8')); }
  catch { fail('viewer_fixture_document'); }
  check(Object.keys(document ?? {}).sort().join(',') === 'commentIds,environment,photoIds,run,version', 'viewer_fixture_shape');
  check(document?.version === 1 && document.environment === 'synthetic'
    && typeof document.run === 'string' && /^[a-f0-9]{16}$/.test(document.run), 'viewer_fixture_scope');
  check(Array.isArray(document.photoIds) && document.photoIds.length === 2
    && document.photoIds.every(isUuid) && new Set(document.photoIds.map((id) => id.toLowerCase())).size === 2, 'viewer_fixture_photos');
  check(Array.isArray(document.commentIds) && document.commentIds.length === 2
    && document.commentIds.every(isUuid) && new Set(document.commentIds.map((id) => id.toLowerCase())).size === 2, 'viewer_fixture_comments');
  return {
    photoIds: document.photoIds.map((id) => id.toLowerCase()),
    commentIds: document.commentIds.map((id) => id.toLowerCase()),
  };
}

function allowed(url) {
  return ['about:', 'blob:', 'data:'].includes(url.protocol)
    || (url.protocol === 'http:' && url.hostname === '127.0.0.1' && ['8090', '8091'].includes(url.port));
}

async function recordChromeStableVersion(context, page) {
  let session = null;
  try {
    session = await context.newCDPSession(page);
    const info = await session.send('Browser.getVersion');
    const product = typeof info?.product === 'string' ? info.product : '';
    const match = /^Chrome\/(\d+(?:\.\d+){1,4})$/.exec(product);
    check(match !== null, 'chrome_stable_product');
    chromeProduct = 'chrome';
    chromeVersion = match[1];
  } catch (error) {
    if (error instanceof GateError) throw error;
    fail('chrome_stable_version');
  } finally {
    await session?.detach().catch(() => null);
  }
}

function safePhotoUrl(relative) {
  return new URL(relative, settings.photos).toString();
}

async function save(page, name) {
  const stem = child(settings.screenshots, name, 'screenshot_child');
  await page.screenshot({ path: inside(settings.screenshots, `${stem}.png`, 'screenshot_file'), fullPage: true });
  screenshots += 1;
}

async function open(role, viewport, credentials) {
  const profile = child(settings.userDataRoot, `${role}-${viewport.width}x${viewport.height}`, 'profile_child');
  check(!fs.existsSync(profile), 'profile_not_fresh');
  let context = null;
  try {
    // The branded Playwright channel deliberately selects installed Google
    // Chrome Stable rather than bundled Chromium or the user's own profile.
    context = await chromium.launchPersistentContext(profile, {
      channel: 'chrome',
      headless: false,
      viewport,
      screen: viewport,
      locale: 'zh-CN',
      serviceWorkers: 'block',
      acceptDownloads: false,
      args: [
        '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--disable-component-update', '--disable-sync', '--no-pings',
        ...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS,
      ],
    });
    await context.route('**/*', (route) => {
      try { return allowed(new URL(route.request().url())) ? route.continue() : route.abort(); }
      catch { return route.abort(); }
    });
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
    return { context, page };
  } catch (error) {
    await context?.close().catch(() => null);
    if (error instanceof GateError) throw error;
    fail(context === null ? 'chrome_stable_launch' : 'chrome_session');
  }
}

async function gotoPhotos(page, role) {
  await page.goto(safePhotoUrl('/photos'), { waitUntil: 'networkidle', timeout: 30_000 });
  await page.locator('[data-photo-app="true"]').waitFor({ timeout: 15_000 });
  const first = page.locator('.photo-card').first();
  await first.waitFor({ state: 'visible', timeout: 20_000 }).catch(() => null);
  check(await first.count() === 1, `${role}_photos_grid`);
}

async function productState(page, role) {
  const result = await page.evaluate(async () => {
    try {
      const response = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json().catch(() => null);
      return {
        status: response.status,
        role: payload?.role,
        canEraUpload: payload?.canEraUpload === true,
        canFamilySubmission: payload?.canFamilySubmission === true,
        csrf: typeof payload?.csrfToken === 'string' && payload.csrfToken.length >= 16,
      };
    } catch { return { status: 0 }; }
  });
  check(result.status === 200 && result.role === role.toUpperCase() && result.csrf, `${role}_product_state`);
  return result;
}

async function assertFamilyKnownLivingDenied(page, credentials) {
  const deniedPhotoId = credentials.familyDeniedPhotoId.toLowerCase();
  const result = await page.evaluate(async (id) => {
    const requests = [
      ['GET', `/api/assets/${id}`],
      ['GET', `/api/assets/${id}/thumbnail?size=thumbnail`],
      ['HEAD', `/api/assets/${id}/thumbnail?size=thumbnail`],
      ['GET', `/api/assets/${id}/thumbnail?size=preview`, { Range: 'bytes=0-1' }],
      ['GET', `/api/assets/${id}/original`],
      ['HEAD', `/api/assets/${id}/original`],
      ['GET', `/api/assets/${id}/original`, { Range: 'bytes=0-1' }],
    ];
    const statuses = [];
    for (const [method, path, headers = {}] of requests) {
      try {
        const response = await fetch(path, { method, headers, credentials: 'same-origin', cache: 'no-store' });
        statuses.push(response.status);
      } catch { statuses.push(0); }
    }
    try {
      const timelineResponse = await fetch('/api/class-archive/timeline?limit=120', { credentials: 'same-origin', cache: 'no-store' });
      const timeline = await timelineResponse.json().catch(() => null);
      const ids = Array.isArray(timeline?.groups)
        ? timeline.groups.flatMap((group) => Array.isArray(group?.items) ? group.items.map((item) => item?.id?.toLowerCase()) : []) : [];
      return { statuses, timelineStatus: timelineResponse.status, leakedToTimeline: ids.includes(id) };
    } catch { return { statuses, timelineStatus: 0, leakedToTimeline: true }; }
  }, deniedPhotoId);
  check(result.timelineStatus === 200 && result.leakedToTimeline === false, 'family_known_living_timeline_denied');
  check(Array.isArray(result.statuses) && result.statuses.length === 7 && result.statuses.every((status) => status === 404), 'family_known_living_mediaguard_denied');

  await page.goto(safePhotoUrl(`/photos/${deniedPhotoId}`), { waitUntil: 'networkidle', timeout: 30_000 });
  await page.locator('[data-photo-app="true"]').waitFor({ timeout: 15_000 });
  const deniedViewer = await page.evaluate((id) => {
    const image = document.querySelector('.viewer-image');
    const html = document.querySelector('[data-photo-app="true"]')?.innerHTML ?? '';
    return {
      rendered: image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0,
      requestedPreview: html.includes(`/api/assets/${id}/thumbnail`),
    };
  }, deniedPhotoId);
  check(deniedViewer.rendered === false && deniedViewer.requestedPreview === false, 'family_known_living_viewer_denied');
  await gotoPhotos(page, 'family');
}

function forbiddenIdentityKeys(value) {
  const forbidden = new Set([
    'classmateid', 'classmate_id', 'identityid', 'identity_id', 'seatid', 'seat_id',
    'accountid', 'account_id', 'userid', 'user_id', 'underlyinguserid', 'underlying_user_id',
    'principalid', 'principal_id', 'pseudonymsubject', 'pseudonym_subject',
  ]);
  const found = [];
  const walk = (item) => {
    if (item === null || typeof item !== 'object') return;
    for (const [key, nested] of Object.entries(item)) {
      found.push(key.toLowerCase());
      walk(nested);
    }
  };
  walk(value);
  return found.some((key) => forbidden.has(key));
}

async function assertAnonymousCommentProjection(page, viewerFixture, credentials) {
  const [photoA, photoB] = viewerFixture.photoIds;
  const [commentA, commentB] = viewerFixture.commentIds;
  const result = await page.evaluate(async ({ photoA, photoB, commentA, commentB, usernames }) => {
    const read = async (photoId, commentId) => {
      const response = await fetch(`/api/class-archive/comments/${photoId}?limit=100`, { credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json().catch(() => null);
      const item = Array.isArray(payload?.items) ? payload.items.find((candidate) => candidate?.id === commentId) : null;
      const encoded = JSON.stringify(payload ?? null);
      return {
        status: response.status,
        payload,
        label: item?.author?.label,
        kind: item?.author?.kind,
        containsFixtureUsername: usernames.some((username) => encoded.includes(username)),
      };
    };
    try {
      const [first, second] = await Promise.all([read(photoA, commentA), read(photoB, commentB)]);
      return { first, second };
    } catch { return { first: { status: 0 }, second: { status: 0 } }; }
  }, {
    photoA, photoB, commentA, commentB,
    usernames: Object.values(credentials.roles).map((entry) => entry.username),
  });
  const validLabel = (label) => typeof label === 'string' && /^匿名\s+[^\s]{1,32}$/u.test(label);
  check(result.first?.status === 200 && result.second?.status === 200, 'anonymous_comment_fixture_api_available');
  check(result.first?.kind === 'ANONYMOUS' && result.second?.kind === 'ANONYMOUS'
    && validLabel(result.first?.label) && validLabel(result.second?.label), 'anonymous_comment_fixture_pseudonym_visible');
  check(result.first.label !== result.second.label, 'anonymous_comment_context_pseudonym_distinct');
  check(!forbiddenIdentityKeys(result.first?.payload) && !forbiddenIdentityKeys(result.second?.payload)
    && !result.first?.containsFixtureUsername && !result.second?.containsFixtureUsername, 'anonymous_comment_api_identity_redacted');

  const firstComment = page.locator(`.comment-item[data-comment-id="${commentA}"]`);
  check(await firstComment.count() === 1, 'anonymous_comment_fixture_dom_present');
  check((await firstComment.locator('.comment-author').textContent())?.trim() === result.first.label, 'anonymous_comment_fixture_dom_pseudonym');
  const htmlState = await page.evaluate((usernames) => {
    const root = document.querySelector('[data-photo-app="true"]');
    const markup = root?.innerHTML ?? '';
    const forbidden = /(?:classmate_id|identity_id|seat_id|account_id|user_id|principal_id|pseudonym_subject)/i;
    return { leakedKey: forbidden.test(markup), leakedUsername: usernames.some((username) => markup.includes(username)) };
  }, Object.values(credentials.roles).map((entry) => entry.username));
  check(!htmlState.leakedKey && !htmlState.leakedUsername, 'anonymous_comment_html_identity_redacted');

  await page.goto(safePhotoUrl(`/photos/${photoB}`), { waitUntil: 'networkidle', timeout: 30_000 });
  const secondComment = page.locator(`.comment-item[data-comment-id="${commentB}"]`);
  await secondComment.waitFor({ state: 'visible', timeout: 20_000 });
  check((await secondComment.locator('.comment-author').textContent())?.trim() === result.second.label, 'anonymous_comment_second_context_dom_pseudonym');
  await page.goto(safePhotoUrl(`/photos/${photoA}`), { waitUntil: 'networkidle', timeout: 30_000 });
  await page.locator('.viewer-image').waitFor({ state: 'visible', timeout: 20_000 });
}

async function viewerJourney(role, viewport, credentials, viewerFixture) {
  const mobile = viewport.width <= 760;
  const { context, page } = await open(role, viewport, credentials);
  try {
    stageAt(`${role}_${mobile ? 'mobile' : 'desktop'}_viewer`);
    await gotoPhotos(page, role);
    if (role === 'family') {
      await assertFamilyKnownLivingDenied(page, credentials);
    }
    const first = page.locator('.photo-card').first();
    await first.click();
    await page.waitForURL((value) => value.origin === new URL(settings.photos).origin && /^\/photos\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 20_000 }).catch(() => null);
    check(/^\/photos\/[0-9a-f-]{36}$/i.test(new URL(page.url()).pathname), `${role}_viewer_route`);
    const photoId = new URL(page.url()).pathname.split('/').pop()?.toLowerCase();
    check(/^[0-9a-f-]{36}$/i.test(photoId ?? ''), `${role}_viewer_photo_id`);
    const image = page.locator('.viewer-image');
    await image.waitFor({ state: 'visible', timeout: 20_000 });
    const ready = await image.evaluate((node) => node instanceof HTMLImageElement && node.complete && node.naturalWidth > 0 && node.naturalHeight > 0).catch(() => false);
    check(ready, `${role}_viewer_decoded`);
    const source = await image.getAttribute('src');
    check(new RegExp(`^/api/assets/${photoId}/thumbnail\\?size=preview&v=[a-f0-9]{32}$`, 'i').test(source ?? ''), `${role}_viewer_mediaguard_preview`);
    check(!/(?:immich|original|_data|galleries|upload)/i.test(source ?? ''), `${role}_viewer_direct_media_forbidden`);
    const strip = page.locator('nav.viewer-filmstrip');
    check(await strip.count() === 1 && await strip.locator('a.viewer-filmstrip-item').count() >= 1, `${role}_viewer_filmstrip`);
    check(await strip.locator('[aria-current="true"][data-current="true"]').count() === 1, `${role}_viewer_filmstrip_current`);

    // The application deliberately schedules adjacent MediaGuard previews only
    // after the current viewer preview has decoded.
    await page.waitForTimeout(900);
    const adjacentPreloaded = await page.evaluate((id) => performance.getEntriesByType('resource').some((entry) => {
      try {
        const url = new URL(entry.name);
        return url.pathname.startsWith('/api/assets/') && url.pathname !== `/api/assets/${id}/thumbnail`
          && url.searchParams.get('size') === 'preview';
      } catch { return false; }
    }), photoId);
    check(adjacentPreloaded, `${role}_viewer_adjacent_preload`);

    const infoToggle = page.locator('.viewer-toolbar button[aria-expanded]');
    check(await infoToggle.count() === 1, `${role}_viewer_comments_toggle`);
    const initialOpen = await infoToggle.getAttribute('aria-expanded');
    check(initialOpen === (mobile ? 'false' : 'true'), `${role}_viewer_comments_initial_state`);
    await infoToggle.click();
    check(await infoToggle.getAttribute('aria-expanded') === (mobile ? 'true' : 'false'), `${role}_viewer_comments_toggle_state`);
    check(await page.locator('.viewer-info').getAttribute('data-open') === (mobile ? 'true' : 'false'), `${role}_viewer_comments_panel_state`);
    if (mobile) {
      await page.waitForFunction(() => {
        const panel = document.querySelector('.viewer-info');
        const next = document.querySelector('.viewer-next');
        if (!(panel instanceof HTMLElement) || !(next instanceof HTMLButtonElement) || panel.dataset.open !== 'true') return false;
        const box = panel.getBoundingClientRect();
        const nextStyle = getComputedStyle(next);
        return Math.abs(box.bottom - window.innerHeight) <= 2
          && nextStyle.pointerEvents === 'none' && Number(nextStyle.opacity) <= 0.01;
      }, undefined, { timeout: 5_000 }).catch(() => null);
      const sheet = await page.locator('.viewer-info').evaluate((panel) => {
        const style = getComputedStyle(panel);
        const box = panel.getBoundingClientRect();
        return {
          absolute: style.position === 'absolute',
          bottomAnchored: Math.abs(box.bottom - window.innerHeight) <= 2,
          visibleHeight: box.height > 80,
        };
      });
      const nextHidden = await page.locator('.viewer-next').evaluate((button) => {
        const style = getComputedStyle(button);
        return style.pointerEvents === 'none' && Number(style.opacity) === 0;
      });
      check(sheet.absolute && sheet.bottomAnchored && sheet.visibleHeight && nextHidden, `${role}_viewer_mobile_comment_sheet`);
    }
    // Return the comment surface to its original state before opening the
    // collapsed photo-information disclosure.
    await infoToggle.click();
    check(await infoToggle.getAttribute('aria-expanded') === initialOpen, `${role}_viewer_comments_toggle_restore`);

    const details = page.locator('details.viewer-photo-info');
    check(await details.count() === 1, `${role}_viewer_info_disclosure`);
    check(await details.evaluate((node) => node.open === false), `${role}_viewer_info_collapsed`);
    await details.locator('summary').click();
    check(await details.evaluate((node) => node.open === true), `${role}_viewer_info_expand`);
    await details.locator('summary').click();
    check(await details.evaluate((node) => node.open === false), `${role}_viewer_info_recollapse`);

    const zoom = page.getByRole('button', { name: '放大', exact: true });
    check(await zoom.count() === 1, `${role}_viewer_zoom_control`);
    await zoom.click();
    check((await image.getAttribute('style') ?? '').includes('scale(1.25)'), `${role}_viewer_zoom`);

    const comments = page.locator('.viewer-comments');
    check(await comments.count() === 1, `${role}_viewer_comments_surface`);
    if (role === 'family') {
      check(await comments.locator('.comment-composer').count() === 0, 'family_comment_composer_hidden');
      check(await comments.locator('.comment-readonly').count() === 1, 'family_comment_readonly_visible');
      const denied = await page.evaluate(async (id) => {
        try {
          const stateResponse = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
          const state = await stateResponse.json().catch(() => null);
          const response = await fetch('/api/class-archive/comments/create', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrfToken: state?.csrfToken, photoUuid: id, parentId: null, body: 'v4-family-readonly-probe' }),
          });
          return { state: stateResponse.status, status: response.status };
        } catch { return { state: 0, status: 0 }; }
      }, photoId);
      check(denied.state === 200 && denied.status === 403, 'family_comment_server_denied');
    } else {
      check(await comments.locator('.comment-composer').count() >= 1, `${role}_comment_composer_visible`);
    }
    if (role === 'anonymous') await assertAnonymousCommentProjection(page, viewerFixture, credentials);

    await save(page, `${role}-${mobile ? 'mobile' : 'desktop'}-viewer`);
    const next = page.locator('.viewer-next');
    check(!(await next.isDisabled()), `${role}_viewer_keyboard_next_available`);
    const before = page.url();
    await page.keyboard.press('ArrowRight');
    await page.waitForURL((value) => value.toString() !== before && /^\/photos\/[0-9a-f-]{36}$/i.test(value.pathname), { timeout: 20_000 }).catch(() => null);
    check(page.url() !== before, `${role}_viewer_keyboard_next`);
    const afterNext = page.url();
    await page.keyboard.press('ArrowLeft');
    await page.waitForURL((value) => value.toString() === before, { timeout: 20_000 }).catch(() => null);
    check(page.url() === before && page.url() !== afterNext, `${role}_viewer_keyboard_previous`);
    // Desktop initially presents the comment panel. The first Escape is
    // intentionally consumed to collapse that panel; a second Escape closes
    // the viewer. Mobile starts with it collapsed, so this remains one Escape.
    await page.keyboard.press('Escape');
    if (new URL(page.url()).pathname !== '/photos') {
      check(await infoToggle.getAttribute('aria-expanded') === 'false', `${role}_viewer_escape_collapses_comments`);
      await page.keyboard.press('Escape');
    }
    await page.waitForURL((value) => value.origin === new URL(settings.photos).origin && value.pathname === '/photos', { timeout: 20_000 }).catch(() => null);
    check(new URL(page.url()).pathname === '/photos', `${role}_viewer_escape_close`);
  } finally {
    await context.close().catch(() => null);
  }
}

async function directMemberUploadDenied(page, role) {
  const result = await page.evaluate(async () => {
    try {
      const stateResponse = await fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' });
      const state = await stateResponse.json().catch(() => null);
      const body = new FormData();
      body.set('action', 'publish_member_photo');
      body.set('pwg_token', typeof state?.csrfToken === 'string' ? state.csrfToken : '');
      body.set('era', 'LIVING');
      body.set('album_id', '00000000-0000-4000-8000-000000000000');
      const response = await fetch('/api/class-archive/member-upload', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Class-Archive-CSRF': typeof state?.csrfToken === 'string' ? state.csrfToken : '' }, body,
      });
      return { state: stateResponse.status, status: response.status };
    } catch { return { state: 0, status: 0 }; }
  });
  check(result.state === 200 && result.status === 403, `${role}_direct_member_upload_denied`);
}

async function directMemberMissingEraDenied(page, role) {
  const result = await page.evaluate(async () => {
    try {
      const [stateResponse, optionsResponse, beforeResponse] = await Promise.all([
        fetch('/api/class-archive/product-state', { credentials: 'same-origin', cache: 'no-store' }),
        fetch('/api/class-archive/member-upload/options', { credentials: 'same-origin', cache: 'no-store' }),
        fetch('/api/class-archive/timeline?limit=1', { credentials: 'same-origin', cache: 'no-store' }),
      ]);
      const state = await stateResponse.json().catch(() => null);
      const options = await optionsResponse.json().catch(() => null);
      const before = await beforeResponse.json().catch(() => null);
      const candidates = [...(Array.isArray(options?.eras?.HERITAGE) ? options.eras.HERITAGE : []), ...(Array.isArray(options?.eras?.LIVING) ? options.eras.LIVING : [])];
      const albumId = candidates.find((item) => typeof item?.id === 'string')?.id;
      if (!albumId || typeof state?.csrfToken !== 'string') return { state: stateResponse.status, options: optionsResponse.status, before: beforeResponse.status, status: 0, after: 0, beforeTotal: -1, afterTotal: -2 };
      const body = new FormData();
      body.set('action', 'publish_member_photo');
      body.set('pwg_token', state.csrfToken);
      // Deliberately no `era` and no file: this is a non-publishing malformed
      // request used only to prove the server rejects missing Era input.
      body.set('album_id', albumId);
      const response = await fetch('/api/class-archive/member-upload', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Class-Archive-CSRF': state.csrfToken }, body,
      });
      const afterResponse = await fetch('/api/class-archive/timeline?limit=1', { credentials: 'same-origin', cache: 'no-store' });
      const after = await afterResponse.json().catch(() => null);
      return { state: stateResponse.status, options: optionsResponse.status, before: beforeResponse.status, status: response.status, after: afterResponse.status, beforeTotal: before?.total, afterTotal: after?.total };
    } catch { return { state: 0, options: 0, before: 0, status: 0, after: 0, beforeTotal: -1, afterTotal: -2 }; }
  });
  check(result.state === 200 && result.options === 200 && result.before === 200 && result.status === 400 && result.after === 200, `${role}_member_upload_missing_era_denied`);
  check(Number.isInteger(result.beforeTotal) && result.beforeTotal === result.afterTotal, `${role}_member_upload_missing_era_no_mutation`);
}

async function eraUploadJourney(role, viewport, credentials) {
  const { context, page } = await open(role, viewport, credentials);
  try {
    stageAt(`${role}_era_upload`);
    await gotoPhotos(page, role);
    const state = await productState(page, role);
    check(state.canEraUpload && !state.canFamilySubmission, `${role}_era_upload_capability`);
    const options = await page.evaluate(async () => {
      try {
        const response = await fetch('/api/class-archive/member-upload/options', { credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json().catch(() => null);
        return { status: response.status, keys: Object.keys(payload?.eras ?? {}).sort(), heritage: Array.isArray(payload?.eras?.HERITAGE) ? payload.eras.HERITAGE.length : 0, living: Array.isArray(payload?.eras?.LIVING) ? payload.eras.LIVING.length : 0 };
      } catch { return { status: 0, keys: [], heritage: 0, living: 0 }; }
    });
    check(options.status === 200 && options.keys.join(',') === 'HERITAGE,LIVING' && options.heritage >= 1 && options.living >= 1, `${role}_era_upload_options`);
    const trigger = page.getByRole('button', { name: '上传', exact: true });
    check(await trigger.count() === 1, `${role}_era_upload_trigger`);
    await trigger.click();
    const dialog = page.locator('dialog.era-upload-dialog[open]');
    await dialog.waitFor({ state: 'visible', timeout: 15_000 });
    check(await dialog.getAttribute('aria-modal') === 'true', `${role}_era_upload_dialog_semantics`);
    const radios = dialog.locator('input[name="era"]');
    const values = await radios.evaluateAll((items) => items.map((item) => item.value).sort().join(','));
    check(await radios.count() === 2 && values === 'HERITAGE,LIVING', `${role}_era_upload_two_choices`);
    const file = dialog.locator('input[type="file"][name="photo"]');
    check(await file.count() === 1 && await file.getAttribute('accept') === 'image/jpeg,image/png,image/webp', `${role}_era_upload_file_control`);
    const album = dialog.locator('select[name="album"]');
    check(await album.isDisabled(), `${role}_era_upload_album_disabled_before_era`);
    let uploadPosts = 0;
    const onRequest = (request) => { try { if (new URL(request.url()).pathname === '/api/class-archive/member-upload' && request.method() === 'POST') uploadPosts += 1; } catch { } };
    page.on('request', onRequest);
    try {
      await dialog.getByRole('button', { name: '确认上传', exact: true }).click();
      check(await dialog.locator('.era-upload-status').textContent() === '请先选择班级历史或毕业后动态。', `${role}_era_upload_client_required`);
      check(uploadPosts === 0, `${role}_era_upload_client_no_request_without_era`);
    } finally {
      page.off('request', onRequest);
    }
    await dialog.locator('input[name="era"][value="HERITAGE"]').check();
    check(!(await album.isDisabled()) && await album.locator('option').count() >= 2, `${role}_era_upload_heritage_album_choices`);
    await dialog.locator('input[name="era"][value="LIVING"]').check();
    check(!(await album.isDisabled()) && await album.locator('option').count() >= 2, `${role}_era_upload_living_album_choices`);
    await dialog.getByRole('button', { name: '取消', exact: true }).click();
    await dialog.waitFor({ state: 'detached', timeout: 10_000 }).catch(() => null);
    check(await dialog.count() === 0, `${role}_era_upload_close`);
    await directMemberMissingEraDenied(page, role);
    await save(page, `${role}-era-upload`);
  } finally {
    await context.close().catch(() => null);
  }
}

async function restrictedUploadJourney(role, viewport, credentials) {
  const { context, page } = await open(role, viewport, credentials);
  try {
    stageAt(`${role}_upload_boundary`);
    await gotoPhotos(page, role);
    const state = await productState(page, role);
    check(!state.canEraUpload, `${role}_direct_era_capability_hidden`);
    if (role === 'family') {
      check(state.canFamilySubmission, 'family_submission_capability');
      check(await page.getByRole('button', { name: '投稿历史照片', exact: true }).count() === 1, 'family_pending_upload_entry');
      check(await page.getByRole('button', { name: '上传', exact: true }).count() === 0, 'family_direct_upload_trigger_hidden');
      check(!await page.locator('body').innerText().then((text) => text.includes('上传到毕业后动态')), 'family_living_upload_copy_hidden');
    } else {
      check(!state.canFamilySubmission, 'anonymous_submission_capability_hidden');
      check(await page.locator('.page-tools .primary-tool').count() === 0, 'anonymous_upload_entry_hidden');
    }
    check(await page.locator('dialog.era-upload-dialog, input[name="era"]').count() === 0, `${role}_era_dialog_not_rendered`);
    const optionsStatus = await page.evaluate(async () => {
      try { return (await fetch('/api/class-archive/member-upload/options', { credentials: 'same-origin', cache: 'no-store' })).status; }
      catch { return 0; }
    });
    check(optionsStatus === 403, `${role}_era_options_server_denied`);
    await directMemberUploadDenied(page, role);
    await save(page, `${role}-${viewport.width <= 760 ? 'mobile' : 'desktop'}-upload-boundary`);
  } finally {
    await context.close().catch(() => null);
  }
}

async function main() {
  settings.piwigo = loopbackOrigin(settings.piwigo, 8090, 'piwigo_origin');
  settings.photos = loopbackOrigin(settings.photos, 8091, 'photos_origin');
  settings.userDataRoot = privatePath(settings.userDataRoot, 'profile_root');
  settings.screenshots = privatePath(settings.screenshots, 'screenshot_root');
  check(fs.existsSync(settings.userDataRoot) && fs.statSync(settings.userDataRoot).isDirectory(), 'profile_root_missing');
  check(fs.existsSync(settings.screenshots) && fs.statSync(settings.screenshots).isDirectory(), 'screenshot_root_missing');
  const credentials = readCredentials();
  const viewerFixture = readViewerFixture();
  stageAt('classmate_desktop_viewer');
  await viewerJourney('classmate', { width: 1440, height: 900 }, credentials, viewerFixture);
  await viewerJourney('family', { width: 390, height: 844 }, credentials, viewerFixture);
  await viewerJourney('anonymous', { width: 390, height: 844 }, credentials, viewerFixture);
  await eraUploadJourney('classmate', { width: 1440, height: 900 }, credentials);
  await eraUploadJourney('teacher', { width: 1920, height: 1080 }, credentials);
  await restrictedUploadJourney('family', { width: 390, height: 844 }, credentials);
  await restrictedUploadJourney('anonymous', { width: 390, height: 844 }, credentials);
  check(chromeProduct === 'chrome', 'chrome_product_final');
  check(/^\d+(?:\.\d+){1,4}$/.test(chromeVersion), 'chrome_version_final');
  process.stdout.write(`V4_CHROME_DEEP_QA=PASS assertions=${assertions} screenshots=${screenshots} channel=chrome chrome_product=${chromeProduct} chrome_version=${chromeVersion}\n`);
}

main().catch((error) => {
  const code = error instanceof GateError ? error.code : 'unexpected';
  process.stdout.write(`V4_CHROME_DEEP_QA=FAIL stage=${stage} code=${code}\n`);
  process.exitCode = 1;
});
