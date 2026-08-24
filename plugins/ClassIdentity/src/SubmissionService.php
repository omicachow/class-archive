<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Access;
use ClassIdentity\Audit;
use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePhotoMappingService;
use ClassIdentity\Repository;

/**
 * The audited Family contribution boundary.
 *
 * Community remains inactive. A Family account can only create a ClassIdentity
 * submission in the private pending store; only an active SYSTEM_ADMIN can
 * read that store or move the one original into Piwigo's normal upload path.
 */
final class ClassIdentitySubmissionService
{
    private const MAX_BYTES = 20971520;
    private const MAX_PIXELS = 120000000;
    private const PRECISIONS = ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'];
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private Repository $repository;
    private string $pendingRoot;

    public function __construct(Repository $repository, string $pendingRoot)
    {
        $this->repository = $repository;
        $this->pendingRoot = rtrim($pendingRoot, '/\\');
    }

    public static function fromPiwigo(): self
    {
        $dataRoot = realpath(PHPWG_ROOT_PATH . '_data');
        if ($dataRoot === false || !is_dir($dataRoot) || is_link($dataRoot)) {
            throw new RuntimeException('family_submission_storage_unavailable');
        }
        return new self(Repository::fromPiwigo(), $dataRoot . DIRECTORY_SEPARATOR . 'class_identity_pending');
    }

    public function submit(int $userId, array $file, ?string $suggestedDate, string $precision, ?string $suggestedAlbum, ?string $description): int
    {
        $context = Access::resolveAuthorizationContext($userId);
        if ($context === null || ($context['role'] ?? null) !== Access::ROLE_FAMILY) {
            throw new RuntimeException('family_submission_forbidden');
        }
        $identityId = (int) ($context['identity_id'] ?? 0);
        $seatId = (int) ($context['seat_id'] ?? 0);
        $accountId = (int) ($context['account_id'] ?? 0);
        $principalId = (int) ($context['principal_id'] ?? 0);
        if ($identityId <= 0 || $seatId <= 0 || $accountId <= 0 || $principalId <= 0) {
            throw new RuntimeException('family_submission_graph_incomplete');
        }

        [$originalName, $mime, $extension, $size, $sha256, $width, $height, $tmp] = $this->validateUpload($file);
        $precision = $this->normalizePrecision($precision);
        $date = $this->normalizeDate($suggestedDate, $precision);
        $album = $this->boundedText($suggestedAlbum, 190);
        $notes = $this->boundedText($description, 2000);
        $this->ensurePendingRoot();

        $nonce = bin2hex(random_bytes(24));
        $storageRef = 'class_identity_pending/' . $nonce . '.' . $extension;
        $thumbnailRef = 'class_identity_pending/' . $nonce . '.jpg';
        $storagePath = $this->resolveRef($storageRef, false);
        $thumbnailPath = $this->resolveRef($thumbnailRef, false);
        if (!is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('family_submission_upload_missing');
        }
        if (!move_uploaded_file($tmp, $storagePath)) {
            throw new RuntimeException('family_submission_storage_write_failed');
        }
        @chmod($storagePath, 0660);

        try {
            $this->createThumbnail($storagePath, $thumbnailPath, $width, $height);
            @chmod($thumbnailPath, 0660);
            $submissionId = $this->repository->transaction(function (Repository $repository) use (
                $seatId,
                $accountId,
                $principalId,
                $identityId,
                $originalName,
                $storageRef,
                $thumbnailRef,
                $mime,
                $extension,
                $size,
                $sha256,
                $width,
                $height,
                $date,
                $precision,
                $album,
                $notes,
                $userId,
            ): int {
                $repository->execute(
                    'INSERT INTO `' . $repository->table('submission') . '` '
                    . '(`seat_id`,`account_id`,`principal_id`,`identity_id`,`state`,`original_filename`,'
                    . '`storage_ref`,`thumbnail_ref`,`mime_type`,`extension`,`byte_size`,`sha256`,`width`,`height`,'
                    . '`suggested_date`,`date_precision`,`suggested_album`,`description`,`uploaded_at`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, ?, ?, \'PENDING\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$seatId, $accountId, $principalId, $identityId, $originalName, $storageRef, $thumbnailRef, $mime, $extension, $size, $sha256, $width, $height, $date, $precision, $album, $notes],
                );
                $id = $repository->lastInsertId();
                (new ClassArchivePhotoMappingService($repository))->createPendingSubmissionMapping(
                    $id,
                    bin2hex($sha256),
                    $storageRef,
                );
                (new Audit($repository))->append([
                    'actor_principal_id' => $principalId,
                    'actor_user_id' => $userId,
                    'actor_kind' => 'FAMILY',
                    'action' => 'SUBMISSION_CREATE',
                    'target_type' => 'SUBMISSION',
                    'target_id' => (string) $id,
                    'target_identity_id' => $identityId,
                    'target_seat_id' => $seatId,
                    'target_account_id' => $accountId,
                    'new_value' => [
                        'state' => 'PENDING',
                        'mime_type' => $mime,
                        'byte_size' => $size,
                        'width' => $width,
                        'height' => $height,
                        'date_precision' => $precision,
                    ],
                    'reason' => '家庭席位提交班级历史照片',
                    'result' => 'SUCCESS',
                ]);

                return $id;
            });
        } catch (Throwable $error) {
            $this->safeUnlink($storagePath);
            $this->safeUnlink($thumbnailPath);
            throw $error;
        }

        return $submissionId;
    }

    /** @return list<array<string, mixed>> */
    public function mine(int $userId): array
    {
        $context = Access::resolveAuthorizationContext($userId);
        if ($context === null || ($context['role'] ?? null) !== Access::ROLE_FAMILY) {
            throw new RuntimeException('family_submission_forbidden');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `id`,`state`,`original_filename`,`mime_type`,`byte_size`,`width`,`height`,'
            . '`suggested_date`,`date_precision`,`suggested_album`,`description`,`uploaded_at`,`reviewed_at`,`review_reason` '
            . 'FROM `' . $this->repository->table('submission') . '` WHERE `principal_id` = ? '
            . 'ORDER BY `uploaded_at` DESC, `id` DESC LIMIT 100',
            [(int) $context['principal_id']],
        );
        foreach ($rows as &$row) {
            $row['state_label'] = match ((string) ($row['state'] ?? '')) {
                'PENDING' => '待审核',
                'APPROVED' => '已通过',
                'REJECTED' => '已拒绝',
                default => '状态异常',
            };
            $row['precision_label'] = ClassIdentityArchiveService::precisionLabel((string) ($row['date_precision'] ?? 'UNKNOWN'));
            $row['relationship_label'] = self::relationshipLabel((string) ($row['family_relationship'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    /**
     * Conservatively remove only aged binaries belonging to already rejected
     * submissions. This is deliberately not exposed by a web route: the
     * maintenance runner calls it after an explicit retention period and
     * keeps the submission plus its audit history intact.
     *
     * @return array{retention_days:int,apply:bool,eligible:int,deleted:int,missing:int,failed:int,entries:list<array{submission_id:int,kind:string,result:string}>}
     */
    public function cleanupRejectedBinaries(int $retentionDays, bool $apply = false): array
    {
        if ($retentionDays < 7 || $retentionDays > 3650) {
            throw new InvalidArgumentException('rejected_binary_retention_invalid');
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400);
        $rows = $this->repository->fetchAll(
            'SELECT `id`,`identity_id`,`seat_id`,`storage_ref`,`thumbnail_ref` '
            . 'FROM `' . $this->repository->table('submission') . '` '
            . "WHERE `state` = 'REJECTED' AND `reviewed_at` IS NOT NULL AND `reviewed_at` <= ? "
            . 'ORDER BY `id` ASC LIMIT 1000',
            [$cutoff],
        );

        $result = [
            'retention_days' => $retentionDays,
            'apply' => $apply,
            'eligible' => 0,
            'deleted' => 0,
            'missing' => 0,
            'failed' => 0,
            'entries' => [],
        ];
        foreach ($rows as $row) {
            $submissionId = (int) ($row['id'] ?? 0);
            if ($submissionId <= 0) {
                continue;
            }
            foreach (['original' => 'storage_ref', 'thumbnail' => 'thumbnail_ref'] as $kind => $field) {
                try {
                    $path = $this->resolveRef((string) ($row[$field] ?? ''), false);
                } catch (Throwable) {
                    $result['failed']++;
                    $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'INVALID_REFERENCE'];
                    continue;
                }
                if (!is_file($path)) {
                    $result['missing']++;
                    $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'ALREADY_MISSING'];
                    continue;
                }
                if (is_link($path)) {
                    $result['failed']++;
                    $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'UNTRUSTED_PATH'];
                    continue;
                }
                $result['eligible']++;
                if (!$apply) {
                    $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'DRY_RUN'];
                    continue;
                }
                if (!@unlink($path) || is_file($path)) {
                    $result['failed']++;
                    $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'DELETE_FAILED'];
                    continue;
                }
                $this->repository->transaction(function (Repository $repository) use ($row, $submissionId, $kind): void {
                    (new Audit($repository))->append([
                        'actor_kind' => 'SYSTEM_MAINTENANCE',
                        'action' => 'REJECTED_BINARY_CLEANUP',
                        'target_type' => 'SUBMISSION_BINARY',
                        'target_id' => $submissionId . ':' . $kind,
                        'target_identity_id' => (int) ($row['identity_id'] ?? 0),
                        'target_seat_id' => (int) ($row['seat_id'] ?? 0),
                        'old_value' => ['state' => 'REJECTED', 'submission_id' => $submissionId],
                        'new_value' => ['state' => 'REJECTED', 'submission_id' => $submissionId, 'result' => 'BINARY_DELETED'],
                        'reason' => '定期清理超过保留期的已拒绝投稿二进制',
                        'result' => 'SUCCESS',
                    ]);
                });
                $result['deleted']++;
                $result['entries'][] = ['submission_id' => $submissionId, 'kind' => $kind, 'result' => 'DELETED'];
            }
        }

        return $result;
    }

    private static function relationshipLabel(string $relationship): string
    {
        return match (strtoupper($relationship)) {
            'MOTHER' => '母亲',
            'FATHER' => '父亲',
            'SIBLING' => '兄弟姐妹',
            'GRANDPARENT' => '祖父母 / 外祖父母',
            'GUARDIAN' => '监护人',
            'OTHER', 'OTHER_FAMILY' => '其他家庭成员',
            default => $relationship === '' ? '家庭席位' : '家庭成员',
        };
    }

    /** @return list<array<string, mixed>> */
    public function adminList(?string $state = null): array
    {
        $this->requireAdmin();
        global $prefixeTable;
        $where = '';
        $params = [];
        if ($state !== null) {
            if (!in_array($state, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
                throw new InvalidArgumentException('submission_state_invalid');
            }
            $where = 'WHERE s.`state` = ?';
            $params[] = $state;
        }
        $rows = $this->repository->fetchAll(
            'SELECT s.*, i.`roster_code`,i.`real_name`, a.`family_relationship`, '
            . 'u.`username`, p.`piwigo_user_id` AS `family_user_id`, '
            . 'rp.`piwigo_user_id` AS `reviewer_user_id` '
            . 'FROM `' . $this->repository->table('submission') . '` s '
            . 'JOIN `' . $this->repository->table('identity') . '` i ON i.`id` = s.`identity_id` '
            . 'JOIN `' . $this->repository->table('account') . '` a ON a.`id` = s.`account_id` '
            . 'JOIN `' . $this->repository->table('principal') . '` p ON p.`id` = s.`principal_id` '
            . 'LEFT JOIN `' . $this->repository->table('principal') . '` rp ON rp.`id` = s.`reviewed_by_principal_id` '
            . 'LEFT JOIN `' . $prefixeTable . 'users` u ON u.`id` = p.`piwigo_user_id` '
            . $where . ' ORDER BY s.`uploaded_at` DESC,s.`id` DESC LIMIT 500',
            $params,
        );
        foreach ($rows as &$row) {
            $row['state_label'] = match ((string) ($row['state'] ?? '')) {
                'PENDING' => '待审核',
                'APPROVED' => '已通过',
                'REJECTED' => '已拒绝',
                default => '状态异常',
            };
            $row['precision_label'] = ClassIdentityArchiveService::precisionLabel((string) ($row['date_precision'] ?? 'UNKNOWN'));
            $row['relationship_label'] = self::relationshipLabel((string) ($row['family_relationship'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $submissionId): ?array
    {
        $this->requireAdmin();
        return $this->repository->fetchOne(
            'SELECT * FROM `' . $this->repository->table('submission') . '` WHERE `id` = ? LIMIT 1',
            [$submissionId],
        );
    }

    public function stream(int $submissionId, string $kind): never
    {
        $this->requireAdmin();
        if (!in_array($kind, ['thumbnail', 'original'], true)) {
            ClassIdentityHttp::abort(404, '资源不存在');
        }
        $row = $this->find($submissionId);
        if ($row === null) {
            ClassIdentityHttp::abort(404, '资源不存在');
        }
        $ref = $kind === 'thumbnail' ? (string) $row['thumbnail_ref'] : (string) $row['storage_ref'];
        $path = $this->resolveRef($ref, true);
        if (!is_file($path) || is_link($path)) {
            ClassIdentityHttp::abort(404, '资源不存在');
        }
        ClassIdentityHttp::noStore();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($kind === 'thumbnail' ? 'image/jpeg' : (string) $row['mime_type']));
        header('Content-Length: ' . (string) filesize($path));
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $row['original_filename']) ?: 'submission';
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        readfile($path);
        exit;
    }

    /** @return array{class_photo_id:string,piwigo_image_id:int}|null */
    public function review(int $submissionId, int $adminUserId, bool $approve, string $reason, ?int $albumId = null, ?string $archiveDate = null, string $precision = 'UNKNOWN', string $eventLabel = ''): ?array
    {
        $adminContext = $this->requireAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true);
        if ($reason === null) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }
        $precision = self::normalizePrecision($precision);
        $archiveDate = self::normalizeDate($archiveDate, $precision);

        $preflightRow = null;
        $sourcePath = null;
        $thumbnailPath = null;
        $eventLabel = self::boundedText($eventLabel, 190) ?? null;
        $dateSource = self::archiveDateSource($archiveDate, $precision, $eventLabel);
        if ($approve) {
            // Every deterministic/read-only validation happens before the
            // durable reviewer claim. A bad album, missing/corrupt pending
            // original or unavailable Core pipeline therefore cannot strand a
            // PENDING row or invalidate a previously ACTIVE read projection.
            $preflightRow = $this->pendingApprovalCandidate($submissionId);
            $albumId = $this->requireHeritageAlbum($albumId);
            $sourcePath = $this->validatePendingApprovalSource($preflightRow);
            $thumbnailPath = $this->resolveRef((string) $preflightRow['thumbnail_ref'], false);
            $this->requirePiwigoApprovalPipeline();
            if (!is_string($sourcePath) || !is_string($thumbnailPath) || !is_int($albumId)) {
                throw new RuntimeException('submission_approval_preflight_incomplete');
            }
        }

        $row = $this->claimSubmissionForReview($submissionId, $adminContext);

        if (!$approve) {
            $this->repository->transaction(function (Repository $repository) use ($row, $submissionId, $adminContext, $reason): void {
                (new ClassArchivePhotoMappingService($repository))->discardPendingSubmissionMapping($submissionId);
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('submission') . '` SET `state`=\'REJECTED\',`reviewed_at`=UTC_TIMESTAMP(6),'
                    . '`reviewed_by_principal_id`=?,`review_reason`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id`=?',
                    [(int) $adminContext['principal_id'], $reason, $submissionId, (int) $adminContext['principal_id']],
                );
                if ($changed !== 1) {
                    throw new RuntimeException('submission_review_race');
                }
                (new Audit($repository))->append([
                    'actor_principal_id' => (int) $adminContext['principal_id'],
                    'actor_user_id' => (int) $adminContext['piwigo_user_id'],
                    'actor_kind' => 'SYSTEM_ADMIN',
                    'action' => 'SUBMISSION_REJECT',
                    'target_type' => 'SUBMISSION',
                    'target_id' => (string) $submissionId,
                    'target_identity_id' => (int) $row['identity_id'],
                    'target_seat_id' => (int) $row['seat_id'],
                    'old_value' => ['state' => 'PENDING'],
                    'new_value' => ['state' => 'REJECTED'],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            });
            return null;
        }

        try {
            // Re-check the immutable row fingerprint, album boundary and file
            // digest after acquiring the reviewer claim. This closes the
            // preflight/claim TOCTOU window. A failure here is still before any
            // Piwigo MyISAM/file mutation and can be compensated exactly.
            if (!hash_equals(
                self::approvalCandidateFingerprint($preflightRow),
                self::approvalCandidateFingerprint($row),
            )) {
                throw new RuntimeException('submission_approval_preflight_drift');
            }
            if ($this->requireHeritageAlbum($albumId) !== $albumId) {
                throw new RuntimeException('submission_approval_album_drift');
            }
            $claimedSourcePath = $this->validatePendingApprovalSource($row);
            if (!hash_equals($sourcePath, $claimedSourcePath)) {
                throw new RuntimeException('submission_approval_source_drift');
            }
        } catch (Throwable $error) {
            $this->releaseFailedApprovalClaim($row, $adminContext, $reason, $error);
            throw $error;
        }

        $imageId = null;
        try {
            // Piwigo's image/category tables are MyISAM. The durable reviewer
            // claim serializes competing reviews; a failed saga is released
            // with an audited compensation below. The public projection stays
            // on its prior ACTIVE generation until promotePendingMapping()
            // atomically invalidates it with the final InnoDB state change.
            $imageId = add_uploaded_file($sourcePath, (string) $row['original_filename'], null, 0);
            if (!is_int($imageId) && !ctype_digit((string) $imageId)) {
                throw new RuntimeException('piwigo_upload_pipeline_failed');
            }
            $imageId = (int) $imageId;
            if ($imageId <= 0) {
                throw new RuntimeException('piwigo_image_id_invalid');
            }
            associate_images_to_categories([$imageId], [$albumId]);
            $this->chmodApprovedOriginal($imageId);
            [$approvedChecksum, $approvedReference] = $this->approvedMediaReferenceAndChecksum($imageId);
        } catch (Throwable $error) {
            $this->releaseFailedApprovalClaim(
                $row,
                $adminContext,
                $reason,
                $error,
                is_int($imageId) && $imageId > 0 ? $imageId : null,
            );
            throw $error;
        }

        try {
            $mapping = $this->repository->transaction(function (Repository $repository) use ($row, $submissionId, $adminContext, $reason, $imageId, $archiveDate, $precision, $dateSource, $eventLabel, $approvedChecksum, $approvedReference): array {
                $repository->execute(
                    'INSERT INTO `' . $repository->table('archive_image') . '` '
                    . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) '
                    . 'VALUES (?, \'HERITAGE\', ?, ?, \'UNKNOWN\', ?, ?, 1, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$imageId, $archiveDate, $precision, $dateSource, $eventLabel, $submissionId],
                );
                $mapping = (new ClassArchivePhotoMappingService($repository))->promotePendingMapping(
                    $submissionId,
                    $imageId,
                    $approvedChecksum,
                    $approvedReference,
                );
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('submission') . '` SET `state`=\'APPROVED\',`reviewed_at`=UTC_TIMESTAMP(6),'
                    . '`reviewed_by_principal_id`=?,`review_reason`=?,`approved_image_id`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id`=?',
                    [(int) $adminContext['principal_id'], $reason, $imageId, $submissionId, (int) $adminContext['principal_id']],
                );
                if ($changed !== 1) {
                    throw new RuntimeException('submission_review_race');
                }
                (new Audit($repository))->append([
                    'actor_principal_id' => (int) $adminContext['principal_id'],
                    'actor_user_id' => (int) $adminContext['piwigo_user_id'],
                    'actor_kind' => 'SYSTEM_ADMIN',
                    'action' => 'SUBMISSION_APPROVE',
                    'target_type' => 'SUBMISSION',
                    'target_id' => (string) $submissionId,
                    'target_identity_id' => (int) $row['identity_id'],
                    'target_seat_id' => (int) $row['seat_id'],
                    'old_value' => ['state' => 'PENDING'],
                    'new_value' => ['state' => 'APPROVED', 'piwigo_image_id' => $imageId, 'era' => 'HERITAGE', 'date_precision' => $precision, 'date_source' => $dateSource],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return $mapping;
            });
        } catch (Throwable $error) {
            $this->releaseFailedApprovalClaim($row, $adminContext, $reason, $error, $imageId);
            throw $error;
        }

        // Piwigo persists a per-user gallery cache. A Family account that
        // browsed an otherwise-empty HERITAGE root before this approval can
        // retain that root in its cached forbidden-category list even though
        // its role already has the correct group ACL. Invalidate after the
        // approved image and Archive metadata are committed, so the next
        // refresh rebuilds permissions from the current association. This is
        // a visibility-cache repair, not an authorization bypass.
        invalidate_user_cache();

        $this->safeUnlink($thumbnailPath);
        if (($mapping['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE) {
            throw new RuntimeException('class_archive_submission_projection_mapping_missing');
        }
        if (class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
            $warmupQueued = \ClassArchiveDerivativeWarmupQueue::enqueueBestEffort(
                (string) $mapping['class_photo_id'],
                $imageId,
            );
            if ($warmupQueued && class_exists('ClassArchiveDerivativeCacheWarmer', false)) {
                // This bounded resize runs only after the approval transaction
                // committed, inside the explicit administrator write boundary.
                // Failure preserves APPROVED truth and its durable marker;
                // member reads remain cache-only and return a generic 503.
                \ClassArchiveDerivativeCacheWarmer::warmBestEffort(
                    (string) $mapping['class_photo_id'],
                    $imageId,
                );
            }
        }
        return [
            'class_photo_id' => (string) $mapping['class_photo_id'],
            'piwigo_image_id' => $imageId,
        ];
    }

    /** @return array<string,mixed> */
    private function pendingApprovalCandidate(int $submissionId): array
    {
        $row = $this->repository->fetchOne(
            'SELECT * FROM `' . $this->repository->table('submission') . '` '
                . 'WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id` IS NULL LIMIT 1',
            [$submissionId],
        );
        if ($row === null) {
            throw new InvalidArgumentException('submission_not_pending');
        }
        return $row;
    }

    /** @param array<string,mixed> $adminContext @return array<string,mixed> */
    private function claimSubmissionForReview(int $submissionId, array $adminContext): array
    {
        return $this->repository->transaction(function (Repository $repository) use ($submissionId, $adminContext): array {
            $candidate = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('submission') . '` '
                    . 'WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id` IS NULL FOR UPDATE',
                [$submissionId],
            );
            if ($candidate === null) {
                throw new InvalidArgumentException('submission_not_pending');
            }
            $claimed = $repository->execute(
                'UPDATE `' . $repository->table('submission') . '` SET `reviewed_by_principal_id`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id` IS NULL',
                [(int) $adminContext['principal_id'], $submissionId],
            );
            if ($claimed !== 1) {
                throw new InvalidArgumentException('submission_not_pending');
            }
            $candidate['reviewed_by_principal_id'] = (int) $adminContext['principal_id'];
            return $candidate;
        });
    }

    /** @param array<string,mixed> $row */
    private function validatePendingApprovalSource(array $row): string
    {
        $path = $this->resolveRef((string) ($row['storage_ref'] ?? ''), true);
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('submission_original_missing');
        }

        $expectedSize = (int) ($row['byte_size'] ?? 0);
        $actualSize = filesize($path);
        if ($expectedSize <= 0 || !is_int($actualSize) || $actualSize !== $expectedSize) {
            throw new RuntimeException('submission_original_size_drift');
        }
        $expectedChecksum = $row['sha256'] ?? null;
        $actualChecksum = hash_file('sha256', $path, true);
        if (
            !is_string($expectedChecksum)
            || strlen($expectedChecksum) !== 32
            || !is_string($actualChecksum)
            || !hash_equals($expectedChecksum, $actualChecksum)
        ) {
            throw new RuntimeException('submission_original_checksum_drift');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_file($finfo, $path);
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        $recordedMime = (string) ($row['mime_type'] ?? '');
        $recordedExtension = strtolower((string) ($row['extension'] ?? ''));
        if (
            !is_string($mime)
            || !isset(self::MIME_EXTENSIONS[$mime])
            || !hash_equals($recordedMime, $mime)
            || !hash_equals($recordedExtension, self::MIME_EXTENSIONS[$mime])
        ) {
            throw new RuntimeException('submission_original_format_drift');
        }
        $info = @getimagesize($path);
        if (
            !is_array($info)
            || (int) ($info[0] ?? 0) !== (int) ($row['width'] ?? 0)
            || (int) ($info[1] ?? 0) !== (int) ($row['height'] ?? 0)
        ) {
            throw new RuntimeException('submission_original_dimensions_drift');
        }
        return $path;
    }

    /** @param array<string,mixed> $row */
    private static function approvalCandidateFingerprint(array $row): string
    {
        $checksum = $row['sha256'] ?? null;
        if (!is_string($checksum) || strlen($checksum) !== 32) {
            throw new RuntimeException('submission_original_checksum_invalid');
        }
        return hash('sha256', json_encode([
            'id' => (int) ($row['id'] ?? 0),
            'identity_id' => (int) ($row['identity_id'] ?? 0),
            'seat_id' => (int) ($row['seat_id'] ?? 0),
            'account_id' => (int) ($row['account_id'] ?? 0),
            'principal_id' => (int) ($row['principal_id'] ?? 0),
            'original_filename' => (string) ($row['original_filename'] ?? ''),
            'storage_ref' => (string) ($row['storage_ref'] ?? ''),
            'thumbnail_ref' => (string) ($row['thumbnail_ref'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'extension' => (string) ($row['extension'] ?? ''),
            'byte_size' => (int) ($row['byte_size'] ?? 0),
            'sha256' => bin2hex($checksum),
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function requirePiwigoApprovalPipeline(): void
    {
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
        if (!function_exists('add_uploaded_file') || !function_exists('associate_images_to_categories') || !function_exists('invalidate_user_cache')) {
            throw new RuntimeException('piwigo_upload_pipeline_unavailable');
        }
    }

    /**
     * Release only the exact reviewer claim owned by this failed attempt. The
     * failed audit and release share one transaction; a database/audit failure
     * therefore cannot silently report compensation that did not persist.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $adminContext
     */
    private function releaseFailedApprovalClaim(
        array $row,
        array $adminContext,
        string $reason,
        Throwable $error,
        ?int $piwigoImageId = null,
    ): void {
        $submissionId = (int) ($row['id'] ?? 0);
        $reviewerId = (int) ($adminContext['principal_id'] ?? 0);
        if ($submissionId <= 0 || $reviewerId <= 0) {
            throw new RuntimeException('submission_review_claim_compensation_invalid');
        }
        $errorCode = self::approvalFailureCode($error);
        $this->repository->transaction(function (Repository $repository) use (
            $row,
            $adminContext,
            $reason,
            $submissionId,
            $reviewerId,
            $piwigoImageId,
            $errorCode,
        ): void {
            $current = $repository->fetchOne(
                'SELECT `state`,`reviewed_by_principal_id` FROM `' . $repository->table('submission') . '` WHERE `id`=? FOR UPDATE',
                [$submissionId],
            );
            if ($current === null) {
                throw new RuntimeException('submission_review_claim_compensation_missing');
            }
            if (($current['state'] ?? null) === 'APPROVED' && (int) ($current['reviewed_by_principal_id'] ?? 0) === $reviewerId) {
                // A commit acknowledgement can fail after the server committed.
                // Never turn an already-approved review back into PENDING.
                return;
            }
            if (($current['state'] ?? null) !== 'PENDING' || (int) ($current['reviewed_by_principal_id'] ?? 0) !== $reviewerId) {
                throw new RuntimeException('submission_review_claim_compensation_conflict');
            }
            $released = $repository->execute(
                'UPDATE `' . $repository->table('submission') . '` SET `reviewed_by_principal_id`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `id`=? AND `state`=\'PENDING\' AND `reviewed_by_principal_id`=?',
                [$submissionId, $reviewerId],
            );
            if ($released !== 1) {
                throw new RuntimeException('submission_review_claim_compensation_race');
            }
            $newValue = ['state' => 'PENDING', 'result' => 'REVIEW_CLAIM_RELEASED'];
            if ($piwigoImageId !== null && $piwigoImageId > 0) {
                $newValue['piwigo_image_id'] = $piwigoImageId;
            }
            (new Audit($repository))->append([
                'actor_principal_id' => $reviewerId,
                'actor_user_id' => (int) ($adminContext['piwigo_user_id'] ?? 0),
                'actor_kind' => 'SYSTEM_ADMIN',
                'action' => 'SUBMISSION_APPROVE_ABORT',
                'target_type' => 'SUBMISSION',
                'target_id' => (string) $submissionId,
                'target_identity_id' => (int) ($row['identity_id'] ?? 0),
                'target_seat_id' => (int) ($row['seat_id'] ?? 0),
                'old_value' => ['state' => 'PENDING'],
                'new_value' => $newValue,
                'reason' => $reason,
                'result' => 'FAILED',
                'error_code' => $errorCode,
            ]);
        });
    }

    private static function approvalFailureCode(Throwable $error): string
    {
        $message = $error->getMessage();
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $message) === 1) {
            return strtoupper($message);
        }
        return 'SUBMISSION_APPROVE_FAILED';
    }

    /** @return array{original_name:string,mime:string,extension:string,size:int,sha256:string,width:int,height:int,tmp:string} */
    private function validateUpload(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('family_submission_upload_invalid');
        }
        $tmp = $file['tmp_name'] ?? null;
        $size = (int) ($file['size'] ?? 0);
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('family_submission_upload_invalid');
        }
        $name = $file['name'] ?? null;
        if (!is_string($name) || $name === '' || strlen($name) > 255 || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidArgumentException('family_submission_filename_invalid');
        }
        $originalName = trim($name);
        if ($originalName === '' || $originalName === '.' || $originalName === '..' || str_contains($originalName, '..')) {
            throw new InvalidArgumentException('family_submission_filename_invalid');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? false : finfo_file($finfo, $tmp);
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('family_submission_mime_invalid');
        }
        $info = @getimagesize($tmp);
        if (!is_array($info) || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            throw new InvalidArgumentException('family_submission_image_invalid');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('family_submission_dimensions_invalid');
        }
        $sha256 = hash_file('sha256', $tmp, true);
        if (!is_string($sha256) || strlen($sha256) !== 32) {
            throw new RuntimeException('family_submission_hash_failed');
        }
        return [$originalName, $mime, self::MIME_EXTENSIONS[$mime], $size, $sha256, $width, $height, $tmp];
    }

    private function createThumbnail(string $source, string $destination, int $width, int $height): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            throw new RuntimeException('family_submission_thumbnail_unavailable');
        }
        $bytes = file_get_contents($source);
        $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            throw new InvalidArgumentException('family_submission_image_invalid');
        }
        $scale = min(640 / max(1, $width), 640 / max(1, $height), 1.0);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($thumb === false) {
            imagedestroy($image);
            throw new RuntimeException('family_submission_thumbnail_unavailable');
        }
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $ok = imagejpeg($thumb, $destination, 82);
        imagedestroy($thumb);
        imagedestroy($image);
        if (!$ok || !is_file($destination)) {
            throw new RuntimeException('family_submission_thumbnail_write_failed');
        }
    }

    private function ensurePendingRoot(): void
    {
        if (is_link($this->pendingRoot)) {
            throw new RuntimeException('family_submission_storage_untrusted');
        }
        if (!is_dir($this->pendingRoot) && !mkdir($this->pendingRoot, 0770, true) && !is_dir($this->pendingRoot)) {
            throw new RuntimeException('family_submission_storage_unavailable');
        }
        @chmod($this->pendingRoot, 0770);
        $real = realpath($this->pendingRoot);
        if ($real === false || !hash_equals(str_replace('\\', '/', $real), str_replace('\\', '/', $this->pendingRoot))) {
            throw new RuntimeException('family_submission_storage_untrusted');
        }
    }

    private function resolveRef(string $ref, bool $mustExist): string
    {
        if (!preg_match('#\Aclass_identity_pending/[a-f0-9]{48}\.(?:jpg|jpeg|png|webp)\z#D', $ref)) {
            throw new RuntimeException('family_submission_storage_ref_invalid');
        }
        $this->ensurePendingRoot();
        $relative = substr($ref, strlen('class_identity_pending/'));
        $path = $this->pendingRoot . DIRECTORY_SEPARATOR . $relative;
        if ($mustExist && (!is_file($path) || is_link($path))) {
            throw new RuntimeException('family_submission_storage_missing');
        }
        if (is_link($path)) {
            throw new RuntimeException('family_submission_storage_untrusted');
        }
        return $path;
    }

    /** @return array<string, mixed> */
    private function requireAdmin(?int $userId = null): array
    {
        global $user;
        $resolved = $userId ?? (int) ($user['id'] ?? 0);
        $context = Access::resolveAuthorizationContext($resolved);
        if ($context === null || ($context['role'] ?? null) !== Access::ROLE_SYSTEM_ADMIN) {
            throw new RuntimeException('class_identity_system_admin_required');
        }
        return $context;
    }

    private function requireHeritageAlbum(?int $albumId): int
    {
        global $prefixeTable;
        $root = $this->repository->fetchOne(
            'SELECT `id`,`uppercats` FROM `' . $prefixeTable . 'categories` WHERE `permalink` = ? LIMIT 1',
            ['class-archive-heritage'],
        );
        if ($root === null) {
            throw new RuntimeException('heritage_album_missing');
        }
        $rootId = (int) $root['id'];
        $selected = $albumId ?? $rootId;
        $category = $this->repository->fetchOne(
            'SELECT `id`,`uppercats`,`status` FROM `' . $prefixeTable . 'categories` WHERE `id` = ? LIMIT 1',
            [$selected],
        );
        if ($category === null || (int) $category['id'] !== $selected) {
            throw new InvalidArgumentException('heritage_album_invalid');
        }
        $uppercats = ',' . (string) ($category['uppercats'] ?? '') . ',';
        if ($selected !== $rootId && !str_contains($uppercats, ',' . $rootId . ',')) {
            throw new InvalidArgumentException('heritage_album_invalid');
        }
        return $selected;
    }

    private function chmodApprovedOriginal(int $imageId): void
    {
        global $prefixeTable;
        $row = $this->repository->fetchOne('SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id` = ? LIMIT 1', [$imageId]);
        if ($row === null) {
            throw new RuntimeException('piwigo_image_missing');
        }
        $path = PHPWG_ROOT_PATH . ltrim((string) $row['path'], './');
        if (!is_file($path) || is_link($path) || !@chmod($path, 0660)) {
            throw new RuntimeException('piwigo_original_permissions_failed');
        }
        clearstatcache(true, $path);
        if (((int) (@fileperms($path) & 0777)) !== 0660) {
            throw new RuntimeException('piwigo_original_permissions_failed');
        }
    }

    /** @return array{0:string,1:string} SHA-256 hex and safe Piwigo reference. */
    private function approvedMediaReferenceAndChecksum(int $imageId): array
    {
        global $prefixeTable;

        $row = $this->repository->fetchOne(
            'SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id` = ? LIMIT 1',
            [$imageId],
        );
        if ($row === null) {
            throw new RuntimeException('piwigo_image_missing');
        }
        $reference = ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
        $path = PHPWG_ROOT_PATH . $reference;
        $root = PHPWG_ROOT_PATH . (str_starts_with($reference, 'upload/') ? 'upload' : 'galleries');
        $rootReal = realpath($root);
        $fileReal = realpath($path);
        if ($rootReal === false || $fileReal === false || is_link($path) || !is_file($fileReal)) {
            throw new RuntimeException('piwigo_image_media_unavailable');
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $fileReal), $rootPrefix)) {
            throw new RuntimeException('piwigo_image_media_untrusted');
        }
        $checksum = hash_file('sha256', $fileReal);
        if (!is_string($checksum)) {
            throw new RuntimeException('piwigo_image_checksum_failed');
        }
        ClassArchivePhoto::checksumToBinary($checksum);

        return [$checksum, $reference];
    }

    private function safeUnlink(string $path): void
    {
        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    private static function normalizePrecision(string $precision): string
    {
        $precision = strtoupper(trim($precision));
        if (!in_array($precision, self::PRECISIONS, true)) {
            throw new InvalidArgumentException('archive_date_precision_invalid');
        }
        return $precision;
    }

    private static function normalizeDate(?string $date, string $precision): ?string
    {
        $date = is_string($date) ? trim($date) : '';
        if ($date === '') {
            return null;
        }
        if ($precision === 'MONTH' && preg_match('/\A\d{4}-\d{2}\z/D', $date)) {
            $date .= '-01';
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)) {
            throw new InvalidArgumentException('archive_date_invalid');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('archive_date_invalid');
        }
        if (in_array($precision, ['TERM', 'EVENT_ONLY', 'UNKNOWN'], true)) {
            throw new InvalidArgumentException('archive_date_precision_mismatch');
        }
        return $date;
    }

    private static function archiveDateSource(?string $archiveDate, string $precision, ?string $eventLabel): string
    {
        if ($archiveDate !== null && in_array($precision, ['EXACT', 'DAY', 'MONTH', 'YEAR'], true)) {
            return 'ARCHIVE_CONFIRMED';
        }
        if ($eventLabel !== null && in_array($precision, ['TERM', 'EVENT_ONLY'], true)) {
            return 'EVENT_INFERENCE';
        }
        return 'UNKNOWN';
    }

    private static function boundedText(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $max || str_contains($value, "\0")) {
            throw new InvalidArgumentException('input_too_long');
        }
        return $value;
    }
}
