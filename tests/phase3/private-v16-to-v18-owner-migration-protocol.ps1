[CmdletBinding()]
param()

# Static-only protocol boundary for the private Owner V16 -> V18 baseline
# helper. This test reads committed source text only: it starts no container,
# opens no private database, and never reads media, snapshots, or endpoint
# environment files.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$adapterPath = Join-Path $projectRoot 'infra\scripts\private-v16-to-v18-owner-migration.ps1'
$helperPath = Join-Path $projectRoot 'infra\scripts\capture-private-v16-to-v18-migration-baseline.ps1'
$boundedNativePath = Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1'
$directAttestationPath = Join-Path $projectRoot 'infra\scripts\attest-v16-to-v18-synthetic-direct-runtime.ps1'
$schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Slice-Function([string]$Text, [string]$StartName, [string]$NextName, [string]$Code) {
    $start = $Text.IndexOf($StartName, [StringComparison]::Ordinal)
    $next = $Text.IndexOf($NextName, [StringComparison]::Ordinal)
    Assert-True ($start -ge 0 -and $next -gt $start) $Code
    return $Text.Substring($start, $next - $start)
}

Assert-True (Test-Path -LiteralPath $helperPath -PathType Leaf) 'private_v16_to_v18_baseline_helper_missing'
Assert-True (Test-Path -LiteralPath $adapterPath -PathType Leaf) 'private_v16_to_v18_owner_adapter_missing'
Assert-True (Test-Path -LiteralPath $boundedNativePath -PathType Leaf) 'private_v16_to_v18_bounded_native_helper_missing'
Assert-True (Test-Path -LiteralPath $directAttestationPath -PathType Leaf) 'private_v16_to_v18_direct_attestation_helper_missing'
Assert-True (Test-Path -LiteralPath $schemaPath -PathType Leaf) 'class_identity_schema_missing'

$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($helperPath, [ref]$tokens, [ref]$parseErrors) | Out-Null
Assert-True ($parseErrors.Count -eq 0) 'private_v16_to_v18_baseline_parse_invalid'
$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($adapterPath, [ref]$tokens, [ref]$parseErrors) | Out-Null
Assert-True ($parseErrors.Count -eq 0) 'private_v16_to_v18_owner_adapter_parse_invalid'
$tokens = $null
$parseErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($boundedNativePath, [ref]$tokens, [ref]$parseErrors) | Out-Null
Assert-True ($parseErrors.Count -eq 0) 'private_v16_to_v18_bounded_native_helper_parse_invalid'

$helper = [IO.File]::ReadAllText($helperPath)
$adapter = [IO.File]::ReadAllText($adapterPath)
$boundedNative = [IO.File]::ReadAllText($boundedNativePath)
$schema = [IO.File]::ReadAllText($schemaPath)
$countsFunction = Slice-Function $helper 'function Get-OwnerV16ToV18Counts' 'function Get-OwnerV16ToV18SemanticFingerprints' 'private_v16_to_v18_count_function_boundary_missing'
$semanticFunction = Slice-Function $helper 'function Get-OwnerV16ToV18SemanticFingerprints' 'function Read-Baseline' 'private_v16_to_v18_semantic_function_boundary_missing'
$helperEnginePipeFunction = Slice-Function $helper 'function Assert-DockerDesktopEnginePipe' 'function Assert-IgnoredPrivateDirectory' 'private_v16_to_v18_baseline_engine_pipe_function_boundary_missing'
$ownerEnginePipeFunction = Slice-Function $adapter 'function Assert-DockerDesktopEnginePipe' 'function Assert-OwnerRuntime' 'private_v16_to_v18_owner_engine_pipe_function_boundary_missing'
$ownerRuntimeFunction = Slice-Function $adapter 'function Assert-OwnerRuntime' 'function Get-SchemaState' 'private_v16_to_v18_owner_runtime_function_boundary_missing'
$ownerInvokeWslFunction = Slice-Function $adapter 'function Invoke-Wsl' 'function Get-WslPath' 'private_v16_to_v18_owner_invoke_wsl_function_boundary_missing'
$baselineInvokeWslFunction = Slice-Function $helper 'function Invoke-WslCapture' 'function Get-WslPath' 'private_v16_to_v18_baseline_invoke_wsl_function_boundary_missing'
$snapshotMaintenanceStateFunction = Slice-Function $adapter 'function Get-SnapshotMaintenanceState' 'function Ensure-SnapshotWriterForRecovery' 'private_v16_to_v18_snapshot_maintenance_state_function_boundary_missing'
$snapshotWriterRecoveryFunction = Slice-Function $adapter 'function Ensure-SnapshotWriterForRecovery' 'function Restore-SnapshotOwnerAvailability' 'private_v16_to_v18_snapshot_writer_recovery_function_boundary_missing'
$snapshotAvailabilityRecoveryFunction = Slice-Function $adapter 'function Restore-SnapshotOwnerAvailability' 'function Create-PreMigrationSnapshot' 'private_v16_to_v18_snapshot_availability_recovery_function_boundary_missing'

# Windows PowerShell 5.1 has no ProcessStartInfo.ArgumentList. The shared
# helper must preserve multiline SQL/shell arguments through CommandLineToArgvW
# quoting while bounding both the host process and the Linux Docker client.
Assert-True ($boundedNative.Contains('function ConvertTo-ClassArchiveWin32Argument') -and $boundedNative.Contains('CommandLineToArgvW')) 'private_v16_to_v18_win32_argument_quoting_missing'
Assert-True ($boundedNative.Contains('NUL byte') -and $boundedNative.Contains('backslashes')) 'private_v16_to_v18_win32_nul_guard_missing'
Assert-True ($boundedNative.Contains('function Invoke-ClassArchiveBoundedNative') -and $boundedNative.Contains('ReadToEndAsync') -and $boundedNative.Contains('WaitForExit($TimeoutSeconds * 1000)') -and $boundedNative.Contains('$process.Kill()')) 'private_v16_to_v18_bounded_process_contract_missing'
Assert-True ($boundedNative.Contains('function Add-ClassArchiveWslTimeout') -and $boundedNative.Contains("'--exec', 'timeout', '--foreground', '--kill-after=10s'") -and $boundedNative.Contains("-eq '--'") -and $boundedNative.Contains("-eq '--exec'")) 'private_v16_to_v18_wsl_timeout_injection_missing'
Assert-True (-not $boundedNative.Contains('native_argument_invalid')) 'private_v16_to_v18_multiline_argument_rejection_forbidden'
foreach ($invoker in @($ownerInvokeWslFunction, $baselineInvokeWslFunction)) {
    Assert-True ($invoker.Contains('TimeoutSeconds') -and $invoker.Contains('Add-ClassArchiveWslTimeout') -and $invoker.Contains('Invoke-ClassArchiveBoundedNative')) 'private_v16_to_v18_bounded_wsl_wrapper_missing'
    Assert-True ($invoker.Contains("`$Code + '_timeout'") -and -not $invoker.Contains('@(&')) 'private_v16_to_v18_bounded_wsl_timeout_mapping_missing'
}
# PowerShell reserves `$Args` for unbound invocation arguments.  The Owner
# adapter must use an explicit payload parameter so a Compose request cannot
# silently collapse into an empty command before its fail-closed boundary.
Assert-True ($ownerInvokeWslFunction.Contains('[string[]]$CommandArguments') -and $ownerInvokeWslFunction.Contains('Add-ClassArchiveWslTimeout -Arguments $CommandArguments') -and -not $ownerInvokeWslFunction.Contains('[string[]]$Args')) 'private_v16_to_v18_owner_command_arguments_binding_missing'

# The helper must be exact to this migration boundary and fail closed for
# other states. It cannot be redirected toward a staging endpoint.
Assert-True ($helper -match "(?s)\[ValidateSet\(\s*'capture'\s*,\s*'compare'\s*,\s*'verify-source'\s*\)\]") 'private_v16_to_v18_action_surface_invalid'
Assert-True ($helper -match '(?s)\[ValidateSet\(\s*''owner''\s*\)\]\s*\[string\]\$Endpoint') 'private_v16_to_v18_endpoint_not_owner_only'
Assert-True ($helper -match '(?s)\[ValidateSet\(\s*16\s*,\s*18\s*\)\]\s*\[int\]\$ExpectedSchema') 'private_v16_to_v18_schema_selector_invalid'
Assert-True ($helper.Contains("'.codex-work\private-real-full\migration-v16-to-v18'")) 'private_v16_to_v18_ignored_root_missing'
Assert-True ($helper.Contains('class_archive_private_full_v3_piwigo') -and $helper.Contains('class_archive_private_full_v3_immich')) 'private_v16_to_v18_owner_projects_missing'
Assert-True (-not ($helper -match "(?s)\[ValidateSet\([^\)]*'staging'")) 'private_v16_to_v18_staging_selector_forbidden'
Assert-True ($helper.Contains('ports=8190_8191')) 'private_v16_to_v18_owner_port_contract_missing'

# Docker Desktop's Windows engine pipe is a required local control-plane
# dependency.  These checks must fail closed *before* a WSL/Docker client can
# hang on a missing Desktop backend; a loopback HTTP listener is not enough.
foreach ($engineGuard in @($helperEnginePipeFunction, $ownerEnginePipeFunction)) {
    Assert-True ($engineGuard.Contains('dockerDesktopLinuxEngine') -and $engineGuard.Contains('docker_engine')) 'private_v16_to_v18_engine_pipe_names_missing'
    Assert-True ($engineGuard.Contains('Test-Path -LiteralPath $_') -and $engineGuard.Contains('docker_engine_pipe_unavailable')) 'private_v16_to_v18_engine_pipe_fail_closed_missing'
    Assert-True (-not $engineGuard.Contains('Invoke-Wsl') -and -not $engineGuard.Contains('Invoke-WslCapture') -and -not $engineGuard.Contains('docker compose')) 'private_v16_to_v18_engine_pipe_guard_must_not_probe_runtime'
}
$ownerGuardIndex = $ownerRuntimeFunction.IndexOf('Assert-DockerDesktopEnginePipe', [StringComparison]::Ordinal)
$ownerCurlIndex = $ownerRuntimeFunction.IndexOf('$curl =', [StringComparison]::Ordinal)
Assert-True ($ownerGuardIndex -ge 0 -and $ownerCurlIndex -gt $ownerGuardIndex) 'private_v16_to_v18_owner_engine_pipe_must_precede_loopback_probe'
$countsGuardIndex = $countsFunction.IndexOf('Assert-DockerDesktopEnginePipe', [StringComparison]::Ordinal)
$countsMariaIndex = $countsFunction.IndexOf('$mariaSql', [StringComparison]::Ordinal)
$semanticGuardIndex = $semanticFunction.IndexOf('Assert-DockerDesktopEnginePipe', [StringComparison]::Ordinal)
$semanticMariaIndex = $semanticFunction.IndexOf('$semanticSql', [StringComparison]::Ordinal)
Assert-True ($countsGuardIndex -ge 0 -and $countsMariaIndex -gt $countsGuardIndex -and $semanticGuardIndex -ge 0 -and $semanticMariaIndex -gt $semanticGuardIndex) 'private_v16_to_v18_baseline_engine_pipe_must_precede_database_probes'

# The Owner adapter is separate from the read-only baseline helper. It must be
# exact to V16 -> V18, require an explicit state-changing confirmation, and
# keep the V17 -> V18 adapter out of this path.
Assert-True ($adapter -match "(?s)\[ValidateSet\(\s*'Probe'\s*,\s*'Snapshot'\s*,\s*'Migrate'\s*,\s*'Validate'\s*\)\]") 'private_v16_to_v18_adapter_action_surface_invalid'
Assert-True ($adapter -match '(?s)\[ValidateSet\(\s*''owner''\s*\)\]\s*\[string\]\$Endpoint') 'private_v16_to_v18_adapter_endpoint_not_owner_only'
Assert-True ($adapter.Contains('$sourceVersion = 16') -and $adapter.Contains('$targetVersion = 18') -and $adapter.Contains("'d6f15c7bd366d9dcf7fc8792b50d0965a8ee33d4'")) 'private_v16_to_v18_adapter_boundary_not_pinned'
Assert-True ($adapter.Contains('[switch]$ConfirmOwnerV16ToV18Migration') -and $adapter.Contains("if (`$Action -in @('Snapshot','Migrate') -and -not `$ConfirmOwnerV16ToV18Migration)")) 'private_v16_to_v18_adapter_confirmation_missing'
Assert-True ($adapter.Contains('function Assert-CleanCheckout') -and $adapter.Contains('migration_checkout_not_clean') -and $adapter.Contains('function Invoke-V4Gate')) 'private_v16_to_v18_adapter_preflight_missing'
Assert-True ($adapter.Contains('function Assert-PiwigoStoppedForSnapshot') -and $adapter.Contains("`$writerStopAttempted = `$true") -and $adapter.Contains("Invoke-Piwigo @('stop','piwigo') -TimeoutSeconds 120") -and $adapter.Contains('Get-PiwigoWriterStateForRecovery')) 'private_v16_to_v18_snapshot_writer_stop_proof_missing'
Assert-True ($adapter.Contains('$captured=Create-PreMigrationSnapshot; $baseline=$captured.Baseline; $snapshotName=$captured.Name') -and $adapter.Contains('Assert-SourceV16; Assert-SourceBaseline $baseline; $plan=Write-Plan')) 'private_v16_to_v18_snapshot_atomic_baseline_recheck_missing'
# Snapshot has no schema/content mutation. A failing DB-only snapshot must
# restore the Owner writer and remove only an observed maintenance marker;
# the state-changing Migrate path intentionally receives no such auto-open.
Assert-True ($snapshotMaintenanceStateFunction.Contains("'CLASS_ARCHIVE_STATUS:503'") -and $snapshotMaintenanceStateFunction.Contains("'Class Archive maintenance mode.'") -and $snapshotMaintenanceStateFunction.Contains("'--output','/dev/null'") -and $snapshotMaintenanceStateFunction.Contains("return 'INACTIVE'") -and $snapshotMaintenanceStateFunction.Contains("return 'UNKNOWN'")) 'private_v16_to_v18_snapshot_maintenance_state_fail_closed_missing'
Assert-True ($snapshotWriterRecoveryFunction.Contains("if (`$state -ne 'true|running')") -and $snapshotWriterRecoveryFunction.Contains("Invoke-Piwigo @('up','-d','--force-recreate','--no-deps','piwigo')") -and $snapshotWriterRecoveryFunction.Contains('snapshot_recovery_writer_not_running')) 'private_v16_to_v18_snapshot_writer_recovery_missing'
Assert-True ($snapshotAvailabilityRecoveryFunction.Contains('Ensure-SnapshotWriterForRecovery') -and $snapshotAvailabilityRecoveryFunction.Contains("if (`$maintenance -eq 'ACTIVE') { Finalize-Maintenance }") -and $snapshotAvailabilityRecoveryFunction.Contains('Assert-OwnerRuntime') -and $snapshotAvailabilityRecoveryFunction.Contains('snapshot_recovery_maintenance_state_unknown')) 'private_v16_to_v18_snapshot_availability_recovery_missing'
$snapshotActionStart = $adapter.IndexOf("if (`$Action -eq 'Snapshot')", [StringComparison]::Ordinal)
$migrateActionStart = $adapter.IndexOf("if ([string]::IsNullOrWhiteSpace(`$MigrationPlanName)", $snapshotActionStart + 1, [StringComparison]::Ordinal)
Assert-True ($snapshotActionStart -ge 0 -and $migrateActionStart -gt $snapshotActionStart) 'private_v16_to_v18_snapshot_action_boundary_missing'
$snapshotAction = $adapter.Substring($snapshotActionStart, $migrateActionStart - $snapshotActionStart)
Assert-True ($snapshotAction.Contains("`$snapshotRecoveryPending=`$true") -and $snapshotAction.Contains('Enter-Maintenance') -and $snapshotAction.Contains('finally') -and $snapshotAction.Contains('Restore-SnapshotOwnerAvailability') -and $snapshotAction.Contains("`$snapshotRecoveryPending=`$false")) 'private_v16_to_v18_snapshot_failure_recovery_finally_missing'
Assert-True ($adapter.IndexOf('Restore-SnapshotOwnerAvailability', $migrateActionStart, [StringComparison]::Ordinal) -eq -1) 'private_v16_to_v18_migrate_auto_open_forbidden'
Assert-True ($adapter.Contains('[ ! -L MANIFEST.json ]') -and $adapter.Contains('"media":"NOT_INCLUDED"') -and $adapter.Contains('dump_sha256=$(sha256sum database.sql.gz') -and $adapter.Contains('dump_bytes=$(wc -c < database.sql.gz') -and $adapter.Contains('sha256sum -c SHA256SUMS')) 'private_v16_to_v18_snapshot_binding_hardening_missing'
Assert-True ($adapter.Contains("install-locked-piwigo-extensions.php','--verify-only")) 'private_v16_to_v18_locked_extension_verify_missing'
Assert-True ($adapter.Contains('function Verify-ClassIdentityRuntime') -and $adapter.Contains('Assert-TargetV18; Verify-ClassIdentityRuntime; Compare-Baseline')) 'private_v16_to_v18_post_migration_runtime_verify_missing'
Assert-True ($adapter.Contains('function Assert-SourceV16') -and $adapter.Contains('function Assert-TargetV18') -and $adapter.Contains('source_schema_not_exact_v16') -and $adapter.Contains('target_schema_not_exact_v18')) 'private_v16_to_v18_adapter_schema_gate_missing'
Assert-True ($adapter.Contains('Assert-SourceBaseline $plan.Baseline; Assert-Snapshot $plan.Snapshot') -and $adapter.Contains('Compare-Baseline $plan.Baseline')) 'private_v16_to_v18_adapter_evidence_recheck_missing'
# The private migration must not be authorized from a static source review
# alone.  Snapshot binds an actual isolated V16 -> V18 runtime attestation to
# the exact commit, then every later plan read re-verifies that binding before
# the owner migration can proceed.
Assert-True ($adapter.Contains("'attest-v16-to-v18-synthetic-direct-runtime.ps1'") -and $adapter.Contains('function Invoke-DirectV16ToV18ProofGate') -and $adapter.Contains("@('-Action','verify')") -and $adapter.Contains('attempt=attempt33') -and $adapter.Contains('direct_runtime_proof_gate_missing') -and $adapter.Contains('direct_runtime_proof_gate_head_stale')) 'private_v16_to_v18_direct_runtime_gate_invocation_missing'
Assert-True ($boundedNative.Contains('StandardOutputEncoding = [Text.UTF8Encoding]::new($false)') -and $boundedNative.Contains('StandardErrorEncoding = [Text.UTF8Encoding]::new($false)') -and $adapter.Contains('Invoke-ClassArchiveBoundedNative') -and $helper.Contains('Invoke-ClassArchiveBoundedNative')) 'private_v16_to_v18_utf8_wsl_path_contract_missing'
Assert-True ($adapter.Contains('$directProof=Invoke-DirectV16ToV18ProofGate; Assert-OwnerRuntime; Assert-SourceV16') -and $adapter.Contains('$plan=Write-Plan $baseline $snapshot $gate $directProof')) 'private_v16_to_v18_snapshot_direct_runtime_gate_order_missing'
Assert-True ($adapter.Contains('direct_v16_to_v18_proof=[ordered]@{commit=$DirectProof.Commit;source_digest=$DirectProof.SourceDigest;proof_sha256=$DirectProof.ProofSha256}') -and $adapter.Contains('migration_plan_direct_runtime_proof_stale') -and $adapter.Contains('$currentDirectProof = Invoke-DirectV16ToV18ProofGate')) 'private_v16_to_v18_plan_direct_runtime_binding_missing'
Assert-True ($adapter.Contains('function Invoke-ChildPowerShell') -and $adapter.Contains("Invoke-ChildPowerShell `$lifecycle @('runtime-owner') 'owner_lifecycle_invalid' 240") -and $adapter.Contains('Invoke-ChildPowerShell $baselineHelper $CommandArguments $Code 600')) 'private_v16_to_v18_bounded_child_powershell_missing'
Assert-True (-not $adapter.Contains('@(& powershell.exe')) 'private_v16_to_v18_unbounded_child_powershell_forbidden'
$privateDriveMarker = ([string][char]77) + ':' + [char]92
foreach ($forbiddenAdapterMarker in @('Rollback', '0.0.0.0', $privateDriveMarker, 'relative_source_path', 'source_filename', 'Remove-Item', 'Copy-Item', 'Move-Item')) {
    Assert-True (-not $adapter.Contains($forbiddenAdapterMarker)) ('private_v16_to_v18_adapter_forbidden_marker_' + ($forbiddenAdapterMarker -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Capture produces an ignored, hash-bound opaque file. The file name is a
# leaf-only UTC pattern, preventing a caller from persisting a host path in
# machine-readable evidence.
Assert-True ($helper.Contains('PRIVATE_V16_TO_V18_NUMERIC_BASELINE')) 'private_v16_to_v18_output_prefix_missing'
Assert-True ($helper.Contains('OWNER_V16_TO_V18_NUMERIC_BASELINE')) 'private_v16_to_v18_scope_missing'
Assert-True ($helper.Contains('owner-v16-to-v18-baseline-') -and $helper.Contains("[0-9]{8}T[0-9]{6}Z")) 'private_v16_to_v18_leaf_pattern_missing'
Assert-True ($helper.Contains('COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS')) 'private_v16_to_v18_privacy_contract_missing'
Assert-True ($helper.Contains('check-ignore --quiet --no-index') -and $helper.Contains('output_directory_not_ignored') -and $helper.Contains('baseline_already_exists')) 'private_v16_to_v18_ignored_immutable_boundary_missing'
Assert-True ($helper.Contains('[string]$ExpectedSha256') -and $helper.Contains('baseline_sha256_mismatch') -and $helper.Contains('[Security.Cryptography.SHA256]::Create()')) 'private_v16_to_v18_sha256_binding_missing'

# Both database probes accept only V16/V18. The source and compare actions
# then narrow those states further: V16 for capture/verify, V18 for compare.
Assert-True ($countsFunction.Contains('ledger_shape=$(q') -and $countsFunction.Contains('16:16:1:16') -and $countsFunction.Contains('18:18:1:18')) 'private_v16_to_v18_count_schema_gate_missing'
Assert-True ($semanticFunction.Contains('ledger_shape=$(q') -and $semanticFunction.Contains('16:16:1:16') -and $semanticFunction.Contains('18:18:1:18')) 'private_v16_to_v18_semantic_schema_gate_missing'
Assert-True ($helper.Contains('capture_requires_source_schema_v16') -and $helper.Contains('verify_source_requires_v16_baseline') -and $helper.Contains('compare_requires_target_schema_v18')) 'private_v16_to_v18_action_schema_gates_missing'
Assert-True ($helper.Contains('capture_requires_no_open_ai_jobs')) 'private_v16_to_v18_open_ai_job_gate_missing'
Assert-True ($helper.Contains('Get-OwnerV16ToV18Counts 16') -and $helper.Contains('Get-OwnerV16ToV18Counts 18')) 'private_v16_to_v18_source_target_count_calls_missing'
Assert-True ($helper.Contains('Get-OwnerV16ToV18SemanticFingerprints 16') -and $helper.Contains('Get-OwnerV16ToV18SemanticFingerprints 18')) 'private_v16_to_v18_source_target_semantic_calls_missing'
Assert-True ($helper.Contains('post_migration_schema_mismatch') -and $helper.Contains('[uint64]$actual[$key] -ne 18')) 'private_v16_to_v18_target_ledger_validation_missing'
Assert-True ($countsFunction.Contains("'immich_face_search'") -and $countsFunction.Contains('FROM face_search') -and $helper.Contains("'immich_face_search'")) 'private_v16_to_v18_face_search_count_binding_missing'

# V16 reads only its established semantic domains. V17/V18 additions are
# validated structurally on the target and deliberately excluded from source
# count and fingerprint evidence.
foreach ($requiredV16Table in @(
    'photo_source_presentation', 'photo_comment', 'auto_collection_photo',
    'ai_asset_index', 'ai_index_job', 'native_source_epoch',
    'private_library_collection', 'private_library_folder',
    'private_library_import', 'private_library_import_item',
    'batch_operation', 'batch_operation_item'
)) {
    Assert-True ($semanticFunction.Contains($requiredV16Table)) ('private_v16_to_v18_semantic_table_missing_' + $requiredV16Table)
}
foreach ($semanticDomain in @('canonical_media', 'album_membership', 'comments', 'person_curation', 'spotlight_collections', 'ai_control', 'identity_and_audit', 'immich_ai_state')) {
    Assert-True ($semanticFunction.Contains("'$semanticDomain'")) ('private_v16_to_v18_semantic_domain_missing_' + $semanticDomain)
}
foreach ($requiredPiwigoIdentityTable in @('pwg_users', 'pwg_user_access', 'pwg_user_group', 'pwg_user_infos', 'pwg_groups')) {
    Assert-True ($semanticFunction.Contains($requiredPiwigoIdentityTable)) ('private_v16_to_v18_piwigo_identity_table_missing_' + $requiredPiwigoIdentityTable)
}
Assert-True ($semanticFunction.Contains('pwg_user_access''; SELECT * FROM ${pwg}user_access ORDER BY user_id,cat_id;') -and -not $semanticFunction.Contains('pwg_user_access''; SELECT * FROM ${pwg}user_access ORDER BY user_id,category_id;')) 'private_v16_to_v18_piwigo_user_access_ordering_invalid'
Assert-True ($semanticFunction.Contains('asset_face') -and $semanticFunction.Contains('face_search') -and $semanticFunction.Contains('smart_search') -and $semanticFunction.Contains("'immich_ai_state'")) 'private_v16_to_v18_immich_ai_fingerprint_missing'
Assert-True ($semanticFunction.Contains("case `"`$digest`" in ''|*[!a-f0-9]*) exit 97 ;; esac") -and $semanticFunction.Contains('[ "${#digest}" -eq 64 ] || exit 97')) 'private_v16_to_v18_immich_digest_length_validation_missing'
Assert-True ($semanticFunction.Contains('--binary-as-hex') -and -not $semanticFunction.Contains('--raw') -and $semanticFunction.Contains('--no-psqlrc') -and $semanticFunction.Contains("'--user','postgres'") ) 'private_v16_to_v18_deterministic_database_serialization_missing'
Assert-True ($countsFunction.Contains('if [ "$schema_version" = 16 ]; then') -and $countsFunction.Contains('collection_snapshot_item') -and $countsFunction.Contains('spotlight_rotation_state')) 'private_v16_to_v18_target_structure_guard_missing'
Assert-True ($countsFunction.Contains('case "$rotation_rows" in 0|1|2)') -and $countsFunction.Contains("scope NOT IN ('FULL','HERITAGE')") -and $countsFunction.Contains('OCTET_LENGTH(candidate_digest) <> 32') -and $countsFunction.Contains('OCTET_LENGTH(revision) <> 32')) 'private_v16_to_v18_rotation_shape_validation_missing'
Assert-True ($helper.Contains('v17_v18_expansion=STRUCTURALLY_VALID') -and $helper.Contains('rotation=IDLE_OR_OPERATIONAL')) 'private_v16_to_v18_target_output_contract_missing'

# The helper never brings row content to PowerShell: deterministic rows stream
# only to a container-local temporary file before sha256sum produces an opaque
# value. Password material remains inside the container environment.
Assert-True ($semanticFunction.Contains('q_to_file') -and $semanticFunction.Contains('sha256sum') -and $semanticFunction.Contains('mktemp')) 'private_v16_to_v18_container_local_fingerprint_missing'
Assert-True ($helper.Contains('export MYSQL_PWD') -and $helper.Contains('unset MARIADB_ROOT_PASSWORD') -and -not $helper.Contains('--password=')) 'private_v16_to_v18_db_secret_process_argument_forbidden'
foreach ($privateMarker in @('relative_source_path', 'source_filename', 'absolute_path', 'full_path')) {
    Assert-True (-not $helper.Contains($privateMarker)) ('private_v16_to_v18_private_marker_forbidden_' + $privateMarker)
}
foreach ($forbiddenMutation in @('INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP TABLE', 'ALTER TABLE', 'Copy-Item', 'Move-Item', 'Remove-Item', 'piwigo_data:', 'piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', '/source/')) {
    Assert-True (-not $helper.Contains($forbiddenMutation)) ('private_v16_to_v18_mutation_or_media_mount_forbidden_' + ($forbiddenMutation -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Source-defined migrations must show the expected V16/V17/V18 ledger names;
# the helper does not assume a bare integer without source-side continuity.
foreach ($migrationName in @(
    '0016_private_source_presentation_surrogate',
    '0017_photos_app_v4_collection_snapshots',
    '0018_photos_app_v4_spotlight_rotation_state'
)) {
    Assert-True ($schema.Contains($migrationName)) ('private_v16_to_v18_schema_ledger_missing_' + $migrationName)
}

Write-Output "PRIVATE_V16_TO_V18_OWNER_MIGRATION_PROTOCOL=PASS assertions=$assertions"
