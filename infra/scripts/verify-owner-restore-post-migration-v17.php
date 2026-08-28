<?php

declare(strict_types=1);

/**
 * Bounded v17 post-migration verifier for a fresh isolated owner restore.
 *
 * It is intentionally separate from the historical v16 verifier: a restore
 * drill must prove the schema generation it actually restored, never merely
 * load whichever verifier happens to be mounted. The command reads business
 * state only; it neither rebuilds projections nor invokes ML indexing.
 */

const OWNER_RESTORE_V17_ROOT = '/var/www/html/piwigo';

function restoreV17VerifyFail(string $code): never
{
    fwrite(STDERR, 'OWNER_RESTORE_POST_MIGRATION_V17=FAIL code='
        . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_required');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('root_forbidden');
    }
    if (getenv('CLASS_ARCHIVE_OWNER_RESTORE_V17_VERIFY') !== '1') {
        throw new RuntimeException('restore_v17_scope_confirmation_missing');
    }
    if (realpath(OWNER_RESTORE_V17_ROOT) !== OWNER_RESTORE_V17_ROOT || is_link(OWNER_RESTORE_V17_ROOT)) {
        throw new RuntimeException('restore_root_untrusted');
    }
    chdir(OWNER_RESTORE_V17_ROOT) || throw new RuntimeException('restore_chdir_failed');
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

    if (!defined('CLASS_IDENTITY_VERSION') || \ClassIdentity\Schema::CURRENT_VERSION !== 17) {
        throw new RuntimeException('schema_source_not_v17');
    }
    \ClassIdentity\Schema::fromPiwigo(CLASS_IDENTITY_VERSION)->verifyCurrent();
    $repository = \ClassIdentity\Repository::fromPiwigo();
    foreach ([
        'collection_snapshot', 'collection_snapshot_item', 'collection_snapshot_pointer',
        'collection_pin', 'collection_feedback', 'collection_maintenance_state',
    ] as $suffix) {
        $row = $repository->fetchOne('SELECT COUNT(*) AS `rows` FROM `' . $repository->table($suffix) . '`');
        if ($row === null || (int) ($row['rows'] ?? -1) < 0) {
            throw new RuntimeException('collection_snapshot_schema_unavailable');
        }
    }
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

    fwrite(STDOUT, 'OWNER_RESTORE_POST_MIGRATION_V17=PASS schema=17 reconciliation=PASS checked_images='
        . (int) $reconciliation['checked_images']
        . ' collections=SCHEMA_VALIDATED ai=PASS open_jobs=0 derivatives=REBUILDABLE_NOT_REQUIRED' . "\n");
} catch (Throwable $error) {
    restoreV17VerifyFail($error->getMessage());
}
