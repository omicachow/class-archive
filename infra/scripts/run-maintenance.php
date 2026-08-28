<?php

declare(strict_types=1);

/**
 * Class Archive maintenance runner.
 *
 * It is intentionally suitable for an interactive Windows wrapper, cron, or a
 * future container scheduler. It contains no credentials, accepts no file
 * paths, and defaults to read-only checks. The optional rejected-binary
 * cleanup only processes explicitly aged REJECTED submission references.
 * Server-expired Spotlight rows are the other narrow mutation: their exact
 * UTC deadline and EXPIRED target state make the operation deterministic,
 * idempotent and audit-backed.
 */

const CLASS_ARCHIVE_MAINTENANCE_ROOT = '/var/www/html/piwigo';

/** @return array{json:bool,apply_rejected_cleanup:bool,require_ready:bool} */
function maintenanceArguments(array $argv): array
{
    $arguments = ['json' => false, 'apply_rejected_cleanup' => false, 'require_ready' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--json') {
            $arguments['json'] = true;
            continue;
        }
        if ($argument === '--apply-rejected-cleanup') {
            $arguments['apply_rejected_cleanup'] = true;
            continue;
        }
        if ($argument === '--require-ready') {
            $arguments['require_ready'] = true;
            continue;
        }
        throw new InvalidArgumentException('maintenance_argument_invalid');
    }
    return $arguments;
}

function maintenancePrepareRuntime(): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('maintenance_cli_required');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('maintenance_refuses_root');
    }
    if (realpath(CLASS_ARCHIVE_MAINTENANCE_ROOT) !== CLASS_ARCHIVE_MAINTENANCE_ROOT || is_link(CLASS_ARCHIVE_MAINTENANCE_ROOT)) {
        throw new RuntimeException('maintenance_root_untrusted');
    }
    if (!is_file(CLASS_ARCHIVE_MAINTENANCE_ROOT . '/local/config/database.inc.php')) {
        throw new RuntimeException('maintenance_piwigo_unavailable');
    }
    chdir(CLASS_ARCHIVE_MAINTENANCE_ROOT) || throw new RuntimeException('maintenance_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/** @return array{state:string,label:string,message:string,timestamp:?string,age_seconds:?int,verified_files:int} */
function backupFreshnessStatus(): array
{
    $path = PHPWG_ROOT_PATH . '_data/class-archive/backup-freshness.json';
    if (!is_file($path) || is_link($path)) {
        return ['state' => 'MISSING', 'label' => '未找到', 'message' => '尚未执行私有备份校验。', 'timestamp' => null, 'age_seconds' => null, 'verified_files' => 0];
    }
    $contents = @file_get_contents($path);
    try {
        $record = is_string($contents) ? json_decode($contents, true, 16, JSON_THROW_ON_ERROR) : null;
    } catch (Throwable) {
        $record = null;
    }
    $state = is_array($record) ? ($record['state'] ?? null) : null;
    if (!is_array($record)
        || (int) ($record['backup_audit_version'] ?? 0) !== 1
        || !in_array($state, ['FRESH', 'STALE', 'INVALID', 'MISSING'], true)
        || !is_string($record['timestamp'] ?? null)
        || strtotime((string) $record['timestamp']) === false
        || !is_int($record['age_seconds'] ?? null)
        || !is_int($record['verified_files'] ?? null)) {
        return ['state' => 'INVALID', 'label' => '无效', 'message' => '私有备份校验记录无效。', 'timestamp' => null, 'age_seconds' => null, 'verified_files' => 0];
    }
    $labels = ['FRESH' => '正常', 'STALE' => '需要备份', 'INVALID' => '无效', 'MISSING' => '未找到'];
    $messages = ['FRESH' => '最近完整备份已由隔离审计器校验。', 'STALE' => '最近完整备份已超过七天。', 'INVALID' => '私有备份校验失败。', 'MISSING' => '没有完整的备份包。'];
    return [
        'state' => $state,
        'label' => $labels[$state],
        'message' => $messages[$state],
        'timestamp' => is_string($record['backup_timestamp'] ?? null) ? $record['backup_timestamp'] : null,
        'age_seconds' => (int) $record['age_seconds'],
        'verified_files' => (int) $record['verified_files'],
    ];
}

/** @param list<array<string,mixed>> $projections */
function maintenanceProjectionState(array $projections, string $kind): string
{
    foreach ($projections as $projection) {
        if (is_array($projection) && ($projection['kind'] ?? null) === $kind) {
            $state = $projection['state'] ?? null;
            if (is_string($state) && in_array($state, ['ACTIVE', 'STALE', 'BUILDING', 'FAILED'], true)) {
                return $state;
            }
            break;
        }
    }
    throw new RuntimeException('maintenance_projection_state_unavailable');
}

/**
 * The builder has already published each pointer atomically. This compact
 * maintenance view deliberately reports only scope/kind counts and never a
 * snapshot id, input revision, source path, or photo identity.
 *
 * @param array<string,mixed> $build
 * @return array{result:string,scopes:list<array{scope:string,state:string,published:bool,kinds:array<string,int>}>}
 */
function collectionSnapshotMaintenanceState(array $build): array
{
    $rawScopes = $build['scopes'] ?? null;
    if (($build['result'] ?? null) !== 'PASS' || !is_array($rawScopes) || !array_is_list($rawScopes)) {
        return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
    }
    $expected = ['FULL' => true, 'HERITAGE_ONLY' => true];
    $scopes = [];
    foreach ($rawScopes as $scope) {
        if (!is_array($scope) || !is_string($scope['scope'] ?? null) || !isset($expected[$scope['scope']])) {
            return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
        }
        $state = (($scope['skipped'] ?? null) === 'CURRENT')
            ? 'CURRENT'
            : ((($scope['skipped'] ?? null) === 'RUNNING') ? 'RUNNING' : 'PUBLISHED');
        $kinds = $scope['kinds'] ?? null;
        if (!is_array($kinds) || array_is_list($kinds)) {
            return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
        }
        $counts = [];
        foreach ($kinds as $kind => $value) {
            if (!is_string($kind)) {
                return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
            }
            if (is_array($value)) {
                $value = $value['itemCount'] ?? null;
            }
            if (!is_int($value) || $value < 0) {
                return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
            }
            $counts[$kind] = $value;
        }
        if (count($counts) !== 4) {
            return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
        }
        $scopes[] = [
            'scope' => $scope['scope'],
            'state' => $state,
            'published' => ($scope['published'] ?? null) === true,
            'kinds' => $counts,
        ];
        unset($expected[$scope['scope']]);
    }
    if ($expected !== []) {
        return ['result' => 'REVIEW_REQUIRED', 'scopes' => []];
    }
    foreach ($scopes as $scope) {
        if ($scope['state'] === 'RUNNING') {
            return ['result' => 'REVIEW_REQUIRED', 'scopes' => $scopes];
        }
    }
    return ['result' => 'PASS', 'scopes' => $scopes];
}

/** @param array<string,mixed> $build */
function collectionSnapshotMaintenanceAttention(array $build): bool
{
    return collectionSnapshotMaintenanceState($build)['result'] !== 'PASS';
}

/*
 * Collections-first scheduling is deliberately owned by this maintenance
 * runner, rather than a browser route or an ambient request clock.  The
 * persisted collection_maintenance_state table supplies the idempotency
 * watermark for each UTC window; the process-wide lock below prevents two
 * runner processes from doing the same heavyweight work concurrently.
 *
 * Keep this policy here rather than in a web-facing domain service: changing
 * it changes MaintenanceStatus::runnerDigest(), so System Health cannot keep
 * presenting a record that was produced under a different schedule.
 */
const CLASS_ARCHIVE_COLLECTION_LIFECYCLE_VERSION = 1;
const CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY = 'NIGHTLY';
const CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY = 'WEEKLY';
const CLASS_ARCHIVE_COLLECTION_LIFECYCLE_MONTHLY = 'MONTHLY';

/** @return \DateTimeImmutable */
function collectionLifecycleServerNow(): \DateTimeImmutable
{
    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
}

/**
 * Return a fixed UTC scheduling window and opaque binary maintenance revision.
 * This pure seam accepts a clock only for the synthetic test harness; runtime
 * callers use collectionLifecycleServerNow() and never consume browser input.
 *
 * @return array{cadence:string,key:string,window:string,revision:string,label:string}
 */
function collectionLifecycleSchedule(string $cadence, \DateTimeImmutable $now): array
{
    $cadence = strtoupper(trim($cadence));
    $utc = $now->setTimezone(new \DateTimeZone('UTC'));
    $window = match ($cadence) {
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY => $utc->format('Y-m-d'),
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY => $utc->format('o-\\WW'),
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_MONTHLY => $utc->format('Y-m'),
        default => throw new \InvalidArgumentException('collection_lifecycle_cadence_invalid'),
    };
    $label = match ($cadence) {
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY => '每日投影刷新',
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY => '每周推荐整理',
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_MONTHLY => '每月集合健康检查',
        default => throw new \LogicException('collection_lifecycle_cadence_unreachable'),
    };
    return [
        'cadence' => $cadence,
        'key' => 'COLLECTION_LIFECYCLE_' . $cadence,
        'window' => $window,
        'revision' => hash(
            'sha256',
            "class-archive/collection-lifecycle/v" . CLASS_ARCHIVE_COLLECTION_LIFECYCLE_VERSION . "\0{$cadence}\0{$window}",
            true,
        ),
        'label' => $label,
    ];
}

/**
 * Claim a durable calendar window exactly once. A stale RUNNING row is never
 * force-reset: it is surfaced for manual review rather than allowing a second
 * process to overlap a half-finished projection publish.
 *
 * @param callable():array<string,mixed> $work
 * @return array{cadence:string,label:string,window:string,state:string,result:string,performed:bool,details:array<string,mixed>}
 */
function collectionLifecycleExecute(
    \ClassIdentity\CollectionSnapshotService $snapshots,
    string $cadence,
    \DateTimeImmutable $now,
    callable $work,
): array {
    $schedule = collectionLifecycleSchedule($cadence, $now);
    $claim = $snapshots->claimMaintenance($schedule['key'], $schedule['revision']);
    $state = (string) ($claim['state'] ?? '');
    if (($claim['claimed'] ?? null) !== true) {
        $safeState = match ($state) {
            'COMPLETE' => 'CURRENT',
            'RUNNING' => 'RUNNING',
            default => 'REVIEW_REQUIRED',
        };
        return [
            'cadence' => $schedule['cadence'],
            'label' => $schedule['label'],
            'window' => $schedule['window'],
            'state' => $safeState,
            'result' => $safeState === 'CURRENT' ? 'PASS' : 'REVIEW_REQUIRED',
            'performed' => false,
            'details' => [],
        ];
    }

    try {
        $details = $work();
        if (!is_array($details)) {
            throw new \RuntimeException('collection_lifecycle_work_result_invalid');
        }
        if (!$snapshots->completeMaintenance($schedule['key'])['completed']) {
            throw new \RuntimeException('collection_lifecycle_completion_failed');
        }
        return [
            'cadence' => $schedule['cadence'],
            'label' => $schedule['label'],
            'window' => $schedule['window'],
            'state' => 'COMPLETE',
            'result' => 'PASS',
            'performed' => true,
            'details' => $details,
        ];
    } catch (\Throwable) {
        // Error text can contain a database or implementation detail. Persist
        // and return only a bounded policy code; product state remains
        // fail-closed until the next explicit maintenance attempt succeeds.
        $errorCode = 'COLLECTION_' . $schedule['cadence'] . '_FAILED';
        try {
            $snapshots->failMaintenance($schedule['key'], $errorCode);
        } catch (\Throwable) {
            // The outer file lock plus a subsequent System Health stale state
            // still prevents this from being mistaken for a green run.
        }
        return [
            'cadence' => $schedule['cadence'],
            'label' => $schedule['label'],
            'window' => $schedule['window'],
            'state' => 'FAILED',
            'result' => 'REVIEW_REQUIRED',
            'performed' => true,
            'details' => ['reason' => $errorCode],
        ];
    }
}

/**
 * @param list<array<string,mixed>> $projections
 * @return array{active_projection_count:int,projection_count:int}
 */
function collectionLifecycleActiveProjectionSummary(array $projections): array
{
    $expected = [
        \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG => true,
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => true,
        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => true,
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => true,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => true,
        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => true,
    ];
    $active = 0;
    foreach ($projections as $projection) {
        if (!is_array($projection) || !is_string($projection['kind'] ?? null)
            || !isset($expected[$projection['kind']])
            || !is_string($projection['state'] ?? null)
            || !is_int($projection['count'] ?? null)
            || (int) $projection['count'] < 0
        ) {
            throw new \RuntimeException('collection_lifecycle_projection_status_invalid');
        }
        if ($projection['state'] !== 'ACTIVE') {
            throw new \RuntimeException('collection_lifecycle_projection_not_active');
        }
        ++$active;
        unset($expected[$projection['kind']]);
    }
    if ($expected !== [] || $active !== 6) {
        throw new \RuntimeException('collection_lifecycle_projection_status_incomplete');
    }
    return ['active_projection_count' => $active, 'projection_count' => 6];
}

/**
 * Load a complete active bundle strictly from immutable snapshot rows. The
 * returned values are internal maintenance material and are never placed in
 * System Health output; only caller-produced counts are exposed.
 *
 * @return array{revision:string,itemsByKind:array<string,list<array<string,mixed>>>}
 */
function collectionLifecycleActiveBundle(
    \ClassIdentity\CollectionSnapshotService $snapshots,
    \ClassIdentity\Gateway\ReadProjectionStore $store,
    string $snapshotScope,
): array {
    $readScope = match ($snapshotScope) {
        \ClassIdentity\CollectionSnapshotService::SCOPE_FULL => \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL,
        \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY => \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE,
        default => throw new \InvalidArgumentException('collection_lifecycle_scope_invalid'),
    };
    $before = $store->presentationEpoch($readScope);
    if (preg_match('/\A[a-f0-9]{64}\z/D', $before) !== 1) {
        throw new \RuntimeException('collection_lifecycle_projection_epoch_invalid');
    }
    $kinds = [
        \ClassIdentity\CollectionSnapshotService::KIND_HOME,
        \ClassIdentity\CollectionSnapshotService::KIND_MEMORY,
        \ClassIdentity\CollectionSnapshotService::KIND_SPOTLIGHT,
        \ClassIdentity\CollectionSnapshotService::KIND_SEARCH_SUGGESTION,
    ];
    $itemsByKind = [];
    foreach ($kinds as $kind) {
        $snapshot = $snapshots->activeSnapshot(
            $snapshotScope,
            $kind,
            static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $item->publicProjection($item->photoIds()),
        );
        if (!hash_equals($before, (string) ($snapshot['revision'] ?? ''))
            || !is_array($snapshot['items'] ?? null)
            || !array_is_list($snapshot['items'])
        ) {
            throw new \RuntimeException('collection_lifecycle_snapshot_bundle_invalid');
        }
        $items = [];
        foreach ($snapshot['items'] as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('collection_lifecycle_snapshot_bundle_invalid');
            }
            // `photoCount` is a read DTO convenience, not a snapshot input.
            unset($item['photoCount']);
            $items[] = $item;
        }
        $itemsByKind[$kind] = $items;
    }
    $after = $store->presentationEpoch($readScope);
    if (!hash_equals($before, $after)) {
        throw new \RuntimeException('collection_lifecycle_source_epoch_changed');
    }
    return ['revision' => $before, 'itemsByKind' => $itemsByKind];
}

/**
 * Rotate only the bounded recommendation slots of Home. Item keys, photo
 * membership, section positions and every non-recommendation card stay
 * intact. The weekly window determines an opaque deterministic offset, so a
 * retry inside the same completed window cannot reorder a second time.
 *
 * @param list<array<string,mixed>> $items
 * @return array{items:list<array<string,mixed>>,recommendation_count:int,rotated:bool}
 */
function collectionLifecycleRotateRecommendations(array $items, string $scope, string $window): array
{
    if (!in_array($scope, [
        \ClassIdentity\CollectionSnapshotService::SCOPE_FULL,
        \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY,
    ], true) || preg_match('/\A\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])\z/D', $window) !== 1) {
        throw new \InvalidArgumentException('collection_lifecycle_recommendation_input_invalid');
    }
    $positions = [];
    $recommendations = [];
    foreach ($items as $ordinal => $item) {
        if (!is_array($item)) {
            throw new \RuntimeException('collection_lifecycle_recommendation_item_invalid');
        }
        $payload = $item['payload'] ?? null;
        if (!is_array($payload) || !is_string($payload['section'] ?? null)) {
            throw new \RuntimeException('collection_lifecycle_recommendation_item_invalid');
        }
        if ($payload['section'] === 'RECOMMENDATION') {
            $positions[] = $ordinal;
            $recommendations[] = $item;
        }
    }
    $count = count($recommendations);
    if ($count < 2) {
        return ['items' => $items, 'recommendation_count' => $count, 'rotated' => false];
    }
    // The offset is always non-zero when two or more cards exist. It uses no
    // account, principal, feedback, browser or model signal.
    $offset = 1 + (hexdec(substr(hash(
        'sha256',
        "class-archive/collection-weekly-recommendation/v1\0{$scope}\0{$window}",
    ), 0, 8)) % ($count - 1));
    $rotated = array_merge(array_slice($recommendations, $offset), array_slice($recommendations, 0, $offset));
    foreach ($positions as $index => $ordinal) {
        $items[$ordinal] = $rotated[$index];
    }
    return ['items' => $items, 'recommendation_count' => $count, 'rotated' => true];
}

/** @return array{scopes:list<array{scope:string,recommendation_count:int,published:bool,rotated:bool}>} */
function collectionLifecycleWeeklyRecommendationRefresh(
    \ClassIdentity\CollectionSnapshotService $snapshots,
    \ClassIdentity\Gateway\ReadProjectionStore $store,
    string $window,
): array {
    $scopes = [];
    foreach ([
        \ClassIdentity\CollectionSnapshotService::SCOPE_FULL,
        \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY,
    ] as $scope) {
        $bundle = collectionLifecycleActiveBundle($snapshots, $store, $scope);
        $home = $bundle['itemsByKind'][\ClassIdentity\CollectionSnapshotService::KIND_HOME] ?? null;
        if (!is_array($home) || !array_is_list($home)) {
            throw new \RuntimeException('collection_lifecycle_home_snapshot_missing');
        }
        $rotation = collectionLifecycleRotateRecommendations($home, $scope, $window);
        $itemsByKind = $bundle['itemsByKind'];
        $itemsByKind[\ClassIdentity\CollectionSnapshotService::KIND_HOME] = $rotation['items'];
        $readScope = $scope === \ClassIdentity\CollectionSnapshotService::SCOPE_FULL
            ? \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL
            : \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE;
        if (!hash_equals($bundle['revision'], $store->presentationEpoch($readScope))) {
            throw new \RuntimeException('collection_lifecycle_source_epoch_changed');
        }
        $published = $snapshots->publishBundle($scope, $bundle['revision'], $itemsByKind);
        if (!is_array($published) || count($published) !== 4) {
            throw new \RuntimeException('collection_lifecycle_recommendation_publish_invalid');
        }
        if (!hash_equals($bundle['revision'], $store->presentationEpoch($readScope))) {
            // The pointer is retained but Gateway revision binding rejects it
            // until explicit maintenance builds a current replacement.
            throw new \RuntimeException('collection_lifecycle_source_epoch_changed');
        }
        $didPublish = false;
        foreach ($published as $result) {
            if (!is_array($result) || !is_bool($result['published'] ?? null)) {
                throw new \RuntimeException('collection_lifecycle_recommendation_publish_invalid');
            }
            $didPublish = $didPublish || $result['published'];
        }
        $scopes[] = [
            'scope' => $scope,
            'recommendation_count' => $rotation['recommendation_count'],
            'rotated' => $rotation['rotated'],
            'published' => $didPublish,
        ];
    }
    return ['scopes' => $scopes];
}

/**
 * A deliberately modest monthly audit: it verifies stored aggregate/snapshot
 * health and reports broad section diversity. It never invokes Piwigo source
 * enumeration, Immich, Face Detection, embeddings or Smart Search indexing.
 *
 * @return array{audit_result:string,scopes:list<array{scope:string,section_count:int,diversity_state:string}>,auto_collection_issues:int,ai_index_result:string}
 */
function collectionLifecycleMonthlyAudit(
    \ClassIdentity\CollectionSnapshotService $snapshots,
    \ClassIdentity\Gateway\ReadProjectionStore $store,
): array {
    collectionLifecycleActiveProjectionSummary($store->status());
    $scopeResults = [];
    foreach ([
        \ClassIdentity\CollectionSnapshotService::SCOPE_FULL,
        \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY,
    ] as $scope) {
        $bundle = collectionLifecycleActiveBundle($snapshots, $store, $scope);
        $home = $bundle['itemsByKind'][\ClassIdentity\CollectionSnapshotService::KIND_HOME] ?? null;
        if (!is_array($home) || !array_is_list($home)) {
            throw new \RuntimeException('collection_lifecycle_home_snapshot_missing');
        }
        $sections = [];
        foreach ($home as $item) {
            $section = is_array($item['payload'] ?? null) ? ($item['payload']['section'] ?? null) : null;
            if (!is_string($section) || !in_array($section, [
                'SPOTLIGHT', 'RECOMMENDATION', 'MEMORY', 'PINNED', 'ALBUM', 'PERSON', 'RECENT',
            ], true)) {
                throw new \RuntimeException('collection_lifecycle_home_section_invalid');
            }
            $sections[$section] = true;
        }
        $count = count($sections);
        $scopeResults[] = [
            'scope' => $scope,
            'section_count' => $count,
            // An empty or metadata-limited library is legitimate; it is a
            // content signal, never a request to invent dates or locations.
            'diversity_state' => $count >= 3 ? 'BALANCED' : 'LIMITED_METADATA',
        ];
    }
    $auto = \ClassIdentity\AutoCollectionService::fromPiwigo()->reconciliationReport();
    $issues = $auto['issues'] ?? null;
    if (!is_array($issues) || !array_is_list($issues)) {
        throw new \RuntimeException('collection_lifecycle_auto_collection_audit_invalid');
    }
    // maintenanceReport() is strictly read-only; it does not enqueue or run
    // AI jobs. Its detailed subjects stay out of this product-safe record.
    $ai = \ClassIdentity\AiIndexService::fromPiwigo()->maintenanceReport();
    $aiResult = $ai['result'] ?? null;
    if (!is_string($aiResult) || !in_array($aiResult, ['PASS', 'REVIEW_REQUIRED'], true)) {
        throw new \RuntimeException('collection_lifecycle_ai_audit_invalid');
    }
    // A completed watermark must never make a known integrity or AI review
    // finding disappear on the next runner invocation.  Leave this monthly
    // window FAILED (with a bounded error code from collectionLifecycleExecute)
    // so it remains fail-closed and is re-audited explicitly rather than
    // silently becoming CURRENT for the remainder of the calendar month.
    if (count($issues) !== 0 || $aiResult !== 'PASS') {
        throw new \RuntimeException('collection_lifecycle_monthly_audit_review_required');
    }
    return [
        'audit_result' => 'PASS',
        'scopes' => $scopeResults,
        'auto_collection_issues' => 0,
        'ai_index_result' => 'PASS',
    ];
}

/**
 * Run the durable Collections lifecycle. Every returned field is suitable for
 * the existing System Health maintenance record: cadence/window/count/state
 * only, never snapshot ids, source paths, photo ids, account ids or errors.
 *
 * @return array{result:string,version:int,clock:string,nightly:array<string,mixed>,weekly:array<string,mixed>,monthly:array<string,mixed>}
 */
function collectionLifecycleRun(?\DateTimeImmutable $now = null): array
{
    // Runtime callers intentionally omit this argument and use the server UTC
    // clock.  The nullable seam exists only for an isolated CLI/MariaDB
    // fixture to prove durable calendar idempotence without changing host
    // time or accepting any browser-controlled input.
    $now ??= collectionLifecycleServerNow();
    $snapshots = \ClassIdentity\CollectionSnapshotService::fromPiwigo();
    $store = \ClassIdentity\Gateway\ReadProjectionStore::fromPiwigo();

    $nightly = collectionLifecycleExecute(
        $snapshots,
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY,
        $now,
        static function (): array {
            // Explicit maintenance-only source refresh. Product GET paths do
            // not call this routine and it does not start any AI indexing.
            $build = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
            $snapshotBuild = $build['collection_snapshots'] ?? null;
            if (!is_array($snapshotBuild) || collectionSnapshotMaintenanceState($snapshotBuild)['result'] !== 'PASS') {
                throw new \RuntimeException('collection_lifecycle_snapshot_publish_unavailable');
            }
            $projections = $build['projections'] ?? null;
            if (!is_array($projections) || !array_is_list($projections)) {
                throw new \RuntimeException('collection_lifecycle_projection_build_invalid');
            }
            return [
                'projections' => collectionLifecycleActiveProjectionSummary($projections),
                'collection_snapshots' => collectionSnapshotMaintenanceState($snapshotBuild),
            ];
        },
    );

    $weekly = collectionLifecycleExecute(
        $snapshots,
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY,
        $now,
        static fn(): array => collectionLifecycleWeeklyRecommendationRefresh(
            $snapshots,
            $store,
            collectionLifecycleSchedule(CLASS_ARCHIVE_COLLECTION_LIFECYCLE_WEEKLY, $now)['window'],
        ),
    );

    $monthly = collectionLifecycleExecute(
        $snapshots,
        CLASS_ARCHIVE_COLLECTION_LIFECYCLE_MONTHLY,
        $now,
        static fn(): array => collectionLifecycleMonthlyAudit($snapshots, $store),
    );

    $monthlyAudit = is_array($monthly['details'] ?? null) ? ($monthly['details']['audit_result'] ?? null) : null;
    $result = (($nightly['result'] ?? null) === 'PASS'
        && ($weekly['result'] ?? null) === 'PASS'
        && ($monthly['result'] ?? null) === 'PASS'
        && (($monthlyAudit === null) || $monthlyAudit === 'PASS'))
        ? 'PASS'
        : 'REVIEW_REQUIRED';
    return [
        'result' => $result,
        'version' => CLASS_ARCHIVE_COLLECTION_LIFECYCLE_VERSION,
        'clock' => 'UTC',
        'nightly' => $nightly,
        'weekly' => $weekly,
        'monthly' => $monthly,
    ];
}

/** @return array<string, mixed> */
function maintenanceRun(bool $applyRejectedCleanup): array
{
    $retention = getenv('CLASS_ARCHIVE_REJECTED_RETENTION_DAYS');
    $retentionDays = is_string($retention) && ctype_digit($retention) ? (int) $retention : 30;
    $retentionDays = max(7, min(3650, $retentionDays));

    $directory = dirname(\ClassIdentity\MaintenanceStatus::lockPath());
    if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('maintenance_lock_directory_unavailable');
    }
    if (is_link($directory)) {
        throw new RuntimeException('maintenance_lock_directory_untrusted');
    }
    $lockPath = \ClassIdentity\MaintenanceStatus::lockPath();
    if (is_link($lockPath)) {
        throw new RuntimeException('maintenance_lock_untrusted');
    }
    $lock = @fopen($lockPath, 'c+b');
    if (!is_resource($lock)) {
        throw new RuntimeException('maintenance_lock_unavailable');
    }
    @chmod($lockPath, 0660);
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('maintenance_already_running');
        }
        $provisioning = \ClassIdentity\ProvisioningService::fromPiwigo()->expireDueFamilyInvitations();
        $expiredSpotlights = \ClassIdentity\SpotlightService::fromPiwigo()->expireDue();
        // Always repair/warm the tiny Spotlight projection after deadline
        // processing. This also recovers a prior create/cancel commit whose
        // post-commit rebuild was interrupted; reads remain fail-closed until
        // this explicit source rebuild succeeds.
        $spotlightProjection = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild(
            [\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT],
            false,
        );
        $collectionSnapshots = $spotlightProjection['collection_snapshots'] ?? null;
        if (!is_array($collectionSnapshots) || !in_array($collectionSnapshots['result'] ?? null, ['PASS', 'REVIEW_REQUIRED'], true)) {
            throw new RuntimeException('maintenance_collection_snapshot_build_unavailable');
        }
        // The narrow Spotlight repair above keeps an expired card from
        // lingering until a calendar boundary. The following lifecycle owns
        // the heavier nightly source refresh, weekly recommendation ordering,
        // and monthly read-only health audit through durable UTC watermarks.
        // No browser route can invoke or steer any of these operations.
        $collectionLifecycle = collectionLifecycleRun();
        $cleanup = \ClassIdentitySubmissionService::fromPiwigo()->cleanupRejectedBinaries($retentionDays, $applyRejectedCleanup);
        $reconciliation = \ClassIdentity\ReconciliationService::fromPiwigo()->scanAndPersist();
        // This is intentionally a read-only control-plane scan. It neither
        // enqueues a retry nor contacts the isolated Immich runtime: model
        // work remains explicit/background-only and product GETs never repair
        // a missing index on demand.
        $aiIndex = \ClassIdentity\AiIndexService::fromPiwigo()->maintenanceReport();
        // Precompute only bounded, canonical Piwigo derivatives. This is an
        // explicit maintenance action; product reads never call the warmer.
        $firstScreenWarmup = classArchivePhotoCacheWarm(
            'first-screen',
            ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'],
            false,
        );
        $coverWarmup = classArchivePhotoCacheWarm(
            'covers',
            ['medium', 'large', 'preview'],
            false,
        );
        // Durable recovery does not depend solely on a filesystem queue
        // enqueue succeeding. Scan every ACTIVE canonical mapping from trusted
        // database state during scheduled maintenance; fresh files are only
        // stat-checked and missing fixed profiles use the same Piwigo pipeline.
        $allRecoveryWarmup = classArchivePhotoCacheWarm(
            'all',
            ['square', 'thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'],
            false,
        );
        $attestation = \ClassIdentity\MediaAttestation::status();
        $backup = backupFreshnessStatus();
        $attention = (
            ($reconciliation['result'] ?? null) !== 'PASS'
            || ($aiIndex['result'] ?? null) !== 'PASS'
            || ($attestation['state'] ?? null) !== 'VERIFIED'
            || ($backup['state'] ?? null) !== 'FRESH'
            || collectionSnapshotMaintenanceAttention($collectionSnapshots)
            || ($collectionLifecycle['result'] ?? null) !== 'PASS'
            || (int) ($cleanup['failed'] ?? 0) > 0
        );
        $tasks = [
            'expired_invitations' => $provisioning,
            'expired_spotlights' => [
                'result' => 'PASS',
                'expired' => $expiredSpotlights,
                'automatic_scope' => 'SERVER_DEADLINE_ONLY',
                'projection_state' => maintenanceProjectionState(
                    $spotlightProjection['projections'] ?? [],
                    \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
                ),
            ],
            'collection_snapshots' => collectionSnapshotMaintenanceState($collectionSnapshots),
            // Deliberately contains only cadence/window/aggregate counts and
            // health states. System Health must never receive private source
            // paths, snapshot ids, photo ids, principal ids, or raw errors.
            'collection_lifecycle' => $collectionLifecycle,
            'rejected_binary_cleanup' => [
                'retention_days' => $retentionDays,
                'apply' => $applyRejectedCleanup,
                'eligible' => (int) ($cleanup['eligible'] ?? 0),
                'deleted' => (int) ($cleanup['deleted'] ?? 0),
                'missing' => (int) ($cleanup['missing'] ?? 0),
                'failed' => (int) ($cleanup['failed'] ?? 0),
            ],
            'reconciliation' => [
                'result' => (string) ($reconciliation['result'] ?? 'REVIEW_REQUIRED'),
                'issue_count' => (int) ($reconciliation['issue_count'] ?? 0),
                'checked_images' => (int) ($reconciliation['checked_images'] ?? 0),
            ],
            'ai_index' => [
                'result' => (string) ($aiIndex['result'] ?? 'REVIEW_REQUIRED'),
                'runtime_state' => (string) ($aiIndex['runtime_state'] ?? 'UNAVAILABLE'),
                'open_jobs' => (int) ($aiIndex['open_jobs'] ?? 0),
                'missing_index_rows' => (int) ($aiIndex['missing_index_rows'] ?? 0),
                'checksum_drift' => (int) ($aiIndex['checksum_drift'] ?? 0),
                'failed_assets' => (int) ($aiIndex['failed_assets'] ?? 0),
                'failed_jobs' => (int) ($aiIndex['failed_jobs'] ?? 0),
                'worker_configured' => (bool) ($aiIndex['worker_configured'] ?? false),
                'safe_auto_fix' => false,
            ],
            'media_permission_verification' => [
                'derivative_files' => (int) (($reconciliation['derivative']['file_count'] ?? 0)),
                'unsafe_entries' => count($reconciliation['derivative']['unsafe_entries'] ?? []),
            ],
            'photo_derivative_warmup' => [
                'result' => 'PASS',
                'first_screen' => [
                    'selected_images' => (int) $firstScreenWarmup['selected_images'],
                    'checked' => (int) $firstScreenWarmup['checked'],
                    'cached' => (int) $firstScreenWarmup['cached'],
                    'generated' => (int) $firstScreenWarmup['generated'],
                    'source_reuse' => (int) $firstScreenWarmup['source_reuse'],
                ],
                'covers' => [
                    'selected_images' => (int) $coverWarmup['selected_images'],
                    'checked' => (int) $coverWarmup['checked'],
                    'cached' => (int) $coverWarmup['cached'],
                    'generated' => (int) $coverWarmup['generated'],
                    'source_reuse' => (int) $coverWarmup['source_reuse'],
                ],
                'all_recovery' => [
                    'selected_images' => (int) $allRecoveryWarmup['selected_images'],
                    'checked' => (int) $allRecoveryWarmup['checked'],
                    'cached' => (int) $allRecoveryWarmup['cached'],
                    'generated' => (int) $allRecoveryWarmup['generated'],
                    'source_reuse' => (int) $allRecoveryWarmup['source_reuse'],
                    'queue_quarantined' => (int) ($allRecoveryWarmup['queue_quarantined'] ?? 0),
                ],
            ],
            'media_attestation' => [
                'state' => (string) ($attestation['state'] ?? 'MISSING'),
                'probe_count' => (int) ($attestation['probe_count'] ?? 0),
            ],
            'backup_freshness' => $backup,
        ];
        $record = \ClassIdentity\MaintenanceStatus::create($tasks, $attention ? 'ATTENTION' : 'PASS');
        \ClassIdentity\MaintenanceStatus::persist($record);
        return $record;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

// The focused synthetic schedule suite imports only the pure functions above.
// This constant is intentionally unavailable to web routes and does not alter
// the production CLI entrypoint.
if (defined('CLASS_ARCHIVE_MAINTENANCE_LIBRARY_ONLY')) {
    return;
}

try {
    $arguments = maintenanceArguments($_SERVER['argv'] ?? []);
    // Piwigo's bootstrap intentionally defines globals such as $conf, $user
    // and $mysqli. Like the existing project CLI scripts, include it at file
    // scope so those globals are not stranded inside a helper function.
    maintenancePrepareRuntime();
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    require_once __DIR__ . '/warm-photo-cache.php';
    $record = maintenanceRun($arguments['apply_rejected_cleanup']);
    $output = [
        'maintenance_version' => $record['maintenance_version'],
        'timestamp' => $record['timestamp'],
        'result' => $record['result'],
        'tasks' => $record['tasks'],
    ];
    if ($arguments['json']) {
        fwrite(STDOUT, json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    } else {
        fwrite(STDOUT, 'MAINTENANCE_RESULT=' . $record['result'] . "\n");
    }
    exit($arguments['require_ready'] && $record['result'] !== 'PASS' ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'MAINTENANCE_RESULT=FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
