[CmdletBinding()]
param()

# Static owner-private Teacher browser contract. It never launches Docker,
# Piwigo, Chrome, a broker, or HTTP, so it cannot unfreeze the FQA-T fixture.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-browser-qa.ps1'
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-browser-qa.mjs'
$adapterPath = Join-Path $projectRoot 'tests\phase3\private-e2e-teacher-fixture-lease.php'
$brokerProtocolPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-fixture-broker-protocol.ps1'
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

foreach ($path in @($wrapperPath, $runnerPath, $adapterPath, $brokerProtocolPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('owner_teacher_browser_file_missing_' + [IO.Path]::GetFileName($path))
}

$node = (Get-Command node -ErrorAction SilentlyContinue).Source
Assert-True (-not [string]::IsNullOrWhiteSpace($node)) 'owner_teacher_browser_node_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'owner_teacher_browser_runner_parse_invalid'
$wrapperTokens = $null
$wrapperErrors = $null
[void][Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$wrapperTokens, [ref]$wrapperErrors)
Assert-True ($wrapperErrors.Count -eq 0) 'owner_teacher_browser_wrapper_parse_invalid'

$currentPowerShell = (Get-Process -Id $PID -ErrorAction Stop).Path
$brokerProtocolOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $brokerProtocolPath 2>&1)
Assert-True ($LASTEXITCODE -eq 0) 'owner_teacher_broker_protocol_failed'
Assert-True (@($brokerProtocolOutput | Where-Object { [string]$_ -match '^V4_OWNER_TEACHER_FIXTURE_BROKER_PROTOCOL=PASS assertions=[0-9]+ static=PASS runtime=NOT_RUN$' }).Count -eq 1) 'owner_teacher_broker_protocol_record_missing'

$runner = [IO.File]::ReadAllText($runnerPath)
$wrapper = [IO.File]::ReadAllText($wrapperPath)
$adapter = [IO.File]::ReadAllText($adapterPath)

foreach ($needle in @(
    "const PERSISTENT_RUN = '3e2f1a94b0c74d81952e6f0a';",
    "const BROWSER_CREDENTIAL_ENV = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT';",
    "const BROWSER_CREDENTIAL_ROOT_KEYS = 'environment,lease,roles,run,version';",
    "const BROWSER_CREDENTIAL_LEASE_KEYS = 'role,roster';",
    "const BROWSER_CREDENTIAL_ROLE_KEYS = 'password,username';",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CORE_ORIGIN",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PHOTO_ORIGIN",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CREDENTIAL_FILE",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PROFILE_ROOT",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_SCREENSHOT_DIR",
    "credential_document_scope",
    "exactObjectKeys(document, BROWSER_CREDENTIAL_ROOT_KEYS, 'credential_document_shape')",
    "exactObjectKeys(document.lease, BROWSER_CREDENTIAL_LEASE_KEYS, 'credential_lease_shape')",
    "exactObjectKeys(document.roles, 'teacher', 'credential_role_shape')",
    "exactObjectKeys(value, BROWSER_CREDENTIAL_ROLE_KEYS, 'credential_teacher_shape')",
    'FQA-T-${configuration.runId.toUpperCase()}',
    'fqa_t_${configuration.runId}_teacher',
    "/^[A-Za-z0-9_-]{64}$/",
    "document = null;",
    "channel: 'chrome'",
    "headless: false",
    "chromium.launchPersistentContext",
    "CHROME_OWNER_TEACHER_LOCALHOST_ONLY_LAUNCH_ARGS",
    "context.route('**/*'",
    "function isUnsafeRequest(request)",
    "function isAllowedLoginPost(request, target)",
    'function gotoCoreLoginBridge(page)',
    "current.origin === configuration.coreOrigin.origin`n    && current.pathname === '/identification.php'",
    "function isAllowedSmartSearchProbe(request, target)",
    "if (isUnsafeRequest(route.request())",
    "teacher_business_write_observed",
    "state.role === 'TEACHER'",
    "state.canEraUpload === true && state.canFamilySubmission === false && state.canManage === false",
    "teacher_manage_api_denied",
    "teacher_family_anonymous_admin_affordance",
    "teacher_library_cards",
    "teacher_album_detail_cards",
    "teacher_people_cards",
    "teacher_search_results",
    "teacher_preview_head_mediaguard",
    "teacher_original_head_mediaguard",
    "teacher_viewer_mediaguard_path",
    "V4_OWNER_TEACHER_FIXTURE_CHROME_QA=PASS",
    "V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL",
    "flag: 'wx', mode: 0o600",
    "A local diagnostic cannot replace the deterministic fail-closed result."
)) {
    Assert-Contains $runner $needle ('owner_teacher_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-Contains $adapter "const PRIVATE_E2E_TEACHER_FIXTURE_BROWSER_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT';" 'owner_teacher_adapter_browser_environment_mismatch'
Assert-Contains $adapter 'return ''fqa_t_'' . privateE2ETeacherFixtureRun($run) . ''_teacher'';' 'owner_teacher_adapter_username_contract_missing'

# The wrapper is intentionally LEASE-only. ENSURE is a distinct pre-snapshot
# tool; allowing it in a browser runner would make a post-snapshot acceptance
# create or claim a fixture and invalidate its business comparison boundary.
foreach ($needle in @(
    '[switch]$ConfirmTeacherCredentialLease',
    'explicit_teacher_credential_lease_confirmation_required',
    "`$fixtureRun = '3e2f1a94b0c74d81952e6f0a'",
    "`$fixtureTarget = 'PRIVATE_REAL_FULL_OWNER'",
    "`$fixtureAck = 'LEASED_TEACHER_FIXTURE_V1'",
    "'http://127.0.0.1:8190/'",
    "'http://127.0.0.1:8191/'",
    "'infra/private-full/.env.piwigo.owner'",
    "'class_archive_private_full_v3_piwigo'",
    "'.codex-work\private-real-qa\runtime\photos-app-v4-owner-teacher-lease'",
    "'.codex-work\private-real-qa\browser\photos-app-v4-owner-teacher-lease'",
    "'.codex-work\private-real-qa\screenshots\photos-app-v4'",
    'Assert-IgnoredPrivateChild',
    'Assert-NoReparseAncestor',
    'Set-OwnerOnlyDirectoryAcl',
    'Assert-PrivateParentAcl',
    'Set-ClassArchiveOwnerOnlyFileAcl -Path $HostPath',
    'Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPath',
    'Copy-TeacherCredentialFromBroker',
    'Assert-TeacherBrowserCredentialDocument',
    '$Broker.StandardInput.BaseStream',
    "Write-TeacherBrokerControl -Broker `$Broker -Command ('EXPORT ' + `$fixtureRun)",
    "Write-TeacherBrokerControl -Broker `$Process -Command ('STOP ' + `$fixtureRun) -CloseAfter",
    'V4_OWNER_TEACHER_FIXTURE_CREDENTIAL=',
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=' + `$fixtureTarget",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' + `$fixtureAck",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_LEASE=1'",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY=1'",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + `$fixtureRun",
    'V4_OWNER_TEACHER_FIXTURE=READY roles=1 ttl=',
    'V4_OWNER_TEACHER_FIXTURE=CLOSED identity=FROZEN credentials=unknown sessions=revoked',
    'V4_OWNER_TEACHER_FIXTURE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked',
    'Start-TeacherLeaseWatchdog',
    'function Remove-WatchdogPrivateProfile',
    '-ProfilePath $runProfile',
    '-ProfileRoot $profileRoot',
    'if (-not (Remove-WatchdogPrivateProfile)) { return $false }',
    'Start-TeacherLeaseBroker',
    'Invoke-TeacherLeaseRecovery',
    'Start-Sleep -Seconds ($LeaseTtlSeconds + 30)',
    '$leaseWatchdog = Start-TeacherLeaseWatchdog',
    '$watchdogStarted = $true',
    '$leaseBroker = Start-TeacherLeaseBroker',
    '$leaseMayBeActive = $true',
    'lease_broker_(?:ready_timeout|rejected)_recovery_failed',
    '$leaseCloseAttested = Close-TeacherLeaseBroker',
    'if (-not $preserveRecoveryRuntime -and $leaseCloseAttested -and $watchdogReaped)',
    'Invoke-ClassArchiveBoundedNative',
    '$browserTimeoutSeconds = 720',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CREDENTIAL_FILE',
    'V4_OWNER_TEACHER_FIXTURE_CHROME_QA_COMPLETE=PASS roles=1 identity=FROZEN sessions=revoked credentials=unknown',
    'if ($cleanupFailed) {'
)) {
    Assert-Contains $wrapper $needle ('owner_teacher_wrapper_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$privateDrivePrefix = ([char]77).ToString() + ':'
foreach ($forbidden in @(
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ENSURE=1',
    '.StandardInput.WriteLine(', 'docker cp', "'base64', '-w0'", 'setInputFiles',
    '/api/class-archive/member-upload', '0.0.0.0', 'https://', $privateDrivePrefix,
    'V4_OWNER_FQA_', 'ConfirmFqaCredentialLease', 'Write-Output $record',
    'Write-Output $result.Stdout', 'Write-Output $nodeResult.Stderr'
)) {
    Assert-NotContains $wrapper $forbidden ('owner_teacher_wrapper_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$previousPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $blockedOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $wrapperPath 2>&1)
    $blockedExit = $LASTEXITCODE
}
finally { $ErrorActionPreference = $previousPreference }
Assert-True ($blockedExit -eq 3) 'owner_teacher_wrapper_no_switch_exit_invalid'
Assert-True ($blockedOutput.Count -eq 1 -and [string]$blockedOutput[0] -eq 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=BLOCKED code=explicit_teacher_credential_lease_confirmation_required') 'owner_teacher_wrapper_no_switch_output_invalid'

# Exact private-only boundaries and fresh, non-reparse inputs must be checked
# by the runner itself even though the wrapper separately verifies ACLs.
foreach ($needle in @(
    "const PRIVATE_ROOT_BOUNDARY = '/.codex-work/private-real-qa/';",
    "const CREDENTIAL_BOUNDARY = '/.codex-work/private-real-qa/runtime/photos-app-v4-owner-teacher-lease/';",
    "const PROFILE_BOUNDARY = '/.codex-work/private-real-qa/browser/photos-app-v4-owner-teacher-lease/';",
    "const SCREENSHOT_BOUNDARY = '/.codex-work/private-real-qa/screenshots/photos-app-v4/';",
    "fs.lstatSync(resolved)",
    "fs.realpathSync.native(resolved)",
    "!stat.isSymbolicLink()",
    'check(entries.length === 0, `setting_${name.toLowerCase()}_not_fresh`)',
    'check(!fs.existsSync(target), `${code}_exists`);'
)) {
    Assert-Contains $runner $needle ('owner_teacher_runner_private_path_guard_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# This is intentionally independent from the FQA-C aggregate. It may use the
# FQA-T namespace but must never become a four-role runner or a fixture creator.
foreach ($forbidden in @(
    "const roles = Object.freeze(['classmate', 'family', 'anonymous'])",
    "fqa_99ca3b3b6af1_classmate",
    "fixture-classmate",
    "createIdentity(",
    "issueClaim(",
    "setInputFiles",
    "/api/class-archive/member-upload",
    "fs.copyFile",
    "fs.cp",
    "docker compose",
    "0.0.0.0",
    "https://",
    $privateDrivePrefix,
    "recovery_plan",
    "closed_password_hash",
    "before_password_sha256",
    "lease_password_sha256"
)) {
    Assert-NotContains $runner $forbidden ('owner_teacher_runner_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_OWNER_TEACHER_BROWSER_PROTOCOL=PASS assertions=$assertions static=PASS runtime=NOT_RUN"
