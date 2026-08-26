[CmdletBinding()]
param()

# Static-only contract for the private full-library deployment runner. It reads
# no ignored endpoint env file, starts no container, and cannot access private
# media. The purpose is to stop a later edit from silently treating 8191 as the
# 8291 staging endpoint or from bypassing the v14 DB-only rollback snapshot.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$paths = @{
    deploy = Join-Path $projectRoot 'infra\scripts\deploy-private-full-class-plugins.ps1'
    lifecycle = Join-Path $projectRoot 'infra\scripts\private-full.ps1'
    compose = Join-Path $projectRoot 'infra\docker-compose.yml'
    privateOverride = Join-Path $projectRoot 'infra\private-full\docker-compose.override.yml'
    snapshot = Join-Path $projectRoot 'infra\scripts\create-pre-migration-db-snapshot.sh'
}

$assertions = 0
function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Read-Source([string]$Name) {
    $path = [string]$paths[$Name]
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('private_full_protocol_missing_' + $Name)
    return [IO.File]::ReadAllText($path)
}

function Index-OfOrFail([string]$Text, [string]$Needle, [string]$Code, [int]$Start = 0) {
    $index = $Text.IndexOf($Needle, $Start, [StringComparison]::Ordinal)
    Assert-True ($index -ge 0) $Code
    return $index
}

$deploy = Read-Source 'deploy'
$lifecycle = Read-Source 'lifecycle'
$compose = Read-Source 'compose'
$privateOverride = Read-Source 'privateOverride'
$snapshot = Read-Source 'snapshot'

# Endpoint selection must be explicit and cannot silently default to staging.
Assert-True (
    $deploy -match '(?s)\[Parameter\(Mandatory\s*=\s*\$true\)\]\s*\[ValidateSet\(\x27staging\x27,\s*\x27owner\x27\)\]\s*\[string\]\$Endpoint'
) 'private_full_deploy_endpoint_not_required'
Assert-True ($deploy -notmatch '\$Endpoint\s*=\s*[\x27\"]staging[\x27\"]') 'private_full_deploy_endpoint_defaults_to_staging'
Assert-True ($deploy -match "staging\s*=\s*@\{(?s:.*?)piwigo_env\s*=\s*'infra/private-full/\.env\.piwigo\.staging'(?s:.*?)immich_env\s*=\s*'infra/private-full/\.env\.immich\.staging'(?s:.*?)http_port\s*=\s*'8290'(?s:.*?)compat_port\s*=\s*'8291'(?s:.*?)runtime_action\s*=\s*'runtime-staging'") 'private_full_staging_endpoint_mapping_invalid'
Assert-True ($deploy -match "owner\s*=\s*@\{(?s:.*?)piwigo_env\s*=\s*'infra/private-full/\.env\.piwigo\.owner'(?s:.*?)immich_env\s*=\s*'infra/private-full/\.env\.immich\.owner'(?s:.*?)http_port\s*=\s*'8190'(?s:.*?)compat_port\s*=\s*'8191'(?s:.*?)validation_action\s*=\s*'validate-owner'(?s:.*?)runtime_action\s*=\s*'runtime-owner'") 'private_full_owner_endpoint_mapping_invalid'
Assert-True ($deploy.Contains('$target = $endpointConfig[$Endpoint]')) 'private_full_deploy_does_not_select_exact_endpoint_mapping'

# private-full.ps1 must validate and runtime-probe owner using the owner env
# rather than pretending that runtime-staging demonstrates 8191 correctness.
Assert-True ($lifecycle -match "ValidateSet\('validate', 'validate-owner'.*'runtime-staging', 'runtime-owner'") 'private_full_lifecycle_owner_actions_missing'
Assert-True ($lifecycle.Contains("'validate-owner' = 'owner'") -and $lifecycle.Contains("'runtime-owner' = 'owner'")) 'private_full_lifecycle_owner_action_mapping_missing'
Assert-True ($lifecycle.Contains("if (`$Mode -eq 'owner') { return @{ http = '8190'; compat = '8191'; base = 'http://127.0.0.1:8190' } }")) 'private_full_owner_endpoint_spec_invalid'
Assert-True ($lifecycle.Contains("if (`$Mode -eq 'owner') { return @{ piwigo = `$PiwigoOwnerEnvPath; immich = `$ImmichOwnerEnvPath } }")) 'private_full_owner_env_selection_invalid'
Assert-True ($lifecycle.Contains('function Assert-EndpointRuntime')) 'private_full_generic_runtime_probe_missing'
Assert-True ($lifecycle.Contains("`$endpoint = Get-ValidatedEndpoint `$endpointMode")) 'private_full_runtime_probe_does_not_validate_selected_endpoint'
Assert-True ($lifecycle.Contains("action=' + `$Action + ' endpoint=' + `$endpointLabel")) 'private_full_runtime_owner_output_not_endpoint_bound'

# Maintenance must close browser writes before the owner schema probe. Exact
# v14 then takes a snapshot; exact v15 explicitly skips it. Piwigo alone stops
# only for the v14 snapshot, while MariaDB remains available for MyISAM dump.
$prepareIndex = Index-OfOrFail $deploy 'prepare-class-archive-maintenance.php' 'private_full_deploy_maintenance_prepare_missing'
$waitIndex = Index-OfOrFail $deploy 'Wait-Maintenance' 'private_full_deploy_maintenance_wait_missing' $prepareIndex
$ownerBranchIndex = Index-OfOrFail $deploy "if (`$Endpoint -eq 'owner')" 'private_full_deploy_owner_snapshot_branch_missing' $waitIndex
$ownerProbeIndex = Index-OfOrFail $deploy 'Get-OwnerPreMigrationSnapshotRequirement' 'private_full_deploy_schema_probe_call_missing' $ownerBranchIndex
$ownerSnapshotIndex = Index-OfOrFail $deploy 'Create-OwnerPreMigrationSnapshot' 'private_full_deploy_snapshot_call_missing' $ownerBranchIndex
$installIndex = Index-OfOrFail $deploy "install-class-archive-plugins.php'" 'private_full_deploy_plugin_install_missing'
Assert-True ($prepareIndex -lt $waitIndex -and $waitIndex -lt $ownerProbeIndex -and $ownerProbeIndex -lt $ownerSnapshotIndex -and $ownerSnapshotIndex -lt $installIndex) 'private_full_deploy_snapshot_not_before_migration'
Assert-True ($deploy.Contains("if (`$Endpoint -eq 'owner')")) 'private_full_owner_snapshot_guard_missing'
Assert-True ($deploy -match "function Get-OwnerPreMigrationSnapshotRequirement \{(?s:.*?)CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe(?s:.*?)REQUIRED_CURRENT_V14\|NOT_REQUIRED_CURRENT_V15(?s:.*?)private_full_owner_schema_probe_invalid") 'private_full_owner_schema_probe_protocol_invalid'
Assert-True ($deploy -match 'if \(\$ownerSnapshotRequirement -eq ''REQUIRED_CURRENT_V14''\) \{(?s:.*?)Create-OwnerPreMigrationSnapshot(?s:.*?)elseif \(\$ownerSnapshotRequirement -eq ''NOT_REQUIRED_CURRENT_V15''\) \{(?s:.*?)\$preMigrationSnapshot = ''NOT_REQUIRED_CURRENT_V15''') 'private_full_owner_schema_branch_not_idempotent'
Assert-True ($deploy -match "function Create-OwnerPreMigrationSnapshot \{(?s:.*?)Invoke-FullCompose @\('stop', 'piwigo'\)(?s:.*?)Assert-PiwigoStoppedForSnapshot(?s:.*?)CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true(?s:.*?)'pre-migration-db-backup'") 'private_full_snapshot_stop_and_confirmation_protocol_invalid'
Assert-True ($deploy -match "function Assert-PiwigoStoppedForSnapshot \{(?s:.*?)class_archive_private_full_v3_piwigo-piwigo-1(?s:.*?)false\|exited") 'private_full_snapshot_writer_stop_assertion_missing'
Assert-True ($deploy.Contains('function RecreatePiwigoUnderMaintenance')) 'private_full_post_snapshot_recreate_missing'
Assert-True ($deploy -match "function Assert-PiwigoPhpFpmReady \{(?s:.*?)php-fpm-ready\.php") 'private_full_post_snapshot_php_fpm_probe_missing'
Assert-True ($deploy -match "function RecreatePiwigoUnderMaintenance \{(?s:.*?)'up', '-d', '--force-recreate', '--no-deps', 'piwigo'(?s:.*?)Wait-Maintenance(?s:.*?)Assert-PiwigoPhpFpmReady(?s:.*?)prepare-class-archive-maintenance\.php") 'private_full_post_snapshot_maintenance_recreate_protocol_invalid'
Assert-True ($deploy -match "install-class-archive-plugins\.php', '--verify-runtime'(?s:.*?)install-class-archive-plugins\.php', '--finalize-maintenance'") 'private_full_runtime_verify_finalize_missing'

# The rollback snapshot service is database-only, root-local and integrity
# checked. It may see the app-network database, but never media/source mounts.
$serviceMatch = [regex]::Match($compose, '(?ms)^  pre-migration-db-backup:\r?\n(?<body>.*?)(?=^  backup-audit:)')
Assert-True $serviceMatch.Success 'private_full_snapshot_service_missing'
$service = if ($serviceMatch.Success) { $serviceMatch.Groups['body'].Value } else { '' }
Assert-True ($service -match 'profiles:\s*\["ops"\]') 'private_full_snapshot_service_profile_invalid'
Assert-True ($service -match 'read_only:\s*true') 'private_full_snapshot_service_read_only_missing'
Assert-True ($service.Contains('cap_drop: ["ALL"]') -and $service.Contains('no-new-privileges:true')) 'private_full_snapshot_service_hardening_missing'
Assert-True ($service.Contains('/workspace/infra/scripts/create-pre-migration-db-snapshot.sh')) 'private_full_snapshot_service_entrypoint_invalid'
Assert-True ($service.Contains('- backups:/backup') -and $service.Contains('- ../infra:/workspace/infra:ro')) 'private_full_snapshot_service_required_mounts_missing'
foreach ($forbiddenMount in @('piwigo_data:', 'piwigo_uploads:', 'piwigo_galleries:', 'piwigo_derivatives:', 'piwigo_scripts:', '/source/', '/private-real-full/')) {
    Assert-True (-not $service.Contains($forbiddenMount)) ('private_full_snapshot_service_forbidden_media_mount_' + ($forbiddenMount -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($privateOverride -match '(?ms)^  pre-migration-db-backup:\r?\n(?<body>.*?)(?=^  backup-audit:)' ) 'private_full_snapshot_private_override_missing'

Assert-True ($snapshot.Contains('umask 077')) 'private_full_snapshot_umask_missing'
Assert-True ($snapshot.Contains('CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM')) 'private_full_snapshot_confirmation_missing'
Assert-True ($snapshot.Contains('CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE') -and $snapshot.Contains('probe|snapshot')) 'private_full_snapshot_probe_mode_missing'
Assert-True ($snapshot.Contains('14:15')) 'private_full_snapshot_v14_to_v15_gate_missing'
Assert-True ($snapshot.Contains('REQUIRED_CURRENT_V14') -and $snapshot.Contains('NOT_REQUIRED_CURRENT_V15')) 'private_full_snapshot_schema_statuses_missing'
Assert-True ($snapshot.Contains('source_schema_not_v14_or_v15')) 'private_full_snapshot_schema_source_gate_missing'
Assert-True ($snapshot.Contains('mariadb-dump --quick --lock-all-tables')) 'private_full_snapshot_myisam_lock_missing'
Assert-True ($snapshot.Contains('MYSQL_PWD="$DB_ROOT_PASSWORD"')) 'private_full_snapshot_secret_not_passed_out_of_argv'
Assert-True (-not $snapshot.Contains('--password=') -and -not $snapshot.Contains('set -x') -and -not $snapshot.Contains('echo "$DB_ROOT_PASSWORD"')) 'private_full_snapshot_secret_output_risk'
Assert-True ($snapshot.Contains('"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK"') -and $snapshot.Contains('"media":"NOT_INCLUDED"')) 'private_full_snapshot_manifest_scope_invalid'
Assert-True ($snapshot.Contains('SHA256SUMS') -and $snapshot.Contains('sha256sum -c SHA256SUMS')) 'private_full_snapshot_hash_manifest_verification_missing'
Assert-True ($snapshot.Contains('PRE_MIGRATION_DB_SNAPSHOT=PASS')) 'private_full_snapshot_pass_record_missing'

# New bytes / read domains need explicit projection recovery. The BFF gets the
# selected endpoint env and only the restricted compatibility service restarts.
$projectionIndex = Index-OfOrFail $deploy 'rebuild-photo-read-projection.php' 'private_full_deploy_projection_rebuild_missing'
$bffIndex = Index-OfOrFail $deploy "Invoke-ImmichCompose @('up', '-d', '--wait', '--wait-timeout', '60', '--force-recreate', 'immich-web-compat')" 'private_full_deploy_compat_only_restart_missing'
$finalizeIndex = Index-OfOrFail $deploy "install-class-archive-plugins.php', '--finalize-maintenance'" 'private_full_deploy_finalize_missing' $bffIndex
$runtimeIndex = Index-OfOrFail $deploy 'Invoke-EndpointLifecycle ([string]$target.runtime_action)' 'private_full_deploy_owner_runtime_validation_missing'
Assert-True ($installIndex -lt $projectionIndex -and $projectionIndex -lt $bffIndex -and $bffIndex -lt $finalizeIndex -and $finalizeIndex -lt $runtimeIndex) 'private_full_deploy_projection_bff_finalize_runtime_order_invalid'
Assert-True ($deploy -notmatch "Invoke-ImmichCompose\s+@\([^\)]*'immich-server'" -and $deploy -notmatch "Invoke-ImmichCompose\s+@\([^\)]*'immich-machine-learning'") 'private_full_deploy_restarts_immich_data_or_ml_service'
Assert-True ($deploy.Contains("'env', ('IMMICH_SPIKE_ENV_FILE=' + `$immichEnvWsl)")) 'private_full_bff_owner_env_handoff_missing'

# Do not declare a recovery proof merely because a pre-migration rollback dump
# exists. Maintenance is run without destructive rejected-binary cleanup.
Assert-True ($deploy -match "run-maintenance\.php', '--json'") 'private_full_non_destructive_maintenance_missing'
Assert-True (-not ($deploy -match "run-maintenance\.php'(?s:.*?)--apply-rejected-cleanup")) 'private_full_deploy_enables_rejected_cleanup'
Assert-True ($deploy.Contains('maintenance=NON_DESTRUCTIVE') -and $deploy.Contains('backup_restore=NOT_REVALIDATED')) 'private_full_deploy_backup_restore_status_overclaimed'
Assert-True (-not $deploy.Contains('--with-synthetic-fixtures')) 'private_full_deploy_must_not_create_synthetic_fixtures'

Write-Output "PRIVATE_FULL_OWNER_DEPLOY_PROTOCOL=PASS assertions=$assertions"
