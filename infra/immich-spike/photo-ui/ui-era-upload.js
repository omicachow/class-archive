import { t } from './i18n.js?v=__PHOTO_UI_ASSET_REV__';
import { append, dialogShell, element, option } from './ui-dom.js?v=__PHOTO_UI_ASSET_REV__';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{12}$/i;
const ERAS = new Set(['HERITAGE', 'LIVING']);
const DIRECT_MEMBER_ROLES = new Set(['CLASSMATE', 'TEACHER']);

function eraLabel(era) {
  return t(era === 'HERITAGE' ? 'business.heritage' : 'business.living');
}

function directMemberRoleNote(role) {
  if (!DIRECT_MEMBER_ROLES.has(role)) return null;
  return t(role === 'CLASSMATE' ? 'upload.roleClassmate' : 'upload.roleTeacher');
}

function safeAlbumChoices(rawAlbums) {
  if (!Array.isArray(rawAlbums) || rawAlbums.length > 240) return [];
  const seen = new Set();
  const choices = [];
  for (const item of rawAlbums) {
    const id = typeof item?.id === 'string' && UUID_V4.test(item.id) ? item.id.toLowerCase() : null;
    const label = typeof item?.label === 'string' ? item.label.trim() : '';
    const eras = Array.isArray(item?.eras) ? item.eras.filter((era) => ERAS.has(era)) : [];
    if (!id || !label || label.length > 190 || eras.length === 0 || seen.has(id)) continue;
    seen.add(id);
    choices.push({ id, label, eras: [...new Set(eras)] });
  }
  return choices;
}

function eraChoice(era, selectedEra, onChoose) {
  const input = element('input');
  input.type = 'radio';
  input.name = 'era';
  input.value = era;
  input.required = true;
  input.checked = selectedEra === era;
  input.addEventListener('change', () => {
    if (input.checked) onChoose(era);
  });
  const copy = element('span', 'era-upload-choice-copy');
  append(copy,
    element('strong', '', t(era === 'HERITAGE' ? 'upload.heritageTitle' : 'upload.livingTitle')),
    element('span', '', t(era === 'HERITAGE' ? 'upload.heritageLead' : 'upload.livingLead')),
  );
  const choice = element('label', 'era-upload-choice');
  choice.dataset.era = era;
  append(choice, input, copy);
  return { choice, input };
}

/**
 * Build only the small, owned dialog surface. The caller owns the fixed BFF
 * route, CSRF header, policy state, and transfer; this helper never fetches,
 * never chooses an Era implicitly, and never constructs a server URL.
 *
 * @param {{trigger?:HTMLElement|null,actorRole?:'CLASSMATE'|'TEACHER',albums:Array<{id:string,label:string,eras:string[]}>,initialAlbumId?:string|null,onSubmit:(input:{era:string,albumId:string,file:File,submit:HTMLButtonElement})=>Promise<void>}} options
 */
export function openEraUploadDialog(options) {
  if (!options || typeof options.onSubmit !== 'function') throw new Error('safe_era_upload_callback_invalid');
  const albums = safeAlbumChoices(options.albums);
  const initialAlbumId = typeof options.initialAlbumId === 'string' && UUID_V4.test(options.initialAlbumId)
    ? options.initialAlbumId.toLowerCase() : null;
  const { dialog, surface } = dialogShell('upload.title', 'upload.lead');
  dialog.classList.add('era-upload-dialog');

  const form = element('form', 'dialog-form era-upload-form');
  form.noValidate = true;
  const eraGroup = element('fieldset', 'era-upload-group');
  eraGroup.append(element('legend', 'field-label', t('upload.eraRequired')));
  const eraChoices = element('div', 'era-upload-choice-grid');
  eraGroup.append(eraChoices);
  const roleNote = directMemberRoleNote(options.actorRole);
  const eraSummary = element('p', 'era-upload-summary');
  eraSummary.hidden = true;

  const albumField = element('label', 'field');
  const albumLabel = element('span', 'field-label', t('upload.albumLabel'));
  const album = element('select', 'select-field');
  album.name = 'album';
  album.required = true;
  const albumHint = element('span', 'field-hint', t('upload.albumHint'));
  append(albumField, albumLabel, album, albumHint);

  const fileField = element('label', 'field');
  const fileLabel = element('span', 'field-label', t('upload.fileLabel'));
  const file = element('input', 'file-field');
  file.type = 'file';
  file.name = 'photo';
  file.accept = 'image/jpeg,image/png,image/webp';
  file.required = true;
  const fileHint = element('span', 'field-hint', t('upload.fileHint'));
  append(fileField, fileLabel, file, fileHint);

  const status = element('p', 'era-upload-status');
  status.setAttribute('aria-live', 'polite');
  status.hidden = true;
  const actions = element('div', 'dialog-actions');
  const cancel = element('button', 'ghost-button', t('common.cancel'));
  cancel.type = 'button';
  cancel.addEventListener('click', () => dialog.close());
  const submit = element('button', 'primary-button', t('upload.submit'));
  submit.type = 'submit';
  append(actions, cancel, submit);
  append(form,
    roleNote ? element('p', 'era-upload-role-note', roleNote) : null,
    eraGroup,
    eraSummary,
    albumField,
    fileField,
    status,
    actions,
  );
  surface.append(form);

  let selectedEra = null;
  const setStatus = (message = '') => {
    status.hidden = message === '';
    status.textContent = message;
  };
  const paintAlbums = () => {
    album.replaceChildren(option('', t(selectedEra ? 'upload.albumChoose' : 'upload.eraChoose')));
    const visible = selectedEra ? albums.filter((entry) => entry.eras.includes(selectedEra)) : [];
    for (const entry of visible) album.append(option(entry.id, entry.label, entry.id === initialAlbumId));
    album.disabled = selectedEra === null || visible.length === 0;
    if (selectedEra && visible.length === 0) {
      setStatus(t('upload.noAlbumForEra'));
    } else {
      setStatus('');
    }
  };
  const chooseEra = (era) => {
    if (!ERAS.has(era)) return;
    selectedEra = era;
    for (const choice of eraChoices.querySelectorAll('.era-upload-choice')) {
      choice.dataset.selected = String(choice.dataset.era === era);
    }
    eraSummary.hidden = false;
    eraSummary.textContent = t('upload.eraSummary', { era: eraLabel(era) });
    paintAlbums();
  };
  const heritage = eraChoice('HERITAGE', null, chooseEra);
  const living = eraChoice('LIVING', null, chooseEra);
  append(eraChoices, heritage.choice, living.choice);
  paintAlbums();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const selectedFile = file.files?.[0] ?? null;
    if (!selectedEra) {
      setStatus(t('upload.eraChoose'));
      heritage.input.focus();
      return;
    }
    if (!UUID_V4.test(album.value)) {
      setStatus(t('upload.albumChoose'));
      album.focus();
      return;
    }
    if (!(selectedFile instanceof File)) {
      setStatus(t('upload.fileChoose'));
      file.focus();
      return;
    }
    submit.disabled = true;
    cancel.disabled = true;
    setStatus(t('upload.submitting'));
    try {
      await options.onSubmit({
        era: selectedEra,
        albumId: album.value.toLowerCase(),
        file: selectedFile,
        submit,
      });
      dialog.close();
    } catch {
      submit.disabled = false;
      cancel.disabled = false;
      setStatus(t('upload.failed'));
    }
  });
  return { dialog, form, file, album, eraSummary };
}
