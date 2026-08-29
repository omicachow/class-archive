[CmdletBinding()]
param(
    # Provisioning is deliberately opt-in: this runner creates short-lived,
    # run-scoped identities through the normal browser claim/invite journeys.
    # It freezes those identities in finally, but an accidental invocation must
    # not mutate the owner library.
    [switch]$ProvisionTemporaryRoles
)

# Owner-private Photos App V4 Chrome Stable acceptance wrapper.  This wrapper
# never starts, stops, rebuilds, migrates, or snapshots Docker.  It mints one
# bounded SYSTEM_ADMIN browser lease, hands it to the Node runner through an
# ignored owner-only file, and revokes it even if Chrome fails.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$separator = [IO.Path]::DirectorySeparatorChar
$coreOrigin = 'http://127.0.0.1:8190/'
$photoOrigin = 'http://127.0.0.1:8191/'
$envRelative = 'infra/private-full/.env.piwigo.owner'
$composeProject = 'class_archive_private_full_v3_piwigo'
$composeFiles = @('infra/docker-compose.yml','infra/private-full/docker-compose.override.yml')
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner'))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\browser\photos-app-v4-owner'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\photos-app-v4'))

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

function Stop-V4OwnerBrowser([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_BROWSER_STOP:' + $Code)
}

function New-RunId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Assert-IgnoredPrivateChild([string]$Candidate, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    if (-not $full.StartsWith($Root.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerBrowser ($Code + '_outside_root')
    }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4OwnerBrowser ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { Stop-V4OwnerBrowser ($Code + '_tracked') }
    return $full
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-V4OwnerBrowser ($Code + '_untrusted') }
    Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-V4OwnerBrowser ($Code + '_untrusted') }
    $full = Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
    if ($reparse.Count -ne 0) { Stop-V4OwnerBrowser ($Code + '_contains_reparse') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

function Get-StrictAdminUsername {
    $envPath = Join-Path $projectRoot $envRelative
    $lines = @(Get-Content -LiteralPath $envPath -Encoding UTF8 | Where-Object { $_ -match '^PIWIGO_ADMIN_USERNAME=' })
    if ($lines.Count -ne 1) { Stop-V4OwnerBrowser 'admin_username_missing' }
    $value = $lines[0].Substring('PIWIGO_ADMIN_USERNAME='.Length)
    if ($value -notmatch '^[A-Za-z0-9_.@+-]{1,100}$') { Stop-V4OwnerBrowser 'admin_username_invalid' }
    return $value
}

function Get-NodePath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $node = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { Stop-V4OwnerBrowser 'node_unavailable' }
    return $node
}

function Get-NodeModulesPath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $modules = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $modules -PathType Container)) { Stop-V4OwnerBrowser 'node_modules_unavailable' }
    return $modules
}

if (-not $ProvisionTemporaryRoles) {
    # Do not create identities merely because someone opened this helper. The
    # explicit switch is part of the permanent safety contract, not a prompt.
    Write-Output 'V4_OWNER_CHROME_QA=BLOCKED code=explicit_temporary_role_provisioning_required'
    exit 3
}

$run = New-RunId
$runRuntime = Join-Path $runtimeRoot $run
$runProfile = Join-Path $profileRoot $run
$runScreenshots = Join-Path $screenshotRoot ('owner-' + $run)
$credentialPath = Join-Path $runRuntime 'admin-session.json'
$lease = $null
$oldValues = @{}
$environmentNames = @(
    'NODE_PATH',
    'CLASS_ARCHIVE_V4_OWNER_RUN_ID',
    'CLASS_ARCHIVE_V4_OWNER_CORE_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_V4_OWNER_PROFILE_ROOT',
    'CLASS_ARCHIVE_V4_OWNER_SCREENSHOT_DIR',
    'CLASS_ARCHIVE_V4_OWNER_PROVISION'
)
$exitCode = 0
foreach ($name in $environmentNames) {
    $item = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -eq $item) { $null } else { [string]$item.Value }
}

try {
    foreach ($root in @($runtimeRoot, $profileRoot, $screenshotRoot)) {
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    foreach ($path in @($runRuntime, $runProfile, $runScreenshots)) {
        if (Test-Path -LiteralPath $path) { Stop-V4OwnerBrowser 'run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($path)
    }
    Assert-IgnoredPrivateChild -Candidate $runRuntime -Root $runtimeRoot -Code 'runtime' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $runProfile -Root $profileRoot -Code 'profile' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $runScreenshots -Root $screenshotRoot -Code 'screenshot' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null

    $compose = [Collections.Generic.List[string]]::new()
    foreach ($argument in @('-d','Ubuntu','--cd',$projectRoot,'--exec','docker','compose','--env-file',$envRelative)) { $compose.Add($argument) }
    foreach ($file in $composeFiles) { $compose.Add('-f'); $compose.Add($file) }
    $compose.Add('-p'); $compose.Add($composeProject)
    $adminUsername = Get-StrictAdminUsername
    $lease = New-ClassArchiveSystemAdminSession -BaseUri ([Uri]$coreOrigin) -ComposeBase ([string[]]$compose) -AdminUsername $adminUsername
    $cookie = @($lease.Session.Cookies.GetCookies([Uri]$coreOrigin) | Where-Object { $_.Name -eq 'pwg_id' -and $_.Value -match '^[A-Za-z0-9,-]{16,128}$' })
    if ($cookie.Count -ne 1) { Stop-V4OwnerBrowser 'admin_cookie_invalid' }
    $document = [ordered]@{
        version = 1
        environment = 'PRIVATE_REAL_FULL_OWNER_V4'
        admin = $adminUsername
        cookie = [string]$cookie[0].Value
        leaseHandle = [string]$lease.Handle
        run = $run
    }
    [IO.File]::WriteAllText($credentialPath, ($document | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_V4_OWNER_RUN_ID = $run
    $env:CLASS_ARCHIVE_V4_OWNER_CORE_ORIGIN = $coreOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_OWNER_PROFILE_ROOT = $runProfile
    $env:CLASS_ARCHIVE_V4_OWNER_SCREENSHOT_DIR = $runScreenshots
    $env:CLASS_ARCHIVE_V4_OWNER_PROVISION = '1'
    $output = @(& (Get-NodePath) (Join-Path $PSScriptRoot 'photos-app-v4-owner-browser-qa.mjs') 2>&1)
    $nodeExit = $LASTEXITCODE
    # The Node harness deliberately emits only bounded gate records. Never
    # relay a browser exception because it can contain private page text.
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_OWNER_CHROME_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_OWNER_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+ cleanup=(?:frozen|failed)$' -or
        $_ -match '^V4_OWNER_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_OWNER_CHROME_QA=PASS\b' })
    if ($nodeExit -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_OWNER_CHROME_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        Stop-V4OwnerBrowser 'node_runner_failed'
    }
    Write-Output $pass[0]
}
catch {
    $code = if ($_.Exception.Message -match '^V4_OWNER_BROWSER_STOP:([A-Za-z0-9_]{1,120})$') { [string]$Matches[1] } else { 'unexpected_' + $_.Exception.GetType().Name }
    if ($code -notmatch '^[A-Za-z0-9_]{1,120}$') { $code = 'unexpected' }
    Write-Output ('V4_OWNER_CHROME_QA=FAIL stage=wrapper code=' + $code)
    $exitCode = 2
}
finally {
    foreach ($name in $environmentNames) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    try { Remove-VerifiedPrivateFile -Path $credentialPath -Root $runtimeRoot -Code 'credential_cleanup' } catch { $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runProfile -Root $profileRoot -Code 'profile_cleanup' } catch { $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runRuntime -Root $runtimeRoot -Code 'runtime_cleanup' } catch { $exitCode = 2 }
    try { if ($null -ne $lease) { Remove-ClassArchiveSystemAdminSession -Lease $lease } } catch { $exitCode = 2 }
}

if ($exitCode -eq 0) { Write-Output 'V4_OWNER_CHROME_QA_COMPLETE=PASS' }
exit $exitCode
