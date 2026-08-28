<?php

declare(strict_types=1);

/**
 * v16-only bounded post-migration verifier for the isolated owner restore
 * runtime. This file deliberately rejects source versions other than 16 so a
 * historical v15→v16 restore drill cannot be mistaken for a v17 restore
 * proof. Use verify-owner-restore-post-migration-v17.php for v17.
 *
 * It verifies schema, persisted business/media reconciliation and the durable
 * AI control plane. It deliberately never warms derivatives or invokes
 * Immich/ML: derivative cache is excluded from the owner recovery bundle and
 * is not a prerequisite for proving business-state recovery.
 */

const OWNER_RESTORE_ROOT = '/var/www/html/piwigo';

function restoreVerifyFail(string $code): never
{
    fwrite(STDERR, 'OWNER_RESTORE_POST_MIGRATION=FAIL code=' . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_required');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('root_forbidden');
    }
    if (getenv('CLASS_ARCHIVE_OWNER_RESTORE_VERIFY') !== '1') {
        throw new RuntimeException('restore_scope_confirmation_missing');
    }
    if (realpath(OWNER_RESTORE_ROOT) !== OWNER_RESTORE_ROOT || is_link(OWNER_RESTORE_ROOT)) {
        throw new RuntimeException('restore_root_untrusted');
    }
    chdir(OWNER_RESTORE_ROOT) || throw new RuntimeException('restore_chdir_failed');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    if (!defined('CLASS_IDENTITY_VERSION') || \ClassIdentity\Schema::CURRENT_VERSION !== 16) {
        throw new RuntimeException('schema_source_invalid');
    }
    \ClassIdentity\Schema::fromPiwigo(CLASS_IDENTITY_VERSION)->verifyCurrent();
    $reconciliation = \ClassIdentity\ReconciliationService::fromPiwigo()->scanAndPersist();
    $ai = \ClassIdentity\AiIndexService::fromPiwigo()->maintenanceReport();
    if (($reconciliation['result'] ?? null) !== 'PASS'
        || (int) ($reconciliation['issue_count'] ?? -1) !== 0
        || (int) ($reconciliation['checked_images'] ?? 0) < 1
    ) {
        throw new RuntimeException('reconciliation_not_clear');
    }
    if (($ai['result'] ?? null) !== 'PASS'
        || (int) ($ai['missing_index_rows'] ?? -1) !== 0
        || (int) ($ai['checksum_drift'] ?? -1) !== 0
        || (int) ($ai['failed_assets'] ?? -1) !== 0
        || (int) ($ai['failed_jobs'] ?? -1) !== 0
        || (int) ($ai['open_jobs'] ?? -1) !== 0
    ) {
        throw new RuntimeException('ai_control_plane_not_ready');
    }

    fwrite(STDOUT, 'OWNER_RESTORE_POST_MIGRATION=PASS schema=16 reconciliation=PASS checked_images='
        . (int) $reconciliation['checked_images'] . ' ai=PASS open_jobs=0 derivatives=REBUILDABLE_NOT_REQUIRED' . "\n");
} catch (Throwable $error) {
    restoreVerifyFail($error->getMessage());
}
