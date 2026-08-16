<?php

declare(strict_types=1);

const CLASS_ARCHIVE_TINY_PIWIGO_ROOT = '/var/www/html/piwigo';

function tinyFail(string $message): never
{
    throw new RuntimeException($message);
}

function tinyAssertRunId(string $value): string
{
    if (!preg_match('/\A[a-f0-9]{16}\z/D', $value)) {
        tinyFail('Invalid synthetic fixture run id.');
    }
    return $value;
}

/** @return array<string, mixed> */
function tinyImageRow(int $imageId, string $runId): array
{
    $rows = query2array('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id = ' . $imageId);
    if (
        count($rows) !== 1
        || (string) $rows[0]['file'] !== 'class-archive-tiny-preview-' . $runId . '.jpg'
        || (string) $rows[0]['comment'] !== 'Class Archive synthetic tiny-preview fixture ' . $runId
    ) {
        tinyFail('Refusing to operate on a non-fixture image.');
    }
    return $rows[0];
}

function tinyRelativePath(array $row): string
{
    $path = preg_replace('#^\./#', '', (string) $row['path']);
    if (
        !is_string($path)
        || !preg_match('#\Aupload/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z#D', $path)
        || str_contains($path, '..')
    ) {
        tinyFail('Fixture storage path is unsafe.');
    }
    return $path;
}

function tinyDerivativePath(string $source): string
{
    global $conf;
    $type = (string) ($conf['class_archive_safe_preview_type'] ?? IMG_XLARGE);
    if (!isset(ImageStdParams::get_defined_type_map()[$type])) {
        tinyFail('Safe preview derivative type is unavailable.');
    }
    $dot = strrpos($source, '.');
    if ($dot === false) {
        tinyFail('Fixture source has no extension.');
    }
    return substr($source, 0, $dot) . '-' . derivative_to_url($type) . substr($source, $dot);
}

function tinyJson(array $payload): void
{
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

if (getenv('CLASS_ARCHIVE_ALLOW_TINY_PREVIEW_FIXTURE') !== '1') {
    fwrite(STDERR, "ERROR: Synthetic tiny-preview fixture flag is required.\n");
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "ERROR: Refusing to manage the fixture as root.\n");
    exit(1);
}

$temporaryInput = null;
$createdImageId = null;
try {
    chdir(CLASS_ARCHIVE_TINY_PIWIGO_ROOT) || tinyFail('Cannot enter Piwigo root.');
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
        tinyFail('Fixture manager could not assume the bootstrap webmaster.');
    }

    $arguments = $_SERVER['argv'] ?? [];
    $mode = (string) ($arguments[1] ?? '');
    if ($mode === 'count') {
        $countRows = query2array('SELECT COUNT(*) AS image_count FROM ' . IMAGES_TABLE);
        if (count($countRows) !== 1) {
            tinyFail('Cannot count baseline images.');
        }
        tinyJson(['image_count' => (int) $countRows[0]['image_count']]);
        exit;
    }

    $runId = tinyAssertRunId((string) ($arguments[2] ?? ''));
    if ($mode === 'create') {
        $existing = query2array(
            'SELECT id FROM ' . IMAGES_TABLE . " WHERE file = 'class-archive-tiny-preview-{$runId}.jpg'"
        );
        if ($existing !== []) {
            tinyFail('Fixture run id already exists.');
        }
        $heritage = query2array(
            "SELECT id FROM " . CATEGORIES_TABLE . " WHERE permalink = 'class-archive-heritage'"
        );
        if (count($heritage) !== 1) {
            tinyFail('HERITAGE root is unavailable.');
        }

        $temporaryInput = sys_get_temp_dir() . '/class-archive-tiny-preview-' . $runId . '.jpg';
        $canvas = imagecreatetruecolor(160, 120);
        if ($canvas === false) {
            tinyFail('Cannot allocate the synthetic image.');
        }
        $background = imagecolorallocate($canvas, 24, 42, 68);
        $accent = imagecolorallocate($canvas, 84, 190, 173);
        $light = imagecolorallocate($canvas, 240, 244, 248);
        imagefill($canvas, 0, 0, $background);
        imagefilledrectangle($canvas, 12, 12, 147, 78, $accent);
        imageline($canvas, 0, 119, 159, 0, $light);
        imagestring($canvas, 3, 18, 42, 'SYNTHETIC', $background);
        imagestring($canvas, 2, 18, 88, substr($runId, 0, 8), $light);
        $written = imagejpeg($canvas, $temporaryInput, 100);
        imagedestroy($canvas);
        if (!$written || !is_file($temporaryInput) || filesize($temporaryInput) <= 0) {
            tinyFail('Cannot create the synthetic JPEG.');
        }

        $filename = 'class-archive-tiny-preview-' . $runId . '.jpg';
        $createdImageId = (int) add_uploaded_file(
            $temporaryInput,
            $filename,
            [(int) $heritage[0]['id']],
            0,
        );
        if ($createdImageId <= 0) {
            tinyFail('Piwigo did not import the synthetic JPEG.');
        }
        single_update(
            IMAGES_TABLE,
            [
                'name' => 'Tiny preview synthetic fixture ' . $runId,
                'author' => 'Class Archive test suite',
                'date_creation' => '2023-06-01 12:00:00',
                'comment' => 'Class Archive synthetic tiny-preview fixture ' . $runId,
            ],
            ['id' => $createdImageId],
        );
        $row = tinyImageRow($createdImageId, $runId);
        $source = tinyRelativePath($row);
        $sourceFile = CLASS_ARCHIVE_TINY_PIWIGO_ROOT . '/' . $source;
        if (is_file($temporaryInput) && !unlink($temporaryInput)) {
            tinyFail('Cannot remove the temporary synthetic input.');
        }
        $temporaryInput = null;
        tinyJson([
            'image_id' => $createdImageId,
            'source_path' => $source,
            'derivative_path' => tinyDerivativePath($source),
            'source_sha256' => hash_file('sha256', $sourceFile),
            'source_width' => (int) $row['width'],
            'source_height' => (int) $row['height'],
        ]);
        $createdImageId = null;
        exit;
    }

    $imageId = (int) ($arguments[3] ?? 0);
    if ($imageId <= 0) {
        tinyFail('Fixture image id is required.');
    }
    $row = tinyImageRow($imageId, $runId);
    $source = tinyRelativePath($row);
    $sourceFile = CLASS_ARCHIVE_TINY_PIWIGO_ROOT . '/' . $source;
    $derivative = tinyDerivativePath($source);
    $derivativeFile = CLASS_ARCHIVE_TINY_PIWIGO_ROOT . '/_data/i/' . $derivative;

    if ($mode === 'inspect') {
        if (!is_file($derivativeFile) || is_link($derivativeFile)) {
            tinyFail('Safe preview derivative is missing.');
        }
        $sourceSize = getimagesize(CLASS_ARCHIVE_TINY_PIWIGO_ROOT . '/' . $source);
        $derivativeSize = getimagesize($derivativeFile);
        if (!is_array($sourceSize) || !is_array($derivativeSize)) {
            tinyFail('Cannot inspect fixture dimensions.');
        }
        tinyJson([
            'derivative_sha256' => hash_file('sha256', $derivativeFile),
            'derivative_width' => (int) $derivativeSize[0],
            'derivative_height' => (int) $derivativeSize[1],
            'source_width' => (int) $sourceSize[0],
            'source_height' => (int) $sourceSize[1],
            'mode' => substr(sprintf('%o', fileperms($derivativeFile)), -3),
        ]);
        exit;
    }

    if ($mode === 'purge') {
        if (file_exists($derivativeFile) || is_link($derivativeFile)) {
            if (!is_file($derivativeFile) || is_link($derivativeFile) || !unlink($derivativeFile)) {
                tinyFail('Cannot purge the fixture derivative safely.');
            }
        }
        tinyJson(['purged' => true]);
        exit;
    }

    if ($mode === 'delete') {
        $deleted = delete_elements([$imageId], true);
        if ($deleted !== 1 || query2array('SELECT id FROM ' . IMAGES_TABLE . ' WHERE id = ' . $imageId) !== []) {
            tinyFail('Fixture image cleanup failed.');
        }
        if (is_file($sourceFile) || is_link($sourceFile)) {
            tinyFail('Fixture original survived image cleanup.');
        }
        if (is_file($derivativeFile) || is_link($derivativeFile)) {
            tinyFail('Fixture derivative survived image cleanup.');
        }
        tinyJson([
            'deleted' => true,
            'original_deleted' => true,
            'derivative_deleted' => true,
        ]);
        exit;
    }

    tinyFail('Unknown fixture mode.');
} catch (Throwable $exception) {
    if ($createdImageId !== null && $createdImageId > 0 && function_exists('delete_elements')) {
        @delete_elements([$createdImageId], true);
    }
    if ($temporaryInput !== null && (is_file($temporaryInput) || is_link($temporaryInput))) {
        @unlink($temporaryInput);
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if ($temporaryInput !== null && is_file($temporaryInput)) {
        @unlink($temporaryInput);
    }
}
