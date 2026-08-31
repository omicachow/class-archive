[CmdletBinding()]
param(
    # Guest acceptance has no identity mutation or credential lease.  The
    # explicit switch exists solely to prevent an accidental headed Chrome
    # launch against the private Owner runtime.
    [switch]$ConfirmGuestReadOnlyAcceptance,

    # The caller creates this owner-only ignored document from an already
    # authorized local media projection.  This wrapper never reads its URLs.
    [string]$MediaProbeDocument
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

if (-not $ConfirmGuestReadOnlyAcceptance) {
    Write-Output 'V4_OWNER_GUEST_CHROME_QA=BLOCKED code=explicit_guest_read_only_confirmation_required'
    exit 3
}
if ([string]::IsNullOrWhiteSpace($MediaProbeDocument)) {
    Write-Output 'V4_OWNER_GUEST_CHROME_QA=BLOCKED code=opaque_media_probe_document_required'
    exit 3
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-guest-browser-qa.mjs'
$browserRoot = Join-Path $projectRoot '.codex-work\private-real-qa\browser\photos-app-v4-owner-guest'
$probeRoot = Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner-guest\opaque-media-probes'
$coreOrigin = 'http://127.0.0.1:8190/'
$photoOrigin = 'http://127.0.0.1:8191/'
$browserTimeoutSeconds = 180
$assertions = 0
$resultCode = 'unexpected_wrapper_failure'
$success = $false
$executionRoot = $null

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerGuest([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_GUEST_STOP:' + $Code)
}

function Assert-V4OwnerGuest([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-V4OwnerGuest $Code }
}

function New-V4OwnerGuestRunId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-V4OwnerGuestNodePath {
    $candidate = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { Stop-V4OwnerGuest 'node_unavailable' }
    return $candidate
}

function Get-V4OwnerGuestNodeModulesPath {
    $candidate = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $candidate -PathType Container)) { Stop-V4OwnerGuest 'node_modules_unavailable' }
    return $candidate
}

function Assert-V4OwnerGuestNoReparseAncestor([string]$Candidate, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $boundary = $projectRoot.TrimEnd('\', '/')
    $cursor = $full
    while (-not [string]::IsNullOrWhiteSpace($cursor) -and -not (Test-Path -LiteralPath $cursor)) {
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
    while (-not [string]::IsNullOrWhiteSpace($cursor)) {
        $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-V4OwnerGuest ($Code + '_reparse_ancestor') }
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $boundary, [StringComparison]::OrdinalIgnoreCase)) { return }
        $parent = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
    Stop-V4OwnerGuest ($Code + '_ancestor_outside_project')
}

function Assert-V4OwnerGuestIgnored([string]$Candidate, [string]$Root, [string]$Code, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/')
    $rootBoundary = $rootFull + [IO.Path]::DirectorySeparatorChar
    Assert-V4OwnerGuest ($full.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) ($Code + '_outside_project')
    Assert-V4OwnerGuest (([string]::Equals($full.TrimEnd('\', '/'), $rootFull, [StringComparison]::OrdinalIgnoreCase)) -or $full.StartsWith($rootBoundary, [StringComparison]::OrdinalIgnoreCase)) ($Code + '_outside_root')
    if ($MustExist) { Assert-V4OwnerGuest (Test-Path -LiteralPath $full) ($Code + '_missing') }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-V4OwnerGuest ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    Assert-V4OwnerGuest (@(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) ($Code + '_tracked')
    Assert-V4OwnerGuestNoReparseAncestor -Candidate $full -Code $Code
    return $full
}

function New-V4OwnerGuestPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    Assert-V4OwnerGuestIgnored -Candidate ([IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($Path))) -Root $Root -Code ($Code + '_parent') | Out-Null
    Assert-V4OwnerGuest (-not (Test-Path -LiteralPath $Path)) ($Code + '_exists')
    $created = New-Item -ItemType Directory -Path $Path -ErrorAction Stop
    $item = Get-Item -LiteralPath $created.FullName -Force -ErrorAction Stop
    Assert-V4OwnerGuest ($item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_untrusted')
    Assert-V4OwnerGuestIgnored -Candidate $item.FullName -Root $Root -Code $Code | Out-Null
    return $item.FullName
}

function Remove-V4OwnerGuestPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-V4OwnerGuest ($item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_untrusted')
    $full = Assert-V4OwnerGuestIgnored -Candidate $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object {
        $_.Attributes -band [IO.FileAttributes]::ReparsePoint
    })
    Assert-V4OwnerGuest ($reparse.Count -eq 0) ($Code + '_contains_reparse')
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

function Resolve-V4OwnerGuestProbeDocument([string]$Value) {
    $resolved = (Resolve-Path -LiteralPath $Value -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    Assert-V4OwnerGuest (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'media_probe_document_untrusted'
    Assert-V4OwnerGuestIgnored -Candidate $item.FullName -Root $probeRoot -Code 'media_probe_document' | Out-Null
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
    Assert-V4OwnerGuest ($item.Length -gt 0 -and $item.Length -le 4096) 'media_probe_document_size_invalid'
    return $item.FullName
}

try {
    Assert-V4OwnerGuest (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'guest_runner_missing'
    Assert-V4OwnerGuest ($coreOrigin -eq 'http://127.0.0.1:8190/' -and $photoOrigin -eq 'http://127.0.0.1:8191/') 'loopback_origins_invalid'
    $probePath = Resolve-V4OwnerGuestProbeDocument -Value $MediaProbeDocument
    $browserParent = [IO.Path]::GetDirectoryName($browserRoot)
    if (-not (Test-Path -LiteralPath $browserParent -PathType Container)) { Stop-V4OwnerGuest 'browser_parent_missing' }
    if (-not (Test-Path -LiteralPath $browserRoot -PathType Container)) {
        New-V4OwnerGuestPrivateDirectory -Path $browserRoot -Root $browserParent -Code 'browser_root' | Out-Null
    }
    Assert-V4OwnerGuestIgnored -Candidate $browserRoot -Root $browserParent -Code 'browser_root' | Out-Null
    $executionRoot = New-V4OwnerGuestPrivateDirectory -Path (Join-Path $browserRoot (New-V4OwnerGuestRunId)) -Root $browserRoot -Code 'browser_execution_root'
    $profileRoot = New-V4OwnerGuestPrivateDirectory -Path (Join-Path $executionRoot 'profile-root') -Root $browserRoot -Code 'browser_profile_root'

    $node = Get-V4OwnerGuestNodePath
    $nodeModules = Get-V4OwnerGuestNodeModulesPath
    $environment = @{
        'NODE_PATH' = $nodeModules
        'CLASS_ARCHIVE_V4_OWNER_GUEST_CORE_ORIGIN' = $coreOrigin
        'CLASS_ARCHIVE_V4_OWNER_GUEST_PHOTO_ORIGIN' = $photoOrigin
        'CLASS_ARCHIVE_V4_OWNER_GUEST_PROFILE_ROOT' = $profileRoot
        'CLASS_ARCHIVE_V4_OWNER_GUEST_MEDIA_PROBE_DOCUMENT' = $probePath
    }
    $previous = @{}
    foreach ($name in $environment.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $environment[$name], 'Process')
    }
    try {
        $native = Invoke-ClassArchiveBoundedNative -Executable $node -Arguments @($runnerPath) -TimeoutSeconds $browserTimeoutSeconds -WorkingDirectory $projectRoot
    }
    finally {
        foreach ($name in $environment.Keys) {
            [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
        }
    }
    Assert-V4OwnerGuest (-not $native.TimedOut -and $native.ExitCode -in @(0, 1)) 'guest_runner_execution_invalid'
    $safe = @($native.Stdout -split "`r?`n" | Where-Object {
        $_ -match '^V4_OWNER_GUEST_CHROME_QA=(?:PASS assertions=[1-9][0-9]*|FAIL code=[a-z0-9_]{1,100})$'
    })
    Assert-V4OwnerGuest ($safe.Count -eq 1) 'guest_runner_output_invalid'
    if ($native.ExitCode -eq 0) {
        Assert-V4OwnerGuest ($safe[0] -match '^V4_OWNER_GUEST_CHROME_QA=PASS assertions=[1-9][0-9]*$') 'guest_runner_pass_invalid'
        $success = $true
    } else {
        Assert-V4OwnerGuest ($safe[0] -match '^V4_OWNER_GUEST_CHROME_QA=FAIL code=([a-z0-9_]{1,100})$') 'guest_runner_failure_invalid'
        $resultCode = [string]$Matches[1]
    }
}
catch {
    $resultCode = if ($_.Exception.Message -match '^V4_OWNER_GUEST_STOP:([a-z0-9_]{1,100})$') {
        [string]$Matches[1]
    } else {
        'unexpected_wrapper_failure'
    }
    $success = $false
}
finally {
    $cleanupFailed = $false
    if ($null -ne $executionRoot) {
        try { Remove-V4OwnerGuestPrivateDirectory -Path $executionRoot -Root $browserRoot -Code 'browser_execution_cleanup' }
        catch { $cleanupFailed = $true }
    }
    if ($cleanupFailed) {
        $success = $false
        if ($resultCode -eq 'unexpected_wrapper_failure') { $resultCode = 'browser_profile_cleanup_failed' }
    }
}

if ($success) {
    Write-Output 'V4_OWNER_GUEST_CHROME_QA=PASS'
    exit 0
}
Write-Output ('V4_OWNER_GUEST_CHROME_QA=FAIL code=' + $resultCode)
exit 2
