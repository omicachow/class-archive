<?php

declare(strict_types=1);

const CLASS_ARCHIVE_RESTORE_EVIDENCE_ROOT = '/var/www/html/piwigo';

/** @return array{bundle:string,fixture:string,rto:int} */
function restoreEvidenceArguments(array $argv): array
{
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/\A--(bundle|fixture-sha256|rto-seconds)=(.+)\z/D', $argument, $matches) !== 1 || isset($values[$matches[1]])) {
            throw new InvalidArgumentException('backup_restore_evidence_argument_invalid');
        }
        $values[$matches[1]] = $matches[2];
    }
    if (!isset($values['bundle'], $values['fixture-sha256'], $values['rto-seconds'])
        || !ctype_digit($values['rto-seconds'])) {
        throw new InvalidArgumentException('backup_restore_evidence_argument_invalid');
    }
    return ['bundle' => $values['bundle'], 'fixture' => $values['fixture-sha256'], 'rto' => (int) $values['rto-seconds']];
}

try {
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)
        || realpath(CLASS_ARCHIVE_RESTORE_EVIDENCE_ROOT) !== CLASS_ARCHIVE_RESTORE_EVIDENCE_ROOT
        || is_link(CLASS_ARCHIVE_RESTORE_EVIDENCE_ROOT)) {
        throw new RuntimeException('backup_restore_evidence_runtime_forbidden');
    }
    $arguments = restoreEvidenceArguments($_SERVER['argv'] ?? []);
    chdir(CLASS_ARCHIVE_RESTORE_EVIDENCE_ROOT) || throw new RuntimeException('backup_restore_evidence_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';
    $record = \ClassIdentity\BackupRestoreEvidence::create($arguments['bundle'], $arguments['fixture'], $arguments['rto']);
    \ClassIdentity\BackupRestoreEvidence::persist($record);
    fwrite(STDOUT, "BACKUP_RESTORE_EVIDENCE=PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'BACKUP_RESTORE_EVIDENCE=FAILED code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
