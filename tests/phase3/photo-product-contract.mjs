import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');

const [schema, controller, gateway, albumService, privateAlbumAliasScript, server, app, css, i18n] = await Promise.all([
  read('plugins/ClassIdentity/src/Schema.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayService.php'),
  read('plugins/ClassIdentity/src/AlbumService.php'),
  read('infra/scripts/set-private-album-display-alias.php'),
  read('infra/immich-spike/web-compat/server.mjs'),
  read('infra/immich-spike/photo-ui/app.js'),
  read('infra/immich-spike/photo-ui/app.css'),
  read('infra/immich-spike/photo-ui/i18n.js'),
]);

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

check(schema.includes('public const CURRENT_VERSION = 15;'), 'ClassIdentity schema must include the v15 Collections-first domain while preserving v14 native checkpoint recovery, v13 private-library journal, v12 durable native source epoch and v8 productization');
check(schema.includes("'name' => '0008_photo_productization_domain'"), 'v8 migration must have a stable ledger name');
check(schema.includes("'name' => '0012_durable_native_source_epoch'"), 'v12 migration must have a stable ledger name');
check(schema.includes("CREATE TABLE IF NOT EXISTS {$epoch}") && schema.includes(') ENGINE=MyISAM'), 'v12 source epoch sentinel must remain durable in the native MyISAM domain');
check(schema.includes("'source_key', 'generation', 'updated_at'"), 'v12 source epoch sentinel schema must remain fingerprinted');
check(schema.includes("'name' => '0013_private_full_library_import'"), 'v13 private full-library import migration must have a stable ledger name');
check(schema.includes("'name' => '0014_private_full_native_checkpoint_recovery'")
  && schema.includes('migrationPrivateFullNativeCheckpointRecovery'), 'v14 must permit a verified native checkpoint before canonical publication');
check(schema.includes("'name' => '0015_collections_first_comments_ai_index'")
  && schema.includes('migrationCollectionsFirstCommentsAndAiIndex'), 'v15 must have a stable Collections-first migration ledger');
check(schema.includes("'PRIVATE_FULL'"), 'full private-library provenance must remain distinct from disposable PRIVATE_QA');
for (const table of [
  'private_library_collection', 'private_library_folder', 'private_library_import', 'private_library_import_item',
]) {
  check(schema.includes(`$this->quotedTable('${table}')`), `v13 must create ${table}`);
  check(schema.includes(`'${table}' => '`), `v13 must lock the ${table} semantic fingerprint`);
}
for (const table of [
  'person_merge', 'person_photo_rule', 'album', 'spotlight', 'photo_source',
  'photo_duplicate', 'batch_operation', 'batch_operation_item',
  'photo_comment', 'auto_collection', 'auto_collection_photo', 'ai_asset_index', 'ai_index_job',
]) {
  check(schema.includes(`$this->quotedTable('${table}')`), `v8 must create ${table}`);
  check(schema.includes(`'${table}' => '`), `v8 must lock the ${table} semantic fingerprint`);
}
for (const constraint of [
  'uq_ci_person_merge_active_source',
  'chk_ci_person_merge_distinct',
  'chk_ci_album_owner',
  'uq_ci_spotlight_active_owner',
  'chk_ci_spotlight_duration',
  'uq_ci_photo_source_provenance',
  'uq_ci_photo_duplicate_active_alias',
  'chk_ci_photo_duplicate_canonical',
  'chk_ci_batch_counts',
  'uq_ci_batch_item_photo',
]) {
  check(schema.includes(constraint), `v8 must preserve constraint ${constraint}`);
}
check(schema.includes("`era` IN ('HERITAGE', 'LIVING', 'MIXED')"), 'album era metadata must support mixed albums without becoming ACL');
check(schema.includes("`relation_kind` IN ('EXACT', 'NEAR')"), 'dedupe must distinguish exact and near candidates');
check(schema.includes("`state` IN ('PREPARED', 'APPLIED', 'FAILED', 'COMPENSATED', 'MANUAL_REVIEW')"), 'bulk journal must expose crash/manual-review states');

const readRoutes = [
  ['/api/class-archive/product-state', '/api/product-state'],
  ['/api/class-archive/albums', '/api/albums'],
  ['/api/class-archive/home', '/api/home'],
  ['/api/class-archive/spotlight', '/api/spotlight'],
  ['/api/class-archive/manage/people', '/api/manage/people'],
  ['/api/class-archive/manage/options', '/api/manage/options'],
  ['/api/class-archive/manage/duplicates', '/api/manage/duplicates'],
];
for (const [publicPath, gatewayPath] of readRoutes) {
  check(server.includes(`['${publicPath}', '${gatewayPath}']`), `BFF must explicitly map ${publicPath}`);
}
const mutationRoutes = [
  '/api/class-archive/manage/people/update',
  '/api/class-archive/manage/people/merge',
  '/api/class-archive/manage/people/move-photos',
  '/api/class-archive/manage/archive/bulk',
  '/api/class-archive/manage/albums/cover',
  '/api/class-archive/manage/duplicates/consolidate',
  '/api/class-archive/spotlight/create',
  '/api/class-archive/spotlight/cancel',
  '/api/class-archive/comments/create',
  '/api/class-archive/comments/reply',
  '/api/class-archive/manage/comments/delete',
];
for (const path of mutationRoutes) {
  check(server.includes(`['${path}', `), `BFF must explicitly allowlist mutation ${path}`);
  check(app.includes(`'${path}'`), `Photo UI must use the bounded mutation ${path}`);
}
check(server.includes("if (url.pathname === '/people/manage' && documentRole !== 'SYSTEM_ADMIN')"), 'BFF must return server-side 403 for non-admin people management');
check(server.includes("respond(response, request.method, 403, 'text/plain; charset=utf-8', '禁止访问'"), 'people management denial must be an actual 403');
check(server.includes('Number(contentLength) > 64 * 1024') && server.includes('length > 64 * 1024'), 'BFF must bound declared and streamed mutation bodies');
check(server.includes("contentType.split(';', 1)[0].trim().toLowerCase() !== 'application/json'"), 'BFF mutations must require JSON');
check(server.includes("'X-Class-Archive-Web-Compat-Internal': '1'"), 'BFF must mark only its fixed internal Gateway relay');
check(!server.includes("url.pathname.replace('/api/class-archive'"), 'BFF must not implement a generic Class Archive proxy');

const candidateIndex = controller.indexOf('$candidate = $gateway->mediaCandidate($classPhotoId)');
const resolveIndex = controller.indexOf('ClassArchiveMediaGuard::resolveCanonicalDelivery', candidateIndex);
const authorizeIndex = controller.indexOf('ClassArchiveMediaGuard::authorize', resolveIndex);
const targetIndex = controller.indexOf('ClassArchiveMediaGuard::assertDeliveryTarget', authorizeIndex);
const accelIndex = controller.indexOf("header('X-Accel-Redirect: '", targetIndex);
check(candidateIndex >= 0 && resolveIndex > candidateIndex && authorizeIndex > resolveIndex && targetIndex > authorizeIndex && accelIndex > targetIndex,
  'canonical media must resolve a visible UUID, authorize before cache probing, require a ready target, then hand off via X-Accel');
check(controller.includes('catch (\\ClassArchiveMediaUnavailable)') && controller.includes('self::respondMediaDeny(503)'),
  'authorized canonical cache misses must fail closed without entering a generator');
check(!controller.includes('readfile(') && !controller.includes('fpassthru('), 'Gateway must not stream archive bytes in PHP');
check(server.includes('if (upstreamResponse.status === 200 || upstreamResponse.status === 206)')
  && server.includes('Do not silently fall back to user-space media relay'), 'BFF must reject successful media without a validated X-Accel target');

const hybridStart = gateway.indexOf('public function hybridSearch(string $query, ?string $albumId = null): array');
const visibleStart = gateway.indexOf('$visible = $this->visiblePhotos();', hybridStart);
const hybridReturn = gateway.indexOf("'people' => ['total' => count($peopleItems)", visibleStart);
check(hybridStart >= 0 && visibleStart > hybridStart && hybridReturn > visibleStart, 'hybrid search counts must derive from policy-filtered photos');
check(gateway.includes("$smart = ['available' => false, 'total' => 0, 'items' => []]")
  && gateway.includes('// Explicit partial degradation: no fallback to a whole library.')
  && gateway.includes("'partial' => ($smart['available'] ?? false) !== true"), 'semantic search failure must degrade independently, report partial results and fail closed');
check(gateway.includes("self::semanticQuery($query)"), 'hybrid search must use the bounded local bilingual normalization hook');
check(gateway.includes("'total' => count($directMembers)") && gateway.includes("'coverPhotoId' => $cover"), 'leaf-album counts and covers must be chosen from policy-visible direct members');
check(gateway.includes("'sourceRoot' => ($mapping['source_root'] ?? false) === true"), 'a private source-root hint must stay presentation-only and policy-neutral');
check(albumService.includes('privateSourceContextsByAlbum') && albumService.includes("'source_root' => $row['parent_folder_id'] === null"), 'full-library source contexts must be derived from the private folder hierarchy rather than a client path');
check(albumService.includes('setDisplayAlias') && albumService.includes('ALBUM_DISPLAY_ALIAS_UPDATE'), 'a display alias must be a generic audited presentation override, not a source-folder rename');
check(privateAlbumAliasScript.includes("['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B']")
  && privateAlbumAliasScript.includes('private_album_alias_root_mapping_invalid')
  && privateAlbumAliasScript.includes("`depth`=0")
  && privateAlbumAliasScript.includes("f.`display_name`=?")
  && privateAlbumAliasScript.includes('setDisplayAlias')
  && !privateAlbumAliasScript.includes('<private-drive-root>/\\'), 'the private alias applicator must target one allowlisted source root without embedding a workstation path');
check(gateway.includes('if ($directMembers === [])') && gateway.includes("'directTotal' => count($directMembers)"), 'pure folder containers must be excluded while direct-photo leaf albums retain their own membership');
check(gateway.includes('public function searchSuggestions(string $query = \'\', ?string $albumId = null): array')
  && gateway.includes('self::boundedSuggestions($people)')
  && gateway.includes('same policy/optional-leaf-album filter used by hybrid search'), 'suggestions must be bounded structured projections over policy-filtered photos');
check(controller.includes("$segments === ['search', 'suggestions']")
  && controller.includes("requireExactQuery(['q', 'albumId'], ['q', 'albumId'])"), 'search suggestions must accept only bounded optional query and album scope');
check(controller.includes("str_contains($code, '_comment_write_forbidden')"), 'family comment-write denials must be an actual 403, never a generic 503');
check(controller.includes('DomainSupport::idToBinary($value)') && controller.includes('return strtolower($value)'), 'product detail routes must require normalized opaque UUIDs');

check(app.includes("const MOBILE_NAVIGATION = new Set(['home', 'photos', 'people', 'search', 'albums', 'my'])"), 'mobile information architecture must include the Collections-first home tab');
check(css.includes('grid-template-columns: repeat(6, minmax(0, 1fr))'), 'mobile tab layout must render six equal targets');
check(css.includes('env(safe-area-inset-bottom)') && css.includes('min-height: 52px'), 'mobile tabs must honor safe area and accessible hit size');
check(app.includes("credentials: 'same-origin'") && app.includes("'X-Class-Archive-CSRF': state.csrfToken"), 'mutations must use same-origin sessions and CSRF');
check(app.includes('body: JSON.stringify({ ...payload, csrfToken: state.csrfToken })'), 'CSRF must be relayed in the validated mutation body');
check(app.includes('event.ctrlKey || event.metaKey || event.shiftKey || longPressed'), 'desktop modifier selection and mobile long press must share the selection controller');
check(app.includes('setTimeout(() => {') && app.includes('}, 520);'), 'mobile selection must use an intentional long-press threshold');
check(app.includes('this.toggle(index, event.shiftKey)'), 'Shift selection must support ranges');
for (const field of ['archiveDate', 'datePrecision', 'eventId', 'albumAddIds', 'albumRemoveIds', 'era', 'eraConfirmed']) {
  check(app.includes(`${field}:`), `bulk archive request must include ${field}`);
}
check(app.includes("eraConfirmInput.focus()") && app.includes("t('photos.bulkEraConfirm')"), 'high-risk era changes must require an explicit confirmation');

for (const key of [
  'people.displayName', 'people.identityLink', 'people.hidden', 'people.cover',
  'people.merge', 'people.correctTitle', 'people.moveTo', 'people.removeFromPerson',
]) {
  check(app.includes(`'${key}'`) || i18n.includes(`'${key}'`), `person curation must expose ${key}`);
}
check(app.includes("apiJson('/api/class-archive/manage/people')"), 'people management must load an admin-only projection');
check(app.includes("sourcePersonIds: [...selected].filter") && app.includes('targetPersonId: target.value'), 'person merge UI must submit source and target projections');
check(app.includes('sourcePersonId: person.id') && app.includes('photoIds,'), 'person correction must submit explicit selected photos');
check(app.includes("group.exact ? t('duplicates.exact') : t('duplicates.near')") && app.includes('if (!group.exact) return card;'), 'near duplicates must remain review-only in the UI');

check(app.includes('card.href = `/albums/${album.id}`'), 'album cards must navigate by stable opaque album id');
check(app.includes('const leafAlbums = albums.filter((album) => album.directCount > 0)')
  && !app.includes('function sourceCollectionPresentation(albums, hierarchy)')
  && !app.includes("'album-children'"), 'the album landing page must suppress source containers while keeping only direct-photo leaf cards');
check(app.includes('function albumDisplayName(album)') && app.includes('album.displayAlias || album.name')
  && app.includes('sourceLabel: safeText(album.sourceLabel'), 'album aliases and source subtitles must remain a presentation layer over durable provenance');
check(app.includes("apiJson(`/api/class-archive/albums/${id.toLowerCase()}?${params}`)"), 'album detail must use the Class Archive album contract with bounded cursor pagination');
check(app.includes('window.scrollTo(0, 0);') && app.includes('newly opened Search'), 'top-level photo routes must reset the viewport after their rendered content settles');
check(server.includes("const query = exactQuery(url, new Set(['cursor', 'limit']));")
  && server.includes('class_archive_web_compat_album_cursor_invalid')
  && server.includes('class_archive_web_compat_album_limit_invalid')
  && server.includes("const hasCursor = query.has('cursor');")
  && server.includes("const hasLimit = query.has('limit');")
  && server.includes('if (hasCursor && !timelineCursorPattern.test(cursor))')
  && server.includes('if (hasLimit && (!/^[1-9][0-9]{0,2}$/.test(limit) || Number(limit) > 240))')
  && server.includes("if (hasCursor) params.set('cursor', cursor);")
  && server.includes("if (hasLimit) params.set('limit', limit);")
  && server.includes('`/api/albums/${assertUuid(albumMatch[1])}${suffix}`'), 'BFF album detail must relay only bounded canonical cursor pagination');
check(app.includes("'/api/class-archive/manage/albums/cover'"), 'album covers must use an audited management endpoint');
check(app.includes("{ albumId: album.id, durationHours: 24 }"), 'Spotlight creation must request the fixed 24-hour duration');
check(app.includes("state.canSpotlight && album.owned && album.canSpotlight"), 'Spotlight UI must require role and ownership capabilities');
check(app.includes('const item = payload?.item ?? payload?.spotlight'), 'Spotlight UI must unwrap the active/item API envelope instead of treating the active boolean as a record');
check(app.includes("'/api/class-archive/spotlight/cancel'"), 'Spotlight must expose explicit cancellation');

const homeStart = app.indexOf('async function renderHome()');
const homeEnd = app.indexOf('async function loadAlbums()', homeStart);
const home = app.slice(homeStart, homeEnd);
check(homeStart >= 0 && homeEnd > homeStart && home.includes("presentationJson('/api/class-archive/home')"), 'home must use an explicit compact Gateway projection');
check(!home.includes("presentationJson('/api/class-archive/timeline')") && !home.includes('photoGrid('), 'home must not fetch or render the full library timeline');
for (const selector of ['home-featured', 'home-memory-row', 'home-album-row', 'home-people-row', 'homeAllPhotos']) {
  check(home.includes(selector), `home must retain stable Collections-first selector ${selector}`);
}
check(server.includes("response.setHeader('Location', '/home')"), 'authenticated BFF root must default to Collections-first home');
check(server.includes('function photoUiDocumentQueryAllowed(url)') && server.includes("url.searchParams.has('album')"), 'only the explicit current-album search context may add a product document query parameter');

for (const path of [
  '/api/class-archive/comments/create',
  '/api/class-archive/comments/reply',
  '/api/class-archive/manage/comments/delete',
]) {
  check(app.includes(`'${path}'`) && server.includes(`['${path}', `), `comments must use explicit bounded BFF mutation ${path}`);
}
check(app.includes('function normalizeComments(payload)') && app.includes('function viewerComments(photoId, role, comments, onRefresh, onLoadMore)'), 'comments must be normalized before rendering a viewer panel');
check(app.includes("new URLSearchParams({ limit: '100' })") && app.includes('comments.hasMore')
  && server.includes("exactQuery(url, new Set(['cursor', 'limit']))"), 'comment reads must remain bounded and keyset-paginated across UI and BFF');
check(app.includes("if (canCreateComment(role)) section.append(commentComposer")
  && app.includes("else if (role === 'FAMILY')"), 'Family comment UI must stay read-only while eligible principals can comment');
check(app.includes("role === 'SYSTEM_ADMIN' && item.canDelete")
  && server.includes("'/api/class-archive/manage/comments/delete' && role !== 'SYSTEM_ADMIN'"), 'comment deletion must have both UI and BFF system-admin controls');
check(app.includes("const title = context.album || t('viewer.photoContext')") && !app.includes('businessLabel(asset?.originalFileName'), 'viewer primary context must not expose original file names');
check(app.includes('viewerPhotoInfo(photo, context)') && css.includes('.viewer-comments') && css.includes('.viewer-photo-info'), 'viewer must render comments first and keep photo details collapsible');

const structuredIndex = app.indexOf('if (exactCount > 0)');
const smartIndex = app.indexOf('if (response.smartPhotos.length > 0)', structuredIndex);
check(structuredIndex >= 0 && smartIndex > structuredIndex, 'search must render structured results before semantic Beta results');
check(app.includes("if (error?.status && error.status !== 404) throw error;"), 'search must not hide unsafe Gateway failures behind a legacy fallback');
check(i18n.includes("'search.smartBeta': '测试中'"), 'semantic matching must be labelled as Beta in natural Chinese');
check(i18n.includes("'search.structured': '精确匹配'"), 'structured search must have a clear Chinese section');
check(app.includes("href: validId(memory.album_id ?? memory.albumId)"), 'memories must open the stable album projection when available');
check(app.includes("if (state.role === 'CLASSMATE')") && app.includes("if (state.role === 'FAMILY')"), 'My must expose role-specific member actions');
check(app.includes("if (state.canManage)") && app.includes("'/people/manage'"), 'My must expose management only through server-projected capability');

for (const key of [
  'photos.bulkTitle', 'people.manageTitle', 'albums.official', 'albums.community',
  'albums.sourceCollections', 'albums.sourceCollectionsLead',
  'home.title', 'home.featured', 'comments.title', 'comments.familyReadonly',
  'spotlight.eyebrow', 'search.structured', 'search.smartBeta', 'my.currentRole',
]) {
  check(i18n.includes(`'${key}'`), `i18n must centralize ${key}`);
}
for (const internalTerm of ['ownerId', 'assetId', 'personId', 'CLIP', 'embedding', 'MediaGuard', 'Piwigo', 'Immich']) {
  check(!i18n.includes(internalTerm), `member-facing i18n must not expose ${internalTerm}`);
}
check(!/\p{Script=Han}/u.test(app), 'member-facing Chinese copy must remain centralized outside application logic');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photo-product-contract', assertions, result: 'PASS' })}\n`);
