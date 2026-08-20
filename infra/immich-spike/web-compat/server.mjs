/**
 * Class Archive's deliberately small Immich Web compatibility boundary.
 *
 * It serves the verified, unmodified upstream Immich Web static build only
 * on the loopback-bound spike port. It never exposes the internal Immich
 * server, PostgreSQL, Valkey, Piwigo database, Piwigo paths, Piwigo image ids,
 * Immich asset ids, or an original-file mount. Browser requests are mapped to
 * Class Archive's canonical UUID gateway and each request revalidates the
 * browser's existing Piwigo session. A compatible Immich "user" is only a
 * presentation object; it is never an authorization source.
 */

import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { lstat, readFile } from 'node:fs/promises';
import { isIP } from 'node:net';
import { extname, resolve, sep } from 'node:path';

const port = parsePort(process.env.CLASS_ARCHIVE_WEB_COMPAT_PORT ?? '3000');
const publicPort = parsePort(process.env.CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT ?? '8091');
const webRoot = resolve(process.env.CLASS_ARCHIVE_WEB_ROOT ?? '/web');
const gatewayOrigin = process.env.CLASS_ARCHIVE_GATEWAY_ORIGIN ?? 'http://piwigo:8088';
const expectedGatewayOrigin = 'http://piwigo:8088';

if (gatewayOrigin !== expectedGatewayOrigin) {
  throw new Error('class_archive_web_compat_gateway_origin_invalid');
}

const publicOrigin = `http://127.0.0.1:${publicPort}`;
const allowedHost = `127.0.0.1:${publicPort}`;
const knownRoles = new Set(['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS', 'SYSTEM_ADMIN']);
const staticTypes = new Map([
  ['.css', 'text/css; charset=utf-8'],
  ['.gif', 'image/gif'],
  ['.html', 'text/html; charset=utf-8'],
  ['.ico', 'image/x-icon'],
  ['.jpg', 'image/jpeg'],
  ['.jpeg', 'image/jpeg'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.svg', 'image/svg+xml'],
  ['.ttf', 'font/ttf'],
  ['.webmanifest', 'application/manifest+json; charset=utf-8'],
  ['.webp', 'image/webp'],
  ['.woff', 'font/woff'],
  ['.woff2', 'font/woff2'],
]);

const compatiblePreferences = Object.freeze({
  albums: { defaultAssetOrder: 'desc' },
  folders: { enabled: false, sidebarWeb: false },
  memories: { enabled: true, duration: 5 },
  people: { enabled: true, sidebarWeb: true },
  sharedLinks: { enabled: false, sidebarWeb: false },
  ratings: { enabled: false },
  tags: { enabled: false, sidebarWeb: false },
  emailNotifications: { enabled: false, albumInvite: false, albumUpdate: false },
  download: { archiveSize: 0, includeEmbeddedVideos: false },
  purchase: { showSupportBadge: false, hideBuyButtonUntil: '2100-01-01T00:00:00.000Z' },
  cast: { gCastEnabled: false },
  recentlyAdded: { sidebarWeb: false },
});

const webCompatCss = `
/* Class Archive presentation skin for the unmodified upstream Web build. */
#dashboard-navbar a[href="/photos"] svg { display: none !important; }
#dashboard-navbar a[href="/photos"]::after {
  content: "班级相册";
  color: var(--immich-primary, #4257d6);
  font-size: 1.125rem;
  font-weight: 650;
  letter-spacing: .02em;
  white-space: nowrap;
}
/* This read-only spike has no Immich sharing, utilities, archive, trash,
   locked-folder, upload, account-management or purchase surface. */
a[href="/sharing"],
a[href="/favorites"],
a[href="/utilities"],
a[href="/archive"],
a[href="/locked"],
a[href="/trash"],
a[href="/map"],
a[href="/user-settings"],
a[href="/partners"],
a[href="/api-keys"],
a[href="/admin"],
a[href="/purchase"],
a[href="/billing"] { display: none !important; }
a[href="/photos"] img[alt="Immich logo"] { display: none !important; }
`;

// The official Web bundle deliberately remains byte-for-byte upstream. This
// compatibility-only bootstrap removes UI affordances whose write/account
// semantics do not exist in the Class Archive gateway. The server separately
// denies every corresponding API mutation; this is a usability and disclosure
// layer, not the authorization boundary.
const webCompatBootstrap = `<script>
(() => {
  const originalFetch = window.fetch.bind(window);
  // A Piwigo session can be revoked or expire while the single-page Web shell
  // is open. Upstream would render a technical 401 stack trace. Return the
  // member to the real Class Archive sign-in route instead; API authorization
  // itself remains server-side and unchanged.
  window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    if (response.status === 401 && location.pathname !== '/auth/login') {
      location.replace('/auth/login');
    }
    return response;
  };
  const blockedPaths = new Set([
    '/sharing', '/favorites', '/utilities', '/archive', '/locked', '/trash',
    '/map', '/user-settings', '/partners', '/api-keys', '/admin', '/purchase', '/billing',
  ]);
  const blockedLabels = new Set([
    '上传', 'Upload', '通知', 'Notifications', '收藏夹', 'Favorites', '共享', 'Shared',
    '实用工具', 'Utilities', '归档', 'Archive', '锁定文件夹', 'Locked Folder',
  ]);
  const normalized = (value) => (value || '').replace(/\\s+/g, ' ').trim();
  const suppress = (element) => {
    element.setAttribute('hidden', '');
    element.setAttribute('aria-hidden', 'true');
    element.style.setProperty('display', 'none', 'important');
  };
  const applyBranding = () => {
    // The verified upstream bundle updates document.title during navigation.
    // Keep the visible local product name without modifying upstream assets.
    if (document.title.includes('Immich')) document.title = document.title.replace(/Immich/g, '班级相册');
  };
  const ensureLegalNotice = () => {
    const navbar = document.querySelector('#dashboard-navbar');
    if (!navbar || document.getElementById('class-archive-legal-notice')) return;
    const link = document.createElement('a');
    link.id = 'class-archive-legal-notice';
    link.href = '/class-archive-about';
    link.textContent = '开源许可';
    link.style.cssText = 'margin-left:auto;margin-right:1rem;font-size:.75rem;color:var(--immich-primary,#4257d6);white-space:nowrap';
    navbar.append(link);
  };
  const applyReadOnlySurfaces = () => {
    document.querySelectorAll('a[href]').forEach((anchor) => {
      try {
        if (blockedPaths.has(new URL(anchor.href, location.origin).pathname)) suppress(anchor);
      } catch { suppress(anchor); }
    });
    document.querySelectorAll('button').forEach((button) => {
      const label = normalized(button.getAttribute('aria-label') || button.getAttribute('title') || button.textContent);
      if (blockedLabels.has(label) || label.includes('class-archive@local.invalid')) suppress(button);
    });
    document.querySelectorAll('meter[aria-label="存储空间"]').forEach((meter) => {
      suppress(meter.parentElement || meter);
    });
    document.querySelectorAll('p').forEach((paragraph) => {
      if (normalized(paragraph.textContent) === '服务器离线') suppress(paragraph.parentElement || paragraph);
    });
    applyBranding();
    ensureLegalNotice();
  };
  new MutationObserver(applyReadOnlySurfaces).observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyReadOnlySurfaces, { once: true });
  else applyReadOnlySurfaces();
})();
</script>`;

const legalNoticeHtml = `<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>开源许可 | 班级相册</title></head><body>
<main><h1>开源许可</h1><p>本地“班级相册”照片前端 Spike 使用了未经修改构建的 Immich Web v3.1.0。</p>
<p>上游：<a href="https://github.com/immich-app/immich" rel="noopener noreferrer">immich-app/immich</a>；许可证：GNU AGPL-3.0-only。</p>
<p>固定上游提交：<code>8aa95c67470a02a8ddedf03c2e52963af33065ff</code>。</p>
<p>本页面不表示 Immich 是班级档案馆的身份、权限或媒体授权来源。</p></main>
</body></html>`;

function parsePort(value) {
  if (!/^[1-9][0-9]{0,4}$/.test(value)) {
    throw new Error('class_archive_web_compat_port_invalid');
  }
  const parsed = Number(value);
  if (!Number.isSafeInteger(parsed) || parsed > 65_535) {
    throw new Error('class_archive_web_compat_port_invalid');
  }
  return parsed;
}

function setSecurityHeaders(response, options = {}) {
  const { html = false, media = false } = options;
  response.setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
  response.setHeader('Pragma', 'no-cache');
  response.setHeader('Expires', '0');
  response.setHeader('Referrer-Policy', 'no-referrer');
  response.setHeader('X-Content-Type-Options', 'nosniff');
  response.setHeader('X-Frame-Options', 'DENY');
  response.setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
  if (html) {
    // The verified upstream static build has an inline bootstrap script.
    response.setHeader(
      'Content-Security-Policy',
      "default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; " +
        "connect-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; " +
        "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'",
    );
  }
  if (media) {
    response.setHeader('Vary', 'Cookie', false);
  }
}

function noBody(method) {
  return method === 'HEAD';
}

function respond(response, method, status, contentType, body = '', options = {}) {
  setSecurityHeaders(response, options);
  response.statusCode = status;
  response.setHeader('Content-Type', contentType);
  if (noBody(method)) {
    response.end();
    return;
  }
  response.end(body);
}

function respondJson(response, method, status, payload) {
  let body = '{"error":"请求暂时无法安全确认"}';
  try {
    body = JSON.stringify(payload);
  } catch {
    status = 503;
  }
  respond(response, method, status, 'application/json; charset=utf-8', body);
}

function rejectHost(request, response) {
  const host = request.headers.host;
  if (host !== allowedHost) {
    respond(response, request.method, 400, 'text/plain; charset=utf-8', 'Invalid host.');
    return true;
  }
  return false;
}

function rejectForeignRequest(request, response) {
  // Browsers normally omit Origin for same-origin GET/HEAD, but send it for
  // the read-only search POST. Do not let a caller on another origin use a
  // loopback cookie as a cross-origin read oracle.
  const origin = request.headers.origin;
  if (typeof origin === 'string' && origin !== publicOrigin) {
    respond(response, request.method, 403, 'text/plain; charset=utf-8', 'Request source denied.');
    return true;
  }
  const fetchSite = request.headers['sec-fetch-site'];
  if (typeof fetchSite === 'string' && fetchSite !== 'same-origin' && fetchSite !== 'none') {
    respond(response, request.method, 403, 'text/plain; charset=utf-8', 'Request source denied.');
    return true;
  }
  return false;
}

function rejectUnsafePath(request, response) {
  // Validate the raw request target before URL normalisation can erase a
  // dot-segment or an encoded slash. SPA fallbacks are only for ordinary
  // application routes, never for an ambiguous filesystem-shaped target.
  const rawTarget = request.url ?? '/';
  const rawPath = rawTarget.split(/[?#]/, 1)[0];
  let decoded;
  try {
    decoded = decodeURIComponent(rawPath);
  } catch {
    respond(response, request.method, 400, 'text/plain; charset=utf-8', 'Invalid path.');
    return true;
  }
  if (!decoded.startsWith('/') || decoded.includes('\\') || decoded.includes('\0') || decoded.includes('//')
    || decoded.split('/').some((piece) => piece === '..')) {
    respond(response, request.method, 400, 'text/plain; charset=utf-8', 'Invalid path.');
    return true;
  }
  return false;
}

function trustedClientAddress(request, response) {
  // The public listener is Piwigo nginx on loopback, not this container. That
  // listener overwrites both headers from its own socket state. Requiring the
  // proxy marker here prevents any other internal peer from using this Web
  // process as a raw Piwigo-cookie relay.
  const marker = request.headers['x-class-archive-web-compat-proxy'];
  const forwarded = request.headers['x-forwarded-for'];
  if (
    marker !== '1'
    || typeof forwarded !== 'string'
    || forwarded.length === 0
    || forwarded.length > 45
    || forwarded !== forwarded.trim()
    || forwarded.includes(',')
    || isIP(forwarded) === 0
  ) {
    respond(response, request.method, 403, 'text/plain; charset=utf-8', 'Request source denied.');
    return null;
  }
  return forwarded;
}

function onlyReadMethod(request, response) {
  if (request.method === 'GET' || request.method === 'HEAD') {
    return false;
  }
  response.setHeader('Allow', 'GET, HEAD');
  respond(response, request.method, 405, 'text/plain; charset=utf-8', 'Read-only endpoint.');
  return true;
}

function safeStaticPath(pathname) {
  let decoded;
  try {
    decoded = decodeURIComponent(pathname);
  } catch {
    return null;
  }
  if (!decoded.startsWith('/') || decoded.includes('\\') || decoded.includes('\0')) {
    return null;
  }
  const pieces = decoded.split('/');
  if (pieces.some((piece) => piece === '..')) {
    return null;
  }
  const candidate = resolve(webRoot, `.${decoded}`);
  if (candidate !== webRoot && !candidate.startsWith(`${webRoot}${sep}`)) {
    return null;
  }
  return candidate;
}

async function readStatic(pathname) {
  const candidate = safeStaticPath(pathname);
  if (!candidate) {
    return null;
  }
  try {
    const entry = await lstat(candidate);
    if (!entry.isFile() || entry.isSymbolicLink()) {
      return null;
    }
    return {
      body: await readFile(candidate),
      type: staticTypes.get(extname(candidate).toLowerCase()) ?? 'application/octet-stream',
    };
  } catch {
    return null;
  }
}

function sessionCookie(request) {
  const cookie = request.headers.cookie;
  return typeof cookie === 'string' && cookie.length <= 8192 ? cookie : '';
}

function isSafeInternalDelivery(value) {
  if (typeof value !== 'string' || value.length < 2 || value.length > 4096
    || value.includes('?') || value.includes('#') || value.includes('\\') || value.includes('\0') || value.includes('//')) {
    return false;
  }
  let decoded;
  try {
    decoded = decodeURIComponent(value);
  } catch {
    return false;
  }
  if (decoded.includes('\\') || decoded.includes('\0') || decoded.includes('//')
    || decoded.split('/').some((part) => part === '.' || part === '..')) {
    return false;
  }
  return [
    '/_class_archive_internal/source/upload/',
    '/_class_archive_internal/source/galleries/',
    '/_class_archive_internal/derivative/',
    '/_class_archive_internal/generate/',
  ].some((prefix) => decoded.startsWith(prefix));
}

class GatewayResponseError extends Error {
  constructor(status) {
    super('class_archive_gateway_response_invalid');
    this.status = status;
  }
}

async function gatewayJson(request, path, clientAddress) {
  if (typeof clientAddress !== 'string' || isIP(clientAddress) === 0) {
    throw new GatewayResponseError(503);
  }
  const upstream = new URL(path, gatewayOrigin);
  const cookie = sessionCookie(request);
  const result = await fetch(upstream, {
    method: 'GET',
    headers: {
      Accept: 'application/json',
      ...(cookie ? { Cookie: cookie } : {}),
      'X-Forwarded-For': clientAddress,
      'X-Class-Archive-Web-Compat-Internal': '1',
    },
    redirect: 'manual',
    signal: AbortSignal.timeout(10_000),
  });
  if (result.status !== 200) {
    throw new GatewayResponseError(result.status);
  }
  const contentType = result.headers.get('content-type') ?? '';
  if (!contentType.toLowerCase().startsWith('application/json')) {
    throw new GatewayResponseError(503);
  }
  return result.json();
}

async function principal(request, clientAddress) {
  const payload = await gatewayJson(request, '/api/me', clientAddress);
  const role = payload?.role;
  if (typeof role !== 'string' || !knownRoles.has(role)) {
    throw new GatewayResponseError(503);
  }
  return role;
}

function technicalUserId(role) {
  const bytes = createHash('sha256').update(`class-archive-immich-web-compat-v1\0${role}`).digest();
  // UUID-shaped, non-secret presentation compatibility id. It is intentionally
  // unrelated to Piwigo and Immich account identifiers.
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = bytes.subarray(0, 16).toString('hex');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function roleLabel(role) {
  return new Map([
    ['CLASSMATE', '班级成员'],
    ['TEACHER', '班级教师'],
    ['FAMILY', '班级家属'],
    ['ANONYMOUS', '匿名成员'],
    ['SYSTEM_ADMIN', '班级管理'],
  ]).get(role) ?? '班级成员';
}

function compatibleUser(role) {
  const now = '2026-01-01T00:00:00.000Z';
  return {
    id: technicalUserId(role),
    email: 'class-archive@local.invalid',
    name: roleLabel(role),
    profileImagePath: '',
    avatarColor: 'blue',
    profileChangedAt: now,
    storageLabel: 'class-archive',
    shouldChangePassword: false,
    // Class Archive SYSTEM_ADMIN does not become an Immich administrator.
    isAdmin: false,
    createdAt: now,
    deletedAt: null,
    updatedAt: now,
    oauthId: '',
    quotaSizeInBytes: null,
    quotaUsageInBytes: 0,
    status: 'active',
    license: null,
  };
}

function exactQuery(url, allowed) {
  const seen = new Map();
  for (const [key, value] of url.searchParams.entries()) {
    if (!allowed.has(key) || seen.has(key) || value.length > 190 || value.includes('\0')) {
      throw new TypeError('class_archive_web_compat_query_invalid');
    }
    seen.set(key, value);
  }
  return seen;
}

function assertUuid(id) {
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id)) {
    throw new TypeError('class_archive_web_compat_photo_id_invalid');
  }
  return id.toLowerCase();
}

function photoDate(photo) {
  const date = typeof photo?.taken_at === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(photo.taken_at)
    ? photo.taken_at
    : '1970-01-01';
  return `${date}T12:00:00.000Z`;
}

function compatibleAssetOwner(role) {
  const owner = compatibleUser(role);
  return {
    id: owner.id,
    email: owner.email,
    name: owner.name,
    profileImagePath: '',
    profileChangedAt: owner.profileChangedAt,
    avatarColor: owner.avatarColor,
  };
}

function compatibleAsset(photo, role) {
  const id = assertUuid(photo?.id);
  const date = photoDate(photo);
  const owner = compatibleAssetOwner(role);
  const title = typeof photo?.title === 'string' && photo.title.length <= 190 ? photo.title : '班级照片';
  return {
    id,
    ownerId: owner.id,
    owner,
    libraryId: 'class-archive-presentation',
    type: 'IMAGE',
    // These compatibility values deliberately contain no Piwigo pathname.
    originalPath: `class-archive/${id}.jpg`,
    originalFileName: title,
    originalMimeType: 'image/jpeg',
    thumbhash: null,
    fileCreatedAt: date,
    fileModifiedAt: date,
    localDateTime: date,
    updatedAt: date,
    createdAt: date,
    isFavorite: false,
    isArchived: false,
    isTrashed: false,
    visibility: 'timeline',
    duration: '0:00:00.000000',
    exifInfo: {
      make: null,
      model: null,
      exifImageWidth: 1600,
      exifImageHeight: 1067,
      fileSizeInByte: 0,
      orientation: '1',
      dateTimeOriginal: date,
      modifyDate: date,
      timeZone: 'UTC',
      lensModel: null,
      fNumber: null,
      focalLength: null,
      iso: null,
      exposureTime: null,
      latitude: null,
      longitude: null,
      city: null,
      country: null,
      state: null,
      description: null,
    },
    livePhotoVideoId: null,
    tags: [],
    people: [],
    stack: null,
    isOffline: false,
    hasMetadata: true,
    duplicateId: null,
    resized: true,
    checksum: null,
    width: 1600,
    height: 1067,
    isEdited: false,
  };
}

function timeBucketResponse(photos, role) {
  const response = {
    id: [], ownerId: [], ratio: [], thumbhash: [], createdAt: [], fileCreatedAt: [], localOffsetHours: [],
    isFavorite: [], isTrashed: [], isImage: [], duration: [], projectionType: [], livePhotoVideoId: [],
    city: [], country: [], visibility: [], stack: [],
  };
  for (const photo of photos) {
    const asset = compatibleAsset(photo, role);
    response.id.push(asset.id);
    response.ownerId.push(asset.ownerId);
    response.ratio.push(asset.width / asset.height);
    response.thumbhash.push(asset.thumbhash);
    response.createdAt.push(asset.createdAt);
    response.fileCreatedAt.push(asset.fileCreatedAt);
    response.localOffsetHours.push(0);
    response.isFavorite.push(false);
    response.isTrashed.push(false);
    response.isImage.push(true);
    response.duration.push(asset.duration);
    response.projectionType.push(null);
    response.livePhotoVideoId.push(null);
    response.city.push(null);
    response.country.push(null);
    response.visibility.push('timeline');
    response.stack.push(null);
  }
  return response;
}

function parseMonth(value) {
  const match = /^(\d{4}-\d{2})-01(?:T00:00:00\.000Z)?$/.exec(value);
  if (!match) {
    throw new TypeError('class_archive_web_compat_time_bucket_invalid');
  }
  return match[1];
}

function opaqueId(namespace, label) {
  const digest = createHash('sha256').update(`class-archive-immich-web-compat-v1\0${namespace}\0${label}`).digest();
  digest[6] = (digest[6] & 0x0f) | 0x40;
  digest[8] = (digest[8] & 0x3f) | 0x80;
  const hex = digest.subarray(0, 16).toString('hex');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function compatibleAlbums(payload, role) {
  const items = Array.isArray(payload?.items) ? payload.items : [];
  return items
    .filter((entry) => typeof entry?.name === 'string' && Number.isInteger(entry?.total))
    .map((entry) => ({
      id: opaqueId('album', entry.name), albumName: entry.name, description: '', albumThumbnailAssetId: null,
      createdAt: '2026-01-01T00:00:00.000Z', updatedAt: '2026-01-01T00:00:00.000Z',
      albumUsers: [{ user: compatibleAssetOwner(role), role: 'owner' }], shared: false, hasSharedLink: false,
      startDate: null, endDate: null, lastModifiedAssetTimestamp: null, assetCount: entry.total, order: 'desc',
      isActivityEnabled: false, contributorCounts: [],
    }));
}

function compatibleSearch(photos, role) {
  return {
    albums: { total: 0, count: 0, items: [], facets: [] },
    assets: { total: photos.length, count: photos.length, items: photos.map((photo) => compatibleAsset(photo, role)), facets: [], nextPage: null },
  };
}

async function parseJsonBody(request) {
  let raw = '';
  for await (const chunk of request) {
    raw += chunk;
    if (raw.length > 8192) {
      throw new TypeError('class_archive_web_compat_body_too_large');
    }
  }
  if (raw === '') {
    return {};
  }
  const parsed = JSON.parse(raw);
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new TypeError('class_archive_web_compat_body_invalid');
  }
  return parsed;
}

function searchTermFromBody(payload) {
  for (const key of ['originalFileName', 'description', 'ocr', 'originalPath', 'query']) {
    const value = payload[key];
    if (typeof value === 'string' && value.trim() !== '') {
      const normalized = value.trim();
      if (normalized.length > 190 || normalized.includes('\0')) {
        throw new TypeError('class_archive_web_compat_search_invalid');
      }
      return normalized;
    }
  }
  return '';
}

function clearCompatibilityCookie(response) {
  response.setHeader('Set-Cookie', 'immich_is_authenticated=; Path=/; Max-Age=0; SameSite=Lax');
}

function setCompatibilityCookie(response) {
  response.setHeader('Set-Cookie', 'immich_is_authenticated=1; Path=/; Max-Age=300; SameSite=Lax');
}

function redirectToPiwigoLogin(request, response) {
  setSecurityHeaders(response);
  clearCompatibilityCookie(response);
  response.statusCode = 303;
  response.setHeader('Location', 'http://127.0.0.1:8090/identification.php');
  response.end();
}

async function proxyCanonicalMedia(request, response, photoId, variant, clientAddress) {
  const headers = {
    ...(sessionCookie(request) ? { Cookie: sessionCookie(request) } : {}),
    ...(typeof request.headers.range === 'string' && request.headers.range.length <= 128 ? { Range: request.headers.range } : {}),
    ...(typeof request.headers['if-none-match'] === 'string' ? { 'If-None-Match': request.headers['if-none-match'] } : {}),
    ...(typeof request.headers['if-modified-since'] === 'string' ? { 'If-Modified-Since': request.headers['if-modified-since'] } : {}),
    'X-Forwarded-For': clientAddress,
    'X-Class-Archive-Web-Compat-Internal': '1',
  };
  const upstream = new URL(`/api/photos/${photoId}/media/${variant}`, gatewayOrigin);
  let upstreamResponse;
  try {
    upstreamResponse = await fetch(upstream, { method: request.method, headers, redirect: 'manual', signal: AbortSignal.timeout(20_000) });
  } catch {
    respond(response, request.method, 503, 'text/plain; charset=utf-8', 'Media temporarily unavailable.', { media: true });
    return;
  }
  const allowedStatus = new Set([200, 206, 304, 403, 404, 503]);
  if (!allowedStatus.has(upstreamResponse.status)) {
    respond(response, request.method, 503, 'text/plain; charset=utf-8', 'Media temporarily unavailable.', { media: true });
    return;
  }
  // The internal Gateway has already established the ClassIdentity +
  // MediaGuard decision. It deliberately leaves its X-Accel target unhandled
  // on port 8088 so this BFF can validate that target and hand it to the
  // loopback ingress nginx. The outer nginx then transfers the file itself:
  // neither PHP nor Node buffers an original or derivative byte stream.
  const accelRedirect = upstreamResponse.headers.get('x-accel-redirect');
  if (accelRedirect !== null) {
    if (upstreamResponse.status !== 200 || !isSafeInternalDelivery(accelRedirect)) {
      respond(response, request.method, 503, 'text/plain; charset=utf-8', 'Media temporarily unavailable.', { media: true });
      return;
    }
    setSecurityHeaders(response, { media: true });
    response.statusCode = 200;
    const contentType = upstreamResponse.headers.get('content-type');
    if (contentType !== null && contentType.toLowerCase().startsWith('image/')) {
      response.setHeader('Content-Type', contentType);
    }
    response.setHeader('X-Accel-Redirect', accelRedirect);
    response.end();
    return;
  }
  if (upstreamResponse.status === 200 || upstreamResponse.status === 206) {
    // A successful canonical media authorization must have an internal nginx
    // target. Do not silently fall back to user-space media relay.
    respond(response, request.method, 503, 'text/plain; charset=utf-8', 'Media temporarily unavailable.', { media: true });
    return;
  }
  setSecurityHeaders(response, { media: true });
  response.statusCode = upstreamResponse.status;
  for (const header of ['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified']) {
    const value = upstreamResponse.headers.get(header);
    if (value !== null) {
      response.setHeader(header, value);
    }
  }
  if (noBody(request.method) || upstreamResponse.status === 304) {
    response.end();
    return;
  }
  response.end(Buffer.from(await upstreamResponse.arrayBuffer()));
}

async function handleApi(request, response, url, clientAddress) {
  const isSearch = url.pathname === '/api/search/metadata' || url.pathname === '/api/search/smart';
  if (request.method !== 'GET' && request.method !== 'HEAD' && !(isSearch && request.method === 'POST')) {
    response.setHeader('Allow', isSearch ? 'POST' : 'GET, HEAD');
    respond(response, request.method, 405, 'application/json; charset=utf-8', '{"error":"只读接口"}');
    return;
  }
  let role;
  try {
    role = await principal(request, clientAddress);
  } catch (error) {
    clearCompatibilityCookie(response);
    const status = error instanceof GatewayResponseError && error.status === 503 ? 503 : 401;
    respondJson(response, request.method, status, { error: status === 503 ? '数据暂时无法安全确认' : '需要班级相册登录' });
    return;
  }

  try {
    if (url.pathname === '/api/users/me') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, compatibleUser(role));
      return;
    }
    if (url.pathname === '/api/users/me/preferences') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, compatiblePreferences);
      return;
    }
    if (url.pathname === '/api/server/about') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, {
        version: 'Class Archive Immich Web compatibility spike', versionUrl: '', licensed: false,
        build: 'class-archive-gateway', buildUrl: '', buildImage: 'verified-upstream-web-v3.1.0', buildImageUrl: '',
        repository: 'immich-app/immich', repositoryUrl: 'https://github.com/immich-app/immich', sourceRef: 'v3.1.0',
        sourceCommit: '8aa95c67470a02a8ddedf03c2e52963af33065ff', sourceUrl: 'https://github.com/immich-app/immich/tree/v3.1.0',
        nodejs: process.version, exiftool: '', ffmpeg: '', libvips: '', imagemagick: '',
      });
      return;
    }
    if (url.pathname === '/api/server/version-history') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, []);
      return;
    }
    if (url.pathname === '/api/server/features') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, {
        smartSearch: false, facialRecognition: false, duplicateDetection: false, map: false, reverseGeocoding: false,
        importFaces: false, sidecar: false, search: true, trash: false, oauth: false, oauthAutoLaunch: false,
        ocr: false, passwordLogin: false, configFile: false, email: false,
      });
      return;
    }
    if (url.pathname === '/api/server/config') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, {
        loginPageMessage: '', trashDays: 0, userDeleteDelay: 0, oauthButtonText: '', isInitialized: true,
        isOnboarded: true, externalDomain: '', publicUsers: false, mapDarkStyleUrl: '', mapLightStyleUrl: '', maintenanceMode: false,
      });
      return;
    }
    if (url.pathname === '/api/server/media-types') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, { image: ['.jpg', '.jpeg', '.png', '.webp'], video: [], sidecar: [] });
      return;
    }
    if (url.pathname === '/api/server/storage') {
      exactQuery(url, new Set());
      respondJson(response, request.method, 200, {
        diskSize: '不适用', diskUse: '不适用', diskAvailable: '不适用', diskSizeRaw: 0, diskUseRaw: 0,
        diskAvailableRaw: 0, diskUsagePercentage: 0,
      });
      return;
    }
    if (url.pathname === '/api/notifications') {
      exactQuery(url, new Set(['id', 'level', 'type', 'unread']));
      respondJson(response, request.method, 200, []);
      return;
    }
    if (url.pathname === '/api/timeline/buckets') {
      const query = exactQuery(url, new Set(['albumId', 'isTrashed', 'isFavorite', 'visibility', 'withStacked', 'withPartners', 'order', 'orderBy']));
      if (query.get('isTrashed') === 'true' || query.get('visibility') === 'archive' || query.has('albumId')) {
        respondJson(response, request.method, 200, []);
        return;
      }
      const timeline = await gatewayJson(request, '/api/timeline', clientAddress);
      const groups = Array.isArray(timeline?.groups) ? timeline.groups : [];
      const result = groups
        .filter((group) => typeof group?.key === 'string' && /^\d{4}-\d{2}$/.test(group.key) && Number.isInteger(group?.total))
        .map((group) => ({ timeBucket: `${group.key}-01T00:00:00.000Z`, count: group.total }));
      respondJson(response, request.method, 200, result);
      return;
    }
    if (url.pathname === '/api/timeline/bucket') {
      const query = exactQuery(url, new Set(['timeBucket', 'albumId', 'isTrashed', 'isFavorite', 'visibility', 'withStacked', 'withPartners', 'order', 'orderBy']));
      const timeBucket = query.get('timeBucket');
      if (!timeBucket) {
        throw new TypeError('class_archive_web_compat_time_bucket_missing');
      }
      if (query.get('isTrashed') === 'true' || query.get('visibility') === 'archive' || query.has('albumId')) {
        respondJson(response, request.method, 200, timeBucketResponse([], role));
        return;
      }
      const requestedMonth = parseMonth(timeBucket);
      const timeline = await gatewayJson(request, '/api/timeline', clientAddress);
      const group = Array.isArray(timeline?.groups) ? timeline.groups.find((entry) => entry?.key === requestedMonth) : null;
      const photos = Array.isArray(group?.items) ? group.items : [];
      respondJson(response, request.method, 200, timeBucketResponse(photos, role));
      return;
    }
    if (url.pathname === '/api/albums') {
      exactQuery(url, new Set(['isShared', 'isOwned', 'assetId']));
      const albums = await gatewayJson(request, '/api/albums', clientAddress);
      respondJson(response, request.method, 200, compatibleAlbums(albums, role));
      return;
    }
    if (url.pathname === '/api/memories') {
      exactQuery(url, new Set(['for', 'isSaved', 'isTrashed', 'order', 'size', 'type']));
      // Metadata bridge capabilities are not projected until the public
      // Gateway exports canonical photo membership. Never synthesize an
      // Immich memory or let its count imply a hidden asset.
      respondJson(response, request.method, 200, []);
      return;
    }
    if (url.pathname === '/api/people') {
      exactQuery(url, new Set(['closestAssetId', 'closestPersonId', 'page', 'size', 'withHidden']));
      // Same rule as memories: an empty, honest result is safer than a count
      // from an adapter that does not yet carry filtered canonical membership.
      respondJson(response, request.method, 200, { hasNextPage: false, hidden: 0, people: [], total: 0 });
      return;
    }
    if (isSearch) {
      const body = await parseJsonBody(request);
      const source = url.pathname.endsWith('/smart') ? body.smartSearchDto : body.metadataSearchDto;
      if (!source || typeof source !== 'object' || Array.isArray(source)) {
        throw new TypeError('class_archive_web_compat_search_body_invalid');
      }
      const query = searchTermFromBody(source);
      const results = await gatewayJson(request, `/api/search?q=${encodeURIComponent(query)}`, clientAddress);
      const photos = Array.isArray(results?.items) ? results.items : [];
      respondJson(response, request.method, 200, compatibleSearch(photos, role));
      return;
    }

    const assetMatch = /^\/api\/assets\/([0-9a-f-]{36})$/.exec(url.pathname);
    if (assetMatch) {
      exactQuery(url, new Set());
      const id = assertUuid(assetMatch[1]);
      const photo = await gatewayJson(request, `/api/photos/${id}`, clientAddress);
      respondJson(response, request.method, 200, compatibleAsset(photo, role));
      return;
    }
    const thumbnailMatch = /^\/api\/assets\/([0-9a-f-]{36})\/thumbnail$/.exec(url.pathname);
    if (thumbnailMatch) {
      const query = exactQuery(url, new Set(['size', 'c', 'edited']));
      const size = query.get('size');
      if (size !== 'thumbnail' && size !== 'preview') {
        throw new TypeError('class_archive_web_compat_thumbnail_size_invalid');
      }
      await proxyCanonicalMedia(request, response, assertUuid(thumbnailMatch[1]), size, clientAddress);
      return;
    }
    const originalMatch = /^\/api\/assets\/([0-9a-f-]{36})\/original$/.exec(url.pathname);
    if (originalMatch) {
      // The upstream Web SDK carries its cache/editor hint on both preview
      // and original URLs. It is not a delivery credential, so accept only
      // the bounded form expected from the verified v3.1.0 bundle. The
      // canonical gateway and MediaGuard still authorize every request.
      const query = exactQuery(url, new Set(['c', 'edited']));
      if (query.has('edited') && query.get('edited') !== 'true' && query.get('edited') !== 'false') {
        throw new TypeError('class_archive_web_compat_original_edited_invalid');
      }
      if (query.has('c') && !/^[A-Za-z0-9_-]{1,128}$/.test(query.get('c'))) {
        throw new TypeError('class_archive_web_compat_original_cache_key_invalid');
      }
      await proxyCanonicalMedia(request, response, assertUuid(originalMatch[1]), 'original', clientAddress);
      return;
    }
  } catch (error) {
    if (error instanceof GatewayResponseError) {
      const status = error.status === 404 ? 404 : 503;
      respondJson(response, request.method, status, { error: status === 404 ? '资源不存在' : '数据暂时无法安全确认' });
      return;
    }
    if (error instanceof TypeError || error instanceof SyntaxError) {
      respondJson(response, request.method, 400, { error: '请求格式无效' });
      return;
    }
    respondJson(response, request.method, 503, { error: '数据暂时无法安全确认' });
    return;
  }
  respondJson(response, request.method, 404, { error: '资源不存在' });
}

async function serveApplication(request, response, url) {
  if (url.pathname === '/healthz') {
    respond(response, request.method, 200, 'text/plain; charset=utf-8', 'ok');
    return;
  }
  if (rejectHost(request, response) || rejectForeignRequest(request, response) || rejectUnsafePath(request, response)) {
    return;
  }
  const clientAddress = trustedClientAddress(request, response);
  if (clientAddress === null) {
    return;
  }
  // The compatible upstream SDK uses POST for two bounded, read-only search
  // payloads. Route API requests before the static read-only guard so that
  // the API-level allowlist remains the sole authority for that exception.
  if (url.pathname.startsWith('/api/')) {
    await handleApi(request, response, url, clientAddress);
    return;
  }
  if (onlyReadMethod(request, response)) {
    return;
  }
  if (url.pathname === '/service-worker.js') {
    respond(response, request.method, 404, 'text/plain; charset=utf-8', 'Not found.');
    return;
  }
  if (url.pathname === '/custom.css') {
    respond(response, request.method, 200, 'text/css; charset=utf-8', webCompatCss);
    return;
  }
  if (url.pathname === '/class-archive-about') {
    respond(response, request.method, 200, 'text/html; charset=utf-8', legalNoticeHtml, { html: true });
    return;
  }
  if (url.pathname === '/auth/login' || url.pathname === '/auth/register' || url.pathname === '/auth/change-password') {
    redirectToPiwigoLogin(request, response);
    return;
  }

  const direct = await readStatic(url.pathname);
  // The application document is authorization-sensitive. Static JS/CSS/font
  // assets can be served without a session, but an explicit /index.html must
  // not be a shortcut around the Piwigo login redirect.
  if (direct && !direct.type.startsWith('text/html')) {
    setSecurityHeaders(response, { html: direct.type.startsWith('text/html') });
    response.statusCode = 200;
    response.setHeader('Content-Type', direct.type);
    if (noBody(request.method)) {
      response.end();
      return;
    }
    response.end(direct.body);
    return;
  }

  try {
    await principal(request, clientAddress);
  } catch {
    redirectToPiwigoLogin(request, response);
    return;
  }
  const index = await readStatic('/index.html');
  if (!index) {
    respond(response, request.method, 503, 'text/plain; charset=utf-8', 'Compatibility web build unavailable.');
    return;
  }
  setCompatibilityCookie(response);
  setSecurityHeaders(response, { html: true });
  response.statusCode = 200;
  response.setHeader('Content-Type', 'text/html; charset=utf-8');
  if (noBody(request.method)) {
    response.end();
    return;
  }
  // Branding is injected into the response only; the verified upstream tree
  // remains unmodified and rebuildable from its pinned source archive.
  const html = index.body.toString('utf8').replace(
    '</head>',
    '<title>班级相册</title><meta name="application-name" content="班级相册"><link rel="stylesheet" href="/custom.css">' + webCompatBootstrap + '</head>',
  );
  response.end(html);
}

const server = createServer((request, response) => {
  const method = request.method ?? 'GET';
  let url;
  try {
    url = new URL(request.url ?? '/', publicOrigin);
  } catch {
    respond(response, method, 400, 'text/plain; charset=utf-8', 'Invalid request.');
    return;
  }
  void serveApplication(request, response, url).catch(() => {
    if (!response.headersSent) {
      respond(response, method, 503, 'text/plain; charset=utf-8', 'Service temporarily unavailable.');
    } else {
      response.destroy();
    }
  });
});

server.listen(port, '0.0.0.0', () => {
  process.stdout.write(`class-archive-immich-web-compat listening on ${port}\n`);
});
