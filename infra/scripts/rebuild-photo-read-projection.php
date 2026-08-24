<?php

declare(strict_types=1);

const CLASS_ARCHIVE_PROJECTION_ROOT = '/var/www/html/piwigo';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('projection_rebuild_cli_required');
    }
    $dryRun = false;
    $json = false;
    $scope = 'all';
    $kinds = ['TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'];
    $kindsSpecified = false;
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif ($argument === '--json') {
            $json = true;
        } elseif (preg_match('/\A--scope=(all|photos|aggregates)\z/D', $argument, $match) === 1) {
            $scope = $match[1];
        } elseif (preg_match('/\A--kinds=(timeline|albums|people|memories|spotlight)(?:,(timeline|albums|people|memories|spotlight))*\z/Di', $argument) === 1) {
            $parts = array_values(array_unique(array_map('strtoupper', explode(',', substr($argument, 8)))));
            $allowed = ['TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'];
            if ($parts === [] || array_diff($parts, $allowed) !== []) {
                throw new InvalidArgumentException('projection_rebuild_kinds_invalid');
            }
            $kinds = $parts;
            $kindsSpecified = true;
        } else {
            throw new InvalidArgumentException('projection_rebuild_argument_invalid');
        }
    }
    if ($scope === 'photos' && $kindsSpecified) {
        throw new InvalidArgumentException('projection_rebuild_kinds_without_aggregates');
    }
    if (realpath(CLASS_ARCHIVE_PROJECTION_ROOT) !== CLASS_ARCHIVE_PROJECTION_ROOT
        || is_link(CLASS_ARCHIVE_PROJECTION_ROOT)
        || !is_file(CLASS_ARCHIVE_PROJECTION_ROOT . '/local/config/database.inc.php')
    ) {
        throw new RuntimeException('projection_rebuild_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_PROJECTION_ROOT) || throw new RuntimeException('projection_rebuild_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    $lockPath = PHPWG_ROOT_PATH . '_data/class-archive/read-projection-rebuild.lock';
    if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0770, true) && !is_dir(dirname($lockPath))) {
        throw new RuntimeException('projection_rebuild_lock_directory_failed');
    }
    $lock = fopen($lockPath, 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('projection_rebuild_already_running');
    }
    try {
        if ($scope === 'photos') {
            $adapter = \ClassIdentity\Gateway\PiwigoGatewayAdapter::fromPiwigo();
            $store = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo();
            $photoResult = null;
            $lastEpochError = null;
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $buildToken = $store->beginPhotoCatalogBuild();
                $photos = $adapter->sourcePhotoCandidatesForRebuild();
                try {
                    $photoResult = $store->rebuildPhotos($photos, $dryRun, $buildToken);
                    $lastEpochError = null;
                    break;
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'class_archive_read_projection_source_epoch_changed') {
                        throw $error;
                    }
                    $lastEpochError = $error;
                }
            }
            if ($lastEpochError !== null || !is_array($photoResult)) {
                throw new RuntimeException('class_archive_read_projection_source_epoch_unstable', 0, $lastEpochError);
            }
            $result = [
                'photos' => $photoResult,
                'aggregates' => null,
                'projections' => $store->status(),
            ];
        } else {
            $result = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild(
                $kinds,
                rebuildPhotos: $scope === 'all',
                dryRun: $dryRun,
            );
        }
        // Preserve the v9 top-level summary for operational consumers while
        // exposing the richer v11 photo/aggregate sections.
        $result['count'] = (int) ($result['photos']['count'] ?? 0);
        $result['changed'] = ($result['photos']['changed'] ?? false) === true
            || ($result['aggregates']['changed'] ?? false) === true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if ($json) {
        fwrite(STDOUT, json_encode(['result' => 'PASS'] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    } else {
        $photoCount = (int) ($result['photos']['count'] ?? 0);
        $changed = $result['changed'];
        fwrite(STDOUT, 'READ_PROJECTION_REBUILD=PASS SCOPE=' . strtoupper($scope)
            . ' KINDS=' . ($scope === 'photos' ? 'NONE' : implode(',', $kinds)) . ' PHOTO_COUNT=' . $photoCount
            . ' CHANGED=' . ($changed ? 'YES' : 'NO') . ' DRY_RUN=' . ($dryRun ? 'YES' : 'NO') . "\n");
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'READ_PROJECTION_REBUILD=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
