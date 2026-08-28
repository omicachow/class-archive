<?php

declare(strict_types=1);

const PIWIGO_ROOT = '/var/www/html/piwigo';
const STATE_PREFIX = PIWIGO_ROOT . '/_data/.class-archive-timeline-runtime-';

function timelineFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function timelineStatePath(string $run): string
{
    if (preg_match('/\A[a-f0-9]{16}\z/D', $run) !== 1) {
        timelineFail('timeline_runtime_run_invalid');
    }
    return STATE_PREFIX . $run . '.json';
}

function timelineBootstrap(): void
{
    if (getenv('CLASS_ARCHIVE_ALLOW_TIMELINE_RUNTIME_FIXTURE') !== '1') {
        timelineFail('timeline_runtime_fixture_not_explicitly_enabled');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        timelineFail('timeline_runtime_fixture_refuses_root');
    }
    chdir(PIWIGO_ROOT) || timelineFail('timeline_runtime_cannot_enter_piwigo_root');
}

/** @return list<array<string,mixed>> */
function timelineRows(string $table, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $list = implode(',', array_map('intval', $ids));
    return query2array('SELECT * FROM `' . $table . '` WHERE `piwigo_image_id` IN (' . $list . ') ORDER BY `piwigo_image_id`');
}

/** @param list<array<string,mixed>> $rows */
function timelineRestoreRows(string $table, array $ids, array $rows): void
{
    $list = implode(',', array_map('intval', $ids));
    pwg_query('DELETE FROM `' . $table . '` WHERE `piwigo_image_id` IN (' . $list . ')');
    foreach ($rows as $row) {
        $columns = ['id','piwigo_image_id','era','archive_date','date_precision','date_confidence','date_source','event_label','official','source_submission_id','created_at','updated_at'];
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $values[] = $value === null ? 'NULL' : "'" . pwg_db_real_escape_string((string) $value) . "'";
        }
        pwg_query(
            'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $values) . ')'
        );
    }
}

if ($argc !== 3 || !in_array($argv[1], ['prepare', 'cleanup'], true)) {
    timelineFail('usage: archive-timeline-runtime-fixture.php prepare|cleanup <run>');
}

$action = $argv[1];
$run = $argv[2];
$statePath = timelineStatePath($run);
timelineBootstrap();

// Piwigo intentionally establishes configuration, database and user globals
// while including common.inc.php. It must remain at file scope: including it
// inside timelineBootstrap() strands those globals in that function and
// causes the Core to attempt an invalid guest redirect.
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

global $prefixeTable;
$repository = \ClassIdentity\Repository::fromPiwigo();
$archiveTable = $repository->table('archive_image');

if ($action === 'cleanup') {
    if (!is_file($statePath) || is_link($statePath)) {
        timelineFail('timeline_runtime_state_missing');
    }
    $raw = file_get_contents($statePath);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || ($state['run'] ?? null) !== $run || !is_array($state['ids'] ?? null) || !is_array($state['rows'] ?? null)) {
        timelineFail('timeline_runtime_state_invalid');
    }
    $ids = array_values(array_filter($state['ids'], static fn ($id): bool => is_int($id) || ctype_digit((string) $id)));
    if (count($ids) !== 5) {
        timelineFail('timeline_runtime_state_ids_invalid');
    }
    timelineRestoreRows($archiveTable, array_map('intval', $ids), $state['rows']);
    if (!unlink($statePath) || file_exists($statePath)) {
        timelineFail('timeline_runtime_state_cleanup_failed');
    }
    fwrite(STDOUT, "ARCHIVE_TIMELINE_RUNTIME_FIXTURE=CLEANUP run={$run}\n");
    exit(0);
}

if (file_exists($statePath) || is_link($statePath)) {
    timelineFail('timeline_runtime_state_already_exists');
}
$heritage = query2array('SELECT `id` FROM `' . $prefixeTable . 'categories` WHERE `permalink` = \'class-archive-heritage\' LIMIT 1');
if (count($heritage) !== 1) {
    timelineFail('timeline_runtime_heritage_root_missing');
}
$heritageId = (int) $heritage[0]['id'];
$candidates = query2array(
    'SELECT DISTINCT i.`id` FROM `' . $prefixeTable . 'images` i '
    . 'JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id` = i.`id` '
    . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id` = ic.`category_id` '
    . 'LEFT JOIN `' . $archiveTable . '` ai ON ai.`piwigo_image_id` = i.`id` '
    . 'WHERE (c.`id` = ' . $heritageId . ' OR FIND_IN_SET(' . $heritageId . ', c.`uppercats`) > 0) '
    . 'AND ai.`source_submission_id` IS NULL '
    . 'ORDER BY i.`id` ASC LIMIT 5'
);
if (count($candidates) !== 5) {
    timelineFail('timeline_runtime_insufficient_safe_heritage_images');
}
$ids = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
$baselineRows = timelineRows($archiveTable, $ids);

$state = ['run' => $run, 'ids' => $ids, 'rows' => $baselineRows];
$encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
if (file_put_contents($statePath, $encoded, LOCK_EX) === false || !chmod($statePath, 0600)) {
    timelineFail('timeline_runtime_state_write_failed');
}

$fixtures = [
    [$ids[0], '2012-06-18', 'EXACT', 'ARCHIVE_CONFIRMED', null],
    [$ids[1], '2012-09-01', 'MONTH', 'ARCHIVE_CONFIRMED', null],
    [$ids[2], '2011-01-01', 'YEAR', 'EXIF_TRUSTED', null],
    [$ids[3], null, 'EVENT_ONLY', 'EVENT_INFERENCE', '合成秋季运动会'],
    [$ids[4], null, 'UNKNOWN', 'UNKNOWN', null],
];
try {
    foreach ($fixtures as [$imageId, $date, $precision, $source, $event]) {
        $dateValue = $date === null ? 'NULL' : "'" . pwg_db_real_escape_string($date) . "'";
        $eventValue = $event === null ? 'NULL' : "'" . pwg_db_real_escape_string($event) . "'";
        pwg_query(
            'INSERT INTO `' . $archiveTable . '` '
            . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) '
            . 'VALUES (' . (int) $imageId . ", 'HERITAGE', {$dateValue}, '" . $precision . "', 'HIGH', '" . $source . "', {$eventValue}, 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `era`=VALUES(`era`),`archive_date`=VALUES(`archive_date`),`date_precision`=VALUES(`date_precision`),`date_confidence`=VALUES(`date_confidence`),`date_source`=VALUES(`date_source`),`event_label`=VALUES(`event_label`),`official`=VALUES(`official`),`updated_at`=UTC_TIMESTAMP(6)'
        );
    }
} catch (Throwable $error) {
    timelineRestoreRows($archiveTable, $ids, $baselineRows);
    @unlink($statePath);
    throw $error;
}

fwrite(STDOUT, "ARCHIVE_TIMELINE_RUNTIME_FIXTURE=READY run={$run} ids=" . implode(',', $ids) . "\n");
