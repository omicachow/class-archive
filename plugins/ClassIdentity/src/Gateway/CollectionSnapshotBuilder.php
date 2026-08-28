<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\CollectionSnapshotService;
use ClassIdentity\DomainSupport;
use ClassIdentity\SpotlightRotationService;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Explicit, build-only materializer for the Photos App v4 Collections-first
 * surface.
 *
 * CollectionSnapshotService owns immutable storage and pointer swaps.  This
 * class deliberately owns only the deterministic conversion from the already
 * durable Gateway read projections into bounded, public-safe collection
 * cards.  It never walks Piwigo/Immich, never evaluates a browser principal,
 * and is never called from a GET route.
 */
final class CollectionSnapshotBuilder
{
    private const BUILDER_VERSION = 1;
    private const HOME_ALBUM_LIMIT = 12;
    private const HOME_MEMORY_LIMIT = 8;
    private const HOME_RECOMMENDATION_LIMIT = 4;
    private const HOME_PERSON_LIMIT = 12;
    private const HOME_RECENT_PHOTO_LIMIT = 24;
    private const HOME_CARD_PHOTO_LIMIT = 64;
    private const MEMORY_ITEM_LIMIT = 100;
    private const CARD_PHOTO_LIMIT = 64;
    private const SEARCH_SUGGESTION_LIMIT = 16;
    private const MAX_ITEM_PHOTOS = 1000;

    /** @var list<string> */
    private const PROJECTION_KINDS = [
        CollectionSnapshotService::KIND_HOME,
        CollectionSnapshotService::KIND_MEMORY,
        CollectionSnapshotService::KIND_SPOTLIGHT,
        CollectionSnapshotService::KIND_SEARCH_SUGGESTION,
    ];

    /**
     * Rebuild both fixed policy scopes from existing durable Gateway rows.
     * A current `presentationEpoch` is used verbatim as every snapshot input
     * revision. Gateway reads compare that revision with their current epoch,
     * so an aggregate invalidation makes a stale snapshot unavailable rather
     * than a source for a fallback read.
     *
     * @return array{result:string,dryRun:bool,scopes:list<array<string,mixed>>}
     */
    public static function rebuild(bool $dryRun = false): array
    {
        return self::rebuildWith(
            ReadProjectionStore::fromPiwigo(),
            CollectionSnapshotService::fromPiwigo(),
            $dryRun,
            $dryRun ? null : SpotlightRotationService::fromPiwigo(),
        );
    }

    /**
     * Public for the disposable synthetic runtime suite. Runtime callers use
     * rebuild(); injected stores/services never widen the production boundary.
     *
     * @return array{result:string,dryRun:bool,scopes:list<array<string,mixed>>}
     */
    public static function rebuildWith(
        ReadProjectionStore $store,
        CollectionSnapshotService $snapshots,
        bool $dryRun = false,
        ?SpotlightRotationService $spotlightRotation = null,
    ): array {
        $scopeMap = [
            ReadProjectionStore::SCOPE_FULL => CollectionSnapshotService::SCOPE_FULL,
            ReadProjectionStore::SCOPE_HERITAGE => CollectionSnapshotService::SCOPE_HERITAGE_ONLY,
        ];
        $scopesByReadScope = [];
        foreach ($scopeMap as $readScope => $snapshotScope) {
            $scopesByReadScope[$readScope] = self::rebuildScope(
                $store,
                $snapshots,
                $readScope,
                $snapshotScope,
                $dryRun,
                $spotlightRotation,
            );
        }
        // The scopes publish independently by design, but a result may only
        // be reported as PASS if both point at the exact active epochs at the
        // same stable read checkpoint. A source mutation between FULL and
        // HERITAGE publication therefore produces a retryable failure rather
        // than a misleading all-green maintenance result.
        self::assertAllScopesCurrent($store, $scopesByReadScope);
        $result = 'PASS';
        foreach ($scopesByReadScope as $scopeResult) {
            if (($scopeResult['skipped'] ?? null) === 'RUNNING') {
                $result = 'REVIEW_REQUIRED';
                break;
            }
        }
        return ['result' => $result, 'dryRun' => $dryRun, 'scopes' => array_values($scopesByReadScope)];
    }

    /** @param array<string,array<string,mixed>> $scopesByReadScope */
    private static function assertAllScopesCurrent(ReadProjectionStore $store, array $scopesByReadScope): void
    {
        $first = [];
        foreach ([ReadProjectionStore::SCOPE_FULL, ReadProjectionStore::SCOPE_HERITAGE] as $scope) {
            $result = $scopesByReadScope[$scope] ?? null;
            $revision = is_array($result) ? ($result['inputRevision'] ?? null) : null;
            if (!is_string($revision) || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
                throw new \RuntimeException('class_archive_collection_snapshot_scope_result_invalid');
            }
            $epoch = $store->presentationEpoch($scope);
            if (!hash_equals($revision, $epoch)) {
                throw new \RuntimeException('class_archive_collection_snapshot_source_epoch_changed');
            }
            $first[$scope] = $epoch;
        }
        foreach ($first as $scope => $epoch) {
            if (!hash_equals($epoch, $store->presentationEpoch($scope))) {
                throw new \RuntimeException('class_archive_collection_snapshot_source_epoch_changed');
            }
        }
    }

    /**
     * Pure deterministic conversion retained as a focused synthetic seam.
     * The input is the role-scoped durable payload for all aggregate kinds;
     * callers must acquire it from ReadProjectionStore, never a live source.
     *
     * `$spotlightRotationRevision` remains a deterministic test-only fallback
     * when no durable rotation service is injected. Production maintenance
     * always supplies the persisted server-owned `$spotlightOrder`; a browser
     * request can neither choose nor advance that order.
     *
     * @param array<string,array<string,mixed>> $payloads
     * @return array<string,list<array<string,mixed>>>
     */
    public static function buildItemsForPayloads(
        array $payloads,
        ?string $spotlightRotationRevision = null,
        ?array $spotlightOrder = null,
    ): array
    {
        foreach ([
            ReadProjectionStore::TIMELINE,
            ReadProjectionStore::ALBUMS,
            ReadProjectionStore::PEOPLE,
            ReadProjectionStore::MEMORIES,
            ReadProjectionStore::SPOTLIGHT,
        ] as $kind) {
            if (!isset($payloads[$kind]) || !is_array($payloads[$kind])) {
                throw new \RuntimeException('class_archive_collection_snapshot_build_payload_missing');
            }
        }

        $albums = self::albumItems($payloads[ReadProjectionStore::ALBUMS]);
        $people = self::personItems($payloads[ReadProjectionStore::PEOPLE]);
        $memories = self::memoryItems($payloads[ReadProjectionStore::MEMORIES]);
        $spotlight = self::spotlightItems(
            $payloads[ReadProjectionStore::SPOTLIGHT],
            self::normalizeRotationRevision($spotlightRotationRevision),
            $spotlightOrder,
        );
        $recent = self::recentItems($payloads[ReadProjectionStore::TIMELINE]);
        $recommendations = self::recommendationItems($memories);

        $home = array_merge(
            $spotlight,
            $recommendations,
            array_map(static fn(array $item): array => self::boundedItem($item, self::HOME_CARD_PHOTO_LIMIT), array_slice($memories, 0, self::HOME_MEMORY_LIMIT)),
            array_map(static fn(array $item): array => self::boundedItem($item, self::HOME_CARD_PHOTO_LIMIT), array_slice($albums, 0, self::HOME_ALBUM_LIMIT)),
            array_map(static fn(array $item): array => self::boundedItem($item, self::HOME_CARD_PHOTO_LIMIT), array_slice($people, 0, self::HOME_PERSON_LIMIT)),
            $recent,
        );

        $suggestions = self::searchSuggestionItems($albums, $people, $memories);
        $result = [
            CollectionSnapshotService::KIND_HOME => self::assertUniqueItems($home),
            CollectionSnapshotService::KIND_MEMORY => self::assertUniqueItems($memories),
            CollectionSnapshotService::KIND_SPOTLIGHT => self::assertUniqueItems($spotlight),
            CollectionSnapshotService::KIND_SEARCH_SUGGESTION => self::assertUniqueItems($suggestions),
        ];

        foreach (self::PROJECTION_KINDS as $kind) {
            if (!array_key_exists($kind, $result)) {
                throw new \RuntimeException('class_archive_collection_snapshot_build_kind_missing');
            }
        }
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private static function rebuildScope(
        ReadProjectionStore $store,
        CollectionSnapshotService $snapshots,
        string $readScope,
        string $snapshotScope,
        bool $dryRun,
        ?SpotlightRotationService $spotlightRotation,
    ): array {
        $before = $store->presentationEpoch($readScope);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1) {
            throw new \RuntimeException('class_archive_collection_snapshot_build_epoch_invalid');
        }
        $payloads = self::readPayloads($store, $readScope);
        // Build a bounded candidate list from the already durable aggregate
        // before the write-side rotation service sees it.  This is not a live
        // catalog scan and it has no principal/browser input.
        $itemsByKind = self::buildItemsForPayloads($payloads, $before);
        $after = $store->presentationEpoch($readScope);
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_collection_snapshot_source_epoch_changed');
        }
        $rotation = null;
        if (!$dryRun && $spotlightRotation !== null) {
            $candidateIds = self::spotlightCandidateIds($itemsByKind);
            $rotationScope = self::spotlightRotationScope($readScope);
            // Server-owned maintenance advances a due checkpoint.  The
            // following accessor is deliberately query-only and proves the
            // exact persisted candidate digest/order before publication.
            $advancedRotation = $spotlightRotation->advanceForMaintenance($rotationScope, $candidateIds);
            $rotation = $spotlightRotation->stateForPublishedCandidates($rotationScope, $candidateIds);
            if (($advancedRotation['revision'] ?? null) !== ($rotation['revision'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            // stateForPublishedCandidates() is intentionally query-only and
            // therefore reports changed=false; retain the maintenance result
            // solely to decide whether an otherwise-current bundle must be
            // republished with a new persisted hero ordering.
            $rotation['changed'] = ($advancedRotation['changed'] ?? null) === true;
            $orderedIds = $rotation['orderedSpotlightIds'] ?? null;
            if (!is_array($orderedIds) || !array_is_list($orderedIds)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            $itemsByKind = self::buildItemsForPayloads($payloads, $before, $orderedIds);
        }
        // A rotation write is intentionally not part of the source epoch. The
        // second checkpoint catches any concurrent archive/ACL mutation that
        // occurred while the durable hero state was being advanced.
        $after = $store->presentationEpoch($readScope);
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('class_archive_collection_snapshot_source_epoch_changed');
        }
        if ($dryRun) {
            return [
                'scope' => $snapshotScope,
                'inputRevision' => $before,
                'published' => false,
                'dryRun' => true,
                'kinds' => array_map(static fn(array $items): int => count($items), $itemsByKind),
            ];
        }

        $maintenanceKey = self::maintenanceKey($snapshotScope);
        // A durable Spotlight checkpoint can change the visible ordering while
        // the ACL-safe aggregate epoch remains unchanged.  It therefore gets a
        // distinct maintenance watermark, while each published snapshot still
        // binds to the original presentation epoch used by Gateway GETs.
        $maintenanceRevision = $before;
        if (is_array($rotation) && ($rotation['changed'] ?? null) === true) {
            $rotationRevision = $rotation['revision'] ?? null;
            if (!is_string($rotationRevision) || preg_match('/\A[a-f0-9]{64}\z/D', $rotationRevision) !== 1) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            $maintenanceRevision = hash('sha256', "collection-snapshot-rotation-v1\0{$before}\0{$rotationRevision}");
        }
        $claim = $snapshots->claimMaintenance($maintenanceKey, $maintenanceRevision);
        if (($claim['claimed'] ?? null) !== true) {
            $state = (string) ($claim['state'] ?? '');
            if ($state === 'RUNNING') {
                if (self::scopeAlreadyCurrent($snapshots, $snapshotScope, $before)) {
                    return [
                        'scope' => $snapshotScope,
                        'inputRevision' => $before,
                        'published' => false,
                        'skipped' => 'CURRENT',
                        'kinds' => array_map(static fn(array $items): int => count($items), $itemsByKind),
                    ];
                }
                return [
                    'scope' => $snapshotScope,
                    'inputRevision' => $before,
                    'published' => false,
                    'skipped' => 'RUNNING',
                    'kinds' => array_map(static fn(array $items): int => count($items), $itemsByKind),
                ];
            }
            if ($state === 'COMPLETE' && self::scopeAlreadyCurrent($snapshots, $snapshotScope, $before)) {
                return [
                    'scope' => $snapshotScope,
                    'inputRevision' => $before,
                    'published' => false,
                    'skipped' => 'CURRENT',
                    'kinds' => array_map(static fn(array $items): int => count($items), $itemsByKind),
                ];
            }
            // A pointer may have been manually quarantined after a previous
            // COMPLETE maintenance watermark. Claim a distinct maintenance
            // repair token; the snapshots themselves remain bound to the
            // exact presentation epoch, never this internal marker.
            $repairToken = hash('sha256', "collection-snapshot-repair-v" . self::BUILDER_VERSION . "\0" . $before, true);
            $repair = $snapshots->claimMaintenance($maintenanceKey, $repairToken);
            if (($repair['claimed'] ?? null) !== true) {
                throw new \RuntimeException('class_archive_collection_snapshot_maintenance_unavailable');
            }
        }

        $published = [];
        $lastSnapshotId = null;
        try {
            // HOME, MEMORY, SPOTLIGHT and SEARCH_SUGGESTION form one user
            // visible Collections revision.  Publish all four pointer swaps
            // in the same database transaction so a GET cannot observe a
            // mixed Home (new hero with stale suggestions, for example).
            $published = $snapshots->publishBundle($snapshotScope, $before, $itemsByKind);
            foreach (self::PROJECTION_KINDS as $kind) {
                $result = $published[$kind] ?? null;
                if (!is_array($result)) {
                    throw new \RuntimeException('class_archive_collection_snapshot_publish_bundle_invalid');
                }
                $lastSnapshotId = is_string($result['snapshotId'] ?? null) ? $result['snapshotId'] : $lastSnapshotId;
            }
            if (!is_string($lastSnapshotId) || !$snapshots->completeMaintenance($maintenanceKey, $lastSnapshotId)['completed']) {
                throw new \RuntimeException('class_archive_collection_snapshot_maintenance_complete_failed');
            }
        } catch (\Throwable $error) {
            try {
                $snapshots->failMaintenance($maintenanceKey, 'COLLECTION_BUILD_FAILED');
            } catch (\Throwable) {
                // A failure to persist a maintenance marker cannot transform
                // a failed build into a visible response. The previous ACTIVE
                // pointer remains the only candidate and Gateway revision
                // binding will reject it once the source epoch has moved.
            }
            throw $error;
        }

        $finalEpoch = $store->presentationEpoch($readScope);
        if (!hash_equals($before, $finalEpoch)) {
            // The snapshots are intentionally left retained but every GET
            // compares their revision with the new epoch and therefore fails
            // closed until the next explicit builder run.
            throw new \RuntimeException('class_archive_collection_snapshot_source_epoch_changed');
        }
        return [
            'scope' => $snapshotScope,
            'inputRevision' => $before,
            'published' => true,
            'kinds' => $published,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function readPayloads(ReadProjectionStore $store, string $scope): array
    {
        $payloads = [];
        foreach ([
            ReadProjectionStore::TIMELINE,
            ReadProjectionStore::ALBUMS,
            ReadProjectionStore::PEOPLE,
            ReadProjectionStore::MEMORIES,
            ReadProjectionStore::SPOTLIGHT,
        ] as $kind) {
            $payloads[$kind] = $store->aggregate($kind, $scope);
        }
        return $payloads;
    }

    private static function maintenanceKey(string $scope): string
    {
        return match ($scope) {
            CollectionSnapshotService::SCOPE_FULL => 'COLLECTION_SNAPSHOTS_FULL',
            CollectionSnapshotService::SCOPE_HERITAGE_ONLY => 'COLLECTION_SNAPSHOTS_HERITAGE',
            default => throw new \InvalidArgumentException('class_archive_collection_snapshot_scope_invalid'),
        };
    }

    private static function scopeAlreadyCurrent(CollectionSnapshotService $snapshots, string $scope, string $revision): bool
    {
        $state = $snapshots->state($scope);
        $items = $state['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }
        $expected = array_fill_keys(self::PROJECTION_KINDS, true);
        foreach ($items as $item) {
            if (!is_array($item)) {
                return false;
            }
            $kind = $item['projectionKind'] ?? null;
            if (!is_string($kind) || !isset($expected[$kind])) {
                return false;
            }
            if (($item['state'] ?? null) !== 'ACTIVE' || !is_string($item['revision'] ?? null)
                || !hash_equals($revision, (string) $item['revision'])) {
                return false;
            }
            unset($expected[$kind]);
        }
        return $expected === [];
    }

    /** @return list<array<string,mixed>> */
    private static function albumItems(array $projection): array
    {
        $items = self::projectionItems($projection, 'albums');
        $result = [];
        foreach ($items as $item) {
            $albumId = self::uuid($item['id'] ?? null, 'album');
            $photoIds = self::photoIds($item['photo_ids'] ?? null, 'album', true);
            self::assertDeclaredPhotoCount($item['total'] ?? null, $photoIds, 'album');
            $cover = self::memberCover($item['coverPhotoId'] ?? null, $photoIds, 'album');
            $title = self::requiredText(($item['displayAlias'] ?? null) ?: ($item['name'] ?? null), 'album');
            $payload = [
                'section' => 'ALBUM',
                'title' => $title,
                'albumId' => $albumId,
            ];
            self::addOptionalText($payload, 'subtitle', $item['sourceLabel'] ?? null);
            self::addOptionalText($payload, 'sourceLabel', $item['sourceLabel'] ?? null);
            self::addOptionalText($payload, 'eventLabel', $item['eventLabel'] ?? null);
            self::addOptionalText($payload, 'dateLabel', $item['dateLabel'] ?? null);
            $result[] = self::item(
                CollectionSnapshotService::ITEM_ALBUM,
                $albumId,
                $cover,
                self::boundedWithCover($photoIds, $cover, self::CARD_PHOTO_LIMIT),
                $payload,
            );
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function personItems(array $projection): array
    {
        if (($projection['available'] ?? null) !== true) {
            if (($projection['available'] ?? null) === false && ($projection['total'] ?? null) === 0 && ($projection['items'] ?? null) === []) {
                return [];
            }
            throw new \RuntimeException('class_archive_collection_snapshot_people_projection_invalid');
        }
        $items = self::projectionItems($projection, 'people');
        $result = [];
        foreach ($items as $item) {
            $personId = self::uuid($item['id'] ?? null, 'person');
            $photoIds = self::photoIds($item['photo_ids'] ?? null, 'person', true);
            self::assertDeclaredPhotoCount($item['photo_count'] ?? null, $photoIds, 'person');
            $cover = self::memberCover($item['cover_photo_id'] ?? null, $photoIds, 'person');
            $result[] = self::item(
                CollectionSnapshotService::ITEM_PERSON,
                $personId,
                $cover,
                self::boundedWithCover($photoIds, $cover, self::CARD_PHOTO_LIMIT),
                [
                    'section' => 'PERSON',
                    'title' => self::requiredText($item['label'] ?? null, 'person'),
                    'badge' => '人物',
                ],
            );
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function memoryItems(array $projection): array
    {
        if (($projection['available'] ?? null) !== true) {
            if (($projection['available'] ?? null) === false && ($projection['total'] ?? null) === 0 && ($projection['items'] ?? null) === []) {
                return [];
            }
            throw new \RuntimeException('class_archive_collection_snapshot_memory_projection_invalid');
        }
        $items = self::projectionItems($projection, 'memories');
        $result = [];
        foreach (array_slice($items, 0, self::MEMORY_ITEM_LIMIT) as $item) {
            $sourceReason = $item['source_reason'] ?? null;
            if (!is_string($sourceReason) || preg_match('/\A[A-Z][A-Za-z0-9_:-]{1,63}\z/D', $sourceReason) !== 1) {
                throw new \RuntimeException('class_archive_collection_snapshot_memory_reason_invalid');
            }
            $photoIds = self::photoIds($item['photo_ids'] ?? null, 'memory', true);
            self::assertDeclaredPhotoCount($item['photo_count'] ?? null, $photoIds, 'memory');
            $cover = self::memberCover($item['cover_photo_id'] ?? null, $photoIds, 'memory');
            $key = 'memory-' . substr(hash('sha256', $sourceReason), 0, 56);
            $payload = [
                'section' => 'MEMORY',
                'title' => self::requiredText($item['label'] ?? null, 'memory'),
                'badge' => '回忆',
            ];
            self::addOptionalText($payload, 'subtitle', $item['subtitle'] ?? null);
            $result[] = self::item(
                CollectionSnapshotService::ITEM_AUTO_COLLECTION,
                $key,
                $cover,
                self::boundedWithCover($photoIds, $cover, self::CARD_PHOTO_LIMIT),
                $payload,
            );
        }
        return $result;
    }

    /**
     * "值得再看" is a distinct Home row, not an alias for the complete
     * Memory projection. It is derived only from its already durable,
     * role-scoped cards and gets its own opaque stable item key so pins and
     * feedback never collide with the underlying Memory card.
     *
     * @param list<array<string,mixed>> $memories
     * @return list<array<string,mixed>>
     */
    private static function recommendationItems(array $memories): array
    {
        $result = [];
        foreach (array_slice($memories, 0, self::HOME_RECOMMENDATION_LIMIT) as $memory) {
            $key = $memory['itemKey'] ?? null;
            $cover = $memory['coverPhotoId'] ?? null;
            $photoIds = $memory['photoIds'] ?? null;
            $payload = $memory['payload'] ?? null;
            if (!is_string($key) || !is_string($cover) || !is_array($photoIds) || !is_array($payload)) {
                throw new \RuntimeException('class_archive_collection_snapshot_recommendation_invalid');
            }
            $recommendation = [
                'section' => 'RECOMMENDATION',
                'title' => '值得再看',
                'badge' => '值得再看',
            ];
            self::addOptionalText($recommendation, 'subtitle', $payload['title'] ?? null);
            self::addOptionalText($recommendation, 'dateLabel', $payload['dateLabel'] ?? null);
            $result[] = self::item(
                CollectionSnapshotService::ITEM_AUTO_COLLECTION,
                'recommendation-' . substr(hash('sha256', $key), 0, 56),
                $cover,
                self::boundedWithCover(self::photoIds($photoIds, 'recommendation', true), $cover, self::HOME_CARD_PHOTO_LIMIT),
                $recommendation,
            );
        }
        return $result;
    }

    /**
     * @param list<string>|null $persistedOrder
     * @return list<array<string,mixed>>
     */
    private static function spotlightItems(array $projection, string $rotationRevision, ?array $persistedOrder = null): array
    {
        $active = $projection['active'] ?? null;
        $total = $projection['total'] ?? null;
        $legacyItem = $projection['item'] ?? null;
        $items = $projection['items'] ?? null;
        if ($active === false && $total === 0 && $legacyItem === null && ($items === null || $items === [])) {
            return [];
        }
        if ($active !== true || !is_int($total) || $total < 1 || $total > self::MAX_ITEM_PHOTOS) {
            throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_invalid');
        }
        if ($items !== null) {
            if (!is_array($items) || !array_is_list($items) || count($items) !== $total
                || ($legacyItem !== null && !is_array($legacyItem))) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_invalid');
            }
            $records = $items;
        } elseif ($total === 1 && is_array($legacyItem)) {
            // Compatibility for the v3 durable aggregate. The builder is
            // already able to consume v4's `{items:[...]}` once its producer
            // publishes it, without treating a legacy singleton as a reason
            // to reduce the public snapshot contract.
            $records = [$legacyItem];
        } else {
            throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_invalid');
        }

        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_invalid');
            }
            $spotlightId = self::uuid($record['id'] ?? null, 'spotlight');
            if (isset($normalized[$spotlightId])) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_duplicate');
            }
            $albumId = self::uuid($record['albumId'] ?? null, 'spotlight_album');
            $cover = self::uuid($record['coverPhotoId'] ?? null, 'spotlight_cover');
            $payload = [
                'section' => 'SPOTLIGHT',
                'title' => self::requiredText($record['albumName'] ?? null, 'spotlight'),
                'badge' => '精选',
                'albumId' => $albumId,
            ];
            self::addOptionalText($payload, 'subtitle', $record['description'] ?? null);
            $normalized[$spotlightId] = self::item(
                CollectionSnapshotService::ITEM_SPOTLIGHT,
                $spotlightId,
                $cover,
                [$cover],
                $payload,
            );
        }
        if (is_array($legacyItem) && !isset($normalized[self::uuid($legacyItem['id'] ?? null, 'spotlight_legacy')])) {
            // A compatibility `item` may remain for old routes, but it must
            // describe a card in the complete v4 list rather than becoming an
            // extra, unscoped public card.
            throw new \RuntimeException('class_archive_collection_snapshot_spotlight_projection_invalid');
        }
        ksort($normalized, SORT_STRING);
        if ($persistedOrder !== null) {
            if (!array_is_list($persistedOrder) || count($persistedOrder) !== count($normalized)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            $seen = [];
            $ordered = [];
            foreach ($persistedOrder as $spotlightId) {
                if (!is_string($spotlightId) || isset($seen[$spotlightId]) || !isset($normalized[$spotlightId])) {
                    throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
                }
                $seen[$spotlightId] = true;
                $ordered[] = $normalized[$spotlightId];
            }
            if (count($seen) !== count($normalized)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            return $ordered;
        }
        $ordered = array_values($normalized);
        $offset = self::rotationOffset($rotationRevision, count($ordered));
        // Do not discard simultaneous active cards: a cyclical ordering makes
        // the leading "今日精选" fair while the complete active set remains
        // available to a horizontal card row.
        return $offset === 0
            ? $ordered
            : array_merge(array_slice($ordered, $offset), array_slice($ordered, 0, $offset));
    }

    /**
     * The build input has already passed spotlightItems() validation.  These
     * opaque ids feed only the server-side rotation state; no principal or
     * media identifier enters that state.
     *
     * @param array<string,list<array<string,mixed>>> $itemsByKind
     * @return list<string>
     */
    private static function spotlightCandidateIds(array $itemsByKind): array
    {
        $items = $itemsByKind[CollectionSnapshotService::KIND_SPOTLIGHT] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
        }
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['itemKey'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            $id = self::uuid($item['itemKey'], 'spotlight_rotation');
            if (isset($ids[$id])) {
                throw new \RuntimeException('class_archive_collection_snapshot_spotlight_rotation_invalid');
            }
            $ids[$id] = true;
        }
        return array_keys($ids);
    }

    private static function spotlightRotationScope(string $readScope): string
    {
        return match ($readScope) {
            ReadProjectionStore::SCOPE_FULL => SpotlightRotationService::SCOPE_FULL,
            ReadProjectionStore::SCOPE_HERITAGE => SpotlightRotationService::SCOPE_HERITAGE,
            default => throw new \InvalidArgumentException('class_archive_collection_snapshot_scope_invalid'),
        };
    }

    /** @return list<array<string,mixed>> */
    private static function recentItems(array $projection): array
    {
        $groups = $projection['groups'] ?? null;
        $total = $projection['total'] ?? null;
        if (!is_int($total) || $total < 0 || !is_array($groups) || !array_is_list($groups)) {
            throw new \RuntimeException('class_archive_collection_snapshot_timeline_projection_invalid');
        }
        if ($groups === []) {
            return [];
        }
        $first = $groups[0] ?? null;
        if (!is_array($first)) {
            throw new \RuntimeException('class_archive_collection_snapshot_timeline_projection_invalid');
        }
        $rawItems = $first['items'] ?? null;
        if (!is_array($rawItems) || !array_is_list($rawItems) || $rawItems === []) {
            throw new \RuntimeException('class_archive_collection_snapshot_timeline_projection_invalid');
        }
        $ids = [];
        foreach ($rawItems as $photo) {
            if (!is_array($photo)) {
                throw new \RuntimeException('class_archive_collection_snapshot_timeline_projection_invalid');
            }
            $ids[] = self::uuid($photo['id'] ?? null, 'timeline_photo');
        }
        $ids = self::photoIds($ids, 'timeline', true);
        $cover = $ids[0];
        $payload = [
            'section' => 'RECENT',
            'title' => '最近整理',
        ];
        self::addOptionalText($payload, 'dateLabel', $first['label'] ?? null);
        return [self::item(
            CollectionSnapshotService::ITEM_PHOTO,
            'recent',
            $cover,
            self::boundedWithCover($ids, $cover, self::HOME_RECENT_PHOTO_LIMIT),
            $payload,
        )];
    }

    /**
     * Search suggestions are deliberately metadata-only. A query result still
     * goes through the existing policy-filtered search endpoint; these cards
     * never contain photo ids, counts, media identifiers or hidden sources.
     *
     * @param list<array<string,mixed>> $albums
     * @param list<array<string,mixed>> $people
     * @param list<array<string,mixed>> $memories
     * @return list<array<string,mixed>>
     */
    private static function searchSuggestionItems(array $albums, array $people, array $memories): array
    {
        $result = [];
        foreach (array_slice($people, 0, self::SEARCH_SUGGESTION_LIMIT) as $item) {
            $payload = $item['payload'] ?? null;
            $personKey = $item['itemKey'] ?? null;
            if (!is_array($payload) || !is_string($personKey)) {
                throw new \RuntimeException('class_archive_collection_snapshot_search_person_invalid');
            }
            self::uuid($personKey, 'search_person');
            $result[] = self::item(
                CollectionSnapshotService::ITEM_SEARCH_SUGGESTION,
                'person-' . $personKey,
                null,
                [],
                [
                    'section' => 'PERSON',
                    'title' => self::requiredText($payload['title'] ?? null, 'search_person'),
                ],
            );
        }
        foreach (array_slice($albums, 0, self::SEARCH_SUGGESTION_LIMIT) as $item) {
            $payload = $item['payload'] ?? null;
            if (!is_array($payload) || !is_string($payload['albumId'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_search_album_invalid');
            }
            $suggestion = [
                'section' => 'ALBUM',
                'title' => self::requiredText($payload['title'] ?? null, 'search_album'),
                'albumId' => (string) $payload['albumId'],
            ];
            self::addOptionalText($suggestion, 'subtitle', $payload['subtitle'] ?? null);
            self::addOptionalText($suggestion, 'sourceLabel', $payload['sourceLabel'] ?? null);
            $result[] = self::item(
                CollectionSnapshotService::ITEM_SEARCH_SUGGESTION,
                'album-' . (string) $payload['albumId'],
                null,
                [],
                $suggestion,
            );
        }
        foreach (array_slice($memories, 0, self::SEARCH_SUGGESTION_LIMIT) as $item) {
            $payload = $item['payload'] ?? null;
            $key = $item['itemKey'] ?? null;
            if (!is_array($payload) || !is_string($key)) {
                throw new \RuntimeException('class_archive_collection_snapshot_search_memory_invalid');
            }
            $result[] = self::item(
                CollectionSnapshotService::ITEM_SEARCH_SUGGESTION,
                'memory-' . substr(hash('sha256', $key), 0, 56),
                null,
                [],
                [
                    'section' => 'MEMORY',
                    'title' => self::requiredText($payload['title'] ?? null, 'search_memory'),
                ],
            );
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function projectionItems(array $projection, string $label): array
    {
        $total = $projection['total'] ?? null;
        $items = $projection['items'] ?? null;
        if (!is_int($total) || $total < 0 || !is_array($items) || !array_is_list($items) || $total !== count($items)) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_projection_invalid');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_projection_invalid');
            }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private static function item(string $kind, string $key, ?string $cover, array $photoIds, array $payload): array
    {
        self::assertPublicPayload($payload);
        return [
            'itemKind' => $kind,
            'itemKey' => $key,
            'coverPhotoId' => $cover,
            'photoIds' => $photoIds,
            'payload' => $payload,
        ];
    }

    /**
     * Public cards intentionally have a small, business-language-only
     * payload. Canonical item keys carry the opaque navigation handle; no
     * principal, account, classmate, person, or storage identifier is copied
     * into payload JSON.
     *
     * @param array<string,mixed> $payload
     */
    private static function assertPublicPayload(array $payload): void
    {
        $allowed = [
            'section' => true,
            'title' => true,
            'subtitle' => true,
            'badge' => true,
            'sourceLabel' => true,
            'eventLabel' => true,
            'dateLabel' => true,
            // Album is a public content route, not a ClassIdentity binding.
            // The PERSON route deliberately uses itemKey only.
            'albumId' => true,
        ];
        $sections = [
            'SPOTLIGHT' => true,
            'RECOMMENDATION' => true,
            'MEMORY' => true,
            'PINNED' => true,
            'ALBUM' => true,
            'PERSON' => true,
            'RECENT' => true,
        ];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key]) || !is_string($value)) {
                throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
            }
        }
        if (!isset($sections[$payload['section'] ?? '']) || !isset($payload['title'])) {
            throw new \RuntimeException('class_archive_collection_snapshot_payload_invalid');
        }
        if (isset($payload['albumId'])) {
            self::uuid($payload['albumId'], 'payload_album');
        }
    }

    /** @param list<string> $photoIds @return list<string> */
    private static function boundedWithCover(array $photoIds, string $cover, int $limit = self::MAX_ITEM_PHOTOS): array
    {
        if ($limit < 1 || $limit > self::MAX_ITEM_PHOTOS) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_photo_limit_invalid');
        }
        $bounded = [$cover];
        foreach ($photoIds as $photoId) {
            if ($photoId === $cover) {
                continue;
            }
            if (count($bounded) >= $limit) {
                break;
            }
            $bounded[] = $photoId;
        }
        return $bounded;
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private static function boundedItem(array $item, int $limit): array
    {
        $photoIds = $item['photoIds'] ?? null;
        $cover = $item['coverPhotoId'] ?? null;
        if (!is_array($photoIds) || !is_string($cover)) {
            throw new \RuntimeException('class_archive_collection_snapshot_build_item_invalid');
        }
        $item['photoIds'] = self::boundedWithCover($photoIds, $cover, $limit);
        return $item;
    }

    /** @param mixed $value @return list<string> */
    private static function photoIds(mixed $value, string $label, bool $required): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_photo_ids_invalid');
        }
        if (($required && $value === []) || count($value) > 10000) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_photo_ids_invalid');
        }
        $result = [];
        $seen = [];
        foreach ($value as $photoId) {
            $id = self::uuid($photoId, $label . '_photo');
            if (isset($seen[$id])) {
                throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_photo_ids_duplicate');
            }
            $seen[$id] = true;
            $result[] = $id;
        }
        return $result;
    }

    /** @param list<string> $photoIds */
    private static function memberCover(mixed $value, array $photoIds, string $label): string
    {
        $candidate = $value === null ? null : self::uuid($value, $label . '_cover');
        if ($candidate !== null && in_array($candidate, $photoIds, true)) {
            return $candidate;
        }
        if ($photoIds === []) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_cover_invalid');
        }
        return $photoIds[0];
    }

    /** @param list<string> $photoIds */
    private static function assertDeclaredPhotoCount(mixed $value, array $photoIds, string $label): void
    {
        if (!is_int($value) || $value < 1 || $value !== count($photoIds)) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_count_invalid');
        }
    }

    private static function normalizeRotationRevision(?string $value): string
    {
        if ($value === null) {
            return str_repeat('0', 64);
        }
        $value = strtolower($value);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_rotation_revision_invalid');
        }
        return $value;
    }

    private static function rotationOffset(string $revision, int $count): int
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_rotation_count_invalid');
        }
        // Iterative modulo avoids integer-width differences between 32-bit
        // and 64-bit PHP. Consecutive hexadecimal epoch values therefore
        // produce the exact 0,1,...,N-1 cyclic lead order for N active cards.
        $remainder = 0;
        foreach (str_split($revision) as $digit) {
            $value = hexdec($digit);
            $remainder = (($remainder * 16) + $value) % $count;
        }
        return $remainder;
    }

    private static function uuid(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_id_invalid');
        }
        try {
            return strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($value)));
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_id_invalid', 0, $error);
        }
    }

    private static function requiredText(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_text_invalid');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 512 || str_contains($value, "\0")) {
            throw new \RuntimeException('class_archive_collection_snapshot_' . $label . '_text_invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private static function addOptionalText(array &$payload, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $payload[$key] = self::requiredText($value, $key);
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function assertUniqueItems(array $items): array
    {
        if (count($items) > 1000) {
            throw new \RuntimeException('class_archive_collection_snapshot_build_item_limit');
        }
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['itemKind'] ?? null) || !is_string($item['itemKey'] ?? null)) {
                throw new \RuntimeException('class_archive_collection_snapshot_build_item_invalid');
            }
            $key = $item['itemKind'] . ':' . $item['itemKey'];
            if (isset($seen[$key])) {
                throw new \RuntimeException('class_archive_collection_snapshot_build_item_duplicate');
            }
            $seen[$key] = true;
        }
        return $items;
    }
}
