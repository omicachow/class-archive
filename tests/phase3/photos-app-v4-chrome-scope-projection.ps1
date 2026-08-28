[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$CredentialFile,

    # Only the narrow People lifecycle may hold the outer host lease while it
    # delegates this scope runner. Direct invocations acquire their own lease.
    [string]$ExternalPhaseALeaseToken = ''
)

# Synthetic-only browser wrapper for the independent V4 scope-projection
# gate. It never provisions People data, starts/stops Docker, or accesses
# private real-library state. A non-empty People projection is a deliberate runtime
# prerequisite; an empty projection is a blocking result, not a skipped PASS.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
$compose = @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml')

function Assert-ChildPath([string]$Base, [string]$Target, [string]$Code) {
    $relative = [IO.Path]::GetRelativePath($Base, $Target)
    if ([string]::IsNullOrWhiteSpace($relative) -or $relative -eq '..' -or $relative.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or [IO.Path]::IsPathRooted($relative)) { throw $Code }
}
function Assert-IgnoredUntracked([string]$Path, [string]$Code) {
    Assert-ChildPath $projectRoot $Path $Code
    $relative = $Path.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw $Code }
}
function New-RunId {
    $bytes = New-Object byte[] 8; $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}
function Get-JsonOutput([string[]]$Arguments, [string]$Code) {
    $prior = $ErrorActionPreference
    try { $ErrorActionPreference = 'Continue'; $lines = @(& wsl.exe @($compose + $Arguments) 2>&1); $exit = $LASTEXITCODE }
    finally { $ErrorActionPreference = $prior }
    if ($exit -ne 0) { throw $Code }
    $json = @($lines | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^\{.*\}$' })
    if ($json.Count -ne 1) { throw $Code }
    try { return ($json[0] | ConvertFrom-Json -ErrorAction Stop) } catch { throw $Code }
}
function Assert-SyntheticBaseline([string]$Code) {
    $state = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-upload-lifecycle-fixture.php','baseline') 'v4_scope_baseline_fixture_failed'
    if ([int]$state.images -ne 72 -or [int]$state.active_canonical -ne 72 -or [int]$state.physical_originals -ne 72 -or [int]$state.multi_album_images -ne 8) { throw $Code }
}
function Update-ScopeTruth([string]$Path, [string]$UnknownPhotoId, [object[]]$UnknownArchivePhotoIds) {
    Assert-IgnoredUntracked $Path 'v4_scope_truth_not_private'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    if ($UnknownPhotoId -notmatch '^[0-9a-f-]{36}$') { throw 'v4_scope_unknown_photo_invalid' }
    try { $document = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { throw 'v4_scope_truth_invalid' }
    $invalidIds = @($document.heritagePhotoIds + $document.livingPhotoIds | Where-Object { [string]$_ -notmatch '^[0-9a-f-]{36}$' })
    $matchingUnknown = @($document.livingPhotoIds | Where-Object { [string]$_ -ieq $UnknownPhotoId })
    $all = @($document.heritagePhotoIds + $document.livingPhotoIds | ForEach-Object { ([string]$_).ToLowerInvariant() })
    $unknownArchive = @($UnknownArchivePhotoIds | ForEach-Object { ([string]$_).ToLowerInvariant() })
    $unknownArchiveInvalid = @($unknownArchive | Where-Object { $_ -notmatch '^[0-9a-f-]{36}$' })
    $truthInvalid = @(
        ($document.version -ne 1),
        ([string]$document.environment -ne 'synthetic'),
        (@($document.heritagePhotoIds).Count -lt 1),
        (@($document.livingPhotoIds).Count -lt 1),
        ($invalidIds.Count -ne 0),
        ($matchingUnknown.Count -ne 1),
        ($all.Count -ne 72),
        ($unknownArchive.Count -lt 1),
        ($unknownArchiveInvalid.Count -ne 0),
        (-not ($unknownArchive -contains $UnknownPhotoId.ToLowerInvariant()))
    ) -contains $true
    if ($truthInvalid) { throw 'v4_scope_truth_shape_invalid' }
    if (@($all | Select-Object -Unique).Count -ne $all.Count) { throw 'v4_scope_truth_duplicate' }
    if (@($unknownArchive | Select-Object -Unique).Count -ne $unknownArchive.Count) { throw 'v4_scope_unknown_archive_duplicate' }
    if (@($unknownArchive | Where-Object { $_ -notin $all }).Count -ne 0) { throw 'v4_scope_unknown_archive_outside_catalog' }
    $ordered = [ordered]@{
        version = 1
        environment = 'synthetic'
        heritagePhotoIds = @($document.heritagePhotoIds | ForEach-Object { ([string]$_).ToLowerInvariant() } | Sort-Object)
        livingPhotoIds = @($document.livingPhotoIds | ForEach-Object { ([string]$_).ToLowerInvariant() } | Sort-Object)
        unknownArchivePhotoIds = @($unknownArchive | Sort-Object)
        unknownPhotoId = $UnknownPhotoId.ToLowerInvariant()
    }
    $temporary = $Path + '.next'
    if (Test-Path -LiteralPath $temporary) { throw 'v4_scope_truth_temporary_exists' }
    [IO.File]::WriteAllText($temporary, ($ordered | ConvertTo-Json -Compress -Depth 5), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $temporary
    Move-Item -LiteralPath $temporary -Destination $Path -Force
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-IgnoredUntracked $Path 'v4_scope_truth_not_private'
}

Assert-ChildPath $workRoot $credentialPath 'v4_scope_credential_outside_work_root'
Assert-IgnoredUntracked $credentialPath 'v4_scope_credential_not_private'
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
$scopeTruthPath = Join-Path (Split-Path -Parent $credentialPath) 'scope-truth.json'
if (-not (Test-Path -LiteralPath $scopeTruthPath -PathType Leaf)) { throw 'v4_scope_truth_missing' }
Assert-IgnoredUntracked $scopeTruthPath 'v4_scope_truth_not_private'
Assert-ClassArchiveOwnerOnlyFileAcl -Path $scopeTruthPath
try { $credential = Get-Content -LiteralPath $credentialPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { throw 'v4_scope_credential_invalid' }
if ($credential.version -ne 1 -or $credential.environment -ne 'synthetic') { throw 'v4_scope_credential_not_synthetic' }
$familyDeniedPhotoId = [string]$credential.familyDeniedPhotoId
if ($familyDeniedPhotoId -notmatch '^[0-9a-f-]{36}$') { throw 'v4_scope_family_denied_photo_invalid' }

$userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
$deps = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies'
$node = Join-Path $deps 'node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_scope_node_unavailable' }

$run = New-RunId
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\browser\photos-app-v4-chrome-scope-projection'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\screenshots\photos-app-v4-scope-projection'))
$profile = [IO.Path]::GetFullPath((Join-Path $profileRoot $run))
$screenshots = [IO.Path]::GetFullPath((Join-Path $screenshotRoot $run))
$names = @('NODE_PATH','CLASS_ARCHIVE_V4_SCOPE_CREDENTIAL_FILE','CLASS_ARCHIVE_V4_SCOPE_TRUTH_FILE','CLASS_ARCHIVE_V4_SCOPE_PIWIGO_ORIGIN','CLASS_ARCHIVE_V4_SCOPE_PHOTO_ORIGIN','CLASS_ARCHIVE_V4_SCOPE_USER_DATA_ROOT','CLASS_ARCHIVE_V4_SCOPE_SCREENSHOT_DIR','CLASS_ARCHIVE_V4_SCOPE_REQUIRE_PEOPLE')
$old = @{}
$scopeFixtureAttempted = $false
$result = $null
$baselineResult = $null
$phaseAMutationLease = $null
$phaseAMutationLeaseOwned = $false

try {
    if ([string]::IsNullOrWhiteSpace($ExternalPhaseALeaseToken)) {
        $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'scope-projection'
        $phaseAMutationLeaseOwned = $true
    }
    else {
        $phaseAMutationLease = Assert-V4SyntheticPhaseAExternalLease -ProjectRoot $projectRoot -Token $ExternalPhaseALeaseToken -ExpectedPurpose 'scope-people-lifecycle'
    }
    foreach ($directory in @($profile, $screenshots)) {
        $base = if ($directory -eq $profile) { $profileRoot } else { $screenshotRoot }
        Assert-ChildPath $base $directory 'v4_scope_run_path_invalid'
        if (Test-Path -LiteralPath $directory) { throw 'v4_scope_run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($directory)
        Assert-IgnoredUntracked $directory 'v4_scope_output_not_private'
    }
    Assert-SyntheticBaseline 'v4_scope_baseline_before_failed'
    # Set this before the command: the fixture intentionally retains its exact
    # repair state and global lock after any mid-prepare failure, and cleanup
    # must be attempted even when no successful JSON response was received.
    $scopeFixtureAttempted = $true
    $unknownFixture = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-scope-unknown-fixture.php','prepare',$run,$familyDeniedPhotoId) 'v4_scope_unknown_fixture_prepare_failed'
    if ($unknownFixture.prepared -ne $true -or [string]$unknownFixture.unknown_photo_id -notmatch '^[0-9a-f-]{36}$' -or @($unknownFixture.unknown_archive_photo_ids).Count -lt 1) { throw 'v4_scope_unknown_fixture_prepare_invalid' }
    Update-ScopeTruth -Path $scopeTruthPath -UnknownPhotoId ([string]$unknownFixture.unknown_photo_id) -UnknownArchivePhotoIds @($unknownFixture.unknown_archive_photo_ids)
    foreach ($name in $names) { $item = Get-Item "Env:$name" -ErrorAction SilentlyContinue; $old[$name] = if ($null -eq $item) { $null } else { $item.Value } }
    $env:NODE_PATH = Join-Path $deps 'node\node_modules'
    $env:CLASS_ARCHIVE_V4_SCOPE_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_SCOPE_TRUTH_FILE = $scopeTruthPath
    $env:CLASS_ARCHIVE_V4_SCOPE_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'
    $env:CLASS_ARCHIVE_V4_SCOPE_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'
    $env:CLASS_ARCHIVE_V4_SCOPE_USER_DATA_ROOT = $profile
    $env:CLASS_ARCHIVE_V4_SCOPE_SCREENSHOT_DIR = $screenshots
    $env:CLASS_ARCHIVE_V4_SCOPE_REQUIRE_PEOPLE = '1'
    $output = @(& $node (Join-Path $PSScriptRoot 'photos-app-v4-chrome-scope-projection.mjs') 2>&1); $exit = $LASTEXITCODE
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_SCOPE_STAGE=[a-z0-9_-]+$' -or $_ -match '^V4_SCOPE_PROJECTION=(PASS assertions=[0-9]+ screenshots=[0-9]+ chrome_version=[0-9.]+ people_required=yes|FAIL stage=[a-z0-9_-]+ assertions=[0-9]+ code=[a-z0-9_]+)$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_SCOPE_PROJECTION=PASS\b' })
    if ($exit -ne 0 -or $pass.Count -ne 1) { throw 'v4_scope_browser_failed' }
    $result = [string]$pass[0]
} finally {
    try {
        foreach ($name in $names) { Remove-Item "Env:$name" -ErrorAction SilentlyContinue; if ($null -ne $old[$name]) { Set-Item "Env:$name" -Value $old[$name] } }
        if ($scopeFixtureAttempted) {
            $cleanup = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-scope-unknown-fixture.php','cleanup',$run) 'v4_scope_unknown_fixture_cleanup_failed'
            if ($cleanup.restored -ne $true -or ($cleanup.cleaned -ne $true -and $cleanup.absent -ne $true)) { throw 'v4_scope_unknown_fixture_cleanup_invalid' }
        }
        Assert-SyntheticBaseline 'v4_scope_baseline_after_failed'
        $baselineResult = 'V4_SCOPE_SYNTHETIC_BASELINE=PASS images=72 originals=72 multi_album=8'
        # The profile has no user data and is exactly under this run's ignored root.
        if (Test-Path -LiteralPath $profile) {
            $item = Get-Item -LiteralPath $profile -Force
            $resolved = (Resolve-Path -LiteralPath $profile).Path
            if (-not $resolved.StartsWith($profileRoot + $separator, [StringComparison]::OrdinalIgnoreCase) -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'v4_scope_profile_cleanup_boundary' }
            Remove-Item -LiteralPath $profile -Recurse -Force -ErrorAction Stop
            if (Test-Path -LiteralPath $profile) { throw 'v4_scope_profile_cleanup_failed' }
        }
    }
    finally {
        if ($phaseAMutationLeaseOwned -and $null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
}
if ([string]::IsNullOrWhiteSpace($result) -or [string]::IsNullOrWhiteSpace($baselineResult)) { throw 'v4_scope_result_or_baseline_missing' }
Write-Output $result
Write-Output ('V4_SCOPE_SCREENSHOTS=' + $screenshots)
Write-Output $baselineResult
# This terminal record is produced only after the fixture, baseline and
# dedicated Chrome profile cleanup all succeeded.
Write-Output 'V4_SCOPE_PROJECTION_COMPLETE=PASS'
