#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const roles = Object.freeze([
  'GUEST',
  'CLASSMATE',
  'TEACHER',
  'FAMILY',
  'ANONYMOUS',
  'ARCHIVIST',
  'SYSTEM_ADMIN',
]);
const authenticatedRoles = Object.freeze(['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS', 'SYSTEM_ADMIN']);

const sources = Object.freeze({
  gateway: 'plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
  gatewayService: 'plugins/ClassIdentity/src/Gateway/GatewayService.php',
  gatewayPolicy: 'plugins/ClassIdentity/src/Gateway/GatewayPolicy.php',
  webCompat: 'infra/immich-spike/web-compat/server.mjs',
  publicIdentity: 'plugins/ClassIdentity/public.php',
  admin: 'plugins/ClassIdentity/admin.php',
  access: 'plugins/ClassIdentity/src/Access.php',
  capabilityGuard: 'plugins/ClassIdentity/src/CapabilityGuard.php',
  comments: 'plugins/ClassIdentity/src/PhotoCommentService.php',
  spotlight: 'plugins/ClassIdentity/src/SpotlightService.php',
  albums: 'plugins/ClassIdentity/src/AlbumService.php',
  submissions: 'plugins/ClassIdentity/src/SubmissionService.php',
});
const sourceText = Object.fromEntries(Object.entries(sources).map(([key, relative]) => [
  key,
  fs.readFileSync(path.join(root, relative), 'utf8'),
]));

function fail(message) {
  throw new Error(`private_role_capability_inventory:${message}`);
}

function exactSet(actual, expected, label) {
  const left = [...new Set(actual)].sort();
  const right = [...new Set(expected)].sort();
  if (JSON.stringify(left) !== JSON.stringify(right)) {
    fail(`${label}_source_drift expected=${JSON.stringify(right)} actual=${JSON.stringify(left)}`);
  }
}

function exactMultiset(actual, expected, label) {
  const left = [...actual].sort();
  const right = [...expected].sort();
  if (JSON.stringify(left) !== JSON.stringify(right)) {
    fail(`${label}_source_drift expected=${JSON.stringify(right)} actual=${JSON.stringify(left)}`);
  }
}

function exactPairs(actual, expected, label) {
  const normalize = (pairs) => pairs
    .map(([publicPath, internalPath]) => `${publicPath}\u0000${internalPath}`)
    .sort();
  const left = normalize(actual);
  const right = normalize(expected);
  if (JSON.stringify(left) !== JSON.stringify(right)) {
    fail(`${label}_source_drift expected=${JSON.stringify(right)} actual=${JSON.stringify(left)}`);
  }
}

function phpMethodBody(text, name) {
  const pattern = new RegExp(`private static function ${name}\\([^]*?\\n    }\\n\\n    /\\*\\*`, 'm');
  return text.match(pattern)?.[0] ?? fail(`php_method_missing:${name}`);
}

function parsePhpLiteralSegmentRoutes(text) {
  return [...text.matchAll(/\$segments === \[([^\]]*)\]/g)].map((match) => {
    const segments = [...match[1].matchAll(/'([^']+)'/g)].map((token) => token[1]);
    if (segments.length === 0) fail('php_literal_segment_route_invalid');
    return segments.join('/');
  });
}

function parseMapEntries(text, name) {
  const body = text.match(new RegExp(`${name}\\s*=\\s*new Map\\(\\[([\\s\\S]*?)\\n\\]\\);`))?.[1]
    ?? fail(`${name}_missing`);
  return [...body.matchAll(/\['([^']+)'\s*,\s*'([^']+)'\]/g)].map((match) => [match[1], match[2]]);
}

const gatewayMutationRoutes = Object.freeze([
  'manage/people/create',
  'manage/people/update',
  'manage/people/merge',
  'manage/people/visibility',
  'manage/people/revert-merge',
  'manage/people/move-photos',
  'manage/archive/bulk',
  'manage/albums/cover',
  'manage/duplicates/consolidate',
  'spotlight/create',
  'spotlight/cancel',
  'comments/create',
  'comments/reply',
  'manage/comments/delete',
  'collections/pins/create',
  'collections/pins/remove',
  'collections/pins/reorder',
  'collections/feedback/set',
  'collections/feedback/clear',
  'collections/memories/save-as-album',
  'collections/albums/cover',
]);
const contractBody = sourceText.gateway.match(/\$contracts\s*=\s*\[([\s\S]*?)\n\s*\];/)?.[1] ?? fail('gateway_contract_missing');
const parsedGatewayMutations = [...contractBody.matchAll(/^\s*'([^']+)'\s*=>/gm)].map((match) => match[1]);
exactSet(parsedGatewayMutations, gatewayMutationRoutes, 'gateway_mutations');

const gatewayReadRoutes = Object.freeze([
  'product-state',
  'member-upload/options',
  'home',
  'collections/home',
  'collections/state',
  'collections/pins',
  'comments/{photoId}',
  'albums/{albumId}',
  'spotlight',
  'search/grouped',
  'search/hybrid',
  'search/suggestions',
  'manage/people',
  'manage/options',
  'manage/duplicates',
  'photos',
  'photos/{photoId}',
  'photos/{photoId}/media/{variant}',
  'timeline',
  'albums',
  'people',
  'people/{personId}',
  'memories',
  'search',
  'search/smart',
  'me',
]);
const gatewayReadBody = phpMethodBody(sourceText.gateway, 'handleProductRead');
const gatewayReadLiteralRoutes = parsePhpLiteralSegmentRoutes(gatewayReadBody);
const expectedGatewayReadLiteralRoutes = Object.freeze([
  'product-state',
  'member-upload/options',
  'home',
  'collections/home',
  'collections/state',
  'collections/pins',
  'spotlight',
  'search/grouped',
  'search/hybrid',
  'search/suggestions',
  'manage/people',
  'manage/options',
  'manage/duplicates',
]);
exactMultiset(gatewayReadLiteralRoutes, expectedGatewayReadLiteralRoutes, 'gateway_read_literal_routes');

const simpleRoutesBody = sourceText.gateway.match(/private const SIMPLE_ROUTES\s*=\s*\[([^\]]*)\];/)?.[1]
  ?? fail('gateway_simple_routes_missing');
const gatewaySimpleRoutes = [...simpleRoutesBody.matchAll(/'([^']+)'/g)].map((match) => match[1]);
const expectedGatewaySimpleRoutes = Object.freeze(['photos', 'timeline', 'albums', 'people', 'memories', 'me', 'home']);
exactSet(gatewaySimpleRoutes, expectedGatewaySimpleRoutes, 'gateway_simple_routes');

const gatewayParseRouteBody = phpMethodBody(sourceText.gateway, 'parseRoute');
const gatewayParseRouteDiscriminants = [...gatewayParseRouteBody.matchAll(/\$route === '([^']+)'/g)].map((match) => match[1]);
exactMultiset(gatewayParseRouteDiscriminants, ['photos', 'photos', 'people', 'search', 'search'], 'gateway_parse_route_discriminants');
const gatewayMediaVariants = gatewayParseRouteBody.match(/in_array\(\$segments\[3\], \[([^\]]+)\]/)?.[1]
  ? [...gatewayParseRouteBody.match(/in_array\(\$segments\[3\], \[([^\]]+)\]/)[1].matchAll(/'([^']+)'/g)].map((match) => match[1])
  : fail('gateway_media_variants_missing');
exactSet(gatewayMediaVariants, ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview', 'original'], 'gateway_media_variants');
const gatewayDispatchBody = sourceText.gateway.match(/\$response\s*=\s*match \(\$route\) \{([\s\S]*?)\n\s*\};/)?.[1]
  ?? fail('gateway_read_dispatch_missing');
const gatewayDispatchRoutes = [...gatewayDispatchBody.matchAll(/'([^']+)'\s*=>/g)].map((match) => match[1]);
exactSet(gatewayDispatchRoutes, ['photos', 'media', 'timeline', 'albums', 'people', 'memories', 'search', 'smart-search', 'me'], 'gateway_read_dispatch_routes');
for (const marker of [
  "($segments[0] ?? null) === 'comments'", "($segments[0] ?? null) === 'albums'",
  "($segments[2] ?? null) === 'media'", "'media' => self::deliverMedia", "'smart-search' => $gateway->smartSearch",
]) {
  if (!gatewayReadBody.includes(marker) && !gatewayParseRouteBody.includes(marker) && !gatewayDispatchBody.includes(marker)) {
    fail(`gateway_read_marker_missing:${marker}`);
  }
}

const expectedBffReadMap = Object.freeze([
  ['/api/class-archive/product-state', '/api/product-state'],
  ['/api/class-archive/member-upload/options', '/api/member-upload/options'],
  ['/api/class-archive/albums', '/api/albums'],
  ['/api/class-archive/home', '/api/home'],
  ['/api/class-archive/collections/home', '/api/collections/home'],
  ['/api/class-archive/collections/state', '/api/collections/state'],
  ['/api/class-archive/collections/pins', '/api/collections/pins'],
  ['/api/class-archive/spotlight', '/api/spotlight'],
  ['/api/class-archive/manage/people', '/api/manage/people'],
  ['/api/class-archive/manage/options', '/api/manage/options'],
  ['/api/class-archive/manage/duplicates', '/api/manage/duplicates'],
]);
const bffReadEntries = parseMapEntries(sourceText.webCompat, 'photoUiGatewayReadRoutes');
const bffMutationEntries = parseMapEntries(sourceText.webCompat, 'photoUiGatewayMutationRoutes');
const bffReadMap = bffReadEntries.map(([publicPath]) => publicPath);
const bffMutationMap = bffMutationEntries.map(([publicPath]) => publicPath);
exactPairs(bffReadEntries, expectedBffReadMap, 'bff_read_map');
exactPairs(
  bffMutationEntries,
  gatewayMutationRoutes.map((route) => [`/api/class-archive/${route}`, `/api/${route}`]),
  'bff_mutation_map',
);

const adminActionSource = sourceText.admin.slice(0, sourceText.admin.indexOf('$streamAction'));
const adminActions = [...adminActionSource.matchAll(/^\s*case\s+'([^']+)'\s*:/gm)].map((match) => match[1]);
const expectedAdminActions = Object.freeze([
  'create_classmate', 'create_teacher', 'issue_claim', 'reissue_claim', 'revoke_claim',
  'reissue_family_invitation', 'revoke_family_invitation', 'compensate_provisioning',
  'freeze_identity', 'unfreeze_identity', 'approve_submission', 'reject_submission',
  'save_archive_metadata', 'create_archive_album', 'disable_anonymous', 'enable_anonymous',
  'resolve_anonymous',
]);
exactSet(adminActions, expectedAdminActions, 'admin_actions');

const publicIdentityRoutes = [...sourceText.publicIdentity.matchAll(/private const (ROUTE_[A-Z_]+) = '([^']+)';/g)]
  .map((match) => [match[1], match[2]]);
const expectedPublicIdentityRoutes = Object.freeze([
  ['ROUTE_CLAIM', 'claim'],
  ['ROUTE_FAMILY_INVITE', 'family-invite'],
  ['ROUTE_MY_IDENTITY', 'my'],
  ['ROUTE_MEMBER_UPLOAD', 'member-upload'],
]);
exactPairs(publicIdentityRoutes, expectedPublicIdentityRoutes, 'public_identity_routes');
const publicIdentityMutations = [...sourceText.publicIdentity.matchAll(/\$route === self::(ROUTE_[A-Z_]+) && \$action === '([^']+)'/g)]
  .map((match) => [match[1], match[2]]);
const expectedPublicIdentityMutations = Object.freeze([
  ['ROUTE_CLAIM', 'claim'],
  ['ROUTE_FAMILY_INVITE', 'accept_family'],
  ['ROUTE_MY_IDENTITY', 'issue_family_invitation'],
  ['ROUTE_MY_IDENTITY', 'activate_anonymous'],
  ['ROUTE_MY_IDENTITY', 'submit_family_photo'],
]);
exactPairs(publicIdentityMutations, expectedPublicIdentityMutations, 'public_identity_mutations');
if (!sourceText.publicIdentity.includes("$action !== 'publish_member_photo'")
  || !sourceText.publicIdentity.includes("($_SERVER['CLASS_ARCHIVE_WEB_COMPAT_INTERNAL'] ?? '') !== '1'")) {
  fail('member_upload_private_bridge_contract_missing');
}

const wsBody = sourceText.capabilityGuard.match(/private const WS_CAPABILITIES\s*=\s*\[([\s\S]*?)\n\s*\];/)?.[1]
  ?? fail('ws_capabilities_missing');
const wsMutations = [...wsBody.matchAll(/'([^']+)'\s*=>\s*self::([A-Z_]+)/g)].map((match) => ({
  route: match[1],
  capability: match[2],
}));
if (wsMutations.length !== 41) fail(`ws_mutation_count:${wsMutations.length}`);

const operations = [];
function decisionFor(role, allowedRoles, visibility = 'NONE') {
  return {
    decision: allowedRoles.includes(role) ? 'ALLOW' : 'DENY',
    visibility_scope: allowedRoles.includes(role) ? visibility : 'NONE',
  };
}

function addOperation(spec) {
  if (!spec.operation_id || !spec.route || !spec.method || !Array.isArray(spec.allowed_roles)) {
    fail('operation_contract_invalid');
  }
  if (operations.some((item) => item.operation_id === spec.operation_id)) {
    fail(`duplicate_operation_id:${spec.operation_id}`);
  }
  const roleMatrix = {};
  for (const role of roles) {
    const visibility = spec.visibility_by_role?.[role]
      ?? (role === 'FAMILY' && spec.content_scoped ? 'HERITAGE_ONLY'
        : (spec.allowed_roles.includes(role) && spec.content_scoped ? (role === 'SYSTEM_ADMIN' ? 'FULL_PLUS_PENDING' : 'FULL') : 'NONE'));
    const base = decisionFor(role, spec.allowed_roles, visibility);
    roleMatrix[role] = {
      ...base,
      ownership_condition: base.decision === 'ALLOW' ? (spec.ownership_condition ?? 'NONE') : 'NOT_APPLICABLE',
      era_condition: base.decision === 'ALLOW' ? (spec.era_condition_by_role?.[role] ?? spec.era_condition ?? 'NONE') : 'NOT_APPLICABLE',
      requires_approval: base.decision === 'ALLOW' ? Boolean(spec.requires_approval_by_role?.[role] ?? spec.requires_approval) : false,
      requires_audit: base.decision === 'ALLOW' ? Boolean(spec.requires_audit_by_role?.[role] ?? spec.requires_audit) : false,
    };
  }
  operations.push({
    operation_id: spec.operation_id,
    surface: spec.surface,
    route: spec.route,
    method: spec.method,
    mutates_data: Boolean(spec.mutates_data),
    cleanup_strategy: spec.cleanup_strategy ?? 'NONE',
    enforcement: spec.enforcement,
    notes: spec.notes ?? null,
    role_matrix: roleMatrix,
  });
}

function ordinaryReadPermission(route) {
  if (route.startsWith('manage/')) return ['SYSTEM_ADMIN'];
  if (route === 'member-upload/options') return ['CLASSMATE', 'TEACHER'];
  return authenticatedRoles;
}

function mutationPermission(route) {
  if (route.startsWith('manage/')) return ['SYSTEM_ADMIN'];
  if (route === 'spotlight/create') return ['CLASSMATE', 'TEACHER'];
  if (route === 'spotlight/cancel') return ['SYSTEM_ADMIN'];
  if (route === 'comments/create' || route === 'comments/reply') return ['CLASSMATE', 'TEACHER', 'ANONYMOUS'];
  if (route.startsWith('collections/pins/') || route.startsWith('collections/feedback/')) return authenticatedRoles;
  if (route === 'collections/memories/save-as-album') return ['CLASSMATE', 'TEACHER', 'FAMILY', 'SYSTEM_ADMIN'];
  if (route === 'collections/albums/cover') return ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'];
  fail(`mutation_permission_unclassified:${route}`);
}

function mutationConditions(route) {
  if (route === 'spotlight/create') return { ownership_condition: 'OWN_ACTIVE_COMMUNITY_ALBUM', requires_audit: true };
  if (route === 'spotlight/cancel') return { ownership_condition: 'SYSTEM_ADMIN_EXPLICIT_TARGET', requires_audit: true };
  if (route === 'comments/create' || route === 'comments/reply') {
    return { ownership_condition: 'CURRENTLY_VISIBLE_PHOTO; ANONYMOUS_REQUIRES_CONTEXT_PSEUDONYM', requires_audit: true };
  }
  if (route.startsWith('collections/pins/') || route.startsWith('collections/feedback/')) {
    return { ownership_condition: 'CURRENT_PRINCIPAL_AND_CURRENT_VISIBLE_SNAPSHOT', requires_audit: false };
  }
  if (route === 'collections/memories/save-as-album') {
    return {
      ownership_condition: 'CURRENT_VISIBLE_MEMORY; FAMILY_PATH_IS_PRIVATE_PIN_ONLY',
      requires_audit_by_role: { CLASSMATE: true, TEACHER: true, FAMILY: false, SYSTEM_ADMIN: true },
    };
  }
  if (route === 'collections/albums/cover') {
    return { ownership_condition: 'ADMIN_OR_OWN_COMMUNITY_ALBUM_AND_VISIBLE_MEMBER_PHOTO', requires_audit: true };
  }
  return { ownership_condition: 'SYSTEM_ADMIN_EXPLICIT_TARGET', requires_audit: true };
}

for (const route of gatewayReadRoutes) {
  addOperation({
    operation_id: `gateway.read.${route.replaceAll(/[{}\/|]/g, '_')}`,
    surface: 'PIWIGO_CANONICAL_GATEWAY',
    route: `/api/${route}`,
    method: 'GET|HEAD',
    allowed_roles: ordinaryReadPermission(route),
    content_scoped: !['product-state', 'me', 'member-upload/options'].includes(route) && !route.startsWith('manage/'),
    ownership_condition: route === 'member-upload/options' ? 'ACTIVE_CLASSMATE_OR_TEACHER_PRINCIPAL' : 'CURRENT_ACTIVE_PRINCIPAL',
    enforcement: route === 'photos/{photoId}/media/{variant}'
      ? 'GatewayPolicy + MediaGuard + X-Accel-Redirect'
      : 'Access + GatewayPolicy + persistent role-scoped projection',
  });
}
for (const route of gatewayMutationRoutes) {
  const conditions = mutationConditions(route);
  addOperation({
    operation_id: `gateway.mutate.${route.replaceAll('/', '_')}`,
    surface: 'PIWIGO_CANONICAL_GATEWAY',
    route: `/api/${route}`,
    method: 'POST',
    allowed_roles: mutationPermission(route),
    mutates_data: true,
    cleanup_strategy: 'FIXTURE_REGISTRY_PLUS_CAS; AUDIT_ROWS_RETAINED',
    enforcement: 'fixed internal BFF marker + exact JSON contract + CSRF + domain role/ownership guard',
    ...conditions,
  });
}

const bffClassReadRoutes = Object.freeze([
  ...bffReadMap,
  '/api/class-archive/albums/{albumId}',
  '/api/class-archive/comments/{photoId}',
  '/api/class-archive/search/suggestions',
  '/api/class-archive/search/grouped',
  '/api/class-archive/search/hybrid',
  '/api/class-archive/timeline',
  '/api/class-archive/memories',
  '/api/class-archive/people/{personId}',
]);
for (const publicRoute of bffClassReadRoutes) {
  const internal = publicRoute.replace('/api/class-archive/', '');
  const permissionRoute = internal.replace('{albumId}', '{albumId}').replace('{photoId}', '{photoId}').replace('{personId}', '{personId}');
  addOperation({
    operation_id: `bff.read.${internal.replaceAll(/[{}\/|]/g, '_')}`,
    surface: 'WEB_COMPAT_CLASS_ARCHIVE_API',
    route: publicRoute,
    method: 'GET|HEAD',
    allowed_roles: ordinaryReadPermission(permissionRoute),
    content_scoped: !['product-state', 'member-upload/options'].includes(internal) && !internal.startsWith('manage/'),
    ownership_condition: 'CURRENT_ACTIVE_PRINCIPAL; FIXED_GATEWAY_ALLOWLIST',
    enforcement: 'BFF exact route/query allowlist -> canonical Gateway policy',
  });
}
for (const publicRoute of bffMutationMap) {
  const internal = publicRoute.replace('/api/class-archive/', '');
  addOperation({
    operation_id: `bff.mutate.${internal.replaceAll('/', '_')}`,
    surface: 'WEB_COMPAT_CLASS_ARCHIVE_API',
    route: publicRoute,
    method: 'POST',
    allowed_roles: mutationPermission(internal),
    mutates_data: true,
    cleanup_strategy: 'FIXTURE_REGISTRY_PLUS_CAS; AUDIT_ROWS_RETAINED',
    enforcement: 'BFF principal + exact route/body + same-origin/CSRF -> canonical Gateway domain guard',
    ...mutationConditions(internal),
  });
}
addOperation({
  operation_id: 'bff.mutate.member_upload',
  surface: 'WEB_COMPAT_CLASS_ARCHIVE_API',
  route: '/api/class-archive/member-upload',
  method: 'POST',
  allowed_roles: ['CLASSMATE', 'TEACHER'],
  mutates_data: true,
  cleanup_strategy: 'REMOVE_FIXTURE_SOURCE_AND_MEMBERSHIP; DELETE_CANONICAL_ONLY_IF_FIXTURE_CREATED_AND_UNREFERENCED',
  ownership_condition: 'ACTIVE_MEMBER; SELECTED_ALLOWED_ALBUM',
  era_condition: 'EXPLICIT_HERITAGE_OR_LIVING',
  requires_audit: true,
  enforcement: 'bounded streaming BFF + CSRF + MemberEraUploadService role/era/album checks',
});

const compatibilityRoutes = Object.freeze([
  ['/api/users/me', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/users/me/preferences', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/about', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/version-history', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/features', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/config', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/media-types', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/server/storage', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/notifications', 'GET|HEAD', false, 'CURRENT_ACTIVE_PRINCIPAL'],
  ['/api/timeline/buckets', 'GET|HEAD', true, 'CURRENT_ROLE_SCOPED_TIMELINE'],
  ['/api/timeline/bucket', 'GET|HEAD', true, 'CURRENT_ROLE_SCOPED_TIMELINE'],
  ['/api/albums', 'GET|HEAD', true, 'CURRENT_ROLE_SCOPED_ALBUM_PROJECTION'],
  ['/api/memories', 'GET|HEAD', true, 'SAFE_EMPTY_COMPATIBILITY_RESULT'],
  ['/api/people', 'GET|HEAD', true, 'CURRENT_ROLE_SCOPED_PEOPLE_PROJECTION'],
  ['/api/search/metadata', 'POST', true, 'READ_ONLY_NORMALIZED_SEARCH_BODY'],
  ['/api/search/smart', 'POST', true, 'READ_ONLY_NORMALIZED_SEARCH_BODY'],
  ['/api/people/{personId}/statistics', 'GET|HEAD', true, 'VISIBLE_PERSON_ONLY'],
  ['/api/people/{personId}/thumbnail', 'GET|HEAD', true, 'VISIBLE_PERSON_COVER_ONLY'],
  ['/api/people/{personId}', 'GET|HEAD', true, 'VISIBLE_PERSON_ONLY'],
  ['/api/assets/{photoId}', 'GET|HEAD', true, 'VISIBLE_PHOTO_ONLY'],
  ['/api/assets/{photoId}/thumbnail', 'GET|HEAD', true, 'VISIBLE_PHOTO_ONLY'],
  ['/api/assets/{photoId}/original', 'GET|HEAD', true, 'VISIBLE_PHOTO_ONLY'],
]);
for (const [route, method, contentScoped, ownership] of compatibilityRoutes) {
  addOperation({
    operation_id: `bff.compat.${route.slice(5).replaceAll(/[{}\/|-]/g, '_')}.${method === 'POST' ? 'post' : 'read'}`,
    surface: 'IMMICH_WEB_COMPATIBILITY_API',
    route,
    method,
    allowed_roles: authenticatedRoles,
    content_scoped: contentScoped,
    ownership_condition: ownership,
    enforcement: route.includes('/thumbnail') || route.endsWith('/original')
      ? 'BFF metadata-only resolution -> canonical Gateway -> MediaGuard; no Node byte relay'
      : 'BFF exact compatibility contract -> canonical Gateway role-scoped projection',
    notes: route === '/api/memories' ? 'Compatibility placeholder deliberately returns an empty list.' : null,
  });
}

const identityOperations = Object.freeze([
  ['identity.claim_page', '/index.php?/class-identity/claim', 'GET', ['GUEST'], false, 'NONE', false],
  ['identity.claim', '/index.php?/class-identity/claim action=claim', 'POST', ['GUEST'], true, 'VALID_ONE_TIME_CLAIM', true],
  ['identity.family_invite_page', '/index.php?/class-identity/family-invite', 'GET', ['GUEST'], false, 'NONE', false],
  ['identity.family_invite_accept', '/index.php?/class-identity/family-invite action=accept_family', 'POST', ['GUEST'], true, 'VALID_ONE_TIME_FAMILY_INVITE', true],
  ['identity.my', '/index.php?/class-identity/my', 'GET', ['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS'], false, 'CURRENT_OWN_IDENTITY; ANONYMOUS_REDACTED', false],
  ['identity.family_invite_issue', '/index.php?/class-identity/my action=issue_family_invitation', 'POST', ['CLASSMATE'], true, 'OWN_AVAILABLE_FAMILY_SEAT', true],
  ['identity.anonymous_activate', '/index.php?/class-identity/my action=activate_anonymous', 'POST', ['CLASSMATE'], true, 'OWN_AVAILABLE_ANONYMOUS_SEAT', true],
  ['identity.family_submission', '/index.php?/class-identity/my action=submit_family_photo', 'POST', ['FAMILY'], true, 'OWN_BOUND_CLASSMATE_IDENTITY', true],
]);
for (const [id, route, method, allowed, mutates, ownership, audit] of identityOperations) {
  addOperation({
    operation_id: id,
    surface: 'CLASS_IDENTITY_PUBLIC',
    route,
    method,
    allowed_roles: allowed,
    mutates_data: mutates,
    cleanup_strategy: mutates ? 'FIXTURE_REGISTRY_PLUS_CAS; REVOKE_CREDENTIALS; AUDIT_ROWS_RETAINED' : 'NONE',
    ownership_condition: ownership,
    era_condition_by_role: id === 'identity.family_submission' ? { FAMILY: 'HERITAGE_ONLY_PENDING' } : {},
    requires_approval_by_role: id === 'identity.family_submission' ? { FAMILY: true } : {},
    requires_audit: audit,
    enforcement: 'public controller exact route/action + CSRF/origin + Provisioning/Submission domain guard',
  });
}

const adminTabs = Object.freeze(['dashboard', 'identities', 'teachers', 'invitations', 'submissions', 'anonymous', 'archive', 'audit', 'system']);
for (const tab of adminTabs) {
  addOperation({
    operation_id: `admin.read.${tab}`,
    surface: 'CLASS_ARCHIVE_ADMIN_CONSOLE',
    route: `/admin.php?page=plugin-ClassIdentity-${tab}`,
    method: 'GET|HEAD',
    allowed_roles: ['SYSTEM_ADMIN'],
    ownership_condition: 'ACTIVE_SYSTEM_ACCOUNT_WITH_SYSTEM_ADMIN_ROLE',
    enforcement: 'Piwigo administrator status + ClassIdentity SYSTEM_ADMIN principal',
  });
}
for (const kind of ['submission_thumbnail', 'submission_original']) {
  addOperation({
    operation_id: `admin.read.${kind}`,
    surface: 'CLASS_ARCHIVE_ADMIN_CONSOLE',
    route: `/admin.php?page=plugin-ClassIdentity-submissions&action=${kind}`,
    method: 'GET',
    allowed_roles: ['SYSTEM_ADMIN'],
    content_scoped: true,
    ownership_condition: 'SYSTEM_ADMIN_EXPLICIT_PENDING_OR_REJECTED_SUBMISSION',
    enforcement: 'Admin route guard + SubmissionService stream authorization',
  });
}
for (const action of expectedAdminActions) {
  addOperation({
    operation_id: `admin.mutate.${action}`,
    surface: 'CLASS_ARCHIVE_ADMIN_CONSOLE',
    route: `/admin.php?page=plugin-ClassIdentity-{tab} action=${action}`,
    method: 'POST',
    allowed_roles: ['SYSTEM_ADMIN'],
    mutates_data: true,
    cleanup_strategy: 'FIXTURE_REGISTRY_PLUS_CAS; NEVER_DELETE_APPEND_ONLY_AUDIT',
    ownership_condition: 'SYSTEM_ADMIN_EXPLICIT_TARGET_AND_REASON',
    requires_audit: true,
    enforcement: 'ClassIdentity admin route guard + Piwigo CSRF + domain SYSTEM_ADMIN requirement',
  });
}

const wsRoleByCapability = Object.freeze({
  COMMENT_IMAGE: ['CLASSMATE', 'TEACHER', 'ANONYMOUS', 'SYSTEM_ADMIN'],
  RATE_IMAGE: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  UPLOAD_PHOTO: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  MANAGE_PHOTO: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  CREATE_ALBUM: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  MANAGE_ALBUM: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  MANAGE_TAG: ['CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN'],
  PRIVATE_COLLECTION: ['CLASSMATE', 'TEACHER', 'FAMILY', 'SYSTEM_ADMIN'],
  ACCOUNT_PREFERENCE: ['CLASSMATE', 'TEACHER', 'FAMILY', 'SYSTEM_ADMIN'],
});
for (const { route, capability } of wsMutations) {
  addOperation({
    operation_id: `piwigo.ws.${route}`,
    surface: 'PIWIGO_CLASSIFIED_WS',
    route: `/ws.php?method=${route}`,
    method: 'POST',
    allowed_roles: wsRoleByCapability[capability] ?? fail(`ws_capability_unclassified:${capability}`),
    mutates_data: true,
    cleanup_strategy: 'DOWNSTREAM_CORE_OWNERSHIP_AND_FIXTURE_REGISTRY_REQUIRED',
    ownership_condition: capability === 'COMMENT_IMAGE' ? 'VISIBLE_IMAGE; ANONYMOUS_REQUIRES_PRESENTER_ATTESTATION' : 'CAPABILITY_GUARD_ALLOW_ONLY; CORE_OWNERSHIP_STILL_REQUIRED',
    requires_audit: false,
    enforcement: 'CapabilityGuard coarse precondition; ALLOW only continues to Piwigo CSRF/ownership/admin checks',
    notes: 'This row is not proof that the operation succeeds for the role; downstream Core policy remains authoritative.',
  });
}
const commonWsReads = Object.freeze([
  'pwg.getVersion',
  'pwg.session.getStatus',
  'pwg.session.logout',
  'pwg.categories.getImages',
  'pwg.categories.getList',
  'pwg.images.getInfo',
  'pwg.images.search',
  'pwg.images.filteredSearch.create',
  'pwg.tags.getList',
  'pwg.tags.getImages',
  'pwg.history.log',
]);
for (const route of commonWsReads) {
  const hasSessionOrHistorySideEffect = [
    'pwg.session.logout',
    'pwg.images.filteredSearch.create',
    'pwg.history.log',
  ].includes(route);
  addOperation({
    operation_id: `piwigo.ws.${route}`,
    surface: 'PIWIGO_ALLOWLISTED_WS_READ',
    route: `/ws.php?method=${route}`,
    method: 'POST',
    allowed_roles: authenticatedRoles,
    content_scoped: !['pwg.getVersion', 'pwg.session.getStatus', 'pwg.session.logout'].includes(route),
    mutates_data: hasSessionOrHistorySideEffect,
    cleanup_strategy: hasSessionOrHistorySideEffect
      ? 'SESSION_OR_APPEND_ONLY_CORE_STATE; DO_NOT_TREAT_AS_PURE_READ'
      : 'NONE',
    ownership_condition: 'CAPABILITY_GUARD_EXACT_COMMON_READ_ALLOWLIST; CORE_ACL_STILL_REQUIRED',
    enforcement: 'CapabilityGuard exact allowlist + Piwigo ACL',
  });
}
addOperation({
  operation_id: 'piwigo.ws.pwg.users.favorites.getList',
  surface: 'PIWIGO_ALLOWLISTED_WS_READ',
  route: '/ws.php?method=pwg.users.favorites.getList',
  method: 'POST',
  allowed_roles: ['CLASSMATE', 'TEACHER', 'FAMILY', 'SYSTEM_ADMIN'],
  content_scoped: true,
  ownership_condition: 'OWN_FAVORITES; CORE_ACL_STILL_REQUIRED',
  enforcement: 'CapabilityGuard exact allowlist + Piwigo ACL',
});
addOperation({
  operation_id: 'piwigo.ws.pwg.session.login',
  surface: 'PIWIGO_LOGIN_WS',
  route: '/ws.php?method=pwg.session.login',
  method: 'POST',
  allowed_roles: ['GUEST'],
  mutates_data: true,
  cleanup_strategy: 'LOGOUT_AND_REVOKE_FIXTURE_SESSION',
  ownership_condition: 'VALID_CREDENTIALS; PRINCIPAL_BOUND_AFTER_LOGIN',
  enforcement: 'CapabilityGuard guest exception + Access finalize-login principal binding',
});

const expectedCounts = Object.freeze({
  canonical_gateway: 47,
  web_compat_class_archive: 41,
  web_compat_immich: 22,
  public_identity: 8,
  admin_console: 28,
  piwigo_ws: 54,
});
const actualCounts = {
  canonical_gateway: operations.filter((item) => item.surface === 'PIWIGO_CANONICAL_GATEWAY').length,
  web_compat_class_archive: operations.filter((item) => item.surface === 'WEB_COMPAT_CLASS_ARCHIVE_API').length,
  web_compat_immich: operations.filter((item) => item.surface === 'IMMICH_WEB_COMPATIBILITY_API').length,
  public_identity: operations.filter((item) => item.surface === 'CLASS_IDENTITY_PUBLIC').length,
  admin_console: operations.filter((item) => item.surface === 'CLASS_ARCHIVE_ADMIN_CONSOLE').length,
  piwigo_ws: operations.filter((item) => item.surface.startsWith('PIWIGO_') && item.surface !== 'PIWIGO_CANONICAL_GATEWAY').length,
};
if (JSON.stringify(actualCounts) !== JSON.stringify(expectedCounts)) {
  fail(`count_drift expected=${JSON.stringify(expectedCounts)} actual=${JSON.stringify(actualCounts)}`);
}
if (operations.length !== 200) fail(`exposed_operation_count:${operations.length}`);

const rows = operations.flatMap((operation) => roles.map((role) => ({
  operation_id: operation.operation_id,
  surface: operation.surface,
  route: operation.route,
  http_method: operation.method,
  role,
  allowed: operation.role_matrix[role].decision,
  ownership_condition: operation.role_matrix[role].ownership_condition,
  era_condition: operation.role_matrix[role].era_condition,
  visibility_scope: operation.role_matrix[role].visibility_scope,
  requires_approval: operation.role_matrix[role].requires_approval,
  requires_audit: operation.role_matrix[role].requires_audit,
  mutates_data: operation.mutates_data,
  cleanup_strategy: operation.cleanup_strategy,
  enforcement: operation.enforcement,
  notes: operation.notes,
})));

const document = {
  schema_version: 2,
  evidence_level: 'STATIC_CODE_AUDIT',
  generated_at: new Date().toISOString(),
  source_files: Object.values(sources),
  source_contract_integrity: 'PASS',
  // These extracted, public-safe declarations prevent a new route/action or
  // BFF remap from silently inheriting a hand-written matrix entry. They are
  // source-contract evidence only; runtime authorization still needs HTTP and
  // browser proof against a real role/session.
  source_surface_contract: {
    gateway: {
      literal_read_routes: [...gatewayReadLiteralRoutes].sort(),
      simple_read_routes: [...gatewaySimpleRoutes].sort(),
      parse_route_discriminants: [...new Set(gatewayParseRouteDiscriminants)].sort(),
      media_variants: [...gatewayMediaVariants].sort(),
      read_dispatch_routes: [...gatewayDispatchRoutes].sort(),
      mutation_routes: [...parsedGatewayMutations].sort(),
    },
    web_compat: {
      gateway_read_mappings: bffReadEntries.map(([publicPath, internalPath]) => ({ public_path: publicPath, internal_path: internalPath })),
      gateway_mutation_mappings: bffMutationEntries.map(([publicPath, internalPath]) => ({ public_path: publicPath, internal_path: internalPath })),
    },
    public_identity: {
      routes: publicIdentityRoutes.map(([constant, route]) => ({ constant, route })),
      mutations: publicIdentityMutations.map(([route_constant, action]) => ({ route_constant, action })),
      member_upload: {
        action: 'publish_member_photo',
        browser_direct_access: 'DENY_UNLESS_FIXED_WEB_COMPAT_INTERNAL_BRIDGE',
      },
    },
    admin: { actions: [...adminActions].sort() },
    piwigo_ws: {
      classified_mutations: wsMutations.map(({ route, capability }) => ({ route, capability })),
    },
  },
  counting_rule: 'Each independently callable route-pattern plus method is one operation. The canonical Piwigo Gateway and its BFF aliases are separate attack surfaces. Static assets, health, redirects, page-shell documents, reflection.* wildcard methods, and three legacy HTML guard families are reported in docs but excluded.',
  counts: {
    ...actualCounts,
    exposed_product_operations: operations.length,
    role_rows: rows.length,
  },
  role_model: {
    implemented: ['CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS', 'SYSTEM_ADMIN'],
    guest: 'Only login and the public Claim/Family Invite pages are intentionally available.',
    reserved_not_implemented: ['ARCHIVIST'],
  },
  operations,
  rows,
};

const outputFlag = process.argv.indexOf('--output');
const outputPath = outputFlag >= 0 && process.argv[outputFlag + 1]
  ? path.resolve(process.cwd(), process.argv[outputFlag + 1])
  : path.join(root, '.codex-work', 'private-role-e2e', 'capability-matrix.json');
if (process.argv.includes('--stdout')) {
  process.stdout.write(`${JSON.stringify(document, null, 2)}\n`);
} else {
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, `${JSON.stringify(document, null, 2)}\n`, { encoding: 'utf8', flag: 'w' });
  process.stdout.write(`CAPABILITY_MATRIX_COMPLETE=PASS\nEXPOSED_PRODUCT_OPERATIONS=${operations.length}\nROLE_MATRIX_ROWS=${rows.length}\nOUTPUT=${outputPath}\n`);
}
