[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'retire')]
    [string]$Action = 'validate',

    [switch]$ConfirmRetirement
)

# Release only the superseded private 400-photo QA runtime after an approved
# full-library cutover. This command is intentionally unable to address the
# synthetic baseline, a full-library volume, an arbitrary Docker name, or an
# original-source directory. `retire` is explicit and requires a local approval
# record plus a second command-line acknowledgement.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateQaRoot = Join-Path $projectRoot '.codex-work\private-real-qa'
$stagingRoot = Join-Path $privateQaRoot 'staging'
$approvalPath = Join-Path $projectRoot '.codex-work\private-real-full\reports\cutover-approval.json'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$legacyScope = 'private-real-qa'
$legacyVolumes = @(
    'class_archive_private_qa_piwigo_data',
    'class_archive_private_qa_piwigo_uploads',
    'class_archive_private_qa_piwigo_galleries',
    'class_archive_private_qa_piwigo_derivatives',
    'class_archive_private_qa_piwigo_db',
    'class_archive_private_qa_piwigo_scripts',
    'class_archive_private_qa_piwigo_backups',
    'class_archive_private_qa_immich_upload',
    'class_archive_private_qa_immich_model_cache',
    'class_archive_private_qa_immich_db',
    'class_archive_private_qa_immich_gateway_secret'
)

function Stop-Retirement([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_QA_RETIRE_STOP:' + $Code)
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-Retirement 'path_outside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredLocalFile([string]$Path, [string]$Code) {
    try { $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop } catch { Stop-Retirement ($Code + '_missing') }
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-Retirement ($Code + '_untrusted')
    }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-Retirement ($Code + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-Retirement ($Code + '_tracked') }
    return $item.FullName
}

function Assert-FullOwnerRuntime {
    $candidate = @(& $wsl -d Ubuntu --exec docker ps --filter 'label=com.classarchive.scope=private-real-full' --format '{{.Names}}|{{.State}}' 2>&1)
    if ($LASTEXITCODE -ne 0) { Stop-Retirement 'owner_runtime_inspect_failed' }
    $running = @($candidate | Where-Object { $_ -match '^class_archive_private_full_v3_(?:piwigo|immich)-[a-z0-9-]+-\d+\|running$' })
    if ($running.Count -lt 3) { Stop-Retirement 'full_owner_runtime_not_running' }
    $piwigo = @($running | Where-Object { $_ -match '^class_archive_private_full_v3_piwigo-piwigo-\d+\|running$' })
    if ($piwigo.Count -ne 1) { Stop-Retirement 'full_owner_piwigo_identity_invalid' }
    $piwigoName = ([string]$piwigo[0] -split '\|', 2)[0]
    $portJson = @(& $wsl -d Ubuntu --exec docker inspect --format '{{json .NetworkSettings.Ports}}' $piwigoName 2>&1)
    if ($LASTEXITCODE -ne 0 -or $portJson.Count -ne 1) { Stop-Retirement 'full_owner_port_inspect_failed' }
    try { $ports = ([string]$portJson[0] | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-Retirement 'full_owner_port_inspect_failed' }
    $ownerCore = @($ports.'80/tcp' | Where-Object { $_.HostIp -eq '127.0.0.1' -and $_.HostPort -eq '8190' })
    $ownerCompat = @($ports.'8081/tcp' | Where-Object { $_.HostIp -eq '127.0.0.1' -and $_.HostPort -eq '8191' })
    if ($ownerCore.Count -ne 1 -or $ownerCompat.Count -ne 1) { Stop-Retirement 'full_owner_port_binding_invalid' }
    try {
        $core = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8190/identification.php' -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
        if ($core.StatusCode -notin @(200, 302)) { Stop-Retirement 'full_owner_core_not_ready' }
        $compat = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8191/healthz' -TimeoutSec 15 -ErrorAction Stop
        if ($compat.StatusCode -ne 200 -or $compat.Content.Trim() -ne 'ok') { Stop-Retirement 'full_owner_compat_not_ready' }
    }
    catch {
        if ($_.Exception.Message -match '^PRIVATE_QA_RETIRE_STOP:') { throw }
        Stop-Retirement 'full_owner_http_probe_failed'
    }
}

function Assert-Approval {
    $full = Assert-IgnoredLocalFile $approvalPath 'approval'
    try { $approval = Get-Content -LiteralPath $full -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-Retirement 'approval_invalid' }
    if (
        [string]$approval.version -ne '1' -or
        [string]$approval.full_real_import -ne 'PASS' -or
        [string]$approval.full_real_browser_e2e -ne 'PASS' -or
        [string]$approval.source_full_integrity -ne 'PASS' -or
        [string]$approval.full_real_owner_ready -ne 'YES' -or
        [string]$approval.file_mode_policy -ne 'PASS' -or
        [string]$approval.old_sample_qa_retirement -ne 'APPROVED'
    ) {
        Stop-Retirement 'approval_gates_missing'
    }
}

function Assert-LegacyVolume([string]$Volume) {
    if ($Volume -notmatch '^class_archive_private_qa_(?:piwigo|immich)_[a-z0-9_]+$') {
        Stop-Retirement 'legacy_volume_name_invalid'
    }
    $record = @(& $wsl -d Ubuntu --exec docker volume inspect --format '{{.Name}}|{{index .Labels "com.classarchive.scope"}}' $Volume 2>&1)
    if ($LASTEXITCODE -ne 0 -or $record.Count -ne 1 -or $record[0] -ne ($Volume + '|' + $legacyScope)) {
        Stop-Retirement 'legacy_volume_identity_invalid'
    }
}

function Get-LegacyContainers {
    $records = @(& $wsl -d Ubuntu --exec docker ps -a --filter ('label=com.classarchive.scope=' + $legacyScope) --format '{{.Names}}|{{.State}}|{{.Label "com.classarchive.scope"}}' 2>&1)
    if ($LASTEXITCODE -ne 0) { Stop-Retirement 'legacy_container_inspect_failed' }
    $names = @()
    foreach ($record in $records) {
        if ([string]::IsNullOrWhiteSpace($record)) { continue }
        $parts = [string]$record -split '\|', 3
        if ($parts.Count -ne 3 -or $parts[2] -ne $legacyScope -or $parts[0] -notmatch '^class_archive_private_qa_(?:piwigo|immich)-[a-z0-9-]+-\d+$') {
            Stop-Retirement 'legacy_container_identity_invalid'
        }
        if ($parts[1] -eq 'running') { Stop-Retirement 'legacy_container_still_running' }
        $names += $parts[0]
    }
    return @($names | Sort-Object -Unique)
}

function Assert-PrivateStagingRoot {
    if (-not (Test-Path -LiteralPath $stagingRoot)) { return $null }
    $item = Get-Item -LiteralPath $stagingRoot -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-Retirement 'legacy_staging_untrusted'
    }
    $expected = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\staging')).TrimEnd('\', '/')
    $actual = [IO.Path]::GetFullPath($item.FullName).TrimEnd('\', '/')
    if (-not [string]::Equals($actual, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-Retirement 'legacy_staging_path_invalid'
    }
    $relative = Get-ProjectRelativePath $actual
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-Retirement 'legacy_staging_not_ignored' }
    $reparse = @(Get-ChildItem -LiteralPath $actual -Force -Recurse -ErrorAction Stop | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
    if ($reparse.Count -ne 0) { Stop-Retirement 'legacy_staging_contains_reparse_point' }
    return $actual
}

try {
    Assert-FullOwnerRuntime
    Assert-Approval
    foreach ($volume in $legacyVolumes) { Assert-LegacyVolume $volume }
    $containers = Get-LegacyContainers
    $staging = Assert-PrivateStagingRoot
    if ($Action -eq 'validate') {
        $stagingState = if ($null -eq $staging) { 'absent' } else { 'verified' }
        Write-Output ('PRIVATE_QA_RETIRE=PASS action=validate containers=' + $containers.Count + ' volumes=' + $legacyVolumes.Count + ' staging=' + $stagingState + ' scope=' + $legacyScope)
        exit 0
    }
    if (-not $ConfirmRetirement) { Stop-Retirement 'retire_requires_confirm_retirement' }
    foreach ($container in $containers) {
        & $wsl -d Ubuntu --exec docker rm $container 1>$null
        if ($LASTEXITCODE -ne 0) { Stop-Retirement 'legacy_container_remove_failed' }
    }
    foreach ($volume in $legacyVolumes) {
        Assert-LegacyVolume $volume
        & $wsl -d Ubuntu --exec docker volume rm $volume 1>$null
        if ($LASTEXITCODE -ne 0) { Stop-Retirement 'legacy_volume_remove_failed' }
    }
    if ($null -ne $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction Stop
    }
    Write-Output ('PRIVATE_QA_RETIRE=PASS action=retire containers=' + $containers.Count + ' volumes=' + $legacyVolumes.Count + ' staging_removed=' + ($null -ne $staging) + ' scope=' + $legacyScope)
    exit 0
}
catch {
    $failureCode = $null
    if ($_.Exception.Message -match '^PRIVATE_QA_RETIRE_STOP:([a-z0-9_]{1,128})$') { $failureCode = [string]$Matches[1] }
    if ($null -ne $failureCode) {
        Write-Output ('PRIVATE_QA_RETIRE=FAIL action=' + $Action + ' code=' + $failureCode)
        exit 2
    }
    $type = $_.Exception.GetType().Name
    if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
    Write-Output ('PRIVATE_QA_RETIRE=FAIL action=' + $Action + ' code=unexpected_' + $type)
    exit 2
}
