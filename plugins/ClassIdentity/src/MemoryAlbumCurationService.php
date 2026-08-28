<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Server-authorized "save memory as album" adapter.
 *
 * An AutoCollection stays a build-owned projection: this service never edits
 * it, its cover, its source reason, or its AI memberships. Instead it creates
 * one distinct Piwigo category beneath exactly one existing era root and adds
 * only image/category relationships. The managed original remains one file.
 */
final class MemoryAlbumCurationService
{
    private const MAX_MEMBERS = 10000;
    private const MEMORY_KEY_PREFIX = 'memory-';

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Persist a stable, shared Album from a current server-side Memory
     * projection. `$memoryProjection` is never browser input: GatewayService
     * obtains it from the current FULL durable aggregate after validating the
     * opaque snapshot item and current policy scope.
     *
     * @param array<string,mixed> $memoryProjection
     * @param array<string,true> $visiblePhotoIds
     * @return array{created:bool,class_album_id:string,title:string,photo_count:int,album_type:string,era:string}
     */
    public function saveFromCurrentMemory(
        int $userId,
        string $memoryKey,
        array $memoryProjection,
        array $visiblePhotoIds,
        ?string $titleOverride,
        ?string $description,
        string $reason,
    ): array {
        [$actor, $albumType, $ownerPrincipalId] = $this->authorizeActor($userId);
        $memoryKey = self::normalizeMemoryKey($memoryKey);
        $expected = self::normalizeProjection($memoryProjection, $memoryKey);
        $visiblePhotoIds = self::normalizeVisiblePhotoIds($visiblePhotoIds);
        $title = DomainSupport::boundedText($titleOverride, 190) ?? $expected['title'];
        $description = DomainSupport::boundedText($description, 4000);
        $reason = Audit::validateReason($reason, true) ?? '';

        return $this->withMemoryLock($memoryKey, function () use (
            $actor,
            $albumType,
            $ownerPrincipalId,
            $memoryKey,
            $expected,
            $visiblePhotoIds,
            $title,
            $description,
            $reason,
        ): array {
            // Resolve and validate the durable Memory before touching a Core
            // table. An opaque item key never gives a caller a source reason,
            // category number, media path, or permission capability.
            $memory = $this->loadAndAssertMemory($expected, $visiblePhotoIds, false);
            $permalink = self::permalinkForSourceReason($memory['source_reason']);
            $rootId = $this->eraRoot($memory['era']);
            $existingCategory = $this->findCategoryByPermalink($permalink);
            if ($existingCategory !== null) {
                return $this->verifyExistingSavedAlbum(
                    $existingCategory,
                    $rootId,
                    $memory,
                    $albumType,
                    $ownerPrincipalId,
                    $title,
                    $description,
                );
            }

            $categoryId = null;
            try {
                $categoryId = $this->createEraChildCategory($title, $description, $rootId, $permalink);
                $this->associateExactMembers((int) $categoryId, $memory['piwigo_image_ids']);

                // The background projection may change while Core performs a
                // non-transactional MyISAM association. Lock/recheck the
                // InnoDB Memory truth immediately before mapping/auditing; a
                // stale or changed memory is compensated, never partially
                // published as a new Album.
                $mapping = $this->repository->transaction(function (Repository $repository) use (
                    $actor,
                    $albumType,
                    $ownerPrincipalId,
                    $memoryKey,
                    $expected,
                    $visiblePhotoIds,
                    $title,
                    $description,
                    $reason,
                    $categoryId,
                ): array {
                    $current = $this->loadAndAssertMemory($expected, $visiblePhotoIds, true);
                    $this->assertExactCategoryMembers((int) $categoryId, $current['piwigo_image_ids']);
                    // Reuse this transaction-aware Repository. Constructing a
                    // second `fromPiwigo()` Repository here would begin an
                    // independent transaction on the same connection while
                    // the durable Memory row is locked.
                    $album = new AlbumService($repository);
                    $mapped = $albumType === 'OFFICIAL'
                        ? $album->ensureMapping(
                            (int) $actor['piwigo_user_id'],
                            (int) $categoryId,
                            'OFFICIAL',
                            $current['era'],
                            null,
                            $description,
                            null,
                            $current['cover_class_photo_id'],
                            $reason,
                        )
                        : $album->ensureOwnedCommunityMapping(
                            (int) $actor['piwigo_user_id'],
                            (int) $categoryId,
                            $current['era'],
                            $description,
                            null,
                            $current['cover_class_photo_id'],
                            $reason,
                        );
                    if (($mapped['album_type'] ?? null) !== $albumType
                        || ($mapped['era'] ?? null) !== $current['era']
                        || ($mapped['owner_principal_id'] ?? null) !== $ownerPrincipalId
                        || ($mapped['description'] ?? null) !== $description
                        || ($mapped['cover_class_photo_id'] ?? null) !== $current['cover_class_photo_id']
                        || ($mapped['state'] ?? null) !== 'ACTIVE') {
                        throw new \RuntimeException('class_archive_memory_album_mapping_drift');
                    }
                    // The Mapping audit gives Piwigo/category context. This
                    // second, high-risk business audit deliberately records
                    // only opaque album/photo identities, era and count—not
                    // the hidden source reason, source path, or full list.
                    (new Audit($repository))->append(DomainSupport::auditActor($actor) + [
                        'action' => 'MEMORY_SAVE_AS_ALBUM',
                        'target_type' => 'ALBUM',
                        'target_id' => (string) $mapped['class_album_id'],
                        'new_value' => [
                            'class_album_id' => (string) $mapped['class_album_id'],
                            'album_type' => $albumType,
                            'era' => (string) $current['era'],
                            'cover_class_photo_id' => (string) $current['cover_class_photo_id'],
                            'item_count' => count($current['photo_ids']),
                            'source_kind' => 'AUTO_COLLECTION',
                        ],
                        'reason' => $reason,
                        'result' => 'SUCCESS',
                    ]);
                    return $mapped;
                });
            } catch (\Throwable $error) {
                if (is_int($categoryId) && $categoryId > 0) {
                    $this->compensateFreshCategory($categoryId);
                }
                throw $error;
            }

            if (!is_array($mapping) || !is_string($mapping['class_album_id'] ?? null)) {
                throw new \RuntimeException('class_archive_memory_album_mapping_invalid');
            }
            invalidate_user_cache();
            return [
                'created' => true,
                'class_album_id' => (string) $mapping['class_album_id'],
                'title' => $title,
                'photo_count' => count($memory['photo_ids']),
                'album_type' => $albumType,
                'era' => (string) $memory['era'],
            ];
        });
    }

    /**
     * @return array{0:array<string,mixed>,1:'OFFICIAL'|'COMMUNITY',2:?int}
     */
    private function authorizeActor(int $userId): array
    {
        $context = Access::resolveAuthorizationContext($userId);
        $role = is_array($context) && is_string($context['role'] ?? null) ? (string) $context['role'] : '';
        if ($role === Access::ROLE_SYSTEM_ADMIN) {
            return [DomainSupport::requireSystemAdmin($userId), 'OFFICIAL', null];
        }
        if (in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true)) {
            $member = DomainSupport::requireMemberRole($userId, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]);
            $principalId = (int) ($member['principal_id'] ?? 0);
            if ($principalId <= 0) {
                throw new \RuntimeException('class_archive_memory_album_member_context_invalid');
            }
            return [$member, 'COMMUNITY', $principalId];
        }
        if ($role === Access::ROLE_FAMILY) {
            // Family can still use pins and feedback as a private arrangement
            // layer, but may not create a shared Album or publish a cover.
            throw new \RuntimeException('class_archive_memory_album_family_private_only');
        }
        throw new \RuntimeException('class_archive_memory_album_role_forbidden');
    }

    /**
     * Resolve the Memory from its durable source reason and bind it to the
     * current full projection passed by GatewayService.
     *
     * @param array{source_reason:string,title:string,subtitle:?string,cover_class_photo_id:string,photo_ids:list<string>} $expected
     * @param array<string,true> $visiblePhotoIds
     * @return array{source_reason:string,title:string,subtitle:?string,cover_class_photo_id:string,photo_ids:list<string>,piwigo_image_ids:list<int>,era:'HERITAGE'|'LIVING'}
     */
    private function loadAndAssertMemory(array $expected, array $visiblePhotoIds, bool $forUpdate): array
    {
        $collectionTable = DomainSupport::table($this->repository, 'auto_collection');
        $memberTable = DomainSupport::table($this->repository, 'auto_collection_photo');
        $row = $this->repository->fetchOne(
            'SELECT `auto_collection_id`,`collection_kind`,`title`,`subtitle`,`source_reason`,`cover_class_photo_id`,`state` '
                . 'FROM `' . $collectionTable . '` WHERE `source_reason`=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$expected['source_reason']],
        );
        if ($row === null
            || ($row['collection_kind'] ?? null) !== 'MEMORY'
            || ($row['state'] ?? null) !== 'ACTIVE'
            || !is_string($row['auto_collection_id'] ?? null)
            || strlen((string) $row['auto_collection_id']) !== 16
            || !is_string($row['cover_class_photo_id'] ?? null)
            || strlen((string) $row['cover_class_photo_id']) !== 16
            || !is_string($row['title'] ?? null)
            || !is_string($row['source_reason'] ?? null)
            || !hash_equals((string) $row['source_reason'], $expected['source_reason'])
            || !hash_equals((string) $row['title'], $expected['title'])
            || (($row['subtitle'] ?? null) !== $expected['subtitle'])
            || !hash_equals(DomainSupport::binaryToId((string) $row['cover_class_photo_id']), $expected['cover_class_photo_id'])) {
            throw new \RuntimeException('class_archive_memory_album_projection_drift');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`ordinal` FROM `' . $memberTable . '` WHERE `auto_collection_id`=? '
                . 'ORDER BY `ordinal` ASC,`class_photo_id` ASC' . ($forUpdate ? ' FOR UPDATE' : ''),
            [(string) $row['auto_collection_id']],
        );
        $photoIds = [];
        $ordinal = 1;
        foreach ($rows as $member) {
            $photoId = $member['class_photo_id'] ?? null;
            if (!is_string($photoId) || strlen($photoId) !== 16 || (int) ($member['ordinal'] ?? 0) !== $ordinal) {
                throw new \RuntimeException('class_archive_memory_album_members_invalid');
            }
            $photoIds[] = DomainSupport::binaryToId($photoId);
            ++$ordinal;
        }
        if ($photoIds === [] || count($photoIds) > self::MAX_MEMBERS || $photoIds !== $expected['photo_ids']) {
            throw new \RuntimeException('class_archive_memory_album_projection_drift');
        }
        if (!in_array($expected['cover_class_photo_id'], $photoIds, true)) {
            throw new \RuntimeException('class_archive_memory_album_cover_invalid');
        }
        foreach ($photoIds as $photoId) {
            if (!isset($visiblePhotoIds[$photoId])) {
                throw new \RuntimeException('class_archive_memory_album_photo_not_visible');
            }
        }
        [$imageIds, $era] = $this->assertPhotosOneSafeEra($photoIds);
        return [
            'source_reason' => (string) $row['source_reason'],
            'title' => (string) $row['title'],
            'subtitle' => $row['subtitle'] === null ? null : (string) $row['subtitle'],
            'cover_class_photo_id' => $expected['cover_class_photo_id'],
            'photo_ids' => $photoIds,
            'piwigo_image_ids' => $imageIds,
            'era' => $era,
        ];
    }

    /** @param list<string> $photoIds @return array{0:list<int>,1:'HERITAGE'|'LIVING'} */
    private function assertPhotosOneSafeEra(array $photoIds): array
    {
        $photoTable = $this->repository->table('photo');
        $archiveTable = $this->repository->table('archive_image');
        $byId = [];
        foreach (array_chunk($photoIds, 500) as $chunk) {
            $rows = $this->repository->fetchAll(
                'SELECT HEX(p.`class_photo_id`) AS `class_photo_id`,p.`piwigo_image_id`,p.`state`,ai.`era` '
                    . 'FROM `' . $photoTable . '` p JOIN `' . $archiveTable . '` ai ON ai.`piwigo_image_id`=p.`piwigo_image_id` '
                    . 'WHERE p.`class_photo_id` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                array_map([DomainSupport::class, 'idToBinary'], $chunk),
            );
            foreach ($rows as $row) {
                $id = strtolower((string) ($row['class_photo_id'] ?? ''));
                if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || isset($byId[$id])) {
                    throw new \RuntimeException('class_archive_memory_album_photo_mapping_invalid');
                }
                $byId[$id] = $row;
            }
        }
        if (count($byId) !== count($photoIds)) {
            throw new \RuntimeException('class_archive_memory_album_photo_mapping_invalid');
        }
        $era = null;
        $imageIds = [];
        foreach ($photoIds as $photoId) {
            $hex = str_replace('-', '', strtolower($photoId));
            $row = $byId[$hex] ?? null;
            $rowEra = is_array($row) ? (string) ($row['era'] ?? '') : '';
            $imageId = is_array($row) ? (int) ($row['piwigo_image_id'] ?? 0) : 0;
            if (!is_array($row) || ($row['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE || $imageId <= 0
                || !in_array($rowEra, ['HERITAGE', 'LIVING'], true)) {
                throw new \RuntimeException('class_archive_memory_album_photo_mapping_invalid');
            }
            if ($era === null) {
                $era = $rowEra;
            } elseif ($era !== $rowEra) {
                // Creating a "mixed" Piwigo category would associate images
                // across both roots and make MediaGuard deny/ambiguous. Do not
                // split a requested Memory silently; fail closed instead.
                throw new \RuntimeException('class_archive_memory_album_mixed_era');
            }
            $imageIds[] = $imageId;
        }
        if (!is_string($era)) {
            throw new \RuntimeException('class_archive_memory_album_era_invalid');
        }
        $this->assertEffectivePiwigoEra($imageIds, $era);
        return [$imageIds, $era];
    }

    /** @param list<int> $imageIds */
    private function assertEffectivePiwigoEra(array $imageIds, string $era): void
    {
        global $prefixeTable;
        $roots = $this->eraRoots();
        $associationRows = [];
        foreach (array_chunk($imageIds, 500) as $chunk) {
            $rows = $this->repository->fetchAll(
                'SELECT ic.`image_id`,c.`id`,c.`uppercats` FROM `' . $prefixeTable . 'image_category` ic '
                    . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
                    . 'WHERE ic.`image_id` IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk,
            );
            foreach ($rows as $row) {
                $imageId = (int) ($row['image_id'] ?? 0);
                if ($imageId <= 0) {
                    throw new \RuntimeException('class_archive_memory_album_effective_era_invalid');
                }
                $associationRows[$imageId][] = $row;
            }
        }
        foreach ($imageIds as $imageId) {
            $rows = $associationRows[$imageId] ?? [];
            if ($rows === []) {
                throw new \RuntimeException('class_archive_memory_album_effective_era_invalid');
            }
            foreach ($rows as $row) {
                $categoryId = (int) ($row['id'] ?? 0);
                $uppercats = ',' . (string) ($row['uppercats'] ?? '') . ',';
                $isHeritage = $categoryId === $roots['HERITAGE'] || str_contains($uppercats, ',' . $roots['HERITAGE'] . ',');
                $isLiving = $categoryId === $roots['LIVING'] || str_contains($uppercats, ',' . $roots['LIVING'] . ',');
                if ($isHeritage === $isLiving || ($isHeritage ? 'HERITAGE' : 'LIVING') !== $era) {
                    throw new \RuntimeException('class_archive_memory_album_effective_era_invalid');
                }
            }
        }
    }

    /** @return array{HERITAGE:int,LIVING:int} */
    private function eraRoots(): array
    {
        global $prefixeTable;
        $rows = $this->repository->fetchAll(
            'SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` '
                . "WHERE `permalink` IN ('class-archive-heritage','class-archive-living')",
        );
        $result = [];
        foreach ($rows as $row) {
            $permalink = $row['permalink'] ?? null;
            $id = (int) ($row['id'] ?? 0);
            $era = $permalink === 'class-archive-heritage' ? 'HERITAGE' : ($permalink === 'class-archive-living' ? 'LIVING' : null);
            if ($era === null || $id <= 0 || isset($result[$era])) {
                throw new \RuntimeException('class_archive_memory_album_roots_invalid');
            }
            $result[$era] = $id;
        }
        if (!isset($result['HERITAGE'], $result['LIVING'])) {
            throw new \RuntimeException('class_archive_memory_album_roots_invalid');
        }
        /** @var array{HERITAGE:int,LIVING:int} $result */
        return $result;
    }

    private function eraRoot(string $era): int
    {
        $roots = $this->eraRoots();
        if (!isset($roots[$era])) {
            throw new \RuntimeException('class_archive_memory_album_era_invalid');
        }
        return $roots[$era];
    }

    /** @return array<string,mixed>|null */
    private function findCategoryByPermalink(string $permalink): ?array
    {
        global $prefixeTable;
        return $this->repository->fetchOne(
            'SELECT `id`,`id_uppercat`,`name`,`permalink`,`status`,`visible`,`commentable` FROM `'
                . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 1',
            [$permalink],
        );
    }

    private function createEraChildCategory(string $title, ?string $description, int $rootId, string $permalink): int
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        if (!function_exists('create_virtual_category') || !function_exists('single_update')) {
            throw new \RuntimeException('class_archive_memory_album_piwigo_unavailable');
        }
        $created = create_virtual_category($title, $rootId, [
            'status' => 'private',
            'visible' => true,
            'commentable' => false,
            'inherit' => true,
            'comment' => $description ?? '',
        ]);
        if (!is_array($created) || !ctype_digit((string) ($created['id'] ?? null))) {
            throw new \RuntimeException('class_archive_memory_album_category_create_failed');
        }
        $categoryId = (int) $created['id'];
        if ($categoryId <= 0) {
            throw new \RuntimeException('class_archive_memory_album_category_create_failed');
        }
        single_update(CATEGORIES_TABLE, ['permalink' => $permalink], ['id' => $categoryId]);
        $category = $this->findCategoryByPermalink($permalink);
        $this->assertCategory($category, $categoryId, $rootId, $title);
        return $categoryId;
    }

    /** @param array<string,mixed>|null $category */
    private function assertCategory(?array $category, int $expectedId, int $rootId, string $title): void
    {
        if ($category === null
            || (int) ($category['id'] ?? 0) !== $expectedId
            || (int) ($category['id_uppercat'] ?? 0) !== $rootId
            || !is_string($category['name'] ?? null)
            || !hash_equals((string) $category['name'], $title)
            || ($category['status'] ?? null) !== 'private'
            || (string) ($category['visible'] ?? '') !== 'true'
            || (string) ($category['commentable'] ?? '') !== 'false') {
            throw new \RuntimeException('class_archive_memory_album_category_drift');
        }
    }

    /** @param list<int> $imageIds */
    private function associateExactMembers(int $categoryId, array $imageIds): void
    {
        if ($categoryId <= 0 || $imageIds === [] || count($imageIds) > self::MAX_MEMBERS || !function_exists('associate_images_to_categories')) {
            throw new \RuntimeException('class_archive_memory_album_association_invalid');
        }
        // Piwigo stores only `image_category` rows here. It does not copy an
        // original, duplicate a derivative, or make a browser-visible path.
        associate_images_to_categories($imageIds, [$categoryId]);
        $this->assertExactCategoryMembers($categoryId, $imageIds);
    }

    /** @param list<int> $expectedImageIds */
    private function assertExactCategoryMembers(int $categoryId, array $expectedImageIds): void
    {
        global $prefixeTable;
        $rows = $this->repository->fetchAll(
            'SELECT `image_id` FROM `' . $prefixeTable . 'image_category` WHERE `category_id`=? ORDER BY `image_id` ASC',
            [$categoryId],
        );
        $actual = [];
        foreach ($rows as $row) {
            $imageId = (int) ($row['image_id'] ?? 0);
            if ($imageId <= 0 || isset($actual[$imageId])) {
                throw new \RuntimeException('class_archive_memory_album_association_invalid');
            }
            $actual[$imageId] = true;
        }
        $expected = [];
        foreach ($expectedImageIds as $imageId) {
            if (!is_int($imageId) || $imageId <= 0 || isset($expected[$imageId])) {
                throw new \RuntimeException('class_archive_memory_album_association_invalid');
            }
            $expected[$imageId] = true;
        }
        if (count($actual) !== count($expected) || array_diff_key($actual, $expected) !== [] || array_diff_key($expected, $actual) !== []) {
            throw new \RuntimeException('class_archive_memory_album_association_drift');
        }
    }

    /**
     * @param array<string,mixed> $category
     * @param array{source_reason:string,title:string,subtitle:?string,cover_class_photo_id:string,photo_ids:list<string>,piwigo_image_ids:list<int>,era:'HERITAGE'|'LIVING'} $memory
     * @return array{created:bool,class_album_id:string,title:string,photo_count:int,album_type:string,era:string}
     */
    private function verifyExistingSavedAlbum(
        array $category,
        int $rootId,
        array $memory,
        string $albumType,
        ?int $ownerPrincipalId,
        string $title,
        ?string $description,
    ): array {
        $categoryId = (int) ($category['id'] ?? 0);
        $this->assertCategory($category, $categoryId, $rootId, $title);
        $this->assertExactCategoryMembers($categoryId, $memory['piwigo_image_ids']);
        $mapping = (new AlbumService($this->repository))->findByPiwigoCategoryId($categoryId);
        if ($mapping === null
            || ($mapping['album_type'] ?? null) !== $albumType
            || ($mapping['era'] ?? null) !== $memory['era']
            || ($mapping['owner_principal_id'] ?? null) !== $ownerPrincipalId
            || ($mapping['description'] ?? null) !== $description
            || ($mapping['cover_class_photo_id'] ?? null) !== $memory['cover_class_photo_id']
            || ($mapping['state'] ?? null) !== 'ACTIVE') {
            throw new \RuntimeException('class_archive_memory_album_existing_drift');
        }
        return [
            'created' => false,
            'class_album_id' => (string) $mapping['class_album_id'],
            'title' => $title,
            'photo_count' => count($memory['photo_ids']),
            'album_type' => $albumType,
            'era' => $memory['era'],
        ];
    }

    private function compensateFreshCategory(int $categoryId): void
    {
        if ($categoryId <= 0 || !function_exists('delete_categories')) {
            return;
        }
        try {
            // `no_delete` removes only the fresh category relationships; it
            // never deletes managed originals that predated this request.
            delete_categories([$categoryId], 'no_delete');
        } catch (\Throwable) {
            // Preserve the original failure. Reconciliation can surface a
            // category orphan, but it must never be silently promoted.
        }
    }

    /** @param array<string,mixed> $memoryProjection @return array{source_reason:string,title:string,subtitle:?string,cover_class_photo_id:string,photo_ids:list<string>} */
    private static function normalizeProjection(array $memoryProjection, string $memoryKey): array
    {
        $sourceReason = $memoryProjection['source_reason'] ?? null;
        $title = DomainSupport::boundedText($memoryProjection['label'] ?? null, 190, true);
        $subtitle = DomainSupport::boundedText($memoryProjection['subtitle'] ?? null, 190);
        $cover = $memoryProjection['cover_photo_id'] ?? null;
        $photos = $memoryProjection['photo_ids'] ?? null;
        if (!is_string($sourceReason) || preg_match('/\A[A-Z][A-Za-z0-9_:-]{1,63}\z/D', $sourceReason) !== 1
            || !is_string($cover) || !is_array($photos) || !array_is_list($photos)
            || !hash_equals($memoryKey, self::memoryKeyForSourceReason($sourceReason))) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        $cover = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($cover)));
        $normalizedPhotos = [];
        $seen = [];
        foreach ($photos as $photoId) {
            if (!is_string($photoId)) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            $photoId = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($photoId)));
            if (isset($seen[$photoId])) {
                throw new \RuntimeException('class_archive_memory_album_projection_invalid');
            }
            $seen[$photoId] = true;
            $normalizedPhotos[] = $photoId;
        }
        if ($normalizedPhotos === [] || count($normalizedPhotos) > self::MAX_MEMBERS || !isset($seen[$cover])) {
            throw new \RuntimeException('class_archive_memory_album_projection_invalid');
        }
        return [
            'source_reason' => $sourceReason,
            'title' => $title,
            'subtitle' => $subtitle,
            'cover_class_photo_id' => $cover,
            'photo_ids' => $normalizedPhotos,
        ];
    }

    /** @param array<string,true> $visiblePhotoIds @return array<string,true> */
    private static function normalizeVisiblePhotoIds(array $visiblePhotoIds): array
    {
        $normalized = [];
        foreach ($visiblePhotoIds as $photoId => $enabled) {
            if ($enabled !== true || !is_string($photoId)) {
                throw new \RuntimeException('class_archive_memory_album_visibility_invalid');
            }
            $id = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($photoId)));
            $normalized[$id] = true;
        }
        if ($normalized === []) {
            throw new \RuntimeException('class_archive_memory_album_visibility_invalid');
        }
        return $normalized;
    }

    private static function normalizeMemoryKey(string $memoryKey): string
    {
        $memoryKey = strtolower(trim($memoryKey));
        if (preg_match('/\Amemory-[a-f0-9]{56}\z/D', $memoryKey) !== 1) {
            throw new \InvalidArgumentException('class_archive_memory_album_key_invalid');
        }
        return $memoryKey;
    }

    private static function memoryKeyForSourceReason(string $sourceReason): string
    {
        return self::MEMORY_KEY_PREFIX . substr(hash('sha256', $sourceReason), 0, 56);
    }

    private static function permalinkForSourceReason(string $sourceReason): string
    {
        return 'class-archive-memory-' . substr(hash('sha256', "class-archive/memory-album/v1\0" . $sourceReason), 0, 40);
    }

    /** @template T @param callable():T $callback @return T */
    private function withMemoryLock(string $memoryKey, callable $callback): mixed
    {
        $name = 'ci_memory_album_' . substr(hash('sha256', $memoryKey), 0, 40);
        $lock = $this->repository->fetchOne('SELECT GET_LOCK(?, 15) AS `locked`', [$name]);
        if ($lock === null || (int) ($lock['locked'] ?? 0) !== 1) {
            throw new \RuntimeException('class_archive_memory_album_lock_unavailable');
        }
        try {
            return $callback();
        } finally {
            $released = $this->repository->fetchOne('SELECT RELEASE_LOCK(?) AS `released`', [$name]);
            if ($released === null || (int) ($released['released'] ?? 0) !== 1) {
                throw new \RuntimeException('class_archive_memory_album_lock_release_failed');
            }
        }
    }
}
