<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Digest-bound operational evidence for the isolated Immich ML cache.
 *
 * This is deliberately a release/UI gate, never an authorization input.
 * MediaGuard remains fail-closed independently if this record is missing or
 * stale.  The record is created only after a cold offline container restart
 * and container-side checksum verification by the Phase 2.5 runner.
 */
final class MlArtifactAttestation
{
    public const VERSION = 1;
    public const FRESHNESS_SECONDS = 30 * 86400;

    private const DATA_DIRECTORY = '_data/class-archive';
    private const FILE_NAME = 'immich-ml-artifact-attestation.json';
    private const MANIFEST = '/workspace/infra/immich-spike/ml-artifacts/manifest.json';
    private const COMPOSE = '/workspace/infra/immich-spike/docker-compose.yml';
    private const PIWIGO_COMPOSE = '/workspace/infra/docker-compose.yml';
    private const EXPECTED_ML_IMAGE = 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05';
    private const FACE_MODEL = 'buffalo_l';
    private const FACE_MODEL_REVISION = 'd09715916a0778919a770c343533641e250b8699';
    private const SEARCH_MODEL = 'ViT-B-32__openai';
    private const SEARCH_MODEL_REVISION = 'a857c8de2c07bbcfa6646adfcf31b798845afa1e';
    private const TESTS = [
        '/workspace/infra/scripts/verify-immich-ml-artifacts.ps1',
        '/workspace/infra/scripts/prepare-immich-ml-artifacts.ps1',
        '/workspace/infra/scripts/attest-immich-ml.ps1',
        '/workspace/infra/scripts/invalidate-immich-ml-artifact-attestation.php',
        '/workspace/infra/scripts/write-immich-ml-artifact-attestation.php',
        '/workspace/plugins/ClassIdentity/src/BuildCommit.php',
        '/workspace/plugins/ClassIdentity/src/MlArtifactAttestation.php',
        '/workspace/tests/phase2/immich-ml-artifact-readiness.ps1',
        '/workspace/tests/phase2/immich-ml-offline-cold-start.ps1',
        '/workspace/tests/phase2/immich-people-search-runtime.ps1',
        '/workspace/tests/phase2/immich-people-search-runtime.mjs',
        '/workspace/tests/phase2/immich-ml-artifact-fail-closed.ps1',
        '/workspace/tests/phase2/immich-gateway-bridge-runtime.ps1',
        '/workspace/tests/phase2/immich-people-fixture.php',
        '/workspace/tests/phase2/immich-people-search-browser.mjs',
        '/workspace/tests/phase2/immich-runtime-isolation.ps1',
        '/workspace/tests/fixtures/phase2-synthetic/README.md',
        '/workspace/tests/fixtures/phase2-synthetic/fictional-cast-classroom.png',
        '/workspace/tests/fixtures/phase2-synthetic/fictional-cast-night-cake.png',
        '/workspace/tests/fixtures/phase2-synthetic/fictional-cast-outdoor.png',
        '/workspace/tests/fixtures/phase2-synthetic/fictional-cast-playground.png',
        '/workspace/tests/fixtures/phase2-synthetic/fictional-cast-portraits.png',
    ];
    private const RUNTIME_SOURCES = [
        '/workspace/plugins/ClassIdentity/main.inc.php',
        '/workspace/infra/immich-spike/bridge/server.mjs',
        '/workspace/infra/immich-spike/web-compat/server.mjs',
        '/workspace/infra/php-fpm/class-archive-env.conf',
        '/workspace/infra/piwigo-nginx/nginx.conf',
    ];

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $path = self::path();
        if (!is_file($path) || is_link($path)) {
            return self::missing('本地 AI 模型尚未安装或尚未完成离线验证。');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 65536) {
            return self::missing('本地 AI 模型验证记录无法读取。');
        }
        try {
            $record = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::missing('本地 AI 模型验证记录格式无效。');
        }
        if (!is_array($record)) {
            return self::missing('本地 AI 模型验证记录格式无效。');
        }

        $required = [
            'ml_artifact_attestation_version', 'commit', 'manifest_sha256',
            'compose_sha256', 'piwigo_compose_sha256', 'test_suite_sha256', 'runtime_source_sha256', 'immich_version',
            'immich_commit', 'machine_learning_image', 'artifact_count',
            'license_status', 'face_model', 'face_model_revision', 'search_model',
            'search_model_revision', 'result', 'offline_cold_start', 'timestamp',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                return self::missing('本地 AI 模型验证记录不完整。');
            }
        }
        foreach (['commit', 'manifest_sha256', 'compose_sha256', 'piwigo_compose_sha256', 'test_suite_sha256', 'runtime_source_sha256', 'immich_version', 'immich_commit', 'machine_learning_image', 'license_status', 'face_model', 'face_model_revision', 'search_model', 'search_model_revision', 'result', 'offline_cold_start', 'timestamp'] as $field) {
            if (!is_string($record[$field])) {
                return self::missing('本地 AI 模型验证记录未通过类型校验。');
            }
        }
        if ((int) $record['ml_artifact_attestation_version'] !== self::VERSION
            || preg_match('/\A[0-9a-f]{40}\z/D', $record['commit']) !== 1
            || !is_int($record['artifact_count']) || $record['artifact_count'] < 1 || $record['artifact_count'] > 64
            || !is_string($record['result']) || $record['result'] !== 'PASS'
            || !is_string($record['offline_cold_start']) || $record['offline_cold_start'] !== 'PASS'
            || !is_string($record['timestamp']) || strtotime($record['timestamp']) === false
        ) {
            return self::missing('本地 AI 模型验证记录未通过完整性校验。');
        }

        try {
            $expected = self::currentEvidence();
            $currentCommit = BuildCommit::current();
        } catch (\Throwable) {
            return self::missing('无法重新计算本地 AI 模型验证摘要。');
        }
        $mismatches = [];
        if (!hash_equals($currentCommit, (string) $record['commit'])) {
            $mismatches[] = 'commit';
        }
        foreach (['manifest_sha256', 'compose_sha256', 'piwigo_compose_sha256', 'test_suite_sha256', 'runtime_source_sha256', 'immich_version', 'immich_commit', 'machine_learning_image', 'artifact_count', 'license_status', 'face_model', 'face_model_revision', 'search_model', 'search_model_revision'] as $field) {
            if (!array_key_exists($field, $expected) || !is_scalar($record[$field]) || (string) $record[$field] !== (string) $expected[$field]) {
                $mismatches[] = $field;
            }
        }
        $age = time() - (int) strtotime((string) $record['timestamp']);
        if ($age < -300) {
            $mismatches[] = 'future_timestamp';
        }
        $age = max(0, $age);
        if ($mismatches !== [] || $age > self::FRESHNESS_SECONDS) {
            return [
                'state' => 'STALE',
                'label' => '需要重新验证',
                'message' => $mismatches === [] ? '本地 AI 离线验证已超过有效期。' : '模型文件、容器配置或验证脚本已发生变化。',
                'timestamp' => (string) $record['timestamp'],
                'commit' => (string) $record['commit'],
                'artifact_count' => (int) $record['artifact_count'],
                'license_status' => (string) $record['license_status'],
                'license_label' => self::licenseLabel((string) $record['license_status']),
                'face_model' => (string) $record['face_model'],
                'face_model_revision' => (string) $record['face_model_revision'],
                'search_model' => (string) $record['search_model'],
                'search_model_revision' => (string) $record['search_model_revision'],
                'mismatches' => $mismatches === [] ? ['freshness'] : $mismatches,
                'record' => $record,
            ];
        }

        return [
            'state' => 'VERIFIED',
            'label' => '已验证',
            'message' => '模型文件 SHA-256、离线冷启动和当前容器配置均已验证。',
            'timestamp' => (string) $record['timestamp'],
            'commit' => (string) $record['commit'],
            'artifact_count' => (int) $record['artifact_count'],
            'license_status' => (string) $record['license_status'],
            'license_label' => self::licenseLabel((string) $record['license_status']),
            'face_model' => (string) $record['face_model'],
            'face_model_revision' => (string) $record['face_model_revision'],
            'search_model' => (string) $record['search_model'],
            'search_model_revision' => (string) $record['search_model_revision'],
            'mismatches' => [],
            'record' => $record,
        ];
    }

    /** @return array<string, mixed> */
    public static function create(string $commit): array
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new \InvalidArgumentException('class_identity_ml_attestation_commit_invalid');
        }
        if (!hash_equals(BuildCommit::current(), $commit)) {
            throw new \InvalidArgumentException('class_identity_ml_attestation_commit_mismatch');
        }
        $evidence = self::currentEvidence();
        return [
            'ml_artifact_attestation_version' => self::VERSION,
            'commit' => $commit,
            'manifest_sha256' => $evidence['manifest_sha256'],
            'compose_sha256' => $evidence['compose_sha256'],
            'piwigo_compose_sha256' => $evidence['piwigo_compose_sha256'],
            'test_suite_sha256' => $evidence['test_suite_sha256'],
            'runtime_source_sha256' => $evidence['runtime_source_sha256'],
            'immich_version' => $evidence['immich_version'],
            'immich_commit' => $evidence['immich_commit'],
            'machine_learning_image' => $evidence['machine_learning_image'],
            'artifact_count' => $evidence['artifact_count'],
            'license_status' => $evidence['license_status'],
            'face_model' => $evidence['face_model'],
            'face_model_revision' => $evidence['face_model_revision'],
            'search_model' => $evidence['search_model'],
            'search_model_revision' => $evidence['search_model_revision'],
            'result' => 'PASS',
            'offline_cold_start' => 'PASS',
            'timestamp' => gmdate('c'),
        ];
    }

    /** @param array<string, mixed> $record */
    public static function persist(array $record): void
    {
        if (($record['result'] ?? null) !== 'PASS'
            || ($record['offline_cold_start'] ?? null) !== 'PASS'
            || (int) ($record['ml_artifact_attestation_version'] ?? 0) !== self::VERSION) {
            throw new \InvalidArgumentException('class_identity_ml_attestation_record_invalid');
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('class_identity_ml_attestation_directory_unavailable');
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false || is_link($directory) || !hash_equals(str_replace('\\', '/', $directory), str_replace('\\', '/', $realDirectory))) {
            throw new \RuntimeException('class_identity_ml_attestation_directory_untrusted');
        }
        @chmod($directory, 0770);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $temporary = $directory . '/.immich-ml-attestation-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('class_identity_ml_attestation_write_unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('class_identity_ml_attestation_write_failed');
            }
            @chmod($temporary, 0660);
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, self::path())) {
                throw new \RuntimeException('class_identity_ml_attestation_publish_failed');
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

    public static function invalidate(): void
    {
        $path = self::path();
        if (is_link($path)) {
            throw new \RuntimeException('class_identity_ml_attestation_path_untrusted');
        }
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('class_identity_ml_attestation_invalidation_failed');
        }
        clearstatcache(true, $path);
        if (is_file($path) || is_link($path)) {
            throw new \RuntimeException('class_identity_ml_attestation_invalidation_unproven');
        }
    }

    /** @return array{manifest_sha256:string,compose_sha256:string,piwigo_compose_sha256:string,test_suite_sha256:string,runtime_source_sha256:string,immich_version:string,immich_commit:string,machine_learning_image:string,artifact_count:int,license_status:string,face_model:string,face_model_revision:string,search_model:string,search_model_revision:string} */
    private static function currentEvidence(): array
    {
        foreach ([self::MANIFEST, self::COMPOSE, self::PIWIGO_COMPOSE, ...self::TESTS, ...self::RUNTIME_SOURCES] as $path) {
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('class_identity_ml_attestation_source_missing');
            }
        }
        $contents = @file_get_contents(self::MANIFEST);
        if (!is_string($contents) || $contents === '' || strlen($contents) > 131072) {
            throw new \RuntimeException('class_identity_ml_attestation_manifest_unreadable');
        }
        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('class_identity_ml_attestation_manifest_invalid');
        }
        if (!is_array($manifest)
            || ($manifest['manifest_version'] ?? null) !== 1
            || !is_array($manifest['generated_for'] ?? null)
            || !is_array($manifest['artifacts'] ?? null)
            || ($manifest['generated_for']['immich_version'] ?? null) !== '3.1.0'
            || ($manifest['generated_for']['immich_commit'] ?? null) !== '8aa95c67470a02a8ddedf03c2e52963af33065ff'
            || ($manifest['generated_for']['machine_learning_image'] ?? null) !== self::EXPECTED_ML_IMAGE
        ) {
            throw new \RuntimeException('class_identity_ml_attestation_manifest_invalid');
        }
        $artifacts = array_values(array_filter($manifest['artifacts'], static fn (mixed $artifact): bool => is_array($artifact) && ($artifact['required'] ?? false) === true));
        if (count($artifacts) !== 8) {
            throw new \RuntimeException('class_identity_ml_attestation_artifact_count_invalid');
        }
        $restricted = false;
        $allowedRedistribution = ['ALLOWED', 'PROHIBITED', 'RESTRICTED', 'UNKNOWN'];
        foreach ($artifacts as $artifact) {
            foreach (['model_name', 'exact_revision', 'relative_cache_path', 'file_size', 'sha256', 'license', 'license_source', 'redistribution_status', 'required_by_immich_version', 'required_by_immich_commit'] as $field) {
                if (!array_key_exists($field, $artifact) || $artifact[$field] === '' || $artifact[$field] === null) {
                    throw new \RuntimeException('class_identity_ml_attestation_artifact_invalid');
                }
            }
            if (!is_string($artifact['sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $artifact['sha256']) !== 1
                || !is_int($artifact['file_size']) || $artifact['file_size'] < 1
                || $artifact['required_by_immich_version'] !== '3.1.0'
                || $artifact['required_by_immich_commit'] !== $manifest['generated_for']['immich_commit']
                || !is_string($artifact['redistribution_status'])
                || !in_array($artifact['redistribution_status'], $allowedRedistribution, true)
                || !is_string($artifact['model_name'])
                || !is_string($artifact['exact_revision'])) {
                throw new \RuntimeException('class_identity_ml_attestation_artifact_invalid');
            }
            $knownModel = ($artifact['model_name'] === self::FACE_MODEL && $artifact['exact_revision'] === self::FACE_MODEL_REVISION)
                || ($artifact['model_name'] === self::SEARCH_MODEL && $artifact['exact_revision'] === self::SEARCH_MODEL_REVISION);
            if (!$knownModel) {
                throw new \RuntimeException('class_identity_ml_attestation_model_revision_invalid');
            }
            if ($artifact['redistribution_status'] !== 'ALLOWED') {
                $restricted = true;
            }
        }

        $composeContents = @file_get_contents(self::COMPOSE);
        if (!is_string($composeContents) || !str_contains($composeContents, 'image: ' . self::EXPECTED_ML_IMAGE)) {
            throw new \RuntimeException('class_identity_ml_attestation_compose_image_mismatch');
        }

        return [
            'manifest_sha256' => self::fileHash(self::MANIFEST),
            'compose_sha256' => self::fileHash(self::COMPOSE),
            'piwigo_compose_sha256' => self::fileHash(self::PIWIGO_COMPOSE),
            'test_suite_sha256' => self::filesDigest(self::TESTS),
            'runtime_source_sha256' => self::filesDigest([...self::RUNTIME_SOURCES, ...self::pluginSourceFiles()]),
            'immich_version' => (string) $manifest['generated_for']['immich_version'],
            'immich_commit' => (string) $manifest['generated_for']['immich_commit'],
            'machine_learning_image' => (string) $manifest['generated_for']['machine_learning_image'],
            'artifact_count' => count($artifacts),
            'license_status' => $restricted ? 'REVIEWED_RESTRICTED' : 'REVIEWED',
            'face_model' => self::FACE_MODEL,
            'face_model_revision' => self::FACE_MODEL_REVISION,
            'search_model' => self::SEARCH_MODEL,
            'search_model_revision' => self::SEARCH_MODEL_REVISION,
        ];
    }

    /** @return list<string> */
    private static function pluginSourceFiles(): array
    {
        $root = '/workspace/plugins/ClassIdentity/src';
        $realRoot = realpath($root);
        if ($realRoot === false || is_link($root) || !is_dir($realRoot)) {
            throw new \RuntimeException('class_identity_ml_attestation_runtime_source_root_untrusted');
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || $entry->isLink() || strtolower($entry->getExtension()) !== 'php') {
                throw new \RuntimeException('class_identity_ml_attestation_runtime_source_untrusted');
            }
            $path = str_replace('\\', '/', $entry->getPathname());
            if (!str_starts_with($path, rtrim(str_replace('\\', '/', $realRoot), '/') . '/')) {
                throw new \RuntimeException('class_identity_ml_attestation_runtime_source_untrusted');
            }
            $paths[] = $path;
        }
        if ($paths === []) {
            throw new \RuntimeException('class_identity_ml_attestation_runtime_source_missing');
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function directory(): string
    {
        $dataRoot = PHPWG_ROOT_PATH . '_data';
        $realDataRoot = realpath($dataRoot);
        if ($realDataRoot === false || is_link($dataRoot) || !is_dir($realDataRoot)) {
            throw new \RuntimeException('class_identity_ml_attestation_data_root_untrusted');
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
        if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) {
            throw new \RuntimeException('class_identity_ml_attestation_hash_failed');
        }
        return $hash;
    }

    /** @param list<string> $paths */
    private static function filesDigest(array $paths): string
    {
        sort($paths, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            $normalized = str_replace('\\', '/', $path);
            $label = str_starts_with($normalized, '/workspace/') ? substr($normalized, strlen('/workspace/')) : $normalized;
            if ($label === '' || str_contains($label, "\0")) {
                throw new \RuntimeException('class_identity_ml_attestation_digest_label_invalid');
            }
            hash_update($context, $label . "\0" . self::fileHash($path) . "\n");
        }
        return hash_final($context);
    }

    private static function licenseLabel(string $status): string
    {
        return match ($status) {
            'REVIEWED_RESTRICTED' => '已审查（仅限本地合成测试）',
            'REVIEWED' => '已审查',
            default => '需要审查',
        };
    }

    /** @return array<string, mixed> */
    private static function missing(string $message): array
    {
        return [
            'state' => 'MISSING',
            'label' => '尚未验证',
            'message' => $message,
            'timestamp' => null,
            'commit' => null,
            'artifact_count' => 0,
            'license_status' => 'UNKNOWN',
            'license_label' => self::licenseLabel('UNKNOWN'),
            'face_model' => null,
            'face_model_revision' => null,
            'search_model' => null,
            'search_model_revision' => null,
            'mismatches' => [],
            'record' => null,
        ];
    }
}
