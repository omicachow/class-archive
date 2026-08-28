[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$CredentialFile,

    # The wrapper changes only two disposable MANUAL ClassArchivePerson rows
    # in Synthetic 8091, then removes them in finally.  Make that narrow
    # mutation an explicit operator decision rather than an implicit Chrome
    # side effect.
    [Parameter(Mandatory = $true)]
    [switch]$ConfirmSyntheticMutation
)

# Runtime prerequisite for the independent V4 scope-projection Chrome gate.
# It prepares a reversible, non-empty synthetic People projection, delegates
# browser evidence to the existing scope runner, and prints the delegated PASS
# only after its own fixture cleanup and 72/72/8 baseline check. It never
# starts/stops Docker, reads a credential value, accesses an Owner/private
# runtime, or touches media/Immich state.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$workRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work'))
$separator = [IO.Path]::DirectorySeparatorChar
. (Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
$credentialPath = (Resolve-Path -LiteralPath $CredentialFile).Path
$scopeRunner = Join-Path $PSScriptRoot 'photos-app-v4-chrome-scope-projection.ps1'
$compose = @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml')

function Assert-ChildPath([string]$Base, [string]$Target, [string]$Code) {
    $relative = Get-V4SyntheticPhaseARelativePath -Base $Base -Target $Target
    if ([string]::IsNullOrWhiteSpace($relative) -or $relative -eq '..' -or $relative.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or [IO.Path]::IsPathRooted($relative)) { throw $Code }
}
function Assert-IgnoredUntracked([string]$Path, [string]$Code) {
    Assert-ChildPath $projectRoot $Path $Code
    $relative = $Path.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { throw $Code }
}
function New-RunId {
    $bytes = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
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
    $state = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-upload-lifecycle-fixture.php','baseline') 'v4_scope_people_baseline_fixture_failed'
    if ([int]$state.images -ne 72 -or [int]$state.active_canonical -ne 72 -or [int]$state.physical_originals -ne 72 -or [int]$state.multi_album_images -ne 8) { throw $Code }
}
function Invoke-ScopeRunner([string]$Path, [string]$Credential, [string]$ExternalPhaseALeaseToken) {
    $prior = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Path -CredentialFile $Credential -ExternalPhaseALeaseToken $ExternalPhaseALeaseToken 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $prior }
    $safe = @($lines | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_SCOPE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_SCOPE_PROJECTION=(PASS assertions=[0-9]+ screenshots=[0-9]+ chrome_version=[0-9.]+ people_required=yes|FAIL stage=[a-z0-9_-]+ assertions=[0-9]+ code=[a-z0-9_]+)$' -or
        $_ -match '^V4_SCOPE_PROJECTION_COMPLETE=PASS$' -or
        $_ -match '^v4_scope_[a-z0-9_]{1,100}$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_SCOPE_PROJECTION=PASS\b' })
    $completion = @($safe | Where-Object { $_ -eq 'V4_SCOPE_PROJECTION_COMPLETE=PASS' })
    $failureRecords = @($safe | Where-Object { $_ -match '^V4_SCOPE_PROJECTION=FAIL stage=[a-z0-9_-]+ assertions=[0-9]+ code=[a-z0-9_]+$' })
    $innerFailureCodes = @($safe | Where-Object { $_ -match '^v4_scope_[a-z0-9_]{1,100}$' })
    if ($exit -ne 0 -or $pass.Count -ne 1 -or $completion.Count -ne 1) {
        # Preserve only the runner's pre-sanitized stage/code in the terminal
        # failure. Raw browser output can contain URL or page details and must
        # remain inside ignored local evidence, never the test transcript.
        if ($failureRecords.Count -eq 1 -and $failureRecords[0] -match ' code=([a-z0-9_]+)$') {
            throw ('v4_scope_people_delegated_scope_failed_' + $Matches[1])
        }
        if ($innerFailureCodes.Count -eq 1) {
            throw ('v4_scope_people_delegated_scope_failed_' + $innerFailureCodes[0])
        }
        throw 'v4_scope_people_delegated_scope_failed'
    }
    return [ordered]@{ pass = [string]$pass[0]; completion = [string]$completion[0] }
}

if (-not $ConfirmSyntheticMutation) { throw 'v4_scope_people_confirmation_required' }
if (-not (Test-Path -LiteralPath $scopeRunner -PathType Leaf)) { throw 'v4_scope_people_scope_runner_missing' }
Assert-ChildPath $workRoot $credentialPath 'v4_scope_people_credential_outside_work_root'
Assert-IgnoredUntracked $credentialPath 'v4_scope_people_credential_not_private'
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath

$run = New-RunId
$fixtureAttempted = $false
$fixturePrepared = $false
$scopeEvidence = $null
$baseline = $false
$phaseAMutationLease = $null

try {
    # Hold this host lease across the brief handoff where the People fixture
    # must release the container mutation lock for the delegated UNKNOWN-era
    # scope fixture. The child validates this exact live token and never owns
    # or removes it, so another Phase-A mutation cannot interleave.
    $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $projectRoot -Purpose 'scope-people-lifecycle'
    Assert-SyntheticBaseline 'v4_scope_people_baseline_before_failed'
    # Set this before invoking prepare: a process-level failure can leave the
    # exact recovery state behind, and finally must attempt its bounded cleanup.
    $fixtureAttempted = $true
    $prepared = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SCOPE_PEOPLE_FIXTURE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-scope-people-fixture.php','prepare',$run) 'v4_scope_people_fixture_prepare_failed'
    if ($prepared.prepared -ne $true -or [int]$prepared.people -ne 2 -or [string]$prepared.scope -ne 'SYNTHETIC_8091') { throw 'v4_scope_people_fixture_prepare_invalid' }
    $fixturePrepared = $true
    $scopeEvidence = Invoke-ScopeRunner $scopeRunner $credentialPath ([string]$phaseAMutationLease.Token)
}
finally {
    try {
        if ($fixtureAttempted) {
            $cleanup = Get-JsonOutput @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SCOPE_PEOPLE_FIXTURE=1','piwigo','php','/workspace/tests/phase3/photos-app-v4-scope-people-fixture.php','cleanup',$run) 'v4_scope_people_fixture_cleanup_failed'
            if ($fixturePrepared -and ($cleanup.cleaned -ne $true -or [int]$cleanup.people -ne 2 -or [string]$cleanup.scope -ne 'SYNTHETIC_8091')) { throw 'v4_scope_people_fixture_cleanup_invalid' }
            if (-not $fixturePrepared -and ($cleanup.cleaned -ne $true -and $cleanup.absent -ne $true)) { throw 'v4_scope_people_fixture_cleanup_invalid' }
        }
        Assert-SyntheticBaseline 'v4_scope_people_baseline_after_failed'
        $baseline = $true
    }
    finally {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
}

if ($null -eq $scopeEvidence -or -not $baseline) { throw 'v4_scope_people_result_or_baseline_missing' }
# The normalizer intentionally keeps only these two predeclared safe records.
# They are emitted after both the delegated scope fixture and this People
# lifecycle have been cleaned, so the existing terminal record remains valid.
Write-Output ([string]$scopeEvidence.pass)
Write-Output ([string]$scopeEvidence.completion)
