<?php

declare(strict_types=1);

/**
 * Class Archive maintenance runner.
 *
 * It is intentionally suitable for an interactive Windows wrapper, cron, or a
 * future container scheduler. It contains no credentials, accepts no file
 * paths, and defaults to read-only checks. The optional rejected-binary
 * cleanup only processes explicitly aged REJECTED submission references.
 */

const CLASS_ARCHIVE_MAINTENANCE_ROOT = '/var/www/html/piwigo';
const CLASS_ARCHIVE_BACKUP_ROOT = '/class-archive-backups';
const CLASS_ARCHIVE_BACKUP_FRESHNESS_SECONDS = 7 * 86400;

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
function backupFreshness(): array
{
    if (!is_dir(CLASS_ARCHIVE_BACKUP_ROOT) || is_link(CLASS_ARCHIVE_BACKUP_ROOT)) {
        return ['state' => 'MISSING', 'label' => '未找到', 'message' => '备份卷不可用。', 'timestamp' => null, 'age_seconds' => null, 'verified_files' => 0];
    }
    $latest = null;
    $iterator = new DirectoryIterator(CLASS_ARCHIVE_BACKUP_ROOT);
    foreach ($iterator as $entry) {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
            continue;
        }
        $name = $entry->getFilename();
        if (preg_match('/\Aclass-archive-[0-9]{8}T[0-9]{6}Z\z/D', $name) !== 1) {
            continue;
        }
        $complete = $entry->getPathname() . '/COMPLETE';
        $manifest = $entry->getPathname() . '/SHA256SUMS';
        if (!is_file($complete) || is_link($complete) || !is_file($manifest) || is_link($manifest)) {
            continue;
        }
        $mtime = filemtime($complete);
        if ($mtime === false) {
            continue;
        }
        if ($latest === null || $mtime > $latest['mtime']) {
            $latest = ['directory' => $entry->getPathname(), 'mtime' => $mtime];
        }
    }
    if ($latest === null) {
        return ['state' => 'MISSING', 'label' => '未找到', 'message' => '没有完整的备份包。', 'timestamp' => null, 'age_seconds' => null, 'verified_files' => 0];
    }
    $manifest = $latest['directory'] . '/SHA256SUMS';
    $lines = @file($manifest, FILE_IGNORE_NEW_LINES);
    $expected = ['database.sql.gz', 'piwigo-data.tar.gz', 'uploads.tar.gz', 'galleries.tar.gz', 'COMPLETE'];
    $hashes = [];
    if (!is_array($lines)) {
        return ['state' => 'INVALID', 'label' => '无效', 'message' => '备份校验清单无法读取。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => max(0, time() - $latest['mtime']), 'verified_files' => 0];
    }
    foreach ($lines as $line) {
        if (preg_match('/\A([a-f0-9]{64})  ([A-Za-z0-9._-]+)\z/D', $line, $matches) !== 1) {
            return ['state' => 'INVALID', 'label' => '无效', 'message' => '备份校验清单格式无效。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => max(0, time() - $latest['mtime']), 'verified_files' => 0];
        }
        if (isset($hashes[$matches[2]])) {
            return ['state' => 'INVALID', 'label' => '无效', 'message' => '备份校验清单包含重复条目。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => max(0, time() - $latest['mtime']), 'verified_files' => 0];
        }
        $hashes[$matches[2]] = $matches[1];
    }
    if (array_keys($hashes) !== $expected) {
        return ['state' => 'INVALID', 'label' => '无效', 'message' => '备份内容与当前完整备份格式不一致。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => max(0, time() - $latest['mtime']), 'verified_files' => 0];
    }
    foreach ($expected as $name) {
        $file = $latest['directory'] . '/' . $name;
        $actual = (!is_file($file) || is_link($file)) ? false : hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($hashes[$name], $actual)) {
            return ['state' => 'INVALID', 'label' => '无效', 'message' => '备份文件校验失败。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => max(0, time() - $latest['mtime']), 'verified_files' => 0];
        }
    }
    $age = max(0, time() - $latest['mtime']);
    if ($age > CLASS_ARCHIVE_BACKUP_FRESHNESS_SECONDS) {
        return ['state' => 'STALE', 'label' => '需要备份', 'message' => '最近完整备份已超过七天。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => $age, 'verified_files' => count($expected)];
    }
    return ['state' => 'FRESH', 'label' => '正常', 'message' => '最近完整备份已校验。', 'timestamp' => gmdate('c', $latest['mtime']), 'age_seconds' => $age, 'verified_files' => count($expected)];
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
        $cleanup = \ClassIdentitySubmissionService::fromPiwigo()->cleanupRejectedBinaries($retentionDays, $applyRejectedCleanup);
        $reconciliation = \ClassIdentity\ReconciliationService::fromPiwigo()->scanAndPersist();
        $attestation = \ClassIdentity\MediaAttestation::status();
        $backup = backupFreshness();
        $attention = (
            ($reconciliation['result'] ?? null) !== 'PASS'
            || ($attestation['state'] ?? null) !== 'VERIFIED'
            || ($backup['state'] ?? null) !== 'FRESH'
            || (int) ($cleanup['failed'] ?? 0) > 0
        );
        $tasks = [
            'expired_invitations' => $provisioning,
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
            'media_permission_verification' => [
                'derivative_files' => (int) (($reconciliation['derivative']['file_count'] ?? 0)),
                'unsafe_entries' => count($reconciliation['derivative']['unsafe_entries'] ?? []),
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
