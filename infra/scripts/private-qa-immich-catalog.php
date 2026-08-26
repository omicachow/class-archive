<?php

declare(strict_types=1);

/**
 * Private Real-Data QA canonical catalog hand-off.
 *
 * The script runs only in the isolated private Piwigo container.  It exports
 * opaque Class Archive UUIDs and generated Piwigo media references, verifies
 * the read-only Immich mount input against the authoritative SHA-256 stored by
 * Class Archive, and atomically binds the resulting internal Immich assets.
 * It never reads the original source roots or emits a source filename.
 */

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\Gateway\BridgeImmichAdapter;
use ClassIdentity\Gateway\PiwigoGatewayAdapter;

const PRIVATE_QA_CATALOG_OUTPUT = '/tmp/class-archive-private-qa-immich-catalog.json';
const PRIVATE_QA_BIND_INPUT = '/tmp/class-archive-private-qa-immich-bindings.json';
const PRIVATE_QA_INDEX_INPUT = '/tmp/class-archive-private-qa-immich-index-evidence.json';
const PRIVATE_QA_ENABLE_INPUT = '/tmp/class-archive-private-qa-immich-enable.json';
const PRIVATE_QA_BRIDGE_TOKEN_OUTPUT = '/tmp/class-archive-private-qa-immich-bridge-token.json';
const PRIVATE_QA_BRIDGE_SECRET = '_data/.class-archive-immich-bridge.json';
const PRIVATE_QA_BRIDGE_FLAG = 'class_identity_immich_bridge_enabled';

function privateQaImmichFail(string $reason): never
{
    $safe = preg_replace('/[^a-z0-9_.-]/', '_', strtolower($reason));
    fwrite(STDERR, "PRIVATE_QA_IMMICH_CATALOG=FAIL reason={$safe}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    privateQaImmichFail('runtime_forbidden');
}
$runtimeScope = (string) getenv('CLASS_ARCHIVE_RUNTIME_SCOPE');
$privateQaRuntime = $runtimeScope === 'PRIVATE_REAL_DATA_QA'
    && getenv('CLASS_ARCHIVE_PRIVATE_REAL_QA') === '1';
$privateFullRuntime = $runtimeScope === 'PRIVATE_REAL_FULL'
    && getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') === '1';
if (!$privateQaRuntime && !$privateFullRuntime) {
    privateQaImmichFail('private_runtime_required');
}
define('PRIVATE_IMMICH_SCOPE', $runtimeScope);
define('PRIVATE_IMMICH_MAX_ASSETS', $privateFullRuntime ? 5000 : 500);
$action = (string) ($_SERVER['argv'][1] ?? '');
if (!in_array($action, ['export', 'export-bound', 'bind', 'complete-indexes', 'export-bridge-token', 'enable', 'probe'], true) || count($_SERVER['argv']) !== 2) {
    privateQaImmichFail('action_invalid');
}

chdir('/var/www/html/piwigo') || privateQaImmichFail('piwigo_root_unavailable');
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

if (!class_exists(ClassIdentity\Repository::class)
    || !class_exists(ClassIdentity\ClassArchivePhotoMappingService::class)) {
    privateQaImmichFail('class_archive_runtime_unavailable');
}
$repository = ClassIdentity\Repository::fromPiwigo();

/** @return array{present:bool,value:string} */
function privateQaImmichBridgeConfig(ClassIdentity\Repository $repository): array
{
    global $prefixeTable;
    $rows = $repository->fetchAll(
        'SELECT `value` FROM `' . $prefixeTable . 'config` WHERE `param`=? LIMIT 2',
        [PRIVATE_QA_BRIDGE_FLAG],
    );
    if (count($rows) > 1 || (count($rows) === 1 && !is_string($rows[0]['value'] ?? null))) {
        throw new RuntimeException('bridge_config_invalid');
    }
    return ['present' => count($rows) === 1, 'value' => count($rows) === 1 ? (string) $rows[0]['value'] : ''];
}

function privateQaImmichAssertBridgeDisabled(ClassIdentity\Repository $repository): void
{
    $config = privateQaImmichBridgeConfig($repository);
    if (($config['present'] && !in_array($config['value'], ['', '0', 'false'], true))
        || file_exists(PRIVATE_QA_BRIDGE_SECRET) || is_link(PRIVATE_QA_BRIDGE_SECRET)) {
        throw new RuntimeException('bridge_not_pristine');
    }
}

/** @return array<string,mixed> */
function privateQaImmichReadJson(string $path, int $maximumBytes): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || is_link($path) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0077) !== 0 || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? 0) < 16 || (int) ($stat['size'] ?? 0) > $maximumBytes) {
        throw new RuntimeException('input_file_invalid');
    }
    $raw = file_get_contents($path);
    try {
        $value = is_string($raw) ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR) : null;
    } finally {
        $raw = null;
    }
    if (!is_array($value)) {
        throw new RuntimeException('input_json_invalid');
    }
    return $value;
}

function privateQaImmichExactKeys(array $value, array $keys): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    return $actual === $keys;
}

/** @return array{path:string,sha256:string} */
function privateQaImmichOriginal(string $reference): array
{
    $normalized = ClassArchivePhoto::normalizeMediaReference($reference);
    $rootName = str_starts_with($normalized, 'upload/') ? 'upload' : 'galleries';
    $root = realpath(PHPWG_ROOT_PATH . $rootName);
    $path = PHPWG_ROOT_PATH . $normalized;
    $real = realpath($path);
    if ($root === false || $real === false || !is_file($real) || is_link($path)
        || !str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
        throw new RuntimeException('original_untrusted');
    }
    clearstatcache(true, $real);
    $mode = (int) @fileperms($real) & 0777;
    if ($mode !== 0660) {
        throw new RuntimeException('original_mode_invalid');
    }
    $sha256 = hash_file('sha256', $real);
    if (!is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1) {
        throw new RuntimeException('original_hash_invalid');
    }
    return ['path' => $real, 'sha256' => $sha256];
}

/**
 * @return array{version:int,scope:string,count:int,catalog_digest:string,photos:list<array{class_photo_id:string,era:string,media_reference:string,sha256:string}>}
 */
function privateQaImmichCatalog(ClassIdentity\Repository $repository, bool $requireUnbound): array
{
    global $prefixeTable;
    $candidates = PiwigoGatewayAdapter::fromPiwigo()->photoCandidates();
    $eras = [];
    foreach ($candidates as $candidate) {
        $candidateId = $candidate->id();
        $candidateEra = $candidate->era();
        if (isset($eras[$candidateId]) || !in_array($candidateEra, ['HERITAGE', 'LIVING'], true)
            || $candidate->state() !== ClassArchivePhoto::STATE_ACTIVE
            || $candidate->mappingState() !== ClassArchivePhoto::STATE_ACTIVE) {
            throw new RuntimeException('catalog_mapping_invalid');
        }
        $eras[$candidateId] = $candidateEra;
    }
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id`,p.`immich_asset_id`,p.`media_checksum`,p.`media_reference`,p.`state` '
        . 'FROM `' . $repository->table('photo') . '` p '
        . 'ORDER BY p.`piwigo_image_id` ASC',
    );
    $imageRow = $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`');
    $imageCount = (int) ($imageRow['count'] ?? -1);
    if ($imageCount < 1 || $imageCount > PRIVATE_IMMICH_MAX_ASSETS || count($rows) !== $imageCount || count($eras) !== $imageCount) {
        throw new RuntimeException('catalog_count_invalid');
    }
    $photos = [];
    $ids = [];
    $references = [];
    foreach ($rows as $row) {
        $binaryId = $row['class_photo_id'] ?? null;
        $binaryChecksum = $row['media_checksum'] ?? null;
        $assetId = $row['immich_asset_id'] ?? null;
        $classPhotoId = is_string($binaryId) && strlen($binaryId) === 16
            ? ClassArchivePhoto::binaryToId($binaryId)
            : '';
        $era = $eras[$classPhotoId] ?? null;
        if (!is_string($binaryId) || strlen($binaryId) !== 16 || !is_string($binaryChecksum) || strlen($binaryChecksum) !== 32
            || ($row['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE
            || !in_array($era, ['HERITAGE', 'LIVING'], true)
            || ($requireUnbound && $assetId !== null)
            || (!$requireUnbound && (!is_string($assetId) || ClassArchivePhoto::normalizeImmichAssetId($assetId) === null))) {
            throw new RuntimeException('catalog_mapping_invalid');
        }
        $reference = ClassArchivePhoto::normalizeMediaReference((string) ($row['media_reference'] ?? ''));
        $original = privateQaImmichOriginal($reference);
        $stored = bin2hex($binaryChecksum);
        if (!hash_equals($stored, $original['sha256']) || isset($ids[$classPhotoId]) || isset($references[$reference])) {
            throw new RuntimeException('catalog_integrity_invalid');
        }
        $ids[$classPhotoId] = true;
        $references[$reference] = true;
        $photos[] = [
            'class_photo_id' => $classPhotoId,
            'era' => $era,
            'media_reference' => $reference,
            'sha256' => $stored,
        ];
    }
    $encodedPhotos = json_encode($photos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return [
        'version' => 1,
        'scope' => PRIVATE_IMMICH_SCOPE,
        'count' => count($photos),
        'catalog_digest' => hash('sha256', $encodedPhotos),
        'photos' => $photos,
    ];
}

function privateQaImmichWriteExclusive(string $path, array $value): void
{
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('output_not_clean');
    }
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        throw new RuntimeException('output_create_failed');
    }
    $length = 0;
    try {
        $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $length = strlen($raw);
        if (!chmod($path, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            throw new RuntimeException('output_write_failed');
        }
    } finally {
        fclose($handle);
        $raw = null;
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || is_link($path) || (((int) ($stat['mode'] ?? 0) & 0170000) !== 0100000)
        || (((int) ($stat['mode'] ?? 0) & 0777) !== 0600) || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? -1) !== $length) {
        throw new RuntimeException('output_write_failed');
    }
}

function privateQaImmichServiceId(string $name): int
{
    $raw = getenv($name);
    if (!is_string($raw) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $raw) !== 1) {
        throw new RuntimeException('bridge_service_identity_invalid');
    }
    $value = (int) $raw;
    if ((string) $value !== $raw || $value <= 0 || $value > 2147483647) {
        throw new RuntimeException('bridge_service_identity_invalid');
    }
    return $value;
}

/**
 * Validate the durable Piwigo-side bridge credential before replacing it.
 *
 * Container initialization deliberately normalizes durable private files to
 * 0660 under the configured service uid/gid.  Freshly created files may still
 * be 0600 and owned by the current nginx process.  No other type, mode, owner,
 * group, hard-link count or JSON shape is trusted.
 *
 * @return array{token:string,mode:int,dev:int,ino:int,uid:int,gid:int,nlink:int}
 */
function privateQaImmichReadDurableBridgeSecret(string $path): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    $mode = is_array($stat) ? ((int) ($stat['mode'] ?? 0) & 0777) : 0;
    $uid = privateQaImmichServiceId('PIWIGO_UID');
    $gid = privateQaImmichServiceId('PIWIGO_GID');
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : $uid;
    $ownerOnly = $mode === 0600 && (int) ($stat['uid'] ?? -1) === $effectiveUid;
    $serviceShared = $mode === 0660
        && (int) ($stat['uid'] ?? -1) === $uid
        && (int) ($stat['gid'] ?? -1) === $gid;
    if ($serviceShared) {
        $parent = dirname($path);
        clearstatcache(true, $parent);
        $parentStat = @lstat($parent);
        $serviceShared = is_array($parentStat)
            && !is_link($parent)
            && (((int) ($parentStat['mode'] ?? 0) & 0170000) === 0040000)
            && (((int) ($parentStat['mode'] ?? 0) & 0007) === 0)
            && (int) ($parentStat['uid'] ?? -1) === $uid
            && (int) ($parentStat['gid'] ?? -1) === $gid;
    }
    if (!is_array($stat) || is_link($path)
        || (((int) ($stat['mode'] ?? 0) & 0170000) !== 0100000)
        || !($ownerOnly || $serviceShared)
        || (int) ($stat['nlink'] ?? 0) !== 1
        || (int) ($stat['size'] ?? 0) < 48 || (int) ($stat['size'] ?? 0) > 512) {
        throw new RuntimeException('bridge_secret_existing_invalid');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('bridge_secret_existing_invalid');
    }
    $raw = null;
    try {
        $opened = fstat($handle);
        foreach (['dev', 'ino', 'uid', 'gid', 'nlink', 'size'] as $key) {
            if (!is_array($opened) || (int) ($opened[$key] ?? -1) !== (int) ($stat[$key] ?? -2)) {
                throw new RuntimeException('bridge_secret_existing_invalid');
            }
        }
        if (((int) ($opened['mode'] ?? 0) & 0777) !== $mode) {
            throw new RuntimeException('bridge_secret_existing_invalid');
        }
        $raw = stream_get_contents($handle, 513);
        $after = fstat($handle);
        foreach (['dev', 'ino', 'uid', 'gid', 'nlink', 'size'] as $key) {
            if (!is_array($after) || (int) ($after[$key] ?? -1) !== (int) ($opened[$key] ?? -2)) {
                throw new RuntimeException('bridge_secret_existing_invalid');
            }
        }
        $decoded = is_string($raw) && strlen($raw) === (int) $stat['size']
            ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR)
            : null;
    } catch (Throwable) {
        $decoded = null;
    } finally {
        fclose($handle);
        $raw = null;
    }
    if (!is_array($decoded) || !privateQaImmichExactKeys($decoded, ['version', 'token'])
        || ($decoded['version'] ?? null) !== 1 || !is_string($decoded['token'] ?? null)
        || preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $decoded['token']) !== 1) {
        throw new RuntimeException('bridge_secret_existing_invalid');
    }
    return [
        'token' => $decoded['token'],
        'mode' => $mode,
        'dev' => (int) ($stat['dev'] ?? -1),
        'ino' => (int) ($stat['ino'] ?? -1),
        'uid' => (int) ($stat['uid'] ?? -1),
        'gid' => (int) ($stat['gid'] ?? -1),
        'nlink' => (int) ($stat['nlink'] ?? -1),
    ];
}

try {
    if ($action === 'export') {
        privateQaImmichAssertBridgeDisabled($repository);
        $catalog = privateQaImmichCatalog($repository, true);
        privateQaImmichWriteExclusive(PRIVATE_QA_CATALOG_OUTPUT, $catalog);
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=export count=' . $catalog['count'] . "\n");
        exit(0);
    }

    if ($action === 'export-bound') {
        // A bound export is also used by the explicit post-provision index
        // verifier. The bridge may already be enabled there; the catalog is
        // still rebuilt from authoritative Class Archive rows and originals.
        $catalog = privateQaImmichCatalog($repository, false);
        privateQaImmichWriteExclusive(PRIVATE_QA_CATALOG_OUTPUT, $catalog);
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=export-bound count=' . $catalog['count'] . "\n");
        exit(0);
    }

    if ($action === 'bind') {
        privateQaImmichAssertBridgeDisabled($repository);
        $catalog = privateQaImmichCatalog($repository, true);
        $input = privateQaImmichReadJson(PRIVATE_QA_BIND_INPUT, 512 * 1024);
        if (!privateQaImmichExactKeys($input, ['version', 'scope', 'catalog_digest', 'assets'])
            || ($input['version'] ?? null) !== 1 || ($input['scope'] ?? null) !== PRIVATE_IMMICH_SCOPE
            || !is_string($input['catalog_digest'] ?? null) || !hash_equals($catalog['catalog_digest'], $input['catalog_digest'])
            || !is_array($input['assets'] ?? null) || count($input['assets']) !== $catalog['count']) {
            throw new RuntimeException('binding_input_invalid');
        }
        $expected = [];
        foreach ($catalog['photos'] as $photo) {
            $expected[$photo['class_photo_id']] = $photo;
        }
        $bindings = [];
        foreach ($input['assets'] as $asset) {
            if (!is_array($asset) || !privateQaImmichExactKeys($asset, ['class_photo_id', 'immich_asset_id'])) {
                throw new RuntimeException('binding_input_invalid');
            }
            $classPhotoId = (string) ($asset['class_photo_id'] ?? '');
            $immichAssetId = ClassArchivePhoto::normalizeImmichAssetId((string) ($asset['immich_asset_id'] ?? ''));
            if ($immichAssetId === null || !isset($expected[$classPhotoId]) || isset($bindings[$classPhotoId])) {
                throw new RuntimeException('binding_input_invalid');
            }
            $bindings[$classPhotoId] = $immichAssetId;
        }
        if (count(array_unique(array_values($bindings), SORT_STRING)) !== $catalog['count']) {
            throw new RuntimeException('binding_asset_duplicate');
        }
        $repository->transaction(function (ClassIdentity\Repository $repository) use ($catalog, $bindings): void {
            // This local-only bulk importer deliberately avoids hundreds of
            // per-row transactions, but it must preserve the same durable
            // write boundary as ClassArchivePhotoMappingService. Both People
            // and Memories consume Immich bindings.
            ClassIdentity\ProjectionMutationBoundary::invalidateAggregates(
                $repository,
                [
                    ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
                    ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
                ],
                'IMMICH_ASSET_BIND',
            );
            foreach ($catalog['photos'] as $photo) {
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('photo') . '` SET `immich_asset_id`=?,`updated_at`=UTC_TIMESTAMP(6) '
                    . 'WHERE `class_photo_id`=? AND `state`=? AND `immich_asset_id` IS NULL AND `media_reference`=? AND `media_checksum`=?',
                    [
                        $bindings[$photo['class_photo_id']],
                        ClassArchivePhoto::idToBinary($photo['class_photo_id']),
                        ClassArchivePhoto::STATE_ACTIVE,
                        $photo['media_reference'],
                        hex2bin($photo['sha256']),
                    ],
                );
                if ($changed !== 1) {
                    throw new RuntimeException('binding_race');
                }
            }
        });
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=bind count=' . $catalog['count'] . "\n");
        exit(0);
    }

    if ($action === 'complete-indexes') {
        // This is an explicit operator-only completion boundary. It is called
        // only after the internal Immich runtime has returned queue-idle,
        // non-empty People and non-empty Smart Search evidence. Ordinary GET
        // paths never enqueue, claim or complete model work.
        if (PRIVATE_IMMICH_SCOPE !== 'PRIVATE_REAL_FULL'
            || !hash_equals('1', (string) getenv('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER'))
            || !class_exists(ClassIdentity\AiIndexService::class)) {
            throw new RuntimeException('index_runtime_forbidden');
        }
        $catalog = privateQaImmichCatalog($repository, false);
        $input = privateQaImmichReadJson(PRIVATE_QA_INDEX_INPUT, 1024 * 1024);
        $keys = [
            'version', 'scope', 'catalog_digest', 'runtime_mode', 'asset_count', 'people_count',
            'face_model_name', 'face_model_revision', 'search_model_name', 'search_model_revision',
            'face_queue_idle', 'recognition_queue_idle', 'search_queue_idle', 'assets',
        ];
        if (!privateQaImmichExactKeys($input, $keys)
            || ($input['version'] ?? null) !== 1
            || ($input['scope'] ?? null) !== PRIVATE_IMMICH_SCOPE
            || !is_string($input['catalog_digest'] ?? null)
            || !hash_equals($catalog['catalog_digest'], (string) $input['catalog_digest'])
            || !in_array($input['runtime_mode'] ?? null, ['INITIAL', 'RESUME'], true)
            || ($input['asset_count'] ?? null) !== $catalog['count']
            || !is_int($input['people_count'] ?? null) || $input['people_count'] < 1
            || ($input['face_queue_idle'] ?? null) !== true
            || ($input['recognition_queue_idle'] ?? null) !== true
            || ($input['search_queue_idle'] ?? null) !== true
            || !is_array($input['assets'] ?? null) || count($input['assets']) !== $catalog['count']) {
            throw new RuntimeException('index_evidence_invalid');
        }
        foreach (['face_model_name', 'face_model_revision', 'search_model_name', 'search_model_revision'] as $field) {
            if (!is_string($input[$field] ?? null)
                || preg_match('/\A[A-Za-z0-9._:@\/-]{1,190}\z/D', (string) $input[$field]) !== 1) {
                throw new RuntimeException('index_evidence_invalid');
            }
        }

        $expected = [];
        foreach ($catalog['photos'] as $photo) {
            $expected[$photo['class_photo_id']] = $photo;
        }
        $bindings = [];
        foreach ($input['assets'] as $asset) {
            if (!is_array($asset) || !privateQaImmichExactKeys($asset, ['class_photo_id', 'immich_asset_id'])) {
                throw new RuntimeException('index_evidence_invalid');
            }
            $classPhotoId = (string) ($asset['class_photo_id'] ?? '');
            $immichAssetId = ClassArchivePhoto::normalizeImmichAssetId((string) ($asset['immich_asset_id'] ?? ''));
            if ($immichAssetId === null || !isset($expected[$classPhotoId]) || isset($bindings[$classPhotoId])) {
                throw new RuntimeException('index_evidence_invalid');
            }
            $bindings[$classPhotoId] = $immichAssetId;
        }
        if (count($bindings) !== $catalog['count']
            || count(array_unique(array_values($bindings), SORT_STRING)) !== $catalog['count']) {
            throw new RuntimeException('index_evidence_invalid');
        }
        $boundRows = $repository->fetchAll(
            'SELECT `class_photo_id`,`immich_asset_id`,`media_checksum`,`state` FROM `'
                . $repository->table('photo') . '` ORDER BY `class_photo_id` ASC',
        );
        if (count($boundRows) !== $catalog['count']) {
            throw new RuntimeException('index_binding_invalid');
        }
        foreach ($boundRows as $row) {
            $binaryId = $row['class_photo_id'] ?? null;
            $binaryChecksum = $row['media_checksum'] ?? null;
            $assetId = ClassArchivePhoto::normalizeImmichAssetId(
                is_string($row['immich_asset_id'] ?? null) ? (string) $row['immich_asset_id'] : null,
            );
            if (!is_string($binaryId) || strlen($binaryId) !== 16
                || !is_string($binaryChecksum) || strlen($binaryChecksum) !== 32
                || ($row['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE) {
                throw new RuntimeException('index_binding_invalid');
            }
            $classPhotoId = ClassArchivePhoto::binaryToId($binaryId);
            if ($assetId === null || !isset($expected[$classPhotoId], $bindings[$classPhotoId])
                || !hash_equals($bindings[$classPhotoId], $assetId)
                || !hash_equals($expected[$classPhotoId]['sha256'], bin2hex($binaryChecksum))) {
                throw new RuntimeException('index_binding_invalid');
            }
        }

        $ai = ClassIdentity\AiIndexService::fromPiwigo();
        $enqueue = $ai->enqueueImportedActivePhotos();
        if (($enqueue['scanned'] ?? null) !== $catalog['count']) {
            throw new RuntimeException('index_job_invalid');
        }
        $jobRows = $repository->fetchAll(
            'SELECT `class_photo_id`,`job_kind`,`expected_checksum`,`state` FROM `'
                . $repository->table('ai_index_job') . '` WHERE `state` IN (?,?) ORDER BY `job_id` ASC',
            [ClassIdentity\AiIndexService::JOB_PENDING, ClassIdentity\AiIndexService::JOB_RUNNING],
        );
        if (count($jobRows) > $catalog['count']) {
            throw new RuntimeException('index_job_invalid');
        }
        foreach ($jobRows as $row) {
            $binaryId = $row['class_photo_id'] ?? null;
            $checksum = $row['expected_checksum'] ?? null;
            if (!is_string($binaryId) || strlen($binaryId) !== 16
                || !is_string($checksum) || strlen($checksum) !== 32
                || ($row['job_kind'] ?? null) !== ClassIdentity\AiIndexService::JOB_INDEX_ASSET
                || ($row['state'] ?? null) !== ClassIdentity\AiIndexService::JOB_PENDING) {
                throw new RuntimeException('index_job_invalid');
            }
            $classPhotoId = ClassArchivePhoto::binaryToId($binaryId);
            if (!isset($expected[$classPhotoId])
                || !hash_equals($expected[$classPhotoId]['sha256'], bin2hex($checksum))) {
                throw new RuntimeException('index_job_invalid');
            }
        }

        $completed = 0;
        while (($job = $ai->claimNextJob()) !== null) {
            $classPhotoId = (string) ($job['class_photo_id'] ?? '');
            if (($job['job_kind'] ?? null) !== ClassIdentity\AiIndexService::JOB_INDEX_ASSET
                || ($job['state'] ?? null) !== ClassIdentity\AiIndexService::JOB_RUNNING
                || !isset($expected[$classPhotoId], $bindings[$classPhotoId])
                || !hash_equals($expected[$classPhotoId]['sha256'], (string) ($job['expected_checksum'] ?? ''))) {
                throw new RuntimeException('index_job_invalid');
            }
            $ai->completeIndexJob(
                (string) $job['job_id'],
                $bindings[$classPhotoId],
                (string) $input['face_model_name'],
                (string) $input['face_model_revision'],
                (string) $input['search_model_name'],
                (string) $input['search_model_revision'],
            );
            ++$completed;
        }
        $status = $ai->status();
        $maintenance = $ai->maintenanceReport();
        if (($status['state'] ?? null) !== 'READY'
            || ($status['worker_configured'] ?? null) !== true
            || ($status['open_jobs'] ?? null) !== 0
            || ($status['review_required'] ?? null) !== false
            || (($status['assets']['INDEXED:INDEXED'] ?? null) !== $catalog['count'])
            || ($maintenance['result'] ?? null) !== 'PASS'
            || ($maintenance['missing_index_rows'] ?? null) !== 0
            || ($maintenance['checksum_drift'] ?? null) !== 0) {
            throw new RuntimeException('index_completion_invalid');
        }
        fwrite(
            STDOUT,
            'PRIVATE_QA_IMMICH_CATALOG=PASS action=complete-indexes count=' . $catalog['count']
                . ' completed=' . $completed . ' state=READY' . "\n",
        );
        exit(0);
    }

    if ($action === 'export-bridge-token') {
        if (!$privateFullRuntime) {
            throw new RuntimeException('bridge_export_runtime_forbidden');
        }
        $catalog = privateQaImmichCatalog($repository, false);
        $config = privateQaImmichBridgeConfig($repository);
        if (!$config['present'] || $config['value'] !== '1') {
            throw new RuntimeException('bridge_export_disabled');
        }
        $secret = privateQaImmichReadDurableBridgeSecret(PRIVATE_QA_BRIDGE_SECRET);
        privateQaImmichWriteExclusive(
            PRIVATE_QA_BRIDGE_TOKEN_OUTPUT,
            [
                'version' => 1,
                'scope' => PRIVATE_IMMICH_SCOPE,
                'catalog_digest' => $catalog['catalog_digest'],
                'token' => $secret['token'],
            ],
        );
        $secret = null;
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=export-bridge-token count=' . $catalog['count'] . "\n");
        exit(0);
    }

    if ($action === 'enable') {
        $catalog = privateQaImmichCatalog($repository, false);
        $input = privateQaImmichReadJson(PRIVATE_QA_ENABLE_INPUT, 2048);
        $token = $input['token'] ?? null;
        if (!privateQaImmichExactKeys($input, ['version', 'scope', 'catalog_digest', 'token'])
            || ($input['version'] ?? null) !== 1 || ($input['scope'] ?? null) !== PRIVATE_IMMICH_SCOPE
            || !is_string($input['catalog_digest'] ?? null) || !hash_equals($catalog['catalog_digest'], $input['catalog_digest'])
            || !is_string($token) || preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token) !== 1) {
            throw new RuntimeException('enable_input_invalid');
        }
        if (file_exists(PRIVATE_QA_BRIDGE_SECRET) || is_link(PRIVATE_QA_BRIDGE_SECRET)) {
            throw new RuntimeException('bridge_secret_not_clean');
        }
        privateQaImmichWriteExclusive(PRIVATE_QA_BRIDGE_SECRET, ['version' => 1, 'token' => $token]);
        global $prefixeTable;
        try {
            $repository->execute(
                'INSERT INTO `' . $prefixeTable . 'config` (`param`,`value`,`comment`) VALUES (?,\'1\',?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`comment`=VALUES(`comment`)',
                [PRIVATE_QA_BRIDGE_FLAG, 'Class Archive private local QA bridge'],
            );
        } catch (Throwable $error) {
            @unlink(PRIVATE_QA_BRIDGE_SECRET);
            throw $error;
        }
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=enable count=' . $catalog['count'] . "\n");
        exit(0);
    }

    $catalog = privateQaImmichCatalog($repository, false);
    $adapter = BridgeImmichAdapter::configuredOrNull();
    if ($adapter->availability() !== 'AVAILABLE') {
        throw new RuntimeException('adapter_unavailable');
    }
    $ids = array_map(static fn (array $photo): string => $photo['class_photo_id'], $catalog['photos']);
    $people = $adapter->peopleForVisiblePhotos($ids);
    fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=probe count=' . $catalog['count'] . ' people=' . count($people) . "\n");
} catch (Throwable $error) {
    $allowed = [
        'input_file_invalid', 'input_json_invalid', 'original_untrusted', 'original_mode_invalid', 'original_hash_invalid',
        'catalog_count_invalid', 'catalog_mapping_invalid', 'catalog_integrity_invalid', 'output_not_clean', 'output_create_failed',
        'output_write_failed', 'binding_input_invalid', 'binding_asset_duplicate', 'binding_race', 'enable_input_invalid',
        'index_runtime_forbidden', 'index_evidence_invalid', 'index_binding_invalid', 'index_job_invalid',
        'index_completion_invalid',
        'bridge_secret_not_clean', 'bridge_service_identity_invalid', 'bridge_secret_existing_invalid',
        'bridge_export_disabled', 'bridge_export_runtime_forbidden',
        'adapter_unavailable', 'class_archive_immich_bridge_binding_invalid',
        'bridge_config_invalid', 'bridge_not_pristine',
        'class_archive_immich_bridge_enablement_invalid', 'class_archive_immich_bridge_response_invalid',
        'class_archive_immich_bridge_secret_unavailable', 'class_archive_immich_bridge_transport_unavailable',
        'class_archive_immich_bridge_unavailable',
    ];
    $reason = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'unexpected';
    privateQaImmichFail($reason);
}
