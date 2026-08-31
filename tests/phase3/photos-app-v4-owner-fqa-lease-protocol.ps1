[CmdletBinding()]
param()

# Pure static contract for the owner FQA lease broker. It does not invoke
# Docker, PHP, HTTP, Chrome, or the lease confirmation switch.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path (Join-Path $PSScriptRoot '..') '..')).Path
$brokerPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-fqa-lease.php'
$brokerRuntimePath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-fqa-broker-runtime.php'
$wrapperPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-browser-qa.ps1'
$servicePath = Join-Path $projectRoot 'plugins/ClassIdentity/src/PrivateE2EFixtureLeaseService.php'
$adminServicePath = Join-Path $projectRoot 'plugins/ClassIdentity/src/AdminService.php'
$mainPath = Join-Path $projectRoot 'plugins/ClassIdentity/main.inc.php'
$docsPath = Join-Path $projectRoot 'docs/photos-app-v4-owner-browser-qa.md'
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

foreach ($path in @($brokerPath, $brokerRuntimePath, $wrapperPath, $servicePath, $adminServicePath, $mainPath, $docsPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('owner_fqa_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}
$broker = [IO.File]::ReadAllText($brokerPath)
$brokerRuntime = [IO.File]::ReadAllText($brokerRuntimePath)
$wrapper = [IO.File]::ReadAllText($wrapperPath)
$service = [IO.File]::ReadAllText($servicePath)
$adminService = [IO.File]::ReadAllText($adminServicePath)
$main = [IO.File]::ReadAllText($mainPath)
$docs = [IO.File]::ReadAllText($docsPath)

Assert-Contains $broker "const V4_FQA_ROSTER = 'FQA-C-99CA3B3B6AF1'" 'owner_fqa_candidate_not_pinned'
Assert-Contains $broker "const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY']" 'owner_fqa_role_set_invalid'
Assert-Contains $broker 'const V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN = true;' 'owner_fqa_broker_runtime_cas_capability_missing'
Assert-Contains $broker "'mutation_exclusion_unavailable'" 'owner_fqa_broker_mutation_exclusion_code_missing'
Assert-Contains $broker "getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'" 'owner_fqa_private_mode_gate_missing'
Assert-Contains $broker '$leaseMode === $recoveryMode' 'owner_fqa_runtime_mode_gate_missing'
Assert-Contains $broker "getenv('CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY') === '1'" 'owner_fqa_recovery_mode_missing'
Assert-Contains $broker 'posix_geteuid() === 0' 'owner_fqa_nonroot_gate_missing'
Assert-Contains $broker 'Schema::fromPiwigo((string) CLASS_IDENTITY_VERSION)->verifyCurrent()' 'owner_fqa_schema_attestation_missing'
Assert-Contains $broker 'Access::isEnforcementEnabled()' 'owner_fqa_enforcement_gate_missing'
Assert-Contains $broker "SELECT GET_LOCK('" 'owner_fqa_database_lock_missing'
Assert-Contains $broker "SELECT RELEASE_LOCK('" 'owner_fqa_database_unlock_missing'
Assert-Contains $broker "array `$allowedIdentityStates = ['FROZEN']" 'owner_fqa_frozen_preflight_missing'
Assert-Contains $broker "identity_type'] ?? null) !== 'CLASSMATE'" 'owner_fqa_identity_type_preflight_missing'
Assert-Contains $broker 'count($accounts) !== 3' 'owner_fqa_exact_account_count_missing'
Assert-Contains $broker "'extra_current_account'" 'owner_fqa_current_account_topology_missing'
Assert-Contains $broker "'extra_seat_principal'" 'owner_fqa_principal_topology_missing'
Assert-Contains $broker "'CLASSMATE' => `$username === 'fqa_99ca3b3b6af1_classmate'" 'owner_fqa_classmate_binding_missing'
Assert-Contains $broker "'FAMILY' => `$username === 'fqa_99ca3b3b6af1_family'" 'owner_fqa_family_binding_missing'
Assert-Contains $broker "'ANONYMOUS' => preg_match('/\Aanon_[a-f0-9]{20}\z/D'" 'owner_fqa_anonymous_binding_missing'
Assert-Contains $broker "candidate_' . `$name . '_present" 'owner_fqa_fail_closed_invariant_missing'
foreach ($needle in @('issued_token', 'submission', 'active_pin', 'unfinished_operation', 'live_comment', 'active_auth_key')) {
    Assert-Contains $broker ("'" + $needle + "'") ('owner_fqa_preflight_missing_' + $needle)
}
Assert-Contains $broker "count(`$admins) !== 1" 'owner_fqa_single_admin_missing'
Assert-Contains $broker 'v4fqaPasswordHash' 'owner_fqa_password_hasher_missing'
Assert-Contains $broker 'compareAndSetFixturePasswordHash' 'owner_fqa_open_password_cas_missing'
Assert-Contains $broker 'compareAndSetLeasedPasswordHash' 'owner_fqa_close_password_cas_missing'
Assert-Contains $broker 'CoreAdapter::revokeAllCredentials' 'owner_fqa_credential_revocation_missing'
Assert-Contains $broker "'action' => 'PRINCIPAL_SECURITY_CHANGE'" 'owner_fqa_security_audit_missing'
Assert-Contains $broker "'reason_code' => 'LOCAL_FQA_LEASE'" 'owner_fqa_safe_audit_reason_code_missing'
Assert-Contains $broker "'reason_code' => 'LOCAL_FQA_LEASE_CLEANUP'" 'owner_fqa_cleanup_audit_reason_code_missing'
Assert-Contains $broker "'state' => `$safe ? 'LEASE_CLOSED' : 'LEASE_CONFLICT'" 'owner_fqa_cleanup_audit_state_missing'
Assert-Contains $broker 'credential_cleanup_audit_failed' 'owner_fqa_cleanup_audit_fail_closed_missing'
Assert-Contains $broker "fopen(`$path, 'x')" 'owner_fqa_exclusive_credential_create_missing'
Assert-Contains $broker 'umask(0077)' 'owner_fqa_private_create_umask_missing'
Assert-Contains $broker 'chmod($path, 0600)' 'owner_fqa_credential_mode_missing'
Assert-Contains $broker "(`$stat['nlink'] ?? 0) !== 1" 'owner_fqa_credential_link_guard_missing'
Assert-Contains $broker "'recovery_plan' => `$recoveryPlan" 'owner_fqa_recovery_plan_missing'
Assert-Contains $broker 'lease_password_sha256' 'owner_fqa_lease_password_digest_missing'
Assert-Contains $broker 'before_password_sha256' 'owner_fqa_before_password_digest_missing'
Assert-Contains $broker "'before_password_sha256' => hash('sha256', (string) `$account['password_hash'])" 'owner_fqa_in_memory_before_password_digest_missing'
Assert-Contains $broker 'closed_password_hash' 'owner_fqa_closed_password_hash_missing'
Assert-Contains $broker 'v4fqaReadCredentialPlan' 'owner_fqa_recovery_plan_reader_missing'
Assert-Contains $broker 'v4fqaExportCredentialDocument' 'owner_fqa_broker_export_reader_missing'
Assert-Contains $broker "hash_equals('EXPORT ' . `$run" 'owner_fqa_authenticated_export_missing'
Assert-Contains $broker 'credential_export_replayed' 'owner_fqa_single_export_guard_missing'
Assert-Contains $broker 'V4_OWNER_FQA_CREDENTIAL=' 'owner_fqa_private_export_record_missing'
Assert-Contains $broker "array_keys(`$document['roles']) !== array_map('strtolower', V4_FQA_ROLES)" 'owner_fqa_export_role_shape_guard_missing'
Assert-Contains $broker 'credential_export_document_invalid' 'owner_fqa_export_document_validation_missing'
Assert-Contains $broker "`$encodedCredential = '';" 'owner_fqa_export_buffer_release_missing'
Assert-Contains $broker 'v4fqaCredentialPlanMatchesState' 'owner_fqa_recovery_topology_binding_missing'
Assert-Contains $broker 'v4fqaRecoveryIdentityEnvelope' 'owner_fqa_recovery_identity_envelope_missing'
Assert-Contains $broker 'v4fqaQuarantineTopologyConflict' 'owner_fqa_recovery_topology_quarantine_missing'
Assert-Contains $broker 'A helper failure must never strand an' 'owner_fqa_cleanup_exception_quarantine_missing'
Assert-Contains $broker "'credential_cleanup_exception'" 'owner_fqa_cleanup_exception_result_missing'
Assert-Contains $broker '// Credential cleanup comes first and is independently pinned to the exact' 'owner_fqa_recovery_cleanup_order_contract_missing'
Assert-Contains $broker '$leaseService->markConflict($leaseContext)' 'owner_fqa_recovery_topology_conflict_state_missing'
Assert-Contains $broker 'if (!$recoveryTopologyConflict && function_exists' 'owner_fqa_recovery_topology_no_runtime_open_missing'
Assert-Contains $broker "defined('V4_FQA_LIBRARY_ONLY')" 'owner_fqa_broker_library_runtime_gate_missing'
Assert-Contains $broker "const V4_FQA_RECOVERY_ROOT = '/var/lib/class-archive-private-e2e'" 'owner_fqa_durable_recovery_root_missing'
Assert-Contains $broker "V4_OWNER_FQA_LEASE=READY roles=3 ttl=" 'owner_fqa_safe_ready_record_missing'
Assert-Contains $broker 'stream_select' 'owner_fqa_control_wait_missing'
Assert-Contains $broker "hash_equals('STOP ' . `$run" 'owner_fqa_authenticated_stop_missing'
Assert-Contains $broker 'time() < $deadline' 'owner_fqa_ttl_missing'
Assert-Contains $broker 'pcntl_signal' 'owner_fqa_signal_cleanup_missing'
Assert-Contains $broker 'finally {' 'owner_fqa_finally_missing'
Assert-Contains $broker '$fixtureLeaseService->recoverAbandonedIdentityLease(' 'owner_fqa_independent_recovery_missing'
Assert-Contains $broker '$recoveryCompleted = $cleanupSafe;' 'owner_fqa_independent_recovery_attestation_missing'
Assert-Contains $broker 'candidate_changed_before_activation' 'owner_fqa_pre_activation_recheck_missing'
Assert-NotContains $broker 'for ($attempt = 0; $attempt < 3; $attempt++)' 'owner_fqa_unsafe_cleanup_retry_present'
Assert-Contains $broker '// Identity activation is deliberately the final opening action.' 'owner_fqa_open_order_contract_missing'
Assert-Contains $broker '// This is a single exact-version CAS, not a retrying rollback.' 'owner_fqa_cleanup_cas_contract_missing'
Assert-True ($broker.LastIndexOf('v4fqaWriteCredentialFile(') -lt $broker.LastIndexOf('v4fqaInstallCredentialPlan(')) 'owner_fqa_plan_not_written_before_password_cas'
Assert-Contains $broker '!fsync($handle)' 'owner_fqa_credential_file_fsync_missing'
Assert-Contains $broker "fopen(dirname(`$path), 'r')" 'owner_fqa_credential_directory_open_missing'
Assert-Contains $broker '!fsync($directoryHandle)' 'owner_fqa_credential_directory_fsync_missing'
Assert-True ($broker.LastIndexOf('!fsync($directoryHandle)') -lt $broker.LastIndexOf('v4fqaInstallCredentialPlan(')) 'owner_fqa_directory_not_synced_before_password_cas'
Assert-Contains $broker "false," 'owner_fqa_unfreeze_call_missing'
Assert-Contains $broker "true," 'owner_fqa_refreeze_call_missing'
Assert-Contains $broker "`$state['lock_version'] + 1" 'owner_fqa_open_lock_version_check_missing'
Assert-Contains $broker '$leaseContext->expectedLockVersion()' 'owner_fqa_close_expected_version_missing'
Assert-Contains $broker '$fixtureLeaseService->heartbeat($fixtureLeaseContext, $ttl)' 'owner_fqa_heartbeat_missing'
Assert-Contains $broker '$leaseService->releaseIdentityLease($leaseContext)' 'owner_fqa_explicit_release_missing'
Assert-Contains $broker 'recoverAbandonedIdentityLease' 'owner_fqa_abandoned_recovery_missing'
Assert-Contains $broker 'Access::resolveAuthorizationContext' 'owner_fqa_open_role_context_check_missing'
Assert-Contains $broker 'v4fqaCloseCredentialPlan(' 'owner_fqa_close_credential_plan_missing'
Assert-Contains $broker '// A concurrent administrator verifier always wins.' 'owner_fqa_admin_password_wins_contract_missing'
Assert-Contains $broker "v4fqaPreflight(`$db, `$prefix, ['FROZEN'])" 'owner_fqa_close_full_preflight_missing'
Assert-Contains $broker "realpath(V4_FQA_ROOT . '/plugins/ClassIdentity/src/AdminService.php')" 'owner_fqa_admin_service_trusted_path_missing'
Assert-Contains $broker 'hash_equals($expectedAdminServicePath, $adminServicePath)' 'owner_fqa_admin_service_path_match_missing'
Assert-Contains $broker "V4_OWNER_FQA_LEASE=CLOSED identity=FROZEN credentials=unknown sessions=revoked" 'owner_fqa_safe_close_record_missing'
Assert-NotContains $broker 'INSERT INTO' 'owner_fqa_direct_insert_forbidden'
Assert-NotContains $broker 'UPDATE `' 'owner_fqa_direct_update_forbidden'
Assert-NotContains $broker 'DELETE FROM' 'owner_fqa_direct_delete_forbidden'
Assert-NotContains $broker 'register_user' 'owner_fqa_account_creation_forbidden'
Assert-NotContains $broker 'createClassmate' 'owner_fqa_identity_creation_forbidden'
Assert-NotContains $broker 'issueFamilyInvitation' 'owner_fqa_invitation_creation_forbidden'
Assert-NotContains $broker 'createSubmission' 'owner_fqa_submission_creation_forbidden'

foreach ($needle in @(
    'scope=SYNTHETIC_RANDOM_PREFIX',
    'v4fqaBuildCredentialPlan',
    'v4fqaWriteCredentialFile',
    'v4fqaInstallCredentialPlan',
    'v4fqaReadCredentialPlan',
    'v4fqaCloseCredentialPlan',
    'helper_throw_finally=CLOSED',
    'crash_first=RECOVERED',
    'crash_middle=RECOVERED',
    'admin_change=PRESERVED_CONFLICT',
    'missing_plan=DENIED',
    'topology_drift=CLEANED_CONFLICT',
    'cleanup_throw=DENIED_CONFLICT',
    'simulated_cleanup_revoke_throw',
    'v4fqaQuarantineTopologyConflict',
    'recoverAbandonedIdentityLease',
    'DROP TABLE IF EXISTS'
)) {
    Assert-Contains $brokerRuntime $needle ('owner_fqa_broker_runtime_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

$topologyQuarantineCall = $broker.IndexOf('$topologyQuarantined = v4fqaQuarantineTopologyConflict(')
$topologyTerminal = $broker.IndexOf('$cleanupTerminal = true;', $topologyQuarantineCall)
Assert-True ($topologyQuarantineCall -ge 0 -and $topologyTerminal -gt $topologyQuarantineCall) 'owner_fqa_topology_terminal_before_quarantine'
Assert-Contains $broker '$cleanupTerminal = v4fqaQuarantineTopologyConflict(' 'owner_fqa_finally_exception_quarantine_missing'

Assert-Contains $wrapper '[switch]$ConfirmFqaCredentialLease' 'owner_fqa_explicit_switch_missing'
Assert-Contains $wrapper 'explicit_fqa_credential_lease_confirmation_required' 'owner_fqa_no_switch_guard_missing'
Assert-Contains $wrapper '$runtimeLeaseMutationExclusionProven = $true' 'owner_fqa_runtime_cas_capability_missing'
Assert-Contains $wrapper 'lease_runtime_disabled_pending_mutation_exclusion' 'owner_fqa_mutation_exclusion_block_missing'
Assert-Contains $wrapper "'-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1'" 'owner_fqa_private_mode_not_process_scoped'
Assert-Contains $wrapper '[IO.FileShare]::None' 'owner_fqa_host_lock_missing'
Assert-Contains $wrapper 'CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS' 'owner_fqa_wrapper_ttl_missing'
Assert-Contains $wrapper '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php' 'owner_fqa_wrapper_broker_missing'
Assert-Contains $wrapper "WriteLine('STOP ' + `$Run)" 'owner_fqa_wrapper_stop_missing'
Assert-Contains $wrapper 'finally {' 'owner_fqa_wrapper_finally_missing'
Assert-Contains $wrapper 'Set-ClassArchiveOwnerOnlyFileAcl' 'owner_fqa_host_credential_acl_missing'
Assert-Contains $wrapper 'Assert-NoReparseAncestor' 'owner_fqa_reparse_ancestor_guard_missing'
Assert-Contains $wrapper 'Assert-PrivateParentAcl -Candidate $HostPath' 'owner_fqa_credential_parent_acl_guard_missing'
Assert-Contains $wrapper '$trustedBoundary = $trustedRoot + $separator' 'owner_fqa_credential_ancestor_acl_boundary_missing'
Assert-Contains $wrapper 'Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName' 'owner_fqa_credential_ancestor_acl_walk_missing'
Assert-Contains $wrapper 'Copy-FqaCredentialFromBroker' 'owner_fqa_private_credential_transport_missing'
Assert-Contains $wrapper "WriteLine('EXPORT ' + `$Run)" 'owner_fqa_broker_export_command_missing'
Assert-NotContains $wrapper "'base64', '-w0'" 'owner_fqa_second_exec_credential_transport_forbidden'
Assert-Contains $wrapper 'Initialize-FqaDurableRecoveryRoot' 'owner_fqa_durable_recovery_mount_preflight_missing'
Assert-Contains $wrapper "mountpoint -q -- " 'owner_fqa_durable_recovery_mount_attestation_missing'
Assert-Contains $wrapper "'/var/lib/class-archive-private-e2e/credentials-'" 'owner_fqa_durable_recovery_plan_path_missing'
Assert-Contains $wrapper 'Set-OwnerOnlyDirectoryAcl -Path $path' 'owner_fqa_run_directory_acl_missing'
Assert-Contains $wrapper 'Stop-FqaNativeProcessTree' 'owner_fqa_broker_process_reap_missing'
Assert-Contains $wrapper 'Invoke-FqaLeaseRecovery' 'owner_fqa_wrapper_recovery_missing'
Assert-Contains $wrapper 'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1' 'owner_fqa_wrapper_recovery_mode_missing'
Assert-Contains $wrapper 'V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked' 'owner_fqa_wrapper_recovery_attestation_missing'
Assert-Contains $wrapper 'New-FqaLeaseWatchdog' 'owner_fqa_durable_watchdog_script_missing'
Assert-Contains $wrapper 'Start-Sleep -Seconds ($LeaseTtlSeconds + 30)' 'owner_fqa_durable_watchdog_ttl_wait_missing'
Assert-Contains $wrapper 'Complete-WatchdogCredentialCleanup' 'owner_fqa_durable_watchdog_credential_cleanup_missing'
Assert-Contains $wrapper '# Preserve the owner-only recovery plan in the container until exact lease' 'owner_fqa_container_recovery_plan_preservation_missing'
Assert-Contains $wrapper 'if ($containerCredentialNeedsCleanup -and $leaseCloseAttested)' 'owner_fqa_container_plan_cleanup_gate_missing'
Assert-Contains $wrapper 'if ($leaseCloseAttested) {' 'owner_fqa_host_plan_cleanup_gate_missing'
Assert-Contains $wrapper 'WATCHDOG_RECOVERY_COMPLETE' 'owner_fqa_durable_watchdog_recovery_marker_missing'
Assert-Contains $wrapper '$leaseCloseAttested = Close-FqaLeaseBroker' 'owner_fqa_durable_watchdog_close_attestation_missing'
Assert-Contains $wrapper '$preserveRecoveryRuntime = $true' 'owner_fqa_durable_watchdog_runtime_preservation_missing'
Assert-Contains $wrapper 'if (-not $preserveRecoveryRuntime -and $leaseCloseAttested -and $watchdogReaped)' 'owner_fqa_durable_watchdog_cleanup_gate_missing'
Assert-Contains $wrapper 'Invoke-ClassArchiveBoundedNative' 'owner_fqa_bounded_browser_watchdog_missing'
Assert-NotContains $wrapper 'function Invoke-Piwigo' 'owner_fqa_unbounded_wsl_helper_forbidden'
Assert-Contains $wrapper '$browserPassRecord = $pass[0]' 'owner_fqa_pass_buffer_missing'
Assert-Contains $wrapper 'if ($cleanupFailed) {' 'owner_fqa_cleanup_gate_missing'
Assert-Contains $wrapper 'security_lease_writes=audited content_writes=0 teacher=not_tested' 'owner_fqa_honest_result_missing'
Assert-NotContains $wrapper 'ProvisionTemporaryRoles' 'owner_fqa_temporary_identity_creation_forbidden'
Assert-NotContains $wrapper 'provision-access-users.php' 'owner_fqa_legacy_fixture_provisioner_forbidden'
Assert-NotContains $wrapper 'docker compose up' 'owner_fqa_compose_up_forbidden'
Assert-NotContains $wrapper 'docker compose down' 'owner_fqa_compose_down_forbidden'
Assert-NotContains $wrapper 'docker volume' 'owner_fqa_volume_mutation_forbidden'

# Durable local-private lease storage and AdminService integration. Acquisition
# is gated; enforcement of an existing ACTIVE row is environment-independent.
foreach ($needle in @(
    "private const ENABLE_ENV = 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED'",
    "getenv(self::ENABLE_ENV) === '1'",
    "PHP_SAPI !== 'cli'",
    "['127.0.0.1', '::1']",
    'class_identity_private_e2e_disabled',
    'private_e2e_fixture_lease',
    '`test_run_id` CHAR(24)',
    '`fixture_owner` VARCHAR(64)',
    '`token_digest` BINARY(32)',
    '`expected_lock_version` INT UNSIGNED',
    '`lease_revision` INT UNSIGNED',
    '`ttl_seconds` SMALLINT UNSIGNED',
    '`heartbeat_at` DATETIME(6)',
    '`expires_at` DATETIME(6)',
    "'ACTIVE','RELEASED','ABANDONED','CONFLICT'",
    'uq_ci_fixture_lease_active_resource',
    'acquireIdentityLease',
    'heartbeat(',
    'beginIdentityMutation',
    'advanceIdentityMutation',
    'confirmIdentityMutationCommitted',
    'compareAndSetFixturePasswordHash',
    'compareAndSetLeasedPasswordHash',
    'assertIdentityHttpAuthorizationAllowed',
    'releaseIdentityLease',
    'recoverAbandonedIdentityLease',
    'markConflict',
    'class_identity_fixture_lease_abandoned_recovery_required',
    'class_identity_fixture_lease_recovery_version_conflict',
    'class_identity_fixture_lease_cas_conflict'
)) {
    Assert-Contains $service $needle ('owner_fqa_service_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $service 'Once an ACTIVE row exists, ordinary administrator mutation paths enforce it' 'owner_fqa_web_enforcement_contract_missing'
Assert-Contains $service 'Exact user id + exact binary username + exact current verifier' 'owner_fqa_cleanup_exact_identity_contract_missing'
Assert-Contains $service 'BINARY `username`=BINARY ? AND BINARY `password`=BINARY ?' 'owner_fqa_cleanup_username_and_hash_cas_missing'
Assert-Contains $service "bind_param('siss', `$replacementPasswordHash, `$userId, `$username, `$expectedPasswordHash)" 'owner_fqa_cleanup_cas_binding_missing'
Assert-Contains $adminService 'PrivateE2EFixtureLeaseService::fromPiwigo()' 'owner_fqa_admin_lease_service_missing'
Assert-Contains $adminService 'beginIdentityMutation($identityId, $fixtureLease)' 'owner_fqa_admin_mutation_guard_missing'
Assert-Contains $adminService 'WHERE id = ? AND state = ? AND lock_version = ?' 'owner_fqa_admin_true_cas_missing'
Assert-Contains $adminService 'advanceIdentityMutation($fixtureLease, $oldVersion, $newVersion)' 'owner_fqa_admin_lease_revision_advance_missing'
Assert-Contains $adminService 'confirmIdentityMutationCommitted(' 'owner_fqa_admin_commit_confirmation_missing'
Assert-Contains $adminService '$fixtureMutationGuard->release()' 'owner_fqa_admin_mutation_guard_release_missing'
Assert-Contains $main "src/PrivateE2EFixtureLeaseService.php" 'owner_fqa_service_bootstrap_missing'

Assert-Contains $docs 'Teacher is deliberately not covered' 'owner_fqa_teacher_blocker_docs_missing'
Assert-Contains $docs 'No identity, seat, account, token,' 'owner_fqa_no_creation_docs_missing'
Assert-Contains $docs 'freezes first' 'owner_fqa_freeze_first_docs_missing'
Assert-Contains $docs 'security-equivalent rather than byte-identical' 'owner_fqa_non_bit_identical_docs_missing'
Assert-Contains $docs '-ConfirmFqaCredentialLease' 'owner_fqa_command_docs_missing'

Write-Output "PHOTOS_APP_V4_OWNER_FQA_LEASE_PROTOCOL=PASS assertions=$assertions"
