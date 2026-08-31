[CmdletBinding()]
param()

# Static public-safe contract only. This does not start Docker, Piwigo, Chrome,
# an Owner runtime, a private browser profile, or a Teacher fixture.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$brokerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-fixture-broker.php'
$adapterPath = Join-Path $projectRoot 'tests\phase3\private-e2e-teacher-fixture-lease.php'
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

foreach ($path in @($brokerPath, $adapterPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('teacher_broker_file_missing_' + [IO.Path]::GetFileName($path))
}
$broker = [IO.File]::ReadAllText($brokerPath)
$adapter = [IO.File]::ReadAllText($adapterPath)

foreach ($needle in @(
    "const V4_TEACHER_BROKER_ROOT = '/var/www/html/piwigo';",
    "const V4_TEACHER_BROKER_RECOVERY_ROOT = '/var/lib/class-archive-private-e2e';",
    "const V4_TEACHER_BROKER_OWNER = 'v4-owner-teacher-fixture-broker';",
    "const V4_TEACHER_BROKER_PERSISTENT_RUN = '3e2f1a94b0c74d81952e6f0a';",
    "const V4_TEACHER_BROKER_TARGET = 'PRIVATE_REAL_FULL_OWNER';",
    "const V4_TEACHER_BROKER_LEDGER_ENVIRONMENT = 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_FIXTURE';",
    "getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'",
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE') !== '1'",
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET')",
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK')",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ENSURE",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_LEASE",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY",
    "CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID",
    "PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY",
    "privateE2ETeacherFixtureAcquireLease(",
    "privateE2ETeacherFixtureInstallCredential(",
    "privateE2ETeacherFixtureCloseCredential(",
    "privateE2ETeacherFixtureReleaseLease(",
    "privateE2ETeacherFixtureRecoveryDocument(",
    "privateE2ETeacherFixtureBrowserCredentialDocument(",
    "ClassIdentityAdminService::fromPiwigo()",
    "->createIdentity(",
    "->issueClaim(",
    '\ClassIdentity\ProvisioningService::fromPiwigo()',
    "->claimFormal(",
    "->setIdentityFrozen(",
    "recoverAbandonedIdentityLease(",
    "compareAndSetLeasedPasswordHash(",
    'function v4teacherAssertTerminalCredentialState(',
    '\ClassIdentity\CoreAdapter::revokeAllCredentials($userId);',
    'SESSIONS_TABLE',
    'USER_AUTH_KEYS_TABLE',
    'teacher_broker_terminal_credentials_live',
    "v4teacherWriteLedger(",
    "v4teacherReadLedger(",
    "v4teacherFreezePartial(",
    "V4_OWNER_TEACHER_FIXTURE_CREDENTIAL=",
    "hash_equals('EXPORT ' . `$run, `$control)",
    "hash_equals('STOP ' . `$run, `$control)",
    "teacher_broker_credential_export_replayed",
    "teacher_broker_ensure_required",
    "V4_OWNER_TEACHER_FIXTURE=ENSURED identity=FROZEN credentials=unknown sessions=revoked",
    "V4_OWNER_TEACHER_FIXTURE=CLOSED identity=FROZEN credentials=unknown sessions=revoked"
)) {
    Assert-Contains $broker $needle ('teacher_broker_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The broker creates through normal services, not through a hand-written SQL
# identity/account insert. Read-only projection SQL and the lease adapter's
# narrow verifier CAS are deliberate and separately audited.
$privateSourceRoot = ([char]77).ToString() + ':' + '\\' + '图片' + '资源'
foreach ($forbidden in @(
    '$_GET', '$_POST', '$_REQUEST', 'add_event_handler', 'register_api_handler',
    'CoreAdapter::registerUser(', 'INSERT INTO `{$ci}identity`', 'INSERT INTO `{$ci}account`',
    'INSERT INTO `{$ci}seat`', 'INSERT INTO `{$prefix}users`', 'docker ', 'docker-compose',
    $privateSourceRoot, 'http://', 'https://'
)) {
    Assert-NotContains $broker $forbidden ('teacher_broker_forbidden_surface_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-Contains $broker "if (`$recoveryMode) {" 'teacher_broker_recovery_branch_missing'
Assert-Contains $broker "} elseif (`$ensureMode) {" 'teacher_broker_ensure_branch_missing'
Assert-Contains $broker "} else {" 'teacher_broker_lease_branch_missing'
Assert-Contains $broker '// Lease mode has a hard precondition: no creation, Claim issuance or' 'teacher_broker_post_snapshot_lease_guard_missing'
Assert-Contains $broker "if (`$candidate === null) {`n            v4teacherFail('teacher_broker_ensure_required');" 'teacher_broker_lease_requires_ensure_missing'
Assert-Contains $broker "v4teacherRemoveLedger(`$ledgerPath);`n        `$ensureComplete = true;" 'teacher_broker_ensure_terminal_cleanup_missing'
Assert-Contains $broker "if (!`$ensureComplete" 'teacher_broker_ensure_finally_guard_missing'
Assert-Contains $broker "'FROZEN'" 'teacher_broker_frozen_terminal_state_missing'
Assert-Contains $broker 'teacher_broker_claim_activation_invalid' 'teacher_broker_immediate_freeze_missing'
Assert-Contains $broker "`$issued['code'] = str_repeat" 'teacher_broker_claim_secret_wipe_missing'
Assert-Contains $broker "`$initialPassword = str_repeat" 'teacher_broker_initial_password_wipe_missing'
Assert-Contains $broker "`$browserPassword = str_repeat" 'teacher_broker_browser_password_wipe_missing'
Assert-Contains $broker "if (!`$closed) {" 'teacher_broker_recovery_fail_closed_missing'
Assert-Contains $broker "`$leaseService->markConflict(`$lease);" 'teacher_broker_conflict_quarantine_missing'
Assert-True (($broker.Split('v4teacherAssertTerminalCredentialState(').Count - 1) -ge 5) 'teacher_broker_terminal_credential_proof_incomplete'
Assert-Contains $broker 'GET_LOCK' 'teacher_broker_global_lock_missing'
Assert-Contains $broker 'RELEASE_LOCK' 'teacher_broker_global_unlock_missing'
Assert-Contains $broker 'ensureMode ? 1 : 0' 'teacher_broker_mode_xor_missing'
Assert-Contains $broker "PHP_SAPI !== 'cli'" 'teacher_broker_cli_guard_missing'
Assert-Contains $broker 'v4teacherPersistentRun($run)' 'teacher_broker_fixed_persistent_run_guard_missing'
Assert-Contains $broker '!hash_equals(V4_TEACHER_BROKER_PERSISTENT_RUN, $run)' 'teacher_broker_dynamic_run_rejection_missing'
Assert-Contains $broker 'function v4teacherSafeThrowableCode(\Throwable $error): string' 'teacher_broker_safe_runtime_error_mapper_missing'
Assert-Contains $broker "(?:class_identity|teacher_broker)_[a-z0-9_]{1,80}" 'teacher_broker_safe_runtime_error_allowlist_missing'
Assert-Contains $adapter "const PRIVATE_E2E_TEACHER_FIXTURE_OWNER = 'v4-owner-teacher-fixture-broker';" 'teacher_adapter_owner_mismatch'

# Recovery is a terminal repair path, not a new fixture acquisition. It needs
# an explicit recovery acknowledgement, but must be able to close an abandoned
# active lease after the normal private-E2E switch was removed.
foreach ($needle in @(
    'function v4teacherRequirePrivateCli(bool $recoveryMode = false): void',
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY') !== '1'",
    "(!`$recoveryMode && getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1')",
    'v4teacherRequirePrivateCli($recoveryMode);',
    'function v4teacherWithRecoveryLeasePermit(callable $callback): mixed',
    "putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1')",
    'recoverAbandonedIdentityLease(',
    'resolveConflictIdentityLease(',
    'LEASE_CONFLICT_RESOLVED',
    'teacher_broker_recovery_reconciliation_required',
    'teacher_broker_recovery_terminal_proof_required'
)) {
    Assert-Contains $broker $needle ('teacher_broker_recovery_gate_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Persist the acquisition intent before the DB can create a durable ACTIVE
# lease. Recovery must then inspect lease state and prove a terminal descriptor
# or a zero-account/revoked-claim pre-provision state before deleting a ledger.
foreach ($needle in @(
    "'LEASE_ACQUIRING'",
    "'LEASE_ACQUIRED'",
    'v4teacherUnresolvedLease(',
    'v4teacherPreProvisionRecoveryProof(',
    "if (`$unresolved['live']) {",
    'teacher_broker_recovery_plan_invalid',
    '"SELECT COUNT(*) AS count FROM `{$prefix}users` WHERE BINARY username=BINARY ?',
    "state IN ('ISSUED','RESERVED')",
    "if (`$stage === 'CONFLICT') {"
)) {
    Assert-Contains $broker $needle ('teacher_broker_recovery_ledger_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $broker 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1' 'teacher_broker_recovery_table_metadata_query_missing'
Assert-NotContains $broker "'SHOW TABLES LIKE ?'" 'teacher_broker_recovery_show_tables_placeholder_unsupported'
$leaseAcquiringWrite = "`$ledger = v4teacherLedger(`$run, `$fixture['identity_id'], `$fixture['seat_id'], 'LEASE_ACQUIRING');"
$leaseAcquireCall = 'privateE2ETeacherFixtureAcquireLease('
Assert-True ($broker.IndexOf($leaseAcquiringWrite) -ge 0) 'teacher_broker_acquiring_ledger_write_missing'
Assert-True ($broker.IndexOf($leaseAcquiringWrite) -lt $broker.LastIndexOf($leaseAcquireCall)) 'teacher_broker_acquiring_ledger_not_before_db_acquire'

# Every recovery close/conflict is audited before the durable ledger can be
# removed. An audit failure takes the ledger through explicit CONFLICT instead
# of yielding an unaudited success.
foreach ($needle in @(
    'function v4teacherAppendRecoveryAudit(',
    'function v4teacherRecoveryConflict(',
    'function v4teacherRecoveryClosedOrConflict(',
    "'LEASE_CONFLICT'",
    "'TERMINAL_FROZEN'",
    'v4teacherWriteLedger($ledgerPath, v4teacherLedger($run, $identityId > 0 ? $identityId : null, $seatId, ''CONFLICT'', $recovery));',
    'v4teacherAppendRecoveryAudit($admin, $fixture, $identityId, $state);'
)) {
    Assert-Contains $broker $needle ('teacher_broker_recovery_audit_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The one credential export is pipe-only: a TTY is rejected before acquisition
# and the live post-unfreeze graph is checked before READY and again before the
# EXPORT document is materialized.
foreach ($needle in @(
    'function v4teacherRequirePipeStdout(): void',
    'stream_isatty',
    'posix_isatty',
    'fstat(STDOUT)',
    'fstat(STDIN)',
    'regular-file redirection is a durable',
    'teacher_broker_stdout_pipe_required',
    'v4teacherRequirePipeStdout();',
    'function v4teacherAssertLeasedTeacherAuthorization(',
    '\ClassIdentity\Access::resolveAuthorizationContext(',
    '\ClassIdentity\Access::ROLE_TEACHER',
    "`$envelope['lock_version'] !== `$lease->expectedLockVersion()",
    'teacher_broker_teacher_authorization_unresolved'
)) {
    Assert-Contains $broker $needle ('teacher_broker_export_authorization_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$pipeGateIndex = $broker.IndexOf('v4teacherRequirePipeStdout();')
$acquireIndex = $broker.LastIndexOf($leaseAcquireCall)
$readyIndex = $broker.IndexOf('V4_OWNER_TEACHER_FIXTURE=READY roles=1')
$exportIndex = $broker.IndexOf("hash_equals('EXPORT ' . `$run, `$control)")
$lastAuthorizationIndex = $broker.LastIndexOf('v4teacherAssertLeasedTeacherAuthorization($db, $prefix, $fixture, $lease, $run);')
Assert-True ($pipeGateIndex -ge 0 -and $pipeGateIndex -lt $acquireIndex) 'teacher_broker_tty_gate_after_acquire'
Assert-True ($readyIndex -gt $pipeGateIndex) 'teacher_broker_ready_before_pipe_gate'
Assert-True ($exportIndex -ge 0 -and $lastAuthorizationIndex -gt $exportIndex) 'teacher_broker_export_without_fresh_authorization'

# No raw credential/document field is allowed in durable ledger code. The
# browser document is constructed only in-memory at the one stdin export site.
Assert-NotContains $broker "'browser_password' =>" 'teacher_broker_browser_password_persisted'
Assert-NotContains $broker "'claim_code' =>" 'teacher_broker_claim_code_persisted'
Assert-NotContains $broker "'initial_password' =>" 'teacher_broker_initial_password_persisted'
Assert-NotContains $broker "'raw_code' =>" 'teacher_broker_raw_claim_persisted'
Assert-NotContains $broker "fwrite(STDOUT, `$browserPassword" 'teacher_broker_plaintext_stdout_missing_guard'

Write-Output "V4_OWNER_TEACHER_FIXTURE_BROKER_PROTOCOL=PASS assertions=$assertions static=PASS runtime=NOT_RUN"
