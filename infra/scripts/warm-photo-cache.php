<?php

declare(strict_types=1);

/**
 * Explicit Class Archive derivative cache warmer.
 *
 * This command deliberately delegates every resize to Piwigo Core's fixed
 * DerivativeImage profile and i.php pipeline. It is an operator/maintenance
 * command, never a request-path fallback and never an authorization boundary.
 */

const CLASS_ARCHIVE_PHOTO_CACHE_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_PHOTO_CACHE_FIRST_SCREEN_LIMIT = 48;
const CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_TIMEOUT_SECONDS = 30.0;
const CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_STDERR_LIMIT = 8192;
const CLASS_ARCHIVE_PHOTO_CACHE_EXACT_MANIFEST = '/tmp/class-archive-photo-cache-exact.json';

/** @return array{scope:string,profiles:list<string>,dry_run:bool,json:bool,queue_digest:?string,exact_manifest_digest:?string,exact_delta_digest:?string} */
function classArchivePhotoCacheArguments(array $argv): array
{
    $result = [
        'scope' => 'first-screen',
        'profiles' => ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'],
        'dry_run' => false,
        'json' => false,
        'queue_digest' => null,
        'exact_manifest_digest' => null,
        'exact_delta_digest' => null,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $result['dry_run'] = true;
            continue;
        }
        if ($argument === '--json') {
            $result['json'] = true;
            continue;
        }
        if (str_starts_with($argument, '--scope=')) {
            $scope = substr($argument, strlen('--scope='));
            if (!in_array($scope, ['first-screen', 'covers', 'queue', 'exact', 'all'], true)) {
                throw new InvalidArgumentException('photo_cache_scope_invalid');
            }
            $result['scope'] = $scope;
            continue;
        }
        if (str_starts_with($argument, '--profiles=')) {
            $profiles = explode(',', substr($argument, strlen('--profiles=')));
            if ($profiles === [] || in_array('', $profiles, true)) {
                throw new InvalidArgumentException('photo_cache_profiles_invalid');
            }
            $allowed = array_keys(classArchivePhotoCacheCanonicalProfiles());
            foreach ($profiles as $profile) {
                if (!in_array($profile, $allowed, true)) {
                    throw new InvalidArgumentException('photo_cache_profile_invalid');
                }
            }
            $profiles = array_values(array_unique($profiles));
            if ($profiles === []) {
                throw new InvalidArgumentException('photo_cache_profiles_invalid');
            }
            $result['profiles'] = $profiles;
            continue;
        }
        if (str_starts_with($argument, '--queue-digest=')) {
            $digest = substr($argument, strlen('--queue-digest='));
            if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1 || $result['queue_digest'] !== null) {
                throw new InvalidArgumentException('photo_cache_queue_digest_invalid');
            }
            $result['queue_digest'] = $digest;
            continue;
        }
        if (str_starts_with($argument, '--exact-manifest-digest=')) {
            $digest = substr($argument, strlen('--exact-manifest-digest='));
            if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1 || $result['exact_manifest_digest'] !== null) {
                throw new InvalidArgumentException('photo_cache_exact_manifest_digest_invalid');
            }
            $result['exact_manifest_digest'] = $digest;
            continue;
        }
        if (str_starts_with($argument, '--exact-delta-digest=')) {
            $digest = substr($argument, strlen('--exact-delta-digest='));
            if (preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1 || $result['exact_delta_digest'] !== null) {
                throw new InvalidArgumentException('photo_cache_exact_delta_digest_invalid');
            }
            $result['exact_delta_digest'] = $digest;
            continue;
        }
        throw new InvalidArgumentException('photo_cache_argument_invalid');
    }
    if ($result['queue_digest'] !== null && ($result['scope'] !== 'queue' || $result['dry_run'])) {
        throw new InvalidArgumentException('photo_cache_queue_digest_scope_invalid');
    }
    $hasExactContract = $result['exact_manifest_digest'] !== null || $result['exact_delta_digest'] !== null;
    if ($result['scope'] === 'exact') {
        if (!$result['dry_run'] || $result['queue_digest'] !== null
            || $result['exact_manifest_digest'] === null || $result['exact_delta_digest'] === null) {
            throw new InvalidArgumentException('photo_cache_exact_scope_contract_invalid');
        }
    } elseif ($hasExactContract) {
        throw new InvalidArgumentException('photo_cache_exact_digest_scope_invalid');
    }
    return $result;
}

/**
 * Read the bounded, operator-created verification set. This scope is
 * deliberately dry-run-only: it can prove that a partially drained delta is
 * already cached, but can neither generate a derivative nor widen to the
 * restored baseline.
 *
 * @return list<array{class_photo_id:string,piwigo_image_id:int}>
 */
function classArchivePhotoCacheExactEntries(string $manifestDigest, string $deltaDigest): array
{
    $path = CLASS_ARCHIVE_PHOTO_CACHE_EXACT_MANIFEST;
    $pathStat = @lstat($path);
    if (!is_array($pathStat) || is_link($path)) {
        throw new RuntimeException('photo_cache_exact_manifest_untrusted');
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException('photo_cache_exact_manifest_untrusted');
    }
    try {
        $stat = fstat($handle);
        if (!is_array($stat)
            || (int) ($pathStat['dev'] ?? -1) !== (int) ($stat['dev'] ?? -2)
            || (int) ($pathStat['ino'] ?? -1) !== (int) ($stat['ino'] ?? -2)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0600
            || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
            || (int) ($stat['size'] ?? 0) < 32 || (int) ($stat['size'] ?? 0) > 131072) {
            throw new RuntimeException('photo_cache_exact_manifest_untrusted');
        }
        $raw = stream_get_contents($handle);
    } finally {
        fclose($handle);
    }
    if (!is_string($raw) || strlen($raw) !== (int) $stat['size']
        || !hash_equals($manifestDigest, hash('sha256', $raw))) {
        throw new RuntimeException('photo_cache_exact_manifest_digest_changed');
    }
    try {
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('photo_cache_exact_manifest_invalid');
    }
    if (!is_array($manifest) || array_keys($manifest) !== ['version', 'delta_digest', 'entries']
        || ($manifest['version'] ?? null) !== 1
        || !is_string($manifest['delta_digest'] ?? null)
        || !hash_equals($deltaDigest, (string) $manifest['delta_digest'])
        || !is_array($manifest['entries'] ?? null)
        || count($manifest['entries']) < 1 || count($manifest['entries']) > 512) {
        throw new RuntimeException('photo_cache_exact_manifest_invalid');
    }
    $entries = [];
    $seenClass = [];
    $seenImage = [];
    $previous = '';
    foreach ($manifest['entries'] as $entry) {
        if (!is_array($entry) || array_keys($entry) !== ['class_photo_id', 'piwigo_image_id']) {
            throw new RuntimeException('photo_cache_exact_manifest_invalid');
        }
        $classPhotoId = strtolower((string) ($entry['class_photo_id'] ?? ''));
        $imageId = $entry['piwigo_image_id'] ?? null;
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $classPhotoId) !== 1
            || !is_int($imageId) || $imageId < 1 || $imageId > 2147483647
            || isset($seenClass[$classPhotoId]) || isset($seenImage[$imageId])) {
            throw new RuntimeException('photo_cache_exact_manifest_invalid');
        }
        $key = $classPhotoId . ':' . $imageId;
        if ($previous !== '' && strcmp($previous, $key) >= 0) {
            throw new RuntimeException('photo_cache_exact_manifest_not_canonical');
        }
        $previous = $key;
        $seenClass[$classPhotoId] = true;
        $seenImage[$imageId] = true;
        $entries[] = ['class_photo_id' => $classPhotoId, 'piwigo_image_id' => $imageId];
    }
    return $entries;
}

/**
 * Public CLI names are intentionally a closed set. The values name the fixed
 * Piwigo constants and are resolved only after Core has bootstrapped.
 *
 * @return array<string,string>
 */
function classArchivePhotoCacheCanonicalProfiles(): array
{
    return [
        // Piwigo's classic picture page uses its fixed square profile for the
        // active-filmstrip thumbnail. It is maintenance-only for Class Archive
        // (the product API keeps its six responsive variants) but must be
        // recoverable before the legacy/core HTTP regression is considered
        // healthy.
        'square' => 'IMG_SQUARE',
        'thumbnail' => 'IMG_THUMB',
        'xsmall' => 'IMG_XSMALL',
        'small' => 'IMG_SMALL',
        'medium' => 'IMG_MEDIUM',
        'large' => 'IMG_LARGE',
        // The product calls Piwigo's fixed XLARGE profile "preview". This is
        // the same mapping used by protected media delivery; it is not a
        // custom size or a second image pipeline.
        'preview' => 'IMG_XLARGE',
    ];
}

/** @param list<string> $requestedProfiles */
function classArchivePhotoCacheCompletesQueuedWarmup(array $requestedProfiles): bool
{
    $productProfiles = ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'];
    return array_diff($productProfiles, array_values(array_unique($requestedProfiles))) === [];
}

function classArchivePhotoCachePrepareRuntime(): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('photo_cache_cli_required');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('photo_cache_refuses_root');
    }
    if (
        realpath(CLASS_ARCHIVE_PHOTO_CACHE_ROOT) !== CLASS_ARCHIVE_PHOTO_CACHE_ROOT
        || is_link(CLASS_ARCHIVE_PHOTO_CACHE_ROOT)
        || !is_file(CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/local/config/database.inc.php')
    ) {
        throw new RuntimeException('photo_cache_piwigo_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_PHOTO_CACHE_ROOT) || throw new RuntimeException('photo_cache_chdir_failed');
    defined('PHPWG_ROOT_PATH') || define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/**
 * @return list<string>
 */
function classArchivePhotoCacheTimelineFirstScreenIds(): array
{
    if (!class_exists(\ClassIdentity\Gateway\ReadProjectionStore::class)) {
        throw new RuntimeException('photo_cache_timeline_projection_unavailable');
    }

    // The photo UI consumes this already-built FULL timeline projection.  The
    // warm set must use the exact same bucket and item order; a SQL-only
    // approximation can select a different event/term bucket and leave the
    // first viewer preview cold after a full import.
    $timeline = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo()->aggregate(
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL,
    );
    $groups = $timeline['groups'] ?? null;
    if (!is_array($groups)) {
        throw new RuntimeException('photo_cache_timeline_projection_invalid');
    }

    $result = [];
    $seen = [];
    foreach ($groups as $group) {
        $items = is_array($group) ? ($group['items'] ?? null) : null;
        if (!is_array($items)) {
            throw new RuntimeException('photo_cache_timeline_projection_invalid');
        }
        foreach ($items as $item) {
            $classPhotoId = is_array($item) && is_string($item['id'] ?? null)
                ? strtolower((string) $item['id'])
                : '';
            try {
                \ClassIdentity\ClassArchivePhoto::idToBinary($classPhotoId);
            } catch (Throwable) {
                throw new RuntimeException('photo_cache_timeline_projection_invalid');
            }
            if (isset($seen[$classPhotoId])) {
                throw new RuntimeException('photo_cache_timeline_projection_duplicate');
            }
            $seen[$classPhotoId] = true;
            $result[] = $classPhotoId;
            if (count($result) === CLASS_ARCHIVE_PHOTO_CACHE_FIRST_SCREEN_LIMIT) {
                return $result;
            }
        }
    }
    return $result;
}

/**
 * @param list<array{class_photo_id:string,piwigo_image_id:int}> $pending
 * @param-out list<array{class_photo_id:string,piwigo_image_id:int}> $quarantined
 * @return list<array<string,mixed>>
 */
function classArchivePhotoCacheRows(string $scope, array $pending = [], array &$quarantined = []): array
{
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('photo_cache_piwigo_prefix_invalid');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $photo = $repository->table('photo');
    $archive = $repository->table('archive_image');
    $p = $prefixeTable;
    $coverJoin = $scope === 'covers'
        ? 'JOIN `' . $p . 'categories` c ON c.`representative_picture_id` = i.`id` '
        : '';
    $timelineIds = $scope === 'first-screen' ? classArchivePhotoCacheTimelineFirstScreenIds() : [];
    if ($scope === 'first-screen' && $timelineIds === []) {
        return [];
    }
    $timelineFilter = '';
    $parameters = [];
    if ($timelineIds !== []) {
        $binaryIds = array_map([\ClassIdentity\ClassArchivePhoto::class, 'idToBinary'], $timelineIds);
        $timelineFilter = ' AND pm.`class_photo_id` IN (' . implode(',', array_fill(0, count($binaryIds), '?')) . ')';
        array_push($parameters, ...$binaryIds);
    }
    // `queue` is the strict write-side delta scope and `exact` is its read-only
    // completed-subset verifier. Both deliberately start with an empty base
    // relation and can receive rows only through opaque UUID/image-id pairs
    // resolved below. In particular neither may turn a supplemental import
    // into a 2k-photo derivative walk merely because the full library is
    // active or the restored derivative cache is intentionally incomplete.
    $queueOnlyFilter = in_array($scope, ['queue', 'exact'], true) ? ' AND 1=0' : '';
    $rows = $repository->fetchAll(
        'SELECT DISTINCT i.`id`,i.`path`,i.`file`,i.`width`,i.`height`,i.`rotation`,i.`representative_ext`, '
        . 'HEX(pm.`class_photo_id`) AS `class_photo_id_hex`,pm.`media_reference`,pm.`state` AS `mapping_state` '
        . 'FROM `' . $p . 'images` i '
        . 'JOIN `' . $photo . '` pm ON pm.`piwigo_image_id` = i.`id` '
        . $coverJoin
        . 'LEFT JOIN `' . $archive . '` ai ON ai.`piwigo_image_id` = i.`id` '
        . "WHERE pm.`state` = 'ACTIVE' " . $timelineFilter . $queueOnlyFilter
        . ' ORDER BY ai.`archive_date` IS NULL ASC,ai.`archive_date` DESC,i.`id` DESC'
        ,
        $parameters,
    );
    if ($timelineIds !== []) {
        $byClassPhotoId = [];
        foreach ($rows as $row) {
            $binary = hex2bin((string) ($row['class_photo_id_hex'] ?? ''));
            if (!is_string($binary) || strlen($binary) !== 16) {
                throw new RuntimeException('photo_cache_timeline_projection_invalid');
            }
            $classPhotoId = \ClassIdentity\ClassArchivePhoto::binaryToId($binary);
            if (isset($byClassPhotoId[$classPhotoId])) {
                throw new RuntimeException('photo_cache_timeline_projection_duplicate');
            }
            $byClassPhotoId[$classPhotoId] = $row;
        }
        $rows = [];
        foreach ($timelineIds as $classPhotoId) {
            if (!isset($byClassPhotoId[$classPhotoId])) {
                throw new RuntimeException('photo_cache_timeline_projection_incomplete');
            }
            $rows[] = $byClassPhotoId[$classPhotoId];
        }
    }
    $seen = [];
    foreach ($rows as $row) {
        $seen[(int) ($row['id'] ?? 0)] = true;
        if (($row['mapping_state'] ?? null) !== \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE) {
            throw new RuntimeException('photo_cache_mapping_not_active');
        }
        $path = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
        $mapped = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($row['media_reference'] ?? ''));
        if (!hash_equals($mapped, $path)) {
            throw new RuntimeException('photo_cache_mapping_reference_drift');
        }
        classArchivePhotoCacheAssertSource($path);
    }

    // Approval/import queue entries contain no paths. Resolve the exact
    // canonical UUID/image pair again and require its ACTIVE mapping before a
    // source path can enter the warmup set. Drift fails the maintenance run
    // and deliberately leaves the marker for operator/reconciliation review.
    foreach ($pending as $entry) {
        $classPhotoId = (string) ($entry['class_photo_id'] ?? '');
        $imageId = (int) ($entry['piwigo_image_id'] ?? 0);
        if ($imageId <= 0 || isset($seen[$imageId])) {
            continue;
        }
        $queued = $repository->fetchAll(
            'SELECT i.`id`,i.`path`,i.`file`,i.`width`,i.`height`,i.`rotation`,i.`representative_ext`, '
            . 'HEX(pm.`class_photo_id`) AS `class_photo_id_hex`,pm.`media_reference`,pm.`state` AS `mapping_state` '
            . 'FROM `' . $p . 'images` i '
            . 'JOIN `' . $photo . '` pm ON pm.`piwigo_image_id` = i.`id` '
            . 'WHERE pm.`class_photo_id`=UNHEX(REPLACE(?,\'-\',\'\')) AND pm.`piwigo_image_id`=? '
            . 'AND pm.`state`=\'ACTIVE\' LIMIT 2',
            [$classPhotoId, $imageId],
        );
        if (count($queued) === 0 && $scope === 'exact') {
            throw new RuntimeException('photo_cache_exact_mapping_unresolved');
        }
        if (count($queued) === 0) {
            $mappingRows = $repository->fetchAll(
                'SELECT `class_photo_id` FROM `' . $photo . '` '
                . 'WHERE `class_photo_id`=UNHEX(REPLACE(?,\'-\',\'\')) OR `piwigo_image_id`=? LIMIT 2',
                [$classPhotoId, $imageId],
            );
            $imageRows = $repository->fetchAll(
                'SELECT `id` FROM `' . $p . 'images` WHERE `id`=? LIMIT 2',
                [$imageId],
            );
            if ($mappingRows === [] && $imageRows === []) {
                \ClassArchiveDerivativeWarmupQueue::quarantineOrphan($classPhotoId, $imageId);
                $quarantined[] = $entry;
                continue;
            }
        }
        if (count($queued) !== 1) {
            throw new RuntimeException('photo_cache_queued_mapping_unresolved');
        }
        $path = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($queued[0]['path'] ?? ''));
        $mapped = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) ($queued[0]['media_reference'] ?? ''));
        if (!hash_equals($mapped, $path)) {
            throw new RuntimeException('photo_cache_mapping_reference_drift');
        }
        classArchivePhotoCacheAssertSource($path);
        $rows[] = $queued[0];
        $seen[$imageId] = true;
    }
    return $rows;
}

function classArchivePhotoCacheAssertSource(string $reference): string
{
    $candidate = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/' . $reference;
    $topLevel = explode('/', $reference, 2)[0] ?? '';
    if (!in_array($topLevel, ['upload', 'galleries'], true)) {
        throw new RuntimeException('photo_cache_source_untrusted');
    }
    return classArchivePhotoCacheAssertTrustedFile(
        $candidate,
        CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/' . $topLevel,
        true,
    );
}

/**
 * @return string resolved file
 */
function classArchivePhotoCacheAssertTrustedFile(string $path, string $trustedRoot, bool $requireMode): string
{
    $stat = @lstat($path);
    $rootStat = @lstat($trustedRoot);
    $resolved = realpath($path);
    $root = realpath($trustedRoot);
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $ownerUid = is_array($stat) ? (int) ($stat['uid'] ?? -1) : -1;
    $rootOwnerUid = is_array($rootStat) ? (int) ($rootStat['uid'] ?? -2) : -2;
    if (!is_array($stat) || !is_array($rootStat)
        || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($rootStat['mode'] ?? 0) & 0170000) !== 0040000
        || (int) ($stat['nlink'] ?? 0) !== 1
        || ($requireMode && (($stat['mode'] ?? 0) & 0777) !== 0660)
        || ($ownerUid !== $rootOwnerUid && ($effectiveUid === null || $ownerUid !== $effectiveUid))
        || is_link($path) || is_link($trustedRoot)
        || $resolved === false || $root === false
        || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('photo_cache_file_untrusted');
    }
    return $resolved;
}

/**
 * Piwigo's HTTP i.php path lazily fills missing source dimensions and rotation.
 * That is a legitimate Core write and therefore makes the Class Archive read
 * projections stale. Perform the same metadata discovery here, before user
 * reads, so a warmed derivative never causes request-time database mutation.
 *
 * @param array<string,mixed> $row
 * @return array{row:array<string,mixed>,changed:bool}
 */
function classArchivePhotoCacheNormalizeSourceMetadata(array $row, string $source, bool $dryRun): array
{
    global $prefixeTable;
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !is_string($prefixeTable ?? null) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('photo_cache_source_metadata_identity_invalid');
    }

    $updates = [];
    $width = isset($row['width']) ? (int) $row['width'] : 0;
    $height = isset($row['height']) ? (int) $row['height'] : 0;
    if ($width <= 0 || $height <= 0) {
        $size = getimagesize($source);
        if (!is_array($size) || (int) ($size[0] ?? 0) <= 0 || (int) ($size[1] ?? 0) <= 0) {
            throw new RuntimeException('photo_cache_source_dimensions_unavailable');
        }
        $row['width'] = $updates['width'] = (int) $size[0];
        $row['height'] = $updates['height'] = (int) $size[1];
    }
    if (!array_key_exists('rotation', $row) || $row['rotation'] === null) {
        if (!class_exists('pwg_image')) {
            throw new RuntimeException('photo_cache_rotation_reader_unavailable');
        }
        $rotation = \pwg_image::get_rotation_code_from_angle(\pwg_image::get_rotation_angle($source));
        if (!is_int($rotation) || $rotation < 0 || $rotation > 3) {
            throw new RuntimeException('photo_cache_source_rotation_invalid');
        }
        $row['rotation'] = $updates['rotation'] = $rotation;
    }

    if ($updates !== [] && !$dryRun) {
        $repository = \ClassIdentity\Repository::fromPiwigo();
        \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
            $repository,
            \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
            'DERIVATIVE_METADATA_NORMALIZE',
        );
        $assignments = [];
        $parameters = [];
        foreach ($updates as $field => $value) {
            $assignments[] = '`' . $field . '`=?';
            $parameters[] = $value;
        }
        $parameters[] = $id;
        $changed = $repository->execute(
            'UPDATE `' . $prefixeTable . 'images` SET ' . implode(',', $assignments) . ' WHERE `id`=?',
            $parameters,
        );
        if ($changed !== 1) {
            throw new RuntimeException('photo_cache_source_metadata_update_failed');
        }
    }
    return ['row' => $row, 'changed' => $updates !== []];
}

/** @return array{absolute:string,relative:string} */
function classArchivePhotoCacheDerivativePath(DerivativeImage $derivative): array
{
    $path = str_replace('\\', '/', $derivative->get_path());
    if (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }
    if (
        !preg_match('#\A_data/i/(?<relative>(?:upload|galleries)/(?:[^/]+/)*[^/]+)\z#D', $path, $matches)
        || str_contains($path, "\0")
        || str_contains($path, '//')
    ) {
        throw new RuntimeException('photo_cache_derivative_path_invalid');
    }
    foreach (explode('/', (string) $matches['relative']) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
            throw new RuntimeException('photo_cache_derivative_path_invalid');
        }
    }
    $absolute = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/' . $path;
    classArchivePhotoCacheAssertDerivativeAncestors(dirname($absolute));
    return ['absolute' => $absolute, 'relative' => (string) $matches['relative']];
}

/** @return array{absolute:string,relative:string} */
function classArchivePhotoCacheCanonicalDerivativePath(string $sourceReference, string $type): array
{
    $token = derivative_to_url($type);
    $dot = strrpos($sourceReference, '.');
    if (!is_string($token) || preg_match('/\A[A-Za-z0-9_]+\z/D', $token) !== 1
        || $dot === false || $dot <= 0 || str_contains(substr($sourceReference, $dot + 1), '/')) {
        throw new RuntimeException('photo_cache_derivative_path_invalid');
    }
    $relative = substr($sourceReference, 0, $dot) . '-' . $token . substr($sourceReference, $dot);
    $absolute = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i/' . $relative;
    classArchivePhotoCacheAssertDerivativeAncestors(dirname($absolute));
    return ['absolute' => $absolute, 'relative' => $relative];
}

function classArchivePhotoCacheAssertDerivativeAncestors(string $directory): void
{
    $root = realpath(CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i');
    if ($root === false || is_link(CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i')) {
        throw new RuntimeException('photo_cache_derivative_root_untrusted');
    }
    $cursor = $directory;
    while (!file_exists($cursor)) {
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            throw new RuntimeException('photo_cache_derivative_parent_unavailable');
        }
        $cursor = $parent;
    }
    if (is_link($cursor)) {
        throw new RuntimeException('photo_cache_derivative_parent_untrusted');
    }
    $resolved = realpath($cursor);
    if (
        $resolved === false
        || ($resolved !== $root && !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
    ) {
        throw new RuntimeException('photo_cache_derivative_parent_untrusted');
    }
}

function classArchivePhotoCacheIsFresh(string $derivative, string $source, string $type): bool
{
    if (!file_exists($derivative) && !is_link($derivative)) {
        return false;
    }
    classArchivePhotoCacheAssertTrustedFile(
        $derivative,
        CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i',
        false,
    );
    $sourceMtime = filemtime($source);
    $derivativeMtime = filemtime($derivative);
    $params = ImageStdParams::get_by_type($type);
    if ($sourceMtime === false || $derivativeMtime === false || $params === null) {
        throw new RuntimeException('photo_cache_freshness_unavailable');
    }
    return $derivativeMtime >= max($sourceMtime, (int) $params->last_mod_time);
}

function classArchivePhotoCacheGenerate(string $relative): void
{
    $generator = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/plugins/ClassArchivePolicy/derivative-generator.php';
    if (!is_file($generator) || is_link($generator)) {
        throw new RuntimeException('photo_cache_generator_unavailable');
    }
    $environment = classArchivePhotoCacheGeneratorEnvironment($relative);
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, $generator],
        $descriptors,
        $pipes,
        CLASS_ARCHIVE_PHOTO_CACHE_ROOT,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('photo_cache_generator_start_failed');
    }
    stream_set_blocking($pipes[2], false);
    $stderr = '';
    $exitCode = -1;
    $started = microtime(true);
    try {
        while (true) {
            $chunk = (string) stream_get_contents($pipes[2]);
            if (strlen($stderr) + strlen($chunk) > CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_STDERR_LIMIT) {
                classArchivePhotoCacheTerminateProcess($process);
                throw new RuntimeException('photo_cache_generator_stderr_limit');
            }
            $stderr .= $chunk;
            $status = proc_get_status($process);
            if (!is_array($status)) {
                classArchivePhotoCacheTerminateProcess($process);
                throw new RuntimeException('photo_cache_generator_status_failed');
            }
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ((microtime(true) - $started) >= CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_TIMEOUT_SECONDS) {
                classArchivePhotoCacheTerminateProcess($process);
                throw new RuntimeException('photo_cache_generator_timeout');
            }
            usleep(20_000);
        }
        $chunk = (string) stream_get_contents($pipes[2]);
        if (strlen($stderr) + strlen($chunk) > CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_STDERR_LIMIT) {
            throw new RuntimeException('photo_cache_generator_stderr_limit');
        }
        $stderr .= $chunk;
    } finally {
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closed;
        }
    }
    if ($exitCode !== 0 || trim($stderr) !== '') {
        throw new RuntimeException('photo_cache_generator_failed');
    }
}

/** @return array<string,string> */
function classArchivePhotoCacheGeneratorEnvironment(string $relative): array
{
    $environment = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HOME' => '/tmp',
        'TMPDIR' => '/tmp',
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'CLASS_ARCHIVE_DERIVATIVE_GENERATOR' => '1',
        'CLASS_ARCHIVE_DERIVATIVE_PATH' => $relative,
        'QUERY_STRING' => '/' . $relative,
        'REQUEST_URI' => '/i.php?/' . $relative,
        'SCRIPT_NAME' => '/i.php',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
    ];
    $timezone = getenv('TZ');
    if (is_string($timezone) && preg_match('#\A[A-Za-z0-9_+./-]{1,64}\z#D', $timezone) === 1
        && !str_contains($timezone, '..')) {
        $environment['TZ'] = $timezone;
    }
    return $environment;
}

/** @param resource $process */
function classArchivePhotoCacheTerminateProcess($process): void
{
    @proc_terminate($process, 15);
    $deadline = microtime(true) + 0.5;
    do {
        $status = @proc_get_status($process);
        if (!is_array($status) || !$status['running']) {
            return;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);
    @proc_terminate($process, 9);
}

/**
 * Piwigo intentionally returns the original when a fixed profile would be an
 * identity transform. Class Archive may not expose those original bytes as a
 * preview, so maintenance creates a metadata-stripped, same-size cache entry
 * with Piwigo's own pwg_image encoder. This never runs from an HTTP read.
 */
function classArchivePhotoCacheGenerateIdentity(string $relative, string $source): void
{
    $target = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i/' . $relative;
    classArchivePhotoCacheAssertDerivativeAncestors(dirname($target));
    $lockPath = sys_get_temp_dir() . '/class-archive-identity-derivative-' . hash('sha256', $relative) . '.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('photo_cache_identity_lock_untrusted');
    }
    $lock = @fopen($lockPath, 'c+b');
    if (!is_resource($lock) || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('photo_cache_identity_lock_failed');
    }
    $temporary = null;
    try {
        clearstatcache(true, $target);
        if (is_file($target) && !is_link($target)) {
            return;
        }
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('photo_cache_identity_target_untrusted');
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException('photo_cache_identity_parent_failed');
        }
        classArchivePhotoCacheAssertDerivativeAncestors($parent);
        require_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $filename = pathinfo($target, PATHINFO_FILENAME);
        if (preg_match('/\A[A-Za-z0-9]+\z/D', $extension) !== 1 || $filename === '') {
            throw new RuntimeException('photo_cache_identity_extension_invalid');
        }
        $temporary = $parent . '/.' . $filename . '.warm-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $sourceSize = @getimagesize($source);
        if (!is_array($sourceSize) || (int) ($sourceSize[0] ?? 0) <= 0 || (int) ($sourceSize[1] ?? 0) <= 0) {
            throw new RuntimeException('photo_cache_identity_source_invalid');
        }
        $image = new \pwg_image($source);
        try {
            $image->set_compression_quality(ImageStdParams::$quality);
            $image->strip();
            $image->write($temporary);
        } finally {
            $image->destroy();
        }
        $outputSize = is_file($temporary) && !is_link($temporary) ? @getimagesize($temporary) : false;
        if (!is_array($outputSize)
            || (int) $outputSize[0] !== (int) $sourceSize[0]
            || (int) $outputSize[1] !== (int) $sourceSize[1]
            || (int) @filesize($temporary) <= 0
            || !@chmod($temporary, 0660)
            || !@rename($temporary, $target)) {
            throw new RuntimeException('photo_cache_identity_generation_failed');
        }
        $temporary = null;
    } finally {
        if (is_string($temporary) && (is_file($temporary) || is_link($temporary))) {
            @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @param list<string> $profiles @return array<string,mixed> */
function classArchivePhotoCacheWarm(
    string $scope,
    array $profiles,
    bool $dryRun,
    ?string $expectedQueueDigest = null,
    ?string $exactManifestDigest = null,
    ?string $exactDeltaDigest = null,
): array
{
    if (!in_array($scope, ['first-screen', 'covers', 'queue', 'exact', 'all'], true)) {
        throw new InvalidArgumentException('photo_cache_scope_invalid');
    }
    if ($scope === 'exact') {
        if (!$dryRun || $expectedQueueDigest !== null
            || !is_string($exactManifestDigest) || preg_match('/\A[0-9a-f]{64}\z/D', $exactManifestDigest) !== 1
            || !is_string($exactDeltaDigest) || preg_match('/\A[0-9a-f]{64}\z/D', $exactDeltaDigest) !== 1) {
            throw new InvalidArgumentException('photo_cache_exact_scope_contract_invalid');
        }
    } elseif ($exactManifestDigest !== null || $exactDeltaDigest !== null) {
        throw new InvalidArgumentException('photo_cache_exact_digest_scope_invalid');
    }
    $canonical = classArchivePhotoCacheCanonicalProfiles();
    foreach ($profiles as $profile) {
        if (!is_string($profile) || !isset($canonical[$profile])) {
            throw new InvalidArgumentException('photo_cache_profile_invalid');
        }
    }
    $lockDirectory = CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/class-archive';
    if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0770, true) && !is_dir($lockDirectory)) {
        throw new RuntimeException('photo_cache_lock_directory_unavailable');
    }
    if (is_link($lockDirectory)) {
        throw new RuntimeException('photo_cache_lock_directory_untrusted');
    }
    $lockPath = $lockDirectory . '/photo-cache-warmup.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('photo_cache_lock_untrusted');
    }
    $lock = @fopen($lockPath, 'c+b');
    if (!is_resource($lock)) {
        throw new RuntimeException('photo_cache_lock_unavailable');
    }
    @chmod($lockPath, 0660);
    $started = microtime(true);
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('photo_cache_already_running');
        }
        $pending = class_exists('ClassArchiveDerivativeWarmupQueue', false)
            ? \ClassArchiveDerivativeWarmupQueue::pending()
            : [];
        // The durable queue represents a full, post-write recovery obligation.
        // Do not let a bounded first-screen or cover pass accidentally expand
        // into a whole-library job just because a large import has queued many
        // active images.  The explicit `all` recovery pass owns queue drain;
        // bounded passes leave every marker intact for that background work.
        $exactEntries = $scope === 'exact'
            ? classArchivePhotoCacheExactEntries((string) $exactManifestDigest, (string) $exactDeltaDigest)
            : [];
        $pendingForScope = $scope === 'exact'
            ? $exactEntries
            : (in_array($scope, ['queue', 'all'], true) ? $pending : []);
        $quarantined = [];
        $rows = classArchivePhotoCacheRows($scope, $pendingForScope, $quarantined);
        $quarantinedKeys = [];
        foreach ($quarantined as $entry) {
            $quarantinedKeys[$entry['class_photo_id'] . ':' . $entry['piwigo_image_id']] = true;
        }
        $pendingByImage = [];
        foreach ($pendingForScope as $entry) {
            if (isset($quarantinedKeys[$entry['class_photo_id'] . ':' . $entry['piwigo_image_id']])) {
                continue;
            }
            $pendingByImage[(int) $entry['piwigo_image_id']] = $entry;
        }
        // `queue_entries` is operator evidence, not a browser payload.  It
        // deliberately contains only opaque Class Archive/Piwigo ids: never a
        // source filename, managed-media reference, or host path.  Keeping the
        // list in the CLI JSON lets a retry prove that a partially drained
        // queue is still a subset of the checksum-bound supplemental delta.
        $queueEntries = $scope === 'queue' ? array_values($pendingByImage) : [];
        $queueDigest = hash(
            'sha256',
            json_encode($queueEntries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        if ($expectedQueueDigest !== null
            && ($scope !== 'queue' || $dryRun || !hash_equals($expectedQueueDigest, $queueDigest))) {
            throw new RuntimeException('photo_cache_queue_digest_changed');
        }
        // A marker is complete once every product variant exists. A recovery
        // run may additionally warm the Piwigo-only square profile without
        // preventing that exact product obligation from being consumed.
        $completesQueuedWarmup = classArchivePhotoCacheCompletesQueuedWarmup($profiles);
        $result = [
            'warmup_version' => 1,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'result' => 'PASS',
            'scope' => $scope,
            'profiles' => array_values($profiles),
            'dry_run' => $dryRun,
            'selected_images' => count($rows),
            'checked' => 0,
            'cached' => 0,
            'generated' => 0,
            'would_generate' => 0,
            'source_reuse' => 0,
            'mode_repairs' => 0,
            'would_repair_mode' => 0,
            'metadata_normalized' => 0,
            'would_normalize_metadata' => 0,
            'queued' => count($pending),
            'queue_quarantined' => count($quarantined),
            'queue_completed' => 0,
            'queue_retained' => count($pending) - count($quarantined),
            'queue_entries' => $queueEntries,
            'queue_digest' => $queueDigest,
            'exact_entries' => count($exactEntries),
            'exact_manifest_digest' => $scope === 'exact' ? $exactManifestDigest : null,
            'exact_delta_digest' => $scope === 'exact' ? $exactDeltaDigest : null,
            'projection_rebuilt' => false,
            'duration_ms' => 0,
        ];
        $changedPhotoIds = [];
        $completedQueueEntries = [];
        foreach ($rows as $row) {
            $sourceReference = \ClassIdentity\ClassArchivePhoto::normalizeMediaReference((string) $row['path']);
            $source = classArchivePhotoCacheAssertSource($sourceReference);
            $metadata = classArchivePhotoCacheNormalizeSourceMetadata($row, $source, $dryRun);
            $row = $metadata['row'];
            if ($metadata['changed']) {
                if ($dryRun) {
                    $result['would_normalize_metadata']++;
                } else {
                    $result['metadata_normalized']++;
                    $binaryId = hex2bin((string) ($row['class_photo_id_hex'] ?? ''));
                    if (!is_string($binaryId) || strlen($binaryId) !== 16) {
                        throw new RuntimeException('photo_cache_class_photo_id_invalid');
                    }
                    $changedPhotoIds[] = \ClassIdentity\ClassArchivePhoto::binaryToId($binaryId);
                }
            }
            $sourceImage = new SrcImage($row);
            foreach ($profiles as $profile) {
                $result['checked']++;
                $constantName = $canonical[$profile];
                if (!defined($constantName)) {
                    throw new RuntimeException('photo_cache_piwigo_profile_unavailable');
                }
                $type = (string) constant($constantName);
                $derivative = DerivativeImage::get_one($type, $sourceImage);
                if (!$derivative instanceof DerivativeImage) {
                    throw new RuntimeException('photo_cache_piwigo_profile_unavailable');
                }
                $identity = $derivative->same_as_source();
                if ($identity) {
                    $result['source_reuse']++;
                    $effectiveType = $type;
                    $target = classArchivePhotoCacheCanonicalDerivativePath($sourceReference, $type);
                } else {
                    $effectiveType = $derivative->get_type();
                    if (!is_string($effectiveType) || $effectiveType === '' || $effectiveType === 'Original') {
                        throw new RuntimeException('photo_cache_piwigo_profile_unavailable');
                    }
                    $target = classArchivePhotoCacheDerivativePath($derivative);
                }
                if (classArchivePhotoCacheIsFresh($target['absolute'], $source, $effectiveType)) {
                    $mode = (fileperms($target['absolute']) ?: 0) & 0777;
                    if ($mode !== 0660) {
                        if ($dryRun) {
                            $result['would_repair_mode']++;
                        } else {
                            if (!@chmod($target['absolute'], 0660) || ((fileperms($target['absolute']) ?: 0) & 0777) !== 0660) {
                                throw new RuntimeException('photo_cache_derivative_mode_failed');
                            }
                            $result['mode_repairs']++;
                        }
                    }
                    classArchivePhotoCacheAssertTrustedFile(
                        $target['absolute'],
                        CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i',
                        true,
                    );
                    $result['cached']++;
                    continue;
                }
                if ($dryRun) {
                    $result['would_generate']++;
                    continue;
                }
                if ($identity) {
                    classArchivePhotoCacheGenerateIdentity($target['relative'], $source);
                } else {
                    classArchivePhotoCacheGenerate($target['relative']);
                }
                // i.php runs in a child process, so this long-lived CLI must
                // forget the negative stat result observed before generation.
                clearstatcache(true, $target['absolute']);
                if (!classArchivePhotoCacheIsFresh($target['absolute'], $source, $effectiveType)) {
                    throw new RuntimeException('photo_cache_derivative_generation_unverified');
                }
                if (!@chmod($target['absolute'], 0660) || ((fileperms($target['absolute']) ?: 0) & 0777) !== 0660) {
                    throw new RuntimeException('photo_cache_derivative_mode_failed');
                }
                classArchivePhotoCacheAssertTrustedFile(
                    $target['absolute'],
                    CLASS_ARCHIVE_PHOTO_CACHE_ROOT . '/_data/i',
                    true,
                );
                $result['generated']++;
            }
            $imageId = (int) ($row['id'] ?? 0);
            if (!$dryRun && $completesQueuedWarmup && isset($pendingByImage[$imageId])) {
                $completedQueueEntries[] = $pendingByImage[$imageId];
                unset($pendingByImage[$imageId]);
            }
        }
        if (!$dryRun && $changedPhotoIds !== []) {
            // Native image metadata writes advance the protected Piwigo source
            // epoch. A bounded refresh is valid only while the catalog epoch is
            // unchanged, so publish one complete generation after the batch.
            // This remains maintenance/write-time work, never an HTTP read.
            \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
            $result['projection_rebuilt'] = true;
        }
        foreach ($completedQueueEntries as $entry) {
            \ClassArchiveDerivativeWarmupQueue::complete(
                (string) $entry['class_photo_id'],
                (int) $entry['piwigo_image_id'],
            );
            $result['queue_completed']++;
            $result['queue_retained']--;
        }
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $result;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** @param array{scope:string,profiles:list<string>,dry_run:bool,json:bool,queue_digest:?string,exact_manifest_digest:?string,exact_delta_digest:?string} $arguments */
function classArchivePhotoCacheMain(array $arguments): int
{
    try {
        $result = classArchivePhotoCacheWarm(
            $arguments['scope'],
            $arguments['profiles'],
            $arguments['dry_run'],
            $arguments['queue_digest'],
            $arguments['exact_manifest_digest'],
            $arguments['exact_delta_digest'],
        );
        if ($arguments['json']) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        } else {
            fwrite(
                STDOUT,
                'PHOTO_CACHE_WARMUP=PASS SCOPE=' . $result['scope']
                . ' CHECKED=' . $result['checked']
                . ' CACHED=' . $result['cached']
                . ' GENERATED=' . $result['generated']
                . ' WOULD_GENERATE=' . $result['would_generate'] . "\n",
            );
        }
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'PHOTO_CACHE_WARMUP=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $classArchivePhotoCacheArguments = classArchivePhotoCacheArguments($_SERVER['argv'] ?? []);
        classArchivePhotoCachePrepareRuntime();
        // Piwigo intentionally populates globals such as $conf, $user and
        // $mysqli. Keep this include at file scope, like the other project CLI
        // tools, so those globals remain available to Core and plugins.
        ob_start();
        require PHPWG_ROOT_PATH . 'include/common.inc.php';
        ob_end_clean();
        require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
        require_once PHPWG_ROOT_PATH . 'include/derivative.inc.php';
        exit(classArchivePhotoCacheMain($classArchivePhotoCacheArguments));
    } catch (Throwable $error) {
        fwrite(STDERR, 'PHOTO_CACHE_WARMUP=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
        exit(1);
    }
}
