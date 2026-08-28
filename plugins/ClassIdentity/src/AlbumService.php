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
        return $this->ensureMappingForActor(
            $admin,
            $piwigoCategoryId,
            $albumType,
            $era,
            $ownerPrincipalId,
            $description,
            $eventLabel,
            $coverClassPhotoId,
            $reason,
        );
    }

    /**
     * Map an existing Piwigo category as an actor-owned Community album.
     *
     * This is intentionally narrower than the System Admin mapping entry
     * point: a caller cannot nominate another owner, an OFFICIAL type, or an
     * arbitrary seat.  It is the only reusable member-facing mapping helper
     * for a future server-authorized collection curation flow.
     *
     * @return array<string,mixed>
     */
    public function ensureOwnedCommunityMapping(
        int $memberUserId,
        int $piwigoCategoryId,
        string $era,
        ?string $description,
        ?string $eventLabel,
        ?string $coverClassPhotoId,
        string $reason,
    ): array {
        $member = DomainSupport::requireMemberRole($memberUserId, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]);
        $principalId = (int) ($member['principal_id'] ?? 0);
        if ($principalId <= 0 || !in_array((string) ($member['role'] ?? ''), [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true)) {
            throw new \RuntimeException('class_archive_community_album_member_context_invalid');
        }
        return $this->ensureMappingForActor(
            $member,
            $piwigoCategoryId,
            'COMMUNITY',
            $era,
            $principalId,
            $description,
            $eventLabel,
            $coverClassPhotoId,
            $reason,
        );
    }

    /**
     * Shared persistence half for already-authorized actor contexts.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function ensureMappingForActor(
        array $actor,
        int $piwigoCategoryId,
        string $albumType,
        string $era,
        ?int $ownerPrincipalId,
        ?string $description,
        ?string $eventLabel,
        ?string $coverClassPhotoId,
        string $reason,
    ): array {
        [$albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverBinary, $reason]
            = $this->normalizeMutation($piwigoCategoryId, $albumType, $era, $ownerPrincipalId, $description, $eventLabel, $coverClassPhotoId, $reason);
        return $this->repository->transaction(function (Repository $repository) use (
            $actor, $piwigoCategoryId, $albumType, $era, $ownerPrincipalId,
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
            ProjectionMutationBoundary::invalidateAggregates(
                $repository,
                [
                    \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
                    \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
                ],
                'ALBUM_MAPPING',
            );
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
                (new Audit($repository))->append(DomainSupport::auditActor($actor) + [
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
            ProjectionMutationBoundary::invalidateAggregates(
                $repository,
                [
                    \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
                    \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
                ],
                'ALBUM_MAPPING',
            );
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
                'display_alias' => $before['display_alias'] ?? null,
                'manual_cover_class_photo_id' => $coverBinary,
                'state' => $state,
            ], true);
        });
    }

    /**
     * Change a manual cover without granting member access to the broader
     * album-mapping editor.  A System Admin may curate any active mapped
     * album; a Classmate or Teacher may curate only an active COMMUNITY album
     * whose owner is their current principal.  Family and Anonymous seats are
     * deliberately excluded: their Collections controls remain personal
     * pins/feedback, never a shared publication surface.
     *
     * @return array<string,mixed>
     */
    public function setManualCoverForActor(
        int $userId,
        string $classAlbumId,
        string $coverClassPhotoId,
        string $reason,
    ): array {
        $context = Access::resolveAuthorizationContext($userId);
        if (!is_array($context) || !is_string($context['role'] ?? null)) {
            throw new \RuntimeException('class_archive_album_cover_role_forbidden');
        }
        $role = (string) $context['role'];
        if ($role === Access::ROLE_SYSTEM_ADMIN) {
            $context = DomainSupport::requireSystemAdmin($userId);
        } elseif (in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true)) {
            $context = DomainSupport::requireMemberRole($userId, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]);
        } else {
            throw new \RuntimeException('class_archive_album_cover_role_forbidden');
        }

        $albumBinary = DomainSupport::idToBinary($classAlbumId);
        $coverClassPhotoId = strtolower(DomainSupport::binaryToId(DomainSupport::idToBinary($coverClassPhotoId)));
        $reason = Audit::validateReason($reason, true) ?? '';
        $isSystemAdmin = $role === Access::ROLE_SYSTEM_ADMIN;
        $actorPrincipalId = (int) ($context['principal_id'] ?? 0);
        if ($actorPrincipalId <= 0) {
            throw new \RuntimeException('class_archive_album_cover_actor_invalid');
        }

        return $this->repository->transaction(function (Repository $repository) use (
            $context,
            $classAlbumId,
            $albumBinary,
            $coverClassPhotoId,
            $reason,
            $isSystemAdmin,
            $actorPrincipalId,
        ): array {
            $table = DomainSupport::table($repository, 'album');
            $before = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `class_album_id`=? FOR UPDATE',
                [$albumBinary],
            );
            if ($before === null) {
                throw new \RuntimeException('class_archive_album_not_found');
            }
            if (($before['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_album_cover_not_active');
            }
            $ownerPrincipalId = $before['owner_principal_id'] === null ? null : (int) $before['owner_principal_id'];
            if (!$isSystemAdmin) {
                if (($before['album_type'] ?? null) !== 'COMMUNITY' || $ownerPrincipalId !== $actorPrincipalId) {
                    throw new \RuntimeException('class_archive_album_cover_role_forbidden');
                }
                // Recheck every nested identity/account/seat marker while the
                // mapping row is locked. An old session cannot continue
                // curating an album after its principal loses validity.
                $this->requireCommunityOwner($ownerPrincipalId);
            }
            $era = (string) ($before['era'] ?? '');
            if (!in_array($era, ['HERITAGE', 'LIVING'], true)) {
                throw new \RuntimeException('class_archive_album_cover_era_unsupported');
            }
            $categoryId = (int) ($before['piwigo_category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new \RuntimeException('class_archive_album_cover_category_invalid');
            }
            $photo = DomainSupport::requireActivePhoto($repository, $coverClassPhotoId, true);
            $imageId = (int) ($photo['piwigo_image_id'] ?? 0);
            $this->requirePhotoInExactCategory($imageId, $categoryId);
            $this->requirePhotoEffectiveEra($imageId, $era);

            $oldCover = $before['manual_cover_class_photo_id'] === null
                ? null
                : DomainSupport::binaryToId((string) $before['manual_cover_class_photo_id']);
            if ($oldCover !== null && hash_equals(strtolower($oldCover), $coverClassPhotoId)) {
                return $this->hydrate($before, true);
            }

            ProjectionMutationBoundary::invalidateAggregates(
                $repository,
                [
                    \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
                    \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
                ],
                'ALBUM_COVER',
            );
            $repository->execute(
                'UPDATE `' . $table . '` SET `manual_cover_class_photo_id`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `class_album_id`=?',
                [DomainSupport::idToBinary($coverClassPhotoId), $albumBinary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                'action' => 'ALBUM_COVER_UPDATE',
                'target_type' => 'ALBUM',
                'target_id' => strtolower($classAlbumId),
                'old_value' => [
                    'class_album_id' => strtolower($classAlbumId),
                    'cover_class_photo_id' => $oldCover,
                ],
                'new_value' => [
                    'class_album_id' => strtolower($classAlbumId),
                    'cover_class_photo_id' => $coverClassPhotoId,
                    'album_type' => (string) $before['album_type'],
                    'era' => $era,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            $before['manual_cover_class_photo_id'] = DomainSupport::idToBinary($coverClassPhotoId);
            return $this->hydrate($before, true);
        });
    }

    /**
     * A member-facing alias is intentionally separate from Piwigo's category
     * name and from private importer/source path identity.  This makes a
     * source folder readable in the product without changing its provenance
     * or making a subsequent full-library import drift.
     *
     * @return array<string,mixed>
     */
    public function setDisplayAlias(
        int $adminUserId,
        string $classAlbumId,
        ?string $displayAlias,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $binary = DomainSupport::idToBinary($classAlbumId);
        $displayAlias = DomainSupport::boundedText($displayAlias, 190);
        $reason = Audit::validateReason($reason, true) ?? '';

        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $binary, $classAlbumId, $displayAlias, $reason,
        ): array {
            $table = DomainSupport::table($repository, 'album');
            $before = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `class_album_id`=? FOR UPDATE',
                [$binary],
            );
            if ($before === null) {
                throw new \RuntimeException('class_archive_album_not_found');
            }
            $previous = $before['display_alias'] ?? null;
            if ($previous !== null && !is_string($previous)) {
                throw new \RuntimeException('class_archive_album_display_alias_invalid');
            }
            if ($previous !== $displayAlias) {
                ProjectionMutationBoundary::invalidateAggregates(
                    $repository,
                    [
                        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
                        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
                    ],
                    'ALBUM_DISPLAY_ALIAS',
                );
                $repository->execute(
                    'UPDATE `' . $table . '` SET `display_alias`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `class_album_id`=?',
                    [$displayAlias, $binary],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'ALBUM_DISPLAY_ALIAS_UPDATE',
                    'target_type' => 'ALBUM',
                    'target_id' => strtolower($classAlbumId),
                    'old_value' => ['display_alias' => $previous],
                    'new_value' => ['display_alias' => $displayAlias],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                $before['display_alias'] = $displayAlias;
            }
            return $this->hydrate($before, true);
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

    /**
     * Selection-only projection for the explicit-era member upload dialog.
     *
     * This is deliberately narrower than the ordinary album browser: it
     * exposes only opaque UUIDs and labels for active OFFICIAL albums that
     * live beneath the matching business-era root.  Family, Anonymous, and
     * SYSTEM_ADMIN principals cannot obtain this target list.  The eventual
     * upload service repeats every mapping/state/tree condition, so this
     * response is never an authorization capability by itself.
     *
     * @return array{eras:array{HERITAGE:list<array{id:string,label:string,subtitle:?string}>,LIVING:list<array{id:string,label:string,subtitle:?string}>}}
     */
    public function memberEraUploadOptions(int $userId): array
    {
        $context = DomainSupport::requireMemberRole($userId, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]);
        if (!in_array((string) ($context['role'] ?? ''), [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true)) {
            throw new \RuntimeException('class_archive_member_upload_role_forbidden');
        }

        global $prefixeTable;
        $roots = [];
        foreach (['HERITAGE' => 'class-archive-heritage', 'LIVING' => 'class-archive-living'] as $era => $permalink) {
            $root = $this->repository->fetchOne(
                'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 1',
                [$permalink],
            );
            $id = (int) ($root['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('class_archive_member_upload_root_unavailable');
            }
            $roots[$era] = $id;
        }

        $rows = $this->repository->fetchAll(
            'SELECT a.`class_album_id`,a.`piwigo_category_id`,a.`era`,a.`display_alias`,a.`description`,a.`event_label`,c.`name`,c.`uppercats` '
                . 'FROM `' . DomainSupport::table($this->repository, 'album') . '` a '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=a.`piwigo_category_id` '
                . "WHERE a.`album_type`='OFFICIAL' AND a.`state`='ACTIVE' AND a.`era` IN ('HERITAGE','LIVING') "
                . 'ORDER BY c.`global_rank` ASC,c.`name` ASC',
        );

        $result = ['HERITAGE' => [], 'LIVING' => []];
        foreach ($rows as $row) {
            $era = (string) ($row['era'] ?? '');
            $binaryId = $row['class_album_id'] ?? null;
            $categoryId = (int) ($row['piwigo_category_id'] ?? 0);
            if (!isset($roots[$era]) || !is_string($binaryId) || strlen($binaryId) !== 16 || $categoryId <= 0) {
                throw new \RuntimeException('class_archive_member_upload_album_projection_invalid');
            }
            $ancestors = array_filter(
                explode(',', trim((string) ($row['uppercats'] ?? ''), ',')),
                static fn(string $value): bool => ctype_digit($value),
            );
            if ($categoryId !== $roots[$era] && !in_array((string) $roots[$era], $ancestors, true)) {
                throw new \RuntimeException('class_archive_member_upload_album_tree_invalid');
            }
            $label = DomainSupport::boundedText($row['display_alias'] ?? null, 190)
                ?? DomainSupport::boundedText($row['name'] ?? null, 190, true);
            $subtitle = DomainSupport::boundedText($row['event_label'] ?? null, 190)
                ?? DomainSupport::boundedText($row['description'] ?? null, 190);
            $result[$era][] = [
                'id' => strtolower(DomainSupport::binaryToId($binaryId)),
                'label' => $label,
                'subtitle' => $subtitle,
            ];
        }

        return ['eras' => $result];
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
            "SELECT a.*,c.`name` AS `piwigo_name`,c.`id_uppercat`,c.`uppercats` FROM `" . DomainSupport::table($this->repository, 'album')
                . "` a JOIN `{$prefixeTable}categories` c ON c.`id`=a.`piwigo_category_id` WHERE a.`state`='ACTIVE' ORDER BY c.`global_rank`,c.`name`",
        );
        // A private full-library source root is still just an OFFICIAL
        // album for authorization.  This narrow presentation marker lets the
        // photo product promote its approved source collections without
        // serializing an absolute source path, filename, or provenance digest.
        $sourceContexts = $this->privateSourceContextsByAlbum();
        // A photo is stored only in its direct Piwigo category.  Build the
        // folder-display projection from that direct membership plus the
        // native ancestor chain, rather than copying associations into every
        // ancestor folder.  The category ids below are internal hand-off data
        // for GatewayService and are never serialized to a browser.
        $visibleCategories = $this->repository->fetchAll(
            'SELECT `id`,`uppercats` FROM `' . $prefixeTable . 'categories` WHERE `id` IN ('
                . implode(',', array_fill(0, count($categorySet), '?')) . ')',
            array_map('intval', array_keys($categorySet)),
        );
        if (count($visibleCategories) !== count($categorySet)) {
            throw new \RuntimeException('class_archive_album_visible_category_missing');
        }
        $visibleAncestorSets = [];
        foreach ($visibleCategories as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0 || !isset($categorySet[$categoryId])) {
                throw new \RuntimeException('class_archive_album_visible_category_invalid');
            }
            $ancestors = [];
            foreach (explode(',', trim((string) ($category['uppercats'] ?? ''), ',')) as $candidate) {
                if ($candidate !== '' && ctype_digit($candidate)) {
                    $ancestors[(int) $candidate] = true;
                }
            }
            $ancestors[$categoryId] = true;
            $visibleAncestorSets[$categoryId] = $ancestors;
        }
        $classAlbumIdByCategory = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row['piwigo_category_id'] ?? 0);
            if ($categoryId <= 0) {
                throw new \RuntimeException('class_archive_album_category_invalid');
            }
            $classAlbumIdByCategory[$categoryId] = DomainSupport::binaryToId((string) ($row['class_album_id'] ?? ''));
        }
        $result = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['piwigo_category_id'];
            $memberCategoryIds = [];
            foreach ($visibleAncestorSets as $visibleCategoryId => $ancestors) {
                if (isset($ancestors[$categoryId])) {
                    $memberCategoryIds[] = $visibleCategoryId;
                }
            }
            if ($memberCategoryIds === []) {
                continue;
            }
            $mapped = $this->hydrate($row, false);
            $cover = $mapped['cover_class_photo_id'];
            if (!is_string($cover) || !isset($photoSet[strtolower($cover)])) {
                $cover = null;
            }
            $parentClassAlbumId = null;
            foreach (array_reverse(explode(',', trim((string) ($row['uppercats'] ?? ''), ','))) as $candidate) {
                if ($candidate === '' || !ctype_digit($candidate)) {
                    continue;
                }
                $candidateId = (int) $candidate;
                if ($candidateId === $categoryId) {
                    continue;
                }
                if (isset($classAlbumIdByCategory[$candidateId])) {
                    $parentClassAlbumId = $classAlbumIdByCategory[$candidateId];
                    break;
                }
            }
            $result[] = [
                'class_album_id' => $mapped['class_album_id'],
                'piwigo_category_id' => $categoryId,
                'parent_class_album_id' => $parentClassAlbumId,
                'visible_category_ids' => $memberCategoryIds,
                'name' => (string) $row['piwigo_name'],
                'display_alias' => $mapped['display_alias'],
                'album_type' => $mapped['album_type'],
                'era' => $mapped['era'],
                'description' => $mapped['description'],
                'event_label' => $mapped['event_label'],
                'cover_class_photo_id' => $cover,
                'source_root' => (bool) ($sourceContexts[strtolower((string) $mapped['class_album_id'])]['source_root'] ?? false),
                'source_collection_code' => $sourceContexts[strtolower((string) $mapped['class_album_id'])]['source_code'] ?? null,
                'source_label' => $sourceContexts[strtolower((string) $mapped['class_album_id'])]['source_label'] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Public-safe source context for a mapped private-library album.  It never
     * returns an absolute path, importer display name, file name, or source
     * digest. The only product label comes from the already-approved local
     * collection display name; no source-specific label is embedded in the
     * distributable code.
     *
     * @return array<string,array{source_root:bool,source_code:string,source_label:string}>
     */
    private function privateSourceContextsByAlbum(): array
    {
        $folder = DomainSupport::table($this->repository, 'private_library_folder');
        $collection = DomainSupport::table($this->repository, 'private_library_collection');
        $rows = $this->repository->fetchAll(
            "SELECT f.`class_album_id`,f.`parent_folder_id`,c.`source_code`,c.`display_name` FROM `{$folder}` f "
                . "JOIN `{$collection}` c ON c.`source_collection_id`=f.`source_collection_id` "
                . "WHERE c.`state`='ACTIVE'",
        );
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['class_album_id'] ?? null) || !is_string($row['source_code'] ?? null)
                || !is_string($row['display_name'] ?? null)) {
                throw new \RuntimeException('class_archive_private_library_source_root_invalid');
            }
            $sourceCode = (string) $row['source_code'];
            if (!in_array($sourceCode, ['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B'], true)) {
                throw new \RuntimeException('class_archive_private_library_source_code_invalid');
            }
            $sourceLabel = trim((string) $row['display_name']);
            if ($sourceLabel === '' || strlen($sourceLabel) > 190
                || str_contains($sourceLabel, '/') || str_contains($sourceLabel, '\\')
                || preg_match('/\A[A-Za-z]:/D', $sourceLabel) === 1
                || preg_match('/[\x00-\x1F\x7F]/', $sourceLabel) === 1
                || preg_match('//u', $sourceLabel) !== 1
            ) {
                throw new \RuntimeException('class_archive_private_library_source_label_invalid');
            }
            $result[strtolower(DomainSupport::binaryToId((string) $row['class_album_id']))] = [
                'source_root' => $row['parent_folder_id'] === null,
                'source_code' => $sourceCode,
                'source_label' => $sourceLabel,
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

    /** A manual Album cover must be a direct member of that Album, not an ancestor-only projection. */
    private function requirePhotoInExactCategory(int $piwigoImageId, int $categoryId): void
    {
        global $prefixeTable;
        if ($piwigoImageId <= 0 || $categoryId <= 0 || $this->repository->fetchOne(
            'SELECT `image_id` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=? LIMIT 1',
            [$piwigoImageId, $categoryId],
        ) === null) {
            throw new \InvalidArgumentException('class_archive_album_cover_membership_invalid');
        }
    }

    /**
     * Piwigo category membership remains part of MediaGuard's effective era
     * decision. Do not accept an album cover that lives outside the exact
     * business root, or in both roots: a manual cover must never become a
     * cross-era ACL bypass or a misleading public thumbnail.
     */
    private function requirePhotoEffectiveEra(int $piwigoImageId, string $expectedEra): void
    {
        global $prefixeTable;
        $roots = $this->repository->fetchAll(
            'SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` '
                . "WHERE `permalink` IN ('class-archive-heritage','class-archive-living')",
        );
        $rootIds = [];
        foreach ($roots as $root) {
            $id = (int) ($root['id'] ?? 0);
            $permalink = $root['permalink'] ?? null;
            if ($id <= 0 || !is_string($permalink) || isset($rootIds[$permalink])) {
                throw new \RuntimeException('class_archive_album_cover_root_invalid');
            }
            $rootIds[$permalink] = $id;
        }
        $heritage = $rootIds['class-archive-heritage'] ?? 0;
        $living = $rootIds['class-archive-living'] ?? 0;
        if ($heritage <= 0 || $living <= 0 || $piwigoImageId <= 0) {
            throw new \RuntimeException('class_archive_album_cover_root_invalid');
        }
        $rows = $this->repository->fetchAll(
            'SELECT c.`id`,c.`uppercats` FROM `' . $prefixeTable . 'image_category` ic '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` WHERE ic.`image_id`=?',
            [$piwigoImageId],
        );
        if ($rows === []) {
            throw new \RuntimeException('class_archive_album_cover_era_invalid');
        }
        foreach ($rows as $row) {
            $categoryId = (int) ($row['id'] ?? 0);
            $uppercats = ',' . (string) ($row['uppercats'] ?? '') . ',';
            $isHeritage = $categoryId === $heritage || str_contains($uppercats, ',' . $heritage . ',');
            $isLiving = $categoryId === $living || str_contains($uppercats, ',' . $living . ',');
            if ($isHeritage === $isLiving || ($isHeritage ? 'HERITAGE' : 'LIVING') !== $expectedEra) {
                throw new \RuntimeException('class_archive_album_cover_era_invalid');
            }
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
            'display_alias' => ($row['display_alias'] ?? null) === null ? null : (string) $row['display_alias'],
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
