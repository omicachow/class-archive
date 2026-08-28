<?php

declare(strict_types=1);

/**
 * Read-only proof for the dedicated v16 -> v17 synthetic migration sandbox.
 *
 * The sandbox deliberately restores only a database snapshot: no originals,
 * derivatives or private media are mounted.  This verifier therefore proves
 * the forward schema/collection-snapshot transition and explicitly does not
 * claim a MediaGuard or browser-media result.
 */

const V4_SYNTHETIC_MIGRATION_ROOT = '/var/www/html/piwigo';

function v4SyntheticMigrationFail(string $code): never
{
    fwrite(STDERR, 'V4_SYNTHETIC_MIGRATION_VERIFY=FAIL code='
        . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

/** @return int */
function v4SyntheticCount(\ClassIdentity\Repository $repository, string $suffix, string $where = '1=1'): int
{
    $row = $repository->fetchOne('SELECT COUNT(*) AS `count` FROM `' . $repository->table($suffix) . '` WHERE ' . $where);
    if ($row === null || !isset($row['count']) || !is_numeric($row['count'])) {
        throw new RuntimeException('count_query_failed_' . $suffix);
    }
    return (int) $row['count'];
}

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_required');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('root_forbidden');
    }
    if (getenv('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION') !== '1'
        || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION') {
        throw new RuntimeException('sandbox_scope_confirmation_missing');
    }
    if (realpath(V4_SYNTHETIC_MIGRATION_ROOT) !== V4_SYNTHETIC_MIGRATION_ROOT
        || is_link(V4_SYNTHETIC_MIGRATION_ROOT)) {
        throw new RuntimeException('piwigo_root_untrusted');
    }

    chdir(V4_SYNTHETIC_MIGRATION_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
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
    $schema = \ClassIdentity\Schema::fromPiwigo(CLASS_IDENTITY_VERSION);
    $schema->verifyCurrent();
    $repository = \ClassIdentity\Repository::fromPiwigo();

    $activePhotos = v4SyntheticCount($repository, 'photo', "`state`='ACTIVE'");
    // The only accepted input is the canonical 8091 public-safe fixture
    // snapshot.  A different DB-only dump may be structurally valid but is
    // not proof of this exact Phase 3.4 synthetic migration boundary.
    if ($activePhotos !== 72) {
        throw new RuntimeException('synthetic_baseline_photo_count_invalid');
    }

    $expectedPointers = 8; // FULL + HERITAGE x HOME/MEMORY/SPOTLIGHT/SEARCH_SUGGESTION.
    $activePointers = v4SyntheticCount(
        $repository,
        'collection_snapshot_pointer',
        "`active_snapshot_id` IS NOT NULL AND `scope` IN ('FULL','HERITAGE')"
    );
    if ($activePointers !== $expectedPointers) {
        throw new RuntimeException('collection_snapshot_pointer_count_invalid');
    }
    $activeSnapshots = v4SyntheticCount(
        $repository,
        'collection_snapshot',
        "`state`='ACTIVE' AND `scope` IN ('FULL','HERITAGE')"
    );
    if ($activeSnapshots < $expectedPointers) {
        throw new RuntimeException('collection_snapshot_active_count_invalid');
    }
    $snapshotItems = v4SyntheticCount($repository, 'collection_snapshot_item');
    if ($snapshotItems < 1) {
        throw new RuntimeException('collection_snapshot_items_missing');
    }
    $maintenanceRows = v4SyntheticCount(
        $repository,
        'collection_maintenance_state',
        "`maintenance_key` IN ('COLLECTION_SNAPSHOTS_FULL','COLLECTION_SNAPSHOTS_HERITAGE') AND `state`='COMPLETE'"
    );
    if ($maintenanceRows !== 2) {
        throw new RuntimeException('collection_snapshot_maintenance_not_complete');
    }

    fwrite(STDOUT, 'V4_SYNTHETIC_MIGRATION_VERIFY=PASS schema=17 photos=72 pointers='
        . $activePointers . ' snapshots=' . $activeSnapshots . ' items=' . $snapshotItems
        . ' media=NOT_MOUNTED media_guard=NOT_CLAIMED browser=NOT_CLAIMED' . "\n");
} catch (Throwable $error) {
    v4SyntheticMigrationFail($error->getMessage());
}
