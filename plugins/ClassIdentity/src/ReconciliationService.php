<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Read-mostly reconciliation across Piwigo's MyISAM media graph, the
 * ClassIdentity InnoDB graph and private media volumes.
 *
 * Findings are intentionally classified, never broadly repaired. A
 * maintenance run may safely expire due invitations and clean explicitly
 * eligible rejected binaries through their domain services, but structural
 * media inconsistencies always remain visible for human review or quarantine.
 */
final class ReconciliationService
{
    public const VERSION = 3;
    public const FRESHNESS_SECONDS = 24 * 3600;

    private const DATA_DIRECTORY = '_data/class-archive';
    private const FILE_NAME = 'reconciliation.json';

    private \mysqli $db;
    private string $prefix;

    private function __construct(\mysqli $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    public static function fromPiwigo(): self
    {
        global $mysqli, $prefixeTable;
        if (!$mysqli instanceof \mysqli || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
            throw new \RuntimeException('class_identity_reconciliation_database_unavailable');
        }
        if (!$mysqli->set_charset('utf8mb4')) {
            throw new \RuntimeException('class_identity_reconciliation_utf8mb4_required');
        }
        return new self($mysqli, $prefixeTable);
    }

    /** @return array<string, mixed> */
    public function scanAndPersist(): array
    {
        $result = $this->scan();
        self::persist($result);
        return $result;
    }

    /** @return array<string, mixed> */
    public function scan(): array
    {
        $issues = [];
        $candidates = [];
        $images = $this->all('SELECT `id`,`path` FROM `' . $this->prefix . 'images` ORDER BY `id` ASC');
        $imageById = [];
        $managedPaths = [];
        foreach ($images as $image) {
            $id = (int) ($image['id'] ?? 0);
            $path = self::normalizePiwigoPath((string) ($image['path'] ?? ''));
            if ($id <= 0 || $path === null) {
                $issues[] = self::issue('PIWIGO_IMAGE_PATH_INVALID', 'MANUAL_REVIEW', 'image:' . $id);
                continue;
            }
            $imageById[$id] = $path;
            $managedPaths[$path] = true;
            $file = self::existingPiwigoFile($path);
            if ($file === null) {
                $issues[] = self::issue('MEDIA_ORIGINAL_MISSING', 'MANUAL_REVIEW', 'image:' . $id);
                continue;
            }
            if (((int) (@fileperms($file) & 0007)) !== 0) {
                $issues[] = self::issue('MEDIA_FILE_MODE_POLICY', 'MANUAL_REVIEW', 'image:' . $id);
            }
        }

        $heritageRoot = $this->scalarInt('SELECT `id` FROM `' . $this->prefix . 'categories` WHERE `permalink` = \'class-archive-heritage\' LIMIT 1');
        if ($heritageRoot <= 0) {
            $issues[] = self::issue('HERITAGE_ROOT_MISSING', 'MANUAL_REVIEW', 'archive-root:heritage');
        }
        $livingRoot = $this->scalarInt('SELECT `id` FROM `' . $this->prefix . 'categories` WHERE `permalink` = \'class-archive-living\' LIMIT 1');
        if ($livingRoot <= 0) {
            $issues[] = self::issue('LIVING_ROOT_MISSING', 'MANUAL_REVIEW', 'archive-root:living');
        }

        $submissionTable = '`' . $this->prefix . 'class_identity_submission`';
        $archiveTable = '`' . $this->prefix . 'class_identity_archive_image`';
        $photoTable = '`' . $this->prefix . 'class_identity_photo`';
        $submissions = $this->all(
            'SELECT `id`,`state`,`storage_ref`,`thumbnail_ref`,`approved_image_id`,`reviewed_at` FROM ' . $submissionTable . ' ORDER BY `id` ASC'
        );
        foreach ($submissions as $submission) {
            $id = (int) ($submission['id'] ?? 0);
            $state = (string) ($submission['state'] ?? '');
            $storage = self::pendingFile((string) ($submission['storage_ref'] ?? ''));
            $thumbnail = self::pendingFile((string) ($submission['thumbnail_ref'] ?? ''));
            $sourceExists = $storage !== null;
            $thumbnailExists = $thumbnail !== null;

            if ($state === 'PENDING') {
                if (!$sourceExists || !$thumbnailExists) {
                    $issues[] = self::issue('PENDING_BINARY_MISSING', 'MANUAL_REVIEW', 'submission:' . $id);
                }
                continue;
            }
            if ($state === 'REJECTED') {
                if ($sourceExists || $thumbnailExists) {
                    $reviewed = strtotime((string) ($submission['reviewed_at'] ?? '') . ' UTC');
                    if ($reviewed !== false && $reviewed <= (time() - self::rejectedRetentionDays() * 86400)) {
                        $candidates[] = ['submission_id' => $id, 'kind' => 'REJECTED_BINARY_CLEANUP_ELIGIBLE'];
                    }
                }
                continue;
            }
            if ($state !== 'APPROVED') {
                $issues[] = self::issue('SUBMISSION_STATE_INVALID', 'MANUAL_REVIEW', 'submission:' . $id);
                continue;
            }
            $imageId = (int) ($submission['approved_image_id'] ?? 0);
            if ($imageId <= 0 || !isset($imageById[$imageId])) {
                $issues[] = self::issue('APPROVED_IMAGE_MISSING', 'MANUAL_REVIEW', 'submission:' . $id);
                continue;
            }
            // The source-submission relation is the durable link; do not infer
            // it from an image filename or a transient pending binary.
            $archiveRows = $this->all('SELECT `piwigo_image_id` FROM ' . $archiveTable . ' WHERE `source_submission_id` = ' . $id . ' LIMIT 2');
            if (count($archiveRows) !== 1 || (int) ($archiveRows[0]['piwigo_image_id'] ?? 0) !== $imageId) {
                $issues[] = self::issue('APPROVED_ARCHIVE_MAPPING_MISSING', 'MANUAL_REVIEW', 'submission:' . $id);
            }
            if ($heritageRoot > 0 && !$this->hasEraAssociation($imageId, $heritageRoot)) {
                $issues[] = self::issue('APPROVED_HERITAGE_ASSOCIATION_MISSING', 'MANUAL_REVIEW', 'submission:' . $id);
            }
        }

        $orphanArchives = $this->all(
            'SELECT ai.`id` FROM ' . $archiveTable . ' ai LEFT JOIN `' . $this->prefix . 'images` i ON i.`id` = ai.`piwigo_image_id` '
            . 'WHERE i.`id` IS NULL ORDER BY ai.`id` ASC'
        );
        foreach ($orphanArchives as $row) {
            $issues[] = self::issue('ARCHIVE_METADATA_IMAGE_MISSING', 'MANUAL_REVIEW', 'archive:' . (int) ($row['id'] ?? 0));
        }

        // Canonical mappings are created lazily by the future gateway so an
        // existing Piwigo-only installation stays healthy while Immich is
        // absent. Once a map exists, however, any uncertain target, checksum
        // or reference is a manual-review issue rather than an automatic
        // rebind. The opaque UUID is hashed in the report to avoid turning the
        // maintenance artifact into a public identifier source.
        $photoMappings = $this->all(
            'SELECT `class_photo_id`,`piwigo_image_id`,`source_submission_id`,`media_checksum`,`media_reference`,`state` '
            . 'FROM ' . $photoTable . ' ORDER BY `created_at` ASC'
        );
        foreach ($photoMappings as $mapping) {
            $subject = 'photo:' . hash('sha256', (string) ($mapping['class_photo_id'] ?? ''));
            $state = (string) ($mapping['state'] ?? '');
            $imageId = $mapping['piwigo_image_id'] === null ? 0 : (int) $mapping['piwigo_image_id'];
            $submissionId = $mapping['source_submission_id'] === null ? 0 : (int) $mapping['source_submission_id'];
            if ($state === 'PENDING') {
                $pending = $submissionId > 0
                    ? $this->all('SELECT `state`,`storage_ref` FROM ' . $submissionTable . ' WHERE `id` = ' . $submissionId . ' LIMIT 1')
                    : [];
                if (count($pending) !== 1 || (string) ($pending[0]['state'] ?? '') !== 'PENDING') {
                    $issues[] = self::issue('CANONICAL_PENDING_MAPPING_INVALID', 'MANUAL_REVIEW', $subject);
                }
                continue;
            }
            if (!in_array($state, ['ACTIVE', 'STALE', 'RETIRED'], true) || $imageId <= 0 || !isset($imageById[$imageId])) {
                $issues[] = self::issue('CANONICAL_PHOTO_MAPPING_TARGET_INVALID', 'MANUAL_REVIEW', $subject);
                continue;
            }
            if ($state !== 'ACTIVE') {
                $issues[] = self::issue('CANONICAL_PHOTO_MAPPING_NOT_ACTIVE', 'MANUAL_REVIEW', $subject);
                continue;
            }
            $reference = self::normalizePiwigoPath((string) ($mapping['media_reference'] ?? ''));
            $file = $reference === null ? null : self::existingPiwigoFile($reference);
            $checksum = is_string($file) ? hash_file('sha256', $file) : false;
            if (
                $reference === null
                || $reference !== $imageById[$imageId]
                || !is_string($checksum)
                || !hash_equals((string) ($mapping['media_checksum'] ?? ''), hex2bin($checksum) ?: '')
            ) {
                $issues[] = self::issue('CANONICAL_PHOTO_MAPPING_DRIFT', 'MANUAL_REVIEW', $subject);
            }
        }

        // Migration 8 adds reversible product projections around Piwigo's
        // legacy category graph. They deliberately remain separate from the
        // request-time ACL, but a half-applied journal, stale mapping, unsafe
        // cover or invalid logical alias must be visible before production is
        // considered healthy. Nothing in this scan mutates those structures.
        $productDomain = $this->productDomainFindings($heritageRoot, $livingRoot);
        $issues = array_merge($issues, $productDomain['issues']);

        // The optional AI runtime is not allowed to hide lifecycle drift. It
        // remains a separate, checksum-bound control plane: this scan only
        // classifies rows/jobs and never invokes Immich or a model.
        $aiIndex = $this->aiIndexFindings();
        $issues = array_merge($issues, $aiIndex['issues']);

        foreach (self::discoverOriginals() as $path) {
            if (!isset($managedPaths[$path])) {
                $issues[] = self::issue('UNMANAGED_ORIGINAL', 'QUARANTINE', 'file:' . hash('sha256', $path));
            }
        }

        $derivative = self::derivativeSummary();
        foreach ($derivative['unsafe_entries'] as $subject) {
            $issues[] = self::issue('DERIVATIVE_UNSAFE_ENTRY', 'MANUAL_REVIEW', $subject);
        }
        $issues = array_slice($issues, 0, 500);
        $now = gmdate('c');
        return [
            'reconciliation_version' => self::VERSION,
            'reconciler_sha256' => self::selfDigest(),
            'timestamp' => $now,
            'result' => $issues === [] ? 'PASS' : 'REVIEW_REQUIRED',
            'issue_count' => count($issues),
            'issues' => $issues,
            'cleanup_candidates' => $candidates,
            'derivative' => $derivative,
            'checked_images' => count($images),
            'canonical_photo_mappings' => count($photoMappings),
            'product_domain' => $productDomain['counts'],
            'ai_index' => $aiIndex['counts'],
        ];
    }

    /**
     * @return array{issues:list<array{code:string,disposition:string,subject:string}>,counts:array<string,int>}
     */
    private function aiIndexFindings(): array
    {
        $issues = [];
        $photo = '`' . $this->prefix . 'class_identity_photo`';
        $index = '`' . $this->prefix . 'class_identity_ai_asset_index`';
        $jobs = '`' . $this->prefix . 'class_identity_ai_index_job`';

        $indexRows = $this->all(
            'SELECT HEX(p.`class_photo_id`) AS `photo_id`,p.`state` AS `photo_state`,p.`media_checksum` AS `photo_checksum`,'
                . 'ai.`class_photo_id` AS `index_photo_id`,ai.`source_checksum`,ai.`face_state`,ai.`search_state` '
                . 'FROM ' . $photo . ' p LEFT JOIN ' . $index . ' ai ON ai.`class_photo_id`=p.`class_photo_id` '
                . 'ORDER BY p.`created_at` ASC'
        );
        $stateCounts = [
            'indexed_rows' => 0,
            'active_missing_rows' => 0,
            'checksum_drift' => 0,
            'retired_state_drift' => 0,
            'failed_assets' => 0,
            'jobs_pending' => 0,
            'jobs_running' => 0,
            'jobs_unavailable' => 0,
            'jobs_failed' => 0,
            'job_target_drift' => 0,
        ];
        foreach ($indexRows as $row) {
            $photoId = (string) ($row['photo_id'] ?? '');
            $subject = self::opaqueSubject('ai-photo', $photoId);
            $active = ($row['photo_state'] ?? null) === ClassArchivePhoto::STATE_ACTIVE;
            if ($row['index_photo_id'] === null) {
                if ($active) {
                    ++$stateCounts['active_missing_rows'];
                    $issues[] = self::issue('AI_INDEX_MAPPING_MISSING', 'MANUAL_REVIEW', $subject);
                }
                continue;
            }
            ++$stateCounts['indexed_rows'];
            $face = (string) ($row['face_state'] ?? '');
            $search = (string) ($row['search_state'] ?? '');
            if (!in_array($face, AiIndexService::indexStates(), true) || !in_array($search, AiIndexService::indexStates(), true)) {
                $issues[] = self::issue('AI_INDEX_STATE_INVALID', 'MANUAL_REVIEW', $subject);
                continue;
            }
            if ($active && (!is_string($row['source_checksum'] ?? null)
                || !is_string($row['photo_checksum'] ?? null)
                || !hash_equals((string) $row['source_checksum'], (string) $row['photo_checksum'])
            )) {
                ++$stateCounts['checksum_drift'];
                $issues[] = self::issue('AI_INDEX_CHECKSUM_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if (!$active && ($face !== AiIndexService::FACE_REMOVED || $search !== AiIndexService::SEARCH_REMOVED)) {
                ++$stateCounts['retired_state_drift'];
                $issues[] = self::issue('AI_INDEX_RETIRED_TARGET_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if ($face === AiIndexService::FACE_FAILED || $search === AiIndexService::SEARCH_FAILED) {
                ++$stateCounts['failed_assets'];
                $issues[] = self::issue('AI_INDEX_ASSET_FAILED', 'MANUAL_REVIEW', $subject);
            }
        }

        $jobRows = $this->all(
            'SELECT HEX(j.`job_id`) AS `job_id`,j.`class_photo_id`,j.`expected_checksum`,j.`state`,p.`state` AS `photo_state`,'
                . 'p.`media_checksum` AS `photo_checksum` FROM ' . $jobs . ' j '
                . 'LEFT JOIN ' . $photo . ' p ON p.`class_photo_id`=j.`class_photo_id` ORDER BY j.`created_at` ASC'
        );
        foreach ($jobRows as $row) {
            $state = (string) ($row['state'] ?? '');
            $subject = self::opaqueSubject('ai-job', (string) ($row['job_id'] ?? ''));
            if (!in_array($state, AiIndexService::jobStates(), true)) {
                $issues[] = self::issue('AI_INDEX_JOB_STATE_INVALID', 'MANUAL_REVIEW', $subject);
                continue;
            }
            if ($state === AiIndexService::JOB_PENDING) {
                ++$stateCounts['jobs_pending'];
            } elseif ($state === AiIndexService::JOB_RUNNING) {
                ++$stateCounts['jobs_running'];
            } elseif ($state === AiIndexService::JOB_UNAVAILABLE) {
                ++$stateCounts['jobs_unavailable'];
            } elseif ($state === AiIndexService::JOB_FAILED) {
                ++$stateCounts['jobs_failed'];
                $issues[] = self::issue('AI_INDEX_JOB_FAILED', 'MANUAL_REVIEW', $subject);
            }
            if (!in_array($state, [AiIndexService::JOB_PENDING, AiIndexService::JOB_RUNNING, AiIndexService::JOB_UNAVAILABLE], true)) {
                continue;
            }
            if ($row['class_photo_id'] === null || ($row['photo_state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE
                || !is_string($row['expected_checksum'] ?? null)
                || !is_string($row['photo_checksum'] ?? null)
                || !hash_equals((string) $row['expected_checksum'], (string) $row['photo_checksum'])
            ) {
                ++$stateCounts['job_target_drift'];
                $issues[] = self::issue('AI_INDEX_JOB_TARGET_DRIFT', 'MANUAL_REVIEW', $subject);
            }
        }

        return ['issues' => $issues, 'counts' => $stateCounts];
    }

    /**
     * @return array{issues:list<array{code:string,disposition:string,subject:string}>,counts:array<string,int>}
     */
    private function productDomainFindings(int $heritageRoot, int $livingRoot): array
    {
        $issues = [];

        $batchTable = '`' . $this->prefix . 'class_identity_batch_operation`';
        $batchItemTable = '`' . $this->prefix . 'class_identity_batch_operation_item`';
        $batches = $this->all(
            'SELECT HEX(`batch_id`) AS `batch_id`,`state`,`item_count`,`applied_count`,`failed_count`,`completed_at` '
            . 'FROM ' . $batchTable . ' ORDER BY `created_at` ASC'
        );
        $batchItemStates = [];
        foreach ($this->all(
            'SELECT HEX(`batch_id`) AS `batch_id`,`state`,COUNT(*) AS `count` FROM ' . $batchItemTable
            . ' GROUP BY `batch_id`,`state` ORDER BY `batch_id`,`state`'
        ) as $row) {
            $batchItemStates[(string) $row['batch_id']][(string) $row['state']] = (int) $row['count'];
        }
        foreach ($batches as $batch) {
            $batchId = (string) ($batch['batch_id'] ?? '');
            $subject = self::opaqueSubject('batch', $batchId);
            $state = (string) ($batch['state'] ?? '');
            $states = $batchItemStates[$batchId] ?? [];
            $itemCount = (int) ($batch['item_count'] ?? 0);
            if ($state === 'PREPARED') {
                $issues[] = self::issue('BATCH_OPERATION_PREPARED', 'MANUAL_REVIEW', $subject);
            } elseif ($state === 'MANUAL_REVIEW') {
                $issues[] = self::issue('BATCH_OPERATION_MANUAL_REVIEW', 'MANUAL_REVIEW', $subject);
            }
            if (array_sum($states) !== $itemCount) {
                $issues[] = self::issue('BATCH_OPERATION_ITEM_COUNT_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if ($state === 'APPLIED' && (
                (int) ($states['APPLIED'] ?? 0) !== $itemCount
                || (int) ($batch['applied_count'] ?? 0) !== $itemCount
                || $batch['completed_at'] === null
            )) {
                $issues[] = self::issue('BATCH_OPERATION_APPLIED_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if (in_array($state, ['FAILED', 'COMPENSATED'], true) && (int) ($states['PREPARED'] ?? 0) > 0) {
                $issues[] = self::issue('BATCH_OPERATION_UNRESOLVED_ITEM', 'MANUAL_REVIEW', $subject);
            }
        }

        $albumTable = '`' . $this->prefix . 'class_identity_album`';
        $photoTable = '`' . $this->prefix . 'class_identity_photo`';
        $principalTable = '`' . $this->prefix . 'class_identity_principal`';
        $accountTable = '`' . $this->prefix . 'class_identity_account`';
        $seatTable = '`' . $this->prefix . 'class_identity_seat`';
        $albums = $this->all(
            'SELECT HEX(a.`class_album_id`) AS `class_album_id`,a.`piwigo_category_id`,a.`album_type`,a.`owner_principal_id`,'
            . 'a.`era`,a.`state`,HEX(a.`manual_cover_class_photo_id`) AS `cover_id`,c.`id` AS `category_exists`,c.`uppercats`,c.`visible`,'
            . 'op.`principal_type` AS `owner_principal_type`,op.`state` AS `owner_principal_state`,'
            . 'oa.`state` AS `owner_account_state`,oa.`current_marker` AS `owner_current_marker`,os.`state` AS `owner_seat_state`,os.`seat_type` AS `owner_seat_type`,'
            . 'cp.`state` AS `cover_state`,cp.`piwigo_image_id` AS `cover_image_id` '
            . 'FROM ' . $albumTable . ' a '
            . 'LEFT JOIN `' . $this->prefix . 'categories` c ON c.`id`=a.`piwigo_category_id` '
            . 'LEFT JOIN ' . $principalTable . ' op ON op.`id`=a.`owner_principal_id` '
            . 'LEFT JOIN ' . $accountTable . ' oa ON oa.`id`=op.`account_id` '
            . 'LEFT JOIN ' . $seatTable . ' os ON os.`id`=oa.`seat_id` '
            . 'LEFT JOIN ' . $photoTable . ' cp ON cp.`class_photo_id`=a.`manual_cover_class_photo_id` '
            . 'ORDER BY a.`created_at` ASC'
        );
        foreach ($albums as $album) {
            $subject = self::opaqueSubject('album', (string) ($album['class_album_id'] ?? ''));
            if ($album['category_exists'] === null) {
                $issues[] = self::issue('ALBUM_PIWIGO_CATEGORY_MISSING', 'MANUAL_REVIEW', $subject);
                continue;
            }
            $categoryId = (int) $album['piwigo_category_id'];
            $categoryEra = self::categoryEra(
                $categoryId,
                (string) ($album['uppercats'] ?? ''),
                $heritageRoot,
                $livingRoot,
            );
            $declaredEra = (string) ($album['era'] ?? '');
            if ($declaredEra !== 'MIXED' && $categoryEra !== $declaredEra) {
                $issues[] = self::issue('ALBUM_ERA_MAPPING_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if (($album['state'] ?? null) === 'ACTIVE' && (string) ($album['visible'] ?? '') !== 'true') {
                $issues[] = self::issue('ALBUM_ACTIVE_CATEGORY_HIDDEN', 'MANUAL_REVIEW', $subject);
            }
            if (($album['album_type'] ?? null) === 'COMMUNITY' && !(
                ($album['owner_principal_type'] ?? null) === 'SEAT_ACCOUNT'
                && ($album['owner_principal_state'] ?? null) === 'ACTIVE'
                && ($album['owner_account_state'] ?? null) === 'ACTIVE'
                && (int) ($album['owner_current_marker'] ?? 0) === 1
                && ($album['owner_seat_state'] ?? null) === 'ACTIVE'
                && in_array($album['owner_seat_type'] ?? null, ['CLASSMATE', 'TEACHER'], true)
            )) {
                $issues[] = self::issue('ALBUM_COMMUNITY_OWNER_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if ($album['cover_id'] !== null && (
                ($album['cover_state'] ?? null) !== 'ACTIVE'
                || (int) ($album['cover_image_id'] ?? 0) <= 0
                || !$this->hasEraAssociation((int) $album['cover_image_id'], $categoryId)
            )) {
                $issues[] = self::issue('ALBUM_COVER_MAPPING_DRIFT', 'MANUAL_REVIEW', $subject);
            }
        }

        $spotlightTable = '`' . $this->prefix . 'class_identity_spotlight`';
        $spotlights = $this->all(
            'SELECT HEX(s.`spotlight_id`) AS `spotlight_id`,s.`state`,s.`expires_at`,s.`owner_principal_id`,'
            . 'a.`state` AS `album_state`,a.`album_type`,a.`owner_principal_id` AS `album_owner_principal_id`,p.`state` AS `owner_state` '
            . 'FROM ' . $spotlightTable . ' s LEFT JOIN ' . $albumTable . ' a ON a.`class_album_id`=s.`class_album_id` '
            . 'LEFT JOIN ' . $principalTable . ' p ON p.`id`=s.`owner_principal_id` ORDER BY s.`created_at` ASC'
        );
        foreach ($spotlights as $spotlight) {
            $subject = self::opaqueSubject('spotlight', (string) ($spotlight['spotlight_id'] ?? ''));
            if (($spotlight['state'] ?? null) === 'ACTIVE' && strtotime((string) ($spotlight['expires_at'] ?? '') . ' UTC') <= time()) {
                $issues[] = self::issue('SPOTLIGHT_EXPIRY_PENDING', 'SAFE_AUTO_FIX', $subject);
            }
            if (($spotlight['state'] ?? null) === 'ACTIVE' && !(
                ($spotlight['album_state'] ?? null) === 'ACTIVE'
                && ($spotlight['album_type'] ?? null) === 'COMMUNITY'
                && (int) ($spotlight['album_owner_principal_id'] ?? 0) === (int) ($spotlight['owner_principal_id'] ?? -1)
                && ($spotlight['owner_state'] ?? null) === 'ACTIVE'
            )) {
                $issues[] = self::issue('SPOTLIGHT_TARGET_DRIFT', 'MANUAL_REVIEW', $subject);
            }
        }

        $personTable = '`' . $this->prefix . 'class_identity_person`';
        $identityTable = '`' . $this->prefix . 'class_identity_identity`';
        $persons = $this->all(
            'SELECT HEX(p.`class_person_id`) AS `class_person_id`,p.`state`,p.`classmate_identity_id`,i.`identity_type`,'
            . 'HEX(p.`manual_cover_class_photo_id`) AS `cover_id`,cp.`state` AS `cover_state` '
            . 'FROM ' . $personTable . ' p LEFT JOIN ' . $identityTable . ' i ON i.`id`=p.`classmate_identity_id` '
            . 'LEFT JOIN ' . $photoTable . ' cp ON cp.`class_photo_id`=p.`manual_cover_class_photo_id` ORDER BY p.`created_at` ASC'
        );
        foreach ($persons as $person) {
            $subject = self::opaqueSubject('person', (string) ($person['class_person_id'] ?? ''));
            // A frozen Classmate remains the same historical person. Only an
            // absent or non-Classmate identity is a mapping error; account
            // state must not rewrite archival identity truth.
            if ($person['classmate_identity_id'] !== null && ($person['identity_type'] ?? null) !== 'CLASSMATE') {
                $issues[] = self::issue('PERSON_IDENTITY_LINK_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if ($person['cover_id'] !== null && ($person['cover_state'] ?? null) !== 'ACTIVE') {
                $issues[] = self::issue('PERSON_COVER_MAPPING_DRIFT', 'MANUAL_REVIEW', $subject);
            }
        }

        $mergeTable = '`' . $this->prefix . 'class_identity_person_merge`';
        $mergeTargets = [];
        foreach ($this->all(
            'SELECT HEX(m.`source_class_person_id`) AS `source_id`,HEX(m.`target_class_person_id`) AS `target_id`,'
            . 'sp.`state` AS `source_state`,tp.`state` AS `target_state` FROM ' . $mergeTable . ' m '
            . 'LEFT JOIN ' . $personTable . ' sp ON sp.`class_person_id`=m.`source_class_person_id` '
            . 'LEFT JOIN ' . $personTable . ' tp ON tp.`class_person_id`=m.`target_class_person_id` '
            . "WHERE m.`state`='ACTIVE' ORDER BY m.`created_at` ASC"
        ) as $merge) {
            $source = (string) ($merge['source_id'] ?? '');
            $target = (string) ($merge['target_id'] ?? '');
            $mergeTargets[$source] = $target;
            if (($merge['source_state'] ?? null) !== 'ACTIVE' || ($merge['target_state'] ?? null) !== 'ACTIVE') {
                $issues[] = self::issue('PERSON_MERGE_TARGET_DRIFT', 'MANUAL_REVIEW', self::opaqueSubject('person', $source));
            }
        }
        foreach (array_keys($mergeTargets) as $source) {
            $seen = [];
            $cursor = $source;
            while (isset($mergeTargets[$cursor])) {
                if (isset($seen[$cursor])) {
                    $issues[] = self::issue('PERSON_MERGE_CYCLE', 'MANUAL_REVIEW', self::opaqueSubject('person', $source));
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $mergeTargets[$cursor];
            }
        }

        $ruleTable = '`' . $this->prefix . 'class_identity_person_photo_rule`';
        foreach ($this->all(
            'SELECT HEX(r.`class_person_id`) AS `person_id`,p.`state` AS `person_state`,ph.`state` AS `photo_state` '
            . 'FROM ' . $ruleTable . ' r LEFT JOIN ' . $personTable . ' p ON p.`class_person_id`=r.`class_person_id` '
            . 'LEFT JOIN ' . $photoTable . ' ph ON ph.`class_photo_id`=r.`class_photo_id`'
        ) as $rule) {
            if (($rule['person_state'] ?? null) !== 'ACTIVE' || ($rule['photo_state'] ?? null) !== 'ACTIVE') {
                $issues[] = self::issue('PERSON_PHOTO_RULE_DRIFT', 'MANUAL_REVIEW', self::opaqueSubject('person', (string) ($rule['person_id'] ?? '')));
            }
        }

        $duplicateTable = '`' . $this->prefix . 'class_identity_photo_duplicate`';
        $duplicateRows = $this->all(
            'SELECT HEX(d.`duplicate_id`) AS `duplicate_id`,HEX(d.`left_class_photo_id`) AS `left_id`,'
            . 'HEX(d.`right_class_photo_id`) AS `right_id`,d.`relation_kind`,d.`state`,HEX(d.`canonical_class_photo_id`) AS `canonical_id`,'
            . 'lp.`state` AS `left_state`,lp.`piwigo_image_id` AS `left_image_id`,lp.`media_checksum` AS `left_checksum`,'
            . 'rp.`state` AS `right_state`,rp.`piwigo_image_id` AS `right_image_id`,rp.`media_checksum` AS `right_checksum` '
            . 'FROM ' . $duplicateTable . ' d LEFT JOIN ' . $photoTable . ' lp ON lp.`class_photo_id`=d.`left_class_photo_id` '
            . 'LEFT JOIN ' . $photoTable . ' rp ON rp.`class_photo_id`=d.`right_class_photo_id` ORDER BY d.`created_at` ASC'
        );
        $activeAliases = [];
        foreach ($duplicateRows as $duplicate) {
            $subject = self::opaqueSubject('duplicate', (string) ($duplicate['duplicate_id'] ?? ''));
            $active = in_array($duplicate['state'] ?? null, ['CANDIDATE', 'CONSOLIDATED'], true);
            if ($active && (($duplicate['left_state'] ?? null) !== 'ACTIVE' || ($duplicate['right_state'] ?? null) !== 'ACTIVE')) {
                $issues[] = self::issue('PHOTO_DUPLICATE_REFERENCE_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if (($duplicate['relation_kind'] ?? null) === 'EXACT' && !hash_equals(
                (string) ($duplicate['left_checksum'] ?? ''),
                (string) ($duplicate['right_checksum'] ?? ''),
            )) {
                $issues[] = self::issue('PHOTO_DUPLICATE_EXACT_CHECKSUM_DRIFT', 'MANUAL_REVIEW', $subject);
            }
            if (($duplicate['state'] ?? null) === 'CONSOLIDATED') {
                $leftEra = $this->effectiveEraForImage((int) ($duplicate['left_image_id'] ?? 0), $heritageRoot, $livingRoot);
                $rightEra = $this->effectiveEraForImage((int) ($duplicate['right_image_id'] ?? 0), $heritageRoot, $livingRoot);
                if ($leftEra === null || $rightEra === null || $leftEra !== $rightEra) {
                    $issues[] = self::issue('PHOTO_DUPLICATE_CROSS_ERA_DRIFT', 'MANUAL_REVIEW', $subject);
                }
                $canonical = (string) ($duplicate['canonical_id'] ?? '');
                $alias = hash_equals($canonical, (string) ($duplicate['left_id'] ?? ''))
                    ? (string) ($duplicate['right_id'] ?? '')
                    : (string) ($duplicate['left_id'] ?? '');
                $activeAliases[$alias] = $canonical;
            }
        }
        foreach ($activeAliases as $alias => $canonical) {
            if (isset($activeAliases[$canonical])) {
                $issues[] = self::issue('PHOTO_DUPLICATE_ALIAS_CHAIN', 'MANUAL_REVIEW', self::opaqueSubject('photo', $alias));
            }
        }

        $sourceTable = '`' . $this->prefix . 'class_identity_photo_source`';
        foreach ($this->all(
            'SELECT ps.`id`,ps.`source_checksum`,p.`media_checksum`,p.`state` AS `photo_state` FROM ' . $sourceTable . ' ps '
            . 'LEFT JOIN ' . $photoTable . ' p ON p.`class_photo_id`=ps.`class_photo_id` ORDER BY ps.`id` ASC'
        ) as $source) {
            if (($source['photo_state'] ?? null) !== 'ACTIVE'
                || !hash_equals((string) ($source['source_checksum'] ?? ''), (string) ($source['media_checksum'] ?? ''))
            ) {
                $issues[] = self::issue('PHOTO_SOURCE_REFERENCE_DRIFT', 'MANUAL_REVIEW', 'source:' . (int) ($source['id'] ?? 0));
            }
        }

        return [
            'issues' => $issues,
            'counts' => [
                'albums' => count($albums),
                'spotlights' => count($spotlights),
                'persons' => count($persons),
                'active_person_merges' => count($mergeTargets),
                'duplicates' => count($duplicateRows),
                'batches' => count($batches),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $path = self::statusPath();
        if (!is_file($path) || is_link($path)) {
            return self::statusMissing('尚未执行数据一致性检查。');
        }
        $json = @file_get_contents($path);
        try {
            $record = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            $record = null;
        }
        if (!is_array($record)
            || (int) ($record['reconciliation_version'] ?? 0) !== self::VERSION
            || !is_string($record['reconciler_sha256'] ?? null)
            || !hash_equals(self::selfDigest(), (string) $record['reconciler_sha256'])
            || !is_string($record['timestamp'] ?? null)
            || strtotime((string) $record['timestamp']) === false
        ) {
            return self::statusMissing('数据一致性检查记录需要重新执行。');
        }
        $age = max(0, time() - (int) strtotime((string) $record['timestamp']));
        if ($age > self::FRESHNESS_SECONDS) {
            return [
                'state' => 'STALE', 'label' => '需要重新检查', 'message' => '数据一致性检查已超过有效期。',
                'timestamp' => (string) $record['timestamp'], 'issue_count' => (int) ($record['issue_count'] ?? 0),
                'record' => $record,
            ];
        }
        $count = (int) ($record['issue_count'] ?? -1);
        if ($count < 0) {
            return self::statusMissing('数据一致性检查记录无效。');
        }
        return [
            'state' => $count === 0 ? 'CLEAR' : 'ISSUES',
            'label' => $count === 0 ? '正常' : '发现 ' . $count . ' 个待处理问题',
            'message' => $count === 0 ? 'Piwigo、ClassIdentity 与媒体存储当前一致。' : '已分类为安全自动修复、人工复核或隔离处理。',
            'timestamp' => (string) $record['timestamp'],
            'issue_count' => $count,
            'record' => $record,
        ];
    }

    /** @param array<string, mixed> $record */
    public static function persist(array $record): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('class_identity_reconciliation_directory_unavailable');
        }
        if (is_link($directory)) {
            throw new \RuntimeException('class_identity_reconciliation_directory_untrusted');
        }
        @chmod($directory, 0770);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $temporary = $directory . '/.reconciliation-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('class_identity_reconciliation_write_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('class_identity_reconciliation_write_failed');
            }
            @chmod($temporary, 0660);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, self::statusPath())) {
                throw new \RuntimeException('class_identity_reconciliation_publish_failed');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function hasEraAssociation(int $imageId, int $rootId): bool
    {
        if ($imageId <= 0 || $rootId <= 0) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM `' . $this->prefix . 'image_category` ic JOIN `' . $this->prefix . 'categories` c ON c.`id` = ic.`category_id` '
            . 'WHERE ic.`image_id` = ? AND (c.`id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) LIMIT 1'
        );
        if (!$statement instanceof \mysqli_stmt) {
            throw new \RuntimeException('class_identity_reconciliation_query_prepare_failed');
        }
        try {
            $statement->bind_param('iii', $imageId, $rootId, $rootId);
            if (!$statement->execute()) {
                throw new \RuntimeException('class_identity_reconciliation_query_execute_failed');
            }
            $result = $statement->get_result();
            return $result instanceof \mysqli_result && $result->num_rows === 1;
        } finally {
            $statement->close();
        }
    }

    private function effectiveEraForImage(int $imageId, int $heritageRoot, int $livingRoot): ?string
    {
        if ($imageId <= 0 || $heritageRoot <= 0 || $livingRoot <= 0) {
            return null;
        }
        $heritage = $this->hasEraAssociation($imageId, $heritageRoot);
        $living = $this->hasEraAssociation($imageId, $livingRoot);
        if ($heritage === $living) {
            return null;
        }
        return $heritage ? 'HERITAGE' : 'LIVING';
    }

    private static function categoryEra(
        int $categoryId,
        string $uppercats,
        int $heritageRoot,
        int $livingRoot,
    ): ?string {
        if ($categoryId <= 0 || $heritageRoot <= 0 || $livingRoot <= 0) {
            return null;
        }
        $ancestors = array_values(array_filter(array_map('intval', explode(',', $uppercats)), static fn(int $id): bool => $id > 0));
        $heritage = $categoryId === $heritageRoot || in_array($heritageRoot, $ancestors, true);
        $living = $categoryId === $livingRoot || in_array($livingRoot, $ancestors, true);
        if ($heritage === $living) {
            return null;
        }
        return $heritage ? 'HERITAGE' : 'LIVING';
    }

    private static function opaqueSubject(string $kind, string $opaqueValue): string
    {
        return $kind . ':' . hash('sha256', $opaqueValue);
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql): array
    {
        $result = $this->db->query($sql);
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('class_identity_reconciliation_query_failed');
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private function scalarInt(string $sql): int
    {
        $rows = $this->all($sql);
        if (count($rows) !== 1) {
            return 0;
        }
        $value = array_values($rows[0])[0] ?? 0;
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array{code:string,disposition:string,subject:string} */
    private static function issue(string $code, string $disposition, string $subject): array
    {
        return ['code' => $code, 'disposition' => $disposition, 'subject' => $subject];
    }

    private static function rejectedRetentionDays(): int
    {
        $value = getenv('CLASS_ARCHIVE_REJECTED_RETENTION_DAYS');
        $days = is_string($value) && ctype_digit($value) ? (int) $value : 30;
        return max(7, min(3650, $days));
    }

    private static function normalizePiwigoPath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), './');
        if ($path === '' || str_contains($path, '..') || !preg_match('#\A(?:upload|galleries)/[A-Za-z0-9._/-]+\z#D', $path)) {
            return null;
        }
        return $path;
    }

    private static function existingPiwigoFile(string $path): ?string
    {
        $full = PHPWG_ROOT_PATH . $path;
        $root = PHPWG_ROOT_PATH . (str_starts_with($path, 'upload/') ? 'upload' : 'galleries');
        $rootReal = realpath($root);
        $fullReal = realpath($full);
        if ($rootReal === false || $fullReal === false || is_link($full) || !is_file($fullReal)) {
            return null;
        }
        $rootNormalized = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $fullNormalized = str_replace('\\', '/', $fullReal);
        return str_starts_with($fullNormalized, $rootNormalized) ? $fullReal : null;
    }

    private static function pendingFile(string $reference): ?string
    {
        if (!preg_match('#\Aclass_identity_pending/[a-f0-9]{48}\.(?:jpg|jpeg|png|webp)\z#D', $reference)) {
            return null;
        }
        $root = PHPWG_ROOT_PATH . 'upload/class_identity_pending';
        $rootReal = realpath($root);
        $file = $root . '/' . substr($reference, strlen('class_identity_pending/'));
        $fileReal = realpath($file);
        if ($rootReal === false || $fileReal === false || is_link($file) || !is_file($fileReal)) {
            return null;
        }
        return str_starts_with(str_replace('\\', '/', $fileReal), rtrim(str_replace('\\', '/', $rootReal), '/') . '/') ? $fileReal : null;
    }

    /** @return list<string> */
    private static function discoverOriginals(): array
    {
        $paths = [];
        $applicationRoot = realpath(PHPWG_ROOT_PATH);
        if ($applicationRoot === false || is_link(PHPWG_ROOT_PATH)) {
            return [];
        }
        $applicationRoot = rtrim(str_replace('\\', '/', $applicationRoot), '/') . '/';
        foreach (['upload', 'galleries'] as $rootName) {
            $root = PHPWG_ROOT_PATH . $rootName;
            if (!is_dir($root) || is_link($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }
                $fullPath = str_replace('\\', '/', $file->getPathname());
                if (!str_starts_with($fullPath, $applicationRoot)) {
                    continue;
                }
                $relative = substr($fullPath, strlen($applicationRoot));
                if (str_starts_with($relative, 'upload/class_identity_pending/')) {
                    continue;
                }
                if (!preg_match('/\.(?:jpg|jpeg|png|webp)\z/iD', $relative)) {
                    continue;
                }
                $paths[] = $relative;
            }
        }
        sort($paths, SORT_STRING);
        return array_slice($paths, 0, 10000);
    }

    /** @return array{file_count:int,unsafe_entries:list<string>} */
    private static function derivativeSummary(): array
    {
        $root = PHPWG_ROOT_PATH . '_data/i';
        if (!is_dir($root) || is_link($root)) {
            return ['file_count' => 0, 'unsafe_entries' => ['derivative-root']];
        }
        $count = 0;
        $unsafe = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isLink() || !$file->isFile()) {
                $unsafe[] = 'derivative:' . hash('sha256', $file->getPathname());
                continue;
            }
            $count++;
            if (((int) ($file->getPerms() & 0007)) !== 0) {
                $unsafe[] = 'derivative:' . hash('sha256', $file->getPathname());
            }
            if (count($unsafe) >= 100) {
                break;
            }
        }
        return ['file_count' => $count, 'unsafe_entries' => $unsafe];
    }

    private static function selfDigest(): string
    {
        // Findings depend on the constrained AI enum contract as well as this
        // scanner. A change in either invalidates a prior reconciliation
        // record, but neither file gives the reconciler permission to invoke
        // a model or repair rows itself.
        $paths = [__FILE__, __DIR__ . '/AiIndexService.php'];
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('class_identity_reconciliation_digest_unavailable');
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new \RuntimeException('class_identity_reconciliation_digest_unavailable');
            }
            hash_update($context, basename($path) . "\0" . $hash . "\n");
        }
        return hash_final($context);
    }

    private static function statusPath(): string
    {
        return self::directory() . '/' . self::FILE_NAME;
    }

    private static function directory(): string
    {
        $dataRoot = PHPWG_ROOT_PATH . '_data';
        $realDataRoot = realpath($dataRoot);
        if ($realDataRoot === false || is_link($dataRoot) || !is_dir($realDataRoot)) {
            throw new \RuntimeException('class_identity_reconciliation_data_root_untrusted');
        }
        return rtrim(str_replace('\\', '/', $realDataRoot), '/') . '/class-archive';
    }

    /** @return array<string, mixed> */
    private static function statusMissing(string $message): array
    {
        return ['state' => 'MISSING', 'label' => '需要重新检查', 'message' => $message, 'timestamp' => null, 'issue_count' => 0, 'record' => null];
    }
}
