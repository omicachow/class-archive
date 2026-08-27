[CmdletBinding()]
param()

# Static lifecycle/order gate. It never opens a recovery bundle, creates an
# image, starts Compose or contacts either owner runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath=Join-Path $root 'infra\scripts\owner-independent-restore-v2.ps1'
$streamPath=Join-Path $root 'infra\scripts\restore-owner-independent-backup-v2.sh'
$script:assertions=0

function Assert-True([bool]$Condition,[string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw 'OWNER_RESTORE_V2_LIFECYCLE=FAIL code=' + $Code }
}

function Assert-InOrder([string]$Text,[string[]]$Needles,[string]$Code) {
    $previous=-1
    foreach ($needle in $Needles) {
        $index=$Text.IndexOf($needle,$previous + 1,[StringComparison]::Ordinal)
        Assert-True ($index -gt $previous) ($Code + '_' + [Math]::Abs($needle.GetHashCode()))
        $previous=$index
    }
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'runner_missing'
Assert-True (Test-Path -LiteralPath $streamPath -PathType Leaf) 'stream_missing'
$runner=[IO.File]::ReadAllText($runnerPath,[Text.Encoding]::UTF8)
$stream=[IO.File]::ReadAllText($streamPath,[Text.Encoding]::UTF8)
$tokens=$null;$errors=$null
[void][Management.Automation.Language.Parser]::ParseFile($runnerPath,[ref]$tokens,[ref]$errors)
Assert-True ($errors.Count -eq 0) 'runner_parse_failed'

$validate=[regex]::Match($runner,"(?ms)^\s*if \(\`$Action -eq 'validate'\) \{.*?^\s*\}").Value
$prepare=[regex]::Match($runner,"(?ms)^\s*if \(\`$Action -eq 'prepare-storage'\) \{.*?^\s*\}").Value
$status=[regex]::Match($runner,"(?ms)^\s*if \(\`$Action -eq 'status'\) \{.*?^\s*\}").Value
$restore=[regex]::Match($runner,"(?ms)^\s*if \(\`$Action -eq 'restore'\) \{.*?(?=^\s*if \(\`$Action -eq 'cold-restart'\))").Value
$cold=[regex]::Match($runner,"(?ms)^\s*if \(\`$Action -eq 'cold-restart'\) \{.*?^\s*\}").Value
foreach ($block in @($validate,$prepare,$status,$restore,$cold)) { Assert-True (-not [string]::IsNullOrWhiteSpace($block)) 'action_block_missing' }

foreach ($needle in @('Mount-RestoreStorage','New-RestoreVolume','Initialize-RestoreEnvironments','Read-PortableRecoverySecrets','Invoke-RestoreCompose')) {
    Assert-True (-not $validate.Contains($needle)) ('validate_mutation_' + $needle.ToLowerInvariant())
}
Assert-InOrder $validate @('Assert-PortsFree','Assert-FreshRestoreRuntime','Assert-RestoreNetworkRangesFree','OWNER_RESTORE_V2_VALIDATE=PASS') 'validate_preflight_order'
Assert-InOrder $prepare @('Assert-PortsFree','Assert-FreshRestoreRuntime','Assert-RestoreNetworkRangesFree','Mount-RestoreStorage $true','Copy-PinnedImages','protected_runtime_changed','OWNER_RESTORE_V2_STORAGE=PASS') 'prepare_order'
foreach ($needle in @('New-RestoreVolume','Invoke-RestoreCompose','Read-PortableRecoverySecrets')) {
    Assert-True (-not $prepare.Contains($needle)) ('prepare_scope_expansion_' + $needle.ToLowerInvariant())
}
foreach ($needle in @('New-RestoreVolume','Invoke-StreamHelper','Initialize-RestoreEnvironments','Read-PortableRecoverySecrets')) {
    Assert-True (-not $status.Contains($needle)) ('status_mutation_' + $needle.ToLowerInvariant())
}

Assert-InOrder $runner @(
    '$bundleInfo=Read-VerifiedBundle','Assert-HostCapabilities',
    '$workflowLock=Enter-WorkflowLock','$protectedBefore=Get-ProtectedRuntimeFingerprint'
) 'global_pre_mutation_order'
Assert-InOrder $restore @(
    'restore_confirmation_required','Assert-PortsFree','Assert-FreshRestoreRuntime','Assert-RestoreNetworkRangesFree',
    'Read-PortableRecoverySecrets','Initialize-RestoreEnvironments','Initialize-RestoreGitEvidence',
    'Write-OwnerOnlyText $archivePassphrasePath','New-RestoreVolume',
    "Invoke-RestoreCompose piwigo @('up','-d','db')","Invoke-RestoreCompose immich @('--profile','immich-spike','up','-d','database','redis')",
    'Invoke-StreamHelper verify','restore-piwigo-data','restore-mariadb','restore-immich-postgres',
    'Copy-VerifiedModelCache','Set-RestoreMaintenanceMarker',"Invoke-RestoreCompose piwigo @('up','-d','piwigo')",
    'prepare-class-archive-maintenance.php','Assert-MaintenanceHttp','rebuild-photo-read-projection.php',
    'Invoke-PrivateImmichFinish','Invoke-PreReleaseVerify','--finalize-maintenance','Assert-RestoreMediaGuard',
    "Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat')",
    'Write-RestoreState','Invoke-AggregateVerify','protected_runtime_changed'
) 'restore_release_order'
Assert-True ($restore.Contains('Remove-PrivateTemporaryFile $archivePassphrasePath')) 'restore_passphrase_finally_missing'
Assert-True ($runner.Contains('if ($null -ne $workflowLock) { $workflowLock.Dispose() }')) 'workflow_lock_finally_missing'

Assert-InOrder $cold @(
    'cold_restart_confirmation_required','Read-RestoreState','Get-RestoreCounts','prepare-class-archive-maintenance.php','Assert-MaintenanceHttp',
    "'stop','-t','30'","Invoke-RestoreCompose piwigo @('stop','-t','30')",
    "Invoke-RestoreCompose piwigo @('up','-d','db','piwigo')",'immich-gateway',
    'Assert-MaintenanceHttp','Invoke-PreReleaseVerify','--finalize-maintenance','Assert-RestoreMediaGuard',
    "Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat')",
    'Invoke-AggregateVerify','reindex=NO'
) 'cold_restart_release_order'
Assert-True (-not $cold.Contains('Invoke-PrivateImmichFinish')) 'cold_restart_reindex_path_detected'

foreach ($needle in @('Reassert-MaintenanceAfterFailure','prepare-class-archive-maintenance.php','Assert-MaintenanceHttp','maintenance=FAIL_CLOSED')) {
    Assert-True ($runner.Contains($needle)) ('fail_closed_contract_missing_' + [Math]::Abs($needle.GetHashCode()))
}
foreach ($forbidden in @("'down'",'docker volume rm','docker volume prune','docker system prune','Remove-Item -LiteralPath $runtimeImage','Remove-Item -LiteralPath $targetRoot')) {
    Assert-True (-not $runner.Contains($forbidden)) ('destructive_runner_action_' + [Math]::Abs($forbidden.GetHashCode()))
}
foreach ($forbidden in @('docker volume rm','docker volume prune','docker system prune','CurrentUser','ProtectedData')) {
    Assert-True (-not $stream.Contains($forbidden)) ('destructive_or_machine_profile_stream_action_' + [Math]::Abs($forbidden.GetHashCode()))
}
Assert-True (([regex]::Matches($stream,[regex]::Escape('rm -rf -- "$gpg_home"'))).Count -eq 1) 'stream_cleanup_not_limited_to_gpg_temp'
Assert-True ($runner.Contains('Read-ClassArchivePortableRecoveryPhrase') -and $runner.Contains('Read-ClassArchivePortableRecoveryEnvelope')) 'portable_only_secret_path_missing'
Assert-True ($runner.Contains('[string]$BundleInfo.restore_tool_head + "`n"') -and $runner.Contains('source_head=[string]$BundleInfo.manifest.source_head')) 'tool_and_source_attestation_mixed'

Write-Output ('OWNER_RESTORE_V2_LIFECYCLE=PASS assertions='+$script:assertions+' evidence=STATIC_ONLY runtime_mutation=NONE')
