[CmdletBinding()]
param()

# Static-only contract for the host-side Synthetic 8091 V4 acceptance lease.
# It starts neither Docker nor Chrome and does not open any ignored credential.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$helperPath = Join-Path $projectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1'
$wrappers = [ordered]@{
    chrome_main = @{ path = (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-qa.ps1'); purpose = 'chrome-main'; root = '$projectRoot'; completion = 'V4_CHROME_QA_COMPLETE=PASS' }
    deep_viewer = @{ path = (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-deep-qa.ps1'); purpose = 'deep-viewer'; root = '$projectRoot'; completion = 'V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS' }
    scope_projection = @{ path = (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-scope-projection.ps1'); purpose = 'scope-projection'; root = '$projectRoot'; completion = 'V4_SCOPE_PROJECTION_COMPLETE=PASS' }
    upload_lifecycle = @{ path = (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-upload-lifecycle.ps1'); purpose = 'upload-lifecycle'; root = '$projectRoot'; completion = 'V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS' }
    cold_restart = @{ path = (Join-Path $projectRoot 'tests\phase3\photos-app-v4-synthetic-cold-restart.ps1'); purpose = 'cold-restart'; root = '$script:ProjectRoot'; completion = 'V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS' }
}
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}
function Assert-PowerShellParse([string]$Path, [string]$Code) {
    $tokens = $null; $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile($Path, [ref]$tokens, [ref]$errors)
    Assert-True ($errors.Count -eq 0) $Code
}

$helper = Read-Source $helperPath 'v4_phase_a_lease_helper_missing'
Assert-PowerShellParse $helperPath 'v4_phase_a_lease_helper_parse_invalid'

# The marker is local, ignored and owner-only. CreateNew is an atomic refusal
# of a concurrent/stale lease; Enter never deletes an existing marker.
foreach ($token in @(
    "'.codex-work'", "'mutation.lock'", 'check-ignore --quiet --no-index',
    'git -C $ProjectRoot ls-files', 'ReparsePoint',
    'Set-ClassArchiveOwnerOnlyFileAcl', 'Assert-ClassArchiveOwnerOnlyFileAcl',
    '[IO.FileMode]::CreateNew', '[IO.FileShare]::None',
    '[Security.Cryptography.RandomNumberGenerator]::Create()',
    'v4_synthetic_phase_a_lease_present_or_stale',
    'v4_synthetic_phase_a_lease_present_or_ambiguous',
    'v4_synthetic_phase_a_lease_initialization_ambiguous'
)) {
    Assert-True ($helper.Contains($token)) ('v4_phase_a_lease_safety_token_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ([regex]::Matches($helper, 'Remove-Item -LiteralPath \$location\.lease_path').Count -eq 1) 'v4_phase_a_lease_unexpected_marker_removal'
Assert-True ($helper.Contains('function Assert-V4SyntheticPhaseAExternalLease') -and $helper.Contains('process_started_at') -and $helper.Contains('Get-Process -Id') -and $helper.Contains('v4_synthetic_phase_a_external_lease_stale')) 'v4_phase_a_lease_external_stale_validation_missing'
Assert-True ($helper.Contains('v4_synthetic_phase_a_external_lease_token_or_purpose_invalid') -and $helper.Contains('ExpectedPurpose')) 'v4_phase_a_lease_external_token_purpose_validation_missing'
$privateSourceDrivePrefix = ('M' + [char]58 + [char]92)
foreach ($forbidden in @('Stop-Process', 'Remove-Item -Recurse', '8191', '8190', $privateSourceDrivePrefix, '0.0.0.0')) {
    Assert-True (-not $helper.Contains($forbidden)) ('v4_phase_a_lease_forbidden_token_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

foreach ($entry in $wrappers.GetEnumerator()) {
    $source = Read-Source $entry.Value.path ('v4_phase_a_lease_wrapper_missing_' + $entry.Key)
    Assert-PowerShellParse $entry.Value.path ('v4_phase_a_lease_wrapper_parse_invalid_' + $entry.Key)
    Assert-True ($source.Contains('v4-synthetic-phase-a-lease.ps1')) ('v4_phase_a_lease_helper_import_missing_' + $entry.Key)
    Assert-True ($source.Contains(('Enter-V4SyntheticPhaseAMutationLease -ProjectRoot ' + $entry.Value.root + " -Purpose '$($entry.Value.purpose)'"))) ('v4_phase_a_lease_enter_missing_' + $entry.Key)
    Assert-True ($source.Contains('Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease')) ('v4_phase_a_lease_exit_missing_' + $entry.Key)
    $completion = $source.IndexOf($entry.Value.completion, [StringComparison]::Ordinal)
    $exit = $source.LastIndexOf('Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease', [StringComparison]::Ordinal)
    Assert-True ($exit -ge 0 -and $completion -gt $exit) ('v4_phase_a_lease_release_before_completion_missing_' + $entry.Key)
}

$scope = [IO.File]::ReadAllText($wrappers.scope_projection.path)
Assert-True ($scope.Contains('[string]$ExternalPhaseALeaseToken') -and $scope.Contains("Assert-V4SyntheticPhaseAExternalLease -ProjectRoot `$projectRoot -Token `$ExternalPhaseALeaseToken -ExpectedPurpose 'scope-people-lifecycle'") -and $scope.Contains('$phaseAMutationLeaseOwned')) 'v4_phase_a_lease_scope_external_delegation_missing'
Assert-True ($scope.IndexOf("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$projectRoot -Purpose 'scope-projection'", [StringComparison]::Ordinal) -lt $scope.IndexOf("'prepare',`$run,`$familyDeniedPhotoId", [StringComparison]::Ordinal)) 'v4_phase_a_lease_scope_enter_order_invalid'

$cold = [IO.File]::ReadAllText($wrappers.cold_restart.path)
$coldEnter = $cold.IndexOf("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$script:ProjectRoot -Purpose 'cold-restart'", [StringComparison]::Ordinal)
$coldBaseline = $cold.IndexOf("Assert-SyntheticBaseline 'v4_synthetic_cold_restart_baseline_before_failed'", [StringComparison]::Ordinal)
$coldExit = $cold.LastIndexOf('Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease', [StringComparison]::Ordinal)
Assert-True ($coldEnter -ge 0 -and $coldBaseline -gt $coldEnter -and $coldExit -gt $coldBaseline) 'v4_phase_a_lease_cold_restart_lifecycle_order_invalid'

Write-Output "V4_SYNTHETIC_PHASE_A_LEASE_PROTOCOL=PASS assertions=$assertions"
