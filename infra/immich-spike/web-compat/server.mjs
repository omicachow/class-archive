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
    '显示和隐藏人物', 'Show and Hide People', '显示人物选项', 'Show person options',
    '隐藏人物', 'Hide person', '合并人物', 'Merge people', '添加到收藏夹', 'Add to favorites',
    '从收藏夹移除', 'Remove from favorites',
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
      const label = normalized(anchor.getAttribute('aria-label') || anchor.getAttribute('title') || anchor.textContent);
      if (blockedLabels.has(label)) suppress(anchor);
    });
    document.querySelectorAll('button').forEach((button) => {
      const label = normalized(button.getAttribute('aria-label') || button.getAttribute('title') || button.textContent);
      if (blockedLabels.has(label) || label.includes('class-archive@local.invalid')) suppress(button);
    });
    document.querySelectorAll('meter[aria-label="存储空间"]').forEach((meter) => {
      suppress(meter.parentElement || meter);
    });
    document.querySelectorAll('p,span,div').forEach((element) => {
      const label = normalized(element.textContent);
      if (label === '服务器离线' || /^存储空间\\s+已用[:：]\\s*0 B\\s*\\/\\s*0 B$/.test(label)) suppress(element.parentElement || element);
    });
    applyBranding();
    ensureLegalNotice();
  };
  const archivePersonIdFromImage = (image) => {
    if (!image) return null;
    try {
      const match = /^\\/api\\/people\\/([0-9a-f-]{36})\\/thumbnail$/i.exec(new URL(image.src, location.origin).pathname);
      return match ? match[1].toLowerCase() : null;
    } catch { return null; }
  };
  const applyArchivePersonRoutes = () => {
    if (!location.pathname.startsWith('/people')) return;
    document.querySelectorAll('img[src*="/api/people/"]').forEach((image) => {
      const id = archivePersonIdFromImage(image);
      const anchor = image.closest('a');
      if (!id || !anchor) return;
      // The public People API exposes the ClassArchivePerson UUID. Rewriting
      // this navigation target keeps keyboard and pointer activation in the
      // archive-aware projection without touching Immich's own route source.
      const destination = '/class-archive-person/' + encodeURIComponent(id);
      if (anchor.getAttribute('href') !== destination) anchor.setAttribute('href', destination);
      anchor.setAttribute('data-class-archive-person-route', 'true');
      if (anchor.dataset.classArchivePersonBound !== '1') {
        anchor.dataset.classArchivePersonBound = '1';
        // SvelteKit's link interceptor is attached in the bubble phase. A
        // narrow capture handler on this one generated Person card therefore
        // keeps ordinary pointer and keyboard activation on the protected
        // archive route instead of its generic fileCreatedAt page.
        anchor.addEventListener('click', (event) => {
          if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
          event.preventDefault();
          event.stopImmediatePropagation();
          event.stopPropagation();
          location.assign(destination);
        }, true);
      }
    });
  };
  // Immich's generic Person page serializes all assets through file-created
  // timestamps. Class Archive intentionally refuses to invent those values
  // for month/year/event/unknown archive dates, so route the click to the
  // narrow archive-aware projection instead. It still fetches every item
  // through the same BFF -> Gateway -> MediaGuard media chain.
  const routeArchivePerson = (event) => {
    // A click generated by a keyboard or browser automation may not carry a
    // MouseEvent.button value. Modifier keys still retain normal new-tab
    // semantics; unmodified activation always follows the archive route.
    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const target = event.target instanceof Element ? event.target : null;
    const pointTarget = Number.isFinite(event.clientX) && Number.isFinite(event.clientY)
      ? document.elementFromPoint(event.clientX, event.clientY)
      : null;
    // A Svelte card can receive the pointer on its overlay instead of the
    // nested image. Resolve the nearest interactive card first so normal
    // pointer and keyboard activation take the identical archive route.
    const source = target || pointTarget;
    const card = source?.closest('button,a,[role="button"],[role="link"]');
    const image = source?.closest('img[src*="/api/people/"]') || card?.querySelector('img[src*="/api/people/"]');
    if (!image || !location.pathname.startsWith('/people')) return;
    const id = archivePersonIdFromImage(image);
    if (!id) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    event.stopPropagation();
    location.assign('/class-archive-person/' + encodeURIComponent(id));
  };
  // The upstream card may navigate during its pointer handler, before a
  // later click listener runs. Capture pointer activation first, and retain
  // click for keyboard activation.
  window.addEventListener('pointerdown', routeArchivePerson, true);
  window.addEventListener('click', routeArchivePerson, true);
  const applyCompatibilityShell = () => {
    applyReadOnlySurfaces();
    applyArchivePersonRoutes();
  };
  new MutationObserver(applyCompatibilityShell).observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['src', 'href'],
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyCompatibilityShell, { once: true });
  else applyCompatibilityShell();
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

function archivePersonHtml(personId) {
  // This archive-aware Person page is intentionally separate from Immich's
  // fileCreatedAt-oriented timeline. It preserves each Class Archive date
  // precision in the visible label and never turns upload/import time into a
  // claimed capture day. `personId` has passed assertUuid() before it is
  // interpolated; all persisted labels are assigned through textContent.
  return `<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>人物 | 班级相册</title><style>
body{margin:0;background:#f7f8fb;color:#1f2937;font:15px/1.5 system-ui,-apple-system,"Microsoft YaHei",sans-serif}main{max-width:1180px;margin:0 auto;padding:28px 20px 56px}.back{color:#3656c5;text-decoration:none}.head{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.head h1{margin:0;font-size:26px}.count{color:#667085}.note{margin:8px 0 26px;color:#596579}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px}.card{border:0;background:transparent;text-align:left;padding:0;color:inherit;cursor:pointer}.thumb{display:block;width:100%;aspect-ratio:1.35;object-fit:cover;border-radius:10px;background:#e5e7eb}.title{display:block;margin-top:7px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.meta{display:block;margin-top:2px;color:#667085;font-size:12px}.empty,.error{border:1px solid #d8deea;border-radius:10px;padding:18px;background:#fff}.error{color:#a12b2b}@media(max-width:460px){main{padding:20px 14px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}
</style></head><body><main><a class="back" href="/people">返回人物</a><section class="head"><h1 id="name">人物</h1><span id="count" class="count"></span></section><p class="note">照片按班级档案日期展示；日期精度不足时会如实标记，不会补写不存在的拍摄时间。</p><section id="person-grid" aria-live="polite"><p class="empty">正在载入人物照片…</p></section></main><script>
(() => {
  const personId = '${personId}';
  const root = document.getElementById('person-grid');
  const text = (tag, value, className) => { const node=document.createElement(tag); node.textContent=value; if(className) node.className=className; return node; };
  const mediaUrl = (id) => '/api/assets/' + encodeURIComponent(id) + '/thumbnail?size=thumbnail';
  const render = (payload) => {
    if (!payload || !Array.isArray(payload.items) || !Number.isInteger(payload.photo_count) || payload.items.length !== payload.photo_count) throw new Error('person_invalid');
    document.getElementById('name').textContent = payload.label || '人物';
    document.getElementById('count').textContent = payload.photo_count + ' 张照片';
    root.replaceChildren();
    if (payload.items.length === 0) { root.append(text('p', '暂无可查看的照片。', 'empty')); return; }
    const grid=document.createElement('div'); grid.className='grid';
    for (const photo of payload.items) {
      if (!photo || typeof photo.id !== 'string') throw new Error('person_item_invalid');
      const button=document.createElement('button'); button.type='button'; button.className='card';
      button.addEventListener('click', () => { location.assign('/class-archive-photo/' + encodeURIComponent(photo.id)); });
      const image=document.createElement('img'); image.className='thumb'; image.loading='lazy'; image.src=mediaUrl(photo.id); image.alt=photo.title || '班级照片';
      const label=photo.archive_date && typeof photo.archive_date.label === 'string' ? photo.archive_date.label : '日期未知';
      button.append(image, text('span', photo.title || '班级照片', 'title'), text('span', label, 'meta'));
      grid.append(button);
    }
    root.append(grid);
  };
  fetch('/api/class-archive/people/' + encodeURIComponent(personId), {credentials:'same-origin',cache:'no-store'})
    .then(async (response) => { if (!response.ok) throw new Error('person_unavailable'); return response.json(); })
    .then(render).catch(() => { root.replaceChildren(text('p', '人物照片暂时无法安全确认，请返回后重试。', 'error')); });
})();
</script></body></html>`;
}

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

function responseIsWritable(response) {
  return !response.destroyed && !response.writableEnded;
}

function respond(response, method, status, contentType, body = '', options = {}) {
  // A browser can cancel an image/navigation fetch while the isolated BFF is
  // still awaiting the policy Gateway. Never let a write to that already-gone
  // socket surface as an unhandled Node error or affect another session.
  if (!responseIsWritable(response)) {
    return;
  }
  try {
    setSecurityHeaders(response, options);
    response.statusCode = status;
    response.setHeader('Content-Type', contentType);
    if (noBody(method)) {
      response.end();
      return;
    }
    response.end(body);
  } catch {
    if (!response.destroyed) {
      response.destroy();
    }
  }
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
  constructor(status, diagnostic = 'gateway_unavailable') {
    super('class_archive_gateway_response_invalid');
    this.status = status;
    this.diagnostic = /^[a-z0-9_]{1,96}$/.test(diagnostic) ? diagnostic : 'gateway_unavailable';
  }
}

const emittedGatewayDiagnostics = new Set();

function emitGatewayDiagnostic(operation, error) {
  const safeOperation = /^[a-z0-9_]{1,48}$/.test(operation) ? operation : 'unknown';
  const code = error instanceof GatewayResponseError ? error.diagnostic
    : error instanceof TypeError || error instanceof SyntaxError ? 'request_invalid'
      : 'unexpected';
  const key = `${safeOperation}:${code}`;
  if (emittedGatewayDiagnostics.has(key)) return;
  emittedGatewayDiagnostics.add(key);
  // These fixed, content-free diagnostics are consumed only by the local
  // runtime harness from the disposable container log. They do not travel
  // back to a browser and intentionally omit route parameters, IDs, cookies,
  // request bodies, upstream URLs, and credentials.
  process.stderr.write(`CLASS_ARCHIVE_BFF_DIAGNOSTIC operation=${safeOperation} code=${code}\n`);
}

// Piwigo's session implementation can serialize requests for one cookie. The
// untouched Immich Web starts several read requests at once, so letting every
// BFF request independently race through the same Piwigo session can turn a
// healthy authorization decision into a timeout/503. Queue only the internal
// metadata calls for one opaque cookie digest. This is not an authorization
// cache: each queued operation still performs its own fresh Gateway request,
// and the digest is discarded as soon as the queue drains.
const gatewaySessionQueues = new Map();
const maxGatewaySessionQueues = 128;
const gatewayRequestResponses = new WeakMap();

function gatewaySessionKey(request, clientAddress) {
  const cookie = sessionCookie(request);
  return createHash('sha256').update(`${clientAddress}\0${cookie}`).digest('hex');
}

function gatewayRequestIsActive(request) {
  const response = gatewayRequestResponses.get(request);
  // Node may auto-destroy an IncomingMessage after a bounded POST body is
  // completely consumed. That is normal stream cleanup, not a browser abort;
  // search performs its second canonical Gateway call only after parsing that
  // body. ServerResponse instead represents the real connection lifetime.
  // A closed/ended response cannot receive a policy result, while an active
  // one still receives a fresh authorization without any permission cache.
  return response !== undefined && !response.destroyed && !response.writableEnded;
}

async function queueGatewaySessionRequest(request, clientAddress, task) {
  const key = gatewaySessionKey(request, clientAddress);
  const previous = gatewaySessionQueues.get(key);
  if (!previous && gatewaySessionQueues.size >= maxGatewaySessionQueues) {
    throw new GatewayResponseError(503, 'gateway_queue_capacity');
  }
  const current = Promise.resolve(previous)
    .catch(() => undefined)
    .then(async () => {
      // A Chromium context can disappear while an earlier same-session request
      // waits its turn. Do not begin a fresh PHP/Gateway request for that dead
      // client: return no authorization result, drain the opaque queue, and
      // leave the next active session independent.
      if (!gatewayRequestIsActive(request)) {
        throw new GatewayResponseError(503, 'gateway_request_inactive');
      }
      return task();
    });
  gatewaySessionQueues.set(key, current);
  try {
    return await current;
  } finally {
    if (gatewaySessionQueues.get(key) === current) {
      gatewaySessionQueues.delete(key);
    }
  }
}

async function fetchGatewayJson(request, path, clientAddress) {
  const upstream = new URL(path, gatewayOrigin);
  const cookie = sessionCookie(request);
  let result;
  try {
    result = await fetch(upstream, {
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
  } catch {
    throw new GatewayResponseError(503, 'gateway_transport');
  }
  if (result.status !== 200) {
    const diagnostic = result.headers.get('x-class-archive-gateway-diagnostic');
    throw new GatewayResponseError(result.status, diagnostic ?? `gateway_http_${result.status}`);
  }
  const contentType = result.headers.get('content-type') ?? '';
  if (!contentType.toLowerCase().startsWith('application/json')) {
    throw new GatewayResponseError(503, 'gateway_content_type');
  }
  try {
    return await result.json();
  } catch {
    throw new GatewayResponseError(503, 'gateway_json');
  }
}

async function gatewayJson(request, path, clientAddress) {
  if (typeof clientAddress !== 'string' || isIP(clientAddress) === 0) {
    throw new GatewayResponseError(503, 'gateway_client_address');
  }
  return queueGatewaySessionRequest(request, clientAddress, () => fetchGatewayJson(request, path, clientAddress));
}

async function principal(request, clientAddress) {
  const payload = await gatewayJson(request, '/api/me', clientAddress);
  const role = payload?.role;
  if (typeof role !== 'string' || !knownRoles.has(role)) {
    throw new GatewayResponseError(503, 'gateway_principal_payload');
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

function archivePersonProjection(person) {
  if (!person || typeof person !== 'object' || typeof person.id !== 'string' || !UUID_V4.test(person.id)
    || typeof person.label !== 'string' || person.label.length < 1 || person.label.length > 190
    || !Number.isInteger(person.photo_count) || person.photo_count < 1 || !Array.isArray(person.items)
    || person.items.length !== person.photo_count) {
    throw new GatewayResponseError(503);
  }
  const ids = new Set();
  const items = person.items.map((photo) => {
    const id = assertUuid(photo?.id);
    if (ids.has(id)) {
      throw new GatewayResponseError(503);
    }
    ids.add(id);
    const title = typeof photo?.title === 'string' && photo.title.length <= 190 ? photo.title : '班级照片';
    return { id, title, archive_date: archiveDateProjection(photo) };
  });
  return { id: person.id.toLowerCase(), label: person.label, photo_count: person.photo_count, items };
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
    // The upstream-shaped search card needs an explicit thumbnail property.
    // This is still a canonical UUID route: the BFF re-checks the active
    // ClassIdentity session and the Piwigo Gateway re-enters MediaGuard
    // before outer nginx can transfer a byte.
    thumbnailPath: `/api/assets/${id}/thumbnail?size=thumbnail`,
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

async function policyTimelinePhotos(request, clientAddress, personId = null) {
  if (personId !== null) {
    // A Person route must not ask Immich for its full cluster and subtract
    // hidden assets afterwards. The canonical Gateway has already applied
    // ClassArchivePolicy before it returns this person's media projection.
    const person = await gatewayJson(request, `/api/people/${assertUuid(personId)}`, clientAddress);
    const items = Array.isArray(person?.items) ? person.items : null;
    if (items === null) {
      throw new GatewayResponseError(503);
    }
    const ids = new Set();
    return items.map((photo) => {
      const id = assertUuid(photo?.id);
      if (ids.has(id)) {
        throw new GatewayResponseError(503);
      }
      ids.add(id);
      return photo;
    });
  }

  const timeline = await gatewayJson(request, '/api/timeline', clientAddress);
  const groups = Array.isArray(timeline?.groups) ? timeline.groups : null;
  if (groups === null) {
    throw new GatewayResponseError(503);
  }
  const ids = new Set();
  const photos = [];
  for (const group of groups) {
    if (!Array.isArray(group?.items)) {
      throw new GatewayResponseError(503);
    }
    for (const photo of group.items) {
      const id = assertUuid(photo?.id);
      if (ids.has(id)) {
        throw new GatewayResponseError(503);
      }
      ids.add(id);
      photos.push(photo);
    }
  }
  return photos;
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
  // Authorization failures are status-only evidence. Never buffer or relay a
  // caller-influenced Gateway body into Node memory, even on an error path.
  // A fixed tiny response also avoids leaking internal diagnostics.
  try { await upstreamResponse.body?.cancel(); } catch { }
  const message = upstreamResponse.status === 404
    ? 'Media not found.'
    : upstreamResponse.status === 403
      ? 'Media access denied.'
      : 'Media temporarily unavailable.';
  respond(response, request.method, upstreamResponse.status, 'text/plain; charset=utf-8', message, { media: true });
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
    emitGatewayDiagnostic('principal', error);
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
      const query = exactQuery(url, new Set(['albumId', 'isTrashed', 'isFavorite', 'visibility', 'withStacked', 'withPartners', 'order', 'orderBy', 'personId']));
      if (query.get('isTrashed') === 'true' || query.get('visibility') === 'archive' || query.has('albumId')) {
        respondJson(response, request.method, 200, []);
        return;
      }
      const photos = await policyTimelinePhotos(request, clientAddress, query.get('personId'));
      const counts = new Map();
      for (const photo of photos) {
        // The generic Immich timeline is only safe for confirmed day-level
        // dates. Month/year/event/unknown assets remain available through
        // the Class Archive timeline projection rather than being rounded
        // to an invented calendar day.
        const date = photoDate(photo);
        if (date === null) continue;
        const month = date.slice(0, 7);
        counts.set(month, (counts.get(month) ?? 0) + 1);
      }
      const result = Array.from(counts.entries())
        .sort(([left], [right]) => right.localeCompare(left))
        .map(([month, count]) => ({ timeBucket: `${month}-01T00:00:00.000Z`, count }));
      respondJson(response, request.method, 200, result);
      return;
    }
    if (url.pathname === '/api/timeline/bucket') {
      const query = exactQuery(url, new Set(['timeBucket', 'albumId', 'isTrashed', 'isFavorite', 'visibility', 'withStacked', 'withPartners', 'order', 'orderBy', 'personId']));
      const timeBucket = query.get('timeBucket');
      if (!timeBucket) {
        throw new TypeError('class_archive_web_compat_time_bucket_missing');
      }
      if (query.get('isTrashed') === 'true' || query.get('visibility') === 'archive' || query.has('albumId')) {
        respondJson(response, request.method, 200, timeBucketResponse([], role));
        return;
      }
      const requestedMonth = parseMonth(timeBucket);
      const photos = (await policyTimelinePhotos(request, clientAddress, query.get('personId')))
        .filter((photo) => {
          const date = photoDate(photo);
          return date !== null && date.slice(0, 7) === requestedMonth;
        });
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
      const requestKey = url.pathname.endsWith('/smart') ? 'smartSearchDto' : 'metadataSearchDto';
      // The upstream v3.1.0 SDK POSTs the DTO itself. Earlier isolated
      // contract probes used the OpenAPI operation-name wrapper. Accept both
      // bounded shapes so the unmodified Web can run, but reject a hybrid
      // payload instead of silently letting arbitrary extra fields redefine
      // the request. Only the normalised, allowlisted search term below is
      // ever sent to the canonical Gateway.
      const wrapped = Object.prototype.hasOwnProperty.call(body, requestKey);
      if (wrapped && Object.keys(body).length !== 1) {
        throw new TypeError('class_archive_web_compat_search_body_ambiguous');
      }
      const source = wrapped ? body[requestKey] : body;
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
      // The upstream search route performs an initial metadata request before
      // the member enters a term. Class Archive does not project arbitrary
      // Immich metadata filters, so an empty/unsupported query is safely
      // empty rather than forwarded as a whole-library request or surfaced as
      // an error toast. A real text query below still goes through the
      // canonical Gateway before any result/count is returned.
      if (query === '') {
        respondJson(response, request.method, 200, compatibleSearch([], role));
        return;
      }
      const path = url.pathname.endsWith('/smart') ? '/api/search/smart' : '/api/search';
      const results = await gatewayJson(request, `${path}?q=${encodeURIComponent(query)}`, clientAddress);
      const photos = Array.isArray(results?.items) ? results.items : [];
      respondJson(response, request.method, 200, compatibleSearch(photos, role));
      return;
    }

    const archivePersonMatch = /^\/api\/class-archive\/people\/([0-9a-f-]{36})$/i.exec(url.pathname);
    if (archivePersonMatch) {
      exactQuery(url, new Set());
      const person = await gatewayJson(request, `/api/people/${assertUuid(archivePersonMatch[1])}`, clientAddress);
      respondJson(response, request.method, 200, archivePersonProjection(person));
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
      // The verified upstream v3.1.0 Web bundle appends `updatedAt` purely as
      // an image-cache buster. It is deliberately ignored here: neither a
      // cache hint nor any query value can select a person, a cover asset, or
      // an authorization decision. Unknown/duplicate query keys still fail
      // closed via exactQuery(), and the actual thumbnail request remains a
      // fresh canonical Gateway -> MediaGuard authorization check.
      exactQuery(url, new Set(['updatedAt']));
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
      emitGatewayDiagnostic('api', error);
      const status = error.status === 404 ? 404 : 503;
      respondJson(response, request.method, status, { error: status === 404 ? '资源不存在' : '数据暂时无法安全确认' });
      return;
    }
    if (error instanceof TypeError || error instanceof SyntaxError) {
      emitGatewayDiagnostic('api', error);
      respondJson(response, request.method, 400, { error: '请求格式无效' });
      return;
    }
    emitGatewayDiagnostic('api', error);
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
  const archivePersonMatch = /^\/class-archive-person\/([0-9a-f-]{36})$/i.exec(url.pathname);
  if (archivePersonMatch) {
    const personId = assertUuid(archivePersonMatch[1]);
    try {
      await principal(request, clientAddress);
      // Return the same status for an unknown/inaccessible person. The custom
      // page itself performs the full, role-filtered data fetch after load.
      const person = await gatewayJson(request, `/api/people/${personId}`, clientAddress);
      archivePersonProjection(person);
    } catch {
      respond(response, request.method, 404, 'text/plain; charset=utf-8', '资源不存在', { html: true });
      return;
    }
    respond(response, request.method, 200, 'text/html; charset=utf-8', archivePersonHtml(personId), { html: true });
    return;
  }
  const upstreamPersonMatch = /^\/people\/([0-9a-f-]{36})$/i.exec(url.pathname);
  if (upstreamPersonMatch) {
    // The pinned, unmodified Web still generates its normal /people/{id}
    // link. Preserve that ordinary browser path, but resolve it server-side
    // into the archive-aware projection. This is not a client-side hiding
    // rule: the active Piwigo principal and canonical Gateway must both
    // authorize the public ClassArchivePerson UUID before we redirect.
    if (url.searchParams.size > 1 || (url.searchParams.size === 1 && url.searchParams.get('previousRoute') !== '/people')) {
      respond(response, request.method, 404, 'text/plain; charset=utf-8', '资源不存在', { html: true });
      return;
    }
    const personId = assertUuid(upstreamPersonMatch[1]);
    try {
      await principal(request, clientAddress);
      const person = await gatewayJson(request, `/api/people/${personId}`, clientAddress);
      archivePersonProjection(person);
    } catch {
      respond(response, request.method, 404, 'text/plain; charset=utf-8', '资源不存在', { html: true });
      return;
    }
    response.setHeader('Location', '/class-archive-person/' + encodeURIComponent(personId));
    respond(response, request.method, 302, 'text/plain; charset=utf-8', '');
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
  // Suppress only socket-level errors for a client that has already gone away.
  // Application failures still become the explicit fail-closed 4xx/503 paths
  // in serveApplication/handleApi.
  response.on('error', () => {});
  const method = request.method ?? 'GET';
  let url;
  try {
    url = new URL(request.url ?? '/', publicOrigin);
  } catch {
    respond(response, method, 400, 'text/plain; charset=utf-8', 'Invalid request.');
    return;
  }
  gatewayRequestResponses.set(request, response);
  response.once('close', () => gatewayRequestResponses.delete(request));
  void serveApplication(request, response, url).catch(() => {
    if (!responseIsWritable(response)) {
      return;
    }
    if (!response.headersSent) {
      respond(response, method, 503, 'text/plain; charset=utf-8', 'Service temporarily unavailable.');
    } else {
      try { response.destroy(); } catch { }
    }
  });
});

server.listen(port, '0.0.0.0', () => {
  process.stdout.write(`class-archive-immich-web-compat listening on ${port}\n`);
});
