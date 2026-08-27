[CmdletBinding()]
param()

# Static-only contract for the restore v15 -> v16 deployment endpoint. It
# never reads ignored env files and never starts Docker.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$deployPath = Join-Path $projectRoot 'infra\scripts\deploy-owner-restore-class-plugins.ps1'
$baseComposePath = Join-Path $projectRoot 'infra\docker-compose.yml'
$restorePiwigoPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.piwigo.override.yml'
$restoreImmichPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.immich.override.yml'
$snapshotPath = Join-Path $projectRoot 'infra\scripts\create-pre-migration-db-snapshot.sh'
$readmePath = Join-Path $projectRoot 'infra\owner-restore\README.md'
$devPath = Join-Path $projectRoot 'infra\scripts\dev.ps1'

$assertions = 0
function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Tracked([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}
function Index-OfOrFail([string]$Text, [string]$Needle, [string]$Code, [int]$Start = 0) {
    $index = $Text.IndexOf($Needle,$Start,[StringComparison]::Ordinal)
    Assert-True ($index -ge 0) $Code
    return $index
}

$deploy = Read-Tracked $deployPath 'restore_schema_deploy_missing'
$baseCompose = Read-Tracked $baseComposePath 'restore_schema_base_compose_missing'
$restorePiwigo = Read-Tracked $restorePiwigoPath 'restore_schema_piwigo_override_missing'
$restoreImmich = Read-Tracked $restoreImmichPath 'restore_schema_immich_override_missing'
$snapshot = Read-Tracked $snapshotPath 'restore_schema_snapshot_helper_missing'
$readme = Read-Tracked $readmePath 'restore_schema_readme_missing'
$dev = Read-Tracked $devPath 'restore_schema_dev_missing'

$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($deployPath,[ref]$tokens,[ref]$errors)
Assert-True ($errors.Count -eq 0) 'restore_schema_deploy_parse_failed'

Assert-True ($deploy.Contains("[ValidateSet('validate', 'migrate')]")) 'restore_schema_action_boundary_missing'
Assert-True ($deploy.Contains('[switch]$ConfirmRestoreMigration')) 'restore_schema_confirmation_missing'
Assert-True ($deploy.Contains('$piwigoProject = ''class_archive_owner_restore_v1_piwigo''')) 'restore_schema_piwigo_project_invalid'
Assert-True ($deploy.Contains('$immichProject = ''class_archive_owner_restore_v1_immich''')) 'restore_schema_immich_project_invalid'
Assert-True ($deploy.Contains('$piwigoEnvRelative = ''infra/owner-restore/.env.piwigo''')) 'restore_schema_piwigo_env_invalid'
Assert-True ($deploy.Contains('$immichEnvRelative = ''infra/owner-restore/.env.immich''')) 'restore_schema_immich_env_invalid'
Assert-True ($deploy.Contains('-d Ubuntu --cd $projectRoot -- @($prefix + $Arguments)') -and -not $deploy.Contains("@('--cd',`$projectRoot,'--')")) 'restore_schema_compose_cwd_invocation_invalid'
Assert-True ($deploy.Contains("CLASS_ARCHIVE_HTTP_PORT='8290'") -and $deploy.Contains("CLASS_ARCHIVE_COMPAT_HTTP_PORT='8291'")) 'restore_schema_ports_invalid'
Assert-True ($deploy.Contains("CLASS_ARCHIVE_RESTORE_NGINX_CONFIG='../.codex-work/owner-restore/runtime/nginx.conf'")) 'restore_schema_nginx_env_identity_missing'
Assert-True ($deploy.Contains('$lockPath = Join-Path $runtimeRoot ''class-plugin-v15-v16.lock''')) 'restore_schema_isolated_lock_missing'
Assert-True ($deploy.Contains('Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath')) 'restore_schema_lock_not_acquired'

Assert-True ($deploy.Contains('$migrationSourceVersion = 15') -and $deploy.Contains('$migrationTargetVersion = 16')) 'restore_schema_version_boundary_missing'
Assert-True ($deploy.Contains('$migrationRequiredStatus = ''REQUIRED_CURRENT_V15''') -and $deploy.Contains('$migrationCurrentStatus = ''NOT_REQUIRED_CURRENT_V16''')) 'restore_schema_state_boundary_missing'
Assert-True ($deploy.Contains('restore_deploy_checkout_not_clean') -and $deploy.Contains('CURRENT_VERSION')) 'restore_schema_source_attestation_missing'
Assert-True ($deploy.Contains('Get-SnapshotRequirement') -and $deploy.Contains('restore_deploy_schema_probe_invalid')) 'restore_schema_probe_missing'
Assert-True ($deploy.Contains("CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe") -and $deploy.Contains("CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=snapshot") -and $deploy.Contains("CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true")) 'restore_schema_snapshot_boundary_missing'
Assert-True ($deploy.Contains("Invoke-Compose 'piwigo' @('stop','piwigo')") -and $deploy.Contains('Assert-PiwigoStoppedForSnapshot')) 'restore_schema_writer_stop_missing'
Assert-True ($deploy.Contains('PASS_V15_TO_V16_DB_ONLY') -and $deploy.Contains('NOT_REQUIRED_CURRENT_V16')) 'restore_schema_idempotence_missing'

$execution = Index-OfOrFail $deploy '$lock = $null' 'restore_schema_execution_missing'
$validateBranch = Index-OfOrFail $deploy "if (`$Action -eq 'validate')" 'restore_schema_validate_branch_missing' $execution
$lockRootPrepare = Index-OfOrFail $deploy 'Initialize-RuntimeRootForLock' 'restore_schema_lock_root_prepare_missing' $validateBranch
$lockAcquire = Index-OfOrFail $deploy 'Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath' 'restore_schema_lock_acquire_missing' $lockRootPrepare
$gitPrepare = Index-OfOrFail $deploy 'Initialize-RestoreGitEvidenceRoot' 'restore_schema_git_prepare_missing' $lockAcquire
$nginxPrepare = Index-OfOrFail $deploy 'Initialize-RestoreNginxConfig' 'restore_schema_nginx_prepare_missing' $gitPrepare
$prepare = Index-OfOrFail $deploy 'maintenance_prepare' 'restore_schema_prepare_missing' $nginxPrepare
$probe = Index-OfOrFail $deploy '$schemaState = Get-SnapshotRequirement' 'restore_schema_probe_order_missing' $prepare
$snapshotCall = Index-OfOrFail $deploy 'Create-PreMigrationSnapshot' 'restore_schema_snapshot_order_missing' $probe
$recreate = Index-OfOrFail $deploy 'Recreate-RestorePiwigoUnderMaintenance $sourceHead' 'restore_schema_recreate_order_missing' $snapshotCall
$install = Index-OfOrFail $deploy "install-class-archive-plugins.php')" 'restore_schema_install_order_missing' $recreate
$targetProbe = Index-OfOrFail $deploy 'Get-SnapshotRequirement) -eq $migrationCurrentStatus' 'restore_schema_target_probe_missing' $install
$projection = Index-OfOrFail $deploy 'rebuild-photo-read-projection.php' 'restore_schema_projection_missing' $targetProbe
$compat = Index-OfOrFail $deploy "'--no-deps','immich-web-compat'" 'restore_schema_compat_missing' $projection
$finalize = Index-OfOrFail $deploy "install-class-archive-plugins.php','--finalize-maintenance'" 'restore_schema_finalize_missing' $compat
Assert-True ($gitPrepare -lt $nginxPrepare -and $nginxPrepare -lt $prepare -and $prepare -lt $probe -and $probe -lt $snapshotCall -and $snapshotCall -lt $recreate -and $recreate -lt $install -and $install -lt $targetProbe -and $targetProbe -lt $projection -and $projection -lt $compat -and $compat -lt $finalize) 'restore_schema_publish_order_invalid'
Assert-True ($validateBranch -lt $lockRootPrepare -and $lockRootPrepare -lt $lockAcquire -and $lockAcquire -lt $gitPrepare) 'restore_schema_validate_or_lock_order_invalid'
Assert-True ($deploy.Contains('[IO.File]::WriteAllBytes($lockPath, [byte[]]::new(0))') `
    -and $deploy.Contains('Set-ClassArchiveOwnerOnlyFileAcl -Path $lockPath') `
    -and $deploy.Contains('Assert-ClassArchiveOwnerOnlyFileAcl -Path $lockPath') `
    -and $deploy.Contains("Test-IgnoredPrivatePath `$lockPath 'restore_deploy_lock_not_private'")) 'restore_schema_lock_privacy_missing'
Assert-True ($deploy.IndexOf('Assert-ClassArchiveOwnerOnlyFileAcl -Path $lockPath',[StringComparison]::Ordinal) -lt $lockAcquire) 'restore_schema_lock_acl_asserted_after_exclusive_open'

Assert-True ($deploy.Contains("Wait-Maintenance") -and $deploy.Contains("'Class Archive maintenance mode.'") -and $deploy.Contains("'RESTORE_STATUS:503'")) 'restore_schema_fail_closed_maintenance_missing'
Assert-True ($deploy.Contains("'up','-d','--force-recreate','--no-deps','piwigo'")) 'restore_schema_piwigo_only_recreate_missing'
Assert-True ($deploy.Contains("'up','-d','--wait','--wait-timeout','60','--force-recreate','--no-deps','immich-web-compat'")) 'restore_schema_bff_only_recreate_missing'
Assert-True (-not ($deploy -match "Invoke-Compose 'immich'(?s:.*?)'immich-server'|Invoke-Compose 'immich'(?s:.*?)'immich-machine-learning'")) 'restore_schema_restarts_immich_runtime'
Assert-True ($deploy.Contains('run-maintenance.php') -and -not $deploy.Contains('--apply-rejected-cleanup')) 'restore_schema_maintenance_not_non_destructive'

Assert-True ($deploy.Contains('Get-ProtectedRuntimeFingerprint') -and $deploy.Contains('protected_runtime_changed_during_restore_deploy')) 'restore_schema_8091_8191_fingerprint_missing'
Assert-True ($deploy.Contains("'http://127.0.0.1:8091/photos'") -and $deploy.Contains("'http://127.0.0.1:8191/home'")) 'restore_schema_8091_8191_http_guard_missing'
Assert-True ($deploy.Contains('Get-RestoreNonTargetFingerprint') -and $deploy.Contains('restore_non_target_service_changed')) 'restore_schema_non_target_fingerprint_missing'
Assert-True ($deploy.Contains('$runningIdentity = $Project + ''|'' + $scopeLabel + ''|true|running''') `
    -and $deploy.Contains('$exitedIdentity = $Project + ''|'' + $scopeLabel + ''|false|exited''') `
    -and $deploy.Contains('@($runningIdentity, $exitedIdentity)')) 'restore_schema_optional_exited_container_identity_invalid'
Assert-True ($deploy.Contains('Assert-RestoreVolumeIdentities') -and $deploy.Contains('/mnt/classarchive-owner-restore-v1/volumes')) 'restore_schema_volume_identity_missing'
Assert-True ($deploy.Contains('com.classarchive.scope') -and $deploy.Contains('owner-restore-drill') -and $deploy.Contains('m-ext4-bind')) 'restore_schema_label_identity_missing'
Assert-True ($deploy.Contains("'80/tcp -> 127.0.0.1:8290'") -and $deploy.Contains("'8081/tcp -> 127.0.0.1:8291'")) 'restore_schema_loopback_identity_missing'
Assert-True ($deploy.Contains('restore_deploy_internal_service_exposed')) 'restore_schema_internal_port_guard_missing'

# The current checkout and BuildCommit evidence must advance together before
# the current workspace-backed Piwigo is recreated. Keeping the backup source
# HEAD would falsely attest old code against the v16 runtime.
$evidence = Index-OfOrFail $deploy 'Update-RestoreGitEvidence $Head' 'restore_schema_git_evidence_update_missing'
$composeRecreate = Index-OfOrFail $deploy "Invoke-Compose 'piwigo' @('up','-d','--force-recreate','--no-deps','piwigo')" 'restore_schema_git_recreate_missing' $evidence
Assert-True ($evidence -lt $composeRecreate) 'restore_schema_git_evidence_update_order_invalid'
Assert-True ($deploy.Contains('Set-ClassArchiveOwnerOnlyFileAcl -Path $gitEvidenceHead') -and $deploy.Contains('restore_deploy_git_evidence_not_private')) 'restore_schema_git_evidence_privacy_missing'
Assert-True ($deploy.Contains('Assert-RestoreGitEvidencePreflight') -and $deploy.Contains('Initialize-RestoreGitEvidenceRoot')) 'restore_schema_git_evidence_late_failure_guard_missing'
Assert-True ($deploy.Contains('[IO.Directory]::CreateDirectory($runtimeRoot)') -and $deploy.Contains('Set-OwnerOnlyDirectoryAcl $runtimeRoot')) 'restore_schema_runtime_root_prepare_missing'
Assert-True ($deploy.Contains('Set-OwnerOnlyDirectoryAcl $gitEvidenceRoot') -and $deploy.Contains('Set-OwnerOnlyDirectoryAcl $gitEvidenceRefs')) 'restore_schema_git_evidence_directory_acl_missing'
Assert-True ($deploy.Contains('Get-ChildItem -LiteralPath $gitEvidenceRefs -Force') -and $deploy.Contains('restore_deploy_git_evidence_refs_invalid')) 'restore_schema_git_evidence_refs_guard_missing'
Assert-True ($deploy.Contains('Assert-RestoreWorkspaceMounts') -and $deploy.Contains("@('infra','plugins','themes','tests')")) 'restore_schema_current_workspace_mount_missing'
Assert-True ($deploy.Contains('Assert-RestoreNginxPreflight') -and $deploy.Contains('Initialize-RestoreNginxConfig')) 'restore_schema_nginx_late_failure_guard_missing'
Assert-True ($deploy.Contains('set_real_ip_from 10.245.0.10/32;') -and $deploy.Contains('Set-ClassArchiveOwnerOnlyFileAcl -Path $restoreNginxPath') -and $deploy.Contains('restore_deploy_nginx_not_private')) 'restore_schema_nginx_private_generation_missing'

$snapshotService = [regex]::Match($baseCompose,'(?ms)^  pre-migration-db-backup:\r?\n(?<body>.*?)(?=^  backup-audit:)')
Assert-True $snapshotService.Success 'restore_schema_snapshot_service_missing'
$snapshotBody = if ($snapshotService.Success) { $snapshotService.Groups['body'].Value } else { '' }
Assert-True ($snapshotBody.Contains('- backups:/backup') -and $snapshotBody.Contains('- ../infra:/workspace/infra:ro')) 'restore_schema_snapshot_mounts_missing'
foreach ($forbidden in @('piwigo_data:','piwigo_uploads:','piwigo_galleries:','piwigo_derivatives:','piwigo_scripts:','/source/')) {
    Assert-True (-not $snapshotBody.Contains($forbidden)) ('restore_schema_snapshot_media_mount_' + ($forbidden -replace '[^a-z0-9]+','_'))
}
Assert-True ($restorePiwigo -match '(?ms)^  pre-migration-db-backup:\r?\n(?<body>.*?)(?=^  backup-audit:)' -and $restorePiwigo.Contains('com.classarchive.scope: owner-restore-drill')) 'restore_schema_snapshot_restore_label_missing'
Assert-True (-not ($restorePiwigo -match '(?m)^\s+ports:\s*$')) 'restore_schema_override_redefines_public_listener'
Assert-True ($restoreImmich.Contains('com.classarchive.scope: owner-restore-drill')) 'restore_schema_immich_restore_label_missing'
Assert-True ($snapshot.Contains('mariadb-dump --quick --lock-all-tables') -and $snapshot.Contains('SHA256SUMS') -and $snapshot.Contains('sha256sum -c SHA256SUMS')) 'restore_schema_snapshot_integrity_missing'
Assert-True ($snapshot.Contains('15:16') -and $snapshot.Contains('migration_ledger_not_contiguous')) 'restore_schema_snapshot_exact_ledger_gate_missing'
Assert-True (-not $deploy.Contains("infra/private-full/.env.piwigo.owner") -and -not $deploy.Contains("infra/private-full/.env.piwigo.staging")) 'restore_schema_private_full_env_referenced'
Assert-True ($readme.Contains('deploy-owner-restore-class-plugins.ps1 validate') -and $readme.Contains('migrate -ConfirmRestoreMigration')) 'restore_schema_readme_usage_missing'
Assert-True ($readme.Contains('database-only rollback snapshot') -and $readme.Contains('fail-closed')) 'restore_schema_readme_safety_missing'
Assert-True ($dev.Contains('owner-restore-schema-migration-protocol.ps1')) 'restore_schema_protocol_not_wired'

Write-Output "OWNER_RESTORE_SCHEMA_MIGRATION_PROTOCOL=PASS assertions=$assertions"
