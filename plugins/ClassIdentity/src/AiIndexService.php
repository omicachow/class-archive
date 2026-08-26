<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Durable, metadata-only control plane for the optional local Immich index.
 *
 * The service never opens an original, calls Immich, starts a model, or
 * discovers a network endpoint.  It only records checksum-bound work for a
 * separately isolated worker.  This keeps the ordinary photo read path free
 * of inference and makes an unavailable private Immich runtime explicit
 * rather than silently returning an unfiltered library.
 */
final class AiIndexService
{
    public const FACE_PENDING = 'PENDING';
    public const FACE_INDEXED = 'INDEXED';
    public const FACE_UNAVAILABLE = 'UNAVAILABLE';
    public const FACE_FAILED = 'FAILED';
    public const FACE_STALE = 'STALE';
    public const FACE_REMOVED = 'REMOVED';

    public const SEARCH_PENDING = 'PENDING';
    public const SEARCH_INDEXED = 'INDEXED';
    public const SEARCH_UNAVAILABLE = 'UNAVAILABLE';
    public const SEARCH_FAILED = 'FAILED';
    public const SEARCH_STALE = 'STALE';
    public const SEARCH_REMOVED = 'REMOVED';

    public const JOB_INDEX_ASSET = 'INDEX_ASSET';
    public const JOB_DELETE_ASSET = 'DELETE_ASSET';
    public const JOB_REINDEX_MODEL = 'REINDEX_MODEL';

    public const TRIGGER_NEW_PHOTO = 'NEW_PHOTO';
    public const TRIGGER_PIXEL_CHANGED = 'PIXEL_CHANGED';
    public const TRIGGER_PHOTO_DELETED = 'PHOTO_DELETED';
    public const TRIGGER_MODEL_CHANGED = 'MODEL_CHANGED';
    public const TRIGGER_ADMIN_REINDEX = 'ADMIN_REINDEX';
    public const TRIGGER_RECONCILIATION = 'RECONCILIATION';

    public const JOB_PENDING = 'PENDING';
    public const JOB_RUNNING = 'RUNNING';
    public const JOB_UNAVAILABLE = 'UNAVAILABLE';
    public const JOB_FAILED = 'FAILED';
    public const JOB_COMPLETE = 'COMPLETE';
    public const JOB_CANCELLED = 'CANCELLED';

    private const MAX_BULK_ITEMS = 100000;

    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Queue face and search work for a newly published canonical photo.
     * This is idempotent for the same active checksum.
     *
     * @return array<string,mixed>
     */
    public function enqueueNewPhoto(string $classPhotoId): array
    {
        return $this->enqueueActivePhoto($classPhotoId, self::TRIGGER_NEW_PHOTO, self::JOB_INDEX_ASSET, null);
    }

    /**
     * Queue a replacement byte stream.  The current canonical checksum is
     * read under lock; callers cannot choose an arbitrary asset/checksum.
     *
     * @return array<string,mixed>
     */
    public function enqueuePixelChange(string $classPhotoId): array
    {
        return $this->enqueueActivePhoto($classPhotoId, self::TRIGGER_PIXEL_CHANGED, self::JOB_INDEX_ASSET, null);
    }

    /**
     * Safe post-import catch-up.  It reads only canonical identifiers and
     * checksums already in Class Archive; it never reads a staging file or
     * starts a model.  Re-running it is a no-op for same-checksum rows.
     *
     * @return array{queued:int,unchanged:int,scanned:int}
     */
    public function enqueueImportedActivePhotos(): array
    {
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id` FROM `' . $this->repository->table('photo') . '` '
                . 'WHERE `state`=? ORDER BY `class_photo_id` ASC LIMIT ' . self::MAX_BULK_ITEMS,
            [ClassArchivePhoto::STATE_ACTIVE],
        );
        if (count($rows) >= self::MAX_BULK_ITEMS) {
            throw new \RuntimeException('class_archive_ai_index_import_scan_too_large');
        }
        $queued = 0;
        $unchanged = 0;
        foreach ($rows as $row) {
            if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16) {
                throw new \RuntimeException('class_archive_ai_index_photo_row_invalid');
            }
            $result = $this->enqueueNewPhoto(DomainSupport::binaryToId((string) $row['class_photo_id']));
            if (($result['queued'] ?? false) === true) {
                ++$queued;
            } else {
                ++$unchanged;
            }
        }
        return ['queued' => $queued, 'unchanged' => $unchanged, 'scanned' => count($rows)];
    }

    /**
     * Explicit reconciliation hook for an already-active photo whose durable
     * index row was missing or stale.  Maintenance only reports divergence;
     * this method is intentionally an opt-in write path.
     *
     * @return array<string,mixed>
     */
    public function enqueueReconciliation(string $classPhotoId): array
    {
        return $this->enqueueActivePhoto($classPhotoId, self::TRIGGER_RECONCILIATION, self::JOB_INDEX_ASSET, null);
    }

    /**
     * Queue a checksum-bound removal after a canonical photo is retired.
     * A worker must still compare the job checksum before touching any
     * external asset, so a late job can never delete a replacement photo.
     *
     * @return array<string,mixed>
     */
    public function enqueuePhotoDeletion(string $classPhotoId): array
    {
        $photoId = DomainSupport::idToBinary($classPhotoId);

        return $this->repository->transaction(function (Repository $repository) use ($classPhotoId, $photoId): array {
            $photo = $repository->fetchOne(
                'SELECT `class_photo_id`,`media_checksum`,`immich_asset_id`,`state` FROM `'
                    . $repository->table('photo') . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoId],
            );
            if ($photo === null || !is_string($photo['media_checksum'] ?? null) || strlen((string) $photo['media_checksum']) !== 32) {
                throw new \RuntimeException('class_archive_ai_index_photo_missing');
            }

            $indexTable = DomainSupport::table($repository, 'ai_asset_index');
            $jobsTable = DomainSupport::table($repository, 'ai_index_job');
            $checksum = (string) $photo['media_checksum'];
            $assetId = ClassArchivePhoto::normalizeImmichAssetId(
                is_string($photo['immich_asset_id'] ?? null) ? (string) $photo['immich_asset_id'] : null,
            );
            $existing = $repository->fetchOne(
                'SELECT `class_photo_id`,`source_checksum`,`immich_asset_id` FROM `'
                    . $indexTable . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoId],
            );
            if ($existing === null) {
                $repository->execute(
                    'INSERT INTO `' . $indexTable . '` ('
                        . '`class_photo_id`,`source_checksum`,`immich_asset_id`,`face_state`,`search_state`,`created_at`,`updated_at`'
                        . ') VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$photoId, $checksum, $assetId, self::FACE_REMOVED, self::SEARCH_REMOVED],
                );
            } else {
                $repository->execute(
                    'UPDATE `' . $indexTable . '` SET `source_checksum`=?,`immich_asset_id`=COALESCE(?,`immich_asset_id`),'
                        . '`face_state`=?,`search_state`=?,`indexed_at`=NULL,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                        . 'WHERE `class_photo_id`=?',
                    [$checksum, $assetId, self::FACE_REMOVED, self::SEARCH_REMOVED, $photoId],
                );
            }
            // Any stale in-flight indexing attempt must not outlive a delete
            // request.  A worker claims only PENDING jobs and validates the
            // photo checksum again before any side effect.
            $repository->execute(
                'UPDATE `' . $jobsTable . '` SET `state`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id`=? AND `state` IN (?,?)',
                [self::JOB_CANCELLED, $photoId, self::JOB_PENDING, self::JOB_RUNNING],
            );
            $job = $this->ensureJob(
                $repository,
                $photoId,
                $checksum,
                self::JOB_DELETE_ASSET,
                self::TRIGGER_PHOTO_DELETED,
            );

            return $this->hydrateJob($job) + ['class_photo_id' => $classPhotoId, 'queued' => true];
        });
    }

    /**
     * Queue a model-version transition across active canonical photos.  It
     * does not invoke a model and does not contact Immich.  The bounded loop
     * is intentionally called from explicit operator/maintenance code only.
     *
     * @return array{queued:int,unchanged:int,scanned:int}
     */
    public function enqueueModelChange(
        string $faceModelName,
        string $faceModelRevision,
        string $searchModelName,
        string $searchModelRevision,
    ): array {
        $models = $this->normalizeModels($faceModelName, $faceModelRevision, $searchModelName, $searchModelRevision);
        $rows = $this->repository->fetchAll(
            'SELECT `class_photo_id` FROM `' . $this->repository->table('photo') . '` '
                . 'WHERE `state`=? ORDER BY `class_photo_id` ASC LIMIT ' . self::MAX_BULK_ITEMS,
            [ClassArchivePhoto::STATE_ACTIVE],
        );
        if (count($rows) >= self::MAX_BULK_ITEMS) {
            throw new \RuntimeException('class_archive_ai_index_model_change_too_large');
        }
        $queued = 0;
        $unchanged = 0;
        foreach ($rows as $row) {
            $binary = $row['class_photo_id'] ?? null;
            if (!is_string($binary) || strlen($binary) !== 16) {
                throw new \RuntimeException('class_archive_ai_index_photo_row_invalid');
            }
            $result = $this->enqueueActivePhoto(
                DomainSupport::binaryToId($binary),
                self::TRIGGER_MODEL_CHANGED,
                self::JOB_REINDEX_MODEL,
                $models,
            );
            if (($result['queued'] ?? false) === true) {
                ++$queued;
            } else {
                ++$unchanged;
            }
        }

        return ['queued' => $queued, 'unchanged' => $unchanged, 'scanned' => count($rows)];
    }

    /**
     * System-admin requested reindex. A null photo id is a deliberate whole
     * library request, audit-backed once rather than once per photo.
     *
     * @return array{queued:int,unchanged:int,scanned:int}
     */
    public function requestAdminReindex(int $adminUserId, ?string $classPhotoId, string $reason): array
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $ids = [];
        if ($classPhotoId !== null) {
            $ids[] = $classPhotoId;
        } else {
            $rows = $this->repository->fetchAll(
                'SELECT `class_photo_id` FROM `' . $this->repository->table('photo') . '` '
                    . 'WHERE `state`=? ORDER BY `class_photo_id` ASC LIMIT ' . self::MAX_BULK_ITEMS,
                [ClassArchivePhoto::STATE_ACTIVE],
            );
            if (count($rows) >= self::MAX_BULK_ITEMS) {
                throw new \RuntimeException('class_archive_ai_index_admin_reindex_too_large');
            }
            foreach ($rows as $row) {
                if (!is_string($row['class_photo_id'] ?? null)) {
                    throw new \RuntimeException('class_archive_ai_index_photo_row_invalid');
                }
                $ids[] = DomainSupport::binaryToId((string) $row['class_photo_id']);
            }
        }

        $queued = 0;
        $unchanged = 0;
        foreach ($ids as $id) {
            $result = $this->enqueueActivePhoto($id, self::TRIGGER_ADMIN_REINDEX, self::JOB_REINDEX_MODEL, null);
            if (($result['queued'] ?? false) === true) {
                ++$queued;
            } else {
                ++$unchanged;
            }
        }
        (new Audit($this->repository))->append(DomainSupport::auditActor($admin) + [
            'action' => 'AI_INDEX_REINDEX_REQUESTED',
            'target_type' => 'AI_INDEX',
            'target_id' => $classPhotoId ?? 'ALL_ACTIVE',
            'new_value' => ['count' => $queued, 'state' => self::JOB_PENDING],
            'reason' => $reason,
            'result' => 'SUCCESS',
        ]);

        return ['queued' => $queued, 'unchanged' => $unchanged, 'scanned' => count($ids)];
    }

    /**
     * Claim one pending worker job.  The return value deliberately contains
     * no filesystem path, Piwigo URL, original filename or browser-visible
     * ID beyond the canonical UUID and expected checksum required for a
     * future isolated worker to verify its own trusted source.
     *
     * @return array<string,mixed>|null
     */
    public function claimNextJob(): ?array
    {
        self::requirePrivateWorker();
        return $this->repository->transaction(function (Repository $repository): ?array {
            $table = DomainSupport::table($repository, 'ai_index_job');
            $row = $repository->fetchOne(
                'SELECT `job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`,`state`,`attempt_count`,`not_before` '
                    . 'FROM `' . $table . '` WHERE `state`=? AND `not_before`<=UTC_TIMESTAMP(6) '
                    . 'ORDER BY `created_at` ASC,`job_id` ASC LIMIT 1 FOR UPDATE',
                [self::JOB_PENDING],
            );
            if ($row === null) {
                return null;
            }
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`attempt_count`=`attempt_count`+1,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `job_id`=? AND `state`=?',
                [self::JOB_RUNNING, (string) $row['job_id'], self::JOB_PENDING],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_ai_index_job_claim_race');
            }
            $row['state'] = self::JOB_RUNNING;
            $row['attempt_count'] = (int) ($row['attempt_count'] ?? 0) + 1;
            return $this->hydrateJob($row);
        });
    }

    /**
     * Accept a trusted-worker completion only if the target photo is still
     * active and its checksum remains the one this job was created for.
     * This protects against late jobs after source replacement or deletion.
     */
    public function completeIndexJob(
        string $jobId,
        string $immichAssetId,
        string $faceModelName,
        string $faceModelRevision,
        string $searchModelName,
        string $searchModelRevision,
    ): void {
        self::requirePrivateWorker();
        $jobBinary = DomainSupport::idToBinary($jobId);
        $assetId = ClassArchivePhoto::normalizeImmichAssetId($immichAssetId);
        if ($assetId === null) {
            throw new \InvalidArgumentException('class_archive_ai_index_asset_invalid');
        }
        $models = $this->normalizeModels($faceModelName, $faceModelRevision, $searchModelName, $searchModelRevision);

        $this->repository->transaction(function (Repository $repository) use ($jobBinary, $assetId, $models): void {
            $job = $this->lockedIndexJob($repository, $jobBinary);
            if ((string) $job['job_kind'] !== self::JOB_INDEX_ASSET && (string) $job['job_kind'] !== self::JOB_REINDEX_MODEL) {
                throw new \RuntimeException('class_archive_ai_index_job_kind_invalid');
            }
            $photoId = $job['class_photo_id'] ?? null;
            $checksum = $job['expected_checksum'] ?? null;
            if (!is_string($photoId) || !is_string($checksum) || strlen($photoId) !== 16 || strlen($checksum) !== 32) {
                throw new \RuntimeException('class_archive_ai_index_job_target_invalid');
            }
            $photo = $repository->fetchOne(
                'SELECT `media_checksum`,`state` FROM `' . $repository->table('photo') . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoId],
            );
            if ($photo === null || (string) ($photo['state'] ?? '') !== ClassArchivePhoto::STATE_ACTIVE
                || !is_string($photo['media_checksum'] ?? null) || !hash_equals($checksum, (string) $photo['media_checksum'])
            ) {
                $this->cancelJobForChecksumDrift($repository, $jobBinary);
                throw new \RuntimeException('class_archive_ai_index_job_checksum_drift');
            }
            $index = DomainSupport::table($repository, 'ai_asset_index');
            $changed = $repository->execute(
                'UPDATE `' . $index . '` SET `immich_asset_id`=?,`face_state`=?,`search_state`=?,'
                    . '`face_model_name`=?,`face_model_revision`=?,`search_model_name`=?,`search_model_revision`=?,'
                    . '`indexed_at`=UTC_TIMESTAMP(6),`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id`=? AND `source_checksum`=?',
                [
                    $assetId, self::FACE_INDEXED, self::SEARCH_INDEXED,
                    $models['face_model_name'], $models['face_model_revision'],
                    $models['search_model_name'], $models['search_model_revision'],
                    $photoId, $checksum,
                ],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_ai_index_state_missing');
            }
            $jobs = DomainSupport::table($repository, 'ai_index_job');
            $changed = $repository->execute(
                'UPDATE `' . $jobs . '` SET `state`=?,`last_error_code`=NULL,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `job_id`=? AND `state`=?',
                [self::JOB_COMPLETE, $jobBinary, self::JOB_RUNNING],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_ai_index_job_completion_race');
            }
        });
    }

    /**
     * Complete a deletion job after an isolated worker has removed its own
     * external cache/index reference.  The Class Archive photo row is never
     * removed by this service; archive/media lifecycle remains authoritative.
     */
    public function completeDeletionJob(string $jobId): void
    {
        self::requirePrivateWorker();
        $jobBinary = DomainSupport::idToBinary($jobId);
        $this->repository->transaction(function (Repository $repository) use ($jobBinary): void {
            $job = $this->lockedIndexJob($repository, $jobBinary);
            if ((string) $job['job_kind'] !== self::JOB_DELETE_ASSET) {
                throw new \RuntimeException('class_archive_ai_index_job_kind_invalid');
            }
            $table = DomainSupport::table($repository, 'ai_index_job');
            $changed = $repository->execute(
                'UPDATE `' . $table . '` SET `state`=?,`last_error_code`=NULL,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `job_id`=? AND `state`=?',
                [self::JOB_COMPLETE, $jobBinary, self::JOB_RUNNING],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_ai_index_job_completion_race');
            }
        });
    }

    /** Mark a claimed job unavailable without retrying it on ordinary reads. */
    public function markJobUnavailable(string $jobId, string $errorCode): void
    {
        $this->finishNonSuccessJob($jobId, self::JOB_UNAVAILABLE, $errorCode, self::FACE_UNAVAILABLE, self::SEARCH_UNAVAILABLE);
    }

    /** Mark a claimed job failed without retrying it on ordinary reads. */
    public function markJobFailed(string $jobId, string $errorCode): void
    {
        $this->finishNonSuccessJob($jobId, self::JOB_FAILED, $errorCode, self::FACE_FAILED, self::SEARCH_FAILED);
    }

    /**
     * Read-only health report used by maintenance/System Health.  It performs
     * no enqueue, retry, network request, model load, or filesystem access.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $indexTable = DomainSupport::table($this->repository, 'ai_asset_index');
        $jobsTable = DomainSupport::table($this->repository, 'ai_index_job');
        $assetRows = $this->repository->fetchAll(
            'SELECT `face_state`,`search_state`,COUNT(*) AS `count` FROM `' . $indexTable . '` '
                . 'GROUP BY `face_state`,`search_state` ORDER BY `face_state`,`search_state`',
        );
        $jobRows = $this->repository->fetchAll(
            'SELECT `state`,COUNT(*) AS `count` FROM `' . $jobsTable . '` GROUP BY `state` ORDER BY `state`',
        );
        $assets = [];
        foreach ($assetRows as $row) {
            $face = (string) ($row['face_state'] ?? '');
            $search = (string) ($row['search_state'] ?? '');
            if (!in_array($face, self::indexStates(), true) || !in_array($search, self::indexStates(), true)) {
                throw new \RuntimeException('class_archive_ai_index_state_invalid');
            }
            $assets[$face . ':' . $search] = (int) ($row['count'] ?? 0);
        }
        $jobs = array_fill_keys(self::jobStates(), 0);
        foreach ($jobRows as $row) {
            $state = (string) ($row['state'] ?? '');
            if (!array_key_exists($state, $jobs)) {
                throw new \RuntimeException('class_archive_ai_index_job_state_invalid');
            }
            $jobs[$state] = (int) ($row['count'] ?? 0);
        }

        $workerConfigured = self::privateWorkerConfigured();
        $queuedOrRunning = $jobs[self::JOB_PENDING] + $jobs[self::JOB_RUNNING];
        $open = $queuedOrRunning + $jobs[self::JOB_UNAVAILABLE];
        // A configured worker that has only terminal-unavailable work is not
        // silently called "in progress". An operator must explicitly repair
        // the runtime/cache and requeue it; GET paths never do that work.
        $state = (!$workerConfigured || ($jobs[self::JOB_UNAVAILABLE] > 0 && $queuedOrRunning === 0))
            ? 'UNAVAILABLE'
            : ($queuedOrRunning > 0 ? 'IN_PROGRESS' : 'READY');
        return [
            'state' => $state,
            'runtime_scope' => self::runtimeScope(),
            'worker_configured' => $workerConfigured,
            'assets' => $assets,
            'jobs' => $jobs,
            'open_jobs' => $open,
            'read_only' => true,
            'message' => match ($state) {
                'UNAVAILABLE' => '私有本地 AI 索引服务尚未配置；照片浏览不受影响。',
                'IN_PROGRESS' => '本地 AI 索引任务正在后台处理；普通浏览不会触发重新计算。',
                default => '本地 AI 索引控制面已就绪。',
            },
        ];
    }

    /**
     * Read-only reconciliation evidence.  Missing rows, checksum drift and
     * terminal failures become visible to maintenance without automatically
     * starting models or widening a browse result.
     *
     * @return array<string,mixed>
     */
    public function maintenanceReport(): array
    {
        $indexTable = DomainSupport::table($this->repository, 'ai_asset_index');
        $photoTable = $this->repository->table('photo');
        $missing = $this->repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM `' . $photoTable . '` p LEFT JOIN `' . $indexTable . '` ai '
                . 'ON ai.`class_photo_id`=p.`class_photo_id` WHERE p.`state`=? AND ai.`class_photo_id` IS NULL',
            [ClassArchivePhoto::STATE_ACTIVE],
        );
        $drift = $this->repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM `' . $photoTable . '` p INNER JOIN `' . $indexTable . '` ai '
                . 'ON ai.`class_photo_id`=p.`class_photo_id` WHERE p.`state`=? AND ai.`source_checksum`<>p.`media_checksum`',
            [ClassArchivePhoto::STATE_ACTIVE],
        );
        $status = $this->status();
        $missingCount = (int) ($missing['count'] ?? 0);
        $driftCount = (int) ($drift['count'] ?? 0);
        $failedAssets = 0;
        foreach (($status['assets'] ?? []) as $statePair => $count) {
            [$faceState, $searchState] = array_pad(explode(':', (string) $statePair, 2), 2, '');
            if ($faceState === self::FACE_FAILED || $searchState === self::SEARCH_FAILED) {
                $failedAssets += (int) $count;
            }
        }
        $failedJobs = (int) (($status['jobs'][self::JOB_FAILED] ?? 0));
        $result = $missingCount === 0 && $driftCount === 0 && $failedAssets === 0 && $failedJobs === 0
            ? 'PASS'
            : 'REVIEW_REQUIRED';
        return [
            'result' => $result,
            'missing_index_rows' => $missingCount,
            'checksum_drift' => $driftCount,
            'failed_assets' => $failedAssets,
            'failed_jobs' => $failedJobs,
            'runtime_state' => $status['state'],
            'open_jobs' => $status['open_jobs'],
            'worker_configured' => $status['worker_configured'],
            'safe_auto_fix' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function enqueueActivePhoto(string $classPhotoId, string $trigger, string $jobKind, ?array $models): array
    {
        $this->assertTrigger($trigger);
        $this->assertJobKind($jobKind);
        $photoId = DomainSupport::idToBinary($classPhotoId);
        return $this->repository->transaction(function (Repository $repository) use ($classPhotoId, $photoId, $trigger, $jobKind, $models): array {
            $photo = $repository->fetchOne(
                'SELECT `class_photo_id`,`media_checksum`,`immich_asset_id`,`state` FROM `'
                    . $repository->table('photo') . '` WHERE `class_photo_id`=? FOR UPDATE',
                [$photoId],
            );
            if ($photo === null || (string) ($photo['state'] ?? '') !== ClassArchivePhoto::STATE_ACTIVE
                || !is_string($photo['media_checksum'] ?? null) || strlen((string) $photo['media_checksum']) !== 32
            ) {
                throw new \RuntimeException('class_archive_ai_index_photo_not_active');
            }
            $checksum = (string) $photo['media_checksum'];
            $assetId = ClassArchivePhoto::normalizeImmichAssetId(
                is_string($photo['immich_asset_id'] ?? null) ? (string) $photo['immich_asset_id'] : null,
            );
            $changed = $this->ensureIndexRow($repository, $photoId, $checksum, $assetId, $trigger, $models);
            if (!$changed) {
                return ['class_photo_id' => $classPhotoId, 'queued' => false, 'state' => 'UNCHANGED'];
            }
            $job = $this->ensureJob($repository, $photoId, $checksum, $jobKind, $trigger);
            return $this->hydrateJob($job) + ['class_photo_id' => $classPhotoId, 'queued' => true];
        });
    }

    private function ensureIndexRow(
        Repository $repository,
        string $photoId,
        string $checksum,
        ?string $assetId,
        string $trigger,
        ?array $models,
    ): bool {
        $table = DomainSupport::table($repository, 'ai_asset_index');
        $row = $repository->fetchOne(
            'SELECT `source_checksum`,`immich_asset_id`,`face_state`,`search_state`,`face_model_name`,`face_model_revision`,'
                . '`search_model_name`,`search_model_revision` FROM `' . $table . '` WHERE `class_photo_id`=? FOR UPDATE',
            [$photoId],
        );
        $force = in_array($trigger, [self::TRIGGER_PIXEL_CHANGED, self::TRIGGER_ADMIN_REINDEX, self::TRIGGER_RECONCILIATION], true);
        if ($row === null) {
            $repository->execute(
                'INSERT INTO `' . $table . '` ('
                    . '`class_photo_id`,`source_checksum`,`immich_asset_id`,`face_state`,`search_state`,`face_model_name`,`face_model_revision`,'
                    . '`search_model_name`,`search_model_revision`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                [
                    $photoId, $checksum, $assetId, self::FACE_PENDING, self::SEARCH_PENDING,
                    $models['face_model_name'] ?? null, $models['face_model_revision'] ?? null,
                    $models['search_model_name'] ?? null, $models['search_model_revision'] ?? null,
                ],
            );
            return true;
        }
        $checksumChanged = !is_string($row['source_checksum'] ?? null) || !hash_equals((string) $row['source_checksum'], $checksum);
        $modelChanged = $models !== null && (
            !hash_equals((string) ($row['face_model_name'] ?? ''), $models['face_model_name'])
            || !hash_equals((string) ($row['face_model_revision'] ?? ''), $models['face_model_revision'])
            || !hash_equals((string) ($row['search_model_name'] ?? ''), $models['search_model_name'])
            || !hash_equals((string) ($row['search_model_revision'] ?? ''), $models['search_model_revision'])
        );
        $needsRetry = $trigger !== self::TRIGGER_NEW_PHOTO && (
            in_array((string) ($row['face_state'] ?? ''), [self::FACE_STALE, self::FACE_FAILED, self::FACE_UNAVAILABLE], true)
            || in_array((string) ($row['search_state'] ?? ''), [self::SEARCH_STALE, self::SEARCH_FAILED, self::SEARCH_UNAVAILABLE], true)
        );
        if (!$checksumChanged && !$modelChanged && !$force && !$needsRetry) {
            return false;
        }
        $repository->execute(
            'UPDATE `' . $table . '` SET `source_checksum`=?,`immich_asset_id`=?,`face_state`=?,`search_state`=?,'
                . '`face_model_name`=?,`face_model_revision`=?,`search_model_name`=?,`search_model_revision`=?,'
                . '`indexed_at`=NULL,`last_error_code`=NULL,`updated_at`=UTC_TIMESTAMP(6) WHERE `class_photo_id`=?',
            [
                $checksum, $checksumChanged ? null : ($assetId ?? ($row['immich_asset_id'] ?? null)), self::FACE_PENDING, self::SEARCH_PENDING,
                $models['face_model_name'] ?? ($row['face_model_name'] ?? null),
                $models['face_model_revision'] ?? ($row['face_model_revision'] ?? null),
                $models['search_model_name'] ?? ($row['search_model_name'] ?? null),
                $models['search_model_revision'] ?? ($row['search_model_revision'] ?? null),
                $photoId,
            ],
        );
        return true;
    }

    /** @return array<string,mixed> */
    private function ensureJob(Repository $repository, string $photoId, string $checksum, string $jobKind, string $trigger): array
    {
        $table = DomainSupport::table($repository, 'ai_index_job');
        // The schema permits one active (PENDING/RUNNING) job for a
        // photo/kind. A byte replacement must supersede even a running old
        // checksum before the new job can be inserted; the worker's later
        // completion then fails closed rather than indexing/reaping a new
        // canonical photo with stale work.
        $repository->execute(
            'UPDATE `' . $table . '` SET `state`=?,`last_error_code`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                . 'WHERE `class_photo_id`=? AND `job_kind`=? AND `state` IN (?,?) '
                . 'AND (`expected_checksum` IS NULL OR `expected_checksum`<>?)',
            [
                self::JOB_CANCELLED, 'SUPERSEDED_CHECKSUM', $photoId, $jobKind,
                self::JOB_PENDING, self::JOB_RUNNING, $checksum,
            ],
        );
        $existing = $repository->fetchOne(
            'SELECT `job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`,`state`,`attempt_count`,`not_before` '
                . 'FROM `' . $table . '` WHERE `class_photo_id`=? AND `job_kind`=? AND `expected_checksum`=? '
                . 'AND `state` IN (?,?) ORDER BY `created_at` ASC LIMIT 1 FOR UPDATE',
            [$photoId, $jobKind, $checksum, self::JOB_PENDING, self::JOB_RUNNING],
        );
        if ($existing !== null) {
            return $existing;
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $jobId = DomainSupport::generateId();
            $jobBinary = DomainSupport::idToBinary($jobId);
            try {
                $repository->execute(
                    'INSERT INTO `' . $table . '` ('
                        . '`job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`,`state`,`attempt_count`,`not_before`,`created_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$jobBinary, $photoId, $jobKind, $trigger, $checksum, self::JOB_PENDING],
                );
                return [
                    'job_id' => $jobBinary,
                    'class_photo_id' => $photoId,
                    'job_kind' => $jobKind,
                    'trigger_kind' => $trigger,
                    'expected_checksum' => $checksum,
                    'state' => self::JOB_PENDING,
                    'attempt_count' => 0,
                    'not_before' => null,
                ];
            } catch (\RuntimeException $error) {
                // A UUID collision is exceptionally unlikely, but only retry
                // the opaque-key collision form. Query failures must remain
                // visible and fail closed.
                if (!str_ends_with($error->getMessage(), '_1062')) {
                    throw $error;
                }
            }
        }
        throw new \RuntimeException('class_archive_ai_index_job_id_collision');
    }

    /** @return array<string,mixed> */
    private function lockedIndexJob(Repository $repository, string $jobId): array
    {
        $table = DomainSupport::table($repository, 'ai_index_job');
        $row = $repository->fetchOne(
            'SELECT `job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`,`state`,`attempt_count`,`not_before` '
                . 'FROM `' . $table . '` WHERE `job_id`=? FOR UPDATE',
            [$jobId],
        );
        if ($row === null || (string) ($row['state'] ?? '') !== self::JOB_RUNNING) {
            throw new \RuntimeException('class_archive_ai_index_job_not_running');
        }
        return $row;
    }

    private function cancelJobForChecksumDrift(Repository $repository, string $jobId): void
    {
        $table = DomainSupport::table($repository, 'ai_index_job');
        $repository->execute(
            'UPDATE `' . $table . '` SET `state`=?,`last_error_code`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                . 'WHERE `job_id`=? AND `state`=?',
            [self::JOB_CANCELLED, 'CHECKSUM_DRIFT', $jobId, self::JOB_RUNNING],
        );
    }

    private function finishNonSuccessJob(string $jobId, string $jobState, string $errorCode, string $faceState, string $searchState): void
    {
        self::requirePrivateWorker();
        $jobBinary = DomainSupport::idToBinary($jobId);
        $errorCode = self::normalizeErrorCode($errorCode);
        $this->repository->transaction(function (Repository $repository) use ($jobBinary, $jobState, $errorCode, $faceState, $searchState): void {
            $job = $this->lockedIndexJob($repository, $jobBinary);
            $photoId = $job['class_photo_id'] ?? null;
            $checksum = $job['expected_checksum'] ?? null;
            if (!is_string($photoId) || !is_string($checksum) || strlen($photoId) !== 16 || strlen($checksum) !== 32) {
                throw new \RuntimeException('class_archive_ai_index_job_target_invalid');
            }
            $index = DomainSupport::table($repository, 'ai_asset_index');
            if ((string) $job['job_kind'] === self::JOB_DELETE_ASSET) {
                $faceState = self::FACE_REMOVED;
                $searchState = self::SEARCH_REMOVED;
            }
            $repository->execute(
                'UPDATE `' . $index . '` SET `face_state`=?,`search_state`=?,`indexed_at`=NULL,`last_error_code`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id`=? AND `source_checksum`=?',
                [$faceState, $searchState, $errorCode, $photoId, $checksum],
            );
            $jobs = DomainSupport::table($repository, 'ai_index_job');
            $changed = $repository->execute(
                'UPDATE `' . $jobs . '` SET `state`=?,`last_error_code`=?,`completed_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `job_id`=? AND `state`=?',
                [$jobState, $errorCode, $jobBinary, self::JOB_RUNNING],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_ai_index_job_completion_race');
            }
        });
    }

    /** @return array{face_model_name:string,face_model_revision:string,search_model_name:string,search_model_revision:string} */
    private function normalizeModels(string $faceName, string $faceRevision, string $searchName, string $searchRevision): array
    {
        return [
            'face_model_name' => self::normalizeModelField($faceName),
            'face_model_revision' => self::normalizeModelField($faceRevision),
            'search_model_name' => self::normalizeModelField($searchName),
            'search_model_revision' => self::normalizeModelField($searchRevision),
        ];
    }

    private static function normalizeModelField(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A[A-Za-z0-9._:@\/-]{1,190}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_ai_index_model_invalid');
        }
        return $value;
    }

    private static function normalizeErrorCode(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/\A[A-Z][A-Z0-9_]{1,63}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('class_archive_ai_index_error_code_invalid');
        }
        return $value;
    }

    private function assertTrigger(string $trigger): void
    {
        if (!in_array($trigger, self::triggers(), true)) {
            throw new \InvalidArgumentException('class_archive_ai_index_trigger_invalid');
        }
    }

    private function assertJobKind(string $kind): void
    {
        if (!in_array($kind, self::jobKinds(), true)) {
            throw new \InvalidArgumentException('class_archive_ai_index_job_kind_invalid');
        }
    }

    /** @return array<string,mixed> */
    private function hydrateJob(array $row): array
    {
        $job = $row['job_id'] ?? null;
        $photo = $row['class_photo_id'] ?? null;
        $checksum = $row['expected_checksum'] ?? null;
        if (!is_string($job) || strlen($job) !== 16 || ($photo !== null && (!is_string($photo) || strlen($photo) !== 16))
            || ($checksum !== null && (!is_string($checksum) || strlen($checksum) !== 32))
        ) {
            throw new \RuntimeException('class_archive_ai_index_job_hydration_invalid');
        }
        $state = (string) ($row['state'] ?? '');
        $kind = (string) ($row['job_kind'] ?? '');
        $trigger = (string) ($row['trigger_kind'] ?? '');
        $this->assertJobKind($kind);
        $this->assertTrigger($trigger);
        if (!in_array($state, self::jobStates(), true)) {
            throw new \RuntimeException('class_archive_ai_index_job_state_invalid');
        }
        return [
            'job_id' => DomainSupport::binaryToId($job),
            'class_photo_id' => $photo === null ? null : DomainSupport::binaryToId($photo),
            'job_kind' => $kind,
            'trigger_kind' => $trigger,
            'expected_checksum' => $checksum === null ? null : bin2hex($checksum),
            'state' => $state,
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'not_before' => $row['not_before'] ?? null,
        ];
    }

    /** @return list<string> */
    public static function indexStates(): array
    {
        return [self::FACE_PENDING, self::FACE_INDEXED, self::FACE_UNAVAILABLE, self::FACE_FAILED, self::FACE_STALE, self::FACE_REMOVED];
    }

    /** @return list<string> */
    public static function jobKinds(): array
    {
        return [self::JOB_INDEX_ASSET, self::JOB_DELETE_ASSET, self::JOB_REINDEX_MODEL];
    }

    /** @return list<string> */
    public static function triggers(): array
    {
        return [
            self::TRIGGER_NEW_PHOTO,
            self::TRIGGER_PIXEL_CHANGED,
            self::TRIGGER_PHOTO_DELETED,
            self::TRIGGER_MODEL_CHANGED,
            self::TRIGGER_ADMIN_REINDEX,
            self::TRIGGER_RECONCILIATION,
        ];
    }

    /** @return list<string> */
    public static function jobStates(): array
    {
        return [self::JOB_PENDING, self::JOB_RUNNING, self::JOB_UNAVAILABLE, self::JOB_FAILED, self::JOB_COMPLETE, self::JOB_CANCELLED];
    }

    private static function runtimeScope(): string
    {
        $scope = getenv('CLASS_ARCHIVE_RUNTIME_SCOPE');
        return is_string($scope) && preg_match('/\A[A-Z0-9_]{1,64}\z/D', $scope) === 1 ? $scope : 'UNKNOWN';
    }

    private static function privateWorkerConfigured(): bool
    {
        // A bridge alone is deliberately insufficient: it can provide query
        // metadata but is not an indexing worker.  This explicit non-secret
        // flag is only set by the isolated private full-runtime compose.
        return self::runtimeScope() === 'PRIVATE_REAL_FULL'
            && hash_equals('1', (string) getenv('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER'));
    }

    private static function requirePrivateWorker(): void
    {
        if (!self::privateWorkerConfigured()) {
            throw new \RuntimeException('class_archive_ai_index_worker_unavailable');
        }
    }
}
