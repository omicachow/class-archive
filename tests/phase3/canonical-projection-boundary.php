<?php

declare(strict_types=1);

/**
 * Real synthetic-runtime integration gate for canonical-photo projection
 * invalidation. Every fixture write is enclosed in an outer Repository
 * transaction and deliberately rolled back; no Piwigo association, media
 * file, canonical mapping, audit row or projection state is retained.
 */

final class CanonicalProjectionRollback extends RuntimeException
{
}

function canonicalProjectionFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=nginx_user_required\n");
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
    \ClassIdentity\CanonicalPhotoService::class,
    \ClassIdentity\ProjectionMutationBoundary::class,
    \ClassIdentity\Gateway\ReadProjectionStore::class,
    \ClassIdentity\Gateway\PiwigoGatewayAdapter::class,
] as $requiredClass) {
    if (!class_exists($requiredClass)) {
        fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=installed_plugin_unavailable\n");
        exit(1);
    }
}

$repository = \ClassIdentity\Repository::fromPiwigo();
$projectionTable = '`' . $repository->table('read_projection') . '`';
$photoTable = '`' . $repository->table('photo') . '`';
$duplicateTable = '`' . $repository->table('photo_duplicate') . '`';
$batchTable = '`' . $repository->table('batch_operation') . '`';
$batchItemTable = '`' . $repository->table('batch_operation_item') . '`';
$auditTable = '`' . $repository->table('audit_event') . '`';
$requiredKinds = [
    \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
    ...\ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
];

$projectionSnapshot = static function () use ($repository, $projectionTable): array {
    return $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "`item_count`,`payload_json`,HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` FROM {$projectionTable} ORDER BY `projection_key`",
    );
};
$before = $projectionSnapshot();
$states = [];
foreach ($before as $row) {
    $states[(string) $row['projection_key']] = (string) $row['state'];
}
foreach ($requiredKinds as $kind) {
    if (($states[$kind] ?? null) !== 'ACTIVE') {
        fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=projection_not_active_" . strtolower($kind) . "\n");
        exit(1);
    }
}

$admin = $repository->fetchOne(
    'SELECT p.`id` AS `principal_id`,p.`piwigo_user_id` FROM `' . $repository->table('principal') . '` p '
        . 'JOIN ' . USER_INFOS_TABLE . " ui ON ui.`user_id`=p.`piwigo_user_id` "
        . "WHERE p.`principal_type`='SYSTEM_ACCOUNT' AND p.`system_role`='SYSTEM_ADMIN' AND p.`state`='ACTIVE' "
        . "AND p.`account_id` IS NULL AND ui.`status` IN ('admin','webmaster') ORDER BY p.`id` LIMIT 1",
);
if ($admin === null) {
    fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=system_admin_missing\n");
    exit(1);
}
$adminUserId = (int) $admin['piwigo_user_id'];
$adminContext = \ClassIdentity\Access::resolveAuthorizationContext($adminUserId);
if (($adminContext['role'] ?? null) !== \ClassIdentity\Access::ROLE_SYSTEM_ADMIN
    || (int) ($adminContext['principal_id'] ?? 0) !== (int) $admin['principal_id']
) {
    fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=system_admin_context_invalid\n");
    exit(1);
}

$candidateRows = $repository->fetchAll(
    'SELECT p.`class_photo_id` FROM ' . $photoTable . ' p '
        . 'JOIN `' . $repository->table('read_photo') . '` rp ON rp.`class_photo_id`=p.`class_photo_id` '
        . "WHERE p.`state`='ACTIVE' AND p.`piwigo_image_id` IS NOT NULL ORDER BY p.`class_photo_id` LIMIT 24",
);
$photoIds = array_map(
    static fn(array $row): string => \ClassIdentity\DomainSupport::binaryToId((string) $row['class_photo_id']),
    $candidateRows,
);
$targetId = null;
$aliasId = null;
for ($left = 0; $left < count($photoIds) && $targetId === null; ++$left) {
    for ($right = $left + 1; $right < count($photoIds); ++$right) {
        $exists = $repository->fetchOne(
            'SELECT 1 AS `found` FROM ' . $duplicateTable . ' WHERE '
                . '(`left_class_photo_id` IN (?,?) OR `right_class_photo_id` IN (?,?)) LIMIT 1',
            [
                \ClassIdentity\DomainSupport::idToBinary($photoIds[$left]),
                \ClassIdentity\DomainSupport::idToBinary($photoIds[$right]),
                \ClassIdentity\DomainSupport::idToBinary($photoIds[$left]),
                \ClassIdentity\DomainSupport::idToBinary($photoIds[$right]),
            ],
        );
        if ($exists === null) {
            $targetId = $photoIds[$left];
            $aliasId = $photoIds[$right];
            break;
        }
    }
}
if (!is_string($targetId) || !is_string($aliasId)) {
    fwrite(STDERR, "CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason=unused_photo_pair_missing\n");
    exit(1);
}

$source = \ClassIdentity\Gateway\PiwigoGatewayAdapter::fromPiwigo();
$service = new \ClassIdentity\CanonicalPhotoService($repository);
$prepare = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'prepareConsolidationJournal');
$refreshDescriptor = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'canonicalProjectionRefresh');
$assertions = 0;
$exit = 0;

try {
    // PREPARED is the last durable InnoDB boundary before the MyISAM album
    // union. Catalog and aggregate invalidation must commit with the journal,
    // and a bounded target+alias refresh must be able to recover the catalog.
    try {
        $repository->transaction(function (\ClassIdentity\Repository $tx) use (
            $service,
            $prepare,
            $refreshDescriptor,
            $source,
            $targetId,
            $aliasId,
            $adminContext,
            $projectionTable,
            $batchTable,
            $batchItemTable,
            $requiredKinds,
            &$assertions,
        ): never {
            $batchId = \ClassIdentity\DomainSupport::generateId();
            $duplicateId = \ClassIdentity\DomainSupport::generateId();
            $prepare->invoke(
                $service,
                $batchId,
                $adminContext,
                $duplicateId,
                $targetId,
                $aliasId,
                'Synthetic canonical projection boundary',
            );
            $batch = $tx->fetchOne(
                'SELECT `state`,`item_count` FROM ' . $batchTable . ' WHERE `batch_id`=? LIMIT 1',
                [\ClassIdentity\DomainSupport::idToBinary($batchId)],
            );
            $items = $tx->fetchOne(
                'SELECT COUNT(*) AS `count` FROM ' . $batchItemTable . ' WHERE `batch_id`=?',
                [\ClassIdentity\DomainSupport::idToBinary($batchId)],
            );
            if (($batch['state'] ?? null) !== 'PREPARED' || (int) ($batch['item_count'] ?? 0) !== 2 || (int) ($items['count'] ?? 0) !== 2) {
                canonicalProjectionFail('canonical_prepare_journal_missing');
            }
            ++$assertions;
            $projectionRows = $tx->fetchAll(
                'SELECT `projection_key`,`state`,`invalidated_reason` FROM ' . $projectionTable
                    . " WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')",
            );
            $projectionStates = [];
            foreach ($projectionRows as $row) {
                $projectionStates[(string) $row['projection_key']] = $row;
            }
            foreach ($requiredKinds as $kind) {
                if (($projectionStates[$kind]['state'] ?? null) !== 'STALE'
                    || ($projectionStates[$kind]['invalidated_reason'] ?? null) !== 'CANONICAL_CONSOLIDATE'
                ) {
                    canonicalProjectionFail('canonical_prepare_projection_not_stale_' . strtolower($kind));
                }
                ++$assertions;
            }
            $descriptor = $refreshDescriptor->invoke($service, $targetId, $aliasId);
            if (($descriptor['class_photo_ids'] ?? null) !== [$targetId, $aliasId]
                || ($descriptor['projection_kinds'] ?? null) !== \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds()
                || ($descriptor['projection_rebuild_mode'] ?? null) !== 'BOUNDED'
            ) {
                canonicalProjectionFail('canonical_projection_descriptor_invalid');
            }
            ++$assertions;
            $projectionStore = new \ClassIdentity\Gateway\ReadProjectionStore($tx);
            $photoBuildToken = $projectionStore->beginPhotoCatalogBuild();
            $photoCandidates = $source->sourcePhotoCandidatesByIdsForRebuild($descriptor['class_photo_ids']);
            $point = $projectionStore->refreshPhotos(
                $photoCandidates,
                $descriptor['projection_kinds'],
                $photoBuildToken,
            );
            if (($point['updated'] ?? null) !== 2) {
                canonicalProjectionFail('canonical_projection_point_refresh_not_bounded');
            }
            ++$assertions;
            $afterRefresh = $tx->fetchAll(
                'SELECT `projection_key`,`state` FROM ' . $projectionTable
                    . " WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')",
            );
            $refreshStates = [];
            foreach ($afterRefresh as $row) {
                $refreshStates[(string) $row['projection_key']] = (string) $row['state'];
            }
            if (($refreshStates['PHOTO_CATALOG'] ?? null) !== 'ACTIVE') {
                canonicalProjectionFail('canonical_projection_catalog_not_republished');
            }
            foreach (\ClassIdentity\ProjectionMutationBoundary::allAggregateKinds() as $kind) {
                if (($refreshStates[$kind] ?? null) !== 'STALE') {
                    canonicalProjectionFail('canonical_projection_aggregate_reactivated_early_' . strtolower($kind));
                }
            }
            $assertions += 6;
            throw new CanonicalProjectionRollback('rollback_prepare');
        });
        canonicalProjectionFail('canonical_prepare_outer_rollback_missing');
    } catch (CanonicalProjectionRollback $rollback) {
        if ($rollback->getMessage() !== 'rollback_prepare') {
            throw $rollback;
        }
    }
    if ($projectionSnapshot() !== $before) {
        canonicalProjectionFail('canonical_prepare_projection_rollback_failed');
    }
    ++$assertions;

    // Revert changes the logical alias graph itself. The canonical source row,
    // PHOTO_CATALOG invalidation and all aggregate invalidations must share one
    // transaction, then the returned target+alias set must support the same
    // bounded post-commit catalog refresh.
    $duplicateId = \ClassIdentity\DomainSupport::generateId();
    try {
        $repository->transaction(function (\ClassIdentity\Repository $tx) use (
            $service,
            $source,
            $duplicateId,
            $targetId,
            $aliasId,
            $adminUserId,
            $admin,
            $duplicateTable,
            $projectionTable,
            &$assertions,
        ): never {
            $tx->execute(
                'INSERT INTO ' . $duplicateTable
                    . ' (`duplicate_id`,`left_class_photo_id`,`right_class_photo_id`,`relation_kind`,`state`,`canonical_class_photo_id`,'
                    . '`created_by_principal_id`,`reviewed_by_principal_id`,`reason`,`reviewed_at`) '
                    . "VALUES (?,?,?,'EXACT','CONSOLIDATED',?,?,?,'Synthetic canonical revert boundary',UTC_TIMESTAMP(6))",
                [
                    \ClassIdentity\DomainSupport::idToBinary($duplicateId),
                    \ClassIdentity\DomainSupport::idToBinary($targetId),
                    \ClassIdentity\DomainSupport::idToBinary($aliasId),
                    \ClassIdentity\DomainSupport::idToBinary($targetId),
                    (int) $admin['principal_id'],
                    (int) $admin['principal_id'],
                ],
            );
            $descriptor = $service->revertConsolidation(
                $adminUserId,
                $duplicateId,
                'Synthetic canonical revert projection boundary',
            );
            if (($descriptor['class_photo_ids'] ?? null) !== [$targetId, $aliasId]
                || ($descriptor['projection_kinds'] ?? null) !== \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds()
                || ($descriptor['projection_rebuild_mode'] ?? null) !== 'BOUNDED'
            ) {
                canonicalProjectionFail('canonical_revert_projection_descriptor_invalid');
            }
            ++$assertions;
            $reverted = $tx->fetchOne(
                'SELECT `state`,`canonical_class_photo_id` FROM ' . $duplicateTable . ' WHERE `duplicate_id`=? LIMIT 1',
                [\ClassIdentity\DomainSupport::idToBinary($duplicateId)],
            );
            if (($reverted['state'] ?? null) !== 'REVERTED' || ($reverted['canonical_class_photo_id'] ?? null) !== null) {
                canonicalProjectionFail('canonical_revert_source_not_updated');
            }
            ++$assertions;
            $stale = $tx->fetchAll(
                'SELECT `projection_key`,`state`,`invalidated_reason` FROM ' . $projectionTable
                    . " WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')",
            );
            foreach ($stale as $row) {
                if (($row['state'] ?? null) !== 'STALE' || ($row['invalidated_reason'] ?? null) !== 'CANONICAL_REVERT') {
                    canonicalProjectionFail('canonical_revert_projection_not_stale_' . strtolower((string) $row['projection_key']));
                }
                ++$assertions;
            }
            $projectionStore = new \ClassIdentity\Gateway\ReadProjectionStore($tx);
            $photoBuildToken = $projectionStore->beginPhotoCatalogBuild();
            $photoCandidates = $source->sourcePhotoCandidatesByIdsForRebuild($descriptor['class_photo_ids']);
            $point = $projectionStore->refreshPhotos(
                $photoCandidates,
                $descriptor['projection_kinds'],
                $photoBuildToken,
            );
            if (($point['updated'] ?? null) !== 2) {
                canonicalProjectionFail('canonical_revert_point_refresh_not_bounded');
            }
            ++$assertions;
            throw new CanonicalProjectionRollback('rollback_revert');
        });
        canonicalProjectionFail('canonical_revert_outer_rollback_missing');
    } catch (CanonicalProjectionRollback $rollback) {
        if ($rollback->getMessage() !== 'rollback_revert') {
            throw $rollback;
        }
    }
    if ($projectionSnapshot() !== $before) {
        canonicalProjectionFail('canonical_revert_projection_rollback_failed');
    }
    if ($repository->fetchOne(
        'SELECT 1 AS `found` FROM ' . $duplicateTable . ' WHERE `duplicate_id`=? LIMIT 1',
        [\ClassIdentity\DomainSupport::idToBinary($duplicateId)],
    ) !== null) {
        canonicalProjectionFail('canonical_revert_fixture_leaked');
    }
    if ($repository->fetchOne(
        'SELECT 1 AS `found` FROM ' . $auditTable . ' WHERE `target_id`=? LIMIT 1',
        [$duplicateId],
    ) !== null) {
        canonicalProjectionFail('canonical_revert_audit_fixture_leaked');
    }
    $assertions += 3;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'CLASS_ARCHIVE_CANONICAL_PROJECTION=FAIL reason='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
}

if ($exit === 0) {
    fwrite(STDOUT, "CLASS_ARCHIVE_CANONICAL_PROJECTION=PASS assertions={$assertions}\n");
}
exit($exit);
