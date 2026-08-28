<?php

declare(strict_types=1);

/**
 * Creates one idempotent, clearly scoped synthetic marker across the v17
 * collection preference/maintenance tables before a format-9 DB-only recovery
 * round trip. It is not a browser route and cannot run outside the isolated
 * V4 synthetic migration runtime.
 */

const V17_SYNTHETIC_RECOVERY_MARKER_ROOT = '/var/www/html/piwigo';

function v17RecoveryMarkerFail(string $code): never
{
    fwrite(STDERR, 'V17_SYNTHETIC_RECOVERY_MARKER=FAIL code='
        . preg_replace('/[^a-z0-9_]/', '_', strtolower($code)) . "\n");
    exit(1);
}

try {
    if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
        throw new RuntimeException('runtime_forbidden');
    }
    if (getenv('CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY') !== '1'
        || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION') {
        throw new RuntimeException('sandbox_scope_confirmation_missing');
    }
    if (realpath(V17_SYNTHETIC_RECOVERY_MARKER_ROOT) !== V17_SYNTHETIC_RECOVERY_MARKER_ROOT
        || is_link(V17_SYNTHETIC_RECOVERY_MARKER_ROOT)) {
        throw new RuntimeException('piwigo_root_untrusted');
    }

    chdir(V17_SYNTHETIC_RECOVERY_MARKER_ROOT) || throw new RuntimeException('piwigo_chdir_failed');
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
    // Environment flags alone are not a synthetic-data proof. Bind the write
    // to the exact immutable synthetic roster/account sentinel created by the
    // V4 bootstrap, and reject a missing or duplicate result before calling
    // any collection service mutation.
    $principals = $repository->fetchAll(
        'SELECT p.`id` FROM `' . $repository->table('principal') . '` p '
        . 'INNER JOIN `' . $repository->table('account') . '` a ON a.`id`=p.`account_id` '
        . 'INNER JOIN `' . $repository->table('seat') . '` s ON s.`id`=a.`seat_id` '
        . 'INNER JOIN `' . $repository->table('identity') . '` i ON i.`id`=s.`identity_id` '
        . "WHERE p.`principal_type`='SEAT_ACCOUNT' AND p.`system_role` IS NULL AND p.`state`='ACTIVE' "
        . "AND a.`requested_username`='fixture-classmate' AND a.`state`='ACTIVE' "
        . "AND s.`ordinal`=1 AND s.`state`='ACTIVE' AND s.`seat_type`='CLASSMATE' "
        . "AND i.`roster_code`='C-SYN-001' AND i.`identity_type`='CLASSMATE' "
        . "AND i.`real_name`='Synthetic Classmate' AND i.`state`='ACTIVE' AND i.`seat_template_version`=1 "
        . 'ORDER BY p.`id` ASC',
    );
    if (count($principals) !== 1) {
        throw new RuntimeException('synthetic_classmate_principal_missing');
    }
    $principalId = (int) ($principals[0]['id'] ?? 0);
    if ($principalId <= 0) {
        throw new RuntimeException('synthetic_classmate_principal_missing');
    }
    $item = $repository->fetchOne(
        'SELECT i.`item_kind`,i.`item_key` FROM `' . $repository->table('collection_snapshot_pointer') . '` p '
        . 'INNER JOIN `' . $repository->table('collection_snapshot_item') . '` i ON i.`snapshot_id`=p.`active_snapshot_id` '
        . "WHERE p.`scope`='FULL' AND p.`projection_kind`='HOME' ORDER BY i.`ordinal` ASC LIMIT 1",
    );
    $itemKind = $item['item_kind'] ?? null;
    $itemKey = $item['item_key'] ?? null;
    if (!is_string($itemKind) || !is_string($itemKey) || $itemKind === '' || $itemKey === '') {
        throw new RuntimeException('synthetic_snapshot_item_missing');
    }

    $visible = static function (\ClassIdentity\CollectionSnapshotItem $snapshotItem): ?array {
        return $snapshotItem->publicProjection($snapshotItem->photoIds());
    };
    $service = \ClassIdentity\CollectionSnapshotService::fromPiwigo();
    $service->pin($principalId, 'FULL', 'HOME', $itemKind, $itemKey, $visible);
    $service->setFeedback($principalId, 'FULL', 'HOME', $itemKind, $itemKey, 'LIKE', $visible);
    $revision = hash('sha256', 'CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY_MARKER', true);
    $claimed = $service->claimMaintenance('V17_SYNTHETIC_RECOVERY_MARKER', $revision);
    if (($claimed['claimed'] ?? false) === true) {
        $completed = $service->completeMaintenance('V17_SYNTHETIC_RECOVERY_MARKER');
        if (($completed['completed'] ?? false) !== true) {
            throw new RuntimeException('synthetic_maintenance_complete_failed');
        }
    }
    $maintenance = $repository->fetchOne(
        'SELECT `state`,`last_input_revision`,`last_snapshot_id` FROM `'
        . $repository->table('collection_maintenance_state') . '` WHERE `maintenance_key`=? LIMIT 1',
        ['V17_SYNTHETIC_RECOVERY_MARKER'],
    );
    if (($maintenance['state'] ?? null) !== 'COMPLETE'
        || !is_string($maintenance['last_input_revision'] ?? null)
        || !hash_equals($revision, (string) $maintenance['last_input_revision'])) {
        throw new RuntimeException('synthetic_maintenance_marker_invalid');
    }

    fwrite(STDOUT, "V17_SYNTHETIC_RECOVERY_MARKER=PASS pin=ACTIVE feedback=LIKE maintenance=COMPLETE scope=SYNTHETIC_ONLY\n");
} catch (Throwable $error) {
    v17RecoveryMarkerFail($error->getMessage());
}
