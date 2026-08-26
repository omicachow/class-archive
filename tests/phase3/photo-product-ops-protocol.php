<?php

declare(strict_types=1);

/**
 * Static Phase 3.2 gate for backup, maintenance, reconciliation and System
 * Health coverage of the ClassIdentity v16 product-domain state and the
 * cache-free materialized read-projection recovery contract.
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
    'evidence_writer' => $root . '/infra/scripts/write-backup-restore-evidence.php',
    'projection_rebuild' => $root . '/infra/scripts/rebuild-photo-read-projection.php',
    'maintenance' => $root . '/infra/scripts/run-maintenance.php',
    'reconciliation' => $root . '/plugins/ClassIdentity/src/ReconciliationService.php',
    'ai_index' => $root . '/plugins/ClassIdentity/src/AiIndexService.php',
    'admin' => $root . '/plugins/ClassIdentity/src/AdminService.php',
    'canonical' => $root . '/plugins/ClassIdentity/src/CanonicalPhotoService.php',
    'bulk' => $root . '/plugins/ClassIdentity/src/BulkArchiveService.php',
    'people' => $root . '/plugins/ClassIdentity/src/PersonCurationService.php',
    'gateway_http' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
    'schema' => $root . '/plugins/ClassIdentity/src/Schema.php',
    'plugin_maintain' => $root . '/plugins/ClassIdentity/maintain.class.php',
    'dev' => $root . '/infra/scripts/dev.ps1',
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

$businessTables = [
    'person_merge',
    'person_photo_rule',
    'album',
    'spotlight',
    'photo_source',
    'photo_source_presentation',
    'photo_duplicate',
    'batch_operation',
    'batch_operation_item',
    'photo_comment',
    'auto_collection',
    'auto_collection_photo',
    'ai_asset_index',
    'ai_index_job',
];
foreach ($businessTables as $table) {
    $assert(str_contains($sources['fixture'], "'{$table}' =>"), "restore fixture omits {$table}");
    $assert(str_contains($sources['compose'], '"' . $table . '"'), "backup manifest omits {$table}");
    $assert(str_contains($sources['restore'], '"' . $table . '"'), "restore verifier omits {$table}");
    $assert(str_contains($sources['audit'], '"' . $table . '"'), "backup auditor omits {$table}");
    $assert(str_contains($sources['admin'], "'{$table}',"), "System Health omits {$table}");
}

// The full private library is a durable business import, but its fixture
// representation must remain path-free and must never expose original source
// filenames. These tables are included in backup/restore and health checks as
// opaque import/provenance state, not as a public data source.
$privateLibraryTables = [
    'private_library_collection',
    'private_library_folder',
    'private_library_import',
    'private_library_import_item',
];
foreach ($privateLibraryTables as $table) {
    $assert(str_contains($sources['fixture'], "'{$table}' =>"), "restore fixture omits {$table}");
    $assert(str_contains($sources['compose'], '"' . $table . '"'), "backup manifest omits {$table}");
    $assert(str_contains($sources['restore'], '"' . $table . '"'), "restore verifier omits {$table}");
    $assert(str_contains($sources['audit'], '"' . $table . '"'), "backup auditor omits {$table}");
    $assert(str_contains($sources['admin'], "'{$table}',"), "System Health omits {$table}");
    $assert(str_contains($sources['schema'], "'{$table}'"), "schema omits {$table}");
}

$assert(str_contains($sources['fixture'], "'fixture_version' => 8"), 'restore fixture version was not advanced');
$assert(str_contains($sources['fixture'], "'class_identity_schema_version' => 16"), 'restore fixture does not attest schema v16');
$assert(str_contains($sources['fixture'], "'native_source_epoch' =>"), 'restore fixture omits the durable native source epoch');
$assert(str_contains($sources['fixture'], "'policy' => 'REBUILD_FROM_BUSINESS_TRUTH'"), 'restore fixture does not separate projection cache from business truth');
$assert(str_contains($sources['fixture'], "'projection' => 'ALL'") && str_contains($sources['fixture'], "'required_active' => ['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT']"), 'restore fixture does not require all materialized projections');
$assert(!str_contains($sources['fixture'], "'read_projection' =>") && !str_contains($sources['fixture'], "'read_photo' =>"), 'restore fixture must not fingerprint rebuildable projection rows');
$assert(str_contains($sources['compose'], '"format":8'), 'backup manifest format was not advanced');
$assert(str_contains($sources['compose'], '"rebuildable_projection_tables":["read_projection","read_photo"]'), 'backup manifest does not classify v16 projection tables as rebuildable cache');
$assert(str_contains($sources['compose'], '"projection_rebuild":"ALL"'), 'backup manifest does not require full projection rebuild');
$assert(str_contains($sources['compose'], '"native_source_epoch"'), 'backup manifest omits the durable native source epoch business table');
$backupSchemaCheck = $position($sources['compose'], 'ClassIdentity schema v16 is not current');
$projectionDataOmission = $position($sources['compose'], '--ignore-table-data="$${DB_NAME}.$${ci_base}read_projection"');
$databaseDump = $position($sources['compose'], 'mariadb-dump --quick --lock-all-tables');
$assert($backupSchemaCheck >= 0 && $databaseDump >= 0 && $backupSchemaCheck < $databaseDump, 'backup must prove schema v16 before the SQL snapshot');
$assert(str_contains($sources['compose'], "ENGINE='MyISAM'") && str_contains($sources['compose'], "source_key='PIWIGO_NATIVE'") && str_contains($sources['compose'], 'OCTET_LENGTH(generation)=16'), 'backup must prove the durable epoch is a valid MyISAM singleton before dumping');
$assert(str_contains($sources['compose'], 'ci_source_epoch_images_bi') && str_contains($sources['compose'], 'ci_source_epoch_categories_bd') && str_contains($sources['compose'], 'test "$${ci_trigger_count}" = 18'), 'backup must prove all 18 native projection/source-epoch triggers');
$assert(str_contains($sources['schema'], "public const LOCKED_PIWIGO_VERSION = '16.4.0'"), 'native trigger integration must remain locked to Piwigo 16.4.0');
$assert(str_contains($sources['schema'], 'self::assertLockedPiwigoRuntime();'), 'Piwigo Schema factory must enforce the exact Core compatibility lock');
$assert(str_contains($sources['schema'], 'prepareNativeMutationProtectionForActivation'), 'Schema must expose guarded trigger reinstallation');
$assert(str_contains($sources['schema'], 'retireNativeMutationProtection'), 'Schema must expose trigger retirement');
$retireInvalidate = $position($sources['schema'], "invalidateNativeLifecycleState('PLUGIN_LIFECYCLE_RETIRED', false)");
$retireDrop = $position($sources['schema'], "'DROP TRIGGER IF EXISTS '");
$assert($retireInvalidate >= 0 && $retireDrop > $retireInvalidate, 'retirement must invalidate projections before dropping native guards');
$assert(str_contains($sources['plugin_maintain'], '$schema->prepareNativeMutationProtectionForActivation();'), 'install/activation maintenance must restore native guards');
$assert(substr_count($sources['plugin_maintain'], '$this->retireNativeMutationProtection();') >= 3, 'install/deactivate/uninstall lifecycle must retire native guards');
$assert(str_contains($sources['plugin_maintain'], 'fromPiwigoForRetirement'), 'retirement must remain available after an accidental unsupported Core upgrade');
$phase0StateTransition = $position($sources['dev'], "'tests\\phase0\\media-guard-state-transitions.ps1'");
$phase0ProjectionRecovery = $position($sources['dev'], "'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php'");
$assert($phase0StateTransition >= 0 && $phase0ProjectionRecovery > $phase0StateTransition, 'phase0 native fixtures must restore the persistent read projection');
$assert($projectionDataOmission > $databaseDump, 'backup must preserve projection DDL while omitting cache rows');
$assert(str_contains($sources['compose'], '--ignore-table-data="$${DB_NAME}.$${ci_base}read_photo"'), 'backup does not omit read_photo cache rows');
$assert(str_contains($sources['compose'], 'rebuildable projection cache leaked into the SQL snapshot'), 'backup does not verify that projection cache rows were omitted');
$assert(str_contains($sources['backup_evidence'], 'public const VERSION = 8'), 'old restore evidence was not invalidated');
$assert(str_contains($sources['backup_evidence'], 'public const FIXTURE_VERSION = 8'), 'restore evidence is not bound to fixture v8');
$assert(str_contains($sources['backup_evidence'], 'public const CLASS_IDENTITY_SCHEMA_VERSION = 16'), 'restore evidence is not bound to schema v16');
$assert(str_contains($sources['backup_evidence'], 'public const BACKUP_MANIFEST_FORMAT = 8'), 'restore evidence is not bound to manifest format 8');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/capture-restore-fixture.php'), 'restore evidence digest omits the fixture implementation');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/audit-backup.sh'), 'restore evidence digest omits the backup auditor');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/restore-backup.sh'), 'restore evidence digest omits the restore implementation');
$assert(str_contains($sources['backup_evidence'], '/workspace/infra/scripts/rebuild-photo-read-projection.php'), 'restore evidence digest omits projection rebuild implementation');
$assert(str_contains($sources['backup_evidence'], '/workspace/plugins/ClassIdentity/src/AiIndexService.php'), 'restore evidence digest omits AI index control plane');
$assert(str_contains($sources['evidence_writer'], 'projection-count') && str_contains($sources['evidence_writer'], "['projection_count']"), 'restore evidence does not record rebuilt PHOTO_CATALOG count');
$assert(str_contains($sources['evidence_writer'], 'aggregate-count') && str_contains($sources['evidence_writer'], "['aggregate_count']"), 'restore evidence does not record rebuilt aggregate count');
$assert(str_contains($sources['restore'], 'restore_business_manifest_invalid'), 'restore does not fail closed on old business manifests');
$manifestCheck = $position($sources['restore'], 'restore_business_manifest_invalid');
$clearTarget = $position($sources['restore'], 'clear_target()');
$assert($manifestCheck >= 0 && $clearTarget >= 0 && $manifestCheck < $clearTarget, 'business manifest must be checked before target deletion');
$databaseRestore = $position($sources['restore'], 'restore_database_failed');
$volumeRestore = $position($sources['restore'], 'restore_piwigo_data_failed');
$restoredSchemaCheck = $position($sources['restore'], 'restore_class_identity_schema_ambiguous');
$assert(
    $databaseRestore >= 0 && $restoredSchemaCheck > $databaseRestore && $volumeRestore > $restoredSchemaCheck,
    'restored schema v16 must be verified before application volumes are repopulated',
);
$assert(str_contains($sources['restore'], 'restore_projection_cache_present'), 'restore does not fail closed when a backup contains projection cache rows');
$projectionPrecheck = $position($sources['restore'], "grep -Eq '^INSERT INTO `[^`]+class_identity_(read_projection|read_photo)`'");
$assert($projectionPrecheck >= 0 && $projectionPrecheck < $clearTarget, 'projection cache contamination must be rejected before target deletion');
$assert(str_contains($sources['restore'], "('PHOTO_CATALOG','STALE',RANDOM_BYTES(16))"), 'restore does not seed PHOTO_CATALOG as stale with a valid build generation');
$assert(str_contains($sources['restore'], 'OCTET_LENGTH(generation)=16') && str_contains($sources['restore'], 'native_source_generation IS NULL'), 'restore does not verify build generations while leaving the stale catalog epoch unbound');
$projectionRebuild = $position($sources['drill'], '$projectionRebuild = Invoke-ReadProjectionRebuild');
$firstBaseline = $position($sources['drill'], "Invoke-Dev 'baseline-verify'");
$postRestoreBaseline = $firstBaseline < 0 ? false : strpos($sources['drill'], "Invoke-Dev 'baseline-verify'", $firstBaseline + 1);
$postRestoreBaseline = $postRestoreBaseline === false ? -1 : $postRestoreBaseline;
$assert($projectionRebuild >= 0 && $postRestoreBaseline > $projectionRebuild, 'restore drill must rebuild all read projections before post-restore regressions');
$assert(str_contains($sources['drill'], "'--scope=all'") && str_contains($sources['drill'], '[int]$catalog[0].count -ne 72'), 'restore drill does not verify PHOTO_CATALOG ACTIVE/count');
$assert(str_contains($sources['drill'], "@('TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT')") && str_contains($sources['drill'], '$activeAggregates.Count -ne 5'), 'restore drill does not verify all materialized aggregate states');
$epochPrecheck = $position($sources['restore'], 'restore_native_source_epoch_invalid');
$assert($epochPrecheck >= 0 && $epochPrecheck < $clearTarget, 'native source epoch dump must be rejected before target deletion');
$assert(str_contains($sources['restore'], 'epoch_engine') && str_contains($sources['restore'], 'MyISAM') && str_contains($sources['restore'], "source_key='PIWIGO_NATIVE'") && str_contains($sources['restore'], 'OCTET_LENGTH(generation)=16'), 'restore must verify the durable epoch engine and singleton row after import');
$assert(str_contains($sources['restore'], '[ "$ci_trigger_count" = 18 ]'), 'restore must verify all 18 native projection/source-epoch triggers');
$assert(str_contains($sources['audit'], '[ "$business_manifest" = 1 ]'), 'backup freshness does not require the v16 cache-free business manifest');
$assert(str_contains($sources['audit'], '[ "$projection_cache_free" = 1 ]'), 'backup freshness does not verify projection cache omission');
$assert(str_contains($sources['audit'], '[ "$durable_epoch_valid" = 1 ]'), 'backup freshness does not verify the durable epoch dump');
$assert(str_contains($sources['audit'], '[ "$native_guard_count" = 18 ]'), 'backup freshness does not verify all 18 native guards');

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
$assert(
    substr_count($sources['canonical'], 'ProjectionMutationBoundary::invalidatePhotos(') >= 2
        && str_contains($sources['canonical'], "'CANONICAL_CONSOLIDATE'")
        && str_contains($sources['canonical'], "'CANONICAL_REVERT'"),
    'canonical consolidation/revert must invalidate PHOTO_CATALOG and every aggregate at the durable source transaction',
);
$prepareMethod = $position($sources['canonical'], 'private function prepareConsolidationJournal(');
$canonicalInvalidation = $prepareMethod < 0
    ? -1
    : (int) strpos($sources['canonical'], 'ProjectionMutationBoundary::invalidatePhotos(', $prepareMethod);
$canonicalJournalInsert = $prepareMethod < 0
    ? -1
    : (int) strpos($sources['canonical'], "'EXACT_DUPLICATE_CONSOLIDATE', 'PREPARED'", $prepareMethod);
$assert(
    $prepareMethod >= 0 && $canonicalInvalidation > $prepareMethod && $canonicalJournalInsert > $canonicalInvalidation,
    'canonical catalog invalidation must commit with PREPARED before the MyISAM association union',
);
$assert(
    str_contains($sources['canonical'], "'class_photo_ids' => [\$target, \$alias]")
        && str_contains($sources['canonical'], "'projection_kinds' => ProjectionMutationBoundary::allAggregateKinds()"),
    'canonical writes do not return the bounded target/alias refresh set',
);
$duplicateMutation = $position($sources['gateway_http'], 'CanonicalPhotoService::fromPiwigo()->consolidateExact(');
$duplicatePointRefresh = $position($sources['gateway_http'], 'ReadProjectionBuilder::rebuildChangedPhotos(');
$assert(
    $duplicateMutation >= 0 && $duplicatePointRefresh > $duplicateMutation,
    'canonical consolidation must point-refresh target and alias after the durable source commit',
);

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
