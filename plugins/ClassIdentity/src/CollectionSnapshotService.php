<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * A single, immutable item from a role-scoped Collection snapshot.
 *
 * Snapshot data is deliberately authorization-neutral: callers must supply a
 * current-policy recheck before this DTO is serialized to a browser.  The
 * class only carries opaque Class Archive photo ids, never Piwigo/Immich ids,
 * source paths, account ids, or a principal graph.
 */
final class CollectionSnapshotItem
{
    /**
     * @param list<string> $photoIds
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private readonly string $snapshotId,
        private readonly int $ordinal,
        private readonly string $itemKind,
        private readonly string $itemKey,
        private readonly ?string $coverPhotoId,
        private readonly array $photoIds,
        private readonly array $payload,
    ) {
    }

    public function snapshotId(): string
    {
        return $this->snapshotId;
    }

    public function ordinal(): int
    {
        return $this->ordinal;
    }

    public function itemKind(): string
    {
        return $this->itemKind;
    }

    public function itemKey(): string
    {
        return $this->itemKey;
    }

    public function coverPhotoId(): ?string
    {
        return $this->coverPhotoId;
    }

    /** @return list<string> */
    public function photoIds(): array
    {
        return $this->photoIds;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Public shape after a caller has re-evaluated all referenced photos for
     * the current principal. A stale Full snapshot therefore never becomes a
     * Family response simply because its UUID is known.
     *
     * @param list<string> $visiblePhotoIds
     * @return array<string,mixed>|null
     */
    public function publicProjection(array $visiblePhotoIds): ?array
    {
        $visible = [];
        $seen = [];
        $allowed = array_fill_keys($this->photoIds, true);
        foreach ($visiblePhotoIds as $photoId) {
            if (!is_string($photoId) || !isset($allowed[$photoId]) || isset($seen[$photoId])) {
                continue;
            }
            $seen[$photoId] = true;
            $visible[] = $photoId;
        }

        // Presentation objects with a photo relationship must retain at least
        // one currently visible canonical photo. Search suggestions are the
        // only deliberately metadata-only item kind.
        if ($this->photoIds !== [] && $visible === []) {
            return null;
        }

        $cover = $this->coverPhotoId;
        if ($cover !== null && !isset($seen[$cover])) {
            $cover = $visible[0] ?? null;
        }

        return [
            'itemKind' => $this->itemKind,
            'itemKey' => $this->itemKey,
            'coverPhotoId' => $cover,
            'photoIds' => $visible,
            'photoCount' => count($visible),
            'payload' => $this->payload,
        ];
    }
}

/**
 * Durable build/read boundary for the Phase 3.4 Collections-first surface.
 *
 * The old v15 AutoCollection domain is intentionally left alone: it remains
 * the durable Memory mirror.  This service adds retained, versioned snapshots
 * with an atomic per-scope pointer, so a failed later build never destroys the
 * last verified result.  It does not decide photo visibility; every browser
 * read accepts a current-policy callback and fails closed on an invalid or
 * unavailable active snapshot.
 */
final class CollectionSnapshotService
{
    public const SCOPE_FULL = 'FULL';
    public const SCOPE_HERITAGE_ONLY = 'HERITAGE_ONLY';
    private const STORED_SCOPE_HERITAGE = 'HERITAGE';

    public const KIND_HOME = 'HOME';
    public const KIND_MEMORY = 'MEMORY';
    public const KIND_SPOTLIGHT = 'SPOTLIGHT';
    public const KIND_SEARCH_SUGGESTION = 'SEARCH_SUGGESTION';

    public const ITEM_AUTO_COLLECTION = 'AUTO_COLLECTION';
    public const ITEM_ALBUM = 'ALBUM';
    public const ITEM_PERSON = 'PERSON';
    public const ITEM_SPOTLIGHT = 'SPOTLIGHT';
    public const ITEM_PHOTO = 'PHOTO';
    public const ITEM_SEARCH_SUGGESTION = 'SEARCH_SUGGESTION';

    public const FEEDBACK_HIDE = 'HIDE';
    public const FEEDBACK_LESS_LIKE = 'LESS_LIKE';
    public const FEEDBACK_LIKE = 'LIKE';

    private const MAX_ITEMS = 1000;
    private const MAX_PHOTO_IDS_PER_ITEM = 1000;
    private const MAX_TOTAL_PHOTO_IDS = 10000;
    private const MAX_PINS = 100;
    // Active feedback is scoped to the four fixed projections.  Bound it to
    // their combined maximum rather than letting an old preference row turn a
    // routine Home read into an unbounded principal-history query.
    private const MAX_FEEDBACK = 4000;

    /** @var list<string> */
    private const PROJECTION_KINDS = [
        self::KIND_HOME,
        self::KIND_MEMORY,
        self::KIND_SPOTLIGHT,
        self::KIND_SEARCH_SUGGESTION,
    ];

    /** @var list<string> */
    private const ITEM_KINDS = [
        self::ITEM_AUTO_COLLECTION,
        self::ITEM_ALBUM,
        self::ITEM_PERSON,
        self::ITEM_SPOTLIGHT,
        self::ITEM_PHOTO,
        self::ITEM_SEARCH_SUGGESTION,
    ];

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Publish an immutable snapshot and atomically switch only this
     * `(scope, projection_kind)` pointer. Previous snapshots are retained as
     * `SUPERSEDED`; GET never builds or repairs a snapshot.
     *
     * @param list<array<string,mixed>> $items
     * @return array{snapshotId:string,scope:string,projectionKind:string,revision:string,itemCount:int,published:bool}
     */
    public function publish(string $scope, string $projectionKind, string $inputRevision, array $items): array
    {
        $storedScope = self::normalizeStoredScope($scope);
        $projectionKind = self::normalizeProjectionKind($projectionKind);
        $revision = self::normalizeRevision($inputRevision);
        $normalizedItems = self::normalizeItems($items);
        $payloadDigest = hash('sha256', self::canonicalJson([
            'scope' => $storedScope,
            'projectionKind' => $projectionKind,
            'revision' => bin2hex($revision),
            'items' => self::snapshotDigestItems($normalizedItems),
        ]), true);

        return $this->repository->transaction(function (Repository $repository) use (
            $storedScope,
            $projectionKind,
            $revision,
            $normalizedItems,
            $payloadDigest,
        ): array {
            $snapshotTable = DomainSupport::table($repository, 'collection_snapshot');
            $itemTable = DomainSupport::table($repository, 'collection_snapshot_item');
            $pointerTable = DomainSupport::table($repository, 'collection_snapshot_pointer');
            $pointer = $repository->fetchOne(
                'SELECT `active_snapshot_id`,`active_revision` FROM `' . $pointerTable . '` '
                    . 'WHERE `scope`=? AND `projection_kind`=? FOR UPDATE',
                [$storedScope, $projectionKind],
            );

            if ($pointer !== null
                && is_string($pointer['active_snapshot_id'] ?? null)
                && strlen((string) $pointer['active_snapshot_id']) === 16
                && is_string($pointer['active_revision'] ?? null)
                && hash_equals((string) $pointer['active_revision'], $revision)
            ) {
                $current = $repository->fetchOne(
                    'SELECT `snapshot_id`,`payload_digest`,`item_count`,`state` FROM `' . $snapshotTable . '` '
                        . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? FOR UPDATE',
                    [(string) $pointer['active_snapshot_id'], $storedScope, $projectionKind],
                );
                if ($current !== null
                    && ($current['state'] ?? null) === 'ACTIVE'
                    && is_string($current['payload_digest'] ?? null)
                    && hash_equals((string) $current['payload_digest'], $payloadDigest)
                    && (int) ($current['item_count'] ?? -1) === count($normalizedItems)
                ) {
                    return self::publishResult((string) $current['snapshot_id'], $storedScope, $projectionKind, $revision, count($normalizedItems), false);
                }
            }

            $snapshotId = DomainSupport::generateId();
            $snapshotBinary = DomainSupport::idToBinary($snapshotId);
            $repository->execute(
                'INSERT INTO `' . $snapshotTable . '` '
                    . '(`snapshot_id`,`scope`,`projection_kind`,`state`,`input_revision`,`payload_digest`,`item_count`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, 'BUILDING', ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$snapshotBinary, $storedScope, $projectionKind, $revision, $payloadDigest, count($normalizedItems)],
            );
            foreach ($normalizedItems as $ordinal => $item) {
                $repository->execute(
                    'INSERT INTO `' . $itemTable . '` '
                        . '(`snapshot_id`,`ordinal`,`item_kind`,`item_key`,`cover_class_photo_id`,`photo_ids_json`,`payload_json`,`payload_digest`,`created_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                    [
                        $snapshotBinary,
                        $ordinal,
                        $item['itemKind'],
                        $item['itemKey'],
                        $item['coverPhotoId'] === null ? null : DomainSupport::idToBinary($item['coverPhotoId']),
                        self::canonicalJson($item['photoIds']),
                        self::canonicalJson($item['payload']),
                        $item['payloadDigest'],
                    ],
                );
            }
            $inserted = $repository->fetchOne(
                'SELECT COUNT(*) AS `count` FROM `' . $itemTable . '` WHERE `snapshot_id`=? FOR UPDATE',
                [$snapshotBinary],
            );
            if ((int) ($inserted['count'] ?? -1) !== count($normalizedItems)) {
                throw new \RuntimeException('class_archive_collection_snapshot_item_count_mismatch');
            }

            if ($pointer !== null) {
                $prior = $pointer['active_snapshot_id'] ?? null;
                if (!is_string($prior) || strlen($prior) !== 16) {
                    throw new \RuntimeException('class_archive_collection_snapshot_pointer_invalid');
                }
                $changed = $repository->execute(
                    'UPDATE `' . $snapshotTable . '` SET `state`=?,`superseded_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? AND `state`=?',
                    ['SUPERSEDED', $prior, $storedScope, $projectionKind, 'ACTIVE'],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_collection_snapshot_active_pointer_drift');
                }
            }

            $activated = $repository->execute(
                'UPDATE `' . $snapshotTable . '` SET `state`=?,`published_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? AND `state`=?',
                ['ACTIVE', $snapshotBinary, $storedScope, $projectionKind, 'BUILDING'],
            );
            if ($activated !== 1) {
                throw new \RuntimeException('class_archive_collection_snapshot_publish_failed');
            }
            $repository->execute(
                'INSERT INTO `' . $pointerTable . '` '
                    . '(`scope`,`projection_kind`,`active_snapshot_id`,`active_revision`,`activated_at`,`updated_at`) '
                    . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE `active_snapshot_id`=VALUES(`active_snapshot_id`),`active_revision`=VALUES(`active_revision`),'
                    . '`activated_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6)',
                [$storedScope, $projectionKind, $snapshotBinary, $revision],
            );

            return self::publishResult($snapshotBinary, $storedScope, $projectionKind, $revision, count($normalizedItems), true);
        });
    }

    /**
     * Publish the complete Collections-first read bundle in one database
     * transaction.  A Home page is composed from several independently
     * addressable projections, but it must never observe a half-published
     * revision (for example, a new HOME pointer beside an old SPOTLIGHT
     * pointer).  This method writes every immutable candidate first, then
     * swaps all four pointers at the same commit boundary.
     *
     * Callers must provide exactly the fixed bundle.  Keeping the set closed
     * avoids a future optional projection silently weakening the all-or-nothing
     * publish guarantee.
     *
     * @param array<string,list<array<string,mixed>>> $itemsByProjectionKind
     * @return array<string,array{snapshotId:string,scope:string,projectionKind:string,revision:string,itemCount:int,published:bool}>
     */
    public function publishBundle(string $scope, string $inputRevision, array $itemsByProjectionKind): array
    {
        $storedScope = self::normalizeStoredScope($scope);
        $revision = self::normalizeRevision($inputRevision);
        $expected = array_fill_keys(self::PROJECTION_KINDS, true);
        $provided = [];
        $prepared = [];

        foreach ($itemsByProjectionKind as $projectionKind => $items) {
            if (!is_string($projectionKind) || !is_array($items)) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_bundle_invalid');
            }
            $kind = self::normalizeProjectionKind($projectionKind);
            if (isset($provided[$kind])) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_bundle_invalid');
            }
            $provided[$kind] = true;
            $normalizedItems = self::normalizeItems($items);
            $prepared[$kind] = [
                'items' => $normalizedItems,
                'payloadDigest' => hash('sha256', self::canonicalJson([
                    'scope' => $storedScope,
                    'projectionKind' => $kind,
                    'revision' => bin2hex($revision),
                    'items' => self::snapshotDigestItems($normalizedItems),
                ]), true),
            ];
        }
        if (count($provided) !== count($expected)
            || array_diff_key($provided, $expected) !== []
            || array_diff_key($expected, $provided) !== []) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_bundle_invalid');
        }

        return $this->repository->transaction(function (Repository $repository) use ($storedScope, $revision, $prepared): array {
            $snapshotTable = DomainSupport::table($repository, 'collection_snapshot');
            $itemTable = DomainSupport::table($repository, 'collection_snapshot_item');
            $pointerTable = DomainSupport::table($repository, 'collection_snapshot_pointer');
            $placeholders = implode(',', array_fill(0, count(self::PROJECTION_KINDS), '?'));
            $pointerRows = $repository->fetchAll(
                'SELECT `projection_kind`,`active_snapshot_id`,`active_revision` FROM `' . $pointerTable . '` '
                    . 'WHERE `scope`=? AND `projection_kind` IN (' . $placeholders . ') '
                    . 'ORDER BY `projection_kind` ASC FOR UPDATE',
                array_merge([$storedScope], self::PROJECTION_KINDS),
            );
            $pointers = [];
            foreach ($pointerRows as $row) {
                $kind = $row['projection_kind'] ?? null;
                if (!is_string($kind) || !in_array($kind, self::PROJECTION_KINDS, true) || isset($pointers[$kind])) {
                    throw new \RuntimeException('class_archive_collection_snapshot_pointer_invalid');
                }
                $pointers[$kind] = $row;
            }

            $allCurrent = count($pointers) === count(self::PROJECTION_KINDS);
            $currentRows = [];
            if ($allCurrent) {
                foreach (self::PROJECTION_KINDS as $kind) {
                    $pointer = $pointers[$kind] ?? null;
                    $activeId = is_array($pointer) ? ($pointer['active_snapshot_id'] ?? null) : null;
                    $activeRevision = is_array($pointer) ? ($pointer['active_revision'] ?? null) : null;
                    if (!is_string($activeId) || strlen($activeId) !== 16
                        || !is_string($activeRevision) || !hash_equals($activeRevision, $revision)) {
                        $allCurrent = false;
                        break;
                    }
                    $row = $repository->fetchOne(
                        'SELECT `snapshot_id`,`payload_digest`,`item_count`,`state` FROM `' . $snapshotTable . '` '
                            . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? FOR UPDATE',
                        [$activeId, $storedScope, $kind],
                    );
                    $candidate = $prepared[$kind];
                    if ($row === null || ($row['state'] ?? null) !== 'ACTIVE'
                        || !is_string($row['payload_digest'] ?? null)
                        || !hash_equals((string) $row['payload_digest'], (string) $candidate['payloadDigest'])
                        || (int) ($row['item_count'] ?? -1) !== count((array) $candidate['items'])) {
                        $allCurrent = false;
                        break;
                    }
                    $currentRows[$kind] = $row;
                }
            }
            if ($allCurrent) {
                $result = [];
                foreach (self::PROJECTION_KINDS as $kind) {
                    $result[$kind] = self::publishResult(
                        (string) $currentRows[$kind]['snapshot_id'],
                        $storedScope,
                        $kind,
                        $revision,
                        count((array) $prepared[$kind]['items']),
                        false,
                    );
                }
                return $result;
            }

            /** @var array<string,string> $snapshotIds */
            $snapshotIds = [];
            foreach (self::PROJECTION_KINDS as $kind) {
                $snapshotId = DomainSupport::generateId();
                $snapshotBinary = DomainSupport::idToBinary($snapshotId);
                $candidate = $prepared[$kind];
                $items = (array) $candidate['items'];
                $repository->execute(
                    'INSERT INTO `' . $snapshotTable . '` '
                        . '(`snapshot_id`,`scope`,`projection_kind`,`state`,`input_revision`,`payload_digest`,`item_count`,`created_at`,`updated_at`) '
                        . "VALUES (?, ?, ?, 'BUILDING', ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$snapshotBinary, $storedScope, $kind, $revision, $candidate['payloadDigest'], count($items)],
                );
                foreach ($items as $ordinal => $item) {
                    $repository->execute(
                        'INSERT INTO `' . $itemTable . '` '
                            . '(`snapshot_id`,`ordinal`,`item_kind`,`item_key`,`cover_class_photo_id`,`photo_ids_json`,`payload_json`,`payload_digest`,`created_at`) '
                            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                        [
                            $snapshotBinary,
                            $ordinal,
                            $item['itemKind'],
                            $item['itemKey'],
                            $item['coverPhotoId'] === null ? null : DomainSupport::idToBinary($item['coverPhotoId']),
                            self::canonicalJson($item['photoIds']),
                            self::canonicalJson($item['payload']),
                            $item['payloadDigest'],
                        ],
                    );
                }
                $inserted = $repository->fetchOne(
                    'SELECT COUNT(*) AS `count` FROM `' . $itemTable . '` WHERE `snapshot_id`=? FOR UPDATE',
                    [$snapshotBinary],
                );
                if ((int) ($inserted['count'] ?? -1) !== count($items)) {
                    throw new \RuntimeException('class_archive_collection_snapshot_item_count_mismatch');
                }
                $snapshotIds[$kind] = $snapshotBinary;
            }

            // All candidates are now complete but remain invisible.  Move any
            // prior active versions aside before making the new bundle active;
            // the transaction boundary guarantees readers see either every old
            // pointer or every new pointer, never a mixed revision.
            foreach (self::PROJECTION_KINDS as $kind) {
                $pointer = $pointers[$kind] ?? null;
                if ($pointer === null) {
                    continue;
                }
                $prior = $pointer['active_snapshot_id'] ?? null;
                if (!is_string($prior) || strlen($prior) !== 16) {
                    throw new \RuntimeException('class_archive_collection_snapshot_pointer_invalid');
                }
                $changed = $repository->execute(
                    'UPDATE `' . $snapshotTable . '` SET `state`=?,`superseded_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? AND `state`=?',
                    ['SUPERSEDED', $prior, $storedScope, $kind, 'ACTIVE'],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_collection_snapshot_active_pointer_drift');
                }
            }

            foreach (self::PROJECTION_KINDS as $kind) {
                $snapshotBinary = $snapshotIds[$kind];
                $activated = $repository->execute(
                    'UPDATE `' . $snapshotTable . '` SET `state`=?,`published_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `snapshot_id`=? AND `scope`=? AND `projection_kind`=? AND `state`=?',
                    ['ACTIVE', $snapshotBinary, $storedScope, $kind, 'BUILDING'],
                );
                if ($activated !== 1) {
                    throw new \RuntimeException('class_archive_collection_snapshot_publish_failed');
                }
                $repository->execute(
                    'INSERT INTO `' . $pointerTable . '` '
                        . '(`scope`,`projection_kind`,`active_snapshot_id`,`active_revision`,`activated_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                        . 'ON DUPLICATE KEY UPDATE `active_snapshot_id`=VALUES(`active_snapshot_id`),`active_revision`=VALUES(`active_revision`),'
                        . '`activated_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6)',
                    [$storedScope, $kind, $snapshotBinary, $revision],
                );
            }

            $result = [];
            foreach (self::PROJECTION_KINDS as $kind) {
                $result[$kind] = self::publishResult(
                    $snapshotIds[$kind],
                    $storedScope,
                    $kind,
                    $revision,
                    count((array) $prepared[$kind]['items']),
                    true,
                );
            }
            return $result;
        });
    }

    /**
     * Read the active immutable snapshot. The callback is mandatory for an
     * HTTP/public caller and must return an already policy-rechecked public DTO
     * or null to suppress one stale/invisible item. It is deliberately run
     * after integrity verification, never used as a fallback builder.
     *
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return array{snapshotId:string,scope:string,projectionKind:string,revision:string,items:list<array<string,mixed>>}
     */
    public function activeSnapshot(string $scope, string $projectionKind, callable $currentAclRecheck): array
    {
        $storedScope = self::normalizeStoredScope($scope);
        $projectionKind = self::normalizeProjectionKind($projectionKind);
        $snapshot = $this->loadActiveSnapshot($storedScope, $projectionKind);
        $items = $snapshot['items'];
        $public = [];
        foreach ($items as $item) {
            $value = $currentAclRecheck($item);
            if ($value === null) {
                continue;
            }
            if (!is_array($value)) {
                throw new \RuntimeException('class_archive_collection_snapshot_acl_recheck_invalid');
            }
            $public[] = $value;
        }
        return [
            'snapshotId' => $snapshot['snapshotId'],
            'scope' => self::publicScope($storedScope),
            'projectionKind' => $projectionKind,
            'revision' => bin2hex($snapshot['revision']),
            'items' => $public,
        ];
    }

    /**
     * Read-only health of all pointer rows for one scope. It intentionally
     * does not activate, repair, or rebuild a snapshot.
     *
     * @return array{scope:string,items:list<array{projectionKind:string,state:string,snapshotId:?string,revision:?string,itemCount:int}>}
     */
    public function state(string $scope): array
    {
        $storedScope = self::normalizeStoredScope($scope);
        $snapshotTable = DomainSupport::table($this->repository, 'collection_snapshot');
        $pointerTable = DomainSupport::table($this->repository, 'collection_snapshot_pointer');
        $rows = $this->repository->fetchAll(
            'SELECT p.`projection_kind`,p.`active_snapshot_id`,p.`active_revision`,s.`state`,s.`item_count` '
                . 'FROM `' . $pointerTable . '` p LEFT JOIN `' . $snapshotTable . '` s '
                . 'ON s.`snapshot_id`=p.`active_snapshot_id` AND s.`scope`=p.`scope` AND s.`projection_kind`=p.`projection_kind` '
                . 'WHERE p.`scope`=? ORDER BY p.`projection_kind` ASC',
            [$storedScope],
        );
        $items = [];
        foreach ($rows as $row) {
            $kind = $row['projection_kind'] ?? null;
            $snapshotId = $row['active_snapshot_id'] ?? null;
            $revision = $row['active_revision'] ?? null;
            $state = $row['state'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::PROJECTION_KINDS, true)) {
                throw new \RuntimeException('class_archive_collection_snapshot_state_invalid');
            }
            $valid = is_string($snapshotId) && strlen($snapshotId) === 16
                && is_string($revision) && strlen($revision) === 32
                && $state === 'ACTIVE';
            $items[] = [
                'projectionKind' => $kind,
                'state' => $valid ? 'ACTIVE' : 'UNAVAILABLE',
                'snapshotId' => $valid ? DomainSupport::binaryToId($snapshotId) : null,
                'revision' => $valid ? bin2hex($revision) : null,
                'itemCount' => $valid ? (int) ($row['item_count'] ?? 0) : 0,
            ];
        }
        return ['scope' => self::publicScope($storedScope), 'items' => $items];
    }

    /**
     * Pin a currently visible item. Snapshot references are stable keys, not
     * active snapshot ids, so the pin can survive a later, safe rotation.
     *
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return array{pinId:string,ordinal:int,projectionKind:string,item:array<string,mixed>}
     */
    public function pin(
        int $principalId,
        string $scope,
        string $projectionKind,
        string $itemKind,
        string $itemKey,
        callable $currentAclRecheck,
    ): array {
        self::requirePrincipalId($principalId);
        [$storedScope, $projectionKind, $itemKind, $itemKey, $public] = $this->requireVisibleItem(
            $scope,
            $projectionKind,
            $itemKind,
            $itemKey,
            $currentAclRecheck,
        );
        return $this->repository->transaction(function (Repository $repository) use (
            $principalId,
            $storedScope,
            $projectionKind,
            $itemKind,
            $itemKey,
            $public,
        ): array {
            $table = DomainSupport::table($repository, 'collection_pin');
            $existing = $repository->fetchOne(
                'SELECT `pin_id`,`ordinal` FROM `' . $table . '` WHERE `principal_id`=? AND `scope`=? '
                    . 'AND `projection_kind`=? AND `item_kind`=? AND `item_key`=? AND `state`=? FOR UPDATE',
                [$principalId, $storedScope, $projectionKind, $itemKind, $itemKey, 'ACTIVE'],
            );
            if ($existing !== null) {
                $pinId = $existing['pin_id'] ?? null;
                if (!is_string($pinId) || strlen($pinId) !== 16) {
                    throw new \RuntimeException('class_archive_collection_pin_existing_invalid');
                }
                return [
                    'pinId' => DomainSupport::binaryToId($pinId),
                    'ordinal' => (int) ($existing['ordinal'] ?? 0),
                    'projectionKind' => $projectionKind,
                    'item' => $public,
                ];
            }
            $next = $repository->fetchOne(
                'SELECT COALESCE(MAX(`ordinal`),0)+1 AS `ordinal` FROM `' . $table . '` '
                    . 'WHERE `principal_id`=? AND `scope`=? AND `state`=? FOR UPDATE',
                [$principalId, $storedScope, 'ACTIVE'],
            );
            $ordinal = (int) ($next['ordinal'] ?? 0);
            if ($ordinal < 1 || $ordinal >= 1000000 || $ordinal > self::MAX_PINS) {
                throw new \RuntimeException('class_archive_collection_pin_limit');
            }
            $pinId = DomainSupport::generateId();
            $repository->execute(
                'INSERT INTO `' . $table . '` '
                    . '(`pin_id`,`principal_id`,`scope`,`projection_kind`,`item_kind`,`item_key`,`ordinal`,`state`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [DomainSupport::idToBinary($pinId), $principalId, $storedScope, $projectionKind, $itemKind, $itemKey, $ordinal],
            );
            return [
                'pinId' => $pinId,
                'ordinal' => $ordinal,
                'projectionKind' => $projectionKind,
                'item' => $public,
            ];
        });
    }

    /** @return array{removed:bool} */
    public function unpin(int $principalId, string $scope, string $projectionKind, string $itemKind, string $itemKey): array
    {
        self::requirePrincipalId($principalId);
        $storedScope = self::normalizeStoredScope($scope);
        $projectionKind = self::normalizeProjectionKind($projectionKind);
        $itemKind = self::normalizeItemKind($itemKind);
        $itemKey = self::normalizeItemKey($itemKey);
        return $this->repository->transaction(function (Repository $repository) use ($principalId, $storedScope, $projectionKind, $itemKind, $itemKey): array {
            $table = DomainSupport::table($repository, 'collection_pin');
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`removed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `principal_id`=? AND `scope`=? AND `projection_kind`=? AND `item_kind`=? AND `item_key`=? AND `state`=?',
                ['REMOVED', $principalId, $storedScope, $projectionKind, $itemKind, $itemKey, 'ACTIVE'],
            );
            return ['removed' => $changed === 1];
        });
    }

    /**
     * @param list<array{projectionKind:string,itemKind:string,itemKey:string}> $targets
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return array{reordered:bool,count:int}
     */
    public function reorderPins(int $principalId, string $scope, array $targets, callable $currentAclRecheck): array
    {
        self::requirePrincipalId($principalId);
        $storedScope = self::normalizeStoredScope($scope);
        if ($targets === [] || count($targets) > self::MAX_PINS) {
            throw new \InvalidArgumentException('class_archive_collection_pin_reorder_invalid');
        }
        $normalized = [];
        $seen = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                throw new \InvalidArgumentException('class_archive_collection_pin_reorder_invalid');
            }
            $kind = self::normalizeProjectionKind($target['projectionKind'] ?? '');
            $itemKind = self::normalizeItemKind($target['itemKind'] ?? '');
            $itemKey = self::normalizeItemKey($target['itemKey'] ?? '');
            $key = $kind . ':' . $itemKind . ':' . $itemKey;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('class_archive_collection_pin_reorder_duplicate');
            }
            $seen[$key] = true;
            // Do not permit an old pin to become an ordering oracle after an
            // ACL revocation or a failed active snapshot.
            $this->requireVisibleItem($storedScope, $kind, $itemKind, $itemKey, $currentAclRecheck);
            $normalized[] = ['projectionKind' => $kind, 'itemKind' => $itemKind, 'itemKey' => $itemKey, 'key' => $key];
        }

        return $this->repository->transaction(function (Repository $repository) use ($principalId, $storedScope, $normalized, $seen): array {
            $table = DomainSupport::table($repository, 'collection_pin');
            $rows = $repository->fetchAll(
                'SELECT `pin_id`,`projection_kind`,`item_kind`,`item_key` FROM `' . $table . '` '
                    . 'WHERE `principal_id`=? AND `scope`=? AND `state`=? ORDER BY `ordinal` ASC FOR UPDATE',
                [$principalId, $storedScope, 'ACTIVE'],
            );
            if (count($rows) !== count($normalized)) {
                throw new \RuntimeException('class_archive_collection_pin_reorder_set_changed');
            }
            $byKey = [];
            foreach ($rows as $row) {
                $pinId = $row['pin_id'] ?? null;
                $key = (string) ($row['projection_kind'] ?? '') . ':' . (string) ($row['item_kind'] ?? '') . ':' . (string) ($row['item_key'] ?? '');
                if (!is_string($pinId) || strlen($pinId) !== 16 || isset($byKey[$key]) || !isset($seen[$key])) {
                    throw new \RuntimeException('class_archive_collection_pin_reorder_set_changed');
                }
                $byKey[$key] = $pinId;
            }
            $repository->execute(
                'UPDATE `' . $table . '` SET `ordinal`=`ordinal`+500000,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `principal_id`=? AND `scope`=? AND `state`=?',
                [$principalId, $storedScope, 'ACTIVE'],
            );
            foreach ($normalized as $ordinal => $target) {
                $changed = $repository->execute(
                    'UPDATE `' . $table . '` SET `ordinal`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `pin_id`=? AND `state`=?',
                    [$ordinal + 1, $byKey[$target['key']], 'ACTIVE'],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_collection_pin_reorder_race');
                }
            }
            return ['reordered' => true, 'count' => count($normalized)];
        });
    }

    /**
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return array{items:list<array{pinId:string,ordinal:int,projectionKind:string,item:array<string,mixed>}>}
     */
    public function pins(int $principalId, string $scope, callable $currentAclRecheck): array
    {
        self::requirePrincipalId($principalId);
        $storedScope = self::normalizeStoredScope($scope);
        $table = DomainSupport::table($this->repository, 'collection_pin');
        $rows = $this->repository->fetchAll(
            'SELECT `pin_id`,`projection_kind`,`item_kind`,`item_key`,`ordinal` FROM `' . $table . '` '
                . 'WHERE `principal_id`=? AND `scope`=? AND `state`=? ORDER BY `ordinal` ASC LIMIT ' . self::MAX_PINS,
            [$principalId, $storedScope, 'ACTIVE'],
        );
        $items = [];
        foreach ($rows as $row) {
            $pinId = $row['pin_id'] ?? null;
            if (!is_string($pinId) || strlen($pinId) !== 16) {
                throw new \RuntimeException('class_archive_collection_pin_row_invalid');
            }
            $projectionKind = self::normalizeProjectionKind((string) ($row['projection_kind'] ?? ''));
            try {
                [, , , , $public] = $this->requireVisibleItem(
                    $storedScope,
                    $projectionKind,
                    (string) ($row['item_kind'] ?? ''),
                    (string) ($row['item_key'] ?? ''),
                    $currentAclRecheck,
                );
            } catch (\RuntimeException $error) {
                // Pins are user preference, never an ACL oracle. A missing,
                // retired, stale, or now-hidden target is silently omitted;
                // GET does not mutate the retained pin record.
                if (str_starts_with($error->getMessage(), 'class_archive_collection_snapshot_')) {
                    continue;
                }
                throw $error;
            }
            $items[] = [
                'pinId' => DomainSupport::binaryToId($pinId),
                'ordinal' => (int) ($row['ordinal'] ?? 0),
                // This validated public discriminator lets a reorder client
                // address the pin without receiving source/storage ids.
                'projectionKind' => $projectionKind,
                'item' => $public,
            ];
        }
        return ['items' => $items];
    }

    /**
     * Return only the caller's currently actionable feedback.  An old HIDE or
     * LESS_LIKE record is not an authorization capability: the target must
     * still exist in the active role-scoped snapshot and pass the same current
     * ACL recheck used by a normal Home card before it is returned.
     *
     * This makes feedback useful to the presentation layer without exposing a
     * historical collection target after an ACL change, snapshot rotation, or
     * identity revocation.  The method intentionally does not repair or
     * retract stale rows on GET; preferences are durable history, not a
     * background mutation side effect.
     *
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return list<array{projectionKind:string,itemKind:string,itemKey:string,feedback:string,item:array<string,mixed>}>
     */
    public function activeFeedback(int $principalId, string $scope, callable $currentAclRecheck): array
    {
        self::requirePrincipalId($principalId);
        $storedScope = self::normalizeStoredScope($scope);
        $table = DomainSupport::table($this->repository, 'collection_feedback');
        $rows = $this->repository->fetchAll(
            'SELECT `projection_kind`,`item_kind`,`item_key`,`feedback_kind` FROM `' . $table . '` '
                . 'WHERE `principal_id`=? AND `scope`=? AND `state`=? '
                . 'ORDER BY `projection_kind` ASC,`item_kind` ASC,`item_key` ASC LIMIT ' . (self::MAX_FEEDBACK + 1),
            [$principalId, $storedScope, 'ACTIVE'],
        );
        if (count($rows) > self::MAX_FEEDBACK) {
            throw new \RuntimeException('class_archive_collection_feedback_limit');
        }

        /** @var array<string,list<array{itemKind:string,itemKey:string,feedback:string}>> $byProjection */
        $byProjection = [];
        foreach ($rows as $row) {
            $projectionKind = self::normalizeProjectionKind((string) ($row['projection_kind'] ?? ''));
            $itemKind = self::normalizeItemKind((string) ($row['item_kind'] ?? ''));
            $itemKey = self::normalizeItemKey((string) ($row['item_key'] ?? ''));
            $feedback = strtoupper(trim((string) ($row['feedback_kind'] ?? '')));
            if (!in_array($feedback, [self::FEEDBACK_HIDE, self::FEEDBACK_LESS_LIKE, self::FEEDBACK_LIKE], true)) {
                throw new \RuntimeException('class_archive_collection_feedback_row_invalid');
            }
            $byProjection[$projectionKind] ??= [];
            $byProjection[$projectionKind][] = [
                'itemKind' => $itemKind,
                'itemKey' => $itemKey,
                'feedback' => $feedback,
            ];
        }

        $result = [];
        foreach ($byProjection as $projectionKind => $targets) {
            $snapshot = $this->activeSnapshot($storedScope, $projectionKind, $currentAclRecheck);
            $visible = [];
            foreach ($snapshot['items'] as $item) {
                if (!is_array($item)
                    || !is_string($item['itemKind'] ?? null)
                    || !is_string($item['itemKey'] ?? null)) {
                    throw new \RuntimeException('class_archive_collection_snapshot_item_invalid');
                }
                $key = $item['itemKind'] . "\0" . $item['itemKey'];
                if (isset($visible[$key])) {
                    throw new \RuntimeException('class_archive_collection_snapshot_item_duplicate');
                }
                $visible[$key] = $item;
            }
            foreach ($targets as $target) {
                $key = $target['itemKind'] . "\0" . $target['itemKey'];
                $item = $visible[$key] ?? null;
                if (!is_array($item)) {
                    // A no-longer-visible target is intentionally inert.  Do
                    // not reveal whether it was removed, became inaccessible,
                    // or simply rotated out of the current snapshot.
                    continue;
                }
                $result[] = [
                    'projectionKind' => $projectionKind,
                    'itemKind' => $target['itemKind'],
                    'itemKey' => $target['itemKey'],
                    'feedback' => $target['feedback'],
                    'item' => $item,
                ];
            }
        }
        return $result;
    }

    /**
     * @param callable(CollectionSnapshotItem):(?array) $currentAclRecheck
     * @return array{feedbackId:string,feedback:string,item:array<string,mixed>}
     */
    public function setFeedback(
        int $principalId,
        string $scope,
        string $projectionKind,
        string $itemKind,
        string $itemKey,
        string $feedbackKind,
        callable $currentAclRecheck,
    ): array {
        self::requirePrincipalId($principalId);
        [$storedScope, $projectionKind, $itemKind, $itemKey, $public] = $this->requireVisibleItem(
            $scope,
            $projectionKind,
            $itemKind,
            $itemKey,
            $currentAclRecheck,
        );
        $feedbackKind = strtoupper(trim($feedbackKind));
        if (!in_array($feedbackKind, [self::FEEDBACK_HIDE, self::FEEDBACK_LESS_LIKE, self::FEEDBACK_LIKE], true)) {
            throw new \InvalidArgumentException('class_archive_collection_feedback_kind_invalid');
        }
        return $this->repository->transaction(function (Repository $repository) use (
            $principalId,
            $storedScope,
            $projectionKind,
            $itemKind,
            $itemKey,
            $feedbackKind,
            $public,
        ): array {
            $table = DomainSupport::table($repository, 'collection_feedback');
            $existing = $repository->fetchOne(
                'SELECT `feedback_id` FROM `' . $table . '` WHERE `principal_id`=? AND `scope`=? AND `projection_kind`=? '
                    . 'AND `item_kind`=? AND `item_key`=? AND `state`=? FOR UPDATE',
                [$principalId, $storedScope, $projectionKind, $itemKind, $itemKey, 'ACTIVE'],
            );
            if ($existing !== null) {
                $feedbackId = $existing['feedback_id'] ?? null;
                if (!is_string($feedbackId) || strlen($feedbackId) !== 16) {
                    throw new \RuntimeException('class_archive_collection_feedback_existing_invalid');
                }
                $changed = $repository->execute(
                    'UPDATE `' . $table . '` SET `feedback_kind`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `feedback_id`=? AND `state`=?',
                    [$feedbackKind, $feedbackId, 'ACTIVE'],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_collection_feedback_update_race');
                }
                return [
                    'feedbackId' => DomainSupport::binaryToId($feedbackId),
                    'feedback' => $feedbackKind,
                    'item' => $public,
                ];
            }
            $feedbackId = DomainSupport::generateId();
            $repository->execute(
                'INSERT INTO `' . $table . '` '
                    . '(`feedback_id`,`principal_id`,`scope`,`projection_kind`,`item_kind`,`item_key`,`feedback_kind`,`state`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, 'ACTIVE', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [DomainSupport::idToBinary($feedbackId), $principalId, $storedScope, $projectionKind, $itemKind, $itemKey, $feedbackKind],
            );
            return ['feedbackId' => $feedbackId, 'feedback' => $feedbackKind, 'item' => $public];
        });
    }

    /** @return array{retracted:bool} */
    public function clearFeedback(int $principalId, string $scope, string $projectionKind, string $itemKind, string $itemKey): array
    {
        self::requirePrincipalId($principalId);
        $storedScope = self::normalizeStoredScope($scope);
        $projectionKind = self::normalizeProjectionKind($projectionKind);
        $itemKind = self::normalizeItemKind($itemKind);
        $itemKey = self::normalizeItemKey($itemKey);
        return $this->repository->transaction(function (Repository $repository) use ($principalId, $storedScope, $projectionKind, $itemKind, $itemKey): array {
            $table = DomainSupport::table($repository, 'collection_feedback');
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`retracted_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `principal_id`=? AND `scope`=? AND `projection_kind`=? AND `item_kind`=? AND `item_key`=? AND `state`=?',
                ['RETRACTED', $principalId, $storedScope, $projectionKind, $itemKind, $itemKey, 'ACTIVE'],
            );
            return ['retracted' => $changed === 1];
        });
    }

    /**
     * Idempotent maintenance watermark claim. It supplies the persistence
     * primitive for a later runner; this class deliberately does not decide a
     * schedule or execute a build from a user GET.
     *
     * @return array{claimed:bool,state:string}
     */
    public function claimMaintenance(string $maintenanceKey, ?string $inputRevision): array
    {
        $maintenanceKey = self::normalizeMaintenanceKey($maintenanceKey);
        $revision = $inputRevision === null ? null : self::normalizeRevision($inputRevision);
        return $this->repository->transaction(function (Repository $repository) use ($maintenanceKey, $revision): array {
            $table = DomainSupport::table($repository, 'collection_maintenance_state');
            $row = $repository->fetchOne(
                'SELECT `state`,`last_input_revision` FROM `' . $table . '` WHERE `maintenance_key`=? FOR UPDATE',
                [$maintenanceKey],
            );
            if ($row !== null) {
                $state = (string) ($row['state'] ?? '');
                $last = $row['last_input_revision'] ?? null;
                if ($state === 'RUNNING') {
                    return ['claimed' => false, 'state' => 'RUNNING'];
                }
                if ($state === 'COMPLETE' && (($revision === null && $last === null) || (is_string($last) && $revision !== null && hash_equals($last, $revision)))) {
                    return ['claimed' => false, 'state' => 'COMPLETE'];
                }
                $repository->execute(
                    'UPDATE `' . $table . '` SET `state`=?,`last_input_revision`=?,`last_snapshot_id`=NULL,`started_at`=UTC_TIMESTAMP(6),'
                        . '`completed_at`=NULL,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) WHERE `maintenance_key`=?',
                    ['RUNNING', $revision, $maintenanceKey],
                );
                return ['claimed' => true, 'state' => 'RUNNING'];
            }
            $repository->execute(
                'INSERT INTO `' . $table . '` (`maintenance_key`,`state`,`last_input_revision`,`started_at`,`created_at`,`updated_at`) '
                    . "VALUES (?, 'RUNNING', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$maintenanceKey, $revision],
            );
            return ['claimed' => true, 'state' => 'RUNNING'];
        });
    }

    /** @return array{completed:bool} */
    public function completeMaintenance(string $maintenanceKey, ?string $snapshotId = null): array
    {
        $maintenanceKey = self::normalizeMaintenanceKey($maintenanceKey);
        $snapshotBinary = $snapshotId === null ? null : DomainSupport::idToBinary($snapshotId);
        return $this->repository->transaction(function (Repository $repository) use ($maintenanceKey, $snapshotBinary): array {
            $table = DomainSupport::table($repository, 'collection_maintenance_state');
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`last_snapshot_id`=?,`completed_at`=UTC_TIMESTAMP(6),`last_error_code`=NULL,'
                    . '`updated_at`=UTC_TIMESTAMP(6) WHERE `maintenance_key`=? AND `state`=?',
                ['COMPLETE', $snapshotBinary, $maintenanceKey, 'RUNNING'],
            );
            return ['completed' => $changed === 1];
        });
    }

    /** @return array{failed:bool} */
    public function failMaintenance(string $maintenanceKey, string $errorCode): array
    {
        $maintenanceKey = self::normalizeMaintenanceKey($maintenanceKey);
        if (preg_match('/\A[A-Z][A-Z0-9_]{1,63}\z/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('class_archive_collection_maintenance_error_invalid');
        }
        return $this->repository->transaction(function (Repository $repository) use ($maintenanceKey, $errorCode): array {
            $table = DomainSupport::table($repository, 'collection_maintenance_state');
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`completed_at`=UTC_TIMESTAMP(6),`last_error_code`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `maintenance_key`=? AND `state`=?',
                ['FAILED', $errorCode, $maintenanceKey, 'RUNNING'],
            );
            return ['failed' => $changed === 1];
        });
    }

    /** @return array{0:string,1:string,2:string,3:string,4:array<string,mixed>} */
    private function requireVisibleItem(
        string $scope,
        string $projectionKind,
        string $itemKind,
        string $itemKey,
        callable $currentAclRecheck,
    ): array {
        $storedScope = self::normalizeStoredScope($scope);
        $projectionKind = self::normalizeProjectionKind($projectionKind);
        $itemKind = self::normalizeItemKind($itemKind);
        $itemKey = self::normalizeItemKey($itemKey);
        $snapshot = $this->activeSnapshot($storedScope, $projectionKind, $currentAclRecheck);
        foreach ($snapshot['items'] as $item) {
            if (($item['itemKind'] ?? null) === $itemKind && ($item['itemKey'] ?? null) === $itemKey) {
                return [$storedScope, $projectionKind, $itemKind, $itemKey, $item];
            }
        }
        throw new \RuntimeException('class_archive_collection_snapshot_item_not_visible');
    }

    /** @return array{snapshotId:string,revision:string,items:list<CollectionSnapshotItem>} */
    private function loadActiveSnapshot(string $storedScope, string $projectionKind): array
    {
        $snapshotTable = DomainSupport::table($this->repository, 'collection_snapshot');
        $pointerTable = DomainSupport::table($this->repository, 'collection_snapshot_pointer');
        $itemTable = DomainSupport::table($this->repository, 'collection_snapshot_item');
        $row = $this->repository->fetchOne(
            'SELECT p.`active_snapshot_id`,p.`active_revision`,s.`input_revision`,s.`payload_digest`,s.`item_count`,s.`state` '
                . 'FROM `' . $pointerTable . '` p INNER JOIN `' . $snapshotTable . '` s '
                . 'ON s.`snapshot_id`=p.`active_snapshot_id` AND s.`scope`=p.`scope` AND s.`projection_kind`=p.`projection_kind` '
                . 'WHERE p.`scope`=? AND p.`projection_kind`=? LIMIT 1',
            [$storedScope, $projectionKind],
        );
        if ($row === null
            || ($row['state'] ?? null) !== 'ACTIVE'
            || !is_string($row['active_snapshot_id'] ?? null)
            || strlen((string) $row['active_snapshot_id']) !== 16
            || !is_string($row['active_revision'] ?? null)
            || strlen((string) $row['active_revision']) !== 32
            || !is_string($row['input_revision'] ?? null)
            || !hash_equals((string) $row['active_revision'], (string) $row['input_revision'])
            || !is_string($row['payload_digest'] ?? null)
            || strlen((string) $row['payload_digest']) !== 32
        ) {
            throw new \RuntimeException('class_archive_collection_snapshot_unavailable');
        }
        $snapshotBinary = (string) $row['active_snapshot_id'];
        $rows = $this->repository->fetchAll(
            'SELECT `ordinal`,`item_kind`,`item_key`,`cover_class_photo_id`,`photo_ids_json`,`payload_json`,`payload_digest` '
                . 'FROM `' . $itemTable . '` WHERE `snapshot_id`=? ORDER BY `ordinal` ASC',
            [$snapshotBinary],
        );
        if (count($rows) !== (int) ($row['item_count'] ?? -1)) {
            throw new \RuntimeException('class_archive_collection_snapshot_item_count_mismatch');
        }
        $normalized = [];
        $items = [];
        foreach ($rows as $ordinal => $item) {
            $entry = self::decodeStoredItem($snapshotBinary, $ordinal, $item);
            $digest = hash('sha256', self::canonicalJson([
                'itemKind' => $entry->itemKind(),
                'itemKey' => $entry->itemKey(),
                'coverPhotoId' => $entry->coverPhotoId(),
                'photoIds' => $entry->photoIds(),
                'payload' => $entry->payload(),
            ]), true);
            $storedDigest = $item['payload_digest'] ?? null;
            if (!is_string($storedDigest) || strlen($storedDigest) !== 32 || !hash_equals($storedDigest, $digest)) {
                throw new \RuntimeException('class_archive_collection_snapshot_item_digest_mismatch');
            }
            $normalized[] = [
                'itemKind' => $entry->itemKind(),
                'itemKey' => $entry->itemKey(),
                'coverPhotoId' => $entry->coverPhotoId(),
                'photoIds' => $entry->photoIds(),
                'payload' => $entry->payload(),
                'payloadDigest' => $digest,
            ];
            $items[] = $entry;
        }
        $expected = hash('sha256', self::canonicalJson([
            'scope' => $storedScope,
            'projectionKind' => $projectionKind,
            'revision' => bin2hex((string) $row['input_revision']),
            'items' => self::snapshotDigestItems($normalized),
        ]), true);
        if (!hash_equals((string) $row['payload_digest'], $expected)) {
            throw new \RuntimeException('class_archive_collection_snapshot_digest_mismatch');
        }
        return [
            'snapshotId' => DomainSupport::binaryToId($snapshotBinary),
            'revision' => (string) $row['input_revision'],
            'items' => $items,
        ];
    }

    /** @return CollectionSnapshotItem */
    private static function decodeStoredItem(string $snapshotBinary, int $ordinal, array $row): CollectionSnapshotItem
    {
        $kind = self::normalizeItemKind((string) ($row['item_kind'] ?? ''));
        $key = self::normalizeItemKey((string) ($row['item_key'] ?? ''));
        $coverBinary = $row['cover_class_photo_id'] ?? null;
        $cover = $coverBinary === null ? null : (is_string($coverBinary) && strlen($coverBinary) === 16 ? DomainSupport::binaryToId($coverBinary) : null);
        if ($coverBinary !== null && $cover === null) {
            throw new \RuntimeException('class_archive_collection_snapshot_cover_invalid');
        }
        try {
            $photoIds = json_decode((string) ($row['photo_ids_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('class_archive_collection_snapshot_json_invalid');
        }
        $normalized = self::normalizeOneItem([
            'itemKind' => $kind,
            'itemKey' => $key,
            'coverPhotoId' => $cover,
            'photoIds' => $photoIds,
            'payload' => $payload,
        ]);
        if ($ordinal !== (int) ($row['ordinal'] ?? -1)) {
            throw new \RuntimeException('class_archive_collection_snapshot_ordinal_invalid');
        }
        return new CollectionSnapshotItem(
            DomainSupport::binaryToId($snapshotBinary),
            $ordinal,
            $normalized['itemKind'],
            $normalized['itemKey'],
            $normalized['coverPhotoId'],
            $normalized['photoIds'],
            $normalized['payload'],
        );
    }

    /** @return array{snapshotId:string,scope:string,projectionKind:string,revision:string,itemCount:int,published:bool} */
    private static function publishResult(string $snapshotBinary, string $storedScope, string $projectionKind, string $revision, int $itemCount, bool $published): array
    {
        return [
            'snapshotId' => DomainSupport::binaryToId($snapshotBinary),
            'scope' => self::publicScope($storedScope),
            'projectionKind' => $projectionKind,
            'revision' => bin2hex($revision),
            'itemCount' => $itemCount,
            'published' => $published,
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function normalizeItems(array $items): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_limit');
        }
        $normalized = [];
        $seen = [];
        $totalPhotos = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_item_invalid');
            }
            $entry = self::normalizeOneItem($item);
            $key = $entry['itemKind'] . ':' . $entry['itemKey'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_item_duplicate');
            }
            $seen[$key] = true;
            $totalPhotos += count($entry['photoIds']);
            if ($totalPhotos > self::MAX_TOTAL_PHOTO_IDS) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_photo_limit');
            }
            $entry['payloadDigest'] = hash('sha256', self::canonicalJson([
                'itemKind' => $entry['itemKind'],
                'itemKey' => $entry['itemKey'],
                'coverPhotoId' => $entry['coverPhotoId'],
                'photoIds' => $entry['photoIds'],
                'payload' => $entry['payload'],
            ]), true);
            $normalized[] = $entry;
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private static function snapshotDigestItems(array $items): array
    {
        return array_map(static fn(array $item): array => [
            'itemKind' => $item['itemKind'],
            'itemKey' => $item['itemKey'],
            'coverPhotoId' => $item['coverPhotoId'],
            'photoIds' => $item['photoIds'],
            'payload' => $item['payload'],
        ], $items);
    }

    /** @param array<string,mixed> $item @return array{itemKind:string,itemKey:string,coverPhotoId:?string,photoIds:list<string>,payload:array<string,mixed>} */
    private static function normalizeOneItem(array $item): array
    {
        $allowed = ['itemKind' => true, 'itemKey' => true, 'coverPhotoId' => true, 'photoIds' => true, 'payload' => true];
        foreach ($item as $key => $_) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_item_shape_invalid');
            }
        }
        $kind = self::normalizeItemKind($item['itemKind'] ?? '');
        $key = self::normalizeItemKey($item['itemKey'] ?? '');
        $rawPhotoIds = $item['photoIds'] ?? [];
        if (!is_array($rawPhotoIds) || count($rawPhotoIds) > self::MAX_PHOTO_IDS_PER_ITEM) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_photo_ids_invalid');
        }
        $photoIds = [];
        $seen = [];
        foreach ($rawPhotoIds as $photoId) {
            if (!is_string($photoId)) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_photo_ids_invalid');
            }
            $binary = DomainSupport::idToBinary($photoId);
            $canonical = DomainSupport::binaryToId($binary);
            if (isset($seen[$canonical])) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_photo_ids_duplicate');
            }
            $seen[$canonical] = true;
            $photoIds[] = $canonical;
        }
        if ($kind !== self::ITEM_SEARCH_SUGGESTION && $photoIds === []) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_photo_required');
        }
        $cover = $item['coverPhotoId'] ?? null;
        if ($cover !== null) {
            if (!is_string($cover)) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_cover_invalid');
            }
            $cover = DomainSupport::binaryToId(DomainSupport::idToBinary($cover));
            if (!isset($seen[$cover])) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_cover_membership_invalid');
            }
        } elseif ($photoIds !== []) {
            $cover = $photoIds[0];
        }
        $payload = $item['payload'] ?? [];
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
        }
        self::assertSafePayload($payload, 0);
        if (strlen(self::canonicalJson($payload)) > 8192) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_limit');
        }
        return [
            'itemKind' => $kind,
            'itemKey' => $key,
            'coverPhotoId' => $cover,
            'photoIds' => $photoIds,
            'payload' => self::canonicalize($payload),
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function assertSafePayload(array $payload, int $depth): void
    {
        if ($depth > 6 || count($payload) > 64) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
        }
        foreach ($payload as $key => $value) {
            if (!is_string($key) || preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $key) !== 1) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
            }
            $lower = strtolower($key);
            foreach (['piwigo', 'immich', 'principal', 'account', 'seat', 'identity', 'storage', 'path', 'filename', 'token', 'secret', 'checksum', 'embedding', 'owner'] as $forbidden) {
                if (str_contains($lower, $forbidden)) {
                    throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_sensitive_key');
                }
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    if (count($value) > 64) {
                        throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
                    }
                    foreach ($value as $entry) {
                        if (is_array($entry)) {
                            if (array_is_list($entry)) {
                                throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
                            }
                            self::assertSafePayload($entry, $depth + 1);
                        } elseif (!is_scalar($entry) && $entry !== null) {
                            throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
                        }
                    }
                } else {
                    self::assertSafePayload($value, $depth + 1);
                }
            } elseif (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('class_archive_collection_snapshot_payload_invalid');
            }
        }
    }

    private static function normalizeStoredScope(string $scope): string
    {
        $scope = strtoupper(trim($scope));
        return match ($scope) {
            self::SCOPE_FULL => self::SCOPE_FULL,
            self::SCOPE_HERITAGE_ONLY, self::STORED_SCOPE_HERITAGE => self::STORED_SCOPE_HERITAGE,
            default => throw new \InvalidArgumentException('class_archive_collection_snapshot_scope_invalid'),
        };
    }

    private static function publicScope(string $storedScope): string
    {
        return $storedScope === self::STORED_SCOPE_HERITAGE ? self::SCOPE_HERITAGE_ONLY : self::SCOPE_FULL;
    }

    private static function normalizeProjectionKind(mixed $kind): string
    {
        if (!is_string($kind)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_projection_kind_invalid');
        }
        $kind = strtoupper(trim($kind));
        if (!in_array($kind, self::PROJECTION_KINDS, true)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_projection_kind_invalid');
        }
        return $kind;
    }

    private static function normalizeItemKind(mixed $kind): string
    {
        if (!is_string($kind)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_kind_invalid');
        }
        $kind = strtoupper(trim($kind));
        if (!in_array($kind, self::ITEM_KINDS, true)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_kind_invalid');
        }
        return $kind;
    }

    private static function normalizeItemKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_key_invalid');
        }
        $key = trim($key);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,95}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_item_key_invalid');
        }
        return $key;
    }

    private static function normalizeRevision(string $revision): string
    {
        if (strlen($revision) === 32) {
            return $revision;
        }
        $revision = strtolower(trim($revision));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_revision_invalid');
        }
        $binary = hex2bin($revision);
        if (!is_string($binary) || strlen($binary) !== 32) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_revision_invalid');
        }
        return $binary;
    }

    private static function normalizeMaintenanceKey(string $key): string
    {
        $key = strtoupper(trim($key));
        if (preg_match('/\A[A-Z][A-Z0-9_:-]{1,63}\z/D', $key) !== 1) {
            throw new \InvalidArgumentException('class_archive_collection_maintenance_key_invalid');
        }
        return $key;
    }

    private static function requirePrincipalId(int $principalId): void
    {
        if ($principalId <= 0) {
            throw new \RuntimeException('class_archive_collection_principal_unresolved');
        }
    }

    private static function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('class_archive_collection_snapshot_json_encode_failed');
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $entry): mixed => self::canonicalize($entry), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }
        return $value;
    }
}
