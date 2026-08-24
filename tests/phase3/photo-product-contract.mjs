import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');

const [schema, controller, gateway, server, app, css, i18n] = await Promise.all([
  read('plugins/ClassIdentity/src/Schema.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayService.php'),
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

check(schema.includes('public const CURRENT_VERSION = 8;'), 'ClassIdentity schema must advance to v8');
check(schema.includes("'name' => '0008_photo_productization_domain'"), 'v8 migration must have a stable ledger name');
for (const table of [
  'person_merge', 'person_photo_rule', 'album', 'spotlight', 'photo_source',
  'photo_duplicate', 'batch_operation', 'batch_operation_item',
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
const targetIndex = controller.indexOf('ClassArchiveMediaGuard::assertDeliveryTarget', resolveIndex);
const authorizeIndex = controller.indexOf('ClassArchiveMediaGuard::authorize', targetIndex);
const accelIndex = controller.indexOf("header('X-Accel-Redirect: '", authorizeIndex);
check(candidateIndex >= 0 && resolveIndex > candidateIndex && targetIndex > resolveIndex && authorizeIndex > targetIndex && accelIndex > authorizeIndex,
  'canonical media must resolve a visible UUID, re-enter MediaGuard, then hand off via X-Accel');
check(!controller.includes('readfile(') && !controller.includes('fpassthru('), 'Gateway must not stream archive bytes in PHP');
check(server.includes('if (upstreamResponse.status === 200 || upstreamResponse.status === 206)')
  && server.includes('Do not silently fall back to user-space media relay'), 'BFF must reject successful media without a validated X-Accel target');

const hybridStart = gateway.indexOf('public function hybridSearch(string $query): array');
const visibleStart = gateway.indexOf('$visible = $this->visiblePhotos();', hybridStart);
const hybridReturn = gateway.indexOf("'people' => ['total' => count($peopleItems)", visibleStart);
check(hybridStart >= 0 && visibleStart > hybridStart && hybridReturn > visibleStart, 'hybrid search counts must derive from policy-filtered photos');
check(gateway.includes("$smart = ['available' => false, 'total' => 0, 'items' => []]")
  && gateway.includes('// Explicit partial degradation: no fallback to a whole library.')
  && gateway.includes("'partial' => ($smart['available'] ?? false) !== true"), 'semantic search failure must degrade independently, report partial results and fail closed');
check(gateway.includes("self::semanticQuery($query)"), 'hybrid search must use the bounded local bilingual normalization hook');
check(gateway.includes("'total' => count($members)") && gateway.includes("'coverPhotoId' => $cover"), 'album counts and covers must be chosen from visible members');
check(controller.includes('DomainSupport::idToBinary($value)') && controller.includes('return strtolower($value)'), 'product detail routes must require normalized opaque UUIDs');

check(app.includes("const MOBILE_NAVIGATION = new Set(['photos', 'people', 'search', 'albums', 'my'])"), 'mobile information architecture must use five tabs');
check(css.includes('grid-template-columns: repeat(5, minmax(0, 1fr))'), 'mobile tab layout must render five equal targets');
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
check(app.includes("apiJson(`/api/class-archive/albums/${id}`)"), 'album detail must use the Class Archive album contract');
check(app.includes("'/api/class-archive/manage/albums/cover'"), 'album covers must use an audited management endpoint');
check(app.includes("{ albumId: album.id, durationHours: 24 }"), 'Spotlight creation must request the fixed 24-hour duration');
check(app.includes("state.canSpotlight && album.owned && album.canSpotlight"), 'Spotlight UI must require role and ownership capabilities');
check(app.includes('const item = payload?.item ?? payload?.spotlight'), 'Spotlight UI must unwrap the active/item API envelope instead of treating the active boolean as a record');
check(app.includes("'/api/class-archive/spotlight/cancel'"), 'Spotlight must expose explicit cancellation');

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
  'spotlight.eyebrow', 'search.structured', 'search.smartBeta', 'my.currentRole',
]) {
  check(i18n.includes(`'${key}'`), `i18n must centralize ${key}`);
}
for (const internalTerm of ['ownerId', 'assetId', 'personId', 'CLIP', 'embedding', 'MediaGuard', 'Piwigo', 'Immich']) {
  check(!i18n.includes(internalTerm), `member-facing i18n must not expose ${internalTerm}`);
}
check(!/\p{Script=Han}/u.test(app), 'member-facing Chinese copy must remain centralized outside application logic');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photo-product-contract', assertions, result: 'PASS' })}\n`);
