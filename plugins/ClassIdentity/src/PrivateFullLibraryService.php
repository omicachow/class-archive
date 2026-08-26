<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Durable, privacy-preserving state for a private full-library import.
 *
 * This is intentionally an import-state service, not a media delivery or
 * policy service. Piwigo remains the category/media manager and MediaGuard
 * remains the only authorization and byte-delivery boundary. The importer
 * supplies only opaque staging files; neither an absolute source path nor an
 * original filename is persisted here.
 */
final class PrivateFullLibraryService
{
    /** @var list<string> */
    private const IMPORT_STATES = ['PREPARED', 'RUNNING', 'COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED'];

    /** @var list<string> */
    private const ITEM_STATES = ['PENDING', 'PROCESSING', 'APPLIED', 'DEDUPLICATED', 'FAILED'];

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Import work is serialised on one connection-scoped MariaDB lease. This
     * is deliberately separate from Piwigo's MyISAM writes: the durable item
     * journal below is still the recovery record if the worker dies.
     */
    public function acquireLease(int $timeoutSeconds = 0): string
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 30) {
            throw new \InvalidArgumentException('class_archive_private_library_lock_timeout_invalid');
        }
        $lock = 'class_archive_private_full_' . substr(hash('sha256', $this->repository->table('private_library_import')), 0, 32);
        $row = $this->repository->fetchOne('SELECT GET_LOCK(?, ?) AS `acquired`', [$lock, $timeoutSeconds]);
        if ($row === null || (int) ($row['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('class_archive_private_library_lock_unavailable');
        }
        return $lock;
    }

    public function releaseLease(string $lock): void
    {
        if (!preg_match('/\Aclass_archive_private_full_[0-9a-f]{32}\z/D', $lock)) {
            throw new \InvalidArgumentException('class_archive_private_library_lock_invalid');
        }
        $row = $this->repository->fetchOne('SELECT RELEASE_LOCK(?) AS `released`', [$lock]);
        if ($row === null || (int) ($row['released'] ?? 0) !== 1) {
            throw new \RuntimeException('class_archive_private_library_lock_release_failed');
        }
    }

    /** @return array<string,mixed> */
    public function beginImport(
        int $adminUserId,
        string $manifestDigestHex,
        int $manifestVersion,
        int $itemTotal,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $manifestDigest = DomainSupport::normalizeHexDigest($manifestDigestHex, true) ?? '';
        if ($manifestVersion < 1 || $manifestVersion > 255 || $itemTotal < 1 || $itemTotal > 1000000) {
            throw new \InvalidArgumentException('class_archive_private_library_import_shape_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $manifestDigest, $manifestVersion, $itemTotal, $reason,
        ): array {
            $table = DomainSupport::table($repository, 'private_library_import');
            $existing = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `manifest_digest`=? FOR UPDATE',
                [$manifestDigest],
            );
            if ($existing !== null) {
                if ((int) $existing['manifest_version'] !== $manifestVersion || (int) $existing['item_total'] !== $itemTotal) {
                    throw new \RuntimeException('class_archive_private_library_manifest_drift');
                }
                $state = (string) $existing['state'];
                if (!in_array($state, self::IMPORT_STATES, true)) {
                    throw new \RuntimeException('class_archive_private_library_import_state_invalid');
                }
                if ($state !== 'COMPLETED') {
                    $repository->execute(
                        'UPDATE `' . $table . '` SET `state`=?,`last_error_code`=NULL,`started_at`=COALESCE(`started_at`,UTC_TIMESTAMP(6)),`completed_at`=NULL,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=?',
                        ['RUNNING', (string) $existing['import_id']],
                    );
                    $existing['state'] = 'RUNNING';
                }
                return $this->hydrateImport($existing);
            }

            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $importId = DomainSupport::generateId();
                $binary = DomainSupport::idToBinary($importId);
                if ($repository->fetchOne('SELECT `import_id` FROM `' . $table . '` WHERE `import_id`=? FOR UPDATE', [$binary]) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $table . '` (`import_id`,`manifest_digest`,`manifest_version`,`item_total`,`state`,`created_by_principal_id`,`started_at`,`created_at`,`updated_at`) '
                        . "VALUES (?, ?, ?, ?, 'RUNNING', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$binary, $manifestDigest, $manifestVersion, $itemTotal, (int) $admin['principal_id']],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PRIVATE_LIBRARY_IMPORT_BEGIN',
                    'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                    'target_id' => $importId,
                    'new_value' => ['manifest_version' => $manifestVersion, 'item_total' => $itemTotal, 'state' => 'RUNNING'],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return [
                    'import_id' => $importId,
                    'manifest_digest' => bin2hex($manifestDigest),
                    'manifest_version' => $manifestVersion,
                    'item_total' => $itemTotal,
                    'state' => 'RUNNING',
                    'applied_count' => 0,
                    'deduplicated_count' => 0,
                    'failed_count' => 0,
                ];
            }
            throw new \RuntimeException('class_archive_private_library_import_id_collision');
        });
    }

    /** @return array<string,mixed> */
    public function ensureCollection(int $adminUserId, string $sourceCode, string $displayName, string $reason): array
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $sourceCode = $this->normalizeSourceCode($sourceCode);
        $displayName = DomainSupport::boundedText($displayName, 190, true) ?? '';
        if (str_contains($displayName, '/') || str_contains($displayName, '\\')
            || preg_match('/\A[A-Za-z]:/D', $displayName) === 1 || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1
        ) {
            throw new \InvalidArgumentException('class_archive_private_library_collection_label_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use ($admin, $sourceCode, $displayName, $reason): array {
            $table = DomainSupport::table($repository, 'private_library_collection');
            $existing = $repository->fetchOne('SELECT * FROM `' . $table . '` WHERE `source_code`=? FOR UPDATE', [$sourceCode]);
            if ($existing !== null) {
                if ((string) $existing['display_name'] !== $displayName || (string) $existing['state'] !== 'ACTIVE') {
                    throw new \RuntimeException('class_archive_private_library_collection_drift');
                }
                return $this->hydrateCollection($existing);
            }
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $id = DomainSupport::generateId();
                $binary = DomainSupport::idToBinary($id);
                if ($repository->fetchOne('SELECT `source_collection_id` FROM `' . $table . '` WHERE `source_collection_id`=? FOR UPDATE', [$binary]) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $table . '` (`source_collection_id`,`source_code`,`display_name`,`state`,`created_by_principal_id`,`created_at`,`updated_at`) '
                        . "VALUES (?, ?, ?, 'ACTIVE', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$binary, $sourceCode, $displayName, (int) $admin['principal_id']],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PRIVATE_LIBRARY_COLLECTION_CREATE',
                    'target_type' => 'PRIVATE_LIBRARY_COLLECTION',
                    'target_id' => $id,
                    'new_value' => ['source_code' => $sourceCode, 'display_name' => $displayName, 'state' => 'ACTIVE'],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return ['source_collection_id' => $id, 'source_code' => $sourceCode, 'display_name' => $displayName, 'state' => 'ACTIVE'];
            }
            throw new \RuntimeException('class_archive_private_library_collection_id_collision');
        });
    }

    /** @return array<string,mixed>|null */
    public function findFolder(string $sourceCollectionId, string $relativePathDigestHex): ?array
    {
        $row = $this->repository->fetchOne(
            'SELECT * FROM `' . DomainSupport::table($this->repository, 'private_library_folder') . '` '
                . 'WHERE `source_collection_id`=? AND `relative_path_digest`=? LIMIT 1',
            [DomainSupport::idToBinary($sourceCollectionId), DomainSupport::normalizeHexDigest($relativePathDigestHex, true)],
        );
        return $row === null ? null : $this->hydrateFolder($row);
    }

    /**
     * Piwigo category creation happens before this method. The service commits
     * the deterministic digest -> category/opaque-album relation only after
     * both native objects exist; a mismatch is fail-closed rather than silently
     * pointing a folder digest at a different category.
     *
     * @return array<string,mixed>
     */
    public function ensureFolder(
        int $adminUserId,
        string $sourceCollectionId,
        string $relativePathDigestHex,
        ?string $parentFolderId,
        int $piwigoCategoryId,
        string $classAlbumId,
        string $displayName,
        int $depth,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $collectionBinary = DomainSupport::idToBinary($sourceCollectionId);
        $pathDigest = DomainSupport::normalizeHexDigest($relativePathDigestHex, true) ?? '';
        $parentBinary = $parentFolderId === null ? null : DomainSupport::idToBinary($parentFolderId);
        $albumBinary = DomainSupport::idToBinary($classAlbumId);
        $displayName = DomainSupport::boundedText($displayName, 190, true) ?? '';
        if ($piwigoCategoryId <= 0 || $depth < 0 || $depth > 255) {
            throw new \InvalidArgumentException('class_archive_private_library_folder_shape_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $sourceCollectionId, $collectionBinary, $pathDigest, $parentFolderId, $parentBinary,
            $piwigoCategoryId, $classAlbumId, $albumBinary, $displayName, $depth, $reason,
        ): array {
            $collection = $repository->fetchOne(
                'SELECT `source_collection_id`,`state` FROM `' . DomainSupport::table($repository, 'private_library_collection') . '` WHERE `source_collection_id`=? FOR UPDATE',
                [$collectionBinary],
            );
            if ($collection === null || (string) $collection['state'] !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_private_library_collection_not_active');
            }
            if ($parentBinary !== null) {
                $parent = $repository->fetchOne(
                    'SELECT `folder_id`,`source_collection_id`,`depth` FROM `' . DomainSupport::table($repository, 'private_library_folder') . '` WHERE `folder_id`=? FOR UPDATE',
                    [$parentBinary],
                );
                if ($parent === null || !hash_equals((string) $parent['source_collection_id'], $collectionBinary) || (int) $parent['depth'] + 1 !== $depth) {
                    throw new \RuntimeException('class_archive_private_library_folder_parent_invalid');
                }
            } elseif ($depth !== 0) {
                throw new \RuntimeException('class_archive_private_library_folder_root_invalid');
            }
            $album = $repository->fetchOne(
                'SELECT `class_album_id`,`piwigo_category_id`,`album_type`,`era`,`state` FROM `' . DomainSupport::table($repository, 'album') . '` WHERE `class_album_id`=? FOR UPDATE',
                [$albumBinary],
            );
            if ($album === null || (int) $album['piwigo_category_id'] !== $piwigoCategoryId
                || (string) $album['album_type'] !== 'OFFICIAL' || (string) $album['era'] !== 'HERITAGE' || (string) $album['state'] !== 'ACTIVE'
            ) {
                throw new \RuntimeException('class_archive_private_library_folder_album_invalid');
            }
            $table = DomainSupport::table($repository, 'private_library_folder');
            $existing = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `source_collection_id`=? AND `relative_path_digest`=? FOR UPDATE',
                [$collectionBinary, $pathDigest],
            );
            if ($existing !== null) {
                if (!self::nullableBinaryEquals($existing['parent_folder_id'] ?? null, $parentBinary)
                    || (int) $existing['piwigo_category_id'] !== $piwigoCategoryId
                    || !hash_equals((string) $existing['class_album_id'], $albumBinary)
                    || (string) $existing['display_name'] !== $displayName
                    || (int) $existing['depth'] !== $depth
                ) {
                    throw new \RuntimeException('class_archive_private_library_folder_drift');
                }
                return $this->hydrateFolder($existing);
            }
            if ($repository->fetchOne('SELECT `folder_id` FROM `' . $table . '` WHERE `piwigo_category_id`=? LIMIT 1 FOR UPDATE', [$piwigoCategoryId]) !== null) {
                throw new \RuntimeException('class_archive_private_library_folder_category_bound');
            }
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $folderId = DomainSupport::generateId();
                $folderBinary = DomainSupport::idToBinary($folderId);
                if ($repository->fetchOne('SELECT `folder_id` FROM `' . $table . '` WHERE `folder_id`=? FOR UPDATE', [$folderBinary]) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $table . '` (`folder_id`,`source_collection_id`,`relative_path_digest`,`parent_folder_id`,`piwigo_category_id`,`class_album_id`,`display_name`,`depth`,`created_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$folderBinary, $collectionBinary, $pathDigest, $parentBinary, $piwigoCategoryId, $albumBinary, $displayName, $depth],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PRIVATE_LIBRARY_FOLDER_MAP',
                    'target_type' => 'PRIVATE_LIBRARY_FOLDER',
                    'target_id' => $folderId,
                    'new_value' => [
                        'source_collection_id' => $sourceCollectionId,
                        'piwigo_category_id' => $piwigoCategoryId,
                        'class_album_id' => $classAlbumId,
                        'depth' => $depth,
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return [
                    'folder_id' => $folderId,
                    'source_collection_id' => $sourceCollectionId,
                    'relative_path_digest' => bin2hex($pathDigest),
                    'parent_folder_id' => $parentFolderId,
                    'piwigo_category_id' => $piwigoCategoryId,
                    'class_album_id' => $classAlbumId,
                    'display_name' => $displayName,
                    'depth' => $depth,
                ];
            }
            throw new \RuntimeException('class_archive_private_library_folder_id_collision');
        });
    }

    /** @return array{action:string,state:string,class_photo_id:?string,piwigo_image_id:?int} */
    public function claimItem(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        string $sourceCollectionId,
        string $folderId,
        string $sourceReferenceDigestHex,
        string $originalFilenameDigestHex,
        string $sourceChecksumHex,
        string $stagingNameDigestHex,
        int $byteSize,
    ): array {
        DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $collectionBinary = DomainSupport::idToBinary($sourceCollectionId);
        $folderBinary = DomainSupport::idToBinary($folderId);
        $referenceDigest = DomainSupport::normalizeHexDigest($sourceReferenceDigestHex, true) ?? '';
        $filenameDigest = DomainSupport::normalizeHexDigest($originalFilenameDigestHex, true) ?? '';
        $checksum = DomainSupport::normalizeHexDigest($sourceChecksumHex, true) ?? '';
        $stagingDigest = DomainSupport::normalizeHexDigest($stagingNameDigestHex, true) ?? '';
        if ($byteSize <= 0) {
            throw new \InvalidArgumentException('class_archive_private_library_item_size_invalid');
        }
        return $this->repository->transaction(function (Repository $repository) use (
            $importBinary, $itemDigest, $collectionBinary, $folderBinary, $referenceDigest,
            $filenameDigest, $checksum, $stagingDigest, $byteSize,
        ): array {
            $import = $repository->fetchOne(
                'SELECT `import_id`,`state` FROM `' . DomainSupport::table($repository, 'private_library_import') . '` WHERE `import_id`=? FOR UPDATE',
                [$importBinary],
            );
            if ($import === null || !in_array((string) $import['state'], ['RUNNING', 'COMPLETED_WITH_ERRORS'], true)) {
                throw new \RuntimeException('class_archive_private_library_import_not_running');
            }
            $folder = $repository->fetchOne(
                'SELECT `folder_id`,`source_collection_id` FROM `' . DomainSupport::table($repository, 'private_library_folder') . '` WHERE `folder_id`=? FOR UPDATE',
                [$folderBinary],
            );
            if ($folder === null || !hash_equals((string) $folder['source_collection_id'], $collectionBinary)) {
                throw new \RuntimeException('class_archive_private_library_item_folder_invalid');
            }
            $table = DomainSupport::table($repository, 'private_library_import_item');
            $existing = $repository->fetchOne(
                'SELECT * FROM `' . $table . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($existing !== null) {
                if (!hash_equals((string) $existing['source_collection_id'], $collectionBinary)
                    || !self::nullableBinaryEquals($existing['folder_id'] ?? null, $folderBinary)
                    || !hash_equals((string) $existing['source_reference_digest'], $referenceDigest)
                    || !hash_equals((string) $existing['original_filename_digest'], $filenameDigest)
                    || !hash_equals((string) $existing['source_checksum'], $checksum)
                    || !hash_equals((string) $existing['staging_name_digest'], $stagingDigest)
                    || (int) $existing['byte_size'] !== $byteSize
                ) {
                    throw new \RuntimeException('class_archive_private_library_item_drift');
                }
                $state = (string) $existing['state'];
                if (!in_array($state, self::ITEM_STATES, true)) {
                    throw new \RuntimeException('class_archive_private_library_item_state_invalid');
                }
                if (in_array($state, ['APPLIED', 'DEDUPLICATED'], true)) {
                    return [
                        'action' => 'SKIP',
                        'state' => $state,
                        'class_photo_id' => $existing['class_photo_id'] === null ? null : DomainSupport::binaryToId((string) $existing['class_photo_id']),
                        'piwigo_image_id' => $existing['piwigo_image_id'] === null ? null : (int) $existing['piwigo_image_id'],
                    ];
                }
                $repository->execute(
                    'UPDATE `' . $table . '` SET `state`=?,`attempt_count`=`attempt_count`+1,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=? AND `item_digest`=?',
                    ['PROCESSING', $importBinary, $itemDigest],
                );
                return [
                    'action' => 'PROCESS',
                    'state' => 'PROCESSING',
                    'class_photo_id' => null,
                    // Piwigo's legacy image/category tables are not part of
                    // the InnoDB transaction.  A prior worker may therefore
                    // have persisted a native image before it could finish the
                    // canonical mapping.  Preserve the checksum-validated
                    // checkpoint so a retry can resume it rather than creating
                    // another original.
                    'piwigo_image_id' => (int) ($existing['piwigo_image_id'] ?? 0) > 0 ? (int) $existing['piwigo_image_id'] : null,
                ];
            }
            $repository->execute(
                'INSERT INTO `' . $table . '` (`import_id`,`item_digest`,`source_collection_id`,`folder_id`,`source_reference_digest`,`original_filename_digest`,`source_checksum`,`staging_name_digest`,`byte_size`,`state`,`attempt_count`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PROCESSING', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$importBinary, $itemDigest, $collectionBinary, $folderBinary, $referenceDigest, $filenameDigest, $checksum, $stagingDigest, $byteSize],
            );
            return ['action' => 'PROCESS', 'state' => 'PROCESSING', 'class_photo_id' => null, 'piwigo_image_id' => null];
        });
    }

    /**
     * Persist the native Piwigo image id immediately after its original bytes
     * have been independently checksum-verified.  This is the durable half
     * of a conservative recovery saga around Piwigo's non-transactional
     * legacy image tables; it is not a permission or delivery decision.
     */
    public function checkpointPiwigoImage(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        int $piwigoImageId,
        string $sourceChecksumHex,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $checksum = DomainSupport::normalizeHexDigest($sourceChecksumHex, true) ?? '';
        if ($piwigoImageId <= 0) {
            throw new \InvalidArgumentException('class_archive_private_library_checkpoint_image_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use (
            $admin, $importId, $importBinary, $itemDigest, $piwigoImageId, $checksum, $reason,
        ): void {
            $table = DomainSupport::table($repository, 'private_library_import_item');
            $row = $repository->fetchOne(
                'SELECT `state`,`source_checksum`,`piwigo_image_id` FROM `' . $table . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($row === null || (string) $row['state'] !== 'PROCESSING'
                || !hash_equals((string) $row['source_checksum'], $checksum)
            ) {
                throw new \RuntimeException('class_archive_private_library_checkpoint_invalid');
            }
            $existingImageId = (int) ($row['piwigo_image_id'] ?? 0);
            if ($existingImageId > 0 && $existingImageId !== $piwigoImageId) {
                throw new \RuntimeException('class_archive_private_library_checkpoint_conflict');
            }
            if ($existingImageId === 0) {
                $changed = $repository->execute(
                    'UPDATE `' . $table . '` SET `piwigo_image_id`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=? AND `item_digest`=? AND `state`=\'PROCESSING\' AND `piwigo_image_id` IS NULL',
                    [$piwigoImageId, $importBinary, $itemDigest],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_private_library_checkpoint_race');
                }
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PRIVATE_LIBRARY_IMPORT_ITEM_CHECKPOINT',
                    'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                    'target_id' => $importId,
                    'new_value' => ['piwigo_image_id' => $piwigoImageId],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            }
        });
    }

    /**
     * Checkpoint a native image created from a verified presentation surrogate.
     * The import item remains bound to the immutable source checksum, so the
     * distinct presentation checksum is validated by the caller and recorded
     * later by completeTransformedItem's provenance join.
     */
    public function checkpointTransformedPiwigoImage(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        int $piwigoImageId,
        string $sourceChecksumHex,
        string $presentationChecksumHex,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $sourceChecksum = DomainSupport::normalizeHexDigest($sourceChecksumHex, true) ?? '';
        $presentationChecksum = DomainSupport::normalizeHexDigest($presentationChecksumHex, true) ?? '';
        if ($piwigoImageId <= 0 || hash_equals($sourceChecksum, $presentationChecksum)) {
            throw new \InvalidArgumentException('class_archive_private_library_transformed_checkpoint_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use (
            $admin, $importId, $importBinary, $itemDigest, $piwigoImageId, $sourceChecksum, $reason,
        ): void {
            $table = DomainSupport::table($repository, 'private_library_import_item');
            $row = $repository->fetchOne(
                'SELECT `state`,`source_checksum`,`piwigo_image_id` FROM `' . $table . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($row === null || (string) $row['state'] !== 'PROCESSING'
                || !hash_equals((string) $row['source_checksum'], $sourceChecksum)
            ) {
                throw new \RuntimeException('class_archive_private_library_checkpoint_invalid');
            }
            $existingImageId = (int) ($row['piwigo_image_id'] ?? 0);
            if ($existingImageId > 0 && $existingImageId !== $piwigoImageId) {
                throw new \RuntimeException('class_archive_private_library_checkpoint_conflict');
            }
            if ($existingImageId === 0) {
                $changed = $repository->execute(
                    'UPDATE `' . $table . '` SET `piwigo_image_id`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=? AND `item_digest`=? AND `state`=\'PROCESSING\' AND `piwigo_image_id` IS NULL',
                    [$piwigoImageId, $importBinary, $itemDigest],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_private_library_checkpoint_race');
                }
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PRIVATE_LIBRARY_IMPORT_ITEM_CHECKPOINT',
                    'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                    'target_id' => $importId,
                    'new_value' => ['piwigo_image_id' => $piwigoImageId, 'presentation_kind' => 'MPO_PRIMARY_FRAME_JPEG'],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            }
        });
    }

    /** @return array{class_photo_id:string,piwigo_image_id:int}|null */
    public function findActiveCanonicalByChecksum(string $checksumHex): ?array
    {
        $checksum = DomainSupport::normalizeHexDigest($checksumHex, true) ?? '';
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`piwigo_image_id` FROM `' . $this->repository->table('photo') . '` '
                . "WHERE `media_checksum`=? AND `state`='ACTIVE' ORDER BY `piwigo_image_id` ASC LIMIT 3",
            [$checksum],
        );
        if (count($rows) > 1) {
            throw new \RuntimeException('class_archive_private_library_checksum_ambiguous');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_string($row['class_photo_id'] ?? null) || (int) ($row['piwigo_image_id'] ?? 0) <= 0) {
            throw new \RuntimeException('class_archive_private_library_canonical_invalid');
        }
        return ['class_photo_id' => DomainSupport::binaryToId((string) $row['class_photo_id']), 'piwigo_image_id' => (int) $row['piwigo_image_id']];
    }

    public function completeItem(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        string $state,
        string $classPhotoId,
        int $piwigoImageId,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $state = strtoupper(trim($state));
        if (!in_array($state, ['APPLIED', 'DEDUPLICATED'], true) || $piwigoImageId <= 0) {
            throw new \InvalidArgumentException('class_archive_private_library_item_complete_invalid');
        }
        $photoBinary = DomainSupport::idToBinary($classPhotoId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use (
            $admin, $importId, $importBinary, $itemDigest, $state, $classPhotoId, $photoBinary, $piwigoImageId, $reason,
        ): void {
            $itemTable = DomainSupport::table($repository, 'private_library_import_item');
            $row = $repository->fetchOne(
                'SELECT `state`,`source_checksum`,`piwigo_image_id` FROM `' . $itemTable . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($row === null || (string) $row['state'] !== 'PROCESSING') {
                throw new \RuntimeException('class_archive_private_library_item_not_processing');
            }
            $photo = $repository->fetchOne(
                'SELECT `class_photo_id`,`piwigo_image_id`,`media_checksum`,`state` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoBinary],
            );
            if ($photo === null || (string) $photo['state'] !== ClassArchivePhoto::STATE_ACTIVE
                || (int) $photo['piwigo_image_id'] !== $piwigoImageId
                || !hash_equals((string) $photo['media_checksum'], (string) $row['source_checksum'])
            ) {
                throw new \RuntimeException('class_archive_private_library_item_canonical_drift');
            }
            $changed = $repository->execute(
                'UPDATE `' . $itemTable . '` SET `state`=?,`class_photo_id`=?,`piwigo_image_id`=?,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `import_id`=? AND `item_digest`=? AND `state`='PROCESSING'",
                [$state, $photoBinary, $piwigoImageId, $importBinary, $itemDigest],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_private_library_item_complete_race');
            }
            $this->refreshImportCounts($repository, $importBinary);
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PRIVATE_LIBRARY_IMPORT_ITEM',
                'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                'target_id' => $importId,
                'new_value' => ['state' => $state, 'class_photo_id' => $classPhotoId, 'piwigo_image_id' => $piwigoImageId],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    /**
     * Complete an item represented by a verified presentation surrogate.
     * The durable import item remains bound to the immutable source checksum;
     * publication is allowed only when the active photo and its explicit
     * presentation provenance both match the caller-supplied media checksum.
     */
    public function completeTransformedItem(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        string $state,
        string $classPhotoId,
        int $piwigoImageId,
        string $presentationChecksumHex,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $presentationChecksum = DomainSupport::normalizeHexDigest($presentationChecksumHex, true) ?? '';
        $state = strtoupper(trim($state));
        if (!in_array($state, ['APPLIED', 'DEDUPLICATED'], true) || $piwigoImageId <= 0) {
            throw new \InvalidArgumentException('class_archive_private_library_item_complete_invalid');
        }
        $photoBinary = DomainSupport::idToBinary($classPhotoId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use (
            $admin, $importId, $importBinary, $itemDigest, $state, $classPhotoId, $photoBinary,
            $piwigoImageId, $presentationChecksum, $reason,
        ): void {
            $itemTable = DomainSupport::table($repository, 'private_library_import_item');
            $row = $repository->fetchOne(
                'SELECT `state`,`source_checksum`,`piwigo_image_id` FROM `' . $itemTable . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($row === null || (string) $row['state'] !== 'PROCESSING' || !is_string($row['source_checksum'] ?? null)) {
                throw new \RuntimeException('class_archive_private_library_item_not_processing');
            }
            $photo = $repository->fetchOne(
                'SELECT `class_photo_id`,`piwigo_image_id`,`media_checksum`,`state` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoBinary],
            );
            if ($photo === null || (string) $photo['state'] !== ClassArchivePhoto::STATE_ACTIVE
                || (int) $photo['piwigo_image_id'] !== $piwigoImageId
                || !is_string($photo['media_checksum'] ?? null)
                || !hash_equals((string) $photo['media_checksum'], $presentationChecksum)
            ) {
                throw new \RuntimeException('class_archive_private_library_item_presentation_drift');
            }
            $presentation = $repository->fetchAll(
                'SELECT ps.`source_checksum`,pp.`presentation_checksum` FROM `'
                    . $repository->table('photo_source_presentation') . '` pp INNER JOIN `'
                    . $repository->table('photo_source') . '` ps ON ps.`id`=pp.`photo_source_id` '
                    . 'WHERE ps.`class_photo_id`=? AND pp.`source_identity_digest`=? LIMIT 2 FOR UPDATE',
                [$photoBinary, $itemDigest],
            );
            if (count($presentation) !== 1
                || !hash_equals((string) ($presentation[0]['source_checksum'] ?? ''), (string) $row['source_checksum'])
                || !hash_equals((string) ($presentation[0]['presentation_checksum'] ?? ''), $presentationChecksum)
            ) {
                throw new \RuntimeException('class_archive_private_library_item_presentation_provenance_invalid');
            }
            $checkpointedImageId = (int) ($row['piwigo_image_id'] ?? 0);
            if (($state === 'APPLIED' && $checkpointedImageId !== $piwigoImageId)
                || ($state === 'DEDUPLICATED' && $checkpointedImageId !== 0)
            ) {
                throw new \RuntimeException('class_archive_private_library_item_completion_kind_invalid');
            }
            $changed = $repository->execute(
                'UPDATE `' . $itemTable . '` SET `state`=?,`class_photo_id`=?,`piwigo_image_id`=?,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `import_id`=? AND `item_digest`=? AND `state`='PROCESSING'",
                [$state, $photoBinary, $piwigoImageId, $importBinary, $itemDigest],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_private_library_item_complete_race');
            }
            $this->refreshImportCounts($repository, $importBinary);
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PRIVATE_LIBRARY_IMPORT_ITEM',
                'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                'target_id' => $importId,
                'new_value' => [
                    'state' => $state,
                    'class_photo_id' => $classPhotoId,
                    'piwigo_image_id' => $piwigoImageId,
                    'presentation_kind' => 'MPO_PRIMARY_FRAME_JPEG',
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    public function failItem(
        int $adminUserId,
        string $importId,
        string $itemDigestHex,
        string $errorCode,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $itemDigest = DomainSupport::normalizeHexDigest($itemDigestHex, true) ?? '';
        $errorCode = $this->normalizeErrorCode($errorCode);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $importId, $importBinary, $itemDigest, $errorCode, $reason): void {
            $itemTable = DomainSupport::table($repository, 'private_library_import_item');
            $row = $repository->fetchOne(
                'SELECT `state` FROM `' . $itemTable . '` WHERE `import_id`=? AND `item_digest`=? FOR UPDATE',
                [$importBinary, $itemDigest],
            );
            if ($row === null || !in_array((string) $row['state'], ['PENDING', 'PROCESSING', 'FAILED'], true)) {
                throw new \RuntimeException('class_archive_private_library_item_fail_invalid');
            }
            $repository->execute(
                // Keep a checksum-validated native checkpoint through a
                // retryable item failure.  It is needed to reconcile the
                // Piwigo/Innodb transaction boundary without depending on a
                // Piwigo filename or duplicate-detection setting.
                'UPDATE `' . $itemTable . '` SET `state`=?,`class_photo_id`=NULL,`last_error_code`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=? AND `item_digest`=?',
                ['FAILED', $errorCode, $importBinary, $itemDigest],
            );
            $this->refreshImportCounts($repository, $importBinary);
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PRIVATE_LIBRARY_IMPORT_ITEM_FAIL',
                'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                'target_id' => $importId,
                'new_value' => ['state' => 'FAILED', 'error_code' => $errorCode],
                'reason' => $reason,
                'result' => 'FAILED',
            ]);
        });
    }

    /** @return array<string,mixed> */
    public function finishImport(int $adminUserId, string $importId, string $reason): array
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $importBinary = DomainSupport::idToBinary($importId);
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use ($admin, $importId, $importBinary, $reason): array {
            $table = DomainSupport::table($repository, 'private_library_import');
            $row = $repository->fetchOne('SELECT * FROM `' . $table . '` WHERE `import_id`=? FOR UPDATE', [$importBinary]);
            if ($row === null) {
                throw new \RuntimeException('class_archive_private_library_import_missing');
            }
            $counts = $this->refreshImportCounts($repository, $importBinary);
            $itemTotal = (int) $row['item_total'];
            $terminal = $counts['applied_count'] + $counts['deduplicated_count'] + $counts['failed_count'];
            $state = $terminal === $itemTotal
                ? ($counts['failed_count'] > 0 ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED')
                : 'RUNNING';
            $completed = $state === 'RUNNING' ? null : 'UTC_TIMESTAMP(6)';
            if ($completed === null) {
                $repository->execute(
                    'UPDATE `' . $table . '` SET `state`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=?',
                    [$state, $importBinary],
                );
            } else {
                $repository->execute(
                    'UPDATE `' . $table . '` SET `state`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=?',
                    [$state, $importBinary],
                );
            }
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PRIVATE_LIBRARY_IMPORT_FINISH',
                'target_type' => 'PRIVATE_LIBRARY_IMPORT',
                'target_id' => $importId,
                'new_value' => ['state' => $state] + $counts,
                'reason' => $reason,
                'result' => $state === 'COMPLETED_WITH_ERRORS' ? 'FAILED' : 'SUCCESS',
            ]);
            return ['import_id' => $importId, 'state' => $state, 'item_total' => $itemTotal] + $counts;
        });
    }

    /**
     * Return the durable, bounded set of canonical photos created by one
     * terminal import. This is the crash-recovery source for incremental AI
     * enqueueing: APPLIED means this import checkpointed the native image;
     * DEDUPLICATED means it reused an older canonical and must not enqueue it.
     *
     * @return list<array{class_photo_id:string,piwigo_image_id:int}>
     */
    public function terminalAppliedPhotosForImport(
        string $importId,
        string $manifestDigestHex,
        int $expectedItemTotal,
    ): array {
        $importBinary = DomainSupport::idToBinary($importId);
        $manifestDigest = DomainSupport::normalizeHexDigest($manifestDigestHex, true) ?? '';
        if ($expectedItemTotal < 1 || $expectedItemTotal > 10000) {
            throw new \InvalidArgumentException('class_archive_private_library_terminal_query_bound_invalid');
        }
        $import = $this->repository->fetchOne(
            'SELECT `manifest_digest`,`item_total`,`state`,`applied_count` FROM `'
                . $this->repository->table('private_library_import') . '` WHERE `import_id`=? LIMIT 1',
            [$importBinary],
        );
        if ($import === null
            || !is_string($import['manifest_digest'] ?? null)
            || !hash_equals((string) $import['manifest_digest'], $manifestDigest)
            || (int) ($import['item_total'] ?? 0) !== $expectedItemTotal
            || !in_array((string) ($import['state'] ?? ''), ['COMPLETED', 'COMPLETED_WITH_ERRORS'], true)
        ) {
            throw new \RuntimeException('class_archive_private_library_terminal_import_invalid');
        }
        $rows = $this->repository->fetchAll(
            'SELECT i.`item_digest`,i.`class_photo_id`,i.`piwigo_image_id`,'
                . 'p.`class_photo_id` AS `active_photo_id`,p.`piwigo_image_id` AS `active_piwigo_image_id`,p.`state` AS `photo_state` '
                . 'FROM `' . $this->repository->table('private_library_import_item') . '` i '
                . 'LEFT JOIN `' . $this->repository->table('photo') . '` p ON p.`class_photo_id`=i.`class_photo_id` '
                . "WHERE i.`import_id`=? AND i.`state`='APPLIED' ORDER BY i.`item_digest` ASC LIMIT "
                . ($expectedItemTotal + 1),
            [$importBinary],
        );
        if (count($rows) > $expectedItemTotal) {
            throw new \RuntimeException('class_archive_private_library_terminal_query_overflow');
        }
        if ((int) ($import['applied_count'] ?? -1) !== count($rows)) {
            throw new \RuntimeException('class_archive_private_library_terminal_applied_count_drift');
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $photoBinary = $row['class_photo_id'] ?? null;
            $activeBinary = $row['active_photo_id'] ?? null;
            $imageId = (int) ($row['piwigo_image_id'] ?? 0);
            if (!is_string($photoBinary) || strlen($photoBinary) !== 16
                || !is_string($activeBinary) || !hash_equals($photoBinary, $activeBinary)
                || $imageId <= 0 || (int) ($row['active_piwigo_image_id'] ?? 0) !== $imageId
                || (string) ($row['photo_state'] ?? '') !== ClassArchivePhoto::STATE_ACTIVE
            ) {
                throw new \RuntimeException('class_archive_private_library_terminal_photo_invalid');
            }
            $classPhotoId = DomainSupport::binaryToId($photoBinary);
            if (isset($seen[$classPhotoId])) {
                // Two source items may theoretically point at one canonical,
                // but two APPLIED rows would claim they both created it.
                throw new \RuntimeException('class_archive_private_library_terminal_photo_ambiguous');
            }
            $seen[$classPhotoId] = true;
            $result[] = ['class_photo_id' => $classPhotoId, 'piwigo_image_id' => $imageId];
        }
        return $result;
    }

    /** @return array{applied_count:int,deduplicated_count:int,failed_count:int} */
    private function refreshImportCounts(Repository $repository, string $importBinary): array
    {
        $itemTable = DomainSupport::table($repository, 'private_library_import_item');
        $counts = $repository->fetchOne(
            'SELECT '
                . "COALESCE(SUM(`state`='APPLIED'),0) AS `applied_count`,"
                . "COALESCE(SUM(`state`='DEDUPLICATED'),0) AS `deduplicated_count`,"
                . "COALESCE(SUM(`state`='FAILED'),0) AS `failed_count` "
                . 'FROM `' . $itemTable . '` WHERE `import_id`=?',
            [$importBinary],
        );
        if ($counts === null) {
            throw new \RuntimeException('class_archive_private_library_counts_unavailable');
        }
        $normalized = [
            'applied_count' => (int) ($counts['applied_count'] ?? 0),
            'deduplicated_count' => (int) ($counts['deduplicated_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
        ];
        $repository->execute(
            'UPDATE `' . DomainSupport::table($repository, 'private_library_import') . '` '
                . 'SET `applied_count`=?,`deduplicated_count`=?,`failed_count`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `import_id`=?',
            [$normalized['applied_count'], $normalized['deduplicated_count'], $normalized['failed_count'], $importBinary],
        );
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function hydrateImport(array $row): array
    {
        if (!is_string($row['import_id'] ?? null) || !is_string($row['manifest_digest'] ?? null)) {
            throw new \RuntimeException('class_archive_private_library_import_hydration_invalid');
        }
        return [
            'import_id' => DomainSupport::binaryToId((string) $row['import_id']),
            'manifest_digest' => bin2hex((string) $row['manifest_digest']),
            'manifest_version' => (int) $row['manifest_version'],
            'item_total' => (int) $row['item_total'],
            'state' => (string) $row['state'],
            'applied_count' => (int) $row['applied_count'],
            'deduplicated_count' => (int) $row['deduplicated_count'],
            'failed_count' => (int) $row['failed_count'],
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateCollection(array $row): array
    {
        if (!is_string($row['source_collection_id'] ?? null)) {
            throw new \RuntimeException('class_archive_private_library_collection_hydration_invalid');
        }
        return [
            'source_collection_id' => DomainSupport::binaryToId((string) $row['source_collection_id']),
            'source_code' => (string) $row['source_code'],
            'display_name' => (string) $row['display_name'],
            'state' => (string) $row['state'],
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateFolder(array $row): array
    {
        if (!is_string($row['folder_id'] ?? null) || !is_string($row['source_collection_id'] ?? null)
            || !is_string($row['relative_path_digest'] ?? null) || !is_string($row['class_album_id'] ?? null)
        ) {
            throw new \RuntimeException('class_archive_private_library_folder_hydration_invalid');
        }
        return [
            'folder_id' => DomainSupport::binaryToId((string) $row['folder_id']),
            'source_collection_id' => DomainSupport::binaryToId((string) $row['source_collection_id']),
            'relative_path_digest' => bin2hex((string) $row['relative_path_digest']),
            'parent_folder_id' => $row['parent_folder_id'] === null ? null : DomainSupport::binaryToId((string) $row['parent_folder_id']),
            'piwigo_category_id' => (int) $row['piwigo_category_id'],
            'class_album_id' => DomainSupport::binaryToId((string) $row['class_album_id']),
            'display_name' => (string) $row['display_name'],
            'depth' => (int) $row['depth'],
        ];
    }

    private function normalizeSourceCode(string $sourceCode): string
    {
        $sourceCode = strtoupper(trim($sourceCode));
        if (!in_array($sourceCode, ['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B'], true)) {
            throw new \InvalidArgumentException('class_archive_private_library_source_code_invalid');
        }
        return $sourceCode;
    }

    private function normalizeErrorCode(string $errorCode): string
    {
        $errorCode = strtoupper(trim($errorCode));
        if (preg_match('/\A[A-Z][A-Z0-9_]{1,63}\z/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('class_archive_private_library_error_code_invalid');
        }
        return $errorCode;
    }

    private static function nullableBinaryEquals(mixed $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        return is_string($left) && hash_equals($left, $right);
    }
}
