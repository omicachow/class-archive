<?php

declare(strict_types=1);

/**
 * Read-only, private-owner snapshot for the Photos App v4 cold-restart gate.
 *
 * This helper intentionally emits only aggregate counts and SHA-256 digests.
 * It does not open a managed original, derivative, provenance path, comment
 * body, account record, or Immich/Postgres vector. The PowerShell runner uses
 * it before and after an opt-in restart of the exact private-full serving
 * containers, so restart evidence stays local and non-sensitive.
 */

const CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_OWNER_SNAPSHOT_PROJECTIONS = [
    'PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT',
];

function ownerColdRestartSnapshotFail(string $code): never
{
    $safe = preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected';
    fwrite(STDERR, "V4_OWNER_COLD_RESTART_SNAPSHOT=FAIL code={$safe}\n");
    exit(1);
}

/** @return array{count:int,digest:string} */
function ownerColdRestartAggregate(\ClassIdentity\Repository $repository, string $sql, string $code): array
{
    $row = $repository->fetchOne($sql);
    if (!is_array($row)
        || !isset($row['row_count'], $row['digest'])
        || !preg_match('/\A[0-9]+\z/D', (string) $row['row_count'])
        || !preg_match('/\A[0-9a-f]{64}\z/D', strtolower((string) $row['digest']))
    ) {
        throw new RuntimeException($code);
    }
    return ['count' => (int) $row['row_count'], 'digest' => strtolower((string) $row['digest'])];
}

/** @return array{count:int,indexed:int,digest:string} */
function ownerColdRestartAiIndexAggregate(\ClassIdentity\Repository $repository, string $table): array
{
    $row = $repository->fetchOne(
        "SELECT COUNT(*) AS `row_count`,"
        . "SUM(CASE WHEN `face_state`='INDEXED' AND `search_state`='INDEXED' THEN 1 ELSE 0 END) AS `indexed_count`,"
        . "SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',HEX(`class_photo_id`),HEX(`source_checksum`),"
        . "COALESCE(`immich_asset_id`,''),`face_state`,`search_state`,COALESCE(`face_model_revision`,''),"
        . "COALESCE(`search_model_revision`,''),COALESCE(DATE_FORMAT(`indexed_at`,'%Y-%m-%d %H:%i:%s.%f'),'')) "
        . "ORDER BY `class_photo_id` SEPARATOR '\\n'),''),256) AS `digest` FROM {$table}",
    );
    if (!is_array($row)
        || !preg_match('/\A[0-9]+\z/D', (string) ($row['row_count'] ?? ''))
        || !preg_match('/\A[0-9]+\z/D', (string) ($row['indexed_count'] ?? ''))
        || !preg_match('/\A[0-9a-f]{64}\z/D', strtolower((string) ($row['digest'] ?? '')))
    ) {
        throw new RuntimeException('ai_index_aggregate_invalid');
    }
    return [
        'count' => (int) $row['row_count'],
        'indexed' => (int) $row['indexed_count'],
        'digest' => strtolower((string) $row['digest']),
    ];
}

/** @return array{total:int,open:int,digest:string} */
function ownerColdRestartAiJobAggregate(\ClassIdentity\Repository $repository, string $table): array
{
    $row = $repository->fetchOne(
        "SELECT COUNT(*) AS `row_count`,"
        . "SUM(CASE WHEN `state` IN ('PENDING','RUNNING') THEN 1 ELSE 0 END) AS `open_count`,"
        . "SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',HEX(`job_id`),COALESCE(HEX(`class_photo_id`),''),"
        . "`job_kind`,`trigger_kind`,COALESCE(HEX(`expected_checksum`),''),`state`,`attempt_count`,"
        . "COALESCE(DATE_FORMAT(`not_before`,'%Y-%m-%d %H:%i:%s.%f'),''),"
        . "COALESCE(DATE_FORMAT(`completed_at`,'%Y-%m-%d %H:%i:%s.%f'),'')) ORDER BY `job_id` SEPARATOR '\\n'),''),256) AS `digest` "
        . "FROM {$table}",
    );
    if (!is_array($row)
        || !preg_match('/\A[0-9]+\z/D', (string) ($row['row_count'] ?? ''))
        || !preg_match('/\A[0-9]+\z/D', (string) ($row['open_count'] ?? ''))
        || !preg_match('/\A[0-9a-f]{64}\z/D', strtolower((string) ($row['digest'] ?? '')))
    ) {
        throw new RuntimeException('ai_job_aggregate_invalid');
    }
    return [
        'total' => (int) $row['row_count'],
        'open' => (int) $row['open_count'],
        'digest' => strtolower((string) $row['digest']),
    ];
}

if (PHP_SAPI !== 'cli'
    || getenv('CLASS_ARCHIVE_V4_OWNER_COLD_RESTART_SNAPSHOT') !== '1'
    || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'PRIVATE_REAL_FULL'
    || getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'
    || !function_exists('posix_geteuid')
    || !function_exists('posix_getpwuid')
    || posix_geteuid() === 0
) {
    ownerColdRestartSnapshotFail('private_owner_gate_required');
}
$runtimeUser = posix_getpwuid(posix_geteuid());
if (!is_array($runtimeUser) || ($runtimeUser['name'] ?? null) !== 'nginx'
    || realpath(CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT) !== CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT
    || is_link(CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT)
    || !is_file(CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT . '/local/config/database.inc.php')
) {
    ownerColdRestartSnapshotFail('runtime_root_untrusted');
}

try {
    chdir(CLASS_ARCHIVE_OWNER_SNAPSHOT_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
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

    $repository = \ClassIdentity\Repository::fromPiwigo();
    $migration = $repository->fetchOne(
        'SELECT COALESCE(MAX(`version`),0) AS `version` FROM `' . $repository->table('migration') . '`',
    );
    if ((int) ($migration['version'] ?? 0) !== 18 || \ClassIdentity\Schema::CURRENT_VERSION !== 18) {
        throw new RuntimeException('schema_v18_required');
    }
    $repository->execute('SET SESSION `group_concat_max_len`=67108864');

    $projectionTable = '`' . $repository->table('read_projection') . '`';
    $pointerTable = '`' . $repository->table('collection_snapshot_pointer') . '`';
    $snapshotTable = '`' . $repository->table('collection_snapshot') . '`';
    $itemTable = '`' . $repository->table('collection_snapshot_item') . '`';
    $commentTable = '`' . $repository->table('photo_comment') . '`';
    $aiIndexTable = '`' . $repository->table('ai_asset_index') . '`';
    $aiJobTable = '`' . $repository->table('ai_index_job') . '`';
    $rotationTable = '`' . $repository->table('spotlight_rotation_state') . '`';

    $projectionRows = $repository->fetchAll(
        "SELECT `projection_key`,`state`,`item_count`,HEX(`generation`) AS `generation`,HEX(`source_revision`) AS `source_revision`,"
        . "HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision` FROM {$projectionTable} "
        . "WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`",
    );
    if (count($projectionRows) !== count(CLASS_ARCHIVE_OWNER_SNAPSHOT_PROJECTIONS)) {
        throw new RuntimeException('projection_rows_incomplete');
    }
    $projectionParts = [];
    foreach ($projectionRows as $row) {
        $kind = (string) ($row['projection_key'] ?? '');
        if (!in_array($kind, CLASS_ARCHIVE_OWNER_SNAPSHOT_PROJECTIONS, true)
            || (string) ($row['state'] ?? '') !== 'ACTIVE'
            || !preg_match('/\A[0-9A-F]{32}\z/D', (string) ($row['generation'] ?? ''))
            || !preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['source_revision'] ?? ''))
            || ($kind !== 'PHOTO_CATALOG' && (!preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['payload_digest'] ?? ''))
                || !preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['dependency_revision'] ?? ''))))
        ) {
            throw new RuntimeException('projection_state_invalid');
        }
        $projectionParts[] = implode('|', [
            $kind, (string) $row['state'], (string) $row['item_count'], (string) $row['generation'],
            (string) $row['source_revision'], (string) ($row['payload_digest'] ?? ''),
            (string) ($row['dependency_revision'] ?? ''),
        ]);
    }
    sort($projectionParts, SORT_STRING);

    $pointers = ownerColdRestartAggregate(
        $repository,
        "SELECT COUNT(*) AS `row_count`,SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',`scope`,`projection_kind`,HEX(`active_snapshot_id`),HEX(`active_revision`)) "
        . "ORDER BY `scope`,`projection_kind` SEPARATOR '\\n'),''),256) AS `digest` FROM {$pointerTable}",
        'collection_pointer_aggregate_invalid',
    );
    if ($pointers['count'] !== 8) {
        throw new RuntimeException('collection_pointer_count_invalid');
    }
    $activeSnapshots = ownerColdRestartAggregate(
        $repository,
        "SELECT COUNT(*) AS `row_count`,SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',`scope`,`projection_kind`,HEX(`snapshot_id`),"
        . "HEX(`input_revision`),HEX(`payload_digest`),`item_count`) ORDER BY `scope`,`projection_kind`,`snapshot_id` SEPARATOR '\\n'),''),256) AS `digest` "
        . "FROM {$snapshotTable} WHERE `state`='ACTIVE'",
        'active_snapshot_aggregate_invalid',
    );
    if ($activeSnapshots['count'] !== 8) {
        throw new RuntimeException('active_snapshot_count_invalid');
    }
    $activeSnapshotItems = ownerColdRestartAggregate(
        $repository,
        "SELECT COUNT(*) AS `row_count`,SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',HEX(i.`snapshot_id`),i.`ordinal`,i.`item_kind`,"
        . "SHA2(i.`item_key`,256),COALESCE(HEX(i.`cover_class_photo_id`),''),HEX(i.`payload_digest`)) "
        . "ORDER BY i.`snapshot_id`,i.`ordinal` SEPARATOR '\\n'),''),256) AS `digest` FROM {$itemTable} i "
        . "JOIN {$snapshotTable} s ON s.`snapshot_id`=i.`snapshot_id` WHERE s.`state`='ACTIVE'",
        'active_snapshot_item_aggregate_invalid',
    );
    $comments = ownerColdRestartAggregate(
        $repository,
        "SELECT COUNT(*) AS `row_count`,SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',HEX(`comment_id`),HEX(`class_photo_id`),"
        . "COALESCE(HEX(`parent_comment_id`),''),`author_role`,`state`,SHA2(`body`,256),"
        . "COALESCE(DATE_FORMAT(`created_at`,'%Y-%m-%d %H:%i:%s.%f'),''),COALESCE(DATE_FORMAT(`deleted_at`,'%Y-%m-%d %H:%i:%s.%f'),'')) "
        . "ORDER BY `comment_id` SEPARATOR '\\n'),''),256) AS `digest` FROM {$commentTable}",
        'comment_aggregate_invalid',
    );
    $aiIndex = ownerColdRestartAiIndexAggregate($repository, $aiIndexTable);
    $aiJobs = ownerColdRestartAiJobAggregate($repository, $aiJobTable);
    if ($aiJobs['open'] !== 0) {
        throw new RuntimeException('ai_reindex_jobs_open');
    }
    // Rotation timing and display count may advance in a valid scheduled
    // rotation window. Retain only candidate-set integrity, not mutable timer
    // fields, so the restart gate does not misclassify a legitimate schedule.
    $rotation = ownerColdRestartAggregate(
        $repository,
        "SELECT COUNT(*) AS `row_count`,SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS('|',`scope`,HEX(`candidate_digest`)) "
        . "ORDER BY `scope` SEPARATOR '\\n'),''),256) AS `digest` FROM {$rotationTable}",
        'spotlight_rotation_aggregate_invalid',
    );
    if ($rotation['count'] !== 2) {
        throw new RuntimeException('spotlight_rotation_count_invalid');
    }

    $payload = [
        'result' => 'PASS',
        'schema_version' => 18,
        'projections' => [
            'count' => count($projectionParts),
            'digest' => hash('sha256', implode("\n", $projectionParts)),
        ],
        'collection' => [
            'pointer_count' => $pointers['count'],
            'pointer_digest' => $pointers['digest'],
            'active_snapshot_count' => $activeSnapshots['count'],
            'active_snapshot_digest' => $activeSnapshots['digest'],
            'active_item_count' => $activeSnapshotItems['count'],
            'active_item_digest' => $activeSnapshotItems['digest'],
        ],
        'comments' => $comments,
        'ai' => [
            'asset_count' => $aiIndex['count'],
            'indexed_count' => $aiIndex['indexed'],
            'asset_digest' => $aiIndex['digest'],
            'job_count' => $aiJobs['total'],
            'open_job_count' => $aiJobs['open'],
            'job_digest' => $aiJobs['digest'],
        ],
        'spotlight_rotation' => $rotation,
    ];
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    $code = strtolower($error->getMessage());
    ownerColdRestartSnapshotFail(preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected_runtime_error');
}
