<?php

declare(strict_types=1);

/**
 * Static contract for the additive format-9/schema-17 synthetic recovery
 * boundary. It never starts Docker, mounts media, or touches a database.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'contracts' => $root . '/infra/scripts/class-archive-recovery-contracts.sh',
    'compose' => $root . '/infra/docker-compose.yml',
    'restore' => $root . '/infra/scripts/restore-backup.sh',
    'audit' => $root . '/infra/scripts/audit-backup.sh',
    'fixture' => $root . '/infra/scripts/capture-restore-fixture.php',
    'evidence' => $root . '/plugins/ClassIdentity/src/BackupRestoreEvidence.php',
    'writer' => $root . '/infra/scripts/write-backup-restore-evidence.php',
    'v17_backup' => $root . '/infra/scripts/create-v17-synthetic-db-backup.sh',
    'v17_restore' => $root . '/infra/scripts/restore-v17-synthetic-db-backup.sh',
    'v17_fixture' => $root . '/infra/scripts/capture-v17-synthetic-recovery-fixture.sh',
    'marker' => $root . '/infra/scripts/seed-v17-synthetic-recovery-marker.php',
    'overlay' => $root . '/infra/v4-synthetic-migration/docker-compose.override.yml',
];
$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "V17_BACKUP_RESTORE_CONTRACT=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$assertions = 0;
$failures = [];
$privateSourcePathMarker = 'M' . chr(58) . chr(92) . 'private-media-root';
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};
$position = static function (string $contents, string $needle): int {
    $position = strpos($contents, $needle);
    return $position === false ? -1 : $position;
};

/**
 * Exercise the shell selector without Docker or a database. The temporary
 * manifest exists only under the test process temp directory and contains
 * contract JSON, never a snapshot or private fixture.
 */
$selectManifest = static function (int $format, string $shape, bool $expected) use ($paths, $assert): void {
    $temporary = tempnam(sys_get_temp_dir(), 'ca-v17-contract-');
    if (!is_string($temporary)) {
        $assert(false, 'manifest_selector_temp_unavailable');
        return;
    }
    $contract = $paths['contracts'];
    $command = match ($shape) {
        'full' => 'set -eu; . ' . escapeshellarg($contract)
            . '; ca_recovery_select_by_format ' . $format
            . '; printf \'{"format":' . $format . ',"created_at":"20260828T000000Z","class_identity_schema":%s,"files":["database.sql.gz","piwigo-data.tar.gz","uploads.tar.gz","galleries.tar.gz","scripts.tar.gz","COMPLETE"]}\' "$CA_RECOVERY_SCHEMA_JSON" > ' . escapeshellarg($temporary)
            . '; ca_recovery_select_manifest ' . escapeshellarg($temporary),
        'v17-db-only' => 'set -eu; . ' . escapeshellarg($contract)
            . '; ca_recovery_select_by_format ' . $format
            . '; printf \'{"format":' . $format . ',"created_at":"20260828T000000Z","class_identity_schema":%s,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED"}\' "$CA_RECOVERY_SCHEMA_JSON" > ' . escapeshellarg($temporary)
            . '; ca_recovery_select_v17_synthetic_manifest ' . escapeshellarg($temporary),
        'v17-schema-mismatch' => 'set -eu; . ' . escapeshellarg($contract)
            . '; ca_recovery_select_by_format 8'
            . '; printf \'{"format":9,"created_at":"20260828T000000Z","class_identity_schema":%s,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED"}\' "$CA_RECOVERY_SCHEMA_JSON" > ' . escapeshellarg($temporary)
            . '; ca_recovery_select_v17_synthetic_manifest ' . escapeshellarg($temporary),
        'unknown-format' => 'printf \'{"format":777,"created_at":"20260828T000000Z","class_identity_schema":{},"files":[]}\' > ' . escapeshellarg($temporary)
            . '; set -eu; . ' . escapeshellarg($contract)
            . '; ca_recovery_select_manifest ' . escapeshellarg($temporary),
        default => throw new LogicException('unknown_test_manifest_shape'),
    };
    $process = proc_open(['sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $exit = 1;
    if (is_resource($process)) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }
    @unlink($temporary);
    $assert(($exit === 0) === $expected, "manifest_selector_{$shape}_format_{$format}");
};

$format8Start = $position($source['contracts'], '    8)');
$format9Start = $position($source['contracts'], '    9)');
$formatDefault = $position($source['contracts'], '    *)');
$format8 = ($format8Start < 0 || $format9Start <= $format8Start) ? '' : substr($source['contracts'], $format8Start, $format9Start - $format8Start);
$format9 = ($format9Start < 0 || $formatDefault <= $format9Start) ? '' : substr($source['contracts'], $format9Start, $formatDefault - $format9Start);
$assert($format8 !== '' && str_contains($format8, 'CA_RECOVERY_FORMAT=8') && str_contains($format8, 'CA_RECOVERY_SCHEMA_VERSION=16'), 'format8_schema16_contract_missing');
$assert($format9 !== '' && str_contains($format9, 'CA_RECOVERY_FORMAT=9') && str_contains($format9, 'CA_RECOVERY_SCHEMA_VERSION=17'), 'format9_schema17_contract_missing');
$assert(!str_contains($format8, 'collection_snapshot') && !str_contains($format8, 'collection_pin'), 'format8_must_not_absorb_v17_tables');
foreach ([
    'collection_snapshot', 'collection_snapshot_item', 'collection_snapshot_pointer',
    'collection_pin', 'collection_feedback', 'collection_maintenance_state',
] as $table) {
    $assert(str_contains($format9, '"' . $table . '"') && str_contains($format9, ' ' . $table), "format9_missing_{$table}");
}
$assert(str_contains($source['contracts'], 'ca_recovery_select_by_schema') && str_contains($source['contracts'], '16) ca_recovery_select_by_format 8') && str_contains($source['contracts'], '17) ca_recovery_select_by_format 9'), 'schema_dispatch_not_exact');
$assert(str_contains($source['contracts'], 'ca_recovery_select_manifest') && str_contains($source['contracts'], 'ca_recovery_select_v17_synthetic_manifest') && str_contains($source['contracts'], 'ca_recovery_select_by_format "$_ca_format"'), 'manifest_dispatch_not_fail_closed');
$selectManifest(8, 'full', true);
$selectManifest(9, 'full', true);
$selectManifest(9, 'v17-db-only', true);
$selectManifest(8, 'v17-schema-mismatch', false);
$selectManifest(777, 'unknown-format', false);

$assert(str_contains($source['compose'], '. /workspace/infra/scripts/class-archive-recovery-contracts.sh'), 'backup_does_not_load_versioned_contract');
$backupSelector = $position($source['compose'], 'ca_recovery_select_by_schema "$${ci_version}"');
$backupDump = $position($source['compose'], 'mariadb-dump --quick --lock-all-tables');
$assert($backupSelector >= 0 && $backupDump > $backupSelector, 'backup_must_select_schema_contract_before_dump');
$assert(str_contains($source['compose'], '"$${CA_RECOVERY_FORMAT}" "$${stamp}" "$${CA_RECOVERY_SCHEMA_JSON}"'), 'backup_manifest_not_bound_to_selected_contract');
$assert(str_contains($source['restore'], 'ca_recovery_select_manifest "$bundle/MANIFEST.json"') && str_contains($source['restore'], 'expected_schema="$CA_RECOVERY_SCHEMA_VERSION"'), 'restore_does_not_select_manifest_contract');
$restoreContract = $position($source['restore'], 'ca_recovery_select_manifest "$bundle/MANIFEST.json"');
$restoreClear = $position($source['restore'], 'clear_target "$target"');
$assert($restoreContract >= 0 && $restoreClear > $restoreContract, 'unknown_format_or_schema_can_reach_target_clear');
$restoreDbEmpty = $position($source['restore'], '[ "$existing_tables" = 0 ] || fail restore_database_not_empty');
$assert($restoreDbEmpty >= 0 && $restoreClear > $restoreDbEmpty, 'nonempty_database_can_reach_media_clear');
$assert(str_contains($source['restore'], 'assert_target_safe()'), 'restore_does_not_prevalidate_all_media_targets');
$assert(str_contains($source['restore'], '[ "$ci_version" = "$expected_schema" ]') && str_contains($source['restore'], 'for suffix in $CA_RECOVERY_ALL_TABLES; do'), 'restore_does_not_recheck_selected_schema_after_import');
$assert(str_contains($source['audit'], 'ca_recovery_select_manifest "$latest_bundle/MANIFEST.json"'), 'backup_auditor_does_not_validate_versioned_manifest');

$assert(str_contains($source['fixture'], 'function fixtureRecoverySchemaVersion') && str_contains($source['fixture'], 'if ($schemaVersion === 17)'), 'fixture_does_not_branch_for_v17');
$assert(str_contains($source['fixture'], "'fixture_version' => 8") && str_contains($source['fixture'], "'class_identity_schema_version' => 16"), 'historical_v16_fixture_shape_missing');
$assert(str_contains($source['fixture'], "'fixture_version' => 9") && str_contains($source['fixture'], "'backup_manifest_format' => 9") && str_contains($source['fixture'], "'recovery_contract' => 'FORMAT_9_SCHEMA_17'"), 'v17_fixture_contract_missing');
foreach (['payload_json_sha256', 'payload_title_sha256', 'photo_ids_json_sha256', 'item_key_sha256', 'principal_sha256'] as $field) {
    $assert(str_contains($source['fixture'], $field), "v17_fixture_opaque_field_{$field}_missing");
}
$assert(!str_contains($source['fixture'], "`payload_json` AS `payload_json`"), 'fixture_must_not_emit_v17_payload_json');

$assert(str_contains($source['evidence'], 'public const VERSION = 8') && str_contains($source['evidence'], 'public const CLASS_IDENTITY_SCHEMA_VERSION = 16') && str_contains($source['evidence'], '/workspace/infra/scripts/class-archive-recovery-contracts.sh'), 'legacy_full_restore_evidence_not_preserved_or_invalidated');
$assert(!str_contains($source['evidence'], 'CURRENT_VERSION = 9') && !str_contains($source['writer'], 'recovery-version'), 'db_only_v17_must_not_claim_full_media_restore_evidence');

foreach (['v17_backup', 'v17_restore', 'v17_fixture'] as $name) {
    $assert(str_contains($source[$name], 'CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY') && str_contains($source[$name], 'SYNTHETIC_V4_MIGRATION'), "{$name}_scope_guard_missing");
    $assert(!str_contains($source[$name], '/source/uploads') && !str_contains($source[$name], '/source/galleries') && !str_contains($source[$name], '/source/piwigo'), "{$name}_media_mount_assumption_forbidden");
}
$assert(str_contains($source['v17_backup'], '"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY"') && str_contains($source['v17_backup'], '"media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED"'), 'v17_backup_scope_manifest_missing');
$assert(str_contains($source['v17_restore'], '[ "$existing_tables" = 0 ] || fail target_database_not_empty') && !str_contains($source['v17_restore'], 'clear_target()') && !str_contains($source['v17_restore'], 'rm -rf'), 'v17_restore_may_clear_target');
$v17Contract = $position($source['v17_restore'], 'ca_recovery_select_v17_synthetic_manifest "$bundle/MANIFEST.json"');
$v17Target = $position($source['v17_restore'], 'existing_tables=$(mariadb');
$assert($v17Contract >= 0 && $v17Target > $v17Contract, 'v17_restore_queries_target_before_contract_validation');
$assert(str_contains($source['v17_restore'], 'for suffix in $CA_RECOVERY_ALL_TABLES; do') && str_contains($source['v17_restore'], 'restored_schema_not_v17'), 'v17_restore_schema_validation_missing');
$assert(str_contains($source['v17_fixture'], 'SHA2(CAST(\`payload_json\` AS CHAR),256)') && str_contains($source['v17_fixture'], "JSON_EXTRACT(\`payload_json\`,'$.title')"), 'db_only_fixture_does_not_hash_json_payload_and_title');
$assert(str_contains($source['marker'], 'CollectionSnapshotService::fromPiwigo()') && str_contains($source['marker'], "'V17_SYNTHETIC_RECOVERY_MARKER'") && str_contains($source['marker'], "'C-SYN-001'") && str_contains($source['marker'], "'fixture-classmate'") && !str_contains($source['marker'], 'INSERT INTO `collection_pin`'), 'synthetic_marker_not_service_scoped');
$assert(str_contains($source['overlay'], '  v17-synthetic-marker:') && str_contains($source['overlay'], 'piwigo_data:/var/www/html/piwigo:ro') && str_contains($source['overlay'], '/_data/templates_c:mode=0770,uid=${PIWIGO_UID:-1000},gid=${PIWIGO_GID:-1000},size=16m'), 'synthetic_marker_must_keep_application_volume_read_only_with_disposable_template_cache');

foreach (['v17-synthetic-db-backup', 'v17-synthetic-recovery-db', 'v17-synthetic-db-restore', 'v17-synthetic-db-fixture', 'v17-synthetic-marker', 'v17-synthetic-recovery-fixture'] as $service) {
    $assert(str_contains($source['overlay'], '  ' . $service . ':'), "overlay_service_{$service}_missing");
}
$assert(str_contains($source['overlay'], 'v17_synthetic_recovery_db:') && str_contains($source['overlay'], 'V17_SYNTHETIC_RECOVERY_DB_VOLUME'), 'second_empty_db_volume_missing');
$overlayRecoveryStart = $position($source['overlay'], '  v17-synthetic-db-backup:');
$overlayRecoveryEnd = $position($source['overlay'], "\nnetworks:");
$overlayRecovery = ($overlayRecoveryStart < 0 || $overlayRecoveryEnd <= $overlayRecoveryStart)
    ? ''
    : substr($source['overlay'], $overlayRecoveryStart, $overlayRecoveryEnd - $overlayRecoveryStart);
$assert($overlayRecovery !== '' && !str_contains($overlayRecovery, 'piwigo_uploads') && !str_contains($overlayRecovery, 'piwigo_galleries') && !str_contains($overlayRecovery, 'piwigo_derivatives'), 'v17_overlay_mounts_media');
$assert(!str_contains($overlayRecovery, '/mnt/m/') && !str_contains($overlayRecovery, 'owner library') && !str_contains($overlayRecovery, 'private runtime'), 'v17_overlay_private_runtime_coupling');

foreach ($source as $name => $contents) {
    $assert(preg_match('/[A-Za-z]:\\\\/', $contents) !== 1, "{$name}_contains_windows_path");
    $assert(!str_contains($contents, $privateSourcePathMarker), "{$name}_contains_private_source_path");
}

if ($failures !== []) {
    fwrite(STDERR, 'V17_BACKUP_RESTORE_CONTRACT=FAIL assertions=' . $assertions . ' failures=' . implode('; ', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "V17_BACKUP_RESTORE_CONTRACT=PASS assertions={$assertions}\n");
