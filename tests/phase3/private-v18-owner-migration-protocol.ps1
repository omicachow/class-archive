[CmdletBinding()]
param()

# Static-only boundary for the private Owner V17 -> V18 migration adapter.
# This test reads source text only: it starts no container, opens no private
# database, and never reads private media, snapshots, or endpoint env files.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$adapterPath = Join-Path $projectRoot 'infra\scripts\private-v18-owner-migration.ps1'
$baselinePath = Join-Path $projectRoot 'infra\scripts\capture-private-v18-migration-baseline.ps1'
$attestationPath = Join-Path $projectRoot 'infra\scripts\attest-v4-synthetic-phase-ab.ps1'
$normalizerPath = Join-Path $projectRoot 'infra\scripts\normalize-v4-synthetic-phase-ab-evidence.ps1'
$syntheticRunnerPath = Join-Path $projectRoot 'infra\scripts\v18-synthetic-migration.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Index-OfOrFail([string]$Text, [string]$Needle, [string]$Code, [int]$Start = 0) {
    $index = $Text.IndexOf($Needle, $Start, [StringComparison]::Ordinal)
    Assert-True ($index -ge 0) $Code
    return $index
}

Assert-True (Test-Path -LiteralPath $adapterPath -PathType Leaf) 'private_v18_owner_adapter_missing'
Assert-True (Test-Path -LiteralPath $baselinePath -PathType Leaf) 'private_v18_baseline_helper_missing'
Assert-True (Test-Path -LiteralPath $attestationPath -PathType Leaf) 'v4_synthetic_phase_ab_attester_missing'
Assert-True (Test-Path -LiteralPath $normalizerPath -PathType Leaf) 'v4_synthetic_phase_ab_evidence_normalizer_missing'
Assert-True (Test-Path -LiteralPath $syntheticRunnerPath -PathType Leaf) 'v18_synthetic_migration_runner_missing'
$adapter = [IO.File]::ReadAllText($adapterPath)
$baseline = [IO.File]::ReadAllText($baselinePath)
$attestation = [IO.File]::ReadAllText($attestationPath)
$normalizer = [IO.File]::ReadAllText($normalizerPath)
$syntheticRunner = [IO.File]::ReadAllText($syntheticRunnerPath)

# The adapter is deliberately narrow: the ordinary operation surface is a
# read-only probe, snapshot, controlled migration, and validation. Rollback is
# a separate, explicitly confirmed recovery operation; it may never happen as
# an implicit catch/finally side effect of migration.
Assert-True ($adapter -match "(?s)\[ValidateSet\(\s*'Probe'\s*,\s*'Snapshot'\s*,\s*'Migrate'\s*,\s*'Validate'\s*\)\]") 'private_v18_action_surface_invalid'
Assert-True ($adapter -notmatch "(?s)\[ValidateSet\([^\)]*'Rollback'") 'private_v18_rollback_must_not_be_implicit_action'
Assert-True ($adapter -match '(?s)\[ValidateSet\(\s*''owner''\s*\)\]\s*\[string\]\$Endpoint') 'private_v18_endpoint_not_owner_only'
Assert-True ($adapter -notmatch "(?i)\bstaging\b") 'private_v18_staging_reference_forbidden'
Assert-True ($adapter.Contains("http_port = '8190'") -and $adapter.Contains("compat_port = '8191'")) 'private_v18_owner_ports_missing'
Assert-True ($adapter.Contains('http://127.0.0.1:8190') -and $adapter.Contains('http://127.0.0.1:8191')) 'private_v18_loopback_endpoints_missing'
Assert-True (-not $adapter.Contains('0.0.0.0')) 'private_v18_non_loopback_bind_forbidden'

# The version boundary is exact and must remain fail-closed; a V16, V18, or
# unknown source database cannot be silently treated as a V17 source.
Assert-True ($adapter.Contains('$migrationSourceVersion = 17') -and $adapter.Contains('$migrationTargetVersion = 18')) 'private_v18_schema_boundary_not_17_to_18'
Assert-True ($adapter -match '(?s)function Assert-SourceSchema17\s*\{.*?(?:schema|ledger).*?(?:17|migrationSourceVersion).*?(?:throw|Stop-)') 'private_v18_source_schema_fail_closed_missing'
Assert-True ($adapter -match '(?s)function Assert-TargetSchema18\s*\{.*?(?:schema|ledger).*?(?:18|migrationTargetVersion).*?(?:throw|Stop-)') 'private_v18_target_schema_fail_closed_missing'
Assert-True ($adapter.Contains('function Get-OwnerSchemaState') -and $adapter.Contains("return 'V17'") -and $adapter.Contains("return 'V18'")) 'private_v18_prelock_schema_state_probe_missing'
Assert-True ($adapter.Contains('Set-StrictMode -Version Latest') -and $adapter.Contains("`$ErrorActionPreference = 'Stop'")) 'private_v18_fail_closed_runtime_mode_missing'

# Every state-changing path needs an explicit Owner confirmation. Probe and
# Validate remain read-only; a future refactor must not turn either into a
# hidden migration command.
Assert-True ($adapter.Contains('[switch]$ConfirmOwnerV18Migration')) 'private_v18_explicit_owner_confirmation_missing'
Assert-True ($adapter -match '(?s)if\s*\(\$Action\s*-in\s*@\(\s*''Snapshot''\s*,\s*''Migrate''\s*\)\).*?ConfirmOwnerV18Migration') 'private_v18_confirmation_not_required_for_writes'
Assert-True ($adapter -match '(?s)\$Action\s*-eq\s*''Probe''.*?(?:return|Write-Output)') 'private_v18_probe_action_missing'
Assert-True ($adapter -match '(?s)\$Action\s*-eq\s*''Validate''.*?(?:return|Write-Output)') 'private_v18_validate_action_missing'

# `runtime-owner` is a stronger proof than a config-only validation. Every
# state-changing path must establish it before the workflow lock and before
# maintenance. Schema 18 retries are deliberately validation-only: a replay
# must not seize the lock or publish maintenance just because a caller reruns
# the command.
Assert-True ($adapter.Contains('function Assert-OwnerRuntimeProof') -and $adapter.Contains('runtime-owner') -and $adapter.Contains('Assert-OwnerLoopbackEndpoints')) 'private_v18_runtime_owner_proof_missing'
Assert-True ($adapter.Contains('function Assert-CleanMigrationCheckout') -and $adapter.Contains('migration_checkout_not_clean') -and $adapter.Contains('status --porcelain=v1 --untracked-files=all')) 'private_v18_clean_checkout_preflight_missing'
$snapshotFlowStart = Index-OfOrFail $adapter "if (`$Action -eq 'Snapshot')" 'private_v18_snapshot_flow_missing'
$migrateFlowStart = Index-OfOrFail $adapter '# Migrate consumes a hash-bound Snapshot plan.' 'private_v18_migrate_flow_missing'
$snapshotFlow = $adapter.Substring($snapshotFlowStart, $migrateFlowStart - $snapshotFlowStart)
$migrateFlow = $adapter.Substring($migrateFlowStart)
$snapshotAcceptance = Index-OfOrFail $snapshotFlow 'Invoke-V4AcceptanceGate' 'private_v18_snapshot_attestation_gate_missing'
$snapshotCheckout = Index-OfOrFail $snapshotFlow 'Assert-CleanMigrationCheckout' 'private_v18_snapshot_checkout_preflight_missing'
$snapshotRuntime = Index-OfOrFail $snapshotFlow 'Assert-OwnerRuntimeProof' 'private_v18_snapshot_runtime_proof_missing'
$snapshotSchema = Index-OfOrFail $snapshotFlow 'Get-OwnerSchemaState' 'private_v18_snapshot_schema_preflight_missing'
$snapshotLock = Index-OfOrFail $snapshotFlow 'Enter-ClassArchivePluginWorkflowLock' 'private_v18_snapshot_lock_missing'
$snapshotMaintenance = Index-OfOrFail $snapshotFlow 'Enter-Maintenance' 'private_v18_snapshot_maintenance_missing'
Assert-True ($snapshotCheckout -lt $snapshotLock -and $snapshotAcceptance -lt $snapshotLock -and $snapshotRuntime -lt $snapshotLock -and $snapshotSchema -lt $snapshotLock -and $snapshotLock -lt $snapshotMaintenance) 'private_v18_snapshot_preflight_must_precede_lock'
$migrateCheckout = Index-OfOrFail $migrateFlow 'Assert-CleanMigrationCheckout' 'private_v18_migrate_checkout_preflight_missing'
$migrateRuntime = Index-OfOrFail $migrateFlow 'Assert-OwnerRuntimeProof' 'private_v18_migrate_runtime_proof_missing'
$migrateSchema = Index-OfOrFail $migrateFlow 'Get-OwnerSchemaState' 'private_v18_migrate_schema_preflight_missing'
$migratePlan = Index-OfOrFail $migrateFlow 'Read-MigrationPlan' 'private_v18_migrate_plan_preflight_missing'
$migrateReplay = Index-OfOrFail $migrateFlow "if (`$schemaState -eq 'V18')" 'private_v18_migrate_replay_branch_missing'
$migrateLock = Index-OfOrFail $migrateFlow 'Enter-ClassArchivePluginWorkflowLock' 'private_v18_migrate_lock_missing'
Assert-True ($migrateCheckout -lt $migrateLock -and $migrateRuntime -lt $migrateLock -and $migrateSchema -lt $migrateLock -and $migratePlan -lt $migrateLock -and $migrateReplay -lt $migrateLock) 'private_v18_migrate_preflight_must_precede_lock'
$migrateV17Boundary = Index-OfOrFail $migrateFlow 'Assert-SourceBaselineUnchanged $plan.Baseline' 'private_v18_migrate_v17_boundary_missing'
$replayFlow = $migrateFlow.Substring($migrateReplay, $migrateV17Boundary - $migrateReplay)
Assert-True ($replayFlow.Contains('idempotent_replay=PASS') -and $replayFlow.Contains('maintenance=NOT_ENTERED') -and $replayFlow.Contains('projection=UNCHANGED') -and $replayFlow.Contains('bff=UNCHANGED')) 'private_v18_replay_contract_missing'
foreach ($forbiddenReplayMutation in @('Enter-ClassArchivePluginWorkflowLock', 'Enter-Maintenance', 'InstallAndMigrateV18', 'RebuildReadProjectionAndRefreshCompat')) {
    Assert-True (-not $replayFlow.Contains($forbiddenReplayMutation)) ('private_v18_replay_mutation_forbidden_' + ($forbiddenReplayMutation -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Snapshot and a safe aggregate-only record-count baseline must happen before
# plugin installation / schema migration. The baseline is evidence, not an
# inventory: it cannot contain source paths, filenames, or private media.
$snapshotIndex = Index-OfOrFail $adapter 'Create-PreMigrationSnapshot' 'private_v18_snapshot_call_missing'
$baselineIndex = Index-OfOrFail $adapter 'Capture-PreMigrationCountBaseline' 'private_v18_count_baseline_missing'
$installIndex = Index-OfOrFail $adapter 'install-class-archive-plugins.php' 'private_v18_plugin_install_missing'
Assert-True ($snapshotIndex -lt $installIndex -and $baselineIndex -lt $installIndex) 'private_v18_snapshot_or_baseline_not_before_migration'
Assert-True ($adapter -match '(?s)function Capture-PreMigrationCountBaseline\s*\{.*?source_records.*?canonical_photos.*?album_relationships.*?comments.*?replies.*?ai_jobs') 'private_v18_baseline_required_domains_missing'
Assert-True ($adapter -match '(?s)function Capture-PreMigrationCountBaseline\s*\{.*?(?:Get-FileHash|SHA256|sha256)') 'private_v18_baseline_integrity_digest_missing'
Assert-True ($adapter.Contains('Assert-SourceBaselineUnchanged') -and $adapter.Contains('-ExpectedSha256') -and $adapter.Contains('semantics=PRESERVED')) 'private_v18_pre_migration_semantic_recheck_missing'
Assert-True ($adapter.Contains('Get-PreMigrationSnapshotBinding') -and $adapter.Contains('Assert-SnapshotBinding') -and $adapter.Contains('sha256sum -c SHA256SUMS')) 'private_v18_snapshot_hash_binding_missing'
Assert-True ($adapter.Contains('Write-MigrationPlan') -and $adapter.Contains('Read-MigrationPlan') -and $adapter.Contains('source_head') -and $adapter.Contains('schema_source_sha256') -and $adapter.Contains('snapshot_manifest_sha256') -and $adapter.Contains('baseline_sha256')) 'private_v18_hash_bound_plan_missing'
Assert-True ($adapter.Contains('v4_acceptance') -and $adapter.Contains('V4AcceptanceGateName') -and $adapter.Contains('Invoke-V4AcceptanceGate')) 'private_v18_synthetic_phase_ab_gate_not_bound'
# `.codex-work` is the required ignored local root for the lock and numeric
# baseline.  The adapter must not hide that safe boundary by constructing the
# string dynamically.  What must never enter tracked source/output contracts
# are provenance paths, filenames, or source-inventory fields.
foreach ($privateMarker in @('relative_source_path', 'source_filename', 'absolute_path', 'full_path')) {
    Assert-True (-not $adapter.Contains($privateMarker)) ('private_v18_baseline_private_data_marker_' + ($privateMarker -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($adapter.Contains("'.codex-work\private-real-full\runtime\class-v18-owner-migration.lock'")) 'private_v18_ignored_lock_root_not_explicit'

# The pre-migration database dump is the existing narrow rollback checkpoint;
# it must remain database-only and use exact V17 -> V18 environment evidence.
Assert-True ($adapter.Contains('pre-migration-db-backup')) 'private_v18_existing_snapshot_service_not_used'
Assert-True ($adapter.Contains('CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION') -and $adapter.Contains('CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION') -and $adapter.Contains('CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true')) 'private_v18_snapshot_version_confirmation_missing'
Assert-True ($adapter.Contains('DB_ONLY') -and $adapter.Contains('media=NOT_INCLUDED')) 'private_v18_snapshot_media_scope_not_explicit'
$snapshotProducerStart = Index-OfOrFail $adapter 'function Create-PreMigrationSnapshot' 'private_v18_snapshot_producer_missing'
$snapshotBindingStart = Index-OfOrFail $adapter 'function Get-PreMigrationSnapshotBinding' 'private_v18_snapshot_binding_function_missing'
$snapshotProducer = $adapter.Substring($snapshotProducerStart, $snapshotBindingStart - $snapshotProducerStart)
Assert-True ($snapshotProducer.Contains('try') -and $snapshotProducer.Contains('finally') -and $snapshotProducer.Contains('$restoreMaintenanceWriter') -and $snapshotProducer.Contains('RecreatePiwigoUnderMaintenance')) 'private_v18_snapshot_writer_recovery_missing'
foreach ($mediaMarker in @('piwigo_data:', 'piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', '/source/', 'Copy-Item', 'Move-Item', 'Remove-Item')) {
    Assert-True (-not $adapter.Contains($mediaMarker)) ('private_v18_media_mutation_or_mount_forbidden_' + ($mediaMarker -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# V18 changes the ClassIdentity migration ledger and projection/BFF state only.
# It must never restart Immich data/ML services or invoke full AI work.
Assert-True ($adapter.Contains("'immich-web-compat'")) 'private_v18_bff_refresh_missing'
foreach ($forbidden in @('immich-server', 'immich-machine-learning', 'face detection', 'face embedding', 'smart search full', 'rebuild-ai-index', 'run-face', 'run-smart-search', 'full index')) {
    Assert-True (-not $adapter.Contains($forbidden)) ('private_v18_ai_or_immich_restart_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($adapter.Contains('rebuild-photo-read-projection.php')) 'private_v18_read_projection_refresh_missing'

# The baseline compares opaque deterministic digests as well as counts. It
# catches equal-count damage to media mappings, album membership, comments,
# person curation, projections and AI control state without exporting any row,
# filename, source path, identifier or credential to host-side output.
Assert-True ($baseline -match "(?s)\[ValidateSet\('capture', 'compare', 'verify-source'\)\]" -and $baseline.Contains('[string]$ExpectedSha256')) 'private_v18_baseline_hash_bound_action_surface_missing'
Assert-True ($baseline.Contains('format = 2') -and $baseline.Contains('semantic_fingerprints') -and $baseline.Contains('ConvertTo-StrictFingerprints') -and $baseline.Contains('COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS')) 'private_v18_semantic_baseline_format_missing'
foreach ($semanticDomain in @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','identity_and_audit')) {
    Assert-True ($baseline.Contains("'$semanticDomain'")) ('private_v18_semantic_domain_missing_' + $semanticDomain)
}
foreach ($semanticTable in @('submission','photo_duplicate','private_library_collection','private_library_folder','private_library_import','private_library_import_item','batch_operation','batch_operation_item')) {
    Assert-True ($baseline.Contains($semanticTable)) ('private_v18_semantic_preservation_table_missing_' + $semanticTable)
}
Assert-True ($baseline.Contains('Assert-SourceBaselineMatches') -and $baseline.Contains('action=verify-source') -and $baseline.Contains('semantics=PRESERVED')) 'private_v18_source_semantic_preservation_missing'
Assert-True ($baseline.Contains('case "$rotation_rows" in 0|1|2)') -and $baseline.Contains("scope NOT IN ('FULL','HERITAGE')") -and $baseline.Contains('rotation=IDLE_OR_OPERATIONAL')) 'private_v18_rotation_operational_shape_missing'
Assert-True (-not $baseline.Contains('post_migration_rotation_state_not_empty')) 'private_v18_rotation_empty_only_compare_forbidden'
Assert-True ($baseline.Contains('export MYSQL_PWD') -and $baseline.Contains('unset MARIADB_ROOT_PASSWORD') -and -not $baseline.Contains('--password=')) 'private_v18_baseline_secret_not_passed_as_process_argument'
foreach ($privateMarker in @('relative_source_path', 'source_filename', 'absolute_path', 'full_path')) {
    Assert-True (-not $baseline.Contains($privateMarker)) ('private_v18_semantic_baseline_private_data_marker_' + ($privateMarker -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($mediaMarker in @('piwigo_data:', 'piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', '/source/', 'Copy-Item', 'Move-Item', 'Remove-Item')) {
    Assert-True (-not $baseline.Contains($mediaMarker)) ('private_v18_semantic_baseline_media_mutation_or_mount_forbidden_' + ($mediaMarker -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The Phase A/B attester is a local, ignored, immutable report. It does not
# launch a browser or Docker and intentionally contains only source/evidence
# hashes plus narrow PASS records. It must bind Chrome, MediaGuard, upload,
# scope and cold-restart proof to the checked-out source revision.
Assert-True ($attestation -match "(?s)\[ValidateSet\('Record', 'Verify'\)\]" -and $attestation.Contains(".codex-work\v4-synthetic-acceptance")) 'v4_phase_ab_attestation_local_root_missing'
Assert-True ($attestation.Contains('Assert-IgnoredDirectory') -and $attestation.Contains('check-ignore --quiet --no-index') -and $attestation.Contains('Set-ClassArchiveOwnerOnlyFileAcl')) 'v4_phase_ab_attestation_private_boundary_missing'
Assert-True ($attestation.Contains('source_head') -and $attestation.Contains('source_digests') -and $attestation.Contains('GOOGLE_CHROME_STABLE')) 'v4_phase_ab_attestation_source_binding_missing'
Assert-True ($attestation.Contains("'infra/scripts/normalize-v4-synthetic-phase-ab-evidence.ps1'") -and $attestation.Contains("'infra/scripts/attest-v4-synthetic-phase-ab.ps1'")) 'v4_phase_ab_attestation_processor_source_binding_missing'
foreach ($requiredGate in @('synthetic_desktop_chrome','synthetic_search_overlay','synthetic_viewer','synthetic_scope_projections','synthetic_upload_era','synthetic_mediaguard','synthetic_server_restart','synthetic_baseline')) {
    Assert-True ($attestation.Contains($requiredGate)) ('v4_phase_ab_attestation_gate_missing_' + $requiredGate)
}
Assert-True ($attestation.Contains('V4_CHROME_DEEP_MEDIAGUARD=PASS') -and $attestation.Contains('V4_SYNTHETIC_COLD_RESTART=PASS') -and $attestation.Contains('baseline=72_72_8')) 'v4_phase_ab_attestation_phase_ab_evidence_missing'
foreach ($forbiddenAttesterMutation in @('docker compose', 'Start-Process', 'playwright', 'chromium.launch')) {
    Assert-True (-not $attestation.Contains($forbiddenAttesterMutation)) ('v4_phase_ab_attestation_runtime_mutation_forbidden_' + ($forbiddenAttesterMutation -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($normalizer.Contains('SAFE_PROTOCOL_LINES_ONLY') -and $normalizer.Contains('V4_SYNTHETIC_PHASE_AB_EVIDENCE=PASS') -and $normalizer.Contains('people_required=yes')) 'v4_phase_ab_evidence_normalizer_contract_missing'
foreach ($forbiddenNormalizerMutation in @('docker compose', 'Start-Process', 'playwright', 'chromium.launch', '127.0.0.1:8191', 'runtime-owner')) {
    Assert-True (-not $normalizer.Contains($forbiddenNormalizerMutation)) ('v4_phase_ab_evidence_normalizer_runtime_or_private_mutation_forbidden_' + ($forbiddenNormalizerMutation -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Existing synthetic migration performs an actual isolated V17 bootstrap, V18
# transition and idempotent replay. This static protocol deliberately does not
# execute it; runtime evidence remains a separate gate.
Assert-True ($syntheticRunner.Contains('bootstrap-v17') -and $syntheticRunner.Contains('Invoke-MigrateV18') -and $syntheticRunner.Contains('idempotent_replay=PASS') -and $syntheticRunner.Contains('unknown_state=DENY') -and $syntheticRunner.Contains('recover')) 'private_v18_owner_equivalent_synthetic_sequence_missing'

# No automatic rollback is safe when the application bytes are V18 but a DB
# snapshot is V17. The adapter must stop in maintenance and refer to the
# separate manual recovery runbook instead of restoring the dump implicitly.
Assert-True ($adapter.Contains('manual_rollback_required') -and $adapter.Contains('docs/private-v18-owner-migration.md')) 'private_v18_manual_rollback_boundary_missing'
Assert-True (-not $adapter.Contains('automatic_rollback') -and -not $adapter.Contains('Restore-PreMigrationSnapshot')) 'private_v18_automatic_rollback_forbidden'

Write-Output "PRIVATE_V18_OWNER_MIGRATION_PROTOCOL=PASS assertions=$assertions"
