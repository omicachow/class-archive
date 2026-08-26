import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');

const [server, app, css, i18n] = await Promise.all([
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

check(server.includes("url.pathname === '/api/class-archive/search/suggestions'"),
  'BFF must expose one explicit Class Archive suggestions route');
check(server.includes("exactQuery(url, new Set(['q', 'albumId']))")
  && server.includes("value.length > 190 || value.includes('\\0')")
  && server.includes('class_archive_web_compat_search_suggestions_query_invalid'),
  'BFF suggestions must bound and reject unsafe query input');
check(server.includes('class_archive_web_compat_search_suggestions_album_invalid')
  && server.includes('!UUID_V4.test(albumId)')
  && server.includes('`/api/search/suggestions${suffix}`'),
  'BFF suggestions must accept only an opaque UUID album scope and a fixed upstream path');

check(app.includes('const SEARCH_SUGGESTION_SECTIONS = Object.freeze([')
  && app.includes("{ key: 'archiveTime', resultType: 'dates'")
  && app.includes('function normalizeSearchSuggestions(payload)'),
  'UI must normalize the four policy-filtered suggestion groups explicitly');
check(app.includes('source.items.length > 24')
  && app.includes('source.total < source.items.length')
  && app.includes("throw new Error('safe_search_suggestions_invalid')"),
  'malformed or unbounded suggestion data must fail closed in the UI');
check(app.includes("apiJson(`/api/class-archive/search/suggestions${suffix}`")
  && app.includes("cache: 'no-store', signal"),
  'UI suggestions must use the bounded BFF endpoint without persisting interactive search metadata');
check(app.includes("input.addEventListener('input'")
  && app.includes('new AbortController()')
  && app.includes('suggestionGeneration'),
  'typing must use debounced, cancellable suggestion requests rather than stale result repainting');
check(app.includes("item.href ? element('a', 'search-live-suggestion')")
  && app.includes("node.addEventListener('click', () => onQuery(item.label))"),
  'person and album suggestions must navigate directly while archive terms execute a bounded search');
check(app.includes("const suggestionHost = element('div', 'search-live-suggestions-host')")
  && app.includes('suggestionHost.hidden = true')
  && app.includes('clearSearchSuggestions();'),
  'empty search must keep suggestions out of the initial page and clear them before submitted results');

check(css.includes('.search-live-suggestions { display: grid;')
  && css.includes('.search-live-suggestion { min-height: 38px;')
  && !css.includes('.search-live-suggestions { min-height:'),
  'live suggestions must stay lightweight instead of becoming a large empty-state card');
check(i18n.includes("'search.liveSuggestions': '搜索建议'"),
  'member-facing suggestion copy must remain centralized in i18n');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-search-suggestions-contract', assertions, result: 'PASS' })}\n`);
