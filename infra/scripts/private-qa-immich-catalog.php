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

const PRIVATE_QA_CATALOG_OUTPUT = '/tmp/class-archive-private-qa-immich-catalog.json';
const PRIVATE_QA_BIND_INPUT = '/tmp/class-archive-private-qa-immich-bindings.json';
const PRIVATE_QA_ENABLE_INPUT = '/tmp/class-archive-private-qa-immich-enable.json';
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
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_QA') !== '1'
    || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'PRIVATE_REAL_DATA_QA') {
    privateQaImmichFail('private_runtime_required');
}
$action = (string) ($_SERVER['argv'][1] ?? '');
if (!in_array($action, ['export', 'bind', 'enable', 'probe'], true) || count($_SERVER['argv']) !== 2) {
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
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id`,p.`immich_asset_id`,p.`media_checksum`,p.`media_reference`,p.`state`,a.`era` '
        . 'FROM `' . $repository->table('photo') . '` p '
        . 'INNER JOIN `' . $repository->table('archive_image') . '` a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'ORDER BY p.`piwigo_image_id` ASC',
    );
    $imageRow = $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`');
    $imageCount = (int) ($imageRow['count'] ?? -1);
    if ($imageCount < 1 || $imageCount > 500 || count($rows) !== $imageCount) {
        throw new RuntimeException('catalog_count_invalid');
    }
    $photos = [];
    $ids = [];
    $references = [];
    foreach ($rows as $row) {
        $binaryId = $row['class_photo_id'] ?? null;
        $binaryChecksum = $row['media_checksum'] ?? null;
        $assetId = $row['immich_asset_id'] ?? null;
        $era = $row['era'] ?? null;
        if (!is_string($binaryId) || strlen($binaryId) !== 16 || !is_string($binaryChecksum) || strlen($binaryChecksum) !== 32
            || ($row['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE
            || !in_array($era, ['HERITAGE', 'LIVING'], true)
            || ($requireUnbound && $assetId !== null)
            || (!$requireUnbound && (!is_string($assetId) || ClassArchivePhoto::normalizeImmichAssetId($assetId) === null))) {
            throw new RuntimeException('catalog_mapping_invalid');
        }
        $classPhotoId = ClassArchivePhoto::binaryToId($binaryId);
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
        'scope' => 'PRIVATE_REAL_DATA_QA',
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
    try {
        $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!chmod($path, 0600) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            throw new RuntimeException('output_write_failed');
        }
    } finally {
        fclose($handle);
        $raw = null;
    }
}

try {
    if ($action === 'export') {
        privateQaImmichAssertBridgeDisabled($repository);
        $catalog = privateQaImmichCatalog($repository, true);
        privateQaImmichWriteExclusive(PRIVATE_QA_CATALOG_OUTPUT, $catalog);
        fwrite(STDOUT, 'PRIVATE_QA_IMMICH_CATALOG=PASS action=export count=' . $catalog['count'] . "\n");
        exit(0);
    }

    if ($action === 'bind') {
        privateQaImmichAssertBridgeDisabled($repository);
        $catalog = privateQaImmichCatalog($repository, true);
        $input = privateQaImmichReadJson(PRIVATE_QA_BIND_INPUT, 512 * 1024);
        if (!privateQaImmichExactKeys($input, ['version', 'scope', 'catalog_digest', 'assets'])
            || ($input['version'] ?? null) !== 1 || ($input['scope'] ?? null) !== 'PRIVATE_REAL_DATA_QA'
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

    if ($action === 'enable') {
        $catalog = privateQaImmichCatalog($repository, false);
        $input = privateQaImmichReadJson(PRIVATE_QA_ENABLE_INPUT, 2048);
        $token = $input['token'] ?? null;
        if (!privateQaImmichExactKeys($input, ['version', 'scope', 'catalog_digest', 'token'])
            || ($input['version'] ?? null) !== 1 || ($input['scope'] ?? null) !== 'PRIVATE_REAL_DATA_QA'
            || !is_string($input['catalog_digest'] ?? null) || !hash_equals($catalog['catalog_digest'], $input['catalog_digest'])
            || !is_string($token) || preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token) !== 1) {
            throw new RuntimeException('enable_input_invalid');
        }
        if (file_exists(PRIVATE_QA_BRIDGE_SECRET) || is_link(PRIVATE_QA_BRIDGE_SECRET)) {
            throw new RuntimeException('bridge_secret_not_clean');
        }
        privateQaImmichWriteExclusive(PRIVATE_QA_BRIDGE_SECRET, ['version' => 1, 'token' => $token]);
        if (!chmod(PRIVATE_QA_BRIDGE_SECRET, 0660)) {
            @unlink(PRIVATE_QA_BRIDGE_SECRET);
            throw new RuntimeException('bridge_secret_mode_invalid');
        }
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
        'bridge_secret_not_clean', 'bridge_secret_mode_invalid', 'adapter_unavailable', 'class_archive_immich_bridge_binding_invalid',
        'bridge_config_invalid', 'bridge_not_pristine',
        'class_archive_immich_bridge_enablement_invalid', 'class_archive_immich_bridge_response_invalid',
        'class_archive_immich_bridge_secret_unavailable', 'class_archive_immich_bridge_transport_unavailable',
        'class_archive_immich_bridge_unavailable',
    ];
    $reason = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'unexpected';
    privateQaImmichFail($reason);
}
