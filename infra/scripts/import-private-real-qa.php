<?php

declare(strict_types=1);

/**
 * One-way import of opaque, verified private-QA staging copies.
 *
 * This CLI exists only inside the isolated Private QA compose project.  It
 * never sees the original source roots, never persists a source filename, and
 * refuses to run in the canonical synthetic environment.
 */

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePhotoMappingService;

function privateQaFail(string $reason): never
{
    $safe = preg_replace('/[^a-z0-9_.-]/', '_', strtolower($reason));
    fwrite(STDERR, "PRIVATE_QA_IMPORT=FAIL reason={$safe}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    privateQaFail('runtime_forbidden');
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_QA') !== '1') {
    privateQaFail('private_runtime_required');
}

$manifestPath = (string) ($_SERVER['argv'][1] ?? '');
$stagingRoot = (string) ($_SERVER['argv'][2] ?? '');
if ($manifestPath !== '/private-real-qa/selection/private-selection-manifest.json'
    || $stagingRoot !== '/private-real-qa/staging') {
    privateQaFail('fixed_private_mount_required');
}
$manifestReal = realpath($manifestPath);
$stagingReal = realpath($stagingRoot);
if ($manifestReal !== $manifestPath || $stagingReal !== $stagingRoot || !is_file($manifestReal) || !is_dir($stagingReal)
    || is_link($manifestPath) || is_link($stagingRoot)) {
    privateQaFail('private_mount_invalid');
}

$raw = file_get_contents($manifestReal);
if (!is_string($raw) || strlen($raw) < 20 || strlen($raw) > 32 * 1024 * 1024) {
    privateQaFail('manifest_unavailable');
}
try {
    $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    privateQaFail('manifest_json_invalid');
}
$raw = null;
if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1 || !is_array($manifest['items'] ?? null)
    || count($manifest['items']) < 1 || count($manifest['items']) > 500) {
    privateQaFail('manifest_schema_invalid');
}

chdir('/var/www/html/piwigo') || privateQaFail('piwigo_root_unavailable');
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

if (!class_exists(ClassIdentityArchiveService::class) || !class_exists(ClassArchivePhotoMappingService::class)) {
    privateQaFail('class_archive_runtime_unavailable');
}
$repository = ClassIdentity\Repository::fromPiwigo();
$principal = $repository->fetchOne(
    'SELECT `piwigo_user_id` FROM `' . $repository->table('principal') . '` '
    . "WHERE `principal_type`='SYSTEM_ACCOUNT' AND `system_role`='SYSTEM_ADMIN' AND `state`='ACTIVE' LIMIT 2",
);
if ($principal === null || (int) ($principal['piwigo_user_id'] ?? 0) <= 0) {
    privateQaFail('system_admin_unavailable');
}
$adminUserId = (int) $principal['piwigo_user_id'];
$user = build_user($adminUserId, false);
if (($user['status'] ?? null) !== 'webmaster') {
    privateQaFail('system_admin_core_status_invalid');
}

/** @return array<string,mixed>|null */
function privateQaAlbumByName(ClassIdentity\Repository $repository, int $parentId, string $name): ?array
{
    global $prefixeTable;
    $rows = $repository->fetchAll(
        'SELECT `id`,`name`,`id_uppercat`,`uppercats` FROM `' . $prefixeTable . 'categories` '
        . 'WHERE `id_uppercat`=? AND `name`=? LIMIT 2',
        [$parentId, $name],
    );
    if (count($rows) > 1) {
        throw new RuntimeException('private_album_ambiguous');
    }
    return $rows[0] ?? null;
}

function privateQaHeritageRoot(ClassIdentity\Repository $repository): int
{
    global $prefixeTable;
    $row = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 1',
        ['class-archive-heritage'],
    );
    if ($row === null || (int) ($row['id'] ?? 0) <= 0) {
        throw new RuntimeException('heritage_root_missing');
    }
    return (int) $row['id'];
}

function privateQaEnsureAlbum(
    ClassIdentityArchiveService $archive,
    ClassIdentity\Repository $repository,
    int $adminUserId,
    int $parentId,
    string $name,
): int {
    $row = privateQaAlbumByName($repository, $parentId, $name);
    if ($row !== null) {
        return (int) $row['id'];
    }
    return $archive->createOfficialAlbum(
        $adminUserId,
        'HERITAGE',
        $name,
        '仅用于本机私有照片界面验收；不代表正式档案结论。',
        'Private local real-data QA album provisioning',
    );
}

/** @return array{date:?string,precision:string,confidence:string,source:string,event:?string,album:string} */
function privateQaProjection(int $index, string $sourceLabel): array
{
    // These are explicitly QA-only projections.  No filesystem or upload
    // timestamp is ever promoted to capture evidence.  Source B has collection
    // semantics that make a generic graduation event useful without naming a
    // person or claiming a day/year; Source A remains mostly unknown.
    if ($sourceLabel === 'Private Source B') {
        return [
            'date' => null,
            'precision' => 'EVENT_ONLY',
            'confidence' => 'LOW',
            'source' => 'EVENT_INFERENCE',
            'event' => '毕业（私有 QA 集合）',
            'album' => '毕业（私有 QA）',
        ];
    }
    if ($index % 4 === 0) {
        return [
            'date' => null,
            'precision' => 'TERM',
            'confidence' => 'LOW',
            'source' => 'EVENT_INFERENCE',
            'event' => '班级活动（私有 QA 集合）',
            'album' => '班级活动（私有 QA）',
        ];
    }
    return [
        'date' => null,
        'precision' => 'UNKNOWN',
        'confidence' => 'UNKNOWN',
        'source' => 'UNKNOWN',
        'event' => null,
        'album' => '日期待整理（私有 QA）',
    ];
}

function privateQaOriginal(int $imageId): array
{
    global $prefixeTable;
    $rows = query2array('SELECT `path`,`file` FROM `' . $prefixeTable . 'images` WHERE `id`=' . $imageId . ' LIMIT 1');
    if (count($rows) !== 1) {
        throw new RuntimeException('imported_image_missing');
    }
    $reference = ClassArchivePhoto::normalizeMediaReference((string) ($rows[0]['path'] ?? ''));
    $path = PHPWG_ROOT_PATH . $reference;
    $real = realpath($path);
    $root = realpath(PHPWG_ROOT_PATH . (str_starts_with($reference, 'upload/') ? 'upload' : 'galleries'));
    if ($real === false || $root === false || !is_file($real) || is_link($path)
        || !str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
        throw new RuntimeException('imported_original_untrusted');
    }
    if (!chmod($real, 0660)) {
        throw new RuntimeException('imported_original_mode_failed');
    }
    clearstatcache(true, $real);
    if (((int) fileperms($real) & 0777) !== 0660) {
        throw new RuntimeException('imported_original_mode_invalid');
    }
    $checksum = hash_file('sha256', $real);
    if (!is_string($checksum)) {
        throw new RuntimeException('imported_original_hash_failed');
    }
    return [$checksum, $reference];
}

$archive = ClassIdentityArchiveService::fromPiwigo();
$mapping = ClassArchivePhotoMappingService::fromPiwigo();
$heritageRoot = privateQaHeritageRoot($repository);
$albumNames = ['毕业（私有 QA）', '班级活动（私有 QA）', '日期待整理（私有 QA）'];
$albums = [];
foreach ($albumNames as $albumName) {
    $albums[$albumName] = privateQaEnsureAlbum($archive, $repository, $adminUserId, $heritageRoot, $albumName);
}

$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
$seenNames = [];
$imported = 0;
$skipped = 0;
try {
    foreach ($manifest['items'] as $offset => $item) {
        if (!is_array($item) || !is_string($item['private_sample_id'] ?? null)
            || preg_match('/\APQA-[0-9]{4}-[0-9a-f]{10}\z/D', $item['private_sample_id']) !== 1
            || !is_string($item['staging_name'] ?? null)
            || preg_match('/\Apqa-[0-9]{4}-[0-9a-f]{16}\.([a-z0-9]{2,5})\z/Di', $item['staging_name'], $extensionMatch) !== 1
            || !is_string($item['source_sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $item['source_sha256']) !== 1
            || !is_string($item['source_label'] ?? null)
            || !in_array($item['source_label'], ['Private Source A', 'Private Source B'], true)) {
            throw new RuntimeException('manifest_item_invalid');
        }
        $extension = strtolower($extensionMatch[1]);
        if (!in_array($extension, $allowedExtensions, true) || isset($seenNames[$item['staging_name']])) {
            throw new RuntimeException('manifest_item_format_invalid');
        }
        $seenNames[$item['staging_name']] = true;
        $source = $stagingRoot . '/' . $item['staging_name'];
        $sourceReal = realpath($source);
        if ($sourceReal === false || dirname($sourceReal) !== $stagingReal || !is_file($sourceReal) || is_link($source)
            || !hash_equals($item['source_sha256'], (string) hash_file('sha256', $sourceReal))) {
            throw new RuntimeException('staging_item_integrity_invalid');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($sourceReal);
        if (!is_string($mime) || !in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('staging_item_mime_invalid');
        }
        $opaqueFile = strtolower($item['private_sample_id']) . '-' . substr($item['source_sha256'], 0, 12) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $escapedFile = pwg_db_real_escape_string($opaqueFile);
        $existing = query2array('SELECT `id` FROM ' . IMAGES_TABLE . " WHERE `file`='{$escapedFile}' LIMIT 2");
        if (count($existing) > 1) {
            throw new RuntimeException('existing_import_ambiguous');
        }
        if (count($existing) === 1) {
            ++$skipped;
            continue;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'class-archive-private-qa-');
        if (!is_string($temporary) || $temporary === '') {
            throw new RuntimeException('temporary_unavailable');
        }
        $imageId = 0;
        try {
            if (!copy($sourceReal, $temporary) || !chmod($temporary, 0600)
                || !hash_equals($item['source_sha256'], (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('temporary_copy_failed');
            }
            $projection = privateQaProjection((int) $offset, $item['source_label']);
            $imageId = (int) add_uploaded_file($temporary, $opaqueFile, [$albums[$projection['album']]], 0);
            if ($imageId <= 0) {
                throw new RuntimeException('piwigo_import_failed');
            }
            single_update(IMAGES_TABLE, [
                'name' => sprintf('私有 QA 照片 %04d', (int) $offset + 1),
                'author' => 'Private local QA',
                'date_creation' => null,
                'comment' => '本机私有 QA 副本；日期字段仅用于界面验证，不代表正式档案结论。',
            ], ['id' => $imageId]);
            $archive->saveMetadata(
                $adminUserId,
                $imageId,
                'HERITAGE',
                $projection['date'],
                $projection['precision'],
                $projection['confidence'],
                $projection['source'],
                $projection['event'],
                true,
                $albums[$projection['album']],
                'Private local real-data QA archive projection',
            );
            [$checksum, $reference] = privateQaOriginal($imageId);
            if (!hash_equals($item['source_sha256'], $checksum)) {
                throw new RuntimeException('piwigo_original_hash_mismatch');
            }
            $canonical = $mapping->ensurePiwigoMapping($imageId, $checksum, $reference);
            if (class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
                $warmupQueued = \ClassArchiveDerivativeWarmupQueue::enqueueBestEffort(
                    (string) ($canonical['class_photo_id'] ?? ''),
                    $imageId,
                );
                if ($warmupQueued && class_exists('ClassArchiveDerivativeCacheWarmer', false)) {
                    // The importer processes one committed image at a time;
                    // resizing is never performed inside a database transaction.
                    \ClassArchiveDerivativeCacheWarmer::warmBestEffort(
                        (string) ($canonical['class_photo_id'] ?? ''),
                        $imageId,
                    );
                }
            }
            ++$imported;
        } catch (Throwable $error) {
            if ($imageId > 0) {
                try {
                    delete_elements([$imageId], true);
                } catch (Throwable) {
                    // The isolated QA environment is left fail-closed for
                    // reconciliation rather than masking the first failure.
                }
            }
            throw $error;
        } finally {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }
    invalidate_user_cache();
} catch (Throwable $error) {
    privateQaFail('import_transaction_failed');
}

fwrite(STDOUT, "PRIVATE_QA_IMPORT=PASS imported={$imported} skipped={$skipped} era=HERITAGE filenames=opaque\n");
