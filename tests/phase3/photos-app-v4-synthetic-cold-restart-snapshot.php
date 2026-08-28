<?php

declare(strict_types=1);

/**
 * Read-only V4 persistence snapshot for the public 8091 cold-restart gate.
 *
 * It emits aggregate counts and SHA-256 digests only; no photo, account,
 * person, source, filesystem, or private-runtime identifier leaves the
 * synthetic Piwigo container. The companion PowerShell runner compares a
 * pre-restart and post-restart snapshot after a genuine public-container
 * restart, so a restarted service cannot silently rebuild a projection or
 * enqueue new AI work and still mint the Phase A/B evidence line.
 */

const V4_COLD_RESTART_ROOT = '/var/www/html/piwigo';
const V4_COLD_RESTART_KINDS = ['ALBUMS', 'MEMORIES', 'PEOPLE', 'PHOTO_CATALOG', 'SPOTLIGHT', 'TIMELINE'];
const V4_COLD_RESTART_SCOPES = ['FULL', 'HERITAGE'];
const V4_COLD_RESTART_SNAPSHOT_KINDS = ['HOME', 'MEMORY', 'SEARCH_SUGGESTION', 'SPOTLIGHT'];

function v4ColdRestartFail(string $code): never
{
    $safe = preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected_runtime_error';
    fwrite(STDERR, "V4_SYNTHETIC_COLD_RESTART_SNAPSHOT=FAIL code={$safe}\n");
    exit(1);
}

/** @param list<array<string,mixed>> $rows @param list<string> $fields */
function v4ColdRestartDigest(array $rows, array $fields): string
{
    $hash = hash_init('sha256');
    foreach ($rows as $row) {
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            if ($value === null) {
                hash_update($hash, "\x00");
                continue;
            }
            $encoded = (string) $value;
            hash_update($hash, "\x01" . pack('N', strlen($encoded)) . $encoded);
        }
        hash_update($hash, "\xff");
    }
    return hash_final($hash);
}

/** @param array<string,mixed>|null $row */
function v4ColdRestartCount(?array $row, string $field, string $code): int
{
    if ($row === null || !isset($row[$field]) || !is_numeric($row[$field])) {
        throw new RuntimeException($code);
    }
    $value = (int) $row[$field];
    if ($value < 0) {
        throw new RuntimeException($code);
    }
    return $value;
}

try {
    if (PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || !function_exists('posix_getpwuid')
        || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_V4_SYNTHETIC_COLD_RESTART') !== '1'
        || !is_file('/workspace/tests/phase3/photos-app-v4-synthetic-cold-restart-snapshot.php')
    ) {
        throw new RuntimeException('test_gate_required');
    }
    $runtimeUser = posix_getpwuid(posix_geteuid());
    if (!is_array($runtimeUser) || ($runtimeUser['name'] ?? null) !== 'nginx') {
        throw new RuntimeException('nginx_user_required');
    }
    if (realpath(V4_COLD_RESTART_ROOT) !== V4_COLD_RESTART_ROOT
        || is_link(V4_COLD_RESTART_ROOT)
        || !is_file(V4_COLD_RESTART_ROOT . '/local/config/database.inc.php')
    ) {
        throw new RuntimeException('piwigo_root_untrusted');
    }

    chdir(V4_COLD_RESTART_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    if (!defined('CLASS_IDENTITY_VERSION') || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        throw new RuntimeException('schema_source_not_v18');
    }
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('piwigo_prefix_invalid');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $migration = v4ColdRestartCount(
        $repository->fetchOne('SELECT COALESCE(MAX(`version`),0) AS `version` FROM `' . $repository->table('migration') . '`'),
        'version',
        'migration_version_missing',
    );
    if ($migration !== 18) {
        throw new RuntimeException('migration_version_not_v18');
    }

    $images = v4ColdRestartCount(
        $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`'),
        'count',
        'image_count_invalid',
    );
    $multiAlbum = v4ColdRestartCount(
        $repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $prefixeTable
                . 'image_category` GROUP BY `image_id` HAVING COUNT(*) > 1) AS `v4_cold_multi_album`',
        ),
        'count',
        'multi_album_count_invalid',
    );
    $photoBaseline = $repository->fetchOne(
        'SELECT COUNT(*) AS `active_canonical`,COUNT(DISTINCT `media_reference`) AS `physical_originals` FROM `'
            . $repository->table('photo') . '` WHERE `state`=?',
        [\ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    $activeCanonical = v4ColdRestartCount($photoBaseline, 'active_canonical', 'canonical_count_invalid');
    $physicalOriginals = v4ColdRestartCount($photoBaseline, 'physical_originals', 'original_count_invalid');
    if ($images !== 72 || $activeCanonical !== 72 || $physicalOriginals !== 72 || $multiAlbum !== 8) {
        throw new RuntimeException('synthetic_baseline_drift');
    }

    $projectionRows = $repository->fetchAll(
        'SELECT `projection_key`,`state`,`item_count`,HEX(`generation`) AS `generation`,HEX(`source_revision`) AS `source_revision`,'
            . 'HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision` FROM `'
            . $repository->table('read_projection') . "` WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`",
    );
    if (count($projectionRows) !== count(V4_COLD_RESTART_KINDS)
        || array_column($projectionRows, 'projection_key') !== V4_COLD_RESTART_KINDS) {
        throw new RuntimeException('projection_rows_incomplete');
    }
    foreach ($projectionRows as $row) {
        $kind = (string) ($row['projection_key'] ?? '');
        if (($row['state'] ?? null) !== 'ACTIVE'
            || !is_numeric($row['item_count'] ?? null) || (int) $row['item_count'] < 0
            || preg_match('/\A[0-9A-F]{32}\z/D', (string) ($row['generation'] ?? '')) !== 1
            || preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['source_revision'] ?? '')) !== 1
        ) {
            throw new RuntimeException('projection_state_invalid');
        }
        $payloadDigest = (string) ($row['payload_digest'] ?? '');
        $dependencyRevision = (string) ($row['dependency_revision'] ?? '');
        if ($kind === 'PHOTO_CATALOG') {
            if ($payloadDigest !== '' || $dependencyRevision !== '') {
                throw new RuntimeException('catalog_aggregate_fields_invalid');
            }
        } elseif (preg_match('/\A[0-9A-F]{64}\z/D', $payloadDigest) !== 1
            || preg_match('/\A[0-9A-F]{64}\z/D', $dependencyRevision) !== 1
        ) {
            throw new RuntimeException('aggregate_digest_invalid');
        }
    }

    $pointerRows = $repository->fetchAll(
        'SELECT p.`scope`,p.`projection_kind`,HEX(p.`active_snapshot_id`) AS `snapshot_id`,HEX(p.`active_revision`) AS `active_revision`,'
            . 's.`state`,HEX(s.`input_revision`) AS `input_revision`,HEX(s.`payload_digest`) AS `payload_digest`,s.`item_count` '
            . 'FROM `' . $repository->table('collection_snapshot_pointer') . '` p JOIN `'
            . $repository->table('collection_snapshot') . '` s ON s.`scope`=p.`scope` AND s.`projection_kind`=p.`projection_kind` '
            . 'AND s.`snapshot_id`=p.`active_snapshot_id` ORDER BY p.`scope`,p.`projection_kind`',
    );
    $expectedPointerKeys = [];
    foreach (V4_COLD_RESTART_SCOPES as $scope) {
        foreach (V4_COLD_RESTART_SNAPSHOT_KINDS as $kind) {
            $expectedPointerKeys[] = $scope . ':' . $kind;
        }
    }
    $actualPointerKeys = array_map(
        static fn(array $row): string => (string) ($row['scope'] ?? '') . ':' . (string) ($row['projection_kind'] ?? ''),
        $pointerRows,
    );
    if (count($pointerRows) !== 8 || $actualPointerKeys !== $expectedPointerKeys) {
        throw new RuntimeException('snapshot_pointer_count_invalid');
    }
    foreach ($pointerRows as $row) {
        if (($row['state'] ?? null) !== 'ACTIVE' || !is_numeric($row['item_count'] ?? null) || (int) $row['item_count'] < 0) {
            throw new RuntimeException('snapshot_pointer_state_invalid');
        }
        foreach (['snapshot_id' => 32, 'active_revision' => 64, 'input_revision' => 64, 'payload_digest' => 64] as $field => $length) {
            if (preg_match('/\A[0-9A-F]{' . $length . '}\z/D', (string) ($row[$field] ?? '')) !== 1) {
                throw new RuntimeException('snapshot_pointer_digest_invalid');
            }
        }
    }
    $snapshotItems = v4ColdRestartCount(
        $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $repository->table('collection_snapshot_item') . '`'),
        'count',
        'snapshot_item_count_invalid',
    );
    if ($snapshotItems < 1) {
        throw new RuntimeException('snapshot_items_missing');
    }

    $maintenanceRows = $repository->fetchAll(
        'SELECT `maintenance_key`,`state`,HEX(`last_input_revision`) AS `last_input_revision`,HEX(`last_snapshot_id`) AS `last_snapshot_id`,`last_error_code` FROM `'
            . $repository->table('collection_maintenance_state') . "` WHERE `maintenance_key` IN ('COLLECTION_SNAPSHOTS_FULL','COLLECTION_SNAPSHOTS_HERITAGE') ORDER BY `maintenance_key`",
    );
    if (count($maintenanceRows) !== 2
        || array_column($maintenanceRows, 'maintenance_key') !== ['COLLECTION_SNAPSHOTS_FULL', 'COLLECTION_SNAPSHOTS_HERITAGE']) {
        throw new RuntimeException('snapshot_maintenance_rows_invalid');
    }
    foreach ($maintenanceRows as $row) {
        if (($row['state'] ?? null) !== 'COMPLETE'
            || preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['last_input_revision'] ?? '')) !== 1
            || preg_match('/\A[0-9A-F]{32}\z/D', (string) ($row['last_snapshot_id'] ?? '')) !== 1
            || ($row['last_error_code'] ?? null) !== null
        ) {
            throw new RuntimeException('snapshot_maintenance_state_invalid');
        }
    }

    $rotationRows = $repository->fetchAll(
        'SELECT `scope`,HEX(`hero_spotlight_id`) AS `hero_spotlight_id`,HEX(`candidate_digest`) AS `candidate_digest`,`display_count`,'
            . '`last_rotated_at`,`next_rotation_at`,HEX(`revision`) AS `revision` FROM `'
            . $repository->table('spotlight_rotation_state') . '` ORDER BY `scope`',
    );
    if (count($rotationRows) !== 2 || array_column($rotationRows, 'scope') !== V4_COLD_RESTART_SCOPES) {
        throw new RuntimeException('spotlight_rotation_rows_invalid');
    }
    foreach ($rotationRows as $row) {
        if (!is_numeric($row['display_count'] ?? null) || (int) $row['display_count'] < 0
            || !is_string($row['next_rotation_at'] ?? null)
            || preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['candidate_digest'] ?? '')) !== 1
            || preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['revision'] ?? '')) !== 1
        ) {
            throw new RuntimeException('spotlight_rotation_state_invalid');
        }
        $hero = (string) ($row['hero_spotlight_id'] ?? '');
        if ($hero !== '' && preg_match('/\A[0-9A-F]{32}\z/D', $hero) !== 1) {
            throw new RuntimeException('spotlight_rotation_hero_invalid');
        }
    }

    $aiIndexRows = $repository->fetchAll(
        'SELECT `class_photo_id`,`source_checksum`,`immich_asset_id`,`face_state`,`search_state`,`face_model_name`,`face_model_revision`,'
            . '`search_model_name`,`search_model_revision`,`indexed_at`,`last_error_code`,`updated_at` FROM `'
            . $repository->table('ai_asset_index') . '` ORDER BY `class_photo_id`',
    );
    $aiJobs = $repository->fetchAll(
        'SELECT `job_id`,`class_photo_id`,`job_kind`,`trigger_kind`,`expected_checksum`,`state`,`attempt_count`,`not_before`,`last_error_code`,`updated_at`,`completed_at` FROM `'
            . $repository->table('ai_index_job') . '` ORDER BY `job_id`',
    );
    $openJobs = 0;
    foreach ($aiJobs as $row) {
        if (!in_array((string) ($row['state'] ?? ''), ['COMPLETE', 'CANCELLED', 'FAILED', 'UNAVAILABLE'], true)) {
            ++$openJobs;
        }
    }
    if ($openJobs !== 0) {
        throw new RuntimeException('ai_jobs_open');
    }

    fwrite(STDOUT, json_encode([
        'result' => 'PASS',
        'schema_version' => 18,
        'baseline' => [
            'images' => 72,
            'active_canonical' => 72,
            'physical_originals' => 72,
            'multi_album_images' => 8,
        ],
        'projections' => [
            'count' => count($projectionRows),
            'digest' => v4ColdRestartDigest($projectionRows, ['projection_key', 'state', 'item_count', 'generation', 'source_revision', 'payload_digest', 'dependency_revision']),
        ],
        'collection_snapshots' => [
            'pointer_count' => count($pointerRows),
            'pointer_digest' => v4ColdRestartDigest($pointerRows, ['scope', 'projection_kind', 'snapshot_id', 'active_revision', 'state', 'input_revision', 'payload_digest', 'item_count']),
            'item_count' => $snapshotItems,
            'maintenance_count' => count($maintenanceRows),
            'maintenance_digest' => v4ColdRestartDigest($maintenanceRows, ['maintenance_key', 'state', 'last_input_revision', 'last_snapshot_id', 'last_error_code']),
        ],
        'spotlight_rotation' => [
            'count' => count($rotationRows),
            'digest' => v4ColdRestartDigest($rotationRows, ['scope', 'hero_spotlight_id', 'candidate_digest', 'display_count', 'last_rotated_at', 'next_rotation_at', 'revision']),
        ],
        'ai' => [
            'asset_index_count' => count($aiIndexRows),
            'asset_index_digest' => v4ColdRestartDigest($aiIndexRows, ['class_photo_id', 'source_checksum', 'immich_asset_id', 'face_state', 'search_state', 'face_model_name', 'face_model_revision', 'search_model_name', 'search_model_revision', 'indexed_at', 'last_error_code', 'updated_at']),
            'job_count' => count($aiJobs),
            'open_job_count' => $openJobs,
            'job_digest' => v4ColdRestartDigest($aiJobs, ['job_id', 'class_photo_id', 'job_kind', 'trigger_kind', 'expected_checksum', 'state', 'attempt_count', 'not_before', 'last_error_code', 'updated_at', 'completed_at']),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    v4ColdRestartFail(strtolower($error->getMessage()));
}
