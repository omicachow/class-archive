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
