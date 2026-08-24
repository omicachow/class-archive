import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');
const [server, app, html, schema, store, service, adapter, controller, nginx, rebuildProjection, photoAppRedirect] = await Promise.all([
  read('infra/immich-spike/web-compat/server.mjs'),
  read('infra/immich-spike/photo-ui/app.js'),
  read('infra/immich-spike/photo-ui/index.html'),
  read('plugins/ClassIdentity/src/Schema.php'),
  read('plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayService.php'),
  read('plugins/ClassIdentity/src/Gateway/PiwigoGatewayAdapter.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php'),
  read('infra/piwigo-nginx/nginx.conf'),
  read('infra/scripts/rebuild-photo-read-projection.php'),
  read('plugins/ClassIdentity/src/PhotoAppRedirect.php'),
]);

let assertions = 0;
const check = (condition, message) => {
  assert.ok(condition, message);
  assertions += 1;
};

check(html.match(/app\.css\?v=__PHOTO_UI_ASSET_REV__/), 'CSS must use a content-revision URL');
check(html.match(/app\.js\?v=__PHOTO_UI_ASSET_REV__/), 'JavaScript must use a content-revision URL');
check(server.includes("'public, max-age=31536000, immutable'"), 'only versioned non-sensitive shell assets may be immutable');
check(server.includes('requestEtagMatches(request, etag)'), 'versioned shell assets must support conditional requests');
check(server.includes("'private, no-cache, max-age=0, must-revalidate, no-transform'"), 'private presentation/media must require revalidation');
check(!server.includes("Cache-Control', 'public'") && !nginx.includes('Cache-Control "public'), 'protected media path must never declare a public cache');
for (const internalPath of ['source/upload/', 'source/galleries/', 'derivative/']) {
  const escaped = internalPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp(`location \\^~ /_class_archive_internal/${escaped} \\{[\\s\\S]{0,240}add_header Cache-Control "private, no-cache, must-revalidate, max-age=0, no-transform" always;`, 'g');
  check((nginx.match(pattern) ?? []).length === 3, `every ${internalPath} X-Accel target must prohibit intermediary transforms`);
}
check((nginx.match(/add_header Cache-Control "private, no-cache, must-revalidate, max-age=0, no-transform" always;/g) ?? []).length >= 9,
  'all source and cached-derivative X-Accel paths must share the protected cache contract');
check(!nginx.includes('/_class_archive_internal/generate/') && !nginx.includes('CLASS_ARCHIVE_DERIVATIVE_GENERATOR'),
  'member nginx servers must have no request-time derivative generation route');
check(server.includes("url.pathname === '/service-worker.js'") && server.includes("request.method, 404"), 'no service worker may retain private pixels');
check(!app.includes('caches.open(') && !app.includes('navigator.serviceWorker.register'), 'member UI must not create a private CacheStorage path');

check(app.includes('const PRESENTATION_CACHE_SCOPE_KEY'), 'presentation cache must persist its current session scope marker');
check(app.includes('photoUiCacheScope') === false, 'browser code must not invent its own authorization scope');
check(server.includes('async function photoUiPrincipalContext(request, clientAddress)')
  && server.includes('photoUiCacheScope(request, role, presentationEpoch, clientAddress)')
  && server.includes('.update(presentationEpoch)')
  && server.includes("gatewayJson(request, '/api/product-state', clientAddress)"),
  'BFF cache scope must bind the fresh role and scoped presentation epoch from one consistent product state');
check(server.includes('/identification.php?redirect=%252Findex.php%253F%252Fclass-archive-photo-app')
  && photoAppRedirect.includes("($tokens[0] ?? null) !== self::TOKEN")
  && photoAppRedirect.includes("header('Location: http://127.0.0.1:'"),
  'login must use an exact authenticated Piwigo bridge back to the photo-first UI');
check(app.includes('storedScope !== state.cacheScope'), 'account switch must purge the prior presentation cache');
const projectionReload = app.slice(
  app.indexOf('function reloadProjectionBackedRoute()'),
  app.indexOf('function presentationCacheKey(path)'),
);
check(projectionReload.includes('runtime.productStatePromise = null;')
  && projectionReload.includes('clearPresentationCache();')
  && projectionReload.indexOf('clearPresentationCache();') < projectionReload.indexOf('location.reload();')
  && projectionReload.includes('concealPrivatePresentation();'),
  'projection mutations must discard the old epoch-bound session payload and conceal private pixels before reload');
check((app.match(/reloadProjectionBackedRoute,/g) ?? []).length === 2,
  'Spotlight create and cancel must both use the projection-safe route reload');
check(app.includes("/^[a-f0-9]{64}$/.test(payload.presentationEpoch)")
  && app.includes('presentationEpoch !== null && typeof payload?.cacheScope'),
  'browser cache reuse must require a valid server projection epoch');
check(app.includes("response.status === 401 || response.status === 403"), 'revocation response must purge presentation data');
const lifecycleStart = app.indexOf("window.addEventListener('pagehide'");
const lifecycle = app.slice(lifecycleStart);
check(lifecycleStart >= 0
  && lifecycle.includes('concealPrivatePresentation();')
  && lifecycle.includes("window.addEventListener('pageshow'")
  && lifecycle.includes('event.persisted')
  && lifecycle.includes("document.visibilityState !== 'visible'")
  && lifecycle.includes('void revalidateVisibleSession();'),
  'pagehide, BFCache and background-tab transitions must conceal old pixels before session revalidation');
const revalidation = app.slice(app.indexOf('async function revalidateVisibleSession()'), lifecycleStart);
check(revalidation.indexOf('concealPrivatePresentation();') >= 0
  && revalidation.indexOf('concealPrivatePresentation();') < revalidation.indexOf('await productState()')
  && revalidation.includes('validationGeneration !== runtime.sessionValidationGeneration')
  && revalidation.indexOf("document.visibilityState !== 'visible'") < revalidation.indexOf('revealPrivatePresentation();'),
  'session revalidation must reveal the private DOM only after the latest visible-session check succeeds');
check(app.includes('sessionStorage.setItem') && !app.includes('localStorage.setItem'), 'presentation SWR must be tab-session scoped');
const presentationRead = app.slice(app.indexOf('async function presentationJson(path)'), app.indexOf('function normalizeProductState'));
check(presentationRead.includes("state.role === 'UNKNOWN' || !state.cacheScope")
  && presentationRead.includes('runtime.presentationFailureActive || runtime.cacheScope !== verifiedScope')
  && presentationRead.includes('runtime.cacheScope !== verifiedScope')
  && presentationRead.includes("document.visibilityState !== 'visible'")
  && presentationRead.includes('failClosedPresentation(error);')
  && presentationRead.includes('throw error;')
  && !presentationRead.includes('if (cached !== null) return cached;'),
  'SWR must purge and conceal stale presentation data when session or projection refresh fails');
check(app.includes('function assertPresentationActive()')
  && app.includes("throw new Error('safe_presentation_fail_closed')")
  && app.includes('assertPresentationActive();\n    let timeline = normalizeTimeline(timelineRead.value)'),
  'a late parallel response must not repaint after another projection refresh has failed closed');
check(!app.includes("return apiJson('/api/albums')"), 'projection failure must not fall back to an uncached alternate album read');

check(schema.includes("'0009_gateway_persistent_read_projection'"), 'durable photo read model must have a forward migration');
check(schema.includes("'0010_gateway_role_scoped_aggregate_projection'"), 'role-scoped aggregates must have a forward migration');
check(schema.includes("$this->quotedTable('read_photo')"), 'persistent photo catalog must be stored in MariaDB');
check(store.includes("WHERE `generation`=? ORDER BY `class_photo_id`"), 'catalog reads must use an atomically published generation');
check(store.includes('class_archive_read_projection_digest_mismatch'), 'catalog corruption must fail closed');
check((store.match(/\$this->assertCatalogStateCurrent\(\$state\);/g) ?? []).length === 3
  && store.includes("$this->assertAggregateStateCurrent($kind, $row);")
  && store.includes('public function presentationEpoch(string $scope): string'),
  'catalog, point, batch and aggregate reads must recheck their exact active binding before return');
check(controller.includes("$productState = $gateway->productState();")
  && controller.includes("'presentationEpoch' => $presentationEpoch"),
  'product state must fail closed through a role-scoped persistent projection epoch');
check(adapter.includes(': $this->readProjection->photos();'), 'normal HTTP adapter reads must use the persistent catalog');
check(adapter.includes('sourcePhotoCandidatesForRebuild()'), 'full Piwigo scans must be isolated to explicit rebuild');
check(controller.includes('self::archiveProjectionKinds($changes)') && controller.includes('self::rebuildPhotoProjection($photos, $projectionKinds)'),
  'archive writes must publish a dependency-scoped catalog/aggregate generation');
const aggregateReadMethod = store.slice(
  store.indexOf('public function aggregate(string $kind, string $scope): array'),
  store.indexOf('public function beginAggregateBuild'),
);
check(store.includes('private const AGGREGATE_KINDS')
  && aggregateReadMethod.includes('class_archive_read_aggregate_unavailable')
  && aggregateReadMethod.includes('payload_json')
  && !aggregateReadMethod.includes('compute')
  && !aggregateReadMethod.includes('rebuild'),
  'materialized photo aggregates must fail closed instead of rebuilding during an HTTP read');
check(store.includes('public function photosByIds(array $classPhotoIds)')
  && controller.includes("ReadProjectionStore::PEOPLE")
  && app.includes("presentationJson('/api/class-archive/timeline')"),
  'durable aggregate membership must hydrate through bounded catalog reads while the client consumes persisted metadata');
check(rebuildProjection.includes('--kinds=') && rebuildProjection.includes('projection_rebuild_kinds_without_aggregates'),
  'maintenance must support scoped aggregate rebuilds and reject meaningless photo-only kind filters');
check(store.includes('public function refreshPhotos(array $photos, array $affectedAggregateKinds, array $buildToken = [])')
  && store.includes("WHERE `class_photo_id`=? AND `generation`=? AND `piwigo_image_id`=?")
  && store.includes('ORDER BY `class_photo_id`')
  && !store.slice(store.indexOf('public function refreshPhotos'), store.indexOf('public function aggregate')).includes('DELETE FROM'),
  'write-path photo refresh must update only bounded rows and recompute the persisted catalog digest');
check(store.includes('public function beginPhotoCatalogBuild(): array')
  && store.includes('class_archive_read_projection_source_epoch_changed')
  && store.includes('assertPhotoBuildTokenMatchesRows($buildToken, $locked)')
  && rebuildProjection.includes('$store->beginPhotoCatalogBuild()'),
  'full and bounded catalog publication must fence source scans against concurrent native invalidation');
check(adapter.includes('sourcePhotoCandidatesByIdsForRebuild')
  && adapter.includes('WHERE pm.`class_photo_id` IN')
  && controller.includes('ReadProjectionBuilder::rebuildChangedPhotos($photoIds, $kinds)'),
  'archive writes must use point source lookup instead of the explicit full maintenance rebuild');
check(controller.includes("$rebuildMode === 'FULL_NATIVE_SOURCE'")
  && controller.includes('ReadProjectionBuilder::rebuild();')
  && controller.includes("$rebuildMode === 'BOUNDED'"),
  'native Piwigo writes must rebuild a fresh catalog while ClassIdentity-only metadata remains bounded');
check(service.includes('private static function paginateTimeline')
  && service.includes('TIMELINE_PAGE_MAX = 240')
  && service.includes('decodeTimelineCursor($cursor, $snapshot, $total, $signingKey)')
  && service.includes("getenv('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET')")
  && service.includes('class-archive/timeline-cursor-signing/v1')
  && service.includes("hash_hmac('sha256', $packedOffset . $snapshot, $signingKey, true)")
  && service.includes('class_archive_gateway_timeline_cursor_secret_unavailable')
  && controller.includes("requireExactQuery(['cursor', 'limit'], ['cursor', 'limit'])"),
  'Gateway HTTP timeline must use a bounded server-authenticated snapshot cursor rather than a public epoch as signing key');
check(server.includes('if (length > 1024 * 1024)')
  && server.includes("Buffer.byteLength(body, 'utf8') > 1024 * 1024")
  && server.includes("exactQuery(url, new Set(['cursor', 'limit']))")
  && server.includes('archiveTimelineProjection(\n        timeline,')
  && app.includes('function mergeTimelinePages(current, next)')
  && app.includes("/api/class-archive/timeline?cursor=${encodeURIComponent(requestedCursor)}&limit=${timeline.limit}")
  && app.includes("rootMargin: '900px 0px'"),
  'BFF and owned Photo UI must preserve the 1 MiB relay bound and incrementally load cursor pages');
check(server.includes('classArchiveDate: archiveDateProjection(photo)')
  && server.includes('classArchiveMediaRevision: mediaRevision')
  && server.includes('classArchiveCacheScope:')
  && app.includes('function archivePhotoFromAsset(asset, id, expectedScope)')
  && app.includes('if (index < 0)')
  && app.includes('photos = [archivePhotoFromAsset(asset, id, verifiedScope)]')
  && app.includes("apiJson('/api/class-archive/product-state', { cache: 'no-store' })"),
  'a session-scope-bound point projection must open a deep-linked photo beyond the first timeline page');
check(server.includes('timelineCompatibilityMaximumAssets = 20_000')
  && server.includes('timelineCompatibilityMaximumPages')
  && server.includes('timelineCompatibilityBucketMaximum')
  && server.includes("GatewayResponseError(503, 'timeline_compatibility_budget')")
  && server.includes("GatewayResponseError(503, 'timeline_compatibility_bucket_budget')"),
  'legacy Immich timeline compatibility must have hard cross-page and browser-response budgets');
check(app.includes('payload.cacheScope !== runtime.cacheScope')
  && app.includes('asset?.classArchiveCacheScope !== verifiedScope')
  && server.includes("GatewayResponseError(503, 'timeline_presentation_scope_mismatch')")
  && server.includes("GatewayResponseError(503, 'photo_presentation_scope_mismatch')"),
  'timeline and point projections must reject mixed-account in-flight responses');

check(app.includes('new IntersectionObserver') && app.includes("rootMargin: '700px 0px'"), 'grid must use bounded lazy-load overscan');
check(app.includes("responsivePhotoImage(photo, 'grid'"), 'grid must select a responsive canonical derivative');
check(app.includes('image.srcset = image.dataset.srcset'), 'responsive sources must activate only in the lazy-load window');
check(app.includes("['xsmall', 432, 324]") && app.includes("['preview', 1224, 918]"), 'responsive descriptors must use the canonical Piwigo bounding boxes');
check(app.includes('maxHeight / sourceHeight') && app.includes('outputWidth <= previousWidth'), 'srcset widths must be aspect-aware and strictly increasing');
check(app.includes('prefetchAdjacentPreviews(photos, index)'), 'viewer must preload adjacent previews');
check(!app.includes('/original'), 'background UI work must never prefetch originals');
check(controller.includes('ClassArchiveMediaGuard::authorize'), 'every canonical media reuse must still re-enter MediaGuard');
check(controller.includes("header('X-Accel-Redirect: '"), 'authorized bytes must remain an nginx X-Accel transfer');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photo-cache-contract', assertions, result: 'PASS' })}\n`);
