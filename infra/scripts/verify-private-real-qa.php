<?php

declare(strict_types=1);

/**
 * Aggregate verifier for the isolated Private Real-Data QA import.
 *
 * It emits counts only. Source paths, filenames, media references and hashes
 * are deliberately never written to stdout or an audit record.
 */

const PRIVATE_QA_VERIFY_ROOT = '/var/www/html/piwigo';
const PRIVATE_QA_VERIFY_MANIFEST = '/private-real-qa/selection/private-selection-manifest.json';

function privateQaVerifyFail(string $reason): never
{
    $safe = preg_replace('/[^a-z0-9_.-]/', '_', strtolower($reason));
    fwrite(STDERR, "PRIVATE_QA_VERIFY=FAIL reason={$safe}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    privateQaVerifyFail('runtime_forbidden');
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_QA') !== '1'
    || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'PRIVATE_REAL_DATA_QA') {
    privateQaVerifyFail('private_runtime_required');
}
if (count($_SERVER['argv']) !== 1) {
    privateQaVerifyFail('arguments_forbidden');
}

$manifestPath = realpath(PRIVATE_QA_VERIFY_MANIFEST);
if ($manifestPath !== PRIVATE_QA_VERIFY_MANIFEST || !is_file($manifestPath) || is_link(PRIVATE_QA_VERIFY_MANIFEST)) {
    privateQaVerifyFail('manifest_unavailable');
}
$raw = file_get_contents($manifestPath);
try {
    $manifest = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
} catch (Throwable) {
    $manifest = null;
} finally {
    $raw = null;
}
if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['items'] ?? null)
    || count($manifest['items']) < 300 || count($manifest['items']) > 500) {
    privateQaVerifyFail('manifest_schema_invalid');
}
$expectedHashes = [];
foreach ($manifest['items'] as $item) {
    $hash = is_array($item) ? ($item['source_sha256'] ?? null) : null;
    if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) {
        privateQaVerifyFail('manifest_schema_invalid');
    }
    $expectedHashes[$hash] = true;
}

chdir(PRIVATE_QA_VERIFY_ROOT) || privateQaVerifyFail('piwigo_root_unavailable');
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

if (!class_exists(ClassIdentity\Repository::class)) {
    privateQaVerifyFail('class_archive_runtime_unavailable');
}
$repository = ClassIdentity\Repository::fromPiwigo();
$imageRows = query2array('SELECT `id`,`file`,`path` FROM ' . IMAGES_TABLE . ' ORDER BY `id`');
$expectedImageCount = 72 + count($expectedHashes);
if (count($imageRows) !== $expectedImageCount) {
    privateQaVerifyFail('image_count_invalid');
}

$referenced = [];
foreach ($imageRows as $image) {
    $stored = (string) ($image['path'] ?? '');
    if (!str_starts_with($stored, './upload/') && !str_starts_with($stored, './galleries/')) {
        privateQaVerifyFail('media_reference_invalid');
    }
    $real = realpath(PRIVATE_QA_VERIFY_ROOT . substr($stored, 1));
    if ($real === false || !is_file($real) || is_link($real) || isset($referenced[$real])) {
        privateQaVerifyFail('physical_original_invalid');
    }
    $referenced[$real] = true;
}

$physical = [];
foreach ([PRIVATE_QA_VERIFY_ROOT . '/upload', PRIVATE_QA_VERIFY_ROOT . '/galleries'] as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isFile() && !$entry->isLink()
            && in_array(strtolower($entry->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)) {
            $physical[$entry->getRealPath()] = true;
        }
    }
}
if (count($physical) !== $expectedImageCount || array_diff_key($physical, $referenced) || array_diff_key($referenced, $physical)) {
    privateQaVerifyFail('physical_original_count_invalid');
}

$rows = $repository->fetchAll(
    'SELECT i.`id`,i.`file`,i.`path`,i.`name`,i.`author`,i.`date_creation`,'
    . 'p.`media_checksum`,p.`media_reference`,p.`state`,a.`era`,a.`date_precision` '
    . 'FROM `' . $GLOBALS['prefixeTable'] . 'images` i '
    . 'INNER JOIN `' . $repository->table('photo') . '` p ON p.`piwigo_image_id`=i.`id` '
    . 'INNER JOIN `' . $repository->table('archive_image') . '` a ON a.`piwigo_image_id`=i.`id` '
    . "WHERE i.`file` LIKE 'pqa-%' ORDER BY i.`id` ASC",
);
if (count($rows) !== count($expectedHashes)) {
    privateQaVerifyFail('private_image_count_invalid');
}
$observedHashes = [];
$precision = [];
foreach ($rows as $row) {
    $checksum = $row['media_checksum'] ?? null;
    $path = (string) ($row['path'] ?? '');
    $real = str_starts_with($path, './') ? realpath(PRIVATE_QA_VERIFY_ROOT . substr($path, 1)) : false;
    $mode = $real !== false ? ((int) @fileperms($real) & 0777) : 0;
    $hash = $real !== false ? hash_file('sha256', $real) : false;
    $storedHash = is_string($checksum) && strlen($checksum) === 32 ? bin2hex($checksum) : '';
    if ($real === false || $mode !== 0660 || !is_string($hash) || !hash_equals($storedHash, $hash)
        || !isset($expectedHashes[$hash]) || isset($observedHashes[$hash])
        || ($row['state'] ?? null) !== ClassIdentity\ClassArchivePhoto::STATE_ACTIVE
        || ($row['era'] ?? null) !== 'HERITAGE' || ($row['date_creation'] ?? null) !== null
        || !is_string($row['name'] ?? null) || !str_starts_with((string) $row['name'], '私有 QA 照片 ')
        || ($row['author'] ?? null) !== 'Private local QA') {
        privateQaVerifyFail('private_image_integrity_invalid');
    }
    $observedHashes[$hash] = true;
    $datePrecision = (string) ($row['date_precision'] ?? '');
    if (!in_array($datePrecision, ['TERM', 'EVENT_ONLY', 'UNKNOWN'], true)) {
        privateQaVerifyFail('archive_date_projection_invalid');
    }
    $precision[$datePrecision] = ($precision[$datePrecision] ?? 0) + 1;
}
if (array_diff_key($expectedHashes, $observedHashes) || array_diff_key($observedHashes, $expectedHashes)) {
    privateQaVerifyFail('private_hash_set_invalid');
}

$multiAlbum = (int) ($repository->fetchOne(
    'SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $GLOBALS['prefixeTable'] . 'image_category` '
    . 'GROUP BY `image_id` HAVING COUNT(DISTINCT `category_id`) > 1) multi_album',
)['count'] ?? -1);
if ($multiAlbum < 8) {
    privateQaVerifyFail('multi_album_invalid');
}

ksort($precision, SORT_STRING);
$precisionText = implode(',', array_map(
    static fn (string $key): string => strtolower($key) . ':' . $precision[$key],
    array_keys($precision),
));
fwrite(
    STDOUT,
    'PRIVATE_QA_VERIFY=PASS selected=' . count($manifest['items'])
    . ' distinct=' . count($expectedHashes)
    . ' images=' . count($imageRows)
    . ' originals=' . count($physical)
    . ' multi_album=' . $multiAlbum
    . ' precision=' . $precisionText . "\n",
);
