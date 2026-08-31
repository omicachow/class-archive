[CmdletBinding()]
param()

# Static/no-switch verification only. It does not start Docker, PHP, Piwigo,
# an Owner runtime, a Teacher broker, or any private Chrome profile.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ensurePath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-fixture-ensure.ps1'
$brokerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-fixture-broker.php'
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

foreach ($path in @($ensurePath, $brokerPath, $brokerProtocolPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('teacher_fixture_ensure_file_missing_' + [IO.Path]::GetFileName($path))
}
$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($ensurePath, [ref]$tokens, [ref]$errors)
Assert-True ($errors.Count -eq 0) 'teacher_fixture_ensure_parse_invalid'

$currentPowerShell = (Get-Process -Id $PID -ErrorAction Stop).Path
$brokerOutput = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $brokerProtocolPath 2>&1)
Assert-True ($LASTEXITCODE -eq 0) 'teacher_fixture_ensure_broker_protocol_failed'
Assert-True (@($brokerOutput | Where-Object { [string]$_ -match '^V4_OWNER_TEACHER_FIXTURE_BROKER_PROTOCOL=PASS assertions=[0-9]+ static=PASS runtime=NOT_RUN$' }).Count -eq 1) 'teacher_fixture_ensure_broker_protocol_record_missing'

$source = [IO.File]::ReadAllText($ensurePath)
$broker = [IO.File]::ReadAllText($brokerPath)
foreach ($needle in @(
    '[switch]$ConfirmTeacherFixtureEnsure',
    'explicit_teacher_fixture_ensure_confirmation_required',
    "`$fixtureRun = '3e2f1a94b0c74d81952e6f0a'",
    "`$fixtureTarget = 'PRIVATE_REAL_FULL_OWNER'",
    "`$fixtureAck = 'LEASED_TEACHER_FIXTURE_V1'",
    "'infra/private-full/.env.piwigo.owner'",
    "'class_archive_private_full_v3_piwigo'",
    "'-d', 'Ubuntu', '--cd', `$projectRoot, '--exec', 'docker', 'compose'",
    "'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1'",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1'",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=' + `$fixtureTarget",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' + `$fixtureAck",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ENSURE=1'",
    "'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + `$fixtureRun",
    "'piwigo', 'php', `$brokerPath, `$fixtureRun",
    'V4_OWNER_TEACHER_FIXTURE=ENSURED identity=FROZEN credentials=unknown sessions=revoked',
    'V4_OWNER_TEACHER_FIXTURE_ENSURE=PASS identity=FROZEN credentials=unknown sessions=revoked target=PRIVATE_REAL_FULL_OWNER',
    'ensure_broker_failed',
    'credential-bearing output'
)) {
    Assert-Contains $source $needle ('teacher_fixture_ensure_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $broker "if (file_exists(`$ledgerPath) || is_link(`$ledgerPath)) {`n            v4teacherFail('teacher_broker_recovery_required');" 'teacher_fixture_ensure_nonterminal_ledger_refusal_missing'

$privateDrivePrefix = ([char]77).ToString() + ':'
foreach ($forbidden in @(
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_LEASE=1',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY=1',
    'V4_OWNER_TEACHER_FIXTURE_CREDENTIAL=',
    'EXPORT ', 'STOP ', 'credentials.json', 'chromium', 'playwright',
    '0.0.0.0', 'https://', $privateDrivePrefix, 'docker cp', 'base64 -w0'
)) {
    Assert-NotContains $source $forbidden ('teacher_fixture_ensure_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

$previous = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $blocked = @(& $currentPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $ensurePath 2>&1)
    $blockedExit = $LASTEXITCODE
}
finally { $ErrorActionPreference = $previous }
Assert-True ($blockedExit -eq 3) 'teacher_fixture_ensure_no_switch_exit_invalid'
Assert-True ($blocked.Count -eq 1 -and [string]$blocked[0] -eq 'V4_OWNER_TEACHER_FIXTURE_ENSURE=BLOCKED code=explicit_teacher_fixture_ensure_confirmation_required') 'teacher_fixture_ensure_no_switch_output_invalid'

Write-Output "PHOTOS_APP_V4_OWNER_TEACHER_FIXTURE_ENSURE_PROTOCOL=PASS assertions=$assertions static=PASS runtime=NOT_RUN"
