<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Provenance and non-destructive duplicate review for canonical photos. */
final class CanonicalPhotoService
{
    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    public function recordSource(
        int $adminUserId,
        string $classPhotoId,
        string $sourceKind,
        string $provenanceCode,
        ?string $sourceReferenceDigestHex,
        ?string $originalFilenameDigestHex,
        string $sourceChecksumHex,
        int $byteSize,
        ?string $observedAtUtc,
        string $reason,
    ): int {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $sourceKind = strtoupper(trim($sourceKind));
        if (!in_array($sourceKind, ['SUBMISSION', 'PIWIGO_IMPORT', 'PRIVATE_QA', 'PRIVATE_FULL', 'MIGRATION', 'OTHER'], true)) {
            throw new \InvalidArgumentException('class_archive_photo_source_kind_invalid');
        }
        $provenanceCode = strtoupper(trim($provenanceCode));
        if (preg_match('/\A[A-Z0-9][A-Z0-9._:-]{1,63}\z/D', $provenanceCode) !== 1) {
            throw new \InvalidArgumentException('class_archive_photo_provenance_code_invalid');
        }
        if ($byteSize <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_source_size_invalid');
        }
        $referenceDigest = DomainSupport::normalizeHexDigest($sourceReferenceDigestHex);
        $filenameDigest = DomainSupport::normalizeHexDigest($originalFilenameDigestHex);
        $sourceChecksum = DomainSupport::normalizeHexDigest($sourceChecksumHex, true) ?? '';
        $observedAtUtc = $this->normalizeUtc($observedAtUtc);
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $classPhotoId, $sourceKind, $provenanceCode, $referenceDigest,
            $filenameDigest, $sourceChecksum, $byteSize, $observedAtUtc, $reason,
        ): int {
            $photo = DomainSupport::requireActivePhoto($repository, $classPhotoId, true);
            if (!is_string($photo['media_checksum']) || !hash_equals($photo['media_checksum'], $sourceChecksum)) {
                throw new \RuntimeException('class_archive_photo_source_checksum_mismatch');
            }
            $table = DomainSupport::table($repository, 'photo_source');
            $existing = $repository->fetchOne(
                'SELECT `id`,`source_reference_digest`,`original_filename_digest`,`source_checksum`,`byte_size`,`observed_at` '
                    . 'FROM `' . $table . '` WHERE `class_photo_id`=? AND `source_kind`=? AND `provenance_code`=? FOR UPDATE',
                [DomainSupport::idToBinary($classPhotoId), $sourceKind, $provenanceCode],
            );
            if ($existing !== null) {
                if (!self::nullableHashEquals($existing['source_reference_digest'] ?? null, $referenceDigest)
                    || !self::nullableHashEquals($existing['original_filename_digest'] ?? null, $filenameDigest)
                    || !hash_equals((string) $existing['source_checksum'], $sourceChecksum)
                    || (int) $existing['byte_size'] !== $byteSize
                    || (($existing['observed_at'] ?? null) === null ? null : (string) $existing['observed_at']) !== $observedAtUtc
                ) {
                    throw new \RuntimeException('class_archive_photo_source_provenance_drift');
                }
                return (int) $existing['id'];
            }
            $repository->execute(
                'INSERT INTO `' . $table . '` '
                    . '(`class_photo_id`,`source_kind`,`provenance_code`,`source_reference_digest`,`original_filename_digest`,`source_checksum`,`byte_size`,`observed_at`,`created_by_principal_id`,`created_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                [DomainSupport::idToBinary($classPhotoId), $sourceKind, $provenanceCode, $referenceDigest, $filenameDigest, $sourceChecksum, $byteSize, $observedAtUtc, (int) $admin['principal_id']],
            );
            $id = $repository->lastInsertId();
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_SOURCE_RECORD',
                'target_type' => 'PHOTO',
                'target_id' => $classPhotoId,
                'new_value' => [
                    'class_photo_id' => $classPhotoId,
                    'source_kind' => $sourceKind,
                    'provenance_code' => $provenanceCode,
                    'byte_size' => $byteSize,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return $id;
        });
    }

    /**
     * Record a source whose immutable bytes cannot be served directly and are
     * represented by a separately checksum-bound presentation surrogate.
     * The ordinary recordSource() contract intentionally remains strict:
     * only this explicit path may bind different source and media checksums.
     */
    public function recordTransformedSource(
        int $adminUserId,
        string $classPhotoId,
        string $sourceKind,
        string $provenanceCode,
        string $sourceIdentityDigestHex,
        ?string $sourceReferenceDigestHex,
        ?string $originalFilenameDigestHex,
        string $sourceChecksumHex,
        int $sourceByteSize,
        string $presentationChecksumHex,
        int $presentationByteSize,
        string $sourceFormat,
        string $presentationFormat,
        string $transformKind,
        string $transformTool,
        string $transformVersion,
        string $transformRecipeDigestHex,
        ?string $observedAtUtc,
        string $reason,
    ): int {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $sourceKind = strtoupper(trim($sourceKind));
        if ($sourceKind !== 'PRIVATE_FULL') {
            throw new \InvalidArgumentException('class_archive_photo_transformed_source_kind_invalid');
        }
        $provenanceCode = strtoupper(trim($provenanceCode));
        if (preg_match('/\A[A-Z0-9][A-Z0-9._:-]{1,63}\z/D', $provenanceCode) !== 1) {
            throw new \InvalidArgumentException('class_archive_photo_provenance_code_invalid');
        }
        if ($sourceByteSize <= 0 || $presentationByteSize <= 0) {
            throw new \InvalidArgumentException('class_archive_photo_source_size_invalid');
        }
        $sourceIdentityDigest = DomainSupport::normalizeHexDigest($sourceIdentityDigestHex, true) ?? '';
        $referenceDigest = DomainSupport::normalizeHexDigest($sourceReferenceDigestHex);
        $filenameDigest = DomainSupport::normalizeHexDigest($originalFilenameDigestHex);
        $sourceChecksum = DomainSupport::normalizeHexDigest($sourceChecksumHex, true) ?? '';
        $presentationChecksum = DomainSupport::normalizeHexDigest($presentationChecksumHex, true) ?? '';
        $recipeDigest = DomainSupport::normalizeHexDigest($transformRecipeDigestHex, true) ?? '';
        $sourceFormat = strtoupper(trim($sourceFormat));
        $presentationFormat = strtoupper(trim($presentationFormat));
        $transformKind = strtoupper(trim($transformKind));
        $transformTool = strtoupper(trim($transformTool));
        $transformVersion = trim($transformVersion);
        if ($sourceFormat !== 'MPO' || $presentationFormat !== 'JPEG'
            || $transformKind !== 'MPO_PRIMARY_FRAME_JPEG' || $transformTool !== 'PILLOW'
            || preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+\-]{0,31}\z/D', $transformVersion) !== 1
        ) {
            throw new \InvalidArgumentException('class_archive_photo_transform_contract_invalid');
        }
        $observedAtUtc = $this->normalizeUtc($observedAtUtc);
        $reason = Audit::validateReason($reason, true) ?? '';

        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $classPhotoId, $sourceKind, $provenanceCode, $sourceIdentityDigest,
            $referenceDigest, $filenameDigest, $sourceChecksum, $sourceByteSize,
            $presentationChecksum, $presentationByteSize, $sourceFormat, $presentationFormat,
            $transformKind, $transformTool, $transformVersion, $recipeDigest, $observedAtUtc, $reason,
        ): int {
            $photo = DomainSupport::requireActivePhoto($repository, $classPhotoId, true);
            if (!is_string($photo['media_checksum']) || !hash_equals($photo['media_checksum'], $presentationChecksum)) {
                throw new \RuntimeException('class_archive_photo_presentation_checksum_mismatch');
            }
            $sourceTable = DomainSupport::table($repository, 'photo_source');
            $presentationTable = DomainSupport::table($repository, 'photo_source_presentation');
            $existing = $repository->fetchAll(
                'SELECT ps.`id`,ps.`class_photo_id`,ps.`source_kind`,ps.`provenance_code`,'
                    . 'ps.`source_reference_digest`,ps.`original_filename_digest`,ps.`source_checksum`,ps.`byte_size`,ps.`observed_at`,'
                    . 'pp.`source_identity_digest`,pp.`presentation_checksum`,pp.`presentation_byte_size`,pp.`source_format`,'
                    . 'pp.`presentation_format`,pp.`transform_kind`,pp.`transform_tool`,pp.`transform_version`,pp.`transform_recipe_digest` '
                    . 'FROM `' . $presentationTable . '` pp INNER JOIN `' . $sourceTable . '` ps ON ps.`id`=pp.`photo_source_id` '
                    . 'WHERE pp.`source_identity_digest`=? LIMIT 2 FOR UPDATE',
                [$sourceIdentityDigest],
            );
            if (count($existing) > 1) {
                throw new \RuntimeException('class_archive_photo_source_identity_ambiguous');
            }
            if ($existing !== []) {
                $row = $existing[0];
                if (!is_string($row['class_photo_id'] ?? null)
                    || !hash_equals((string) $row['class_photo_id'], DomainSupport::idToBinary($classPhotoId))
                    || (string) $row['source_kind'] !== $sourceKind
                    || (string) $row['provenance_code'] !== $provenanceCode
                    || !self::nullableHashEquals($row['source_reference_digest'] ?? null, $referenceDigest)
                    || !self::nullableHashEquals($row['original_filename_digest'] ?? null, $filenameDigest)
                    || !hash_equals((string) $row['source_checksum'], $sourceChecksum)
                    || (int) $row['byte_size'] !== $sourceByteSize
                    || (($row['observed_at'] ?? null) === null ? null : (string) $row['observed_at']) !== $observedAtUtc
                    || !hash_equals((string) $row['presentation_checksum'], $presentationChecksum)
                    || (int) $row['presentation_byte_size'] !== $presentationByteSize
                    || (string) $row['source_format'] !== $sourceFormat
                    || (string) $row['presentation_format'] !== $presentationFormat
                    || (string) $row['transform_kind'] !== $transformKind
                    || (string) $row['transform_tool'] !== $transformTool
                    || (string) $row['transform_version'] !== $transformVersion
                    || !hash_equals((string) $row['transform_recipe_digest'], $recipeDigest)
                ) {
                    throw new \RuntimeException('class_archive_photo_transformed_source_drift');
                }
                return (int) $row['id'];
            }
            $provenanceCollision = $repository->fetchOne(
                'SELECT `id` FROM `' . $sourceTable . '` WHERE `source_kind`=? AND `provenance_code`=? LIMIT 1 FOR UPDATE',
                [$sourceKind, $provenanceCode],
            );
            if ($provenanceCollision !== null) {
                throw new \RuntimeException('class_archive_photo_transformed_source_drift');
            }
            $repository->execute(
                'INSERT INTO `' . $sourceTable . '` '
                    . '(`class_photo_id`,`source_kind`,`provenance_code`,`source_reference_digest`,`original_filename_digest`,`source_checksum`,`byte_size`,`observed_at`,`created_by_principal_id`,`created_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                [DomainSupport::idToBinary($classPhotoId), $sourceKind, $provenanceCode, $referenceDigest, $filenameDigest, $sourceChecksum, $sourceByteSize, $observedAtUtc, (int) $admin['principal_id']],
            );
            $sourceId = $repository->lastInsertId();
            $repository->execute(
                'INSERT INTO `' . $presentationTable . '` '
                    . '(`photo_source_id`,`source_identity_digest`,`presentation_checksum`,`presentation_byte_size`,`source_format`,`presentation_format`,`transform_kind`,`transform_tool`,`transform_version`,`transform_recipe_digest`,`created_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
                [$sourceId, $sourceIdentityDigest, $presentationChecksum, $presentationByteSize, $sourceFormat, $presentationFormat, $transformKind, $transformTool, $transformVersion, $recipeDigest],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_SOURCE_RECORD',
                'target_type' => 'PHOTO',
                'target_id' => $classPhotoId,
                'new_value' => [
                    'class_photo_id' => $classPhotoId,
                    'source_kind' => $sourceKind,
                    'provenance_code' => $provenanceCode,
                    'byte_size' => $sourceByteSize,
                    'presentation_kind' => $transformKind,
                    'presentation_byte_size' => $presentationByteSize,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return $sourceId;
        });
    }

    public function registerExactCandidate(int $adminUserId, string $leftClassPhotoId, string $rightClassPhotoId, string $reason): string
    {
        return $this->registerCandidate($adminUserId, $leftClassPhotoId, $rightClassPhotoId, 'EXACT', null, $reason);
    }

    public function registerNearCandidate(
        int $adminUserId,
        string $leftClassPhotoId,
        string $rightClassPhotoId,
        float $similarity,
        string $reason,
    ): string {
        if (!is_finite($similarity) || $similarity < 0.0 || $similarity > 1.0) {
            throw new \InvalidArgumentException('class_archive_photo_similarity_invalid');
        }
        return $this->registerCandidate($adminUserId, $leftClassPhotoId, $rightClassPhotoId, 'NEAR', $similarity, $reason);
    }

    public function rejectCandidate(int $adminUserId, string $duplicateId, string $reason): void
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $duplicateId, $reason): void {
            $binary = DomainSupport::idToBinary($duplicateId);
            $row = $repository->fetchOne(
                'SELECT `state`,`relation_kind` FROM `' . DomainSupport::table($repository, 'photo_duplicate') . '` WHERE `duplicate_id`=? FOR UPDATE',
                [$binary],
            );
            if ($row === null || ($row['state'] ?? null) !== 'CANDIDATE') {
                throw new \RuntimeException('class_archive_photo_duplicate_not_candidate');
            }
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'photo_duplicate') . '` '
                    . "SET `state`='REJECTED',`reviewed_by_principal_id`=?,`reviewed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
                    . "WHERE `duplicate_id`=? AND `state`='CANDIDATE'",
                [(int) $admin['principal_id'], $binary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_DUPLICATE_REVIEW',
                'target_type' => 'PHOTO_DUPLICATE',
                'target_id' => $duplicateId,
                'old_value' => ['state' => 'CANDIDATE'],
                'new_value' => ['state' => 'REJECTED', 'relation_kind' => (string) $row['relation_kind']],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    /**
     * Logical exact consolidation. The target receives the union of Piwigo
     * category associations; neither image row nor either file is removed.
     *
     * @return array{class_photo_ids:list<string>,projection_kinds:list<string>,projection_rebuild_mode:string}
     */
    public function consolidateExact(
        int $adminUserId,
        string $duplicateId,
        string $targetClassPhotoId,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $duplicateBinary = DomainSupport::idToBinary($duplicateId);
        $targetBinary = DomainSupport::idToBinary($targetClassPhotoId);
        // This connection-scoped MariaDB advisory lease serializes every
        // MyISAM association union for this ClassIdentity installation. The
        // PREPARED journal below is the durable crash record; MariaDB releases
        // the lease automatically if the PHP worker or connection dies.
        $consolidationLock = $this->consolidationLockName();
        $this->acquireConsolidationLock($consolidationLock);
        try {
        $relation = $this->repository->fetchOne(
            'SELECT * FROM `' . DomainSupport::table($this->repository, 'photo_duplicate') . '` WHERE `duplicate_id`=? LIMIT 1',
            [$duplicateBinary],
        );
        if ($relation === null || ($relation['state'] ?? null) !== 'CANDIDATE' || ($relation['relation_kind'] ?? null) !== 'EXACT') {
            throw new \RuntimeException('class_archive_photo_exact_candidate_required');
        }
        $left = (string) $relation['left_class_photo_id'];
        $right = (string) $relation['right_class_photo_id'];
        if (!hash_equals($targetBinary, $left) && !hash_equals($targetBinary, $right)) {
            throw new \InvalidArgumentException('class_archive_photo_duplicate_target_invalid');
        }
        $aliasBinary = hash_equals($targetBinary, $left) ? $right : $left;
        $targetId = DomainSupport::binaryToId($targetBinary);
        $aliasId = DomainSupport::binaryToId($aliasBinary);
        if (!hash_equals($this->resolveCanonical($targetId), strtolower($targetId))
            || !hash_equals($this->resolveCanonical($aliasId), strtolower($aliasId))
        ) {
            throw new \RuntimeException('class_archive_photo_duplicate_chain_manual_review');
        }
        $target = DomainSupport::requireActivePhoto($this->repository, $targetId);
        $alias = DomainSupport::requireActivePhoto($this->repository, $aliasId);
        if (!is_string($target['media_checksum']) || !is_string($alias['media_checksum']) || !hash_equals($target['media_checksum'], $alias['media_checksum'])) {
            throw new \RuntimeException('class_archive_photo_exact_checksum_mismatch');
        }

        // A durable PREPARED row exists before touching Piwigo's non-
        // transactional association table. A process crash is therefore a
        // visible reconciliation item, never an invisible half-union.
        $batchId = DomainSupport::generateId();
        $this->prepareConsolidationJournal($batchId, $admin, $duplicateId, $targetId, $aliasId, $reason);
        try {
            $targetEra = $this->effectiveEraForImage((int) $target['piwigo_image_id']);
            $aliasEra = $this->effectiveEraForImage((int) $alias['piwigo_image_id']);
            if ($targetEra !== $aliasEra) {
                throw new \RuntimeException('class_archive_photo_duplicate_cross_era_conflict');
            }
            $this->assertArchiveMetadataCompatible((int) $target['piwigo_image_id'], (int) $alias['piwigo_image_id']);
        } catch (\Throwable $error) {
            $errorCode = $error->getMessage() === 'class_archive_photo_duplicate_archive_metadata_conflict'
                ? 'ARCHIVE_METADATA_CONFLICT'
                : 'CROSS_ERA_OR_AMBIGUOUS';
            $this->finishConsolidationJournal($batchId, $admin, $duplicateId, $reason, 'MANUAL_REVIEW', $errorCode);
            throw $error;
        }

        $addedCategories = $this->unionPiwigoAssociations((int) $target['piwigo_image_id'], (int) $alias['piwigo_image_id']);
        try {
            $this->repository->transaction(function (Repository $repository) use (
                $admin, $duplicateId, $duplicateBinary, $targetClassPhotoId, $targetBinary, $reason, $batchId,
            ): void {
                // The PREPARED journal and native association triggers keep
                // the multi-engine saga fail-closed, but a builder can recover
                // those early epochs before this canonical source state is
                // committed. Re-invalidate atomically with photo_duplicate so
                // that recovery can never publish across the final commit.
                ProjectionMutationBoundary::invalidatePhotos(
                    $repository,
                    ProjectionMutationBoundary::allAggregateKinds(),
                    'CANONICAL_CONSOLIDATE_FINALIZE',
                );
                $locked = $repository->fetchOne(
                    'SELECT `state`,`relation_kind`,`left_class_photo_id`,`right_class_photo_id` FROM `'
                        . DomainSupport::table($repository, 'photo_duplicate') . '` WHERE `duplicate_id`=? FOR UPDATE',
                    [$duplicateBinary],
                );
                if ($locked === null || ($locked['state'] ?? null) !== 'CANDIDATE' || ($locked['relation_kind'] ?? null) !== 'EXACT') {
                    throw new \RuntimeException('class_archive_photo_exact_candidate_race');
                }
                $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'photo_duplicate') . '` '
                        . "SET `state`='CONSOLIDATED',`canonical_class_photo_id`=?,`reviewed_by_principal_id`=?,`reviewed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
                        . "WHERE `duplicate_id`=? AND `state`='CANDIDATE'",
                    [$targetBinary, (int) $admin['principal_id'], $duplicateBinary],
                );
                $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                        . "SET `state`='APPLIED',`updated_at`=UTC_TIMESTAMP(6) WHERE `batch_id`=? AND `state`='PREPARED'",
                    [DomainSupport::idToBinary($batchId)],
                );
                $journalChanged = $repository->execute(
                    'UPDATE `' . DomainSupport::table($repository, 'batch_operation') . '` '
                        . "SET `state`='APPLIED',`applied_count`=`item_count`,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
                        . "WHERE `batch_id`=? AND `state`='PREPARED'",
                    [DomainSupport::idToBinary($batchId)],
                );
                if ($journalChanged !== 1) {
                    throw new \RuntimeException('class_archive_photo_consolidation_journal_race');
                }
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PHOTO_DUPLICATE_CONSOLIDATE',
                    'target_type' => 'PHOTO_DUPLICATE',
                    'target_id' => $duplicateId,
                    'old_value' => ['state' => 'CANDIDATE', 'relation_kind' => 'EXACT'],
                    'new_value' => [
                        'state' => 'CONSOLIDATED',
                        'canonical_class_photo_id' => $targetClassPhotoId,
                        'canonicalized' => true,
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            });
            // The source mutation is now durable. The caller must perform the
            // returned bounded refresh, or a full rebuild when the native
            // Piwigo association source changed; until then PHOTO_CATALOG and
            // every canonical-sensitive aggregate remain STALE and reads fail
            // closed.
            return $this->canonicalProjectionRefresh($targetId, $aliasId, $addedCategories !== []);
        } catch (\Throwable $error) {
            $compensated = $this->removeOnlyAddedPiwigoAssociations((int) $target['piwigo_image_id'], $addedCategories);
            $this->finishConsolidationJournal(
                $batchId,
                $admin,
                $duplicateId,
                $reason,
                $compensated ? 'COMPENSATED' : 'MANUAL_REVIEW',
                $compensated ? 'CONSOLIDATION_TRANSACTION_FAILED' : 'CONSOLIDATION_COMPENSATION_FAILED',
            );
            throw $error;
        }
        } finally {
            $this->releaseConsolidationLock($consolidationLock);
        }
    }

    /**
     * Revert only the logical alias. Album union is retained conservatively.
     *
     * @return array{class_photo_ids:list<string>,projection_kinds:list<string>,projection_rebuild_mode:string}
     */
    public function revertConsolidation(int $adminUserId, string $duplicateId, string $reason): array
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use ($admin, $duplicateId, $reason): array {
            $binary = DomainSupport::idToBinary($duplicateId);
            $row = $repository->fetchOne(
                'SELECT `state`,`canonical_class_photo_id`,`left_class_photo_id`,`right_class_photo_id` FROM `' . DomainSupport::table($repository, 'photo_duplicate')
                    . '` WHERE `duplicate_id`=? FOR UPDATE',
                [$binary],
            );
            if ($row === null || ($row['state'] ?? null) !== 'CONSOLIDATED') {
                throw new \RuntimeException('class_archive_photo_duplicate_not_consolidated');
            }
            $canonical = DomainSupport::binaryToId((string) $row['canonical_class_photo_id']);
            $left = DomainSupport::binaryToId((string) $row['left_class_photo_id']);
            $right = DomainSupport::binaryToId((string) $row['right_class_photo_id']);
            if (!hash_equals($canonical, $left) && !hash_equals($canonical, $right)) {
                throw new \RuntimeException('class_archive_photo_duplicate_mapping_invalid');
            }
            $alias = hash_equals($canonical, $left) ? $right : $left;
            ProjectionMutationBoundary::invalidatePhotos(
                $repository,
                ProjectionMutationBoundary::allAggregateKinds(),
                'CANONICAL_REVERT',
            );
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'photo_duplicate') . '` '
                    . "SET `state`='REVERTED',`canonical_class_photo_id`=NULL,`reviewed_by_principal_id`=?,`reviewed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
                    . "WHERE `duplicate_id`=? AND `state`='CONSOLIDATED'",
                [(int) $admin['principal_id'], $binary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_DUPLICATE_REVERT',
                'target_type' => 'PHOTO_DUPLICATE',
                'target_id' => $duplicateId,
                'old_value' => ['state' => 'CONSOLIDATED', 'canonical_class_photo_id' => $canonical],
                'new_value' => ['state' => 'REVERTED'],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return $this->canonicalProjectionRefresh($canonical, $alias);
        });
    }

    public function resolveCanonical(string $classPhotoId): string
    {
        $normalized = strtolower($classPhotoId);
        return $this->canonicalMapFor([$normalized])[$normalized];
    }

    /**
     * Resolve a whole policy-approved candidate set in one query. The result
     * always contains an entry for every input id. Active alias chains are
     * rejected even though the schema normally prevents them: a broken or
     * partially restored duplicate graph must never widen a public response.
     *
     * @param list<string> $classPhotoIds
     * @return array<string,string> input id => canonical id
     */
    public function canonicalMapFor(array $classPhotoIds): array
    {
        $result = [];
        $binaries = [];
        foreach ($classPhotoIds as $classPhotoId) {
            if (!is_string($classPhotoId)) {
                throw new \InvalidArgumentException('class_archive_photo_id_invalid');
            }
            $normalized = strtolower($classPhotoId);
            $binary = DomainSupport::idToBinary($normalized);
            $result[$normalized] = $normalized;
            $binaries[$normalized] = $binary;
        }
        if ($binaries === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($binaries), '?'));
        $rows = $this->repository->fetchAll(
            'SELECT `active_alias_class_photo_id`,`canonical_class_photo_id` FROM `'
                . DomainSupport::table($this->repository, 'photo_duplicate') . '` '
                . "WHERE `state`='CONSOLIDATED' AND `active_alias_class_photo_id` IN ({$placeholders})",
            array_values($binaries),
        );
        $canonicalTargets = [];
        foreach ($rows as $row) {
            if (!is_string($row['active_alias_class_photo_id'] ?? null)
                || !is_string($row['canonical_class_photo_id'] ?? null)
            ) {
                throw new \RuntimeException('class_archive_photo_duplicate_mapping_invalid');
            }
            $alias = DomainSupport::binaryToId((string) $row['active_alias_class_photo_id']);
            $canonical = DomainSupport::binaryToId((string) $row['canonical_class_photo_id']);
            if (!isset($result[$alias]) || !hash_equals($result[$alias], $alias)) {
                throw new \RuntimeException('class_archive_photo_duplicate_mapping_ambiguous');
            }
            $result[$alias] = $canonical;
            $canonicalTargets[$canonical] = DomainSupport::idToBinary($canonical);
        }
        foreach ($result as $alias => $canonical) {
            if (!hash_equals($alias, $canonical)
                && isset($result[$canonical])
                && !hash_equals($result[$canonical], $canonical)
            ) {
                throw new \RuntimeException('class_archive_photo_duplicate_chain_manual_review');
            }
        }
        if ($canonicalTargets !== []) {
            $targetPlaceholders = implode(',', array_fill(0, count($canonicalTargets), '?'));
            $chained = $this->repository->fetchOne(
                'SELECT 1 AS `found` FROM `' . DomainSupport::table($this->repository, 'photo_duplicate') . '` '
                    . "WHERE `state`='CONSOLIDATED' AND `active_alias_class_photo_id` IN ({$targetPlaceholders}) LIMIT 1",
                array_values($canonicalTargets),
            );
            if ($chained !== null) {
                throw new \RuntimeException('class_archive_photo_duplicate_chain_manual_review');
            }
        }
        return $result;
    }

    /** @return list<string> canonical id followed by every active logical alias */
    public function canonicalGroupIds(string $classPhotoId): array
    {
        $canonical = $this->resolveCanonical($classPhotoId);
        $rows = $this->repository->fetchAll(
            'SELECT `active_alias_class_photo_id` FROM `' . DomainSupport::table($this->repository, 'photo_duplicate')
                . "` WHERE `canonical_class_photo_id`=? AND `state`='CONSOLIDATED' ORDER BY `active_alias_class_photo_id`",
            [DomainSupport::idToBinary($canonical)],
        );
        $result = [$canonical];
        foreach ($rows as $row) {
            if (!is_string($row['active_alias_class_photo_id'] ?? null)) {
                throw new \RuntimeException('class_archive_photo_duplicate_mapping_invalid');
            }
            $alias = DomainSupport::binaryToId((string) $row['active_alias_class_photo_id']);
            if (hash_equals($alias, $canonical)) {
                throw new \RuntimeException('class_archive_photo_duplicate_mapping_invalid');
            }
            $result[] = $alias;
        }
        return $result;
    }

    /** Public-safe provenance without local paths, filenames, or digests. @return list<array<string,mixed>> */
    public function provenanceSummary(string $classPhotoId): array
    {
        $group = $this->canonicalGroupIds($classPhotoId);
        $parameters = array_map(static fn(string $id): string => DomainSupport::idToBinary($id), $group);
        $placeholders = implode(',', array_fill(0, count($parameters), '?'));
        $rows = $this->repository->fetchAll(
            'SELECT `source_kind`,`provenance_code`,`byte_size`,`observed_at`,`created_at` FROM `'
                . DomainSupport::table($this->repository, 'photo_source')
                . "` WHERE `class_photo_id` IN ({$placeholders}) ORDER BY `created_at`,`id`",
            $parameters,
        );
        return array_map(static fn(array $row): array => [
            'source_kind' => (string) $row['source_kind'],
            'provenance_code' => (string) $row['provenance_code'],
            'byte_size' => (int) $row['byte_size'],
            'observed_at' => $row['observed_at'] === null ? null : (string) $row['observed_at'],
            'recorded_at' => (string) $row['created_at'],
        ], $rows);
    }

    /** Admin duplicate review list. @return list<array<string,mixed>> */
    public function listCandidates(int $adminUserId, string $state = 'CANDIDATE', int $limit = 500): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        $state = strtoupper(trim($state));
        if (!in_array($state, ['CANDIDATE', 'REJECTED', 'CONSOLIDATED', 'REVERTED'], true)) {
            throw new \InvalidArgumentException('class_archive_photo_duplicate_state_invalid');
        }
        $limit = max(1, min(1000, $limit));
        $rows = $this->repository->fetchAll(
            'SELECT `duplicate_id`,`left_class_photo_id`,`right_class_photo_id`,`relation_kind`,`similarity`,`state`,`canonical_class_photo_id`,`created_at`,`reviewed_at` '
                . 'FROM `' . DomainSupport::table($this->repository, 'photo_duplicate') . '` WHERE `state`=? ORDER BY `updated_at` DESC LIMIT ' . $limit,
            [$state],
        );
        return array_map(static fn(array $row): array => [
            'duplicate_id' => DomainSupport::binaryToId((string) $row['duplicate_id']),
            'left_class_photo_id' => DomainSupport::binaryToId((string) $row['left_class_photo_id']),
            'right_class_photo_id' => DomainSupport::binaryToId((string) $row['right_class_photo_id']),
            'relation_kind' => (string) $row['relation_kind'],
            'similarity' => $row['similarity'] === null ? null : (float) $row['similarity'],
            'state' => (string) $row['state'],
            'canonical_class_photo_id' => $row['canonical_class_photo_id'] === null ? null : DomainSupport::binaryToId((string) $row['canonical_class_photo_id']),
            'created_at' => (string) $row['created_at'],
            'reviewed_at' => $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
        ], $rows);
    }

    private function registerCandidate(
        int $adminUserId,
        string $leftClassPhotoId,
        string $rightClassPhotoId,
        string $kind,
        ?float $similarity,
        string $reason,
    ): string {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $left = DomainSupport::requireActivePhoto($this->repository, $leftClassPhotoId);
        $right = DomainSupport::requireActivePhoto($this->repository, $rightClassPhotoId);
        $leftBinary = DomainSupport::idToBinary($leftClassPhotoId);
        $rightBinary = DomainSupport::idToBinary($rightClassPhotoId);
        if (hash_equals($leftBinary, $rightBinary)) {
            throw new \InvalidArgumentException('class_archive_photo_duplicate_same');
        }
        if (strcmp($leftBinary, $rightBinary) > 0) {
            [$leftBinary, $rightBinary] = [$rightBinary, $leftBinary];
        }
        if ($kind === 'EXACT' && (!is_string($left['media_checksum']) || !is_string($right['media_checksum']) || !hash_equals($left['media_checksum'], $right['media_checksum']))) {
            throw new \RuntimeException('class_archive_photo_exact_checksum_mismatch');
        }
        return $this->repository->transaction(function (Repository $repository) use ($admin, $leftBinary, $rightBinary, $kind, $similarity, $reason): string {
            $table = DomainSupport::table($repository, 'photo_duplicate');
            $existing = $repository->fetchOne(
                'SELECT `duplicate_id`,`state` FROM `' . $table . '` WHERE `left_class_photo_id`=? AND `right_class_photo_id`=? AND `relation_kind`=? FOR UPDATE',
                [$leftBinary, $rightBinary, $kind],
            );
            if ($existing !== null) {
                if (($existing['state'] ?? null) !== 'CANDIDATE') {
                    throw new \RuntimeException('class_archive_photo_duplicate_already_reviewed');
                }
                return DomainSupport::binaryToId((string) $existing['duplicate_id']);
            }
            $duplicateId = DomainSupport::generateId();
            $repository->execute(
                'INSERT INTO `' . $table . '` '
                    . '(`duplicate_id`,`left_class_photo_id`,`right_class_photo_id`,`relation_kind`,`similarity`,`state`,`created_by_principal_id`,`reason`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, ?, ?, 'CANDIDATE', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [DomainSupport::idToBinary($duplicateId), $leftBinary, $rightBinary, $kind, $similarity, (int) $admin['principal_id'], $reason],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_DUPLICATE_REVIEW',
                'target_type' => 'PHOTO_DUPLICATE',
                'target_id' => $duplicateId,
                'new_value' => [
                    'duplicate_id' => $duplicateId,
                    'from' => DomainSupport::binaryToId($leftBinary),
                    'to' => DomainSupport::binaryToId($rightBinary),
                    'relation_kind' => $kind,
                    'similarity' => $similarity,
                    'state' => 'CANDIDATE',
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return $duplicateId;
        });
    }

    private function prepareConsolidationJournal(
        string $batchId,
        array $admin,
        string $duplicateId,
        string $targetClassPhotoId,
        string $aliasClassPhotoId,
        string $reason,
    ): void {
        $summary = json_encode([
            'target' => $targetClassPhotoId,
            'alias' => $aliasClassPhotoId,
            'duplicate' => $duplicateId,
            'operation' => 'EXACT_DUPLICATE_CONSOLIDATE',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->repository->transaction(function (Repository $repository) use (
            $batchId, $admin, $duplicateId, $targetClassPhotoId, $aliasClassPhotoId, $reason, $summary,
        ): void {
            ProjectionMutationBoundary::invalidatePhotos(
                $repository,
                ProjectionMutationBoundary::allAggregateKinds(),
                'CANONICAL_CONSOLIDATE',
            );
            $batch = DomainSupport::idToBinary($batchId);
            $repository->execute(
                'INSERT INTO `' . DomainSupport::table($repository, 'batch_operation') . '` '
                    . '(`batch_id`,`actor_principal_id`,`operation_type`,`state`,`payload_digest`,`item_count`,`high_risk_confirmed`,`reason`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, 'EXACT_DUPLICATE_CONSOLIDATE', 'PREPARED', ?, 2, 1, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$batch, (int) $admin['principal_id'], hash('sha256', $summary, true), $reason],
            );
            foreach ([
                [$targetClassPhotoId, ['canonicalized' => false, 'duplicate_id' => $duplicateId], ['canonicalized' => true, 'duplicate_id' => $duplicateId]],
                [$aliasClassPhotoId, ['canonicalized' => false, 'duplicate_id' => $duplicateId], ['canonicalized' => true, 'canonical_class_photo_id' => $targetClassPhotoId, 'duplicate_id' => $duplicateId]],
            ] as [$photoId, $before, $after]) {
                $repository->execute(
                    'INSERT INTO `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                        . '(`batch_id`,`class_photo_id`,`state`,`before_value`,`after_value`,`created_at`,`updated_at`) '
                        . "VALUES (?, ?, 'PREPARED', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [
                        $batch,
                        DomainSupport::idToBinary((string) $photoId),
                        json_encode($before, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        json_encode($after, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                );
            }
        });
    }

    /**
     * The logical alias graph and target album union can change the public
     * projection of both physical rows. Never refresh only the chosen target:
     * the alias row must be rebound in the same catalog transaction before
     * aggregates can become ACTIVE again.
     *
     * @return array{class_photo_ids:list<string>,projection_kinds:list<string>,projection_rebuild_mode:string}
     */
    private function canonicalProjectionRefresh(
        string $targetClassPhotoId,
        string $aliasClassPhotoId,
        bool $nativeSourceMutated = false,
    ): array
    {
        $target = strtolower($targetClassPhotoId);
        $alias = strtolower($aliasClassPhotoId);
        DomainSupport::idToBinary($target);
        DomainSupport::idToBinary($alias);
        if (hash_equals($target, $alias)) {
            throw new \RuntimeException('class_archive_photo_duplicate_mapping_invalid');
        }
        return [
            'class_photo_ids' => [$target, $alias],
            'projection_kinds' => ProjectionMutationBoundary::allAggregateKinds(),
            'projection_rebuild_mode' => $nativeSourceMutated ? 'FULL_NATIVE_SOURCE' : 'BOUNDED',
        ];
    }

    private function finishConsolidationJournal(
        string $batchId,
        array $admin,
        string $duplicateId,
        string $reason,
        string $state,
        string $errorCode,
    ): void {
        if (!in_array($state, ['COMPENSATED', 'MANUAL_REVIEW'], true)) {
            throw new \InvalidArgumentException('class_archive_photo_consolidation_journal_state_invalid');
        }
        $this->repository->transaction(function (Repository $repository) use (
            $batchId, $admin, $duplicateId, $reason, $state, $errorCode,
        ): void {
            $batch = DomainSupport::idToBinary($batchId);
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'batch_operation') . '` '
                    . 'SET `state`=?,`failed_count`=`item_count`,`error_code`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `batch_id`=? AND `state`='PREPARED'",
                [$state, $errorCode, $batch],
            );
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'batch_operation_item') . '` '
                    . 'SET `state`=?,`error_code`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . "WHERE `batch_id`=? AND `state`='PREPARED'",
                [$state, $errorCode, $batch],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_DUPLICATE_CONSOLIDATE',
                'target_type' => 'PHOTO_DUPLICATE',
                'target_id' => $duplicateId,
                'old_value' => ['operation_state' => 'PREPARED'],
                'new_value' => ['operation_state' => $state, 'failed_count' => 2],
                'reason' => $reason,
                'result' => str_starts_with($errorCode, 'CROSS_ERA') || $errorCode === 'ARCHIVE_METADATA_CONFLICT'
                    ? 'DENIED'
                    : 'FAILED',
                'error_code' => $errorCode,
            ]);
        });
    }

    private function consolidationLockName(): string
    {
        return 'class_archive_exact_' . substr(
            hash('sha256', DomainSupport::table($this->repository, 'photo_duplicate')),
            0,
            32,
        );
    }

    private function acquireConsolidationLock(string $lockName): void
    {
        $row = $this->repository->fetchOne('SELECT GET_LOCK(?, 0) AS `acquired`', [$lockName]);
        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('class_archive_photo_consolidation_lock_unavailable');
        }
    }

    private function releaseConsolidationLock(string $lockName): void
    {
        $row = $this->repository->fetchOne('SELECT RELEASE_LOCK(?) AS `released`', [$lockName]);
        if ((int) ($row['released'] ?? 0) !== 1) {
            throw new \RuntimeException('class_archive_photo_consolidation_lock_release_failed');
        }
    }

    /**
     * Missing evidence may be projected from the alias, but two contradictory
     * pieces of archive evidence require an administrator to decide which is
     * correct. Consolidation never silently picks one date/event over another.
     */
    private function assertArchiveMetadataCompatible(int $targetImageId, int $aliasImageId): void
    {
        if ($targetImageId <= 0 || $aliasImageId <= 0 || $targetImageId === $aliasImageId) {
            throw new \RuntimeException('class_archive_photo_duplicate_archive_metadata_conflict');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `piwigo_image_id`,`archive_date`,`date_precision`,`event_label` FROM `'
                . $this->repository->table('archive_image') . '` WHERE `piwigo_image_id` IN (?, ?)',
            [$targetImageId, $aliasImageId],
        );
        $metadata = [];
        foreach ($rows as $row) {
            $imageId = (int) ($row['piwigo_image_id'] ?? 0);
            if (!in_array($imageId, [$targetImageId, $aliasImageId], true) || isset($metadata[$imageId])) {
                throw new \RuntimeException('class_archive_photo_duplicate_archive_metadata_conflict');
            }
            $precision = strtoupper(trim((string) ($row['date_precision'] ?? '')));
            if (!in_array($precision, ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'], true)) {
                throw new \RuntimeException('class_archive_photo_duplicate_archive_metadata_conflict');
            }
            $date = $row['archive_date'] === null ? null : trim((string) $row['archive_date']);
            $event = $row['event_label'] === null ? null : trim((string) $row['event_label']);
            $metadata[$imageId] = [
                'archive_date' => $date === '' ? null : $date,
                'date_precision' => $precision,
                'event_label' => $event === '' ? null : $event,
            ];
        }
        if (!isset($metadata[$targetImageId], $metadata[$aliasImageId])) {
            throw new \RuntimeException('class_archive_photo_duplicate_archive_metadata_conflict');
        }
        $target = $metadata[$targetImageId];
        $alias = $metadata[$aliasImageId];
        $dateConflict = $target['archive_date'] !== null
            && $alias['archive_date'] !== null
            && $target['archive_date'] !== $alias['archive_date'];
        $precisionConflict = $target['date_precision'] !== 'UNKNOWN'
            && $alias['date_precision'] !== 'UNKNOWN'
            && $target['date_precision'] !== $alias['date_precision'];
        $eventConflict = $target['event_label'] !== null
            && $alias['event_label'] !== null
            && $target['event_label'] !== $alias['event_label'];
        if ($dateConflict || $precisionConflict || $eventConflict) {
            throw new \RuntimeException('class_archive_photo_duplicate_archive_metadata_conflict');
        }
    }

    /** Resolve both metadata and actual Piwigo root membership, fail closed. */
    private function effectiveEraForImage(int $piwigoImageId): string
    {
        global $prefixeTable;
        $archive = $this->repository->fetchOne(
            'SELECT `era` FROM `' . $this->repository->table('archive_image') . '` WHERE `piwigo_image_id`=? LIMIT 1',
            [$piwigoImageId],
        );
        $declared = (string) ($archive['era'] ?? '');
        if (!in_array($declared, ['HERITAGE', 'LIVING'], true)) {
            throw new \RuntimeException('class_archive_photo_effective_era_unknown');
        }
        $roots = $this->repository->fetchAll(
            'SELECT `id`,`permalink` FROM `' . $prefixeTable . 'categories` '
                . "WHERE `permalink` IN ('class-archive-heritage','class-archive-living')",
        );
        $rootIds = [];
        foreach ($roots as $root) {
            $rootIds[(string) $root['permalink']] = (int) $root['id'];
        }
        $heritage = $rootIds['class-archive-heritage'] ?? 0;
        $living = $rootIds['class-archive-living'] ?? 0;
        if ($heritage <= 0 || $living <= 0) {
            throw new \RuntimeException('class_archive_photo_era_roots_missing');
        }
        $membership = $this->repository->fetchOne(
            'SELECT '
                . 'MAX(CASE WHEN c.`id`=? OR FIND_IN_SET(?,c.`uppercats`) > 0 THEN 1 ELSE 0 END) AS heritage, '
                . 'MAX(CASE WHEN c.`id`=? OR FIND_IN_SET(?,c.`uppercats`) > 0 THEN 1 ELSE 0 END) AS living '
                . 'FROM `' . $prefixeTable . 'image_category` ic '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` WHERE ic.`image_id`=?',
            [$heritage, $heritage, $living, $living, $piwigoImageId],
        );
        $hasHeritage = (int) ($membership['heritage'] ?? 0) === 1;
        $hasLiving = (int) ($membership['living'] ?? 0) === 1;
        if ($hasHeritage === $hasLiving) {
            throw new \RuntimeException('class_archive_photo_effective_era_ambiguous');
        }
        $effective = $hasHeritage ? 'HERITAGE' : 'LIVING';
        if ($effective !== $declared) {
            throw new \RuntimeException('class_archive_photo_effective_era_drift');
        }
        return $effective;
    }

    /** @return list<int> category ids newly associated with target */
    private function unionPiwigoAssociations(int $targetImageId, int $sourceImageId): array
    {
        global $prefixeTable;
        if ($targetImageId <= 0 || $sourceImageId <= 0) {
            throw new \RuntimeException('class_archive_photo_piwigo_mapping_invalid');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `category_id`,`image_id` FROM `' . $prefixeTable . 'image_category` WHERE `image_id` IN (?, ?)',
            [$targetImageId, $sourceImageId],
        );
        $target = [];
        $source = [];
        foreach ($rows as $row) {
            $category = (int) $row['category_id'];
            if ((int) $row['image_id'] === $targetImageId) {
                $target[$category] = true;
            } else {
                $source[$category] = true;
            }
        }
        $added = [];
        foreach (array_keys($source) as $categoryId) {
            if (isset($target[$categoryId])) {
                continue;
            }
            $inserted = $this->repository->execute(
                'INSERT IGNORE INTO `' . $prefixeTable . 'image_category` (`image_id`,`category_id`) VALUES (?, ?)',
                [$targetImageId, $categoryId],
            );
            if ($inserted === 1) {
                // Compensation owns only rows inserted by this attempt. If a
                // concurrent writer won the INSERT IGNORE race (affected=0),
                // deleting that association later would corrupt their work.
                $added[] = $categoryId;
            } elseif ($inserted !== 0) {
                throw new \RuntimeException('class_archive_photo_piwigo_association_insert_invalid');
            }
        }
        if ($added !== [] && function_exists('invalidate_user_cache')) {
            invalidate_user_cache();
        }
        return $added;
    }

    /** @param list<int> $categoryIds */
    private function removeOnlyAddedPiwigoAssociations(int $imageId, array $categoryIds): bool
    {
        global $prefixeTable;
        $ok = true;
        foreach ($categoryIds as $categoryId) {
            try {
                $this->repository->execute(
                    'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=?',
                    [$imageId, $categoryId],
                );
            } catch (\Throwable) {
                // Reconciliation will surface any conservative compensation
                // failure; originals and source associations remain intact.
                $ok = false;
            }
        }
        if ($categoryIds !== [] && function_exists('invalidate_user_cache')) {
            invalidate_user_cache();
        }
        return $ok;
    }

    private function normalizeUtc(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_photo_observed_at_invalid');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', str_contains($value, '.') ? $value : $value . '.000000', new \DateTimeZone('UTC'));
        if (!$parsed) {
            throw new \InvalidArgumentException('class_archive_photo_observed_at_invalid');
        }
        return $parsed->format('Y-m-d H:i:s.u');
    }

    private static function nullableHashEquals(mixed $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        return is_string($left) && hash_equals($left, $right);
    }
}
