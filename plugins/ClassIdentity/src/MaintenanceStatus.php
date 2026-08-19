<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Digest-bound, non-secret result of the most recent maintenance run.
 *
 * The record is informational and operational only. It never influences an
 * authorization decision; expired or invalid evidence is surfaced as stale so
 * a deployment cannot mistake an old maintenance result for a current one.
 */
final class MaintenanceStatus
{
    public const VERSION = 1;
    public const FRESHNESS_SECONDS = 48 * 3600;

    private const DATA_DIRECTORY = '_data/class-archive';
    private const FILE_NAME = 'maintenance-last.json';

    /** @param array<string, mixed> $tasks */
    public static function create(array $tasks, string $result): array
    {
        if (!in_array($result, ['PASS', 'ATTENTION', 'FAILED'], true)) {
            throw new \InvalidArgumentException('class_identity_maintenance_result_invalid');
        }
        return [
            'maintenance_version' => self::VERSION,
            'runner_sha256' => self::runnerDigest(),
            'timestamp' => gmdate('c'),
            'result' => $result,
            'tasks' => $tasks,
        ];
    }

    /** @param array<string, mixed> $record */
    public static function persist(array $record): void
    {
        if ((int) ($record['maintenance_version'] ?? 0) !== self::VERSION || !is_array($record['tasks'] ?? null)) {
            throw new \InvalidArgumentException('class_identity_maintenance_record_invalid');
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('class_identity_maintenance_directory_unavailable');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || is_link($directory) || !hash_equals(str_replace('\\', '/', $directory), str_replace('\\', '/', $realDirectory))) {
            throw new \RuntimeException('class_identity_maintenance_directory_untrusted');
        }
        @chmod($directory, 0770);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $temporary = $directory . '/.maintenance-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('class_identity_maintenance_write_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('class_identity_maintenance_write_failed');
            }
            @chmod($temporary, 0660);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, self::path())) {
                throw new \RuntimeException('class_identity_maintenance_publish_failed');
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

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $path = self::path();
        if (!is_file($path) || is_link($path)) {
            return self::missing('尚未执行后台维护。');
        }
        $contents = @file_get_contents($path);
        try {
            $record = is_string($contents) ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            $record = null;
        }
        if (!is_array($record)
            || (int) ($record['maintenance_version'] ?? 0) !== self::VERSION
            || !is_string($record['runner_sha256'] ?? null)
            || !hash_equals(self::runnerDigest(), (string) $record['runner_sha256'])
            || !is_string($record['timestamp'] ?? null)
            || strtotime((string) $record['timestamp']) === false
            || !in_array($record['result'] ?? null, ['PASS', 'ATTENTION', 'FAILED'], true)
            || !is_array($record['tasks'] ?? null)
        ) {
            return self::missing('后台维护记录需要重新执行。');
        }
        $age = max(0, time() - (int) strtotime((string) $record['timestamp']));
        if ($age > self::FRESHNESS_SECONDS) {
            return [
                'state' => 'STALE',
                'label' => '需要重新执行',
                'message' => '后台维护记录已超过有效期。',
                'timestamp' => (string) $record['timestamp'],
                'result' => (string) $record['result'],
                'age_seconds' => $age,
                'tasks' => $record['tasks'],
            ];
        }
        return [
            'state' => (string) $record['result'] === 'PASS' ? 'VERIFIED' : 'ATTENTION',
            'label' => (string) $record['result'] === 'PASS' ? '已完成' : '需要关注',
            'message' => (string) $record['result'] === 'PASS' ? '后台维护已按当前策略完成。' : '后台维护发现需要关注的项目。',
            'timestamp' => (string) $record['timestamp'],
            'result' => (string) $record['result'],
            'age_seconds' => $age,
            'tasks' => $record['tasks'],
        ];
    }

    public static function lockPath(): string
    {
        return self::directory() . '/maintenance.lock';
    }

    private static function directory(): string
    {
        $dataRoot = PHPWG_ROOT_PATH . '_data';
        $realDataRoot = realpath($dataRoot);
        if ($realDataRoot === false || is_link($dataRoot) || !is_dir($realDataRoot)) {
            throw new \RuntimeException('class_identity_maintenance_data_root_untrusted');
        }
        return rtrim(str_replace('\\', '/', $realDataRoot), '/') . '/class-archive';
    }

    private static function path(): string
    {
        return self::directory() . '/' . self::FILE_NAME;
    }

    private static function runnerDigest(): string
    {
        $paths = [__FILE__, '/workspace/infra/scripts/run-maintenance.php'];
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('class_identity_maintenance_runner_missing');
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new \RuntimeException('class_identity_maintenance_runner_digest_failed');
            }
            hash_update($context, basename($path) . "\0" . $hash . "\n");
        }
        return hash_final($context);
    }

    /** @return array<string, mixed> */
    private static function missing(string $message): array
    {
        return [
            'state' => 'MISSING',
            'label' => '需要重新执行',
            'message' => $message,
            'timestamp' => null,
            'result' => null,
            'age_seconds' => null,
            'tasks' => [],
        ];
    }
}
