import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const uiRoot = resolve(root, 'infra', 'immich-spike', 'photo-ui');
const serverPath = resolve(root, 'infra', 'immich-spike', 'web-compat', 'server.mjs');
const files = Object.freeze({
  html: resolve(uiRoot, 'index.html'),
  css: resolve(uiRoot, 'app.css'),
  app: resolve(uiRoot, 'app.js'),
  i18n: resolve(uiRoot, 'i18n.js'),
  server: serverPath,
});

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

for (const [name, path] of Object.entries(files)) {
  const entry = await stat(path);
  check(entry.isFile() && entry.size > 0, `${name} must be a non-empty regular file`);
}

const html = await readFile(files.html, 'utf8');
const css = await readFile(files.css, 'utf8');
const app = await readFile(files.app, 'utf8');
const i18n = await readFile(files.i18n, 'utf8');
const server = await readFile(files.server, 'utf8');

check(html.includes('type="module" src="/photo-ui/app.js?v=__PHOTO_UI_ASSET_REV__"'), 'document must load the versioned owned module');
check(html.includes('href="/photo-ui/app.css?v=__PHOTO_UI_ASSET_REV__"'), 'document must load the versioned owned stylesheet');
check(!/<script(?![^>]*\bsrc=)[^>]*>/i.test(html), 'document must not use inline scripts');

for (const [route, label] of [
  ['/photos', '照片'],
  ['/people', '人物'],
  ['/search', '搜索'],
  ['/albums', '相册'],
  ['/memories', '回忆'],
  ['/my', '我的'],
]) {
  check(app.includes(`href: '${route}'`), `navigation must include ${route}`);
  check(i18n.includes(`'${label}'`), `i18n must include the Chinese label for ${route}`);
  check(server.includes(`'${route}'`), `BFF must recognize ${route}`);
}

check(server.includes("const photoUiRoot = resolve(process.env.CLASS_ARCHIVE_PHOTO_UI_ROOT ?? '/photo-ui')"), 'BFF must use an explicit read-only photo UI root contract');
check(server.includes("['/photo-ui/app.js', 'app.js']"), 'BFF must allowlist owned static files');
check(server.includes('if (isPhotoUiRoute(url.pathname))'), 'BFF must route product pages to the owned UI');
const uiRouteStart = server.indexOf('if (isPhotoUiRoute(url.pathname))');
const uiPrincipal = server.indexOf('await principal(request, clientAddress)', uiRouteStart);
const uiDocument = server.indexOf("readPhotoUiFile('index.html')", uiRouteStart);
check(uiRouteStart >= 0 && uiPrincipal > uiRouteStart && uiDocument > uiPrincipal, 'BFF must authenticate before reading the owned application document');
const rootRouteStart = server.indexOf("if (url.pathname === '/')");
const rootPrincipal = server.indexOf('await principal(request, clientAddress)', rootRouteStart);
const rootPhotoRedirect = server.indexOf("response.setHeader('Location', '/photos')", rootRouteStart);
check(rootRouteStart >= 0 && rootPrincipal > rootRouteStart && rootPhotoRedirect > rootPrincipal, 'root route must authenticate before redirecting to the photo product');
check((server.match(/url\.searchParams\.size !== 0/g) ?? []).length >= 2, 'root and owned routes must fail closed on unknown query parameters');
check(server.includes("url.searchParams.get('v') !== revision"), 'owned static assets must accept only their current content revision');
const detailPhotoPrecheck = server.indexOf('gatewayJson(request, `/api/photos/${photoId}`', uiRouteStart);
const detailPersonPrecheck = server.indexOf('gatewayJson(request, `/api/people/${personId}`', uiRouteStart);
check(detailPhotoPrecheck > uiPrincipal && detailPhotoPrecheck < uiDocument, 'photo detail document must be prechecked by the canonical Gateway');
check(detailPersonPrecheck > uiPrincipal && detailPersonPrecheck < uiDocument, 'person detail document must be prechecked by the canonical Gateway');
check(server.includes('process.env.CLASS_ARCHIVE_CORE_PUBLIC_PORT === undefined'), 'core public port must be explicit rather than guessed');
check(server.includes("response.setHeader('Location', '/class-archive-core/login')"), 'login handoff must first use a relative BFF redirect route');
check(server.includes("['/class-archive-core/identity', '/index.php?/class-identity/my']"), 'core identity redirect must be bounded by a server-side allowlist');
check(!server.includes('127.0.0.1:8090') && !app.includes(':8090'), 'owned UI and BFF must not hardcode the core HTTP port');
const internalGatewayFetch = server.slice(
  server.indexOf('async function fetchGatewayJson'),
  server.indexOf('async function gatewayJson'),
);
check(internalGatewayFetch.includes('boundedGatewayBody(result)'), 'internal compatibility metadata must enforce the same 1 MiB response bound');
check(!internalGatewayFetch.includes('result.json()'), 'internal compatibility metadata must not use an unbounded JSON buffer');
check(server.includes("url.pathname === '/class-archive-about'"), 'legacy class-archive about route must remain available');
check(server.includes("url.pathname === '/class-archive-timeline'"), 'legacy class-archive timeline route must remain available');
check(server.includes('/class-archive-person/'), 'legacy class-archive person route must remain available');
check(server.includes('/class-archive-photo/'), 'legacy class-archive photo route must remain available');

check(app.includes("presentationJson('/api/class-archive/timeline')"), 'timeline must use the cached archive-aware projection');
check(app.includes("presentationJson('/api/people?size=500&withHidden=false')"), 'people must use the policy-filtered presentation cache');
check(app.includes("apiJson('/api/search/metadata'"), 'hybrid search must use metadata search');
check(app.includes("apiJson('/api/search/smart'"), 'hybrid search must use safe smart search');
check(app.includes("apiJson(`/api/class-archive/search/hybrid?${params}`)"), 'search must prefer the structured Class Archive hybrid projection');
const searchRenderStart = app.indexOf('async function renderSearch()');
const searchSubmitStart = app.indexOf("form.addEventListener('submit'", searchRenderStart);
const legacyInitialState = app.indexOf("emptyState('search.initialTitle', 'search.initialBody')", searchRenderStart);
check(searchRenderStart >= 0 && searchSubmitStart > searchRenderStart, 'search route must own a bounded submit flow');
check(legacyInitialState === -1, 'search must not render a large empty-state card before a query');
check(app.includes('function searchDiscovery(onQuery)')
  && app.includes("const discovery = searchDiscovery(runQuery)")
  && app.includes('suggestion.addEventListener'), 'search must provide clickable lightweight query suggestions');
check(app.includes('if (!results.isConnected) page.append(results);')
  && app.indexOf('results.replaceChildren(loadingState())', searchSubmitStart) > searchSubmitStart,
  'search results and skeletons must be attached only after a non-empty query');
for (const key of [
  'search.suggestionsTitle', 'search.suggestionGraduation', 'search.suggestionSportsMeet',
  'search.suggestionClassroom', 'search.suggestionPlayground', 'search.suggestionGroupPhoto',
  'search.suggestionBasketball', 'search.discoveryHint',
]) check(i18n.includes(`'${key}'`), `search discovery copy must be centralized for ${key}`);
check(css.includes('.search-discovery') && css.includes('.search-suggestion') && !css.includes('.search-discovery { min-height:'),
  'search discovery must stay lightweight instead of becoming a large empty-state card');
check(app.includes("presentationJson('/api/class-archive/albums')"), 'albums must use the cached BFF contract');
check(app.includes('function sourceCollectionPresentation(albums, hierarchy)')
  && app.includes("album.sourceRoot === true")
  && i18n.includes("'albums.sourceCollections'"), 'safe source collections must be promoted without exposing source paths');
check(app.includes("presentationJson('/api/class-archive/memories')"), 'memories must use the cached archive-aware BFF contract');
check(app.includes("apiJson('/api/users/me')"), 'profile must use the presentation-only current user endpoint');
check(app.includes("['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'].includes(size)"), 'owned media helper must use only canonical responsive derivatives');
check(app.includes("if (revision) params.set('v', revision)"), 'owned media URLs must carry the opaque content revision when available');
check(!app.includes('/original'), 'owned UI must not request an original endpoint');
check(!app.includes(':2283') && !app.includes('immich-server') && !app.includes('immich_asset_id'), 'owned UI must not expose the internal Immich media boundary');
check(!app.includes('ownerId') && !app.includes('assetId') && !app.includes('personId'), 'owned UI must not render or depend on technical identifier fields');
check(!/\p{Script=Han}/u.test(app), 'member-facing Chinese copy must remain centralized in i18n');
check(!i18n.includes('CLIP') && !i18n.includes('embedding') && !i18n.includes('ownerId'), 'member-facing translations must not expose technical AI or identity terms');

check(app.includes("event.key === 'ArrowLeft'"), 'viewer must support previous-photo keyboard navigation');
check(app.includes("event.key === 'ArrowRight'"), 'viewer must support next-photo keyboard navigation');
check(app.includes("event.key === 'Escape'"), 'viewer must support Escape');
check(app.includes('updateZoom'), 'viewer must expose bounded zoom controls');
check(app.includes("responsivePhotoImage(photo, 'viewer'"), 'viewer must use a responsive MediaGuard-backed preview');
check(app.includes("addEventListener('touchstart'"), 'viewer must start mobile touch gesture tracking');
check(app.includes("addEventListener('touchmove'"), 'viewer must handle two-finger pinch movement');
check(app.includes("addEventListener('touchend'"), 'viewer must complete horizontal swipe navigation');
check(app.includes("gesture = { type: 'pinch'"), 'viewer must distinguish pinch from swipe gestures');
check(app.includes('Math.abs(deltaX) >= 56'), 'viewer swipe must use a deliberate horizontal threshold');
check(app.includes('prefetchAdjacentPreviews(photos, index)'), 'viewer must prefetch only adjacent authorized previews');
check(app.includes("preview.src = mediaUrl(adjacent.id, 'preview', adjacent.mediaRevision ?? '')"), 'adjacent prefetch must remain on the versioned BFF MediaGuard preview route');
check(app.includes('new IntersectionObserver') && app.includes("rootMargin: '700px 0px'"), 'grid media must use bounded viewport overscan');
check(app.includes("index < 6"), 'only the first-screen grid cards may load eagerly');
check(app.includes('image.srcset = image.dataset.srcset') && app.includes('image.sizes = options.sizes'), 'grid must expose responsive srcset and sizes');
check(app.includes('sessionStorage.setItem') && app.includes('sessionStorage.removeItem'), 'presentation SWR must remain session-scoped and purgeable');
check(app.includes("window.addEventListener('pagehide'")
  && app.includes("window.addEventListener('pageshow'")
  && app.includes('event.persisted')
  && app.includes("document.visibilityState !== 'visible'")
  && app.includes('concealPrivatePresentation();'),
  'back-forward and background-tab transitions must conceal private pixels before revalidating the session');
check(app.includes('runtime.cacheScope !== verifiedScope')
  && app.includes('runtime.presentationFailureActive || runtime.cacheScope !== verifiedScope')
  && app.includes('failClosedPresentation(error);')
  && !app.includes('if (cached !== null) return cached;'),
  'presentation SWR failures must remove stale data instead of treating it as a successful refresh');
check(app.includes('function assertPresentationActive()')
  && app.includes("throw new Error('safe_presentation_fail_closed')"),
  'parallel presentation reads must not repaint after a sibling read has failed closed');
check(app.includes('function reloadProjectionBackedRoute()')
  && (app.match(/reloadProjectionBackedRoute,/g) ?? []).length === 2,
  'Spotlight mutations must reload through the epoch-aware presentation invalidation path');

check(css.includes('--sidebar: 220px'), 'desktop sidebar must be 220px');
check(css.includes('html[data-session-revalidating="true"] #app')
  && css.includes('visibility: hidden')
  && css.includes('pointer-events: none'),
  'the session barrier must hide and disable the old private interface');
check(app.includes("const MOBILE_NAVIGATION = new Set(['photos', 'people', 'search', 'albums', 'my'])"), 'mobile navigation must use the five product tabs');
check(css.includes('grid-template-columns: repeat(5, minmax(0, 1fr))'), 'mobile navigation must have five equal tabs');
check(css.includes('env(safe-area-inset-bottom)'), 'mobile navigation must respect the safe area');
check(css.includes('min-height: 52px'), 'mobile tab hit areas must exceed 44px');
check(css.includes('@media (max-width: 760px)'), 'owned UI must include a mobile layout');
check(css.includes('@media (prefers-reduced-motion: reduce)'), 'owned UI must respect reduced motion');
check(css.includes('columns: 5 180px'), 'desktop photo grid must provide a dense masonry presentation');
check(css.includes('object-fit: contain'), 'photo grid must preserve source composition instead of forcing a crop');
check(css.includes('touch-action: none'), 'viewer touch surface must reserve gestures for swipe and pinch');
check(css.includes('color: #f5f3ef; border-color: rgba(255,255,255,.18)'), 'viewer controls must keep readable contrast on the dark media surface');
check(css.includes('.viewer-page[data-info-open="true"] .viewer-nav'), 'mobile Viewer navigation must not overlap the open information sheet');
check(app.includes("history.scrollRestoration = 'manual'") && app.includes('window.scrollTo(0, 0)'), 'full-page product navigation must start at a stable top position');
check(app.includes("new Intl.DateTimeFormat('zh-CN'"), 'Spotlight expiry must use a natural localized date instead of a raw database timestamp');
check(!app.includes('createElementNS') && !app.includes('iconPaths'), 'owned UI must not hand-draw substitute icon assets');
check(app.includes("mediaUrl(album.coverPhotoId, 'medium')") && css.includes('.album-cover'), 'album cards must use an authorized medium cover derivative');
check(app.includes("mediaUrl(memory.coverPhotoId, 'large')") && css.includes('.memory-card'), 'memory cards must use an authorized large cover derivative');

check(server.includes("'public, max-age=31536000, immutable'"), 'versioned app shell assets must use native immutable HTTP caching');
check(server.includes("'private, no-cache, max-age=0, must-revalidate, no-transform'"), 'private media and metadata must revalidate rather than use public caching');
check(server.includes("url.pathname === '/service-worker.js'") && server.includes("'Not found.'"), 'private media must not be retained by a service worker');
check(server.includes('async function photoUiPrincipalContext(request, clientAddress)')
  && server.includes('photoUiCacheScope(request, role, presentationEpoch, clientAddress)')
  && server.includes('.update(presentationEpoch)')
  && server.includes("gatewayJson(request, '/api/product-state', clientAddress)"),
  'presentation cache scope must bind to a consistent fresh role and scoped projection epoch');
check(server.includes("'/identification.php?redirect=%252Findex.php%253F%252Fclass-archive-photo-app'"), 'login must return through Piwigo\'s canonical authenticated photo-first bridge');
check(server.includes("fetchSite === 'same-site'") && server.includes("url.pathname === '/photos'") && server.includes("request.headers['sec-fetch-mode'] === 'navigate'") && server.includes("request.headers['sec-fetch-dest'] === 'document'"), 'cross-port login return must be limited to the exact top-level photos document');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photo-ui-static', assertions, result: 'PASS' })}\n`);
