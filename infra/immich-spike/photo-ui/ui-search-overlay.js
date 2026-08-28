import { t } from './i18n.js?v=__PHOTO_UI_ASSET_REV__';
import { append, element } from './ui-dom.js?v=__PHOTO_UI_ASSET_REV__';

let overlaySequence = 0;
export const SEARCH_SCOPE_KINDS = Object.freeze(['ALL', 'ALBUM', 'PERSON', 'MEMORY', 'COLLECTION']);

const SEARCH_SCOPE_KIND_SET = new Set(SEARCH_SCOPE_KINDS);
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const MEMORY_SCOPE_ID = /^memory-[a-f0-9]{56}$/;
const COLLECTION_SCOPE_ID = /^[A-Za-z0-9][A-Za-z0-9:_-]{0,95}$/;
const focusableSelector = [
  'a[href]:not([aria-disabled="true"])',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function safeScopeText(value) {
  return typeof value === 'string' && value.trim().length > 0 && value.trim().length <= 190
    ? value.trim() : null;
}

function normalizeScopeId(kind, value) {
  if (kind === 'ALBUM' || kind === 'PERSON') {
    return typeof value === 'string' && UUID_V4.test(value) ? value.toLowerCase() : null;
  }
  if (kind === 'MEMORY') {
    return typeof value === 'string' && MEMORY_SCOPE_ID.test(value) ? value : null;
  }
  if (kind === 'COLLECTION') {
    return typeof value === 'string' && COLLECTION_SCOPE_ID.test(value) ? value : null;
  }
  return null;
}

function normalizeScopeOptions(rawOptions, fallbackLabel = '') {
  const source = Array.isArray(rawOptions) ? rawOptions : [];
  const options = [];
  const seen = new Set();
  for (const raw of source) {
    const kind = typeof raw?.kind === 'string' ? raw.kind : '';
    const id = normalizeScopeId(kind, raw?.id);
    const key = kind === 'ALL' ? 'ALL'
      : (id !== null && raw?.key === `${kind}:${id}` ? raw.key : '');
    const label = safeScopeText(raw?.label);
    if (!SEARCH_SCOPE_KIND_SET.has(kind) || !key || !label || (kind !== 'ALL' && id === null) || seen.has(key)) continue;
    seen.add(key);
    options.push({
      kind,
      key,
      id,
      label,
      description: safeScopeText(raw?.description) ?? '',
      disabled: raw?.disabled === true,
    });
  }
  if (!options.some((option) => option.kind === 'ALL')) {
    options.unshift({
      kind: 'ALL',
      key: 'ALL',
      label: safeScopeText(fallbackLabel) ?? t('search.scopeAll'),
      description: '',
      disabled: false,
    });
  }
  return options.slice(0, SEARCH_SCOPE_KINDS.length);
}

function dialogFocusableElements(dialog) {
  return [...dialog.querySelectorAll(focusableSelector)]
    .filter((node) => node instanceof HTMLElement && !node.hidden && node.getClientRects().length > 0);
}

/**
 * Build the owned global-search surface without coupling it to transport,
 * policy, or routing.  The app owns every request and result projection; this
 * module owns only native-dialog semantics and the small stable DOM contract.
 */
export function openGlobalSearchOverlay({ scopeOptions = [], scopeLabel = '', onScopeChange, onClose }) {
  overlaySequence += 1;
  const returnFocus = document.activeElement;
  const dialog = element('dialog', 'global-search-dialog');
  dialog.dataset.searchOverlay = 'true';
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');

  const surface = element('section', 'global-search-surface');
  const heading = element('h1', 'sr-only', t('search.overlayTitle'));
  heading.id = `global-search-title-${overlaySequence}`;
  dialog.setAttribute('aria-labelledby', heading.id);

  const toolbar = element('div', 'global-search-toolbar');
  const form = element('form', 'global-search-form');
  form.role = 'search';
  const input = element('input', 'global-search-input');
  input.type = 'search';
  input.name = 'query';
  input.autocomplete = 'off';
  input.maxLength = 190;
  input.placeholder = t('search.placeholder');
  input.setAttribute('aria-label', t('search.label'));
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', `global-search-combobox-${overlaySequence}`);
  const submit = element('button', 'global-search-submit', t('search.submit'));
  submit.type = 'submit';
  append(form, input, submit);

  const actions = element('div', 'global-search-actions');
  const scopeLabelNode = element('label', 'sr-only', t('search.scopeLabel'));
  scopeLabelNode.htmlFor = `global-search-scope-${overlaySequence}`;
  const scope = element('select', 'global-search-scope');
  scope.id = scopeLabelNode.htmlFor;
  scope.name = 'scope';
  scope.dataset.scopeToggle = 'true';
  const close = element('button', 'icon-button global-search-close', t('common.close'));
  close.type = 'button';
  close.setAttribute('aria-label', t('search.close'));
  append(actions, scopeLabelNode, scope, close);
  append(toolbar, form, actions);

  const context = element('p', 'global-search-context');
  context.id = `global-search-context-${overlaySequence}`;
  context.hidden = true;

  const suggestionHost = element('div', 'global-search-suggestions');
  suggestionHost.id = `global-search-suggestions-${overlaySequence}`;
  suggestionHost.setAttribute('aria-label', t('search.liveSuggestions'));
  const comboboxList = element('ul', 'global-search-combobox-list');
  comboboxList.id = `global-search-combobox-${overlaySequence}`;
  comboboxList.setAttribute('role', 'listbox');
  comboboxList.hidden = true;
  const status = element('p', 'global-search-status');
  status.setAttribute('aria-live', 'polite');
  status.hidden = true;
  const results = element('div', 'global-search-results');
  results.hidden = true;

  append(surface, heading, toolbar, context, comboboxList, suggestionHost, status, results);
  dialog.append(surface);

  const closeOverlay = () => dialog.close();
  close.addEventListener('click', closeOverlay);
  dialog.addEventListener('cancel', (event) => {
    // Own Escape explicitly so every close path reaches the same focus/history
    // cleanup callback. Native <dialog> still provides the modal backdrop.
    event.preventDefault();
    closeOverlay();
  });
  dialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab') return;
    const focusable = dialogFocusableElements(dialog);
    if (focusable.length === 0) {
      event.preventDefault();
      input.focus({ preventScroll: true });
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeOverlay();
  });
  dialog.addEventListener('close', () => {
    dialog.remove();
    if (returnFocus instanceof HTMLElement && returnFocus.isConnected) returnFocus.focus({ preventScroll: true });
    onClose?.();
  }, { once: true });
  document.body.append(dialog);
  dialog.showModal();
  input.focus({ preventScroll: true });

  let normalizedScopeOptions = normalizeScopeOptions(scopeOptions, scopeLabel);
  const paintScopeOptions = (requestedKey = '') => {
    const selectedKey = normalizedScopeOptions.some((option) => option.key === requestedKey && !option.disabled)
      ? requestedKey
      : (normalizedScopeOptions.find((option) => !option.disabled)?.key ?? 'ALL');
    scope.replaceChildren();
    for (const option of normalizedScopeOptions) {
      const node = element('option', '', option.label);
      node.value = option.key;
      node.disabled = option.disabled;
      node.selected = option.key === selectedKey;
      scope.append(node);
    }
    const current = normalizedScopeOptions.find((option) => option.key === selectedKey) ?? null;
    scope.hidden = normalizedScopeOptions.filter((option) => !option.disabled).length <= 1;
    scope.dataset.scopeKind = current?.kind ?? 'ALL';
    context.hidden = !current?.description;
    context.textContent = current?.description ?? '';
    if (context.hidden) {
      input.removeAttribute('aria-describedby');
      scope.removeAttribute('aria-describedby');
    } else {
      input.setAttribute('aria-describedby', context.id);
      scope.setAttribute('aria-describedby', context.id);
    }
    return current;
  };
  paintScopeOptions('ALL');
  scope.addEventListener('change', () => {
    const selected = paintScopeOptions(scope.value);
    if (selected) onScopeChange?.(selected);
  });

  return {
    dialog,
    form,
    input,
    submit,
    scope,
    context,
    comboboxList,
    suggestionHost,
    status,
    results,
    setScopeOptions(nextOptions, selectedKey = 'ALL') {
      normalizedScopeOptions = normalizeScopeOptions(nextOptions);
      return paintScopeOptions(selectedKey);
    },
    close: closeOverlay,
  };
}
