<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Explicit-era publication boundary for active Classmate and Teacher seats.
 *
 * This is intentionally separate from the Family pending-submission flow.
 * A member must choose both an opaque Class Archive album id and HERITAGE or
 * LIVING before Core accepts a file; neither EXIF nor file timestamps may
 * decide the business era.  Piwigo's MyISAM image/category write is isolated
 * behind a fail-closed saga: the image starts at Core access level 16, is
 * checked before association, and stays quarantined if the InnoDB half cannot
 * commit.  Reconciliation can then report the exact orphan safely instead of
 * turning an uncertain upload into a public file.
 */
final class MemberEraUploadService
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_PIXELS = 120000000;
    private const QUARANTINE_LEVEL = 16;
    private const PUBLISHED_LEVEL = 0;

    /** @var list<string> */
    private const MEMBER_ROLES = [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER];

    /** @var array<string,string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Publish one newly uploaded image into an explicitly selected official
     * album.  The returned IDs are for the owned BFF only; no Piwigo id,
     * original path, filename, checksum, or media URL is returned to a
     * browser.
     *
     * @param array<string,mixed> $file PHP multipart entry
     * @return array{class_photo_id:string,era:string,album_id:string,index_queued:bool,derivative_warmup_queued:bool}
     */
    public function publish(int $userId, array $file, string $era, string $classAlbumId): array
    {
        // Authorize before hashing a browser-controlled stream. The BFF has
        // already denied non-members, but the PHP domain boundary repeats it
        // so no internal route or future caller can turn this into a generic
        // file validator.
        $context = DomainSupport::requireMemberRole($userId, self::MEMBER_ROLES);
        $this->assertActiveMemberContext($context, $userId);
        [, , , , $checksum] = $this->validateUpload($file);

        // Piwigo duplicate detection is MD5-based and can return an unrelated
        // existing Core row. Serialize by our SHA-256 canonical identity
        // instead, then either associate an already verified same-era
        // canonical photo or create one fresh quarantined Core row. This is a
        // request-local advisory lock only; it is never an authorization
        // grant and a lock failure denies the upload.
        return $this->withChecksumLock($checksum, function () use ($userId, $file, $era, $classAlbumId): array {
            return $this->publishLocked($userId, $file, $era, $classAlbumId);
        });
    }

    /**
     * @param array<string,mixed> $file PHP multipart entry
     * @return array{class_photo_id:string,era:string,album_id:string,index_queued:bool,derivative_warmup_queued:bool}
     */
    private function publishLocked(int $userId, array $file, string $era, string $classAlbumId): array
    {
        $context = DomainSupport::requireMemberRole($userId, self::MEMBER_ROLES);
        $this->assertActiveMemberContext($context, $userId);
        $era = self::requireExplicitEra($era);
        $album = $this->requireOfficialEraAlbum($classAlbumId, $era);
        [$safeName, $mime, $extension, $size, $sourceChecksum, $width, $height, $temporary, $sourceMd5] = $this->validateUpload($file);
        $this->requirePiwigoPipeline();

        $existing = $this->findActiveCanonicalByChecksum($sourceChecksum, $era);
        if ($existing !== null) {
            return $this->associateExistingCanonical(
                $context,
                $existing,
                $album,
                $era,
                $sourceChecksum,
            );
        }
        // Core may already contain a same-byte row that predates or bypassed
        // canonical mapping. Do not create a second original merely because
        // its SHA-256 identity is not yet managed: surface it for curation.
        $this->assertNoUnmappedPiwigoMd5Duplicate($sourceMd5);

        $imageId = null;
        $associated = false;
        $createdByThisRequest = false;
        $mapping = null;
        try {
            // The ClassArchiveMediaFilePolicy hook runs inside Core's upload
            // path and normalizes the physical original to 0660.  Level 16
            // prevents any normal principal from reading a partially written
            // MyISAM/Core row before the ClassIdentity transaction commits.
            $created = $this->addFreshPiwigoImage($temporary, $safeName);
            if (!is_int($created) && !ctype_digit((string) $created)) {
                throw new \RuntimeException('member_era_upload_piwigo_pipeline_failed');
            }
            $imageId = (int) $created;
            if ($imageId <= 0) {
                throw new \RuntimeException('member_era_upload_piwigo_image_invalid');
            }
            $this->assertFreshQuarantinedImage($imageId, $safeName);
            $createdByThisRequest = true;

            // Repeat the opaque-id mapping check immediately before the
            // non-transactional Core association.  An album changed/retired
            // after preflight is a denial, never a fallback to an era root.
            $album = $this->requireOfficialEraAlbum($classAlbumId, $era);
            associate_images_to_categories([$imageId], [(int) $album['piwigo_category_id']]);
            $associated = true;
            $this->assertExactAlbumAssociation($imageId, (int) $album['piwigo_category_id']);
            $this->assertManagedOriginal($imageId);
            [$uploadedChecksum, $mediaReference] = $this->managedMediaReferenceAndChecksum($imageId);
            if (!hash_equals($sourceChecksum, $uploadedChecksum)) {
                throw new \RuntimeException('member_era_upload_checksum_drift');
            }

            $mapping = $this->repository->transaction(function (Repository $repository) use (
                $context,
                $userId,
                $imageId,
                $era,
                $album,
                $uploadedChecksum,
                $mediaReference,
                $mime,
                $extension,
                $size,
                $width,
                $height,
            ): array {
                // A second mapping/category check closes the Core-to-InnoDB
                // handoff.  It rejects any unexpected multi-album membership
                // rather than accidentally publishing into another semantic
                // space.
                $this->assertOfficialEraAlbumStillMatches($album, $era);
                $this->assertExactAlbumAssociation($imageId, (int) $album['piwigo_category_id']);

                $repository->execute(
                    'INSERT INTO `' . $repository->table('archive_image') . '` '
                    . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, NULL, \'UNKNOWN\', \'UNKNOWN\', \'UNKNOWN\', NULL, 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$imageId, $era],
                );
                $createdMapping = (new ClassArchivePhotoMappingService($repository))->ensurePiwigoMapping(
                    $imageId,
                    $uploadedChecksum,
                    $mediaReference,
                );
                if (($createdMapping['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE) {
                    throw new \RuntimeException('member_era_upload_mapping_not_active');
                }

                // This direct SQL archive/mapping saga intentionally bypasses
                // the higher-level archive service, so mark the durable read
                // catalog stale explicitly before committing. The post-commit
                // builder below then appends exactly this known canonical id;
                // without this boundary an ACTIVE catalog could remain
                // silently incomplete after a successful Core write.
                ProjectionMutationBoundary::invalidatePhotos(
                    $repository,
                    ProjectionMutationBoundary::allAggregateKinds(),
                    'MEMBER_ERA_UPLOAD_PUBLISH',
                );

                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_PUBLISH',
                    'target_type' => 'PHOTO',
                    'target_id' => (string) $createdMapping['class_photo_id'],
                    'target_identity_id' => (int) $context['identity_id'],
                    'target_seat_id' => (int) $context['seat_id'],
                    'target_account_id' => (int) $context['account_id'],
                    'target_principal_id' => (int) $context['principal_id'],
                    'new_value' => [
                        'class_photo_id' => (string) $createdMapping['class_photo_id'],
                        'piwigo_image_id' => $imageId,
                        'class_album_id' => (string) $album['class_album_id'],
                        'piwigo_category_id' => (int) $album['piwigo_category_id'],
                        'era' => $era,
                        'mime_type' => $mime,
                        'extension' => $extension,
                        'byte_size' => $size,
                        'width' => $width,
                        'height' => $height,
                        // The upload has no curator-supplied chronology. This
                        // deliberately records unknown archive evidence, not
                        // an inferred era or fabricated capture date.
                        'date_precision' => 'UNKNOWN',
                        'date_confidence' => 'UNKNOWN',
                        'date_source' => 'UNKNOWN',
                        'official' => false,
                    ],
                    'reason' => $era === 'HERITAGE'
                        ? '成员自主发布班级历史照片'
                        : '成员自主发布毕业后动态照片',
                    'result' => 'SUCCESS',
                ]);

                return $createdMapping;
            });
        } catch (\Throwable $error) {
            if ($createdByThisRequest && $imageId !== null && $imageId > 0) {
                $this->quarantineFailedCoreWrite($imageId, $associated ? (int) $album['piwigo_category_id'] : null, $context, $error);
            } else {
                $this->auditPreImageFailure($context, $era, $classAlbumId, $error);
            }
            throw $error;
        }

        if (!is_array($mapping) || !is_string($mapping['class_photo_id'] ?? null)) {
            // The InnoDB truth committed but its acknowledgement was not
            // trustworthy.  Keep the Core row quarantined rather than making
            // a byte available without a confirmed canonical mapping.
            $this->quarantineFailedCoreWrite($imageId, (int) $album['piwigo_category_id'], $context, new \RuntimeException('member_era_upload_mapping_acknowledgement_invalid'));
            throw new \RuntimeException('member_era_upload_mapping_acknowledgement_invalid');
        }

        try {
            $this->releasePublishedVisibility($imageId);
        } catch (\Throwable $error) {
            // The durable mapping/metadata exists, but the original remains
            // level-16 and therefore denied for normal members. Preserve that
            // safe state for reconciliation instead of guessing that it is
            // visible, and make the retry condition auditable.
            $this->auditVisibilityReleaseFailure($context, (string) $mapping['class_photo_id'], $imageId, $error);
            throw $error;
        }

        $this->rebuildPhotoProjectionIfRequired(
            $context,
            (string) $mapping['class_photo_id'],
            $imageId,
        );
        invalidate_user_cache();

        $derivativeWarmupQueued = false;
        if (class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
            $derivativeWarmupQueued = \ClassArchiveDerivativeWarmupQueue::enqueueBestEffort(
                (string) $mapping['class_photo_id'],
                $imageId,
            );
            if ($derivativeWarmupQueued && class_exists('ClassArchiveDerivativeCacheWarmer', false)) {
                // This is a bounded post-commit cache preparation. A failure
                // leaves the durable queue marker for maintenance; it never
                // changes the successful publication or causes read-time
                // derivative generation.
                \ClassArchiveDerivativeCacheWarmer::warmBestEffort(
                    (string) $mapping['class_photo_id'],
                    $imageId,
                );
            }
        }

        $indexQueued = false;
        try {
            $job = (new AiIndexService($this->repository))->enqueueNewPhoto((string) $mapping['class_photo_id']);
            $indexQueued = ($job['queued'] ?? false) === true;
        } catch (\Throwable $error) {
            // AI remains an incremental, durable work queue. It must never
            // turn this request into a full-library index or roll a valid
            // publication back after bytes have become visible.
            $this->auditIncrementalQueueFailure($context, (string) $mapping['class_photo_id'], $imageId, $error);
        }

        return [
            'class_photo_id' => (string) $mapping['class_photo_id'],
            'era' => $era,
            'album_id' => (string) $album['class_album_id'],
            'index_queued' => $indexQueued,
            'derivative_warmup_queued' => $derivativeWarmupQueued,
        ];
    }

    /** @param array<string,mixed> $context */
    private function assertActiveMemberContext(array $context, int $userId): void
    {
        if (!in_array((string) ($context['role'] ?? ''), self::MEMBER_ROLES, true)) {
            throw new \RuntimeException('member_era_upload_role_forbidden');
        }
        foreach (['identity_id', 'seat_id', 'account_id', 'principal_id', 'piwigo_user_id'] as $field) {
            if ((int) ($context[$field] ?? 0) <= 0) {
                throw new \RuntimeException('member_era_upload_principal_incomplete');
            }
        }
        if ((int) $context['piwigo_user_id'] !== $userId) {
            throw new \RuntimeException('member_era_upload_principal_mismatch');
        }
    }

    /** @return array{class_album_id:string,piwigo_category_id:int,era:string,album_type:string,state:string,uppercats:string} */
    private function requireOfficialEraAlbum(string $classAlbumId, string $era): array
    {
        global $prefixeTable;

        $binary = DomainSupport::idToBinary($classAlbumId);
        $row = $this->repository->fetchOne(
            'SELECT a.`class_album_id`,a.`piwigo_category_id`,a.`album_type`,a.`era`,a.`state`,c.`uppercats` '
            . 'FROM `' . DomainSupport::table($this->repository, 'album') . '` a '
            . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=a.`piwigo_category_id` '
            . 'WHERE a.`class_album_id`=? LIMIT 1',
            [$binary],
        );
        if ($row === null) {
            throw new \InvalidArgumentException('member_era_upload_album_invalid');
        }
        $mappedId = $row['class_album_id'] ?? null;
        $categoryId = (int) ($row['piwigo_category_id'] ?? 0);
        if (!is_string($mappedId) || strlen($mappedId) !== 16 || $categoryId <= 0
            || ($row['album_type'] ?? null) !== 'OFFICIAL'
            || ($row['state'] ?? null) !== 'ACTIVE'
            || ($row['era'] ?? null) !== $era
        ) {
            throw new \InvalidArgumentException('member_era_upload_album_invalid');
        }
        $root = $this->eraRoot($era);
        $uppercats = (string) ($row['uppercats'] ?? '');
        if ($categoryId !== $root && !str_contains(',' . $uppercats . ',', ',' . $root . ',')) {
            throw new \InvalidArgumentException('member_era_upload_album_invalid');
        }
        return [
            'class_album_id' => DomainSupport::binaryToId($mappedId),
            'piwigo_category_id' => $categoryId,
            'era' => $era,
            'album_type' => 'OFFICIAL',
            'state' => 'ACTIVE',
            'uppercats' => $uppercats,
        ];
    }

    /** @param array{class_album_id:string,piwigo_category_id:int,era:string,album_type:string,state:string,uppercats:string} $album */
    private function assertOfficialEraAlbumStillMatches(array $album, string $era): void
    {
        $current = $this->requireOfficialEraAlbum((string) $album['class_album_id'], $era);
        if ((int) $current['piwigo_category_id'] !== (int) $album['piwigo_category_id']) {
            throw new \RuntimeException('member_era_upload_album_drift');
        }
    }

    private function eraRoot(string $era): int
    {
        global $prefixeTable;

        $permalink = $era === 'HERITAGE' ? 'class-archive-heritage' : 'class-archive-living';
        $row = $this->repository->fetchOne(
            'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 1',
            [$permalink],
        );
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('member_era_upload_root_missing');
        }
        return $id;
    }

    private static function requireExplicitEra(string $era): string
    {
        $era = strtoupper(trim($era));
        if (!in_array($era, ['HERITAGE', 'LIVING'], true)) {
            throw new \InvalidArgumentException('member_era_upload_era_required');
        }
        return $era;
    }

    /**
     * Serialize one content identity without adding a table or treating an
     * advisory lock as durable state.  The name contains only a truncated
     * digest, is bounded below MariaDB's lock-name maximum, and is released
     * before the request returns.  A missing/contended lock is fail-closed.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withChecksumLock(string $checksum, callable $callback): mixed
    {
        if (preg_match('/\A[0-9a-f]{64}\z/Di', $checksum) !== 1) {
            throw new \RuntimeException('member_era_upload_checksum_lock_invalid');
        }
        $name = 'ci_member_upload_' . substr(strtolower($checksum), 0, 40);
        $row = $this->repository->fetchOne('SELECT GET_LOCK(?, 15) AS `locked`', [$name]);
        if ($row === null || (int) ($row['locked'] ?? 0) !== 1) {
            throw new \RuntimeException('member_era_upload_checksum_lock_unavailable');
        }

        try {
            return $callback();
        } finally {
            // If the connection cannot prove release, report a retryable
            // failure rather than pretending the request is complete. The
            // committed mapping remains checksum-idempotent and MediaGuard
            // remains authoritative; no lock result grants file access.
            $released = $this->repository->fetchOne('SELECT RELEASE_LOCK(?) AS `released`', [$name]);
            if ($released === null || (int) ($released['released'] ?? 0) !== 1) {
                throw new \RuntimeException('member_era_upload_checksum_lock_release_failed');
            }
        }
    }

    /**
     * @return array{class_photo_id:string,piwigo_image_id:int,era:string,media_reference:string}|null
     */
    private function findActiveCanonicalByChecksum(string $checksum, string $era): ?array
    {
        $checksumBinary = ClassArchivePhoto::checksumToBinary($checksum);
        $rows = $this->repository->fetchAll(
            'SELECT p.`class_photo_id`,p.`piwigo_image_id`,p.`media_reference`,p.`state`,ai.`era` '
            . 'FROM `' . $this->repository->table('photo') . '` p '
            . 'JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id`=p.`piwigo_image_id` '
            . 'WHERE p.`media_checksum`=? AND p.`state`=? ORDER BY p.`class_photo_id` ASC LIMIT 2',
            [$checksumBinary, ClassArchivePhoto::STATE_ACTIVE],
        );
        if (count($rows) > 1) {
            // Existing duplicate canonical truth is a maintenance concern;
            // a new member request must never choose an arbitrary winner.
            throw new \RuntimeException('member_era_upload_canonical_checksum_ambiguous');
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        $binaryId = $row['class_photo_id'] ?? null;
        $imageId = (int) ($row['piwigo_image_id'] ?? 0);
        $mappedEra = (string) ($row['era'] ?? '');
        $reference = $row['media_reference'] ?? null;
        if (!is_string($binaryId) || strlen($binaryId) !== 16 || $imageId <= 0
            || !is_string($reference) || $reference === '' || $mappedEra !== $era
        ) {
            throw new \RuntimeException('member_era_upload_canonical_checksum_mapping_invalid');
        }
        return [
            'class_photo_id' => DomainSupport::binaryToId($binaryId),
            'piwigo_image_id' => $imageId,
            'era' => $mappedEra,
            'media_reference' => $reference,
        ];
    }

    /**
     * Associate a proven existing same-era canonical image without accepting
     * a new Core byte stream.  This preserves one original while keeping all
     * source-album relationships visible in the selected semantic space.
     *
     * @param array<string,mixed> $context
     * @param array{class_photo_id:string,piwigo_image_id:int,era:string,media_reference:string} $existing
     * @param array{class_album_id:string,piwigo_category_id:int,era:string,album_type:string,state:string,uppercats:string} $album
     * @return array{class_photo_id:string,era:string,album_id:string,index_queued:bool,derivative_warmup_queued:bool}
     */
    private function associateExistingCanonical(
        array $context,
        array $existing,
        array $album,
        string $era,
        string $checksum,
    ): array {
        $imageId = (int) $existing['piwigo_image_id'];
        $categoryId = (int) $album['piwigo_category_id'];
        $this->assertExistingCanonicalReady($existing, $era, $checksum);
        $wasAssociated = $this->hasAlbumAssociation($imageId, $categoryId);

        // This exact Piwigo association is idempotent. Its native trigger
        // invalidates the catalog before the write; the durable audit below
        // records whether the request added a relationship or merely reused
        // one. No ancestor memberships are copied.
        $this->assertOfficialEraAlbumStillMatches($album, $era);
        associate_images_to_categories([$imageId], [$categoryId]);
        $this->assertContainsAlbumAssociation($imageId, $categoryId);
        $this->assertOfficialEraAlbumStillMatches($album, $era);
        $this->assertExistingCanonicalReady($existing, $era, $checksum);
        $added = !$wasAssociated;

        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $existing, $album, $era, $checksum, $added): void {
                $this->assertExistingCanonicalMappingCurrent($existing, $era, $checksum);
                if ($added) {
                    // The bounded native source change is represented by the
                    // one known canonical UUID; no all-library AI/derivative
                    // work is queued for unchanged pixels.
                    ProjectionMutationBoundary::invalidatePhotos(
                        $repository,
                        ProjectionMutationBoundary::allAggregateKinds(),
                        'MEMBER_ERA_UPLOAD_DEDUP_ASSOCIATION',
                    );
                }
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_DEDUP_ASSOCIATION',
                    'target_type' => 'PHOTO',
                    'target_id' => (string) $existing['class_photo_id'],
                    'target_identity_id' => (int) $context['identity_id'],
                    'target_seat_id' => (int) $context['seat_id'],
                    'target_account_id' => (int) $context['account_id'],
                    'target_principal_id' => (int) $context['principal_id'],
                    'new_value' => [
                        'class_photo_id' => (string) $existing['class_photo_id'],
                        'piwigo_image_id' => (int) $existing['piwigo_image_id'],
                        'class_album_id' => (string) $album['class_album_id'],
                        'piwigo_category_id' => (int) $album['piwigo_category_id'],
                        'era' => $era,
                        'association_added' => $added,
                    ],
                    'reason' => '成员上传与现有同内容照片一致，复用单份原图',
                    'result' => 'SUCCESS',
                ]);
            });
        } catch (\Throwable $error) {
            // The byte and its mapping existed before this request. Never
            // quarantine, delete, or lower that image here; the selected
            // same-era association is left for reconciliation if the durable
            // audit/projection half could not commit.
            $this->auditExistingAssociationFailure($context, $existing, $album, $era, $error);
            throw $error;
        }

        // A prior retry may have added the relationship but lost its bounded
        // projection rebuild before responding. Recheck the durable catalog
        // state even when this exact association already existed, so a retry
        // can recover the one-photo write without any full-library scan.
        $this->rebuildPhotoProjectionIfRequired(
            $context,
            (string) $existing['class_photo_id'],
            $imageId,
        );
        if ($added) {
            invalidate_user_cache();
        }
        return [
            'class_photo_id' => (string) $existing['class_photo_id'],
            'era' => $era,
            'album_id' => (string) $album['class_album_id'],
            'index_queued' => false,
            'derivative_warmup_queued' => false,
        ];
    }

    /** @param array{class_photo_id:string,piwigo_image_id:int,era:string,media_reference:string} $existing */
    private function assertExistingCanonicalReady(array $existing, string $era, string $checksum): void
    {
        $this->assertExistingCanonicalMappingCurrent($existing, $era, $checksum);
        global $prefixeTable;
        $imageId = (int) $existing['piwigo_image_id'];
        $row = $this->repository->fetchOne(
            'SELECT `level` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$imageId],
        );
        if ($row === null || (int) ($row['level'] ?? -1) !== self::PUBLISHED_LEVEL) {
            throw new \RuntimeException('member_era_upload_canonical_visibility_invalid');
        }
        [$actualChecksum, $actualReference] = $this->managedMediaReferenceAndChecksum($imageId);
        if (!hash_equals($checksum, $actualChecksum)
            || !hash_equals((string) $existing['media_reference'], $actualReference)
        ) {
            throw new \RuntimeException('member_era_upload_canonical_media_drift');
        }
        $this->assertSingleEffectiveEra($imageId, $era);
    }

    /** @param array{class_photo_id:string,piwigo_image_id:int,era:string,media_reference:string} $existing */
    private function assertExistingCanonicalMappingCurrent(array $existing, string $era, string $checksum): void
    {
        $id = DomainSupport::idToBinary((string) $existing['class_photo_id']);
        $checksumBinary = ClassArchivePhoto::checksumToBinary($checksum);
        $row = $this->repository->fetchOne(
            'SELECT p.`piwigo_image_id`,p.`media_reference`,p.`state`,ai.`era` '
            . 'FROM `' . $this->repository->table('photo') . '` p '
            . 'JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id`=p.`piwigo_image_id` '
            . 'WHERE p.`class_photo_id`=? AND p.`media_checksum`=? AND p.`state`=? LIMIT 1',
            [$id, $checksumBinary, ClassArchivePhoto::STATE_ACTIVE],
        );
        if ($row === null || (int) ($row['piwigo_image_id'] ?? 0) !== (int) $existing['piwigo_image_id']
            || !is_string($row['media_reference'] ?? null)
            || !hash_equals((string) $existing['media_reference'], (string) $row['media_reference'])
            || ($row['era'] ?? null) !== $era
        ) {
            throw new \RuntimeException('member_era_upload_canonical_mapping_drift');
        }
    }

    private function assertSingleEffectiveEra(int $imageId, string $era): void
    {
        global $prefixeTable;
        $heritage = $this->eraRoot('HERITAGE');
        $living = $this->eraRoot('LIVING');
        $row = $this->repository->fetchOne(
            'SELECT MAX(CASE WHEN c.`id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END) AS `heritage`, '
            . 'MAX(CASE WHEN c.`id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END) AS `living` '
            . 'FROM `' . $prefixeTable . 'image_category` ic '
            . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` WHERE ic.`image_id`=?',
            [$heritage, $heritage, $living, $living, $imageId],
        );
        $hasHeritage = (int) ($row['heritage'] ?? 0) === 1;
        $hasLiving = (int) ($row['living'] ?? 0) === 1;
        if ($hasHeritage === $hasLiving || ($hasHeritage ? 'HERITAGE' : 'LIVING') !== $era) {
            throw new \RuntimeException('member_era_upload_canonical_effective_era_invalid');
        }
    }

    private function hasAlbumAssociation(int $imageId, int $categoryId): bool
    {
        global $prefixeTable;
        $row = $this->repository->fetchOne(
            'SELECT 1 AS `present` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=? LIMIT 1',
            [$imageId, $categoryId],
        );
        return $row !== null && (int) ($row['present'] ?? 0) === 1;
    }

    private function assertContainsAlbumAssociation(int $imageId, int $categoryId): void
    {
        if (!$this->hasAlbumAssociation($imageId, $categoryId)) {
            throw new \RuntimeException('member_era_upload_album_association_missing');
        }
    }

    private function assertNoUnmappedPiwigoMd5Duplicate(string $md5): void
    {
        global $prefixeTable;
        if (preg_match('/\A[0-9a-f]{32}\z/Di', $md5) !== 1) {
            throw new \RuntimeException('member_era_upload_md5_invalid');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `id` FROM `' . $prefixeTable . 'images` WHERE `md5sum`=? ORDER BY `id` ASC LIMIT 2',
            [$md5],
        );
        if ($rows !== []) {
            // The active canonical SHA lookup above would have handled a
            // managed same-byte photo. Any remaining Core duplicate is not a
            // safe target for a member request, so deny instead of letting
            // Piwigo select one and mutating its visibility/association.
            throw new \RuntimeException('member_era_upload_unmapped_core_duplicate');
        }
    }

    private function addFreshPiwigoImage(string $temporary, string $safeName): mixed
    {
        global $conf;
        if (!is_array($conf)) {
            throw new \RuntimeException('member_era_upload_core_configuration_unavailable');
        }
        $hadDuplicateSetting = array_key_exists('upload_detect_duplicate', $conf);
        $previousDuplicateSetting = $conf['upload_detect_duplicate'] ?? null;
        // Core's MD5 duplicate branch returns an arbitrary pre-existing image
        // and unlinks this temporary file. We already own a SHA-256 canonical
        // decision under a lock, so suppress only that request-local Core
        // shortcut. Restore the exact original configuration in finally.
        $conf['upload_detect_duplicate'] = false;
        try {
            return add_uploaded_file($temporary, $safeName, null, self::QUARANTINE_LEVEL);
        } finally {
            if ($hadDuplicateSetting) {
                $conf['upload_detect_duplicate'] = $previousDuplicateSetting;
            } else {
                unset($conf['upload_detect_duplicate']);
            }
        }
    }

    /** @return array{0:string,1:string,2:string,3:int,4:string,5:int,6:int,7:string,8:string} */
    private function validateUpload(array $file): array
    {
        // A PHP multiple-file shape is an array at one or more of these
        // fields. Reject it before any scalar cast so malformed multipart
        // input cannot emit conversion warnings or become an accidental
        // multi-file operation.
        foreach (['error', 'tmp_name', 'size', 'name'] as $field) {
            if (is_array($file[$field] ?? null)) {
                throw new \InvalidArgumentException('member_era_upload_file_invalid');
            }
        }
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($errorCode) && !(is_string($errorCode) && ctype_digit($errorCode))) {
            throw new \InvalidArgumentException('member_era_upload_file_invalid');
        }
        if ((int) $errorCode !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('member_era_upload_file_invalid');
        }
        $temporary = $file['tmp_name'] ?? null;
        $rawSize = $file['size'] ?? null;
        if (!is_int($rawSize) && !(is_string($rawSize) && ctype_digit($rawSize))) {
            throw new \InvalidArgumentException('member_era_upload_file_invalid');
        }
        $size = (int) $rawSize;
        if (!is_string($temporary) || $temporary === '' || !is_uploaded_file($temporary)
            || $size <= 0 || $size > self::MAX_BYTES
        ) {
            throw new \InvalidArgumentException('member_era_upload_file_invalid');
        }
        $name = $file['name'] ?? null;
        if (!is_string($name) || $name === '' || strlen($name) > 255 || str_contains($name, "\0")
            || str_contains($name, '/') || str_contains($name, '\\')
        ) {
            throw new \InvalidArgumentException('member_era_upload_filename_invalid');
        }
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '..')) {
            throw new \InvalidArgumentException('member_era_upload_filename_invalid');
        }
        $providedExtension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($providedExtension, ['jpg', 'jpeg', 'png', 'webp'], true)
            || preg_match('/\.(?:php[0-9]?|phtml|phar|cgi|pl|py|rb|js|mjs|html?|svg)\.(?:jpe?g|png|webp)\z/iD', $name) === 1
        ) {
            throw new \InvalidArgumentException('member_era_upload_filename_invalid');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_file($finfo, $temporary);
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new \InvalidArgumentException('member_era_upload_mime_invalid');
        }
        $image = @getimagesize($temporary);
        if (!is_array($image) || (int) ($image[0] ?? 0) <= 0 || (int) ($image[1] ?? 0) <= 0) {
            throw new \InvalidArgumentException('member_era_upload_image_invalid');
        }
        if (($image['mime'] ?? null) !== $mime) {
            throw new \InvalidArgumentException('member_era_upload_mime_invalid');
        }
        $width = (int) $image[0];
        $height = (int) $image[1];
        if ($width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('member_era_upload_dimensions_invalid');
        }
        $actualSize = filesize($temporary);
        if (!is_int($actualSize) || $actualSize !== $size) {
            throw new \InvalidArgumentException('member_era_upload_size_invalid');
        }
        $checksum = hash_file('sha256', $temporary);
        if (!is_string($checksum) || preg_match('/\A[0-9a-f]{64}\z/Di', $checksum) !== 1) {
            throw new \RuntimeException('member_era_upload_checksum_failed');
        }
        $md5 = md5_file($temporary);
        if (!is_string($md5) || preg_match('/\A[0-9a-f]{32}\z/Di', $md5) !== 1) {
            throw new \RuntimeException('member_era_upload_md5_failed');
        }
        // Piwigo receives an opaque safe name. The original browser filename
        // is neither a path selector nor an authorization/provenance key.
        $safeName = bin2hex(random_bytes(20)) . '.' . self::MIME_EXTENSIONS[$mime];
        return [$safeName, $mime, self::MIME_EXTENSIONS[$mime], $size, strtolower($checksum), $width, $height, $temporary, strtolower($md5)];
    }

    private function requirePiwigoPipeline(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
        if (!function_exists('add_uploaded_file') || !function_exists('associate_images_to_categories') || !function_exists('invalidate_user_cache')
            || !class_exists('ClassArchiveMediaFilePolicy', false)
        ) {
            throw new \RuntimeException('member_era_upload_pipeline_unavailable');
        }
    }

    private function assertFreshQuarantinedImage(int $imageId, string $safeName): void
    {
        global $prefixeTable;

        $row = $this->repository->fetchOne(
            'SELECT `id`,`level`,`file` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$imageId],
        );
        if ($row === null || (int) ($row['id'] ?? 0) !== $imageId
            || (int) ($row['level'] ?? -1) !== self::QUARANTINE_LEVEL
            || !is_string($row['file'] ?? null)
            || !hash_equals($safeName, (string) $row['file'])
        ) {
            throw new \RuntimeException('member_era_upload_quarantine_invalid');
        }
        $mapping = (new ClassArchivePhotoMappingService($this->repository))->findByPiwigoImageId($imageId);
        if ($mapping !== null) {
            throw new \RuntimeException('member_era_upload_existing_mapping');
        }
        $associations = $this->repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=?',
            [$imageId],
        );
        if ((int) ($associations['count'] ?? -1) !== 0) {
            throw new \RuntimeException('member_era_upload_existing_association');
        }
    }

    private function assertExactAlbumAssociation(int $imageId, int $categoryId): void
    {
        global $prefixeTable;

        $rows = $this->repository->fetchAll(
            'SELECT `category_id` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? ORDER BY `category_id` ASC',
            [$imageId],
        );
        if (count($rows) !== 1 || (int) ($rows[0]['category_id'] ?? 0) !== $categoryId) {
            throw new \RuntimeException('member_era_upload_album_association_invalid');
        }
    }

    private function assertManagedOriginal(int $imageId): void
    {
        global $prefixeTable;

        $row = $this->repository->fetchOne(
            'SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$imageId],
        );
        if ($row === null) {
            throw new \RuntimeException('member_era_upload_piwigo_image_missing');
        }
        $reference = ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
        if (!str_starts_with($reference, 'upload/')) {
            throw new \RuntimeException('member_era_upload_media_reference_invalid');
        }
        $path = PHPWG_ROOT_PATH . $reference;
        $uploadRoot = realpath(PHPWG_ROOT_PATH . 'upload');
        $resolved = realpath($path);
        if ($uploadRoot === false || $resolved === false || !is_file($resolved) || is_link($path)) {
            throw new \RuntimeException('member_era_upload_media_unavailable');
        }
        $prefix = rtrim(str_replace('\\', '/', $uploadRoot), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            throw new \RuntimeException('member_era_upload_media_untrusted');
        }
        // The policy hook owns normalisation. Recheck it here so an absent or
        // broken hook cannot be masked by a successful Core upload.
        $mode = @fileperms($resolved);
        if (!is_int($mode) || ($mode & 0777) !== 0660) {
            throw new \RuntimeException('member_era_upload_media_mode_invalid');
        }
    }

    /** @return array{0:string,1:string} SHA-256 hex and MediaGuard-safe relative reference */
    private function managedMediaReferenceAndChecksum(int $imageId): array
    {
        global $prefixeTable;

        $row = $this->repository->fetchOne(
            'SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$imageId],
        );
        if ($row === null) {
            throw new \RuntimeException('member_era_upload_piwigo_image_missing');
        }
        $reference = ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
        $path = PHPWG_ROOT_PATH . $reference;
        $root = PHPWG_ROOT_PATH . (str_starts_with($reference, 'upload/') ? 'upload' : 'galleries');
        $rootReal = realpath($root);
        $fileReal = realpath($path);
        if ($rootReal === false || $fileReal === false || !is_file($fileReal) || is_link($path)) {
            throw new \RuntimeException('member_era_upload_media_unavailable');
        }
        $prefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $fileReal), $prefix)) {
            throw new \RuntimeException('member_era_upload_media_untrusted');
        }
        $checksum = hash_file('sha256', $fileReal);
        if (!is_string($checksum) || preg_match('/\A[0-9a-f]{64}\z/Di', $checksum) !== 1) {
            throw new \RuntimeException('member_era_upload_checksum_failed');
        }
        return [strtolower($checksum), $reference];
    }

    private function releasePublishedVisibility(int $imageId): void
    {
        global $prefixeTable;

        $changed = $this->repository->execute(
            'UPDATE `' . $prefixeTable . 'images` SET `level`=? WHERE `id`=? AND `level`=?',
            [self::PUBLISHED_LEVEL, $imageId, self::QUARANTINE_LEVEL],
        );
        if ($changed !== 1) {
            throw new \RuntimeException('member_era_upload_visibility_release_failed');
        }
        $row = $this->repository->fetchOne(
            'SELECT `level` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
            [$imageId],
        );
        if ($row === null || (int) ($row['level'] ?? -1) !== self::PUBLISHED_LEVEL) {
            throw new \RuntimeException('member_era_upload_visibility_release_failed');
        }
    }

    /** @param array<string,mixed> $context */
    private function quarantineFailedCoreWrite(int $imageId, ?int $categoryId, array $context, \Throwable $error): void
    {
        global $prefixeTable;

        try {
            // Core rows/files are not transactionally coupled to InnoDB. Keep
            // this exact image inaccessible, and remove only the exact album
            // association that this request added. We intentionally do not
            // call delete_elements() on an uncertain legacy write: a durable
            // reconciliation finding is safer than deleting bytes after an
            // ambiguous cross-store failure.
            $this->repository->execute(
                'UPDATE `' . $prefixeTable . 'images` SET `level`=? WHERE `id`=?',
                [self::QUARANTINE_LEVEL, $imageId],
            );
            if ($categoryId !== null && $categoryId > 0) {
                $this->repository->execute(
                    'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=?',
                    [$imageId, $categoryId],
                );
            }
            invalidate_user_cache();
        } catch (\Throwable) {
            // The Piwigo row remains level-16 if the DB is unavailable or
            // any exact compensation cannot be proven. Never lower level or
            // make a fallback deletion attempt here.
        }

        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $imageId, $categoryId, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_ABORT',
                    'target_type' => 'PHOTO_UPLOAD',
                    'target_id' => (string) $imageId,
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'piwigo_image_id' => $imageId,
                        'piwigo_category_id' => $categoryId,
                        'state' => 'QUARANTINED',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传未完成，已隔离待一致性核对',
                    'result' => 'FAILED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // The original write error remains authoritative. If InnoDB is
            // unavailable, the level-16 Core row is still fail-closed and the
            // filename-shape reconciliation rule can surface it later.
        }
    }

    /** @param array<string,mixed> $context */
    private function auditPreImageFailure(array $context, string $era, string $classAlbumId, \Throwable $error): void
    {
        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $era, $classAlbumId, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_ABORT',
                    'target_type' => 'PHOTO_UPLOAD',
                    'target_id' => null,
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'class_album_id' => $classAlbumId,
                        'era' => $era,
                        'state' => 'REJECTED_BEFORE_CORE_WRITE',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传在写入前被拒绝',
                    'result' => 'DENIED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // Audit unavailability is not an authorization fallback. The
            // original validation/upload error remains the request outcome.
        }
    }

    /**
     * The canonical byte pre-dates this request.  A failure after an exact
     * association must never compensate by quarantining or deleting it; keep
     * a minimal audited reconciliation signal instead.
     *
     * @param array<string,mixed> $context
     * @param array{class_photo_id:string,piwigo_image_id:int,era:string,media_reference:string} $existing
     * @param array{class_album_id:string,piwigo_category_id:int,era:string,album_type:string,state:string,uppercats:string} $album
     */
    private function auditExistingAssociationFailure(
        array $context,
        array $existing,
        array $album,
        string $era,
        \Throwable $error,
    ): void {
        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $existing, $album, $era, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_DEDUP_ASSOCIATION_FAILED',
                    'target_type' => 'PHOTO',
                    'target_id' => (string) $existing['class_photo_id'],
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'class_photo_id' => (string) $existing['class_photo_id'],
                        'piwigo_image_id' => (int) $existing['piwigo_image_id'],
                        'class_album_id' => (string) $album['class_album_id'],
                        'piwigo_category_id' => (int) $album['piwigo_category_id'],
                        'era' => $era,
                        'state' => 'RECONCILIATION_REQUIRED',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传复用现有原图时状态未能完整确认',
                    'result' => 'FAILED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // No audit failure may modify the pre-existing canonical byte.
        }
    }

    /** @param array<string,mixed> $context */
    private function auditVisibilityReleaseFailure(array $context, string $classPhotoId, int $imageId, \Throwable $error): void
    {
        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $classPhotoId, $imageId, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_VISIBILITY_RELEASE_FAILED',
                    'target_type' => 'PHOTO',
                    'target_id' => $classPhotoId,
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'class_photo_id' => $classPhotoId,
                        'piwigo_image_id' => $imageId,
                        'state' => 'QUARANTINED',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传已入库但可见性释放失败，等待一致性核对',
                    'result' => 'FAILED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // No failure path may convert a level-16 image into a visible one.
        }
    }

    /** @param array<string,mixed> $context */
    private function auditIncrementalQueueFailure(array $context, string $classPhotoId, int $imageId, \Throwable $error): void
    {
        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $classPhotoId, $imageId, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_INDEX_QUEUE_FAILED',
                    'target_type' => 'PHOTO',
                    'target_id' => $classPhotoId,
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'class_photo_id' => $classPhotoId,
                        'piwigo_image_id' => $imageId,
                        'state' => 'PUBLISHED_INDEX_PENDING',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传已发布，增量索引等待维护重试',
                    'result' => 'FAILED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // Durable media remains MediaGuard-protected even if the optional
            // local AI queue cannot record its work immediately.
        }
    }

    /**
     * Publish one known canonical photo into the durable read catalog. This
     * service always creates or changes Piwigo Core source rows, which rotate
     * the durable MyISAM source epoch. A fresh catalog generation is therefore
     * required; treating that write as a point refresh would acknowledge a
     * source set the point builder cannot prove complete. This rebuild is
     * metadata/projection work only: derivative and AI jobs below remain
     * bounded to the newly published canonical photo.
     *
     * @param array<string,mixed> $context
     */
    private function rebuildPhotoProjectionIfRequired(array $context, string $classPhotoId, int $imageId): void
    {
        try {
            $store = new Gateway\ReadProjectionStore($this->repository);
            $catalogState = '';
            foreach ($store->status() as $status) {
                if (($status['kind'] ?? null) === Gateway\ReadProjectionStore::PHOTO_CATALOG) {
                    $catalogState = (string) ($status['state'] ?? '');
                    break;
                }
            }
            if ($catalogState === 'ACTIVE') {
                $projected = $store->photo($classPhotoId);
                if ($projected === null || $projected->piwigoImageIdForDelivery() !== $imageId) {
                    throw new \RuntimeException('member_era_upload_projection_mapping_missing');
                }
                return;
            }
            if ($catalogState !== 'STALE') {
                throw new \RuntimeException('member_era_upload_projection_state_unavailable');
            }
            Gateway\ReadProjectionBuilder::rebuild();
            $projected = $store->photo($classPhotoId);
            if ($projected === null || $projected->piwigoImageIdForDelivery() !== $imageId) {
                throw new \RuntimeException('member_era_upload_projection_mapping_missing');
            }
        } catch (\Throwable $error) {
            $this->auditProjectionRebuildFailure($context, $classPhotoId, $imageId, $error);
            throw new \RuntimeException('member_era_upload_projection_rebuild_failed', 0, $error);
        }
    }

    /** @param array<string,mixed> $context */
    private function auditProjectionRebuildFailure(array $context, string $classPhotoId, int $imageId, \Throwable $error): void
    {
        try {
            $this->repository->transaction(function (Repository $repository) use ($context, $classPhotoId, $imageId, $error): void {
                (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                    'action' => 'MEMBER_ERA_UPLOAD_PROJECTION_REBUILD_FAILED',
                    'target_type' => 'PHOTO',
                    'target_id' => $classPhotoId,
                    'target_identity_id' => (int) ($context['identity_id'] ?? 0),
                    'target_seat_id' => (int) ($context['seat_id'] ?? 0),
                    'target_account_id' => (int) ($context['account_id'] ?? 0),
                    'target_principal_id' => (int) ($context['principal_id'] ?? 0),
                    'new_value' => [
                        'class_photo_id' => $classPhotoId,
                        'piwigo_image_id' => $imageId,
                        'state' => 'PROJECTION_STALE',
                        'error_code' => self::failureCode($error),
                    ],
                    'reason' => '成员上传已入库，增量展示投影等待维护重试',
                    'result' => 'FAILED',
                    'error_code' => self::failureCode($error),
                ]);
            });
        } catch (\Throwable) {
            // The projection remains STALE and Gateway refuses it even when
            // the optional audit write cannot be persisted.
        }
    }

    private static function failureCode(\Throwable $error): string
    {
        $message = $error->getMessage();
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $message) === 1
            ? strtoupper($message)
            : 'MEMBER_ERA_UPLOAD_FAILED';
    }
}
