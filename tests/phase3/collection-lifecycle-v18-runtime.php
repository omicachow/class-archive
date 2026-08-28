<?php

declare(strict_types=1);

/**
 * Disposable V18 MariaDB proof for the server-owned Collections lifecycle.
 *
 * It executes only in the explicit attempt12-style V18 synthetic runtime.
 * The production-like lifecycle is invoked against that lab once; all
 * adversarial watermark and weekly publication assertions use a random table
 * prefix which is removed in finally. No browser input, principal, media
 * mount, Owner runtime, or private fixture is used.
 */

function collectionLifecycleV18Fail(string $message): never
{
    throw new RuntimeException($message);
}

function collectionLifecycleV18Identifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $identifier) !== 1) {
        collectionLifecycleV18Fail('collection_lifecycle_v18_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function collectionLifecycleV18Execute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        collectionLifecycleV18Fail('collection_lifecycle_v18_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed>|null */
function collectionLifecycleV18One(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        collectionLifecycleV18Fail('collection_lifecycle_v18_query_failed_' . $db->errno);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

/** @return array{count:int,fingerprint:string} */
function collectionLifecycleV18AiJobFingerprint(mysqli $db, string $table): array
{
    $row = collectionLifecycleV18One(
        $db,
        'SELECT COUNT(*) AS `count`,COALESCE(SUM(CRC32(CONCAT_WS(\'|\',HEX(`job_id`),`state`,`attempt_count`,'
            . "COALESCE(DATE_FORMAT(`updated_at`,'%Y-%m-%d %H:%i:%s.%f'),''),COALESCE(DATE_FORMAT(`completed_at`,'%Y-%m-%d %H:%i:%s.%f'),'')))),0) AS `digest` "
            . 'FROM ' . collectionLifecycleV18Identifier($table),
    );
    return [
        'count' => (int) ($row['count'] ?? -1),
        'fingerprint' => (string) ($row['digest'] ?? ''),
    ];
}

/** @return array<string,string> */
function collectionLifecycleV18SnapshotIds(\ClassIdentity\CollectionSnapshotService $snapshots, string $scope): array
{
    $state = $snapshots->state($scope);
    $result = [];
    foreach ((array) ($state['items'] ?? []) as $item) {
        if (!is_array($item) || !is_string($item['projectionKind'] ?? null)
            || !is_string($item['snapshotId'] ?? null) || !is_string($item['revision'] ?? null)
            || ($item['state'] ?? null) !== 'ACTIVE') {
            collectionLifecycleV18Fail('collection_lifecycle_v18_snapshot_state_invalid');
        }
        $result[$item['projectionKind']] = $item['snapshotId'];
    }
    ksort($result, SORT_STRING);
    if (count($result) !== 4) {
        collectionLifecycleV18Fail('collection_lifecycle_v18_snapshot_bundle_incomplete');
    }
    return $result;
}

/** @return bool */
function collectionLifecycleV18SafeStatus(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $entry) {
        if (!is_string($key)) {
            return false;
        }
        $lower = strtolower($key);
        foreach (['snapshotid', 'photoid', 'principal', 'account', 'seat', 'identity', 'path', 'filename', 'token', 'secret', 'error'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return false;
            }
        }
        if (is_array($entry) && !collectionLifecycleV18SafeStatus($entry)) {
            return false;
        }
    }
    return true;
}

if (PHP_SAPI !== 'cli' || getenv('CLASS_ARCHIVE_V18_SYNTHETIC_PROOF') !== '1'
    || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION'
    || getenv('CLASS_ARCHIVE_V18_RUNTIME_PROOF') !== '1'
    || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "COLLECTION_LIFECYCLE_V18_RUNTIME=FAIL reason=isolated_v18_scope_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "COLLECTION_LIFECYCLE_V18_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
chdir(PHPWG_ROOT_PATH) || exit(1);
$_SERVER['SCRIPT_NAME'] = '/ws.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require PHPWG_ROOT_PATH . 'include/common.inc.php';
ob_end_clean();

$root = dirname(__DIR__, 2);
// The isolated V18 lab mounts the repository at /workspace without installing
// the development plugin into Piwigo's immutable image.  Keep the runtime
// proof on the real Piwigo bootstrap, but deliberately load the checked-out
// plugin source through its explicit path rather than assuming a writable
// production plugin mount.
defined('CLASS_IDENTITY_PATH') or define('CLASS_IDENTITY_PATH', $root . '/plugins/ClassIdentity/');
require_once $root . '/plugins/ClassIdentity/main.inc.php';
require_once $root . '/infra/scripts/warm-photo-cache.php';
define('CLASS_ARCHIVE_MAINTENANCE_LIBRARY_ONLY', true);
require $root . '/infra/scripts/run-maintenance.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

global $mysqli, $prefixeTable;
if (!$mysqli instanceof mysqli || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "COLLECTION_LIFECYCLE_V18_RUNTIME=FAIL reason=piwigo_database_unavailable\n");
    exit(1);
}
$db = $mysqli;
if (!$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "COLLECTION_LIFECYCLE_V18_RUNTIME=FAIL reason=utf8mb4_unavailable\n");
    exit(1);
}

$run = strtolower(bin2hex(random_bytes(6)));
// Keep the V18 collection snapshot pointer name under MariaDB's 64-byte cap.
$basePrefix = 'ci_cl_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$assertions = 0;
$exit = 0;
$createdNative = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        collectionLifecycleV18Fail($message);
    }
};

try {
    $v18Table = $prefixeTable . 'class_identity_spotlight_rotation_state';
    $v18Row = collectionLifecycleV18One(
        $db,
        "SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . $db->real_escape_string($v18Table) . "'",
    );
    $assert((int) ($v18Row['count'] ?? 0) === 1 && \ClassIdentity\Schema::CURRENT_VERSION === 18, 'v18_schema_not_active');

    // This is the actual runner-owned lifecycle against the disposable V18
    // migration runtime.  It receives only an internal UTC test seam; no
    // request/global browser value reaches calendar scheduling.
    $aiJobsTable = $prefixeTable . 'class_identity_ai_index_job';
    $globalAiBefore = collectionLifecycleV18AiJobFingerprint($db, $aiJobsTable);
    $globalAt = new DateTimeImmutable('2034-06-17 04:05:06.000000', new DateTimeZone('UTC'));
    $globalCycle = collectionLifecycleRun($globalAt);
    $assert(in_array($globalCycle['result'] ?? null, ['PASS', 'REVIEW_REQUIRED'], true)
        && ($globalCycle['version'] ?? null) === CLASS_ARCHIVE_COLLECTION_LIFECYCLE_VERSION
        && ($globalCycle['clock'] ?? null) === 'UTC'
        && is_array($globalCycle['nightly'] ?? null) && is_array($globalCycle['weekly'] ?? null) && is_array($globalCycle['monthly'] ?? null), 'global_lifecycle_result_invalid');
    $globalAiAfter = collectionLifecycleV18AiJobFingerprint($db, $aiJobsTable);
    $assert($globalAiAfter === $globalAiBefore, 'global_lifecycle_created_or_mutated_ai_job');

    $photoTable = $ci . 'photo';
    $principalTable = $ci . 'principal';
    foreach ([$photoTable, $principalTable] as $table) {
        collectionLifecycleV18Identifier($table);
    }
    collectionLifecycleV18Execute($db, 'CREATE TABLE ' . collectionLifecycleV18Identifier($photoTable) . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL,PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    collectionLifecycleV18Execute($db, 'CREATE TABLE ' . collectionLifecycleV18Identifier($principalTable) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL,PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection'))->invoke($schema);
    $createdNative = classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots'))->invoke($schema);
    $repository = new \ClassIdentity\Repository($db, $basePrefix);
    $store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
    $snapshots = new \ClassIdentity\CollectionSnapshotService($repository);

    $heritageId = '10000000-0000-4000-8000-000000000001';
    $livingId = '10000000-0000-4000-8000-000000000002';
    collectionLifecycleV18Execute($db, 'INSERT INTO ' . collectionLifecycleV18Identifier($photoTable)
        . " (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')),(UNHEX('10000000000040008000000000000002'))");
    collectionLifecycleV18Execute($db, 'INSERT INTO ' . collectionLifecycleV18Identifier($principalTable) . ' (`id`) VALUES (1)');
    $heritage = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '合成历史照片', '2023-10-18', ['合成相册'], '历史 合成', 940001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101],
    );
    $living = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $livingId, 'LIVING', 'ACTIVE', 'ACTIVE', '合成动态照片', null, ['合成相册'], '动态 合成', 940002, 'UNKNOWN', 'UNKNOWN', null, [101],
    );
    $catalog = $store->rebuildPhotos([$heritage, $living], false, $store->beginPhotoCatalogBuild());
    $assert(($catalog['count'] ?? null) === 2, 'fixture_catalog_publish_failed');

    $heritagePhoto = $heritage->publicProjection();
    $livingPhoto = $living->publicProjection();
    $payloads = [
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => [
                'total' => 2,
                'groups' => [
                    ['key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH', 'total' => 1, 'items' => [$heritagePhoto]],
                    ['key' => 'unknown', 'label' => '日期未知', 'kind' => 'UNKNOWN', 'total' => 1, 'items' => [$livingPhoto]],
                ],
            ],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 1, 'items' => [[
                'id' => '30000000-0000-4000-8000-000000000001', 'name' => '合成相册', 'displayAlias' => '合成班级相册', 'total' => 2,
                'coverPhotoId' => $heritageId, 'photo_ids' => [$heritageId, $livingId], 'sourceLabel' => '合成来源', 'eventLabel' => '合成活动', 'dateLabel' => '班级历史',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => [
                'total' => 1,
                'groups' => [
                    ['key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH', 'total' => 1, 'items' => [$heritagePhoto]],
                ],
            ],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 1, 'items' => [[
                'id' => '30000000-0000-4000-8000-000000000001', 'name' => '合成相册', 'displayAlias' => '合成班级相册', 'total' => 1,
                'coverPhotoId' => $heritageId, 'photo_ids' => [$heritageId], 'sourceLabel' => '合成来源', 'eventLabel' => '合成活动', 'dateLabel' => '班级历史',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
    ];
    $kinds = [
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
    ];
    $aggregate = $store->rebuildAggregates($payloads, $kinds, $store->beginAggregateBuild($kinds));
    $assert(($aggregate['changed'] ?? null) === true && count((array) ($aggregate['changed_kinds'] ?? [])) === 5, 'fixture_aggregate_publish_failed');

    $fullEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
    $heritageEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE);
    $homeFull = [
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-a', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐甲']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-b', 'coverPhotoId' => $livingId, 'photoIds' => [$livingId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐乙']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-c', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐丙']],
        ['itemKind' => 'PHOTO', 'itemKey' => 'stable-photo', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'ALBUM', 'title' => '固定项目']],
    ];
    $homeHeritage = [
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-a', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐甲']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-b', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐乙']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'weekly-c', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'RECOMMENDATION', 'title' => '合成推荐丙']],
        ['itemKind' => 'PHOTO', 'itemKey' => 'stable-photo', 'coverPhotoId' => $heritageId, 'photoIds' => [$heritageId], 'payload' => ['section' => 'ALBUM', 'title' => '固定项目']],
    ];
    $emptyBundle = [
        \ClassIdentity\CollectionSnapshotService::KIND_MEMORY => [],
        \ClassIdentity\CollectionSnapshotService::KIND_SPOTLIGHT => [],
        \ClassIdentity\CollectionSnapshotService::KIND_SEARCH_SUGGESTION => [],
    ];
    $snapshots->publishBundle(\ClassIdentity\CollectionSnapshotService::SCOPE_FULL, $fullEpoch, [\ClassIdentity\CollectionSnapshotService::KIND_HOME => $homeFull] + $emptyBundle);
    $snapshots->publishBundle(\ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY, $heritageEpoch, [\ClassIdentity\CollectionSnapshotService::KIND_HOME => $homeHeritage] + $emptyBundle);
    $beforeFull = collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_FULL);
    $beforeHeritage = collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY);

    $weeklyAt = new DateTimeImmutable('2034-06-17 04:05:06.000000', new DateTimeZone('UTC'));
    $weekly = collectionLifecycleExecute(
        $snapshots,
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY,
        $weeklyAt,
        static fn(): array => collectionLifecycleWeeklyRecommendationRefresh(
            $snapshots,
            $store,
            collectionLifecycleSchedule(CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY, $weeklyAt)['window'],
        ),
    );
    $afterFull = collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_FULL);
    $afterHeritage = collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY);
    $weeklyScopes = $weekly['details']['scopes'] ?? null;
    $assert(($weekly['state'] ?? null) === 'COMPLETE' && ($weekly['result'] ?? null) === 'PASS' && ($weekly['performed'] ?? null) === true
        && is_array($weeklyScopes) && count($weeklyScopes) === 2
        && array_reduce($weeklyScopes, static fn(bool $carry, array $scope): bool => $carry && ($scope['published'] ?? null) === true && ($scope['rotated'] ?? null) === true && ($scope['recommendation_count'] ?? null) === 3, true), 'weekly_rotation_not_published');
    $assert(count(array_diff_assoc($beforeFull, $afterFull)) === 4 && count(array_diff_assoc($beforeHeritage, $afterHeritage)) === 4, 'weekly_not_atomic_four_kind_publish');
    $weeklyAgain = collectionLifecycleExecute($snapshots, CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY, $weeklyAt, static fn(): array => ['unexpected' => true]);
    $assert(($weeklyAgain['state'] ?? null) === 'CURRENT' && ($weeklyAgain['result'] ?? null) === 'PASS' && ($weeklyAgain['performed'] ?? null) === false
        && collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_FULL) === $afterFull
        && collectionLifecycleV18SnapshotIds($snapshots, \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY) === $afterHeritage, 'weekly_window_not_idempotent');

    $proofKey = 'COLLECTION_PROOF_' . strtoupper($run);
    $proofRevision = hash('sha256', 'collection-lifecycle-v18-proof:' . $run, true);
    $claimed = $snapshots->claimMaintenance($proofKey, $proofRevision);
    $running = $snapshots->claimMaintenance($proofKey, $proofRevision);
    $assert(($claimed['claimed'] ?? null) === true && ($claimed['state'] ?? null) === 'RUNNING'
        && ($running['claimed'] ?? null) === false && ($running['state'] ?? null) === 'RUNNING', 'maintenance_claim_running_contract_failed');
    $failed = $snapshots->failMaintenance($proofKey, 'COLLECTION_PROOF_FAILED');
    $failedRow = collectionLifecycleV18One($db, 'SELECT `state`,`last_error_code` FROM ' . collectionLifecycleV18Identifier($ci . 'collection_maintenance_state')
        . " WHERE `maintenance_key`='" . $db->real_escape_string($proofKey) . "'");
    $reclaimed = $snapshots->claimMaintenance($proofKey, $proofRevision);
    $completed = $snapshots->completeMaintenance($proofKey);
    $current = $snapshots->claimMaintenance($proofKey, $proofRevision);
    $assert(($failed['failed'] ?? null) === true && ($failedRow['state'] ?? null) === 'FAILED' && ($failedRow['last_error_code'] ?? null) === 'COLLECTION_PROOF_FAILED'
        && ($reclaimed['claimed'] ?? null) === true && ($completed['completed'] ?? null) === true
        && ($current['claimed'] ?? null) === false && ($current['state'] ?? null) === 'COMPLETE', 'maintenance_claim_complete_failed_contract_failed');

    $failureAt = new DateTimeImmutable('2034-06-18 04:05:06.000000', new DateTimeZone('UTC'));
    $failedExecute = collectionLifecycleExecute($snapshots, CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY, $failureAt, static function (): array {
        throw new RuntimeException('synthetic_test_failure');
    });
    $assert(($failedExecute['state'] ?? null) === 'FAILED' && ($failedExecute['result'] ?? null) === 'REVIEW_REQUIRED'
        && (($failedExecute['details']['reason'] ?? null) === 'COLLECTION_NIGHTLY_FAILED'), 'maintenance_execute_failed_state_missing');

    // The monthly audit touches global AutoCollection/AiIndex control-plane
    // reports only. It must never enqueue, retry or mutate an AI job.
    $monthlyAiBefore = collectionLifecycleV18AiJobFingerprint($db, $aiJobsTable);
    $monthlyAt = new DateTimeImmutable('2034-07-01 04:05:06.000000', new DateTimeZone('UTC'));
    $monthly = collectionLifecycleExecute(
        $snapshots,
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_MONTHLY,
        $monthlyAt,
        static fn(): array => collectionLifecycleMonthlyAudit($snapshots, $store),
    );
    $monthlyAiAfter = collectionLifecycleV18AiJobFingerprint($db, $aiJobsTable);
    $assert(in_array($monthly['state'] ?? null, ['COMPLETE', 'FAILED'], true)
        && in_array($monthly['result'] ?? null, ['PASS', 'REVIEW_REQUIRED'], true)
        && $monthlyAiAfter === $monthlyAiBefore, 'monthly_audit_mutated_ai_job');

    // Exercise the complete maintenance wrapper too.  This uses the current
    // synthetic-only lab clock and may surface ordinary infra attention, but
    // its lifecycle output must be bounded System Health data and not a
    // browser-triggered source rebuild.
    $maintenance = null;
    $maintenanceFailure = null;
    try {
        $maintenance = maintenanceRun(false);
    } catch (Throwable $maintenanceError) {
        // attempt12 is deliberately database-only and has no trusted media
        // mount. The full wrapper therefore reaches its existing derivative
        // warm-up boundary and must fail closed rather than treating absent
        // media as cacheable. This remains a real invocation of the wrapper;
        // direct lifecycle evidence above remains independent of media.
        $maintenanceFailure = $maintenanceError->getMessage();
    }
    $lifecycleStatus = is_array($maintenance) ? ($maintenance['tasks']['collection_lifecycle'] ?? null) : null;
    $afterMaintenanceAi = collectionLifecycleV18AiJobFingerprint($db, $aiJobsTable);
    if (is_array($maintenance)) {
        $assert(is_array($lifecycleStatus) && collectionLifecycleV18SafeStatus($lifecycleStatus)
            && $afterMaintenanceAi === $monthlyAiAfter, 'maintenance_wrapper_lifecycle_or_ai_contract_failed');
        $maintenanceMode = 'STRUCTURED';
    } else {
        $assert($maintenanceFailure === 'photo_cache_file_untrusted'
            && $afterMaintenanceAi === $monthlyAiAfter, 'maintenance_wrapper_not_fail_closed_in_medialess_lab');
        $maintenanceMode = 'FAIL_CLOSED_MEDIALESS_LAB';
    }

    fwrite(STDOUT, "COLLECTION_LIFECYCLE_V18_RUNTIME=PASS assertions={$assertions} maintenance_wrapper={$maintenanceMode} run={$run}\n");
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'COLLECTION_LIFECYCLE_V18_RUNTIME=FAIL run=' . $run . ' reason='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $like = $db->real_escape_string($ci) . '%';
    $tables = $db->query("SELECT `TABLE_NAME` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $names = [];
    if ($tables instanceof mysqli_result) {
        while ($row = $tables->fetch_assoc()) {
            if (is_array($row) && is_string($row['TABLE_NAME'] ?? null)) {
                $names[] = $row['TABLE_NAME'];
            }
        }
        $tables->free();
    }
    foreach (array_reverse($names) as $name) {
        $db->query('DROP TABLE IF EXISTS ' . collectionLifecycleV18Identifier($name));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'COLLECTION_LIFECYCLE_V18_RUNTIME_CLEANUP=FAIL run=' . $run . ' reason='
                . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n");
            $exit = 1;
        }
    }
    $remaining = collectionLifecycleV18One($db, "SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    if ((int) ($remaining['count'] ?? -1) !== 0) {
        fwrite(STDERR, "COLLECTION_LIFECYCLE_V18_RUNTIME_CLEANUP=FAIL run={$run} remaining=" . (int) ($remaining['count'] ?? -1) . "\n");
        $exit = 1;
    } elseif ($exit === 0) {
        fwrite(STDOUT, "COLLECTION_LIFECYCLE_V18_RUNTIME_CLEANUP=PASS run={$run}\n");
    }
}

exit($exit);
