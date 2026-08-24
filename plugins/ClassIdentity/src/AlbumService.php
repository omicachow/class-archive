<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Stable opaque Class Archive album ids mapped onto existing Piwigo albums. */
final class AlbumService
{
    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Map an existing Piwigo category. This service deliberately does not
     * duplicate Piwigo's category creator or media manager.
     *
     * @return array<string,mixed>
     */
    public function ensureMapping(
        int $adminUserId,
        int $piwigoCategoryId,
        string $albumType,
        string $era,
        ?int $ownerPrincipalId,
        ?string $description,
        ?string $eventLabel,
        ?string $coverClassPhotoId,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        [$albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverBinary, $reason]
            = $this->normalizeMutation($piwigoCategoryId, $albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverClassPhotoId, $reason);
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $piwigoCategoryId, $albumType, $era, $ownerPrincipalId,
            $description, $eventLabel, $coverBinary, $coverClassPhotoId, $reason,
        ): array {
            $table = DomainSupport::table($repository, 'album');
            $existing = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `piwigo_category_id` = ? FOR UPDATE',
                [$piwigoCategoryId],
            );
            if ($existing !== null) {
                $mapped = $this->hydrate($existing, true);
                if (
                    $mapped['album_type'] !== $albumType
                    || $mapped['era'] !== $era
                    || $mapped['owner_principal_id'] !== $ownerPrincipalId
                    || $mapped['description'] !== $description
                    || $mapped['event_label'] !== $eventLabel
                    || $mapped['cover_class_photo_id'] !== $coverClassPhotoId
                    || $mapped['state'] !== 'ACTIVE'
                ) {
                    throw new \RuntimeException('class_archive_album_mapping_drift');
                }
                return $mapped;
            }
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $classAlbumId = DomainSupport::generateId();
                $binary = DomainSupport::idToBinary($classAlbumId);
                if ($repository->fetchOne('SELECT `class_album_id` FROM `' . $table . '` WHERE `class_album_id` = ? FOR UPDATE', [$binary]) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $table . '` '
                    . '(`class_album_id`,`piwigo_category_id`,`album_type`,`owner_principal_id`,`era`,`description`,`event_label`,`manual_cover_class_photo_id`,`state`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$binary, $piwigoCategoryId, $albumType, $ownerPrincipalId, $era, $description, $eventLabel, $coverBinary],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'ALBUM_MAPPING_CREATE',
                    'target_type' => 'ALBUM',
                    'target_id' => $classAlbumId,
                    'new_value' => [
                        'class_album_id' => $classAlbumId,
                        'piwigo_category_id' => $piwigoCategoryId,
                        'album_type' => $albumType,
                        'owner_principal_id' => $ownerPrincipalId,
                        'era' => $era,
                        'event_label' => $eventLabel,
                        'cover_class_photo_id' => $coverClassPhotoId,
                        'state' => 'ACTIVE',
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return $this->hydrate([
                    'class_album_id' => $binary,
                    'piwigo_category_id' => $piwigoCategoryId,
                    'album_type' => $albumType,
                    'owner_principal_id' => $ownerPrincipalId,
                    'era' => $era,
                    'description' => $description,
                    'event_label' => $eventLabel,
                    'manual_cover_class_photo_id' => $coverBinary,
                    'state' => 'ACTIVE',
                ], true);
            }
            throw new \RuntimeException('class_archive_album_id_collision');
        });
    }

    /** @return array<string,mixed> */
    public function updateMapping(
        int $adminUserId,
        string $classAlbumId,
        string $albumType,
        string $era,
        ?int $ownerPrincipalId,
        ?string $description,
        ?string $eventLabel,
        ?string $coverClassPhotoId,
        string $state,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $current = $this->findByClassAlbumId($classAlbumId);
        if ($current === null) {
            throw new \RuntimeException('class_archive_album_not_found');
        }
        [$albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverBinary, $reason]
            = $this->normalizeMutation((int) $current['piwigo_category_id'], $albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverClassPhotoId, $reason);
        $state = strtoupper(trim($state));
        if (!in_array($state, ['ACTIVE', 'HIDDEN', 'RETIRED'], true)) {
            throw new \InvalidArgumentException('class_archive_album_state_invalid');
        }
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $classAlbumId, $albumType, $era, $ownerPrincipalId, $description,
            $eventLabel, $coverBinary, $coverClassPhotoId, $state, $reason,
        ): array {
            $binary = DomainSupport::idToBinary($classAlbumId);
            $table = DomainSupport::table($repository, 'album');
            $before = $repository->fetchOne('SELECT * FROM `' . $table . '` WHERE `class_album_id` = ? FOR UPDATE', [$binary]);
            if ($before === null) {
                throw new \RuntimeException('class_archive_album_not_found');
            }
            $repository->execute(
                'UPDATE `' . $table . '` SET `album_type`=?,`owner_principal_id`=?,`era`=?,`description`=?,'
                . '`event_label`=?,`manual_cover_class_photo_id`=?,`state`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `class_album_id`=?',
                [$albumType, $ownerPrincipalId, $era, $description, $eventLabel, $coverBinary, $state, $binary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'ALBUM_MAPPING_UPDATE',
                'target_type' => 'ALBUM',
                'target_id' => $classAlbumId,
                'old_value' => [
                    'album_type' => (string) $before['album_type'],
                    'owner_principal_id' => $before['owner_principal_id'] === null ? null : (int) $before['owner_principal_id'],
                    'era' => (string) $before['era'],
                    'event_label' => $before['event_label'] === null ? null : (string) $before['event_label'],
                    'cover_class_photo_id' => $before['manual_cover_class_photo_id'] === null ? null : DomainSupport::binaryToId((string) $before['manual_cover_class_photo_id']),
                    'state' => (string) $before['state'],
                ],
                'new_value' => [
                    'album_type' => $albumType,
                    'owner_principal_id' => $ownerPrincipalId,
                    'era' => $era,
                    'event_label' => $eventLabel,
                    'cover_class_photo_id' => $coverClassPhotoId,
                    'state' => $state,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return $this->hydrate([
                'class_album_id' => $binary,
                'piwigo_category_id' => $before['piwigo_category_id'],
                'album_type' => $albumType,
                'owner_principal_id' => $ownerPrincipalId,
                'era' => $era,
                'description' => $description,
                'event_label' => $eventLabel,
                'manual_cover_class_photo_id' => $coverBinary,
                'state' => $state,
            ], true);
        });
    }

    /** @return array<string,mixed>|null */
    public function findByClassAlbumId(string $classAlbumId): ?array
    {
        $row = $this->repository->fetchOne(
            'SELECT * FROM `' . DomainSupport::table($this->repository, 'album') . '` WHERE `class_album_id` = ? LIMIT 1',
            [DomainSupport::idToBinary($classAlbumId)],
        );
        return $row === null ? null : $this->hydrate($row, true);
    }

    /** @return array<string,mixed>|null */
    public function findByPiwigoCategoryId(int $piwigoCategoryId): ?array
    {
        if ($piwigoCategoryId <= 0) {
            return null;
        }
        $row = $this->repository->fetchOne(
            'SELECT * FROM `' . DomainSupport::table($this->repository, 'album') . '` WHERE `piwigo_category_id` = ? LIMIT 1',
            [$piwigoCategoryId],
        );
        return $row === null ? null : $this->hydrate($row, true);
    }

    /** Admin-only mapping list with owner ids and Piwigo display metadata. @return list<array<string,mixed>> */
    public function listMappings(int $adminUserId): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        global $prefixeTable;
        $rows = $this->repository->fetchAll(
            'SELECT a.*,c.`name` AS `piwigo_name`,c.`status` AS `piwigo_status`,c.`visible` AS `piwigo_visible` '
                . 'FROM `' . DomainSupport::table($this->repository, 'album') . '` a '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=a.`piwigo_category_id` '
                . 'ORDER BY a.`updated_at` DESC LIMIT 2000',
        );
        return array_map(function (array $row): array {
            $mapped = $this->hydrate($row, true);
            $mapped['name'] = (string) $row['piwigo_name'];
            $mapped['piwigo_status'] = (string) $row['piwigo_status'];
            $mapped['piwigo_visible'] = (string) $row['piwigo_visible'];
            return $mapped;
        }, $rows);
    }

    /**
     * Public-safe projection from caller-approved categories/photos. Album era
     * is descriptive only; it never authorizes mixed album contents.
     *
     * @param list<int> $visiblePiwigoCategoryIds
     * @param list<string> $visibleClassPhotoIds
     * @return list<array<string,mixed>>
     */
    public function projectVisible(array $visiblePiwigoCategoryIds, array $visibleClassPhotoIds): array
    {
        $categorySet = [];
        foreach ($visiblePiwigoCategoryIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('class_archive_album_visible_category_invalid');
            }
            $categorySet[$id] = true;
        }
        $photoSet = [];
        foreach ($visibleClassPhotoIds as $id) {
            DomainSupport::idToBinary($id);
            $photoSet[strtolower($id)] = $id;
        }
        global $prefixeTable;
        $rows = $this->repository->fetchAll(
            "SELECT a.*,c.`name` AS `piwigo_name` FROM `" . DomainSupport::table($this->repository, 'album')
                . "` a JOIN `{$prefixeTable}categories` c ON c.`id`=a.`piwigo_category_id` WHERE a.`state`='ACTIVE' ORDER BY c.`global_rank`,c.`name`",
        );
        $result = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['piwigo_category_id'];
            if (!isset($categorySet[$categoryId])) {
                continue;
            }
            $mapped = $this->hydrate($row, false);
            $cover = $mapped['cover_class_photo_id'];
            if (!is_string($cover) || !isset($photoSet[strtolower($cover)])) {
                $cover = null;
            }
            $result[] = [
                'class_album_id' => $mapped['class_album_id'],
                'piwigo_category_id' => $categoryId,
                'name' => (string) $row['piwigo_name'],
                'album_type' => $mapped['album_type'],
                'era' => $mapped['era'],
                'description' => $mapped['description'],
                'event_label' => $mapped['event_label'],
                'cover_class_photo_id' => $cover,
            ];
        }
        return $result;
    }

    /** @return array{0:string,1:string,2:?int,3:?string,4:?string,5:?string,6:string} */
    private function normalizeMutation(
        int $categoryId,
        string $albumType,
        string $era,
        ?int $ownerPrincipalId,
        ?string $description,
        ?string $eventLabel,
        ?string $coverClassPhotoId,
        string $reason,
    ): array {
        $this->requirePiwigoCategory($categoryId);
        $albumType = strtoupper(trim($albumType));
        if (!in_array($albumType, ['OFFICIAL', 'COMMUNITY'], true)) {
            throw new \InvalidArgumentException('class_archive_album_type_invalid');
        }
        $era = strtoupper(trim($era));
        if (!in_array($era, ['HERITAGE', 'LIVING', 'MIXED'], true)) {
            throw new \InvalidArgumentException('class_archive_album_era_invalid');
        }
        if ($albumType === 'OFFICIAL') {
            if ($ownerPrincipalId !== null) {
                throw new \InvalidArgumentException('class_archive_official_album_owner_invalid');
            }
        } else {
            $this->requireCommunityOwner($ownerPrincipalId);
        }
        $description = DomainSupport::boundedText($description, 4000);
        $eventLabel = DomainSupport::boundedText($eventLabel, 190);
        $coverBinary = null;
        if ($coverClassPhotoId !== null) {
            $photo = DomainSupport::requireActivePhoto($this->repository, $coverClassPhotoId);
            $this->requirePhotoInCategoryTree((int) $photo['piwigo_image_id'], $categoryId);
            $coverBinary = DomainSupport::idToBinary($coverClassPhotoId);
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        return [$albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverBinary, $reason];
    }

    private function requirePiwigoCategory(int $categoryId): void
    {
        global $prefixeTable;
        if ($categoryId <= 0 || $this->repository->fetchOne(
            'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `id`=? LIMIT 1',
            [$categoryId],
        ) === null) {
            throw new \InvalidArgumentException('class_archive_album_piwigo_category_invalid');
        }
    }

    private function requirePhotoInCategoryTree(int $piwigoImageId, int $categoryId): void
    {
        global $prefixeTable;
        if ($piwigoImageId <= 0 || $categoryId <= 0 || $this->repository->fetchOne(
            'SELECT ic.`image_id` FROM `' . $prefixeTable . 'image_category` ic '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
                . 'WHERE ic.`image_id`=? AND (c.`id`=? OR FIND_IN_SET(?,c.`uppercats`) > 0) LIMIT 1',
            [$piwigoImageId, $categoryId, $categoryId],
        ) === null) {
            throw new \InvalidArgumentException('class_archive_album_cover_membership_invalid');
        }
    }

    private function requireCommunityOwner(?int $principalId): void
    {
        if ($principalId === null || $principalId <= 0) {
            throw new \InvalidArgumentException('class_archive_community_album_owner_required');
        }
        $p = $this->repository->table('principal');
        $a = $this->repository->table('account');
        $s = $this->repository->table('seat');
        $i = $this->repository->table('identity');
        $row = $this->repository->fetchOne(
            "SELECT s.`seat_type`,p.`state` AS principal_state,a.`state` AS account_state,a.`current_marker`,s.`state` AS seat_state,i.`state` AS identity_state "
                . "FROM `{$p}` p JOIN `{$a}` a ON a.`id`=p.`account_id` JOIN `{$s}` s ON s.`id`=a.`seat_id` JOIN `{$i}` i ON i.`id`=s.`identity_id` "
                . "WHERE p.`id`=? AND p.`principal_type`='SEAT_ACCOUNT' LIMIT 1",
            [$principalId],
        );
        if ($row === null
            || !in_array((string) $row['seat_type'], ['CLASSMATE', 'TEACHER'], true)
            || ($row['principal_state'] ?? null) !== 'ACTIVE'
            || ($row['account_state'] ?? null) !== 'ACTIVE'
            || (int) ($row['current_marker'] ?? 0) !== 1
            || ($row['seat_state'] ?? null) !== 'ACTIVE'
            || ($row['identity_state'] ?? null) !== 'ACTIVE'
        ) {
            throw new \InvalidArgumentException('class_archive_community_album_owner_invalid');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row, bool $includeOwner): array
    {
        $result = [
            'class_album_id' => DomainSupport::binaryToId((string) $row['class_album_id']),
            'piwigo_category_id' => (int) $row['piwigo_category_id'],
            'album_type' => (string) $row['album_type'],
            'era' => (string) $row['era'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'event_label' => $row['event_label'] === null ? null : (string) $row['event_label'],
            'cover_class_photo_id' => $row['manual_cover_class_photo_id'] === null
                ? null : DomainSupport::binaryToId((string) $row['manual_cover_class_photo_id']),
            'state' => (string) $row['state'],
        ];
        if ($includeOwner) {
            $result['owner_principal_id'] = $row['owner_principal_id'] === null ? null : (int) $row['owner_principal_id'];
        }
        return $result;
    }
}
