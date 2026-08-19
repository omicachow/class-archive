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
    public const VERSION = 1;
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

        $submissionTable = '`' . $this->prefix . 'class_identity_submission`';
        $archiveTable = '`' . $this->prefix . 'class_identity_archive_image`';
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
        $path = __FILE__;
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('class_identity_reconciliation_digest_unavailable');
        }
        return $hash;
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
