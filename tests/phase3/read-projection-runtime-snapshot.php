<?php

declare(strict_types=1);

const CLASS_ARCHIVE_RUNTIME_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_RUNTIME_KINDS = ['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'];

function runtimeSnapshotFail(string $code): never
{
    $safe = preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected';
    fwrite(STDERR, "READ_PROJECTION_RUNTIME_SNAPSHOT=FAIL code={$safe}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli'
    || !function_exists('posix_geteuid')
    || !function_exists('posix_getpwuid')
    || posix_geteuid() === 0
) {
    runtimeSnapshotFail('untrusted_runtime_user');
}
$runtimeUser = posix_getpwuid(posix_geteuid());
if (!is_array($runtimeUser) || ($runtimeUser['name'] ?? null) !== 'nginx') {
    runtimeSnapshotFail('nginx_user_required');
}
if (realpath(CLASS_ARCHIVE_RUNTIME_ROOT) !== CLASS_ARCHIVE_RUNTIME_ROOT
    || is_link(CLASS_ARCHIVE_RUNTIME_ROOT)
    || !is_file(CLASS_ARCHIVE_RUNTIME_ROOT . '/local/config/database.inc.php')
) {
    runtimeSnapshotFail('piwigo_root_untrusted');
}

try {
    chdir(CLASS_ARCHIVE_RUNTIME_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
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

    if (!method_exists(\ClassIdentity\Gateway\ReadProjectionStore::class, 'refreshPhotos')
        || !method_exists(\ClassIdentity\Gateway\PiwigoGatewayAdapter::class, 'sourcePhotoCandidatesByIdsForRebuild')
    ) {
        throw new RuntimeException('incremental_contract_not_deployed');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $migration = $repository->fetchOne(
        'SELECT COALESCE(MAX(`version`),0) AS `version` FROM `' . $repository->table('migration') . '`',
    );
    if ((int) ($migration['version'] ?? 0) !== \ClassIdentity\Schema::CURRENT_VERSION) {
        throw new RuntimeException('schema_version_invalid');
    }
    $projectionTable = '`' . $repository->table('read_projection') . '`';
    $photoTable = '`' . $repository->table('read_photo') . '`';
    $rows = $repository->fetchAll(
        "SELECT `projection_key`,`state`,`item_count`,`built_at`,HEX(`generation`) AS `generation`,"
            . "HEX(`source_revision`) AS `source_revision`,HEX(`payload_digest`) AS `payload_digest`,"
            . "HEX(`dependency_revision`) AS `dependency_revision` FROM {$projectionTable} "
            . "WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`",
    );
    if (count($rows) !== count(CLASS_ARCHIVE_RUNTIME_KINDS)) {
        throw new RuntimeException('projection_rows_incomplete');
    }
    $projections = [];
    foreach ($rows as $row) {
        $kind = (string) ($row['projection_key'] ?? '');
        if (!in_array($kind, CLASS_ARCHIVE_RUNTIME_KINDS, true)
            || ($row['state'] ?? null) !== 'ACTIVE'
            || preg_match('/\A[0-9A-F]{32}\z/D', (string) ($row['generation'] ?? '')) !== 1
            || preg_match('/\A[0-9A-F]{64}\z/D', (string) ($row['source_revision'] ?? '')) !== 1
            || !is_string($row['built_at'] ?? null)
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
        $projections[$kind] = [
            'state' => 'ACTIVE',
            'generation' => strtolower((string) $row['generation']),
            'built_at' => (string) $row['built_at'],
            'source_revision' => strtolower((string) $row['source_revision']),
            'payload_digest' => $payloadDigest === '' ? null : strtolower($payloadDigest),
            'dependency_revision' => $dependencyRevision === '' ? null : strtolower($dependencyRevision),
            'item_count' => (int) ($row['item_count'] ?? 0),
        ];
    }
    if (array_keys($projections) !== ['ALBUMS', 'MEMORIES', 'PEOPLE', 'PHOTO_CATALOG', 'SPOTLIGHT', 'TIMELINE']) {
        throw new RuntimeException('projection_order_invalid');
    }

    $candidate = $repository->fetchOne(
        'SELECT p.`class_photo_id`,a.`archive_date`,a.`date_precision`,a.`date_source`,a.`date_confidence`,a.`event_label` '
            . "FROM `{$repository->table('photo')}` p JOIN `{$repository->table('archive_image')}` a "
            . 'ON a.`piwigo_image_id`=p.`piwigo_image_id` '
            . "WHERE p.`state`='ACTIVE' AND a.`date_source` IN ('UNKNOWN','ARCHIVE_CONFIRMED') "
            . "AND a.`date_precision` IN ('UNKNOWN','EXACT','DAY','MONTH','YEAR') "
            . "ORDER BY a.`date_source`='UNKNOWN' DESC,p.`piwigo_image_id` ASC LIMIT 1",
    );
    if ($candidate === null || !is_string($candidate['class_photo_id'] ?? null)) {
        throw new RuntimeException('synthetic_mutation_candidate_missing');
    }
    $candidateId = \ClassIdentity\ClassArchivePhoto::binaryToId((string) $candidate['class_photo_id']);
    $catalogGeneration = hex2bin($projections['PHOTO_CATALOG']['generation']);
    if (!is_string($catalogGeneration) || strlen($catalogGeneration) !== 16) {
        throw new RuntimeException('catalog_generation_invalid');
    }
    $readRows = $repository->fetchAll(
        "SELECT `class_photo_id`,`row_digest`,`built_at` FROM {$photoTable} WHERE `generation`=? ORDER BY `class_photo_id`",
        [$catalogGeneration],
    );
    if (count($readRows) !== $projections['PHOTO_CATALOG']['item_count']) {
        throw new RuntimeException('read_photo_count_invalid');
    }
    $nonTargetParts = [];
    $targetStorage = null;
    foreach ($readRows as $row) {
        if (!is_string($row['class_photo_id'] ?? null) || strlen((string) $row['class_photo_id']) !== 16
            || !is_string($row['row_digest'] ?? null) || strlen((string) $row['row_digest']) !== 32
            || !is_string($row['built_at'] ?? null)
        ) {
            throw new RuntimeException('read_photo_row_invalid');
        }
        $part = (string) $row['class_photo_id'] . (string) $row['row_digest'] . (string) $row['built_at'];
        if (hash_equals((string) $candidate['class_photo_id'], (string) $row['class_photo_id'])) {
            $targetStorage = [
                'row_digest' => bin2hex((string) $row['row_digest']),
                'built_at' => (string) $row['built_at'],
            ];
        } else {
            $nonTargetParts[] = $part;
        }
    }
    if ($targetStorage === null) {
        throw new RuntimeException('read_photo_target_missing');
    }

    $derivativeRoot = CLASS_ARCHIVE_RUNTIME_ROOT . '/_data/i';
    if (realpath($derivativeRoot) !== $derivativeRoot || is_link($derivativeRoot) || !is_dir($derivativeRoot)) {
        throw new RuntimeException('derivative_root_untrusted');
    }
    $derivativeFiles = 0;
    $derivativeBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($derivativeRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || $entry->isLink()) {
            throw new RuntimeException('derivative_entry_untrusted');
        }
        if (!$entry->isFile()) {
            continue;
        }
        ++$derivativeFiles;
        $size = $entry->getSize();
        if ($size < 0) {
            throw new RuntimeException('derivative_size_invalid');
        }
        $derivativeBytes += $size;
    }

    $payload = [
        'result' => 'PASS',
        'schema_version' => \ClassIdentity\Schema::CURRENT_VERSION,
        'incremental_contract' => 1,
        'projections' => $projections,
        'read_photo' => [
            'count' => count($readRows),
            'generation' => $projections['PHOTO_CATALOG']['generation'],
            'non_target_storage_digest' => hash('sha256', implode('', $nonTargetParts)),
            'target_storage' => $targetStorage,
        ],
        'mutation_candidate' => [
            'id' => $candidateId,
            'archive_date' => $candidate['archive_date'] === null ? null : (string) $candidate['archive_date'],
            'date_precision' => (string) $candidate['date_precision'],
            'date_source' => (string) $candidate['date_source'],
            'date_confidence' => (string) $candidate['date_confidence'],
            'event_label' => $candidate['event_label'] === null ? null : (string) $candidate['event_label'],
        ],
        'derivatives' => ['files' => $derivativeFiles, 'bytes' => $derivativeBytes],
    ];
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    $code = strtolower($error->getMessage());
    runtimeSnapshotFail(preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected_runtime_error');
}
