[CmdletBinding()]
param(
    # The only owner-state mutation performed by this harness is a bounded
    # password rotation of the four pre-existing, explicitly bound fixture
    # principals. Requiring this switch prevents an accidental invocation from
    # changing even those credentials.
    [switch]$ConfirmExistingFixtureCredentialRotation
)

# Owner-private Photos App V4 Chrome Stable role acceptance wrapper.
#
# This runner never creates an Identity, Seat, Account, Claim, Invitation, or
# token. It rotates the already-bound fixture accounts through the established
# provision-access-users.php helper, runs read-only browser journeys, then
# revokes every fixture session by rotating the same four accounts to a fresh
# unknown secret in finally. The temporary credentials exist only in ignored,
# owner-only files and are never written to argv, stdout, Git, or documentation.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$separator = [IO.Path]::DirectorySeparatorChar
$coreOrigin = 'http://127.0.0.1:8190/'
$photoOrigin = 'http://127.0.0.1:8191/'
$envRelative = 'infra/private-full/.env.piwigo.owner'
$composeProject = 'class_archive_private_full_v3_piwigo'
$composeFiles = @('infra/docker-compose.yml', 'infra/private-full/docker-compose.override.yml')
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner-existing-fixtures'))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\browser\photos-app-v4-owner-existing-fixtures'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\photos-app-v4'))

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function Stop-V4OwnerFixtureBrowser([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_FIXTURE_BROWSER_STOP:' + $Code)
}

function New-RunId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function New-SecretText {
    $bytes = New-Object byte[] 48
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Assert-IgnoredPrivateChild([string]$Candidate, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + $separator
    $rootBoundary = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + $separator
    if (-not $full.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerFixtureBrowser ($Code + '_outside_project')
    }
    if (-not $full.StartsWith($rootBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerFixtureBrowser ($Code + '_outside_root')
    }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4OwnerFixtureBrowser ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) {
        Stop-V4OwnerFixtureBrowser ($Code + '_tracked')
    }
    return $full
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerFixtureBrowser ($Code + '_untrusted')
    }
    Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerFixtureBrowser ($Code + '_untrusted')
    }
    $full = Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object {
        $_.Attributes -band [IO.FileAttributes]::ReparsePoint
    })
    if ($reparse.Count -ne 0) { Stop-V4OwnerFixtureBrowser ($Code + '_contains_reparse') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

function Get-NodePath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $node = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { Stop-V4OwnerFixtureBrowser 'node_unavailable' }
    return $node
}

function Get-NodeModulesPath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $modules = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $modules -PathType Container)) { Stop-V4OwnerFixtureBrowser 'node_modules_unavailable' }
    return $modules
}

function Invoke-Piwigo([string[]]$Arguments, [string]$Code) {
    $compose = [Collections.Generic.List[string]]::new()
    foreach ($argument in @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', $envRelative)) {
        $compose.Add($argument)
    }
    foreach ($file in $composeFiles) { $compose.Add('-f'); $compose.Add($file) }
    $compose.Add('-p'); $compose.Add($composeProject)
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $commandArguments = [string[]]($compose.ToArray() + $Arguments)
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" @commandArguments 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) { Stop-V4OwnerFixtureBrowser $Code }
    return [string]::Join("`n", ($lines | ForEach-Object { [string]$_ }))
}

function Set-ExistingFixturePasswords([string]$Password, [string]$Run, [string]$HostPasswordPath, [string]$Code) {
    if ($Password -notmatch '^[A-Za-z0-9_-]{32,190}$' -or $Run -notmatch '^[a-f0-9]{24}$') {
        Stop-V4OwnerFixtureBrowser ($Code + '_secret_invalid')
    }
    Assert-IgnoredPrivateChild -Candidate $HostPasswordPath -Root $runtimeRoot -Code ($Code + '_host_secret') | Out-Null
    if (Test-Path -LiteralPath $HostPasswordPath) { Stop-V4OwnerFixtureBrowser ($Code + '_host_secret_exists') }
    [IO.File]::WriteAllText($HostPasswordPath, $Password, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $HostPasswordPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPasswordPath
    $containerSecret = '/tmp/class-archive-fixture-password-' + $Run.Substring(0, 16) + '.txt'
    try {
        $relative = $HostPasswordPath.Substring($projectRoot.Length + 1).Replace('\', '/')
        [void](Invoke-Piwigo -Arguments @('cp', $relative, ('piwigo:' + $containerSecret)) -Code ($Code + '_copy_failed'))
        [void](Invoke-Piwigo -Arguments @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $containerSecret + ' && chmod 0600 ' + $containerSecret)) -Code ($Code + '_mode_failed'))
        $result = Invoke-Piwigo -Arguments @(
            'exec', '-T', '--user', 'nginx', '-e', ('CLASS_ARCHIVE_FIXTURE_PASSWORD_FILE=' + $containerSecret),
            'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
        ) -Code ($Code + '_provisioner_failed')
        if ($result.Trim() -ne 'ACCESS_FIXTURES_READY') { Stop-V4OwnerFixtureBrowser ($Code + '_provisioner_rejected') }
    }
    finally {
        try {
            [void](Invoke-Piwigo -Arguments @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $containerSecret) -Code ($Code + '_container_cleanup_failed'))
        }
        finally {
            Remove-VerifiedPrivateFile -Path $HostPasswordPath -Root $runtimeRoot -Code ($Code + '_host_cleanup')
        }
    }
}

if (-not $ConfirmExistingFixtureCredentialRotation) {
    Write-Output 'V4_OWNER_EXISTING_FIXTURE_CHROME_QA=BLOCKED code=explicit_fixture_credential_rotation_confirmation_required'
    exit 3
}

$run = New-RunId
$runRuntime = Join-Path $runtimeRoot $run
$runProfile = Join-Path $profileRoot $run
$runScreenshots = Join-Path $screenshotRoot ('owner-existing-fixtures-' + $run)
$credentialPath = Join-Path $runRuntime 'credentials.json'
$fixturePasswordPath = Join-Path $runRuntime 'fixture-password.txt'
$rotationPasswordPath = Join-Path $runRuntime 'rotation-password.txt'
$temporaryPassword = $null
$rotationPassword = $null
$fixtureCredentialChanged = $false
$exitCode = 0
$wrapperStage = 'initialization'
$oldValues = @{}
$environmentNames = @(
    'NODE_PATH',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR'
)
foreach ($name in $environmentNames) {
    $item = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -eq $item) { $null } else { [string]$item.Value }
}

try {
    $wrapperStage = 'private_paths'
    foreach ($root in @($runtimeRoot, $profileRoot, $screenshotRoot)) {
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    foreach ($path in @($runRuntime, $runProfile, $runScreenshots)) {
        if (Test-Path -LiteralPath $path) { Stop-V4OwnerFixtureBrowser 'run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($path)
    }
    Assert-IgnoredPrivateChild -Candidate $runRuntime -Root $runtimeRoot -Code 'runtime' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $runProfile -Root $profileRoot -Code 'profile' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $runScreenshots -Root $screenshotRoot -Code 'screenshots' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null

    $wrapperStage = 'fixture_prepare'
    $temporaryPassword = New-SecretText
    # From this point on finally must attempt a second independent rotation,
    # even when the first provisioner invocation changes the hashes and then
    # fails while validating or cleaning its bounded transport.
    $fixtureCredentialChanged = $true
    Set-ExistingFixturePasswords -Password $temporaryPassword -Run $run -HostPasswordPath $fixturePasswordPath -Code 'fixture_prepare'
    $wrapperStage = 'credential_document'
    $roles = [ordered]@{
        classmate = [ordered]@{ username = 'fixture-classmate'; password = $temporaryPassword }
        family = [ordered]@{ username = 'fixture-family'; password = $temporaryPassword }
        teacher = [ordered]@{ username = 'fixture-teacher'; password = $temporaryPassword }
        anonymous = [ordered]@{ username = 'fixture-anonymous'; password = $temporaryPassword }
    }
    $document = [ordered]@{
        version = 1
        environment = 'PRIVATE_REAL_FULL_OWNER_V4_EXISTING_FIXTURES'
        roles = $roles
        run = $run
    }
    [IO.File]::WriteAllText($credentialPath, ($document | ConvertTo-Json -Compress -Depth 5), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    $roles = $null
    $document = $null

    $wrapperStage = 'chrome_runner'
    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID = $run
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN = $coreOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT = $runProfile
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR = $runScreenshots

    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& (Get-NodePath) (Join-Path $PSScriptRoot 'photos-app-v4-owner-browser-qa.mjs') 2>&1)
        $nodeExit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ roles=4 full_photos=[0-9]+ heritage_photos=[0-9]+ living_photos=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+ writes=0$' -or
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS\b' })
    if ($nodeExit -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        Stop-V4OwnerFixtureBrowser 'node_runner_failed'
    }
    Write-Output $pass[0]
}
catch {
    $code = if ($_.Exception.Message -match '^V4_OWNER_FIXTURE_BROWSER_STOP:([A-Za-z0-9_]{1,120})$') {
        [string]$Matches[1]
    } else {
        $exceptionType = $_.Exception.GetType().Name
        $innerType = if ($null -ne $_.Exception.InnerException) { $_.Exception.InnerException.GetType().Name } else { 'none' }
        'unexpected_' + $wrapperStage + '_' + $exceptionType + '_' + $innerType
    }
    if ($code -notmatch '^[A-Za-z0-9_]{1,120}$') { $code = 'unexpected' }
    Write-Output ('V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL stage=wrapper code=' + $code.ToLowerInvariant())
    $exitCode = 2
}
finally {
    foreach ($name in $environmentNames) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    # This second independent password is intentionally never written into the
    # credential document. The trusted provisioner hashes it and revokes every
    # browser session, then both the host/container secret files are removed.
    if ($fixtureCredentialChanged) {
        try {
            $wrapperStage = 'fixture_final_rotation'
            $rotationPassword = New-SecretText
            Set-ExistingFixturePasswords -Password $rotationPassword -Run $run -HostPasswordPath $rotationPasswordPath -Code 'fixture_final_rotation'
        }
        catch { $exitCode = 2 }
        finally { $rotationPassword = $null }
    }
    $temporaryPassword = $null
    try { Remove-VerifiedPrivateFile -Path $credentialPath -Root $runtimeRoot -Code 'credential_cleanup' } catch { $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runProfile -Root $profileRoot -Code 'profile_cleanup' } catch { $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runRuntime -Root $runtimeRoot -Code 'runtime_cleanup' } catch { $exitCode = 2 }
}

if ($exitCode -eq 0) { Write-Output 'V4_OWNER_EXISTING_FIXTURE_CHROME_QA_COMPLETE=PASS sessions=revoked credentials=unknown' }
exit $exitCode
