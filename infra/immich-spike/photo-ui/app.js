import { applyDocumentTranslations, t } from './i18n.js?v=__PHOTO_UI_ASSET_REV__';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const TIMELINE_CURSOR = /^[A-Za-z0-9_-]{48}$/;
const app = document.getElementById('app');
const MOBILE_NAVIGATION = new Set(['home', 'photos', 'people', 'search', 'albums', 'my']);
const MUTATION_PATHS = new Set([
  '/api/class-archive/manage/people/create',
  '/api/class-archive/manage/people/update',
  '/api/class-archive/manage/people/merge',
  '/api/class-archive/manage/people/visibility',
  '/api/class-archive/manage/people/revert-merge',
  '/api/class-archive/manage/people/move-photos',
  '/api/class-archive/manage/archive/bulk',
  '/api/class-archive/manage/albums/cover',
  '/api/class-archive/manage/duplicates/consolidate',
  '/api/class-archive/spotlight/create',
  '/api/class-archive/spotlight/cancel',
  '/api/class-archive/comments/create',
  '/api/class-archive/comments/reply',
  '/api/class-archive/manage/comments/delete',
]);
const PRESENTATION_CACHE_PREFIX = 'class-archive-photo-ui-v3:';
const PRESENTATION_CACHE_SCOPE_KEY = `${PRESENTATION_CACHE_PREFIX}active-scope`;
const PRESENTATION_CACHE_PATHS = new Set([
  '/api/class-archive/timeline',
  '/api/class-archive/spotlight',
  '/api/class-archive/albums',
  '/api/class-archive/memories',
  '/api/class-archive/home',
  '/api/people?size=500&withHidden=false',
]);
const PRESENTATION_CACHE_MAX_AGE_MS = 12 * 60 * 60 * 1000;

const runtime = {
  productStatePromise: null,
  productStateFailure: null,
  manageOptionsPromise: null,
  activeSelection: null,
  dialogSequence: 0,
  cacheScope: null,
  sessionValidationGeneration: 0,
  presentationFailureActive: false,
  timelinePageObserver: null,
};

const navigation = Object.freeze([
  { key: 'home', href: '/home' },
  { key: 'photos', href: '/photos' },
  { key: 'people', href: '/people' },
  { key: 'search', href: '/search' },
  { key: 'albums', href: '/albums' },
  { key: 'memories', href: '/memories' },
  { key: 'my', href: '/my' },
]);

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function append(parent, ...children) {
  for (const child of children.flat()) {
    if (child !== null && child !== undefined) parent.append(child);
  }
  return parent;
}

function navLink(item, active, mobile = false) {
  const link = element('a', 'nav-link');
  link.href = item.href;
  link.setAttribute('aria-label', t(`nav.${item.key}`));
  if (active === item.key) link.setAttribute('aria-current', 'page');
  const label = element('span', mobile ? 'mobile-label' : 'nav-label', t(`nav.${item.key}`));
  link.append(label);
  return link;
}

function sidebar(active) {
  const side = element('aside', 'sidebar');
  const brand = element('a', 'brand');
  brand.href = '/home';
  append(
    brand,
    element('span', 'brand-title', t('product.name')),
    element('span', 'brand-subtitle', t('product.subtitle')),
  );
  const nav = element('nav', 'nav-list');
  nav.setAttribute('aria-label', t('accessibility.primaryNav'));
  append(nav, navigation.map((item) => navLink(item, active)));
  const footer = element('div', 'sidebar-footer');
  const about = element('a', '', t('nav.about'));
  about.href = '/class-archive-about';
  footer.append(about);
  append(side, brand, nav, footer);
  return side;
}

function mobileNavigation(active) {
  const nav = element('nav', 'mobile-nav');
  nav.setAttribute('aria-label', t('accessibility.mobileNav'));
  append(nav, navigation.filter((item) => MOBILE_NAVIGATION.has(item.key)).map((item) => navLink(item, active, true)));
  return nav;
}

function shell(active, content) {
  const main = element('main', 'main');
  main.id = 'main-content';
  const width = element('div', 'content');
  width.append(content);
  main.append(width);
  app.replaceChildren(sidebar(active), main, mobileNavigation(active));
}

function pageHeader(titleKey, leadKey, totalText = '') {
  const header = element('header', 'page-header');
  const copy = element('div');
  append(
    copy,
    element('p', 'page-eyebrow', t('product.name')),
    element('h1', 'page-title', t(titleKey)),
    element('p', 'page-lead', t(leadKey)),
  );
  append(header, copy, totalText ? element('div', 'page-total', totalText) : null);
  return header;
}

function emptyState(titleKey, bodyKey) {
  const state = element('section', 'empty-state');
  append(state, element('h2', '', t(titleKey)), element('p', '', t(bodyKey)));
  return state;
}

function loadingState() {
  const state = element('section', 'photo-loading');
  state.setAttribute('aria-live', 'polite');
  state.append(element('span', 'sr-only', t('common.loading')));
  const heading = element('div', 'loading-heading');
  append(heading, element('span', 'skeleton-line skeleton-line-title'), element('span', 'skeleton-line skeleton-line-meta'));
  const grid = element('div', 'loading-photo-grid');
  for (let index = 0; index < 12; index += 1) {
    const tile = element('span', 'loading-photo-tile');
    tile.style.setProperty('--skeleton-ratio', index % 4 === 0 ? '1.36' : index % 3 === 0 ? '.78' : '1.05');
    grid.append(tile);
  }
  append(state, heading, grid);
  return state;
}

function errorState() {
  const state = element('section', 'error-state');
  const button = element('button', 'primary-button', t('common.retry'));
  button.type = 'button';
  button.addEventListener('click', () => location.reload());
  append(state, element('h2', '', t('common.safeErrorTitle')), element('p', '', t('common.safeErrorBody')), button);
  return state;
}

function concealPrivatePresentation() {
  document.documentElement.dataset.sessionRevalidating = 'true';
  app.setAttribute('aria-busy', 'true');
}

function revealPrivatePresentation() {
  delete document.documentElement.dataset.sessionRevalidating;
  app.removeAttribute('aria-busy');
}

function failClosedPresentation(error) {
  clearPresentationCache();
  concealPrivatePresentation();
  runtime.presentationFailureActive = true;
  runtime.timelinePageObserver?.disconnect();
  runtime.timelinePageObserver = null;
  if (error?.status === 401 || error?.status === 403) return;
  if (runtime.activeSelection) runtime.activeSelection.destroy();
  app.replaceChildren(errorState());
  if (document.visibilityState === 'visible') revealPrivatePresentation();
}

function assertPresentationActive() {
  if (runtime.presentationFailureActive) throw new Error('safe_presentation_fail_closed');
}

function showLoading(active, titleKey, leadKey) {
  if (runtime.activeSelection) runtime.activeSelection.destroy();
  const page = element('div');
  append(page, pageHeader(titleKey, leadKey), loadingState());
  shell(active, page);
}

function safeText(value, fallback = '') {
  return typeof value === 'string' && value.length > 0 && value.length <= 300 ? value : fallback;
}

function businessLabel(value, fallbackKey = '') {
  const labels = new Map([
    ['HERITAGE', t('business.heritage')],
    ['LIVING', t('business.living')],
    ['OFFICIAL', t('business.officialArchive')],
    ['COMMUNITY', t('business.communityAlbum')],
  ]);
  if (labels.has(value)) return labels.get(value);
  const text = safeText(value, '');
  if (text && !/^[A-Z][A-Z0-9_:-]*$/.test(text)) return text;
  return fallbackKey ? t(fallbackKey) : '';
}

function precisionLabel(value) {
  const labels = new Map([
    ['EXACT', t('precision.exact')],
    ['DAY', t('precision.day')],
    ['MONTH', t('precision.month')],
    ['TERM', t('precision.term')],
    ['YEAR', t('precision.year')],
    ['EVENT_ONLY', t('precision.eventOnly')],
    ['UNKNOWN', t('precision.unknown')],
  ]);
  return labels.get(value) ?? t('precision.unknown');
}

function dateSourceLabel(value) {
  const labels = new Map([
    ['ARCHIVE_CONFIRMED', t('dateSource.confirmed')],
    ['EVENT_INFERENCE', t('dateSource.event')],
    ['EXIF_TRUSTED', t('dateSource.exif')],
    ['UNKNOWN', t('dateSource.unknown')],
  ]);
  return labels.get(value) ?? t('dateSource.unknown');
}

function rawArchiveDate(photo) {
  const precision = typeof photo?.date_precision === 'string' ? photo.date_precision : 'UNKNOWN';
  const source = typeof photo?.date_source === 'string' ? photo.date_source : 'UNKNOWN';
  const takenAt = typeof photo?.taken_at === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(photo.taken_at)
    ? photo.taken_at : null;
  const eventLabel = safeText(photo?.eventLabel ?? photo?.event_label, '');
  let label = t('common.unknownDate');
  if (takenAt && ['EXACT', 'DAY'].includes(precision)) {
    label = t('date.dayLabel', { year: takenAt.slice(0, 4), month: takenAt.slice(5, 7), day: takenAt.slice(8, 10) });
  } else if (takenAt && precision === 'MONTH') {
    label = t('date.monthLabel', { year: takenAt.slice(0, 4), month: takenAt.slice(5, 7) });
  } else if (takenAt && precision === 'YEAR') {
    label = t('date.yearLabel', { year: takenAt.slice(0, 4) });
  } else if (eventLabel) {
    label = eventLabel;
  }
  return { label, precision: precisionLabel(precision), source: dateSourceLabel(source) };
}

function roleLabel(value) {
  const labels = new Map([
    ['SYSTEM_ADMIN', t('business.roleAdmin')],
    ['ARCHIVIST', t('business.roleArchivist')],
    ['CLASSMATE', t('business.roleClassmate')],
    ['TEACHER', t('business.roleTeacher')],
    ['FAMILY', t('business.roleFamily')],
    ['ANONYMOUS', t('business.roleAnonymous')],
  ]);
  return labels.get(value) ?? t('business.roleUnknown');
}

function validId(value) {
  return typeof value === 'string' && UUID_V4.test(value);
}

function opaqueChoiceId(value) {
  if (validId(value)) return value.toLowerCase();
  if (Number.isSafeInteger(value) && value > 0) return String(value);
  if (typeof value === 'string' && /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(value)) return value;
  return null;
}

function apiError(response) {
  const error = new Error('safe_api_error');
  error.status = response.status;
  return error;
}

function clearPresentationCache() {
  runtime.cacheScope = null;
  try {
    for (let index = sessionStorage.length - 1; index >= 0; index -= 1) {
      const key = sessionStorage.key(index);
      if (key?.startsWith('class-archive-photo-ui-v')) sessionStorage.removeItem(key);
    }
  } catch {
    // A disabled/full browser storage area is a performance miss, never an
    // authorization fallback. The next request still goes to the server.
  }
}

function reloadProjectionBackedRoute() {
  // A successful mutation can rotate the server presentation epoch before a
  // same-tab reload observes the new product state. Session storage survives
  // that reload, so an old projection could otherwise paint once and remain
  // visible on pages that do not repaint their SWR refresh (album detail in
  // particular). Remove the old epoch-bound payload before navigation and
  // conceal the private DOM while the fresh document is loading.
  runtime.productStatePromise = null;
  runtime.productStateFailure = null;
  runtime.presentationFailureActive = false;
  clearPresentationCache();
  concealPrivatePresentation();
  location.reload();
}

function presentationCacheKey(path) {
  if (!runtime.cacheScope || !PRESENTATION_CACHE_PATHS.has(path)) return null;
  return `${PRESENTATION_CACHE_PREFIX}${runtime.cacheScope}:${path}`;
}

function readPresentationCache(path) {
  const key = presentationCacheKey(path);
  if (!key) return null;
  try {
    const record = JSON.parse(sessionStorage.getItem(key) ?? 'null');
    if (!record || record.scope !== runtime.cacheScope || record.path !== path
      || !Number.isFinite(record.storedAt) || Date.now() - record.storedAt > PRESENTATION_CACHE_MAX_AGE_MS
      || !Object.hasOwn(record, 'payload')) {
      sessionStorage.removeItem(key);
      return null;
    }
    return record.payload;
  } catch {
    try { sessionStorage.removeItem(key); } catch { }
    return null;
  }
}

function writePresentationCache(path, payload) {
  const key = presentationCacheKey(path);
  if (!key) return;
  try {
    sessionStorage.setItem(key, JSON.stringify({
      scope: runtime.cacheScope,
      path,
      storedAt: Date.now(),
      payload,
    }));
  } catch {
    // Quota/storage denial must not alter the fresh server result.
  }
}

async function apiJson(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    cache: options.cache ?? 'no-cache',
    ...options,
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
  });
  if (response.status === 401 || response.status === 403) {
    clearPresentationCache();
    location.assign('/auth/login');
    throw apiError(response);
  }
  if (!response.ok || !(response.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) {
    throw apiError(response);
  }
  return response.json();
}

async function presentationJson(path) {
  if (!PRESENTATION_CACHE_PATHS.has(path)) throw new Error('safe_presentation_cache_path_invalid');
  assertPresentationActive();
  const state = await productState();
  if (state.role === 'UNKNOWN' || !state.cacheScope) {
    const error = runtime.productStateFailure ?? new Error('safe_product_state_unavailable');
    failClosedPresentation(error);
    throw error;
  }
  const verifiedScope = state.cacheScope;
  const cached = readPresentationCache(path);
  const refresh = apiJson(path, { cache: 'no-cache' }).then((payload) => {
    if (runtime.presentationFailureActive || runtime.cacheScope !== verifiedScope
      || document.visibilityState !== 'visible') {
      throw new Error('safe_presentation_session_changed');
    }
    writePresentationCache(path, payload);
    return payload;
  }).catch((error) => {
    failClosedPresentation(error);
    throw error;
  });
  // Some pages intentionally paint an already verified session-scoped value
  // before the refresh completes. Register a rejection observer even when the
  // page does not need the fresh value for repainting; failClosedPresentation
  // has already removed the stale DOM before this observer settles.
  refresh.catch(() => undefined);
  if (cached !== null) return { value: cached, refresh, cacheHit: true };
  return { value: await refresh, refresh: null, cacheHit: false };
}

function normalizeProductState(payload) {
  const knownRoles = new Set(['SYSTEM_ADMIN', 'ARCHIVIST', 'CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS']);
  const role = knownRoles.has(payload?.role) ? payload.role : 'UNKNOWN';
  const presentationEpoch = typeof payload?.presentationEpoch === 'string'
    && /^[a-f0-9]{64}$/.test(payload.presentationEpoch) ? payload.presentationEpoch : null;
  const cacheScope = presentationEpoch !== null && typeof payload?.cacheScope === 'string'
    && /^[a-f0-9]{32}$/.test(payload.cacheScope) ? payload.cacheScope : null;
  return {
    role,
    canManage: (payload?.canManage === true || payload?.can_manage === true) && ['SYSTEM_ADMIN', 'ARCHIVIST'].includes(role),
    canSpotlight: (payload?.canSpotlight === true || payload?.can_spotlight === true) && ['CLASSMATE', 'TEACHER'].includes(role),
    csrfToken: safeText(payload?.csrfToken ?? payload?.csrf_token, ''),
    presentationEpoch,
    cacheScope,
  };
}

async function productState() {
  if (!runtime.productStatePromise) {
    runtime.productStatePromise = apiJson('/api/class-archive/product-state')
      .then((payload) => {
        runtime.productStateFailure = null;
        const state = normalizeProductState(payload);
        if (!state.cacheScope || state.role === 'UNKNOWN') {
          clearPresentationCache();
          return state;
        }
        let storedScope = null;
        try { storedScope = sessionStorage.getItem(PRESENTATION_CACHE_SCOPE_KEY); } catch { }
        if ((runtime.cacheScope !== null && runtime.cacheScope !== state.cacheScope)
          || (storedScope !== null && storedScope !== state.cacheScope)) {
          clearPresentationCache();
        }
        runtime.cacheScope = state.cacheScope;
        try { sessionStorage.setItem(PRESENTATION_CACHE_SCOPE_KEY, state.cacheScope); } catch { }
        return state;
      })
      .catch((error) => {
        runtime.productStateFailure = error;
        clearPresentationCache();
        return normalizeProductState(null);
      });
  }
  return runtime.productStatePromise;
}

async function mutate(path, payload) {
  if (!MUTATION_PATHS.has(path)) throw new Error('safe_mutation_path_invalid');
  const state = await productState();
  if (!state.csrfToken) throw new Error('safe_csrf_unavailable');
  return apiJson(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Class-Archive-CSRF': state.csrfToken },
    body: JSON.stringify({ ...payload, csrfToken: state.csrfToken }),
  });
}

function toast(message, kind = 'success') {
  const existing = document.querySelector('.toast');
  if (existing) existing.remove();
  const node = element('div', `toast toast-${kind}`, message);
  node.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  document.body.append(node);
  requestAnimationFrame(() => node.dataset.visible = 'true');
  setTimeout(() => {
    node.dataset.visible = 'false';
    setTimeout(() => node.remove(), 220);
  }, 3200);
}

function dialogShell(titleKey, leadKey = '') {
  runtime.dialogSequence += 1;
  const returnFocus = document.activeElement;
  const dialog = element('dialog', 'app-dialog');
  const surface = element('div', 'dialog-surface');
  const header = element('header', 'dialog-header');
  const copy = element('div');
  const title = element('h2', '', t(titleKey));
  title.id = `dialog-title-${runtime.dialogSequence}`;
  const lead = leadKey ? element('p', '', t(leadKey)) : null;
  if (lead) lead.id = `dialog-lead-${runtime.dialogSequence}`;
  append(copy, title, lead);
  dialog.setAttribute('aria-labelledby', title.id);
  if (lead) dialog.setAttribute('aria-describedby', lead.id);
  const close = element('button', 'icon-button dialog-close', t('common.close'));
  close.type = 'button';
  close.setAttribute('aria-label', t('accessibility.dialogClose'));
  close.addEventListener('click', () => dialog.close());
  append(header, copy, close);
  append(surface, header);
  dialog.append(surface);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  dialog.addEventListener('close', () => {
    dialog.remove();
    if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus();
  }, { once: true });
  document.body.append(dialog);
  dialog.showModal();
  return { dialog, surface };
}

function labeledControl(labelKey, control, hint = '') {
  const label = element('label', 'field');
  append(label, element('span', 'field-label', t(labelKey)), control, hint ? element('span', 'field-hint', hint) : null);
  return label;
}

function labeledGroup(labelKey, content) {
  const group = element('fieldset', 'field fieldset-reset');
  append(group, element('legend', 'field-label', t(labelKey)), content);
  return group;
}

function readReason(control) {
  const value = control.value.trim();
  control.setCustomValidity(value ? '' : t('common.reasonRequired'));
  if (!value) {
    control.reportValidity();
    control.focus();
    return null;
  }
  return value;
}

function option(value, label, selected = false) {
  const node = element('option', '', label);
  node.value = value;
  node.selected = selected;
  return node;
}

function normalizeArchivePhoto(photo) {
  if (!photo || !validId(photo.id)) throw new Error('safe_photo_invalid');
  const archive = photo.archive_date && typeof photo.archive_date === 'object' ? photo.archive_date : {};
  const fallback = rawArchiveDate(photo);
  const width = Number.isSafeInteger(photo.width) && photo.width > 0 && photo.width <= 200000 ? photo.width : null;
  const height = Number.isSafeInteger(photo.height) && photo.height > 0 && photo.height <= 200000 ? photo.height : null;
  const mediaRevision = typeof photo.media_revision === 'string' && /^[a-f0-9]{32}$/.test(photo.media_revision)
    ? photo.media_revision : '';
  return {
    id: photo.id.toLowerCase(),
    title: businessLabel(photo.title, 'accessibility.photo'),
    eventLabel: safeText(photo.eventLabel ?? photo.event_label, ''),
    width,
    height,
    mediaRevision,
    archiveDate: {
      label: safeText(archive.label, fallback.label),
      precision: safeText(archive.precision, fallback.precision),
      source: safeText(archive.sourceLabel ?? archive.source_label ?? archive.source, fallback.source),
    },
  };
}

function normalizeTimeline(payload) {
  if (!payload || !Number.isInteger(payload.total) || payload.total < 0
    || !Number.isInteger(payload.count) || payload.count < 0 || payload.count > payload.total
    || !Number.isInteger(payload.limit) || payload.limit < 1 || payload.limit > 240
    || payload.count > payload.limit || typeof payload.hasMore !== 'boolean'
    || (payload.hasMore && (typeof payload.nextCursor !== 'string' || !TIMELINE_CURSOR.test(payload.nextCursor)))
    || (!payload.hasMore && payload.nextCursor !== null)
    || typeof payload.cacheScope !== 'string' || !/^[a-f0-9]{32}$/.test(payload.cacheScope)
    || payload.cacheScope !== runtime.cacheScope || !Array.isArray(payload.groups)
    || (payload.total === 0 && (payload.count !== 0 || payload.groups.length !== 0 || payload.hasMore))
    || (payload.total > 0 && payload.count === 0)) {
    throw new Error('safe_timeline_invalid');
  }
  const ids = new Set();
  const groups = payload.groups.map((group) => {
    if (!group || typeof group.key !== 'string' || typeof group.kind !== 'string'
      || !Array.isArray(group.items) || !Number.isInteger(group.total) || group.total < 1
      || !Number.isInteger(group.count) || group.count < 1 || group.count > group.total
      || group.count !== group.items.length) {
      throw new Error('safe_timeline_group_invalid');
    }
    const items = group.items.map(normalizeArchivePhoto);
    for (const photo of items) {
      if (ids.has(photo.id)) throw new Error('safe_timeline_duplicate');
      ids.add(photo.id);
    }
    return {
      key: group.key,
      kind: group.kind,
      label: businessLabel(group.label, 'common.unknownDate'),
      total: group.total,
      count: group.count,
      items,
    };
  });
  if (ids.size !== payload.count) throw new Error('safe_timeline_total_invalid');
  return {
    total: payload.total,
    count: payload.count,
    limit: payload.limit,
    groups,
    hasMore: payload.hasMore,
    nextCursor: payload.nextCursor,
    cacheScope: payload.cacheScope,
  };
}

function mergeTimelinePages(current, next) {
  if (!current || !next || current.total !== next.total || current.limit !== next.limit
    || current.cacheScope !== next.cacheScope || current.cacheScope !== runtime.cacheScope
    || current.hasMore !== true || current.nextCursor === null) {
    throw new Error('safe_timeline_page_state_invalid');
  }
  const groups = current.groups.map((group) => ({ ...group, items: [...group.items] }));
  const byKey = new Map(groups.map((group) => [group.key, group]));
  const ids = new Set(groups.flatMap((group) => group.items.map((photo) => photo.id)));
  for (const incoming of next.groups) {
    let target = byKey.get(incoming.key);
    if (target === undefined) {
      target = { ...incoming, items: [] };
      groups.push(target);
      byKey.set(incoming.key, target);
    } else if (target.kind !== incoming.kind || target.label !== incoming.label || target.total !== incoming.total) {
      throw new Error('safe_timeline_page_group_drift');
    }
    for (const photo of incoming.items) {
      if (ids.has(photo.id)) throw new Error('safe_timeline_page_duplicate');
      ids.add(photo.id);
      target.items.push(photo);
    }
    target.count = target.items.length;
    if (target.count > target.total) throw new Error('safe_timeline_page_group_overflow');
  }
  const count = ids.size;
  if (count !== current.count + next.count || count > current.total
    || (!next.hasMore && count !== current.total) || (next.hasMore && count >= current.total)) {
    throw new Error('safe_timeline_page_total_invalid');
  }
  return {
    total: current.total,
    count,
    limit: current.limit,
    groups,
    hasMore: next.hasMore,
    nextCursor: next.nextCursor,
    cacheScope: next.cacheScope,
  };
}

function archivePhotoFromAsset(asset, id, expectedScope) {
  if (!asset || typeof asset.id !== 'string' || asset.id.toLowerCase() !== id
    || !asset.classArchiveDate || typeof asset.classArchiveDate !== 'object'
    || typeof expectedScope !== 'string' || asset.classArchiveCacheScope !== expectedScope) {
    throw new Error('safe_viewer_point_projection_invalid');
  }
  return normalizeArchivePhoto({
    id,
    title: asset.originalFileName,
    archive_date: asset.classArchiveDate,
    media_revision: asset.classArchiveMediaRevision,
    width: asset.classArchiveWidth,
    height: asset.classArchiveHeight,
  });
}

function mediaUrl(id, size, revision = '') {
  if (!validId(id) || !['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'].includes(size)) {
    throw new Error('safe_media_path_invalid');
  }
  if (revision !== '' && !/^[a-f0-9]{32}$/.test(revision)) throw new Error('safe_media_revision_invalid');
  const params = new URLSearchParams({ size });
  if (revision) params.set('v', revision);
  return `/api/assets/${id.toLowerCase()}/thumbnail?${params}`;
}

const lazyImageObserver = typeof IntersectionObserver === 'function'
  ? new IntersectionObserver((entries, observer) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        activateImage(entry.target);
        observer.unobserve(entry.target);
      }
    }, { rootMargin: '700px 0px', threshold: 0.01 })
  : null;

function activateImage(image) {
  if (!(image instanceof HTMLImageElement) || image.dataset.activated === 'true') return;
  image.dataset.activated = 'true';
  if (image.dataset.srcset) image.srcset = image.dataset.srcset;
  if (image.dataset.src) image.src = image.dataset.src;
}

function responsivePhotoSource(photo, purpose = 'grid') {
  const revision = photo?.mediaRevision ?? '';
  const variants = [
    ['xsmall', 432, 324],
    ['small', 576, 432],
    ['medium', 792, 594],
    ['large', 1008, 756],
    ['preview', 1224, 918],
  ];
  const policies = {
    // The desktop shell reserves navigation and gutters, so each of the five
    // masonry columns is about 15vw rather than 20vw. A truthful sizes hint
    // keeps Chrome from choosing the next, unnecessarily heavy derivative.
    grid: { initial: 'xsmall', sizes: '(max-width: 680px) 33vw, (max-width: 1100px) 25vw, 15vw' },
    search: { initial: 'small', sizes: '(max-width: 680px) 33vw, (max-width: 1100px) 50vw, 25vw' },
    portrait: { initial: 'small', sizes: '(max-width: 680px) 28vw, 190px' },
    cover: { initial: 'medium', sizes: '(max-width: 680px) 100vw, (max-width: 1100px) 50vw, 34vw' },
    hero: { initial: 'large', sizes: '100vw' },
    viewer: { initial: 'preview', sizes: '100vw' },
  };
  const policy = policies[purpose] ?? policies.grid;
  const sourceWidth = Number(photo?.width);
  const sourceHeight = Number(photo?.height);
  const hasDimensions = Number.isFinite(sourceWidth) && sourceWidth > 0
    && Number.isFinite(sourceHeight) && sourceHeight > 0;
  const responsiveVariants = [];
  let previousWidth = 0;
  for (const [variant, maxWidth, maxHeight] of variants) {
    const outputWidth = hasDimensions
      ? Math.max(1, Math.floor(sourceWidth * Math.min(1, maxWidth / sourceWidth, maxHeight / sourceHeight)))
      : Math.min(maxWidth, maxHeight);
    if (outputWidth <= previousWidth) continue;
    responsiveVariants.push(`${mediaUrl(photo.id, variant, revision)} ${outputWidth}w`);
    previousWidth = outputWidth;
  }
  return {
    src: mediaUrl(photo.id, policy.initial, revision),
    // Piwigo profiles are bounding boxes, not guaranteed output widths. Using
    // the box width as a srcset descriptor makes portrait photos look blurry
    // because the browser overestimates the pixels it will receive. Describe
    // the projected, aspect-ratio-preserving output width instead.
    srcset: responsiveVariants.join(', '),
    sizes: policy.sizes,
    aspectRatio: photo.width && photo.height ? `${photo.width} / ${photo.height}` : '',
  };
}

const adjacentPreviewCache = new Map();

function prefetchAdjacentPreviews(photos, index) {
  for (const offset of [-1, 1]) {
    const adjacent = photos[index + offset];
    if (!adjacent || adjacentPreviewCache.has(adjacent.id)) continue;
    const preview = new Image();
    preview.decoding = 'async';
    preview.referrerPolicy = 'no-referrer';
    preview.src = mediaUrl(adjacent.id, 'preview', adjacent.mediaRevision ?? '');
    adjacentPreviewCache.set(adjacent.id, preview);
  }
  while (adjacentPreviewCache.size > 4) {
    adjacentPreviewCache.delete(adjacentPreviewCache.keys().next().value);
  }
}

function resilientImage(src, alt, eager = false, options = {}) {
  const image = element('img');
  image.alt = alt;
  image.loading = eager ? 'eager' : 'lazy';
  image.decoding = 'async';
  image.referrerPolicy = 'no-referrer';
  if (typeof options.sizes === 'string' && options.sizes) image.sizes = options.sizes;
  if (typeof options.srcset === 'string' && options.srcset) image.dataset.srcset = options.srcset;
  if (typeof options.aspectRatio === 'string' && options.aspectRatio) image.style.aspectRatio = options.aspectRatio;
  if (eager) image.fetchPriority = 'high';
  image.dataset.src = src;
  let failures = 0;
  image.addEventListener('load', () => {
    failures = 0;
    delete image.dataset.loadState;
  });
  image.addEventListener('error', () => {
    failures += 1;
    if (failures <= 2) {
      image.dataset.loadState = 'retrying';
      image.dataset.activated = 'false';
      image.removeAttribute('src');
      image.removeAttribute('srcset');
      setTimeout(() => activateImage(image), failures * 900);
      return;
    }
    // Keep a translated error state in the authorized card. Removing the
    // element left a silent grey tile and made a transient cold derivative
    // timeout look like a missing photo.
    image.dataset.loadState = 'error';
    image.alt = alt || t('common.imageUnavailable');
  });
  if (eager || lazyImageObserver === null) activateImage(image);
  else lazyImageObserver.observe(image);
  return image;
}

function responsivePhotoImage(photo, purpose = 'grid', alt = '', eager = false) {
  const source = responsivePhotoSource(photo, purpose);
  return resilientImage(source.src, alt, eager, source);
}

function normalizeManageOptions(payload) {
  const normalizeChoices = (items, idKeys = ['id'], labelKeys = ['label', 'name']) => (Array.isArray(items) ? items : []).flatMap((item) => {
    if (!item || typeof item !== 'object') return [];
    const id = idKeys.map((key) => opaqueChoiceId(item[key])).find(Boolean);
    const label = labelKeys.map((key) => item[key]).find((value) => typeof value === 'string' && value.length > 0);
    return id && label ? [{ id, label: safeText(label, '') }] : [];
  });
  return {
    albums: normalizeChoices(payload?.albums, ['id', 'albumId'], ['name', 'label']),
    events: normalizeChoices(payload?.events, ['id', 'eventId', 'label'], ['label', 'name']),
    identities: normalizeChoices(payload?.identities, ['id', 'identityId'], ['displayName', 'name', 'label']),
  };
}

async function manageOptions() {
  if (!runtime.manageOptionsPromise) {
    runtime.manageOptionsPromise = apiJson('/api/class-archive/manage/options').then(normalizeManageOptions);
  }
  return runtime.manageOptionsPromise;
}

function checkboxChoices(items, name) {
  const group = element('div', 'choice-grid');
  if (items.length === 0) {
    group.append(element('span', 'field-hint', t('common.empty')));
    return group;
  }
  for (const item of items) {
    const label = element('label', 'check-choice');
    const input = element('input');
    input.type = 'checkbox';
    input.name = name;
    input.value = item.id;
    append(label, input, element('span', '', item.label));
    group.append(label);
  }
  return group;
}

async function openBulkOrganizer(controller) {
  if (controller.selected.size === 0) return;
  let options;
  try {
    options = await manageOptions();
  } catch {
    toast(t('common.operationFailed'), 'error');
    return;
  }
  const { dialog, surface } = dialogShell('photos.bulkTitle', 'photos.bulkLead');
  const form = element('form', 'dialog-form');
  form.method = 'dialog';

  const archiveDate = element('input', 'text-field');
  archiveDate.type = 'text';
  archiveDate.maxLength = 32;
  archiveDate.placeholder = 'YYYY / YYYY-MM / YYYY-MM-DD';

  const precision = element('select', 'select-field');
  append(precision,
    option('', t('precision.keep')),
    option('EXACT', t('precision.exact')),
    option('DAY', t('precision.day')),
    option('MONTH', t('precision.month')),
    option('TERM', t('precision.term')),
    option('YEAR', t('precision.year')),
    option('EVENT_ONLY', t('precision.eventOnly')),
    option('UNKNOWN', t('precision.unknown')),
  );

  const eventSelect = element('select', 'select-field');
  append(eventSelect, option('', t('precision.keep')), options.events.map((item) => option(item.id, item.label)));
  const eventCustom = element('input', 'text-field');
  eventCustom.type = 'text';
  eventCustom.maxLength = 190;
  eventCustom.placeholder = t('photos.bulkEventCustomPlaceholder');
  eventSelect.addEventListener('change', () => {
    eventCustom.disabled = eventSelect.value !== '';
    if (eventSelect.value !== '') eventCustom.value = '';
  });
  eventCustom.addEventListener('input', () => {
    eventSelect.disabled = eventCustom.value.trim() !== '';
    if (eventCustom.value.trim() !== '') eventSelect.value = '';
  });

  const era = element('select', 'select-field');
  append(era, option('', t('era.keep')), option('HERITAGE', t('business.heritage')), option('LIVING', t('business.living')));
  const eraConfirmation = element('label', 'confirm-row');
  const eraConfirmInput = element('input');
  eraConfirmInput.type = 'checkbox';
  append(eraConfirmation, eraConfirmInput, element('span', '', t('photos.bulkEraConfirm')));
  eraConfirmation.hidden = true;
  era.addEventListener('change', () => {
    eraConfirmation.hidden = era.value === '';
    if (era.value === '') eraConfirmInput.checked = false;
  });

  const reason = element('textarea', 'text-area');
  reason.required = true;
  reason.maxLength = 500;
  reason.placeholder = t('common.reasonPlaceholder');

  const addAlbums = checkboxChoices(options.albums, 'addAlbum');
  const removeAlbums = checkboxChoices(options.albums, 'removeAlbum');
  append(form,
    labeledControl('photos.bulkDate', archiveDate),
    labeledControl('photos.bulkPrecision', precision),
    labeledControl('photos.bulkEvent', eventSelect),
    labeledControl('photos.bulkEventCustom', eventCustom),
    labeledGroup('photos.bulkAddAlbums', addAlbums),
    labeledGroup('photos.bulkRemoveAlbums', removeAlbums),
    labeledControl('photos.bulkEra', era),
    eraConfirmation,
    labeledControl('common.reason', reason),
  );
  const actions = element('div', 'dialog-actions');
  const cancel = element('button', 'secondary-button', t('common.cancel'));
  cancel.type = 'button';
  cancel.addEventListener('click', () => dialog.close());
  const submit = element('button', 'primary-button', t('photos.bulkSubmit'));
  submit.type = 'submit';
  append(actions, cancel, submit);
  form.append(actions);
  surface.append(form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reasonValue = readReason(reason);
    if (!reasonValue) return;
    if (era.value && !eraConfirmInput.checked) {
      eraConfirmInput.focus();
      return;
    }
    submit.disabled = true;
    const checkedValues = (name) => [...form.querySelectorAll(`input[name="${name}"]:checked`)].map((input) => input.value);
    try {
      await mutate('/api/class-archive/manage/archive/bulk', {
        photoIds: [...controller.selected],
        archiveDate: archiveDate.value.trim() || null,
        datePrecision: precision.value || null,
        eventId: eventSelect.value || null,
        eventLabel: eventCustom.value.trim() || null,
        albumAddIds: checkedValues('addAlbum'),
        albumRemoveIds: checkedValues('removeAlbum'),
        era: era.value || null,
        eraConfirmed: Boolean(era.value && eraConfirmInput.checked),
        reason: reasonValue,
      });
      dialog.close();
      controller.clear();
      toast(t('common.operationSucceeded'));
      setTimeout(() => location.reload(), 450);
    } catch {
      submit.disabled = false;
      toast(t('common.operationFailed'), 'error');
    }
  });
}

function selectionController(photos, options = {}) {
  if (runtime.activeSelection) runtime.activeSelection.destroy();
  const controller = {
    photos,
    selected: new Set(),
    lastIndex: null,
    active: false,
    cards: new Map(),
    bar: null,
    options,
    enter() {
      this.active = true;
      this.refresh();
    },
    toggle(index, range = false, forceSelected = null) {
      if (!this.active) this.enter();
      if (range && this.lastIndex !== null) {
        const start = Math.min(index, this.lastIndex);
        const end = Math.max(index, this.lastIndex);
        for (let cursor = start; cursor <= end; cursor += 1) this.selected.add(this.photos[cursor].id);
      } else {
        const id = this.photos[index].id;
        const shouldSelect = forceSelected === null ? !this.selected.has(id) : forceSelected;
        if (shouldSelect) this.selected.add(id);
        else this.selected.delete(id);
      }
      this.lastIndex = index;
      this.refresh();
    },
    clear() {
      this.selected.clear();
      this.lastIndex = null;
      this.active = false;
      this.refresh();
    },
    refresh() {
      for (const [id, card] of this.cards) {
        const selected = this.selected.has(id);
        card.dataset.selecting = String(this.active);
        card.dataset.selected = String(selected);
        card.setAttribute('aria-selected', String(selected));
      }
      if (!this.bar) return;
      this.bar.hidden = !this.active;
      this.bar.querySelector('[data-selection-count]').textContent = t('photos.selectedCount', { count: this.selected.size });
      for (const button of this.bar.querySelectorAll('[data-requires-selection]')) button.disabled = this.selected.size === 0;
      const single = this.bar.querySelector('[data-requires-single]');
      if (single) single.disabled = this.selected.size !== 1;
    },
    attachBar() {
      const bar = element('aside', 'selection-bar');
      bar.hidden = true;
      bar.setAttribute('aria-label', t('accessibility.selection'));
      const count = element('strong', 'selection-count');
      count.dataset.selectionCount = '';
      const actions = element('div', 'selection-actions');
      const organize = element('button', 'primary-button', t('photos.organize'));
      organize.type = 'button';
      organize.dataset.requiresSelection = '';
      organize.addEventListener('click', () => openBulkOrganizer(this));
      append(actions, organize);
      if (typeof this.options.onSetCover === 'function') {
        const cover = element('button', 'secondary-button', t('albums.setCover'));
        cover.type = 'button';
        cover.dataset.requiresSingle = '';
        cover.addEventListener('click', () => this.options.onSetCover([...this.selected][0]));
        actions.append(cover);
      }
      const cancel = element('button', 'ghost-button', t('photos.exitSelection'));
      cancel.type = 'button';
      cancel.addEventListener('click', () => this.clear());
      append(actions, cancel);
      append(bar, count, actions);
      document.body.append(bar);
      this.bar = bar;
      this.refresh();
    },
    bind(card, index) {
      const photo = this.photos[index];
      this.cards.set(photo.id, card);
      card.setAttribute('role', 'option');
      card.setAttribute('aria-selected', 'false');
      let longPressTimer = null;
      let longPressed = false;
      const cancelLongPress = () => {
        if (longPressTimer) clearTimeout(longPressTimer);
        longPressTimer = null;
      };
      card.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch' || this.active) return;
        longPressed = false;
        longPressTimer = setTimeout(() => {
          longPressed = true;
          this.toggle(index, false, true);
        }, 520);
      });
      card.addEventListener('pointermove', cancelLongPress);
      card.addEventListener('pointercancel', cancelLongPress);
      card.addEventListener('pointerup', cancelLongPress);
      card.addEventListener('contextmenu', (event) => {
        if (this.active || longPressed) event.preventDefault();
      });
      card.addEventListener('click', (event) => {
        const selectingGesture = this.active || event.ctrlKey || event.metaKey || event.shiftKey || longPressed;
        if (!selectingGesture) return;
        event.preventDefault();
        if (!longPressed) this.toggle(index, event.shiftKey);
        longPressed = false;
      });
    },
    destroy() {
      if (this.bar) this.bar.remove();
      if (runtime.activeSelection === this) runtime.activeSelection = null;
    },
  };
  runtime.activeSelection = controller;
  controller.attachBar();
  return controller;
}

function photoCard(photo, index = 0, controller = null) {
  const link = element('a', 'photo-card');
  link.href = `/photos/${photo.id}`;
  link.setAttribute('aria-label', photo.title);
  const marker = element('span', 'selection-marker', t('accessibility.selected'));
  marker.setAttribute('aria-hidden', 'true');
  const caption = element('span', 'photo-caption');
  append(caption, element('strong', '', photo.title), element('span', '', photo.archiveDate.label));
  append(link, responsivePhotoImage(photo, 'grid', '', index < 6), marker, caption);
  if (controller) {
    const selectionIndex = controller.photos.findIndex((item) => item.id === photo.id);
    if (selectionIndex >= 0) controller.bind(link, selectionIndex);
  }
  return link;
}

function photoGrid(photos, extraClass = '', controller = null) {
  const grid = element('div', `photo-grid ${extraClass}`.trim());
  if (controller) grid.setAttribute('role', 'listbox');
  if (controller) grid.setAttribute('aria-multiselectable', 'true');
  append(grid, photos.map((photo, index) => photoCard(photo, index, controller)));
  return grid;
}

function normalizeSpotlight(payload) {
  if (!payload || payload.active === false) return null;
  // The Class Archive API uses { active: boolean, item: {...} }. Do not let
  // the boolean envelope shadow the actual Spotlight record; legacy payloads
  // that put the record under `spotlight` remain accepted.
  const item = payload?.item ?? payload?.spotlight
    ?? (typeof payload?.active === 'object' ? payload.active : payload);
  if (!item || typeof item !== 'object' || item.active === false) return null;
  const albumId = item.albumId ?? item.album_id ?? item.class_album_id ?? item.targetId;
  const id = opaqueChoiceId(item.id ?? item.spotlightId ?? item.spotlight_id);
  const coverPhotoId = item.coverPhotoId ?? item.cover_photo_id;
  if (!validId(albumId) || !id || !validId(coverPhotoId)) return null;
  return {
    id,
    albumId: albumId.toLowerCase(),
    albumName: safeText(item.albumName ?? item.album_name ?? item.title, t('albums.title')),
    coverPhotoId: coverPhotoId.toLowerCase(),
    description: safeText(item.description, ''),
    expiresAt: formatLocalDateTime(item.expiresAt ?? item.expires_at),
  };
}

function formatLocalDateTime(value) {
  const text = safeText(value, '');
  if (!text) return '';
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(text)
    ? `${text.replace(' ', 'T')}Z`
    : text;
  const date = new Date(normalized);
  if (!Number.isFinite(date.getTime())) return '';
  return new Intl.DateTimeFormat('zh-CN', {
    month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(date);
}

async function openReasonMutation(titleKey, leadKey, path, payload, onSuccess) {
  const { dialog, surface } = dialogShell(titleKey, leadKey);
  const form = element('form', 'dialog-form');
  const reason = element('textarea', 'text-area');
  reason.required = true;
  reason.maxLength = 500;
  reason.placeholder = t('common.reasonPlaceholder');
  form.append(labeledControl('common.reason', reason));
  const actions = element('div', 'dialog-actions');
  const cancel = element('button', 'secondary-button', t('common.cancel'));
  cancel.type = 'button';
  cancel.addEventListener('click', () => dialog.close());
  const submit = element('button', 'primary-button', t('common.confirm'));
  submit.type = 'submit';
  append(actions, cancel, submit);
  form.append(actions);
  surface.append(form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reasonValue = readReason(reason);
    if (!reasonValue) return;
    submit.disabled = true;
    try {
      const result = await mutate(path, { ...payload, reason: reasonValue });
      dialog.close();
      toast(t('common.operationSucceeded'));
      if (onSuccess) await onSuccess(result);
    } catch {
      submit.disabled = false;
      toast(t('common.operationFailed'), 'error');
    }
  });
}

function spotlightHero(spotlight, state) {
  const hero = element('section', 'spotlight-hero');
  const cover = resilientImage(mediaUrl(spotlight.coverPhotoId, 'preview'), '', true, { sizes: '100vw' });
  const shade = element('div', 'spotlight-shade');
  const copy = element('div', 'spotlight-copy');
  append(copy,
    element('p', 'spotlight-eyebrow', t('spotlight.eyebrow')),
    element('h2', '', spotlight.albumName),
    spotlight.description ? element('p', 'spotlight-description', spotlight.description) : null,
    spotlight.expiresAt ? element('p', 'spotlight-expiry', t('spotlight.expires', { time: spotlight.expiresAt })) : null,
  );
  const actions = element('div', 'spotlight-actions');
  const open = element('a', 'light-button', t('spotlight.open'));
  open.href = `/albums/${spotlight.albumId}`;
  actions.append(open);
  if (state.canManage) {
    const cancel = element('button', 'light-ghost-button', t('spotlight.cancel'));
    cancel.type = 'button';
    cancel.addEventListener('click', () => openReasonMutation(
      'spotlight.cancel',
      '',
      '/api/class-archive/spotlight/cancel',
      { spotlightId: spotlight.id },
      reloadProjectionBackedRoute,
    ));
    actions.append(cancel);
  }
  append(shade, copy, actions);
  append(hero, cover, shade);
  return hero;
}

function paintPhotos(timeline, state, spotlightPayload, loadMore = null) {
  assertPresentationActive();
  if (runtime.activeSelection) runtime.activeSelection.destroy();
  runtime.timelinePageObserver?.disconnect();
  runtime.timelinePageObserver = null;
  const spotlight = normalizeSpotlight(spotlightPayload);
  const page = element('div');
  append(page, pageHeader('photos.title', 'photos.lead', t('common.photosCount', { count: timeline.total })));
  if (spotlight) page.append(spotlightHero(spotlight, state));
  const utility = element('div', 'photo-utilities');
  const memories = element('a', 'secondary-button', t('photos.memoriesEntry'));
  memories.href = '/memories';
  utility.append(memories);
  let manageButton = null;
  if (state.canManage && timeline.total > 0) {
    manageButton = element('button', 'secondary-button', t('photos.organize'));
    manageButton.type = 'button';
    utility.append(manageButton);
  }
  page.append(utility);
  if (timeline.total === 0) {
    page.append(emptyState('photos.emptyTitle', 'photos.emptyBody'));
  } else {
    const allPhotos = timeline.groups.flatMap((group) => group.items);
    const controller = state.canManage ? selectionController(allPhotos) : null;
    if (manageButton && controller) manageButton.addEventListener('click', () => controller.enter());
    for (const group of timeline.groups) {
      const section = element('section', 'timeline-section');
      const heading = element('div', 'section-heading');
      append(heading, element('h2', '', group.label), element('span', '', t('common.photosCount', { count: group.total })));
      append(section, heading, photoGrid(group.items, '', controller));
      page.append(section);
    }
    if (controller) page.append(element('p', 'selection-hint', t('photos.selectionHint')));
  }
  if (timeline.hasMore) {
    if (typeof loadMore !== 'function' || !TIMELINE_CURSOR.test(timeline.nextCursor)) {
      throw new Error('safe_timeline_loader_invalid');
    }
    const controls = element('div', 'timeline-page-controls');
    const status = element('p', 'timeline-page-status', t('photos.loadedCount', { count: timeline.count, total: timeline.total }));
    const button = element('button', 'secondary-button', t('photos.loadMore'));
    button.type = 'button';
    button.addEventListener('click', async () => {
      if (button.disabled) return;
      button.disabled = true;
      button.textContent = t('photos.loadingMore');
      try {
        await loadMore(timeline.nextCursor);
      } catch (error) {
        failClosedPresentation(error);
      }
    });
    append(controls, status, button);
    page.append(controls);
    if (typeof IntersectionObserver === 'function') {
      runtime.timelinePageObserver = new IntersectionObserver((entries, observer) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        observer.disconnect();
        button.click();
      }, { rootMargin: '900px 0px', threshold: 0.01 });
      runtime.timelinePageObserver.observe(controls);
    }
  }
  shell('photos', page);
}

async function renderPhotos() {
  showLoading('photos', 'photos.title', 'photos.lead');
  try {
    const state = await productState();
    if (state.role === 'UNKNOWN' || !state.cacheScope) throw new Error('safe_product_state_unavailable');
    const [timelineRead, spotlightRead] = await Promise.all([
      presentationJson('/api/class-archive/timeline'),
      presentationJson('/api/class-archive/spotlight'),
    ]);
    assertPresentationActive();
    let timeline = normalizeTimeline(timelineRead.value);
    let spotlight = spotlightRead.value;
    let timelineGeneration = 0;
    let pageRequestActive = false;
    const paint = () => paintPhotos(timeline, state, spotlight, timeline.hasMore ? async (requestedCursor) => {
      if (pageRequestActive || requestedCursor !== timeline.nextCursor) {
        throw new Error('safe_timeline_page_request_invalid');
      }
      pageRequestActive = true;
      const generation = timelineGeneration;
      const verifiedScope = runtime.cacheScope;
      try {
        const path = `/api/class-archive/timeline?cursor=${encodeURIComponent(requestedCursor)}&limit=${timeline.limit}`;
        const next = normalizeTimeline(await apiJson(path, { cache: 'no-cache' }));
        if (generation !== timelineGeneration) return;
        if (runtime.presentationFailureActive || runtime.cacheScope !== verifiedScope
          || document.visibilityState !== 'visible') {
          throw new Error('safe_timeline_page_session_changed');
        }
        timeline = mergeTimelinePages(timeline, next);
        paint();
      } finally {
        pageRequestActive = false;
      }
    } : null);
    paint();
    if (timelineRead.refresh || spotlightRead.refresh) {
      Promise.all([
        timelineRead.refresh ?? Promise.resolve(timelineRead.value),
        spotlightRead.refresh ?? Promise.resolve(spotlightRead.value),
      ]).then(([freshTimeline, freshSpotlight]) => {
        assertPresentationActive();
        if (location.pathname !== '/photos') return;
        if (JSON.stringify(freshTimeline) === JSON.stringify(timelineRead.value)
          && JSON.stringify(freshSpotlight) === JSON.stringify(spotlightRead.value)) return;
        timelineGeneration += 1;
        timeline = normalizeTimeline(freshTimeline);
        spotlight = freshSpotlight;
        paint();
      }).catch((error) => failClosedPresentation(error));
    }
  } catch {
    const page = element('div');
    append(page, pageHeader('photos.title', 'photos.lead'), errorState());
    shell('photos', page);
  }
}

function viewerButton(labelKey) {
  const button = element('button', 'icon-button');
  button.type = 'button';
  button.setAttribute('aria-label', t(labelKey));
  button.textContent = t(labelKey);
  return button;
}

function closeViewer() {
  if (document.referrer.startsWith(location.origin) && history.length > 1) history.back();
  else location.assign('/photos');
}

function infoRow(labelKey, value) {
  const row = element('div', 'info-row');
  append(row, element('dt', '', t(labelKey)), element('dd', '', value));
  return row;
}

function normalizeComments(payload) {
  if (!payload || !Number.isInteger(payload.total) || !Array.isArray(payload.items)
    || payload.total < payload.items.length || payload.total < 0
    || typeof payload.hasMore !== 'boolean'
    || (payload.hasMore && !validId(payload.nextCursor))
    || (!payload.hasMore && payload.nextCursor !== null)) {
    throw new Error('safe_comments_invalid');
  }
  return {
    total: payload.total,
    hasMore: payload.hasMore,
    nextCursor: payload.nextCursor ? payload.nextCursor.toLowerCase() : null,
    items: payload.items.map((item) => {
      const parentId = item?.parentId ?? item?.parent_id ?? null;
      const author = item?.author;
      const deleted = item?.deleted === true;
      if (!item || !validId(item.id) || (parentId !== null && !validId(parentId))
        || (deleted ? item.body !== null : (typeof item.body !== 'string' || item.body.length < 1 || item.body.length > 2_000))
        || !author || typeof author !== 'object' || !safeText(author.label, '')
        || typeof author.kind !== 'string' || !/^[A-Z_]{1,32}$/.test(author.kind)
        || typeof item.createdAt !== 'string' || !/^\d{4}-\d{2}-\d{2}T/.test(item.createdAt)
        || typeof item.canReply !== 'boolean' || typeof item.canDelete !== 'boolean'
        || typeof item.deleted !== 'boolean'
        || (deleted && (author.kind !== 'DELETED' || item.canReply || item.canDelete))
        || (!deleted && author.kind === 'DELETED')) {
        throw new Error('safe_comment_invalid');
      }
      return {
        id: item.id.toLowerCase(),
        parentId: parentId ? parentId.toLowerCase() : null,
        body: item.body,
        author: { label: safeText(author.label, t('comments.author')), kind: author.kind },
        createdAt: item.createdAt,
        canReply: item.canReply,
        canDelete: item.canDelete,
        deleted,
      };
    }),
  };
}

async function loadComments(photoId, cursor = null) {
  if (!validId(photoId)) throw new Error('safe_comment_photo_invalid');
  if (cursor !== null && !validId(cursor)) throw new Error('safe_comment_cursor_invalid');
  const query = new URLSearchParams({ limit: '100' });
  if (cursor !== null) query.set('cursor', cursor.toLowerCase());
  return normalizeComments(await apiJson(`/api/class-archive/comments/${photoId.toLowerCase()}?${query}`, { cache: 'no-store' }));
}

function formatCommentTime(value) {
  const time = Date.parse(value);
  if (!Number.isFinite(time)) return '';
  return new Intl.DateTimeFormat('zh-CN', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(time));
}

function canCreateComment(role) {
  return ['CLASSMATE', 'TEACHER', 'ANONYMOUS'].includes(role);
}

function commentComposer(photoId, parentId, onComplete) {
  const form = element('form', parentId ? 'comment-composer comment-reply-form' : 'comment-composer');
  const input = element('textarea', 'text-area');
  input.name = 'body';
  input.maxLength = 2_000;
  input.required = true;
  input.placeholder = t(parentId ? 'comments.replyPlaceholder' : 'comments.placeholder');
  input.setAttribute('aria-label', t(parentId ? 'comments.replyPlaceholder' : 'comments.placeholder'));
  const actions = element('div', 'comment-composer-actions');
  const submit = element('button', 'primary-button compact-button', t(parentId ? 'comments.reply' : 'comments.submit'));
  submit.type = 'submit';
  actions.append(submit);
  append(form, input, actions);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const body = input.value.trim();
    if (!body) {
      input.focus();
      return;
    }
    submit.disabled = true;
    try {
      await mutate(parentId ? '/api/class-archive/comments/reply' : '/api/class-archive/comments/create', {
        photoUuid: photoId,
        parentId,
        body,
      });
      input.value = '';
      await onComplete();
      toast(t('comments.saved'));
    } catch {
      submit.disabled = false;
      toast(t('comments.failed'), 'error');
    }
  });
  return form;
}

function viewerContext(asset, photo) {
  const rawAlbums = Array.isArray(asset?.albums) ? asset.albums : [];
  const album = rawAlbums.map((item) => (item && typeof item === 'object'
    ? safeText(item.displayAlias ?? item.name, '') : '')).find(Boolean)
    ?? safeText(asset?.albumName ?? asset?.album_name, '');
  const source = safeText(asset?.sourceLabel ?? asset?.source_label, '');
  const archiveDate = photo?.archiveDate?.label && photo.archiveDate.label !== t('common.unknownDate')
    ? photo.archiveDate.label : '';
  return { album, source, archiveDate };
}

function viewerPhotoInfo(photo, context) {
  const details = element('details', 'viewer-photo-info');
  const summary = element('summary', '', t('viewer.photoInfo'));
  const list = element('dl', 'info-list');
  const rows = [];
  if (context.album) rows.push(infoRow('viewer.album', context.album));
  if (context.source) rows.push(infoRow('viewer.sourceCollection', context.source));
  if (context.archiveDate) rows.push(infoRow('viewer.archiveDate', context.archiveDate));
  if (photo?.archiveDate?.precision && photo.archiveDate.precision !== t('precision.unknown')) rows.push(infoRow('viewer.precision', photo.archiveDate.precision));
  if (rows.length === 0) rows.push(infoRow('viewer.archiveDate', t('viewer.timePending')));
  append(list, rows);
  append(details, summary, list);
  return details;
}

function commentItem(item, role, photoId, onComplete) {
  const card = element('article', 'comment-item');
  card.dataset.commentId = item.id;
  if (item.deleted) card.dataset.deleted = 'true';
  if (item.parentId) card.dataset.reply = 'true';
  const heading = element('header', 'comment-header');
  append(heading,
    element('strong', 'comment-author', item.author.label),
    element('time', 'comment-time', formatCommentTime(item.createdAt)),
  );
  const body = element('p', 'comment-body', item.deleted ? t('comments.deletedTombstone') : item.body);
  const actions = element('div', 'comment-actions');
  if (canCreateComment(role) && item.canReply) {
    const reply = element('button', 'ghost-button compact-button comment-reply', t('comments.reply'));
    reply.type = 'button';
    reply.addEventListener('click', () => {
      const existing = card.querySelector('.comment-reply-form');
      if (existing) {
        existing.remove();
        reply.setAttribute('aria-expanded', 'false');
        return;
      }
      card.append(commentComposer(photoId, item.id, onComplete));
      reply.setAttribute('aria-expanded', 'true');
      card.querySelector('.comment-reply-form textarea')?.focus();
    });
    reply.setAttribute('aria-expanded', 'false');
    actions.append(reply);
  }
  if (role === 'SYSTEM_ADMIN' && item.canDelete) {
    const remove = element('button', 'ghost-button compact-button comment-delete', t('comments.delete'));
    remove.type = 'button';
    remove.addEventListener('click', () => openReasonMutation(
      'comments.deleteTitle',
      'comments.deleteLead',
      '/api/class-archive/manage/comments/delete',
      { commentId: item.id },
      async () => {
        await onComplete();
        toast(t('comments.deleted'));
      },
    ));
    actions.append(remove);
  }
  append(card, heading, body, actions.childElementCount > 0 ? actions : null);
  return card;
}

function viewerComments(photoId, role, comments, onRefresh, onLoadMore) {
  const section = element('section', 'viewer-comments');
  const heading = element('div', 'viewer-comments-heading');
  append(heading, element('h2', '', t('comments.title')), element('span', '', t('comments.count', { count: comments?.total ?? 0 })));
  section.append(heading);
  if (!comments) {
    section.append(element('p', 'comment-unavailable', t('comments.unavailable')));
    return section;
  }
  if (comments.items.length === 0) {
    append(section, element('p', 'comment-empty-title', t('comments.emptyTitle')), element('p', 'comment-empty-body', t('comments.emptyBody')));
  } else {
    const list = element('div', 'comments-list');
    append(list, comments.items.map((item) => commentItem(item, role, photoId, onRefresh)));
    section.append(list);
    if (comments.hasMore && typeof onLoadMore === 'function') {
      const more = element('button', 'ghost-button compact-button comment-load-more', t('comments.loadMore'));
      more.type = 'button';
      more.addEventListener('click', async () => {
        more.disabled = true;
        try { await onLoadMore(); } catch { more.disabled = false; }
      });
      section.append(more);
    }
  }
  if (canCreateComment(role)) section.append(commentComposer(photoId, null, onRefresh));
  else if (role === 'FAMILY') section.append(element('p', 'comment-readonly', t('comments.familyReadonly')));
  return section;
}

async function renderViewer(id) {
  app.replaceChildren(loadingState());
  try {
    const state = await productState();
    const verifiedScope = state.cacheScope;
    if (state.role === 'UNKNOWN' || !verifiedScope) throw new Error('safe_viewer_scope_unavailable');
    const [asset, timelineRead] = await Promise.all([
      apiJson(`/api/assets/${id}`),
      presentationJson('/api/class-archive/timeline'),
    ]);
    assertPresentationActive();
    if (runtime.cacheScope !== verifiedScope || asset?.classArchiveCacheScope !== verifiedScope) {
      throw new Error('safe_viewer_scope_changed');
    }
    const timeline = normalizeTimeline(timelineRead.value);
    let photos = timeline.groups.flatMap((group) => group.items);
    let index = photos.findIndex((photo) => photo.id === id);
    if (index < 0) {
      // A deep link may target a later timeline page. The point endpoint has
      // already applied the same current-principal policy, so use its bounded
      // presentation metadata without scanning every earlier page.
      photos = [archivePhotoFromAsset(asset, id, verifiedScope)];
      index = 0;
    }
    const confirmedState = normalizeProductState(await apiJson('/api/class-archive/product-state', { cache: 'no-store' }));
    if (confirmedState.cacheScope !== verifiedScope || confirmedState.role !== state.role
      || runtime.cacheScope !== verifiedScope) {
      throw new Error('safe_viewer_scope_changed');
    }
    const photo = photos[index];
    const context = viewerContext(asset, photo);
    const title = context.album || t('viewer.photoContext');
    let comments = null;
    try { comments = await loadComments(id); } catch { comments = null; }

    const page = element('main', 'viewer-page');
    page.id = 'main-content';
    page.dataset.infoOpen = 'false';
    const stage = element('section', 'viewer-stage');
    const wrap = element('div', 'viewer-image-wrap');
    const image = responsivePhotoImage(photo, 'viewer', title, true);
    image.className = 'viewer-image';
    wrap.append(image);

    const toolbar = element('div', 'viewer-toolbar');
    const close = viewerButton('accessibility.close');
    close.addEventListener('click', closeViewer);
    const leftActions = element('div', 'viewer-actions');
    leftActions.append(close);
    const rightActions = element('div', 'viewer-actions');
    const zoomOut = viewerButton('accessibility.zoomOut');
    const zoomIn = viewerButton('accessibility.zoomIn');
    const infoToggle = viewerButton('accessibility.comments');
    infoToggle.setAttribute('aria-expanded', 'false');
    append(rightActions, zoomOut, zoomIn, infoToggle);
    append(toolbar, leftActions, rightActions);

    const previous = viewerButton('accessibility.previous');
    previous.classList.add('viewer-nav', 'viewer-prev');
    const next = viewerButton('accessibility.next');
    next.classList.add('viewer-nav', 'viewer-next');
    previous.disabled = index === 0;
    next.disabled = index === photos.length - 1;

    const goTo = (offset) => {
      const target = photos[index + offset];
      if (target) location.assign(`/photos/${target.id}`);
    };
    previous.addEventListener('click', () => goTo(-1));
    next.addEventListener('click', () => goTo(1));
    // Large archival originals make cold derivative generation expensive.
    // Let the requested preview finish before adjacent prefetch begins.
    image.addEventListener('load', () => {
      setTimeout(() => prefetchAdjacentPreviews(photos, index), 700);
    }, { once: true });

    const info = element('aside', 'viewer-info');
    info.dataset.open = 'false';
    const contextHeader = element('header', 'viewer-context');
    append(contextHeader,
      element('p', 'viewer-context-eyebrow', context.source || t('viewer.photoContext')),
      context.album ? element('h1', '', context.album) : null,
      context.archiveDate ? element('p', 'viewer-context-date', context.archiveDate) : null,
    );
    const commentsRoot = element('div', 'viewer-comments-root');
    const paintComments = () => {
      commentsRoot.replaceChildren(viewerComments(id, state.role, comments, refreshComments, loadMoreComments));
    };
    const refreshComments = async () => {
      try { comments = await loadComments(id); } catch { comments = null; }
      paintComments();
    };
    const loadMoreComments = async () => {
      if (!comments?.hasMore || !comments.nextCursor) return;
      const next = await loadComments(id, comments.nextCursor);
      const seen = new Set(comments.items.map((item) => item.id));
      comments = {
        total: Math.max(comments.total, next.total),
        items: [...comments.items, ...next.items.filter((item) => !seen.has(item.id))],
        hasMore: next.hasMore,
        nextCursor: next.nextCursor,
      };
      paintComments();
    };
    paintComments();
    append(info, contextHeader, commentsRoot, viewerPhotoInfo(photo, context));

    let zoom = 1;
    const updateZoom = (nextZoom) => {
      zoom = Math.min(3, Math.max(1, nextZoom));
      image.style.transform = `scale(${zoom})`;
      zoomOut.disabled = zoom <= 1;
      zoomIn.disabled = zoom >= 3;
    };
    zoomOut.addEventListener('click', () => updateZoom(zoom - .25));
    zoomIn.addEventListener('click', () => updateZoom(zoom + .25));
    wrap.addEventListener('dblclick', () => updateZoom(zoom === 1 ? 2 : 1));
    let gesture = null;
    const touchDistance = (touches) => Math.hypot(
      touches[0].clientX - touches[1].clientX,
      touches[0].clientY - touches[1].clientY,
    );
    wrap.addEventListener('touchstart', (event) => {
      if (event.touches.length === 2) {
        gesture = { type: 'pinch', distance: touchDistance(event.touches), zoom };
        return;
      }
      if (event.touches.length === 1) {
        gesture = {
          type: 'swipe',
          x: event.touches[0].clientX,
          y: event.touches[0].clientY,
          startedAt: performance.now(),
        };
      }
    }, { passive: true });
    wrap.addEventListener('touchmove', (event) => {
      if (gesture?.type !== 'pinch' || event.touches.length !== 2 || gesture.distance <= 0) return;
      event.preventDefault();
      updateZoom(gesture.zoom * (touchDistance(event.touches) / gesture.distance));
    }, { passive: false });
    wrap.addEventListener('touchend', (event) => {
      if (gesture?.type === 'pinch') {
        if (event.touches.length === 0) gesture = null;
        return;
      }
      if (gesture?.type !== 'swipe' || event.touches.length !== 0 || event.changedTouches.length !== 1) return;
      const deltaX = event.changedTouches[0].clientX - gesture.x;
      const deltaY = event.changedTouches[0].clientY - gesture.y;
      const duration = performance.now() - gesture.startedAt;
      gesture = null;
      if (zoom === 1 && duration <= 650 && Math.abs(deltaX) >= 56 && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
        goTo(deltaX < 0 ? 1 : -1);
      }
    }, { passive: true });
    wrap.addEventListener('touchcancel', () => { gesture = null; }, { passive: true });
    infoToggle.addEventListener('click', () => {
      const open = info.dataset.open !== 'true';
      info.dataset.open = String(open);
      page.dataset.infoOpen = String(open);
      infoToggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeViewer();
      }
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        goTo(-1);
      }
      if (event.key === 'ArrowRight') {
        event.preventDefault();
        goTo(1);
      }
    });
    append(stage, wrap, toolbar, previous, next);
    append(page, stage, info);
    app.replaceChildren(page);
  } catch {
    const page = element('div');
    append(page, pageHeader('photos.title', 'photos.lead'), errorState());
    shell('photos', page);
  }
}

function normalizePeople(payload) {
  if (!payload || !Array.isArray(payload.people) || !Number.isInteger(payload.total) || payload.total !== payload.people.length) {
    throw new Error('safe_people_invalid');
  }
  return payload.people.map((person) => {
    const coverPhotoId = person?.coverPhotoId ?? person?.cover_photo_id;
    const photoCount = person?.photoCount ?? person?.photo_count;
    if (!person || !validId(person.id) || !validId(coverPhotoId)) throw new Error('safe_person_invalid');
    return {
      id: person.id.toLowerCase(),
      coverPhotoId: coverPhotoId.toLowerCase(),
      portraitFocus: normalizePortraitFocus(person.portraitFocus ?? person.portrait_focus),
      name: safeText(person.name ?? person.label, t('people.unnamed')),
      count: Number.isInteger(photoCount) && photoCount > 0 ? photoCount : null,
    };
  });
}

function normalizePortraitFocus(value) {
  if (value === undefined || value === null) return null;
  if (!value || typeof value !== 'object' || Array.isArray(value)
      || !Number.isFinite(value.x) || !Number.isFinite(value.y) || !Number.isFinite(value.zoom)
      || value.x < 0 || value.x > 1 || value.y < 0 || value.y > 1 || value.zoom < 1 || value.zoom > 6) {
    throw new Error('safe_person_focus_invalid');
  }
  return { x: value.x, y: value.y, zoom: value.zoom };
}

function portraitImage(person, eager = false) {
  const image = resilientImage(mediaUrl(person.coverPhotoId, 'small'), '', eager, { sizes: '(max-width: 680px) 28vw, 190px' });
  if (person.portraitFocus) {
    image.style.setProperty('--portrait-x', `${person.portraitFocus.x * 100}%`);
    image.style.setProperty('--portrait-y', `${person.portraitFocus.y * 100}%`);
    image.style.setProperty('--portrait-zoom', String(person.portraitFocus.zoom));
  }
  return image;
}

function personCard(person) {
  const link = element('a', 'person-card');
  link.href = `/people/${person.id}`;
  const portrait = element('span', 'person-photo');
  portrait.append(portraitImage(person));
  append(
    link,
    portrait,
    element('span', 'person-name', person.name),
    person.count === null ? null : element('span', 'person-count', t('common.photosCount', { count: person.count })),
  );
  return link;
}

async function renderPeople() {
  showLoading('people', 'people.title', 'people.lead');
  try {
    const state = await productState();
    const peopleRead = await presentationJson('/api/people?size=500&withHidden=false');
    assertPresentationActive();
    const people = normalizePeople(peopleRead.value);
    const page = element('div');
    append(page, pageHeader('people.title', 'people.lead', t('common.peopleCount', { count: people.length })));
    if (state.canManage) {
      const actions = element('div', 'page-actions');
      const manage = element('a', 'secondary-button', t('people.manage'));
      manage.href = '/people/manage';
      actions.append(manage);
      page.append(actions);
    }
    if (people.length === 0) page.append(emptyState('people.emptyTitle', 'people.emptyBody'));
    else {
      const grid = element('div', 'people-grid');
      append(grid, people.map(personCard));
      page.append(grid);
    }
    shell('people', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('people.title', 'people.lead'), errorState());
    shell('people', page);
  }
}

function normalizeManagePeople(payload) {
  const items = payload?.items ?? payload?.people;
  if (!Array.isArray(items)) throw new Error('safe_manage_people_invalid');
  return items.map((person) => {
    const id = person?.id ?? person?.classPersonId ?? person?.class_person_id;
    const coverPhotoId = person?.coverPhotoId ?? person?.cover_photo_id ?? null;
    const photosPayload = person?.photos ?? person?.items ?? [];
    if (!validId(id) || (coverPhotoId !== null && !validId(coverPhotoId)) || !Array.isArray(photosPayload)) {
      throw new Error('safe_manage_person_invalid');
    }
    const photos = photosPayload.map(normalizeArchivePhoto);
    return {
      id: id.toLowerCase(),
      name: safeText(person.displayName ?? person.display_name ?? person.name ?? person.label, t('people.unnamed')),
      coverPhotoId: coverPhotoId ? coverPhotoId.toLowerCase() : photos[0]?.id ?? null,
      linkedIdentityId: opaqueChoiceId(person.classmateIdentityId ?? person.classmate_identity_id),
      linkedIdentityName: safeText(person.classmateIdentityName ?? person.classmate_identity_name ?? person.identityName, ''),
      hidden: person.hidden === true || person.is_hidden === true,
      count: Number.isInteger(person.photoCount ?? person.photo_count)
        ? (person.photoCount ?? person.photo_count)
        : photos.length,
      photos,
    };
  });
}

function normalizeManageMerges(payload) {
  const items = payload?.merges ?? [];
  if (!Array.isArray(items)) throw new Error('safe_manage_merges_invalid');
  return items.map((merge) => {
    const id = merge?.id ?? merge?.mergeId ?? merge?.merge_id;
    const sourcePersonId = merge?.sourcePersonId ?? merge?.source_person_id;
    const targetPersonId = merge?.targetPersonId ?? merge?.target_person_id;
    if (!validId(id) || !validId(sourcePersonId) || !validId(targetPersonId)) {
      throw new Error('safe_manage_merge_invalid');
    }
    return {
      id: id.toLowerCase(),
      sourceName: safeText(merge.sourceName ?? merge.source_name, t('people.unnamed')),
      targetName: safeText(merge.targetName ?? merge.target_name, t('people.unnamed')),
    };
  });
}

function managePersonRow(person, allPeople, selected, refreshSelection) {
  const row = element('article', 'manage-person-row');
  const choose = element('input');
  choose.type = 'checkbox';
  choose.setAttribute('aria-label', person.name);
  choose.addEventListener('change', () => {
    if (choose.checked) selected.add(person.id);
    else selected.delete(person.id);
    refreshSelection();
  });
  const portrait = element('span', 'manage-person-portrait');
  if (person.coverPhotoId) portrait.append(resilientImage(mediaUrl(person.coverPhotoId, 'xsmall'), '', false));
  const copy = element('div', 'manage-person-copy');
  append(copy,
    element('strong', '', person.name),
    element('span', '', t('common.photosCount', { count: person.count })),
    element('span', '', person.linkedIdentityName || t('people.unlinked')),
  );
  const status = element('span', `status-pill ${person.hidden ? 'status-muted' : ''}`, person.hidden ? t('people.hiddenStatus') : t('people.visibleStatus'));
  const edit = element('button', 'secondary-button compact-button', t('common.edit'));
  edit.type = 'button';
  edit.addEventListener('click', () => openPersonEditor(person, allPeople));
  append(row, choose, portrait, copy, status, edit);
  return row;
}

async function openPersonMoveDialog(person, photoIds, allPeople) {
  if (photoIds.length === 0) return;
  const { dialog, surface } = dialogShell('people.correctTitle');
  const form = element('form', 'dialog-form');
  const target = element('select', 'select-field');
  append(target, option('', t('people.removeFromPerson')));
  for (const item of allPeople.filter((candidate) => candidate.id !== person.id)) {
    target.append(option(item.id, item.name));
  }
  target.append(option('__new__', t('people.moveToNew')));
  const newName = element('input', 'text-field');
  newName.type = 'text';
  newName.maxLength = 190;
  newName.placeholder = t('people.newPersonNamePlaceholder');
  newName.hidden = true;
  target.addEventListener('change', () => {
    newName.hidden = target.value !== '__new__';
    newName.required = target.value === '__new__';
    if (!newName.required) newName.value = '';
  });
  const reason = element('textarea', 'text-area');
  reason.required = true;
  reason.maxLength = 500;
  reason.placeholder = t('common.reasonPlaceholder');
  append(form,
    labeledControl('people.moveTo', target),
    labeledControl('people.newPersonName', newName),
    labeledControl('common.reason', reason),
  );
  const actions = element('div', 'dialog-actions');
  const cancel = element('button', 'secondary-button', t('common.cancel'));
  cancel.type = 'button';
  cancel.addEventListener('click', () => dialog.close());
  const submit = element('button', 'primary-button', t('common.confirm'));
  submit.type = 'submit';
  append(actions, cancel, submit);
  form.append(actions);
  surface.append(form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reasonValue = readReason(reason);
    if (!reasonValue) return;
    submit.disabled = true;
    try {
      let targetPersonId = target.value || null;
      if (targetPersonId === '__new__') {
        const created = await mutate('/api/class-archive/manage/people/create', {
          displayName: newName.value.trim(),
          classmateIdentityId: null,
          reason: reasonValue,
        });
        if (!validId(created?.id)) throw new Error('safe_person_create_result_invalid');
        targetPersonId = created.id.toLowerCase();
      }
      await mutate('/api/class-archive/manage/people/move-photos', {
        sourcePersonId: person.id,
        targetPersonId,
        photoIds,
        reason: reasonValue,
      });
      dialog.close();
      toast(t('common.operationSucceeded'));
      setTimeout(() => location.reload(), 450);
    } catch {
      submit.disabled = false;
      toast(t('common.operationFailed'), 'error');
    }
  });
}

async function openPersonEditor(person, allPeople) {
  let options;
  try {
    options = await manageOptions();
  } catch {
    toast(t('common.operationFailed'), 'error');
    return;
  }
  const { dialog, surface } = dialogShell('people.editTitle');
  const form = element('form', 'dialog-form');
  const name = element('input', 'text-field');
  name.type = 'text';
  name.required = true;
  name.maxLength = 190;
  name.value = person.name === t('people.unnamed') ? '' : person.name;
  const identity = element('select', 'select-field');
  append(identity, option('', t('people.noIdentityLink')));
  for (const item of options.identities) identity.append(option(item.id, item.label, item.id === person.linkedIdentityId));
  const hiddenLabel = element('label', 'confirm-row');
  const hidden = element('input');
  hidden.type = 'checkbox';
  hidden.checked = person.hidden;
  append(hiddenLabel, hidden, element('span', '', t('people.hidden')));
  append(form, labeledControl('people.displayName', name), labeledControl('people.identityLink', identity), hiddenLabel);

  let coverId = person.coverPhotoId;
  const correctionIds = new Set();
  if (person.photos.length > 0) {
    const label = element('span', 'field-label', t('people.photos'));
    const photoChoices = element('div', 'manage-photo-grid');
    for (const photo of person.photos) {
      const card = element('div', 'manage-photo-choice');
      card.append(resilientImage(mediaUrl(photo.id, 'xsmall'), '', false));
      const controls = element('div', 'manage-photo-controls');
      const cover = element('label', 'mini-choice');
      const coverInput = element('input');
      coverInput.type = 'radio';
      coverInput.name = 'personCover';
      coverInput.checked = photo.id === coverId;
      coverInput.addEventListener('change', () => { if (coverInput.checked) coverId = photo.id; });
      append(cover, coverInput, element('span', '', t('people.setCover')));
      const correct = element('label', 'mini-choice');
      const correctInput = element('input');
      correctInput.type = 'checkbox';
      correctInput.addEventListener('change', () => {
        if (correctInput.checked) correctionIds.add(photo.id);
        else correctionIds.delete(photo.id);
        const correctionButton = form.querySelector('[data-correction-action]');
        if (correctionButton) correctionButton.disabled = correctionIds.size === 0;
      });
      append(correct, correctInput, element('span', '', t('common.select')));
      append(controls, cover, correct);
      append(card, controls);
      photoChoices.append(card);
    }
    const field = element('div', 'field');
    append(field, label, photoChoices);
    form.append(field);
  }

  const reason = element('textarea', 'text-area');
  reason.required = true;
  reason.maxLength = 500;
  reason.placeholder = t('common.reasonPlaceholder');
  form.append(labeledControl('common.reason', reason));
  const actions = element('div', 'dialog-actions dialog-actions-split');
  const correct = element('button', 'secondary-button', t('people.correctTitle'));
  correct.type = 'button';
  correct.dataset.correctionAction = '';
  correct.disabled = true;
  correct.addEventListener('click', () => {
    if (correctionIds.size === 0) return;
    const photoIds = [...correctionIds];
    dialog.close();
    void openPersonMoveDialog(person, photoIds, allPeople);
  });
  const cancel = element('button', 'ghost-button', t('common.cancel'));
  cancel.type = 'button';
  cancel.addEventListener('click', () => dialog.close());
  const submit = element('button', 'primary-button', t('common.save'));
  submit.type = 'submit';
  append(actions, correct, cancel, submit);
  form.append(actions);
  surface.append(form);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reasonValue = readReason(reason);
    if (!reasonValue) return;
    submit.disabled = true;
    try {
      await mutate('/api/class-archive/manage/people/update', {
        classPersonId: person.id,
        displayName: name.value.trim(),
        classmateIdentityId: identity.value || null,
        hidden: hidden.checked,
        coverPhotoId: coverId,
        reason: reasonValue,
      });
      dialog.close();
      toast(t('common.operationSucceeded'));
      setTimeout(() => location.reload(), 450);
    } catch {
      submit.disabled = false;
      toast(t('common.operationFailed'), 'error');
    }
  });
}

function normalizeDuplicateGroups(payload) {
  const items = payload?.items ?? [];
  if (!Array.isArray(items)) throw new Error('safe_duplicate_groups_invalid');
  return items.flatMap((group) => {
    const groupId = opaqueChoiceId(group?.id ?? group?.groupId);
    const rawPhotos = group?.photos ?? group?.items;
    if (!groupId || !Array.isArray(rawPhotos)) return [];
    const photos = rawPhotos.flatMap((photo) => {
      const id = photo?.id ?? photo?.photoId;
      if (!validId(id)) return [];
      const sourceCount = Number(photo?.sourceCount ?? photo?.source_count ?? 0);
      if (!Number.isInteger(sourceCount) || sourceCount < 0) return [];
      return [{
        id: id.toLowerCase(),
        sourceLabel: safeText(photo.sourceLabel ?? photo.source, t('duplicates.source')),
        sourceCount,
      }];
    });
    return photos.length > 1 ? [{
      id: groupId,
      exact: group.exact === true || group.type === 'EXACT',
      photos,
    }] : [];
  });
}

function duplicateGroupCard(group) {
  const card = element('article', 'duplicate-card');
  append(card, element('h3', '', group.exact ? t('duplicates.exact') : t('duplicates.near')));
  const form = element('form', 'duplicate-form');
  const choices = element('div', 'duplicate-choice-grid');
  for (const [index, photo] of group.photos.entries()) {
    const label = element('label', 'duplicate-choice');
    const input = element('input');
    input.type = 'radio';
    input.name = `canonical-${group.id}`;
    input.value = photo.id;
    input.required = true;
    input.checked = index === 0;
    const image = resilientImage(mediaUrl(photo.id, 'xsmall'), '', false);
    append(label, input, image, element('span', '', photo.sourceCount > 0
      ? t('duplicates.sourcesCount', { count: photo.sourceCount })
      : photo.sourceLabel));
    choices.append(label);
  }
  const reason = element('input', 'text-field');
  let submit = null;
  if (group.exact) {
    reason.required = true;
    reason.maxLength = 500;
    reason.placeholder = t('common.reasonPlaceholder');
    submit = element('button', 'secondary-button', t('duplicates.consolidate'));
    submit.type = 'submit';
    append(form, choices, labeledControl('common.reason', reason), submit);
  } else {
    for (const radio of choices.querySelectorAll('input[type="radio"]')) radio.disabled = true;
    append(form, choices, element('p', 'field-hint', t('duplicates.nearNote')));
  }
  card.append(form);
  if (!group.exact) return card;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const reasonValue = readReason(reason);
    if (!reasonValue) return;
    submit.disabled = true;
    const canonical = form.querySelector('input[type="radio"]:checked');
    try {
      await mutate('/api/class-archive/manage/duplicates/consolidate', {
        duplicateGroupId: group.id,
        canonicalPhotoId: canonical?.value,
        reason: reasonValue,
      });
      card.remove();
      toast(t('common.operationSucceeded'));
    } catch {
      submit.disabled = false;
      toast(t('common.operationFailed'), 'error');
    }
  });
  return card;
}

async function renderPeopleManage() {
  showLoading('people', 'people.manageTitle', 'people.manageLead');
  try {
    const state = await productState();
    if (!state.canManage) throw new Error('safe_manage_forbidden');
    const [peoplePayload, duplicatePayload] = await Promise.all([
      apiJson('/api/class-archive/manage/people'),
      apiJson('/api/class-archive/manage/duplicates').catch(() => ({ items: [] })),
    ]);
    const people = normalizeManagePeople(peoplePayload);
    const activeMerges = normalizeManageMerges(peoplePayload);
    const duplicateGroups = normalizeDuplicateGroups(duplicatePayload);
    const page = element('div');
    const back = element('a', 'back-link', t('people.manageBack'));
    back.href = '/people';
    append(page, back, pageHeader('people.manageTitle', 'people.manageLead', t('common.peopleCount', { count: people.length })));
    const selected = new Set();
    const manageActions = element('div', 'manage-toolbar');
    const selectionCount = element('strong', '', t('people.manageSelected', { count: 0 }));
    const merge = element('button', 'secondary-button', t('people.merge'));
    merge.type = 'button';
    merge.disabled = true;
    const hideSelected = element('button', 'secondary-button', t('people.hideSelected'));
    hideSelected.type = 'button';
    hideSelected.disabled = true;
    const showSelected = element('button', 'secondary-button', t('people.showSelected'));
    showSelected.type = 'button';
    showSelected.disabled = true;
    const refreshSelection = () => {
      selectionCount.textContent = t('people.manageSelected', { count: selected.size });
      merge.disabled = selected.size < 2;
      hideSelected.disabled = selected.size === 0;
      showSelected.disabled = selected.size === 0;
    };
    const bulkVisibility = (hidden) => openReasonMutation(
      hidden ? 'people.hideSelected' : 'people.showSelected',
      hidden ? 'people.hideSelectedLead' : 'people.showSelectedLead',
      '/api/class-archive/manage/people/visibility',
      { classPersonIds: [...selected], hidden },
      () => location.reload(),
    );
    hideSelected.addEventListener('click', () => bulkVisibility(true));
    showSelected.addEventListener('click', () => bulkVisibility(false));
    merge.addEventListener('click', () => {
      const selectedPeople = people.filter((person) => selected.has(person.id));
      const { dialog, surface } = dialogShell('people.mergeTitle', 'people.mergeWarning');
      const form = element('form', 'dialog-form');
      const target = element('select', 'select-field');
      append(target, selectedPeople.map((person) => option(person.id, person.name)));
      const cover = element('select', 'select-field');
      const refreshCovers = () => {
        cover.replaceChildren();
        for (const person of selectedPeople) {
          if (person.coverPhotoId) cover.append(option(person.coverPhotoId, person.name, person.id === target.value));
        }
      };
      target.addEventListener('change', refreshCovers);
      refreshCovers();
      const reason = element('textarea', 'text-area');
      reason.required = true;
      reason.maxLength = 500;
      reason.placeholder = t('common.reasonPlaceholder');
      append(form,
        labeledControl('people.mergeTarget', target),
        labeledControl('people.mergeCover', cover),
        labeledControl('common.reason', reason),
      );
      const actions = element('div', 'dialog-actions');
      const cancel = element('button', 'secondary-button', t('common.cancel'));
      cancel.type = 'button';
      cancel.addEventListener('click', () => dialog.close());
      const submit = element('button', 'primary-button', t('people.merge'));
      submit.type = 'submit';
      append(actions, cancel, submit);
      form.append(actions);
      surface.append(form);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const reasonValue = readReason(reason);
        if (!reasonValue) return;
        submit.disabled = true;
        try {
          await mutate('/api/class-archive/manage/people/merge', {
            sourcePersonIds: [...selected].filter((id) => id !== target.value),
            targetPersonId: target.value,
            coverPhotoId: cover.value || null,
            reason: reasonValue,
          });
          dialog.close();
          toast(t('common.operationSucceeded'));
          setTimeout(() => location.reload(), 450);
        } catch {
          submit.disabled = false;
          toast(t('common.operationFailed'), 'error');
        }
      });
    });
    const create = element('button', 'primary-button', t('people.create'));
    create.type = 'button';
    create.addEventListener('click', async () => {
      let options;
      try {
        options = await manageOptions();
      } catch {
        toast(t('common.operationFailed'), 'error');
        return;
      }
      const { dialog, surface } = dialogShell('people.createTitle', 'people.createLead');
      const form = element('form', 'dialog-form');
      const name = element('input', 'text-field');
      name.type = 'text';
      name.required = true;
      name.maxLength = 190;
      const identity = element('select', 'select-field');
      append(identity, option('', t('people.noIdentityLink')));
      for (const item of options.identities) identity.append(option(item.id, item.label));
      const reason = element('textarea', 'text-area');
      reason.required = true;
      reason.maxLength = 500;
      reason.placeholder = t('common.reasonPlaceholder');
      append(form,
        labeledControl('people.displayName', name),
        labeledControl('people.identityLink', identity),
        labeledControl('common.reason', reason),
      );
      const actions = element('div', 'dialog-actions');
      const cancel = element('button', 'secondary-button', t('common.cancel'));
      cancel.type = 'button';
      cancel.addEventListener('click', () => dialog.close());
      const submit = element('button', 'primary-button', t('people.create'));
      submit.type = 'submit';
      append(actions, cancel, submit);
      form.append(actions);
      surface.append(form);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const reasonValue = readReason(reason);
        if (!reasonValue || !name.value.trim()) return;
        submit.disabled = true;
        try {
          await mutate('/api/class-archive/manage/people/create', {
            displayName: name.value.trim(),
            classmateIdentityId: identity.value || null,
            reason: reasonValue,
          });
          dialog.close();
          toast(t('common.operationSucceeded'));
          setTimeout(() => location.reload(), 450);
        } catch {
          submit.disabled = false;
          toast(t('common.operationFailed'), 'error');
        }
      });
      name.focus();
    });
    append(manageActions, selectionCount, merge, hideSelected, showSelected, create);
    page.append(manageActions);
    const list = element('section', 'manage-people-list');
    if (people.length === 0) list.append(element('p', 'manage-empty', t('people.noManageItems')));
    else append(list, people.map((person) => managePersonRow(person, people, selected, refreshSelection)));
    page.append(list);
    if (activeMerges.length > 0) {
      const mergeHistory = element('section', 'merge-history');
      const heading = element('div', 'section-heading');
      append(heading, element('h2', '', t('people.mergeHistory')), element('span', '', String(activeMerges.length)));
      mergeHistory.append(heading);
      for (const item of activeMerges) {
        const row = element('div', 'merge-history-row');
        const description = element('span', '', t('people.mergeSummary', { source: item.sourceName, target: item.targetName }));
        const revert = element('button', 'secondary-button compact-button', t('people.revertMerge'));
        revert.type = 'button';
        revert.addEventListener('click', () => openReasonMutation(
          'people.revertMergeTitle',
          'people.revertMergeLead',
          '/api/class-archive/manage/people/revert-merge',
          { mergeId: item.id },
          () => location.reload(),
        ));
        append(row, description, revert);
        mergeHistory.append(row);
      }
      page.append(mergeHistory);
    }
    const duplicateSection = element('section', 'duplicate-section');
    const duplicateHeading = element('div', 'section-heading');
    append(duplicateHeading, element('h2', '', t('duplicates.title')), element('span', '', String(duplicateGroups.length)));
    duplicateSection.append(duplicateHeading);
    if (duplicateGroups.length === 0) duplicateSection.append(element('p', 'manage-empty', t('duplicates.none')));
    else append(duplicateSection, duplicateGroups.map(duplicateGroupCard));
    page.append(duplicateSection);
    shell('people', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('people.manageTitle', 'people.manageLead'), errorState());
    shell('people', page);
  }
}

function normalizePerson(payload) {
  if (!payload || !validId(payload.id) || !validId(payload.cover_photo_id)
      || !Number.isInteger(payload.photo_count) || !Array.isArray(payload.items)
      || payload.photo_count !== payload.items.length) {
    throw new Error('safe_person_detail_invalid');
  }
  return {
    id: payload.id.toLowerCase(),
    coverPhotoId: payload.cover_photo_id.toLowerCase(),
    portraitFocus: normalizePortraitFocus(payload.portrait_focus),
    name: safeText(payload.label, t('people.unnamed')),
    count: payload.photo_count,
    photos: payload.items.map(normalizeArchivePhoto),
  };
}

async function renderPerson(id) {
  showLoading('people', 'people.title', 'people.lead');
  try {
    const person = normalizePerson(await apiJson(`/api/class-archive/people/${id}`));
    const page = element('div');
    const back = element('a', 'back-link', t('person.back'));
    back.href = '/people';
    const hero = element('section', 'person-hero');
    const portrait = element('span', 'person-photo');
    portrait.append(portraitImage(person, true));
    const copy = element('div');
    append(copy, element('h1', 'page-title', person.name), element('p', 'page-lead', t('common.photosCount', { count: person.count })));
    append(hero, portrait, copy);
    append(page, back, hero, photoGrid(person.photos));
    shell('people', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('people.title', 'people.lead'), errorState());
    shell('people', page);
  }
}

function normalizeLegacySearch(payload) {
  const items = payload?.assets?.items;
  if (!Array.isArray(items)) throw new Error('safe_search_invalid');
  return items.map((asset) => {
    if (!asset || !validId(asset.id)) throw new Error('safe_search_asset_invalid');
    return {
      id: asset.id.toLowerCase(),
      title: businessLabel(asset.originalFileName, 'accessibility.photo'),
      archiveDate: { label: t('common.unknownDate'), precision: t('common.unknownDate'), source: t('common.unknownDate') },
    };
  });
}

async function legacySearch(query) {
  const options = (body) => ({
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const settled = await Promise.allSettled([
    apiJson('/api/search/metadata', options({ metadataSearchDto: { originalFileName: query } })),
    apiJson('/api/search/smart', options({ smartSearchDto: { query } })),
  ]);
  const fulfilled = settled.filter((result) => result.status === 'fulfilled');
  if (fulfilled.length === 0) throw new Error('safe_search_unavailable');
  const combined = new Map();
  for (const result of fulfilled) {
    for (const photo of normalizeLegacySearch(result.value)) {
      if (!combined.has(photo.id)) combined.set(photo.id, photo);
    }
  }
  return {
    structured: [],
    structuredPhotos: [],
    smartPhotos: [...combined.values()],
    partial: fulfilled.length !== settled.length,
    normalized: false,
  };
}

function normalizeSearchPhoto(photo) {
  if (!photo || !validId(photo.id ?? photo.photoId ?? photo.classPhotoId)) throw new Error('safe_hybrid_photo_invalid');
  const id = photo.id ?? photo.photoId ?? photo.classPhotoId;
  if (photo.archive_date || photo.archiveDate) {
    return normalizeArchivePhoto({
      ...photo,
      id,
      archive_date: photo.archive_date ?? photo.archiveDate,
    });
  }
  return {
    id: id.toLowerCase(),
    title: safeText(photo.title ?? photo.label ?? photo.originalFileName, t('accessibility.photo')),
    archiveDate: {
      label: safeText(photo.dateLabel, t('common.unknownDate')),
      precision: precisionLabel(photo.datePrecision ?? 'UNKNOWN'),
      source: t('common.unknownDate'),
    },
  };
}

function resultItems(value) {
  if (Array.isArray(value)) return value;
  return Array.isArray(value?.items) ? value.items : [];
}

function normalizeStructuredResult(type, item) {
  if (!item || typeof item !== 'object') return null;
  const label = safeText(item.label ?? item.name ?? item.title, '');
  if (!label) return null;
  const count = Number.isInteger(item.total ?? item.count ?? item.photoCount) ? (item.total ?? item.count ?? item.photoCount) : null;
  const rawId = item.id ?? item.classPersonId ?? item.albumId;
  let href = null;
  if (type === 'people' && validId(rawId)) href = `/people/${rawId.toLowerCase()}`;
  if (type === 'albums' && validId(rawId)) href = `/albums/${rawId.toLowerCase()}`;
  return { type, label, count, href };
}

function normalizeHybridSearch(payload) {
  if (!payload || typeof payload !== 'object') throw new Error('safe_hybrid_search_invalid');
  const structuredSource = payload.structured ?? payload.exact ?? payload.sections ?? {};
  const structured = [];
  const typeNames = ['people', 'albums', 'events', 'dates', 'tags', 'descriptions'];
  // The Class Archive Gateway calls its archival-date bucket `archiveTime`.
  // Keep the presentation vocabulary as `dates`, while accepting that
  // canonical server field so a structured archive-time result can never
  // disappear merely because it does not share an older compatibility name.
  const structuredType = (value) => value === 'archiveTime' || value === 'archive_time' ? 'dates' : value;
  if (Array.isArray(structuredSource)) {
    for (const section of structuredSource) {
      const candidate = structuredType(section?.type);
      const type = typeNames.includes(candidate) ? candidate : null;
      if (!type) continue;
      const items = resultItems(section);
      structured.push({ type, items: items.map((item) => normalizeStructuredResult(type, item)).filter(Boolean) });
    }
  } else {
    for (const type of typeNames) {
      const items = resultItems(type === 'dates'
        ? (structuredSource.dates ?? structuredSource.archiveTime ?? structuredSource.archive_time)
        : structuredSource[type]);
      if (items.length > 0) structured.push({ type, items: items.map((item) => normalizeStructuredResult(type, item)).filter(Boolean) });
    }
  }
  const structuredPhotos = resultItems(
    structuredSource.photos ?? payload.photos ?? payload.exactPhotos ?? payload.exact_photos,
  ).map(normalizeSearchPhoto);
  const smartPhotos = resultItems(payload.smart ?? payload.smartMatches ?? payload.semantic).map(normalizeSearchPhoto);
  const seen = new Set(structuredPhotos.map((photo) => photo.id));
  const uniqueSmart = smartPhotos.filter((photo) => {
    if (seen.has(photo.id)) return false;
    seen.add(photo.id);
    return true;
  });
  return {
    structured,
    structuredPhotos,
    smartPhotos: uniqueSmart,
    partial: payload.partial === true,
    normalized: payload.normalized === true || Boolean(payload.normalizedQuery ?? payload.normalized_query),
  };
}

async function hybridSearch(query, albumId = null) {
  try {
    const params = new URLSearchParams({ q: query });
    if (albumId && validId(albumId)) params.set('albumId', albumId.toLowerCase());
    return normalizeHybridSearch(await apiJson(`/api/class-archive/search/hybrid?${params}`));
  } catch (error) {
    if (error?.status && error.status !== 404) throw error;
    if (albumId) throw error;
    return legacySearch(query);
  }
}

const SEARCH_SUGGESTION_SECTIONS = Object.freeze([
  { key: 'people', resultType: 'people', titleKey: 'search.peopleSection' },
  { key: 'albums', resultType: 'albums', titleKey: 'search.albumsSection' },
  { key: 'events', resultType: 'events', titleKey: 'search.eventsSection' },
  { key: 'archiveTime', resultType: 'dates', titleKey: 'search.datesSection' },
]);

function normalizeSearchSuggestionItem(type, item) {
  if (!item || typeof item !== 'object') throw new Error('safe_search_suggestion_item_invalid');
  const label = safeText(item.label ?? item.displayAlias ?? item.name ?? item.title, '');
  const rawCount = item.total ?? item.count ?? item.photoCount ?? item.photo_count;
  const rawId = item.id ?? item.classPersonId ?? item.class_person_id ?? item.albumId ?? item.album_id;
  if (!label || (rawCount !== undefined && (!Number.isInteger(rawCount) || rawCount < 0))) {
    throw new Error('safe_search_suggestion_item_invalid');
  }
  if ((type === 'people' || type === 'albums') && !validId(rawId)) {
    throw new Error('safe_search_suggestion_item_invalid');
  }
  let href = null;
  if (type === 'people') href = `/people/${rawId.toLowerCase()}`;
  if (type === 'albums') href = `/albums/${rawId.toLowerCase()}`;
  return { label, count: rawCount ?? null, href };
}

function normalizeSearchSuggestions(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    throw new Error('safe_search_suggestions_invalid');
  }
  return SEARCH_SUGGESTION_SECTIONS.map((section) => {
    const source = payload[section.key];
    if (!source || typeof source !== 'object' || !Number.isInteger(source.total) || source.total < 0
      || !Array.isArray(source.items) || source.items.length > 24 || source.total < source.items.length) {
      throw new Error('safe_search_suggestions_invalid');
    }
    return {
      ...section,
      total: source.total,
      items: source.items.map((item) => normalizeSearchSuggestionItem(section.resultType, item)),
    };
  }).filter((section) => section.items.length > 0);
}

async function searchSuggestions(query, albumId = null, signal = undefined) {
  if (typeof query !== 'string' || query.length > 190 || query.includes('\0')) {
    throw new Error('safe_search_suggestion_query_invalid');
  }
  const params = new URLSearchParams();
  if (query) params.set('q', query);
  if (albumId) {
    if (!validId(albumId)) throw new Error('safe_search_suggestion_album_invalid');
    params.set('albumId', albumId.toLowerCase());
  }
  const suffix = params.size > 0 ? `?${params}` : '';
  return normalizeSearchSuggestions(await apiJson(`/api/class-archive/search/suggestions${suffix}`, {
    cache: 'no-store', signal,
  }));
}

function searchSuggestionSection(section, onQuery) {
  const group = element('section', 'search-live-suggestion-group');
  group.dataset.suggestionType = section.key;
  group.append(element('h2', '', t(section.titleKey)));
  const list = element('div', 'search-live-suggestion-list');
  for (const item of section.items) {
    const node = item.href ? element('a', 'search-live-suggestion') : element('button', 'search-live-suggestion');
    if (item.href) node.href = item.href;
    else {
      node.type = 'button';
      node.addEventListener('click', () => onQuery(item.label));
    }
    append(node,
      element('strong', '', item.label),
      item.count === null ? null : element('span', '', t('common.photosCount', { count: item.count })),
    );
    list.append(node);
  }
  group.append(list);
  return group;
}

function renderSearchSuggestions(sections, onQuery) {
  if (sections.length === 0) return null;
  const panel = element('section', 'search-live-suggestions');
  panel.setAttribute('aria-label', t('search.liveSuggestions'));
  panel.append(element('p', 'search-live-suggestions-label', t('search.liveSuggestions')));
  append(panel, sections.map((section) => searchSuggestionSection(section, onQuery)));
  return panel;
}

function structuredSection(section, onQuery) {
  const key = `search.${section.type}Section`;
  const group = element('section', 'search-structured-group');
  append(group, element('h3', '', t(key)));
  const list = element('div', 'search-result-chips');
  for (const item of section.items) {
    const node = item.href ? element('a', 'search-result-chip') : element('button', 'search-result-chip');
    if (item.href) node.href = item.href;
    else {
      node.type = 'button';
      node.addEventListener('click', () => onQuery(item.label));
    }
    append(node, element('strong', '', item.label), item.count === null ? null : element('span', '', t('common.photosCount', { count: item.count })));
    list.append(node);
  }
  group.append(list);
  return group;
}

function renderHybridResults(response, onQuery) {
  const root = element('div', 'hybrid-results');
  const exactCount = response.structuredPhotos.length + response.structured.reduce((total, section) => total + section.items.length, 0);
  if (exactCount > 0) {
    const section = element('section', 'search-section');
    const heading = element('div', 'search-section-heading');
    append(heading, element('div', '', undefined), element('span', 'result-kind', t('search.structured')));
    const headingCopy = heading.firstElementChild;
    append(headingCopy, element('h2', '', t('search.structured')), element('p', '', t('search.structuredLead')));
    section.append(heading);
    for (const group of response.structured) section.append(structuredSection(group, onQuery));
    if (response.structuredPhotos.length > 0) {
      const photosHeading = element('h3', 'search-subheading', t('search.photosSection'));
      append(section, photosHeading, photoGrid(response.structuredPhotos, 'search-photo-grid'));
    }
    root.append(section);
  }
  if (response.smartPhotos.length > 0) {
    const section = element('section', 'search-section smart-section');
    const heading = element('div', 'search-section-heading');
    const copy = element('div');
    append(copy, element('h2', '', t('search.smart')), element('p', '', t('search.smartLead')));
    append(heading, copy, element('span', 'beta-badge', t('search.smartBeta')));
    append(section, heading, photoGrid(response.smartPhotos, 'search-photo-grid'));
    root.append(section);
  }
  if (exactCount === 0 && response.smartPhotos.length === 0) root.append(emptyState('search.noResultsTitle', 'search.noResultsBody'));
  return root;
}

const SEARCH_SUGGESTION_KEYS = Object.freeze([
  'search.suggestionGraduation',
  'search.suggestionSportsMeet',
  'search.suggestionClassroom',
  'search.suggestionPlayground',
  'search.suggestionGroupPhoto',
  'search.suggestionBasketball',
]);

function searchDiscovery(onQuery) {
  const discovery = element('section', 'search-discovery');
  const suggestions = element('div', 'search-suggestions');
  for (const key of SEARCH_SUGGESTION_KEYS) {
    const suggestion = element('button', 'search-suggestion', t(key));
    suggestion.type = 'button';
    suggestion.addEventListener('click', () => onQuery(t(key)));
    suggestions.append(suggestion);
  }
  append(
    discovery,
    element('p', 'search-discovery-label', t('search.suggestionsTitle')),
    suggestions,
    element('p', 'search-discovery-hint', t('search.discoveryHint')),
  );
  return discovery;
}

async function renderSearch() {
  if (runtime.activeSelection) runtime.activeSelection.destroy();
  const page = element('div');
  append(page, pageHeader('search.title', 'search.lead'));
  const form = element('form', 'search-form');
  form.role = 'search';
  const input = element('input', 'search-field');
  input.type = 'search';
  input.name = 'query';
  input.autocomplete = 'off';
  input.maxLength = 190;
  input.placeholder = t('search.placeholder');
  input.setAttribute('aria-label', t('search.label'));
  const submit = element('button', 'primary-button', t('search.submit'));
  submit.type = 'submit';
  append(form, input, submit);
  const status = element('p', 'search-status');
  status.setAttribute('aria-live', 'polite');
  status.hidden = true;
  const results = element('div');
  const suggestionHost = element('div', 'search-live-suggestions-host');
  suggestionHost.hidden = true;
  const albumId = new URLSearchParams(location.search).get('album');
  const albumContext = validId(albumId) ? albumId.toLowerCase() : null;
  let suggestionTimer = null;
  let suggestionController = null;
  let suggestionGeneration = 0;
  const clearSearchSuggestions = () => {
    suggestionGeneration += 1;
    if (suggestionTimer !== null) {
      clearTimeout(suggestionTimer);
      suggestionTimer = null;
    }
    if (suggestionController !== null) {
      suggestionController.abort();
      suggestionController = null;
    }
    suggestionHost.hidden = true;
    suggestionHost.replaceChildren();
  };
  const runQuery = (value) => {
    input.value = value;
    clearSearchSuggestions();
    form.requestSubmit();
  };
  const discovery = searchDiscovery(runQuery);
  const context = albumContext ? element('div', 'search-context') : null;
  if (context) {
    const clear = element('a', 'search-context-clear', t('search.clearAlbumContext'));
    clear.href = '/search';
    append(context, element('span', '', t('search.albumContext')), clear);
  }
  append(page, form, context, suggestionHost, discovery, status);
  shell('search', page);

  input.addEventListener('input', () => {
    const query = input.value.trim();
    clearSearchSuggestions();
    if (!query) return;
    const generation = suggestionGeneration;
    suggestionTimer = setTimeout(async () => {
      const controller = new AbortController();
      suggestionController = controller;
      try {
        const sections = await searchSuggestions(query, albumContext, controller.signal);
        if (generation !== suggestionGeneration || input.value.trim() !== query) return;
        const panel = renderSearchSuggestions(sections, runQuery);
        if (panel) suggestionHost.replaceChildren(panel);
        else suggestionHost.replaceChildren();
        suggestionHost.hidden = panel === null;
      } catch {
        // Suggestions are optional presentation metadata. A failed or stale
        // request must disappear rather than leave cached candidate labels on
        // screen; the explicit submitted search remains independently safe.
        if (generation === suggestionGeneration) {
          suggestionHost.hidden = true;
          suggestionHost.replaceChildren();
        }
      } finally {
        if (suggestionController === controller) suggestionController = null;
      }
    }, 180);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = input.value.trim();
    if (!query) {
      input.focus();
      return;
    }
    clearSearchSuggestions();
    discovery.hidden = true;
    status.hidden = false;
    if (!results.isConnected) page.append(results);
    submit.disabled = true;
    status.textContent = t('search.searching');
    results.replaceChildren(loadingState());
    try {
      const response = await hybridSearch(query, albumContext);
      const total = response.structuredPhotos.length + response.smartPhotos.length;
      status.textContent = response.partial
        ? t('search.partial')
        : `${t('search.results', { count: total })}${response.normalized ? ` · ${t('search.normalized')}` : ''}`;
      results.replaceChildren(renderHybridResults(response, runQuery));
    } catch {
      status.textContent = '';
      results.replaceChildren(errorState());
    } finally {
      submit.disabled = false;
    }
  });
}

function normalizeAlbums(payload) {
  const items = Array.isArray(payload) ? payload : payload?.items;
  if (!Array.isArray(items)) throw new Error('safe_albums_invalid');
  return items.map((album) => {
    const id = album?.id ?? album?.albumId ?? album?.class_album_id;
    const total = album?.total ?? album?.assetCount ?? album?.photoCount ?? album?.photo_count;
    const coverPhotoId = album?.coverPhotoId ?? album?.cover_photo_id ?? album?.albumThumbnailAssetId ?? null;
    const parentAlbumId = album?.parentAlbumId ?? album?.parent_album_id ?? null;
    const directTotal = album?.directTotal ?? album?.direct_total ?? null;
    if (!album || !validId(id) || !Number.isInteger(total) || total < 0
      || (coverPhotoId !== null && !validId(coverPhotoId))
      || (parentAlbumId !== null && !validId(parentAlbumId))
      || (directTotal !== null && (!Number.isInteger(directTotal) || directTotal < 0 || directTotal > total))) {
      throw new Error('safe_album_invalid');
    }
    return {
      id: id.toLowerCase(),
      parentAlbumId: parentAlbumId ? parentAlbumId.toLowerCase() : null,
      name: safeText(album.name ?? album.albumName ?? album.album_name, t('albums.title')),
      displayAlias: safeText(album.displayAlias ?? album.display_alias, ''),
      sourceLabel: safeText(album.sourceLabel ?? album.source_label, ''),
      sourceKind: ['QQ', 'GRADUATION', 'ARCHIVE', 'COMMUNITY'].includes(album.sourceKind ?? album.source_kind)
        ? (album.sourceKind ?? album.source_kind) : null,
      type: (album.type ?? album.album_type) === 'COMMUNITY' ? 'COMMUNITY' : 'OFFICIAL',
      description: safeText(album.description, ''),
      eventLabel: safeText(album.eventLabel ?? album.event_label, ''),
      dateLabel: safeText(album.dateLabel ?? album.date_label, ''),
      count: total,
      directCount: directTotal === null ? total : directTotal,
      coverPhotoId: coverPhotoId ? coverPhotoId.toLowerCase() : null,
      sourceRoot: album.sourceRoot === true || album.source_root === true,
      owned: album.owned === true || album.owned_by_current === true,
      canSpotlight: album.canSpotlight === true || album.can_spotlight === true,
    };
  });
}

function albumDisplayName(album) {
  return album.displayAlias || album.name;
}

function albumCard(album) {
  const card = element('a', 'album-card');
  card.href = `/albums/${album.id}`;
  const cover = element('div', 'album-cover');
  if (album.coverPhotoId) cover.append(resilientImage(mediaUrl(album.coverPhotoId, 'medium'), '', false, { sizes: '(max-width: 680px) 100vw, 34vw' }));
  const copy = element('div', 'album-copy');
  append(copy,
    element('h3', 'album-title', albumDisplayName(album)),
    album.sourceLabel ? element('p', 'album-source', album.sourceLabel) : null,
    album.eventLabel || album.dateLabel ? element('p', 'album-meta', [album.eventLabel, album.dateLabel].filter(Boolean).join(' · ')) : null,
    element('p', 'album-count', t('common.photosCount', { count: album.count })),
  );
  append(card, cover, copy);
  return card;
}

function albumSection(titleKey, leadKey, albums) {
  const section = element('section', 'album-section');
  const heading = element('div', 'collection-heading');
  const copy = element('div');
  append(copy, element('h2', '', t(titleKey)), element('p', '', t(leadKey)));
  heading.append(copy);
  section.append(heading);
  const grid = element('div', 'album-grid');
  append(grid, albums.map((album) => albumCard(album)));
  section.append(grid);
  return section;
}

function homeItems(value) {
  if (Array.isArray(value)) return value;
  if (value && typeof value === 'object' && Array.isArray(value.items)) return value.items;
  if (value && typeof value === 'object' && Array.isArray(value.people)) return value.people;
  if (value && typeof value === 'object' && value.item && typeof value.item === 'object') return [value.item];
  return [];
}

function normalizeHomeFeature(item) {
  if (!item || typeof item !== 'object') return null;
  const albumId = item.albumId ?? item.album_id;
  const photoId = item.photoId ?? item.photo_id ?? item.coverPhotoId ?? item.cover_photo_id;
  const coverPhotoId = item.coverPhotoId ?? item.cover_photo_id ?? photoId;
  if (!validId(albumId) && !validId(photoId)) return null;
  return {
    href: validId(albumId) ? `/albums/${albumId.toLowerCase()}` : `/photos/${photoId.toLowerCase()}`,
    coverPhotoId: validId(coverPhotoId) ? coverPhotoId.toLowerCase() : null,
    title: businessLabel(item.title ?? item.label ?? item.name ?? item.albumName ?? item.album_name, 'home.featured'),
    subtitle: safeText(item.subtitle ?? item.description, ''),
  };
}

function normalizeHomeMemory(item) {
  if (!item || typeof item !== 'object') return null;
  const coverPhotoId = item.coverPhotoId ?? item.cover_photo_id;
  const albumId = item.albumId ?? item.album_id;
  if (!validId(coverPhotoId)) return null;
  const count = item.photoCount ?? item.photo_count ?? item.count;
  return {
    coverPhotoId: coverPhotoId.toLowerCase(),
    href: validId(albumId) ? `/albums/${albumId.toLowerCase()}` : `/photos/${coverPhotoId.toLowerCase()}`,
    title: businessLabel(item.title ?? item.label ?? item.name, 'memories.title'),
    subtitle: safeText(item.subtitle, ''),
    count: Number.isInteger(count) && count > 0 ? count : null,
  };
}

function normalizeHome(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)
    || !Object.hasOwn(payload, 'featured') || !Object.hasOwn(payload, 'memories')
    || !Object.hasOwn(payload, 'albums') || !Object.hasOwn(payload, 'people')
    || !Object.hasOwn(payload, 'allPhotos')) {
    throw new Error('safe_home_invalid');
  }
  const allPhotos = payload.allPhotos;
  const allPhotosCount = allPhotos?.total ?? allPhotos?.photoCount ?? allPhotos?.photo_count ?? allPhotos?.count;
  if (!Number.isInteger(allPhotosCount) || allPhotosCount < 0) throw new Error('safe_home_photo_count_invalid');
  const albums = normalizeAlbums({ items: homeItems(payload.albums) }).filter((album) => album.directCount > 0);
  const peopleItems = homeItems(payload.people);
  const people = peopleItems.length > 0
    ? normalizePeople({ people: peopleItems, total: peopleItems.length }) : [];
  return {
    featured: homeItems(payload.featured).map(normalizeHomeFeature).filter(Boolean).slice(0, 1),
    memories: homeItems(payload.memories).map(normalizeHomeMemory).filter(Boolean).slice(0, 8),
    albums: albums.slice(0, 8),
    people: people.slice(0, 8),
    allPhotosCount,
  };
}

function homeRowHeading(titleKey, leadKey, href = '') {
  const heading = element('div', 'home-row-heading');
  const copy = element('div');
  append(copy, element('h2', '', t(titleKey)), leadKey ? element('p', '', t(leadKey)) : null);
  heading.append(copy);
  if (href) {
    const link = element('a', 'home-row-link', t('home.viewAll'));
    link.href = href;
    heading.append(link);
  }
  return heading;
}

function homeMemoryCard(memory) {
  const card = element('a', 'home-memory-card');
  card.href = memory.href;
  const cover = element('span', 'home-memory-cover');
  cover.append(resilientImage(mediaUrl(memory.coverPhotoId, 'large'), '', false, { sizes: '(max-width: 760px) 68vw, 280px' }));
  const copy = element('span', 'home-memory-copy');
  append(copy,
    element('strong', '', memory.title),
    memory.subtitle ? element('span', '', memory.subtitle) : null,
    memory.count === null ? null : element('span', '', t('common.photosCount', { count: memory.count })),
  );
  append(card, cover, copy);
  return card;
}

function homePersonCard(person) {
  const card = element('a', 'home-person-card');
  card.href = `/people/${person.id}`;
  const portrait = element('span', 'home-person-photo');
  portrait.append(portraitImage(person));
  append(card, portrait, element('strong', '', person.name),
    person.count === null ? null : element('span', '', t('common.photosCount', { count: person.count })));
  return card;
}

async function renderHome() {
  showLoading('home', 'home.title', 'home.lead');
  try {
    // The home projection is intentionally its own compact server-side read.
    // It must never fetch or aggregate the full timeline in the browser.
    const home = normalizeHome((await presentationJson('/api/class-archive/home')).value);
    assertPresentationActive();
    const page = element('div', 'home-page');
    page.append(pageHeader('home.title', 'home.lead'));

    const featured = element('section', 'home-featured');
    featured.append(homeRowHeading('home.featured', 'home.featuredLead'));
    if (home.featured.length === 0) {
      featured.append(element('p', 'home-row-empty', t('home.featuredEmpty')));
    } else {
      const item = home.featured[0];
      const card = element('a', 'home-featured-card');
      card.href = item.href;
      if (item.coverPhotoId) card.append(resilientImage(mediaUrl(item.coverPhotoId, 'large'), '', true, { sizes: '(max-width: 760px) 100vw, 72vw' }));
      const shade = element('div', 'home-featured-copy');
      append(shade, element('p', '', t('home.featured')), element('h2', '', item.title), item.subtitle ? element('span', '', item.subtitle) : null);
      card.append(shade);
      featured.append(card);
    }

    const memories = element('section', 'home-section');
    memories.append(homeRowHeading('home.memories', 'home.memoriesLead', '/memories'));
    const memoryRow = element('div', 'home-memory-row');
    if (home.memories.length > 0) append(memoryRow, home.memories.map(homeMemoryCard));
    else memoryRow.append(element('p', 'home-row-empty', t('home.memoriesEmpty')));
    memories.append(memoryRow);

    const albums = element('section', 'home-section');
    albums.append(homeRowHeading('home.albums', 'home.albumsLead', '/albums'));
    const albumRow = element('div', 'home-album-row');
    if (home.albums.length > 0) append(albumRow, home.albums.map(albumCard));
    else albumRow.append(element('p', 'home-row-empty', t('home.albumsEmpty')));
    albums.append(albumRow);

    const people = element('section', 'home-section');
    people.append(homeRowHeading('home.people', 'home.peopleLead', '/people'));
    const peopleRow = element('div', 'home-people-row');
    if (home.people.length > 0) append(peopleRow, home.people.map(homePersonCard));
    else peopleRow.append(element('p', 'home-row-empty', t('home.peopleEmpty')));
    people.append(peopleRow);

    const allPhotos = element('a', 'home-all-photos', t('home.allPhotos'));
    allPhotos.href = '/photos';
    allPhotos.dataset.homeAllPhotos = 'true';
    allPhotos.setAttribute('aria-label', t('home.allPhotosCount', { count: home.allPhotosCount }));
    page.append(featured, memories, albums, people, allPhotos);
    shell('home', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('home.title', 'home.lead'), errorState());
    shell('home', page);
  }
}

async function loadAlbums() {
  return (await presentationJson('/api/class-archive/albums')).value;
}

const ALBUM_FILTERS = Object.freeze([
  { key: 'all', labelKey: 'albums.filterAll' },
  { key: 'qq', labelKey: 'albums.filterQq' },
  { key: 'graduation', labelKey: 'albums.filterGraduation' },
  { key: 'archive', labelKey: 'albums.filterArchive' },
  { key: 'community', labelKey: 'albums.filterCommunity' },
]);

function albumMatchesFilter(album, filter) {
  if (filter === 'all') return true;
  if (filter === 'community') return album.type === 'COMMUNITY' || album.sourceKind === 'COMMUNITY';
  if (filter === 'archive') return album.type === 'OFFICIAL' && album.sourceKind === 'ARCHIVE';
  if (filter === 'qq') return album.sourceKind === 'QQ';
  if (filter === 'graduation') return album.sourceKind === 'GRADUATION';
  return false;
}

function albumFilterBar(activeFilter, onFilter) {
  const bar = element('div', 'album-filter-bar');
  bar.setAttribute('aria-label', t('albums.filtersLabel'));
  for (const filter of ALBUM_FILTERS) {
    const button = element('button', 'album-filter', t(filter.labelKey));
    button.type = 'button';
    button.dataset.active = String(filter.key === activeFilter);
    button.setAttribute('aria-pressed', String(filter.key === activeFilter));
    button.addEventListener('click', () => onFilter(filter.key));
    bar.append(button);
  }
  return bar;
}

async function renderAlbums() {
  showLoading('albums', 'albums.title', 'albums.lead');
  try {
    const albums = normalizeAlbums(await loadAlbums());
    assertPresentationActive();
    // Source collections and folder containers remain durable provenance only.
    // The member-facing grid receives leaf albums from the Gateway, then keeps
    // a second defensive direct-membership check so an accidental container
    // payload never restores file-manager navigation to the product surface.
    const leafAlbums = albums.filter((album) => album.directCount > 0);
    let activeFilter = 'all';
    const paint = () => {
      const page = element('div');
      append(page, pageHeader('albums.title', 'albums.lead'));
      if (leafAlbums.length === 0) {
        page.append(emptyState('albums.emptyTitle', 'albums.emptyBody'));
      } else {
        page.append(albumFilterBar(activeFilter, (next) => {
          activeFilter = next;
          paint();
        }));
        const visible = leafAlbums.filter((album) => albumMatchesFilter(album, activeFilter));
        if (visible.length === 0) page.append(emptyState('albums.emptyFilterTitle', 'albums.emptyFilterBody'));
        else page.append(albumSection('albums.leafTitle', 'albums.leafLead', visible));
      }
      shell('albums', page);
    };
    paint();
  } catch {
    const page = element('div');
    append(page, pageHeader('albums.title', 'albums.lead'), errorState());
    shell('albums', page);
  }
}

function normalizeAlbumDetail(payload) {
  const source = payload?.album ? { ...payload.album, items: payload.items ?? payload.album.items } : payload;
  const [album] = normalizeAlbums({ items: [source] });
  const rawItems = source?.items ?? source?.photos;
  if (!Array.isArray(rawItems)) throw new Error('safe_album_detail_invalid');
  const photos = rawItems.map(normalizeArchivePhoto);
  const pageCount = source?.count ?? photos.length;
  const pageLimit = source?.limit ?? Math.max(1, photos.length);
  const hasMore = source?.hasMore ?? source?.has_more ?? false;
  const nextCursor = source?.nextCursor ?? source?.next_cursor ?? null;
  if (!Number.isInteger(pageCount) || pageCount !== photos.length || pageCount < 0 || pageCount > album.count
    || !Number.isInteger(pageLimit) || pageLimit < 1 || pageLimit > 240
    || typeof hasMore !== 'boolean'
    || (hasMore && (typeof nextCursor !== 'string' || !TIMELINE_CURSOR.test(nextCursor)))
    || (!hasMore && nextCursor !== null)
    || (album.count === 0 && (pageCount !== 0 || hasMore))
    || (album.count > 0 && pageCount === 0)) {
    throw new Error('safe_album_detail_count_invalid');
  }
  const ids = new Set();
  for (const photo of photos) {
    if (ids.has(photo.id)) throw new Error('safe_album_detail_duplicate');
    ids.add(photo.id);
  }
  return { ...album, photos, pageCount, pageLimit, hasMore, nextCursor };
}

function mergeAlbumPages(current, next) {
  if (!current || !next || current.id !== next.id || current.count !== next.count
    || current.pageLimit !== next.pageLimit || current.hasMore !== true || current.nextCursor === null) {
    throw new Error('safe_album_page_state_invalid');
  }
  const ids = new Set(current.photos.map((photo) => photo.id));
  for (const photo of next.photos) {
    if (ids.has(photo.id)) throw new Error('safe_album_page_duplicate');
    ids.add(photo.id);
  }
  const pageCount = current.pageCount + next.pageCount;
  if (pageCount !== ids.size || pageCount > current.count
    || (!next.hasMore && pageCount !== current.count)
    || (next.hasMore && pageCount >= current.count)) {
    throw new Error('safe_album_page_total_invalid');
  }
  return {
    ...current,
    photos: [...current.photos, ...next.photos],
    pageCount,
    hasMore: next.hasMore,
    nextCursor: next.nextCursor,
  };
}

async function loadAlbumPage(id, cursor = null, limit = 120) {
  if (!validId(id) || !Number.isInteger(limit) || limit < 1 || limit > 240
    || (cursor !== null && (typeof cursor !== 'string' || !TIMELINE_CURSOR.test(cursor)))) {
    throw new Error('safe_album_page_request_invalid');
  }
  const params = new URLSearchParams({ limit: String(limit) });
  if (cursor) params.set('cursor', cursor);
  return normalizeAlbumDetail(await apiJson(`/api/class-archive/albums/${id.toLowerCase()}?${params}`));
}

async function renderAlbum(id) {
  showLoading('albums', 'albums.title', 'albums.lead');
  try {
    const [initialAlbum, state, spotlightRead] = await Promise.all([
      loadAlbumPage(id),
      productState(),
      presentationJson('/api/class-archive/spotlight'),
    ]);
    assertPresentationActive();
    let album = initialAlbum;
    const spotlight = normalizeSpotlight(spotlightRead.value);
    let pageRequestActive = false;
    const paint = () => {
      if (runtime.activeSelection) runtime.activeSelection.destroy();
      const page = element('div');
      const back = element('a', 'back-link', t('albums.back'));
      back.href = '/albums';
      page.append(back);
      const hero = element('section', 'album-detail-hero');
      if (album.coverPhotoId) hero.append(resilientImage(mediaUrl(album.coverPhotoId, 'preview'), '', true, { sizes: '100vw' }));
      const shade = element('div', 'album-detail-shade');
      append(shade,
        album.sourceLabel ? element('p', 'page-eyebrow', album.sourceLabel) : element('p', 'page-eyebrow', album.type === 'COMMUNITY' ? t('albums.community') : t('albums.official')),
        element('h1', '', albumDisplayName(album)),
        album.description ? element('p', 'album-description', album.description) : null,
        element('p', 'album-detail-meta', [album.eventLabel, album.dateLabel, t('common.photosCount', { count: album.count })].filter(Boolean).join(' · ')),
      );
      const heroActions = element('div', 'album-hero-actions');
      const searchWithin = element('a', 'light-button', t('albums.searchWithin'));
      searchWithin.href = `/search?album=${album.id}`;
      heroActions.append(searchWithin);
      if (state.canSpotlight && album.owned && album.canSpotlight && spotlight?.albumId !== album.id) {
        const create = element('button', 'light-button', t('spotlight.create'));
        create.type = 'button';
        create.addEventListener('click', () => openReasonMutation(
          'spotlight.createTitle',
          'spotlight.createLead',
          '/api/class-archive/spotlight/create',
          { albumId: album.id, durationHours: 24 },
          reloadProjectionBackedRoute,
        ));
        heroActions.append(create);
      }
      if (spotlight?.albumId === album.id) heroActions.append(element('span', 'light-status', t('spotlight.active')));
      shade.append(heroActions);
      hero.append(shade);
      page.append(hero);
      if (album.photos.length === 0) {
        page.append(element('p', 'manage-empty', t('albums.noPhotos')));
      } else {
        let manageButton = null;
        if (state.canManage) {
          const actions = element('div', 'page-actions');
          manageButton = element('button', 'secondary-button', t('photos.organize'));
          manageButton.type = 'button';
          actions.append(manageButton);
          page.append(actions);
        }
        const controller = state.canManage ? selectionController(album.photos, {
          onSetCover: (photoId) => openReasonMutation(
            'albums.setCover',
            '',
            '/api/class-archive/manage/albums/cover',
            { albumId: album.id, photoId },
            () => location.reload(),
          ),
        }) : null;
        if (manageButton && controller) manageButton.addEventListener('click', () => controller.enter());
        page.append(photoGrid(album.photos, 'album-photo-grid', controller));
      }
      if (album.hasMore) {
        const controls = element('div', 'timeline-page-controls');
        const status = element('p', 'timeline-page-status', t('albums.loadedCount', { count: album.pageCount, total: album.count }));
        const button = element('button', 'secondary-button', t('albums.loadMore'));
        button.type = 'button';
        button.addEventListener('click', async () => {
          if (button.disabled || pageRequestActive || !album.nextCursor) return;
          button.disabled = true;
          button.textContent = t('albums.loadingMore');
          pageRequestActive = true;
          try {
            const next = await loadAlbumPage(album.id, album.nextCursor, album.pageLimit);
            if (runtime.presentationFailureActive || document.visibilityState !== 'visible') {
              throw new Error('safe_album_page_session_changed');
            }
            album = mergeAlbumPages(album, next);
            paint();
          } catch (error) {
            failClosedPresentation(error);
          } finally {
            pageRequestActive = false;
          }
        });
        append(controls, status, button);
        page.append(controls);
      }
      shell('albums', page);
    };
    paint();
  } catch {
    const page = element('div');
    append(page, pageHeader('albums.title', 'albums.lead'), errorState());
    shell('albums', page);
  }
}

async function renderMemories() {
  showLoading('memories', 'memories.title', 'memories.lead');
  try {
    const payload = (await presentationJson('/api/class-archive/memories')).value;
    assertPresentationActive();
    if (!payload || !Number.isInteger(payload.total) || !Array.isArray(payload.items) || payload.total !== payload.items.length) {
      throw new Error('safe_memories_invalid');
    }
    const memories = payload.items.map((memory) => {
      if (!memory || typeof memory.label !== 'string' || !Number.isInteger(memory.photo_count)
          || memory.photo_count < 1 || !validId(memory.cover_photo_id)) {
        throw new Error('safe_memory_invalid');
      }
      return {
        label: businessLabel(memory.label, 'memories.title'),
        count: memory.photo_count,
        coverPhotoId: memory.cover_photo_id.toLowerCase(),
        href: validId(memory.album_id ?? memory.albumId)
          ? `/albums/${(memory.album_id ?? memory.albumId).toLowerCase()}`
          : `/photos/${memory.cover_photo_id.toLowerCase()}`,
      };
    });
    const page = element('div');
    append(page, pageHeader('memories.title', 'memories.lead'));
    if (memories.length === 0) {
      page.append(emptyState('memories.emptyTitle', 'memories.emptyBody'));
    } else {
      page.append(element('p', 'memory-archive-note', t('memories.archiveNote')));
      const grid = element('div', 'memory-grid');
      for (const memory of memories) {
        const card = element('a', 'memory-card');
        card.href = memory.href;
        card.setAttribute('aria-label', `${t('memories.open')}: ${memory.label}`);
        const cover = resilientImage(mediaUrl(memory.coverPhotoId, 'large'), '', false, { sizes: '(max-width: 680px) 100vw, 42vw' });
        const shade = element('div', 'memory-shade');
        append(shade, element('h2', 'memory-title', memory.label), element('p', 'memory-count', t('common.photosCount', { count: memory.count })));
        append(card, cover, shade);
        grid.append(card);
      }
      page.append(grid);
    }
    shell('memories', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('memories.title', 'memories.lead'), errorState());
    shell('memories', page);
  }
}

async function renderMy() {
  showLoading('my', 'my.title', 'my.lead');
  try {
    const [user, state] = await Promise.all([apiJson('/api/users/me'), productState()]);
    const role = roleLabel(state.role);
    const page = element('div');
    append(page, pageHeader('my.title', 'my.lead'));
    const card = element('section', 'profile-card');
    const displayName = safeText(user?.displayName ?? user?.name, '');
    append(card,
      displayName ? element('h2', 'profile-name', displayName) : null,
      element('p', '', t('my.currentRole')),
      element('span', 'role-badge', role),
      element('p', '', t('my.scopeNote')),
    );
    const links = element('div', 'profile-links');
    const linkItems = [];
    if (['CLASSMATE', 'TEACHER', 'FAMILY'].includes(state.role)) {
      linkItems.push(['/class-archive-core/identity', 'my.identity']);
    }
    if (state.role === 'CLASSMATE') {
      linkItems.push(['/class-archive-core/identity', 'my.familyInvite']);
      linkItems.push(['/class-archive-core/identity', 'my.anonymousSeat']);
    }
    if (state.role === 'FAMILY') linkItems.push(['/class-archive-core/identity', 'my.submissions']);
    if (state.canManage) {
      linkItems.push(['/class-archive-core/admin', 'my.adminConsole']);
      linkItems.push(['/people/manage', 'my.peopleManage']);
    }
    linkItems.push(['/class-archive-core/home', 'my.gallery']);
    linkItems.push(['/class-archive-about', 'my.about']);
    for (const [href, key] of linkItems) {
      const link = element('a', 'profile-link');
      link.href = href;
      append(link, element('span', '', t(key)), element('span', '', t('common.view')));
      links.append(link);
    }
    append(card, links, element('p', 'environment-note', t('my.localOnly')));
    page.append(card);
    shell('my', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('my.title', 'my.lead'), errorState());
    shell('my', page);
  }
}

function route() {
  const path = location.pathname;
  if (path === '/home') return { name: 'home' };
  if (path === '/photos') return { name: 'photos' };
  const photo = /^\/photos\/([0-9a-f-]{36})$/i.exec(path);
  if (photo && validId(photo[1])) return { name: 'viewer', id: photo[1].toLowerCase() };
  if (path === '/people') return { name: 'people' };
  if (path === '/people/manage') return { name: 'peopleManage' };
  const person = /^\/people\/([0-9a-f-]{36})$/i.exec(path);
  if (person && validId(person[1])) return { name: 'person', id: person[1].toLowerCase() };
  if (path === '/search') return { name: 'search' };
  if (path === '/albums') return { name: 'albums' };
  const album = /^\/albums\/([0-9a-f-]{36})$/i.exec(path);
  if (album && validId(album[1])) return { name: 'album', id: album[1].toLowerCase() };
  if (path === '/memories') return { name: 'memories' };
  if (path === '/my') return { name: 'my' };
  return { name: 'home' };
}

async function start() {
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.scrollTo(0, 0);
  applyDocumentTranslations();
  const current = route();
  const handlers = {
    home: () => renderHome(),
    photos: () => renderPhotos(),
    viewer: () => renderViewer(current.id),
    people: () => renderPeople(),
    peopleManage: () => renderPeopleManage(),
    person: () => renderPerson(current.id),
    search: () => renderSearch(),
    albums: () => renderAlbums(),
    album: () => renderAlbum(current.id),
    memories: () => renderMemories(),
    my: () => renderMy(),
  };
  await handlers[current.name]();
  // A full page-route transition can restore the previous document scroll
  // position after its initial script has run.  Keep every top-level photo
  // product destination anchored at its own header so a newly opened Search
  // page never begins with its title clipped above the viewport.
  window.scrollTo(0, 0);
}

async function revalidateVisibleSession() {
  concealPrivatePresentation();
  const validationGeneration = ++runtime.sessionValidationGeneration;
  const previousScope = runtime.cacheScope;
  runtime.productStatePromise = null;
  const state = await productState();
  if (validationGeneration !== runtime.sessionValidationGeneration) return;
  if (!state.cacheScope || state.role === 'UNKNOWN') {
    clearPresentationCache();
    location.replace('/auth/login');
    return;
  }
  if (previousScope !== null && previousScope !== state.cacheScope) {
    clearPresentationCache();
    location.reload();
    return;
  }
  if (document.visibilityState !== 'visible') return;
  runtime.presentationFailureActive = false;
  revealPrivatePresentation();
}

window.addEventListener('pagehide', () => {
  ++runtime.sessionValidationGeneration;
  concealPrivatePresentation();
});
window.addEventListener('pageshow', (event) => {
  if (event.persisted) void revalidateVisibleSession();
});
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') {
    ++runtime.sessionValidationGeneration;
    concealPrivatePresentation();
    return;
  }
  void revalidateVisibleSession();
});

void start();
