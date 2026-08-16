<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';
const FIXTURE_DIRECTORY = '/tmp/class-archive-test-images';

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function albumByPermalink(string $permalink): ?array
{
    $escaped = pwg_db_real_escape_string($permalink);
    $rows = query2array(
        'SELECT id, name, permalink, status, visible, id_uppercat FROM ' . CATEGORIES_TABLE
        . " WHERE permalink = '{$escaped}'"
    );
    if (count($rows) > 1) {
        fail("Duplicate fixture album permalink: {$permalink}");
    }
    return $rows[0] ?? null;
}

function ensureFixtureAlbum(string $name, string $permalink, int $parentId): int
{
    $album = albumByPermalink($permalink);
    if ($album === null) {
        $created = create_virtual_category(
            $name,
            $parentId,
            ['status' => 'private', 'visible' => true, 'commentable' => false, 'inherit' => true]
        );
        if (isset($created['error'])) {
            fail("Cannot create fixture album {$name}: {$created['error']}");
        }
        $id = (int) $created['id'];
        single_update(CATEGORIES_TABLE, ['permalink' => $permalink], ['id' => $id]);
        $album = albumByPermalink($permalink);
    }

    if (
        $album === null
        || $album['name'] !== $name
        || (int) $album['id_uppercat'] !== $parentId
        || $album['status'] !== 'private'
        || $album['visible'] !== 'true'
    ) {
        fail("Fixture album {$name} differs from its expected private baseline.");
    }
    return (int) $album['id'];
}

if (getenv('CLASS_ARCHIVE_ALLOW_SYNTHETIC_SEED') !== '1') {
    fail('Synthetic seeding requires CLASS_ARCHIVE_ALLOW_SYNTHETIC_SEED=1.');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fail('Refusing to seed as root.');
}
if (!is_file(PIWIGO_ROOT . '/local/config/database.inc.php')) {
    fail('Piwigo is not installed.');
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
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';

$user = build_user(1, false);
if (($user['status'] ?? null) !== 'webmaster') {
    fail('Fixture seeder could not assume the local bootstrap webmaster.');
}

// Synthetic fixture imports are a bounded administrator operation. Keep them
// out of the asynchronous lounge so the seed command has a deterministic,
// immediately testable result.
conf_update_param('lounge_active', false, true);
conf_update_param('lounge_activate_threshold', 100000, true);
empty_lounge();

$heritage = albumByPermalink('class-archive-heritage');
$living = albumByPermalink('class-archive-living');
if ($heritage === null || $living === null) {
    fail('Run the private baseline before seeding fixtures.');
}

$albums = [
    'heritageGraduation' => ensureFixtureAlbum('毕业典礼（合成测试）', 'fixture-heritage-graduation', (int) $heritage['id']),
    'heritageSports' => ensureFixtureAlbum('运动会（合成测试）', 'fixture-heritage-sports', (int) $heritage['id']),
    'livingReunion' => ensureFixtureAlbum('五周年聚会（合成测试）', 'fixture-living-reunion', (int) $living['id']),
    'livingMinecraft' => ensureFixtureAlbum('昨晚又打 MC 了（合成测试）', 'fixture-living-minecraft', (int) $living['id']),
];

$files = glob(FIXTURE_DIRECTORY . '/class-archive-fixture-*.png');
if ($files === false || count($files) < 50 || count($files) > 100) {
    fail('Expected 50-100 generated PNG fixtures.');
}
sort($files, SORT_NATURAL);

$imageIds = [];
foreach ($files as $path) {
    if (preg_match('/class-archive-fixture-([0-9]{3})\.png$/', $path, $matches) !== 1) {
        fail('Unexpected fixture path: ' . $path);
    }
    $index = (int) $matches[1];
    $isHeritage = $index % 2 === 0;
    if ($isHeritage) {
        $categoryIds = [$index % 4 === 0 ? $albums['heritageSports'] : $albums['heritageGraduation']];
        if ($index % 12 === 0) {
            $categoryIds = [$albums['heritageGraduation'], $albums['heritageSports']];
        }
        $date = sprintf('2023-%02d-%02d 12:00:00', (($index / 2) % 12) + 1, (($index * 3) % 27) + 1);
        $title = sprintf('高中记忆 %03d（合成）', $index);
    } else {
        $categoryIds = [$index % 4 === 1 ? $albums['livingReunion'] : $albums['livingMinecraft']];
        if ($index % 15 === 0) {
            $categoryIds = [$albums['livingReunion'], $albums['livingMinecraft']];
        }
        $date = sprintf('2026-%02d-%02d 18:30:00', (($index + 1) % 8) + 1, (($index * 5) % 27) + 1);
        $title = sprintf('后来记忆 %03d（合成）', $index);
    }

    $imageId = add_uploaded_file($path, basename($path), $categoryIds, 0);
    single_update(
        IMAGES_TABLE,
        [
            'name' => $title,
            'author' => 'Class Archive synthetic seed',
            'date_creation' => $date,
            'comment' => 'Synthetic fixture; contains no real person or class photo.',
        ],
        ['id' => $imageId]
    );
    $imageIds[] = (int) $imageId;
}

invalidate_user_cache();
$uniqueImageIds = array_values(array_unique($imageIds));
if (count($uniqueImageIds) !== count($files)) {
    fail('Fixture hashes unexpectedly collapsed into duplicate images.');
}

$multiAlbumRows = query2array(
    'SELECT image_id, COUNT(*) AS album_count FROM ' . IMAGE_CATEGORY_TABLE
    . ' WHERE image_id IN (' . implode(',', $uniqueImageIds) . ') GROUP BY image_id HAVING COUNT(*) > 1'
);
if ($multiAlbumRows === []) {
    fail('No fixture image was associated with multiple logical albums.');
}

fwrite(
    STDOUT,
    sprintf(
        "SYNTHETIC_SEED_OK images=%d multi_album_images=%d\n",
        count($uniqueImageIds),
        count($multiAlbumRows)
    )
);
