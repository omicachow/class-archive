[CmdletBinding()]
param()

# Static and no-switch safety contract. It never launches Docker, PHP, Chrome,
# or HTTP and therefore cannot unfreeze the 8191 marker aggregate.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path (Join-Path $PSScriptRoot '..') '..')).Path
$wrapperPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-browser-qa.ps1'
$runnerPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-browser-qa.mjs'
$leaseProtocolPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-fqa-lease-protocol.ps1'
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

foreach ($path in @($wrapperPath, $runnerPath, $leaseProtocolPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('owner_fqa_browser_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}

$currentPowerShell = (Get-Process -Id $PID -ErrorAction Stop).Path
$leaseOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $leaseProtocolPath 2>&1)
Assert-True ($LASTEXITCODE -eq 0) 'owner_fqa_lease_protocol_failed'
Assert-True (@($leaseOutput | Where-Object { [string]$_ -match '^PHOTOS_APP_V4_OWNER_FQA_LEASE_PROTOCOL=PASS assertions=[0-9]+$' }).Count -eq 1) 'owner_fqa_lease_protocol_record_missing'

$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$errors)
Assert-True ($errors.Count -eq 0) 'owner_fqa_wrapper_parse_invalid'
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
Assert-True (-not [string]::IsNullOrWhiteSpace($node)) 'owner_fqa_node_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'owner_fqa_runner_parse_invalid'

$wrapper = [IO.File]::ReadAllText($wrapperPath)
$runner = [IO.File]::ReadAllText($runnerPath)

Assert-Contains $wrapper 'infra/private-full/.env.piwigo.owner' 'owner_fqa_full_v3_env_missing'
Assert-Contains $wrapper 'class_archive_private_full_v3_piwigo' 'owner_fqa_full_v3_project_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\browser\photos-app-v4-owner-fqa-lease' 'owner_fqa_profile_root_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\screenshots\photos-app-v4' 'owner_fqa_screenshot_root_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8190/'" 'owner_fqa_core_origin_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8191/'" 'owner_fqa_photo_origin_missing'
Assert-Contains $wrapper 'roles=3' 'owner_fqa_wrapper_role_count_missing'
Assert-NotContains $wrapper 'fixture-teacher' 'owner_fqa_wrapper_teacher_forbidden'
Assert-NotContains $wrapper 'ConfirmExistingFixtureCredentialRotation' 'owner_fqa_legacy_switch_forbidden'

Assert-Contains $runner "const roles = Object.freeze(['classmate', 'family', 'anonymous'])" 'owner_fqa_runner_roles_invalid'
Assert-Contains $runner "const fullRoles = Object.freeze(['classmate', 'anonymous'])" 'owner_fqa_runner_full_roles_invalid'
Assert-Contains $runner "credentials.lease?.roster === 'FQA-C-99CA3B3B6AF1'" 'owner_fqa_runner_candidate_binding_missing'
Assert-Contains $runner "value?.username === 'fqa_99ca3b3b6af1_classmate'" 'owner_fqa_runner_classmate_binding_missing'
Assert-Contains $runner "value?.username === 'fqa_99ca3b3b6af1_family'" 'owner_fqa_runner_family_binding_missing'
Assert-Contains $runner '/^anon_[a-f0-9]{20}$/' 'owner_fqa_runner_anonymous_binding_missing'
Assert-Contains $runner 'leasedUsernames' 'owner_fqa_runner_dynamic_redaction_missing'
Assert-NotContains $runner "'teacher'" 'owner_fqa_runner_teacher_role_forbidden'
Assert-NotContains $runner 'fixture-teacher' 'owner_fqa_runner_teacher_fixture_forbidden'
Assert-Contains $runner 'chromium.launchPersistentContext' 'owner_fqa_persistent_context_missing'
Assert-Contains $runner "channel: 'chrome'" 'owner_fqa_chrome_channel_missing'
Assert-Contains $runner 'headless: false' 'owner_fqa_headed_chrome_missing'
Assert-Contains $runner "context.route('**/*'" 'owner_fqa_network_route_guard_missing'
Assert-Contains $runner 'CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS' 'owner_fqa_process_network_guard_missing'
Assert-Contains $runner 'isBusinessMutation' 'owner_fqa_business_mutation_guard_missing'
Assert-Contains $runner "target.pathname === '/api/class-archive/comments/create'" 'owner_fqa_family_denial_probe_missing'
Assert-Contains $runner 'denied?.state === 200 && denied?.status === 403' 'owner_fqa_family_comment_server_denial_missing'
Assert-Contains $runner 'afterDigest === beforeDigest' 'owner_fqa_family_comment_no_write_missing'
Assert-Contains $runner 'completeCommentDigest' 'owner_fqa_family_comment_complete_digest_missing'
Assert-Contains $runner 'successfulBusinessWrites === 0' 'owner_fqa_zero_content_write_assertion_missing'
Assert-Contains $runner "home?.scope === 'FULL'" 'owner_fqa_full_scope_missing'
Assert-Contains $runner "home?.scope === 'HERITAGE_ONLY'" 'owner_fqa_heritage_scope_missing'
Assert-Contains $runner 'family_known_living_media_denied' 'owner_fqa_living_media_denial_missing'
Assert-Contains $runner "method: 'HEAD'" 'owner_fqa_head_probe_missing'
Assert-Contains $runner "Range: 'bytes=0-31'" 'owner_fqa_range_probe_missing'
Assert-Contains $runner '[`/api/assets/${livingId}`, { method: ''GET'', headers: { Range: ''bytes=0-31'' } }]' 'owner_fqa_original_range_probe_missing'
Assert-Contains $runner 'family_album_counts' 'owner_fqa_album_count_scope_missing'
Assert-Contains $runner 'family_people_counts' 'owner_fqa_people_count_scope_missing'
Assert-Contains $runner 'albumDetailsComplete' 'owner_fqa_complete_album_counts_missing'
Assert-Contains $runner 'peopleDetailsComplete' 'owner_fqa_complete_people_counts_missing'
Assert-Contains $runner 'completeSearchPhotoIds' 'owner_fqa_complete_search_counts_missing'
Assert-Contains $runner 'payload.total === payload.items.length' 'owner_fqa_spotlight_count_binding_missing'
Assert-Contains $runner 'anonymous_api_identity_leak' 'owner_fqa_anonymous_api_redaction_missing'
Assert-Contains $runner 'anonymous_html_identity_leak' 'owner_fqa_anonymous_html_redaction_missing'
Assert-Contains $runner 'const markup = await page.content()' 'owner_fqa_anonymous_full_html_scan_missing'
Assert-Contains $runner "dialog.locator('.hybrid-results')" 'owner_fqa_search_rendered_results_missing'
Assert-Contains $runner "dialog.locator('.error-state').count() === 0" 'owner_fqa_search_error_state_rejected_missing'
Assert-Contains $runner 'roles=3' 'owner_fqa_safe_aggregate_record_missing'

foreach ($forbidden in @(
    'create_classmate', 'create_teacher', 'issue_claim', 'issue_family_invitation', 'accept_family',
    'activate_anonymous', 'freeze_identity', '/api/class-archive/member-upload', 'setInputFiles',
    'fs.copyFile', 'fs.cp', '0.0.0.0', 'https://'
)) {
    Assert-NotContains $runner $forbidden ('owner_fqa_runner_forbidden_' + (($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant()))
}

$previous = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $blockedOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $wrapperPath 2>&1)
    $blockedExit = $LASTEXITCODE
}
finally { $ErrorActionPreference = $previous }
$blockedLines = @($blockedOutput | ForEach-Object { [string]$_ })
Assert-True ($blockedExit -eq 3) 'owner_fqa_no_switch_exit_invalid'
Assert-True ($blockedLines.Count -eq 1 -and $blockedLines[0] -eq 'V4_OWNER_FQA_CHROME_QA=BLOCKED code=explicit_fqa_credential_lease_confirmation_required') 'owner_fqa_no_switch_output_invalid'

try {
    $ErrorActionPreference = 'Continue'
    $confirmedBlockedOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $wrapperPath -ConfirmFqaCredentialLease 2>&1)
    $confirmedBlockedExit = $LASTEXITCODE
}
finally { $ErrorActionPreference = $previous }
$confirmedBlockedLines = @($confirmedBlockedOutput | ForEach-Object { [string]$_ })
Assert-True ($confirmedBlockedExit -eq 4) 'owner_fqa_confirmed_runtime_block_exit_invalid'
Assert-True ($confirmedBlockedLines.Count -eq 1 -and $confirmedBlockedLines[0] -eq 'V4_OWNER_FQA_CHROME_QA=BLOCKED code=lease_runtime_disabled_pending_mutation_exclusion') 'owner_fqa_confirmed_runtime_block_output_invalid'

Write-Output "PHOTOS_APP_V4_OWNER_EXISTING_FIXTURE_BROWSER_PROTOCOL=PASS assertions=$assertions roles=3 teacher=blocked_no_fixture"
