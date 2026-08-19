<?php

declare(strict_types=1);

/**
 * Real MariaDB integration gate for the opaque ClassArchivePhoto map.
 *
 * Evidence level: CONTRACT_TESTED. No Immich process, network call, browser
 * or media delivery path is used. Two impossible-high Piwigo image ids are
 * scoped to this run and removed exactly in finally; no Piwigo image, file or
 * mapping outside that namespace is touched.
 */

const PHOTO_MAP_TEST_ROOT = '/var/www/html/piwigo/';

function photoMapFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_MAPPING=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_MAPPING=FAIL reason=nginx_user_required\n");
    exit(1);
}
$runtimeRoot = rtrim(PHOTO_MAP_TEST_ROOT, '/');
$resolvedRuntimeRoot = realpath($runtimeRoot);
if ($resolvedRuntimeRoot === false || rtrim($resolvedRuntimeRoot, '/') !== $runtimeRoot || is_link($runtimeRoot)) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_MAPPING=FAIL reason=runtime_root_untrusted\n");
    exit(1);
}

chdir(PHOTO_MAP_TEST_ROOT) || exit(1);
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

if (!class_exists(ClassIdentity\Schema::class) || !class_exists(ClassIdentity\ClassArchivePhotoMappingService::class)) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_MAPPING=FAIL reason=installed_plugin_unavailable\n");
    exit(1);
}

$repository = ClassIdentity\Repository::fromPiwigo();
$run = strtolower(bin2hex(random_bytes(5)));
$suffix = hexdec(substr($run, 0, 5));
// 15 million lies well below unsigned MEDIUMINT's maximum but outside the
// canonical synthetic image fixture range. Check Core first and fail rather
// than overwrite any unexpected row.
$firstId = 15000000 + ($suffix % 500000);
$secondId = $firstId + 500000;
$photoTable = '`' . $repository->table('photo') . '`';
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};
$expects = static function (callable $callback, string $expected, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    try {
        $callback();
        $failures[] = $label . ':not_thrown';
    } catch (Throwable $error) {
        if ($error->getMessage() !== $expected) {
            $failures[] = $label . ':wrong_error';
        }
    }
};
$expectsDuplicate = static function (callable $callback, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    try {
        $callback();
        $failures[] = $label . ':not_thrown';
    } catch (Throwable $error) {
        // Piwigo's pinned mysqli bootstrap enables strict reporting, while
        // the Repository wrapper produces its stable code with reporting off.
        // Both forms prove the same unique-key invariant without putting a
        // driver-specific message into test output.
        $duplicate = ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062)
            || $error->getMessage() === 'class_identity_query_execute_failed_1062';
        if (!$duplicate) {
            $failures[] = $label . ':wrong_error';
        }
    }
};
$exit = 0;

try {
    ClassIdentity\Schema::fromPiwigo('0.1.0')->verifyCurrent();
    $coreRows = query2array('SELECT `id` FROM ' . IMAGES_TABLE . ' WHERE `id` IN (' . $firstId . ',' . $secondId . ')');
    if ($coreRows !== []) {
        photoMapFail('reserved_piwigo_test_ids_occupied');
    }
    $existing = $repository->fetchOne(
        'SELECT `class_photo_id` FROM ' . $photoTable . ' WHERE `piwigo_image_id` IN (?, ?) LIMIT 1',
        [$firstId, $secondId],
    );
    if ($existing !== null) {
        photoMapFail('reserved_piwigo_mapping_ids_occupied');
    }

    $service = new ClassIdentity\ClassArchivePhotoMappingService($repository);
    $checksum = hash('sha256', 'class-archive-photo-map-contract:' . $run);
    $first = $service->ensurePiwigoMapping($firstId, $checksum, 'upload/contract-' . $run . '.jpg');
    $assert(is_string($first['class_photo_id'] ?? null) && ($first['piwigo_image_id'] ?? null) === $firstId, 'create_opaque_mapping');
    $assert(array_key_exists('immich_asset_id', $first) && $first['immich_asset_id'] === null && ($first['state'] ?? null) === 'ACTIVE', 'immich_nullable_active_mapping');
    $same = $service->ensurePiwigoMapping($firstId, $checksum, 'upload/contract-' . $run . '.jpg');
    $assert(($same['class_photo_id'] ?? null) === ($first['class_photo_id'] ?? null), 'mapping_id_stable');
    $assetId = ClassIdentity\ClassArchivePhoto::generateId();
    $service->bindImmichAsset((string) $first['class_photo_id'], $assetId);
    $bound = $service->findByClassPhotoId((string) $first['class_photo_id']);
    $assert(($bound['immich_asset_id'] ?? null) === $assetId, 'future_immich_link_internal_only');
    $second = $service->ensurePiwigoMapping($secondId, $checksum, 'upload/contract-second-' . $run . '.jpg');
    $expectsDuplicate(
        static fn () => $service->bindImmichAsset((string) $second['class_photo_id'], $assetId),
        'immich_asset_unique',
    );
    $expects(
        static fn () => $service->ensurePiwigoMapping($firstId, str_repeat('b', 64), 'upload/contract-' . $run . '.jpg'),
        'class_archive_photo_mapping_drift',
        'digest_drift_marks_mapping_stale',
    );
    $stale = $service->findByPiwigoImageId($firstId);
    $assert(($stale['state'] ?? null) === 'STALE', 'mapping_drift_fail_closed_state');
} catch (Throwable $error) {
    $failures[] = 'unexpected:' . get_class($error) . ':' . $error->getMessage();
} finally {
    try {
        $repository->execute('DELETE FROM ' . $photoTable . ' WHERE `piwigo_image_id` IN (?, ?)', [$firstId, $secondId]);
        $remaining = $repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM ' . $photoTable . ' WHERE `piwigo_image_id` IN (?, ?)',
            [$firstId, $secondId],
        );
        if ((int) ($remaining['count'] ?? -1) !== 0) {
            $failures[] = 'cleanup_mapping_rows';
        }
    } catch (Throwable $error) {
        $failures[] = 'cleanup:' . get_class($error);
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'CLASS_ARCHIVE_PHOTO_MAPPING=FAIL evidence=CONTRACT_TESTED run=' . $run . ' assertions=' . $assertions . ' failures=' . implode(',', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, 'CLASS_ARCHIVE_PHOTO_MAPPING=PASS evidence=CONTRACT_TESTED run=' . $run . ' assertions=' . $assertions . "\n");
exit($exit);
