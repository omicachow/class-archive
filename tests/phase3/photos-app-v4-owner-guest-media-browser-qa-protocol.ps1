[CmdletBinding()]
param()

# Static-only contract for the coordinator which joins an authenticated FQA
# Classmate proof to an unauthenticated Guest media proof. It must neither
# access Docker/DB nor carry a credential, UUID, or private media URL itself.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$coordinatorPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-guest-media-browser-qa.ps1'
$fqaPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-browser-qa.ps1'
$guestPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-guest-browser-qa.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True $Text.Contains($Needle) $Code
}
function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True (-not $Text.Contains($Needle)) $Code
}

foreach ($path in @($coordinatorPath, $fqaPath, $guestPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('guest_media_coordinator_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}

$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($coordinatorPath, [ref]$tokens, [ref]$errors)
Assert-True (@($errors).Count -eq 0) 'guest_media_coordinator_parse_invalid'
$source = [IO.File]::ReadAllText($coordinatorPath, [Text.UTF8Encoding]::new($false, $true))

Assert-Contains $source 'ConfirmOwnerGuestMediaAcceptance' 'guest_media_coordinator_explicit_confirmation_missing'
Assert-Contains $source 'explicit_owner_guest_media_confirmation_required' 'guest_media_coordinator_confirmation_gate_missing'
Assert-Contains $source 'photos-app-v4-owner-browser-qa.ps1' 'guest_media_coordinator_fqa_wrapper_missing'
Assert-Contains $source 'photos-app-v4-owner-guest-browser-qa.ps1' 'guest_media_coordinator_guest_wrapper_missing'
Assert-Contains $source 'New-V4OwnerGuestMediaRunId' 'guest_media_coordinator_fresh_probe_name_missing'
Assert-Contains $source "'owner-fqa-'" 'guest_media_coordinator_probe_prefix_missing'
Assert-Contains $source 'Assert-V4OwnerGuestMediaIgnored' 'guest_media_coordinator_ignored_probe_guard_missing'
Assert-Contains $source 'Assert-ClassArchiveOwnerOnlyFileAcl' 'guest_media_coordinator_probe_acl_guard_missing'
Assert-Contains $source 'Remove-V4OwnerGuestMediaProbe' 'guest_media_coordinator_probe_cleanup_missing'
Assert-Contains $source "'-GuestMediaProbeDocument'" 'guest_media_coordinator_fqa_handoff_missing'
Assert-Contains $source "'-MediaProbeDocument'" 'guest_media_coordinator_guest_handoff_missing'
Assert-Contains $source 'V4_OWNER_GUEST_MEDIA_CHROME_QA=PASS fqa=PASS guest=PASS media=GET_HEAD_RANGE' 'guest_media_coordinator_sanitized_pass_missing'
Assert-Contains $source 'Invoke-ClassArchiveBoundedNative' 'guest_media_coordinator_bounded_child_process_missing'
Assert-Contains $source '$fqa.Stdout' 'guest_media_coordinator_fqa_compact_parse_missing'
Assert-Contains $source '$guest.Stdout' 'guest_media_coordinator_guest_compact_parse_missing'
Assert-NotContains $source 'Write-Output $probePath' 'guest_media_coordinator_probe_path_output_forbidden'

$privateDrivePrefix = ([char]77).ToString() + ':' + ([char]92).ToString()
foreach ($forbidden in @(
    'docker', 'wsl.exe', 'compose', 'mysql', 'postgres', 'Set-Content', 'Out-File',
    '0.0.0.0', 'https://', $privateDrivePrefix, 'source_root', 'relative_source_path',
    'original_filename', 'password', 'token', 'writeFileSync'
)) {
    Assert-NotContains $source $forbidden ('guest_media_coordinator_forbidden_' + (($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant()))
}

Write-Output "V4_OWNER_GUEST_MEDIA_CHROME_QA_PROTOCOL=PASS assertions=$assertions"
