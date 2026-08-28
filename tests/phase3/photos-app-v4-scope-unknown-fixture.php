<?php

declare(strict_types=1);

/**
 * Synthetic-only, reversible UNKNOWN-era fault for the V4 scope browser gate.
 *
 * The live policy never allows an ambiguous/missing era.  This fixture proves
 * that behaviour through the real BFF with one canonical synthetic photo: it
 * temporarily removes only that image's LIVING-root memberships, publishes a
 * new read projection, and later restores the exact memberships and projection.
 * It never creates/deletes media, accounts, mappings, or source files.
 */

const V4_SCOPE_ROOT = '/var/www/html/piwigo';

function v4scopeFail(string $code): never
{
    fwrite(STDERR, "V4_SCOPE_UNKNOWN_FIXTURE=FAIL code={$code}\n");
    exit(1);
}

/** @param array<string,mixed> $value */
function v4scopeJson(array $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function v4scopeRequireRuntime(): void
{
    if (PHP_SAPI !== 'cli'
        || !function_exists('posix_geteuid')
        || posix_geteuid() === 0
        || getenv('CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE') !== '1'
        || !is_file('/workspace/tests/phase3/photos-app-v4-scope-unknown-fixture.php')
    ) {
        v4scopeFail('test_gate_required');
    }
    if (realpath(V4_SCOPE_ROOT) !== V4_SCOPE_ROOT || is_link(V4_SCOPE_ROOT)) {
        v4scopeFail('piwigo_root_untrusted');
    }
    chdir(V4_SCOPE_ROOT) || v4scopeFail('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function v4scopeRunId(string $value): string
{
    $value = strtolower($value);
    if (preg_match('/\A[a-f0-9]{16}\z/D', $value) !== 1) {
        v4scopeFail('run_invalid');
    }
    return $value;
}

function v4scopeStatePath(string $run): string
{
    return '/tmp/class-archive-v4-scope-' . v4scopeRunId($run) . '.json';
}

function v4scopeLockPath(): string
{
    return '/tmp/class-archive-v4-scope.lock';
}

function v4scopeNormalizeRank(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/\A-?[0-9]{1,10}\z/D', $value) === 1) {
        return (int) $value;
    }
    v4scopeFail('association_rank_invalid');
}

/** @return array{version:int,run:string} */
function v4scopeReadGlobalLock(string $run): array
{
    $path = v4scopeLockPath();
    if (!is_file($path) || is_link($path)) {
        v4scopeFail('global_lock_missing');
    }
    $stat = lstat($path);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600 || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? 0) < 32 || (int) ($stat['size'] ?? 0) > 1024
    ) {
        v4scopeFail('global_lock_metadata_invalid');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        v4scopeFail('global_lock_read_failed');
    }
    try {
        $document = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        v4scopeFail('global_lock_json_invalid');
    }
    if (!is_array($document) || array_keys($document) !== ['version', 'run']
        || ($document['version'] ?? null) !== 1 || !is_string($document['run'] ?? null)
        || !hash_equals(v4scopeRunId($run), $document['run'])
    ) {
        v4scopeFail('global_lock_shape_invalid');
    }
    return ['version' => 1, 'run' => $document['run']];
}

/** @param array<string,mixed> $document */
function v4scopeWriteExclusiveJson(string $path, array $document, string $code): void
{
    if (file_exists($path) || is_link($path)) {
        v4scopeFail($code);
    }
    $raw = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $previousUmask = umask(0077);
    try {
        $handle = @fopen($path, 'x');
    } finally {
        umask($previousUmask);
    }
    if (!is_resource($handle)) {
        v4scopeFail($code);
    }
    try {
        if (fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) {
            v4scopeFail($code);
        }
    } finally {
        fclose($handle);
    }
    if (!chmod($path, 0600)) {
        v4scopeFail($code);
    }
}

function v4scopeAcquireGlobalLock(string $run): void
{
    v4scopeWriteExclusiveJson(v4scopeLockPath(), ['version' => 1, 'run' => v4scopeRunId($run)], 'global_lock_held');
    v4scopeReadGlobalLock($run);
}

function v4scopeReleaseGlobalLock(string $run): void
{
    $path = v4scopeLockPath();
    v4scopeReadGlobalLock($run);
    if (!unlink($path) || file_exists($path) || is_link($path)) {
        v4scopeFail('global_lock_release_failed');
    }
}

/** @return array<string,mixed> */
function v4scopeReadState(string $path, string $run): array
{
    if (!is_file($path) || is_link($path)) {
        v4scopeFail('state_missing');
    }
    $stat = lstat($path);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0777) !== 0600 || (int) ($stat['nlink'] ?? 0) !== 1
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || (int) ($stat['size'] ?? 0) < 80 || (int) ($stat['size'] ?? 0) > 16384
    ) {
        v4scopeFail('state_metadata_invalid');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        v4scopeFail('state_read_failed');
    }
    try {
        $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        v4scopeFail('state_json_invalid');
    }
    if (!is_array($state)
        || array_keys($state) !== ['version', 'run', 'stage', 'photo_id', 'image_id', 'living_associations']
        || ($state['version'] ?? null) !== 1
        || !is_string($state['run'] ?? null) || !hash_equals(v4scopeRunId($run), $state['run'])
        || !in_array($state['stage'] ?? null, ['PREPARED', 'ACTIVE'], true)
        || !is_string($state['photo_id'] ?? null)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $state['photo_id']) !== 1
        || !is_int($state['image_id'] ?? null) || $state['image_id'] <= 0
        || !is_array($state['living_associations']) || $state['living_associations'] === []
    ) {
        v4scopeFail('state_shape_invalid');
    }
    $seen = [];
    foreach ($state['living_associations'] as $index => $association) {
        if (!is_array($association) || array_keys($association) !== ['category_id', 'rank']) {
            v4scopeFail('state_associations_invalid');
        }
        $categoryId = $association['category_id'] ?? null;
        if (!is_int($categoryId) || $categoryId <= 0 || isset($seen[$categoryId])) {
            v4scopeFail('state_categories_invalid');
        }
        $state['living_associations'][$index]['rank'] = v4scopeNormalizeRank($association['rank'] ?? null);
        $seen[$categoryId] = true;
    }
    return $state;
}

/** @param array<string,mixed> $state */
function v4scopeWriteState(string $path, array $state): void
{
    if (file_exists($path) || is_link($path)) {
        v4scopeFail('state_already_exists');
    }
    $temporary = $path . '.new';
    if (file_exists($temporary) || is_link($temporary)) {
        v4scopeFail('state_temporary_already_exists');
    }
    v4scopeWriteExclusiveJson($temporary, $state, 'state_temporary_already_exists');
    if (!rename($temporary, $path)) {
        v4scopeFail('state_create_failed');
    }
    clearstatcache(true, $path);
    v4scopeReadState($path, (string) $state['run']);
}

/** @param array<string,mixed> $state */
function v4scopeReplaceState(string $path, array $state): void
{
    $before = v4scopeReadState($path, (string) $state['run']);
    if ($before['photo_id'] !== ($state['photo_id'] ?? null)
        || $before['image_id'] !== ($state['image_id'] ?? null)
        || $before['living_associations'] !== ($state['living_associations'] ?? null)
    ) {
        v4scopeFail('state_replace_mismatch');
    }
    $temporary = $path . '.next';
    v4scopeWriteState($temporary, $state);
    if (!rename($temporary, $path)) {
        v4scopeFail('state_replace_failed');
    }
    clearstatcache(true, $path);
    v4scopeReadState($path, (string) $state['run']);
}

function v4scopeRebuild(): void
{
    if (!class_exists(\ClassIdentity\Gateway\ReadProjectionBuilder::class)) {
        v4scopeFail('projection_builder_unavailable');
    }
    $result = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
    if (!is_array($result)) {
        v4scopeFail('projection_rebuild_invalid');
    }
}

function v4scopeAssertSyntheticBaseline(): void
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $photoTable = $repository->table('photo');
    $images = query2array('SELECT COUNT(*) AS `count` FROM `' . $prefixeTable . 'images`');
    $multi = query2array(
        'SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $prefixeTable . 'image_category` '
        . 'GROUP BY `image_id` HAVING COUNT(*) > 1) AS `multi_album_images`',
    );
    $active = $repository->fetchOne(
        'SELECT COUNT(*) AS `count`,COUNT(DISTINCT `media_reference`) AS `physical_originals` FROM `'
        . $photoTable . '` WHERE `state`=?',
        [\ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    $actual = [
        'images' => (int) ($images[0]['count'] ?? -1),
        'active_canonical' => (int) ($active['count'] ?? -1),
        'physical_originals' => (int) ($active['physical_originals'] ?? -1),
        'multi_album_images' => (int) ($multi[0]['count'] ?? -1),
    ];
    if ($actual !== ['images' => 72, 'active_canonical' => 72, 'physical_originals' => 72, 'multi_album_images' => 8]) {
        v4scopeFail('synthetic_baseline_drift');
    }
}

/** @return list<string> */
function v4scopeUnknownBucketPhotoIds(): array
{
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $photoTable = $repository->table('photo');
    $archiveTable = $repository->table('archive_image');
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id` FROM `' . $photoTable . '` p '
        . 'LEFT JOIN `' . $archiveTable . '` ai ON ai.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'WHERE p.`state`=? AND NOT ('
        . '(COALESCE(ai.`date_source`,\'UNKNOWN\') IN (\'ARCHIVE_CONFIRMED\',\'EXIF_TRUSTED\') '
        . 'AND ai.`archive_date` IS NOT NULL AND COALESCE(ai.`date_precision`,\'UNKNOWN\') IN (\'EXACT\',\'DAY\',\'MONTH\',\'YEAR\')) '
        . 'OR (COALESCE(ai.`date_source`,\'UNKNOWN\')=\'EVENT_INFERENCE\' '
        . 'AND ai.`event_label` IS NOT NULL AND ai.`event_label` <> \'\')'
        . ') ORDER BY p.`class_photo_id` ASC',
        [\ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    $result = [];
    foreach ($rows as $row) {
        $binary = $row['class_photo_id'] ?? null;
        if (!is_string($binary) || strlen($binary) !== 16) {
            v4scopeFail('unknown_bucket_photo_invalid');
        }
        $id = \ClassIdentity\DomainSupport::binaryToId($binary);
        if (isset($result[$id])) {
            v4scopeFail('unknown_bucket_photo_duplicate');
        }
        $result[$id] = true;
    }
    if ($result === []) {
        v4scopeFail('unknown_bucket_empty');
    }
    return array_keys($result);
}

/** @return array{photo_id:string,image_id:int,living_associations:list<array{category_id:int,rank:?int}>,unknown_archive_photo_ids:list<string>} */
function v4scopeSelectLivingPhoto(string $excludedPhotoId): array
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $living = query2array(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=\'class-archive-living\' LIMIT 2',
    );
    $heritage = query2array(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=\'class-archive-heritage\' LIMIT 2',
    );
    if (count($living) !== 1 || count($heritage) !== 1) {
        v4scopeFail('era_roots_unavailable');
    }
    $livingRoot = (int) ($living[0]['id'] ?? 0);
    $heritageRoot = (int) ($heritage[0]['id'] ?? 0);
    if ($livingRoot <= 0 || $heritageRoot <= 0 || $livingRoot === $heritageRoot) {
        v4scopeFail('era_roots_invalid');
    }
    $rows = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id`, '
        . 'MAX(CASE WHEN (ic.`category_id`=? OR FIND_IN_SET(?, c.`uppercats`)>0) THEN 1 ELSE 0 END) AS `is_heritage`, '
        . 'MAX(CASE WHEN (ic.`category_id`=? OR FIND_IN_SET(?, c.`uppercats`)>0) THEN 1 ELSE 0 END) AS `is_living` '
        . 'FROM `' . $repository->table('photo') . '` p '
        . 'JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id`=p.`piwigo_image_id` '
        . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
        . 'WHERE p.`state`=? GROUP BY p.`class_photo_id`,p.`piwigo_image_id` '
        . 'HAVING `is_heritage`=0 AND `is_living`=1 ORDER BY p.`piwigo_image_id` ASC LIMIT 8',
        [$heritageRoot, $heritageRoot, $livingRoot, $livingRoot, \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE],
    );
    if (count($rows) < 2) {
        v4scopeFail('living_candidate_missing');
    }
    $excludedPhotoId = strtolower($excludedPhotoId);
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $excludedPhotoId) !== 1) {
        v4scopeFail('excluded_photo_invalid');
    }
    $unknownBucketIds = v4scopeUnknownBucketPhotoIds();
    $unknownBucket = array_fill_keys($unknownBucketIds, true);
    $row = null;
    $photoId = null;
    foreach ($rows as $candidate) {
        $binaryId = $candidate['class_photo_id'] ?? null;
        if (!is_string($binaryId) || strlen($binaryId) !== 16) {
            v4scopeFail('living_candidate_invalid');
        }
        $candidateId = \ClassIdentity\DomainSupport::binaryToId($binaryId);
        if (!hash_equals($excludedPhotoId, $candidateId) && isset($unknownBucket[$candidateId])) {
            $row = $candidate;
            $photoId = $candidateId;
            break;
        }
    }
    if (!is_array($row) || !is_string($photoId)) {
        v4scopeFail('living_candidate_exhausted');
    }
    $imageId = (int) ($row['piwigo_image_id'] ?? 0);
    if ($imageId <= 0) {
        v4scopeFail('living_candidate_invalid');
    }
    $associations = $repository->fetchAll(
        'SELECT ic.`category_id`,ic.`rank` FROM `' . $prefixeTable . 'image_category` ic '
        . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
        . 'WHERE ic.`image_id`=? AND (ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0) '
        . 'ORDER BY ic.`category_id` ASC',
        [$imageId, $livingRoot, $livingRoot],
    );
    $categoryIds = [];
    foreach ($associations as $association) {
        $categoryId = (int) ($association['category_id'] ?? 0);
        if ($categoryId <= 0 || isset($categoryIds[$categoryId])) {
            v4scopeFail('living_association_invalid');
        }
        $categoryIds[$categoryId] = [
            'category_id' => $categoryId,
            'rank' => v4scopeNormalizeRank($association['rank'] ?? null),
        ];
    }
    if ($categoryIds === []) {
        v4scopeFail('living_association_missing');
    }
    return [
        'photo_id' => $photoId,
        'image_id' => $imageId,
        'living_associations' => array_values($categoryIds),
        'unknown_archive_photo_ids' => $unknownBucketIds,
    ];
}

/** @return list<array{category_id:int,rank:?int}> */
function v4scopeLivingAssociationsForImage(int $imageId): array
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $living = query2array(
        'SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink`=\'class-archive-living\' LIMIT 2',
    );
    if (count($living) !== 1) {
        v4scopeFail('living_root_unavailable');
    }
    $livingRoot = (int) ($living[0]['id'] ?? 0);
    if ($livingRoot <= 0) {
        v4scopeFail('living_root_invalid');
    }
    $rows = $repository->fetchAll(
        'SELECT ic.`category_id`,ic.`rank` FROM `' . $prefixeTable . 'image_category` ic '
        . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
        . 'WHERE ic.`image_id`=? AND (ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0) '
        . 'ORDER BY ic.`category_id` ASC',
        [$imageId, $livingRoot, $livingRoot],
    );
    $associations = [];
    foreach ($rows as $association) {
        $categoryId = (int) ($association['category_id'] ?? 0);
        if ($categoryId <= 0 || isset($associations[$categoryId])) {
            v4scopeFail('living_association_invalid');
        }
        $associations[$categoryId] = [
            'category_id' => $categoryId,
            'rank' => v4scopeNormalizeRank($association['rank'] ?? null),
        ];
    }
    return array_values($associations);
}

/** @param array<string,mixed> $state */
function v4scopeRemoveLivingAssociations(array $state): void
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    foreach ($state['living_associations'] as $association) {
        $categoryId = (int) ($association['category_id'] ?? 0);
        $changed = $repository->execute(
            'DELETE FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=?',
            [(int) $state['image_id'], (int) $categoryId],
        );
        if ($changed !== 1) {
            v4scopeFail('living_association_remove_failed');
        }
    }
    invalidate_user_cache();
}

/** @param array<string,mixed> $state */
function v4scopeRestoreLivingAssociations(array $state): void
{
    global $prefixeTable;
    $repository = \ClassIdentity\Repository::fromPiwigo();
    foreach ($state['living_associations'] as $association) {
        $categoryId = (int) ($association['category_id'] ?? 0);
        $rank = v4scopeNormalizeRank($association['rank'] ?? null);
        $existing = $repository->fetchAll(
            'SELECT `category_id`,`rank` FROM `' . $prefixeTable . 'image_category` WHERE `image_id`=? AND `category_id`=? LIMIT 2',
            [(int) $state['image_id'], (int) $categoryId],
        );
        if (count($existing) > 1) {
            v4scopeFail('living_association_restore_ambiguous');
        }
        if ($existing === []) {
            $changed = $repository->execute(
                'INSERT INTO `' . $prefixeTable . 'image_category` (`image_id`,`category_id`,`rank`) VALUES (?, ?, ?)',
                [(int) $state['image_id'], $categoryId, $rank],
            );
            if ($changed !== 1) {
                v4scopeFail('living_association_restore_failed');
            }
        } elseif (v4scopeNormalizeRank($existing[0]['rank'] ?? null) !== $rank) {
            v4scopeFail('living_association_restore_rank_mismatch');
        }
    }
    invalidate_user_cache();
}

function v4scopeAssertExcluded(string $photoId): void
{
    $candidate = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo()->photo($photoId);
    if ($candidate !== null) {
        v4scopeFail('unknown_photo_still_projected');
    }
}

/** @param array<string,mixed> $state */
function v4scopeAssertRestored(array $state): void
{
    $actual = v4scopeLivingAssociationsForImage((int) $state['image_id']);
    $expected = $state['living_associations'];
    if ($actual !== $expected) {
        v4scopeFail('living_association_restore_verify_failed');
    }
    $candidate = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo()->photo((string) $state['photo_id']);
    if ($candidate === null) {
        v4scopeFail('living_projection_restore_verify_failed');
    }
}

function v4scopePrepare(string $run, string $excludedPhotoId): never
{
    $path = v4scopeStatePath($run);
    v4scopeAssertSyntheticBaseline();
    v4scopeAcquireGlobalLock($run);
    try {
        $candidate = v4scopeSelectLivingPhoto($excludedPhotoId);
        $state = [
            'version' => 1,
            'run' => v4scopeRunId($run),
            'stage' => 'PREPARED',
            'photo_id' => $candidate['photo_id'],
            'image_id' => $candidate['image_id'],
            'living_associations' => $candidate['living_associations'],
        ];
        v4scopeWriteState($path, $state);
        v4scopeRemoveLivingAssociations($state);
        v4scopeRebuild();
        v4scopeAssertExcluded((string) $state['photo_id']);
        $state['stage'] = 'ACTIVE';
        v4scopeReplaceState($path, $state);
    } catch (Throwable $error) {
        // State and the exclusive lock intentionally remain in place. The
        // wrapper always calls cleanup, which is the only repair path allowed
        // to restore the exact saved associations and rebuild the projection.
        v4scopeFail('prepare_failed');
    }
    v4scopeJson([
        'prepared' => true,
        'unknown_photo_id' => $state['photo_id'],
        'unknown_archive_photo_ids' => $candidate['unknown_archive_photo_ids'],
    ]);
}

function v4scopeCleanup(string $run): never
{
    $path = v4scopeStatePath($run);
    $lockPath = v4scopeLockPath();
    $hasLock = file_exists($lockPath) || is_link($lockPath);
    $hasState = file_exists($path) || is_link($path);
    if (!$hasLock && !$hasState) {
        v4scopeJson(['cleaned' => false, 'absent' => true, 'restored' => true]);
    }
    if (!$hasLock) {
        v4scopeFail('state_without_global_lock');
    }
    v4scopeReadGlobalLock($run);
    if (!$hasState) {
        v4scopeReleaseGlobalLock($run);
        v4scopeJson(['cleaned' => false, 'absent' => true, 'restored' => true]);
    }
    $state = v4scopeReadState($path, $run);
    v4scopeRestoreLivingAssociations($state);
    v4scopeRebuild();
    v4scopeAssertRestored($state);
    v4scopeAssertSyntheticBaseline();
    if (!unlink($path) || file_exists($path) || is_link($path)) {
        v4scopeFail('state_cleanup_failed');
    }
    v4scopeReleaseGlobalLock($run);
    v4scopeJson(['cleaned' => true, 'absent' => false, 'restored' => true]);
}

function v4scopeAcquireMutationLock(string $run): never
{
    v4scopeAssertSyntheticBaseline();
    v4scopeAcquireGlobalLock($run);
    v4scopeJson(['locked' => true]);
}

function v4scopeReleaseMutationLock(string $run): never
{
    v4scopeReleaseGlobalLock($run);
    v4scopeJson(['unlocked' => true]);
}

try {
    v4scopeRequireRuntime();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    $arguments = array_values(array_slice($_SERVER['argv'] ?? [], 1));
    $command = $arguments[0];
    if ($command === 'prepare' && count($arguments) === 3) {
        v4scopePrepare(v4scopeRunId((string) $arguments[1]), (string) $arguments[2]);
    }
    if ($command === 'cleanup' && count($arguments) === 2) {
        v4scopeCleanup(v4scopeRunId((string) $arguments[1]));
    }
    if ($command === 'lock' && count($arguments) === 2) {
        v4scopeAcquireMutationLock(v4scopeRunId((string) $arguments[1]));
    }
    if ($command === 'unlock' && count($arguments) === 2) {
        v4scopeReleaseMutationLock(v4scopeRunId((string) $arguments[1]));
    }
    v4scopeFail('command_invalid');
} catch (Throwable $error) {
    fwrite(STDERR, 'V4_SCOPE_UNKNOWN_FIXTURE=FAIL code=' . preg_replace('/[^a-z0-9_]/i', '_', $error->getMessage()) . "\n");
    exit(1);
}
