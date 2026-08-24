<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\Access;
use ClassIdentity\AlbumService;
use ClassIdentity\CanonicalPhotoService;
use ClassIdentity\ClassArchivePerson;
use ClassIdentity\PersonCurationService;
use ClassIdentity\Repository;
use ClassIdentity\SpotlightService;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Class Archive Gateway application boundary.
 *
 * It is shared by the same-origin, read-only Piwigo HTTP controller and by
 * the contract tests. Public projections remain metadata-only; the controller
 * may use an internal visible candidate to dispatch a canonical UUID through
 * the existing MediaGuard/X-Accel delivery path. This is intentionally not an
 * Immich API adapter and does not create an Immich runtime claim.
 */
final class GatewayService
{
    /**
     * Request-scoped, policy-filtered canonical projection. Keeping the raw
     * approved ids alongside their canonical ids lets AI enrichment preserve
     * an alias' metadata without ever returning the alias to the browser.
     *
     * @var array{photos:list<GatewayPhotoCandidate>,raw_photos:list<GatewayPhotoCandidate>,raw_ids:list<string>,id_map:array<string,string>}|null
     */
    private ?array $visiblePhotoResolution = null;

    public function __construct(
        private readonly IdentityAdapter $identity,
        private readonly PiwigoAdapter $piwigo,
        private readonly ImmichAdapter $immich,
        private readonly GatewayPolicy $policy = new GatewayPolicy(),
        private readonly ?AlbumService $albumDomain = null,
        private readonly ?PersonCurationService $personCuration = null,
        private readonly ?SpotlightService $spotlightDomain = null,
        private readonly ?CanonicalPhotoService $canonicalDomain = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return ['role' => $this->principal()->role()];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function photos(): array
    {
        $resolution = $this->visiblePhotoResolution();
        $visible = $resolution['photos'];
        return [
            'total' => count($visible),
            'items' => array_map(static fn (GatewayPhotoCandidate $photo): array => $photo->publicProjection(), $visible),
        ];
    }

    /** @return array<string, mixed>|null */
    public function photo(string $classPhotoId): ?array
    {
        foreach ($this->visiblePhotos() as $photo) {
            if (hash_equals($photo->id(), $classPhotoId)) {
                return $photo->publicProjection();
            }
        }
        // Never distinguish hidden from unknown canonical ids.
        return null;
    }

    /**
     * Resolve a canonical id only after the same policy filter that feeds all
     * public aggregates. The internal candidate retains the private Piwigo
     * mapping for the MediaGuard dispatcher, but callers must never serialize
     * it or use it as a browser identity.
     */
    public function mediaCandidate(string $classPhotoId): ?GatewayPhotoCandidate
    {
        foreach ($this->visiblePhotos() as $photo) {
            if (hash_equals($photo->id(), $classPhotoId)) {
                return $photo;
            }
        }
        // Never distinguish a hidden canonical id from an unknown one.
        return null;
    }

    /** @return array{total:int,groups:list<array<string,mixed>>} */
    public function timeline(): array
    {
        $visible = $this->visiblePhotos();
        $groups = [];
        foreach ($visible as $photo) {
            $projection = $photo->publicProjection();
            $bucket = $photo->timelineBucket();
            $key = $bucket['key'];
            $groups[$key]['key'] = $key;
            $groups[$key]['label'] = $bucket['label'];
            $groups[$key]['kind'] = $bucket['kind'];
            $groups[$key]['items'][] = $projection;
        }
        uasort($groups, static function (array $left, array $right): int {
            $leftUnknown = ($left['kind'] ?? '') === 'UNKNOWN';
            $rightUnknown = ($right['kind'] ?? '') === 'UNKNOWN';
            if ($leftUnknown !== $rightUnknown) {
                return $leftUnknown ? 1 : -1;
            }
            return strcmp((string) ($right['key'] ?? ''), (string) ($left['key'] ?? ''));
        });
        foreach ($groups as &$group) {
            $group['total'] = count($group['items']);
        }
        unset($group);

        return ['total' => count($visible), 'groups' => array_values($groups)];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function albums(): array
    {
        if ($this->albumDomain !== null) {
            $items = $this->visibleAlbumItems();
            return ['total' => count($items), 'items' => $items];
        }
        $albums = [];
        foreach ($this->visiblePhotos() as $photo) {
            foreach ($photo->albumLabels() as $label) {
                $albums[$label]['name'] = $label;
                $albums[$label]['photo_ids'][] = $photo->id();
                $albums[$label]['cover_photo_id'] ??= $photo->id();
            }
        }
        ksort($albums, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($albums as &$album) {
            $album['total'] = count($album['photo_ids']);
            unset($album['photo_ids']);
        }
        unset($album);

        return ['total' => count($albums), 'items' => array_values($albums)];
    }

    /** @return array<string,mixed>|null */
    public function album(string $classAlbumId): ?array
    {
        \ClassIdentity\DomainSupport::idToBinary($classAlbumId);
        foreach ($this->visibleAlbumItems(true) as $album) {
            if (isset($album['id']) && is_string($album['id']) && hash_equals($album['id'], strtolower($classAlbumId))) {
                return $album;
            }
        }
        // Hidden and unknown albums deliberately share the same result.
        return null;
    }

    /**
     * Structured archive results remain usable when semantic search is down.
     * Every section is formed from the already policy-filtered photo set, so
     * neither counts nor covers can reveal a hidden Era.
     *
     * @return array<string,mixed>
     */
    public function hybridSearch(string $query): array
    {
        $query = $this->normalizeQuery($query);
        $visible = $this->visiblePhotos();
        $matchingIds = $this->matchingCanonicalPhotoIds($query);
        $photos = [];
        $events = [];
        $timeBuckets = [];
        foreach ($visible as $photo) {
            if (isset($matchingIds[$photo->id()])) {
                $photos[] = $photo->publicProjection();
            }
            $projection = $photo->publicProjection();
            $event = $projection['event_label'] ?? null;
            if (is_string($event) && $event !== '' && self::contains($event, $query)) {
                $events[$event]['name'] = $event;
                $events[$event]['photoCount'] = (int) ($events[$event]['photoCount'] ?? 0) + 1;
                $events[$event]['coverPhotoId'] ??= $photo->id();
            }
            $bucket = $photo->timelineBucket();
            if (self::contains((string) ($bucket['label'] ?? ''), $query)) {
                $key = (string) ($bucket['key'] ?? 'unknown');
                $timeBuckets[$key]['label'] = (string) ($bucket['label'] ?? '日期未知');
                $timeBuckets[$key]['kind'] = (string) ($bucket['kind'] ?? 'UNKNOWN');
                $timeBuckets[$key]['photoCount'] = (int) ($timeBuckets[$key]['photoCount'] ?? 0) + 1;
                $timeBuckets[$key]['coverPhotoId'] ??= $photo->id();
            }
        }
        $albumItems = [];
        foreach ($this->visibleAlbumItems() as $album) {
            $haystack = implode("\n", array_filter([
                $album['name'] ?? null,
                $album['description'] ?? null,
                $album['eventLabel'] ?? null,
                $album['dateLabel'] ?? null,
            ], 'is_string'));
            if (self::contains($haystack, $query)) {
                $albumItems[] = $album;
            }
        }
        $peopleItems = [];
        try {
            foreach (($this->people()['items'] ?? []) as $person) {
                if (is_array($person) && self::contains((string) ($person['label'] ?? ''), $query)) {
                    $peopleItems[] = $person;
                }
            }
        } catch (\RuntimeException) {
            // People enrichment is optional. A failure never widens another
            // section or turns the exact archive search into a 503.
        }

        $smart = ['available' => false, 'total' => 0, 'items' => []];
        try {
            $smart = $this->smartSearch(self::semanticQuery($query));
        } catch (\Throwable) {
            // Explicit partial degradation: no fallback to a whole library.
        }
        return [
            'query' => $query,
            // Structured archive lookup remains authoritative when the
            // optional Immich semantic adapter is unavailable.  Expose that
            // degradation explicitly so clients never present a safe empty
            // smart result as a complete search result.
            'partial' => ($smart['available'] ?? false) !== true,
            'people' => ['total' => count($peopleItems), 'items' => $peopleItems],
            'albums' => ['total' => count($albumItems), 'items' => $albumItems],
            'events' => ['total' => count($events), 'items' => array_values($events)],
            'archiveTime' => ['total' => count($timeBuckets), 'items' => array_values($timeBuckets)],
            'photos' => ['total' => count($photos), 'items' => $photos],
            'smart' => $smart,
        ];
    }

    /** @return array{active:bool,item:?array<string,mixed>} */
    public function spotlight(): array
    {
        if ($this->spotlightDomain === null || $this->albumDomain === null) {
            return ['active' => false, 'item' => null];
        }
        $albums = $this->visibleAlbumItems();
        $byId = [];
        foreach ($albums as $album) {
            $byId[(string) $album['id']] = $album;
        }
        $records = $this->spotlightDomain->activeForUser($this->currentUserId(), array_keys($byId));
        foreach ($records as $record) {
            $albumId = (string) ($record['class_album_id'] ?? '');
            if (!isset($byId[$albumId])) {
                continue;
            }
            $album = $byId[$albumId];
            return ['active' => true, 'item' => [
                'id' => (string) $record['spotlight_id'],
                'albumId' => $albumId,
                'albumName' => (string) $album['name'],
                'coverPhotoId' => $album['coverPhotoId'],
                'description' => $album['description'],
                'publisherLabel' => '班级成员',
                'expiresAt' => (string) $record['expires_at'],
            ]];
        }
        return ['active' => false, 'item' => null];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function managedPeople(): array
    {
        if ($this->personCuration === null) {
            throw new \RuntimeException('class_archive_person_curation_unavailable');
        }
        $userId = $this->currentUserId();
        $visible = $this->visiblePhotos();
        $allowedIds = array_map(static fn(GatewayPhotoCandidate $photo): string => $photo->id(), $visible);
        $visibleById = [];
        foreach ($visible as $photo) {
            $visibleById[$photo->id()] = $photo->publicProjection();
        }
        $clusters = $this->rawPersonClusters();
        $projected = $this->personCuration->projectForVisiblePhotos($allowedIds, $clusters, true, $userId);
        $state = $this->personCuration->listProjectionState($userId);
        $stateById = [];
        $personNames = [];
        foreach (($state['persons'] ?? []) as $person) {
            if (is_array($person) && is_string($person['class_person_id'] ?? null)) {
                $stateById[$person['class_person_id']] = $person;
                $personNames[$person['class_person_id']] = is_string($person['display_name'] ?? null)
                    ? (string) $person['display_name'] : '未命名人物';
            }
        }
        $activeMergeSources = [];
        $mergeItems = [];
        foreach (($state['merges'] ?? []) as $merge) {
            if (!is_array($merge) || ($merge['state'] ?? null) !== 'ACTIVE') {
                continue;
            }
            $source = (string) ($merge['source_class_person_id'] ?? '');
            $target = (string) ($merge['target_class_person_id'] ?? '');
            \ClassIdentity\DomainSupport::idToBinary($source);
            \ClassIdentity\DomainSupport::idToBinary($target);
            $activeMergeSources[$source] = true;
            $mergeItems[] = [
                'id' => (string) $merge['merge_id'],
                'sourcePersonId' => $source,
                'sourceName' => $personNames[$source] ?? '未命名人物',
                'targetPersonId' => $target,
                'targetName' => $personNames[$target] ?? '未命名人物',
                'createdAt' => (string) ($merge['created_at'] ?? ''),
            ];
        }
        $identityNames = $this->identityNames();
        $items = [];
        foreach ($projected as $person) {
            $id = (string) $person['class_person_id'];
            $overlay = $stateById[$id] ?? [];
            $identityId = isset($overlay['classmate_identity_id']) ? (int) $overlay['classmate_identity_id'] : null;
            $personPhotos = [];
            foreach (($person['class_photo_ids'] ?? []) as $photoId) {
                if (!is_string($photoId) || !isset($visibleById[$photoId])) {
                    throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                }
                $personPhotos[] = $visibleById[$photoId];
            }
            $items[] = [
                'id' => $id,
                'displayName' => $person['display_name'] ?? null,
                'coverPhotoId' => $person['cover_class_photo_id'] ?? null,
                'classmateIdentityId' => $identityId,
                'classmateIdentityName' => $identityId !== null ? ($identityNames[$identityId] ?? null) : null,
                'hidden' => ($person['visibility'] ?? 'VISIBLE') === 'HIDDEN',
                'photoCount' => (int) ($person['photo_count'] ?? 0),
                'photos' => $personPhotos,
            ];
            unset($stateById[$id]);
        }
        // A newly created empty manual person has no photo membership yet but
        // remains manageable. No Immich or database id is serialized.
        foreach ($stateById as $id => $overlay) {
            if (isset($activeMergeSources[$id])) {
                continue;
            }
            $identityId = isset($overlay['classmate_identity_id']) ? (int) $overlay['classmate_identity_id'] : null;
            $items[] = [
                'id' => $id,
                'displayName' => $overlay['display_name'] ?? null,
                'coverPhotoId' => null,
                'classmateIdentityId' => $identityId,
                'classmateIdentityName' => $identityId !== null ? ($identityNames[$identityId] ?? null) : null,
                'hidden' => ($overlay['visibility'] ?? 'VISIBLE') === 'HIDDEN',
                'photoCount' => 0,
                'photos' => [],
            ];
        }
        usort($items, static fn(array $left, array $right): int => strcmp((string) ($left['displayName'] ?? ''), (string) ($right['displayName'] ?? '')) ?: strcmp($left['id'], $right['id']));
        return ['total' => count($items), 'items' => $items, 'merges' => $mergeItems];
    }

    /** @return array<string,mixed> */
    public function managementOptions(): array
    {
        $userId = $this->currentUserId();
        \ClassIdentity\DomainSupport::requireSystemAdmin($userId);
        $albums = [];
        if ($this->albumDomain !== null) {
            foreach ($this->albumDomain->listMappings($userId) as $album) {
                if (($album['state'] ?? null) !== 'ACTIVE') {
                    continue;
                }
                $albums[] = ['id' => $album['class_album_id'], 'name' => $album['name'], 'type' => $album['album_type']];
            }
        }
        $repository = Repository::fromPiwigo();
        $events = [];
        foreach ($repository->fetchAll(
            'SELECT DISTINCT `event_label` FROM `' . $repository->table('archive_image') . '` '
                . "WHERE `event_label` IS NOT NULL AND `event_label` <> '' ORDER BY `event_label` LIMIT 1000",
        ) as $row) {
            $label = trim((string) ($row['event_label'] ?? ''));
            if ($label !== '') {
                $events[] = ['id' => hash('sha256', $label), 'name' => $label];
            }
        }
        $identities = [];
        foreach ($repository->fetchAll(
            'SELECT `id`,`roster_code`,`real_name` FROM `' . $repository->table('identity') . "` WHERE `identity_type`='CLASSMATE' AND `state` <> 'RETIRED' ORDER BY `roster_code` LIMIT 5000",
        ) as $row) {
            $identities[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['real_name'],
                'rosterCode' => (string) $row['roster_code'],
            ];
        }
        return ['albums' => $albums, 'events' => $events, 'identities' => $identities];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function managedDuplicates(): array
    {
        if ($this->canonicalDomain === null) {
            throw new \RuntimeException('class_archive_duplicate_service_unavailable');
        }
        $rows = $this->canonicalDomain->listCandidates($this->currentUserId());
        $visibleById = [];
        foreach ($this->visiblePhotos() as $photo) {
            $visibleById[$photo->id()] = $photo->publicProjection();
        }
        $items = [];
        foreach ($rows as $row) {
            $left = (string) $row['left_class_photo_id'];
            $right = (string) $row['right_class_photo_id'];
            if (!isset($visibleById[$left], $visibleById[$right])) {
                continue;
            }
            $items[] = [
                'id' => (string) $row['duplicate_id'],
                'type' => (string) $row['relation_kind'],
                'exact' => ($row['relation_kind'] ?? null) === 'EXACT',
                'similarity' => $row['similarity'],
                'photos' => [
                    [
                        'id' => $left,
                        'sourceLabel' => '来源 1',
                        'sourceCount' => count($this->canonicalDomain->provenanceSummary($left)),
                    ],
                    [
                        'id' => $right,
                        'sourceLabel' => '来源 2',
                        'sourceCount' => count($this->canonicalDomain->provenanceSummary($right)),
                    ],
                ],
            ];
        }
        return ['total' => count($items), 'items' => $items];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function search(string $query): array
    {
        $query = $this->normalizeQuery($query);
        $matches = [];
        $matchingIds = $this->matchingCanonicalPhotoIds($query);
        foreach ($this->visiblePhotos() as $photo) {
            if (isset($matchingIds[$photo->id()])) {
                $matches[] = $photo->publicProjection();
            }
        }

        return ['total' => count($matches), 'items' => $matches];
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    public function people(): array
    {
        $projection = $this->visiblePeople();
        $items = [];
        foreach ($projection['items'] as $item) {
            if (is_array($item)) {
                unset($item['items']);
                $items[] = $item;
            }
        }
        return ['available' => $projection['available'], 'total' => count($items), 'items' => $items];
    }

    /** @return array<string,mixed>|null */
    public function person(string $classPersonId): ?array
    {
        ClassArchivePerson::idToBinary($classPersonId);
        $projection = $this->visiblePeople();
        foreach ($projection['items'] as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id']) && hash_equals($item['id'], $classPersonId)) {
                return $item;
            }
        }
        // Never distinguish an inaccessible cluster from an unknown id.
        return null;
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    public function smartSearch(string $query): array
    {
        $query = $this->normalizeQuery($query);
        if ($this->immich->availability() !== 'AVAILABLE') {
            throw new \RuntimeException('class_archive_gateway_smart_search_unavailable');
        }
        $resolution = $this->visiblePhotoResolution();
        $visible = $resolution['photos'];
        $allowed = self::allowedIdSet($visible);
        if ($allowed === []) {
            return ['available' => true, 'total' => 0, 'items' => []];
        }
        $byId = [];
        foreach ($visible as $photo) {
            $byId[$photo->id()] = $photo;
        }
        $seen = [];
        $items = [];
        foreach ($this->immich->smartSearchForVisiblePhotos($resolution['raw_ids'], $query) as $classPhotoId) {
            if (!is_string($classPhotoId) || !isset($resolution['id_map'][$classPhotoId])) {
                throw new \RuntimeException('class_archive_gateway_smart_search_response_invalid');
            }
            $canonicalId = $resolution['id_map'][$classPhotoId];
            if (!isset($allowed[$canonicalId], $byId[$canonicalId])) {
                throw new \RuntimeException('class_archive_gateway_smart_search_response_invalid');
            }
            if (!isset($seen[$canonicalId])) {
                $seen[$canonicalId] = true;
                $items[] = $byId[$canonicalId]->publicProjection();
            }
        }

        return ['available' => true, 'total' => count($items), 'items' => $items];
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    public function memories(): array
    {
        $resolution = $this->visiblePhotoResolution();
        $visible = $resolution['photos'];
        $allowed = self::allowedIdSet($visible);
        $byId = [];
        $items = [];
        foreach ($visible as $photo) {
            $byId[$photo->id()] = $photo;
            $bucket = $photo->timelineBucket();
            if (($bucket['kind'] ?? 'UNKNOWN') === 'UNKNOWN') {
                continue;
            }
            $key = 'archive:' . (string) ($bucket['key'] ?? '');
            if (!isset($items[$key])) {
                $items[$key] = [
                    'label' => (string) ($bucket['label'] ?? '班级回忆'),
                    'kind' => (string) ($bucket['kind'] ?? 'EVENT'),
                    'photo_count' => 0,
                    'cover_photo_id' => $photo->id(),
                ];
            }
            ++$items[$key]['photo_count'];
        }
        $immichAvailable = $this->immich->availability() === 'AVAILABLE';
        // Class Archive chronology is the business truth. When it can form a
        // reliable event/month/year memory, do not duplicate it with an
        // opaque Immich collection over the same photos.
        if ($items !== []) {
            return ['available' => true, 'total' => count($items), 'items' => array_values($items)];
        }
        if (!$immichAvailable) {
            return ['available' => false, 'total' => 0, 'items' => []];
        }
        $items = [];
        $ordinal = 0;
        foreach ($this->immich->memoriesForVisiblePhotos($resolution['raw_ids']) as $candidate) {
            if (!$candidate instanceof GatewayMemoryCandidate) {
                throw new \RuntimeException('class_archive_gateway_memory_candidate_invalid');
            }
            $members = [];
            foreach ($candidate->classPhotoIds() as $rawClassPhotoId) {
                $classPhotoId = $resolution['id_map'][$rawClassPhotoId] ?? null;
                if (!is_string($classPhotoId) || !isset($allowed[$classPhotoId], $byId[$classPhotoId])) {
                    throw new \RuntimeException('class_archive_gateway_memory_candidate_invalid');
                }
                $members[$classPhotoId] = true;
            }
            if ($members === []) {
                continue;
            }
            $coverPhotoId = null;
            foreach ($visible as $photo) {
                if (isset($members[$photo->id()])) {
                    $coverPhotoId = $photo->id();
                    break;
                }
            }
            if ($coverPhotoId === null) {
                throw new \RuntimeException('class_archive_gateway_memory_cover_invalid');
            }
            ++$ordinal;
            $items['immich:' . $ordinal] = [
                'label' => $candidate->label(),
                'kind' => 'COLLECTION',
                'photo_count' => count($members),
                'cover_photo_id' => $coverPhotoId,
            ];
        }

        return ['available' => true, 'total' => count($items), 'items' => array_values($items)];
    }

    /** @return list<GatewayPhotoCandidate> */
    private function visiblePhotos(): array
    {
        return $this->visiblePhotoResolution()['photos'];
    }

    /**
     * Policy is evaluated on every physical candidate before logical aliases
     * are folded. A canonical target missing from the same visible set is an
     * inconsistent authorization graph, so the whole response fails closed.
     *
     * @return array{photos:list<GatewayPhotoCandidate>,raw_photos:list<GatewayPhotoCandidate>,raw_ids:list<string>,id_map:array<string,string>}
     */
    private function visiblePhotoResolution(): array
    {
        if ($this->visiblePhotoResolution !== null) {
            return $this->visiblePhotoResolution;
        }
        $principal = $this->principal();
        try {
            // The adapter has no aggregate/count API. Filtering happens before
            // every count, group, person and memory computation in this class.
            $raw = $this->policy->filterVisible($principal, $this->piwigo->photoCandidates());
            $rawById = [];
            foreach ($raw as $photo) {
                $rawById[$photo->id()] = $photo;
            }
            $rawIds = array_keys($rawById);
            $idMap = $this->canonicalDomain === null
                ? array_combine($rawIds, $rawIds)
                : $this->canonicalDomain->canonicalMapFor($rawIds);
            if ($idMap === false || count($idMap) !== count($rawById)) {
                throw new \RuntimeException('class_archive_gateway_canonical_mapping_invalid');
            }

            /** @var array<string,list<GatewayPhotoCandidate>> $groups */
            $groups = [];
            foreach ($rawById as $id => $photo) {
                $canonicalId = $idMap[$id] ?? null;
                if (!is_string($canonicalId) || !isset($rawById[$canonicalId])) {
                    throw new \RuntimeException('class_archive_gateway_canonical_target_unavailable');
                }
                if (($idMap[$canonicalId] ?? $canonicalId) !== $canonicalId) {
                    throw new \RuntimeException('class_archive_gateway_canonical_chain_invalid');
                }
                $groups[$canonicalId][] = $photo;
            }

            $photos = [];
            foreach ($raw as $photo) {
                $id = $photo->id();
                if (($idMap[$id] ?? null) !== $id) {
                    continue;
                }
                $members = $groups[$id] ?? null;
                if (!is_array($members) || $members === []) {
                    throw new \RuntimeException('class_archive_gateway_canonical_group_invalid');
                }
                $photos[] = count($members) === 1
                    ? $photo
                    : $this->mergeCanonicalCandidates($photo, $members);
            }
            if (count($photos) !== count($groups)) {
                throw new \RuntimeException('class_archive_gateway_canonical_projection_incomplete');
            }
            return $this->visiblePhotoResolution = [
                'photos' => $photos,
                'raw_photos' => $raw,
                'raw_ids' => $rawIds,
                'id_map' => $idMap,
            ];
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_gateway_source_unavailable', 0, $error);
        }
    }

    /** @param list<GatewayPhotoCandidate> $members */
    private function mergeCanonicalCandidates(GatewayPhotoCandidate $canonical, array $members): GatewayPhotoCandidate
    {
        $base = $canonical->publicProjection();
        $title = is_string($base['title'] ?? null) && trim((string) $base['title']) !== ''
            ? (string) $base['title'] : null;
        $takenAt = is_string($base['taken_at'] ?? null) ? (string) $base['taken_at'] : null;
        $datePrecision = (string) ($base['date_precision'] ?? 'UNKNOWN');
        $dateSource = (string) ($base['date_source'] ?? 'UNKNOWN');
        $eventLabel = is_string($base['event_label'] ?? null) ? (string) $base['event_label'] : null;
        $albumLabels = [];
        $categoryIds = [];
        $searchParts = [];

        foreach ($members as $member) {
            if (!$member instanceof GatewayPhotoCandidate
                || $member->era() !== $canonical->era()
                || $member->state() !== $canonical->state()
                || $member->mappingState() !== $canonical->mappingState()
            ) {
                throw new \RuntimeException('class_archive_gateway_canonical_metadata_conflict');
            }
            $projection = $member->publicProjection();
            foreach ($member->albumLabels() as $label) {
                $albumLabels[$label] = true;
                $searchParts[] = $label;
            }
            foreach ($member->albumCategoryIds() as $categoryId) {
                $categoryIds[$categoryId] = true;
            }
            foreach (['title', 'taken_at', 'event_label'] as $field) {
                if (is_string($projection[$field] ?? null) && trim((string) $projection[$field]) !== '') {
                    $searchParts[] = (string) $projection[$field];
                }
            }
            $memberTakenAt = is_string($projection['taken_at'] ?? null) ? (string) $projection['taken_at'] : null;
            $memberPrecision = (string) ($projection['date_precision'] ?? 'UNKNOWN');
            $memberEvent = is_string($projection['event_label'] ?? null) && trim((string) $projection['event_label']) !== ''
                ? trim((string) $projection['event_label'])
                : null;
            if (($takenAt !== null && $memberTakenAt !== null && $takenAt !== $memberTakenAt)
                || ($datePrecision !== 'UNKNOWN' && $memberPrecision !== 'UNKNOWN' && $datePrecision !== $memberPrecision)
                || ($eventLabel !== null && $memberEvent !== null && $eventLabel !== $memberEvent)
            ) {
                // This also protects installations containing a legacy or
                // partially restored consolidated row created before the
                // write-side conflict gate existed.
                throw new \RuntimeException('class_archive_gateway_canonical_archive_metadata_conflict');
            }
            if ($title === null && is_string($projection['title'] ?? null) && trim((string) $projection['title']) !== '') {
                $title = (string) $projection['title'];
            }
            if ($eventLabel === null && $memberEvent !== null) {
                $eventLabel = $memberEvent;
            }
            // The selected canonical row remains authoritative when it has
            // archive evidence. Alias metadata fills only an unknown target;
            // both variants remain searchable and physically preserved.
            if ($dateSource === 'UNKNOWN'
                && is_string($projection['date_source'] ?? null)
                && (string) $projection['date_source'] !== 'UNKNOWN'
            ) {
                $takenAt = is_string($projection['taken_at'] ?? null) ? (string) $projection['taken_at'] : null;
                $datePrecision = (string) ($projection['date_precision'] ?? 'UNKNOWN');
                $dateSource = (string) $projection['date_source'];
                if (is_string($projection['event_label'] ?? null) && trim((string) $projection['event_label']) !== '') {
                    $eventLabel = (string) $projection['event_label'];
                }
            }
        }

        return new GatewayPhotoCandidate(
            $canonical->id(),
            $canonical->era(),
            $canonical->state(),
            $canonical->mappingState(),
            $title,
            $takenAt,
            array_keys($albumLabels),
            implode("\n", $searchParts),
            $canonical->piwigoImageIdForDelivery(),
            $datePrecision,
            $dateSource,
            $eventLabel,
            array_map('intval', array_keys($categoryIds)),
        );
    }

    /** @return array<string,true> */
    private function matchingCanonicalPhotoIds(string $query): array
    {
        $resolution = $this->visiblePhotoResolution();
        $matches = [];
        foreach ($resolution['raw_photos'] as $photo) {
            if (!$photo->matches($query)) {
                continue;
            }
            $canonicalId = $resolution['id_map'][$photo->id()] ?? null;
            if (!is_string($canonicalId)) {
                throw new \RuntimeException('class_archive_gateway_canonical_mapping_invalid');
            }
            $matches[$canonicalId] = true;
        }
        return $matches;
    }

    /**
     * @return array{available:bool,items:list<array<string,mixed>>}
     */
    private function visiblePeople(): array
    {
        $resolution = $this->visiblePhotoResolution();
        $visible = $resolution['photos'];
        if ($this->immich->availability() !== 'AVAILABLE' && $this->personCuration === null) {
            return ['available' => false, 'items' => []];
        }
        $allowed = self::allowedIdSet($visible);
        if ($allowed === []) {
            return ['available' => true, 'items' => []];
        }
        $photosById = [];
        foreach ($visible as $photo) {
            $photosById[$photo->id()] = $photo;
        }
        if ($this->personCuration !== null) {
            $clusterRows = $this->immich->availability() === 'AVAILABLE'
                ? $this->rawPersonClusters()
                : [];
            $clustersById = [];
            foreach ($clusterRows as $cluster) {
                if (is_string($cluster['class_person_id'] ?? null)) {
                    $clustersById[strtolower((string) $cluster['class_person_id'])] = $cluster;
                }
            }
            $projected = $this->personCuration->projectForVisiblePhotos(array_keys($allowed), $clusterRows);
            $items = [];
            $ordinal = 0;
            foreach ($projected as $person) {
                $memberIds = [];
                foreach (($person['class_photo_ids'] ?? []) as $photoId) {
                    if (!is_string($photoId) || !isset($photosById[$photoId])) {
                        throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                    }
                    $memberIds[$photoId] = true;
                }
                if ($memberIds === []) {
                    continue;
                }
                $photos = [];
                foreach ($visible as $photo) {
                    if (isset($memberIds[$photo->id()])) {
                        $photos[] = $photo->publicProjection();
                    }
                }
                ++$ordinal;
                $label = is_string($person['display_name'] ?? null) && trim((string) $person['display_name']) !== ''
                    ? trim((string) $person['display_name'])
                    : '未命名人物 ' . $ordinal;
                $cover = is_string($person['cover_class_photo_id'] ?? null) ? (string) $person['cover_class_photo_id'] : '';
                if (!isset($memberIds[$cover])) {
                    $cover = (string) $photos[0]['id'];
                }
                $item = [
                    'id' => (string) $person['class_person_id'],
                    'label' => $label,
                    'photo_count' => count($photos),
                    'cover_photo_id' => $cover,
                    'items' => $photos,
                ];
                foreach (($person['source_class_person_ids'] ?? []) as $sourceId) {
                    $source = is_string($sourceId) ? ($clustersById[strtolower($sourceId)] ?? null) : null;
                    if (!is_array($source)
                        || !hash_equals((string) ($source['cover_class_photo_id'] ?? ''), $cover)
                        || !is_array($source['portrait_focus'] ?? null)
                    ) {
                        continue;
                    }
                    $item['portrait_focus'] = $source['portrait_focus'];
                    break;
                }
                $items[] = $item;
            }
            return ['available' => $this->immich->availability() === 'AVAILABLE' || $items !== [], 'items' => $items];
        }
        /** @var array<string,array{candidate:GatewayPersonCandidate,members:array<string,true>}> $people */
        $people = [];
        foreach ($this->canonicalPersonCandidates() as $candidate) {
            $id = $candidate->id();
            if (!isset($people[$id])) {
                $people[$id] = ['candidate' => $candidate, 'members' => []];
            } elseif (!hash_equals($people[$id]['candidate']->label(), $candidate->label())) {
                throw new \RuntimeException('class_archive_gateway_people_candidate_ambiguous');
            }
            foreach ($candidate->classPhotoIds() as $classPhotoId) {
                if (!isset($allowed[$classPhotoId])) {
                    throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                }
                $people[$id]['members'][$classPhotoId] = true;
            }
        }
        ksort($people, SORT_STRING);
        $items = [];
        $ordinal = 0;
        foreach ($people as $id => $person) {
            $memberIds = $person['members'];
            if ($memberIds === []) {
                continue;
            }
            $photos = [];
            foreach ($visible as $photo) {
                if (isset($memberIds[$photo->id()])) {
                    $photos[] = $photo->publicProjection();
                }
            }
            if ($photos === []) {
                continue;
            }
            ++$ordinal;
            $label = $person['candidate']->label();
            if ($label === '人物') {
                $label = '人物 ' . $ordinal;
            }
            $coverPhotoId = $person['candidate']->coverPhotoId();
            if ($coverPhotoId === null || !isset($memberIds[$coverPhotoId]) || !isset($photosById[$coverPhotoId])) {
                $coverPhotoId = (string) $photos[0]['id'];
            }
            $portraitFocus = hash_equals($coverPhotoId, (string) ($person['candidate']->coverPhotoId() ?? ''))
                ? $person['candidate']->portraitFocus()
                : null;
            $item = [
                'id' => $id,
                'label' => $label,
                'photo_count' => count($photos),
                'cover_photo_id' => $coverPhotoId,
                'items' => $photos,
            ];
            if ($portraitFocus !== null) {
                $item['portrait_focus'] = $portraitFocus;
            }
            $items[] = $item;
        }

        return ['available' => true, 'items' => $items];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visibleAlbumItems(bool $includePhotos = false): array
    {
        if ($this->albumDomain === null) {
            return [];
        }
        $visible = $this->visiblePhotos();
        $categoryIds = [];
        $photoIds = [];
        foreach ($visible as $photo) {
            $photoIds[] = $photo->id();
            foreach ($photo->albumCategoryIds() as $categoryId) {
                $categoryIds[$categoryId] = true;
            }
        }
        if ($categoryIds === [] || $photoIds === []) {
            return [];
        }
        $mappings = $this->albumDomain->projectVisible(array_map('intval', array_keys($categoryIds)), $photoIds);
        $context = $this->authorizationContext();
        $principalId = (int) ($context['principal_id'] ?? 0);
        $role = (string) ($context['role'] ?? '');
        $items = [];
        foreach ($mappings as $mapping) {
            $categoryId = (int) ($mapping['piwigo_category_id'] ?? 0);
            $members = [];
            foreach ($visible as $photo) {
                if (in_array($categoryId, $photo->albumCategoryIds(), true)) {
                    $members[] = $photo;
                }
            }
            if ($members === []) {
                continue;
            }
            $memberSet = [];
            foreach ($members as $member) {
                $memberSet[$member->id()] = true;
            }
            $cover = is_string($mapping['cover_class_photo_id'] ?? null)
                ? (string) $mapping['cover_class_photo_id']
                : null;
            if ($cover === null || !isset($memberSet[$cover])) {
                $cover = $members[0]->id();
            }
            // Owner data remains internal and is used only for the boolean UI
            // capability. A principal/account/seat id never crosses the API.
            $domain = $this->albumDomain->findByClassAlbumId((string) $mapping['class_album_id']);
            if ($domain === null) {
                throw new \RuntimeException('class_archive_gateway_album_mapping_unavailable');
            }
            $owned = $principalId > 0 && (int) ($domain['owner_principal_id'] ?? 0) === $principalId;
            $canSpotlight = $owned && ($mapping['album_type'] ?? null) === 'COMMUNITY'
                && in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true);
            $era = (string) ($mapping['era'] ?? 'MIXED');
            $dateLabel = is_string($mapping['event_label'] ?? null) && trim((string) $mapping['event_label']) !== ''
                ? trim((string) $mapping['event_label'])
                : match ($era) {
                    'HERITAGE' => '班级历史',
                    'LIVING' => '毕业后动态',
                    default => '跨时期相册',
                };
            $item = [
                'id' => strtolower((string) $mapping['class_album_id']),
                'name' => (string) $mapping['name'],
                'type' => (string) $mapping['album_type'],
                'description' => $mapping['description'] ?? null,
                'eventLabel' => $mapping['event_label'] ?? null,
                'dateLabel' => $dateLabel,
                'total' => count($members),
                'coverPhotoId' => $cover,
                'owned' => $owned,
                'canSpotlight' => $canSpotlight,
            ];
            if ($includePhotos) {
                $item['items'] = array_map(static fn(GatewayPhotoCandidate $photo): array => $photo->publicProjection(), $members);
            }
            $items[] = $item;
        }
        usort($items, static function (array $left, array $right): int {
            $type = strcmp((string) $left['type'], (string) $right['type']);
            return $type !== 0 ? $type : strnatcasecmp((string) $left['name'], (string) $right['name']);
        });
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function rawPersonClusters(): array
    {
        if ($this->immich->availability() !== 'AVAILABLE') {
            return [];
        }
        $rows = [];
        foreach ($this->canonicalPersonCandidates() as $candidate) {
            $rows[] = [
                'class_person_id' => $candidate->id(),
                'class_photo_ids' => $candidate->classPhotoIds(),
                'cover_class_photo_id' => $candidate->coverPhotoId(),
                'portrait_focus' => $candidate->portraitFocus(),
            ];
        }
        return $rows;
    }

    /**
     * Immich is queried with every policy-approved physical UUID, including
     * active exact aliases. Memberships and covers are then folded to opaque
     * canonical UUIDs before counts, curation or serialization.
     *
     * @return list<GatewayPersonCandidate>
     */
    private function canonicalPersonCandidates(): array
    {
        if ($this->immich->availability() !== 'AVAILABLE') {
            return [];
        }
        $resolution = $this->visiblePhotoResolution();
        $result = [];
        foreach ($this->immich->peopleForVisiblePhotos($resolution['raw_ids']) as $candidate) {
            if (!$candidate instanceof GatewayPersonCandidate) {
                throw new \RuntimeException('class_archive_gateway_people_candidate_invalid');
            }
            $members = [];
            foreach ($candidate->classPhotoIds() as $rawClassPhotoId) {
                $canonicalId = $resolution['id_map'][$rawClassPhotoId] ?? null;
                if (!is_string($canonicalId)) {
                    throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                }
                $members[$canonicalId] = true;
            }
            if ($members === []) {
                continue;
            }
            $cover = $candidate->coverPhotoId();
            if ($cover !== null) {
                $cover = $resolution['id_map'][$cover] ?? null;
                if (!is_string($cover) || !isset($members[$cover])) {
                    throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                }
            }
            $result[] = new GatewayPersonCandidate(
                $candidate->id(),
                $candidate->label(),
                array_keys($members),
                $cover,
                $candidate->portraitFocus(),
            );
        }
        return $result;
    }

    /** @return array<int,string> */
    private function identityNames(): array
    {
        $repository = Repository::fromPiwigo();
        $result = [];
        foreach ($repository->fetchAll(
            'SELECT `id`,`real_name` FROM `' . $repository->table('identity') . "` WHERE `identity_type`='CLASSMATE' AND `state` <> 'RETIRED'",
        ) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && is_string($row['real_name'] ?? null)) {
                $result[$id] = (string) $row['real_name'];
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function authorizationContext(): array
    {
        global $user;
        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        $context = $userId > 0 ? Access::resolveAuthorizationContext($userId) : null;
        if (!is_array($context) || !is_string($context['role'] ?? null)) {
            throw new \RuntimeException('class_archive_gateway_principal_unresolved');
        }
        return $context;
    }

    private function currentUserId(): int
    {
        $context = $this->authorizationContext();
        $id = (int) ($context['piwigo_user_id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('class_archive_gateway_principal_unresolved');
        }
        return $id;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return function_exists('mb_stripos')
            ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false
            : stripos($haystack, $needle) !== false;
    }

    /** Local-only bilingual hints; exact archive matches still rank first. */
    private static function semanticQuery(string $query): string
    {
        $vocabulary = [
            '操场' => 'playground',
            '教室' => 'classroom',
            '毕业' => 'graduation',
            '篮球' => 'basketball',
            '合照' => 'group photo',
            '集体照' => 'group photo',
            '夜晚' => 'night',
            '晚上' => 'night',
            '运动会' => 'sports day',
        ];
        $hints = [];
        foreach ($vocabulary as $chinese => $english) {
            if (self::contains($query, $chinese)) {
                $hints[$english] = true;
            }
        }
        return $hints === [] ? $query : trim($query . ' ' . implode(' ', array_keys($hints)));
    }

    private function principal(): GatewayPrincipal
    {
        try {
            $principal = $this->identity->currentPrincipal();
        } catch (\Throwable) {
            $principal = null;
        }
        if (!$principal instanceof GatewayPrincipal) {
            throw new \RuntimeException('class_archive_gateway_principal_unresolved');
        }
        return $principal;
    }

    private function normalizeQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 190 || str_contains($query, "\0")) {
            throw new \InvalidArgumentException('class_archive_gateway_search_invalid');
        }
        return $query;
    }

    /** @param list<GatewayPhotoCandidate> $photos @return array<string,true> */
    private static function allowedIdSet(array $photos): array
    {
        $result = [];
        foreach ($photos as $photo) {
            $result[$photo->id()] = true;
        }
        return $result;
    }

    /** @param list<string> $candidateIds @param array<string,true> $allowed */
    private static function intersectionCount(array $candidateIds, array $allowed): int
    {
        $seen = [];
        foreach ($candidateIds as $id) {
            if (isset($allowed[$id])) {
                $seen[$id] = true;
            }
        }
        return count($seen);
    }
}

/**
 * Exact public API contract. Metadata routes expose no backend media identity;
 * the canonical media route still requires a resolved ClassIdentity Principal,
 * an already visible candidate and a fresh MediaGuard authorization before
 * nginx can transfer any bytes.
 */
final class GatewayRouteContract
{
    /** @return array<string, array{method:string,evidence:string}> */
    public static function routes(): array
    {
        return [
            '/api/photos' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/timeline' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/albums' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/people' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/people/{id}' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/search' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/search/smart' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/photos/{id}' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/photos/{id}/media/{thumbnail|preview|original}' => ['method' => 'GET, HEAD', 'evidence' => 'CONTRACT_TESTED'],
            '/api/me' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/memories' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/product-state' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/albums/{id}' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/spotlight' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/search/hybrid' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/people' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/options' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/duplicates' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/people/{create|update|merge|visibility|revert-merge|move-photos}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/archive/bulk' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/albums/cover' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/duplicates/consolidate' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/spotlight/{create|cancel}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
        ];
    }

    public static function publiclyBound(): bool
    {
        return true;
    }
}
