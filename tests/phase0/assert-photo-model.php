<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function scalarQuery(string $query): int
{
    $result = pwg_query($query);
    $row = pwg_db_fetch_row($result);
    return (int) $row[0];
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fail('Refusing to inspect as root.');
}
chdir(PIWIGO_ROOT) || fail('Cannot enter Piwigo root.');
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

$imageCount = scalarQuery('SELECT COUNT(*) FROM ' . IMAGES_TABLE);
if ($imageCount !== 72) {
    fail("Expected 72 synthetic images, found {$imageCount}.");
}
$pathCount = scalarQuery('SELECT COUNT(DISTINCT path) FROM ' . IMAGES_TABLE);
if ($pathCount !== $imageCount) {
    fail('More than one image record points at the same original path.');
}

$imageRows = query2array('SELECT id, path FROM ' . IMAGES_TABLE . ' ORDER BY id');
$referencedFiles = [];
foreach ($imageRows as $imageRow) {
    $storedPath = (string) $imageRow['path'];
    if (!str_starts_with($storedPath, './upload/') && !str_starts_with($storedPath, './galleries/')) {
        fail("Image {$imageRow['id']} has an unexpected original path: {$storedPath}");
    }
    $realPath = realpath(PIWIGO_ROOT . substr($storedPath, 1));
    if ($realPath === false || !is_file($realPath)) {
        fail("Image {$imageRow['id']} original is missing: {$storedPath}");
    }
    $referencedFiles[$realPath] = true;
}
if (count($referencedFiles) !== $imageCount) {
    fail('Image rows do not resolve to one distinct physical original each.');
}

$physicalOriginals = [];
foreach ([PIWIGO_ROOT . '/upload', PIWIGO_ROOT . '/galleries'] as $originalRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($originalRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $extension = strtolower($entry->getExtension());
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)) {
            $physicalOriginals[$entry->getRealPath()] = true;
        }
    }
}
if (count($physicalOriginals) !== $imageCount || array_diff_key($physicalOriginals, $referencedFiles)) {
    fail('The fixture media trees contain an extra or unreferenced physical original.');
}
$multiAlbumCount = scalarQuery(
    'SELECT COUNT(*) FROM (SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE
    . ' GROUP BY image_id HAVING COUNT(*) > 1) AS multi_album'
);
if ($multiAlbumCount < 1) {
    fail('No photo is associated with multiple logical albums.');
}

$roots = query2array(
    'SELECT id, permalink FROM ' . CATEGORIES_TABLE
    . " WHERE permalink IN ('class-archive-heritage','class-archive-living')",
    'permalink'
);
if (!isset($roots['class-archive-heritage'], $roots['class-archive-living'])) {
    fail('Era roots are missing.');
}
$heritageId = (int) $roots['class-archive-heritage']['id'];
$livingId = (int) $roots['class-archive-living']['id'];

$rows = query2array(
    'SELECT ic.image_id, c.uppercats FROM ' . IMAGE_CATEGORY_TABLE . ' ic'
    . ' JOIN ' . CATEGORIES_TABLE . ' c ON c.id = ic.category_id'
    . ' ORDER BY ic.image_id'
);
$erasByImage = [];
foreach ($rows as $row) {
    $ancestors = array_map('intval', explode(',', $row['uppercats']));
    if (in_array($heritageId, $ancestors, true)) {
        $erasByImage[(int) $row['image_id']]['HERITAGE'] = true;
    }
    if (in_array($livingId, $ancestors, true)) {
        $erasByImage[(int) $row['image_id']]['LIVING'] = true;
    }
}
foreach ($erasByImage as $imageId => $eras) {
    if (count($eras) !== 1) {
        fail("Image {$imageId} crosses or lacks an Era boundary.");
    }
}

$community = query2array("SELECT state FROM " . PLUGINS_TABLE . " WHERE id IN ('Community','community')");
foreach ($community as $row) {
    if ($row['state'] === 'active') {
        fail('Community is active before its security gate is resolved.');
    }
}
if (is_dir(PIWIGO_ROOT . '/plugins/UserCollections')) {
    fail('Quarantined User Collections code is present in the supported runtime.');
}

fwrite(STDOUT, "PHOTO_MODEL_ASSERTIONS=PASS\n");
fwrite(STDOUT, "IMAGES={$imageCount}\n");
fwrite(STDOUT, 'ORIGINAL_FILES=' . count($physicalOriginals) . "\n");
fwrite(STDOUT, "MULTI_ALBUM_IMAGES={$multiAlbumCount}\n");
