<?php

declare(strict_types=1);

/**
 * Static boundary for the standalone, additive V17 -> V18 synthetic
 * migration/recovery laboratory. Runtime evidence is emitted only by the
 * fixed-at-invocation synthetic runner; this test deliberately makes no browser or media
 * claim.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'runner' => $root . '/infra/scripts/v18-synthetic-migration.ps1',
    'compose' => $root . '/infra/v18-synthetic-migration/docker-compose.override.yml',
    'proof' => $root . '/infra/scripts/v18-synthetic-migration-proof.php',
    'probe' => $root . '/infra/scripts/v18-synthetic-db-probe.sh',
    'backup' => $root . '/infra/scripts/create-v18-synthetic-db-backup.sh',
    'restore' => $root . '/infra/scripts/restore-v18-synthetic-db-backup.sh',
    'v17Contract' => $root . '/infra/scripts/class-archive-recovery-contracts.sh',
];
$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "V18_SYNTHETIC_MIGRATION_CONTRACT=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$assertions = 0;
$failures = [];
$privateSourcePathMarker = 'M' . chr(58) . chr(92) . 'private-media-root';
$privateDriveRootMarker = 'M' . chr(58) . chr(92);
$privateQaPathMarker = '.codex-work/' . 'private-real-qa';
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($source['runner'], "[ValidateSet('attempt8', 'attempt9', 'attempt10', 'attempt11', 'attempt12', 'attempt13', 'attempt14', 'attempt15', 'attempt16', 'attempt17', 'attempt18', 'attempt19')]")
    && str_contains($source['runner'], "'.codex-work\\v18-synthetic-migration-' + \$Attempt")
    && str_contains($source['runner'], "'9690'") && str_contains($source['runner'], "'9691'"), 'attempt12_identity_not_fixed');
$assert(str_contains($source['runner'], "'10.255.7.0/24'") && str_contains($source['runner'], "'10.238.0.0/16'")
    && str_contains($source['runner'], 'Assert-FreshSyntheticAttempt') && !str_contains($source['runner'], 'docker compose down')
    && !str_contains($source['runner'], 'docker volume rm') && !str_contains($source['runner'], 'docker rm '), 'attempt12_isolation_or_preservation_missing');
$assert(str_contains($source['runner'], "'attempt16'") && str_contains($source['runner'], "'10090'") && str_contains($source['runner'], "'10091'")
    && str_contains($source['runner'], "'10.255.11.0/24'") && str_contains($source['runner'], "'10.230.0.0/16'")
    && str_contains($source['runner'], 'V16 -> V18 laboratory') && str_contains($source['runner'], 'does not bootstrap historical V17 code'), 'attempt16_direct_v16_to_v18_identity_not_fixed');
$assert(str_contains($source['runner'], "'attempt17'") && str_contains($source['runner'], "'10190'") && str_contains($source['runner'], "'10191'")
    && str_contains($source['runner'], "'10.255.12.0/24'") && str_contains($source['runner'], "'10.228.0.0/16'")
    && str_contains($source['runner'], "'10.228.0.10'") && str_contains($source['runner'], 'attempt17 is')
    && str_contains($source['runner'], 'shares no Docker project, volumes, bridges, or'), 'attempt17_direct_v16_to_v18_identity_not_fixed');
$assert(str_contains($source['runner'], "'attempt18'") && str_contains($source['runner'], "'10290'") && str_contains($source['runner'], "'10291'")
    && str_contains($source['runner'], "'10.255.13.0/24'") && str_contains($source['runner'], "'10.226.0.0/16'")
    && str_contains($source['runner'], "'10.226.0.10'") && str_contains($source['runner'], 'attempt18 is')
    && str_contains($source['runner'], 'implicit Get-FileHash module'), 'attempt18_direct_v16_to_v18_identity_not_fixed');
$assert(str_contains($source['runner'], "'attempt19'") && str_contains($source['runner'], "'10390'") && str_contains($source['runner'], "'10391'")
    && str_contains($source['runner'], "'10.255.14.0/24'") && str_contains($source['runner'], "'10.224.0.0/16'")
    && str_contains($source['runner'], "'10.224.0.10'") && str_contains($source['runner'], 'attempt19 is')
    && str_contains($source['runner'], 'module-qualified hashing'), 'attempt19_direct_v16_to_v18_identity_not_fixed');
$assert(str_contains($source['runner'], 'function Get-FileSha256') && str_contains($source['runner'], 'Import-Module -Name Microsoft.PowerShell.Utility -ErrorAction Stop')
    && str_contains($source['runner'], 'Microsoft.PowerShell.Utility\\Get-FileHash')
    && str_contains($source['runner'], "Stop-V18SyntheticMigration 'file_hash_runtime_failed'")
    && str_contains($source['runner'], "Stop-V18SyntheticMigration 'file_hash_result_invalid'")
    && !str_contains($source['runner'], 'file_hash_command_unavailable'), 'explicit_file_hash_module_gate_missing');
$assert(str_contains($source['runner'], '52ff3a7ba91155efc7bed1572e2b1740973e484c')
    && str_contains($source['runner'], 'aee8ced818747a8f81c816ef5aef112005af280b694ef3bdf8f7ac453e6f7413')
    && str_contains($source['runner'], 'historical_schema_extract_failed'), 'historical_v17_source_not_pinned');
$assert(str_contains($source['runner'], 'bootstrap-v17') && str_contains($source['runner'], 'schema_from=17 schema_to=18')
    && str_contains($source['runner'], 'idempotent_replay=PASS') && str_contains($source['runner'], 'unknown_state=DENY'), 'actual_v17_to_v18_or_fail_closed_flow_missing');
$assert(str_contains($source['proof'], 'V18_SYNTHETIC_V17_SCHEMA_SHA256')
    && str_contains($source['proof'], 'v18SyntheticProofLoadHistoricalSchema')
    && str_contains($source['proof'], 'v18SyntheticProofFingerprint')
    && str_contains($source['proof'], 'v18SyntheticProofV17LedgerFingerprint')
    && str_contains($source['proof'], 'v18SyntheticProofFailClosed'), 'proof_historical_fingerprint_or_fail_closed_missing');
$assert(str_contains($source['proof'], 'collection_snapshot') && str_contains($source['proof'], 'collection_snapshot_pointer')
    && str_contains($source['proof'], 'collection_maintenance_state') && str_contains($source['proof'], 'spotlight_rotation_state'), 'v17_v18_state_coverage_missing');
$assert(str_contains($source['compose'], 'v18-synthetic-db-restore-v16:')
    && str_contains($source['compose'], 'v18-synthetic-recovery-db:')
    && str_contains($source['compose'], 'v18-synthetic-db-backup:')
    && str_contains($source['compose'], 'v18-synthetic-recovery-verify:'), 'separate_v18_recovery_services_missing');
$assert(str_contains($source['compose'], 'internal: true') && !str_contains($source['compose'], $privateDriveRootMarker)
    && !str_contains($source['compose'], '/mnt/m/'), 'compose_private_or_network_boundary_invalid');
$restoreSource = file_get_contents($root . '/infra/scripts/restore-v4-synthetic-pre-migration-db.sh');
$assert(is_string($restoreSource)
    && str_contains($restoreSource, "expected_current_snapshot_script_sha='1897ea83db59c9126125ce63afe538e7a73e58ee1386db5acf518b6ddafaf7c5'")
    && str_contains($restoreSource, '9c5035e26aec9b3f616272f48d4a0c5a3ce81b0a505ac7bc71ad5a47176db7c0')
    && str_contains($restoreSource, 'snapshot_restore_mechanism_unreviewed')
    && str_contains($restoreSource, 'snapshot_not_created_by_reviewed_mechanism')
    && !str_contains($restoreSource, 'snapshot_not_created_by_current_mechanism'), 'reviewed_v16_snapshot_producer_allowlist_missing');
$assert(str_contains($source['backup'], 'DB_ONLY_SYNTHETIC_V18_RECOVERY')
    && str_contains($source['backup'], 'format":10') && str_contains($source['backup'], 'mariadb-dump') && str_contains($source['backup'], 'sha256sum -c')
    && str_contains($source['restore'], 'target_not_empty') && str_contains($source['restore'], 'restored_schema_not_v18'), 'format10_backup_restore_fail_closed_missing');
$assert(str_contains($source['v17Contract'], 'CA_RECOVERY_FORMAT=9') && !str_contains($source['v17Contract'], 'spotlight_rotation_state'), 'historical_v17_recovery_contract_mutated');

foreach ($source as $name => $contents) {
    $assert(preg_match('/[A-Za-z]:\\\\/', $contents) !== 1, "{$name}_contains_windows_path");
    $assert(!str_contains($contents, $privateSourcePathMarker) && !str_contains($contents, $privateQaPathMarker), "{$name}_contains_private_path");
    $assert(!preg_match('/(?:password|token|secret)\s*[:=]\s*(?!\$\{)[^\s\'\"]{12,}/i', $contents), "{$name}_contains_literal_secret");
}

if ($failures !== []) {
    fwrite(STDERR, 'V18_SYNTHETIC_MIGRATION_CONTRACT=FAIL assertions=' . $assertions . ' failures=' . implode('; ', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "V18_SYNTHETIC_MIGRATION_CONTRACT=PASS assertions={$assertions}\n");
