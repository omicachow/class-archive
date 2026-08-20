<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

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
    public function __construct(
        private readonly IdentityAdapter $identity,
        private readonly PiwigoAdapter $piwigo,
        private readonly ImmichAdapter $immich,
        private readonly GatewayPolicy $policy = new GatewayPolicy(),
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
        $visible = $this->visiblePhotos();
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
            $date = $projection['taken_at'];
            $key = is_string($date) ? substr($date, 0, 7) : 'unknown';
            $groups[$key]['key'] = $key;
            $groups[$key]['items'][] = $projection;
        }
        krsort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            $group['total'] = count($group['items']);
        }
        unset($group);

        return ['total' => count($visible), 'groups' => array_values($groups)];
    }

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function albums(): array
    {
        $albums = [];
        foreach ($this->visiblePhotos() as $photo) {
            foreach ($photo->albumLabels() as $label) {
                $albums[$label]['name'] = $label;
                $albums[$label]['photo_ids'][] = $photo->id();
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

    /** @return array{total:int,items:list<array<string,mixed>>} */
    public function search(string $query): array
    {
        $query = $this->normalizeQuery($query);
        $matches = [];
        foreach ($this->visiblePhotos() as $photo) {
            if ($photo->matches($query)) {
                $matches[] = $photo->publicProjection();
            }
        }

        return ['total' => count($matches), 'items' => $matches];
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    public function people(): array
    {
        $visible = $this->visiblePhotos();
        if ($this->immich->availability() !== 'AVAILABLE') {
            return ['available' => false, 'total' => 0, 'items' => []];
        }
        $allowed = self::allowedIdSet($visible);
        $items = [];
        foreach ($this->immich->peopleForVisiblePhotos(array_keys($allowed)) as $candidate) {
            if (!$candidate instanceof GatewayPersonCandidate) {
                throw new \RuntimeException('class_archive_gateway_people_candidate_invalid');
            }
            $count = self::intersectionCount($candidate->classPhotoIds(), $allowed);
            if ($count === 0) {
                continue;
            }
            $items[] = ['label' => $candidate->label(), 'photo_count' => $count];
        }

        return ['available' => true, 'total' => count($items), 'items' => $items];
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    public function memories(): array
    {
        $visible = $this->visiblePhotos();
        if ($this->immich->availability() !== 'AVAILABLE') {
            return ['available' => false, 'total' => 0, 'items' => []];
        }
        $allowed = self::allowedIdSet($visible);
        $items = [];
        foreach ($this->immich->memoriesForVisiblePhotos(array_keys($allowed)) as $candidate) {
            if (!$candidate instanceof GatewayMemoryCandidate) {
                throw new \RuntimeException('class_archive_gateway_memory_candidate_invalid');
            }
            $count = self::intersectionCount($candidate->classPhotoIds(), $allowed);
            if ($count === 0) {
                continue;
            }
            $items[] = ['label' => $candidate->label(), 'photo_count' => $count];
        }

        return ['available' => true, 'total' => count($items), 'items' => $items];
    }

    /** @return list<GatewayPhotoCandidate> */
    private function visiblePhotos(): array
    {
        $principal = $this->principal();
        try {
            // The adapter has no aggregate/count API. Filtering happens before
            // every count, group, person and memory computation in this class.
            return $this->policy->filterVisible($principal, $this->piwigo->photoCandidates());
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_gateway_source_unavailable', 0, $error);
        }
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
            '/api/search' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/photos/{id}' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/photos/{id}/media/{thumbnail|preview|original}' => ['method' => 'GET, HEAD', 'evidence' => 'CONTRACT_TESTED'],
            '/api/me' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/memories' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
        ];
    }

    public static function publiclyBound(): bool
    {
        return true;
    }
}
