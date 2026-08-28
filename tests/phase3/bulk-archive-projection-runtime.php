<?php

declare(strict_types=1);

/**
 * Real MariaDB/Piwigo runtime regression for the v12 dual native-source guard.
 *
 * The canonical synthetic baseline deliberately has no archive_image rows.
 * This test therefore creates one temporary Class Archive metadata row for an
 * existing canonical photo, prefers an inherited-but-not-direct album mapping,
 * and exercises a real Piwigo association add. Every fixture row/association is
 * removed in finally and the complete projection is rebuilt before exit.
 */

function bulkProjectionFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

chdir('/var/www/html/piwigo') || exit(1);
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

foreach ([
    \ClassIdentity\BulkArchiveService::class,
    \ClassIdentity\ProjectionMutationBoundary::class,
    \ClassIdentity\Gateway\ReadProjectionBuilder::class,
    \ClassIdentity\Gateway\ReadProjectionStore::class,
] as $requiredClass) {
    if (!class_exists($requiredClass)) {
        fwrite(STDERR, "CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME=FAIL reason=installed_plugin_unavailable\n");
        exit(1);
    }
}

$repository = \ClassIdentity\Repository::fromPiwigo();
$projection = '`' . $repository->table('read_projection') . '`';
$photo = '`' . $repository->table('photo') . '`';
$archive = '`' . $repository->table('archive_image') . '`';
$album = '`' . $repository->table('album') . '`';
$batch = '`' . $repository->table('batch_operation') . '`';
$batchItem = '`' . $repository->table('batch_operation_item') . '`';
$audit = '`' . $repository->table('audit_event') . '`';
$requiredKinds = [
    \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
    ...\ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
];
$run = strtolower(bin2hex(random_bytes(6)));
$reason = 'Synthetic archive projection test ' . implode(' ', str_split($run, 4));
$batchId = null;
$restoreAssociation = false;
$createdArchiveRow = false;
$fixtureImageId = null;
$fixtureCategoryId = null;
$assertions = 0;
$exit = 0;

$states = static function () use ($repository, $projection): array {
    $result = [];
    foreach ($repository->fetchAll(
        "SELECT `projection_key`,`state`,`invalidated_reason` FROM {$projection} ORDER BY `projection_key`",
    ) as $row) {
        $result[(string) $row['projection_key']] = [
            'state' => (string) $row['state'],
            'reason' => is_string($row['invalidated_reason'] ?? null) ? $row['invalidated_reason'] : null,
        ];
    }
    return $result;
};

try {
    $beforeStates = $states();
    foreach ($requiredKinds as $kind) {
        if (($beforeStates[$kind]['state'] ?? null) !== 'ACTIVE') {
            bulkProjectionFail('projection_not_active_' . strtolower($kind));
        }
    }
    ++$assertions;

    $admin = $repository->fetchOne(
        'SELECT p.`piwigo_user_id` FROM `' . $repository->table('principal') . '` p '
            . 'JOIN ' . USER_INFOS_TABLE . " ui ON ui.`user_id`=p.`piwigo_user_id` "
            . "WHERE p.`principal_type`='SYSTEM_ACCOUNT' AND p.`system_role`='SYSTEM_ADMIN' AND p.`state`='ACTIVE' "
            . "AND p.`account_id` IS NULL AND ui.`status` IN ('admin','webmaster') ORDER BY p.`id` LIMIT 1",
    );
    if ($admin === null || (int) ($admin['piwigo_user_id'] ?? 0) <= 0) {
        bulkProjectionFail('system_admin_missing');
    }
    $adminUserId = (int) $admin['piwigo_user_id'];
    ++$assertions;

    global $prefixeTable;
    $fixture = $repository->fetchOne(
        "SELECT p.`class_photo_id`,p.`piwigo_image_id`,a.`class_album_id`,a.`piwigo_category_id`,a.`era`, "
            . "(SELECT COUNT(*) FROM `{$prefixeTable}image_category` direct_ic "
            . "WHERE direct_ic.`image_id`=p.`piwigo_image_id` AND direct_ic.`category_id`=a.`piwigo_category_id`) AS direct_count "
            . "FROM {$photo} p LEFT JOIN {$archive} ai ON ai.`piwigo_image_id`=p.`piwigo_image_id` "
            . "JOIN `{$prefixeTable}image_category` ic ON ic.`image_id`=p.`piwigo_image_id` "
            . "JOIN `{$prefixeTable}categories` c ON c.`id`=ic.`category_id` "
            . "JOIN {$album} a ON (c.`id`=a.`piwigo_category_id` OR FIND_IN_SET(a.`piwigo_category_id`,c.`uppercats`) > 0) "
            . "WHERE p.`state`='ACTIVE' AND a.`state`='ACTIVE' AND a.`era` IN ('HERITAGE','LIVING') AND ai.`id` IS NULL "
            . "AND NOT EXISTS (SELECT 1 FROM `{$prefixeTable}image_category` direct_ic "
            . "WHERE direct_ic.`image_id`=p.`piwigo_image_id` AND direct_ic.`category_id`=a.`piwigo_category_id`) "
            . 'ORDER BY p.`class_photo_id` LIMIT 1',
    );
    if ($fixture === null) {
        bulkProjectionFail('temporary_archive_fixture_slot_missing');
    }
    $classPhotoId = \ClassIdentity\DomainSupport::binaryToId((string) $fixture['class_photo_id']);
    $classAlbumId = \ClassIdentity\DomainSupport::binaryToId((string) $fixture['class_album_id']);
    $imageId = (int) $fixture['piwigo_image_id'];
    $categoryId = (int) $fixture['piwigo_category_id'];
    $era = (string) $fixture['era'];
    $fixtureImageId = $imageId;
    $fixtureCategoryId = $categoryId;
    $insertedArchiveRows = $repository->execute(
        "INSERT INTO {$archive} "
            . '(`piwigo_image_id`,`era`,`archive_date`,`date_precision`,`date_confidence`,`date_source`,`event_label`,`official`,`source_submission_id`,`created_at`,`updated_at`) '
            . "VALUES (?, ?, NULL, 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', NULL, 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
        [$imageId, $era],
    );
    if ($insertedArchiveRows !== 1) {
        bulkProjectionFail('temporary_archive_fixture_insert_failed');
    }
    $createdArchiveRow = true;
    ++$assertions;

    $associationBefore = $repository->fetchOne(
        "SELECT COUNT(*) AS `count` FROM `{$prefixeTable}image_category` ic JOIN {$album} a "
            . 'ON a.`piwigo_category_id`=ic.`category_id` WHERE ic.`image_id`=? AND a.`class_album_id`=?',
        [$imageId, \ClassIdentity\DomainSupport::idToBinary($classAlbumId)],
    );
    $directBefore = (int) ($associationBefore['count'] ?? -1);
    if ($directBefore !== 0) {
        bulkProjectionFail('association_fixture_not_real_add');
    }
    $restoreAssociation = true;
    ++$assertions;

    $result = \ClassIdentity\BulkArchiveService::fromPiwigo()->apply(
        $adminUserId,
        [$classPhotoId],
        ['add_album_ids' => [$classAlbumId]],
        $reason,
        false,
    );
    $batchId = is_string($result['batch_id'] ?? null) ? strtolower($result['batch_id']) : null;
    if (!is_string($batchId) || ($result['state'] ?? null) !== 'APPLIED' || (int) ($result['applied_count'] ?? 0) !== 1
        || ($result['projection_rebuild_mode'] ?? null) !== 'FULL_NATIVE_SOURCE'
    ) {
        bulkProjectionFail('bulk_operation_not_applied');
    }
    ++$assertions;

    $stale = $states();
    foreach ($requiredKinds as $kind) {
        if (($stale[$kind]['state'] ?? null) !== 'STALE'
            || ($stale[$kind]['reason'] ?? null) !== 'ARCHIVE_BULK_FINALIZE'
        ) {
            bulkProjectionFail('native_guard_not_stale_' . strtolower($kind));
        }
    }
    $assertions += 5;

    $kinds = \ClassIdentity\ProjectionMutationBoundary::archiveKinds([
        'add_album_ids' => [$classAlbumId],
    ]);
    if ($kinds !== \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds()) {
        bulkProjectionFail('native_guard_recovery_scope_incomplete');
    }
    ++$assertions;

    $rebuilt = \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
    if ((int) ($rebuilt['photos']['count'] ?? 0) < 1) {
        bulkProjectionFail('native_source_catalog_rebuild_failed');
    }
    ++$assertions;
    $active = $states();
    foreach ($requiredKinds as $kind) {
        if (($active[$kind]['state'] ?? null) !== 'ACTIVE') {
            bulkProjectionFail('projection_not_recovered_' . strtolower($kind));
        }
    }
    $assertions += 5;

    $associationAfter = $repository->fetchOne(
        "SELECT COUNT(*) AS `count` FROM `{$prefixeTable}image_category` ic JOIN {$album} a "
            . 'ON a.`piwigo_category_id`=ic.`category_id` WHERE ic.`image_id`=? AND a.`class_album_id`=?',
        [$imageId, \ClassIdentity\DomainSupport::idToBinary($classAlbumId)],
    );
    if ((int) ($associationAfter['count'] ?? 0) !== 1) {
        bulkProjectionFail('duplicate_association_created');
    }
    ++$assertions;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME=FAIL run=' . $run
        . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    try {
        if ($restoreAssociation && is_int($fixtureImageId) && is_int($fixtureCategoryId)) {
            $repository->execute(
                "DELETE FROM `{$prefixeTable}image_category` WHERE `image_id`=? AND `category_id`=?",
                [$fixtureImageId, $fixtureCategoryId],
            );
        }
        if ($createdArchiveRow && is_int($fixtureImageId)) {
            $deleted = $repository->execute(
                "DELETE FROM {$archive} WHERE `piwigo_image_id`=? AND `source_submission_id` IS NULL",
                [$fixtureImageId],
            );
            if ($deleted !== 1) {
                throw new RuntimeException('temporary_archive_fixture_cleanup_failed');
            }
        }
        $cleanupBatch = $batchId;
        if (!is_string($cleanupBatch)) {
            $row = $repository->fetchOne(
                "SELECT `batch_id` FROM {$batch} WHERE `reason`=? ORDER BY `created_at` DESC LIMIT 1",
                [$reason],
            );
            $cleanupBatch = $row === null ? null : \ClassIdentity\DomainSupport::binaryToId((string) $row['batch_id']);
        }
        if (is_string($cleanupBatch)) {
            $repository->transaction(function (\ClassIdentity\Repository $tx) use ($cleanupBatch, $batch, $batchItem, $audit): void {
                $binary = \ClassIdentity\DomainSupport::idToBinary($cleanupBatch);
                $tx->execute("DELETE FROM {$audit} WHERE `target_type`='BATCH_OPERATION' AND `target_id`=?", [$cleanupBatch]);
                $tx->execute("DELETE FROM {$batchItem} WHERE `batch_id`=?", [$binary]);
                $tx->execute("DELETE FROM {$batch} WHERE `batch_id`=?", [$binary]);
            });
        }
        if ($createdArchiveRow || $restoreAssociation) {
            \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
        } else {
            $remaining = $states();
            foreach ($requiredKinds as $kind) {
                if (($remaining[$kind]['state'] ?? null) !== 'ACTIVE') {
                    \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
                    break;
                }
            }
        }
        if (is_int($fixtureImageId) && is_int($fixtureCategoryId)) {
            $association = $repository->fetchOne(
                "SELECT COUNT(*) AS `count` FROM `{$prefixeTable}image_category` WHERE `image_id`=? AND `category_id`=?",
                [$fixtureImageId, $fixtureCategoryId],
            );
            $archiveRows = $repository->fetchOne(
                "SELECT COUNT(*) AS `count` FROM {$archive} WHERE `piwigo_image_id`=?",
                [$fixtureImageId],
            );
            if ((int) ($association['count'] ?? -1) !== 0 || (int) ($archiveRows['count'] ?? -1) !== 0) {
                throw new RuntimeException('temporary_fixture_not_fully_restored');
            }
        }
        $finalStates = $states();
        foreach ($requiredKinds as $kind) {
            if (($finalStates[$kind]['state'] ?? null) !== 'ACTIVE') {
                throw new RuntimeException('projection_cleanup_not_active_' . strtolower($kind));
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, 'CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME_CLEANUP=FAIL run=' . $run
            . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n");
        $exit = 1;
    }
}

if ($exit === 0) {
    fwrite(STDOUT, "CLASS_ARCHIVE_BULK_PROJECTION_RUNTIME=PASS assertions={$assertions} run={$run}\n");
}
exit($exit);
