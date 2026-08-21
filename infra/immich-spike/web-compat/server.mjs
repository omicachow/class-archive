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
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
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
    if (!navbar) return;
    if (!document.getElementById('class-archive-timeline-link')) {
      const timeline = document.createElement('a');
      timeline.id = 'class-archive-timeline-link';
      timeline.href = '/class-archive-timeline';
      timeline.textContent = '档案时间轴';
      timeline.style.cssText = 'margin-left:auto;margin-right:.75rem;font-size:.875rem;color:var(--immich-primary,#4257d6);white-space:nowrap';
      navbar.append(timeline);
    }
    if (document.getElementById('class-archive-legal-notice')) return;
    const link = document.createElement('a');
    link.id = 'class-archive-legal-notice';
    link.href = '/class-archive-about';
    link.textContent = '开源许可';
    link.style.cssText = 'margin-right:1rem;font-size:.75rem;color:var(--immich-primary,#4257d6);white-space:nowrap';
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

// This is deliberately a small, separate projection rather than an attempt to
// force archive semantics into Immich's fileCreatedAt model. It is populated
// exclusively from /api/timeline, whose groups come from Class Archive's
// evidence-aware archive date fields. The DOM renderer uses textContent for
// all persisted labels; an archive event name never becomes HTML.
const archiveTimelineHtml = `<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>档案时间轴 | 班级相册</title><style>
body{margin:0;background:#f7f8fb;color:#1f2937;font:15px/1.5 system-ui,-apple-system,"Microsoft YaHei",sans-serif}
main{max-width:1180px;margin:0 auto;padding:28px 20px 56px}header{display:flex;align-items:baseline;gap:16px;flex-wrap:wrap}h1{margin:0;font-size:26px}.back{color:#3656c5;text-decoration:none}.note{margin:8px 0 28px;color:#596579}.group{margin:30px 0}.group h2{font-size:18px;margin:0 0 12px}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px}.card{border:0;background:transparent;text-align:left;padding:0;color:inherit;cursor:pointer}.thumb{display:block;width:100%;aspect-ratio:1.35;object-fit:cover;border-radius:10px;background:#e5e7eb}.title{display:block;margin-top:7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.meta{display:block;margin-top:2px;color:#667085;font-size:12px}.empty,.error{border:1px solid #d8deea;border-radius:10px;padding:18px;background:#fff}.error{color:#a12b2b}@media(max-width:460px){main{padding:20px 14px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}
</style></head><body><main><header><h1>档案时间轴</h1><a class="back" href="/photos">返回照片</a></header><p class="note">按已确认的档案日期、活动和日期精度整理；不会把上传时间当作拍摄时间。</p><section id="timeline" aria-live="polite"><p class="empty">正在载入档案时间轴…</p></section></main><script>
(() => {
  const root = document.getElementById('timeline');
  const text = (tag, value, className) => { const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; return node; };
  const mediaUrl = (id) => '/api/assets/' + encodeURIComponent(id) + '/thumbnail?size=thumbnail';
  const render = (payload) => {
    root.replaceChildren();
    if (!payload || !Array.isArray(payload.groups) || payload.groups.length === 0) { root.append(text('p', '暂无可查看的档案照片。', 'empty')); return; }
    for (const group of payload.groups) {
      const section = document.createElement('section'); section.className = 'group';
      section.append(text('h2', group.label + '（' + group.total + ' 张）'));
      const grid = document.createElement('div'); grid.className = 'grid';
      for (const photo of group.items) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'card';
        button.addEventListener('click', () => { location.assign('/class-archive-photo/' + encodeURIComponent(photo.id)); });
        const image = document.createElement('img'); image.className = 'thumb'; image.loading = 'lazy'; image.src = mediaUrl(photo.id); image.alt = photo.title || '班级照片';
        button.append(image, text('span', photo.title || '班级照片', 'title'), text('span', photo.archive_date.label, 'meta'));
        grid.append(button);
      }
      section.append(grid); root.append(section);
    }
  };
  fetch('/api/class-archive/timeline', { credentials: 'same-origin' }).then(async (response) => {
    if (!response.ok) throw new Error('timeline_unavailable'); return response.json();
  }).then(render).catch(() => { root.replaceChildren(text('p', '档案时间轴暂时无法安全确认，请稍后重试。', 'error')); });
})();
</script></body></html>`;

function archivePhotoHtml(photoId) {
  // `photoId` has already passed assertUuid() before interpolation. This
  // deliberately small viewer does not ask the upstream Immich SPA to infer a
  // capture date from technical fields; it reads the same archive projection
  // as the timeline and asks the canonical BFF media endpoint for a preview.
  return `<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>查看照片 | 班级相册</title><style>
body{margin:0;background:#111827;color:#f8fafc;font:15px/1.5 system-ui,-apple-system,"Microsoft YaHei",sans-serif}main{max-width:1180px;margin:0 auto;padding:22px 18px 40px}.back{color:#c7d2fe;text-decoration:none}.layout{margin-top:18px;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,320px);gap:22px}.image-wrap{min-height:300px;display:grid;place-items:center;background:#0b1220;border-radius:12px;overflow:hidden}.image-wrap img{display:block;max-width:100%;max-height:78vh;object-fit:contain}.meta{background:#1f2937;border-radius:12px;padding:18px}.meta h1{font-size:20px;margin:0 0 12px}.meta p{margin:7px 0;color:#d1d5db}.label{color:#9ca3af}.error{background:#7f1d1d;padding:14px;border-radius:10px}@media(max-width:700px){main{padding:16px 12px}.layout{grid-template-columns:1fr;gap:12px}.image-wrap{min-height:220px}.image-wrap img{max-height:62vh}}
</style></head><body><main><a class="back" href="/class-archive-timeline">返回档案时间轴</a><div class="layout"><section class="image-wrap" id="image-wrap" aria-live="polite"><p>正在载入照片…</p></section><aside class="meta"><h1 id="title">班级照片</h1><p><span class="label">档案时间：</span><span id="date">正在载入…</span></p><p><span class="label">日期精度：</span><span id="precision">正在载入…</span></p><p><span class="label">日期来源：</span><span id="source">正在载入…</span></p></aside></div></main><script>
(() => {
  const photoId = '${photoId}';
  const error = (message) => { const root=document.getElementById('image-wrap'); root.replaceChildren(); const p=document.createElement('p');p.className='error';p.textContent=message;root.append(p); };
  const set = (id, value) => { document.getElementById(id).textContent=value; };
  const archiveItem = async () => {
    const response = await fetch('/api/class-archive/timeline', {credentials:'same-origin',cache:'no-store'});
    if (!response.ok) throw new Error('timeline_unavailable');
    const timeline = await response.json();
    for (const group of timeline.groups || []) for (const item of group.items || []) if (item.id === photoId) return item;
    throw new Error('photo_not_visible');
  };
  Promise.all([
    fetch('/api/assets/' + encodeURIComponent(photoId), {credentials:'same-origin',cache:'no-store'}), archiveItem(),
  ]).then(async ([assetResponse, archive]) => {
    if (!assetResponse.ok) throw new Error('photo_unavailable');
    const asset = await assetResponse.json();
    set('title', asset.originalFileName || archive.title || '班级照片');
    set('date', archive.archive_date.label);
    set('precision', archive.archive_date.precision);
    set('source', archive.archive_date.source);
    const image = document.createElement('img'); image.alt=asset.originalFileName || archive.title || '班级照片';
    image.addEventListener('error', () => error('照片预览暂时无法安全确认，请稍后重试。'), {once:true});
    image.src='/api/assets/' + encodeURIComponent(photoId) + '/thumbnail?size=preview';
    const root=document.getElementById('image-wrap');root.replaceChildren(image);
  }).catch(() => error('照片暂时无法安全确认，请返回后重试。'));
})();
</script></body></html>`;
}

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
  // The upstream compatibility shape has several mandatory-looking date
  // fields. Returning upload/import time (or a placeholder epoch) would
  // incorrectly turn technical file metadata into a claimed capture date.
  // Only a confirmed day-level archive value is representable in that shape.
  if (
    typeof photo?.taken_at !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(photo.taken_at)
    || !['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'].includes(photo?.date_source)
    || !['EXACT', 'DAY'].includes(photo?.date_precision)
  ) {
    return null;
  }
  return `${photo.taken_at}T12:00:00.000Z`;
}

function archiveDateProjection(photo) {
  const precision = typeof photo?.date_precision === 'string' ? photo.date_precision : 'UNKNOWN';
  const source = typeof photo?.date_source === 'string' ? photo.date_source : 'UNKNOWN';
  const takenAt = typeof photo?.taken_at === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(photo.taken_at) ? photo.taken_at : null;
  const event = typeof photo?.event_label === 'string' && photo.event_label.length > 0 && photo.event_label.length <= 190 ? photo.event_label : null;
  const precisionLabels = Object.freeze({
    EXACT: '日期精确', DAY: '日期精确', MONTH: '仅确定到月份', TERM: '仅确定学期',
    YEAR: '仅确定年份', EVENT_ONLY: '仅确定活动', UNKNOWN: '日期未知',
  });
  const sourceLabels = Object.freeze({
    ARCHIVE_CONFIRMED: '档案确认日期', EVENT_INFERENCE: '档案事件推定', EXIF_TRUSTED: '已核验 EXIF 日期', UNKNOWN: '日期来源未确认',
  });
  let label = '日期未知';
  if (takenAt && ['EXACT', 'DAY'].includes(precision)) {
    label = `${takenAt.slice(0, 4)}年${takenAt.slice(5, 7)}月${takenAt.slice(8, 10)}日`;
  } else if (takenAt && precision === 'MONTH') {
    label = `${takenAt.slice(0, 4)}年${takenAt.slice(5, 7)}月`;
  } else if (takenAt && precision === 'YEAR') {
    label = `${takenAt.slice(0, 4)}年`;
  } else if (event) {
    label = event;
  }
  return {
    label,
    precision: precisionLabels[precision] ?? precisionLabels.UNKNOWN,
    source: sourceLabels[source] ?? sourceLabels.UNKNOWN,
  };
}

function archiveTimelineProjection(payload) {
  const groups = Array.isArray(payload?.groups) ? payload.groups : null;
  if (!groups || !Number.isInteger(payload?.total) || payload.total < 0) {
    throw new GatewayResponseError(503);
  }
  const keys = new Set();
  const photoIds = new Set();
  let total = 0;
  const output = groups.map((group) => {
    if (!group || typeof group !== 'object' || typeof group.key !== 'string' || typeof group.label !== 'string'
      || group.label.length < 1 || group.label.length > 190 || !Number.isInteger(group.total) || group.total < 1
      || !['MONTH', 'YEAR', 'EVENT', 'UNKNOWN'].includes(group.kind) || !Array.isArray(group.items) || keys.has(group.key)) {
      throw new GatewayResponseError(503);
    }
    const keyMatchesKind = (group.kind === 'MONTH' && /^month:\d{4}-\d{2}$/.test(group.key))
      || (group.kind === 'YEAR' && /^year:\d{4}$/.test(group.key))
      || (group.kind === 'EVENT' && /^event:[0-9a-f]{64}$/.test(group.key))
      || (group.kind === 'UNKNOWN' && group.key === 'unknown');
    if (!keyMatchesKind || (group.kind === 'UNKNOWN' && groups.indexOf(group) !== groups.length - 1)) {
      throw new GatewayResponseError(503);
    }
    keys.add(group.key);
    const items = group.items.map((photo) => {
      const id = assertUuid(photo?.id);
      if (photoIds.has(id)) {
        throw new GatewayResponseError(503);
      }
      photoIds.add(id);
      const title = typeof photo?.title === 'string' && photo.title.length <= 190 ? photo.title : '班级照片';
      return { id, title, archive_date: archiveDateProjection(photo) };
    });
    if (items.length !== group.total) {
      throw new GatewayResponseError(503);
    }
    total += items.length;
    return { key: group.key, label: group.label, kind: group.kind, total: group.total, items };
  });
  if (total !== payload.total) {
    throw new GatewayResponseError(503);
  }
  return { total, groups: output };
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
    // Unknown/month/year/event-only archive dates stay null in the generic
    // Immich-shaped object. They are rendered with their real precision by
    // /class-archive-timeline instead of being silently rounded to a day.
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

function gatewayPeople(payload) {
  if (payload?.available === false && payload?.total === 0 && Array.isArray(payload?.items) && payload.items.length === 0) {
    return [];
  }
  if (payload?.available !== true || !Array.isArray(payload?.items) || !Number.isInteger(payload?.total) || payload.total !== payload.items.length) {
    throw new GatewayResponseError(503);
  }
  const ids = new Set();
  return payload.items.map((entry) => {
    if (
      !entry || typeof entry !== 'object' || typeof entry.id !== 'string' || !UUID_V4.test(entry.id)
      || typeof entry.label !== 'string' || entry.label.length < 1 || entry.label.length > 190
      || !Number.isInteger(entry.photo_count) || entry.photo_count < 1
      || typeof entry.cover_photo_id !== 'string' || !UUID_V4.test(entry.cover_photo_id)
      || ids.has(entry.id)
    ) {
      throw new GatewayResponseError(503);
    }
    ids.add(entry.id);
    return entry;
  });
}

function compatiblePerson(person, role) {
  if (!person || typeof person !== 'object' || typeof person.id !== 'string' || !UUID_V4.test(person.id)
    || typeof person.label !== 'string' || person.label.length < 1 || person.label.length > 190
    || typeof person.cover_photo_id !== 'string' || !UUID_V4.test(person.cover_photo_id)) {
    throw new GatewayResponseError(503);
  }
  // Thumbnail bytes remain a canonical asset request, which re-enters the
  // Gateway and MediaGuard. There is no Immich thumbnail URL in this object.
  return {
    id: person.id,
    name: person.label,
    birthDate: null,
    thumbnailPath: `/api/people/${person.id}/thumbnail`,
    isHidden: false,
    isFavorite: false,
    updatedAt: '2026-01-01T00:00:00.000Z',
  };
}

function compatiblePeople(payload, role) {
  const people = gatewayPeople(payload);
  return {
    hasNextPage: false,
    hidden: 0,
    total: people.length,
    people: people.map((person) => compatiblePerson(person, role)),
  };
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
      let enrichmentAvailable = false;
      try {
        const people = await gatewayJson(request, '/api/people', clientAddress);
        enrichmentAvailable = people?.available === true;
      } catch {
        // The metadata-only bridge is optional for the base compatibility
        // shell. Do not advertise an unavailable ML feature as usable.
        enrichmentAvailable = false;
      }
      respondJson(response, request.method, 200, {
        smartSearch: enrichmentAvailable, facialRecognition: enrichmentAvailable, duplicateDetection: false, map: false, reverseGeocoding: false,
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
        .filter((group) => typeof group?.key === 'string' && /^month:\d{4}-\d{2}$/.test(group.key) && Array.isArray(group?.items))
        .map((group) => {
          // The generic Immich timeline is only safe for confirmed day-level
          // dates. Month/year/event/unknown assets remain available through
          // the Class Archive timeline projection rather than being rounded
          // to an invented calendar day.
          const items = group.items.filter((photo) => photoDate(photo) !== null);
          return { timeBucket: `${group.key.slice('month:'.length)}-01T00:00:00.000Z`, count: items.length };
        })
        .filter((group) => group.count > 0);
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
      const group = Array.isArray(timeline?.groups) ? timeline.groups.find((entry) => entry?.key === `month:${requestedMonth}`) : null;
      const photos = Array.isArray(group?.items) ? group.items.filter((photo) => photoDate(photo) !== null) : [];
      respondJson(response, request.method, 200, timeBucketResponse(photos, role));
      return;
    }
    if (url.pathname === '/api/class-archive/timeline') {
      exactQuery(url, new Set());
      const timeline = await gatewayJson(request, '/api/timeline', clientAddress);
      respondJson(response, request.method, 200, archiveTimelineProjection(timeline));
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
      const people = await gatewayJson(request, '/api/people', clientAddress);
      respondJson(response, request.method, 200, compatiblePeople(people, role));
      return;
    }
    if (isSearch) {
      const body = await parseJsonBody(request);
      const source = url.pathname.endsWith('/smart') ? body.smartSearchDto : body.metadataSearchDto;
      if (!source || typeof source !== 'object' || Array.isArray(source)) {
        throw new TypeError('class_archive_web_compat_search_body_invalid');
      }
      const personIds = source.personIds;
      if (url.pathname === '/api/search/metadata' && Array.isArray(personIds)) {
        if (personIds.length !== 1 || typeof personIds[0] !== 'string' || !UUID_V4.test(personIds[0])) {
          throw new TypeError('class_archive_web_compat_person_search_invalid');
        }
        const person = await gatewayJson(request, `/api/people/${personIds[0].toLowerCase()}`, clientAddress);
        const items = Array.isArray(person?.items) ? person.items : null;
        if (!items) {
          throw new GatewayResponseError(503);
        }
        respondJson(response, request.method, 200, compatibleSearch(items, role));
        return;
      }
      const query = searchTermFromBody(source);
      const path = url.pathname.endsWith('/smart') ? '/api/search/smart' : '/api/search';
      const results = await gatewayJson(request, `${path}?q=${encodeURIComponent(query)}`, clientAddress);
      const photos = Array.isArray(results?.items) ? results.items : [];
      respondJson(response, request.method, 200, compatibleSearch(photos, role));
      return;
    }

    const personStatisticsMatch = /^\/api\/people\/([0-9a-f-]{36})\/statistics$/.exec(url.pathname);
    if (personStatisticsMatch) {
      exactQuery(url, new Set());
      const person = await gatewayJson(request, `/api/people/${assertUuid(personStatisticsMatch[1])}`, clientAddress);
      if (!Number.isInteger(person?.photo_count) || person.photo_count < 1) {
        throw new GatewayResponseError(503);
      }
      respondJson(response, request.method, 200, { assets: person.photo_count });
      return;
    }
    const personThumbnailMatch = /^\/api\/people\/([0-9a-f-]{36})\/thumbnail$/.exec(url.pathname);
    if (personThumbnailMatch) {
      exactQuery(url, new Set());
      const person = await gatewayJson(request, `/api/people/${assertUuid(personThumbnailMatch[1])}`, clientAddress);
      if (typeof person?.cover_photo_id !== 'string' || !UUID_V4.test(person.cover_photo_id)) {
        throw new GatewayResponseError(503);
      }
      await proxyCanonicalMedia(request, response, person.cover_photo_id.toLowerCase(), 'thumbnail', clientAddress);
      return;
    }
    const personMatch = /^\/api\/people\/([0-9a-f-]{36})$/.exec(url.pathname);
    if (personMatch) {
      exactQuery(url, new Set());
      const person = await gatewayJson(request, `/api/people/${assertUuid(personMatch[1])}`, clientAddress);
      respondJson(response, request.method, 200, compatiblePerson(person, role));
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
  if (url.pathname === '/class-archive-timeline') {
    // Unlike the static legal notice, this page contains a live, role-filtered
    // archive projection. It must not become a shell shortcut around the
    // Piwigo session check.
    try {
      await principal(request, clientAddress);
    } catch {
      redirectToPiwigoLogin(request, response);
      return;
    }
    respond(response, request.method, 200, 'text/html; charset=utf-8', archiveTimelineHtml, { html: true });
    return;
  }
  const archivePhotoMatch = /^\/class-archive-photo\/([0-9a-f-]{36})$/i.exec(url.pathname);
  if (archivePhotoMatch) {
    const photoId = assertUuid(archivePhotoMatch[1]);
    try {
      await principal(request, clientAddress);
    } catch {
      redirectToPiwigoLogin(request, response);
      return;
    }
    try {
      // Do not return a distinct viewer shell for a known-but-hidden UUID.
      // This policy check is metadata-only; the actual preview still makes its
      // own canonical MediaGuard request in the browser.
      const photo = await gatewayJson(request, `/api/photos/${photoId}`, clientAddress);
      assertUuid(photo?.id);
    } catch (error) {
      const status = error instanceof GatewayResponseError && error.status === 404 ? 404 : 503;
      respond(response, request.method, status, 'text/plain; charset=utf-8', status === 404 ? '资源不存在' : '数据暂时无法安全确认', { html: true });
      return;
    }
    respond(response, request.method, 200, 'text/html; charset=utf-8', archivePhotoHtml(photoId), { html: true });
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
