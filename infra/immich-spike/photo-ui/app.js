import { applyDocumentTranslations, t } from './i18n.js';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const app = document.getElementById('app');

const navigation = Object.freeze([
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
  brand.href = '/photos';
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
  append(nav, navigation.map((item) => navLink(item, active, true)));
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
  const state = element('section', 'empty-state');
  state.setAttribute('aria-live', 'polite');
  state.append(element('p', '', t('common.loading')));
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

function showLoading(active, titleKey, leadKey) {
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
  ]);
  return labels.get(value) ?? safeText(value, fallbackKey ? t(fallbackKey) : '');
}

function validId(value) {
  return typeof value === 'string' && UUID_V4.test(value);
}

function apiError(response) {
  const error = new Error('safe_api_error');
  error.status = response.status;
  return error;
}

async function apiJson(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    cache: 'no-store',
    ...options,
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
  });
  if (response.status === 401) {
    location.assign('/auth/login');
    throw apiError(response);
  }
  if (!response.ok || !(response.headers.get('content-type') ?? '').toLowerCase().startsWith('application/json')) {
    throw apiError(response);
  }
  return response.json();
}

function normalizeArchivePhoto(photo) {
  if (!photo || !validId(photo.id)) throw new Error('safe_photo_invalid');
  const archive = photo.archive_date && typeof photo.archive_date === 'object' ? photo.archive_date : {};
  return {
    id: photo.id.toLowerCase(),
    title: businessLabel(photo.title, 'accessibility.photo'),
    archiveDate: {
      label: safeText(archive.label, t('common.unknownDate')),
      precision: safeText(archive.precision, t('common.unknownDate')),
      source: safeText(archive.source, t('common.unknownDate')),
    },
  };
}

function normalizeTimeline(payload) {
  if (!payload || !Number.isInteger(payload.total) || payload.total < 0 || !Array.isArray(payload.groups)) {
    throw new Error('safe_timeline_invalid');
  }
  const ids = new Set();
  const groups = payload.groups.map((group) => {
    if (!group || !Array.isArray(group.items) || !Number.isInteger(group.total) || group.total !== group.items.length) {
      throw new Error('safe_timeline_group_invalid');
    }
    const items = group.items.map(normalizeArchivePhoto);
    for (const photo of items) {
      if (ids.has(photo.id)) throw new Error('safe_timeline_duplicate');
      ids.add(photo.id);
    }
    return {
      label: businessLabel(group.label, 'common.unknownDate'),
      total: group.total,
      items,
    };
  });
  if (ids.size !== payload.total) throw new Error('safe_timeline_total_invalid');
  return { total: payload.total, groups };
}

function mediaUrl(id, size) {
  if (!validId(id) || !['thumbnail', 'preview'].includes(size)) throw new Error('safe_media_path_invalid');
  return `/api/assets/${id.toLowerCase()}/thumbnail?size=${size}`;
}

const adjacentPreviewCache = new Map();

function prefetchAdjacentPreviews(photos, index) {
  for (const offset of [-1, 1]) {
    const adjacent = photos[index + offset];
    if (!adjacent || adjacentPreviewCache.has(adjacent.id)) continue;
    const preview = new Image();
    preview.decoding = 'async';
    preview.referrerPolicy = 'no-referrer';
    preview.src = mediaUrl(adjacent.id, 'preview');
    adjacentPreviewCache.set(adjacent.id, preview);
  }
  while (adjacentPreviewCache.size > 4) {
    adjacentPreviewCache.delete(adjacentPreviewCache.keys().next().value);
  }
}

function resilientImage(src, alt, eager = false) {
  const image = element('img');
  image.src = src;
  image.alt = alt;
  image.loading = eager ? 'eager' : 'lazy';
  image.decoding = 'async';
  image.referrerPolicy = 'no-referrer';
  image.addEventListener('error', () => image.remove(), { once: true });
  return image;
}

function photoCard(photo, index = 0) {
  const link = element('a', 'photo-card');
  link.href = `/photos/${photo.id}`;
  link.setAttribute('aria-label', photo.title);
  const caption = element('span', 'photo-caption');
  append(caption, element('strong', '', photo.title), element('span', '', photo.archiveDate.label));
  append(link, resilientImage(mediaUrl(photo.id, 'thumbnail'), '', index < 9), caption);
  return link;
}

function photoGrid(photos) {
  const grid = element('div', 'photo-grid');
  append(grid, photos.map((photo, index) => photoCard(photo, index)));
  return grid;
}

async function renderPhotos() {
  showLoading('photos', 'photos.title', 'photos.lead');
  try {
    const timeline = normalizeTimeline(await apiJson('/api/class-archive/timeline'));
    const page = element('div');
    append(page, pageHeader('photos.title', 'photos.lead', t('common.photosCount', { count: timeline.total })));
    if (timeline.total === 0) {
      page.append(emptyState('photos.emptyTitle', 'photos.emptyBody'));
    } else {
      for (const group of timeline.groups) {
        const section = element('section', 'timeline-section');
        const heading = element('div', 'section-heading');
        append(heading, element('h2', '', group.label), element('span', '', t('common.photosCount', { count: group.total })));
        append(section, heading, photoGrid(group.items));
        page.append(section);
      }
    }
    shell('photos', page);
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

async function renderViewer(id) {
  app.replaceChildren(loadingState());
  try {
    const [asset, timelinePayload] = await Promise.all([
      apiJson(`/api/assets/${id}`),
      apiJson('/api/class-archive/timeline'),
    ]);
    const timeline = normalizeTimeline(timelinePayload);
    const photos = timeline.groups.flatMap((group) => group.items);
    const index = photos.findIndex((photo) => photo.id === id);
    if (index < 0) throw new Error('safe_viewer_membership_invalid');
    const photo = photos[index];
    const title = businessLabel(asset?.originalFileName, 'accessibility.photo');

    const page = element('main', 'viewer-page');
    page.id = 'main-content';
    const stage = element('section', 'viewer-stage');
    const wrap = element('div', 'viewer-image-wrap');
    const image = resilientImage(mediaUrl(id, 'preview'), title, true);
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
    const infoToggle = viewerButton('accessibility.info');
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
    prefetchAdjacentPreviews(photos, index);

    const info = element('aside', 'viewer-info');
    info.dataset.open = 'false';
    const position = t('viewer.position', { current: index + 1, total: photos.length });
    const list = element('dl', 'info-list');
    append(
      list,
      infoRow('viewer.archiveDate', photo.archiveDate.label),
      infoRow('viewer.precision', photo.archiveDate.precision),
      infoRow('viewer.source', photo.archiveDate.source),
    );
    append(info, element('h1', '', title), element('p', 'viewer-position', position), list, element('p', 'viewer-security', t('viewer.security')));

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
    if (!person || !validId(person.id)) throw new Error('safe_person_invalid');
    return {
      id: person.id.toLowerCase(),
      name: safeText(person.name, t('people.unnamed')),
      count: Number.isInteger(person.photoCount) && person.photoCount > 0 ? person.photoCount : null,
    };
  });
}

function personCard(person) {
  const link = element('a', 'person-card');
  link.href = `/people/${person.id}`;
  const portrait = element('span', 'person-photo');
  portrait.append(resilientImage(`/api/people/${person.id}/thumbnail`, '', false));
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
    const people = normalizePeople(await apiJson('/api/people?size=500&withHidden=false'));
    const page = element('div');
    append(page, pageHeader('people.title', 'people.lead', t('common.peopleCount', { count: people.length })));
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

function normalizePerson(payload) {
  if (!payload || !validId(payload.id) || !Number.isInteger(payload.photo_count) || !Array.isArray(payload.items)
      || payload.photo_count !== payload.items.length) {
    throw new Error('safe_person_detail_invalid');
  }
  return {
    id: payload.id.toLowerCase(),
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
    portrait.append(resilientImage(`/api/people/${id}/thumbnail`, '', true));
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

function normalizeSearch(payload) {
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

async function search(query) {
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
    for (const photo of normalizeSearch(result.value)) {
      if (!combined.has(photo.id)) combined.set(photo.id, photo);
    }
  }
  return { photos: [...combined.values()], partial: fulfilled.length !== settled.length };
}

async function renderSearch() {
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
  const results = element('div');
  results.append(emptyState('search.initialTitle', 'search.initialBody'));
  append(page, form, status, results);
  shell('search', page);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const query = input.value.trim();
    if (!query) {
      input.focus();
      return;
    }
    submit.disabled = true;
    status.textContent = t('search.searching');
    results.replaceChildren(loadingState());
    try {
      const response = await search(query);
      status.textContent = response.partial
        ? t('search.partial')
        : t('search.results', { count: response.photos.length });
      if (response.photos.length === 0) results.replaceChildren(emptyState('search.noResultsTitle', 'search.noResultsBody'));
      else results.replaceChildren(photoGrid(response.photos));
    } catch {
      status.textContent = '';
      results.replaceChildren(errorState());
    } finally {
      submit.disabled = false;
    }
  });
}

function normalizeAlbums(payload) {
  if (!Array.isArray(payload)) throw new Error('safe_albums_invalid');
  return payload.map((album) => {
    if (!album || !Number.isInteger(album.assetCount) || album.assetCount < 0) throw new Error('safe_album_invalid');
    return { name: businessLabel(album.albumName, 'albums.title'), count: album.assetCount };
  });
}

async function renderAlbums() {
  showLoading('albums', 'albums.title', 'albums.lead');
  try {
    const albums = normalizeAlbums(await apiJson('/api/albums'));
    const page = element('div');
    append(page, pageHeader('albums.title', 'albums.lead'));
    if (albums.length === 0) page.append(emptyState('albums.emptyTitle', 'albums.emptyBody'));
    else {
      const grid = element('div', 'album-grid');
      for (const album of albums) {
        const card = element('article', 'album-card');
        append(card, element('h2', 'album-title', album.name), element('p', 'album-count', t('common.photosCount', { count: album.count })));
        grid.append(card);
      }
      page.append(grid);
    }
    shell('albums', page);
  } catch {
    const page = element('div');
    append(page, pageHeader('albums.title', 'albums.lead'), errorState());
    shell('albums', page);
  }
}

async function renderMemories() {
  showLoading('memories', 'memories.title', 'memories.lead');
  try {
    const memories = await apiJson('/api/memories');
    if (!Array.isArray(memories)) throw new Error('safe_memories_invalid');
    const page = element('div');
    append(page, pageHeader('memories.title', 'memories.lead'), emptyState('memories.emptyTitle', 'memories.emptyBody'));
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
    const user = await apiJson('/api/users/me');
    const role = safeText(user?.name, t('my.currentRole'));
    const page = element('div');
    append(page, pageHeader('my.title', 'my.lead'));
    const card = element('section', 'profile-card');
    append(card, element('p', '', t('my.currentRole')), element('span', 'role-badge', role), element('p', '', t('my.scopeNote')));
    const links = element('div', 'profile-links');
    const linkItems = [
      ['/class-archive-core/identity', 'my.identity'],
      ['/class-archive-core/home', 'my.gallery'],
      ['/class-archive-about', 'my.about'],
    ];
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
  if (path === '/photos') return { name: 'photos' };
  const photo = /^\/photos\/([0-9a-f-]{36})$/i.exec(path);
  if (photo && validId(photo[1])) return { name: 'viewer', id: photo[1].toLowerCase() };
  if (path === '/people') return { name: 'people' };
  const person = /^\/people\/([0-9a-f-]{36})$/i.exec(path);
  if (person && validId(person[1])) return { name: 'person', id: person[1].toLowerCase() };
  if (path === '/search') return { name: 'search' };
  if (path === '/albums') return { name: 'albums' };
  if (path === '/memories') return { name: 'memories' };
  if (path === '/my') return { name: 'my' };
  return { name: 'photos' };
}

async function start() {
  applyDocumentTranslations();
  const current = route();
  const handlers = {
    photos: () => renderPhotos(),
    viewer: () => renderViewer(current.id),
    people: () => renderPeople(),
    person: () => renderPerson(current.id),
    search: () => renderSearch(),
    albums: () => renderAlbums(),
    memories: () => renderMemories(),
    my: () => renderMy(),
  };
  await handlers[current.name]();
}

void start();
