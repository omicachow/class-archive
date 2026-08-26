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
    'person' => 'SELECT HEX(`class_person_id`) AS `class_person_id`,`immich_person_id`,`display_name`,`classmate_identity_id`,HEX(`manual_cover_class_photo_id`) AS `manual_cover_class_photo_id`,`source_kind`,`visibility`,`state`,`lock_version` FROM `' . $ci . 'person` ORDER BY `created_at` ASC',
    'person_merge' => 'SELECT HEX(`merge_id`) AS `merge_id`,HEX(`source_class_person_id`) AS `source_class_person_id`,HEX(`target_class_person_id`) AS `target_class_person_id`,`state`,`created_by_principal_id`,`reverted_by_principal_id`,`created_at`,`reverted_at` FROM `' . $ci . 'person_merge` ORDER BY `created_at`,`merge_id` ASC',
    'person_photo_rule' => 'SELECT HEX(`class_person_id`) AS `class_person_id`,HEX(`class_photo_id`) AS `class_photo_id`,`rule`,`updated_by_principal_id`,`created_at`,`updated_at` FROM `' . $ci . 'person_photo_rule` ORDER BY `class_person_id`,`class_photo_id` ASC',
    'album' => 'SELECT HEX(`class_album_id`) AS `class_album_id`,`piwigo_category_id`,SHA2(CAST(COALESCE(`display_alias`,\'\') AS CHAR),256) AS `display_alias_sha256`,`album_type`,`owner_principal_id`,`era`,`event_label`,HEX(`manual_cover_class_photo_id`) AS `manual_cover_class_photo_id`,`state` FROM `' . $ci . 'album` ORDER BY `created_at`,`class_album_id` ASC',
    'spotlight' => 'SELECT HEX(`spotlight_id`) AS `spotlight_id`,`owner_principal_id`,HEX(`class_album_id`) AS `class_album_id`,`state`,`starts_at`,`expires_at`,`cancelled_at`,`cancelled_by_principal_id` FROM `' . $ci . 'spotlight` ORDER BY `created_at`,`spotlight_id` ASC',
    'photo_source' => 'SELECT `id`,HEX(`class_photo_id`) AS `class_photo_id`,`source_kind`,`provenance_code`,HEX(`source_reference_digest`) AS `source_reference_digest`,HEX(`original_filename_digest`) AS `original_filename_digest`,HEX(`source_checksum`) AS `source_checksum`,`byte_size`,`observed_at`,`created_by_principal_id` FROM `' . $ci . 'photo_source` ORDER BY `id` ASC',
    'photo_duplicate' => 'SELECT HEX(`duplicate_id`) AS `duplicate_id`,HEX(`left_class_photo_id`) AS `left_class_photo_id`,HEX(`right_class_photo_id`) AS `right_class_photo_id`,`relation_kind`,`similarity`,`state`,HEX(`canonical_class_photo_id`) AS `canonical_class_photo_id`,`created_by_principal_id`,`reviewed_by_principal_id`,`reviewed_at` FROM `' . $ci . 'photo_duplicate` ORDER BY `created_at`,`duplicate_id` ASC',
    'batch_operation' => 'SELECT HEX(`batch_id`) AS `batch_id`,`actor_principal_id`,`operation_type`,`state`,HEX(`payload_digest`) AS `payload_digest`,`item_count`,`applied_count`,`failed_count`,`high_risk_confirmed`,`error_code`,`created_at`,`updated_at`,`completed_at` FROM `' . $ci . 'batch_operation` ORDER BY `created_at`,`batch_id` ASC',
    'batch_operation_item' => 'SELECT `id`,HEX(`batch_id`) AS `batch_id`,HEX(`class_photo_id`) AS `class_photo_id`,`state`,SHA2(CAST(`before_value` AS CHAR),256) AS `before_sha256`,SHA2(CAST(`after_value` AS CHAR),256) AS `after_sha256`,`error_code`,`created_at`,`updated_at` FROM `' . $ci . 'batch_operation_item` ORDER BY `id` ASC',
    // Full private-library state is business truth. Only opaque digests and
    // business-visible collection/folder labels are fingerprinted: neither a
    // source filesystem path nor an original filename can leave the private
    // importer journal through this restore fixture.
    'private_library_collection' => 'SELECT HEX(`source_collection_id`) AS `source_collection_id`,`source_code`,`display_name`,`state`,`created_by_principal_id`,`created_at`,`updated_at` FROM `' . $ci . 'private_library_collection` ORDER BY `source_code` ASC',
    'private_library_folder' => 'SELECT HEX(`folder_id`) AS `folder_id`,HEX(`source_collection_id`) AS `source_collection_id`,HEX(`relative_path_digest`) AS `relative_path_digest`,HEX(`parent_folder_id`) AS `parent_folder_id`,`piwigo_category_id`,HEX(`class_album_id`) AS `class_album_id`,`display_name`,`depth`,`created_at`,`updated_at` FROM `' . $ci . 'private_library_folder` ORDER BY `source_collection_id`,`relative_path_digest` ASC',
    'private_library_import' => 'SELECT HEX(`import_id`) AS `import_id`,HEX(`manifest_digest`) AS `manifest_digest`,`manifest_version`,`item_total`,`state`,`applied_count`,`deduplicated_count`,`failed_count`,`last_error_code`,`created_by_principal_id`,`started_at`,`completed_at`,`created_at`,`updated_at` FROM `' . $ci . 'private_library_import` ORDER BY `created_at`,`import_id` ASC',
    'private_library_import_item' => 'SELECT HEX(`import_id`) AS `import_id`,HEX(`item_digest`) AS `item_digest`,HEX(`source_collection_id`) AS `source_collection_id`,HEX(`folder_id`) AS `folder_id`,HEX(`source_reference_digest`) AS `source_reference_digest`,HEX(`original_filename_digest`) AS `original_filename_digest`,HEX(`source_checksum`) AS `source_checksum`,HEX(`staging_name_digest`) AS `staging_name_digest`,`byte_size`,`state`,HEX(`class_photo_id`) AS `class_photo_id`,`piwigo_image_id`,`attempt_count`,`last_error_code`,`created_at`,`updated_at` FROM `' . $ci . 'private_library_import_item` ORDER BY `import_id`,`item_digest` ASC',
    // User-authored comment text is business state, but a restore fixture
    // fingerprints it rather than emitting it. This keeps the synthetic
    // recovery proof useful without making the fixture a comment export.
    'photo_comment' => 'SELECT HEX(`comment_id`) AS `comment_id`,HEX(`class_photo_id`) AS `class_photo_id`,HEX(`parent_comment_id`) AS `parent_comment_id`,`author_principal_id`,`author_role`,SHA2(CAST(`body` AS CHAR),256) AS `body_sha256`,`state`,`deleted_by_principal_id`,SHA2(CAST(COALESCE(`delete_reason`,\'\') AS CHAR),256) AS `delete_reason_sha256`,`created_at`,`updated_at`,`deleted_at` FROM `' . $ci . 'photo_comment` ORDER BY `created_at`,`comment_id` ASC',
    // Auto collection labels can be curator-authored. Persist their exact
    // state through opaque digests, never raw labels, in recovery evidence.
    'auto_collection' => 'SELECT HEX(`auto_collection_id`) AS `auto_collection_id`,`collection_kind`,SHA2(CAST(`title` AS CHAR),256) AS `title_sha256`,SHA2(CAST(COALESCE(`subtitle`,\'\') AS CHAR),256) AS `subtitle_sha256`,SHA2(CAST(`source_reason` AS CHAR),256) AS `source_reason_sha256`,`archive_date`,`date_precision`,HEX(`cover_class_photo_id`) AS `cover_class_photo_id`,`visibility_scope`,HEX(`projection_revision`) AS `projection_revision`,`state`,`generated_at`,`updated_at` FROM `' . $ci . 'auto_collection` ORDER BY `generated_at`,`auto_collection_id` ASC',
    'auto_collection_photo' => 'SELECT HEX(`auto_collection_id`) AS `auto_collection_id`,HEX(`class_photo_id`) AS `class_photo_id`,`ordinal`,`created_at` FROM `' . $ci . 'auto_collection_photo` ORDER BY `auto_collection_id`,`ordinal`,`class_photo_id` ASC',
    // The Class Archive control plane records only checksum/model state. Face
    // vectors and search embeddings remain in isolated Immich/Postgres and
    // are intentionally absent from this MariaDB-only fixture.
    'ai_asset_index' => 'SELECT HEX(`class_photo_id`) AS `class_photo_id`,HEX(`source_checksum`) AS `source_checksum`,SHA2(CAST(COALESCE(`immich_asset_id`,\'\') AS CHAR),256) AS `immich_asset_id_sha256`,`face_state`,`search_state`,`face_model_name`,`face_model_revision`,`search_model_name`,`search_model_revision`,`indexed_at`,`last_error_code`,`created_at`,`updated_at` FROM `' . $ci . 'ai_asset_index` ORDER BY `class_photo_id` ASC',
    'ai_index_job' => 'SELECT HEX(`job_id`) AS `job_id`,HEX(`class_photo_id`) AS `class_photo_id`,`job_kind`,`trigger_kind`,HEX(`expected_checksum`) AS `expected_checksum`,`state`,`attempt_count`,`not_before`,`last_error_code`,`created_at`,`updated_at`,`completed_at` FROM `' . $ci . 'ai_index_job` ORDER BY `created_at`,`job_id` ASC',
    'native_source_epoch' => 'SELECT `source_key`,HEX(`generation`) AS `generation`,`updated_at` FROM `' . $ci . 'native_source_epoch` ORDER BY `source_key` ASC',
    'audit' => 'SELECT `id`,`actor_kind`,`action`,`target_type`,`target_id`,`result`,`occurred_at` FROM `' . $ci . 'audit_event` ORDER BY `id` ASC',
    'migration' => 'SELECT `version`,`migration_name`,HEX(`checksum`) AS `checksum`,`plugin_version`,`applied_at` FROM `' . $ci . 'migration` ORDER BY `version` ASC',
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
$payload = [
    'fixture_version' => 7,
    'class_identity_schema_version' => 15,
    'projection_recovery' => [
        'policy' => 'REBUILD_FROM_BUSINESS_TRUTH',
        'projection' => 'ALL',
        'expected_count' => (int) $summary['photo']['count'],
        'required_active' => ['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT'],
    ],
    'summary' => $summary,
];
$payload['fixture_sha256'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
