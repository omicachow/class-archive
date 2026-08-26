<?php

declare(strict_types=1);

/**
 * Disposable MariaDB proof for the v15 AutoCollection memory persistence
 * domain. It uses only generated UUIDs/metadata, opens no photo bytes, and
 * removes every random-prefix table in finally.
 */

function autoCollectionRuntimeFail(string $message): never
{
    throw new RuntimeException($message);
}

function autoCollectionRuntimeIdent(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
        autoCollectionRuntimeFail('auto_collection_runtime_identifier_invalid');
    }
    return '`' . $name . '`';
}

function autoCollectionRuntimeExec(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        autoCollectionRuntimeFail('auto_collection_runtime_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed>|null */
function autoCollectionRuntimeOne(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        autoCollectionRuntimeFail('auto_collection_runtime_query_failed_' . $db->errno);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "AUTO_COLLECTION_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "AUTO_COLLECTION_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "AUTO_COLLECTION_RUNTIME=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "AUTO_COLLECTION_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/AutoCollectionService.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_auto_mem_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$sourceCi = $prefixeTable . 'class_identity_';
$created = [];
$assertions = 0;
$exit = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        autoCollectionRuntimeFail($message);
    }
};

try {
    $version = autoCollectionRuntimeOne($db, 'SELECT VERSION() AS `version`');
    if (!str_starts_with((string) ($version['version'] ?? ''), '11.8.8-MariaDB')) {
        autoCollectionRuntimeFail('auto_collection_runtime_locked_mariadb_required');
    }

    autoCollectionRuntimeExec(
        $db,
        'CREATE TABLE ' . autoCollectionRuntimeIdent($ci . 'album') . ' LIKE ' . autoCollectionRuntimeIdent($sourceCi . 'album'),
    );
    $created[] = $ci . 'album';
    autoCollectionRuntimeExec(
        $db,
        'CREATE TABLE ' . autoCollectionRuntimeIdent($ci . 'photo') . ' ('
            . '`class_photo_id` BINARY(16) NOT NULL,`state` VARCHAR(16) NOT NULL,PRIMARY KEY (`class_photo_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    $created[] = $ci . 'photo';
    autoCollectionRuntimeExec(
        $db,
        'CREATE TABLE ' . autoCollectionRuntimeIdent($ci . 'principal') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL,PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    $created[] = $ci . 'principal';
    autoCollectionRuntimeExec(
        $db,
        'CREATE TABLE ' . autoCollectionRuntimeIdent($ci . 'read_projection') . ' ('
            . '`projection_key` VARCHAR(32) NOT NULL,`state` VARCHAR(16) NOT NULL,'
            . '`payload_json` LONGTEXT NULL,`payload_digest` BINARY(32) NULL,PRIMARY KEY (`projection_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    $created[] = $ci . 'read_projection';
    foreach (['photo_comment', 'auto_collection', 'auto_collection_photo', 'ai_asset_index', 'ai_index_job'] as $suffix) {
        $created[] = $ci . $suffix;
    }
    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    $migration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationCollectionsFirstCommentsAndAiIndex');
    $migration->invoke($schema);

    $photoIds = [
        '10000000-0000-4000-8000-000000000001',
        '10000000-0000-4000-8000-000000000002',
        '10000000-0000-4000-8000-000000000003',
    ];
    $photo = autoCollectionRuntimeIdent($ci . 'photo');
    $statement = $db->prepare('INSERT INTO ' . $photo . ' (`class_photo_id`,`state`) VALUES (?,?)');
    if (!$statement instanceof mysqli_stmt) {
        autoCollectionRuntimeFail('auto_collection_runtime_photo_prepare_failed');
    }
    foreach ($photoIds as $photoId) {
        if (!$statement->execute([\ClassIdentity\DomainSupport::idToBinary($photoId), \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE])) {
            autoCollectionRuntimeFail('auto_collection_runtime_photo_insert_failed');
        }
    }
    $statement->close();

    $reason = 'MEMORY:' . str_repeat('A', 56);
    $payload = [
        'available' => true,
        'total' => 1,
        'items' => [[
            'label' => '合成秋季运动会',
            'subtitle' => '班级活动',
            'kind' => 'EVENT',
            'photo_count' => 2,
            'cover_photo_id' => $photoIds[0],
            'photo_ids' => [$photoIds[0], $photoIds[1]],
            'source_reason' => $reason,
            'archive_date' => null,
            'date_precision' => 'EVENT_ONLY',
        ]],
    ];
    $service = new \ClassIdentity\AutoCollectionService(new \ClassIdentity\Repository($db, $basePrefix));
    try {
        $service->syncMemoryProjectionInCurrentTransaction($payload);
        autoCollectionRuntimeFail('transaction_participant_accepted_without_transaction');
    } catch (LogicException $error) {
        $assert($error->getMessage() === 'class_archive_auto_collection_transaction_required', 'transaction_guard_wrong_error');
    }
    $publishProjection = static function (array $fullPayload, string $state = 'ACTIVE') use ($db, $ci): void {
        $json = json_encode([
            '_projection' => ['version' => 3, 'kind' => 'MEMORIES'],
            'FULL' => $fullPayload,
            'HERITAGE' => ['available' => true, 'total' => 0, 'items' => []],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $statement = $db->prepare(
            'INSERT INTO ' . autoCollectionRuntimeIdent($ci . 'read_projection')
                . ' (`projection_key`,`state`,`payload_json`,`payload_digest`) VALUES (?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE `state`=VALUES(`state`),`payload_json`=VALUES(`payload_json`),'
                . '`payload_digest`=VALUES(`payload_digest`)',
        );
        $digest = hash('sha256', $json, true);
        if (!$statement instanceof mysqli_stmt || !$statement->execute(['MEMORIES', $state, $json, $digest])) {
            autoCollectionRuntimeFail('auto_collection_runtime_projection_publish_failed');
        }
        $statement->close();
    };
    $first = $service->syncMemoryProjection($payload);
    $assert($first === ['inserted' => 1, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'total' => 1], 'initial_sync_invalid');

    $collection = autoCollectionRuntimeIdent($ci . 'auto_collection');
    $member = autoCollectionRuntimeIdent($ci . 'auto_collection_photo');
    $row = autoCollectionRuntimeOne($db, 'SELECT HEX(`auto_collection_id`) AS `id`,`title`,`state`,OCTET_LENGTH(`projection_revision`) AS `revision` FROM ' . $collection);
    $assert(($row['title'] ?? null) === '合成秋季运动会' && ($row['state'] ?? null) === 'ACTIVE' && (int) ($row['revision'] ?? 0) === 32, 'initial_header_not_persisted');
    $collectionId = strtolower((string) ($row['id'] ?? ''));
    $members = autoCollectionRuntimeOne($db, 'SELECT GROUP_CONCAT(LOWER(HEX(`class_photo_id`)) ORDER BY `ordinal` SEPARATOR ",") AS `ids` FROM ' . $member);
    $expectedFirst = str_replace('-', '', $photoIds[0]) . ',' . str_replace('-', '', $photoIds[1]);
    $assert(($members['ids'] ?? null) === $expectedFirst, 'initial_members_not_persisted');

    $same = $service->syncMemoryProjection($payload);
    $assert($same === ['inserted' => 0, 'updated' => 0, 'unchanged' => 1, 'retired' => 0, 'total' => 1], 'same_revision_not_idempotent');

    $payload['items'][0]['label'] = '合成秋季运动会（整理版）';
    $payload['items'][0]['photo_count'] = 2;
    $payload['items'][0]['cover_photo_id'] = $photoIds[2];
    $payload['items'][0]['photo_ids'] = [$photoIds[2], $photoIds[0]];
    $updated = $service->syncMemoryProjection($payload);
    $assert($updated === ['inserted' => 0, 'updated' => 1, 'unchanged' => 0, 'retired' => 0, 'total' => 1], 'changed_revision_not_updated');
    $updatedRow = autoCollectionRuntimeOne($db, 'SELECT LOWER(HEX(`auto_collection_id`)) AS `id`,`title`,`state` FROM ' . $collection);
    $assert(($updatedRow['id'] ?? null) === str_replace('-', '', $collectionId)
        && ($updatedRow['title'] ?? null) === '合成秋季运动会（整理版）' && ($updatedRow['state'] ?? null) === 'ACTIVE', 'stable_collection_id_not_retained');
    $updatedMembers = autoCollectionRuntimeOne($db, 'SELECT GROUP_CONCAT(LOWER(HEX(`class_photo_id`)) ORDER BY `ordinal` SEPARATOR ",") AS `ids` FROM ' . $member);
    $expectedUpdated = str_replace('-', '', $photoIds[2]) . ',' . str_replace('-', '', $photoIds[0]);
    $assert(($updatedMembers['ids'] ?? null) === $expectedUpdated, 'changed_members_not_replaced');

    $empty = $service->syncMemoryProjection(['available' => true, 'total' => 0, 'items' => []]);
    $assert($empty === ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 1, 'total' => 0], 'removed_memory_not_retired');
    $retired = autoCollectionRuntimeOne($db, 'SELECT `state` FROM ' . $collection);
    $assert(($retired['state'] ?? null) === 'RETIRED', 'retired_state_missing');

    $reactivated = $service->syncMemoryProjection($payload);
    $assert($reactivated === ['inserted' => 0, 'updated' => 1, 'unchanged' => 0, 'retired' => 0, 'total' => 1], 'retired_memory_not_reactivated');
    $count = autoCollectionRuntimeOne($db, 'SELECT COUNT(*) AS `count` FROM ' . $collection);
    $assert((int) ($count['count'] ?? -1) === 1, 'reactivation_created_duplicate_collection');

    $unavailable = $service->syncMemoryProjection(['available' => false, 'total' => 0, 'items' => []]);
    $assert($unavailable === ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'total' => 0], 'unavailable_source_not_preserved');
    $preserved = autoCollectionRuntimeOne($db, 'SELECT COUNT(*) AS `count` FROM ' . $collection . " WHERE `state`='ACTIVE'");
    $assert((int) ($preserved['count'] ?? -1) === 1, 'unavailable_source_retired_memory');

    // A class-term context is valid without fabricating a day-level archive
    // date. The shared FULL revision changes and is written to every ACTIVE
    // row when another Memory is added.
    $payload['items'][0]['date_precision'] = 'TERM';
    $payload['items'][0]['archive_date'] = null;
    $term = $service->syncMemoryProjection($payload);
    $assert(($term['updated'] ?? null) === 1, 'dateless_term_not_accepted');
    $two = $payload;
    $two['items'][] = [
        'label' => '合成毕业回忆',
        'subtitle' => null,
        'kind' => 'EVENT',
        'photo_count' => 1,
        'cover_photo_id' => $photoIds[1],
        'photo_ids' => [$photoIds[1]],
        'source_reason' => 'MEMORY:' . str_repeat('B', 56),
        'archive_date' => null,
        'date_precision' => 'EVENT_ONLY',
    ];
    $two['total'] = 2;
    $twoResult = $service->syncMemoryProjection($two);
    $assert(($twoResult['inserted'] ?? null) === 1 && ($twoResult['updated'] ?? null) === 1, 'shared_revision_not_propagated');
    $revisionRows = autoCollectionRuntimeOne(
        $db,
        'SELECT COUNT(DISTINCT `projection_revision`) AS `revisions`,COUNT(*) AS `active` FROM ' . $collection . " WHERE `state`='ACTIVE'",
    );
    $assert((int) ($revisionRows['revisions'] ?? 0) === 1 && (int) ($revisionRows['active'] ?? 0) === 2, 'active_rows_do_not_share_full_revision');
    $service->syncMemoryProjection($payload);

    $bad = $payload;
    $bad['items'][0]['photo_ids'] = [$photoIds[0], '10000000-0000-4000-8000-000000000099'];
    $bad['items'][0]['cover_photo_id'] = $photoIds[0];
    $badRejected = false;
    try {
        $service->syncMemoryProjection($bad);
    } catch (RuntimeException $error) {
        $badRejected = $error->getMessage() === 'class_archive_auto_collection_photo_missing';
    }
    $assert($badRejected, 'missing_photo_not_fail_closed');
    $badReason = $payload;
    $badReason['items'][0]['source_reason'] = "MEMORY:BAD\nREASON";
    try {
        $service->syncMemoryProjection($badReason);
        autoCollectionRuntimeFail('multiline_source_reason_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_auto_collection_source_reason_invalid', 'multiline_source_reason_wrong_error');
    }

    $publishProjection($payload);
    $report = $service->reconciliationReport();
    $assert(($report['issues'] ?? null) === [] && (int) ($report['counts']['active'] ?? 0) === 1, 'matching_projection_reported_drift');

    // Real MariaDB fault injection for every structural invariant consumed by
    // ReconciliationService. Each mutation is restored before the next one.
    autoCollectionRuntimeExec($db, 'UPDATE ' . $collection . ' SET `cover_class_photo_id`=UNHEX(\'' . str_replace('-', '', $photoIds[1]) . "') WHERE `state`='ACTIVE'");
    $codes = array_column($service->reconciliationReport()['issues'], 'code');
    $assert(in_array('AUTO_COLLECTION_COVER_NOT_MEMBER', $codes, true), 'cover_member_drift_not_detected');
    autoCollectionRuntimeExec($db, 'UPDATE ' . $collection . ' SET `cover_class_photo_id`=UNHEX(\'' . str_replace('-', '', $photoIds[2]) . "') WHERE `state`='ACTIVE'");

    autoCollectionRuntimeExec($db, 'UPDATE ' . $member . " SET `ordinal`=3 WHERE `ordinal`=2 AND `auto_collection_id`=UNHEX('{$collectionId}')");
    $codes = array_column($service->reconciliationReport()['issues'], 'code');
    $assert(in_array('AUTO_COLLECTION_MEMBER_ORDINAL_DRIFT', $codes, true), 'ordinal_drift_not_detected');
    autoCollectionRuntimeExec($db, 'UPDATE ' . $member . " SET `ordinal`=2 WHERE `ordinal`=3 AND `auto_collection_id`=UNHEX('{$collectionId}')");

    autoCollectionRuntimeExec($db, 'UPDATE ' . $collection . " SET `projection_revision`=RANDOM_BYTES(32) WHERE `state`='ACTIVE'");
    $codes = array_column($service->reconciliationReport()['issues'], 'code');
    $assert(in_array('AUTO_COLLECTION_REVISION_DRIFT', $codes, true), 'revision_drift_not_detected');
    $service->syncMemoryProjection($payload);

    $duplicateId = strtolower(bin2hex(random_bytes(16)));
    $duplicateAccepted = $db->query(
        'INSERT INTO ' . $collection
            . ' (`auto_collection_id`,`collection_kind`,`title`,`subtitle`,`source_reason`,`archive_date`,`date_precision`,'
            . '`cover_class_photo_id`,`visibility_scope`,`projection_revision`,`state`) SELECT UNHEX(\'' . $duplicateId
            . "'),'MEMORY',`title`,`subtitle`,`source_reason`,`archive_date`,`date_precision`,`cover_class_photo_id`,`visibility_scope`,RANDOM_BYTES(32),'ACTIVE'"
            . ' FROM ' . $collection . " WHERE `state`='ACTIVE' LIMIT 1",
    );
    $assert($duplicateAccepted === false && $db->errno === 1062, 'source_reason_unique_constraint_not_enforced');

    $publishProjection($payload, 'STALE');
    $codes = array_column($service->reconciliationReport()['issues'], 'code');
    $assert(in_array('AUTO_COLLECTION_MEMORY_PROJECTION_NOT_ACTIVE', $codes, true), 'stale_memory_projection_not_detected');
    $publishProjection($payload);
    $status = $service->status();
    $assert(($status['active'] ?? null) === 1 && ($status['retired'] ?? null) === 1
        && ($status['total'] ?? null) === 2 && ($status['read_only'] ?? null) === true, 'read_only_status_invalid');

    fwrite(STDOUT, "AUTO_COLLECTION_RUNTIME=PASS assertions={$assertions} run={$run}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'AUTO_COLLECTION_RUNTIME=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(array_unique($created)) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . autoCollectionRuntimeIdent($table));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($ci) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $count = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($count !== 0) {
        fwrite(STDERR, "AUTO_COLLECTION_RUNTIME_CLEANUP=FAIL run={$run} remaining={$count}\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
