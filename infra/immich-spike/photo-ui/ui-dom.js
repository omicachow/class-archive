import { t } from './i18n.js?v=__PHOTO_UI_ASSET_REV__';

let dialogSequence = 0;

export function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

export function append(parent, ...children) {
  for (const child of children.flat()) {
    if (child !== null && child !== undefined) parent.append(child);
  }
  return parent;
}

export function emptyState(titleKey, bodyKey) {
  const state = element('section', 'empty-state');
  append(state, element('h2', '', t(titleKey)), element('p', '', t(bodyKey)));
  return state;
}

export function loadingState() {
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

export function errorState() {
  const state = element('section', 'error-state');
  const button = element('button', 'primary-button', t('common.retry'));
  button.type = 'button';
  button.addEventListener('click', () => location.reload());
  append(state, element('h2', '', t('common.safeErrorTitle')), element('p', '', t('common.safeErrorBody')), button);
  return state;
}

export function toast(message, kind = 'success') {
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

export function dialogShell(titleKey, leadKey = '') {
  dialogSequence += 1;
  const returnFocus = document.activeElement;
  const dialog = element('dialog', 'app-dialog');
  const surface = element('div', 'dialog-surface');
  const header = element('header', 'dialog-header');
  const copy = element('div');
  const title = element('h2', '', t(titleKey));
  title.id = `dialog-title-${dialogSequence}`;
  const lead = leadKey ? element('p', '', t(leadKey)) : null;
  if (lead) lead.id = `dialog-lead-${dialogSequence}`;
  append(copy, title, lead);
  // Native showModal supplies the interaction boundary; state it explicitly
  // as well so assistive technology gets a stable modal-dialog contract.
  dialog.setAttribute('role', 'dialog');
  dialog.setAttribute('aria-modal', 'true');
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

export function labeledControl(labelKey, control, hint = '') {
  const label = element('label', 'field');
  append(label, element('span', 'field-label', t(labelKey)), control, hint ? element('span', 'field-hint', hint) : null);
  return label;
}

export function labeledGroup(labelKey, content) {
  const group = element('fieldset', 'field fieldset-reset');
  append(group, element('legend', 'field-label', t(labelKey)), content);
  return group;
}

export function option(value, label, selected = false) {
  const node = element('option', '', label);
  node.value = value;
  node.selected = selected;
  return node;
}
