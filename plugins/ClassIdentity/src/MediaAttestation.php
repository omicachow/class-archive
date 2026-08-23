<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Digest-bound evidence for the MediaGuard HTTP matrix.
 *
 * This is an operational release gate only. It is intentionally never read by
 * MediaGuard while authorizing a request: stale or absent evidence blocks a
 * production promotion, while the media path itself continues to fail closed.
 */
final class MediaAttestation
{
    public const VERSION = 1;
    public const FRESHNESS_SECONDS = 30 * 86400;

    private const DATA_DIRECTORY = '_data/class-archive';
    private const FILE_NAME = 'media-attestation.json';

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $path = self::path();
        if (!is_file($path) || is_link($path)) {
            return self::missing('未找到媒体访问安全验证记录。');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 65536) {
            return self::missing('媒体访问安全验证记录无法读取。');
        }
        try {
            $record = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::missing('媒体访问安全验证记录格式无效。');
        }
        if (!is_array($record)) {
            return self::missing('媒体访问安全验证记录格式无效。');
        }

        $required = [
            'media_attestation_version', 'commit', 'policy_sha256', 'nginx_sha256',
            'media_guard_sha256', 'schema_sha256', 'schema_version', 'migration_version',
            'test_suite_sha256', 'test_suite_version', 'probe_count', 'result', 'timestamp',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                return self::missing('媒体访问安全验证记录不完整。');
            }
        }
        if ((int) $record['media_attestation_version'] !== self::VERSION
            || !is_string($record['commit']) || preg_match('/\A[0-9a-f]{40}\z/D', $record['commit']) !== 1
            || !is_string($record['result']) || $record['result'] !== 'PASS'
            || !is_int($record['probe_count']) || $record['probe_count'] < 1
            || !is_string($record['timestamp']) || strtotime($record['timestamp']) === false
        ) {
            return self::missing('媒体访问安全验证记录未通过完整性校验。');
        }

        try {
            $expected = self::currentEvidence();
            $currentCommit = BuildCommit::current();
        } catch (\Throwable) {
            return self::missing('无法重新计算媒体访问安全验证摘要。');
        }

        $mismatches = [];
        if (!hash_equals($currentCommit, (string) $record['commit'])) {
            $mismatches[] = 'commit';
        }
        foreach (['policy_sha256', 'nginx_sha256', 'media_guard_sha256', 'schema_sha256', 'test_suite_sha256'] as $field) {
            if (!is_string($record[$field]) || !hash_equals($expected[$field], $record[$field])) {
                $mismatches[] = $field;
            }
        }
        if ((int) $record['schema_version'] !== $expected['schema_version']
            || (int) $record['migration_version'] !== $expected['migration_version']) {
            $mismatches[] = 'schema_or_migration_version';
        }
        $timestamp = (int) strtotime($record['timestamp']);
        $age = time() - $timestamp;
        if ($age < -300) {
            $mismatches[] = 'future_timestamp';
        }
        $age = max(0, $age);
        if ($mismatches !== []) {
            return [
                'state' => 'STALE',
                'label' => '需要重新验证',
                'message' => '相关安全代码、配置或迁移已发生变化。',
                'timestamp' => (string) $record['timestamp'],
                'commit' => (string) $record['commit'],
                'probe_count' => (int) $record['probe_count'],
                'age_seconds' => $age,
                'mismatches' => $mismatches,
                'record' => $record,
            ];
        }
        if ($age > self::FRESHNESS_SECONDS) {
            return [
                'state' => 'STALE',
                'label' => '需要重新验证',
                'message' => '媒体访问安全验证已超过有效期。',
                'timestamp' => (string) $record['timestamp'],
                'commit' => (string) $record['commit'],
                'probe_count' => (int) $record['probe_count'],
                'age_seconds' => $age,
                'mismatches' => ['freshness'],
                'record' => $record,
            ];
        }

        return [
            'state' => 'VERIFIED',
            'label' => '已验证',
            'message' => '当前媒体访问安全代码与已通过的 HTTP 验证一致。',
            'timestamp' => (string) $record['timestamp'],
            'commit' => (string) $record['commit'],
            'probe_count' => (int) $record['probe_count'],
            'age_seconds' => $age,
            'mismatches' => [],
            'record' => $record,
        ];
    }

    /** @return array<string, mixed> */
    public static function create(string $commit, int $probeCount, string $testSuiteVersion): array
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1 || $probeCount < 1 || $probeCount > 1000000) {
            throw new \InvalidArgumentException('class_identity_media_attestation_input_invalid');
        }
        if ($testSuiteVersion === '' || strlen($testSuiteVersion) > 64 || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $testSuiteVersion) !== 1) {
            throw new \InvalidArgumentException('class_identity_media_attestation_suite_version_invalid');
        }
        if (!hash_equals(BuildCommit::current(), $commit)) {
            throw new \InvalidArgumentException('class_identity_media_attestation_commit_mismatch');
        }
        $evidence = self::currentEvidence();
        return [
            'media_attestation_version' => self::VERSION,
            'commit' => $commit,
            'policy_sha256' => $evidence['policy_sha256'],
            'nginx_sha256' => $evidence['nginx_sha256'],
            'media_guard_sha256' => $evidence['media_guard_sha256'],
            'schema_sha256' => $evidence['schema_sha256'],
            'schema_version' => $evidence['schema_version'],
            'migration_version' => $evidence['migration_version'],
            'test_suite_sha256' => $evidence['test_suite_sha256'],
            'test_suite_version' => $testSuiteVersion,
            'probe_count' => $probeCount,
            'result' => 'PASS',
            'timestamp' => gmdate('c'),
        ];
    }

    /** @param array<string, mixed> $record */
    public static function persist(array $record): void
    {
        if (($record['result'] ?? null) !== 'PASS' || (int) ($record['media_attestation_version'] ?? 0) !== self::VERSION) {
            throw new \InvalidArgumentException('class_identity_media_attestation_record_invalid');
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('class_identity_media_attestation_directory_unavailable');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || is_link($directory) || !hash_equals(str_replace('\\', '/', $directory), str_replace('\\', '/', $realDirectory))) {
            throw new \RuntimeException('class_identity_media_attestation_directory_untrusted');
        }
        @chmod($directory, 0770);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $temporary = $directory . '/.media-attestation-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('class_identity_media_attestation_write_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('class_identity_media_attestation_write_failed');
            }
            @chmod($temporary, 0660);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, self::path())) {
                throw new \RuntimeException('class_identity_media_attestation_publish_failed');
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

    /** @return array{policy_sha256:string,nginx_sha256:string,media_guard_sha256:string,schema_sha256:string,schema_version:int,migration_version:int,test_suite_sha256:string} */
    private static function currentEvidence(): array
    {
        $policyRoot = PHPWG_ROOT_PATH . 'plugins/ClassArchivePolicy';
        $mediaGuard = $policyRoot . '/src/MediaGuard.php';
        $schema = PHPWG_ROOT_PATH . 'plugins/ClassIdentity/src/Schema.php';
        $nginx = '/etc/nginx/nginx.conf';
        $tests = [
            '/workspace/tests/phase0/media-guard-http.ps1',
            '/workspace/tests/phase0/media-guard-tiny-preview.ps1',
            '/workspace/tests/phase0/media-guard-state-transitions.ps1',
            '/workspace/tests/phase0/assert-media-permissions.sh',
        ];
        foreach ([$mediaGuard, $schema, $nginx, ...$tests] as $path) {
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('class_identity_media_attestation_source_missing');
            }
        }
        $migrationVersion = 0;
        try {
            $repository = Repository::fromPiwigo();
            $row = $repository->fetchOne('SELECT COALESCE(MAX(`version`), 0) AS `version` FROM `' . $repository->table('migration') . '`');
            $migrationVersion = (int) ($row['version'] ?? 0);
        } catch (\Throwable) {
            throw new \RuntimeException('class_identity_media_attestation_migration_unavailable');
        }
        return [
            'policy_sha256' => self::treeDigest($policyRoot),
            'nginx_sha256' => self::fileHash($nginx),
            'media_guard_sha256' => self::fileHash($mediaGuard),
            'schema_sha256' => self::fileHash($schema),
            'schema_version' => Schema::CURRENT_VERSION,
            'migration_version' => $migrationVersion,
            'test_suite_sha256' => self::filesDigest($tests),
        ];
    }

    private static function directory(): string
    {
        $dataRoot = PHPWG_ROOT_PATH . '_data';
        $realDataRoot = realpath($dataRoot);
        if ($realDataRoot === false || is_link($dataRoot) || !is_dir($realDataRoot)) {
            throw new \RuntimeException('class_identity_media_attestation_data_root_untrusted');
        }
        return rtrim(str_replace('\\', '/', $realDataRoot), '/') . '/class-archive';
    }

    private static function path(): string
    {
        return self::directory() . '/' . self::FILE_NAME;
    }

    private static function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || !preg_match('/\A[0-9a-f]{64}\z/D', $hash)) {
            throw new \RuntimeException('class_identity_media_attestation_hash_failed');
        }
        return $hash;
    }

    /** @param list<string> $paths */
    private static function filesDigest(array $paths): string
    {
        sort($paths, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($context, basename($path) . "\0" . self::fileHash($path) . "\n");
        }
        return hash_final($context);
    }

    private static function treeDigest(string $root): string
    {
        if (!is_dir($root) || is_link($root)) {
            throw new \RuntimeException('class_identity_media_attestation_tree_unavailable');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') && !str_ends_with($path, '.inc.php')) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', $root)) + 1);
            $files[$relative] = self::fileHash($path);
        }
        if ($files === []) {
            throw new \RuntimeException('class_identity_media_attestation_tree_empty');
        }
        ksort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $relative => $hash) {
            hash_update($context, $relative . "\0" . $hash . "\n");
        }
        return hash_final($context);
    }

    /** @return array<string, mixed> */
    private static function missing(string $message): array
    {
        return [
            'state' => 'MISSING',
            'label' => '需要重新验证',
            'message' => $message,
            'timestamp' => null,
            'commit' => null,
            'probe_count' => 0,
            'age_seconds' => null,
            'mismatches' => [],
            'record' => null,
        ];
    }
}
