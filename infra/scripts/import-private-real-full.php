<?php

declare(strict_types=1);

/**
 * Resumable import for the isolated full private real-photo library.
 *
 * This executable is intentionally separate from import-private-real-qa.php.
 * It accepts only the fixed read-only full-library staging mount, stores no
 * absolute source path/original filename, and can never run in the synthetic
 * or sample-QA compose projects.
 */

use ClassIdentity\AlbumService;
use ClassIdentity\AiIndexService;
use ClassIdentity\CanonicalPhotoService;
use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePhotoMappingService;
use ClassIdentity\PrivateFullLibraryService;

const PRIVATE_FULL_MANIFEST = '/private-real-full/manifests/full-real-import-manifest.json';
const PRIVATE_FULL_STAGING = '/private-real-full/staging';
const PRIVATE_FULL_VERSION = 1;
const PRIVATE_FULL_MAX_ITEMS = 200000;

function privateFullFail(string $reason): never
{
    $safe = preg_replace('/[^a-z0-9_.-]/', '_', strtolower($reason));
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_IMPORT=FAIL reason={$safe}\n");
    exit(1);
}

function privateFullHashText(string $value): string
{
    return hash('sha256', $value);
}

function privateFullHex(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A[0-9a-f]{64}\z/Di', $value) !== 1) {
        throw new RuntimeException('manifest_digest_invalid');
    }
    return strtolower($value);
}

function privateFullCollection(mixed $code, mixed $label): array
{
    if (!is_string($code) || !is_string($label)) {
        throw new RuntimeException('manifest_collection_invalid');
    }
    if (!in_array($code, ['PRIVATE_SOURCE_A', 'PRIVATE_SOURCE_B'], true)
        || trim($label) === '' || strlen($label) > 190 || str_contains($label, "\0")
        || str_contains($label, '/') || str_contains($label, "\\") || preg_match('/\A[A-Za-z]:/D', $label) === 1
        || preg_match('/[\x00-\x1F\x7F]/', $label) === 1 || preg_match('//u', $label) !== 1
    ) {
        throw new RuntimeException('manifest_collection_invalid');
    }
    return [$code, $label];
}

/** @return array<string,mixed> */
function privateFullNormalizeItem(mixed $item): array
{
    if (!is_array($item)) {
        throw new RuntimeException('manifest_item_invalid');
    }
    // The manifest mounted in Piwigo is intentionally path-free. Raw source
    // paths and original filenames remain only in the owner-local copier
    // journal and never reach this process, database, Audit, or Piwigo API.
    if (array_key_exists('relative_source_path', $item) || array_key_exists('original_filename', $item)
        || array_key_exists('source_root', $item)
    ) {
        throw new RuntimeException('manifest_sensitive_source_field');
    }
    [$code, $label] = privateFullCollection($item['source_collection_code'] ?? null, $item['source_collection_label'] ?? null);
    $itemDigest = privateFullHex($item['item_digest'] ?? null);
    $folderDigest = privateFullHex($item['folder_path_digest'] ?? null);
    $parentDigest = $item['parent_folder_path_digest'] ?? null;
    if ($parentDigest !== null) {
        $parentDigest = privateFullHex($parentDigest);
    }
    if (!is_array($item['folder_segments'] ?? null) || count($item['folder_segments']) > 255) {
        throw new RuntimeException('manifest_folder_segments_invalid');
    }
    $folders = [];
    foreach ($item['folder_segments'] as $segment) {
        if (!is_string($segment) || $segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 190
            || str_contains($segment, "\\") || str_contains($segment, '/') || str_contains($segment, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1 || preg_match('//u', $segment) !== 1
        ) {
            throw new RuntimeException('manifest_folder_segments_invalid');
        }
        $folders[] = $segment;
    }
    $extension = strtolower((string) ($item['extension'] ?? ''));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('manifest_extension_invalid');
    }
    $sourceReferenceDigest = privateFullHex($item['source_reference_digest'] ?? null);
    $expectedItemDigest = privateFullHashText($code . "\0" . $sourceReferenceDigest);
    $folderPath = implode('/', $folders);
    $expectedFolderDigest = privateFullHashText($code . "\0" . $folderPath);
    $expectedParentDigest = $folders === [] ? null : privateFullHashText($code . "\0" . implode('/', array_slice($folders, 0, -1)));
    if (!hash_equals($expectedItemDigest, $itemDigest) || !hash_equals($expectedFolderDigest, $folderDigest)
        || (($expectedParentDigest === null && $parentDigest !== null) || ($expectedParentDigest !== null && !hash_equals($expectedParentDigest, (string) $parentDigest)))
    ) {
        throw new RuntimeException('manifest_folder_digest_invalid');
    }
    $stagingName = $item['staging_name'] ?? null;
    $expectedStaging = 'frl-' . privateFullHex($item['source_sha256'] ?? null) . '.' . $extension;
    if (!is_string($stagingName) || !hash_equals($expectedStaging, $stagingName)
        || !hash_equals(privateFullHashText($stagingName), privateFullHex($item['staging_name_digest'] ?? null))
    ) {
        throw new RuntimeException('manifest_staging_name_invalid');
    }
    $size = $item['file_size'] ?? null;
    if (!is_int($size) || $size <= 0) {
        throw new RuntimeException('manifest_item_size_invalid');
    }
    $filenameDigest = privateFullHex($item['original_filename_digest'] ?? null);
    return [
        'item_digest' => $itemDigest,
        'source_collection_code' => $code,
        'source_collection_label' => $label,
        'folder_path_digest' => $folderDigest,
        'parent_folder_path_digest' => $parentDigest,
        'folder_segments' => $folders,
        'source_reference_digest' => $sourceReferenceDigest,
        'original_filename_digest' => $filenameDigest,
        'source_sha256' => privateFullHex($item['source_sha256'] ?? null),
        'staging_name' => $stagingName,
        'staging_name_digest' => privateFullHex($item['staging_name_digest'] ?? null),
        'file_size' => $size,
        'extension' => $extension,
    ];
}

/** @param list<array<string,mixed>> $items */
function privateFullManifestDigest(array $items): string
{
    usort($items, static fn(array $left, array $right): int => [$left['source_collection_code'], $left['item_digest']] <=> [$right['source_collection_code'], $right['item_digest']]);
    $lines = ['CLASS_ARCHIVE_PRIVATE_FULL_LIBRARY', 'VERSION=' . PRIVATE_FULL_VERSION];
    foreach ($items as $item) {
        $lines[] = implode("\x1e", [
            $item['item_digest'], $item['source_collection_code'], $item['source_collection_label'],
            $item['folder_path_digest'], $item['parent_folder_path_digest'] ?? '',
            implode("\x1f", $item['folder_segments']), $item['source_reference_digest'],
            $item['original_filename_digest'], $item['source_sha256'], $item['staging_name'],
            $item['staging_name_digest'], (string) $item['file_size'], $item['extension'],
        ]);
    }
    return privateFullHashText(implode("\n", $lines) . "\n");
}

/** @return array<string,mixed> */
function privateFullLoadManifest(): array
{
    $manifestPath = (string) ($_SERVER['argv'][1] ?? '');
    $stagingRoot = (string) ($_SERVER['argv'][2] ?? '');
    if ($manifestPath !== PRIVATE_FULL_MANIFEST || $stagingRoot !== PRIVATE_FULL_STAGING) {
        throw new RuntimeException('fixed_private_mount_required');
    }
    $manifestReal = realpath($manifestPath);
    $stagingReal = realpath($stagingRoot);
    if ($manifestReal !== $manifestPath || $stagingReal !== $stagingRoot || !is_file($manifestReal) || !is_dir($stagingReal)
        || is_link($manifestPath) || is_link($stagingRoot)
    ) {
        throw new RuntimeException('private_mount_invalid');
    }
    $raw = file_get_contents($manifestReal);
    if (!is_string($raw) || strlen($raw) < 20 || strlen($raw) > 128 * 1024 * 1024) {
        throw new RuntimeException('manifest_unavailable');
    }
    try {
        $manifest = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('manifest_json_invalid');
    }
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== PRIVATE_FULL_VERSION
        || ($manifest['kind'] ?? null) !== 'class_archive_private_full_library' || !is_array($manifest['items'] ?? null)
        || count($manifest['items']) < 1 || count($manifest['items']) > PRIVATE_FULL_MAX_ITEMS
    ) {
        throw new RuntimeException('manifest_schema_invalid');
    }
    $items = [];
    $itemDigests = [];
    $stagingNames = [];
    foreach ($manifest['items'] as $rawItem) {
        $item = privateFullNormalizeItem($rawItem);
        if (isset($itemDigests[$item['item_digest']])) {
            throw new RuntimeException('manifest_item_duplicate');
        }
        if (isset($stagingNames[$item['staging_name']]) && !hash_equals($stagingNames[$item['staging_name']], (string) $item['source_sha256'])) {
            throw new RuntimeException('manifest_staging_collision');
        }
        $itemDigests[$item['item_digest']] = true;
        $stagingNames[$item['staging_name']] = (string) $item['source_sha256'];
        $items[] = $item;
    }
    $digest = privateFullManifestDigest($items);
    if (!is_string($manifest['import_digest'] ?? null) || !hash_equals($digest, (string) $manifest['import_digest'])) {
        throw new RuntimeException('manifest_digest_invalid');
    }
    return ['manifest_digest' => $digest, 'items' => $items, 'staging_root' => $stagingReal];
}

/** @return array<string,mixed>|null */
function privateFullCategoryByName(ClassIdentity\Repository $repository, int $parentId, string $name): ?array
{
    global $prefixeTable;
    $rows = $repository->fetchAll(
        'SELECT `id`,`name`,`id_uppercat`,`uppercats`,`status`,`visible` FROM `' . $prefixeTable . 'categories` WHERE `id_uppercat`=? AND `name`=? LIMIT 2',
        [$parentId, $name],
    );
    if (count($rows) > 1) {
        throw new RuntimeException('private_full_category_ambiguous');
    }
    return $rows[0] ?? null;
}

function privateFullHeritageRoot(ClassIdentity\Repository $repository): int
{
    global $prefixeTable;
    $row = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=? LIMIT 2',
        ['class-archive-heritage'],
    );
    if ($row === null || (int) ($row['id'] ?? 0) <= 0) {
        throw new RuntimeException('heritage_root_missing');
    }
    return (int) $row['id'];
}

/** @return array{piwigo_category_id:int,class_album_id:string} */
function privateFullEnsurePiwigoAlbum(
    ClassIdentity\Repository $repository,
    AlbumService $albums,
    int $adminUserId,
    int $parentId,
    string $name,
    ?int $expectedCategoryId = null,
    ?string $expectedClassAlbumId = null,
): array {
    if (($expectedCategoryId === null) !== ($expectedClassAlbumId === null)
        || ($expectedCategoryId !== null && $expectedCategoryId <= 0)
    ) {
        throw new RuntimeException('private_full_category_expectation_invalid');
    }
    $row = privateFullCategoryByName($repository, $parentId, $name);
    if ($row === null) {
        if ($expectedCategoryId !== null) {
            throw new RuntimeException('private_full_category_mapping_missing');
        }
        $created = create_virtual_category($name, $parentId, [
            'status' => 'private',
            'visible' => true,
            'commentable' => false,
            'inherit' => true,
            'comment' => '本机私有完整照片库目录；档案日期需另行整理。',
        ]);
        if (!is_array($created) || !ctype_digit((string) ($created['id'] ?? ''))) {
            throw new RuntimeException('piwigo_category_create_failed');
        }
        $categoryId = (int) $created['id'];
    } else {
        $categoryId = (int) $row['id'];
        // A folder journal is authoritative for reuse. Do not silently adopt
        // an unrelated category which happens to share a display name under
        // the same parent: that could rewrite an owner-curated hierarchy.
        if ($expectedCategoryId === null) {
            throw new RuntimeException('private_full_category_name_collision');
        }
    }
    if ($expectedCategoryId !== null && $categoryId !== $expectedCategoryId) {
        throw new RuntimeException('private_full_category_mapping_drift');
    }
    $mapping = $albums->ensureMapping(
        $adminUserId,
        $categoryId,
        'OFFICIAL',
        'HERITAGE',
        null,
        null,
        null,
        null,
        'Private full local library folder mapping',
    );
    if (!is_string($mapping['class_album_id'] ?? null)) {
        throw new RuntimeException('album_mapping_invalid');
    }
    if ($expectedClassAlbumId !== null && !hash_equals($expectedClassAlbumId, (string) $mapping['class_album_id'])) {
        throw new RuntimeException('private_full_category_mapping_drift');
    }
    return ['piwigo_category_id' => $categoryId, 'class_album_id' => (string) $mapping['class_album_id']];
}

/** @return array<string,mixed> */
function privateFullEnsureFolderHierarchy(
    PrivateFullLibraryService $library,
    ClassIdentity\Repository $repository,
    AlbumService $albums,
    int $adminUserId,
    array $collection,
    array $item,
): array {
    $sourceCode = (string) $collection['source_code'];
    $sourceCollectionId = (string) $collection['source_collection_id'];
    $heritageRoot = privateFullHeritageRoot($repository);
    $rootDigest = privateFullHashText($sourceCode . "\0");
    $root = $library->findFolder($sourceCollectionId, $rootDigest);
    if ($root === null) {
        $album = privateFullEnsurePiwigoAlbum($repository, $albums, $adminUserId, $heritageRoot, (string) $collection['display_name']);
        $root = $library->ensureFolder(
            $adminUserId,
            $sourceCollectionId,
            $rootDigest,
            null,
            $album['piwigo_category_id'],
            $album['class_album_id'],
            (string) $collection['display_name'],
            0,
            'Private full local library root folder mapping',
        );
    } else {
        privateFullEnsurePiwigoAlbum(
            $repository,
            $albums,
            $adminUserId,
            $heritageRoot,
            (string) $collection['display_name'],
            (int) $root['piwigo_category_id'],
            (string) $root['class_album_id'],
        );
    }
    $current = $root;
    $parts = [];
    foreach ($item['folder_segments'] as $segment) {
        $parts[] = $segment;
        $relative = implode('/', $parts);
        $pathDigest = privateFullHashText($sourceCode . "\0" . $relative);
        $existing = $library->findFolder($sourceCollectionId, $pathDigest);
        if ($existing === null) {
            $album = privateFullEnsurePiwigoAlbum(
                $repository,
                $albums,
                $adminUserId,
                (int) $current['piwigo_category_id'],
                $segment,
            );
            $existing = $library->ensureFolder(
                $adminUserId,
                $sourceCollectionId,
                $pathDigest,
                (string) $current['folder_id'],
                $album['piwigo_category_id'],
                $album['class_album_id'],
                $segment,
                count($parts),
                'Private full local library nested folder mapping',
            );
        } else {
            privateFullEnsurePiwigoAlbum(
                $repository,
                $albums,
                $adminUserId,
                (int) $current['piwigo_category_id'],
                $segment,
                (int) $existing['piwigo_category_id'],
                (string) $existing['class_album_id'],
            );
        }
        $current = $existing;
    }
    if (!hash_equals((string) $current['relative_path_digest'], (string) $item['folder_path_digest'])) {
        throw new RuntimeException('folder_mapping_digest_drift');
    }
    return $current;
}

/** @return array{checksum:string,reference:string} */
function privateFullPiwigoOriginal(int $imageId): array
{
    global $prefixeTable;
    $rows = query2array('SELECT `path` FROM `' . $prefixeTable . 'images` WHERE `id`=' . $imageId . ' LIMIT 1');
    if (count($rows) !== 1 || !is_string($rows[0]['path'] ?? null)) {
        throw new RuntimeException('piwigo_image_missing');
    }
    $reference = ClassArchivePhoto::normalizeMediaReference((string) $rows[0]['path']);
    $path = PHPWG_ROOT_PATH . $reference;
    $real = realpath($path);
    $root = realpath(PHPWG_ROOT_PATH . (str_starts_with($reference, 'upload/') ? 'upload' : 'galleries'));
    $normalizedReal = $real === false ? '' : str_replace('\\', '/', $real);
    $normalizedRoot = $root === false ? '' : rtrim(str_replace('\\', '/', $root), '/') . '/';
    if ($real === false || $root === false || !is_file($real) || is_link($path) || !str_starts_with($normalizedReal, $normalizedRoot)) {
        throw new RuntimeException('piwigo_original_untrusted');
    }
    if (!chmod($real, 0660)) {
        throw new RuntimeException('piwigo_original_mode_failed');
    }
    clearstatcache(true, $real);
    if (((int) fileperms($real) & 0777) !== 0660) {
        throw new RuntimeException('piwigo_original_mode_invalid');
    }
    $checksum = hash_file('sha256', $real);
    if (!is_string($checksum)) {
        throw new RuntimeException('piwigo_original_hash_failed');
    }
    return ['checksum' => strtolower($checksum), 'reference' => $reference];
}

function privateFullEnsureAssociation(ClassIdentity\Repository $repository, int $imageId, int $categoryId): void
{
    global $prefixeTable;
    if ($imageId <= 0 || $categoryId <= 0) {
        throw new RuntimeException('piwigo_association_shape_invalid');
    }
    $repository->execute(
        'INSERT IGNORE INTO `' . $prefixeTable . 'image_category` (`image_id`,`category_id`) VALUES (?, ?)',
        [$imageId, $categoryId],
    );
}

/** @return array{mime:string,source:string} */
function privateFullVerifyStaging(array $item, string $stagingRoot): array
{
    $source = $stagingRoot . '/' . $item['staging_name'];
    $real = realpath($source);
    if ($real === false || dirname($real) !== $stagingRoot || !is_file($real) || is_link($source)
        || (int) filesize($real) !== (int) $item['file_size']
        || !hash_equals((string) $item['source_sha256'], (string) hash_file('sha256', $real))
    ) {
        throw new RuntimeException('staging_integrity_invalid');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($real);
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!is_string($mime) || !isset($mimes[$item['extension']]) || !hash_equals($mimes[$item['extension']], $mime)) {
        throw new RuntimeException('staging_mime_invalid');
    }
    $dimensions = @getimagesize($real);
    if (!is_array($dimensions) || (int) ($dimensions[0] ?? 0) <= 0 || (int) ($dimensions[1] ?? 0) <= 0) {
        throw new RuntimeException('staging_image_invalid');
    }
    return ['mime' => $mime, 'source' => $real];
}

/** @return int|null */
function privateFullExistingOpaqueImage(string $opaqueFile): ?int
{
    $escaped = pwg_db_real_escape_string($opaqueFile);
    $rows = query2array('SELECT `id` FROM ' . IMAGES_TABLE . " WHERE `file`='{$escaped}' LIMIT 2");
    if (count($rows) > 1) {
        throw new RuntimeException('opaque_image_ambiguous');
    }
    return $rows === [] ? null : (int) $rows[0]['id'];
}

/**
 * Seed the archive row before creating the canonical photo mapping.
 *
 * Piwigo's native image write and ClassIdentity's InnoDB mapping are a
 * resumable saga, not one transaction.  A new image is therefore deliberately
 * invisible until this row and the active mapping both exist.  Creating the
 * HERITAGE/UNKNOWN seed first is safe: MediaGuard has no active canonical
 * mapping to authorize until the next step succeeds.  This mirrors the
 * submission approval flow, without pretending an imported source has a
 * Family submission.
 */
function privateFullEnsureArchiveSeed(ClassIdentity\Repository $repository, int $imageId): void
{
    if ($imageId <= 0) {
        throw new RuntimeException('archive_seed_image_invalid');
    }
    $repository->transaction(function (ClassIdentity\Repository $repository) use ($imageId): void {
        $table = $repository->table('archive_image');
        $row = $repository->fetchOne(
            'SELECT `era`,`source_submission_id` FROM `' . $table . '` WHERE `piwigo_image_id`=? LIMIT 2 FOR UPDATE',
            [$imageId],
        );
        if ($row === null) {
            $repository->execute(
                'INSERT INTO `' . $table . '` '
                . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) '
                . "VALUES (?, 'HERITAGE', NULL, 'UNKNOWN', 'UNKNOWN', NULL, 1, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                [$imageId],
            );
            return;
        }
        if ((string) ($row['era'] ?? '') !== 'HERITAGE' || $row['source_submission_id'] !== null) {
            throw new RuntimeException('canonical_checksum_cross_era');
        }
    });
}

function privateFullProvenanceCode(string $sourceCode, string $itemDigest): string
{
    $letter = $sourceCode === 'PRIVATE_SOURCE_A' ? 'A' : 'B';
    return 'FULL.' . $letter . '.' . substr($itemDigest, 0, 56);
}

function privateFullSafeItemError(Throwable $error): string
{
    return match ($error->getMessage()) {
        'staging_integrity_invalid' => 'STAGING_INTEGRITY',
        'staging_mime_invalid' => 'STAGING_MIME',
        'staging_image_invalid' => 'STAGING_IMAGE',
        'piwigo_import_failed' => 'PIWIGO_IMPORT',
        'piwigo_original_hash_mismatch' => 'ORIGINAL_HASH',
        default => 'ITEM_IMPORT',
    };
}

function privateFullIsStructural(Throwable $error): bool
{
    $message = $error->getMessage();
    return str_starts_with($message, 'class_identity_')
        || str_starts_with($message, 'class_archive_')
        // Folder/category mapping is the deterministic, data-model half of
        // the import. Continuing after any mapping drift could silently put a
        // later source item in the wrong archive hierarchy.
        || str_starts_with($message, 'private_full_category_')
        || str_starts_with($message, 'folder_mapping_')
        || in_array($message, [
            'canonical_checksum_cross_era',
            'opaque_image_ambiguous',
            'private_full_checkpoint_canonical_conflict', 'piwigo_original_untrusted', 'piwigo_original_mode_failed',
            'piwigo_original_mode_invalid', 'piwigo_original_hash_failed', 'piwigo_original_hash_mismatch',
            'piwigo_image_missing', 'canonical_mapping_invalid', 'album_mapping_invalid',
            'archive_canonical_mapping_required', 'archive_seed_image_invalid',
        ], true);
}

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    privateFullFail('runtime_forbidden');
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1' || getenv('CLASS_ARCHIVE_PRIVATE_REAL_QA') === '1') {
    privateFullFail('private_full_runtime_required');
}

try {
    $manifest = privateFullLoadManifest();
} catch (Throwable $error) {
    privateFullFail($error->getMessage());
}

chdir('/var/www/html/piwigo') || privateFullFail('piwigo_root_unavailable');
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

if (!class_exists(PrivateFullLibraryService::class) || !class_exists(ClassArchivePhotoMappingService::class)
    || !class_exists(CanonicalPhotoService::class) || !class_exists(AiIndexService::class)
) {
    privateFullFail('class_archive_runtime_unavailable');
}

$repository = ClassIdentity\Repository::fromPiwigo();
$principals = $repository->fetchAll(
    'SELECT `piwigo_user_id` FROM `' . $repository->table('principal') . '` '
        . "WHERE `principal_type`='SYSTEM_ACCOUNT' AND `system_role`='SYSTEM_ADMIN' AND `state`='ACTIVE' LIMIT 2",
);
if (count($principals) !== 1 || (int) ($principals[0]['piwigo_user_id'] ?? 0) <= 0) {
    privateFullFail('system_admin_unavailable');
}
$adminUserId = (int) $principals[0]['piwigo_user_id'];
$user = build_user($adminUserId, false);
if (($user['status'] ?? null) !== 'webmaster') {
    privateFullFail('system_admin_core_status_invalid');
}

$library = PrivateFullLibraryService::fromPiwigo();
$albums = AlbumService::fromPiwigo();
$mapping = ClassArchivePhotoMappingService::fromPiwigo();
$canonical = CanonicalPhotoService::fromPiwigo();
$aiIndex = AiIndexService::fromPiwigo();
$lease = null;
$imported = 0;
$deduplicated = 0;
$skipped = 0;
$failed = 0;
try {
    $lease = $library->acquireLease(0);
    $run = $library->beginImport(
        $adminUserId,
        (string) $manifest['manifest_digest'],
        PRIVATE_FULL_VERSION,
        count($manifest['items']),
        'Private full local library import begin',
    );
    $importId = (string) $run['import_id'];
    // A completed manifest is a durable no-op on a repeated invocation. Do
    // not reopen its journal or touch a single Piwigo association merely to
    // prove idempotence; beginImport already checked the exact digest/version
    // and item count under the import-row lock.
    $completedNoop = (string) $run['state'] === 'COMPLETED';
    if ($completedNoop) {
        $skipped = count($manifest['items']);
    }
    foreach ($completedNoop ? [] : $manifest['items'] as $item) {
        $collection = $library->ensureCollection(
            $adminUserId,
            (string) $item['source_collection_code'],
            (string) $item['source_collection_label'],
            'Private full local library source collection mapping',
        );
        $folder = privateFullEnsureFolderHierarchy($library, $repository, $albums, $adminUserId, $collection, $item);
        $claim = $library->claimItem(
            $adminUserId,
            $importId,
            (string) $item['item_digest'],
            (string) $collection['source_collection_id'],
            (string) $folder['folder_id'],
            (string) $item['source_reference_digest'],
            (string) $item['original_filename_digest'],
            (string) $item['source_sha256'],
            (string) $item['staging_name_digest'],
            (int) $item['file_size'],
        );
        if ($claim['action'] === 'SKIP') {
            ++$skipped;
            continue;
        }
        try {
            $staging = privateFullVerifyStaging($item, (string) $manifest['staging_root']);
            $checkpointedImageId = (int) ($claim['piwigo_image_id'] ?? 0);
            $existing = $library->findActiveCanonicalByChecksum((string) $item['source_sha256']);
            if ($existing !== null) {
                if ($checkpointedImageId > 0 && $checkpointedImageId !== (int) $existing['piwigo_image_id']) {
                    // Two physical originals with one checksum were found
                    // before the native/InnoDB saga could complete. Do not
                    // silently hide an orphan or choose one by convenience.
                    throw new RuntimeException('private_full_checkpoint_canonical_conflict');
                }
                privateFullEnsureAssociation($repository, (int) $existing['piwigo_image_id'], (int) $folder['piwigo_category_id']);
                privateFullEnsureArchiveSeed($repository, (int) $existing['piwigo_image_id']);
                $canonical->recordSource(
                    $adminUserId,
                    (string) $existing['class_photo_id'],
                    'PRIVATE_FULL',
                    privateFullProvenanceCode((string) $item['source_collection_code'], (string) $item['item_digest']),
                    (string) $item['source_reference_digest'],
                    (string) $item['original_filename_digest'],
                    (string) $item['source_sha256'],
                    (int) $item['file_size'],
                    null,
                    'Private full local library source provenance',
                );
                $library->completeItem(
                    $adminUserId, $importId, (string) $item['item_digest'], 'DEDUPLICATED',
                    (string) $existing['class_photo_id'], (int) $existing['piwigo_image_id'],
                    'Private full local library exact canonical reuse',
                );
                ++$deduplicated;
                continue;
            }

            // Piwigo 16.4 persists the supplied opaque filename in
            // `images.file` even though its physical upload path is random.
            // It is a deterministic, non-sensitive recovery key for a crash
            // occurring after the native insert but before our InnoDB
            // checkpoint. It is never an authorization credential.
            $opaqueFile = 'frl-' . substr((string) $item['item_digest'], 0, 24) . '-'
                . substr((string) $item['source_sha256'], 0, 12) . '.' . (string) $item['extension'];
            $imageId = $checkpointedImageId > 0 ? $checkpointedImageId : privateFullExistingOpaqueImage($opaqueFile);
            if ($imageId === null) {
                $temporary = tempnam(sys_get_temp_dir(), 'class-archive-private-full-');
                if (!is_string($temporary) || $temporary === '') {
                    throw new RuntimeException('temporary_unavailable');
                }
                try {
                    if (!copy((string) $staging['source'], $temporary) || !chmod($temporary, 0600)
                        || !hash_equals((string) $item['source_sha256'], (string) hash_file('sha256', $temporary))
                    ) {
                        throw new RuntimeException('temporary_copy_failed');
                    }
                    $imageId = (int) add_uploaded_file($temporary, $opaqueFile, [(int) $folder['piwigo_category_id']], 0);
                    if ($imageId <= 0) {
                        throw new RuntimeException('piwigo_import_failed');
                    }
                } finally {
                    if (is_file($temporary) && !is_link($temporary)) {
                        @unlink($temporary);
                    }
                }
            }
            $original = privateFullPiwigoOriginal($imageId);
            if (!hash_equals((string) $item['source_sha256'], (string) $original['checksum'])) {
                throw new RuntimeException('piwigo_original_hash_mismatch');
            }
            // This checksum-verified checkpoint is written before metadata,
            // archive, mapping or provenance work. A restart can now resume
            // from the native original without relying on Piwigo's internal
            // randomised filename or duplicate-detection configuration.
            $library->checkpointPiwigoImage(
                $adminUserId,
                $importId,
                (string) $item['item_digest'],
                $imageId,
                (string) $item['source_sha256'],
                'Private full local library native image checkpoint',
            );
            privateFullEnsureAssociation($repository, $imageId, (int) $folder['piwigo_category_id']);
            single_update(IMAGES_TABLE, [
                'name' => '班级历史照片',
                'author' => 'Private local library',
                'date_creation' => null,
                'comment' => '本机私有档案副本；档案日期由后续整理确定。',
            ], ['id' => $imageId]);
            // Seed archive truth before the canonical mapping so no partially
            // imported original can become visible.  Mapping is the final
            // publication precondition for the Gateway and MediaGuard.
            privateFullEnsureArchiveSeed($repository, $imageId);
            $mapped = $mapping->ensurePiwigoMapping($imageId, (string) $original['checksum'], (string) $original['reference']);
            if (!is_string($mapped['class_photo_id'] ?? null)) {
                throw new RuntimeException('canonical_mapping_invalid');
            }
            $canonical->recordSource(
                $adminUserId,
                (string) $mapped['class_photo_id'],
                'PRIVATE_FULL',
                privateFullProvenanceCode((string) $item['source_collection_code'], (string) $item['item_digest']),
                (string) $item['source_reference_digest'],
                (string) $item['original_filename_digest'],
                (string) $item['source_sha256'],
                (int) $item['file_size'],
                null,
                'Private full local library source provenance',
            );
            if (class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
                // Queue only. A full library must become resumable/browsable
                // without serialising every derivative profile under the
                // warmer's global lock; bounded maintenance performs the
                // durable queue before the guarded cutover.
                \ClassArchiveDerivativeWarmupQueue::enqueueBestEffort((string) $mapped['class_photo_id'], $imageId);
            }
            $library->completeItem(
                $adminUserId, $importId, (string) $item['item_digest'], 'APPLIED',
                (string) $mapped['class_photo_id'], $imageId,
                'Private full local library original imported',
            );
            ++$imported;
        } catch (Throwable $error) {
            if (privateFullIsStructural($error)) {
                throw $error;
            }
            $library->failItem(
                $adminUserId,
                $importId,
                (string) $item['item_digest'],
                privateFullSafeItemError($error),
                'Private full local library item rejected before publication',
            );
            ++$failed;
        }
    }
    if ($completedNoop) {
        $finished = $run;
    } else {
        $finished = $library->finishImport($adminUserId, $importId, 'Private full local library import finish');
        if ($imported > 0 || $deduplicated > 0) {
            \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
            invalidate_user_cache();
        }
    }
    // This is a metadata-only, idempotent post-import hook. It intentionally
    // queues work only after the import journal is terminal; no photo import
    // can fail because a private Immich worker is absent, and no runtime read
    // will later compensate by starting model work.
    $aiQueue = $aiIndex->enqueueImportedActivePhotos();
    fwrite(STDOUT, 'PRIVATE_FULL_LIBRARY_IMPORT=PASS imported=' . $imported . ' deduplicated=' . $deduplicated
        . ' skipped=' . $skipped . ' failed=' . $failed . ' state=' . (string) $finished['state']
        . ' ai_jobs_queued=' . (int) $aiQueue['queued'] . ' ai_jobs_unchanged=' . (int) $aiQueue['unchanged']
        . " originals_mode=0660\n");
} catch (Throwable $error) {
    privateFullFail($error->getMessage());
} finally {
    if (is_string($lease)) {
        try {
            $library->releaseLease($lease);
        } catch (Throwable) {
            // The original failure is safer; a dying MariaDB connection also
            // releases GET_LOCK automatically.
        }
    }
}
