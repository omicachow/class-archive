import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const uiRoot = resolve(root, 'infra', 'immich-spike', 'photo-ui');
const paths = Object.freeze({
  html: resolve(uiRoot, 'index.html'),
  app: resolve(uiRoot, 'app.js'),
  css: resolve(uiRoot, 'app.css'),
  i18n: resolve(uiRoot, 'i18n.js'),
  dom: resolve(uiRoot, 'ui-dom.js'),
  eraUpload: resolve(uiRoot, 'ui-era-upload.js'),
  searchOverlay: resolve(uiRoot, 'ui-search-overlay.js'),
  server: resolve(root, 'infra', 'immich-spike', 'web-compat', 'server.mjs'),
});

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

for (const [name, file] of Object.entries(paths)) {
  const entry = await stat(file);
  check(entry.isFile() && entry.size > 0, `${name}_missing`);
}

const [html, app, css, i18n, dom, eraUpload, searchOverlay, server] = await Promise.all([
  readFile(paths.html, 'utf8'),
  readFile(paths.app, 'utf8'),
  readFile(paths.css, 'utf8'),
  readFile(paths.i18n, 'utf8'),
  readFile(paths.dom, 'utf8'),
  readFile(paths.eraUpload, 'utf8'),
  readFile(paths.searchOverlay, 'utf8'),
  readFile(paths.server, 'utf8'),
]);

// V4 is deliberately an owned native-module surface.  A small feature must
// not silently introduce a bundled framework or loosen the asset boundary.
check(html.includes('type="module" src="/photo-ui/app.js?v=__PHOTO_UI_ASSET_REV__"'), 'owned_module_entry_missing');
check(!/<script(?![^>]*\bsrc=)[^>]*>/i.test(html), 'inline_script_forbidden');
check(!/<(?:script|link)[^>]+https?:\/\//i.test(html), 'external_runtime_asset_forbidden');
for (const module of ['app.js', 'i18n.js', 'ui-dom.js', 'ui-era-upload.js', 'ui-search-overlay.js']) {
  check(server.includes(`'${module}'`), `static_manifest_${module}_missing`);
}
check(server.includes('const photoUiStaticManifest = Object.freeze([')
  && server.includes('const photoUiAssetNames = Object.freeze(photoUiStaticManifest.map(([, fileName]) => fileName));')
  && server.includes("url.searchParams.get('v') !== revision"), 'immutable_static_manifest_or_revision_gate_missing');
check(server.includes("'Content-Security-Policy'") && server.includes("default-src 'self'") && server.includes("script-src 'self'"),
  'csp_baseline_missing');
const photoUiCspStart = server.indexOf('if (photoUi)');
const photoUiCspEnd = server.indexOf('// The verified upstream static build has an inline bootstrap script.', photoUiCspStart);
const photoUiCsp = photoUiCspStart >= 0 && photoUiCspEnd > photoUiCspStart
  ? server.slice(photoUiCspStart, photoUiCspEnd)
  : '';
check(server.includes("{ html: true, photoUi: true }")
  && photoUiCsp.includes("style-src 'self' 'unsafe-inline'; script-src 'self'")
  && !photoUiCsp.includes("script-src 'self' 'unsafe-inline'")
  && !photoUiCsp.includes('wasm-unsafe-eval'),
  'photo_ui_csp_must_not_allow_inline_script_or_wasm');
for (const forbidden of ['react', 'preact', 'svelte', 'vue', 'solid-js', 'lit-html']) {
  const expression = new RegExp(`(?:from\\s+['\"]|require\\s*\\(|import\\s*\\()(?:(?!\\n).)*${forbidden}`, 'i');
  check(!expression.test(`${app}\n${dom}\n${eraUpload}\n${searchOverlay}`), `framework_dependency_${forbidden}`);
}
check(app.includes("from './ui-search-overlay.js?v=__PHOTO_UI_ASSET_REV__'")
  && searchOverlay.includes("from './i18n.js?v=__PHOTO_UI_ASSET_REV__';")
  && searchOverlay.includes("from './ui-dom.js?v=__PHOTO_UI_ASSET_REV__';"), 'revisioned_overlay_module_graph_missing');
check(app.includes("from './ui-era-upload.js?v=__PHOTO_UI_ASSET_REV__'")
  && eraUpload.includes("from './i18n.js?v=__PHOTO_UI_ASSET_REV__';")
  && eraUpload.includes("from './ui-dom.js?v=__PHOTO_UI_ASSET_REV__';"), 'revisioned_era_upload_module_graph_missing');

const navigation = app.match(/const navigation = Object\.freeze\(\[(?<items>[\s\S]*?)\]\);/);
check(navigation?.groups?.items, 'primary_navigation_declaration_missing');
const primaryRoutes = [...navigation.groups.items.matchAll(/href:\s*'([^']+)'/g)].map((match) => match[1]);
assert.deepEqual(primaryRoutes, ['/home', '/photos'], 'primary_navigation_is_not_collections_and_library_only');
assertions += 1;
check(i18n.includes("'nav.home': '精选集'") && i18n.includes("'nav.photos': '资料库'"), 'v4_primary_navigation_copy_missing');
for (const legacyRoute of ['/people', '/albums', '/memories', '/my', '/search']) {
  check(!primaryRoutes.includes(legacyRoute) && server.includes(`'${legacyRoute}'`), `legacy_deep_link_${legacyRoute}_not_preserved_or_not_demoted`);
}

check(app.includes("const MOBILE_NAVIGATION = new Set(['photos', 'home'])"), 'mobile_primary_navigation_not_library_collections');
check(app.includes('mobile-search-action') && app.includes('dataset.globalSearchTrigger'), 'mobile_global_search_action_missing');
check(css.includes('grid-template-columns: repeat(3, minmax(0, 1fr))')
  && css.includes('env(safe-area-inset-bottom)') && css.includes('min-height: 52px'),
  'mobile_three_action_safe_area_or_target_contract_missing');

// The overlay is visual-only.  App.js owns requests and the Gateway continues
// to own scope filtering; this prevents a small dialog helper becoming a second
// policy or transport implementation.
check(searchOverlay.includes('export function openGlobalSearchOverlay(')
  && searchOverlay.includes("element('dialog', 'global-search-dialog')")
  && searchOverlay.includes("dialog.setAttribute('aria-modal', 'true')")
  && searchOverlay.includes('dialog.showModal()')
  && searchOverlay.includes('returnFocus instanceof HTMLElement')
  && searchOverlay.includes('returnFocus.focus('), 'native_dialog_accessibility_contract_missing');
check(searchOverlay.includes("input.type = 'search'")
  && searchOverlay.includes("input.setAttribute('aria-autocomplete', 'list')")
  && searchOverlay.includes("input.setAttribute('aria-controls'")
  && searchOverlay.includes("status.setAttribute('aria-live', 'polite')"), 'search_combobox_or_status_semantics_missing');
check(!searchOverlay.includes('fetch(') && !searchOverlay.includes('/api/') && !searchOverlay.includes('runtime.'),
  'overlay_must_not_own_api_or_policy_state');
// The era dialog is likewise presentation-only. It receives opaque, already
// role-scoped choices from app.js and cannot turn a UI control into a second
// policy evaluator or an arbitrary upload transport.
check(eraUpload.includes('export function openEraUploadDialog(')
  && eraUpload.includes("dialogShell('upload.title', 'upload.lead')")
  && eraUpload.includes("dialog.classList.add('era-upload-dialog')")
  && eraUpload.includes("file.accept = 'image/jpeg,image/png,image/webp'")
  && eraUpload.includes("input.required = true")
  && eraUpload.includes('const ERAS = new Set'),
  'era_upload_native_dialog_and_explicit_era_contract_missing');
check(!eraUpload.includes('fetch(') && !eraUpload.includes('/api/') && !eraUpload.includes('runtime.'),
  'era_upload_dialog_must_not_own_api_or_policy_state');
check(app.includes("body.set('action', 'publish_member_photo')")
  && app.includes("body.set('pwg_token', state.csrfToken)")
  && app.includes("body.set('era', era)")
  && app.includes("body.set('album_id', albumId.toLowerCase())")
  && app.includes("body.set('member_photo', file)")
  && app.includes("'X-Class-Archive-CSRF': state.csrfToken")
  && app.includes("fetch('/api/class-archive/member-upload'")
  && !app.includes("'Content-Type': 'multipart/form-data'"),
  'era_upload_form_and_csrf_transport_contract_missing');
check(app.includes("canEraUpload: payload?.canEraUpload === true")
  && app.includes("canFamilySubmission: payload?.canFamilySubmission === true")
  && app.includes("location.assign('/class-archive-core/identity')"),
  'family_pending_submission_must_remain_separate_from_member_direct_publish');
check(server.includes("['/photo-ui/ui-era-upload.js', 'ui-era-upload.js']")
  && server.includes("'/api/class-archive/member-upload/options'")
  && server.includes("const memberEraUploadRoles = new Set(['CLASSMATE', 'TEACHER'])"),
  'era_upload_static_or_bff_role_boundary_missing');
check(app.includes('function openSearchFromTrigger(')
  && app.includes('openGlobalSearchOverlay({')
  && app.includes("window.addEventListener('popstate'")
  && app.includes('history.back()'), 'overlay_history_back_contract_missing');
check(app.includes("event.key.toLowerCase() === 'k'")
  && app.includes("event.key === '/'") && app.includes('event.ctrlKey || event.metaKey'),
  'search_shortcuts_missing');
check(app.includes("'/home?search=1'") || app.includes("searchParams.set('search', '1')"),
  'legacy_search_must_canonicalize_to_overlay_state');
check(server.includes("url.pathname === '/home'") && server.includes("url.searchParams.has('search')"),
  'bff_must_allow_only_explicit_home_search_compatibility_query');
check(app.includes('searchOverlayOpening: null')
  && app.includes('if (runtime.searchOverlayOpening)')
  && app.includes('const pushed = setSearchOverlayHistory()')
  && app.includes('prevalidatedState: state'),
  'search_open_must_coalesce_fast_triggers_and_push_history_only_after_state_check');

check(app.includes('runtime.currentSearchContext')
  && searchOverlay.includes('dataset.scopeToggle')
  && app.includes('const scopeId = () => gatewayAlbumScope(activeScope);')
  && app.includes('const groupedScope = () => gatewaySearchScope(activeScope);')
  && app.includes('searchSuggestions(query, scopeId(), controller.signal)')
  && app.includes('groupedSearch(query, requestScope, null, controller.signal)')
  && app.includes("new URLSearchParams({ q: query, contextType: normalized.kind, limit: String(SEARCH_PAGE_LIMIT) })")
  && app.includes("if (normalized.id !== null) params.set('contextId', normalized.id);"),
  'search_scope_must_flow_to_typed_gateway_queries');
check(server.includes("exactQuery(url, new Set(['q', 'contextType', 'contextId', 'albumId', 'cursor', 'limit']))")
  && server.includes('class_archive_web_compat_grouped_search_context_invalid')
  && server.includes("url.pathname === '/api/class-archive/search/grouped'")
  && server.includes("url.pathname === '/api/class-archive/search/suggestions'"),
  'bff_search_scope_must_stay_bounded_and_typed_context_validated');
const hybridStart = app.indexOf('function renderGroupedSearchResults(response, onQuery, onLoadMore = null)');
const structuredStart = app.indexOf('if (structuredCount > 0 || response.photos.items.length > 0)', hybridStart);
const semanticStart = app.indexOf('if (response.semantic.available && response.semantic.items.length > 0)', hybridStart);
check(hybridStart >= 0 && structuredStart > hybridStart && semanticStart > structuredStart,
  'structured_archive_results_must_render_before_semantic_beta');
check(app.includes('// A failed search is not permission to call any upstream library')
  && app.includes('overlay.results.replaceChildren();')
  && app.includes("setStatus(t('search.partialUnavailable'))")
  && !app.includes("return legacySearch(query, albumId)"),
  'search_must_not_fallback_to_an_unscoped_library_after_gateway_failure');

check(app.includes("dataset.avatarMenuTrigger = 'true'")
  && app.includes("aria-haspopup', 'dialog'")
  && app.includes("aria-expanded', 'false'")
  && app.includes('function openAvatarMenu('),
  'avatar_menu_intent_missing');
check(app.includes("const LIBRARY_VIEW_PREFERENCE_KEY = 'class-archive.library-view.v4'")
  && app.includes('function libraryViewToggle(')
  && app.includes("localStorage.setItem(LIBRARY_VIEW_PREFERENCE_KEY")
  && app.includes("grid.dataset.layout = layout"),
  'library_view_toggle_must_be_owned_and_persisted');
check(app.includes('function archiveJump(')
  && app.includes('timeline-group-${index}')
  && app.includes("reducedMotion ? 'auto' : 'smooth'"),
  'library_archive_jump_must_use_loaded_safe_timeline_sections');
check(css.includes('.photo-grid[data-layout="square"]')
  && css.includes('.archive-jump') && css.includes('.library-view-toggle'),
  'library_view_and_archive_jump_visual_contract_missing');
check(app.includes('function viewerFilmstrip(')
  && app.includes("element('nav', 'viewer-filmstrip')")
  && app.includes("append(stage, wrap, toolbar, previous, next, viewerFilmstrip(photos, index))")
  && css.includes('.viewer-filmstrip'),
  'viewer_filmstrip_must_use_authorized_photo_projection');
check(app.includes('const setInfoOpen = (nextOpen) =>')
  && app.includes("if (infoOpen) setInfoOpen(false)")
  && css.includes('.viewer-page[data-info-open="false"]')
  && css.includes('.viewer-info[data-open="false"]'),
  'viewer_comment_panel_must_be_collapsible_without_hiding_policy');
check(app.includes('const editable = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement')
  && app.includes('editable || runtime.searchOverlay || document.querySelector(\'dialog[open]\')'),
  'viewer_keyboard_navigation_must_not_hijack_comment_or_dialog_input');
check(!app.includes(':2283') && !app.includes('/original') && !app.includes('immich_asset_id'),
  'v4_ui_must_not_bypass_mediaguard_or_expose_immich_media');
check(server.includes("'private, no-cache, max-age=0, must-revalidate, no-transform'")
  && server.includes('Do not silently fall back to user-space media relay'),
  'v4_contract_must_retain_private_metadata_and_mediaguard_media_guards');

// Collection pin state is persisted by the Gateway and sent only through the
// existing CSRF-protected, fixed-path BFF mutation map.  The UI never invents
// a link, a policy scope, or a raw upstream endpoint for a pinned item.
check(app.includes('function normalizeCollectionPins(')
  && app.includes("apiJson('/api/class-archive/collections/pins'")
  && app.includes("'/api/class-archive/collections/pins/create'")
  && app.includes("'/api/class-archive/collections/pins/remove'")
  && app.includes("'/api/class-archive/collections/pins/reorder'"),
  'collection_pins_must_read_and_mutate_only_through_owned_gateway_routes');
check(server.includes("['/api/class-archive/collections/pins', '/api/collections/pins']")
  && server.includes("['/api/class-archive/collections/pins/create', '/api/collections/pins/create']")
  && server.includes("['/api/class-archive/collections/pins/reorder', '/api/collections/pins/reorder']"),
  'collection_pin_bff_paths_must_remain_fixed_and_allowlisted');
check(i18n.includes("'home.pin': '固定到首页'")
  && i18n.includes("'home.unpin': '取消固定'")
  && i18n.includes("'home.movePinnedEarlier': '向前移动'")
  && i18n.includes("'home.movePinnedLater': '向后移动'")
  && css.includes('.home-collection-actions { opacity: 1; transform: none; }')
  && css.includes('.home-collection-action { min-height: 44px; }'),
  'collection_pin_controls_must_have_chinese_copy_and_mobile_touch_targets');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photos-app-v4-contract', assertions, result: 'PASS' })}\n`);
