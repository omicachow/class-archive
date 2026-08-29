[CmdletBinding()]
param()

# Synthetic-only Chrome Stable performance acceptance. This wrapper owns the
# complete fixture lifecycle: a shared mutation lease surrounds credential
# preparation, browser execution, password rotation and credential deletion.
# It never starts/stops containers and never addresses Owner/private runtimes.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
$fixtureRunner = Join-Path $PSScriptRoot 'private-browser-fixture.ps1'
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function Assert-PrivateIgnoredPath([string]$Path, [string]$Base, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $relativeToBase = Get-V4SyntheticPhaseARelativePath -Base $Base -Target $full
    if ([string]::IsNullOrWhiteSpace($relativeToBase) -or $relativeToBase -eq '..' -or $relativeToBase.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or [IO.Path]::IsPathRooted($relativeToBase)) {
        throw $Code
    }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw $Code }
    return $full
}

function Invoke-SyntheticFixture([ValidateSet('prepare', 'rotate')][string]$Action, [string]$CredentialFile = '') {
    $arguments = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $fixtureRunner, '-Environment', 'synthetic', '-Action', $Action)
    if ($Action -eq 'rotate') { $arguments += @('-CredentialFile', $CredentialFile) }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& powershell.exe @arguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { throw ('v4_chrome_performance_fixture_' + $Action + '_failed') }
    $lines = @($output | ForEach-Object { [string]$_ })
    if ($Action -eq 'rotate') {
        if (@($lines | Where-Object { $_ -eq 'SYNTHETIC_BROWSER_FIXTURE=PASS action=rotate' }).Count -ne 1) {
            throw 'v4_chrome_performance_fixture_rotate_result_invalid'
        }
        return $null
    }
    $matches = @($lines | Where-Object { $_ -match '^SYNTHETIC_BROWSER_FIXTURE=PASS action=prepare credential=(.+) scope_truth=(.+)$' })
    if ($matches.Count -ne 1) { throw 'v4_chrome_performance_fixture_prepare_result_invalid' }
    $match = [regex]::Match($matches[0], '^SYNTHETIC_BROWSER_FIXTURE=PASS action=prepare credential=(.+) scope_truth=(.+)$')
    if (-not $match.Success) { throw 'v4_chrome_performance_fixture_prepare_parse_invalid' }
    return $match.Groups[1].Value
}

$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$deps = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = Join-Path $deps 'node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_chrome_performance_node_unavailable' }

$randomBytes = New-Object byte[] 8
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($randomBytes) } finally { $rng.Dispose() }
$run = (($randomBytes | ForEach-Object { $_.ToString('x2') }) -join '')
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser\photos-app-v4-chrome-performance'))
$evidenceRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\evidence\photos-app-v4-chrome-performance'))
$profilePath = Assert-PrivateIgnoredPath (Join-Path $profileRoot $run) $profileRoot 'v4_chrome_performance_profile_boundary'
$evidenceDirectory = Assert-PrivateIgnoredPath (Join-Path $evidenceRoot $run) $evidenceRoot 'v4_chrome_performance_evidence_boundary'
$evidencePath = Join-Path $evidenceDirectory 'performance.json'
if ((Test-Path -LiteralPath $profilePath) -or (Test-Path -LiteralPath $evidenceDirectory)) { throw 'v4_chrome_performance_run_path_not_fresh' }
[void][IO.Directory]::CreateDirectory($evidenceDirectory)

$environmentNames = @(
    'NODE_PATH',
    'CLASS_ARCHIVE_V4_PERF_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_V4_PERF_PIWIGO_ORIGIN',
    'CLASS_ARCHIVE_V4_PERF_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_V4_PERF_USER_DATA_ROOT',
    'CLASS_ARCHIVE_V4_PERF_EVIDENCE_FILE'
)
$oldEnvironment = @{}
foreach ($name in $environmentNames) {
    $item = Get-Item "Env:$name" -ErrorAction SilentlyContinue
    $oldEnvironment[$name] = if ($null -eq $item) { $null } else { $item.Value }
}

$lease = $null
$credentialPath = $null
$cleanupFailure = $null
$safeOutput = @()
$runPassed = $false
try {
    $lease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'chrome-performance'
    $credentialPath = Invoke-SyntheticFixture -Action prepare
    $credentialPath = (Resolve-Path -LiteralPath $credentialPath).Path
    [void](Assert-PrivateIgnoredPath $credentialPath $workRoot 'v4_chrome_performance_credential_boundary')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

    $env:NODE_PATH = Join-Path $deps 'node\node_modules'
    $env:CLASS_ARCHIVE_V4_PERF_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_PERF_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'
    $env:CLASS_ARCHIVE_V4_PERF_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'
    $env:CLASS_ARCHIVE_V4_PERF_USER_DATA_ROOT = $profilePath
    $env:CLASS_ARCHIVE_V4_PERF_EVIDENCE_FILE = $evidencePath

    $output = @(& $node (Join-Path $PSScriptRoot 'photos-app-v4-chrome-performance.mjs') 2>&1)
    $code = $LASTEXITCODE
    $safeOutput = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_CHROME_PERFORMANCE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_CHROME_PERFORMANCE=PASS samples=7 warmups=2 channel=chrome chrome_version=[0-9.]+$' -or
        $_ -match '^V4_CHROME_PERFORMANCE=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$' -or
        $_ -match '^(SEARCH_OVERLAY_OPEN_P50_MS|SEARCH_SUGGESTIONS_VISIBLE_P50_MS|STRUCTURED_SEARCH_P50_MS|COLLECTIONS_HOME_WARM_P50_MS)=[0-9]+$'
    })
    if ($code -ne 0 -or @($safeOutput | Where-Object { $_ -match '^V4_CHROME_PERFORMANCE=PASS\b' }).Count -ne 1) {
        $safeOutput | Where-Object { $_ -match '^(SEARCH_OVERLAY_OPEN_P50_MS|SEARCH_SUGGESTIONS_VISIBLE_P50_MS|STRUCTURED_SEARCH_P50_MS|COLLECTIONS_HOME_WARM_P50_MS)=[0-9]+$' } | Write-Output
        $failure = @($safeOutput | Where-Object { $_ -match '^V4_CHROME_PERFORMANCE=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
    }
    else {
        foreach ($metric in @('SEARCH_OVERLAY_OPEN_P50_MS','SEARCH_SUGGESTIONS_VISIBLE_P50_MS','STRUCTURED_SEARCH_P50_MS','COLLECTIONS_HOME_WARM_P50_MS')) {
            if (@($safeOutput | Where-Object { $_ -match ('^' + $metric + '=[0-9]+$') }).Count -ne 1) {
                throw 'v4_chrome_performance_metric_cardinality'
            }
        }
        if (-not (Test-Path -LiteralPath $evidencePath -PathType Leaf)) { throw 'v4_chrome_performance_evidence_missing' }
        $runPassed = $true
    }
}
finally {
    foreach ($name in $environmentNames) {
        Remove-Item "Env:$name" -ErrorAction SilentlyContinue
        if ($null -ne $oldEnvironment[$name]) { Set-Item "Env:$name" -Value $oldEnvironment[$name] }
    }
    if ($null -ne $credentialPath -and (Test-Path -LiteralPath $credentialPath -PathType Leaf)) {
        try { [void](Invoke-SyntheticFixture -Action rotate -CredentialFile $credentialPath) }
        catch { $cleanupFailure = $_ }
    }
    try {
        if (Test-Path -LiteralPath $profilePath) {
            $resolved = (Resolve-Path -LiteralPath $profilePath).Path
            $item = Get-Item -LiteralPath $profilePath -Force
            if (-not $resolved.StartsWith($profileRoot + $separator, [StringComparison]::OrdinalIgnoreCase) -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
                throw 'v4_chrome_performance_profile_cleanup_boundary'
            }
            Remove-Item -LiteralPath $profilePath -Recurse -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $profilePath) { throw 'v4_chrome_performance_profile_cleanup_failed' }
        }
    }
    catch { if ($null -eq $cleanupFailure) { $cleanupFailure = $_ } }
    try { if ($null -ne $lease) { Exit-V4SyntheticPhaseAMutationLease -Lease $lease } }
    catch { if ($null -eq $cleanupFailure) { $cleanupFailure = $_ } }
    if ($null -ne $cleanupFailure) { throw 'v4_chrome_performance_cleanup_failed' }
}

if (-not $runPassed) { exit 1 }
$safeOutput | Write-Output
Write-Output 'V4_CHROME_PERFORMANCE_COMPLETE=PASS credential=ROTATED profile=REMOVED evidence=IGNORED'
