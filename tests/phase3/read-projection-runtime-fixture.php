<?php

declare(strict_types=1);

const CLASS_ARCHIVE_POINT_FIXTURE_ROOT = '/var/www/html/piwigo';
// Keep the short-lived mutation journal outside Piwigo's persisted media
// volumes. The container entrypoint intentionally normalizes those volumes to
// the delivery UID/GID and mode 0660 on restart; that must not make this
// unprivileged, mode-0600 test journal unreadable before its exact cleanup.
const CLASS_ARCHIVE_POINT_FIXTURE_STATE = '/tmp/class-archive-read-projection-runtime-state.json';

function pointFixtureFail(string $code): never
{
    $safe = preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected';
    fwrite(STDERR, "READ_PROJECTION_RUNTIME_FIXTURE=FAIL code={$safe}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli' || getenv('CLASS_ARCHIVE_ALLOW_READ_PROJECTION_RUNTIME_FIXTURE') !== '1'
    || !function_exists('posix_geteuid') || posix_geteuid() === 0
) {
    pointFixtureFail('explicit_unprivileged_cli_required');
}
if ($argc !== 3 || !in_array($argv[1], ['prepare', 'cleanup'], true)
    || preg_match('/\A[a-f0-9]{16}\z/D', (string) $argv[2]) !== 1
) {
    pointFixtureFail('arguments_invalid');
}
$action = (string) $argv[1];
$run = (string) $argv[2];

try {
    if (realpath(CLASS_ARCHIVE_POINT_FIXTURE_ROOT) !== CLASS_ARCHIVE_POINT_FIXTURE_ROOT
        || is_link(CLASS_ARCHIVE_POINT_FIXTURE_ROOT)
        || !is_file(CLASS_ARCHIVE_POINT_FIXTURE_ROOT . '/local/config/database.inc.php')
    ) {
        throw new RuntimeException('piwigo_root_untrusted');
    }
    chdir(CLASS_ARCHIVE_POINT_FIXTURE_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
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
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    global $prefixeTable;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('piwigo_prefix_invalid');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $archive = '`' . $repository->table('archive_image') . '`';

    if ($action === 'cleanup') {
        if (!is_file(CLASS_ARCHIVE_POINT_FIXTURE_STATE) || is_link(CLASS_ARCHIVE_POINT_FIXTURE_STATE)) {
            throw new RuntimeException('state_missing');
        }
        $metadata = lstat(CLASS_ARCHIVE_POINT_FIXTURE_STATE);
        $raw = file_get_contents(CLASS_ARCHIVE_POINT_FIXTURE_STATE);
        $state = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($metadata) || (($metadata['mode'] ?? 0) & 0777) !== 0600
            || !is_array($state) || ($state['run'] ?? null) !== $run
            || !is_int($state['image_id'] ?? null) || $state['image_id'] <= 0
            || ($state['baseline'] ?? null) !== null
        ) {
            throw new RuntimeException('state_invalid');
        }
        $repository->transaction(function () use ($repository, $archive, $state): void {
            $deleted = $repository->execute(
                "DELETE FROM {$archive} WHERE `piwigo_image_id`=? AND `source_submission_id` IS NULL "
                    . "AND `era`='HERITAGE' AND `archive_date` IS NULL AND `date_precision`='UNKNOWN' "
                    . "AND `date_confidence`='UNKNOWN' AND `date_source`='UNKNOWN' AND `event_label` IS NULL AND `official`=0",
                [(int) $state['image_id']],
            );
            if ($deleted !== 1) {
                throw new RuntimeException('cleanup_row_drift');
            }
        });
        if (!unlink(CLASS_ARCHIVE_POINT_FIXTURE_STATE) || file_exists(CLASS_ARCHIVE_POINT_FIXTURE_STATE)) {
            throw new RuntimeException('state_cleanup_failed');
        }
        fwrite(STDOUT, "READ_PROJECTION_RUNTIME_FIXTURE=CLEAN run={$run}\n");
        exit(0);
    }

    if (file_exists(CLASS_ARCHIVE_POINT_FIXTURE_STATE) || is_link(CLASS_ARCHIVE_POINT_FIXTURE_STATE)) {
        throw new RuntimeException('state_already_exists');
    }
    $heritage = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-heritage' LIMIT 1",
    );
    $living = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-living' LIMIT 1",
    );
    $heritageId = (int) ($heritage['id'] ?? 0);
    $livingId = (int) ($living['id'] ?? 0);
    if ($heritageId <= 0 || $livingId <= 0 || $heritageId === $livingId) {
        throw new RuntimeException('era_roots_invalid');
    }
    $candidate = $repository->fetchOne(
        'SELECT p.`piwigo_image_id` FROM `' . $repository->table('photo') . '` p '
            . 'JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id`=p.`piwigo_image_id` '
            . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
            . "LEFT JOIN {$archive} a ON a.`piwigo_image_id`=p.`piwigo_image_id` "
            . "WHERE p.`state`='ACTIVE' AND a.`id` IS NULL GROUP BY p.`piwigo_image_id` "
            . 'HAVING MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=1 '
            . 'AND MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=0 '
            . 'ORDER BY p.`piwigo_image_id` ASC LIMIT 1',
        [$heritageId, $heritageId, $livingId, $livingId],
    );
    $imageId = (int) ($candidate['piwigo_image_id'] ?? 0);
    if ($imageId <= 0) {
        throw new RuntimeException('safe_candidate_missing');
    }
    $state = json_encode(['version' => 1, 'run' => $run, 'image_id' => $imageId, 'baseline' => null], JSON_THROW_ON_ERROR);
    $handle = fopen(CLASS_ARCHIVE_POINT_FIXTURE_STATE, 'x+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('state_create_failed');
    }
    try {
        if (!flock($handle, LOCK_EX) || fwrite($handle, $state) !== strlen($state) || !fflush($handle)
            || !chmod(CLASS_ARCHIVE_POINT_FIXTURE_STATE, 0600)
        ) {
            throw new RuntimeException('state_publish_failed');
        }
    } finally {
        fclose($handle);
    }
    try {
        $repository->execute(
            "INSERT INTO {$archive} (`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,"
                . "`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) "
                . "VALUES (?,'HERITAGE',NULL,'UNKNOWN','UNKNOWN','UNKNOWN',NULL,0,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
            [$imageId],
        );
    } catch (Throwable $error) {
        @unlink(CLASS_ARCHIVE_POINT_FIXTURE_STATE);
        throw $error;
    }
    fwrite(STDOUT, "READ_PROJECTION_RUNTIME_FIXTURE=READY run={$run}\n");
} catch (Throwable $error) {
    $code = strtolower($error->getMessage());
    pointFixtureFail(preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) === 1 ? $code : 'unexpected_fixture_error');
}
