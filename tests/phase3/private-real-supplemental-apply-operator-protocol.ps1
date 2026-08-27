[CmdletBinding()]
param()

# Public-safe static contract. It never opens ignored artifacts, source roots,
# Docker state, a private environment, or a real image.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$operator = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\apply-private-real-supplemental.ps1'))
$overlay = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\private-full\docker-compose.supplemental-apply.override.yml'))
$target = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\verify-private-real-supplemental-target.php'))
$importer = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\import-private-real-full.php'))
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

try {
    Assert-Protocol ($operator.Contains("[ValidateSet('validate', 'apply')]")) 'operator_action_set_invalid'
    Assert-Protocol ($operator.Contains("[string]`$Action = 'validate'")) 'operator_safe_action_default_missing'
    Assert-Protocol ($operator.Contains("[ValidateSet('restore', 'owner')]")) 'operator_target_set_invalid'
    Assert-Protocol ($operator.Contains("[string]`$Target = 'restore'")) 'operator_restore_default_missing'
    Assert-Protocol ($operator.Contains('ConfirmSupplementalApply.IsPresent')) 'apply_confirmation_missing'
    Assert-Protocol ($operator.Contains('ConfirmOwnerRuntime.IsPresent')) 'owner_second_confirmation_missing'
    $confirm = $operator.IndexOf("Assert-Apply `$ConfirmSupplementalApply.IsPresent 'apply_confirmation_required'", [StringComparison]::Ordinal)
    $maintenance = $operator.IndexOf("`$script:stage = 'maintenance_gate'", [StringComparison]::Ordinal)
    Assert-Protocol ($confirm -ge 0 -and $maintenance -gt $confirm) 'confirmation_after_mutation'
    $workflowLock = $operator.IndexOf("`$lock = Enter-ClassArchivePluginWorkflowLock", [StringComparison]::Ordinal)
    $schemaProbe = $operator.IndexOf("`$schemaLines = Invoke-Compose", [StringComparison]::Ordinal)
    Assert-Protocol ($workflowLock -ge 0 -and $schemaProbe -gt $workflowLock) 'runtime_preflight_not_serialized'
    Assert-Protocol ($operator.Contains("project = 'class_archive_owner_restore_v1_piwigo'")) 'restore_project_missing'
    Assert-Protocol ($operator.Contains("project = 'class_archive_private_full_v3_piwigo'")) 'owner_project_missing'
    Assert-Protocol ($operator.Contains("env = 'infra/owner-restore/.env.piwigo'")) 'restore_env_missing'
    Assert-Protocol ($operator.Contains("env = 'infra/private-full/.env.piwigo.owner'")) 'owner_env_missing'
    Assert-Protocol ($operator.Contains("`$declaredVolumes = Get-Property `$config 'volumes'")) 'compose_declared_volume_lookup_missing'
    Assert-Protocol ($operator.Contains("`$source -ceq [string]`$expected.key")) 'compose_logical_volume_key_gate_missing'
    Assert-Protocol ($operator.Contains("[string](Get-Property `$declaration 'name') -ceq [string]`$expected.name")) 'compose_runtime_volume_name_gate_missing'
    Assert-Protocol ($operator.Contains('Assert-IgnoredPrivateLeaf')) 'ignored_private_gate_missing'
    Assert-Protocol ($operator.Contains('Assert-ClassArchiveOwnerOnlyFileAcl')) 'owner_acl_gate_missing'
    Assert-Protocol ($operator.Contains('Invoke-VerifiedArtifact')) 'verified_artifact_gate_missing'
    Assert-Protocol ($operator.Contains('sources=28 presentations=26')) 'supplemental_input_contract_missing'
    Assert-Protocol ($operator.Contains('durable_applied=26 durable_deduplicated=2')) 'supplemental_terminal_contract_missing'
    Assert-Protocol ($operator.Contains('PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=schema schema=16 source_paths=NOT_READ')) 'operator_schema_gate_missing'
    Assert-Protocol ($operator.Contains("'piwigo_writer_not_stopped'")) 'single_writer_gate_missing'
    Assert-Protocol ($operator.Contains("'apply_network_not_fresh'")) 'fresh_internal_network_gate_missing'
    Assert-Protocol ($operator.Contains("'docker', 'network', 'connect', '--alias', 'db'")) 'bounded_db_network_connect_missing'
    Assert-Protocol ($operator.Contains("'docker', 'network', 'disconnect', '--force'")) 'bounded_db_network_disconnect_missing'
    Assert-Protocol ($operator.Contains("'docker', 'network', 'rm'")) 'bounded_network_cleanup_missing'
    Assert-Protocol ($operator.Contains("@('create', '--no-build', '--no-recreate', 'supplemental-apply')")) 'network_create_may_recreate_db'
    Assert-Protocol ($operator.Contains("'/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare'")) 'maintenance_prepare_missing'
    Assert-Protocol ($operator.Contains("'/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance'")) 'maintenance_finalize_missing'
    Assert-Protocol ($operator.Contains("`$lines = @(Invoke-WslCapture @('docker', 'inspect', '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}'")) 'single_line_container_probe_not_array_wrapped'
    Assert-Protocol ($operator.Contains("`$state = @(Invoke-WslCapture @('docker', 'inspect', '--format', '{{.State.Status}}'")) 'single_line_writer_probe_not_array_wrapped'
    $stop = $operator.IndexOf("@('stop', 'piwigo')", [StringComparison]::Ordinal)
    $run = $operator.IndexOf("'supplemental-apply') 'supplemental_import_failed'", [StringComparison]::Ordinal)
    Assert-Protocol ($stop -gt $maintenance -and $run -gt $stop) 'writer_stop_order_invalid'
    Assert-Protocol ($operator.Contains('APPLY_VERIFIED_28_SOURCE_26_PRESENTATION_BATCH')) 'container_confirmation_missing'
    Assert-Protocol ($operator.Contains('historical_manifest=NOT_MOUNTED')) 'legacy_manifest_evidence_missing'
    Assert-Protocol ($operator.Contains('source_mount=NONE')) 'source_mount_evidence_missing'
    Assert-Protocol ($operator.Contains("'/opt/class-archive/import-private-real-full.php' = `$ProjectWsl.TrimEnd('/') + '/infra/scripts/import-private-real-full.php'")) 'importer_mount_identity_gate_missing'
    Assert-Protocol ($operator.Contains("'/opt/class-archive/verify-supplemental-target.php' = `$ProjectWsl.TrimEnd('/') + '/infra/scripts/verify-private-real-supplemental-target.php'")) 'target_gate_mount_identity_gate_missing'
    Assert-Protocol (-not ($operator -match 'Write-(?:Output|Host).*(?:ManifestWsl|StagingWsl|OutputPath|StagingPath|envFile)')) 'private_path_output_detected'
    Assert-Protocol (-not $operator.Contains('<private-drive-root>/')) 'm_source_path_detected'
    Assert-Protocol (-not ($operator -match '(?i)\bmariadb(?:-dump)?\b')) 'operator_direct_database_access_detected'

    Assert-Protocol ($overlay.Contains('supplemental-apply:')) 'apply_service_missing'
    Assert-Protocol ($overlay.Contains('supplemental_internal:')) 'apply_internal_network_missing'
    Assert-Protocol ($overlay.Contains('_supplemental_internal')) 'apply_network_identity_missing'
    Assert-Protocol ($overlay.Contains('internal: true')) 'apply_network_not_internal'
    Assert-Protocol ($overlay.Contains('read_only: true')) 'apply_root_not_read_only'
    Assert-Protocol ($overlay.Contains('cap_drop: ["ALL"]')) 'apply_cap_drop_missing'
    Assert-Protocol ($overlay.Contains('no-new-privileges:true')) 'apply_no_new_privileges_missing'
    Assert-Protocol ($overlay.Contains('pull_policy: never')) 'apply_pull_policy_invalid'
    Assert-Protocol ($overlay.Contains('/private-real-full/manifests/supplemental-import-manifest.json')) 'supplemental_manifest_mount_missing'
    Assert-Protocol ($overlay.Contains('/private-real-full/supplemental-staging')) 'supplemental_staging_mount_missing'
    Assert-Protocol ([regex]::Matches($overlay, 'create_host_path: false').Count -eq 4) 'apply_bind_guard_invalid'
    Assert-Protocol ($overlay.Contains('CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_ACTION: preflight')) 'apply_safe_action_default_missing'
    Assert-Protocol ($overlay.Contains('/opt/class-archive/verify-supplemental-target.php preflight')) 'container_preflight_missing'
    Assert-Protocol ($overlay.Contains('/opt/class-archive/verify-supplemental-target.php postflight')) 'container_postflight_missing'
    Assert-Protocol ($overlay.Contains('/opt/class-archive/import-private-real-full.php')) 'reviewed_importer_not_reused'
    Assert-Protocol (-not $overlay.Contains('../infra:/workspace/infra')) 'broad_infra_mount_detected'
    Assert-Protocol (-not $overlay.Contains('../plugins:/workspace/plugins')) 'broad_plugin_mount_detected'
    foreach ($forbidden in @('FULL_REAL_STAGING_PATH', 'FULL_REAL_IMPORT_MANIFEST_PATH', 'full-real-import-manifest.json', '/mnt/m/', 'source_root', 'relative_source_path')) {
        Assert-Protocol ($overlay.IndexOf($forbidden, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'apply_source_or_legacy_input_detected'
    }

    Assert-Protocol ($target.Contains('PRIVATE_SUPPLEMENTAL_TARGET_SOURCES = 28')) 'target_source_count_missing'
    Assert-Protocol ($target.Contains('PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS = 26')) 'target_presentation_count_missing'
    Assert-Protocol ($target.Contains("Schema::CURRENT_VERSION !== 16")) 'target_source_schema_gate_missing'
    Assert-Protocol ($target.Contains("(int) (`$migration['schema_version'] ?? 0) !== 16")) 'target_database_schema_gate_missing'
    Assert-Protocol ($target.Contains("(int) (`$import[0]['applied_count'] ?? -1) !== PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS")) 'target_applied_count_gate_missing'
    Assert-Protocol ($target.Contains("(int) (`$import[0]['deduplicated_count'] ?? -1) !== PRIVATE_SUPPLEMENTAL_TARGET_SOURCES - PRIVATE_SUPPLEMENTAL_TARGET_PRESENTATIONS")) 'target_deduplicated_count_gate_missing'
    Assert-Protocol ($target.Contains('supplemental_provenance_counts_invalid')) 'target_provenance_gate_missing'
    Assert-Protocol ($target.Contains('supplemental_preflight_26_plus_2_state_invalid')) 'target_preexisting_canonical_gate_missing'
    Assert-Protocol ($target.Contains("`$sourceExisting === 0 && `$canonicalExisting !== 0")) 'fresh_canonical_collision_gate_missing'
    Assert-Protocol ($target.Contains("? 'FRESH'" ) -and $target.Contains("? 'REPLAY' : 'RESUME'")) 'preflight_resume_mode_missing'
    Assert-Protocol ($target.Contains("array_key_exists('relative_source_path'")) 'target_sensitive_field_gate_missing'
    Assert-Protocol (-not ($target -match 'fwrite\([^\r\n]*(?:manifestPath|stagingPath|file)')) 'target_private_path_output_detected'

    $batchPreflight = $importer.IndexOf('privateFullPreflightSupplementalSources($repository, $manifest[''items'']);', [StringComparison]::Ordinal)
    $beginImport = $importer.IndexOf('$run = $library->beginImport(', [StringComparison]::Ordinal)
    Assert-Protocol ($batchPreflight -ge 0 -and $beginImport -gt $batchPreflight) 'batch_source_identity_preflight_order_invalid'
    Assert-Protocol ($importer.Contains("if (`$completedNoop)")) 'completed_manifest_noop_missing'
    Assert-Protocol ($importer.Contains('terminalAppliedPhotosForImport(')) 'durable_incremental_reconciliation_missing'

    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_APPLY_OPERATOR_PROTOCOL=PASS assertions=' + $assertions + ' evidence=STATIC_SYNTHETIC_ONLY')
}
catch {
    $code = [string]$_.Exception.Message
    if ($code -notmatch '^[a-z0-9_]{1,96}$') { $code = 'supplemental_apply_protocol_failed' }
    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_APPLY_OPERATOR_PROTOCOL=FAIL code=' + $code + ' assertions=' + $assertions)
    exit 1
}
