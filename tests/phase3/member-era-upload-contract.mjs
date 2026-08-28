import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Public-safe static and synthetic contract suite for the bounded member
// publish path. It opens no database, media tree, private QA state, network,
// or browser. Runtime upload coverage remains an explicit later gate.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');
const paths = Object.freeze({
  service: 'plugins/ClassIdentity/src/MemberEraUploadService.php',
  publicController: 'plugins/ClassIdentity/public.php',
  familyTemplate: 'plugins/ClassIdentity/template/public/my-identity.tpl',
  familySubmissionService: 'plugins/ClassIdentity/src/SubmissionService.php',
  album: 'plugins/ClassIdentity/src/AlbumService.php',
  gateway: 'plugins/ClassIdentity/src/Gateway/GatewayService.php',
  controller: 'plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
  projectionStore: 'plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php',
  reconciliation: 'plugins/ClassIdentity/src/ReconciliationService.php',
  main: 'plugins/ClassIdentity/main.inc.php',
  installer: 'infra/scripts/install-class-archive-plugins.php',
  bff: 'infra/immich-spike/web-compat/server.mjs',
  nginx: 'infra/piwigo-nginx/nginx.conf',
  compose: 'infra/docker-compose.yml',
});
const source = Object.fromEntries(await Promise.all(Object.entries(paths).map(async ([name, path]) => [name, await read(path)])));

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

function section(text, start, end) {
  const first = text.indexOf(start);
  if (first < 0) return '';
  const last = text.indexOf(end, first + start.length);
  return last < 0 ? text.slice(first) : text.slice(first, last);
}

const service = source.service;
const publish = section(service, 'public function publish(', '    /** @param array<string,mixed> $context */\n    private function assertActiveMemberContext');
check(service.includes('final class MemberEraUploadService'), 'member_upload_service_missing');
check(source.main.includes("'src/MemberEraUploadService.php'"), 'member_upload_service_not_bootstrapped');
check(source.installer.includes("'src/MemberEraUploadService.php'"), 'member_upload_service_not_installed');
check(service.includes('private const MEMBER_ROLES = [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER];'), 'only_classmate_teacher_may_direct_publish');
check(service.includes("in_array($era, ['HERITAGE', 'LIVING'], true)"), 'explicit_heritage_living_only');
check(!/\bexif_[a-z_]+\s*\(/i.test(service), 'era_upload_must_not_infer_from_exif');
check(publish.includes('DomainSupport::requireMemberRole($userId, self::MEMBER_ROLES)')
  && publish.includes('$this->assertActiveMemberContext($context, $userId)')
  && publish.includes('self::requireExplicitEra($era)')
  && publish.includes('$this->requireOfficialEraAlbum($classAlbumId, $era)'), 'active_role_era_album_preconditions_missing');
check(service.includes("($row['album_type'] ?? null) !== 'OFFICIAL'")
  && service.includes("($row['state'] ?? null) !== 'ACTIVE'")
  && service.includes("($row['era'] ?? null) !== $era")
  && service.includes('DomainSupport::idToBinary($classAlbumId)'), 'album_uuid_or_era_compatibility_missing');
check(service.includes("'class-archive-heritage'") && service.includes("'class-archive-living'"), 'era_root_membership_check_missing');
check(service.includes('MAX_BYTES = 20 * 1024 * 1024')
  && service.includes("'image/jpeg' => 'jpg'")
  && service.includes("'image/png' => 'png'")
  && service.includes("'image/webp' => 'webp'"), 'file_size_or_mime_allowlist_missing');
check(service.includes('is_uploaded_file($temporary)')
  && service.includes('finfo_file($finfo, $temporary)')
  && service.includes('@getimagesize($temporary)')
  && service.includes("str_contains($name, '/')")
  && service.includes("str_contains($name, '\\\\')")
  && service.includes('member_era_upload_filename_invalid'), 'server_file_validation_missing');
check(service.includes("foreach (['error', 'tmp_name', 'size', 'name'] as $field)")
  && service.includes('is_array($file[$field] ?? null)'), 'multiple_file_shape_must_be_rejected');
check(service.includes('bin2hex(random_bytes(20))')
  && service.includes('return add_uploaded_file($temporary, $safeName, null, self::QUARANTINE_LEVEL);'), 'opaque_storage_name_or_piwigo_pipeline_missing');
check(publish.includes('associate_images_to_categories([$imageId], [(int) $album[\'piwigo_category_id\']])')
  && publish.includes('$this->assertExactAlbumAssociation($imageId, (int) $album[\'piwigo_category_id\'])'), 'exact_single_album_association_missing');
check(service.includes('ClassArchiveMediaFilePolicy')
  && service.includes("($mode & 0777) !== 0660")
  && service.includes("str_starts_with($reference, 'upload/')"), 'managed_media_policy_or_0660_check_missing');
check(publish.includes('ClassArchivePhotoMappingService($repository))->ensurePiwigoMapping')
  && publish.includes("'action' => 'MEMBER_ERA_UPLOAD_PUBLISH'")
  && publish.includes("'date_precision' => 'UNKNOWN'")
  && publish.includes('$this->releasePublishedVisibility($imageId)'), 'canonical_mapping_audit_or_visibility_saga_missing');
check(service.includes('quarantineFailedCoreWrite')
  && service.includes("'MEMBER_ERA_UPLOAD_ABORT'")
  && service.includes("'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=?'")
  && !/(?:^|\n)\s*delete_elements\s*\(/m.test(service), 'safe_targeted_compensation_missing');
check(service.includes('withChecksumLock($checksum')
  && service.includes('findActiveCanonicalByChecksum($sourceChecksum, $era)')
  && service.includes('associateExistingCanonical(')
  && service.includes('assertNoUnmappedPiwigoMd5Duplicate($sourceMd5)')
  && service.includes("$conf['upload_detect_duplicate'] = false")
  && service.includes('assertFreshQuarantinedImage($imageId, $safeName)')
  && service.includes('$createdByThisRequest && $imageId !== null'), 'checksum_dedup_or_existing_core_row_hijack_guard_missing');
check(publish.includes('ClassArchiveDerivativeWarmupQueue::enqueueBestEffort')
  && publish.includes('AiIndexService($this->repository))->enqueueNewPhoto')
  && service.includes('rebuildPhotoProjectionIfRequired(')
  && service.includes('Gateway\\ReadProjectionBuilder::rebuildChangedPhotos(')
  && service.includes('ProjectionMutationBoundary::invalidatePhotos(')
  && service.includes('foreach ($store->status() as $status)')
  && service.includes("($status['kind'] ?? null) === Gateway\\ReadProjectionStore::PHOTO_CATALOG")
  && !service.includes('sourcePhotoCandidatesForRebuild('), 'incremental_derivative_ai_or_projection_missing');
check(source.projectionStore.includes('or atomically appends new canonical rows to the')
  && source.projectionStore.includes('class_archive_read_projection_refresh_append_failed')
  && source.projectionStore.includes('`item_count`=?,`invalidated_reason`=NULL')
  && source.projectionStore.includes('WHERE `generation`=? AND `piwigo_image_id` IN ('), 'bounded_append_projection_contract_missing');
check(!service.includes('Community'), 'community_upload_must_not_be_reused');
check(source.reconciliation.includes('MEMBER_UPLOAD_QUARANTINED_REQUIRES_REVIEW')
  && source.reconciliation.includes('MEMBER_ERA_UPLOAD_ABORT')
  && source.reconciliation.includes('MANUAL_REVIEW'), 'quarantined_saga_reconciliation_missing');

const publicController = source.publicController;
const familySubmission = section(publicController, 'private static function handleFamilySubmission(): void', '    private static function handleMemberEraUploadBridge(): never');
check(source.familyTemplate.includes('name="era" value="HERITAGE"'), 'family_submission_form_must_declare_heritage_only');
check(familySubmission.includes("$era = self::postOptional('era', 16);")
  && familySubmission.includes("if ($era !== 'HERITAGE')")
  && familySubmission.includes("family_submission_era_invalid"), 'family_submission_must_fail_closed_on_living_or_unknown_era');
check(familySubmission.includes("unset($_POST['era']")
  && familySubmission.includes("self::clearPostedFields(['era'"), 'family_submission_era_must_be_cleared_after_handling');
check(familySubmission.includes("ClassIdentitySubmissionService::fromPiwigo()->submit(")
  && familySubmission.includes("self::currentUserId(),\n                $era,"), 'family_submission_controller_must_pass_explicit_era_to_domain_service');
const familySubmissionService = source.familySubmissionService;
const familySubmit = section(familySubmissionService, 'public function submit(', '    /** @return list<array<string, mixed>> */');
const familyEraGuard = familySubmit.indexOf("if ($era !== 'HERITAGE')");
check(familySubmit.includes('public function submit(int $userId, string $era, array $file')
  && familyEraGuard >= 0
  && familyEraGuard < familySubmit.indexOf('$this->validateUpload($file)')
  && familyEraGuard < familySubmit.indexOf('$this->ensurePendingRoot()')
  && familyEraGuard < familySubmit.indexOf('move_uploaded_file($tmp, $storagePath)'), 'family_submission_service_era_guard_must_precede_any_file_or_db_write');
check(familySubmit.includes("'era' => 'HERITAGE'"), 'family_submission_audit_must_record_fixed_heritage_scope');
const publicBridge = section(publicController, 'private static function handleMemberEraUploadBridge()', '    /** @return array<string, mixed> */\n    private static function loadMyIdentity');
check(publicController.includes("private const ROUTE_MEMBER_UPLOAD = 'member-upload';")
  && publicController.includes('self::handleMemberEraUploadBridge();'), 'fixed_private_php_route_missing');
check(publicBridge.includes("($_SERVER['CLASS_ARCHIVE_WEB_COMPAT_INTERNAL'] ?? '') !== '1'")
  && publicBridge.includes('ClassIdentityHttp::requireMutation()')
  && publicBridge.includes("$_POST['pwg_token']")
  && publicBridge.includes("$_SERVER['HTTP_X_CLASS_ARCHIVE_CSRF']")
  && publicBridge.includes('hash_equals($bodyToken, $headerToken)'), 'php_session_or_csrf_recheck_missing');
check(publicBridge.includes("$action !== 'publish_member_photo'")
  && publicBridge.includes("self::postPlain('era', 16)")
  && publicBridge.includes("self::postPlain('album_id', 64)")
  && publicBridge.includes("$_FILES['member_photo']"), 'fixed_multipart_form_contract_missing');
check(publicBridge.includes("'photoId' => $published['class_photo_id']")
  && !publicBridge.includes("'piwigo_image_id' =>")
  && !publicBridge.includes("'media_reference' =>"), 'browser_response_must_not_expose_internal_media_identifiers');

const controller = source.controller;
check(controller.includes("'canEraUpload' => in_array($role, [\\ClassIdentity\\Access::ROLE_CLASSMATE, \\ClassIdentity\\Access::ROLE_TEACHER], true)")
  && controller.includes("'canFamilySubmission' => $role === \\ClassIdentity\\Access::ROLE_FAMILY"), 'role_scoped_upload_flags_missing');
check(controller.includes("$segments === ['member-upload', 'options']")
  && controller.includes('self::requireExactQuery([])')
  && controller.includes('return $gateway->memberEraUploadOptions();'), 'fixed_options_endpoint_missing');
check(source.gateway.includes('public function memberEraUploadOptions()')
  && source.album.includes('public function memberEraUploadOptions(int $userId): array')
  && source.album.includes("'id' => strtolower(DomainSupport::binaryToId")
  && source.album.includes("'label' =>")
  && source.album.includes("'subtitle' =>"), 'safe_album_option_projection_missing');
check(!source.album.slice(source.album.indexOf('public function memberEraUploadOptions'), source.album.indexOf('public function memberEraUploadOptions') + 8000).includes("'piwigo_category_id' =>"), 'options_must_not_expose_piwigo_category_id');

const bff = source.bff;
const bffUpload = section(bff, 'function memberEraUploadRequestContract(', 'async function principal(');
const bffRelay = section(bff, 'async function relayMemberEraUpload(', 'async function principal(');
check(bff.includes("const memberEraUploadPublicPath = '/api/class-archive/member-upload';")
  && bff.includes("const memberEraUploadInternalPath = '/member-upload';")
  && bff.includes("const memberEraUploadRoles = new Set(['CLASSMATE', 'TEACHER']);"), 'fixed_bff_upload_paths_or_roles_missing');
check(bff.includes("['/api/class-archive/member-upload/options', '/api/member-upload/options']"), 'fixed_bff_options_allowlist_missing');
check(bffUpload.includes("request.headers.origin !== publicOrigin")
  && bffUpload.includes("request.headers['sec-fetch-site'] !== 'same-origin'")
  && bffUpload.includes("request.headers['transfer-encoding'] !== undefined")
  && bffUpload.includes('memberEraUploadMaxRequestBytes')
  && bffUpload.includes('equalMemberUploadCsrf(csrf, expected)'), 'bff_same_origin_body_bound_or_csrf_gate_missing');
check(bffRelay.includes('createHttpRequest(upstreamUrl')
  && bffRelay.includes('new URL(memberEraUploadInternalPath, gatewayOrigin)')
  && bffRelay.includes('pipeline(request, limiter, upstream)')
  && !bffRelay.includes('await fetch(')
  && !bffRelay.includes('.formData('), 'bff_must_stream_fixed_target_without_form_buffering');
check(bffUpload.includes("'X-Class-Archive-Web-Compat-Internal': '1'")
  && bffUpload.includes("'X-Forwarded-For': clientAddress")
  && bffUpload.includes('browser Host/Origin/X-Forwarded-* never cross this hop'), 'bff_must_reconstruct_not_forward_trusted_headers');
check(bffUpload.includes('memberEraUploadMaxResponseBytes')
  && bffUpload.includes('memberEraUploadSuccessProjection')
  && bffUpload.includes("decoded.state !== 'PUBLISHED'"), 'bff_control_response_must_be_bounded_and_whitelisted');

const nginx = source.nginx;
const outer = section(nginx, 'server {\n        listen 8081;', '    # The BFF\'s only Piwigo-facing listener.');
const outerUpload = section(outer, 'location = /api/class-archive/member-upload {', '        location / {');
const inner = section(nginx, 'server {\n        listen 8088;', '    # Direct access to Piwigo');
const innerUpload = section(inner, 'location = /member-upload {', '        location = /api {');
check(outerUpload.includes('client_max_body_size 21m;')
  && outerUpload.includes('proxy_request_buffering off;')
  && outerUpload.includes('proxy_pass http://$class_archive_web_compat_upstream$request_uri;'), 'outer_exact_upload_route_must_stream_to_bff');
check(inner.includes('client_max_body_size 8k;')
  && nginx.includes('map $realip_remote_addr $class_archive_web_compat_bff_source {')
  && nginx.includes('172.23.0.10 1;')
  && inner.includes('if ($class_archive_web_compat_bff_source != 1)')
  && inner.includes('if ($http_x_class_archive_web_compat_internal != "1")')
  && innerUpload.includes('client_max_body_size 21m;')
  && innerUpload.includes('fastcgi_request_buffering off;')
  && innerUpload.includes('fastcgi_buffering off;'), 'inner_upload_listener_or_streaming_gate_missing');
check(!/location = \/member-upload \{[\s\S]*?\n\s*internal;/m.test(innerUpload)
  && !innerUpload.includes('proxy_pass')
  && innerUpload.includes('fastcgi_param CLASS_ARCHIVE_WEB_COMPAT_INTERNAL 1;'), 'bff_post_target_must_not_be_nginx_internal_or_generic_proxy');
check(innerUpload.includes('fastcgi_param HTTP_HOST piwigo;')
  && innerUpload.includes('fastcgi_param HTTP_ORIGIN "";')
  && innerUpload.includes('fastcgi_param HTTP_X_FORWARDED_FOR "";')
  && innerUpload.includes('fastcgi_param HTTP_X_REAL_IP "";'), 'inner_fastcgi_must_overwrite_spoofable_headers');
check(source.compose.includes('- "8088"')
  && !source.compose.includes('127.0.0.1:${CLASS_ARCHIVE_GATEWAY_HTTP_PORT')
  && !source.compose.includes(':8088"'), 'inner_listener_must_not_be_host_published');
check(nginx.includes('location ^~ /_class_archive_internal/source/upload/ {')
  && nginx.includes('location ^~ /_class_archive_internal/derivative/ {'), 'existing_x_accel_media_locations_must_remain_present');

// Synthetic policy matrix: this is intentionally UI-neutral and spells out
// the fixed product-state contract before a browser dialog is wired in.
const syntheticRoles = Object.freeze([
  ['CLASSMATE', true, false],
  ['TEACHER', true, false],
  ['FAMILY', false, true],
  ['ANONYMOUS', false, false],
  ['SYSTEM_ADMIN', false, false],
]);
for (const [role, canEraUpload, canFamilySubmission] of syntheticRoles) {
  check(memberEraUploadRoles(role) === canEraUpload, `synthetic_direct_publish_role_${role}`);
  check(memberFamilySubmission(role) === canFamilySubmission, `synthetic_family_flow_role_${role}`);
}
function memberEraUploadRoles(role) {
  return role === 'CLASSMATE' || role === 'TEACHER';
}
function memberFamilySubmission(role) {
  return role === 'FAMILY';
}

process.stdout.write(`${JSON.stringify({ suite: 'phase3-member-era-upload-contract', assertions, result: 'PASS' })}\n`);
