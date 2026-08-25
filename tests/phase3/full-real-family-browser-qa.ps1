[CmdletBinding()]
param(
    [ValidateSet('staging', 'owner')]
    [string]$Mode = 'staging'
)

# A bounded local-only wrapper.  The only persisted handoff to Chromium is a
# short-lived SYSTEM_ADMIN test session stored beneath an ignored directory;
# the helper revokes it before returning.  Family and Classmate credentials
# exist solely inside the Node process.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envRelative = if ($Mode -eq 'staging') { 'infra/private-full/.env.piwigo.staging' } else { 'infra/private-full/.env.piwigo.owner' }
$corePort = if ($Mode -eq 'staging') { 8290 } else { 8190 }
$photoPort = if ($Mode -eq 'staging') { 8291 } else { 8191 }
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-full\runtime\family-browser'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\full-real'))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser-profiles'))

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

function Stop-FullFamilyBrowser([string]$Code) { throw [InvalidOperationException]::new('FULL_REAL_FAMILY_BROWSER_STOP:' + $Code) }

function Assert-IgnoredPrivatePath([string]$Path, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $separator = [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($Root.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) { Stop-FullFamilyBrowser ($Code + '_outside_root') }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-FullFamilyBrowser ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { Stop-FullFamilyBrowser ($Code + '_tracked') }
    return $full
}

function New-RunId {
    $bytes = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-EnvSetting([string]$Name) {
    $path = Join-Path $projectRoot $envRelative
    $lines = @(Get-Content -LiteralPath $path -Encoding UTF8 | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') })
    if ($lines.Count -ne 1) { Stop-FullFamilyBrowser 'admin_username_missing' }
    $value = $lines[0].Substring(($Name + '=').Length)
    if ($value -notmatch '^[A-Za-z0-9_.@+-]{1,100}$') { Stop-FullFamilyBrowser 'admin_username_invalid' }
    return $value
}

function Get-ChromePath {
    $path = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)) 'Google\Chrome\Application\chrome.exe'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-FullFamilyBrowser 'chrome_unavailable' }
    return $path
}

function Get-NodePath {
    $path = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-FullFamilyBrowser 'node_unavailable' }
    return $path
}

function Get-NodeModulesPath {
    $path = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $path -PathType Container)) { Stop-FullFamilyBrowser 'node_modules_unavailable' }
    return $path
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-FullFamilyBrowser ($Code + '_untrusted') }
    Assert-IgnoredPrivatePath -Path $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-FullFamilyBrowser ($Code + '_untrusted') }
    $full = Assert-IgnoredPrivatePath -Path $item.FullName -Root $Root -Code $code
    if (@(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count -ne 0) { Stop-FullFamilyBrowser ($Code + '_contains_reparse') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

$runId = New-RunId
$credentialPath = Join-Path $runtimeRoot ($runId + '.credentials.json')
$screenshotDirectory = Join-Path $screenshotRoot ('family-' + $runId)
$profileDirectory = Join-Path $profileRoot ('full-real-family-' + $Mode + '-' + $runId)
$lease = $null
$oldNodePath = $env:NODE_PATH
$names = @('CLASS_ARCHIVE_FULL_FAMILY_QA_CORE_ORIGIN','CLASS_ARCHIVE_FULL_FAMILY_QA_PHOTO_ORIGIN','CLASS_ARCHIVE_FULL_FAMILY_QA_SCREENSHOT_DIR','CLASS_ARCHIVE_FULL_FAMILY_QA_PROFILE_DIR','CLASS_ARCHIVE_FULL_FAMILY_QA_CREDENTIAL_FILE','CLASS_ARCHIVE_FULL_FAMILY_QA_CHROME')
$oldValues = @{}
$exitCode = 0
foreach ($name in $names) {
    $current = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -ne $current) { [string]$current.Value } else { $null }
}

try {
    foreach ($root in @($runtimeRoot, $screenshotRoot, $profileRoot)) {
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Assert-IgnoredPrivatePath -Path (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    [void][IO.Directory]::CreateDirectory($screenshotDirectory)
    [void][IO.Directory]::CreateDirectory($profileDirectory)
    Assert-IgnoredPrivatePath -Path $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null
    Assert-IgnoredPrivatePath -Path $screenshotDirectory -Root $screenshotRoot -Code 'screenshot' | Out-Null
    Assert-IgnoredPrivatePath -Path $profileDirectory -Root $profileRoot -Code 'profile' | Out-Null

    $adminUsername = Get-EnvSetting 'PIWIGO_ADMIN_USERNAME'
    $compose = @('-d','Ubuntu','--cd',$projectRoot,'--exec','docker','compose','--env-file',$envRelative,'-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml','-p','class_archive_private_full_v3_piwigo')
    $lease = New-ClassArchiveSystemAdminSession -BaseUri ([Uri]("http://127.0.0.1:$corePort/")) -ComposeBase ([string[]]$compose) -AdminUsername $adminUsername
    $cookies = @($lease.Session.Cookies.GetCookies([Uri]("http://127.0.0.1:$corePort/")) | Where-Object { $_.Name -eq 'pwg_id' -and $_.Value -match '^[A-Za-z0-9,-]{16,128}$' })
    if ($cookies.Count -ne 1) { Stop-FullFamilyBrowser 'admin_cookie_invalid' }
    $document = [ordered]@{ version = 1; environment = 'PRIVATE_REAL_FULL'; admin = $adminUsername; cookie = [string]$cookies[0].Value; leaseHandle = [string]$lease.Handle }
    [IO.File]::WriteAllText($credentialPath, ($document | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_CORE_ORIGIN = "http://127.0.0.1:$corePort/"
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_PHOTO_ORIGIN = "http://127.0.0.1:$photoPort/"
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_SCREENSHOT_DIR = $screenshotDirectory
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_PROFILE_DIR = $profileDirectory
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_FULL_FAMILY_QA_CHROME = Get-ChromePath
    $output = @(& (Get-NodePath) (Join-Path $PSScriptRoot 'full-real-family-browser-qa.mjs') 2>&1)
    $exitCode = $LASTEXITCODE
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^FULL_REAL_FAMILY_BROWSER_QA=(?:PASS|FAIL)\b' })
    $pass = @($safe | Where-Object { $_ -match '^FULL_REAL_FAMILY_BROWSER_QA=PASS\b' })
    if ($exitCode -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^FULL_REAL_FAMILY_BROWSER_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1 -and $failure[0] -match 'code=([A-Za-z0-9_]{1,120})$') {
            Stop-FullFamilyBrowser ('browser_qa_failed_' + [string]$Matches[1])
        }
        Stop-FullFamilyBrowser 'browser_qa_failed'
    }
    Write-Output $pass[0]
    Write-Output 'FULL_REAL_FAMILY_BROWSER_SCREENSHOTS=PRIVATE_LOCAL_IGNORED'
}
catch {
    $code = if ($_.Exception.Message -match '^FULL_REAL_FAMILY_BROWSER_STOP:([A-Za-z0-9_]{1,96})$') { [string]$Matches[1] } else { 'unexpected_' + $_.Exception.GetType().Name }
    if ($code -notmatch '^[A-Za-z0-9_]{1,120}$') { $code = 'unexpected' }
    Write-Output ('FULL_REAL_FAMILY_BROWSER_QA=FAIL code=' + $code)
    $exitCode = 2
}
finally {
    foreach ($name in $names) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    if ($null -ne $oldNodePath) { $env:NODE_PATH = $oldNodePath } else { Remove-Item Env:NODE_PATH -ErrorAction SilentlyContinue }
    try { Remove-VerifiedPrivateFile -Path $credentialPath -Root $runtimeRoot -Code 'credential_cleanup' } catch { $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $profileDirectory -Root $profileRoot -Code 'profile_cleanup' } catch { $exitCode = 2 }
    try { if ($null -ne $lease) { Remove-ClassArchiveSystemAdminSession -Lease $lease } } catch { $exitCode = 2 }
}
exit $exitCode
