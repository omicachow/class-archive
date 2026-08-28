<?php

declare(strict_types=1);

/**
 * Exercises Piwigo's real add_uploaded_file() path and proves the
 * ClassArchivePolicy hook removes executable/world permissions before the
 * resulting original is retained. The temporary image is deleted through the
 * real Piwigo removal API in a finally block.
 */

function mediaFilePolicyFail(string $code): never
{
    fwrite(STDERR, 'MEDIA_FILE_POLICY=FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) . "\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    mediaFilePolicyFail('runtime_forbidden');
}

chdir('/var/www/html/piwigo') || mediaFilePolicyFail('root_unavailable');
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
require_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

$before = (int) array_values(query2array('SELECT COUNT(*) AS `count` FROM ' . IMAGES_TABLE)[0])[0];
$token = bin2hex(random_bytes(10));
$fileName = 'class-archive-media-policy-' . $token . '.png';
$temporary = tempnam(sys_get_temp_dir(), 'class-archive-upload-');
if (!is_string($temporary) || $temporary === '') {
    mediaFilePolicyFail('temporary_unavailable');
}
$imageId = 0;
$path = null;

try {
    $payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8kQAAAABJRU5ErkJggg==', true);
    if (!is_string($payload) || file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload) || !chmod($temporary, 0600)) {
        throw new RuntimeException('temporary_write_failed');
    }

    $imageId = (int) add_uploaded_file($temporary, $fileName, null, 0);
    if ($imageId <= 0) {
        throw new RuntimeException('upload_return_invalid');
    }
    $rows = query2array('SELECT `path` FROM ' . IMAGES_TABLE . ' WHERE `id`=' . $imageId . ' LIMIT 1');
    if (count($rows) !== 1 || !is_string($rows[0]['path'] ?? null) || !str_starts_with((string) $rows[0]['path'], './upload/')) {
        throw new RuntimeException('uploaded_path_invalid');
    }
    $path = PHPWG_ROOT_PATH . ltrim((string) $rows[0]['path'], './');
    $mode = @fileperms($path);
    if (!is_int($mode) || ($mode & 0777) !== 0660) {
        throw new RuntimeException('uploaded_mode_not_private');
    }
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('uploaded_file_untrusted');
    }
} catch (Throwable $error) {
    // If add_uploaded_file() threw after Core inserted a row, locate only the
    // unique synthetic filename to give finally a precise cleanup target.
    if ($imageId === 0) {
        $escaped = pwg_db_real_escape_string($fileName);
        $rows = query2array('SELECT `id`,`path` FROM ' . IMAGES_TABLE . " WHERE `file`='{$escaped}' LIMIT 2");
        if (count($rows) === 1) {
            $imageId = (int) $rows[0]['id'];
            $path = is_string($rows[0]['path'] ?? null) ? PHPWG_ROOT_PATH . ltrim((string) $rows[0]['path'], './') : null;
        } elseif (count($rows) > 1) {
            mediaFilePolicyFail('synthetic_row_ambiguous');
        }
    }
    $failure = $error;
} finally {
    $cleanupError = null;
    try {
        if ($imageId > 0) {
            $deleted = delete_elements([$imageId], true);
            if ($deleted !== 1) {
                throw new RuntimeException('synthetic_image_cleanup_failed');
            }
        }
        if (is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
            @unlink($temporary);
        }
        if (is_string($path) && (is_file($path) || is_link($path))) {
            throw new RuntimeException('synthetic_original_residual');
        }
        $after = (int) array_values(query2array('SELECT COUNT(*) AS `count` FROM ' . IMAGES_TABLE)[0])[0];
        if ($after !== $before) {
            throw new RuntimeException('image_count_not_restored');
        }
    } catch (Throwable $error) {
        $cleanupError = $error;
    }
}

if (isset($failure)) {
    mediaFilePolicyFail($failure->getMessage());
}
if (isset($cleanupError)) {
    mediaFilePolicyFail($cleanupError->getMessage());
}

fwrite(STDOUT, "MEDIA_FILE_POLICY=PASS assertions=5\n");
