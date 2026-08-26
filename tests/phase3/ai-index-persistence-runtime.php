<?php

declare(strict_types=1);

/**
 * Disposable MariaDB semantics for the checksum-bound AI control plane.
 *
 * No image is opened, no Immich service is contacted and no model is loaded.
 * It creates an isolated random table prefix, exercises durable job state,
 * then removes every test table in finally.
 */

function aiIndexRuntimeFail(string $message): never
{
    throw new RuntimeException($message);
}

function aiIndexRuntimeIdent(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
        aiIndexRuntimeFail('ai_index_runtime_identifier_invalid');
    }
    return '`' . $name . '`';
}

function aiIndexRuntimeExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        aiIndexRuntimeFail('ai_index_runtime_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed>|null */
function aiIndexRuntimeOne(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        aiIndexRuntimeFail('ai_index_runtime_query_failed_' . $db->errno);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "AI_INDEX_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "AI_INDEX_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "AI_INDEX_RUNTIME=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "AI_INDEX_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/Audit.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/AiIndexService.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_air_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$sourceCi = $prefixeTable . 'class_identity_';
$created = [];
$assertions = 0;
$exit = 0;
$priorScope = getenv('CLASS_ARCHIVE_RUNTIME_SCOPE');
$priorWorker = getenv('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER');
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        aiIndexRuntimeFail($message);
    }
};

try {
    // The service refuses to claim/finish jobs unless an explicitly private
    // worker is configured. This test supplies only the non-secret scope
    // flags and restores the host environment in finally.
    putenv('CLASS_ARCHIVE_RUNTIME_SCOPE=PRIVATE_REAL_FULL');
    putenv('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER=1');
    $version = aiIndexRuntimeOne($db, 'SELECT VERSION() AS `version`');
    if (!str_starts_with((string) ($version['version'] ?? ''), '11.8.8-MariaDB')) {
        aiIndexRuntimeFail('ai_index_runtime_locked_mariadb_required');
    }

    // v15 changes album, then uses photo/principal only as constrained FK
    // parents. Build the smallest production-shaped fixture possible.
    aiIndexRuntimeExecute(
        $db,
        'CREATE TABLE ' . aiIndexRuntimeIdent($ci . 'album') . ' LIKE ' . aiIndexRuntimeIdent($sourceCi . 'album'),
    );
    $created[] = $ci . 'album';
    aiIndexRuntimeExecute(
        $db,
        'CREATE TABLE ' . aiIndexRuntimeIdent($ci . 'photo') . ' ('
            . '`class_photo_id` BINARY(16) NOT NULL,`media_checksum` BINARY(32) NOT NULL,'
            . '`immich_asset_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`state` VARCHAR(16) NOT NULL,`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . '`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (`class_photo_id`), UNIQUE KEY `uq_ai_runtime_asset` (`immich_asset_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    $created[] = $ci . 'photo';
    aiIndexRuntimeExecute(
        $db,
        'CREATE TABLE ' . aiIndexRuntimeIdent($ci . 'principal') . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    $created[] = $ci . 'principal';
    foreach (['photo_comment', 'auto_collection', 'auto_collection_photo', 'ai_asset_index', 'ai_index_job'] as $suffix) {
        $created[] = $ci . $suffix;
    }
    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    $migration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationCollectionsFirstCommentsAndAiIndex');
    $migration->invoke($schema);

    $photoId = \ClassIdentity\DomainSupport::generateId();
    $photoBinary = \ClassIdentity\DomainSupport::idToBinary($photoId);
    $checksumA = hash('sha256', 'ai-index-runtime-a', true);
    $checksumB = hash('sha256', 'ai-index-runtime-b', true);
    $photo = aiIndexRuntimeIdent($ci . 'photo');
    $statement = $db->prepare('INSERT INTO ' . $photo . ' (`class_photo_id`,`media_checksum`,`state`) VALUES (?, ?, ?)');
    if (!$statement instanceof mysqli_stmt || !$statement->execute([$photoBinary, $checksumA, \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE])) {
        aiIndexRuntimeFail('ai_index_runtime_photo_insert_failed');
    }
    $statement->close();

    $service = new \ClassIdentity\AiIndexService(new \ClassIdentity\Repository($db, $basePrefix));
    $first = $service->enqueueNewPhoto($photoId);
    $assert(($first['queued'] ?? null) === true, 'new_photo_not_queued');
    $second = $service->enqueueNewPhoto($photoId);
    $assert(($second['queued'] ?? null) === false, 'new_photo_not_idempotent');
    $index = aiIndexRuntimeOne($db, 'SELECT `face_state`,`search_state`,`indexed_at` FROM ' . aiIndexRuntimeIdent($ci . 'ai_asset_index'));
    $assert(($index['face_state'] ?? null) === 'PENDING' && ($index['search_state'] ?? null) === 'PENDING' && ($index['indexed_at'] ?? null) === null, 'initial_index_state_invalid');
    $jobCount = aiIndexRuntimeOne($db, 'SELECT COUNT(*) AS `count` FROM ' . aiIndexRuntimeIdent($ci . 'ai_index_job'));
    $assert((int) ($jobCount['count'] ?? -1) === 1, 'new_photo_created_duplicate_jobs');

    // A byte replacement must cancel the old pending job and create one job
    // for the new checksum rather than allowing the uniqueness guard to hide
    // the change.
    $statement = $db->prepare('UPDATE ' . $photo . ' SET `media_checksum`=? WHERE `class_photo_id`=?');
    if (!$statement instanceof mysqli_stmt || !$statement->execute([$checksumB, $photoBinary])) {
        aiIndexRuntimeFail('ai_index_runtime_photo_replace_failed');
    }
    $statement->close();
    $changed = $service->enqueuePixelChange($photoId);
    $assert(($changed['queued'] ?? null) === true, 'pixel_change_not_queued');
    $jobs = aiIndexRuntimeOne($db, 'SELECT SUM(`state`=\'CANCELLED\') AS `cancelled`,SUM(`state`=\'PENDING\') AS `pending` FROM ' . aiIndexRuntimeIdent($ci . 'ai_index_job'));
    $assert((int) ($jobs['cancelled'] ?? 0) === 1 && (int) ($jobs['pending'] ?? 0) === 1, 'pixel_change_did_not_supersede_old_job');

    $claimed = $service->claimNextJob();
    $assert(is_array($claimed) && ($claimed['expected_checksum'] ?? null) === bin2hex($checksumB), 'claim_did_not_bind_replacement_checksum');
    $service->markJobUnavailable((string) $claimed['job_id'], 'MODEL_CACHE_MISSING');
    $unavailable = aiIndexRuntimeOne($db, 'SELECT `face_state`,`search_state`,`indexed_at` FROM ' . aiIndexRuntimeIdent($ci . 'ai_asset_index'));
    $assert(($unavailable['face_state'] ?? null) === 'UNAVAILABLE' && ($unavailable['search_state'] ?? null) === 'UNAVAILABLE' && ($unavailable['indexed_at'] ?? null) === null, 'unavailable_state_not_persisted');

    $retry = $service->enqueueReconciliation($photoId);
    $assert(($retry['queued'] ?? null) === true, 'explicit_reconciliation_did_not_requeue');
    $claimed = $service->claimNextJob();
    $assert(is_array($claimed), 'reconciliation_job_not_claimable');
    $service->completeIndexJob(
        (string) $claimed['job_id'],
        '50000000-0000-4000-8000-000000000001',
        'face-model', 'face-revision-a', 'search-model', 'search-revision-a',
    );
    $indexed = aiIndexRuntimeOne($db, 'SELECT `face_state`,`search_state`,`indexed_at`,`face_model_revision`,`search_model_revision` FROM ' . aiIndexRuntimeIdent($ci . 'ai_asset_index'));
    $assert(($indexed['face_state'] ?? null) === 'INDEXED' && ($indexed['search_state'] ?? null) === 'INDEXED' && ($indexed['indexed_at'] ?? null) !== null, 'completion_not_persisted');
    $assert(($indexed['face_model_revision'] ?? null) === 'face-revision-a' && ($indexed['search_model_revision'] ?? null) === 'search-revision-a', 'model_revision_not_persisted');

    $model = $service->enqueueModelChange('face-model', 'face-revision-b', 'search-model', 'search-revision-b');
    $assert($model['queued'] === 1 && $model['scanned'] === 1, 'model_change_not_queued');
    $modelJob = $service->claimNextJob();
    $assert(is_array($modelJob) && ($modelJob['job_kind'] ?? null) === 'REINDEX_MODEL' && ($modelJob['trigger_kind'] ?? null) === 'MODEL_CHANGED', 'model_change_job_contract_invalid');
    $service->markJobUnavailable((string) $modelJob['job_id'], 'WORKER_OFFLINE');

    // Retiring the canonical row does not delete business/media truth here;
    // it creates a checksum-bound cleanup job for the isolated AI runtime.
    $statement = $db->prepare('UPDATE ' . $photo . ' SET `state`=? WHERE `class_photo_id`=?');
    if (!$statement instanceof mysqli_stmt || !$statement->execute([\ClassIdentity\ClassArchivePhoto::STATE_RETIRED, $photoBinary])) {
        aiIndexRuntimeFail('ai_index_runtime_photo_retire_failed');
    }
    $statement->close();
    $deleted = $service->enqueuePhotoDeletion($photoId);
    $assert(($deleted['queued'] ?? null) === true && ($deleted['job_kind'] ?? null) === 'DELETE_ASSET', 'delete_job_not_queued');
    $deletedJob = $service->claimNextJob();
    $assert(is_array($deletedJob) && ($deletedJob['job_kind'] ?? null) === 'DELETE_ASSET', 'delete_job_not_claimable');
    $service->completeDeletionJob((string) $deletedJob['job_id']);
    $removed = aiIndexRuntimeOne($db, 'SELECT `face_state`,`search_state`,`indexed_at` FROM ' . aiIndexRuntimeIdent($ci . 'ai_asset_index'));
    $assert(($removed['face_state'] ?? null) === 'REMOVED' && ($removed['search_state'] ?? null) === 'REMOVED' && ($removed['indexed_at'] ?? null) === null, 'delete_state_not_persisted');

    fwrite(STDOUT, "AI_INDEX_RUNTIME=PASS assertions={$assertions} run={$run}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'AI_INDEX_RUNTIME=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(array_unique($created)) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . aiIndexRuntimeIdent($table));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($ci) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $count = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($count !== 0) {
        fwrite(STDERR, "AI_INDEX_RUNTIME_CLEANUP=FAIL run={$run} remaining={$count}\n");
        $exit = 1;
    }
    putenv($priorScope === false ? 'CLASS_ARCHIVE_RUNTIME_SCOPE' : 'CLASS_ARCHIVE_RUNTIME_SCOPE=' . $priorScope);
    putenv($priorWorker === false ? 'CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER' : 'CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER=' . $priorWorker);
    $db->close();
}

exit($exit);
