[CmdletBinding()]
param()

# Static-only boundary for the direct-current-source V16 -> V18 synthetic
# migration proof.  It reads tracked source text only: no Docker service, DB,
# browser, media volume, Owner runtime, or private artifact is opened.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$proofPath = Join-Path $projectRoot 'infra\scripts\v16-to-v18-synthetic-direct-proof.php'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $proofPath -PathType Leaf) 'v16_to_v18_direct_proof_missing'
$proof = [IO.File]::ReadAllText($proofPath)

# Scope is stricter than merely being PHP CLI: direct application can run only
# inside the purpose-labelled isolated synthetic V4 migration runtime, as the
# image's unprivileged nginx account.
Assert-True ($proof.Contains("CLASS_ARCHIVE_V16_TO_V18_DIRECT_PROOF") -and $proof.Contains("CLASS_ARCHIVE_RUNTIME_SCOPE") -and $proof.Contains("SYNTHETIC_V4_MIGRATION")) 'v16_to_v18_direct_scope_gate_missing'
Assert-True ($proof.Contains("posix_geteuid") -and $proof.Contains("posix_getpwuid") -and $proof.Contains("nginx_user_required")) 'v16_to_v18_direct_unprivileged_guard_missing'
Assert-True ($proof.Contains("V16_TO_V18_DIRECT_PROOF_ROOT") -and $proof.Contains("/var/www/html/piwigo") -and $proof.Contains("/workspace/plugins/ClassIdentity/src/Schema.php")) 'v16_to_v18_direct_trusted_source_paths_missing'
Assert-True ($proof.Contains("`$prefixeTable !== 'piwigo_'") -and $proof.Contains('synthetic_database_prefix_invalid')) 'v16_to_v18_direct_database_prefix_boundary_missing'

# No historical schema can be mounted, loaded, or used as a bridge.  The
# current V18 source itself must migrate an exact schema-16 ledger.
foreach ($forbiddenHistorical in @('V18_SYNTHETIC_V17_SCHEMA', 'LoadHistoricalSchema', '/workspace/v18-synthetic-v17/', 'bootstrap-v17', 'historical_commit')) {
    Assert-True (-not $proof.Contains($forbiddenHistorical)) ('v16_to_v18_direct_historical_bridge_forbidden_' + ($forbiddenHistorical -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($proof.Contains('Schema::CURRENT_VERSION !== 18') -and $proof.Contains("if (`$schemaVersion === 16)") -and $proof.Contains("elseif (`$schemaVersion === 18)") -and $proof.Contains('migration_source_not_exact_v16_or_v18')) 'v16_to_v18_direct_exact_schema_boundary_missing'
Assert-True ($proof -match "(?s)if \(!in_array\(\`$mode, \['--migrate-current-source', '--verify-current-source', '--fail-closed'\], true\)\)") 'v16_to_v18_direct_mode_surface_invalid'

# The direct current-source call must be followed by both an exact target
# ledger verification and Schema's read-only full schema attestation.  A
# replay is validation-only; it never calls a historical bootstrap path.
Assert-True ($proof.Contains('function v16ToV18DirectMigrate') -and $proof.Contains('$schema->migrate();') -and $proof.Contains('$schema->verifyCurrent();')) 'v16_to_v18_direct_current_schema_migration_missing'
Assert-True ($proof.Contains('v16ToV18DirectAssertExactLedger($sourceVersion, 16)') -and $proof.Contains('v16ToV18DirectAssertTargetLedger') -and $proof.Contains('target_ledger_migration_invalid_')) 'v16_to_v18_direct_ledger_preservation_missing'
Assert-True ($proof.Contains('0017_photos_app_v4_collection_snapshots') -and $proof.Contains('0018_photos_app_v4_spotlight_rotation_state') -and $proof.Contains('v16ToV18DirectExpectedChecksum')) 'v16_to_v18_direct_migration_17_18_checksum_binding_missing'
Assert-True ($proof.Contains('schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE') -and $proof.Contains('schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS')) 'v16_to_v18_direct_transition_or_replay_evidence_missing'

# Every table already present in the V16 prefixed database is fingerprinted
# before migration and compared afterwards.  The one migration ledger is
# handled separately; the exact seven additive V17/V18 tables must be absent
# before, then exist and remain empty after the schema-only proof.
Assert-True ($proof.Contains('v16ToV18DirectLegacyFingerprint') -and $proof.Contains('CHECKSUM TABLE') -and $proof.Contains('SHOW CREATE TABLE') -and $proof.Contains('legacy_tables_not_preserved')) 'v16_to_v18_direct_legacy_preservation_fingerprint_missing'
Assert-True ($proof.Contains('v16ToV18DirectAssertTablesAbsent') -and $proof.Contains('v16ToV18DirectAssertNewTablesEmpty') -and $proof.Contains('target_table_present_before_migration') -and $proof.Contains('new_table_not_empty')) 'v16_to_v18_direct_additive_table_boundary_missing'
foreach ($suffix in @('collection_snapshot','collection_snapshot_item','collection_snapshot_pointer','collection_pin','collection_feedback','collection_maintenance_state','spotlight_rotation_state')) {
    Assert-True ($proof.Contains("'$suffix'")) ('v16_to_v18_direct_additive_table_missing_' + $suffix)
}
Assert-True ($proof.Contains('v18_scope_constraint_not_enforced') -and $proof.Contains("VALUES ('INVALID'")) 'v16_to_v18_direct_v18_constraint_runtime_check_missing'

# Fail-closed unknown-ledger proof is an isolated scratch table and must clean
# itself even after a negative assertion.  The helper cannot claim media or
# browser evidence: it only states that media was not touched.
Assert-True ($proof.Contains('function v16ToV18DirectFailClosed') -and $proof.Contains('unknown_schema_not_fail_closed') -and $proof.Contains('DROP TABLE IF EXISTS')) 'v16_to_v18_direct_fail_closed_cleanup_missing'
Assert-True ($proof.Contains('unknown_schema=DENY scratch=DISPOSED') -and $proof.Contains('media=NOT_TOUCHED')) 'v16_to_v18_direct_evidence_scope_missing'
$privateSourceMarker = (([string][char]77) + ':' + [char]92) + '图片资源'
foreach ($forbiddenBoundary in @('8191','8091','8291','/private-real','/source/',$privateSourceMarker,'Copy-Item','Move-Item','Remove-Item','MediaGuard::','X-Accel')) {
    Assert-True (-not $proof.Contains($forbiddenBoundary)) ('v16_to_v18_direct_private_or_media_boundary_forbidden_' + ($forbiddenBoundary -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "V16_TO_V18_SYNTHETIC_DIRECT_PROTOCOL=PASS assertions=$assertions"
