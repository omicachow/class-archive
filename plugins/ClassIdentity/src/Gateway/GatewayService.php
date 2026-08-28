<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\Access;
use ClassIdentity\AlbumService;
use ClassIdentity\CanonicalPhotoService;
use ClassIdentity\ClassArchivePerson;
use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\CollectionSnapshotItem;
use ClassIdentity\CollectionSnapshotService;
use ClassIdentity\DomainSupport;
use ClassIdentity\MemoryAlbumCurationService;
use ClassIdentity\PersonCurationService;
use ClassIdentity\PhotoCommentService;
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
    private const TIMELINE_PAGE_DEFAULT = 120;
    private const TIMELINE_PAGE_MAX = 240;
    private const ALBUM_PAGE_DEFAULT = 120;
    private const ALBUM_PAGE_MAX = 240;
    private const SEARCH_PAGE_DEFAULT = 60;
    private const SEARCH_PAGE_MAX = 120;
    private const SEARCH_SECTION_MAX = 24;

    /**
     * Request-scoped, policy-filtered canonical projection. Keeping the raw
     * approved ids alongside their canonical ids lets AI enrichment preserve
     * an alias' metadata without ever returning the alias to the browser.
     *
     * @var array{photos:list<GatewayPhotoCandidate>,raw_photos:list<GatewayPhotoCandidate>,raw_ids:list<string>,id_map:array<string,string>}|null
     */
    private ?array $visiblePhotoResolution = null;

    /** @var array{total:int,groups:list<array<string,mixed>>}|null */
    private ?array $timelineProjection = null;

    /** Raw 32-byte presentation snapshot; public but never an authorization credential. */
    private ?string $timelineSnapshot = null;

    public function __construct(
        private readonly IdentityAdapter $identity,
        private readonly PiwigoAdapter $piwigo,
        private readonly ImmichAdapter $immich,
        private readonly GatewayPolicy $policy = new GatewayPolicy(),
        private readonly ?AlbumService $albumDomain = null,
        private readonly ?PersonCurationService $personCuration = null,
        private readonly ?SpotlightService $spotlightDomain = null,
        private readonly ?CanonicalPhotoService $canonicalDomain = null,
        private readonly ?ReadProjectionStore $readProjection = null,
        // Tests may inject a deterministic non-production root. Runtime uses
        // the already-provisioned server-only pseudonym root and derives a
        // separate timeline key below; the presentation epoch is public and
        // must never be accepted as HMAC key material.
        private readonly ?string $timelineCursorRootSecret = null,
        // Threaded product comments are deliberately optional for old
        // contract fixtures.  The HTTP runtime always injects the domain.
        private readonly ?PhotoCommentService $commentDomain = null,
        // Versioned V4 Collection snapshots remain optional for historical
        // contract fixtures. Runtime injects the durable domain; old routes
        // deliberately keep their existing read-projection contract.
        private readonly ?CollectionSnapshotService $collectionSnapshotDomain = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return ['role' => $this->principal()->role()];
    }

    /** @return array{role:string,presentation_epoch:string} */
    public function productState(): array
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
        }
        $principal = $this->principal();
        return [
            'role' => $principal->role(),
            'presentation_epoch' => $this->readProjection->presentationEpoch($this->projectionScope($principal)),
        ];
    }

    /**
     * Compact, persistent Collections-first entry projection.  Home is not a
     * disguised library request: it reads only existing role-scoped aggregate
     * rows and bounded card subsets, never `visiblePhotos()` or a live
     * Piwigo/Immich traversal.
     *
     * @return array<string,mixed>
     */
    public function home(): array
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        $scope = $this->projectionScope();
        $before = $this->readProjection->presentationEpoch($scope);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1) {
            throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
        }
        $spotlight = $this->validatedSpotlightProjection(
            $this->readProjection->aggregate(ReadProjectionStore::SPOTLIGHT, $scope),
        );
        $memories = $this->homeAggregateItems(ReadProjectionStore::MEMORIES, $scope, 8);
        $albums = $this->homeAggregateItems(ReadProjectionStore::ALBUMS, $scope, 8);
        $people = $this->homeAggregateItems(ReadProjectionStore::PEOPLE, $scope, 8);
        $timeline = $this->readProjection->aggregate(ReadProjectionStore::TIMELINE, $scope);
        $total = $timeline['total'] ?? null;
        if (!is_int($total) || $total < 0) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $after = $this->readProjection->presentationEpoch($scope);
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_gateway_home_snapshot_changed');
        }
        $featured = $spotlight['active'] === true && is_array($spotlight['item'] ?? null)
            ? [$spotlight['item']]
            : [];
        return [
            'featured' => ['items' => $featured],
            'memories' => ['items' => $memories],
            'albums' => ['items' => $albums],
            'people' => ['items' => $people],
            'allPhotos' => ['total' => $total],
        ];
    }

    /**
     * V4 Collections-first Home. Unlike the legacy `/api/home` contract this
     * consumes a published snapshot, then rechecks every referenced canonical
     * photo for the current principal before returning a count, cover or id.
     * It never creates a snapshot on GET.
     *
     * @return array<string,mixed>
     */
    public function collectionsHome(): array
    {
        $service = $this->collectionSnapshotDomain();
        [$scope, $before] = $this->collectionReadBinding();
        // Home is a coherent V17+ collection bundle, not an independently
        // refreshed HOME row.  During a bounded background rebuild the last
        // complete active bundle is safe to retain: every card is still
        // current-policy rechecked below.  A missing, partial or
        // mixed-revision bundle remains a fail-closed error.
        $bundle = $this->publishedCollectionSnapshotBundle($scope, $before, true);
        $snapshot = $service->activeSnapshot(
            $scope,
            CollectionSnapshotService::KIND_HOME,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
        $revision = $snapshot['revision'] ?? null;
        if (!is_string($revision) || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1
            || !hash_equals($bundle['snapshotRevision'], $revision)) {
            throw new \RuntimeException('class_archive_collection_snapshot_stale');
        }

        // Feedback is a private arrangement aid, never a visibility grant. The
        // domain returns only records whose target is still part of the current
        // role-scoped snapshot and passes a fresh media-policy recheck. HIDE
        // removes only this principal's Home card; it cannot suppress a card
        // for anyone else or affect archive/media access.
        $feedbackByTarget = [];
        foreach ($service->activeFeedback(
            $this->collectionPrincipalId(),
            $scope,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        ) as $feedback) {
            if (($feedback['projectionKind'] ?? null) !== CollectionSnapshotService::KIND_HOME
                || !is_string($feedback['itemKind'] ?? null)
                || !is_string($feedback['itemKey'] ?? null)
                || !is_string($feedback['feedback'] ?? null)
                || !is_array($feedback['item'] ?? null)) {
                continue;
            }
            $feedbackByTarget[(string) $feedback['itemKind'] . "\0" . (string) $feedback['itemKey']] = $feedback;
        }
        $visibleItems = [];
        $hiddenItems = [];
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['itemKind'] ?? null) || !is_string($item['itemKey'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_item_invalid');
            }
            $feedback = $feedbackByTarget[(string) $item['itemKind'] . "\0" . (string) $item['itemKey']] ?? null;
            if (is_array($feedback) && ($feedback['feedback'] ?? null) === CollectionSnapshotService::FEEDBACK_HIDE) {
                // Preserve only an already policy-checked public item for the
                // owner's quiet restore control. No id/metadata outside the
                // ordinary snapshot contract is introduced.
                $hiddenItems[] = [
                    'itemKind' => (string) $item['itemKind'],
                    'itemKey' => (string) $item['itemKey'],
                    'item' => $item,
                ];
                continue;
            }
            if (is_array($feedback)) {
                $item['feedback'] = (string) $feedback['feedback'];
            }
            $visibleItems[] = $item;
        }
        $snapshot['items'] = $visibleItems;
        $snapshot['preferences'] = ['hidden' => $hiddenItems];
        [, $after] = $this->collectionReadBinding();
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_gateway_collection_snapshot_changed');
        }
        // These are presentation-cache diagnostics, not authorization input.
        // The browser never chooses the mode; a current policy check was
        // applied to every card regardless of whether this is a fresh bundle
        // or the retained last complete bundle.
        $snapshot['presentationEpoch'] = $before;
        $snapshot['snapshotState'] = $bundle['mode'];
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function collectionsState(): array
    {
        $service = $this->collectionSnapshotDomain();
        [$scope, $epoch] = $this->collectionReadBinding();
        $state = $service->state($scope);
        foreach ($state['items'] as &$item) {
            if (($item['state'] ?? null) === 'ACTIVE'
                && (!is_string($item['revision'] ?? null) || !hash_equals($epoch, $item['revision']))) {
                $item['state'] = 'STALE';
                $item['snapshotId'] = null;
                $item['revision'] = null;
                $item['itemCount'] = 0;
            }
        }
        unset($item);
        return $state + ['presentationEpoch' => $epoch];
    }

    /** @return array<string,mixed> */
    public function collectionPins(): array
    {
        $service = $this->collectionSnapshotDomain();
        [$scope, $epoch] = $this->collectionReadBinding();
        // Pins are a read-only personal presentation.  They may use the same
        // retained active bundle as Home, but the write paths below still
        // require an exactly current snapshot before they alter preferences.
        $this->publishedCollectionSnapshotBundle($scope, $epoch, true);
        $pins = $service->pins(
            $this->collectionPrincipalId(),
            $scope,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
        $hiddenTargets = [];
        foreach ($service->activeFeedback(
            $this->collectionPrincipalId(),
            $scope,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        ) as $feedback) {
            if (($feedback['feedback'] ?? null) !== CollectionSnapshotService::FEEDBACK_HIDE
                || !is_string($feedback['projectionKind'] ?? null)
                || !is_string($feedback['itemKind'] ?? null)
                || !is_string($feedback['itemKey'] ?? null)) {
                continue;
            }
            $hiddenTargets[(string) $feedback['projectionKind'] . "\0" . (string) $feedback['itemKind'] . "\0" . (string) $feedback['itemKey']] = true;
        }
        $pins['items'] = array_values(array_filter(
            (array) ($pins['items'] ?? []),
            static function (mixed $pin) use ($hiddenTargets): bool {
                if (!is_array($pin)
                    || !is_string($pin['projectionKind'] ?? null)
                    || !is_array($pin['item'] ?? null)
                    || !is_string($pin['item']['itemKind'] ?? null)
                    || !is_string($pin['item']['itemKey'] ?? null)) {
                    throw new \RuntimeException('class_archive_collection_pin_row_invalid');
                }
                $target = (string) $pin['projectionKind'] . "\0" . (string) $pin['item']['itemKind'] . "\0" . (string) $pin['item']['itemKey'];
                return !isset($hiddenTargets[$target]);
            },
        ));
        return $pins;
    }

    /** @return array<string,mixed> */
    public function pinCollection(string $projectionKind, string $itemKind, string $itemKey): array
    {
        $this->requireCurrentCollectionSnapshot($projectionKind);
        return $this->collectionSnapshotDomain()->pin(
            $this->collectionPrincipalId(),
            $this->projectionScope(),
            $projectionKind,
            $itemKind,
            $itemKey,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
    }

    /** @return array<string,mixed> */
    public function unpinCollection(string $projectionKind, string $itemKind, string $itemKey): array
    {
        return $this->collectionSnapshotDomain()->unpin(
            $this->collectionPrincipalId(),
            $this->projectionScope(),
            $projectionKind,
            $itemKind,
            $itemKey,
        );
    }

    /**
     * @param list<array{projectionKind:string,itemKind:string,itemKey:string}> $pins
     * @return array<string,mixed>
     */
    public function reorderCollectionPins(array $pins): array
    {
        foreach ($pins as $pin) {
            if (!is_array($pin) || !is_string($pin['projectionKind'] ?? null)) {
                throw new \InvalidArgumentException('class_archive_collection_pin_reorder_invalid');
            }
            $this->requireCurrentCollectionSnapshot($pin['projectionKind']);
        }
        return $this->collectionSnapshotDomain()->reorderPins(
            $this->collectionPrincipalId(),
            $this->projectionScope(),
            $pins,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
    }

    /** @return array<string,mixed> */
    public function setCollectionFeedback(string $projectionKind, string $itemKind, string $itemKey, string $feedback): array
    {
        $this->requireCurrentCollectionSnapshot($projectionKind);
        return $this->collectionSnapshotDomain()->setFeedback(
            $this->collectionPrincipalId(),
            $this->projectionScope(),
            $projectionKind,
            $itemKind,
            $itemKey,
            $feedback,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
    }

    /** @return array<string,mixed> */
    public function clearCollectionFeedback(string $projectionKind, string $itemKind, string $itemKey): array
    {
        return $this->collectionSnapshotDomain()->clearFeedback(
            $this->collectionPrincipalId(),
            $this->projectionScope(),
            $projectionKind,
            $itemKind,
            $itemKey,
        );
    }

    /**
     * Save one current AutoCollection Memory as a distinct durable Album.
     *
     * The browser submits only the opaque snapshot key.  Gateway binds it to
     * the current role-scoped snapshot and the current FULL memory aggregate,
     * then the domain repeats durable membership/era validation before any
     * Piwigo association occurs.  This is a shared curation operation, not a
     * client-side copy/export and never transfers an original byte.
     *
     * @return array{created:bool,privateArrangement:bool,savedKind:'ALBUM'|'PRIVATE_PIN',albumId:?string,pinId:?string,title:string,photoCount:int,albumType:?string,era:?string}
     */
    public function saveMemoryAsAlbum(
        string $memoryKey,
        ?string $titleOverride,
        ?string $description,
        string $reason,
    ): array {
        $role = $this->principal()->role();
        $isFamilyPrivateArrangement = $role === Access::ROLE_FAMILY;
        if (!$isFamilyPrivateArrangement
            && !in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_SYSTEM_ADMIN], true)) {
            throw new \RuntimeException('class_archive_memory_album_role_forbidden');
        }
        [$scope, $epoch] = $this->collectionReadBinding();
        if ((!$isFamilyPrivateArrangement && $scope !== ReadProjectionStore::SCOPE_FULL)
            || ($isFamilyPrivateArrangement && $scope !== ReadProjectionStore::SCOPE_HERITAGE)) {
            throw new \RuntimeException('class_archive_memory_album_scope_invalid');
        }
        $this->requireCurrentCollectionSnapshot(CollectionSnapshotService::KIND_MEMORY);
        $snapshot = $this->collectionSnapshotDomain()->activeSnapshot(
            $scope,
            CollectionSnapshotService::KIND_MEMORY,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
        if (!is_string($snapshot['revision'] ?? null) || !hash_equals($epoch, (string) $snapshot['revision'])) {
            throw new \RuntimeException('class_archive_collection_snapshot_stale');
        }
        $snapshotItem = null;
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['itemKind'] ?? null) || !is_string($item['itemKey'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_item_invalid');
            }
            if (($item['itemKind'] ?? null) === CollectionSnapshotService::ITEM_AUTO_COLLECTION
                && hash_equals((string) $item['itemKey'], $memoryKey)) {
                if ($snapshotItem !== null) {
                    throw new \RuntimeException('class_archive_memory_album_snapshot_duplicate');
                }
                $snapshotItem = $item;
            }
        }
        if (!is_array($snapshotItem)) {
            throw new \RuntimeException('class_archive_collection_snapshot_item_not_visible');
        }
        if ($isFamilyPrivateArrangement) {
            // Family may keep a current, ACL-checked Memory as a private
            // organization item. It is exactly an idempotent Collection pin:
            // no Piwigo category, Album mapping, cover mutation, audit-visible
            // shared curation, media membership, or new original is created.
            // Personal title/description overrides would imply a second
            // private-album domain, so fail closed until that domain exists.
            if ($titleOverride !== null || $description !== null) {
                throw new \RuntimeException('class_archive_memory_private_arrangement_customization_forbidden');
            }
            $pin = $this->collectionSnapshotDomain()->pin(
                $this->collectionPrincipalId(),
                $scope,
                CollectionSnapshotService::KIND_MEMORY,
                CollectionSnapshotService::ITEM_AUTO_COLLECTION,
                $memoryKey,
                fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
            );
            $public = $pin['item'] ?? null;
            if (!is_array($public) || !is_string($public['title'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_pin_row_invalid');
            }
            return [
                'created' => false,
                'privateArrangement' => true,
                'savedKind' => 'PRIVATE_PIN',
                'albumId' => null,
                'pinId' => is_string($pin['pinId'] ?? null) ? (string) $pin['pinId'] : null,
                'title' => (string) $public['title'],
                'photoCount' => is_array($public['photoIds'] ?? null) ? count($public['photoIds']) : 0,
                'albumType' => null,
                'era' => 'HERITAGE',
            ];
        }
        $memory = $this->memoryProjectionForCuration($memoryKey, $snapshotItem, $scope);
        $visible = self::allowedIdSet($this->visiblePhotos());
        [, $afterLookup] = $this->collectionReadBinding();
        if (!hash_equals($epoch, $afterLookup)) {
            throw new \RuntimeException('class_archive_gateway_collection_snapshot_changed');
        }
        $result = MemoryAlbumCurationService::fromPiwigo()->saveFromCurrentMemory(
            $this->currentUserId(),
            $memoryKey,
            $memory,
            $visible,
            $titleOverride,
            $description,
            $reason,
        );
        if (($result['created'] ?? null) === true) {
            // A category relationship is a native Core source mutation. Build
            // a fresh catalog/projection before returning, rather than serving
            // an old snapshot or calculating a new card at read time.
            ReadProjectionBuilder::rebuild();
            invalidate_user_cache();
        }
        return [
            'created' => (bool) ($result['created'] ?? false),
            'privateArrangement' => false,
            'savedKind' => 'ALBUM',
            'albumId' => (string) ($result['class_album_id'] ?? ''),
            'pinId' => null,
            'title' => (string) ($result['title'] ?? ''),
            'photoCount' => (int) ($result['photo_count'] ?? 0),
            'albumType' => (string) ($result['album_type'] ?? ''),
            'era' => (string) ($result['era'] ?? ''),
        ];
    }

    /**
     * Change the cover of an already persisted Album. It intentionally does
     * not mutate AutoCollection covers: those are build-owned and would be
     * silently overwritten by the next memory projection run.
     *
     * @return array{updated:bool,albumId:string,coverPhotoId:string}
     */
    public function setAlbumCover(string $classAlbumId, string $coverClassPhotoId, string $reason): array
    {
        if ($this->mediaCandidate($coverClassPhotoId) === null) {
            // Hidden and unknown canonical ids intentionally share one outcome
            // before AlbumService looks at any category mapping.
            throw new \RuntimeException('class_archive_gateway_photo_not_found');
        }
        $result = AlbumService::fromPiwigo()->setManualCoverForActor(
            $this->currentUserId(),
            $classAlbumId,
            $coverClassPhotoId,
            $reason,
        );
        ReadProjectionBuilder::rebuild([
            ReadProjectionStore::ALBUMS,
            ReadProjectionStore::SPOTLIGHT,
        ], false);
        invalidate_user_cache();
        return [
            'updated' => true,
            'albumId' => (string) ($result['class_album_id'] ?? ''),
            'coverPhotoId' => (string) ($result['cover_class_photo_id'] ?? ''),
        ];
    }

    /**
     * @return array{total:int,items:list<array<string,mixed>>,hasMore:bool,nextCursor:?string}|null
     */
    public function comments(string $classPhotoId, ?string $cursor = null, ?int $limit = null): ?array
    {
        if ($this->commentDomain === null) {
            throw new \RuntimeException('class_archive_comment_domain_unavailable');
        }
        $candidate = $this->mediaCandidate($classPhotoId);
        if ($candidate === null) {
            // Hidden and unknown canonical ids deliberately share one result.
            return null;
        }
        return $this->commentDomain->listForVisiblePhoto(
            $candidate->id(),
            $candidate->piwigoImageIdForDelivery(),
            $this->principal()->role(),
            $cursor,
            $limit,
        );
    }

    /** @return array{comment_id:string} */
    public function createComment(string $classPhotoId, ?string $parentCommentId, string $body): array
    {
        if ($this->commentDomain === null) {
            throw new \RuntimeException('class_archive_comment_domain_unavailable');
        }
        $candidate = $this->mediaCandidate($classPhotoId);
        if ($candidate === null) {
            throw new \RuntimeException('class_archive_gateway_photo_not_found');
        }
        return $this->commentDomain->create(
            $this->currentUserId(),
            $candidate->id(),
            $candidate->piwigoImageIdForDelivery(),
            $parentCommentId,
            $body,
        );
    }

    /** @return array{deleted:bool} */
    public function deleteComment(string $commentId, string $reason): array
    {
        if ($this->commentDomain === null) {
            throw new \RuntimeException('class_archive_comment_domain_unavailable');
        }
        return $this->commentDomain->delete($this->currentUserId(), $commentId, $reason);
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
        $scope = $this->readProjection !== null ? $this->projectionScope() : null;
        $before = $scope !== null ? $this->readProjection?->presentationEpoch($scope) : null;
        $projection = null;
        if ($this->piwigo instanceof PointPiwigoAdapter) {
            $photo = $this->pointVisiblePhoto($classPhotoId);
            $projection = $photo?->publicProjection();
        } else {
            foreach ($this->visiblePhotos() as $photo) {
                if (hash_equals($photo->id(), $classPhotoId)) {
                    $projection = $photo->publicProjection();
                    break;
                }
            }
        }
        if ($projection === null) {
            // Never distinguish hidden from unknown canonical ids.
            return null;
        }
        if ($scope !== null && is_string($before)) {
            $after = $this->readProjection?->presentationEpoch($scope);
            if (!is_string($after) || preg_match('/\A[a-f0-9]{64}\z/D', $after) !== 1
                || !hash_equals($before, $after)
            ) {
                throw new \RuntimeException('class_archive_gateway_photo_snapshot_changed');
            }
            // The BFF consumes this binding and replaces it with an opaque,
            // browser-session cache scope. It is not a photo identifier or an
            // authorization credential and is never rendered by the UI.
            $projection['presentation_epoch'] = $after;
        }
        return $projection;
    }

    /**
     * Resolve a canonical id only after the same policy filter that feeds all
     * public aggregates. The internal candidate retains the private Piwigo
     * mapping for the MediaGuard dispatcher, but callers must never serialize
     * it or use it as a browser identity.
     */
    public function mediaCandidate(string $classPhotoId): ?GatewayPhotoCandidate
    {
        if ($this->piwigo instanceof PointPiwigoAdapter) {
            return $this->pointVisiblePhoto($classPhotoId);
        }
        foreach ($this->visiblePhotos() as $photo) {
            if (hash_equals($photo->id(), $classPhotoId)) {
                return $photo;
            }
        }
        // Never distinguish a hidden canonical id from an unknown one.
        return null;
    }

    /**
     * Point lookup shortens only the source read. Current-principal policy and
     * canonical alias checks remain mandatory and are never read from cache.
     */
    private function pointVisiblePhoto(string $classPhotoId): ?GatewayPhotoCandidate
    {
        ClassArchivePhoto::idToBinary($classPhotoId);
        try {
            $candidate = $this->piwigo instanceof PointPiwigoAdapter
                ? $this->piwigo->photoCandidate($classPhotoId)
                : null;
            if (!$candidate instanceof GatewayPhotoCandidate) {
                return null;
            }
            $visible = $this->policy->filterVisible($this->principal(), [$candidate]);
            if (count($visible) !== 1 || !$visible[0] instanceof GatewayPhotoCandidate) {
                return null;
            }
            if ($this->canonicalDomain !== null) {
                $map = $this->canonicalDomain->canonicalMapFor([$classPhotoId]);
                if (!isset($map[$classPhotoId]) || !hash_equals($classPhotoId, (string) $map[$classPhotoId])) {
                    // A consolidated alias is never a browser identity.
                    return null;
                }
            }
            return $visible[0];
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_gateway_source_unavailable', 0, $error);
        }
    }

    /**
     * Return one bounded, stable page from the current role-filtered timeline.
     * The cursor is only a position in an authenticated projection snapshot;
     * it is never accepted as an authorization credential.
     *
     * @return array{total:int,count:int,limit:int,groups:list<array<string,mixed>>,has_more:bool,next_cursor:?string}
     */
    public function timeline(?string $cursor = null, ?int $limit = null): array
    {
        $limit ??= self::TIMELINE_PAGE_DEFAULT;
        if ($limit < 1 || $limit > self::TIMELINE_PAGE_MAX) {
            throw new \InvalidArgumentException('class_archive_gateway_timeline_limit_invalid');
        }
        $timeline = $this->timelineProjection;
        if ($timeline === null) {
            if ($this->readProjection !== null) {
                $scope = $this->projectionScope();
                $before = $this->readProjection->presentationEpoch($scope);
                $timeline = $this->readProjection->aggregate(ReadProjectionStore::TIMELINE, $scope);
                $after = $this->readProjection->presentationEpoch($scope);
                if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1
                    || !hash_equals($before, $after)
                ) {
                    throw new \RuntimeException('class_archive_gateway_timeline_snapshot_changed');
                }
                $snapshot = hex2bin($before);
                if (!is_string($snapshot) || strlen($snapshot) !== 32) {
                    throw new \RuntimeException('class_archive_gateway_timeline_snapshot_invalid');
                }
                $this->timelineSnapshot = $snapshot;
            } else {
                $timeline = $this->computeTimeline();
                try {
                    $this->timelineSnapshot = hash(
                        'sha256',
                        json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        true,
                    );
                } catch (\Throwable $error) {
                    throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid', 0, $error);
                }
            }
            $this->timelineProjection = $timeline;
        }
        if (!is_string($this->timelineSnapshot) || strlen($this->timelineSnapshot) !== 32) {
            throw new \RuntimeException('class_archive_gateway_timeline_snapshot_invalid');
        }
        $page = self::paginateTimeline(
            $timeline,
            $cursor,
            $limit,
            $this->timelineSnapshot,
            $this->timelineCursorSigningKey(),
        );
        $page['presentation_epoch'] = bin2hex($this->timelineSnapshot);
        return $page;
    }

    /**
     * @param array<string,mixed> $timeline
     * @return array{total:int,count:int,limit:int,groups:list<array<string,mixed>>,has_more:bool,next_cursor:?string}
     */
    private static function paginateTimeline(
        array $timeline,
        ?string $cursor,
        int $limit,
        string $snapshot,
        string $signingKey,
    ): array
    {
        $total = $timeline['total'] ?? null;
        $groups = $timeline['groups'] ?? null;
        if (!is_int($total) || $total < 0 || !is_array($groups) || !array_is_list($groups)) {
            throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid');
        }

        $seenPhotoIds = [];
        $calculatedTotal = 0;
        foreach ($groups as $group) {
            if (!is_array($group)
                || !is_string($group['key'] ?? null)
                || !is_string($group['label'] ?? null)
                || !is_string($group['kind'] ?? null)
                || !is_int($group['total'] ?? null)
                || !is_array($group['items'] ?? null)
                || !array_is_list($group['items'])
                || $group['total'] < 1
                || $group['total'] !== count($group['items'])
            ) {
                throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid');
            }
            foreach ($group['items'] as $photo) {
                if (!is_array($photo) || !is_string($photo['id'] ?? null)) {
                    throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid');
                }
                try {
                    ClassArchivePhoto::idToBinary($photo['id']);
                } catch (\Throwable $error) {
                    throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid', 0, $error);
                }
                $photoId = strtolower($photo['id']);
                if (isset($seenPhotoIds[$photoId])) {
                    throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid');
                }
                $seenPhotoIds[$photoId] = true;
            }
            $calculatedTotal += $group['total'];
        }
        if ($calculatedTotal !== $total || ($total === 0 && $groups !== [])) {
            throw new \RuntimeException('class_archive_gateway_timeline_projection_invalid');
        }

        if (strlen($snapshot) !== 32) {
            throw new \RuntimeException('class_archive_gateway_timeline_snapshot_invalid');
        }
        if (strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_timeline_cursor_secret_unavailable');
        }
        $offset = self::decodeTimelineCursor($cursor, $snapshot, $total, $signingKey);
        $end = min($total, $offset + $limit);
        $pageGroups = [];
        $position = 0;
        $pageCount = 0;
        foreach ($groups as $group) {
            $groupStart = $position;
            $groupEnd = $position + $group['total'];
            $position = $groupEnd;
            if ($groupEnd <= $offset) {
                continue;
            }
            if ($groupStart >= $end) {
                break;
            }
            $sliceStart = max(0, $offset - $groupStart);
            $sliceLength = min($groupEnd, $end) - ($groupStart + $sliceStart);
            if ($sliceLength <= 0) {
                continue;
            }
            $items = array_slice($group['items'], $sliceStart, $sliceLength);
            $pageGroups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'kind' => $group['kind'],
                'total' => $group['total'],
                'count' => count($items),
                'items' => $items,
            ];
            $pageCount += count($items);
        }
        if ($pageCount !== $end - $offset) {
            throw new \RuntimeException('class_archive_gateway_timeline_page_invalid');
        }
        $hasMore = $end < $total;
        return [
            'total' => $total,
            'count' => $pageCount,
            'limit' => $limit,
            'groups' => $pageGroups,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? self::encodeTimelineCursor($end, $snapshot, $signingKey) : null,
        ];
    }

    private function timelineCursorSigningKey(): string
    {
        $root = $this->timelineCursorRootSecret;
        if ($root === null) {
            $environment = getenv('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET');
            $root = is_string($environment) ? $environment : null;
        }
        if (!is_string($root) || strlen($root) < 32 || strlen($root) > 4096) {
            throw new \RuntimeException('class_archive_gateway_timeline_cursor_secret_unavailable');
        }
        return hash_hmac(
            'sha256',
            "class-archive/timeline-cursor-signing/v1\0",
            $root,
            true,
        );
    }

    private static function encodeTimelineCursor(int $offset, string $snapshot, string $signingKey): string
    {
        if ($offset <= 0 || strlen($snapshot) !== 32 || strlen($signingKey) !== 32 || $offset > 0xffffffff) {
            throw new \RuntimeException('class_archive_gateway_timeline_cursor_invalid');
        }
        $packedOffset = pack('N', $offset);
        $mac = hash_hmac('sha256', $packedOffset . $snapshot, $signingKey, true);
        return rtrim(strtr(base64_encode($packedOffset . $mac), '+/', '-_'), '=');
    }

    private static function decodeTimelineCursor(
        ?string $cursor,
        string $snapshot,
        int $total,
        string $signingKey,
    ): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_timeline_cursor_invalid');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || strlen($decoded) !== 36) {
            throw new \InvalidArgumentException('class_archive_gateway_timeline_cursor_invalid');
        }
        $unpacked = unpack('Noffset', substr($decoded, 0, 4));
        $offset = is_array($unpacked) ? (int) ($unpacked['offset'] ?? 0) : 0;
        if (strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_timeline_cursor_secret_unavailable');
        }
        $expectedMac = hash_hmac(
            'sha256',
            substr($decoded, 0, 4) . $snapshot,
            $signingKey,
            true,
        );
        if ($offset <= 0 || $offset >= $total || !hash_equals($expectedMac, substr($decoded, 4))) {
            throw new \InvalidArgumentException('class_archive_gateway_timeline_cursor_invalid');
        }
        return $offset;
    }

    /** Album cursors are bound to both the role-scoped projection and album id. */
    private function albumCursorSigningKey(): string
    {
        $root = $this->timelineCursorRootSecret;
        if ($root === null) {
            $environment = getenv('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET');
            $root = is_string($environment) ? $environment : null;
        }
        if (!is_string($root) || strlen($root) < 32 || strlen($root) > 4096) {
            throw new \RuntimeException('class_archive_gateway_album_cursor_secret_unavailable');
        }
        return hash_hmac(
            'sha256',
            "class-archive/album-cursor-signing/v1\0",
            $root,
            true,
        );
    }

    private static function encodeAlbumCursor(int $offset, string $albumId, string $snapshot, string $signingKey): string
    {
        ClassArchivePhoto::idToBinary($albumId);
        if ($offset <= 0 || $offset > 0xffffffff || strlen($snapshot) !== 32 || strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_album_cursor_invalid');
        }
        $packedOffset = pack('N', $offset);
        $mac = hash_hmac('sha256', strtolower($albumId) . "\0" . $packedOffset . $snapshot, $signingKey, true);
        return rtrim(strtr(base64_encode($packedOffset . $mac), '+/', '-_'), '=');
    }

    private static function decodeAlbumCursor(
        ?string $cursor,
        string $albumId,
        string $snapshot,
        int $total,
        string $signingKey,
    ): int {
        ClassArchivePhoto::idToBinary($albumId);
        if ($total < 0 || strlen($snapshot) !== 32 || strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_album_cursor_invalid');
        }
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_album_cursor_invalid');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || strlen($decoded) !== 36) {
            throw new \InvalidArgumentException('class_archive_gateway_album_cursor_invalid');
        }
        $unpacked = unpack('Noffset', substr($decoded, 0, 4));
        $offset = is_array($unpacked) ? (int) ($unpacked['offset'] ?? 0) : 0;
        $expectedMac = hash_hmac(
            'sha256',
            strtolower($albumId) . "\0" . substr($decoded, 0, 4) . $snapshot,
            $signingKey,
            true,
        );
        if ($offset <= 0 || $offset >= $total || !hash_equals($expectedMac, substr($decoded, 4))) {
            throw new \InvalidArgumentException('class_archive_gateway_album_cursor_invalid');
        }
        return $offset;
    }

    /** @return array{total:int,groups:list<array<string,mixed>>} */
    private function computeTimeline(): array
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
        if ($this->readProjection !== null) {
            $projection = $this->readProjection->aggregate(ReadProjectionStore::ALBUMS, $this->projectionScope());
            $items = is_array($projection['items'] ?? null) ? $projection['items'] : null;
            if ($items === null) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $items = $this->applyAlbumCapabilities($items);
            foreach ($items as &$item) {
                unset($item['items'], $item['photo_ids'], $item['direct_photo_ids']);
            }
            unset($item);
            return ['total' => count($items), 'items' => $items];
        }
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

    /**
     * Narrow member-publication selector.  The ordinary album projection is a
     * read-only, leaf-card view and intentionally omits era/internal mapping
     * details, so do not ask a browser to infer upload eligibility from it.
     *
     * Only active CLASSMATE/TEACHER principals receive opaque IDs for active
     * OFFICIAL albums beneath the matching era root.  This is selection data,
     * never an authorization grant: MemberEraUploadService repeats the exact
     * mapping/state/tree checks immediately before Core writes.
     *
     * @return array{eras:array{HERITAGE:list<array{id:string,label:string,subtitle:?string}>,LIVING:list<array{id:string,label:string,subtitle:?string}>}}
     */
    public function memberEraUploadOptions(): array
    {
        if ($this->albumDomain === null) {
            throw new \RuntimeException('class_archive_member_upload_album_service_unavailable');
        }
        return $this->albumDomain->memberEraUploadOptions($this->currentUserId());
    }

    /** @return array<string,mixed>|null */
    public function album(string $classAlbumId, ?string $cursor = null, ?int $limit = null): ?array
    {
        \ClassIdentity\DomainSupport::idToBinary($classAlbumId);
        $limit ??= self::ALBUM_PAGE_DEFAULT;
        if ($limit < 1 || $limit > self::ALBUM_PAGE_MAX) {
            throw new \InvalidArgumentException('class_archive_gateway_album_limit_invalid');
        }
        if ($this->readProjection !== null) {
            $scope = $this->projectionScope();
            $before = $this->readProjection->presentationEpoch($scope);
            if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1) {
                throw new \RuntimeException('class_archive_gateway_album_snapshot_invalid');
            }
            $projection = $this->readProjection->aggregate(ReadProjectionStore::ALBUMS, $scope);
            $after = $this->readProjection->presentationEpoch($scope);
            if (!hash_equals($before, $after)) {
                throw new \RuntimeException('class_archive_gateway_album_snapshot_changed');
            }
            $snapshot = hex2bin($before);
            if (!is_string($snapshot) || strlen($snapshot) !== 32) {
                throw new \RuntimeException('class_archive_gateway_album_snapshot_invalid');
            }
            $items = is_array($projection['items'] ?? null) ? $this->applyAlbumCapabilities($projection['items']) : [];
            foreach ($items as $album) {
                if (is_array($album) && is_string($album['id'] ?? null) && hash_equals($album['id'], strtolower($classAlbumId))) {
                    $photoIds = $this->projectionPhotoIds($album);
                    $total = count($photoIds);
                    $offset = self::decodeAlbumCursor(
                        $cursor,
                        strtolower($classAlbumId),
                        $snapshot,
                        $total,
                        $this->albumCursorSigningKey(),
                    );
                    $pageIds = array_slice($photoIds, $offset, $limit);
                    if ($pageIds === [] && $total > 0) {
                        throw new \RuntimeException('class_archive_gateway_album_page_invalid');
                    }
                    $album['items'] = $this->hydrateProjectionPhotos($pageIds);
                    $end = $offset + count($pageIds);
                    $hasMore = $end < $total;
                    $album['total'] = $total;
                    $album['count'] = count($pageIds);
                    $album['limit'] = $limit;
                    $album['has_more'] = $hasMore;
                    $album['next_cursor'] = $hasMore
                        ? self::encodeAlbumCursor($end, strtolower($classAlbumId), $snapshot, $this->albumCursorSigningKey())
                        : null;
                    unset($album['photo_ids']);
                    return $album;
                }
            }
            return null;
        }
        foreach ($this->visibleAlbumItems(true) as $album) {
            if (isset($album['id']) && is_string($album['id']) && hash_equals($album['id'], strtolower($classAlbumId))) {
                return $album;
            }
        }
        // Hidden and unknown albums deliberately share the same result.
        return null;
    }

    /**
     * V4 grouped search contract.  SearchContext is a server-enforced
     * constraint, never a browser-side filter: its membership is resolved from
     * the current role-scoped projection before structured counts, covers,
     * people, exact photos, semantic candidates or a cursor are formed.
     *
     * The opaque cursor authenticates its offset together with the normalized
     * query, context, current principal namespace, role scope and presentation
     * revision.  It is therefore a continuation marker, not an authorization
     * credential and cannot be replayed by another account or after a
     * projection change.
     *
     * @return array<string,mixed>
     */
    public function groupedSearch(
        string $query,
        SearchContext $context,
        ?string $cursor = null,
        ?int $limit = null,
    ): array {
        $query = $this->normalizeQuery($query);
        $limit ??= self::SEARCH_PAGE_DEFAULT;
        if ($limit < 1 || $limit > self::SEARCH_PAGE_MAX) {
            throw new \InvalidArgumentException('class_archive_gateway_search_limit_invalid');
        }
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }

        $principal = $this->principal();
        $cursorSubject = $principal->cursorSubject();
        if ($cursorSubject === null) {
            // A role is not an account namespace. Do not issue a transferable
            // cursor merely because two principals share FULL visibility.
            throw new \RuntimeException('class_archive_gateway_search_cursor_principal_unavailable');
        }
        $scope = $this->projectionScope($principal);
        $before = $this->readProjection->presentationEpoch($scope);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1) {
            throw new \RuntimeException('class_archive_gateway_search_snapshot_invalid');
        }
        $this->requireAllPublishedCollectionSnapshotsCurrent($scope, $before);

        // `visiblePhotos()` applies ClassArchivePolicy before its result feeds
        // every result family. A context may only narrow this policy-approved
        // set; it can never add an id from a role's broader projection.
        $visible = $this->visiblePhotos();
        $visibleAllowed = self::allowedIdSet($visible);
        $contextAllowed = $this->resolveSearchContextPhotoIds($context, $scope, $before);
        if ($contextAllowed !== null) {
            $visible = array_values(array_filter(
                $visible,
                static fn(GatewayPhotoCandidate $photo): bool => isset($contextAllowed[$photo->id()]),
            ));
        }
        $allowed = self::allowedIdSet($visible);
        // A stale/invalid context must not degrade into an unscoped search.
        // Every non-ALL membership was already read from the active scoped
        // projection; this extra intersection prevents any dependency drift
        // from widening it during the same request.
        foreach ($allowed as $photoId => $_) {
            if (!isset($visibleAllowed[$photoId])) {
                throw new \RuntimeException('class_archive_gateway_search_context_dependency_invalid');
            }
        }

        $matchingIds = $this->matchingCanonicalPhotoIds($query);
        $photos = [];
        $events = [];
        $archiveTime = [];
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
                $archiveTime[$key]['label'] = (string) ($bucket['label'] ?? '日期未知');
                $archiveTime[$key]['kind'] = (string) ($bucket['kind'] ?? 'UNKNOWN');
                $archiveTime[$key]['photoCount'] = (int) ($archiveTime[$key]['photoCount'] ?? 0) + 1;
                $archiveTime[$key]['coverPhotoId'] ??= $photo->id();
            }
        }

        $people = [];
        try {
            foreach ($this->peopleForVisiblePhotoSet($allowed) as $person) {
                if (is_array($person) && self::contains((string) ($person['label'] ?? ''), $query)) {
                    $people[] = $person;
                }
            }
        } catch (\RuntimeException) {
            // Person enrichment is optional, but a failed projection may never
            // expand another result section or turn into a full-library read.
            $people = [];
        }

        $albums = [];
        foreach ($this->albumsForVisiblePhotoSet($allowed) as $album) {
            $haystack = implode("\n", array_filter([
                $album['name'] ?? null,
                $album['displayAlias'] ?? null,
                $album['description'] ?? null,
                $album['eventLabel'] ?? null,
                $album['dateLabel'] ?? null,
            ], 'is_string'));
            if (self::contains($haystack, $query)) {
                $albums[] = $album;
            }
        }

        $semantic = ['available' => false, 'total' => 0, 'items' => []];
        try {
            $semantic = $this->smartSearchForVisiblePhotoSet(self::semanticQuery($query), $allowed);
        } catch (\Throwable) {
            // Semantic failure is deliberately isolated: structured results
            // remain available, no stale Immich response is trusted, and no
            // broader query is attempted as a fallback.
        }

        $total = count($photos);
        $offset = self::decodeGroupedSearchCursor(
            $cursor,
            $query,
            $context,
            $scope,
            $cursorSubject,
            $before,
            $total,
            $this->searchCursorSigningKey(),
        );
        $pageItems = array_slice($photos, $offset, $limit);
        $end = $offset + count($pageItems);
        $hasMore = $end < $total;

        $after = $this->readProjection->presentationEpoch($scope);
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_gateway_search_snapshot_changed');
        }

        return [
            'query' => $query,
            'context' => $context->publicProjection(),
            'partial' => ($semantic['available'] ?? false) !== true,
            'projection_revision' => $before,
            'people' => self::boundedSearchSection($people),
            'albums' => self::boundedSearchSection($albums),
            'events' => self::boundedSearchSection(array_values($events)),
            'archiveTime' => self::boundedSearchSection(array_values($archiveTime)),
            'photos' => [
                'total' => $total,
                'count' => count($pageItems),
                'limit' => $limit,
                'items' => $pageItems,
            ],
            'semantic' => self::boundedSearchSection($semantic['items'] ?? [], (bool) ($semantic['available'] ?? false)),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore
                ? self::encodeGroupedSearchCursor(
                    $end,
                    $query,
                    $context,
                    $scope,
                    $cursorSubject,
                    $before,
                    $this->searchCursorSigningKey(),
                )
                : null,
        ];
    }

    /**
     * Structured archive results remain usable when semantic search is down.
     * Every section is formed from the already policy-filtered photo set, so
     * neither counts nor covers can reveal a hidden Era.
     *
     * @return array<string,mixed>
     */
    public function hybridSearch(string $query, ?string $albumId = null): array
    {
        $query = $this->normalizeQuery($query);
        if ($albumId !== null) {
            DomainSupport::idToBinary($albumId);
            $albumId = strtolower($albumId);
        }
        $albumAllowed = $albumId === null ? null : $this->albumScopedPhotoIds($albumId);
        $visible = $this->visiblePhotos();
        if ($albumAllowed !== null) {
            $visible = array_values(array_filter(
                $visible,
                static fn(GatewayPhotoCandidate $photo): bool => isset($albumAllowed[$photo->id()]),
            ));
        }
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
            if ($albumId !== null && !hash_equals((string) ($album['id'] ?? ''), $albumId)) {
                continue;
            }
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
            $peopleProjection = $albumAllowed === null
                ? ($this->people()['items'] ?? [])
                : $this->peopleForVisiblePhotoSet($albumAllowed);
            foreach ($peopleProjection as $person) {
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
            if ($albumAllowed !== null) {
                $smartItems = [];
                foreach (($smart['items'] ?? []) as $item) {
                    if (!is_array($item) || !is_string($item['id'] ?? null)) {
                        throw new \RuntimeException('class_archive_gateway_smart_search_response_invalid');
                    }
                    if (isset($albumAllowed[$item['id']])) {
                        $smartItems[] = $item;
                    }
                }
                $smart = ['available' => true, 'total' => count($smartItems), 'items' => $smartItems];
            }
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

    /**
     * Lightweight structured suggestions for the Apple-like search entry
     * state. This deliberately never invokes smart-search, face detection,
     * clustering, or any write-side job. Results are read only from the
     * active, role-scoped SEARCH_SUGGESTION snapshot and rechecked before
     * serialization; a missing or stale snapshot is an error, never a live
     * whole-library fallback.
     *
     * @return array<string,mixed>
     */
    public function searchSuggestions(string $query = '', ?string $albumId = null): array
    {
        $query = trim($query);
        if (strlen($query) > 190 || str_contains($query, "\0")) {
            throw new \InvalidArgumentException('class_archive_gateway_search_invalid');
        }
        if ($albumId !== null) {
            DomainSupport::idToBinary($albumId);
            $albumId = strtolower($albumId);
        }
        // Suggestions are a durable, role-scoped projection, including when
        // an old client supplies its legacy albumId input.  This endpoint must
        // never fall back to a live library aggregate: that would make opening
        // Search an accidental whole-library read and create a stale-count
        // side channel while a snapshot is being
        // republished.  The old route remains accepted, but is now a bounded
        // filter over the current immutable SEARCH_SUGGESTION snapshot.
        return $this->persistentSearchSuggestions($query, $albumId);
    }

    /** @return array{active:bool,total:int,items:list<array<string,mixed>>,item:?array<string,mixed>} */
    public function spotlight(): array
    {
        if ($this->readProjection !== null) {
            return $this->validatedSpotlightProjection(
                $this->readProjection->aggregate(ReadProjectionStore::SPOTLIGHT, $this->projectionScope()),
            );
        }
        return $this->computeSpotlight(true);
    }

    /** @return array{active:bool,total:int,items:list<array<string,mixed>>,item:?array<string,mixed>} */
    private function computeSpotlight(bool $requireCurrentUser): array
    {
        if ($this->spotlightDomain === null || $this->albumDomain === null) {
            return ['active' => false, 'total' => 0, 'items' => [], 'item' => null];
        }
        // Album membership and cover selection are already role-scoped. User
        // ownership capabilities are deliberately excluded from the shared
        // FULL/HERITAGE payload.
        $albums = $this->visibleAlbumItems(false, false);
        $byId = [];
        foreach ($albums as $album) {
            $byId[(string) $album['id']] = $album;
        }
        $records = $requireCurrentUser
            ? $this->spotlightDomain->activeForUser($this->currentUserId(), array_keys($byId))
            : $this->spotlightDomain->activeForProjection(array_keys($byId));
        $items = [];
        foreach ($records as $record) {
            $albumId = (string) ($record['class_album_id'] ?? '');
            if (!isset($byId[$albumId])) {
                continue;
            }
            $album = $byId[$albumId];
            $items[] = [
                'id' => (string) $record['spotlight_id'],
                'albumId' => $albumId,
                'albumName' => (string) $album['name'],
                'coverPhotoId' => $album['coverPhotoId'],
                'description' => $album['description'],
                'publisherLabel' => '班级成员',
                'expiresAt' => (string) $record['expires_at'],
            ];
        }
        if ($items === []) {
            return ['active' => false, 'total' => 0, 'items' => [], 'item' => null];
        }
        // The persisted collection builder applies fair round-robin ordering
        // to this complete role-scoped set.  Keep `item` only as a legacy
        // compatibility selection for old Gateway consumers; it never drops
        // concurrent active Spotlight records from the source projection.
        return ['active' => true, 'total' => count($items), 'items' => $items, 'item' => $items[0]];
    }

    /**
     * Validate the persisted public shape and suppress a cached card at its
     * server deadline even if the maintenance expiry job has not run yet.
     * Expiry can only remove visibility; it never falls back to live source.
     *
     * @param array<string,mixed> $projection
     * @return array{active:bool,total:int,items:list<array<string,mixed>>,item:?array<string,mixed>}
     */
    private function validatedSpotlightProjection(array $projection): array
    {
        $active = $projection['active'] ?? null;
        $total = $projection['total'] ?? null;
        $legacyItem = $projection['item'] ?? null;
        $items = $projection['items'] ?? null;
        // v16 aggregate rows contain the singleton `{item}` form.  Accept it
        // only for exactly one active record so the additive v17 deployment
        // can read its old projection until maintenance publishes the list.
        if ($items === null && $active === true && $total === 1 && is_array($legacyItem)) {
            $items = [$legacyItem];
        }
        // The old singleton projection also encoded an empty state as
        // `{active:false,total:0,item:null}`.  It carries no media or
        // capability data, so normalizing that exact shape preserves the
        // additive V17/V18 read path without accepting a malformed active
        // record or widening visibility.
        if ($items === null && $active === false && $total === 0 && $legacyItem === null) {
            $items = [];
        }
        if (!is_bool($active) || !is_int($total) || $total < 0 || !is_array($items) || !array_is_list($items)
            || ($legacyItem !== null && !is_array($legacyItem))
            || (!$active && ($total !== 0 || $items !== [] || $legacyItem !== null))
            || ($active && ($total < 1 || count($items) !== $total || count($items) > 64))
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        if (!$active) {
            return ['active' => false, 'total' => 0, 'items' => [], 'item' => null];
        }
        $visible = [];
        $seenIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            foreach (['id', 'albumId', 'albumName', 'publisherLabel', 'expiresAt'] as $field) {
                if (!is_string($item[$field] ?? null)) {
                    throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
                }
            }
            DomainSupport::idToBinary((string) $item['id']);
            if (isset($seenIds[(string) $item['id']])) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $seenIds[(string) $item['id']] = true;
            DomainSupport::idToBinary((string) $item['albumId']);
            if (!is_string($item['coverPhotoId'] ?? null)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            ClassArchivePhoto::idToBinary((string) $item['coverPhotoId']);
            if (($item['description'] ?? null) !== null && !is_string($item['description'])) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $expires = self::spotlightUtcTimestamp((string) $item['expiresAt']);
            // A stale cached record can only disappear at the deadline. It
            // can never make another card visible or cause a live rebuild.
            if ($expires > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                $visible[] = $item;
            }
        }
        if ($visible === []) {
            return ['active' => false, 'total' => 0, 'items' => [], 'item' => null];
        }
        $selected = $visible[0];
        if (is_array($legacyItem)) {
            if (!is_string($legacyItem['id'] ?? null)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $legacyFound = false;
            foreach ($visible as $candidate) {
                if (hash_equals((string) $legacyItem['id'], (string) $candidate['id'])) {
                    $selected = $candidate;
                    $legacyFound = true;
                    break;
                }
            }
            if (!$legacyFound) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
        }
        return ['active' => true, 'total' => count($visible), 'items' => $visible, 'item' => $selected];
    }

    private static function spotlightUtcTimestamp(string $value): \DateTimeImmutable
    {
        $zone = new \DateTimeZone('UTC');
        foreach ([
            ['!Y-m-d H:i:s.u', 'Y-m-d H:i:s.u'],
            ['!Y-m-d H:i:s', 'Y-m-d H:i:s'],
        ] as [$format, $canonicalFormat]) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed instanceof \DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && hash_equals($value, $parsed->format($canonicalFormat))
            ) {
                return $parsed;
            }
        }
        throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
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
        $projection = $this->peopleProjection();
        $items = [];
        foreach ($projection['items'] as $item) {
            if (is_array($item)) {
                unset($item['items'], $item['photo_ids']);
                $items[] = $item;
            }
        }
        return ['available' => $projection['available'], 'total' => count($items), 'items' => $items];
    }

    /** @return array<string,mixed>|null */
    public function person(string $classPersonId): ?array
    {
        ClassArchivePerson::idToBinary($classPersonId);
        $projection = $this->peopleProjection();
        foreach ($projection['items'] as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id']) && hash_equals($item['id'], $classPersonId)) {
                if ($this->readProjection !== null) {
                    $photoIds = $this->projectionPhotoIds($item);
                    $item['items'] = $this->hydrateProjectionPhotos($photoIds);
                    unset($item['photo_ids']);
                }
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
        if ($this->readProjection !== null) {
            return self::publicMemoryPayload(
                $this->readProjection->aggregate(ReadProjectionStore::MEMORIES, $this->projectionScope()),
            );
        }
        // The production HTTP controller always injects ReadProjectionStore.
        // This fallback remains solely for isolated, non-HTTP contract
        // fixtures; it neither writes nor invokes a model/runtime service.
        return self::publicMemoryPayload($this->computeMemories());
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    private function computeMemories(): array
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
                $metadata = self::memoryArchiveMetadata($bucket);
                $items[$key] = [
                    'label' => (string) ($bucket['label'] ?? '班级回忆'),
                    'kind' => (string) ($bucket['kind'] ?? 'EVENT'),
                    'photo_count' => 0,
                    'cover_photo_id' => $photo->id(),
                    'photo_ids' => [],
                    'source_reason' => self::memorySourceReason('ARCHIVE_BUCKET', $key),
                    'archive_date' => $metadata['archive_date'],
                    'date_precision' => $metadata['date_precision'],
                ];
            }
            ++$items[$key]['photo_count'];
            $items[$key]['photo_ids'][] = $photo->id();
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
            $memberIds = array_keys($members);
            $reasonMaterial = $candidate->label() . "\0" . implode("\0", $memberIds);
            $items[self::memorySourceReason('IMMICH_COLLECTION', $reasonMaterial)] = [
                'label' => $candidate->label(),
                'kind' => 'COLLECTION',
                'photo_count' => count($members),
                'cover_photo_id' => $coverPhotoId,
                'photo_ids' => $memberIds,
                'source_reason' => self::memorySourceReason('IMMICH_COLLECTION', $reasonMaterial),
                'archive_date' => null,
                'date_precision' => 'UNKNOWN',
            ];
        }

        return ['available' => true, 'total' => count($items), 'items' => array_values($items)];
    }

    /**
     * The stored aggregate includes opaque membership/source material needed
     * by the build-side AutoCollection synchronizer. Browser responses are a
     * narrower, validated presentation contract; the internal material is
     * never an API field or an authorization credential.
     *
     * @return array{available:bool,total:int,items:list<array<string,mixed>>}
     */
    private static function publicMemoryPayload(array $payload): array
    {
        if (!is_bool($payload['available'] ?? null)
            || !is_int($payload['total'] ?? null)
            || !is_array($payload['items'] ?? null)
            || !array_is_list($payload['items'])
            || (int) $payload['total'] !== count($payload['items'])
        ) {
            throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
        }
        $items = [];
        foreach ($payload['items'] as $item) {
            if (!is_array($item)
                || !is_string($item['label'] ?? null) || trim((string) $item['label']) === '' || strlen((string) $item['label']) > 190
                || !is_string($item['kind'] ?? null) || !in_array((string) $item['kind'], ['MONTH', 'YEAR', 'EVENT', 'COLLECTION'], true)
                || !is_int($item['photo_count'] ?? null) || (int) $item['photo_count'] < 1
                || !is_string($item['cover_photo_id'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
            }
            try {
                $cover = strtolower(ClassArchivePhoto::binaryToId(ClassArchivePhoto::idToBinary((string) $item['cover_photo_id'])));
            } catch (\Throwable $error) {
                throw new \RuntimeException('class_archive_gateway_memory_projection_invalid', 0, $error);
            }
            if (isset($item['photo_ids'])) {
                if (!is_array($item['photo_ids']) || !array_is_list($item['photo_ids'])
                    || count($item['photo_ids']) !== (int) $item['photo_count'] || count($item['photo_ids']) > 10000
                ) {
                    throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
                }
                $seen = [];
                foreach ($item['photo_ids'] as $photoId) {
                    if (!is_string($photoId)) {
                        throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
                    }
                    try {
                        $photoId = strtolower(ClassArchivePhoto::binaryToId(ClassArchivePhoto::idToBinary($photoId)));
                    } catch (\Throwable $error) {
                        throw new \RuntimeException('class_archive_gateway_memory_projection_invalid', 0, $error);
                    }
                    if (isset($seen[$photoId])) {
                        throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
                    }
                    $seen[$photoId] = true;
                }
                if (!isset($seen[$cover])) {
                    throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
                }
            }
            // Delete build-only material before constructing a public card.
            // The allow-list below is the actual response boundary; the unset
            // makes that boundary robust against a future refactor which
            // accidentally reuses $item later in this method.
            unset(
                $item['photo_ids'],
                $item['source_reason'],
                $item['projection_revision'],
                $item['archive_date'],
                $item['date_precision'],
            );
            $public = [
                'label' => (string) $item['label'],
                'kind' => (string) $item['kind'],
                'photo_count' => (int) $item['photo_count'],
                'cover_photo_id' => $cover,
            ];
            foreach (['album_id', 'albumId'] as $albumField) {
                if (!isset($item[$albumField])) {
                    continue;
                }
                if (!is_string($item[$albumField])) {
                    throw new \RuntimeException('class_archive_gateway_memory_projection_invalid');
                }
                try {
                    $public[$albumField] = strtolower(ClassArchivePhoto::binaryToId(ClassArchivePhoto::idToBinary($item[$albumField])));
                } catch (\Throwable $error) {
                    throw new \RuntimeException('class_archive_gateway_memory_projection_invalid', 0, $error);
                }
            }
            // Explicitly do not carry build material or backend ids.
            $items[] = $public;
        }
        return ['available' => (bool) $payload['available'], 'total' => count($items), 'items' => $items];
    }

    /** @param array{key?:string,label?:string,kind?:string} $bucket @return array{archive_date:?string,date_precision:string} */
    private static function memoryArchiveMetadata(array $bucket): array
    {
        $key = is_string($bucket['key'] ?? null) ? (string) $bucket['key'] : '';
        $kind = is_string($bucket['kind'] ?? null) ? (string) $bucket['kind'] : '';
        if ($kind === 'MONTH' && preg_match('/\Amonth:(\d{4})-(\d{2})\z/D', $key, $matches) === 1) {
            return ['archive_date' => $matches[1] . '-' . $matches[2] . '-01', 'date_precision' => 'MONTH'];
        }
        if ($kind === 'YEAR' && preg_match('/\Ayear:(\d{4})\z/D', $key, $matches) === 1) {
            return ['archive_date' => $matches[1] . '-01-01', 'date_precision' => 'YEAR'];
        }
        if ($kind === 'EVENT' && str_starts_with($key, 'event:')) {
            return ['archive_date' => null, 'date_precision' => 'EVENT_ONLY'];
        }
        throw new \RuntimeException('class_archive_gateway_memory_bucket_invalid');
    }

    private static function memorySourceReason(string $kind, string $material): string
    {
        if (!in_array($kind, ['ARCHIVE_BUCKET', 'IMMICH_COLLECTION'], true) || $material === '') {
            throw new \RuntimeException('class_archive_gateway_memory_source_invalid');
        }
        // 56 hexadecimal chars keep the private reason within the v15
        // VARCHAR(64) contract while leaving 224 bits of collision space.
        return 'MEMORY:' . substr(hash('sha256', $kind . "\0" . $material), 0, 56);
    }

    /**
     * Build-only entry point used by ReadProjectionBuilder. It intentionally
     * bypasses the read store so maintenance cannot recursively consume the
     * stale payload it is trying to replace.
     *
     * @return array<string,mixed>
     */
    public function projectionPayload(string $kind): array
    {
        return match ($kind) {
            ReadProjectionStore::TIMELINE => $this->computeTimeline(),
            ReadProjectionStore::ALBUMS => (function (): array {
                $items = $this->visibleAlbumItems(true, false);
                foreach ($items as &$item) {
                    $photos = is_array($item['items'] ?? null) ? $item['items'] : null;
                    if ($photos === null) {
                        throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
                    }
                    $item['photo_ids'] = array_map(static function (array $photo): string {
                        if (!is_string($photo['id'] ?? null)) {
                            throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
                        }
                        return (string) $photo['id'];
                    }, $photos);
                    unset($item['items']);
                    unset($item['direct_photo_ids']);
                }
                unset($item);
                return ['total' => count($items), 'items' => $items];
            })(),
            ReadProjectionStore::PEOPLE => (function (): array {
                $projection = $this->visiblePeople();
                $items = is_array($projection['items'] ?? null) ? $projection['items'] : [];
                foreach ($items as &$item) {
                    $photos = is_array($item['items'] ?? null) ? $item['items'] : null;
                    if ($photos === null) {
                        throw new \RuntimeException('class_archive_gateway_people_projection_invalid');
                    }
                    $item['photo_ids'] = array_map(static function (array $photo): string {
                        if (!is_string($photo['id'] ?? null)) {
                            throw new \RuntimeException('class_archive_gateway_people_projection_invalid');
                        }
                        return (string) $photo['id'];
                    }, $photos);
                    unset($item['items']);
                }
                unset($item);
                return [
                    'available' => (bool) ($projection['available'] ?? false),
                    'total' => count($items),
                    'items' => $items,
                ];
            })(),
            ReadProjectionStore::MEMORIES => $this->computeMemories(),
            ReadProjectionStore::SPOTLIGHT => $this->computeSpotlight(false),
            default => throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid'),
        };
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
            $canonical->mediaRevision(),
            $canonical->sourceDimensions()['width'] ?? null,
            $canonical->sourceDimensions()['height'] ?? null,
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

    /** @return array{available:bool,total?:int,items:list<array<string,mixed>>} */
    private function peopleProjection(): array
    {
        if ($this->readProjection !== null) {
            $projection = $this->readProjection->aggregate(ReadProjectionStore::PEOPLE, $this->projectionScope());
            if (!is_bool($projection['available'] ?? null)
                || !is_int($projection['total'] ?? null)
                || !is_array($projection['items'] ?? null)
                || $projection['total'] !== count($projection['items'])
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            return $projection;
        }
        $projection = $this->visiblePeople();
        $projection['total'] = count($projection['items']);
        return $projection;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function visibleAlbumItems(bool $includePhotos = false, bool $includeCapabilities = true): array
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
        $principalId = 0;
        $role = '';
        if ($includeCapabilities) {
            $context = $this->authorizationContext();
            $principalId = (int) ($context['principal_id'] ?? 0);
            $role = (string) ($context['role'] ?? '');
        }
        $items = [];
        foreach ($mappings as $mapping) {
            $categoryId = (int) ($mapping['piwigo_category_id'] ?? 0);
            $memberCategoryIds = $mapping['visible_category_ids'] ?? null;
            if ($categoryId <= 0 || !is_array($memberCategoryIds) || $memberCategoryIds === []) {
                throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
            }
            $memberCategorySet = [];
            foreach ($memberCategoryIds as $memberCategoryId) {
                if (!is_int($memberCategoryId) || $memberCategoryId <= 0) {
                    throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
                }
                $memberCategorySet[$memberCategoryId] = true;
            }
            $members = [];
            $directMembers = [];
            foreach ($visible as $photo) {
                $belongsToTree = false;
                foreach ($photo->albumCategoryIds() as $photoCategoryId) {
                    if (isset($memberCategorySet[$photoCategoryId])) {
                        $belongsToTree = true;
                        break;
                    }
                }
                if ($belongsToTree) {
                    $members[] = $photo;
                }
                if (in_array($categoryId, $photo->albumCategoryIds(), true)) {
                    $directMembers[] = $photo;
                }
            }
            // Piwigo hierarchy remains intact for source provenance and
            // administrative navigation, but consumer album cards are leaf
            // projections: a card represents only the photos directly held
            // by that mapped category.  This prevents a parent folder from
            // becoming a second copy of every descendant relationship.
            if ($directMembers === []) {
                continue;
            }
            $directMemberSet = [];
            foreach ($directMembers as $member) {
                $directMemberSet[$member->id()] = true;
            }
            $cover = is_string($mapping['cover_class_photo_id'] ?? null)
                ? (string) $mapping['cover_class_photo_id']
                : null;
            if ($cover === null || !isset($directMemberSet[$cover])) {
                $cover = $directMembers[0]->id();
            }
            // Owner data remains internal and is used only for the boolean UI
            // capability. A principal/account/seat id never crosses the API.
            $domain = $this->albumDomain->findByClassAlbumId((string) $mapping['class_album_id']);
            if ($domain === null) {
                throw new \RuntimeException('class_archive_gateway_album_mapping_unavailable');
            }
            $owned = $includeCapabilities && $principalId > 0
                && (int) ($domain['owner_principal_id'] ?? 0) === $principalId;
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
            $sourceCollectionCode = $mapping['source_collection_code'] ?? null;
            if ($sourceCollectionCode !== null && !is_string($sourceCollectionCode)) {
                throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
            }
            $sourceKind = match ($sourceCollectionCode) {
                'PRIVATE_SOURCE_A' => 'SOURCE_A',
                'PRIVATE_SOURCE_B' => 'SOURCE_B',
                null => (($mapping['album_type'] ?? null) === 'COMMUNITY' ? 'COMMUNITY' : 'ARCHIVE'),
                default => throw new \RuntimeException('class_archive_gateway_album_projection_invalid'),
            };
            $sourceLabel = $mapping['source_label'] ?? null;
            if ($sourceLabel !== null && !is_string($sourceLabel)) {
                throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
            }
            $displayAlias = $mapping['display_alias'] ?? null;
            if ($displayAlias !== null && !is_string($displayAlias)) {
                throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
            }
            $item = [
                'id' => strtolower((string) $mapping['class_album_id']),
                'name' => (string) $mapping['name'],
                'displayAlias' => $displayAlias,
                'type' => (string) $mapping['album_type'],
                'description' => $mapping['description'] ?? null,
                'eventLabel' => $mapping['event_label'] ?? null,
                'dateLabel' => $dateLabel,
                'total' => count($directMembers),
                'directTotal' => count($directMembers),
                // Kept solely as a bounded internal/presentation aggregate.
                // It is never used to synthesize ancestor membership.
                'recursiveTotal' => count($members),
                'coverPhotoId' => $cover,
                // This is a harmless presentation hint for the local full
                // library album landing page. It contains no source path or
                // provenance identifier and never participates in ACL.
                'sourceRoot' => ($mapping['source_root'] ?? false) === true,
                'sourceLabel' => $sourceLabel,
                'sourceKind' => $sourceKind,
                'owned' => $owned,
                'canSpotlight' => $canSpotlight,
            ];
            $parentId = $mapping['parent_class_album_id'] ?? null;
            if ($parentId !== null) {
                if (!is_string($parentId)) {
                    throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
                }
                ClassArchivePhoto::idToBinary($parentId);
                $item['parentAlbumId'] = strtolower($parentId);
            }
            if ($includePhotos) {
                $item['items'] = array_map(static fn(GatewayPhotoCandidate $photo): array => $photo->publicProjection(), $directMembers);
            }
            $items[] = $item;
        }
        usort($items, static function (array $left, array $right): int {
            $type = strcmp((string) $left['type'], (string) $right['type']);
            if ($type !== 0) {
                return $type;
            }
            return strnatcasecmp(
                (string) ($left['displayAlias'] ?? $left['name']),
                (string) ($right['displayAlias'] ?? $right['name']),
            );
        });
        return $items;
    }

    /**
     * Ownership is the only album-list field that is account-specific. Keep
     * it out of the shared role-scope payload and overlay it from fresh domain
     * state after current-principal authorization succeeds.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function applyAlbumCapabilities(array $items): array
    {
        if ($this->albumDomain === null) {
            throw new \RuntimeException('class_archive_album_service_unavailable');
        }
        $context = $this->authorizationContext();
        $principalId = (int) ($context['principal_id'] ?? 0);
        $role = (string) ($context['role'] ?? '');
        foreach ($items as &$item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $domain = $this->albumDomain->findByClassAlbumId((string) $item['id']);
            if ($domain === null) {
                throw new \RuntimeException('class_archive_gateway_album_mapping_unavailable');
            }
            $owned = $principalId > 0 && (int) ($domain['owner_principal_id'] ?? 0) === $principalId;
            $item['owned'] = $owned;
            $item['canSpotlight'] = $owned && ($item['type'] ?? null) === 'COMMUNITY'
                && in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true);
        }
        unset($item);
        return $items;
    }

    /**
     * Bounded card slice from a durable aggregate.  Remove nested member
     * lists even if an old payload contained them: Home cards must never turn
     * an aggregate read into a hidden full-library metadata response.
     *
     * @return list<array<string,mixed>>
     */
    private function homeAggregateItems(string $kind, string $scope, int $limit): array
    {
        if ($this->readProjection === null || $limit < 1 || $limit > 24) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $payload = $this->readProjection->aggregate($kind, $scope);
        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) > 10000) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $result = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            unset(
                $item['items'],
                $item['photo_ids'],
                $item['direct_photo_ids'],
                $item['source_reason'],
                $item['projection_revision'],
                $item['archive_date'],
                $item['date_precision'],
            );
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Resolves one leaf album's already persisted direct membership.  A parent
     * folder is never implicitly expanded here; that would reintroduce both
     * duplicate result counts and a potential category-side channel.
     *
     * @return array<string,true> canonical photo id set
     */
    private function albumScopedPhotoIds(string $classAlbumId): array
    {
        DomainSupport::idToBinary($classAlbumId);
        if ($this->readProjection !== null) {
            $payload = $this->readProjection->aggregate(ReadProjectionStore::ALBUMS, $this->projectionScope());
            $items = $payload['items'] ?? null;
            if (!is_array($items)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null)) {
                    throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
                }
                if (!hash_equals(strtolower((string) $item['id']), strtolower($classAlbumId))) {
                    continue;
                }
                $result = [];
                foreach ($this->projectionPhotoIds($item) as $photoId) {
                    $result[$photoId] = true;
                }
                return $result;
            }
            throw new \RuntimeException('class_archive_gateway_album_not_found');
        }
        foreach ($this->visibleAlbumItems(true, false) as $album) {
            if (!hash_equals((string) ($album['id'] ?? ''), strtolower($classAlbumId))) {
                continue;
            }
            $items = $album['items'] ?? null;
            if (!is_array($items)) {
                throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
            }
            $result = [];
            foreach ($items as $photo) {
                if (!is_array($photo) || !is_string($photo['id'] ?? null)) {
                    throw new \RuntimeException('class_archive_gateway_album_projection_invalid');
                }
                ClassArchivePhoto::idToBinary($photo['id']);
                $result[strtolower($photo['id'])] = true;
            }
            return $result;
        }
        throw new \RuntimeException('class_archive_gateway_album_not_found');
    }

    /**
     * @param array<string,true> $allowedPhotoIds
     * @return list<array<string,mixed>>
     */
    private function peopleForVisiblePhotoSet(array $allowedPhotoIds): array
    {
        $projection = $this->peopleProjection();
        $items = [];
        foreach (($projection['items'] ?? []) as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $memberIds = [];
            if (isset($item['photo_ids'])) {
                $memberIds = $this->projectionPhotoIds($item);
            } elseif (is_array($item['items'] ?? null)) {
                foreach ($item['items'] as $photo) {
                    if (!is_array($photo) || !is_string($photo['id'] ?? null)) {
                        throw new \RuntimeException('class_archive_gateway_people_response_invalid');
                    }
                    ClassArchivePhoto::idToBinary($photo['id']);
                    $memberIds[] = strtolower($photo['id']);
                }
            } else {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $visibleMembers = array_values(array_filter(
                $memberIds,
                static fn(string $photoId): bool => isset($allowedPhotoIds[$photoId]),
            ));
            if ($visibleMembers === []) {
                continue;
            }
            $cover = is_string($item['cover_photo_id'] ?? null) ? strtolower((string) $item['cover_photo_id']) : null;
            if ($cover === null || !isset($allowedPhotoIds[$cover])) {
                $cover = $visibleMembers[0];
            }
            $copy = $item;
            unset($copy['items'], $copy['photo_ids']);
            $copy['photo_count'] = count($visibleMembers);
            $copy['cover_photo_id'] = $cover;
            $items[] = $copy;
        }
        return $items;
    }

    /** @param array<string,mixed> $item @return list<string> */
    private function projectionPhotoIds(array $item): array
    {
        $ids = is_array($item['photo_ids'] ?? null) ? $item['photo_ids'] : null;
        if ($ids === null || count($ids) > 10000) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $seen = [];
        $result = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            ClassArchivePhoto::idToBinary($id);
            $id = strtolower($id);
            if (isset($seen[$id])) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            $seen[$id] = true;
            $result[] = $id;
        }
        $declared = is_int($item['photo_count'] ?? null)
            ? (int) $item['photo_count']
            : (is_int($item['total'] ?? null) ? (int) $item['total'] : null);
        if ($declared !== null && $declared !== count($result)) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        return $result;
    }

    /**
     * Hydrate membership ids from the current durable catalog. This is a
     * bounded indexed join, not a live Piwigo/Immich aggregation. Policy is
     * deliberately re-applied so a stale membership can never widen access.
     *
     * @param list<string> $photoIds
     * @return list<array<string,mixed>>
     */
    private function hydrateProjectionPhotos(array $photoIds): array
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        $candidates = $this->readProjection->photosByIds($photoIds);
        if (count($candidates) !== count($photoIds)) {
            throw new \RuntimeException('class_archive_read_aggregate_dependency_missing');
        }
        $visible = $this->policy->filterVisible($this->principal(), $candidates);
        if (count($visible) !== count($photoIds)) {
            // A restrictive Era/state mutation must rebuild the role-scoped
            // membership before detail reads resume; never silently expose or
            // miscount a stale member.
            throw new \RuntimeException('class_archive_read_aggregate_dependency_stale');
        }
        $result = [];
        foreach ($visible as $index => $photo) {
            if (!$photo instanceof GatewayPhotoCandidate || !hash_equals($photoIds[$index], $photo->id())) {
                throw new \RuntimeException('class_archive_read_aggregate_dependency_order_invalid');
            }
            $result[] = $photo->publicProjection();
        }
        return $result;
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

    /**
     * @param list<array<string,mixed>> $items
     * @return array{total:int,items:list<array<string,mixed>>}
     */
    private static function boundedSuggestions(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            $count = (int) ($right['photoCount'] ?? 0) <=> (int) ($left['photoCount'] ?? 0);
            return $count !== 0 ? $count : strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });
        return ['total' => count($items), 'items' => array_slice($items, 0, 8)];
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

    private function collectionSnapshotDomain(): CollectionSnapshotService
    {
        if (!$this->collectionSnapshotDomain instanceof CollectionSnapshotService) {
            throw new \RuntimeException('class_archive_collection_snapshot_domain_unavailable');
        }
        return $this->collectionSnapshotDomain;
    }

    private function collectionPrincipalId(): int
    {
        $context = $this->authorizationContext();
        $principalId = (int) ($context['principal_id'] ?? 0);
        $role = $context['role'] ?? null;
        if ($principalId <= 0 || !is_string($role) || !hash_equals($this->principal()->role(), $role)) {
            throw new \RuntimeException('class_archive_gateway_principal_unresolved');
        }
        return $principalId;
    }

    /** @return array{0:string,1:string} */
    private function collectionReadBinding(): array
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        $scope = $this->projectionScope();
        $epoch = $this->readProjection->presentationEpoch($scope);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $epoch) !== 1) {
            throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
        }
        return [$scope, $epoch];
    }

    private function requireCurrentCollectionSnapshot(string $projectionKind): void
    {
        [$scope, $epoch] = $this->collectionReadBinding();
        $state = $this->collectionSnapshotDomain()->state($scope);
        foreach ($state['items'] as $item) {
            if (($item['projectionKind'] ?? null) === $projectionKind
                && ($item['state'] ?? null) === 'ACTIVE'
                && is_string($item['revision'] ?? null)
                && hash_equals($epoch, $item['revision'])) {
                return;
            }
        }
        throw new \RuntimeException('class_archive_collection_snapshot_stale');
    }

    /**
     * Bind a browser-supplied opaque Memory key to one exact, current
     * server-side durable Memory projection. This method deliberately returns
     * build material only to the internal curation service; the public
     * snapshot and all HTTP responses keep source_reason and full membership
     * out of the browser.
     *
     * @param array<string,mixed> $snapshotItem
     * @return array{label:string,subtitle:?string,cover_photo_id:string,photo_ids:list<string>,source_reason:string}
     */
    private function memoryProjectionForCuration(string $memoryKey, array $snapshotItem, string $scope): array
    {
        if ($this->readProjection === null
            || preg_match('/\Amemory-[a-f0-9]{56}\z/D', $memoryKey) !== 1
            || ($snapshotItem['itemKind'] ?? null) !== CollectionSnapshotService::ITEM_AUTO_COLLECTION
            || !is_string($snapshotItem['itemKey'] ?? null)
            || !hash_equals((string) $snapshotItem['itemKey'], $memoryKey)) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        $payload = $snapshotItem['payload'] ?? null;
        $snapshotCover = $snapshotItem['coverPhotoId'] ?? null;
        $snapshotPhotos = $snapshotItem['photoIds'] ?? null;
        $snapshotCount = $snapshotItem['photoCount'] ?? null;
        if (!is_array($payload)
            || ($payload['section'] ?? null) !== 'MEMORY'
            || !is_string($payload['title'] ?? null)
            || !is_string($snapshotCover)
            || !is_array($snapshotPhotos)
            || !array_is_list($snapshotPhotos)
            || !is_int($snapshotCount)
            || $snapshotCount < 1
            || $snapshotCount !== count($snapshotPhotos)) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        $snapshotTitle = DomainSupport::boundedText((string) $payload['title'], 190, true);
        $snapshotSubtitle = $payload['subtitle'] ?? null;
        if ($snapshotSubtitle !== null && !is_string($snapshotSubtitle)) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        $snapshotSubtitle = DomainSupport::boundedText($snapshotSubtitle, 190);
        try {
            $snapshotCover = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($snapshotCover)));
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid', 0, $error);
        }
        $snapshotPhotoSet = [];
        foreach ($snapshotPhotos as $photoId) {
            if (!is_string($photoId)) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            try {
                $photoId = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($photoId)));
            } catch (\Throwable $error) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid', 0, $error);
            }
            if (isset($snapshotPhotoSet[$photoId])) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            $snapshotPhotoSet[$photoId] = true;
        }
        if (!isset($snapshotPhotoSet[$snapshotCover])) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }

        $projection = $this->readProjection->aggregate(ReadProjectionStore::MEMORIES, $scope);
        if (($projection['available'] ?? null) !== true
            || !is_int($projection['total'] ?? null)
            || !is_array($projection['items'] ?? null)
            || !array_is_list($projection['items'])
            || (int) $projection['total'] !== count($projection['items'])) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        $match = null;
        foreach ($projection['items'] as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            $sourceReason = $item['source_reason'] ?? null;
            if (!is_string($sourceReason) || preg_match('/\A[A-Z][A-Za-z0-9_:-]{1,63}\z/D', $sourceReason) !== 1) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            $candidateKey = 'memory-' . substr(hash('sha256', $sourceReason), 0, 56);
            if (!hash_equals($candidateKey, $memoryKey)) {
                continue;
            }
            if ($match !== null) {
                throw new \RuntimeException('class_archive_memory_album_projection_ambiguous');
            }
            $title = DomainSupport::boundedText($item['label'] ?? null, 190, true);
            $subtitle = DomainSupport::boundedText($item['subtitle'] ?? null, 190);
            $cover = $item['cover_photo_id'] ?? null;
            if (!is_string($cover)) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            try {
                $cover = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($cover)));
            } catch (\Throwable $error) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid', 0, $error);
            }
            $photoIds = $this->projectionPhotoIds($item);
            $fullSet = array_fill_keys($photoIds, true);
            if (!hash_equals($title, $snapshotTitle)
                || $subtitle !== $snapshotSubtitle
                || !hash_equals($cover, $snapshotCover)
                || !isset($fullSet[$cover])) {
                throw new \RuntimeException('class_archive_memory_album_projection_drift');
            }
            foreach ($snapshotPhotoSet as $photoId => $_) {
                if (!isset($fullSet[$photoId])) {
                    throw new \RuntimeException('class_archive_memory_album_projection_drift');
                }
            }
            $match = [
                'label' => $title,
                'subtitle' => $subtitle,
                'cover_photo_id' => $cover,
                'photo_ids' => $photoIds,
                'source_reason' => $sourceReason,
            ];
        }
        if (!is_array($match)) {
            throw new \RuntimeException('class_archive_collection_snapshot_item_not_visible');
        }
        return $match;
    }

    private function requireAllPublishedCollectionSnapshotsCurrent(string $scope, string $epoch): void
    {
        $this->publishedCollectionSnapshotBundle($scope, $epoch, false);
    }

    /**
     * Resolve the closed, atomic V4 collection bundle for a read.  The active
     * pointer is always the latest complete publication.  If a dependency
     * change invalidates its input revision before the maintenance worker has
     * published the next bundle, callers that opt in may serve that retained
     * bundle only.  They still must call `activeSnapshot()` with the current
     * ACL recheck for every item before serializing count, cover or photo id.
     *
     * This is deliberately not a historical-snapshot browse API: it never
     * selects a SUPERSEDED row, accepts a caller-supplied revision, repairs a
     * pointer, or builds a new snapshot on GET.  Mutations use the strict
     * `requireCurrentCollectionSnapshot()` path.
     *
     * @return array{snapshotRevision:string,mode:'CURRENT'|'FALLBACK'}
     */
    private function publishedCollectionSnapshotBundle(string $scope, string $epoch, bool $allowFallback): array
    {
        $state = $this->collectionSnapshotDomain()->state($scope);
        $expected = [
            CollectionSnapshotService::KIND_HOME => false,
            CollectionSnapshotService::KIND_MEMORY => false,
            CollectionSnapshotService::KIND_SPOTLIGHT => false,
            CollectionSnapshotService::KIND_SEARCH_SUGGESTION => false,
        ];
        $snapshotRevision = null;
        foreach ($state['items'] as $item) {
            $kind = $item['projectionKind'] ?? null;
            if (!is_string($kind) || !array_key_exists($kind, $expected)) {
                throw new \RuntimeException('class_archive_collection_snapshot_state_invalid');
            }
            if (($item['state'] ?? null) !== 'ACTIVE'
                || !is_string($item['revision'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $item['revision']) !== 1) {
                throw new \RuntimeException('class_archive_collection_snapshot_stale');
            }
            $itemRevision = (string) $item['revision'];
            if ($snapshotRevision === null) {
                $snapshotRevision = $itemRevision;
            } elseif (!hash_equals($snapshotRevision, $itemRevision)) {
                // Atomic publication has one logical input revision across all
                // four pointers.  A mixed bundle has no safe previous view.
                throw new \RuntimeException('class_archive_collection_snapshot_state_invalid');
            }
            if ($expected[$kind] === true) {
                throw new \RuntimeException('class_archive_collection_snapshot_state_invalid');
            }
            $expected[$kind] = true;
        }
        foreach ($expected as $published) {
            if ($published !== true) {
                throw new \RuntimeException('class_archive_collection_snapshot_stale');
            }
        }
        if (!is_string($snapshotRevision) || preg_match('/\A[a-f0-9]{64}\z/D', $snapshotRevision) !== 1) {
            throw new \RuntimeException('class_archive_collection_snapshot_stale');
        }
        if (hash_equals($epoch, $snapshotRevision)) {
            return ['snapshotRevision' => $snapshotRevision, 'mode' => 'CURRENT'];
        }
        if ($allowFallback) {
            return ['snapshotRevision' => $snapshotRevision, 'mode' => 'FALLBACK'];
        }
        throw new \RuntimeException('class_archive_collection_snapshot_stale');
    }

    /**
     * Current-ACL recheck hook for an immutable snapshot item. The snapshot is
     * only a candidate list: every photo UUID is resolved through the same
     * point-read policy path used by viewer/media delivery before a count or
     * cover can reach the browser.
     *
     * @return array<string,mixed>|null
     */
    private function recheckCollectionSnapshotItem(CollectionSnapshotItem $item): ?array
    {
        $visible = [];
        foreach ($item->photoIds() as $photoId) {
            if ($this->mediaCandidate($photoId) !== null) {
                $visible[] = $photoId;
            }
        }
        return $item->publicProjection($visible);
    }

    private function projectionScope(?GatewayPrincipal $principal = null): string
    {
        $principal ??= $this->principal();
        return match ($principal->role()) {
            Access::ROLE_FAMILY => ReadProjectionStore::SCOPE_HERITAGE,
            Access::ROLE_CLASSMATE,
            Access::ROLE_TEACHER,
            Access::ROLE_ANONYMOUS,
            Access::ROLE_SYSTEM_ADMIN => ReadProjectionStore::SCOPE_FULL,
            default => throw new \RuntimeException('class_archive_gateway_principal_unresolved'),
        };
    }

    /**
     * @return array<string,true>|null Null means the explicit ALL context.
     */
    private function resolveSearchContextPhotoIds(SearchContext $context, string $scope, string $revision): ?array
    {
        return match ($context->type()) {
            SearchContext::ALL => null,
            SearchContext::ALBUM => $this->albumScopedPhotoIds((string) $context->target()),
            SearchContext::PERSON => $this->personScopedPhotoIds((string) $context->target()),
            SearchContext::MEMORY => $this->snapshotSearchContextPhotoIds(
                CollectionSnapshotService::KIND_MEMORY,
                (string) $context->target(),
                $scope,
                $revision,
            ),
            SearchContext::COLLECTION => $this->snapshotSearchContextPhotoIds(
                CollectionSnapshotService::KIND_HOME,
                (string) $context->target(),
                $scope,
                $revision,
            ),
            default => throw new \RuntimeException('class_archive_gateway_search_context_unresolved'),
        };
    }

    /** @return array<string,true> */
    private function personScopedPhotoIds(string $classPersonId): array
    {
        ClassArchivePerson::idToBinary($classPersonId);
        $classPersonId = strtolower($classPersonId);
        $projection = $this->peopleProjection();
        foreach (($projection['items'] ?? []) as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            if (!hash_equals(strtolower((string) $item['id']), $classPersonId)) {
                continue;
            }
            return self::searchContextPhotoIdSet($this->projectionPhotoIds($item));
        }
        // Hidden and unknown People scopes deliberately share one outcome.
        throw new \RuntimeException('class_archive_gateway_search_context_not_found');
    }

    /** @return array<string,true> */
    private function snapshotSearchContextPhotoIds(string $projectionKind, string $itemKey, string $scope, string $revision): array
    {
        $this->requireCurrentCollectionSnapshot($projectionKind);
        $snapshot = $this->collectionSnapshotDomain()->activeSnapshot(
            $scope,
            $projectionKind,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckCollectionSnapshotItem($item),
        );
        $snapshotRevision = $snapshot['revision'] ?? null;
        if (!is_string($snapshotRevision) || !hash_equals($revision, $snapshotRevision)) {
            throw new \RuntimeException('class_archive_collection_snapshot_stale');
        }
        $items = $snapshot['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['itemKey'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            if (!hash_equals((string) $item['itemKey'], $itemKey)) {
                continue;
            }
            return self::searchContextPhotoIdSet($item['photoIds'] ?? null);
        }
        // Never distinguish an inaccessible snapshot card from an unknown key.
        throw new \RuntimeException('class_archive_gateway_search_context_not_found');
    }

    /** @return array<string,true> */
    private static function searchContextPhotoIdSet(mixed $photoIds): array
    {
        if (!is_array($photoIds) || !array_is_list($photoIds) || $photoIds === [] || count($photoIds) > 10000) {
            throw new \RuntimeException('class_archive_gateway_search_context_not_found');
        }
        $result = [];
        foreach ($photoIds as $photoId) {
            if (!is_string($photoId)) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            ClassArchivePhoto::idToBinary($photoId);
            $photoId = strtolower($photoId);
            if (isset($result[$photoId])) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            $result[$photoId] = true;
        }
        return $result;
    }

    /**
     * Restrict the durable album projection to an already current-policy
     * photo-set. Covers and every count are recomputed from that intersection;
     * no broader aggregate count crosses a context boundary.
     *
     * @param array<string,true> $allowedPhotoIds
     * @return list<array<string,mixed>>
     */
    private function albumsForVisiblePhotoSet(array $allowedPhotoIds): array
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        $payload = $this->readProjection->aggregate(ReadProjectionStore::ALBUMS, $this->projectionScope());
        $rawItems = $payload['items'] ?? null;
        if (!is_array($rawItems) || !array_is_list($rawItems)) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
            }
            ClassArchivePhoto::idToBinary((string) $item['id']);
            $members = array_values(array_filter(
                $this->projectionPhotoIds($item),
                static fn(string $photoId): bool => isset($allowedPhotoIds[$photoId]),
            ));
            if ($members === []) {
                continue;
            }
            $cover = is_string($item['coverPhotoId'] ?? null) ? strtolower((string) $item['coverPhotoId']) : null;
            if ($cover === null || !isset($allowedPhotoIds[$cover]) || !in_array($cover, $members, true)) {
                $cover = $members[0];
            }
            $copy = $item;
            unset($copy['items'], $copy['photo_ids'], $copy['direct_photo_ids']);
            $copy['id'] = strtolower((string) $copy['id']);
            $copy['total'] = count($members);
            $copy['directTotal'] = count($members);
            // A search context has no recursive membership. Retaining a count
            // formed outside it would create a context-side information leak.
            $copy['recursiveTotal'] = count($members);
            $copy['coverPhotoId'] = $cover;
            $items[] = $copy;
        }
        return $items;
    }

    /**
     * @param array<string,true> $allowedCanonicalIds
     * @return array{available:bool,total:int,items:list<array<string,mixed>>}
     */
    private function smartSearchForVisiblePhotoSet(string $query, array $allowedCanonicalIds): array
    {
        if ($this->immich->availability() !== 'AVAILABLE') {
            throw new \RuntimeException('class_archive_gateway_smart_search_unavailable');
        }
        if ($allowedCanonicalIds === []) {
            return ['available' => true, 'total' => 0, 'items' => []];
        }
        $resolution = $this->visiblePhotoResolution();
        $byId = [];
        foreach ($resolution['photos'] as $photo) {
            if (isset($allowedCanonicalIds[$photo->id()])) {
                $byId[$photo->id()] = $photo;
            }
        }
        if (count($byId) !== count($allowedCanonicalIds)) {
            throw new \RuntimeException('class_archive_gateway_search_context_dependency_invalid');
        }
        $rawIds = [];
        foreach ($resolution['raw_ids'] as $rawId) {
            $canonicalId = $resolution['id_map'][$rawId] ?? null;
            if (!is_string($canonicalId)) {
                throw new \RuntimeException('class_archive_gateway_canonical_mapping_invalid');
            }
            if (isset($allowedCanonicalIds[$canonicalId])) {
                $rawIds[] = $rawId;
            }
        }
        if ($rawIds === []) {
            throw new \RuntimeException('class_archive_gateway_search_context_dependency_invalid');
        }
        $seen = [];
        $items = [];
        foreach ($this->immich->smartSearchForVisiblePhotos($rawIds, $query) as $rawClassPhotoId) {
            if (!is_string($rawClassPhotoId) || !isset($resolution['id_map'][$rawClassPhotoId])) {
                throw new \RuntimeException('class_archive_gateway_smart_search_response_invalid');
            }
            $canonicalId = $resolution['id_map'][$rawClassPhotoId];
            if (!isset($allowedCanonicalIds[$canonicalId], $byId[$canonicalId])) {
                throw new \RuntimeException('class_archive_gateway_smart_search_response_invalid');
            }
            if (!isset($seen[$canonicalId])) {
                $seen[$canonicalId] = true;
                $items[] = $byId[$canonicalId]->publicProjection();
            }
        }
        return ['available' => true, 'total' => count($items), 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function persistentSearchSuggestions(string $query, ?string $legacyAlbumId): array
    {
        [$scope, $before] = $this->collectionReadBinding();
        // SEARCH_SUGGESTION belongs to the same closed bundle as Home,
        // Memories and Spotlight.  A retained active bundle may cover the
        // brief interval before its replacement is published, but each typed
        // target below is rechecked against the current scoped projection.
        $bundle = $this->publishedCollectionSnapshotBundle($scope, $before, true);
        $snapshot = $this->collectionSnapshotDomain()->activeSnapshot(
            $scope,
            CollectionSnapshotService::KIND_SEARCH_SUGGESTION,
            fn(CollectionSnapshotItem $item): ?array => $this->recheckSearchSuggestionSnapshotItem($item),
        );
        if (!is_string($snapshot['revision'] ?? null)
            || !hash_equals($bundle['snapshotRevision'], (string) $snapshot['revision'])) {
            throw new \RuntimeException('class_archive_collection_snapshot_stale');
        }
        $sections = [
            'people' => [],
            'albums' => [],
            'events' => [],
            'archiveTime' => [],
        ];
        $items = $snapshot['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        foreach ($items as $item) {
            if (!is_array($item)
                || ($item['itemKind'] ?? null) !== CollectionSnapshotService::ITEM_SEARCH_SUGGESTION
                || !is_string($item['itemKey'] ?? null)
                || !is_array($item['payload'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            $payload = $item['payload'];
            $section = $payload['section'] ?? null;
            $title = $payload['title'] ?? null;
            if (!is_string($section) || !is_string($title) || trim($title) === '' || strlen($title) > 190) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            if ($section === 'PERSON') {
                // An album-scoped legacy overlay cannot infer person
                // membership from a global suggestion card. Returning an
                // empty section is safer than re-reading the full People
                // projection merely to fill an optional typeahead surface.
                if ($legacyAlbumId !== null || !self::contains($title, $query)) {
                    continue;
                }
                $id = str_starts_with((string) $item['itemKey'], 'person-')
                    ? substr((string) $item['itemKey'], strlen('person-'))
                    : '';
                ClassArchivePerson::idToBinary($id);
                $sections['people'][] = [
                    'id' => strtolower($id),
                    'label' => $title,
                    'context' => ['type' => SearchContext::PERSON, 'id' => strtolower($id)],
                ];
                continue;
            }
            if ($section === 'ALBUM') {
                $albumId = $payload['albumId'] ?? null;
                if (!is_string($albumId)) {
                    throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
                }
                ClassArchivePhoto::idToBinary($albumId);
                $albumId = strtolower($albumId);
                if (($legacyAlbumId !== null && !hash_equals($legacyAlbumId, $albumId))
                    || !self::contains($title, $query)
                ) {
                    continue;
                }
                $entry = [
                    'id' => strtolower($albumId),
                    'label' => $title,
                    'context' => ['type' => SearchContext::ALBUM, 'id' => strtolower($albumId)],
                ];
                foreach (['subtitle', 'sourceLabel'] as $field) {
                    if (isset($payload[$field]) && is_string($payload[$field]) && strlen((string) $payload[$field]) <= 190) {
                        $entry[$field] = (string) $payload[$field];
                    }
                }
                $sections['albums'][] = $entry;
                continue;
            }
            if ($section === 'MEMORY') {
                // The memory key is opaque and derives from a durable reason;
                // returning it lets a future overlay set a MEMORY constraint
                // without exposing that private reason or a file path.
                if (preg_match('/\Amemory-[a-f0-9]{56}\z/D', (string) $item['itemKey']) !== 1) {
                    throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
                }
                // The legacy suggestions schema has no Memory section. Keep
                // the durable card private to the snapshot rather than
                // smuggling a new response shape into older callers. The V4
                // grouped route accepts MEMORY explicitly.
                continue;
            }
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        $after = $this->readProjection?->presentationEpoch($scope);
        if (!is_string($after) || !hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_gateway_search_snapshot_changed');
        }
        $result = ['query' => $query];
        foreach ($sections as $name => $sectionItems) {
            // Snapshot ordering is deliberate (curation/rotation); unlike
            // legacy live suggestions it must not be re-sorted by an
            // unavailable context-wide count.
            $result[$name] = [
                'total' => count($sectionItems),
                'items' => array_slice($sectionItems, 0, self::SEARCH_SECTION_MAX),
            ];
        }
        // `snapshot_revision` identifies the immutable candidate list that
        // was actually read.  `presentation_epoch` remains the current
        // request binding and lets a session cache discard this response as
        // soon as the projection changes again.
        $result['snapshot_revision'] = $bundle['snapshotRevision'];
        $result['presentation_epoch'] = $before;
        $result['snapshot_state'] = $bundle['mode'];
        return $result;
    }

    /**
     * SEARCH_SUGGESTION cards deliberately carry no photo ids, so the generic
     * snapshot callback alone cannot re-evaluate their metadata target. Check
     * the opaque PERSON/ALBUM target against the current role-scoped durable
     * projection as well. This remains a bounded projection lookup, never a
     * fallback library traversal; malformed or stale target state aborts the
     * read rather than presenting an unchecked label.
     *
     * @return array<string,mixed>|null
     */
    private function recheckSearchSuggestionSnapshotItem(CollectionSnapshotItem $item): ?array
    {
        $public = $this->recheckCollectionSnapshotItem($item);
        if ($public === null) {
            return null;
        }
        if ($item->itemKind() !== CollectionSnapshotService::ITEM_SEARCH_SUGGESTION) {
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        $payload = $item->payload();
        $section = $payload['section'] ?? null;
        if (!is_string($section)) {
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        if ($section === 'PERSON') {
            $key = $item->itemKey();
            if (!str_starts_with($key, 'person-')) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            $personId = substr($key, strlen('person-'));
            ClassArchivePerson::idToBinary($personId);
            // `personScopedPhotoIds` both proves the target remains visible
            // in the current role scope and rejects a malformed aggregate.
            $this->personScopedPhotoIds(strtolower($personId));
            return $public;
        }
        if ($section === 'ALBUM') {
            $albumId = $payload['albumId'] ?? null;
            if (!is_string($albumId)) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
            DomainSupport::idToBinary($albumId);
            if ($this->albumScopedPhotoIds(strtolower($albumId)) === []) {
                return null;
            }
            return $public;
        }
        if ($section === 'MEMORY') {
            // The old suggestions response intentionally has no Memory
            // section. Suppress it here rather than serializing an unchecked
            // opaque key into a legacy response shape.
            return null;
        }
        throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
    }

    private function searchCursorSigningKey(): string
    {
        $root = $this->timelineCursorRootSecret;
        if ($root === null) {
            $environment = getenv('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET');
            $root = is_string($environment) ? $environment : null;
        }
        if (!is_string($root) || strlen($root) < 32 || strlen($root) > 4096) {
            throw new \RuntimeException('class_archive_gateway_search_cursor_secret_unavailable');
        }
        return hash_hmac('sha256', "class-archive/grouped-search-cursor-signing/v1\0", $root, true);
    }

    private static function encodeGroupedSearchCursor(
        int $offset,
        string $query,
        SearchContext $context,
        string $scope,
        string $cursorSubject,
        string $revision,
        string $signingKey,
    ): string {
        if ($offset <= 0 || $offset > 0xffffffff || strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_search_cursor_invalid');
        }
        $packedOffset = pack('N', $offset);
        $mac = hash_hmac(
            'sha256',
            self::groupedSearchCursorMaterial($packedOffset, $query, $context, $scope, $cursorSubject, $revision),
            $signingKey,
            true,
        );
        return rtrim(strtr(base64_encode($packedOffset . $mac), '+/', '-_'), '=');
    }

    private static function decodeGroupedSearchCursor(
        ?string $cursor,
        string $query,
        SearchContext $context,
        string $scope,
        string $cursorSubject,
        string $revision,
        int $total,
        string $signingKey,
    ): int {
        if ($total < 0 || strlen($signingKey) !== 32) {
            throw new \RuntimeException('class_archive_gateway_search_cursor_invalid');
        }
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_search_cursor_invalid');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || strlen($decoded) !== 36) {
            throw new \InvalidArgumentException('class_archive_gateway_search_cursor_invalid');
        }
        $unpacked = unpack('Noffset', substr($decoded, 0, 4));
        $offset = is_array($unpacked) ? (int) ($unpacked['offset'] ?? 0) : 0;
        $expected = hash_hmac(
            'sha256',
            self::groupedSearchCursorMaterial(substr($decoded, 0, 4), $query, $context, $scope, $cursorSubject, $revision),
            $signingKey,
            true,
        );
        if ($offset <= 0 || $offset >= $total || !hash_equals($expected, substr($decoded, 4))) {
            throw new \InvalidArgumentException('class_archive_gateway_search_cursor_invalid');
        }
        return $offset;
    }

    private static function groupedSearchCursorMaterial(
        string $packedOffset,
        string $query,
        SearchContext $context,
        string $scope,
        string $cursorSubject,
        string $revision,
    ): string {
        if (strlen($packedOffset) !== 4
            || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1
            || !in_array($scope, [ReadProjectionStore::SCOPE_FULL, ReadProjectionStore::SCOPE_HERITAGE], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,95}\z/D', $cursorSubject) !== 1
        ) {
            throw new \RuntimeException('class_archive_gateway_search_cursor_invalid');
        }
        return "class-archive/grouped-search-cursor/v1\0" . $packedOffset . "\0" . $query . "\0"
            . $context->binding() . "\0" . $scope . "\0" . $cursorSubject . "\0" . $revision;
    }

    /** @return array{available:bool,total:int,items:list<array<string,mixed>>} */
    private static function boundedSearchSection(array $items, bool $available = true): array
    {
        if (!array_is_list($items)) {
            throw new \RuntimeException('class_archive_gateway_search_section_invalid');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_gateway_search_section_invalid');
            }
        }
        return [
            'available' => $available,
            'total' => count($items),
            'items' => array_slice($items, 0, self::SEARCH_SECTION_MAX),
        ];
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
            '/api/photos/{id}/media/{thumbnail|xsmall|small|medium|large|preview|original}' => ['method' => 'GET, HEAD', 'evidence' => 'CONTRACT_TESTED'],
            '/api/me' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/memories' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/product-state' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/albums/{id}' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/spotlight' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/search/grouped' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/search/hybrid' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/home' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/state' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/pins' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/people' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/options' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/duplicates' => ['method' => 'GET', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/people/{create|update|merge|visibility|revert-merge|move-photos}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/archive/bulk' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/albums/cover' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/manage/duplicates/consolidate' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/spotlight/{create|cancel}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/pins/{create|remove|reorder}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/feedback/{set|clear}' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/memories/save-as-album' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
            '/api/collections/albums/cover' => ['method' => 'POST', 'evidence' => 'CONTRACT_TESTED'],
        ];
    }

    public static function publiclyBound(): bool
    {
        return true;
    }
}
