<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Persistent ClassArchivePhoto -> Piwigo -> (future) Immich mapping.
 *
 * This service deliberately owns no file delivery. It records enough stable
 * provenance to reconcile a media file later, while MediaGuard remains the
 * only authorization and byte-delivery boundary.
 */
final class ClassArchivePhotoMappingService
{
    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return array<string, mixed>|null */
    public function findByClassPhotoId(string $classPhotoId): ?array
    {
        $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
        $row = $this->repository->fetchOne(
            'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
            . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
            . 'FROM `' . $this->repository->table('photo') . '` WHERE `class_photo_id` = ? LIMIT 1',
            [$binaryId],
        );

        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<string, mixed>|null */
    public function findByPiwigoImageId(int $piwigoImageId): ?array
    {
        if ($piwigoImageId <= 0) {
            return null;
        }
        $row = $this->repository->fetchOne(
            'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
            . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
            . 'FROM `' . $this->repository->table('photo') . '` WHERE `piwigo_image_id` = ? LIMIT 1',
            [$piwigoImageId],
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Resolve only complete, active canonical-to-Immich bindings for a
     * policy-approved set. This is intentionally an all-or-nothing lookup:
     * a partial external index must make enrichment unavailable rather than
     * silently produce a count derived from an unknown subset.
     *
     * @param list<string> $classPhotoIds
     * @return array<string,string> canonical UUID => internal Immich asset UUID
     */
    public function activeImmichAssetBindings(array $classPhotoIds): array
    {
        if ($classPhotoIds === []) {
            return [];
        }
        if (count($classPhotoIds) > 500) {
            throw new \InvalidArgumentException('class_archive_photo_immich_binding_batch_invalid');
        }

        $binaryIds = [];
        $expected = [];
        foreach ($classPhotoIds as $classPhotoId) {
            if (!is_string($classPhotoId)) {
                throw new \InvalidArgumentException('class_archive_photo_id_invalid');
            }
            $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
            if (isset($expected[$classPhotoId])) {
                throw new \RuntimeException('class_archive_photo_immich_binding_duplicate');
            }
            $expected[$classPhotoId] = true;
            $binaryIds[] = $binaryId;
        }

        $placeholders = implode(',', array_fill(0, count($binaryIds), '?'));
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
            . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
            . 'FROM `' . $this->repository->table('photo') . '` '
            . 'WHERE `class_photo_id` IN (' . $placeholders . ') AND `state` = ? AND `immich_asset_id` IS NOT NULL',
            array_merge($binaryIds, [ClassArchivePhoto::STATE_ACTIVE]),
        );

        $result = [];
        foreach ($rows as $row) {
            $mapping = self::hydrate($row);
            $classPhotoId = (string) $mapping['class_photo_id'];
            $assetId = $mapping['immich_asset_id'] ?? null;
            if (!isset($expected[$classPhotoId]) || !is_string($assetId) || isset($result[$classPhotoId])) {
                throw new \RuntimeException('class_archive_photo_immich_binding_invalid');
            }
            $result[$classPhotoId] = $assetId;
        }
        if (count($result) !== count($expected)) {
            throw new \RuntimeException('class_archive_photo_immich_binding_incomplete');
        }

        return $result;
    }

    /**
     * Create a mapping for an already accepted Piwigo image or return the
     * existing verified mapping. A changed file digest/reference marks the
     * existing mapping STALE and refuses to continue instead of silently
     * rebinding an opaque public id to different media.
     *
     * @return array<string, mixed>
     */
    public function ensurePiwigoMapping(int $piwigoImageId, string $checksumHex, string $mediaReference): array
    {
        if ($piwigoImageId <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_piwigo_image_id_invalid');
        }
        $checksum = ClassArchivePhoto::checksumToBinary($checksumHex);
        $mediaReference = ClassArchivePhoto::normalizeMediaReference($mediaReference);

        $drifted = false;
        $mapping = $this->repository->transaction(function (Repository $repository) use ($piwigoImageId, $checksum, $mediaReference, &$drifted): array {
            $row = $repository->fetchOne(
                'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
                . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
                . 'FROM `' . $repository->table('photo') . '` WHERE `piwigo_image_id` = ? FOR UPDATE',
                [$piwigoImageId],
            );
            if ($row !== null) {
                $mapped = self::hydrate($row);
                if (($mapped['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE) {
                    throw new \RuntimeException('class_archive_photo_mapping_not_active');
                }
                if (
                    !hash_equals((string) $mapped['media_checksum'], ClassArchivePhoto::checksumToHex($checksum))
                    || !hash_equals((string) $mapped['media_reference'], $mediaReference)
                ) {
                    // A previously published mapping must invalidate the
                    // catalog even when its Piwigo image has disappeared.
                    // The missing Core row is itself drift; retaining an
                    // ACTIVE catalog in that case would keep stale metadata
                    // and counts available after byte delivery starts denying.
                    ProjectionMutationBoundary::invalidatePhotos(
                        $repository,
                        ProjectionMutationBoundary::allAggregateKinds(),
                        'PHOTO_MAPPING_DRIFT',
                    );
                    $changed = $repository->execute(
                        'UPDATE `' . $repository->table('photo') . '` SET `state` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                        . 'WHERE `class_photo_id` = ? AND `state` = ?',
                        [ClassArchivePhoto::STATE_STALE, (string) $row['class_photo_id'], ClassArchivePhoto::STATE_ACTIVE],
                    );
                    if ($changed !== 1) {
                        throw new \RuntimeException('class_archive_photo_mapping_stale_race');
                    }
                    // Do not throw inside this transaction: an exception would
                    // roll the STALE transition back and silently leave a
                    // changed physical file projected as ACTIVE.
                    $drifted = true;
                    return $mapped;
                }

                return $mapped;
            }

            // A UUID collision is cryptographically negligible, but the
            // primary key remains the source of truth. The bounded retry
            // avoids accepting a duplicate mapping in the impossible case.
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $classPhotoId = ClassArchivePhoto::generateId();
                $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
                $alreadyUsed = $repository->fetchOne(
                    'SELECT `class_photo_id` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id` = ? LIMIT 1 FOR UPDATE',
                    [$binaryId],
                );
                if ($alreadyUsed !== null) {
                    continue;
                }
                if ($this->piwigoImageExists($repository, $piwigoImageId)) {
                    ProjectionMutationBoundary::invalidatePhotos(
                        $repository,
                        ProjectionMutationBoundary::allAggregateKinds(),
                        'PHOTO_MAPPING_CREATE',
                    );
                }
                $repository->execute(
                    'INSERT INTO `' . $repository->table('photo') . '` '
                    . '(`class_photo_id`,`piwigo_image_id`,`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$binaryId, $piwigoImageId, $checksum, $mediaReference, ClassArchivePhoto::STATE_ACTIVE],
                );

                return [
                    'class_photo_id' => $classPhotoId,
                    'piwigo_image_id' => $piwigoImageId,
                    'source_submission_id' => null,
                    'immich_asset_id' => null,
                    'media_checksum' => ClassArchivePhoto::checksumToHex($checksum),
                    'media_reference' => $mediaReference,
                    'state' => ClassArchivePhoto::STATE_ACTIVE,
                ];
            }

            throw new \RuntimeException('class_archive_photo_mapping_id_collision');
        });
        if ($drifted) {
            throw new \RuntimeException('class_archive_photo_mapping_drift');
        }

        return $mapping;
    }

    /**
     * Reserve the source Submission mapping before Piwigo accepts it. Pending
     * rows are never public and are excluded by the gateway policy.
     *
     * @return array<string, mixed>
     */
    public function createPendingSubmissionMapping(
        int $submissionId,
        string $checksumHex,
        string $mediaReference,
    ): array {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_submission_id_invalid');
        }
        $checksum = ClassArchivePhoto::checksumToBinary($checksumHex);
        $mediaReference = ClassArchivePhoto::normalizePendingMediaReference($mediaReference);

        return $this->repository->transaction(function (Repository $repository) use ($submissionId, $checksum, $mediaReference): array {
            $row = $repository->fetchOne(
                'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
                . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
                . 'FROM `' . $repository->table('photo') . '` WHERE `source_submission_id` = ? FOR UPDATE',
                [$submissionId],
            );
            if ($row !== null) {
                $mapped = self::hydrate($row);
                if (
                    ($mapped['state'] ?? null) !== ClassArchivePhoto::STATE_PENDING
                    || !hash_equals((string) $mapped['media_checksum'], ClassArchivePhoto::checksumToHex($checksum))
                    || !hash_equals((string) $mapped['media_reference'], $mediaReference)
                ) {
                    throw new \RuntimeException('class_archive_photo_pending_mapping_drift');
                }
                return $mapped;
            }
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $classPhotoId = ClassArchivePhoto::generateId();
                $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
                if ($repository->fetchOne(
                    'SELECT `class_photo_id` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id` = ? LIMIT 1 FOR UPDATE',
                    [$binaryId],
                ) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $repository->table('photo') . '` '
                    . '(`class_photo_id`,`source_submission_id`,`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$binaryId, $submissionId, $checksum, $mediaReference, ClassArchivePhoto::STATE_PENDING],
                );
                return [
                    'class_photo_id' => $classPhotoId,
                    'piwigo_image_id' => null,
                    'source_submission_id' => $submissionId,
                    'immich_asset_id' => null,
                    'media_checksum' => ClassArchivePhoto::checksumToHex($checksum),
                    'media_reference' => $mediaReference,
                    'state' => ClassArchivePhoto::STATE_PENDING,
                ];
            }
            throw new \RuntimeException('class_archive_photo_mapping_id_collision');
        });
    }

    /**
     * Complete a pending mapping after the existing Piwigo approval pipeline
     * has created an image. No file is copied and no Immich API is called.
     *
     * @return array<string, mixed>
     */
    public function promotePendingMapping(int $submissionId, int $piwigoImageId, string $checksumHex, string $mediaReference): array
    {
        if ($submissionId <= 0 || $piwigoImageId <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_promotion_id_invalid');
        }
        $checksum = ClassArchivePhoto::checksumToBinary($checksumHex);
        $mediaReference = ClassArchivePhoto::normalizeMediaReference($mediaReference);

        return $this->repository->transaction(function (Repository $repository) use ($submissionId, $piwigoImageId, $checksum, $mediaReference): array {
            $row = $repository->fetchOne(
                'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`immich_asset_id`,'
                . '`media_checksum`,`media_reference`,`state`,`created_at`,`updated_at` '
                . 'FROM `' . $repository->table('photo') . '` WHERE `source_submission_id` = ? FOR UPDATE',
                [$submissionId],
            );
            if ($row === null) {
                return $this->ensurePiwigoMapping($piwigoImageId, ClassArchivePhoto::checksumToHex($checksum), $mediaReference);
            }
            $mapped = self::hydrate($row);
            if (
                ($mapped['state'] ?? null) !== ClassArchivePhoto::STATE_PENDING
                || !hash_equals((string) $mapped['media_checksum'], ClassArchivePhoto::checksumToHex($checksum))
            ) {
                throw new \RuntimeException('class_archive_photo_pending_mapping_drift');
            }
            $existingImage = $repository->fetchOne(
                'SELECT `class_photo_id` FROM `' . $repository->table('photo') . '` '
                . 'WHERE `piwigo_image_id` = ? AND `class_photo_id` <> ? LIMIT 1 FOR UPDATE',
                [$piwigoImageId, (string) $row['class_photo_id']],
            );
            if ($existingImage !== null) {
                throw new \RuntimeException('class_archive_photo_piwigo_mapping_conflict');
            }
            if ($this->piwigoImageExists($repository, $piwigoImageId)) {
                ProjectionMutationBoundary::invalidatePhotos(
                    $repository,
                    ProjectionMutationBoundary::allAggregateKinds(),
                    'PHOTO_MAPPING_PROMOTE',
                );
            }
            $changed = $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `piwigo_image_id` = ?, `media_reference` = ?, `state` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                . 'WHERE `class_photo_id` = ? AND `state` = ?',
                [$piwigoImageId, $mediaReference, ClassArchivePhoto::STATE_ACTIVE, (string) $row['class_photo_id'], ClassArchivePhoto::STATE_PENDING],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_photo_pending_mapping_race');
            }

            $mapped['piwigo_image_id'] = $piwigoImageId;
            $mapped['media_reference'] = $mediaReference;
            $mapped['state'] = ClassArchivePhoto::STATE_ACTIVE;
            return $mapped;
        });
    }

    /**
     * Bind an already-imported Immich asset after a future runtime adapter has
     * independently verified its checksum. The method never contacts Immich.
     */
    public function bindImmichAsset(string $classPhotoId, string $immichAssetId): void
    {
        $binaryId = ClassArchivePhoto::idToBinary($classPhotoId);
        $assetId = ClassArchivePhoto::normalizeImmichAssetId($immichAssetId);
        if ($assetId === null) {
            throw new \InvalidArgumentException('class_archive_photo_immich_asset_id_invalid');
        }
        $this->repository->transaction(function (Repository $repository) use ($assetId, $binaryId): void {
            ProjectionMutationBoundary::invalidateAggregates(
                $repository,
                [
                    \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
                    \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
                ],
                'IMMICH_ASSET_BIND',
            );
            $changed = $repository->execute(
                'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id` = ?, `updated_at` = UTC_TIMESTAMP(6) '
                . 'WHERE `class_photo_id` = ? AND `state` = ?',
                [$assetId, $binaryId, ClassArchivePhoto::STATE_ACTIVE],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_photo_mapping_not_active');
            }
        });
    }

    /**
     * Remove an unaccepted pending mapping. The submission and its audit event
     * remain authoritative for retention and review history; no public
     * canonical photo has been published at this point.
     */
    public function discardPendingSubmissionMapping(int $submissionId): void
    {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_submission_id_invalid');
        }
        $changed = $this->repository->execute(
            'DELETE FROM `' . $this->repository->table('photo') . '` WHERE `source_submission_id` = ? AND `state` = ?',
            [$submissionId, ClassArchivePhoto::STATE_PENDING],
        );
        if ($changed > 1) {
            throw new \RuntimeException('class_archive_photo_pending_mapping_ambiguous');
        }
    }

    private function piwigoImageExists(Repository $repository, int $piwigoImageId): bool
    {
        global $prefixeTable;
        if (!isset($prefixeTable) || !is_string($prefixeTable) || $prefixeTable === '') {
            throw new \RuntimeException('class_archive_piwigo_prefix_unavailable');
        }
        return $repository->fetchOne(
            'SELECT `id` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$piwigoImageId],
        ) !== null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function hydrate(array $row): array
    {
        $binaryId = $row['class_photo_id'] ?? null;
        $checksum = $row['media_checksum'] ?? null;
        if (!is_string($binaryId) || !is_string($checksum)) {
            throw new \RuntimeException('class_archive_photo_mapping_row_invalid');
        }
        $state = (string) ($row['state'] ?? '');
        if (!in_array($state, ClassArchivePhoto::states(), true)) {
            throw new \RuntimeException('class_archive_photo_mapping_state_invalid');
        }
        $reference = $state === ClassArchivePhoto::STATE_PENDING
            ? ClassArchivePhoto::normalizePendingMediaReference((string) ($row['media_reference'] ?? ''))
            : ClassArchivePhoto::normalizeMediaReference((string) ($row['media_reference'] ?? ''));
        $assetId = isset($row['immich_asset_id']) && $row['immich_asset_id'] !== null
            ? ClassArchivePhoto::normalizeImmichAssetId((string) $row['immich_asset_id'])
            : null;

        return [
            'class_photo_id' => ClassArchivePhoto::binaryToId($binaryId),
            'piwigo_image_id' => $row['piwigo_image_id'] === null ? null : (int) $row['piwigo_image_id'],
            'source_submission_id' => $row['source_submission_id'] === null ? null : (int) $row['source_submission_id'],
            'immich_asset_id' => $assetId,
            'media_checksum' => ClassArchivePhoto::checksumToHex($checksum),
            'media_reference' => $reference,
            'state' => $state,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
