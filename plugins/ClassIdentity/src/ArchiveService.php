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
            'visible' => false,
            'commentable' => false,
            'inherit' => true,
            'comment' => $comment ?? '',
        ]);
        if (!is_array($created) || !isset($created['id']) || !ctype_digit((string) $created['id'])) {
            throw new RuntimeException('piwigo_album_create_failed');
        }
        $albumId = (int) $created['id'];
        try {
            (new Audit($this->repository))->append([
                'actor_principal_id' => (int) $admin['principal_id'],
                'actor_user_id' => (int) $admin['piwigo_user_id'],
                'actor_kind' => 'SYSTEM_ADMIN',
                'action' => 'ARCHIVE_ALBUM_CREATE',
                'target_type' => 'ALBUM',
                'target_id' => (string) $albumId,
                'new_value' => ['era' => $era, 'name' => $name, 'official' => 1],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        } catch (Throwable $error) {
            // The Core category tables are MyISAM. A newly created, empty
            // album is safe to remove if its audit event cannot be recorded.
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
            . 'ai.`era`,ai.`archive_date`,ai.`date_precision`,ai.`date_confidence`,ai.`event_label`,ai.`official`, '
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

    public function saveMetadata(int $adminUserId, int $imageId, string $era, ?string $archiveDate, string $precision, string $confidence, ?string $eventLabel, bool $official, ?int $albumId, string $reason): void
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
        $reason = Audit::validateReason($reason, true);
        if ($reason === null) {
            throw new InvalidArgumentException('class_identity_audit_reason_required');
        }
        $this->imageExists($imageId);
        $selectedAlbum = $this->requireEraAlbum($era, $albumId);
        global $prefixeTable;

        $this->repository->transaction(function (Repository $repository) use ($admin, $imageId, $era, $archiveDate, $precision, $confidence, $eventLabel, $official, $reason): void {
            $before = $repository->fetchOne(
                'SELECT `era`,`archive_date`,`date_precision`,`date_confidence`,`event_label`,`official` FROM `' . $repository->table('archive_image') . '` WHERE `piwigo_image_id` = ? FOR UPDATE',
                [$imageId],
            );
            $repository->execute(
                'INSERT INTO `' . $repository->table('archive_image') . '` '
                . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`event_label`,`official`,`created_at`,`updated_at`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `era`=VALUES(`era`),`archive_date`=VALUES(`archive_date`),`date_precision`=VALUES(`date_precision`),'
                . '`date_confidence`=VALUES(`date_confidence`),`event_label`=VALUES(`event_label`),`official`=VALUES(`official`),`updated_at`=UTC_TIMESTAMP(6)',
                [$imageId, $era, $archiveDate, $precision, $confidence, $eventLabel, $official ? 1 : 0],
            );
            (new Audit($repository))->append([
                'actor_principal_id' => (int) $admin['principal_id'],
                'actor_user_id' => (int) $admin['piwigo_user_id'],
                'actor_kind' => 'SYSTEM_ADMIN',
                'action' => 'ARCHIVE_METADATA_UPDATE',
                'target_type' => 'IMAGE',
                'target_id' => (string) $imageId,
                'old_value' => $before ?? ['state' => 'UNRECORDED'],
                'new_value' => [
                    'era' => $era,
                    'archive_date' => $archiveDate,
                    'date_precision' => $precision,
                    'date_confidence' => $confidence,
                    'event_label' => $eventLabel,
                    'official' => $official ? 1 : 0,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });

        if (function_exists('associate_images_to_categories')) {
            associate_images_to_categories([$imageId], [$selectedAlbum]);
        } else {
            throw new RuntimeException('piwigo_album_service_unavailable');
        }
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
