[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:assertions=0

function Assert-True([bool]$Condition,[string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw 'OWNER_RESTORE_V2_PROTOCOL=FAIL code=' + $Code }
}

function Read-Text([string]$Relative) {
    $path=Join-Path $root $Relative
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ($Relative.Replace('/','_') + '_missing')
    return [IO.File]::ReadAllText($path,[Text.Encoding]::UTF8)
}

$runner=Read-Text 'infra/scripts/owner-independent-restore-v2.ps1'
$stream=Read-Text 'infra/scripts/restore-owner-independent-backup-v2.sh'
$piwigo=Read-Text 'infra/owner-restore-v2/docker-compose.piwigo.override.yml'
$immich=Read-Text 'infra/owner-restore-v2/docker-compose.immich.override.yml'
$piwigoEnv=Read-Text 'infra/owner-restore-v2/piwigo.restore.env.example'
$immichEnv=Read-Text 'infra/owner-restore-v2/immich.restore.env.example'
$readme=Read-Text 'infra/owner-restore-v2/README.md'
$adapter=Read-Text 'infra/scripts/private-qa-immich.ps1'
$allV2=$runner+"`n"+$stream+"`n"+$piwigo+"`n"+$immich+"`n"+$piwigoEnv+"`n"+$immichEnv+"`n"+$readme

$tokens=$null;$errors=$null
[void][Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'infra/scripts/owner-independent-restore-v2.ps1'),[ref]$tokens,[ref]$errors)
Assert-True ($errors.Count -eq 0) 'runner_parse_failed'
[void][Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'infra/scripts/private-qa-immich.ps1'),[ref]$tokens,[ref]$errors)
Assert-True ($errors.Count -eq 0) 'adapter_parse_failed'

foreach ($required in @(
    "ValidateSet('validate', 'prepare-storage', 'restore', 'verify', 'cold-restart', 'status')",
    "`$sourceDrive = 'C:'","`$targetDrive = 'M:'",
    "`$sourceRoot = Join-Path (`$sourceDrive + '\') 'ClassArchive-Independent-Recovery'",
    "`$targetRoot = Join-Path (`$targetDrive + '\') 'ClassArchive-Independent-Restore-v2'",
    'classarchive-owner-restore-v2.ext4','/mnt/classarchive-owner-restore-v2',
    'class_archive_owner_restore_v2_piwigo','class_archive_owner_restore_v2_immich','class_archive_owner_restore_v2_gateway',
    'owner-independent-restore-v2','m-ext4-bind-v2','CLASSARCHIVE_V2',
    "@(8390,8391)",'127.0.0.1:8390','127.0.0.1:8391',
    'owner-full-v2-[0-9]{8}T[0-9]{6}Z','owner-full-recovery-v2','owner-portable-recovery-kit-v1',
    'owner-portable-recovery-secrets-v1','Read-ClassArchivePortableRecoveryPhrase','Read-ClassArchivePortableRecoveryEnvelope',
    'Get-PhysicalDiskIndex','recovery_media_not_physically_independent','Get-ProtectedRuntimeFingerprint','protected_runtime_changed',
    'Enter-WorkflowLock','workflow_lock_held','ConfirmCreateRestoreStorage','ConfirmIsolatedRestore','ConfirmColdRestart',
    'Assert-RestoreNetworkRangesFree','Assert-RestoreNetworkIsolation','Assert-RestoreVolumeIdentity','restore_runtime_not_fresh',
    'prepare-class-archive-maintenance.php','Assert-MaintenanceHttp','rebuild-photo-read-projection.php','--finalize-maintenance',
    'Reassert-MaintenanceAfterFailure','maintenance=FAIL_CLOSED','private-full-owner-media-http.php','MEDIAGUARD_ONLY',
    "--cd `$projectRoot --exec",'Copy-VerifiedModelCache','model_cache_manifest_mismatch','reindex=NO'
)) { Assert-True ($runner.Contains($required)) ('runner_contract_missing_' + [Math]::Abs($required.GetHashCode())) }

foreach ($forbidden in @(
    'class_archive_owner_restore_v1','ClassArchive-Temporary-Recovery','classarchive-owner-restore-v1.ext4',
    '/mnt/classarchive-owner-restore-v1','owner-restore-drill','m-ext4-bind|','10.245.','8290','8291','8190','8191',
    'ProtectedData','Unprotect-DpapiValue','CurrentUser','docker volume rm','docker system prune','docker volume prune',
    "'down'",'Remove-Item -LiteralPath $runtimeImage','Remove-Item -LiteralPath $targetRoot'
)) { Assert-True (-not $allV2.Contains($forbidden)) ('v2_forbidden_identity_' + [Math]::Abs($forbidden.GetHashCode())) }

Assert-True (([regex]::Matches($runner,[regex]::Escape('business-state/recovery-secrets.dpapi.json'))).Count -eq 1) 'legacy_envelope_not_inventory_only'
Assert-True (-not ($runner -match '(?s)Get-Content[^\r\n]*recovery-secrets\.dpapi|ReadAllText[^\r\n]*recovery-secrets\.dpapi')) 'legacy_envelope_read_forbidden'
Assert-True ($runner.Contains('$kitManifest.dpapi_required -eq $false')) 'portable_kit_machine_profile_rejection_missing'
Assert-True ($runner.Contains("`$manifest.encryption.portable_envelope.payload_format -eq 'owner-portable-recovery-secrets-v1'")) 'portable_root_contract_missing'
Assert-True ($runner.Contains("Assert-RestoreV2 (@(Compare-Object `$requiredPayloads @(`$checksums.Keys | Sort-Object)).Count -eq 0)")) 'root_checksum_exact_inventory_missing'
Assert-True ($runner.Contains('backup_bundle_inventory_invalid')) 'bundle_disk_inventory_missing'

foreach ($cidr in 0..4 | ForEach-Object { '10.246.'+$_+'.0/24' }) { Assert-True ($allV2.Contains($cidr)) ('v2_cidr_missing_'+$cidr) }
foreach ($port in @('8390','8391')) {
    Assert-True ($piwigoEnv.Contains($port) -and $immichEnv.Contains($port)) ('v2_env_port_missing_'+$port)
}
foreach ($volume in @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts','piwigo_backups','immich_upload','immich_model_cache','immich_db','immich_gateway_secret')) {
    Assert-True ($allV2.Contains('class_archive_owner_restore_v2_'+$volume)) ('v2_volume_missing_'+$volume)
}
Assert-True (([regex]::Matches($piwigo,'com\.classarchive\.scope: owner-independent-restore-v2')).Count -ge 4) 'piwigo_scope_labels_missing'
Assert-True (([regex]::Matches($immich,'com\.classarchive\.scope: owner-independent-restore-v2')).Count -ge 4) 'immich_scope_labels_missing'
Assert-True ($piwigo.Contains('source: ../.codex-work/owner-restore-v2/runtime/git-evidence/HEAD') -and
    $piwigo.Contains('source: ../.codex-work/owner-restore-v2/runtime/git-evidence/refs')) 'v2_git_evidence_mount_missing'
Assert-True ($runner.Contains('[string]$BundleInfo.restore_tool_head + "`n"') -and
    $runner.Contains('source_head=[string]$BundleInfo.manifest.source_head')) 'source_and_tool_attestation_not_separated'

Assert-True ($stream.Contains('--source-root') -and
    $stream.Contains('case "$source_root" in */ClassArchive-Independent-Recovery)') -and
    $stream.Contains('"$source_root"/bundles/owner-full-v2-') -and
    $stream.Contains('/mnt/classarchive-owner-restore-v2/volumes/') -and
    $stream.Contains('owner-independent-restore-v2') -and
    $stream.Contains('CLASSARCHIVE_V2')) 'stream_identity_invalid'
Assert-True (-not ($stream -match '(?i)dpapi|protecteddata|currentuser')) 'stream_machine_profile_dependency_forbidden'
foreach ($mode in @('verify','restore-mariadb','restore-immich-postgres','restore-piwigo-data','restore-piwigo-scripts','restore-piwigo-uploads','restore-piwigo-galleries','restore-immich-upload','write-piwigo-config')) {
    Assert-True ($stream.Contains($mode)) ('stream_mode_missing_'+$mode)
}
Assert-True ($stream.Contains('--numeric-owner --same-owner --same-permissions --acls --xattrs')) 'posix_restore_flags_missing'
Assert-True ($stream.Contains('--network none --read-only --cap-drop ALL')) 'stream_helper_sandbox_missing'

Assert-True ($adapter.Contains("ValidateSet('qa', 'full', 'restore', 'restore-v2')")) 'immich_restore_v2_adapter_missing'
Assert-True ($adapter.Contains("private_relative = `$(if (`$restoreV2) { '.codex-work/owner-restore-v2' }")) 'immich_restore_v2_private_root_missing'
Assert-True ($adapter.Contains("piwigo_project = `$(if (`$restoreV2) { 'class_archive_owner_restore_v2_piwigo' }")) 'immich_restore_v2_piwigo_project_missing'
Assert-True ($adapter.Contains("compat_port = `$(if (`$restoreV2) { 8391 }")) 'immich_restore_v2_port_missing'

$bash=@(& wsl.exe -d Ubuntu --cd $root --exec bash -n infra/scripts/restore-owner-independent-backup-v2.sh 2>&1)
Assert-True ($LASTEXITCODE -eq 0) 'stream_bash_parse_failed'

$oldSpike=$env:IMMICH_SPIKE_ENV_FILE
try {
    $env:IMMICH_SPIKE_ENV_FILE='../owner-restore-v2/immich.restore.env.example'
    $piwigoConfig=@(& wsl.exe -d Ubuntu --cd $root --exec docker compose --env-file infra/owner-restore-v2/piwigo.restore.env.example `
        -f infra/docker-compose.yml -f infra/owner-restore-v2/docker-compose.piwigo.override.yml -f infra/private-full/docker-compose.ai-worker.override.yml `
        -p class_archive_owner_restore_v2_piwigo config 2>&1)
    Assert-True ($LASTEXITCODE -eq 0) 'piwigo_compose_config_failed'
    $immichConfig=@(& wsl.exe -d Ubuntu --cd $root --exec docker compose --env-file infra/owner-restore-v2/immich.restore.env.example `
        -f infra/immich-spike/docker-compose.yml -f infra/owner-restore-v2/docker-compose.immich.override.yml `
        -p class_archive_owner_restore_v2_immich --profile immich-spike --profile immich-ml --profile immich-gateway-integration --profile immich-web-compat config 2>&1)
    Assert-True ($LASTEXITCODE -eq 0) 'immich_compose_config_failed'
}
finally { $env:IMMICH_SPIKE_ENV_FILE=$oldSpike }
$rendered=($piwigoConfig+"`n"+$immichConfig) -join "`n"
Assert-True ($rendered -match 'host_ip: 127\.0\.0\.1' -and $rendered -match 'published: "8390"' -and $rendered -match 'published: "8391"') 'rendered_loopback_ports_missing'
Assert-True (-not ($rendered -match 'host_ip: 0\.0\.0\.0|published: "2283"|published: "3000"|published: "8080"')) 'rendered_internal_service_exposed'
Assert-True ($rendered.Contains('name: class_archive_owner_restore_v2_piwigo_data') -and
    $rendered.Contains('name: class_archive_owner_restore_v2_immich_db')) 'rendered_volume_identity_missing'
Assert-True ($rendered.Contains('com.classarchive.scope: owner-independent-restore-v2')) 'rendered_scope_label_missing'

Write-Output ('OWNER_RESTORE_V2_PROTOCOL=PASS assertions='+$script:assertions+' evidence=STATIC_ONLY runtime_mutation=NONE')
