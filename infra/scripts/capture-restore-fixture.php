<?php

declare(strict_types=1);

const CLASS_ARCHIVE_FIXTURE_ROOT = '/var/www/html/piwigo';

function fixtureFail(string $code): never
{
    fwrite(STDERR, "RESTORE_FIXTURE=FAILED code={$code}\n");
    exit(1);
}

function fixturePrepareRuntime(): void
{
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        fixtureFail('runtime_forbidden');
    }
    if (realpath(CLASS_ARCHIVE_FIXTURE_ROOT) !== CLASS_ARCHIVE_FIXTURE_ROOT || is_link(CLASS_ARCHIVE_FIXTURE_ROOT)) {
        fixtureFail('root_untrusted');
    }
    chdir(CLASS_ARCHIVE_FIXTURE_ROOT) || fixtureFail('chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/** @return array{count:int,sha256:string} */
function fixtureRows(mysqli $db, string $query): array
{
    $result = $db->query($query);
    if (!$result instanceof mysqli_result) {
        fixtureFail('query_failed');
    }
    $hash = hash_init('sha256');
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        ksort($row, SORT_STRING);
        hash_update($hash, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $count++;
    }
    $result->free();
    return ['count' => $count, 'sha256' => hash_final($hash)];
}

/** @return array{count:int,sha256:string} */
function fixturePhysicalOriginals(mysqli $db, string $prefix): array
{
    $result = $db->query('SELECT `id`,`path` FROM `' . $prefix . 'images` ORDER BY `id` ASC');
    if (!$result instanceof mysqli_result) {
        fixtureFail('image_query_failed');
    }
    $hash = hash_init('sha256');
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $relative = ltrim(str_replace('\\', '/', (string) ($row['path'] ?? '')), './');
        if ($relative === '' || str_contains($relative, '..') || !preg_match('#\A(?:upload|galleries)/[A-Za-z0-9._/-]+\z#D', $relative)) {
            fixtureFail('image_path_invalid');
        }
        $path = PHPWG_ROOT_PATH . $relative;
        $digest = (!is_file($path) || is_link($path)) ? false : hash_file('sha256', $path);
        if (!is_string($digest)) {
            fixtureFail('image_original_missing');
        }
        hash_update($hash, (string) $row['id'] . "\0" . $relative . "\0" . $digest . "\n");
        $count++;
    }
    $result->free();
    return ['count' => $count, 'sha256' => hash_final($hash)];
}

/** @return array{count:int,sha256:string} */
function fixturePersistentLifecycleScript(): array
{
    $path = '/usr/local/bin/scripts/user.sh';
    if (!is_file($path) || is_link($path)) {
        fixtureFail('lifecycle_script_missing');
    }
    $digest = hash_file('sha256', $path);
    $mode = @fileperms($path);
    if (!is_string($digest) || !is_int($mode) || ($mode & 0777) !== 0755) {
        fixtureFail('lifecycle_script_invalid');
    }
    return [
        'count' => 1,
        'sha256' => hash('sha256', 'user.sh' . "\0" . ($mode & 0777) . "\0" . $digest . "\n"),
    ];
}

fixturePrepareRuntime();
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();
global $mysqli, $prefixeTable;
if (!$mysqli instanceof mysqli || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fixtureFail('database_unavailable');
}

$ci = $prefixeTable . 'class_identity_';
$tables = [
    'images' => 'SELECT `id`,`path`,`md5sum`,`level`,`date_available` FROM `' . $prefixeTable . 'images` ORDER BY `id` ASC',
    'image_category' => 'SELECT `image_id`,`category_id` FROM `' . $prefixeTable . 'image_category` ORDER BY `image_id`,`category_id`',
    'categories' => 'SELECT `id`,`name`,`permalink`,`uppercats`,`status`,`visible` FROM `' . $prefixeTable . 'categories` ORDER BY `id` ASC',
    'identity' => 'SELECT `id`,`roster_code`,`identity_type`,`state`,`lock_version` FROM `' . $ci . 'identity` ORDER BY `id` ASC',
    'seat' => 'SELECT `id`,`identity_id`,`seat_type`,`ordinal`,`state`,`invite_generation`,`lock_version` FROM `' . $ci . 'seat` ORDER BY `id` ASC',
    'account' => 'SELECT `id`,`seat_id`,`state`,`current_marker`,`provisioning_operation_id` FROM `' . $ci . 'account` ORDER BY `id` ASC',
    'principal' => 'SELECT `id`,`account_id`,`system_role`,`principal_type`,`state`,`auth_epoch` FROM `' . $ci . 'principal` ORDER BY `id` ASC',
    'token_state' => 'SELECT `id`,`seat_id`,`purpose`,`generation`,`state`,`expires_at`,`consumed_at`,`revoked_at` FROM `' . $ci . 'token` ORDER BY `id` ASC',
    'operation' => 'SELECT `id`,`operation_type`,`state`,`seat_id`,`account_id`,`core_user_id`,`completed_at`,`last_error_code` FROM `' . $ci . 'operation` ORDER BY `id` ASC',
    'submission' => 'SELECT `id`,`seat_id`,`state`,`approved_image_id`,`reviewed_at`,`date_precision` FROM `' . $ci . 'submission` ORDER BY `id` ASC',
    'archive_image' => 'SELECT `id`,`piwigo_image_id`,`era`,`source_submission_id`,`date_precision`,`official` FROM `' . $ci . 'archive_image` ORDER BY `id` ASC',
    'photo' => 'SELECT HEX(`class_photo_id`) AS `class_photo_id`,`piwigo_image_id`,`source_submission_id`,HEX(`media_checksum`) AS `media_checksum`,`state` FROM `' . $ci . 'photo` ORDER BY `created_at` ASC',
    'audit' => 'SELECT `id`,`actor_kind`,`action`,`target_type`,`target_id`,`result`,`occurred_at` FROM `' . $ci . 'audit_event` ORDER BY `id` ASC',
];
$summary = [];
foreach ($tables as $name => $query) {
    $summary[$name] = fixtureRows($mysqli, $query);
}
$summary['physical_originals'] = fixturePhysicalOriginals($mysqli, $prefixeTable);
$summary['persistent_lifecycle_script'] = fixturePersistentLifecycleScript();
$multi = $mysqli->query('SELECT COUNT(*) AS `count` FROM (SELECT `image_id` FROM `' . $prefixeTable . 'image_category` GROUP BY `image_id` HAVING COUNT(*) > 1) x');
if (!$multi instanceof mysqli_result || ($row = $multi->fetch_assoc()) === null) {
    fixtureFail('multi_album_query_failed');
}
$summary['multi_album_images'] = (int) $row['count'];
$payload = ['fixture_version' => 1, 'summary' => $summary];
$payload['fixture_sha256'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
