import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..', '..');
const read = (path) => readFile(resolve(root, path), 'utf8');

const [contracts, gateway, controller, bff] = await Promise.all([
  read('plugins/ClassIdentity/src/Gateway/Contracts.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayService.php'),
  read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php'),
  read('infra/immich-spike/web-compat/server.mjs'),
]);

let assertions = 0;
function check(condition, message) {
  assert.ok(condition, message);
  assertions += 1;
}

function methodBody(source, declaration, nextDeclaration) {
  const start = source.indexOf(declaration);
  const end = source.indexOf(nextDeclaration, start + declaration.length);
  assert.notEqual(start, -1, `missing ${declaration}`);
  assert.notEqual(end, -1, `missing boundary ${nextDeclaration}`);
  return source.slice(start, end);
}

const grouped = methodBody(gateway, 'public function groupedSearch(', '    /**\n     * Structured archive results');
const suggestionsEntry = methodBody(gateway, 'public function searchSuggestions(string $query = \'\', ?string $albumId = null): array', '    /** @return array{active:bool,total:int,items:list<array<string,mixed>>,item:?array<string,mixed>} */');
const suggestions = methodBody(gateway, 'private function persistentSearchSuggestions(', '    private function searchCursorSigningKey(');
const collectionsHome = methodBody(gateway, 'public function collectionsHome(): array', '    /** @return array<string,mixed> */\n    public function collectionsState');

check(contracts.includes('final class SearchContext')
  && contracts.includes("public const ALL = 'ALL';")
  && contracts.includes("public const ALBUM = 'ALBUM';")
  && contracts.includes("public const PERSON = 'PERSON';")
  && contracts.includes("public const MEMORY = 'MEMORY';")
  && contracts.includes("public const COLLECTION = 'COLLECTION';"),
'Gateway defines the complete typed server-side search-context enum');
check(contracts.includes('ClassArchivePerson::idToBinary($target)')
  && contracts.includes("preg_match('/\\Amemory-[a-f0-9]{56}\\z/D', $target)")
  && contracts.includes("preg_match('/\\A[A-Za-z0-9][A-Za-z0-9:_-]{0,95}\\z/D', $target)"),
'person, memory, and collection targets are opaque canonical values rather than backend ids');
check(contracts.includes('public function cursorSubject(): ?string')
  && contracts.includes("new self($role, 'u:' . $piwigoUserId)"),
'runtime principals supply an account-bound cursor namespace');

check(grouped.indexOf('$visible = $this->visiblePhotos();') >= 0
  && grouped.indexOf('$contextAllowed = $this->resolveSearchContextPhotoIds') > grouped.indexOf('$visible = $this->visiblePhotos();')
  && grouped.includes('$this->peopleForVisiblePhotoSet($allowed)')
  && grouped.includes('$this->albumsForVisiblePhotoSet($allowed)')
  && grouped.includes('$this->smartSearchForVisiblePhotoSet('),
'every grouped-search family starts from policy-filtered visible photos and then intersects its context');
check(grouped.includes('$this->requireAllPublishedCollectionSnapshotsCurrent($scope, $before);'),
'grouped search requires a current collection bundle before emitting any aggregate');
check(gateway.includes('SearchContext::MEMORY => $this->snapshotSearchContextPhotoIds(')
  && gateway.includes('SearchContext::COLLECTION => $this->snapshotSearchContextPhotoIds(')
  && gateway.includes('private function personScopedPhotoIds(')
  && gateway.includes("throw new \\RuntimeException('class_archive_gateway_search_context_not_found');"),
'non-ALL contexts resolve only through role-scoped album/person/snapshot membership and fail closed');
check(gateway.includes('private function albumsForVisiblePhotoSet(')
  && gateway.includes("$copy['total'] = count($members);")
  && gateway.includes("$copy['coverPhotoId'] = $cover;")
  && gateway.includes("$copy['photo_count'] = count($visibleMembers);")
  && gateway.includes("$copy['cover_photo_id'] = $cover;"),
'album and people counts/covers are recomputed from the context intersection');

check(grouped.includes('decodeGroupedSearchCursor(')
  && grouped.includes('encodeGroupedSearchCursor(')
  && gateway.includes('private static function groupedSearchCursorMaterial(')
  && gateway.includes('$context->binding()')
  && gateway.includes('$cursorSubject')
  && gateway.includes('$revision')
  && gateway.includes('hash_equals($expected, substr($decoded, 4))'),
'next_cursor is HMAC-bound to query context principal scope and projection revision');
check(gateway.includes('private const SEARCH_PAGE_MAX = 120;')
  && grouped.includes("throw new \\InvalidArgumentException('class_archive_gateway_search_limit_invalid');")
  && grouped.includes("preg_match('/\\A[a-f0-9]{64}\\z/D', $before)"),
'grouped search enforces bounded pages and rejects an invalid presentation binding');

check(suggestions.includes('CollectionSnapshotService::KIND_SEARCH_SUGGESTION')
  && suggestions.includes('activeSnapshot(')
  && suggestions.includes('recheckSearchSuggestionSnapshotItem')
  && suggestions.includes('publishedCollectionSnapshotBundle($scope, $before, true)')
  && suggestions.includes("$result['snapshot_state'] = $bundle['mode'];")
  && suggestions.includes("'context' => ['type' => SearchContext::PERSON")
  && suggestions.includes("'context' => ['type' => SearchContext::ALBUM")
  && !suggestions.includes('$this->visiblePhotos()')
  && !suggestions.includes('$this->visibleAlbumItems()')
  && !suggestions.includes('$this->people()'),
'search suggestions read and ACL-recheck only the active durable snapshot, may retain one coherent fallback bundle, and never scan the live library');
check(gateway.includes('private function recheckSearchSuggestionSnapshotItem(')
  && gateway.includes('$this->personScopedPhotoIds(strtolower($personId));')
  && gateway.includes('$this->albumScopedPhotoIds(strtolower($albumId)) === []'),
'metadata-only suggestions recheck their typed PERSON/ALBUM target against the current role-scoped projection');
check(gateway.includes('return $this->persistentSearchSuggestions($query, $albumId);'),
'legacy searchSuggestions(q, albumId) keeps its route and input shape while using the snapshot read path');
check(suggestionsEntry.includes('return $this->persistentSearchSuggestions($query, $albumId);')
  && !suggestionsEntry.includes('$this->visiblePhotos()')
  && !suggestionsEntry.includes('$this->visibleAlbumItems()')
  && !suggestionsEntry.includes('$this->people()'),
'the public suggestions route has no alternate live aggregation fallback');
check(collectionsHome.includes('publishedCollectionSnapshotBundle($scope, $before, true)')
  && collectionsHome.includes("$snapshot['snapshotState'] = $bundle['mode'];"),
'Collections Home rejects mixed or partial bundles but may use one ACL-rechecked retained active bundle during a rebuild');

check(controller.includes("if ($segments === ['search', 'grouped'])")
  && controller.includes("['q', 'contextType', 'contextId', 'albumId', 'cursor', 'limit']")
  && controller.includes('SearchContext::ALL && $hasContextId')
  && controller.includes('SearchContext::legacyAlbum($albumId)')
  && controller.includes('SearchContext::fromRequest(')
  && controller.includes('$gateway->groupedSearch($query, $context, $cursor, $limit)'),
'Gateway HTTP boundary accepts only the typed grouped-search parameters and preserves legacy albumId');
check(gateway.includes("'/api/search/grouped' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED']")
  && controller.includes("if ($segments === ['search', 'hybrid'])")
  && controller.includes("if ($segments === ['search', 'suggestions'])"),
'new grouped route is declared without removing old hybrid or suggestion routes');

check(bff.includes("url.pathname === '/api/class-archive/search/grouped'")
  && bff.includes("exactQuery(url, new Set(['q', 'contextType', 'contextId', 'albumId', 'cursor', 'limit']))")
  && bff.includes('groupedSearchContextTypes')
  && bff.includes('groupedSearchMemoryContextPattern')
  && bff.includes('groupedSearchCollectionContextPattern')
  && bff.includes("contextType === 'ALL' && hasContextId")
  && bff.includes('class_archive_web_compat_grouped_search_context_invalid'),
'BFF exposes one fixed grouped-search path and rejects malformed or conflicting contexts before relay');
check(bff.includes('!timelineCursorPattern.test(cursor)')
  && bff.includes('Number(limit) > groupedSearchPageMaximum')
  && bff.includes('`/api/search/grouped?${params.toString()}`')
  && bff.includes("url.pathname === '/api/class-archive/search/hybrid'"),
'BFF forwards only bounded cursor/limit fields and retains the separate legacy hybrid route');

process.stdout.write(`${JSON.stringify({ suite: 'phase3-search-context-contract', assertions, result: 'PASS', evidence: 'STATIC' })}\n`);
