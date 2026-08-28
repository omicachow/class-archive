<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\Access;
use ClassIdentity\AlbumService;
use ClassIdentity\AutoCollectionService;
use ClassIdentity\CanonicalPhotoService;
use ClassIdentity\PersonCurationService;
use ClassIdentity\Repository;
use ClassIdentity\SpotlightService;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Build-only identity source for the two policy projection scopes. */
final class ProjectionBuildIdentityAdapter implements IdentityAdapter
{
    public function __construct(private readonly string $role)
    {
    }

    public function currentPrincipal(): ?GatewayPrincipal
    {
        return new GatewayPrincipal($this->role);
    }
}

/**
 * Explicit write/background projection builder.
 *
 * It is never called by a GET path. The generated payloads contain only
 * canonical public photo/person/album identities and are separated into FULL
 * and HERITAGE scopes before they reach MariaDB. Runtime GatewayService still
 * resolves the fresh principal before selecting either scope.
 */
final class ReadProjectionBuilder
{
    private const DEFAULT_KINDS = [
        ReadProjectionStore::TIMELINE,
        ReadProjectionStore::ALBUMS,
        ReadProjectionStore::PEOPLE,
        ReadProjectionStore::MEMORIES,
        ReadProjectionStore::SPOTLIGHT,
    ];

    /**
     * @param list<string> $kinds
     * @return array<string,mixed>
     */
    public static function rebuild(array $kinds = self::DEFAULT_KINDS, bool $rebuildPhotos = true, bool $dryRun = false): array
    {
        $store = ReadProjectionStore::fromPiwigo();
        $photoResult = null;
        if ($rebuildPhotos) {
            $source = PiwigoGatewayAdapter::fromPiwigo();
            // A full catalog generation can add, remove or restrict any
            // photo. All aggregate kinds are therefore invalidated in the
            // catalog publish transaction, regardless of the caller's
            // requested optimization. Point refreshes below remain targeted.
            $lastEpochError = null;
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $buildToken = $store->beginPhotoCatalogBuild();
                $photos = $source->sourcePhotoCandidatesForRebuild();
                try {
                    $photoResult = $store->rebuildPhotos($photos, $dryRun, $buildToken);
                    $lastEpochError = null;
                    break;
                } catch (\RuntimeException $error) {
                    if ($error->getMessage() !== 'class_archive_read_projection_source_epoch_changed') {
                        throw $error;
                    }
                    $lastEpochError = $error;
                }
            }
            if ($lastEpochError !== null || !is_array($photoResult)) {
                throw new \RuntimeException('class_archive_read_projection_source_epoch_unstable', 0, $lastEpochError);
            }
            if (($photoResult['changed'] ?? false) === true) {
                $kinds = self::DEFAULT_KINDS;
            }
            if ($dryRun && ($photoResult['changed'] ?? false) === true) {
                // The candidate catalog is intentionally not published during
                // dry-run, so aggregate builders cannot safely hydrate it.
                // Report the exact aggregate scope that would be rebuilt while
                // leaving every persisted state and generation untouched.
                return [
                    'photos' => $photoResult,
                    'aggregates' => [
                        'changed' => true,
                        'changed_kinds' => array_values(array_unique($kinds)),
                        'dry_run' => true,
                        'deferred_until_catalog_publish' => true,
                    ],
                    'projections' => $store->status(),
                ];
            }
        }
        $aggregateResult = self::rebuildAggregatesWithStore($store, $kinds, $dryRun);
        // Collection snapshots are a separate durable read model. They only
        // materialize after the full role-scoped aggregate generation has
        // committed, and bind to that exact presentation epoch. This keeps
        // GET paths read-only and makes any later aggregate invalidation fail
        // closed at the snapshot revision boundary.
        $collectionSnapshots = self::rebuildCollectionSnapshots($dryRun);
        return [
            'photos' => $photoResult,
            'aggregates' => $aggregateResult,
            'collection_snapshots' => $collectionSnapshots,
            'projections' => $store->status(),
        ];
    }

    /**
     * Incrementally publish the exact photos changed by an archive write, then
     * rebuild only the aggregate kinds declared by the write dependency map.
     * PHOTO_CATALOG and those aggregates must already be STALE.
     *
     * @param list<string> $classPhotoIds
     * @param list<string> $kinds
     * @return array<string,mixed>
     */
    public static function rebuildChangedPhotos(array $classPhotoIds, array $kinds): array
    {
        $store = ReadProjectionStore::fromPiwigo();
        $source = PiwigoGatewayAdapter::fromPiwigo();
        $buildToken = $store->beginPhotoCatalogBuild();
        $photoResult = $store->refreshPhotos(
            $source->sourcePhotoCandidatesByIdsForRebuild($classPhotoIds),
            $kinds,
            $buildToken,
        );
        $aggregateResult = self::rebuildAggregatesWithStore($store, $kinds, false);
        $collectionSnapshots = self::rebuildCollectionSnapshots(false);
        return [
            'photos' => $photoResult,
            'aggregates' => $aggregateResult,
            'collection_snapshots' => $collectionSnapshots,
            'projections' => $store->status(),
        ];
    }

    /** @param list<string> $kinds @return array{changed:bool,changed_kinds:list<string>,dry_run:bool} */
    private static function rebuildAggregatesWithStore(ReadProjectionStore $store, array $kinds, bool $dryRun): array
    {
        if ($kinds === []) {
            return ['changed' => false, 'changed_kinds' => [], 'dry_run' => $dryRun];
        }
        $publishesMemories = in_array(ReadProjectionStore::MEMORIES, $kinds, true);
        if (!$dryRun && $publishesMemories) {
            // Commit an explicit fail-closed barrier before source work. If
            // AutoCollection synchronization or aggregate publication fails,
            // MEMORIES stays STALE and no browser can read a split revision.
            $store->invalidate(
                [ReadProjectionStore::MEMORIES],
                'AUTO_COLLECTION_REBUILD_STARTED',
                false,
            );
        }
        $buildToken = $store->beginAggregateBuild($kinds);
        $piwigo = PiwigoGatewayAdapter::fromPiwigo();
        $immich = BridgeImmichAdapter::configuredOrNull();
        $album = AlbumService::fromPiwigo();
        $people = PersonCurationService::fromPiwigo();
        $spotlight = SpotlightService::fromPiwigo();
        $canonical = CanonicalPhotoService::fromPiwigo();
        $payloads = [
            ReadProjectionStore::SCOPE_FULL => [],
            ReadProjectionStore::SCOPE_HERITAGE => [],
        ];
        $roles = [
            ReadProjectionStore::SCOPE_FULL => Access::ROLE_CLASSMATE,
            ReadProjectionStore::SCOPE_HERITAGE => Access::ROLE_FAMILY,
        ];
        foreach ($roles as $scope => $role) {
            $gateway = new GatewayService(
                new ProjectionBuildIdentityAdapter($role),
                $piwigo,
                $immich,
                new GatewayPolicy(),
                $album,
                $people,
                $spotlight,
                $canonical,
                null,
            );
            foreach ($kinds as $kind) {
                if (!is_string($kind)) {
                    throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid');
                }
                $payloads[$scope][$kind] = $gateway->projectionPayload($kind);
            }
        }
        $beforePublish = null;
        if (!$dryRun && $publishesMemories) {
            $fullMemoryPayload = $payloads[ReadProjectionStore::SCOPE_FULL][ReadProjectionStore::MEMORIES] ?? null;
            if (!is_array($fullMemoryPayload)) {
                throw new \RuntimeException('class_archive_auto_collection_memory_payload_missing');
            }
            // ReadProjectionStore calls this while holding its publish locks
            // in the same Repository transaction. The callback deliberately
            // uses that Repository and never opens a nested transaction.
            $beforePublish = static function (Repository $repository) use ($fullMemoryPayload): array {
                return (new AutoCollectionService($repository))
                    ->syncMemoryProjectionInCurrentTransaction($fullMemoryPayload);
            };
        }
        $result = $store->rebuildAggregates(
            $payloads,
            $kinds,
            $buildToken,
            $dryRun,
            $beforePublish,
        );
        if ($beforePublish !== null) {
            $result['auto_collections'] = $result['pre_publish_result'] ?? null;
            unset($result['pre_publish_result']);
        }
        return $result;
    }

    /**
     * Snapshot failures never make a just-published legacy aggregate unreadable
     * by old routes. The Collections endpoint still rejects a missing/stale
     * pointer through its exact presentation-epoch binding, while callers and
     * maintenance receive a durable, non-sensitive review signal instead of
     * accidentally treating an unavailable snapshot as an empty Collection.
     *
     * @return array<string,mixed>
     */
    private static function rebuildCollectionSnapshots(bool $dryRun): array
    {
        if ($dryRun) {
            return ['result' => 'DEFERRED_DRY_RUN'];
        }
        try {
            return CollectionSnapshotBuilder::rebuild();
        } catch (\Throwable) {
            return [
                'result' => 'REVIEW_REQUIRED',
                'reason' => 'COLLECTION_SNAPSHOT_BUILD_FAILED',
            ];
        }
    }
}
