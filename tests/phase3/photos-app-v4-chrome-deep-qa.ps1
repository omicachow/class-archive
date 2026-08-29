[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$CredentialFile,
    [switch]$RunMediaGuardRegression
)

# Synthetic-only Google Chrome Stable companion. It never starts/stops Docker
# or touches private data. Its run-scoped Anonymous-comment fixture is created
# and removed with exact database/audit boundaries so the browser pseudonym
# assertion is non-vacuous. The optional Phase 0 + Phase 1 calls are separate
# already-running HTTP regression boundaries, never browser evidence.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')

function Assert-PrivateChildPath([string]$Base, [string]$Target, [string]$Code) {
    $relative = Get-V4SyntheticPhaseARelativePath -Base $Base -Target $Target
    if ([string]::IsNullOrWhiteSpace($relative) -or $relative -eq '..' -or $relative.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or [IO.Path]::IsPathRooted($relative)) {
        throw $Code
    }
}

$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
Assert-PrivateChildPath $workRoot $credentialPath 'v4_chrome_deep_credential_outside_work_root'
$credentialRelative = $credentialPath.Substring($projectRoot.Length + 1).Replace('\', '/')
& git -C $projectRoot check-ignore --quiet --no-index -- $credentialRelative
if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $credentialRelative).Count -ne 0) { throw 'v4_chrome_deep_credential_not_private' }
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$deps = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = Join-Path $deps 'node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_chrome_deep_node_unavailable' }

$bytes = New-Object byte[] 8
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
$run = (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser\photos-app-v4-chrome-deep'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\screenshots\photos-app-v4-chrome-deep'))
$userDataRoot = [IO.Path]::GetFullPath((Join-Path $profileRoot $run))
$screenshotDir = [IO.Path]::GetFullPath((Join-Path $screenshotRoot $run))
$viewerFixturePath = [IO.Path]::GetFullPath((Join-Path $screenshotDir 'viewer-fixture.json'))
foreach ($path in @($userDataRoot, $screenshotDir)) {
    $base = if ($path -eq $userDataRoot) { $profileRoot } else { $screenshotRoot }
    Assert-PrivateChildPath $base $path 'v4_chrome_deep_private_path_invalid'
    if (Test-Path -LiteralPath $path) { throw 'v4_chrome_deep_run_path_not_fresh' }
    [void][IO.Directory]::CreateDirectory($path)
    $relative = $path.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw 'v4_chrome_deep_output_not_private' }
}

function Assert-V4DeepSyntheticComposeEnvironment {
    # Do not delegate container selection to ambient Compose state. Read only
    # the non-secret identity fields needed to prove this command can resolve
    # the public synthetic project (8090), never an owner/private project.
    $environmentPath = Join-Path $projectRoot '.env.piwigo'
    if (-not (Test-Path -LiteralPath $environmentPath -PathType Leaf)) { throw 'v4_chrome_deep_synthetic_env_missing' }
    $expected = [ordered]@{
        COMPOSE_PROJECT_NAME = 'class_archive_piwigo'
        CLASS_ARCHIVE_HTTP_PORT = '8090'
        CLASS_ARCHIVE_BASE_URL = 'http://localhost:8090'
    }
    $actual = @{}
    foreach ($line in [IO.File]::ReadLines($environmentPath)) {
        $match = [regex]::Match($line, '^([A-Z0-9_]+)=(.*)$')
        if ($match.Success -and $expected.Contains($match.Groups[1].Value)) {
            $actual[$match.Groups[1].Value] = $match.Groups[2].Value
        }
    }
    foreach ($key in $expected.Keys) {
        if ($actual[$key] -cne $expected[$key]) { throw 'v4_chrome_deep_synthetic_env_identity_invalid' }
    }
}

function Invoke-V4DeepViewerFixture([ValidateSet('prepare', 'cleanup')][string]$Action, [string]$Run) {
    if ($Run -notmatch '^[a-f0-9]{16}$') { throw 'v4_chrome_deep_fixture_run_invalid' }
    # This is deliberately compose *exec* against the existing engineering
    # container only. It never runs `up`, `down`, `stop`, `restart`, seeds a
    # database, or addresses any private runtime.
    $arguments = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_V4_VIEWER_FIXTURE=1',
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-viewer-fixture.php', $Action, $Run
    )
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& wsl.exe @arguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { throw ('v4_chrome_deep_fixture_' + $Action + '_failed') }
    $lines = @($output | ForEach-Object { [string]$_ })
    if ($Action -eq 'cleanup') {
        if (@($lines | Where-Object { $_ -eq ('V4_VIEWER_FIXTURE=CLEANUP run=' + $Run) }).Count -ne 1) {
            throw 'v4_chrome_deep_fixture_cleanup_result_invalid'
        }
        return $null
    }
    $pattern = '^V4_VIEWER_FIXTURE=READY run=' + [regex]::Escape($Run) + ' photo_a=([0-9a-f-]{36}) photo_b=([0-9a-f-]{36}) comment_a=([0-9a-f-]{36}) comment_b=([0-9a-f-]{36})$'
    $ready = @($lines | Where-Object { $_ -match $pattern })
    if ($ready.Count -ne 1) { throw 'v4_chrome_deep_fixture_prepare_result_invalid' }
    $match = [regex]::Match($ready[0], $pattern)
    if (-not $match.Success) { throw 'v4_chrome_deep_fixture_prepare_parse_invalid' }
    return [ordered]@{
        version = 1
        environment = 'synthetic'
        run = $Run
        photoIds = @($match.Groups[1].Value.ToLowerInvariant(), $match.Groups[2].Value.ToLowerInvariant())
        commentIds = @($match.Groups[3].Value.ToLowerInvariant(), $match.Groups[4].Value.ToLowerInvariant())
    }
}

$fixtureAttempted = $false
$fixtureCleanupFailure = $null
$result = $null
$mediaResult = $null
$phaseAMutationLease = $null

$names = @('NODE_PATH','CLASS_ARCHIVE_V4_DEEP_CREDENTIAL_FILE','CLASS_ARCHIVE_V4_DEEP_PIWIGO_ORIGIN','CLASS_ARCHIVE_V4_DEEP_PHOTO_ORIGIN','CLASS_ARCHIVE_V4_DEEP_VIEWER_FIXTURE_FILE','CLASS_ARCHIVE_V4_DEEP_USER_DATA_ROOT','CLASS_ARCHIVE_V4_DEEP_SCREENSHOT_DIR')
$old = @{}
foreach ($name in $names) {
    $item = Get-Item "Env:$name" -ErrorAction SilentlyContinue
    $old[$name] = if ($null -eq $item) { $null } else { $item.Value }
}
try {
    $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'deep-viewer'
    Assert-V4DeepSyntheticComposeEnvironment
    $fixtureAttempted = $true
    $viewerFixture = Invoke-V4DeepViewerFixture -Action prepare -Run $run
    [IO.File]::WriteAllText($viewerFixturePath, ($viewerFixture | ConvertTo-Json -Compress -Depth 4), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $viewerFixturePath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $viewerFixturePath
    $fixtureRelative = $viewerFixturePath.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $fixtureRelative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $fixtureRelative).Count -ne 0) { throw 'v4_chrome_deep_fixture_not_private' }
    $env:NODE_PATH = Join-Path $deps 'node\node_modules'
    $env:CLASS_ARCHIVE_V4_DEEP_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_DEEP_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'
    $env:CLASS_ARCHIVE_V4_DEEP_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'
    $env:CLASS_ARCHIVE_V4_DEEP_VIEWER_FIXTURE_FILE = $viewerFixturePath
    $env:CLASS_ARCHIVE_V4_DEEP_USER_DATA_ROOT = $userDataRoot
    $env:CLASS_ARCHIVE_V4_DEEP_SCREENSHOT_DIR = $screenshotDir
    $output = @(& $node (Join-Path $PSScriptRoot 'photos-app-v4-chrome-deep-qa.mjs') 2>&1)
    $code = $LASTEXITCODE
    # Do not relay DOM, URL, browser or credential diagnostics. The MJS runner
    # deliberately emits only these fixed stage/result forms.
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_CHROME_DEEP_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_CHROME_DEEP_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$' -or
        $_ -match '^V4_CHROME_DEEP_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_CHROME_DEEP_QA=PASS\b' })
    if ($code -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_CHROME_DEEP_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        throw 'v4_chrome_deep_qa_failed'
    }
    $result = [string]$pass[0]

    if ($RunMediaGuardRegression) {
        # Phase 0 owns the direct media GET/HEAD/Range, known URL, logout,
        # account-switch and path/query matrix. Phase 1 owns freeze/revoke
        # session invalidation. Both operate only on the existing synthetic
        # 8090 service; this wrapper never invokes `up`, `stop`, `down`,
        # reseeding, or any private endpoint.
        $mediaOutput = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') test-phase0 2>&1)
        $mediaCode = $LASTEXITCODE
        $mediaPass = @($mediaOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -eq 'MEDIA_GUARD_HTTP=PASS' })
        if ($mediaCode -ne 0 -or $mediaPass.Count -ne 1) {
            Write-Output 'V4_CHROME_DEEP_MEDIAGUARD=FAIL source=dev.ps1:test-phase0'
            throw 'v4_chrome_deep_mediaguard_failed'
        }
        $identityOutput = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') test-phase1 2>&1)
        $identityCode = $LASTEXITCODE
        $identityPass = @($identityOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^CLASS_IDENTITY_HTTP=PASS\b' })
        if ($identityCode -ne 0 -or $identityPass.Count -ne 1) {
            Write-Output 'V4_CHROME_DEEP_MEDIAGUARD=FAIL source=dev.ps1:test-phase1'
            throw 'v4_chrome_deep_identity_media_regression_failed'
        }
        $mediaResult = 'V4_CHROME_DEEP_MEDIAGUARD=PASS source=dev.ps1:test-phase0+test-phase1'
    }
}
finally {
    try {
        if ($fixtureAttempted) {
            try { [void](Invoke-V4DeepViewerFixture -Action cleanup -Run $run) }
            catch { $fixtureCleanupFailure = $_ }
        }
        foreach ($name in $names) {
            Remove-Item "Env:$name" -ErrorAction SilentlyContinue
            if ($null -ne $old[$name]) { Set-Item "Env:$name" -Value $old[$name] }
        }
        # Delete only this random, checked child. Never follow an unexpected
        # reparse point and never touch any user-owned Chrome profile.
        try {
            if (Test-Path -LiteralPath $userDataRoot) {
                $resolved = (Resolve-Path -LiteralPath $userDataRoot).Path
                $item = Get-Item -LiteralPath $userDataRoot -Force
                if (-not $resolved.StartsWith($profileRoot + $separator, [StringComparison]::OrdinalIgnoreCase) -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'v4_chrome_deep_profile_cleanup_boundary' }
                Remove-Item -LiteralPath $userDataRoot -Recurse -Force -ErrorAction Stop
                if (Test-Path -LiteralPath $userDataRoot) { throw 'v4_chrome_deep_profile_cleanup_failed' }
            }
        }
        catch {
            if ($null -eq $fixtureCleanupFailure) { $fixtureCleanupFailure = $_ }
        }
        if ($null -ne $fixtureCleanupFailure) { throw 'v4_chrome_deep_fixture_cleanup_failed' }
    }
    catch {
        throw
    }
    finally {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
}
if ([string]::IsNullOrWhiteSpace($result)) { throw 'v4_chrome_deep_result_missing' }
Write-Output $result
# Keep screenshots in their ignored local directory without emitting its
# workstation path into a transcript that later becomes safe evidence.
if ($RunMediaGuardRegression) {
    if ($mediaResult -cne 'V4_CHROME_DEEP_MEDIAGUARD=PASS source=dev.ps1:test-phase0+test-phase1') { throw 'v4_chrome_deep_mediaguard_result_missing' }
    Write-Output $mediaResult
    # This terminal record proves both Phase 0/1 HTTP regressions and the
    # viewer/profile finally cleanup completed before evidence is accepted.
    Write-Output 'V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS'
} else {
    Write-Output 'V4_CHROME_DEEP_COMPLETE=PASS mediaguard=SKIPPED'
}
