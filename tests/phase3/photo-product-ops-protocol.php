<?php

declare(strict_types=1);

/**
 * Static Phase 3.2 gate for backup, maintenance, reconciliation and System
 * Health coverage of the ClassIdentity v8 product-domain state.
 *
 * The destructive restore drill remains a separate, explicitly confirmed
 * synthetic-only test. This gate never connects to a database or removes a
 * volume.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'compose' => $root . '/infra/docker-compose.yml',
    'restore' => $root . '/infra/scripts/restore-backup.sh',
    'audit' => $root . '/infra/scripts/audit-backup.sh',
    'fixture' => $root . '/infra/scripts/capture-restore-fixture.php',
    'drill' => $root . '/infra/scripts/backup-restore-drill.ps1',
    'backup_evidence' => $root . '/plugins/ClassIdentity/src/BackupRestoreEvidence.php',
    'maintenance' => $root . '/infra/scripts/run-maintenance.php',
    'reconciliation' => $root . '/plugins/ClassIdentity/src/ReconciliationService.php',
    'admin' => $root . '/plugins/ClassIdentity/src/AdminService.php',
    'canonical' => $root . '/plugins/ClassIdentity/src/CanonicalPhotoService.php',
    'bulk' => $root . '/plugins/ClassIdentity/src/BulkArchiveService.php',
    'people' => $root . '/plugins/ClassIdentity/src/PersonCurationService.php',
    'gateway_http' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
];
$sources = [];
foreach ($paths as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "PHOTO_PRODUCT_OPS=FAIL missing={$name}\n");
        exit(1);
    }
    $sources[$name] = $source;
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};
$position = static function (string $source, string $needle): int {
    $offset = strpos($source, $needle);
    return $offset === false ? -1 : $offset;
};

$v8Tables = [
    'person_merge',
    'person_photo_rule',
    'album',
    'spotlight',
    'photo_source',
    'photo_duplicate',
    'batch_operation',
    'batch_operation_item',
];
foreach ($v8Tables as $table) {
    $assert(str_contains($sources['fixture'], "'{$table}' =>"), "restore fixture omits {$table}");
    $assert(str_contains($sources['compose'], '"' . $table . '"'), "backup manifest omits {$table}");
    $assert(str_contains($sources['restore'], '"' . $table . '"'), "restore verifier omits {$table}");
    $assert(str_contains($sources['audit'], '"' . $table . '"'), "backup auditor omits {$table}");
    $assert(str_contains($sources['admin'], "'{$table}',"), "System Health omits {$table}");
}

$assert(str_contains($sources['fixture'], "'fixture_version' => 2"), 'restore fixture version was not advanced');
$assert(str_contains($sources['fixture'], "'class_identity_schema_version' => 8"), 'restore fixture does not attest schema v8');
$assert(str_contains($sources['compose'], '"format":4'), 'backup manifest format was not advanced');
$backupSchemaCheck = $position($sources['compose'], 'ClassIdentity schema v8 is not current');
$databaseDump = $position($sources['compose'], 'mariadb-dump --quick --lock-all-tables');
$assert($backupSchemaCheck >= 0 && $databaseDump >= 0 && $backupSchemaCheck < $databaseDump, 'backup must prove schema v8 before the SQL snapshot');
$assert(str_contains($sources['backup_evidence'], 'public const VERSION = 2'), 'old restore evidence was not invalidated');
$assert(str_contains($sources['backup_evidence'], 'public const FIXTURE_VERSION = 2'), 'restore evidence is not bound to fixture v2');
$assert(str_contains($sources['backup_evidence'], 'public const CLASS_IDENTITY_SCHEMA_VERSION = 8'), 'restore evidence is not bound to schema v8');
$assert(str_contains($sources['backup_evidence'], 'public const BACKUP_MANIFEST_FORMAT = 4'), 'restore evidence is not bound to manifest format 4');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/capture-restore-fixture.php'), 'restore evidence digest omits the fixture implementation');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/audit-backup.sh'), 'restore evidence digest omits the backup auditor');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/restore-backup.sh'), 'restore evidence digest omits the restore implementation');
$assert(str_contains($sources['restore'], 'restore_business_manifest_invalid'), 'restore does not fail closed on old business manifests');
$manifestCheck = $position($sources['restore'], 'restore_business_manifest_invalid');
$clearTarget = $position($sources['restore'], 'clear_target()');
$assert($manifestCheck >= 0 && $clearTarget >= 0 && $manifestCheck < $clearTarget, 'business manifest must be checked before target deletion');
$databaseRestore = $position($sources['restore'], 'restore_database_failed');
$volumeRestore = $position($sources['restore'], 'restore_piwigo_data_failed');
$restoredSchemaCheck = $position($sources['restore'], 'restore_class_identity_schema_ambiguous');
$assert(
    $databaseRestore >= 0 && $restoredSchemaCheck > $databaseRestore && $volumeRestore > $restoredSchemaCheck,
    'restored schema v8 must be verified before application volumes are repopulated',
);
$assert(str_contains($sources['audit'], '[ "$business_manifest" = 1 ]'), 'backup freshness does not require the v8 business manifest');

$expire = $position($sources['maintenance'], 'SpotlightService::fromPiwigo()->expireDue()');
$scan = $position($sources['maintenance'], 'ReconciliationService::fromPiwigo()->scanAndPersist()');
$assert($expire >= 0 && $scan >= 0 && $expire < $scan, 'due Spotlight rows must expire before reconciliation');
$assert(str_contains($sources['maintenance'], "'automatic_scope' => 'SERVER_DEADLINE_ONLY'"), 'Spotlight auto-expiry scope is not explicit');
$assert(str_contains($sources['maintenance'], "'expired_spotlights'"), 'maintenance evidence omits Spotlight expiry');

$reconciliationCodes = [
    'BATCH_OPERATION_PREPARED',
    'BATCH_OPERATION_MANUAL_REVIEW',
    'ALBUM_PIWIGO_CATEGORY_MISSING',
    'ALBUM_COVER_MAPPING_DRIFT',
    'ALBUM_COMMUNITY_OWNER_DRIFT',
    'SPOTLIGHT_EXPIRY_PENDING',
    'SPOTLIGHT_TARGET_DRIFT',
    'PERSON_IDENTITY_LINK_DRIFT',
    'PERSON_MERGE_CYCLE',
    'PERSON_PHOTO_RULE_DRIFT',
    'PHOTO_DUPLICATE_EXACT_CHECKSUM_DRIFT',
    'PHOTO_DUPLICATE_CROSS_ERA_DRIFT',
    'PHOTO_DUPLICATE_ALIAS_CHAIN',
    'PHOTO_SOURCE_REFERENCE_DRIFT',
];
foreach ($reconciliationCodes as $code) {
    $assert(str_contains($sources['reconciliation'], "'{$code}'"), "reconciliation omits {$code}");
}
$assert(str_contains($sources['reconciliation'], "'SPOTLIGHT_EXPIRY_PENDING', 'SAFE_AUTO_FIX'"), 'only exact Spotlight expiry may be classified safe');
$assert(!str_contains($sources['reconciliation'], 'DELETE FROM'), 'reconciliation must never delete product state');
$assert(!str_contains($sources['reconciliation'], 'UPDATE `'), 'reconciliation must remain read-only');

$assert(str_contains($sources['canonical'], 'public function canonicalMapFor(array $classPhotoIds): array'), 'canonical alias resolution is not bulk/fail-closed');
$assert(str_contains($sources['canonical'], 'public function canonicalGroupIds(string $classPhotoId): array'), 'canonical provenance cannot include active aliases');
$insertRace = $position($sources['canonical'], '$inserted = $this->repository->execute(');
$ownedAssociation = $position($sources['canonical'], 'if ($inserted === 1)');
$compensationRecord = $position($sources['canonical'], '$added[] = $categoryId;');
$assert(
    $insertRace >= 0 && $ownedAssociation > $insertRace && $compensationRecord > $ownedAssociation,
    'duplicate compensation must record only associations inserted by this attempt',
);

$lockAcquire = $position($sources['bulk'], '$locks = $this->acquirePhotoLocks($ids);');
$lockedOperation = $position($sources['bulk'], '$result = $this->applyWhileLocked(');
$lockRelease = $position($sources['bulk'], '$released = $this->releasePhotoLocks($locks);');
$assert(
    $lockAcquire >= 0 && $lockedOperation > $lockAcquire && $lockRelease > $lockedOperation,
    'bulk photo state and MyISAM saga must remain inside the per-photo lock lifetime',
);
$assert(str_contains($sources['bulk'], '$rows = $this->loadPhotoArchiveRows($ids);'), 'locked bulk operation omits fresh photo state read');
$assert(str_contains($sources['bulk'], 'SELECT GET_LOCK(?, ?) AS `acquired`'), 'bulk writer omits MariaDB per-photo serialization');
$assert(str_contains($sources['bulk'], 'SELECT RELEASE_LOCK(?) AS `released`'), 'bulk writer does not explicitly release photo locks');
$assert(str_contains($sources['bulk'], 'INSERT IGNORE INTO `'), 'bulk association add is not atomic/idempotent');
$assert(str_contains($sources['bulk'], 'return $changed === 1;'), 'bulk compensation ownership is not based on affected rows');
$assert(
    str_contains($sources['bulk'], "'failed_count' => \$failedCount")
        && !str_contains($sources['bulk'], "'failed_count' => 1"),
    'bulk failure audit must report the real failed item count',
);
$lockAcquire = $position($sources['canonical'], '$this->acquireConsolidationLock($consolidationLock);');
$journalPrepare = $position($sources['canonical'], '$this->prepareConsolidationJournal(');
$associationUnion = $position($sources['canonical'], '$this->unionPiwigoAssociations(');
$lockRelease = $position($sources['canonical'], '$this->releaseConsolidationLock($consolidationLock);');
$assert(
    $lockAcquire >= 0 && $journalPrepare > $lockAcquire && $associationUnion > $journalPrepare && $lockRelease > $associationUnion,
    'exact consolidation must hold one advisory lease across durable journal and MyISAM union',
);
$assert(str_contains($sources['canonical'], 'SELECT GET_LOCK(?, 0) AS `acquired`') && str_contains($sources['canonical'], 'SELECT RELEASE_LOCK(?) AS `released`'), 'consolidation lease is not implemented with MariaDB advisory locking');
$assert(str_contains($sources['canonical'], 'class_archive_photo_duplicate_archive_metadata_conflict'), 'exact consolidation does not fail closed on archive metadata conflict');
$assert(str_contains($sources['canonical'], "'ARCHIVE_METADATA_CONFLICT'"), 'archive metadata conflict is not persisted for manual review');

$assert(str_contains($sources['people'], 'public function movePhotos('), 'person photo correction does not expose an atomic batch operation');
$assert(str_contains($sources['people'], 'return $this->repository->transaction(function (Repository $repository)'), 'person photo correction is not enclosed by one repository transaction');
$personMutation = $position($sources['gateway_http'], '->movePhotos(');
$personMutationLoop = $position($sources['gateway_http'], 'foreach ($photos as $photo)');
$assert($personMutation >= 0 && $personMutationLoop === -1, 'Gateway must not partially commit a multi-photo person correction');
$assert(
    str_contains($sources['people'], "'rule' => \$before[strtolower(\$fromClassPersonId)] ?? 'INHERITED'")
        && !str_contains($sources['people'], "'old_value' => ['class_person_id' => \$fromClassPersonId, 'rule' => 'INCLUDE']"),
    'person move audit must record observed overlay state instead of claiming inherited membership',
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "PHOTO_PRODUCT_OPS_ASSERTION_FAILED: {$failure}\n");
    }
    fwrite(STDERR, 'PHOTO_PRODUCT_OPS=FAIL assertions=' . $assertions . ' failures=' . count($failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'PHOTO_PRODUCT_OPS=PASS assertions=' . $assertions . "\n");
