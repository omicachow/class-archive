<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Access;
use ClassIdentity\Audit;
use ClassIdentity\Repository;

/** Business archive metadata and album-association boundary. */
final class ClassIdentityArchiveService
{
    private const PRECISIONS = ['EXACT', 'DAY', 'MONTH', 'TERM', 'YEAR', 'EVENT_ONLY', 'UNKNOWN'];
    private const CONFIDENCES = ['HIGH', 'MEDIUM', 'LOW', 'UNKNOWN'];
    private const DATE_SOURCES = ['ARCHIVE_CONFIRMED', 'EVENT_INFERENCE', 'EXIF_TRUSTED', 'UNKNOWN'];

    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return list<array<string, mixed>> */
    public function albums(): array
    {
        $this->requireAdmin();
        global $prefixeTable;
        return $this->repository->fetchAll(
            'SELECT `id`,`name`,`permalink`,`uppercats`,`status`,`visible` FROM `' . $prefixeTable . 'categories` '
            . 'ORDER BY `global_rank`,`name` LIMIT 1000',
        );
    }

    public function createOfficialAlbum(int $adminUserId, string $era, string $name, ?string $comment, string $reason): int
    {
        $admin = $this->requireAdmin($adminUserId);
        $era = strtoupper(trim($era));
        if (!in_array($era, ['HERITAGE', 'LIVING'], true)) {
            throw new InvalidArgumentException('archive_era_invalid');
        }
        $name = self::boundedText($name, 190);
        if ($name === null) {
            throw new InvalidArgumentException('archive_album_name_required');
        }
        $comment = self::boundedText($comment, 2000);
        $reason = Audit::validateReason($reason, true);
        if ($reason === null) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }
        $parentId = $this->rootId($era === 'HERITAGE' ? 'class-archive-heritage' : 'class-archive-living');
        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        if (!function_exists('create_virtual_category')) {
            throw new RuntimeException('piwigo_album_service_unavailable');
        }
        $created = create_virtual_category($name, $parentId, [
            'status' => 'private',
            // Piwigo treats `visible=false` as a lock which its native ACL
            // calculation denies even to an otherwise authorized private
            // group. An official Class Archive child remains private through
            // inherited group access; it must be visible inside that allowed
            // scope so the canonical MediaGuard path can serve it.
            'visible' => true,
            'commentable' => false,
            'inherit' => true,
            'comment' => $comment ?? '',
        ]);
        if (!is_array($created) || !isset($created['id']) || !ctype_digit((string) $created['id'])) {
            throw new RuntimeException('piwigo_album_create_failed');
        }
        $albumId = (int) $created['id'];
        try {
            // Piwigo remains the album manager, while the product-facing
            // opaque id and audit are created by the Class Archive domain.
            // The public UI never serializes this numeric category id.
            \ClassIdentity\AlbumService::fromPiwigo()->ensureMapping(
                $adminUserId,
                $albumId,
                'OFFICIAL',
                $era,
                null,
                $comment,
                null,
                null,
                $reason,
            );
        } catch (Throwable $error) {
            // The Core category tables are MyISAM. A newly created empty album
            // is safe to remove when its stable mapping/audit did not commit.
            if (function_exists('delete_categories')) {
                try {
                    delete_categories([$albumId], 'no_delete');
                } catch (Throwable) {
                    // Preserve the original audit failure; the orphan is
                    // visible to System/Admin review rather than hidden.
                }
            }
            throw $error;
        }
        return $albumId;
    }

    /** @return list<array<string, mixed>> */
    public function images(int $limit = 250): array
    {
        $this->requireAdmin();
        global $prefixeTable;
        $limit = max(1, min(500, $limit));
        $heritage = $this->rootId('class-archive-heritage');
        $living = $this->rootId('class-archive-living');
        $rows = $this->repository->fetchAll(
            'SELECT DISTINCT i.`id` AS `image_id`,i.`file`,i.`width`,i.`height`,i.`date_available`, '
            . 'ai.`era`,ai.`archive_date`,ai.`date_precision`,ai.`date_confidence`,ai.`date_source`,ai.`event_label`,ai.`official`, '
            . 's.`id` AS `submission_id`,s.`original_filename`,s.`state` AS `submission_state`, '
            . 'ci.`roster_code`,ci.`real_name`,a.`family_relationship` '
            . 'FROM `' . $prefixeTable . 'images` i '
            . 'JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id` = i.`id` '
            . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id` = ic.`category_id` '
            . 'LEFT JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id` = i.`id` '
            . 'LEFT JOIN `' . $this->repository->table('submission') . '` s ON s.`id` = ai.`source_submission_id` '
            . 'LEFT JOIN `' . $this->repository->table('identity') . '` ci ON ci.`id` = s.`identity_id` '
            . 'LEFT JOIN `' . $this->repository->table('account') . '` a ON a.`id` = s.`account_id` '
            . 'WHERE (c.`id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0 OR c.`id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) '
            . 'ORDER BY COALESCE(ai.`archive_date`, \'9999-12-31\') ASC,i.`id` DESC LIMIT ' . $limit,
            [$heritage, $heritage, $living, $living],
        );
        foreach ($rows as &$row) {
            $row['era_label'] = self::eraLabel((string) ($row['era'] ?? ''));
            $row['precision_label'] = self::precisionLabel((string) ($row['date_precision'] ?? 'UNKNOWN'));
            $row['confidence_label'] = self::confidenceLabel((string) ($row['date_confidence'] ?? 'UNKNOWN'));
            $row['date_source_label'] = self::dateSourceLabel((string) ($row['date_source'] ?? 'UNKNOWN'));
            $row['submission_state_label'] = match ((string) ($row['submission_state'] ?? '')) {
                'PENDING' => '待审核',
                'APPROVED' => '已通过',
                'REJECTED' => '已拒绝',
                default => $row['submission_state'] ? '状态异常' : '非投稿来源',
            };
        }
        unset($row);
        return $rows;
    }

    /** @return array{class_photo_id:string,projection_kinds:list<string>,projection_rebuild_mode:string} */
    public function saveMetadata(int $adminUserId, int $imageId, string $era, ?string $archiveDate, string $precision, string $confidence, ?string $dateSource, ?string $eventLabel, bool $official, ?int $albumId, string $reason): array
    {
        $admin = $this->requireAdmin($adminUserId);
        $era = strtoupper(trim($era));
        if (!in_array($era, ['HERITAGE', 'LIVING'], true)) {
            throw new InvalidArgumentException('archive_era_invalid');
        }
        $precision = self::normalizePrecision($precision);
        $confidence = strtoupper(trim($confidence));
        if (!in_array($confidence, self::CONFIDENCES, true)) {
            throw new InvalidArgumentException('archive_confidence_invalid');
        }
        $archiveDate = self::normalizeDate($archiveDate, $precision);
        $eventLabel = self::boundedText($eventLabel, 190);
        $dateSource = self::normalizeDateSource($dateSource, $archiveDate, $precision, $confidence, $eventLabel);
        $reason = Audit::validateReason($reason, true);
        if ($reason === null) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }
        $this->imageExists($imageId);
        $selectedAlbum = $this->requireEraAlbum($era, $albumId);
        $photo = $this->repository->fetchOne(
            'SELECT `class_photo_id`,`state` FROM `' . $this->repository->table('photo') . '` WHERE `piwigo_image_id`=? LIMIT 1',
            [$imageId],
        );
        $before = $this->repository->fetchOne(
            'SELECT `era` FROM `' . $this->repository->table('archive_image') . '` WHERE `piwigo_image_id`=? LIMIT 1',
            [$imageId],
        );
        if ($photo === null || ($photo['state'] ?? null) !== \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE || $before === null) {
            throw new RuntimeException('archive_canonical_mapping_required');
        }
        if (($before['era'] ?? null) !== $era) {
            // The legacy single-item form has no complete old-album removal
            // review. Era transitions are therefore confined to the new bulk
            // organizer, which has explicit confirmation and compensation.
            throw new RuntimeException('archive_era_change_requires_photo_ui');
        }

        $albumService = \ClassIdentity\AlbumService::fromPiwigo();
        $mapping = $albumService->findByPiwigoCategoryId($selectedAlbum);
        if ($mapping === null) {
            $mapping = $albumService->ensureMapping(
                $adminUserId,
                $selectedAlbum,
                'OFFICIAL',
                $era,
                null,
                null,
                null,
                null,
                $reason,
            );
        }
        if (($mapping['album_type'] ?? null) !== 'OFFICIAL' || ($mapping['era'] ?? null) !== $era) {
            throw new RuntimeException('archive_album_mapping_conflict');
        }

        $changes = [
            'archive_date' => $archiveDate,
            'date_precision' => $precision,
            'date_confidence' => $confidence,
            'date_source' => $dateSource,
            'event_label' => $eventLabel,
            'official' => $official,
            'add_album_ids' => [(string) $mapping['class_album_id']],
            'remove_album_ids' => [],
        ];
        $classPhotoId = \ClassIdentity\DomainSupport::binaryToId((string) $photo['class_photo_id']);
        $bulkResult = \ClassIdentity\BulkArchiveService::fromPiwigo()->apply(
            $adminUserId,
            [$classPhotoId],
            $changes,
            $reason,
            false,
        );
        return [
            'class_photo_id' => $classPhotoId,
            'projection_kinds' => ProjectionMutationBoundary::archiveKinds($changes),
            'projection_rebuild_mode' => (string) ($bulkResult['projection_rebuild_mode'] ?? ''),
        ];
    }

    public static function eraLabel(string $era): string
    {
        return match ($era) {
            'HERITAGE' => '班级历史',
            'LIVING' => '毕业后动态',
            default => '未分类',
        };
    }

    public static function precisionLabel(string $precision): string
    {
        return match ($precision) {
            'EXACT' => '日期精确',
            'DAY' => '仅确定到日',
            'MONTH' => '仅确定到月份',
            'TERM' => '仅确定学期',
            'YEAR' => '仅确定年份',
            'EVENT_ONLY' => '仅确定事件',
            'UNKNOWN' => '日期未知',
            default => '日期未知',
        };
    }

    public static function confidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'HIGH' => '高可信',
            'MEDIUM' => '中可信',
            'LOW' => '低可信',
            default => '未评估',
        };
    }

    public static function dateSourceLabel(string $source): string
    {
        return match ($source) {
            'ARCHIVE_CONFIRMED' => '档案确认日期',
            'EVENT_INFERENCE' => '档案事件推定',
            'EXIF_TRUSTED' => '已核验 EXIF 日期',
            default => '日期来源未确认',
        };
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

    private function imageExists(int $imageId): void
    {
        global $prefixeTable;
        if ($imageId <= 0 || $this->repository->fetchOne('SELECT `id` FROM `' . $prefixeTable . 'images` WHERE `id` = ? LIMIT 1', [$imageId]) === null) {
            throw new InvalidArgumentException('archive_image_invalid');
        }
    }

    private function rootId(string $permalink): int
    {
        global $prefixeTable;
        $row = $this->repository->fetchOne('SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink` = ? LIMIT 1', [$permalink]);
        if ($row === null) {
            throw new RuntimeException('archive_era_root_missing');
        }
        return (int) $row['id'];
    }

    private function requireEraAlbum(string $era, ?int $albumId): int
    {
        global $prefixeTable;
        $root = $this->rootId($era === 'HERITAGE' ? 'class-archive-heritage' : 'class-archive-living');
        $selected = $albumId ?? $root;
        $row = $this->repository->fetchOne('SELECT `id`,`uppercats` FROM `' . $prefixeTable . 'categories` WHERE `id` = ? LIMIT 1', [$selected]);
        if ($row === null || ($selected !== $root && !str_contains(',' . (string) $row['uppercats'] . ',', ',' . $root . ','))) {
            throw new InvalidArgumentException('archive_album_outside_era');
        }
        return $selected;
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

    /**
     * A source is curator-supplied evidence, never a silent upload-time or
     * unverified EXIF fallback.  In particular, EXIF_TRUSTED requires a
     * human-confirmed high-confidence record before it can affect timeline
     * ordering or user-visible chronology.
     */
    private static function normalizeDateSource(?string $source, ?string $archiveDate, string $precision, string $confidence, ?string $eventLabel): string
    {
        $source = strtoupper(trim((string) $source));
        if (!in_array($source, self::DATE_SOURCES, true)) {
            throw new InvalidArgumentException('archive_date_source_invalid');
        }
        if ($source === 'UNKNOWN') {
            if ($archiveDate !== null || $precision !== 'UNKNOWN' || $eventLabel !== null) {
                throw new InvalidArgumentException('archive_date_source_evidence_mismatch');
            }
            return $source;
        }
        if (in_array($source, ['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'], true)) {
            if ($archiveDate === null || !in_array($precision, ['EXACT', 'DAY', 'MONTH', 'YEAR'], true)) {
                throw new InvalidArgumentException('archive_date_source_evidence_mismatch');
            }
            if ($source === 'EXIF_TRUSTED' && $confidence !== 'HIGH') {
                throw new InvalidArgumentException('archive_date_source_exif_requires_high_confidence');
            }
            return $source;
        }
        if ($archiveDate !== null || $eventLabel === null || !in_array($precision, ['TERM', 'EVENT_ONLY'], true)) {
            throw new InvalidArgumentException('archive_date_source_evidence_mismatch');
        }
        return 'EVENT_INFERENCE';
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
