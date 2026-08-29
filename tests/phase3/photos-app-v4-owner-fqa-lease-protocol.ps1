[CmdletBinding()]
param()

# Pure static contract for the owner FQA lease broker. It does not invoke
# Docker, PHP, HTTP, Chrome, or the lease confirmation switch.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path (Join-Path $PSScriptRoot '..') '..')).Path
$brokerPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-fqa-lease.php'
$wrapperPath = Join-Path $projectRoot 'tests/phase3/photos-app-v4-owner-browser-qa.ps1'
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

foreach ($path in @($brokerPath, $wrapperPath, $docsPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('owner_fqa_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}
$broker = [IO.File]::ReadAllText($brokerPath)
$wrapper = [IO.File]::ReadAllText($wrapperPath)
$docs = [IO.File]::ReadAllText($docsPath)

Assert-Contains $broker "const V4_FQA_ROSTER = 'FQA-C-99CA3B3B6AF1'" 'owner_fqa_candidate_not_pinned'
Assert-Contains $broker "const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY']" 'owner_fqa_role_set_invalid'
Assert-Contains $broker 'const V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN = false;' 'owner_fqa_broker_runtime_hard_block_missing'
Assert-Contains $broker "'mutation_exclusion_unavailable'" 'owner_fqa_broker_mutation_exclusion_code_missing'
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
Assert-Contains $broker 'CoreAdapter::setPassword' 'owner_fqa_password_hasher_missing'
Assert-Contains $broker 'CoreAdapter::revokeAllCredentials' 'owner_fqa_credential_revocation_missing'
Assert-Contains $broker "'action' => 'PRINCIPAL_SECURITY_CHANGE'" 'owner_fqa_security_audit_missing'
Assert-Contains $broker "'reason_code' => 'LOCAL_FQA_LEASE'" 'owner_fqa_safe_audit_reason_code_missing'
Assert-Contains $broker "fopen(`$path, 'x')" 'owner_fqa_exclusive_credential_create_missing'
Assert-Contains $broker 'umask(0077)' 'owner_fqa_private_create_umask_missing'
Assert-Contains $broker 'chmod($path, 0600)' 'owner_fqa_credential_mode_missing'
Assert-Contains $broker "(`$stat['nlink'] ?? 0) !== 1" 'owner_fqa_credential_link_guard_missing'
Assert-Contains $broker "V4_OWNER_FQA_LEASE=READY roles=3 ttl=" 'owner_fqa_safe_ready_record_missing'
Assert-Contains $broker 'stream_select' 'owner_fqa_control_wait_missing'
Assert-Contains $broker "hash_equals('STOP ' . `$run" 'owner_fqa_authenticated_stop_missing'
Assert-Contains $broker 'time() < $deadline' 'owner_fqa_ttl_missing'
Assert-Contains $broker 'pcntl_signal' 'owner_fqa_signal_cleanup_missing'
Assert-Contains $broker 'finally {' 'owner_fqa_finally_missing'
Assert-Contains $broker 'v4fqaCloseAccess($db, $prefixeTable, $admin, $state, false)' 'owner_fqa_independent_recovery_missing'
Assert-Contains $broker 'candidate_changed_before_activation' 'owner_fqa_pre_activation_recheck_missing'
Assert-Contains $broker 'for ($attempt = 0; $attempt < 3; $attempt++)' 'owner_fqa_refreeze_retry_missing'
Assert-Contains $broker '// Identity activation is deliberately the final opening action.' 'owner_fqa_open_order_contract_missing'
Assert-Contains $broker '// Close access first.' 'owner_fqa_freeze_first_contract_missing'
Assert-Contains $broker "false," 'owner_fqa_unfreeze_call_missing'
Assert-Contains $broker "true," 'owner_fqa_refreeze_call_missing'
Assert-Contains $broker "`$state['lock_version'] + 1" 'owner_fqa_open_lock_version_check_missing'
Assert-Contains $broker "`$state['lock_version'] + 2" 'owner_fqa_close_lock_version_check_missing'
Assert-Contains $broker 'Access::resolveAuthorizationContext' 'owner_fqa_open_role_context_check_missing'
Assert-Contains $broker "auth_epoch'] < `$account['auth_epoch'] + 1" 'owner_fqa_close_epoch_check_missing'
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

Assert-Contains $wrapper '[switch]$ConfirmFqaCredentialLease' 'owner_fqa_explicit_switch_missing'
Assert-Contains $wrapper 'explicit_fqa_credential_lease_confirmation_required' 'owner_fqa_no_switch_guard_missing'
Assert-Contains $wrapper '$runtimeLeaseMutationExclusionProven = $false' 'owner_fqa_runtime_hard_block_missing'
Assert-Contains $wrapper 'lease_runtime_disabled_pending_mutation_exclusion' 'owner_fqa_mutation_exclusion_block_missing'
Assert-Contains $wrapper '[IO.FileShare]::None' 'owner_fqa_host_lock_missing'
Assert-Contains $wrapper 'CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS' 'owner_fqa_wrapper_ttl_missing'
Assert-Contains $wrapper '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php' 'owner_fqa_wrapper_broker_missing'
Assert-Contains $wrapper "WriteLine('STOP ' + `$Run)" 'owner_fqa_wrapper_stop_missing'
Assert-Contains $wrapper 'finally {' 'owner_fqa_wrapper_finally_missing'
Assert-Contains $wrapper 'Set-ClassArchiveOwnerOnlyFileAcl' 'owner_fqa_host_credential_acl_missing'
Assert-Contains $wrapper 'Assert-NoReparseAncestor' 'owner_fqa_reparse_ancestor_guard_missing'
Assert-Contains $wrapper 'Set-OwnerOnlyDirectoryAcl -Path $path' 'owner_fqa_run_directory_acl_missing'
Assert-Contains $wrapper '$browserPassRecord = $pass[0]' 'owner_fqa_pass_buffer_missing'
Assert-Contains $wrapper 'security_lease_writes=audited content_writes=0 teacher=not_tested' 'owner_fqa_honest_result_missing'
Assert-NotContains $wrapper 'ProvisionTemporaryRoles' 'owner_fqa_temporary_identity_creation_forbidden'
Assert-NotContains $wrapper 'provision-access-users.php' 'owner_fqa_legacy_fixture_provisioner_forbidden'
Assert-NotContains $wrapper 'docker compose up' 'owner_fqa_compose_up_forbidden'
Assert-NotContains $wrapper 'docker compose down' 'owner_fqa_compose_down_forbidden'
Assert-NotContains $wrapper 'docker volume' 'owner_fqa_volume_mutation_forbidden'

Assert-Contains $docs 'Teacher is deliberately not covered' 'owner_fqa_teacher_blocker_docs_missing'
Assert-Contains $docs 'No identity, seat, account, token,' 'owner_fqa_no_creation_docs_missing'
Assert-Contains $docs 'freezes first' 'owner_fqa_freeze_first_docs_missing'
Assert-Contains $docs 'security-equivalent rather than byte-identical' 'owner_fqa_non_bit_identical_docs_missing'
Assert-Contains $docs '-ConfirmFqaCredentialLease' 'owner_fqa_command_docs_missing'

Write-Output "PHOTOS_APP_V4_OWNER_FQA_LEASE_PROTOCOL=PASS assertions=$assertions"
