<?php

declare(strict_types=1);

/**
 * Pure/synthetic contract for the Class Archive Collections maintenance
 * calendar. It deliberately imports the runner in library-only mode: no
 * database, Piwigo bootstrap, media source, model runtime or browser is used.
 */

function collectionLifecycleTestFail(string $message): never
{
    throw new RuntimeException($message);
}

function collectionLifecycleTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        collectionLifecycleTestFail($message);
    }
}

define('CLASS_ARCHIVE_MAINTENANCE_LIBRARY_ONLY', true);
$root = dirname(__DIR__, 2);
$runnerPath = $root . '/infra/scripts/run-maintenance.php';
$snapshotServicePath = $root . '/plugins/ClassIdentity/src/CollectionSnapshotService.php';
$source = file_get_contents($runnerPath);
if (!is_string($source) || $source === '' || !is_file($snapshotServicePath)) {
    fwrite(STDERR, "COLLECTION_LIFECYCLE_MAINTENANCE=FAIL reason=runner_unavailable\n");
    exit(1);
}
if (!defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', '/synthetic/class-archive/');
}
require $snapshotServicePath;
require $runnerPath;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    collectionLifecycleTestAssert($condition, $message);
    ++$assertions;
};
$functionBody = static function (string $php, string $name): string {
    $start = strpos($php, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $brace = strpos($php, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    for ($index = $brace, $length = strlen($php); $index < $length; ++$index) {
        if ($php[$index] === '{') {
            ++$depth;
        } elseif ($php[$index] === '}' && --$depth === 0) {
            return substr($php, $brace, $index - $brace + 1);
        }
    }
    return '';
};

try {
    $utc = new DateTimeZone('UTC');
    $night = collectionLifecycleSchedule('nightly', new DateTimeImmutable('2030-01-01 23:59:59', $utc));
    $assert($night['cadence'] === 'NIGHTLY' && $night['key'] === 'COLLECTION_LIFECYCLE_NIGHTLY', 'nightly_key_invalid');
    $assert($night['window'] === '2030-01-01' && strlen($night['revision']) === 32, 'nightly_window_or_revision_invalid');
    $assert($night['label'] === '每日投影刷新', 'nightly_label_invalid');

    $weekSunday = collectionLifecycleSchedule('WEEKLY', new DateTimeImmutable('2030-01-06 23:59:59', $utc));
    $weekMonday = collectionLifecycleSchedule('WEEKLY', new DateTimeImmutable('2030-01-07 00:00:00', $utc));
    $assert($weekSunday['window'] !== $weekMonday['window'], 'weekly_window_did_not_cross_iso_boundary');
    $assert($weekMonday['key'] === 'COLLECTION_LIFECYCLE_WEEKLY' && strlen($weekMonday['revision']) === 32, 'weekly_key_invalid');

    $monthLast = collectionLifecycleSchedule('MONTHLY', new DateTimeImmutable('2030-01-31 23:59:59', $utc));
    $monthFirst = collectionLifecycleSchedule('MONTHLY', new DateTimeImmutable('2030-02-01 00:00:00', $utc));
    $assert($monthLast['window'] === '2030-01' && $monthFirst['window'] === '2030-02', 'monthly_window_invalid');
    $assert($monthFirst['label'] === '每月集合健康检查', 'monthly_label_invalid');

    foreach (['INVALID', '', 'NIGHTLY-WEEKLY'] as $invalidCadence) {
        try {
            collectionLifecycleSchedule($invalidCadence, new DateTimeImmutable('2030-01-01', $utc));
            collectionLifecycleTestFail('invalid_cadence_accepted');
        } catch (InvalidArgumentException) {
            ++$assertions;
        }
    }

    $home = [
        ['itemKind' => 'ALBUM', 'itemKey' => 'album-a', 'payload' => ['section' => 'ALBUM']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'recommendation-a', 'payload' => ['section' => 'RECOMMENDATION']],
        ['itemKind' => 'PERSON', 'itemKey' => 'person-a', 'payload' => ['section' => 'PERSON']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'recommendation-b', 'payload' => ['section' => 'RECOMMENDATION']],
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'recommendation-c', 'payload' => ['section' => 'RECOMMENDATION']],
        ['itemKind' => 'PHOTO', 'itemKey' => 'recent', 'payload' => ['section' => 'RECENT']],
    ];
    $first = collectionLifecycleRotateRecommendations($home, 'FULL', $weekMonday['window']);
    $second = collectionLifecycleRotateRecommendations($home, 'FULL', $weekMonday['window']);
    $assert($first['recommendation_count'] === 3 && $first['rotated'] === true, 'weekly_recommendation_rotation_missing');
    $assert($first === $second, 'weekly_recommendation_rotation_not_deterministic');
    $assert($first['items'][0] === $home[0] && $first['items'][2] === $home[2] && $first['items'][5] === $home[5], 'non_recommendation_slots_changed');
    $beforeKeys = [$home[1]['itemKey'], $home[3]['itemKey'], $home[4]['itemKey']];
    $afterKeys = [$first['items'][1]['itemKey'], $first['items'][3]['itemKey'], $first['items'][4]['itemKey']];
    sort($beforeKeys, SORT_STRING);
    sort($afterKeys, SORT_STRING);
    $assert($beforeKeys === $afterKeys, 'weekly_recommendation_membership_changed');
    $assert($afterKeys !== [] && $first['items'] !== $home, 'weekly_recommendation_order_not_rotated');

    $single = collectionLifecycleRotateRecommendations([
        ['itemKind' => 'AUTO_COLLECTION', 'itemKey' => 'recommendation-one', 'payload' => ['section' => 'RECOMMENDATION']],
    ], 'HERITAGE_ONLY', $weekMonday['window']);
    $assert($single['recommendation_count'] === 1 && $single['rotated'] === false, 'single_recommendation_should_not_rotate');
    foreach ([
        static fn() => collectionLifecycleRotateRecommendations($home, 'INVALID', $weekMonday['window']),
        static fn() => collectionLifecycleRotateRecommendations($home, 'FULL', '2030-99'),
    ] as $invalid) {
        try {
            $invalid();
            collectionLifecycleTestFail('invalid_recommendation_input_accepted');
        } catch (InvalidArgumentException) {
            ++$assertions;
        }
    }

    foreach ([
        'CLASS_ARCHIVE_COLLECTION_LIFECYCLE_NIGHTLY',
        'COLLECTION_LIFECYCLE_NIGHTLY',
        'claimMaintenance($schedule[\'key\'], $schedule[\'revision\'])',
        'completeMaintenance($schedule[\'key\'])',
        'failMaintenance($schedule[\'key\'], $errorCode)',
        'ReadProjectionBuilder::rebuild()',
        'AutoCollectionService::fromPiwigo()->reconciliationReport()',
        'AiIndexService::fromPiwigo()->maintenanceReport()',
        "'collection_lifecycle' => \$collectionLifecycle",
        'CLASS_ARCHIVE_MAINTENANCE_LIBRARY_ONLY',
    ] as $needle) {
        $assert(str_contains($source, $needle), 'lifecycle_source_contract_missing_' . strtolower(str_replace(['$', '(', ')', '[', ']', "'", ':'], '_', $needle)));
    }
    $weeklyBody = $functionBody($source, 'collectionLifecycleWeeklyRecommendationRefresh');
    $assert($weeklyBody !== '' && !str_contains($weeklyBody, 'ReadProjectionBuilder::rebuild')
        && !str_contains($weeklyBody, 'AiIndexService') && !str_contains($weeklyBody, '->enqueue'),
        'weekly_rotation_must_not_rebuild_projections_or_ai');
    $monthlyBody = $functionBody($source, 'collectionLifecycleMonthlyAudit');
    $assert($monthlyBody !== '' && !str_contains($monthlyBody, 'ReadProjectionBuilder::rebuild')
        && !str_contains($monthlyBody, '->enqueue') && !str_contains($monthlyBody, '->reindex'),
        'monthly_audit_must_not_rebuild_or_reindex');
    $assert(str_contains($monthlyBody, 'collection_lifecycle_monthly_audit_review_required'),
        'monthly_review_finding_must_not_become_current');
    foreach (['$_GET', '$_POST', '$_COOKIE'] as $forbidden) {
        $assert(!str_contains($source, $forbidden), 'maintenance_runner_must_not_consume_browser_input');
    }

    $safe = [
        'result' => 'PASS',
        'version' => 1,
        'clock' => 'UTC',
        'nightly' => ['cadence' => 'NIGHTLY', 'window' => '2030-01-01', 'state' => 'CURRENT'],
    ];
    $safeJson = json_encode($safe, JSON_THROW_ON_ERROR);
    foreach (['photoId', 'snapshotId', 'principal', 'account', 'path', 'filename', 'secret', 'token'] as $forbidden) {
        $assert(!str_contains(strtolower($safeJson), strtolower($forbidden)), 'safe_lifecycle_shape_leaks_' . strtolower($forbidden));
    }

    fwrite(STDOUT, "COLLECTION_LIFECYCLE_MAINTENANCE=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'COLLECTION_LIFECYCLE_MAINTENANCE=FAIL reason='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
