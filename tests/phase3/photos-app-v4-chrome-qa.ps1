[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$CredentialFile
)

# V4 synthetic-only Chrome Stable acceptance wrapper.  It never provisions
# identities, starts containers, or rotates credentials; prepare the ignored
# fixture separately with private-browser-fixture.ps1 -Environment synthetic.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
if (-not $credentialPath.StartsWith($workRoot + $separator, [StringComparison]::OrdinalIgnoreCase)) { throw 'v4_chrome_credential_outside_work_root' }
$credentialRelative = $credentialPath.Substring($projectRoot.Length + 1).Replace('\', '/')
& git -C $projectRoot check-ignore --quiet --no-index -- $credentialRelative
if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $credentialRelative).Count -ne 0) { throw 'v4_chrome_credential_not_private' }
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$deps = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = Join-Path $deps 'node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_chrome_node_unavailable' }

$bytes = New-Object byte[] 8
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
$run = (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser\photos-app-v4-chrome'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\screenshots\photos-app-v4-chrome'))
$userDataRoot = [IO.Path]::GetFullPath((Join-Path $profileRoot $run))
$screenshotDir = [IO.Path]::GetFullPath((Join-Path $screenshotRoot $run))
foreach ($path in @($userDataRoot, $screenshotDir)) {
    $base = if ($path -eq $userDataRoot) { $profileRoot } else { $screenshotRoot }
    if (-not $path.StartsWith($base + $separator, [StringComparison]::OrdinalIgnoreCase)) { throw 'v4_chrome_private_path_invalid' }
    if (Test-Path -LiteralPath $path) { throw 'v4_chrome_run_path_not_fresh' }
    [void][IO.Directory]::CreateDirectory($path)
    $relative = $path.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw 'v4_chrome_output_not_private' }
}

$old = @{}
$names = @('NODE_PATH','CLASS_ARCHIVE_V4_CREDENTIAL_FILE','CLASS_ARCHIVE_V4_PIWIGO_ORIGIN','CLASS_ARCHIVE_V4_PHOTO_ORIGIN','CLASS_ARCHIVE_V4_USER_DATA_ROOT','CLASS_ARCHIVE_V4_SCREENSHOT_DIR')
foreach ($name in $names) { $item = Get-Item "Env:$name" -ErrorAction SilentlyContinue; $old[$name] = if ($null -eq $item) { $null } else { $item.Value } }
$result = $null
$phaseAMutationLease = $null
try {
    # Read-only evidence still takes the shared lease so it cannot accidentally
    # observe another runner's short-lived synthetic fixture or rollback.
    $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'chrome-main'
    $env:NODE_PATH = Join-Path $deps 'node\node_modules'
    $env:CLASS_ARCHIVE_V4_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'
    $env:CLASS_ARCHIVE_V4_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'
    $env:CLASS_ARCHIVE_V4_USER_DATA_ROOT = $userDataRoot
    $env:CLASS_ARCHIVE_V4_SCREENSHOT_DIR = $screenshotDir
    $output = @(& $node (Join-Path $PSScriptRoot 'photos-app-v4-chrome-qa.mjs') 2>&1)
    $code = $LASTEXITCODE
    # Browser errors can contain DOM URLs or returned identifiers. Only relay
    # the runner's deliberately bounded gate codes/stages to stdout.
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_CHROME_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$' -or
        $_ -match '^V4_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_CHROME_QA=PASS\b' })
    if ($code -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_CHROME_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        throw 'v4_chrome_qa_failed'
    }
    $result = [string]$pass[0]
} finally {
    try {
        foreach ($name in $names) { Remove-Item "Env:$name" -ErrorAction SilentlyContinue; if ($null -ne $old[$name]) { Set-Item "Env:$name" -Value $old[$name] } }
        # Browser user data is deliberately ephemeral; screenshots remain as ignored evidence.
        if (Test-Path -LiteralPath $userDataRoot) {
            $resolved = (Resolve-Path -LiteralPath $userDataRoot).Path
            $item = Get-Item -LiteralPath $userDataRoot -Force
            if (-not $resolved.StartsWith($profileRoot + $separator, [StringComparison]::OrdinalIgnoreCase) -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'v4_chrome_profile_cleanup_boundary' }
            Remove-Item -LiteralPath $userDataRoot -Recurse -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $userDataRoot) { throw 'v4_chrome_profile_cleanup_failed' }
        }
    }
    finally {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
}
if ([string]::IsNullOrWhiteSpace($result)) { throw 'v4_chrome_qa_result_missing' }
Write-Output $result
Write-Output ('V4_CHROME_SCREENSHOTS=' + $screenshotDir)
# This must remain the terminal runner record: it proves the success result
# survived finally cleanup of the dedicated Chrome profile.
Write-Output 'V4_CHROME_QA_COMPLETE=PASS'
