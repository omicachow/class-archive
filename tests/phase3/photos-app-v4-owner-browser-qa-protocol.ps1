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
$watchdogMatch = [regex]::Match($wrapper, '(?ms)^\s+\$source = @''\r?\n(?<body>.*?)\r?\n''@\s*$')
Assert-True $watchdogMatch.Success 'owner_fqa_watchdog_source_missing'
$watchdogTokens = $null
$watchdogErrors = $null
[void][Management.Automation.Language.Parser]::ParseInput($watchdogMatch.Groups['body'].Value, [ref]$watchdogTokens, [ref]$watchdogErrors)
Assert-True ($watchdogErrors.Count -eq 0) 'owner_fqa_watchdog_source_parse_invalid'

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
Assert-Contains $runner "credentialDocument.lease?.roster === 'FQA-C-99CA3B3B6AF1'" 'owner_fqa_runner_candidate_binding_missing'
Assert-Contains $runner 'credentialDocument?.version === 3' 'owner_fqa_runner_v3_credential_document_missing'
Assert-Contains $runner "'environment,lease,recovery_plan,roles,run,version'" 'owner_fqa_runner_v3_credential_shape_missing'
Assert-Contains $runner 'credential_recovery_plan_shape' 'owner_fqa_runner_recovery_plan_validation_missing'
Assert-Contains $runner "credentialDocument = null;" 'owner_fqa_runner_recovery_plan_retention_missing'
Assert-Contains $runner 'never passed to page.evaluate' 'owner_fqa_runner_recovery_plan_browser_boundary_missing'
Assert-Contains $runner "value?.username === 'fqa_99ca3b3b6af1_classmate'" 'owner_fqa_runner_classmate_binding_missing'
Assert-Contains $runner "value?.username === 'fqa_99ca3b3b6af1_family'" 'owner_fqa_runner_family_binding_missing'
Assert-Contains $runner '/^anon_[a-f0-9]{20}$/' 'owner_fqa_runner_anonymous_binding_missing'
Assert-Contains $runner 'leasedUsernames' 'owner_fqa_runner_dynamic_redaction_missing'
Assert-Contains $runner 'normalizedIdentityKey' 'owner_fqa_runner_identity_key_normalization_missing'
Assert-Contains $runner "'__HYDRATION_DATA__'" 'owner_fqa_runner_hydration_surface_missing'
Assert-Contains $runner 'dynamicHydrationNames' 'owner_fqa_runner_dynamic_hydration_values_missing'
Assert-Contains $runner ".replaceAll('\\u005f', '')" 'owner_fqa_runner_escaped_identity_key_normalization_missing'
Assert-Contains $runner 'document.documentElement.outerHTML' 'owner_fqa_runner_full_document_scan_missing'
Assert-NotContains $runner "'teacher'" 'owner_fqa_runner_teacher_role_forbidden'
Assert-NotContains $runner 'fixture-teacher' 'owner_fqa_runner_teacher_fixture_forbidden'
Assert-Contains $runner 'chromium.launchPersistentContext' 'owner_fqa_persistent_context_missing'
Assert-Contains $runner "channel: 'chrome'" 'owner_fqa_chrome_channel_missing'
Assert-Contains $runner 'headless: false' 'owner_fqa_headed_chrome_missing'
Assert-Contains $runner "context.route('**/*'" 'owner_fqa_network_route_guard_missing'
Assert-Contains $runner 'CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS' 'owner_fqa_process_network_guard_missing'
Assert-Contains $runner "const SAFE_HTTP_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])" 'owner_fqa_all_unsafe_methods_guard_missing'
Assert-Contains $runner 'function isUnsafeRequest(request)' 'owner_fqa_all_unsafe_request_function_missing'
Assert-Contains $runner 'if (isUnsafeRequest(route.request()))' 'owner_fqa_all_unsafe_route_enforcement_missing'
Assert-Contains $runner 'function isAllowedLoginPost(request, target, role)' 'owner_fqa_exact_login_post_allowlist_missing'
Assert-Contains $runner "target.origin !== coreOrigin.origin || target.pathname !== '/identification.php'" 'owner_fqa_login_post_target_binding_missing'
Assert-Contains $runner "application/x-www-form-urlencoded" 'owner_fqa_login_post_content_type_binding_missing'
Assert-Contains $runner "fields.get('username') === credential.username" 'owner_fqa_login_post_username_binding_missing'
Assert-Contains $runner "fields.get('password') === credential.password" 'owner_fqa_login_post_password_binding_missing'
Assert-Contains $runner 'function isAllowedFamilyDeniedCommentProbe(request, target, role, enabled)' 'owner_fqa_exact_family_denial_allowlist_missing'
Assert-Contains $runner "target.origin === photoOrigin.origin && target.pathname === '/api/class-archive/comments/create'" 'owner_fqa_family_denial_target_binding_missing'
Assert-Contains $runner "payload?.body === 'family-readonly-owner-acceptance'" 'owner_fqa_family_denial_body_binding_missing'
Assert-Contains $runner 'const allowedLogin = isAllowedLoginPost(route.request(), target, role);' 'owner_fqa_login_post_route_allowlist_missing'
Assert-Contains $runner 'const allowedDeniedProbe = isAllowedFamilyDeniedCommentProbe(route.request(), target, role, familyDeniedCommentProbe);' 'owner_fqa_family_denial_route_allowlist_missing'
Assert-Contains $runner "const SEMANTIC_PROBE_QUERY = '\u6bd5\u4e1a graduation'" 'owner_fqa_semantic_probe_query_missing'
Assert-Contains $runner 'function isAllowedSmartSearchProbe(request, target)' 'owner_fqa_exact_smart_search_allowlist_missing'
Assert-Contains $runner "target.pathname === '/api/search/smart'" 'owner_fqa_smart_search_target_binding_missing'
Assert-Contains $runner "target.search === ''" 'owner_fqa_smart_search_query_string_rejected_missing'
Assert-Contains $runner "mediaType !== 'application/json'" 'owner_fqa_smart_search_content_type_binding_missing'
Assert-Contains $runner 'Object.keys(payload).length === 1 && payload.query === SEMANTIC_PROBE_QUERY' 'owner_fqa_smart_search_body_binding_missing'
Assert-Contains $runner 'const allowedSmartSearch = isAllowedSmartSearchProbe(route.request(), target);' 'owner_fqa_smart_search_route_allowlist_missing'
Assert-Contains $runner 'if (!allowedLogin && !allowedDeniedProbe && !allowedSmartSearch)' 'owner_fqa_unsafe_default_deny_missing'
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
Assert-Contains $runner 'album_detail_cover_membership' 'owner_fqa_album_cover_membership_missing'
Assert-Contains $runner 'person_detail_cover_membership' 'owner_fqa_person_cover_membership_missing'
Assert-Contains $runner 'people_count_scope' 'owner_fqa_search_people_count_scope_missing'
Assert-Contains $runner 'albums_count_scope' 'owner_fqa_search_album_count_scope_missing'
Assert-Contains $runner 'people_total_scope' 'owner_fqa_search_people_total_scope_missing'
Assert-Contains $runner 'albums_total_scope' 'owner_fqa_search_album_total_scope_missing'
Assert-Contains $runner 'events_count_scope' 'owner_fqa_search_event_count_scope_missing'
Assert-Contains $runner 'archive_time_count_scope' 'owner_fqa_search_archive_time_count_scope_missing'
Assert-Contains $runner 'semantic_total_scope' 'owner_fqa_search_semantic_total_scope_missing'
Assert-Contains $runner 'assets.total === assets.items.length && assets.count === assets.items.length' 'owner_fqa_semantic_exact_count_missing'
Assert-Contains $runner 'semantic_grouped_exact_total' 'owner_fqa_grouped_semantic_exact_count_missing'
Assert-Contains $runner 'family_semantic_exact_living_leak' 'owner_fqa_semantic_family_scope_missing'
Assert-Contains $runner 'albums.get(albumId).cover === cover' 'owner_fqa_spotlight_album_cover_binding_missing'
Assert-Contains $runner 'albumDetailsComplete' 'owner_fqa_complete_album_counts_missing'
Assert-Contains $runner 'peopleDetailsComplete' 'owner_fqa_complete_people_counts_missing'
Assert-Contains $runner 'completeSearchPhotoIds' 'owner_fqa_complete_search_counts_missing'
Assert-Contains $runner 'payload.total === payload.items.length' 'owner_fqa_spotlight_count_binding_missing'
Assert-Contains $runner 'anonymous_api_identity_leak' 'owner_fqa_anonymous_api_redaction_missing'
Assert-Contains $runner 'anonymous_html_identity_leak' 'owner_fqa_anonymous_html_redaction_missing'
Assert-Contains $runner 'document.documentElement.outerHTML' 'owner_fqa_anonymous_full_html_scan_missing'
Assert-Contains $runner "dialog.locator('.hybrid-results')" 'owner_fqa_search_rendered_results_missing'
Assert-Contains $runner "dialog.locator('.error-state').count() === 0" 'owner_fqa_search_error_state_rejected_missing'
Assert-Contains $runner "dialog.locator('.photo-loading').count() === 0" 'owner_fqa_search_loading_state_rejected_missing'
Assert-Contains $runner "dialog.locator('.hybrid-results .search-section').count() > 0" 'owner_fqa_search_real_section_required_missing'
Assert-Contains $runner "dialog.locator('.hybrid-results .empty-state').count() === 0" 'owner_fqa_search_empty_state_rejected_missing'
Assert-Contains $runner 'closeRoleContext' 'owner_fqa_browser_context_cleanup_missing'
Assert-Contains $runner 'browser_pass_record_missing' 'owner_fqa_browser_pass_after_cleanup_missing'
Assert-Contains $runner 'roles=3' 'owner_fqa_safe_aggregate_record_missing'

$runnerClose = $runner.LastIndexOf("await closeRoleContext(classmateSession, 'classmate')")
$runnerPass = $runner.LastIndexOf('process.stdout.write(`${passRecord}\n`)')
Assert-True ($runnerClose -ge 0 -and $runnerPass -gt $runnerClose) 'owner_fqa_browser_pass_emitted_before_context_cleanup'

Assert-Contains $wrapper 'Assert-PrivateParentAcl -Candidate $HostPath' 'owner_fqa_credential_parent_acl_preflight_missing'
Assert-Contains $wrapper '$trustedBoundary = $trustedRoot + $separator' 'owner_fqa_credential_ancestor_acl_boundary_missing'
Assert-Contains $wrapper 'Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName' 'owner_fqa_credential_ancestor_acl_walk_missing'
Assert-Contains $wrapper 'Set-ClassArchiveOwnerOnlyFileAcl -Path $HostPath' 'owner_fqa_credential_acl_before_write_missing'
Assert-Contains $wrapper "'base64', '-w0', '--', `$ContainerPath" 'owner_fqa_credential_private_transport_missing'
Assert-NotContains $wrapper "'cp', ('piwigo:'" 'owner_fqa_insecure_docker_cp_forbidden'
Assert-Contains $wrapper 'Stop-FqaNativeProcessTree' 'owner_fqa_broker_reap_missing'
Assert-Contains $wrapper 'Invoke-FqaLeaseRecovery' 'owner_fqa_independent_refreeze_missing'
Assert-Contains $wrapper 'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1' 'owner_fqa_recovery_mode_invocation_missing'
Assert-Contains $wrapper 'New-FqaLeaseWatchdog' 'owner_fqa_durable_watchdog_script_missing'
Assert-Contains $wrapper 'Start-FqaLeaseWatchdog' 'owner_fqa_durable_watchdog_start_missing'
Assert-Contains $wrapper 'Start-Sleep -Seconds ($LeaseTtlSeconds + 30)' 'owner_fqa_watchdog_expiry_wait_missing'
Assert-Contains $wrapper 'Complete-WatchdogCredentialCleanup' 'owner_fqa_watchdog_credential_cleanup_missing'
Assert-Contains $wrapper 'WATCHDOG_RECOVERY_COMPLETE' 'owner_fqa_watchdog_recovery_marker_missing'
Assert-Contains $wrapper '$leaseCloseAttested = Close-FqaLeaseBroker' 'owner_fqa_close_attestation_gate_missing'
Assert-Contains $wrapper '$preserveRecoveryRuntime = $true' 'owner_fqa_watchdog_runtime_preservation_missing'
Assert-Contains $wrapper 'if (-not $preserveRecoveryRuntime -and $leaseCloseAttested -and $watchdogReaped)' 'owner_fqa_watchdog_cleanup_gate_missing'
Assert-Contains $wrapper 'Invoke-ClassArchiveBoundedNative' 'owner_fqa_browser_watchdog_missing'
Assert-Contains $wrapper '$browserTimeoutSeconds = 720' 'owner_fqa_browser_timeout_missing'
Assert-NotContains $wrapper 'function Invoke-Piwigo' 'owner_fqa_unbounded_wsl_helper_forbidden'
Assert-Contains $wrapper "if (`$cleanupFailed) {" 'owner_fqa_cleanup_failure_precedes_pass_missing'
Assert-Contains $wrapper 'V4_OWNER_FQA_CLEANUP=FAIL code=' 'owner_fqa_cleanup_failure_detail_missing'
Assert-Contains $wrapper 'if ($null -ne $failureRecord) { Write-Output $failureRecord }' 'owner_fqa_primary_failure_hidden_by_cleanup_missing'
$wrapperCleanup = $wrapper.LastIndexOf('if ($cleanupFailed) {')
$wrapperPass = $wrapper.LastIndexOf('Write-Output $browserPassRecord')
Assert-True ($wrapperCleanup -ge 0 -and $wrapperPass -gt $wrapperCleanup) 'owner_fqa_wrapper_pass_emitted_before_cleanup_gate'
$wrapperWatchdogStart = $wrapper.IndexOf('$leaseWatchdog = Start-FqaLeaseWatchdog')
$wrapperBrokerStart = $wrapper.IndexOf('$leaseBroker = Start-FqaLeaseBroker')
$wrapperCloseAttestation = $wrapper.LastIndexOf('$leaseCloseAttested = Close-FqaLeaseBroker')
$wrapperWatchdogReap = $wrapper.LastIndexOf('$watchdogReaped = Stop-FqaNativeProcessTree')
Assert-True ($wrapperWatchdogStart -ge 0 -and $wrapperBrokerStart -gt $wrapperWatchdogStart) 'owner_fqa_watchdog_started_after_lease_attempt'
Assert-True ($wrapperCloseAttestation -ge 0 -and $wrapperWatchdogReap -gt $wrapperCloseAttestation) 'owner_fqa_watchdog_cancelled_before_close_attestation'

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

# Never exercise the confirmation switch from a protocol/static test. The
# confirmed path is a real, audited 8191 security lease and belongs only to the
# explicit private runtime acceptance command.
Assert-Contains $wrapper '$runtimeLeaseMutationExclusionProven = $true' 'owner_fqa_runtime_cas_capability_missing'
Assert-Contains $wrapper "'-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1'" 'owner_fqa_private_gate_missing'

Write-Output "PHOTOS_APP_V4_OWNER_EXISTING_FIXTURE_BROWSER_PROTOCOL=PASS assertions=$assertions roles=3 teacher=blocked_no_fixture"
