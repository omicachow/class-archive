<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Digest-bound proof that a synthetic backup was restored and compared with a
 * deterministic, non-secret state fingerprint.
 */
final class BackupRestoreEvidence
{
    public const VERSION = 1;
    public const FRESHNESS_SECONDS = 90 * 86400;

    private const DATA_DIRECTORY = '_data/class-archive';
    private const FILE_NAME = 'backup-restore-drill.json';

    /** @return array<string, mixed> */
    public static function create(string $bundle, string $fixtureSha256, int $rtoSeconds): array
    {
        if (preg_match('/\Aclass-archive-[0-9]{8}T[0-9]{6}Z\z/D', $bundle) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $fixtureSha256) !== 1
            || $rtoSeconds < 0 || $rtoSeconds > 86400) {
            throw new \InvalidArgumentException('class_identity_backup_restore_evidence_input_invalid');
        }
        return [
            'backup_restore_version' => self::VERSION,
            'evidence_sha256' => self::selfDigest(),
            'timestamp' => gmdate('c'),
            'bundle' => $bundle,
            'fixture_sha256' => $fixtureSha256,
            'rto_seconds' => $rtoSeconds,
            'result' => 'PASS',
        ];
    }

    /** @param array<string, mixed> $record */
    public static function persist(array $record): void
    {
        if ((int) ($record['backup_restore_version'] ?? 0) !== self::VERSION || ($record['result'] ?? null) !== 'PASS') {
            throw new \InvalidArgumentException('class_identity_backup_restore_evidence_invalid');
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('class_identity_backup_restore_directory_unavailable');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || is_link($directory) || !hash_equals(str_replace('\\', '/', $directory), str_replace('\\', '/', $realDirectory))) {
            throw new \RuntimeException('class_identity_backup_restore_directory_untrusted');
        }
        @chmod($directory, 0770);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $temporary = $directory . '/.backup-restore-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('class_identity_backup_restore_write_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('class_identity_backup_restore_write_failed');
            }
            @chmod($temporary, 0660);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, self::path())) {
                throw new \RuntimeException('class_identity_backup_restore_publish_failed');
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
            return self::missing('尚未完成合成环境备份恢复演练。');
        }
        $contents = @file_get_contents($path);
        try {
            $record = is_string($contents) ? json_decode($contents, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            $record = null;
        }
        if (!is_array($record)
            || (int) ($record['backup_restore_version'] ?? 0) !== self::VERSION
            || !is_string($record['evidence_sha256'] ?? null)
            || !hash_equals(self::selfDigest(), (string) $record['evidence_sha256'])
            || !is_string($record['timestamp'] ?? null)
            || strtotime((string) $record['timestamp']) === false
            || !is_string($record['fixture_sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', (string) $record['fixture_sha256']) !== 1
            || ($record['result'] ?? null) !== 'PASS') {
            return self::missing('备份恢复演练记录需要重新执行。');
        }
        $age = max(0, time() - (int) strtotime((string) $record['timestamp']));
        if ($age > self::FRESHNESS_SECONDS) {
            return [
                'state' => 'STALE', 'label' => '需要重新演练', 'message' => '备份恢复演练已超过有效期。',
                'timestamp' => (string) $record['timestamp'], 'rto_seconds' => (int) ($record['rto_seconds'] ?? 0), 'record' => $record,
            ];
        }
        return [
            'state' => 'VERIFIED', 'label' => '已演练', 'message' => '最近一次合成环境恢复与确定性状态指纹一致。',
            'timestamp' => (string) $record['timestamp'], 'rto_seconds' => (int) ($record['rto_seconds'] ?? 0), 'record' => $record,
        ];
    }

    private static function directory(): string
    {
        $dataRoot = PHPWG_ROOT_PATH . '_data';
        $realDataRoot = realpath($dataRoot);
        if ($realDataRoot === false || is_link($dataRoot) || !is_dir($realDataRoot)) {
            throw new \RuntimeException('class_identity_backup_restore_data_root_untrusted');
        }
        return rtrim(str_replace('\\', '/', $realDataRoot), '/') . '/class-archive';
    }

    private static function path(): string
    {
        return self::directory() . '/' . self::FILE_NAME;
    }

    private static function selfDigest(): string
    {
        $hash = hash_file('sha256', __FILE__);
        if (!is_string($hash)) {
            throw new \RuntimeException('class_identity_backup_restore_digest_unavailable');
        }
        return $hash;
    }

    /** @return array<string, mixed> */
    private static function missing(string $message): array
    {
        return ['state' => 'MISSING', 'label' => '需要演练', 'message' => $message, 'timestamp' => null, 'rto_seconds' => null, 'record' => null];
    }
}
