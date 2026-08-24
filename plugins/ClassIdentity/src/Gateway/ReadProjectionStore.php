<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\DomainSupport;
use ClassIdentity\Repository;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Durable, authorization-neutral Gateway read model.
 *
 * Only source candidates and dependency freshness are persisted. Principal,
 * Seat, role and ALLOW/DENY outcomes are intentionally absent: GatewayPolicy
 * still evaluates the current authenticated principal on every request.
 */
final class ReadProjectionStore
{
    public const PHOTO_CATALOG = 'PHOTO_CATALOG';
    public const TIMELINE = 'TIMELINE';
    public const ALBUMS = 'ALBUMS';
    public const PEOPLE = 'PEOPLE';
    public const MEMORIES = 'MEMORIES';
    public const SPOTLIGHT = 'SPOTLIGHT';
    public const SCOPE_FULL = 'FULL';
    public const SCOPE_HERITAGE = 'HERITAGE';

    private const NATIVE_SOURCE_KEY = 'PIWIGO_NATIVE';

    private const AGGREGATE_VERSION = 3;
    private const AGGREGATE_KINDS = [
        self::TIMELINE,
        self::ALBUMS,
        self::PEOPLE,
        self::MEMORIES,
        self::SPOTLIGHT,
    ];
    private const SCOPES = [self::SCOPE_FULL, self::SCOPE_HERITAGE];

    private const KINDS = [
        self::PHOTO_CATALOG,
        self::TIMELINE,
        self::ALBUMS,
        self::PEOPLE,
        self::MEMORIES,
        self::SPOTLIGHT,
    ];

    public function __construct(
        private readonly Repository $repository,
        private readonly ?\Closure $beforeFinalReadValidation = null,
    )
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return list<array{kind:string,state:string,count:int,built_at:?string,reason:?string}> */
    public function status(): array
    {
        $nativeEpoch = $this->nativeSourceEpochBinary();
        $rows = $this->repository->fetchAll(
            'SELECT `projection_key`,`state`,`item_count`,`built_at`,`invalidated_reason`,`native_source_generation` FROM `'
                . $this->repository->table('read_projection') . '` ORDER BY `projection_key`',
        );
        $nativeMismatch = false;
        foreach ($rows as $row) {
            if (($row['projection_key'] ?? null) === self::PHOTO_CATALOG
                && ($row['state'] ?? null) === 'ACTIVE'
                && (!is_string($row['native_source_generation'] ?? null)
                    || !hash_equals($nativeEpoch, (string) $row['native_source_generation']))
            ) {
                $nativeMismatch = true;
                break;
            }
        }
        $items = [];
        foreach ($rows as $row) {
            $kind = (string) ($row['projection_key'] ?? '');
            $state = (string) ($row['state'] ?? '');
            if (!in_array($kind, self::KINDS, true) || !in_array($state, ['ACTIVE', 'STALE', 'BUILDING', 'FAILED'], true)) {
                throw new \RuntimeException('class_archive_read_projection_status_invalid');
            }
            $effectivelyStale = $nativeMismatch && in_array($kind, [self::PHOTO_CATALOG, ...self::AGGREGATE_KINDS], true);
            $items[] = [
                'kind' => $kind,
                'state' => $effectivelyStale ? 'STALE' : $state,
                'count' => (int) ($row['item_count'] ?? 0),
                'built_at' => is_string($row['built_at'] ?? null) ? $row['built_at'] : null,
                'reason' => $effectivelyStale
                    ? 'NATIVE_SOURCE_EPOCH_MISMATCH'
                    : (is_string($row['invalidated_reason'] ?? null) ? $row['invalidated_reason'] : null),
            ];
        }
        if (count($items) !== count(self::KINDS)) {
            throw new \RuntimeException('class_archive_read_projection_status_incomplete');
        }
        return $items;
    }

    /** @return list<GatewayPhotoCandidate> */
    public function photos(): array
    {
        $state = $this->activeCatalogState();
        $generation = (string) $state['generation'];
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`payload_json`,`row_digest` FROM `' . $this->repository->table('read_photo') . '` '
                . 'WHERE `generation`=? ORDER BY `class_photo_id`',
            [$generation],
        );
        if (count($rows) !== (int) $state['item_count']) {
            throw new \RuntimeException('class_archive_read_projection_incomplete');
        }
        $photos = [];
        $catalogParts = [];
        foreach ($rows as $row) {
            $photos[] = $this->decodePhotoRow($row);
            $catalogParts[] = (string) $row['class_photo_id'] . (string) $row['row_digest'];
        }
        $actual = hash('sha256', implode('', $catalogParts), true);
        if (!hash_equals((string) $state['source_revision'], $actual)) {
            throw new \RuntimeException('class_archive_read_projection_digest_mismatch');
        }
        $this->invokeReadValidationHook(self::PHOTO_CATALOG . ':photos');
        $this->assertCatalogStateCurrent($state);
        return $photos;
    }

    public function photo(string $classPhotoId): ?GatewayPhotoCandidate
    {
        $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
        $state = $this->activeCatalogState();
        $row = $this->repository->fetchOne(
            'SELECT `class_photo_id`,`payload_json`,`row_digest` FROM `' . $this->repository->table('read_photo') . '` '
                . 'WHERE `class_photo_id`=? AND `generation`=? LIMIT 1',
            [$binaryId, (string) $state['generation']],
        );
        $photo = $row === null ? null : $this->decodePhotoRow($row);
        $this->invokeReadValidationHook(self::PHOTO_CATALOG . ':photo');
        $this->assertCatalogStateCurrent($state);
        return $photo;
    }

    /**
     * Bounded batch lookup for an already-authorized aggregate membership.
     * The caller must still run current-principal policy over the returned
     * candidates. Missing rows are returned as missing; they are never filled
     * by a live Piwigo scan.
     *
     * @param list<string> $classPhotoIds
     * @return list<GatewayPhotoCandidate>
     */
    public function photosByIds(array $classPhotoIds): array
    {
        if ($classPhotoIds === []) {
            return [];
        }
        if (count($classPhotoIds) > 10000) {
            throw new \InvalidArgumentException('class_archive_read_projection_batch_too_large');
        }
        $binaryIds = [];
        $order = [];
        foreach ($classPhotoIds as $index => $classPhotoId) {
            if (!is_string($classPhotoId)) {
                throw new \InvalidArgumentException('class_archive_read_projection_id_invalid');
            }
            $normalized = strtolower($classPhotoId);
            if (isset($order[$normalized])) {
                throw new \InvalidArgumentException('class_archive_read_projection_id_duplicate');
            }
            $order[$normalized] = $index;
            $binaryIds[] = ClassArchivePhoto::idToBinary($normalized);
        }
        $state = $this->activeCatalogState();
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`payload_json`,`row_digest` FROM `' . $this->repository->table('read_photo') . '` '
                . 'WHERE `generation`=? AND `class_photo_id` IN (' . implode(',', array_fill(0, count($binaryIds), '?')) . ')',
            array_merge([(string) $state['generation']], $binaryIds),
        );
        $found = [];
        foreach ($rows as $row) {
            $candidate = $this->decodePhotoRow($row);
            $found[$candidate->id()] = $candidate;
        }
        $result = [];
        foreach (array_keys($order) as $id) {
            if (isset($found[$id])) {
                $result[] = $found[$id];
            }
        }
        $this->invokeReadValidationHook(self::PHOTO_CATALOG . ':photosByIds');
        $this->assertCatalogStateCurrent($state);
        return $result;
    }

    /**
     * Capture every projection source epoch before the caller scans Piwigo.
     * Native Core writes rotate the durable Piwigo source epoch (and the
     * legacy guarded projection rows), while ClassIdentity dependency writes
     * rotate at least one aggregate epoch. Comparing the complete token in the
     * publish transaction prevents an older source snapshot from overwriting a
     * newer fail-closed invalidation.
     *
     * @return array{catalog_generation:string,native_source_epoch:string,aggregate_epochs:array<string,string>}
     */
    public function beginPhotoCatalogBuild(): array
    {
        return $this->repository->transaction(function (): array {
            $table = '`' . $this->repository->table('read_projection') . '`';
            $rows = $this->repository->fetchAll(
                "SELECT `projection_key`,`state`,`generation` FROM {$table} WHERE `projection_key` IN "
                    . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key` FOR UPDATE",
            );
            return $this->photoBuildTokenFromRows($rows, $this->nativeSourceEpochBinary());
        });
    }

    /**
     * @param list<GatewayPhotoCandidate> $photos
     * @param array{catalog_generation:string,native_source_epoch:string,aggregate_epochs:array<string,string>} $buildToken
     * @return array{changed:bool,count:int,source_revision:string,dry_run:bool}
     */
    public function rebuildPhotos(
        array $photos,
        bool $dryRun = false,
        array $buildToken = [],
    ): array
    {
        $this->assertPhotoBuildTokenShape($buildToken);
        // A generation swap can add, remove or restrict any photo, so every
        // derived aggregate is an affected dependency. Selective refresh is
        // available only through refreshPhotos(), whose bounded write set and
        // explicit field-to-kind map can be proven.
        $normalizedAffectedKinds = array_fill_keys(self::AGGREGATE_KINDS, true);
        $encoded = [];
        $seenIds = [];
        $seenImages = [];
        foreach ($photos as $photo) {
            if (!$photo instanceof GatewayPhotoCandidate) {
                throw new \InvalidArgumentException('class_archive_read_projection_candidate_invalid');
            }
            $payload = $photo->readModelProjection();
            $id = $photo->id();
            $imageId = $photo->piwigoImageIdForDelivery();
            if (isset($seenIds[$id]) || isset($seenImages[$imageId])) {
                throw new \RuntimeException('class_archive_read_projection_source_duplicate');
            }
            $seenIds[$id] = true;
            $seenImages[$imageId] = true;
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $rowDigest = hash('sha256', $json, true);
            $encoded[] = [
                'id' => ClassArchivePhoto::idToBinary($id),
                'image_id' => $imageId,
                'era' => (string) $photo->era(),
                'payload' => $json,
                'row_digest' => $rowDigest,
            ];
        }
        usort($encoded, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        $revision = hash('sha256', implode('', array_map(
            static fn(array $row): string => $row['id'] . $row['row_digest'],
            $encoded,
        )), true);
        $current = $this->repository->fetchOne(
            'SELECT `state`,`source_revision`,`native_source_generation`,`item_count` FROM `' . $this->repository->table('read_projection') . '` '
                . 'WHERE `projection_key`=? LIMIT 1',
            [self::PHOTO_CATALOG],
        );
        $changed = $current === null
            || ($current['state'] ?? null) !== 'ACTIVE'
            || !is_string($current['source_revision'] ?? null)
            || !hash_equals((string) $current['source_revision'], $revision)
            || !is_string($current['native_source_generation'] ?? null)
            || !hash_equals((string) $current['native_source_generation'], (string) hex2bin($buildToken['native_source_epoch']))
            || (int) ($current['item_count'] ?? -1) !== count($encoded);
        if (!$changed) {
            try {
                // Do not let a superficially matching meta row preserve a
                // truncated or corrupted generation forever.
                $changed = count($this->photos()) !== count($encoded);
            } catch (\Throwable) {
                $changed = true;
            }
        }
        $result = [
            'changed' => $changed,
            'count' => count($encoded),
            'source_revision' => bin2hex($revision),
            'dry_run' => $dryRun,
        ];
        if ($dryRun) {
            $this->assertPhotoBuildTokenCurrent($buildToken);
            return $result;
        }
        if (!$changed) {
            $this->assertPhotoBuildTokenCurrent($buildToken);
            return $result;
        }

        $generation = DomainSupport::idToBinary(DomainSupport::generateId());
        $this->repository->transaction(function () use (
            $encoded,
            $generation,
            $revision,
            $normalizedAffectedKinds,
            $buildToken,
        ): void {
            $meta = '`' . $this->repository->table('read_projection') . '`';
            $rows = '`' . $this->repository->table('read_photo') . '`';
            $locked = $this->repository->fetchAll(
                "SELECT `projection_key`,`state`,`generation` FROM {$meta} WHERE `projection_key` IN "
                    . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key` FOR UPDATE",
            );
            $this->assertPhotoBuildTokenMatchesRows($buildToken, $locked);
            $building = $this->repository->execute(
                "UPDATE {$meta} SET `state`='BUILDING',`generation`=?,`invalidated_reason`=NULL,`updated_at`=UTC_TIMESTAMP(6) "
                    . 'WHERE `projection_key`=? AND `generation`=?',
                [$generation, self::PHOTO_CATALOG, (string) hex2bin($buildToken['catalog_generation'])],
            );
            if ($building !== 1) {
                throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
            }
            $this->repository->execute("DELETE FROM {$rows}");
            foreach ($encoded as $row) {
                $this->repository->execute(
                    "INSERT INTO {$rows} (`class_photo_id`,`piwigo_image_id`,`era`,`payload_json`,`row_digest`,`generation`,`built_at`) "
                        . 'VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))',
                    [$row['id'], $row['image_id'], $row['era'], $row['payload'], $row['row_digest'], $generation],
                );
            }
            // Publish the source generation only after every dependent
            // aggregate has become unavailable in this same transaction.
            // A process exit can therefore expose either the previous
            // catalog with its aggregates, or the new catalog with STALE
            // aggregates, never a mixed ACTIVE generation.
            if ($normalizedAffectedKinds !== []) {
                $this->invalidateRowsInCurrentTransaction(
                    array_keys($normalizedAffectedKinds),
                    'PHOTO_CATALOG_CHANGED',
                );
            }
            $updated = $this->repository->execute(
                "UPDATE {$meta} SET `state`='ACTIVE',`source_revision`=?,`native_source_generation`=?,`item_count`=?,`invalidated_reason`=NULL,"
                    . '`built_at`=UTC_TIMESTAMP(6),`invalidated_at`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `projection_key`=? AND `generation`=?',
                [$revision, (string) hex2bin($buildToken['native_source_epoch']), count($encoded), self::PHOTO_CATALOG, $generation],
            );
            if ($updated !== 1) {
                throw new \RuntimeException('class_archive_read_projection_publish_race');
            }
        });
        return $result;
    }

    /**
     * Publish a bounded archive write set without deleting or rebuilding the
     * durable photo catalog. The caller must have marked PHOTO_CATALOG and the
     * explicitly affected aggregates STALE before changing the source state.
     * A failed point lookup/update therefore leaves every relevant read path
     * fail-closed, while unrelated aggregate kinds remain available.
     *
     * The catalog generation is deliberately retained: all untouched rows
     * continue to belong to the same atomic catalog. The transaction updates
     * only the selected rows, recomputes the authorization-neutral catalog
     * digest from stored row digests, and republishes the meta row last.
     *
     * @param list<GatewayPhotoCandidate> $photos
     * @param list<string> $affectedAggregateKinds
     * @param array{catalog_generation:string,native_source_epoch:string,aggregate_epochs:array<string,string>} $buildToken
     * @return array{changed:bool,updated:int,count:int,source_revision:string,generation:string}
     */
    public function refreshPhotos(array $photos, array $affectedAggregateKinds, array $buildToken = []): array
    {
        $this->assertPhotoBuildTokenShape($buildToken);
        if ($photos === [] || count($photos) > 500) {
            throw new \InvalidArgumentException('class_archive_read_projection_refresh_size_invalid');
        }
        $normalizedKinds = [];
        foreach ($affectedAggregateKinds as $kind) {
            if (!is_string($kind)) {
                throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid');
            }
            $this->assertAggregateKind($kind);
            $normalizedKinds[$kind] = true;
        }
        if ($normalizedKinds === []) {
            throw new \InvalidArgumentException('class_archive_read_projection_refresh_dependencies_missing');
        }
        $encoded = [];
        $seenIds = [];
        $seenImages = [];
        foreach ($photos as $photo) {
            if (!$photo instanceof GatewayPhotoCandidate) {
                throw new \InvalidArgumentException('class_archive_read_projection_candidate_invalid');
            }
            $id = $photo->id();
            $imageId = $photo->piwigoImageIdForDelivery();
            if (isset($seenIds[$id]) || isset($seenImages[$imageId])) {
                throw new \RuntimeException('class_archive_read_projection_source_duplicate');
            }
            $seenIds[$id] = true;
            $seenImages[$imageId] = true;
            $json = json_encode(
                $photo->readModelProjection(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $encoded[] = [
                'id' => ClassArchivePhoto::idToBinary($id),
                'image_id' => $imageId,
                'era' => (string) $photo->era(),
                'payload' => $json,
                'row_digest' => hash('sha256', $json, true),
            ];
        }

        $result = $this->repository->transaction(function () use ($encoded, $normalizedKinds, $buildToken): array {
            $meta = '`' . $this->repository->table('read_projection') . '`';
            $rows = '`' . $this->repository->table('read_photo') . '`';
            $locked = $this->repository->fetchAll(
                "SELECT `projection_key`,`state`,`generation` FROM {$meta} WHERE `projection_key` IN "
                    . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key` FOR UPDATE",
            );
            $this->assertPhotoBuildTokenMatchesRows($buildToken, $locked);
            $catalog = $this->repository->fetchOne(
                "SELECT `state`,`source_revision`,`generation`,`native_source_generation`,`item_count` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                [self::PHOTO_CATALOG],
            );
            if ($catalog === null
                || ($catalog['state'] ?? null) !== 'STALE'
                || !is_string($catalog['source_revision'] ?? null) || strlen((string) $catalog['source_revision']) !== 32
                || !is_string($catalog['generation'] ?? null) || strlen((string) $catalog['generation']) !== 16
                || !is_string($catalog['native_source_generation'] ?? null)
                || !hash_equals((string) $catalog['native_source_generation'], (string) hex2bin($buildToken['native_source_epoch']))
                || (int) ($catalog['item_count'] ?? -1) < 1
            ) {
                throw new \RuntimeException('class_archive_read_projection_refresh_state_invalid');
            }
            foreach (array_keys($normalizedKinds) as $kind) {
                $aggregate = $this->repository->fetchOne(
                    "SELECT `state` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                    [$kind],
                );
                if ($aggregate === null || ($aggregate['state'] ?? null) !== 'STALE') {
                    throw new \RuntimeException('class_archive_read_aggregate_refresh_state_invalid');
                }
            }
            $generation = (string) $catalog['generation'];
            $ids = array_column($encoded, 'id');
            $existing = $this->repository->fetchAll(
                "SELECT `class_photo_id`,`piwigo_image_id` FROM {$rows} WHERE `generation`=? AND `class_photo_id` IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE',
                array_merge([$generation], $ids),
            );
            if (count($existing) !== count($encoded)) {
                throw new \RuntimeException('class_archive_read_projection_refresh_mapping_incomplete');
            }
            $existingById = [];
            foreach ($existing as $row) {
                if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16) {
                    throw new \RuntimeException('class_archive_read_projection_refresh_row_invalid');
                }
                $existingById[(string) $row['class_photo_id']] = (int) ($row['piwigo_image_id'] ?? 0);
            }
            $changed = false;
            foreach ($encoded as $row) {
                if (!isset($existingById[$row['id']]) || $existingById[$row['id']] !== $row['image_id']) {
                    throw new \RuntimeException('class_archive_read_projection_refresh_identity_drift');
                }
                $current = $this->repository->fetchOne(
                    "SELECT `era`,`payload_json`,`row_digest` FROM {$rows} WHERE `class_photo_id`=? AND `generation`=? LIMIT 1",
                    [$row['id'], $generation],
                );
                if ($current === null) {
                    throw new \RuntimeException('class_archive_read_projection_refresh_mapping_incomplete');
                }
                $rowChanged = ($current['era'] ?? null) !== $row['era']
                    || !is_string($current['payload_json'] ?? null) || !hash_equals((string) $current['payload_json'], $row['payload'])
                    || !is_string($current['row_digest'] ?? null) || !hash_equals((string) $current['row_digest'], $row['row_digest']);
                if (!$rowChanged) {
                    continue;
                }
                $updated = $this->repository->execute(
                    "UPDATE {$rows} SET `era`=?,`payload_json`=?,`row_digest`=?,`built_at`=UTC_TIMESTAMP(6) "
                        . 'WHERE `class_photo_id`=? AND `generation`=? AND `piwigo_image_id`=?',
                    [$row['era'], $row['payload'], $row['row_digest'], $row['id'], $generation, $row['image_id']],
                );
                if ($updated !== 1) {
                    throw new \RuntimeException('class_archive_read_projection_refresh_publish_failed');
                }
                $changed = true;
            }
            $catalogRows = $this->repository->fetchAll(
                "SELECT `class_photo_id`,`row_digest` FROM {$rows} WHERE `generation`=? ORDER BY `class_photo_id`",
                [$generation],
            );
            if (count($catalogRows) !== (int) $catalog['item_count']) {
                throw new \RuntimeException('class_archive_read_projection_refresh_catalog_incomplete');
            }
            $parts = [];
            foreach ($catalogRows as $row) {
                if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16
                    || !is_string($row['row_digest'] ?? null) || strlen((string) $row['row_digest']) !== 32
                ) {
                    throw new \RuntimeException('class_archive_read_projection_refresh_row_invalid');
                }
                $parts[] = (string) $row['class_photo_id'] . (string) $row['row_digest'];
            }
            $revision = hash('sha256', implode('', $parts), true);
            // Aggregates whose declared dependency set was not touched keep
            // their public payload and kind epoch, but are atomically rebound
            // to the new catalog revision. A reader can therefore require an
            // exact catalog binding without forcing unrelated recomputation.
            $this->rebindUnchangedAggregatesInCurrentTransaction(
                array_keys($normalizedKinds),
                $generation,
                $revision,
            );
            $published = $this->repository->execute(
                "UPDATE {$meta} SET `state`='ACTIVE',`source_revision`=?,`invalidated_reason`=NULL,"
                    . '`built_at`=UTC_TIMESTAMP(6),`invalidated_at`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `projection_key`=? AND `state`='STALE' AND `generation`=?",
                [$revision, self::PHOTO_CATALOG, $generation],
            );
            if ($published !== 1) {
                throw new \RuntimeException('class_archive_read_projection_refresh_publish_race');
            }
            return [
                'changed' => $changed,
                'updated' => count($encoded),
                'count' => count($catalogRows),
                'source_revision' => bin2hex($revision),
                'generation' => bin2hex($generation),
            ];
        });

        // Decode and digest-check the just-published catalog before aggregates
        // are allowed to rebuild from it. Any corruption fails the request;
        // the controller's relevant aggregate rows remain STALE.
        $this->photos();
        return $result;
    }

    /**
     * Fetch an already-built role-scope aggregate. Selecting a scope is not an
     * authorization decision: GatewayService must first resolve the current,
     * unfrozen principal and map its role to one of the two fixed scopes.
     *
     * @return array<string,mixed>
     */
    public function aggregate(string $kind, string $scope): array
    {
        $binding = null;
        return $this->readAggregate($kind, $scope, $binding);
    }

    /**
     * Return one opaque browser-presentation revision for the current fixed
     * policy scope. Every authorization-sensitive aggregate is validated and
     * bound to the same active catalog before the revision is emitted. This is
     * a cache invalidation input only; it is never an authorization credential.
     */
    public function presentationEpoch(string $scope): string
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_scope_invalid');
        }

        $catalog = null;
        $aggregateBindings = [];
        foreach (self::AGGREGATE_KINDS as $kind) {
            $binding = null;
            $this->readAggregate($kind, $scope, $binding);
            if (!is_array($binding)
                || !is_array($binding['catalog'] ?? null)
                || !is_array($binding['aggregate'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
            }
            if ($catalog === null) {
                $catalog = $binding['catalog'];
            } else {
                $this->assertCatalogBindingsEqual($catalog, $binding['catalog']);
            }
            $aggregateBindings[$kind] = $binding['aggregate'];
        }
        if (!is_array($catalog)) {
            throw new \RuntimeException('class_archive_read_presentation_binding_unavailable');
        }

        // A kind may be invalidated after an earlier aggregate was decoded.
        // Recheck the complete captured set immediately before issuing the
        // cache revision so a stale/failed projection cannot authorize reuse.
        $this->assertCatalogStateCurrent($catalog);
        foreach ($aggregateBindings as $kind => $binding) {
            $this->assertAggregateStateCurrent($kind, $binding);
        }

        $material = [
            'version' => 1,
            'scope' => $scope,
            'catalog' => $this->catalogBindingForDigest($catalog),
            'aggregates' => [],
        ];
        foreach (self::AGGREGATE_KINDS as $kind) {
            $material['aggregates'][$kind] = $this->aggregateBindingForDigest($aggregateBindings[$kind]);
        }
        return hash(
            'sha256',
            json_encode($material, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string,mixed>|null $binding
     * @return array<string,mixed>
     */
    private function readAggregate(string $kind, string $scope, ?array &$binding): array
    {
        $this->assertAggregateKind($kind);
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_scope_invalid');
        }
        $row = $this->repository->fetchOne(
            'SELECT `state`,`source_revision`,`generation`,`item_count`,`payload_json`,`payload_digest`,`dependency_revision` '
                . 'FROM `' . $this->repository->table('read_projection') . '` WHERE `projection_key`=? LIMIT 1',
            [$kind],
        );
        if ($row === null
            || ($row['state'] ?? null) !== 'ACTIVE'
            || !is_string($row['source_revision'] ?? null) || strlen((string) $row['source_revision']) !== 32
            || !is_string($row['generation'] ?? null) || strlen((string) $row['generation']) !== 16
            || !is_string($row['payload_json'] ?? null)
            || !is_string($row['payload_digest'] ?? null) || strlen((string) $row['payload_digest']) !== 32
            || !is_string($row['dependency_revision'] ?? null) || strlen((string) $row['dependency_revision']) !== 32
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_unavailable');
        }
        $payloadDigest = hash('sha256', (string) $row['payload_json'], true);
        $payloads = json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payloads)
            || array_keys($payloads) !== array_merge(['_projection'], self::SCOPES)
            || !is_array($payloads['_projection'] ?? null)
            || !is_array($payloads[$scope] ?? null)
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_payload_invalid');
        }
        $projection = $payloads['_projection'];
        if (($projection['version'] ?? null) !== self::AGGREGATE_VERSION
            || ($projection['kind'] ?? null) !== $kind
            || !is_string($projection['catalog_generation'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $projection['catalog_generation']) !== 1
            || !is_string($projection['catalog_revision'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $projection['catalog_revision']) !== 1
            || !is_string($projection['kind_epoch'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $projection['kind_epoch']) !== 1
            || !hash_equals(bin2hex((string) $row['generation']), (string) $projection['kind_epoch'])
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_dependency_invalid');
        }
        $catalog = $this->activeCatalogState();
        if (!hash_equals(bin2hex((string) $catalog['generation']), (string) $projection['catalog_generation'])
            || !hash_equals(bin2hex((string) $catalog['source_revision']), (string) $projection['catalog_revision'])
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_catalog_mismatch');
        }
        $expectedDependency = self::aggregateDependencyRevision($kind, $projection);
        $sourceRevision = hash('sha256', $expectedDependency . $payloadDigest, true);
        if (!hash_equals($expectedDependency, (string) $row['dependency_revision'])
            || !hash_equals($payloadDigest, (string) $row['payload_digest'])
            || !hash_equals($sourceRevision, (string) $row['source_revision'])
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_digest_mismatch');
        }
        $this->invokeReadValidationHook('AGGREGATE:' . $kind);
        $this->assertAggregateStateCurrent($kind, $row);
        $this->assertCatalogStateCurrent($catalog);
        $binding = ['aggregate' => $row, 'catalog' => $catalog];
        return $payloads[$scope];
    }

    /**
     * Capture the exact catalog and per-kind write epoch before expensive
     * source projection begins. The publish transaction later rechecks this
     * token under row locks. Relevant writes rotate their kind epoch before
     * changing source state, so an old payload can never be reactivated.
     *
     * @param list<string> $kinds
     * @return array{catalog_generation:string,catalog_revision:string,native_source_epoch:string,kind_epochs:array<string,string>}
     */
    public function beginAggregateBuild(array $kinds): array
    {
        $normalized = [];
        foreach ($kinds as $kind) {
            if (!is_string($kind)) {
                throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid');
            }
            $this->assertAggregateKind($kind);
            $normalized[$kind] = true;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_kind_missing');
        }
        return $this->repository->transaction(function () use ($normalized): array {
            $meta = '`' . $this->repository->table('read_projection') . '`';
            $catalog = $this->repository->fetchOne(
                "SELECT `state`,`source_revision`,`generation`,`native_source_generation` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                [self::PHOTO_CATALOG],
            );
            $nativeEpoch = $this->nativeSourceEpochBinary();
            if ($catalog === null
                || ($catalog['state'] ?? null) !== 'ACTIVE'
                || !is_string($catalog['source_revision'] ?? null) || strlen((string) $catalog['source_revision']) !== 32
                || !is_string($catalog['generation'] ?? null) || strlen((string) $catalog['generation']) !== 16
                || !is_string($catalog['native_source_generation'] ?? null)
                || !hash_equals($nativeEpoch, (string) $catalog['native_source_generation'])
            ) {
                throw new \RuntimeException('class_archive_read_projection_unavailable');
            }
            $epochs = [];
            foreach (array_keys($normalized) as $kind) {
                $row = $this->repository->fetchOne(
                    "SELECT `state`,`generation` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                    [$kind],
                );
                if ($row === null
                    || !in_array((string) ($row['state'] ?? ''), ['ACTIVE', 'STALE'], true)
                    || !is_string($row['generation'] ?? null) || strlen((string) $row['generation']) !== 16
                ) {
                    throw new \RuntimeException('class_archive_read_aggregate_epoch_unavailable');
                }
                $epochs[$kind] = bin2hex((string) $row['generation']);
            }
            return [
                'catalog_generation' => bin2hex((string) $catalog['generation']),
                'catalog_revision' => bin2hex((string) $catalog['source_revision']),
                'native_source_epoch' => bin2hex($nativeEpoch),
                'kind_epochs' => $epochs,
            ];
        });
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $payloadsByScope
     * @param list<string> $kinds
     * @return array{changed:bool,changed_kinds:list<string>,dry_run:bool}
     */
    public function rebuildAggregates(
        array $payloadsByScope,
        array $kinds,
        array $buildToken,
        bool $dryRun = false,
    ): array
    {
        if (array_keys($payloadsByScope) !== self::SCOPES) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_scopes_incomplete');
        }
        $normalizedKinds = [];
        foreach ($kinds as $kind) {
            if (!is_string($kind)) {
                throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid');
            }
            $this->assertAggregateKind($kind);
            $normalizedKinds[$kind] = true;
        }
        if ($normalizedKinds === []) {
            return ['changed' => false, 'changed_kinds' => [], 'dry_run' => $dryRun];
        }
        $this->assertAggregateBuildToken($buildToken, array_keys($normalizedKinds));
        $encoded = [];
        foreach (array_keys($normalizedKinds) as $kind) {
            $scoped = [
                '_projection' => [
                    'version' => self::AGGREGATE_VERSION,
                    'kind' => $kind,
                    'catalog_generation' => $buildToken['catalog_generation'],
                    'catalog_revision' => $buildToken['catalog_revision'],
                    'kind_epoch' => $buildToken['kind_epochs'][$kind],
                ],
            ];
            $itemCount = 0;
            foreach (self::SCOPES as $scope) {
                $payload = $payloadsByScope[$scope][$kind] ?? null;
                if (!is_array($payload)) {
                    throw new \InvalidArgumentException('class_archive_read_aggregate_payload_missing');
                }
                self::assertSafeAggregatePayload($payload);
                $scoped[$scope] = $payload;
                $itemCount += is_int($payload['total'] ?? null) && $payload['total'] >= 0
                    ? (int) $payload['total'] : 0;
            }
            $json = json_encode($scoped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $payloadDigest = hash('sha256', $json, true);
            $dependencyRevision = self::aggregateDependencyRevision($kind, $scoped['_projection']);
            $sourceRevision = hash('sha256', $dependencyRevision . $payloadDigest, true);
            $current = $this->repository->fetchOne(
                'SELECT `state`,`source_revision`,`item_count`,`payload_digest`,`dependency_revision` FROM `'
                    . $this->repository->table('read_projection') . '` WHERE `projection_key`=? LIMIT 1',
                [$kind],
            );
            $changed = $current === null
                || ($current['state'] ?? null) !== 'ACTIVE'
                || !is_string($current['source_revision'] ?? null)
                || !hash_equals((string) $current['source_revision'], $sourceRevision)
                || !is_string($current['payload_digest'] ?? null)
                || !hash_equals((string) $current['payload_digest'], $payloadDigest)
                || !is_string($current['dependency_revision'] ?? null)
                || !hash_equals((string) $current['dependency_revision'], $dependencyRevision)
                || (int) ($current['item_count'] ?? -1) !== $itemCount;
            $encoded[$kind] = compact(
                'json',
                'payloadDigest',
                'dependencyRevision',
                'sourceRevision',
                'itemCount',
                'changed',
            );
        }
        $changedKinds = array_keys(array_filter($encoded, static fn(array $item): bool => $item['changed']));
        if ($dryRun || $changedKinds === []) {
            // Computing payloads can take long enough for a source writer to
            // rotate either the native sentinel, catalog binding or a kind
            // epoch. A dry-run/no-change answer is evidence too: validate the
            // complete token again after computation instead of reporting a
            // stale snapshot as current.
            $this->assertAggregateBuildTokenCurrent($buildToken, array_keys($normalizedKinds));
            return ['changed' => $changedKinds !== [], 'changed_kinds' => $changedKinds, 'dry_run' => $dryRun];
        }
        $this->repository->transaction(function () use ($encoded, $changedKinds, $buildToken): void {
            $meta = '`' . $this->repository->table('read_projection') . '`';
            $catalog = $this->repository->fetchOne(
                "SELECT `state`,`source_revision`,`generation`,`native_source_generation` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                [self::PHOTO_CATALOG],
            );
            $nativeEpoch = $this->nativeSourceEpochBinary();
            if ($catalog === null
                || ($catalog['state'] ?? null) !== 'ACTIVE'
                || !is_string($catalog['source_revision'] ?? null)
                || !is_string($catalog['generation'] ?? null)
                || !is_string($catalog['native_source_generation'] ?? null)
                || !hash_equals((string) $catalog['source_revision'], (string) hex2bin($buildToken['catalog_revision']))
                || !hash_equals((string) $catalog['generation'], (string) hex2bin($buildToken['catalog_generation']))
                || !hash_equals((string) $catalog['native_source_generation'], (string) hex2bin($buildToken['native_source_epoch']))
                || !hash_equals($nativeEpoch, (string) $catalog['native_source_generation'])
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_catalog_publish_race');
            }
            foreach ($changedKinds as $kind) {
                $item = $encoded[$kind];
                $epoch = (string) hex2bin($buildToken['kind_epochs'][$kind]);
                $current = $this->repository->fetchOne(
                    "SELECT `state`,`generation` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                    [$kind],
                );
                if ($current === null
                    || !in_array((string) ($current['state'] ?? ''), ['ACTIVE', 'STALE'], true)
                    || !is_string($current['generation'] ?? null)
                    || !hash_equals((string) $current['generation'], $epoch)
                ) {
                    throw new \RuntimeException('class_archive_read_aggregate_publish_race');
                }
                $updated = $this->repository->execute(
                    "UPDATE {$meta} SET `state`='ACTIVE',`source_revision`=?,`generation`=?,`item_count`=?,"
                        . '`payload_json`=?,`payload_digest`=?,`dependency_revision`=?,`invalidated_reason`=NULL,'
                        . '`built_at`=UTC_TIMESTAMP(6),`invalidated_at`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `projection_key`=?',
                    [
                        $item['sourceRevision'],
                        $epoch,
                        $item['itemCount'],
                        $item['json'],
                        $item['payloadDigest'],
                        $item['dependencyRevision'],
                        $kind,
                    ],
                );
                if ($updated !== 1) {
                    throw new \RuntimeException('class_archive_read_aggregate_publish_failed');
                }
            }
        });
        return ['changed' => true, 'changed_kinds' => $changedKinds, 'dry_run' => false];
    }

    /** @param list<string> $kinds */
    public function invalidate(array $kinds, string $reason, bool $cascadeCatalog = true): void
    {
        if ($reason === '' || strlen($reason) > 64 || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('class_archive_read_projection_reason_invalid');
        }
        $normalized = [];
        foreach ($kinds as $kind) {
            if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException('class_archive_read_projection_kind_invalid');
            }
            $normalized[$kind] = true;
        }
        if ($cascadeCatalog && isset($normalized[self::PHOTO_CATALOG])) {
            foreach (self::AGGREGATE_KINDS as $kind) {
                $normalized[$kind] = true;
            }
        }
        if ($normalized === []) {
            return;
        }
        $this->repository->transaction(function () use ($normalized, $reason): void {
            $this->invalidateRowsInCurrentTransaction(array_keys($normalized), $reason);
        });
    }

    /** @param list<string> $kinds */
    private function invalidateRowsInCurrentTransaction(array $kinds, string $reason): void
    {
        $table = '`' . $this->repository->table('read_projection') . '`';
        foreach ($kinds as $kind) {
            if (in_array($kind, self::AGGREGATE_KINDS, true)) {
                // This is the per-kind source epoch. It rotates before every
                // relevant business write and is retained when the resulting
                // aggregate becomes ACTIVE. A concurrent builder holding the
                // previous epoch is therefore unable to publish.
                $epoch = DomainSupport::idToBinary(DomainSupport::generateId());
                $updated = $this->repository->execute(
                    "UPDATE {$table} SET `state`='STALE',`generation`=?,`invalidated_reason`=?,"
                        . '`invalidated_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key`=?',
                    [$epoch, $reason, $kind],
                );
            } else {
                $updated = $this->repository->execute(
                    "UPDATE {$table} SET `state`='STALE',`invalidated_reason`=?,`invalidated_at`=UTC_TIMESTAMP(6),"
                        . '`updated_at`=UTC_TIMESTAMP(6) WHERE `projection_key`=?',
                    [$reason, $kind],
                );
            }
            if ($updated !== 1) {
                throw new \RuntimeException('class_archive_read_projection_invalidation_failed');
            }
        }
    }

    /** @param list<string> $affectedKinds */
    private function rebindUnchangedAggregatesInCurrentTransaction(
        array $affectedKinds,
        string $catalogGeneration,
        string $catalogRevision,
    ): void {
        $affected = array_fill_keys($affectedKinds, true);
        $table = '`' . $this->repository->table('read_projection') . '`';
        foreach (self::AGGREGATE_KINDS as $kind) {
            if (isset($affected[$kind])) {
                continue;
            }
            $row = $this->repository->fetchOne(
                "SELECT `state`,`generation`,`payload_json` FROM {$table} WHERE `projection_key`=? FOR UPDATE",
                [$kind],
            );
            if ($row === null || ($row['state'] ?? null) !== 'ACTIVE'
                || !is_string($row['generation'] ?? null) || strlen((string) $row['generation']) !== 16
                || !is_string($row['payload_json'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_rebind_unavailable');
            }
            $payloads = json_decode((string) $row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payloads)
                || array_keys($payloads) !== array_merge(['_projection'], self::SCOPES)
                || !is_array($payloads['_projection'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_rebind_payload_invalid');
            }
            $projection = $payloads['_projection'];
            if (($projection['version'] ?? null) !== self::AGGREGATE_VERSION
                || ($projection['kind'] ?? null) !== $kind
                || ($projection['kind_epoch'] ?? null) !== bin2hex((string) $row['generation'])
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_rebind_dependency_invalid');
            }
            $projection['catalog_generation'] = bin2hex($catalogGeneration);
            $projection['catalog_revision'] = bin2hex($catalogRevision);
            $payloads['_projection'] = $projection;
            $json = json_encode($payloads, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $payloadDigest = hash('sha256', $json, true);
            $dependencyRevision = self::aggregateDependencyRevision($kind, $projection);
            $sourceRevision = hash('sha256', $dependencyRevision . $payloadDigest, true);
            $updated = $this->repository->execute(
                "UPDATE {$table} SET `source_revision`=?,`payload_json`=?,`payload_digest`=?,`dependency_revision`=?,"
                    . '`built_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `projection_key`=? AND `state`='ACTIVE' AND `generation`=?",
                [$sourceRevision, $json, $payloadDigest, $dependencyRevision, $kind, (string) $row['generation']],
            );
            if ($updated !== 1) {
                throw new \RuntimeException('class_archive_read_aggregate_rebind_failed');
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{catalog_generation:string,native_source_epoch:string,aggregate_epochs:array<string,string>}
     */
    private function photoBuildTokenFromRows(array $rows, string $nativeSourceEpoch): array
    {
        if (strlen($nativeSourceEpoch) !== 16) {
            throw new \RuntimeException('class_archive_native_source_epoch_invalid');
        }
        if (count($rows) !== 1 + count(self::AGGREGATE_KINDS)) {
            throw new \RuntimeException('class_archive_read_projection_build_token_incomplete');
        }
        $catalogGeneration = null;
        $aggregateEpochs = [];
        foreach ($rows as $row) {
            $kind = is_string($row['projection_key'] ?? null) ? (string) $row['projection_key'] : '';
            $state = is_string($row['state'] ?? null) ? (string) $row['state'] : '';
            $generation = $row['generation'] ?? null;
            if (!in_array($kind, array_merge([self::PHOTO_CATALOG], self::AGGREGATE_KINDS), true)
                || !in_array($state, ['ACTIVE', 'STALE', 'FAILED'], true)
                || !is_string($generation) || strlen($generation) !== 16
            ) {
                throw new \RuntimeException('class_archive_read_projection_build_token_invalid');
            }
            if ($kind === self::PHOTO_CATALOG) {
                $catalogGeneration = bin2hex($generation);
            } else {
                $aggregateEpochs[$kind] = bin2hex($generation);
            }
        }
        if (!is_string($catalogGeneration)) {
            throw new \RuntimeException('class_archive_read_projection_build_token_incomplete');
        }
        $orderedEpochs = [];
        foreach (self::AGGREGATE_KINDS as $kind) {
            if (!isset($aggregateEpochs[$kind])) {
                throw new \RuntimeException('class_archive_read_projection_build_token_incomplete');
            }
            $orderedEpochs[$kind] = $aggregateEpochs[$kind];
        }
        return [
            'catalog_generation' => $catalogGeneration,
            'native_source_epoch' => bin2hex($nativeSourceEpoch),
            'aggregate_epochs' => $orderedEpochs,
        ];
    }

    /** @param array<string,mixed> $token */
    private function assertPhotoBuildTokenShape(array $token): void
    {
        if (!is_string($token['catalog_generation'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $token['catalog_generation']) !== 1
            || !is_string($token['native_source_epoch'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $token['native_source_epoch']) !== 1
            || !is_array($token['aggregate_epochs'] ?? null)
            || array_keys($token['aggregate_epochs']) !== self::AGGREGATE_KINDS
        ) {
            throw new \InvalidArgumentException('class_archive_read_projection_build_token_invalid');
        }
        foreach ($token['aggregate_epochs'] as $kind => $epoch) {
            $this->assertAggregateKind((string) $kind);
            if (!is_string($epoch) || preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1) {
                throw new \InvalidArgumentException('class_archive_read_projection_build_token_invalid');
            }
        }
    }

    /** @param array<string,mixed> $token @param list<array<string,mixed>> $rows */
    private function assertPhotoBuildTokenMatchesRows(array $token, array $rows): void
    {
        $this->assertPhotoBuildTokenShape($token);
        $current = $this->photoBuildTokenFromRows($rows, $this->nativeSourceEpochBinary());
        if (!hash_equals((string) $token['catalog_generation'], $current['catalog_generation'])) {
            throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
        }
        if (!hash_equals((string) $token['native_source_epoch'], $current['native_source_epoch'])) {
            throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
        }
        foreach (self::AGGREGATE_KINDS as $kind) {
            if (!hash_equals((string) $token['aggregate_epochs'][$kind], $current['aggregate_epochs'][$kind])) {
                throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
            }
        }
    }

    /** @param array<string,mixed> $token */
    private function assertPhotoBuildTokenCurrent(array $token): void
    {
        $this->repository->transaction(function () use ($token): void {
            $table = '`' . $this->repository->table('read_projection') . '`';
            $rows = $this->repository->fetchAll(
                "SELECT `projection_key`,`state`,`generation` FROM {$table} WHERE `projection_key` IN "
                    . "('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key` FOR UPDATE",
            );
            $this->assertPhotoBuildTokenMatchesRows($token, $rows);
        });
    }

    /** @return array<string,mixed> */
    private function activeCatalogState(): array
    {
        $state = $this->repository->fetchOne(
            'SELECT `state`,`source_revision`,`item_count`,`generation`,`native_source_generation` FROM `' . $this->repository->table('read_projection') . '` '
                . 'WHERE `projection_key`=? LIMIT 1',
            [self::PHOTO_CATALOG],
        );
        if ($state === null
            || ($state['state'] ?? null) !== 'ACTIVE'
            || !is_string($state['source_revision'] ?? null) || strlen((string) $state['source_revision']) !== 32
            || !is_string($state['generation'] ?? null) || strlen((string) $state['generation']) !== 16
            || !is_string($state['native_source_generation'] ?? null) || strlen((string) $state['native_source_generation']) !== 16
            || (int) ($state['item_count'] ?? -1) < 0
        ) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        $this->assertNativeSourceEpochBinding((string) $state['native_source_generation']);
        return $state;
    }

    /** @param array<string,mixed> $bound */
    private function assertCatalogStateCurrent(array $bound): void
    {
        $current = $this->activeCatalogState();
        $this->assertCatalogBindingsEqual($bound, $current);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function assertCatalogBindingsEqual(array $left, array $right): void
    {
        foreach (['source_revision', 'generation', 'native_source_generation'] as $field) {
            if (!is_string($left[$field] ?? null)
                || !is_string($right[$field] ?? null)
                || !hash_equals((string) $left[$field], (string) $right[$field])
            ) {
                throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
            }
        }
        if ((int) ($left['item_count'] ?? -1) < 0
            || (int) ($left['item_count'] ?? -1) !== (int) ($right['item_count'] ?? -2)
        ) {
            throw new \RuntimeException('class_archive_read_projection_source_epoch_changed');
        }
    }

    /** @param array<string,mixed> $bound */
    private function assertAggregateStateCurrent(string $kind, array $bound): void
    {
        $this->assertAggregateKind($kind);
        $current = $this->repository->fetchOne(
            'SELECT `state`,`source_revision`,`generation`,`item_count`,`payload_digest`,`dependency_revision` '
                . 'FROM `' . $this->repository->table('read_projection') . '` WHERE `projection_key`=? LIMIT 1',
            [$kind],
        );
        if ($current === null || ($current['state'] ?? null) !== 'ACTIVE') {
            throw new \RuntimeException('class_archive_read_aggregate_unavailable');
        }
        foreach (['source_revision', 'generation', 'payload_digest', 'dependency_revision'] as $field) {
            if (!is_string($bound[$field] ?? null)
                || !is_string($current[$field] ?? null)
                || !hash_equals((string) $bound[$field], (string) $current[$field])
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_source_epoch_changed');
            }
        }
        if ((int) ($bound['item_count'] ?? -1) < 0
            || (int) ($bound['item_count'] ?? -1) !== (int) ($current['item_count'] ?? -2)
        ) {
            throw new \RuntimeException('class_archive_read_aggregate_source_epoch_changed');
        }
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function catalogBindingForDigest(array $binding): array
    {
        return [
            'source_revision' => bin2hex((string) $binding['source_revision']),
            'generation' => bin2hex((string) $binding['generation']),
            'native_source_generation' => bin2hex((string) $binding['native_source_generation']),
            'item_count' => (int) $binding['item_count'],
        ];
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function aggregateBindingForDigest(array $binding): array
    {
        return [
            'source_revision' => bin2hex((string) $binding['source_revision']),
            'generation' => bin2hex((string) $binding['generation']),
            'payload_digest' => bin2hex((string) $binding['payload_digest']),
            'dependency_revision' => bin2hex((string) $binding['dependency_revision']),
            'item_count' => (int) $binding['item_count'],
        ];
    }

    private function invokeReadValidationHook(string $checkpoint): void
    {
        if ($this->beforeFinalReadValidation instanceof \Closure) {
            ($this->beforeFinalReadValidation)($checkpoint);
        }
    }

    private function nativeSourceEpochBinary(): string
    {
        $row = $this->repository->fetchOne(
            'SELECT `generation` FROM `' . $this->repository->table('native_source_epoch') . '` '
                . 'WHERE `source_key`=? LIMIT 1',
            [self::NATIVE_SOURCE_KEY],
        );
        if ($row === null
            || !is_string($row['generation'] ?? null)
            || strlen((string) $row['generation']) !== 16
        ) {
            throw new \RuntimeException('class_archive_native_source_epoch_unavailable');
        }
        return (string) $row['generation'];
    }

    private function assertNativeSourceEpochBinding(string $boundEpoch): void
    {
        if (strlen($boundEpoch) !== 16
            || !hash_equals($boundEpoch, $this->nativeSourceEpochBinary())
        ) {
            throw new \RuntimeException('class_archive_read_projection_native_source_epoch_mismatch');
        }
    }

    private function assertAggregateKind(string $kind): void
    {
        if (!in_array($kind, self::AGGREGATE_KINDS, true)) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_kind_invalid');
        }
    }

    /** @param array<string,mixed> $token @param list<string> $kinds */
    private function assertAggregateBuildToken(array $token, array $kinds): void
    {
        if (!is_string($token['catalog_generation'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $token['catalog_generation']) !== 1
            || !is_string($token['catalog_revision'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $token['catalog_revision']) !== 1
            || !is_string($token['native_source_epoch'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) $token['native_source_epoch']) !== 1
            || !is_array($token['kind_epochs'] ?? null)
        ) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_build_token_invalid');
        }
        $expected = array_values(array_unique($kinds));
        $actual = array_keys($token['kind_epochs']);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new \InvalidArgumentException('class_archive_read_aggregate_build_token_incomplete');
        }
        foreach ($token['kind_epochs'] as $kind => $epoch) {
            $this->assertAggregateKind((string) $kind);
            if (!is_string($epoch) || preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1) {
                throw new \InvalidArgumentException('class_archive_read_aggregate_epoch_invalid');
            }
        }
    }

    /** @param array<string,mixed> $token @param list<string> $kinds */
    private function assertAggregateBuildTokenCurrent(array $token, array $kinds): void
    {
        $this->assertAggregateBuildToken($token, $kinds);
        $this->repository->transaction(function () use ($token, $kinds): void {
            $meta = '`' . $this->repository->table('read_projection') . '`';
            $catalog = $this->repository->fetchOne(
                "SELECT `state`,`source_revision`,`generation`,`native_source_generation` FROM {$meta} "
                    . 'WHERE `projection_key`=? FOR UPDATE',
                [self::PHOTO_CATALOG],
            );
            $nativeEpoch = $this->nativeSourceEpochBinary();
            if ($catalog === null
                || ($catalog['state'] ?? null) !== 'ACTIVE'
                || !is_string($catalog['source_revision'] ?? null) || strlen((string) $catalog['source_revision']) !== 32
                || !is_string($catalog['generation'] ?? null) || strlen((string) $catalog['generation']) !== 16
                || !is_string($catalog['native_source_generation'] ?? null) || strlen((string) $catalog['native_source_generation']) !== 16
                || !hash_equals((string) $catalog['source_revision'], (string) hex2bin($token['catalog_revision']))
                || !hash_equals((string) $catalog['generation'], (string) hex2bin($token['catalog_generation']))
                || !hash_equals((string) $catalog['native_source_generation'], (string) hex2bin($token['native_source_epoch']))
                || !hash_equals($nativeEpoch, (string) $catalog['native_source_generation'])
            ) {
                throw new \RuntimeException('class_archive_read_aggregate_source_epoch_changed');
            }
            foreach ($kinds as $kind) {
                $row = $this->repository->fetchOne(
                    "SELECT `state`,`generation` FROM {$meta} WHERE `projection_key`=? FOR UPDATE",
                    [$kind],
                );
                if ($row === null
                    || !in_array((string) ($row['state'] ?? ''), ['ACTIVE', 'STALE'], true)
                    || !is_string($row['generation'] ?? null) || strlen((string) $row['generation']) !== 16
                    || !hash_equals((string) $row['generation'], (string) hex2bin($token['kind_epochs'][$kind]))
                ) {
                    throw new \RuntimeException('class_archive_read_aggregate_source_epoch_changed');
                }
            }
        });
    }

    /** @param array<string,mixed> $projection */
    private static function aggregateDependencyRevision(string $kind, array $projection): string
    {
        return hash(
            'sha256',
            "class-archive-aggregate-contract\0"
                . self::AGGREGATE_VERSION . "\0"
                . $kind . "\0"
                . (string) hex2bin((string) $projection['catalog_generation'])
                . (string) hex2bin((string) $projection['catalog_revision'])
                . (string) hex2bin((string) $projection['kind_epoch']),
            true,
        );
    }

    /** @param array<string,mixed> $payload */
    private static function assertSafeAggregatePayload(array $payload): void
    {
        $walk = static function (array $node) use (&$walk): void {
            foreach ($node as $key => $value) {
                if (is_string($key) && preg_match('/(?:account|seat|principal|piwigo|immich|owner_id|user_id|token|secret)/i', $key) === 1) {
                    throw new \InvalidArgumentException('class_archive_read_aggregate_sensitive_key');
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);
    }

    /** @param array<string,mixed> $row */
    private function decodePhotoRow(array $row): GatewayPhotoCandidate
    {
        if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16
            || !is_string($row['payload_json'] ?? null)
            || !is_string($row['row_digest'] ?? null) || strlen((string) $row['row_digest']) !== 32
            || !hash_equals((string) $row['row_digest'], hash('sha256', (string) $row['payload_json'], true))
        ) {
            throw new \RuntimeException('class_archive_read_projection_row_invalid');
        }
        $payload = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || !is_string($payload['class_photo_id'] ?? null)
            || !hash_equals(ClassArchivePhoto::binaryToId((string) $row['class_photo_id']), strtolower($payload['class_photo_id']))
        ) {
            throw new \RuntimeException('class_archive_read_projection_row_invalid');
        }
        return GatewayPhotoCandidate::fromReadModelProjection($payload);
    }
}
