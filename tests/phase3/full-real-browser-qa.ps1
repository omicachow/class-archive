[CmdletBinding()]
param(
    [ValidateSet('staging', 'owner', 'restore')]
    [string]$Mode = 'staging'
)

# Local Chromium owner acceptance for the full real library. It mints a bounded,
# short-lived SYSTEM_ADMIN session only for this test, stores its cookie in an
# ignored owner-only file, and revokes the session before returning. Screenshots
# contain real private photos and are therefore confined to ignored local paths.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$isRestore = $Mode -eq 'restore'
$envRelative = if ($isRestore) { 'infra/owner-restore/.env.piwigo' } elseif ($Mode -eq 'staging') { 'infra/private-full/.env.piwigo.staging' } else { 'infra/private-full/.env.piwigo.owner' }
$corePort = if ($Mode -eq 'owner') { 8190 } else { 8290 }
$photoPort = if ($Mode -eq 'owner') { 8191 } else { 8291 }
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot $(if ($isRestore) { '.codex-work\owner-restore\runtime\browser' } else { '.codex-work\private-real-full\runtime\browser' })))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot $(if ($isRestore) { '.codex-work\owner-restore\screenshots\owner' } else { '.codex-work\private-real-qa\screenshots\full-real' })))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser-profiles'))
$credentialEnvironment = if ($isRestore) { 'OWNER_RESTORE_DRILL' } else { 'PRIVATE_REAL_FULL' }
$composeProject = if ($isRestore) { 'class_archive_owner_restore_v1_piwigo' } else { 'class_archive_private_full_v3_piwigo' }
$composeFiles = if ($isRestore) {
    @('infra/docker-compose.yml','infra/owner-restore/docker-compose.piwigo.override.yml','infra/private-full/docker-compose.ai-worker.override.yml')
} else {
    @('infra/docker-compose.yml','infra/private-full/docker-compose.override.yml')
}
$restoreDockerHost = 'unix:///var/run/docker.sock'
$separator = [IO.Path]::DirectorySeparatorChar

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

function Stop-FullBrowser([string]$Code) {
    throw [InvalidOperationException]::new('FULL_REAL_BROWSER_STOP:' + $Code)
}

function Assert-IgnoredPrivatePath([string]$Path, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($Root.TrimEnd('\', '/') + $separator, [StringComparison]::OrdinalIgnoreCase)) { Stop-FullBrowser ($Code + '_outside_root') }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-FullBrowser ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { Stop-FullBrowser ($Code + '_tracked') }
    return $full
}

function New-RunId {
    $bytes = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-StrictEnvironmentValue([string]$Name) {
    $envPath = Join-Path $projectRoot $envRelative
    $lines = @(Get-Content -LiteralPath $envPath -Encoding UTF8 | Where-Object { $_ -match ('^' + [regex]::Escape($Name) + '=') })
    if ($lines.Count -ne 1) { Stop-FullBrowser 'admin_username_missing' }
    $value = $lines[0].Substring(($Name + '=').Length)
    if ($value -notmatch '^[A-Za-z0-9_.@+-]{1,100}$') { Stop-FullBrowser 'admin_username_invalid' }
    return $value
}

function Get-RestoreSystemAdminUsername([string[]]$ComposeBase) {
    $sql = "SELECT u.username FROM piwigo_class_identity_principal p JOIN piwigo_users u ON u.id=p.piwigo_user_id JOIN piwigo_user_infos ui ON ui.user_id=u.id WHERE p.principal_type='SYSTEM_ACCOUNT' AND p.system_role='SYSTEM_ADMIN' AND p.state='ACTIVE' AND ui.status IN ('admin','webmaster') ORDER BY p.id;"
    $shell = 'exec mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute="$1"'
    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in $ComposeBase) { $arguments.Add($argument) }
    foreach ($argument in @('exec','-T','db','sh','-eu','-c',$shell,'sh',$sql)) { $arguments.Add($argument) }
    $native = Invoke-ClassArchiveNativeWithInput -FileName 'wsl.exe' -Arguments $arguments.ToArray()
    if ([int]$native.ExitCode -ne 0) { Stop-FullBrowser 'restore_admin_discovery_failed' }
    $rows = @(($native.Output -join '') -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($rows.Count -ne 1 -or $rows[0] -notmatch '^[A-Za-z0-9_.@+-]{1,100}$') { Stop-FullBrowser 'restore_admin_discovery_ambiguous' }
    return [string]$rows[0]
}

function Get-ChromePath {
    $programFiles = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)
    $path = Join-Path $programFiles 'Google\Chrome\Application\chrome.exe'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-FullBrowser 'chrome_unavailable' }
    return $path
}

function Get-NodePath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $path = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-FullBrowser 'node_unavailable' }
    return $path
}

function Get-NodeModulesPath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $path = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $path -PathType Container)) { Stop-FullBrowser 'node_modules_unavailable' }
    return $path
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-FullBrowser ($Code + '_untrusted') }
    Assert-IgnoredPrivatePath -Path $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-FullBrowser ($Code + '_untrusted') }
    $full = Assert-IgnoredPrivatePath -Path $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint })
    if ($reparse.Count -ne 0) { Stop-FullBrowser ($Code + '_contains_reparse_point') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

$runId = New-RunId
$credentialPath = Join-Path $runtimeRoot ($runId + '.credentials.json')
$screenshotDirectory = Join-Path $screenshotRoot $runId
$profileDirectory = Join-Path $profileRoot ('full-real-' + $Mode + '-' + $runId)
$lease = $null
$oldNodePath = $env:NODE_PATH
$names = @('CLASS_ARCHIVE_FULL_QA_MODE','CLASS_ARCHIVE_FULL_QA_CORE_ORIGIN','CLASS_ARCHIVE_FULL_QA_PHOTO_ORIGIN','CLASS_ARCHIVE_FULL_QA_SCREENSHOT_DIR','CLASS_ARCHIVE_FULL_QA_PROFILE_DIR','CLASS_ARCHIVE_FULL_QA_CHROME','CLASS_ARCHIVE_FULL_QA_CREDENTIAL_FILE')
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
    # The check above validates a non-existent child path against Git's ignore
    # rules without creating a sentinel beside real private data.
    [void][IO.Directory]::CreateDirectory($screenshotDirectory)
    [void][IO.Directory]::CreateDirectory($profileDirectory)
    Assert-IgnoredPrivatePath -Path $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null
    Assert-IgnoredPrivatePath -Path $screenshotDirectory -Root $screenshotRoot -Code 'screenshot' | Out-Null
    Assert-IgnoredPrivatePath -Path $profileDirectory -Root $profileRoot -Code 'profile' | Out-Null

    $compose = [Collections.Generic.List[string]]::new()
    foreach ($argument in @('-d','Ubuntu','--cd',$projectRoot,'--exec')) { $compose.Add($argument) }
    foreach ($argument in @('docker')) { $compose.Add($argument) }
    if ($isRestore) { foreach ($argument in @('--host',$restoreDockerHost)) { $compose.Add($argument) } }
    foreach ($argument in @('compose','--env-file',$envRelative)) { $compose.Add($argument) }
    foreach ($file in $composeFiles) { $compose.Add('-f'); $compose.Add($file) }
    $compose.Add('-p'); $compose.Add($composeProject)
    $adminUsername = if ($isRestore) { Get-RestoreSystemAdminUsername -ComposeBase $compose.ToArray() } else { Get-StrictEnvironmentValue 'PIWIGO_ADMIN_USERNAME' }
    $lease = New-ClassArchiveSystemAdminSession -BaseUri ([Uri]("http://127.0.0.1:$corePort/")) -ComposeBase ([string[]]$compose) -AdminUsername $adminUsername
    $cookies = @($lease.Session.Cookies.GetCookies([Uri]("http://127.0.0.1:$corePort/")) | Where-Object { $_.Name -eq 'pwg_id' -and $_.Value -match '^[A-Za-z0-9,-]{16,128}$' })
    if ($cookies.Count -ne 1) { Stop-FullBrowser 'admin_cookie_invalid' }
    $credential = [ordered]@{
        version = 1
        environment = $credentialEnvironment
        admin = $adminUsername
        cookie = [string]$cookies[0].Value
        leaseHandle = [string]$lease.Handle
    }
    [IO.File]::WriteAllText($credentialPath, ($credential | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

    $node = Get-NodePath
    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_FULL_QA_MODE = $Mode
    $env:CLASS_ARCHIVE_FULL_QA_CORE_ORIGIN = "http://127.0.0.1:$corePort/"
    $env:CLASS_ARCHIVE_FULL_QA_PHOTO_ORIGIN = "http://127.0.0.1:$photoPort/"
    $env:CLASS_ARCHIVE_FULL_QA_SCREENSHOT_DIR = $screenshotDirectory
    $env:CLASS_ARCHIVE_FULL_QA_PROFILE_DIR = $profileDirectory
    $env:CLASS_ARCHIVE_FULL_QA_CHROME = Get-ChromePath
    $env:CLASS_ARCHIVE_FULL_QA_CREDENTIAL_FILE = $credentialPath
    $output = @(& $node (Join-Path $PSScriptRoot 'full-real-browser-qa.mjs') 2>&1)
    $exitCode = $LASTEXITCODE
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^FULL_REAL_BROWSER_QA=(?:PASS|FAIL)\b' })
    $pass = @($safe | Where-Object { $_ -match '^FULL_REAL_BROWSER_QA=PASS\b' })
    if ($exitCode -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^FULL_REAL_BROWSER_QA=FAIL\b' } | Select-Object -Last 1)
        Stop-FullBrowser ('browser_qa_failed' + $(if ($failure.Count -eq 1) { '_' + (($failure[0] -split 'code=')[-1]) } else { '' }))
    }
    Write-Output $pass[0]
    Write-Output 'FULL_REAL_BROWSER_SCREENSHOTS=PRIVATE_LOCAL_IGNORED'
}
catch {
    $code = if ($_.Exception.Message -match '^FULL_REAL_BROWSER_STOP:([A-Za-z0-9_]{1,96})$') { [string]$Matches[1] } else { 'unexpected_' + $_.Exception.GetType().Name }
    if ($code -notmatch '^[A-Za-z0-9_]{1,120}$') { $code = 'unexpected' }
    Write-Output ('FULL_REAL_BROWSER_QA=FAIL code=' + $code)
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
