<?php

declare(strict_types=1);

/**
 * Disposable MariaDB schema + domain gate for v17 Collection snapshots.
 *
 * It creates only a random-prefix InnoDB fixture, never migrates a live
 * Class Archive database or opens a media file. Use --derive only to refresh
 * the locked semantic digests after a deliberate v17 schema change.
 */

const COLLECTION_SNAPSHOT_DIGEST_SUFFIXES = [
    'collection_snapshot',
    'collection_snapshot_item',
    'collection_snapshot_pointer',
    'collection_pin',
    'collection_feedback',
    'collection_maintenance_state',
];

function collectionSnapshotFail(string $message): never
{
    throw new RuntimeException($message);
}

function collectionSnapshotIdent(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
        collectionSnapshotFail('collection_snapshot_identifier_invalid');
    }
    return '`' . $name . '`';
}

function collectionSnapshotExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        collectionSnapshotFail('collection_snapshot_query_failed_' . $db->errno);
    }
}

function collectionSnapshotRejected(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql) !== false) {
        collectionSnapshotFail('collection_snapshot_constraint_not_enforced_' . $label);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/CollectionSnapshotService.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_css_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$created = [];
$assertions = 0;
$exit = 0;

try {
    $version = $db->query('SELECT VERSION()');
    $versionText = $version instanceof mysqli_result ? (string) ($version->fetch_row()[0] ?? '') : '';
    if ($version instanceof mysqli_result) {
        $version->free();
    }
    if (!str_starts_with($versionText, '11.8.8-MariaDB')) {
        collectionSnapshotFail('collection_snapshot_locked_mariadb_required');
    }

    $photo = $ci . 'photo';
    $principal = $ci . 'principal';
    collectionSnapshotExecute($db, 'CREATE TABLE ' . collectionSnapshotIdent($photo) . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    collectionSnapshotExecute($db, 'CREATE TABLE ' . collectionSnapshotIdent($principal) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $created[] = $photo;
    $created[] = $principal;
    foreach (COLLECTION_SNAPSHOT_DIGEST_SUFFIXES as $suffix) {
        $created[] = $ci . $suffix;
    }

    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    $migration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots');
    $migration->invoke($schema);

    $digest = new ReflectionMethod(\ClassIdentity\Schema::class, 'semanticDigest');
    $expectedMethod = new ReflectionMethod(\ClassIdentity\Schema::class, 'expectedSemanticDigests');
    $expected = $expectedMethod->invoke(null);
    if (!is_array($expected)) {
        collectionSnapshotFail('collection_snapshot_expected_digest_invalid');
    }
    $derived = [];
    foreach (COLLECTION_SNAPSHOT_DIGEST_SUFFIXES as $suffix) {
        $actual = $digest->invoke($schema, $suffix);
        if (!is_string($actual)) {
            collectionSnapshotFail('collection_snapshot_digest_invalid_' . $suffix);
        }
        if (!$derive && !hash_equals((string) ($expected[$suffix] ?? ''), $actual)) {
            collectionSnapshotFail('collection_snapshot_digest_mismatch_' . $suffix);
        }
        $derived[$suffix] = $actual;
        ++$assertions;
    }
    if ($derive) {
        fwrite(STDOUT, 'COLLECTION_SNAPSHOT_SCHEMA=DERIVED ' . implode(' ', array_map(
            static fn(string $suffix): string => $suffix . '=' . $derived[$suffix],
            COLLECTION_SNAPSHOT_DIGEST_SUFFIXES,
        )) . ' run=' . $run . "\n");
        exit(0);
    }

    $snapshot = collectionSnapshotIdent($ci . 'collection_snapshot');
    $pointer = collectionSnapshotIdent($ci . 'collection_snapshot_pointer');
    $pin = collectionSnapshotIdent($ci . 'collection_pin');
    $feedback = collectionSnapshotIdent($ci . 'collection_feedback');
    $maintenance = collectionSnapshotIdent($ci . 'collection_maintenance_state');
    collectionSnapshotExecute($db, "INSERT INTO " . collectionSnapshotIdent($photo) . " (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')), (UNHEX('10000000000040008000000000000002'))");
    collectionSnapshotExecute($db, "INSERT INTO " . collectionSnapshotIdent($principal) . " (`id`) VALUES (1)");
    collectionSnapshotRejected($db, "INSERT INTO {$snapshot} (`snapshot_id`,`scope`,`projection_kind`,`state`,`input_revision`,`payload_digest`,`item_count`) VALUES (UNHEX('20000000000040008000000000000001'),'INVALID','HOME','BUILDING',UNHEX(SHA2('a',256)),UNHEX(SHA2('b',256)),0)", 'snapshot_scope');
    ++$assertions;
    collectionSnapshotRejected($db, "INSERT INTO {$pointer} (`scope`,`projection_kind`,`active_snapshot_id`,`active_revision`) VALUES ('FULL','HOME',UNHEX('2fffffffffff4fff8fff000000000001'),UNHEX(SHA2('c',256)))", 'pointer_fk');
    ++$assertions;

    $repository = new \ClassIdentity\Repository($db, $basePrefix);
    $service = new \ClassIdentity\CollectionSnapshotService($repository);
    $photoOne = '10000000-0000-4000-8000-000000000001';
    $photoTwo = '10000000-0000-4000-8000-000000000002';
    $revisionOne = hash('sha256', 'snapshot-one');
    $items = [[
        'itemKind' => 'ALBUM',
        'itemKey' => 'synthetic-album',
        'coverPhotoId' => $photoTwo,
        'photoIds' => [$photoOne, $photoTwo],
        'payload' => ['title' => '合成相册', 'subtitle' => '仅测试'],
    ]];
    $first = $service->publish('FULL', 'HOME', $revisionOne, $items);
    if (($first['published'] ?? null) !== true || ($first['itemCount'] ?? null) !== 1) {
        collectionSnapshotFail('collection_snapshot_publish_failed');
    }
    ++$assertions;
    $same = $service->publish('FULL', 'HOME', $revisionOne, $items);
    if (($same['published'] ?? null) !== false || ($same['snapshotId'] ?? null) !== ($first['snapshotId'] ?? null)) {
        collectionSnapshotFail('collection_snapshot_publish_idempotence_failed');
    }
    ++$assertions;
    $full = $service->activeSnapshot('FULL', 'HOME', static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $item->publicProjection([$photoOne]));
    if (($full['scope'] ?? null) !== 'FULL' || count((array) ($full['items'] ?? [])) !== 1
        || (($full['items'][0]['coverPhotoId'] ?? null) !== $photoOne)
        || (($full['items'][0]['photoCount'] ?? null) !== 1)) {
        collectionSnapshotFail('collection_snapshot_current_acl_recheck_failed');
    }
    ++$assertions;
    $heritage = $service->publish('HERITAGE_ONLY', 'HOME', hash('sha256', 'heritage-one'), [[
        'itemKind' => 'ALBUM',
        'itemKey' => 'heritage-album',
        'coverPhotoId' => $photoOne,
        'photoIds' => [$photoOne],
        'payload' => ['title' => '班级历史'],
    ]]);
    if (($heritage['scope'] ?? null) !== 'HERITAGE_ONLY') {
        collectionSnapshotFail('collection_snapshot_heritage_scope_mapping_failed');
    }
    ++$assertions;
    $second = $service->publish('FULL', 'HOME', hash('sha256', 'snapshot-two'), [[
        'itemKind' => 'ALBUM',
        'itemKey' => 'synthetic-album',
        'coverPhotoId' => $photoOne,
        'photoIds' => [$photoOne],
        'payload' => ['title' => '合成相册 v2'],
    ]]);
    if (($second['published'] ?? null) !== true || ($second['snapshotId'] ?? null) === ($first['snapshotId'] ?? null)) {
        collectionSnapshotFail('collection_snapshot_new_version_failed');
    }
    $superseded = $db->query("SELECT COUNT(*) FROM {$snapshot} WHERE `state`='SUPERSEDED'");
    if (!$superseded instanceof mysqli_result || (int) ($superseded->fetch_row()[0] ?? 0) !== 1) {
        collectionSnapshotFail('collection_snapshot_history_retention_failed');
    }
    $superseded->free();
    ++$assertions;
    $current = static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $item->publicProjection($item->photoIds());
    $pinned = $service->pin(1, 'FULL', 'HOME', 'ALBUM', 'synthetic-album', $current);
    if (($pinned['ordinal'] ?? null) !== 1 || !is_string($pinned['pinId'] ?? null)
        || ($pinned['projectionKind'] ?? null) !== 'HOME') {
        collectionSnapshotFail('collection_snapshot_pin_failed');
    }
    $pins = $service->pins(1, 'FULL', $current);
    if (count((array) ($pins['items'] ?? [])) !== 1) {
        collectionSnapshotFail('collection_snapshot_pin_read_failed');
    }
    if (($pins['items'][0]['projectionKind'] ?? null) !== 'HOME') {
        collectionSnapshotFail('collection_snapshot_pin_projection_kind_missing');
    }
    ++$assertions;
    $reorder = $service->reorderPins(1, 'FULL', [[
        'projectionKind' => 'HOME', 'itemKind' => 'ALBUM', 'itemKey' => 'synthetic-album',
    ]], $current);
    if (($reorder['reordered'] ?? null) !== true) {
        collectionSnapshotFail('collection_snapshot_pin_reorder_failed');
    }
    ++$assertions;
    $feedbackResult = $service->setFeedback(1, 'FULL', 'HOME', 'ALBUM', 'synthetic-album', 'LESS_LIKE', $current);
    if (($feedbackResult['feedback'] ?? null) !== 'LESS_LIKE') {
        collectionSnapshotFail('collection_snapshot_feedback_failed');
    }
    ++$assertions;
    $activeFeedback = $service->activeFeedback(1, 'FULL', $current);
    if (count($activeFeedback) !== 1
        || ($activeFeedback[0]['projectionKind'] ?? null) !== 'HOME'
        || ($activeFeedback[0]['itemKind'] ?? null) !== 'ALBUM'
        || ($activeFeedback[0]['itemKey'] ?? null) !== 'synthetic-album'
        || ($activeFeedback[0]['feedback'] ?? null) !== 'LESS_LIKE'
        || (($activeFeedback[0]['item']['payload']['title'] ?? null) !== '合成相册 v2')) {
        collectionSnapshotFail('collection_snapshot_active_feedback_projection_failed');
    }
    ++$assertions;
    if ($service->activeFeedback(1, 'FULL', static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => null) !== []) {
        collectionSnapshotFail('collection_snapshot_active_feedback_acl_leak');
    }
    ++$assertions;
    $claim = $service->claimMaintenance('NIGHTLY_COLLECTIONS_FULL', hash('sha256', 'maintenance'));
    if (($claim['claimed'] ?? null) !== true || !$service->completeMaintenance('NIGHTLY_COLLECTIONS_FULL', (string) $second['snapshotId'])['completed']) {
        collectionSnapshotFail('collection_snapshot_maintenance_failed');
    }
    $unchanged = $service->claimMaintenance('NIGHTLY_COLLECTIONS_FULL', hash('sha256', 'maintenance'));
    if (($unchanged['claimed'] ?? null) !== false || ($unchanged['state'] ?? null) !== 'COMPLETE') {
        collectionSnapshotFail('collection_snapshot_maintenance_idempotence_failed');
    }
    ++$assertions;
    collectionSnapshotRejected($db, "INSERT INTO {$pin} (`pin_id`,`principal_id`,`scope`,`projection_kind`,`item_kind`,`item_key`,`ordinal`,`state`) VALUES (UNHEX('30000000000040008000000000000001'),1,'FULL','HOME','ALBUM','synthetic-album',99,'ACTIVE')", 'pin_active_target_unique');
    ++$assertions;
    collectionSnapshotRejected($db, "INSERT INTO {$feedback} (`feedback_id`,`principal_id`,`scope`,`projection_kind`,`item_kind`,`item_key`,`feedback_kind`,`state`) VALUES (UNHEX('40000000000040008000000000000001'),1,'FULL','HOME','ALBUM','synthetic-album','HIDE','ACTIVE')", 'feedback_active_target_unique');
    ++$assertions;
    if (!$service->clearFeedback(1, 'FULL', 'HOME', 'ALBUM', 'synthetic-album')['retracted']) {
        collectionSnapshotFail('collection_snapshot_feedback_retract_failed');
    }
    ++$assertions;
    collectionSnapshotExecute($db, "INSERT INTO {$maintenance} (`maintenance_key`,`state`) VALUES ('VALID_IDLE','IDLE')");
    collectionSnapshotRejected($db, "INSERT INTO {$maintenance} (`maintenance_key`,`state`) VALUES ('INVALID_RUNNING','RUNNING')", 'maintenance_running_timestamp');
    ++$assertions;
    try {
        $service->publish('FULL', 'HOME', hash('sha256', 'bad-payload'), [[
            'itemKind' => 'ALBUM',
            'itemKey' => 'invalid-payload',
            'photoIds' => [$photoOne],
            'payload' => ['ownerPrincipalId' => 1],
        ]]);
        collectionSnapshotFail('collection_snapshot_sensitive_payload_not_rejected');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'class_archive_collection_snapshot_payload_sensitive_key') {
            throw $error;
        }
    }
    ++$assertions;

    // A Collections-first refresh changes HOME, MEMORY, SPOTLIGHT and
    // SEARCH_SUGGESTION together.  Prove that an all-kind publish is
    // idempotent and that a database failure while writing one candidate
    // cannot leave a partially switched pointer bundle behind.
    $bundleRevision = hash('sha256', 'collection-bundle-one');
    $bundleItems = [
        'HOME' => [[
            'itemKind' => 'ALBUM', 'itemKey' => 'bundle-home', 'coverPhotoId' => $photoOne,
            'photoIds' => [$photoOne], 'payload' => ['title' => '精选集'],
        ]],
        'MEMORY' => [[
            'itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'bundle-memory', 'coverPhotoId' => $photoOne,
            'photoIds' => [$photoOne], 'payload' => ['title' => '合成回忆'],
        ]],
        'SPOTLIGHT' => [[
            'itemKind' => 'SPOTLIGHT', 'itemKey' => 'bundle-spotlight', 'coverPhotoId' => $photoOne,
            'photoIds' => [$photoOne], 'payload' => ['title' => '今日精选'],
        ]],
        'SEARCH_SUGGESTION' => [[
            'itemKind' => 'SEARCH_SUGGESTION', 'itemKey' => 'bundle-suggestion',
            'photoIds' => [], 'payload' => ['title' => '毕业'],
        ]],
    ];
    $bundle = $service->publishBundle('FULL', $bundleRevision, $bundleItems);
    $bundleState = $service->state('FULL');
    if (count($bundle) !== 4 || count((array) ($bundleState['items'] ?? [])) !== 4) {
        collectionSnapshotFail('collection_snapshot_bundle_publish_failed');
    }
    foreach (['HOME', 'MEMORY', 'SPOTLIGHT', 'SEARCH_SUGGESTION'] as $kind) {
        if (($bundle[$kind]['published'] ?? null) !== true
            || (($bundle[$kind]['revision'] ?? null) !== $bundleRevision)) {
            collectionSnapshotFail('collection_snapshot_bundle_revision_failed');
        }
    }
    ++$assertions;
    $bundleAgain = $service->publishBundle('FULL', $bundleRevision, $bundleItems);
    foreach ($bundleAgain as $kind => $result) {
        if (($result['published'] ?? null) !== false
            || (($result['snapshotId'] ?? null) !== ($bundle[$kind]['snapshotId'] ?? null))) {
            collectionSnapshotFail('collection_snapshot_bundle_idempotence_failed');
        }
    }
    ++$assertions;

    $beforeBrokenBundle = $service->state('FULL');
    $brokenBundleItems = $bundleItems;
    $brokenBundleItems['MEMORY'][0]['coverPhotoId'] = '90000000-0000-4000-8000-000000000001';
    $brokenBundleItems['MEMORY'][0]['photoIds'] = ['90000000-0000-4000-8000-000000000001'];
    try {
        $service->publishBundle('FULL', hash('sha256', 'collection-bundle-broken'), $brokenBundleItems);
        collectionSnapshotFail('collection_snapshot_bundle_failure_not_rejected');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_identity_expected_result_set'
            && $error->getMessage() !== 'class_identity_statement_execute_failed_1452'
            && $error->getMessage() !== 'class_identity_query_execute_failed_1452') {
            throw $error;
        }
    }
    if ($service->state('FULL') !== $beforeBrokenBundle) {
        collectionSnapshotFail('collection_snapshot_bundle_atomic_rollback_failed');
    }
    ++$assertions;

    fwrite(STDOUT, 'COLLECTION_SNAPSHOT_SCHEMA=PASS assertions=' . $assertions . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'COLLECTION_SNAPSHOT_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_reverse(array_unique($created)) as $table) {
        $db->query('DROP TABLE IF EXISTS ' . collectionSnapshotIdent($table));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($ci) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $count = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($count !== 0) {
        fwrite(STDERR, 'COLLECTION_SNAPSHOT_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $count . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
