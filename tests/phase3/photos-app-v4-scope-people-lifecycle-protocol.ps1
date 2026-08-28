[CmdletBinding()]
param()

# Static-only contract for the synthetic People prerequisite which wraps the
# existing V4 scope Chrome runner. It does not start Docker/Chrome, open a
# credential file, or contact 8091/8191.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$fixturePath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-scope-people-fixture.php'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-scope-people-lifecycle.ps1'
$scopeWrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-scope-projection.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-scope-people-lifecycle.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$fixture = Read-Source $fixturePath 'v4_scope_people_fixture_missing'
$wrapper = Read-Source $wrapperPath 'v4_scope_people_wrapper_missing'
$scopeWrapper = Read-Source $scopeWrapperPath 'v4_scope_people_delegated_wrapper_missing'
$docs = Read-Source $docsPath 'v4_scope_people_docs_missing'
$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'v4_scope_people_wrapper_parse_invalid'

# The lifecycle is deliberately a synthetic, CLI/nginx-only test fixture. It
# must stay under a separate state/lock namespace, require the explicit gate,
# and never create media or alter Piwigo category associations.
Assert-True ($fixture.StartsWith('<?php') -and $fixture.Contains('declare(strict_types=1);') -and $fixture.Contains("getenv('CLASS_ARCHIVE_V4_SCOPE_PEOPLE_FIXTURE') !== '1'") -and $fixture.Contains("const V4PL_ROOT = '/var/www/html/piwigo'") -and $fixture.Contains('posix_geteuid() === 0')) 'v4_scope_people_fixture_runtime_gate_missing'
Assert-True ($fixture.Contains("'/tmp/class-archive-v4-scope-people-'") -and $fixture.Contains("'/tmp/class-archive-v4-scope.lock'") -and -not $fixture.Contains("'/tmp/class-archive-v4-scope-people.lock'") -and $fixture.Contains("fopen(`$path, 'x')") -and $fixture.Contains('chmod($path, 0600)') -and $fixture.Contains('v4plAcquireLock') -and $fixture.Contains('v4plAcquireCleanupLock') -and $fixture.Contains('v4plReleaseLock')) 'v4_scope_people_fixture_shared_projection_lock_missing'
Assert-True ($fixture.Contains('V4PL_PEOPLE = 2') -and $fixture.Contains("'HERITAGE'") -and $fixture.Contains("'LIVING'") -and $fixture.Contains('v4plSelectRoleScopedPhotos') -and $fixture.Contains('v4plAssertPhotoEras')) 'v4_scope_people_fixture_two_scope_data_missing'
Assert-True ($fixture.Contains("'MANUAL'") -and $fixture.Contains("'INCLUDE'") -and $fixture.Contains('manual_cover_class_photo_id') -and $fixture.Contains('immich_person_id') -and $fixture.Contains("`$row['immich_person_id'] !== null") -and $fixture.Contains('v4plAssertNoPreexistingManualPeople') -and $fixture.Contains('v4plOwnedStateStatus')) 'v4_scope_people_fixture_exact_ownership_missing'
Assert-True ($fixture.Contains('v4plInsertOwnedState') -and $fixture.Contains('v4plDeleteOwnedState') -and $fixture.Contains('v4plRebuildProjection') -and $fixture.Contains('ReadProjectionBuilder::rebuild') -and $fixture.Contains('v4plAssertSyntheticBaseline')) 'v4_scope_people_fixture_projection_lifecycle_missing'
Assert-True ($fixture.Contains('person_photo_rule') -and $fixture.Contains('person_merge') -and $fixture.Contains('updated_by_principal_id') -and $fixture.Contains('system_admin_principal_invalid')) 'v4_scope_people_fixture_foreign_key_boundary_missing'
Assert-True ($fixture.IndexOf('v4plAcquireLock($run);', [StringComparison]::Ordinal) -lt $fixture.IndexOf('v4plAssertNoPreexistingManualPeople($repository);', [StringComparison]::Ordinal) -and $fixture.Contains('no database mutation can have occurred') -and $fixture.Contains("'absent' => true")) 'v4_scope_people_fixture_lock_recheck_or_recovery_missing'
foreach ($forbidden in @('delete_elements(', 'add_photos_to_category', 'associate_images_to_categories', 'move_image_to_categories', 'media_reference`=', '8191', '8190', 'private-real', 'immich_asset_id`=')) {
    Assert-True (-not $fixture.Contains($forbidden)) ('v4_scope_people_fixture_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The PowerShell wrapper invokes only the existing public synthetic scope
# wrapper. It neither starts/stops services nor reads/logs credentials; exact
# cleanup and the baseline precede forwarding the delegated terminal record.
Assert-True ($wrapper.Contains('[switch]$ConfirmSyntheticMutation') -and $wrapper.Contains("'v4_scope_people_confirmation_required'") -and $wrapper.Contains('Assert-IgnoredUntracked $credentialPath') -and $wrapper.Contains('Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath') -and -not $wrapper.Contains('Get-Content -LiteralPath $credentialPath')) 'v4_scope_people_credential_boundary_missing'
Assert-True ($wrapper.Contains("'http://127.0.0.1:8090/'") -eq $false -and $wrapper.Contains("'http://127.0.0.1:8091/'") -eq $false -and $wrapper.Contains("'infra/docker-compose.yml'") -and $wrapper.Contains("'CLASS_ARCHIVE_V4_SCOPE_PEOPLE_FIXTURE=1'")) 'v4_scope_people_public_synthetic_compose_missing'
Assert-True ($wrapper.Contains("v4-synthetic-phase-a-lease.ps1") -and $wrapper.Contains("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$projectRoot -Purpose 'scope-people-lifecycle'") -and $wrapper.Contains('Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease')) 'v4_scope_people_outer_phase_a_lease_missing'
Assert-True ($wrapper.Contains('photos-app-v4-chrome-scope-projection.ps1') -and $wrapper.Contains('Invoke-ScopeRunner') -and $wrapper.Contains('-ExternalPhaseALeaseToken $ExternalPhaseALeaseToken') -and $wrapper.Contains('([string]$phaseAMutationLease.Token)') -and $wrapper.Contains('people_required=yes') -and $wrapper.Contains('V4_SCOPE_PROJECTION_COMPLETE=PASS')) 'v4_scope_people_delegated_scope_contract_missing'
Assert-True ($wrapper.Contains('Assert-SyntheticBaseline') -and $wrapper.Contains("'v4_scope_people_baseline_before_failed'") -and $wrapper.Contains("'v4_scope_people_baseline_after_failed'") -and $wrapper.Contains('active_canonical') -and $wrapper.Contains('multi_album_images')) 'v4_scope_people_baseline_bracket_missing'
Assert-True ($wrapper.Contains("'prepare',`$run") -and $wrapper.Contains("'cleanup',`$run") -and $wrapper.Contains('$fixtureAttempted = $true') -and $wrapper.Contains('$fixturePrepared = $true')) 'v4_scope_people_fixture_command_lifecycle_missing'
$leaseEnter = $wrapper.IndexOf("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$projectRoot -Purpose 'scope-people-lifecycle'", [StringComparison]::Ordinal)
$baselineBefore = $wrapper.IndexOf("Assert-SyntheticBaseline 'v4_scope_people_baseline_before_failed'", [StringComparison]::Ordinal)
$baselineAfter = $wrapper.LastIndexOf("Assert-SyntheticBaseline 'v4_scope_people_baseline_after_failed'", [StringComparison]::Ordinal)
$leaseExit = $wrapper.IndexOf('Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease', [StringComparison]::Ordinal)
$completion = $wrapper.IndexOf('Write-Output ([string]$scopeEvidence.completion)', [StringComparison]::Ordinal)
Assert-True ($leaseEnter -ge 0 -and $baselineBefore -gt $leaseEnter -and $baselineAfter -gt $baselineBefore -and $leaseExit -gt $baselineAfter -and $completion -gt $leaseExit) 'v4_scope_people_lease_must_span_cleanup_and_terminal_record'
foreach ($forbidden in @(' up ', ' down ', ' stop ', '8191', '8190', 'private-real', 'Start-Process', 'chromium.launch', 'Get-Content -LiteralPath $credentialPath')) {
    Assert-True (-not $wrapper.Contains($forbidden)) ('v4_scope_people_wrapper_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($scopeWrapper.Contains('CLASS_ARCHIVE_V4_SCOPE_REQUIRE_PEOPLE') -and $scopeWrapper.Contains("'v4_scope_baseline_after_failed'")) 'v4_scope_people_delegated_wrapper_scope_missing'

foreach ($term in @('STATIC', 'RUNTIME_TESTED', 'BROWSER_E2E_TESTED', 'SYNTHETIC 8091', '72 / 72 / 8', 'MANUAL', 'not Immich face detection', 'does not start Docker', '8191', 'finally')) {
    Assert-True ($docs.Contains($term)) ('v4_scope_people_docs_boundary_missing_' + ($term -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_SCOPE_PEOPLE_LIFECYCLE_PROTOCOL=PASS assertions=$assertions"
