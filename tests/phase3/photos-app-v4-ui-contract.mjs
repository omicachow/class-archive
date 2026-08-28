import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// This is a static, public-safe UI boundary contract. It does not start a
// browser or any private runtime; browser evidence remains a separate gate.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');
const [app, css, i18n, overlay, upload] = await Promise.all([
  read('infra/immich-spike/photo-ui/app.js'),
  read('infra/immich-spike/photo-ui/app.css'),
  read('infra/immich-spike/photo-ui/i18n.js'),
  read('infra/immich-spike/photo-ui/ui-search-overlay.js'),
  read('infra/immich-spike/photo-ui/ui-era-upload.js'),
]);

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

check(app.includes("const navigation = Object.freeze([")
  && app.includes("{ key: 'home', href: '/home' }")
  && app.includes("{ key: 'photos', href: '/photos' }"),
'v4_navigation_must_keep_collections_and_library_as_the_only_primary_destinations');
for (const path of ['/people', '/albums', '/memories', '/my', '/search']) {
  check(!app.slice(app.indexOf('const navigation'), app.indexOf('function navLink')).includes(`href: '${path}'`),
    `legacy_destination_${path}_must_not_return_to_primary_navigation`);
}
check(app.includes("dataset.avatarMenuTrigger = 'true'")
  && app.includes("aria-haspopup', 'dialog'")
  && app.includes('function openAvatarMenu('),
'avatar_menu_must_remain_the_non_primary_account_entry');

check(overlay.includes("export const SEARCH_SCOPE_KINDS = Object.freeze(['ALL', 'ALBUM', 'PERSON', 'MEMORY', 'COLLECTION'])"),
  'overlay_must_own_the_complete_typed_scope_vocabulary');
check(overlay.includes("const scope = element('select', 'global-search-scope')")
  && overlay.includes("scope.name = 'scope'")
  && overlay.includes("scope.dataset.scopeKind")
  && overlay.includes('onScopeChange?.(selected)'),
  'scope_affordance_must_be_native_select_and_report_an_opaque_typed_choice');
check(overlay.includes('function normalizeScopeId(kind, value)')
  && overlay.includes('const MEMORY_SCOPE_ID = /^memory-[a-f0-9]{56}$/;')
  && overlay.includes('const COLLECTION_SCOPE_ID = /^[A-Za-z0-9][A-Za-z0-9:_-]{0,95}$/;')
  && overlay.includes("raw?.key === `${kind}:${id}`"),
  'overlay_must_preserve_only_valid_typed_opaque_scope_identifiers');
check(overlay.includes("dialog.setAttribute('role', 'dialog')")
  && overlay.includes("dialog.setAttribute('aria-modal', 'true')")
  && overlay.includes('dialog.showModal()'),
  'overlay_must_use_native_modal_dialog_semantics');
check(overlay.includes("dialog.addEventListener('cancel'")
  && overlay.includes('event.preventDefault();\n    closeOverlay();')
  && overlay.includes("dialog.addEventListener('keydown'")
  && overlay.includes("event.key !== 'Tab'")
  && overlay.includes('dialogFocusableElements(dialog)'),
  'overlay_must_own_escape_and_tab_focus_cycle_semantics');
check(overlay.includes('returnFocus instanceof HTMLElement')
  && overlay.includes('returnFocus.focus({ preventScroll: true })'),
  'overlay_must_restore_focus_to_its_trigger_after_close');
check(!overlay.includes('fetch(') && !overlay.includes('/api/') && !overlay.includes('runtime.'),
  'overlay_must_not_become_a_second_transport_or_policy_layer');

check(app.includes("const SEARCH_CONTEXT_MEMORY_ID = /^memory-[a-f0-9]{56}$/;")
  && app.includes("const SEARCH_CONTEXT_COLLECTION_ID = /^[A-Za-z0-9][A-Za-z0-9:_-]{0,95}$/;")
  && app.includes('function normalizeSearchContextId(kind, value)')
  && app.includes('function gatewaySearchScope(scope)')
  && app.includes('contextType: normalized.kind')
  && app.includes("params.set('contextId', normalized.id)"),
  'typed_person_memory_and_collection_scope_ids_must_be_normalized_before_the_fixed_gateway_request');
check(app.includes("apiJson(`/api/class-archive/search/grouped?${request.params}`")
  && app.includes('function normalizeGroupedSearch(payload, expectedScope)')
  && app.includes('function mergeGroupedSearchPages(current, next)')
  && !app.includes('/api/class-archive/search/hybrid')
  && !app.includes("apiJson('/api/search/metadata'")
  && !app.includes("apiJson('/api/search/smart'"),
  'all_member_search_results_must_use_the_typed_gateway_grouped_contract_without_browser_side_legacy_fallbacks');
check(app.includes('function supportsScopedSuggestions(scope)')
  && app.includes('if (!supportsScopedSuggestions(activeScope)) return;')
  && app.includes('Search suggestions currently have their own persistent legacy'),
  'unsupported_typed_scope_suggestions_must_not_silently_fall_back_to_an_unscoped_request');
check(app.includes("setSearchContext({ kind: 'ALBUM'")
  && app.includes("setSearchContext({ kind: 'PERSON'")
  && app.includes('function normalizeSearchContext(value)'),
  'app_must_preserve_typed_album_and_person_context_without_relabeling_ids_as_album_scope');
check(app.includes("history.replaceState({ classArchiveLegacySearch: true }, '', '/home?search=1')")
  && app.includes("window.addEventListener('popstate'")
  && app.includes('runtime.searchOverlay.close()'),
  'legacy_search_and_browser_back_must_resolve_to_the_global_overlay_not_a_separate_search_page');

for (const key of [
  'search.scopeLabel', 'search.scopeAll', 'search.scopeAlbum', 'search.scopePerson',
  'search.scopeMemory', 'search.scopeCollection', 'upload.roleClassmate', 'upload.roleTeacher',
  'upload.eraSummary', 'search.smartUnavailable', 'search.loadMore', 'search.loadingMore',
]) {
  check(i18n.includes(`'${key}'`), `${key}_must_be_centralized_in_i18n`);
}
check(upload.includes("const DIRECT_MEMBER_ROLES = new Set(['CLASSMATE', 'TEACHER'])")
  && upload.includes('function directMemberRoleNote(role)')
  && upload.includes("const eraSummary = element('p', 'era-upload-summary')")
  && upload.includes("t('upload.eraSummary', { era: eraLabel(era) })"),
  'era_upload_must_explain_member_context_and_the_explicit_selected_era');
check(!upload.includes('fetch(') && !upload.includes('/api/') && !upload.includes('runtime.'),
  'era_dialog_must_not_weaken_server_owned_upload_policy');
check(app.includes('actorRole: state.role')
  && app.includes("['CLASSMATE', 'TEACHER'].includes(state.role)")
  && app.includes("location.assign('/class-archive-core/identity')"),
  'family_pending_submission_must_remain_separate_from_direct_member_era_publish');
check(css.includes('.global-search-scope')
  && css.includes('.era-upload-role-note')
  && css.includes('.era-upload-summary')
  && css.includes('env(safe-area-inset-bottom)'),
  'new_controls_must_reuse_existing_tokens_and_keep_mobile_safe_area_support');
check(app.includes('function normalizeCollectionHomePreferences(payload)')
  && app.includes("'safe_collection_home_preferences_target_invalid'")
  && app.includes("'safe_collection_home_feedback_invalid'")
  && app.includes("preferences: normalizeCollectionHomePreferences(payload.preferences)"),
  'per_principal_collection_feedback_must_be_validated_as_a_narrow_extension_of_the_signed_home_shape');
check(app.includes("'/api/class-archive/collections/feedback/set'")
  && app.includes("'/api/class-archive/collections/feedback/clear'")
  && app.includes("feedback === 'HIDE'")
  && app.includes('function hiddenCollectionPreferences(hidden, onHomeChanged)')
  && app.includes('restore.dataset.collectionFeedbackRestore'),
  'home_feedback_must_use_the_existing_csrf_mutation_contract_and_offer_a_quiet_restore_surface');
for (const key of [
  'home.lessLike', 'home.restoreRecommendation', 'home.hide', 'home.hiddenCount', 'home.restore',
]) {
  check(i18n.includes(`'${key}'`), `${key}_must_be_centralized_in_i18n`);
}
check(css.includes('.home-collection-feedback')
  && css.includes('.home-hidden-preferences')
  && css.includes('var(--line)')
  && css.includes('var(--ink-soft)'),
  'collection_feedback_controls_must_reuse_existing_photo_ui_tokens_without_a_dashboard_card');
check(!/\p{Script=Han}/u.test(app) && !/\p{Script=Han}/u.test(upload) && !/\p{Script=Han}/u.test(overlay),
  'member_facing_ui_copy_must_remain_in_i18n');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-photos-app-v4-ui-contract', assertions, result: 'PASS', evidence: 'STATIC_ONLY' })}\n`);
