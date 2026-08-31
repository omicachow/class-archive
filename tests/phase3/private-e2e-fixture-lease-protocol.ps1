[CmdletBinding()]
param()

# Pure protocol proof. It does not invoke Docker, HTTP, Chrome or a private
# Owner runtime; the companion PHP runtime uses only random-prefix synthetic
# tables and deletes them in finally.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$servicePath = Join-Path $projectRoot 'plugins\ClassIdentity\src\PrivateE2EFixtureLeaseService.php'
$adminPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\AdminService.php'
$accessPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Access.php'
$mainPath = Join-Path $projectRoot 'plugins\ClassIdentity\main.inc.php'
$brokerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-fqa-lease.php'
$runtimePath = Join-Path $projectRoot 'tests\phase3\private-e2e-fixture-lease-runtime.php'
$docsPath = Join-Path $projectRoot 'docs\private-e2e-fixture-lease.md'
$installerPath = Join-Path $projectRoot 'infra\scripts\install-class-archive-plugins.php'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Required([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}
function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True $Text.Contains($Needle) $Code
}

$service = Read-Required $servicePath 'private_e2e_fixture_lease_service_missing'
$admin = Read-Required $adminPath 'private_e2e_fixture_lease_admin_missing'
$access = Read-Required $accessPath 'private_e2e_fixture_lease_access_missing'
$main = Read-Required $mainPath 'private_e2e_fixture_lease_bootstrap_missing'
$broker = Read-Required $brokerPath 'private_e2e_fixture_lease_broker_missing'
$runtime = Read-Required $runtimePath 'private_e2e_fixture_lease_runtime_missing'
$docs = Read-Required $docsPath 'private_e2e_fixture_lease_docs_missing'
$installer = Read-Required $installerPath 'private_e2e_fixture_lease_installer_missing'

foreach ($needle in @(
    "private const ENABLE_ENV = 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED'",
    "getenv(self::ENABLE_ENV) === '1'",
    "PHP_SAPI !== 'cli'",
    "['127.0.0.1', '::1']",
    'class_identity_private_e2e_disabled',
    'class_identity_private_e2e_fixture_lease',
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
    'releaseIdentityLease',
    'recoverAbandonedIdentityLease',
    'markConflict',
    'activeIdentityLeaseMetadata',
    'assertIdentityHttpAuthorizationAllowed',
    'public function targetKind(): string',
    'public function targetId(): int',
    'public function owner(): string',
    'public function versionToken(): string',
    "'target_kind' => (string) `$row['resource_type']",
    "'target_id' => (int) `$row['resource_id']",
    "'test_run_id' => (string) `$row['test_run_id']",
    "'owner' => (string) `$row['fixture_owner']",
    "'fixture_owner' => (string) `$row['fixture_owner']",
    "'expected_lock_version' => (int) `$row['expected_lock_version']",
    "'lease_revision' => (int) `$row['lease_revision']",
    "'version_token' => (int) `$row['expected_lock_version'] . ':' . (int) `$row['lease_revision']",
    'class_identity_fixture_lease_abandoned_recovery_required',
    'class_identity_fixture_lease_recovery_version_conflict',
    'class_identity_fixture_lease_recovery_owner_conflict',
    'class_identity_fixture_lease_cas_conflict',
    'class_identity_fixture_lease_http_authorization_expired',
    'class_identity_fixture_lease_http_authorization_conflict',
    'class_identity_fixture_lease_http_authorization_version_conflict',
    'class_identity_fixture_lease_context_not_serializable',
    'class_identity_fixture_lease_conflict_reconciliation_required'
)) {
    Assert-Contains $service $needle ('private_e2e_fixture_lease_service_token_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $service 'Once an ACTIVE row exists, ordinary administrator mutation paths enforce it' 'private_e2e_fixture_lease_web_enforcement_missing'
Assert-Contains $service 'TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6))' 'private_e2e_fixture_lease_database_ttl_missing'
Assert-Contains $service '`expires_at`>UTC_TIMESTAMP(6)' 'private_e2e_fixture_lease_expiry_guard_missing'
Assert-Contains $service 'hash(''sha256'', $this->token, true)' 'private_e2e_fixture_lease_token_hash_missing'
Assert-Contains $service 'class_identity_fixture_lease_storage_invalid' 'private_e2e_fixture_lease_storage_fail_closed_missing'
Assert-Contains $service 'Exact user id + exact binary username + exact current verifier' 'private_e2e_fixture_lease_cleanup_identity_binding_missing'
Assert-Contains $service 'BINARY `username`=BINARY ? AND BINARY `password`=BINARY ?' 'private_e2e_fixture_lease_cleanup_username_hash_cas_missing'

Assert-Contains $admin 'PrivateE2EFixtureLeaseService::fromPiwigo()' 'private_e2e_fixture_lease_admin_service_missing'
Assert-Contains $admin 'beginIdentityMutation($identityId, $fixtureLease)' 'private_e2e_fixture_lease_admin_guard_missing'
Assert-Contains $admin 'WHERE id = ? AND state = ? AND lock_version = ?' 'private_e2e_fixture_lease_admin_true_cas_missing'
Assert-Contains $admin 'advanceIdentityMutation($fixtureLease, $oldVersion, $newVersion)' 'private_e2e_fixture_lease_admin_revision_advance_missing'
Assert-Contains $admin 'confirmIdentityMutationCommitted(' 'private_e2e_fixture_lease_admin_commit_confirmation_missing'
Assert-True ($admin.IndexOf('$this->commit();') -lt $admin.LastIndexOf('confirmIdentityMutationCommitted(')) 'private_e2e_fixture_lease_context_advanced_before_commit'
Assert-Contains $admin '$fixtureMutationGuard->release()' 'private_e2e_fixture_lease_admin_guard_release_missing'
Assert-Contains $access '->assertIdentityHttpAuthorizationAllowed((int) $context[''identity_id''])' 'private_e2e_fixture_lease_http_access_bridge_missing'
Assert-Contains $access '// A live local-private fixture lease is an intentional, bounded' 'private_e2e_fixture_lease_http_access_contract_missing'
Assert-Contains $main "src/PrivateE2EFixtureLeaseService.php" 'private_e2e_fixture_lease_main_include_missing'
Assert-Contains $installer "'src/PrivateE2EFixtureLeaseService.php'" 'private_e2e_fixture_lease_installer_manifest_missing'
Assert-Contains $service 'information_schema.COLUMNS' 'private_e2e_fixture_lease_column_attestation_missing'
Assert-Contains $service 'information_schema.STATISTICS' 'private_e2e_fixture_lease_index_attestation_missing'
Assert-Contains $service 'public function resolveConflictIdentityLease(' 'private_e2e_fixture_conflict_resolution_api_missing'
Assert-Contains $service "SET ``state``='RELEASED'" 'private_e2e_fixture_conflict_resolution_terminal_state_missing'
Assert-Contains $service "AND ``expected_lock_version``=? AND ``lease_revision``=?" 'private_e2e_fixture_conflict_resolution_cas_conjunction_missing'
Assert-Contains $service 'class_identity_fixture_lease_conflict_resolution_required' 'private_e2e_fixture_conflict_resolution_owner_run_guard_missing'
Assert-Contains $runtime 'ENGINE=MyISAM' 'private_e2e_fixture_lease_malformed_engine_fixture_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_storage_invalid' 'private_e2e_fixture_lease_malformed_storage_rejection_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_http_authorization_expired' 'private_e2e_fixture_lease_runtime_expired_http_deny_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_http_authorization_conflict' 'private_e2e_fixture_lease_runtime_conflict_http_deny_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_http_authorization_version_conflict' 'private_e2e_fixture_lease_runtime_version_drift_http_deny_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_context_not_serializable' 'private_e2e_fixture_lease_runtime_context_serialization_rejection_missing'
Assert-Contains $runtime 'class_identity_fixture_lease_recovery_owner_conflict' 'private_e2e_fixture_lease_runtime_recovery_owner_binding_missing'
Assert-Contains $runtime 'lease_context_advanced_before_commit' 'private_e2e_fixture_lease_runtime_rollback_context_proof_missing'
Assert-Contains $runtime 'fixture_credential_cleanup_username_rebind_allowed' 'private_e2e_fixture_lease_runtime_username_rebind_proof_missing'

Assert-Contains $broker 'const V4_FQA_RUNTIME_MUTATION_EXCLUSION_PROVEN = true;' 'private_e2e_fixture_lease_broker_capability_missing'
Assert-Contains $broker "getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'" 'private_e2e_fixture_lease_broker_enable_gate_missing'
Assert-Contains $broker 'acquireIdentityLease' 'private_e2e_fixture_lease_broker_acquire_missing'
Assert-Contains $broker 'heartbeat($fixtureLeaseContext, $ttl)' 'private_e2e_fixture_lease_broker_heartbeat_missing'
Assert-Contains $broker 'releaseIdentityLease($leaseContext)' 'private_e2e_fixture_lease_broker_release_missing'
Assert-Contains $broker 'recoverAbandonedIdentityLease' 'private_e2e_fixture_lease_broker_recovery_missing'
Assert-Contains $broker '// This is a single exact-version CAS, not a retrying rollback.' 'private_e2e_fixture_lease_broker_no_unsafe_rollback_missing'
Assert-True (-not $broker.Contains('for ($attempt = 0; $attempt < 3; $attempt++)')) 'private_e2e_fixture_lease_broker_unsafe_retry_present'

foreach ($needle in @(
    'product feature, registration path, administrator API, or HTTP endpoint',
    'Resource CAS binding',
    'Cleanup is one exact-version CAS',
    'never returns the bearer token or its digest',
    'drift records `CONFLICT` and does not roll back business state'
)) {
    Assert-Contains $docs $needle ('private_e2e_fixture_lease_docs_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Acquisition/recovery are internal CLI broker calls only. No plugin HTTP
# controller, action or API route may call either primitive.
$pluginPhp = @(& git -C $projectRoot ls-files -- 'plugins/ClassIdentity/*.php' 'plugins/ClassIdentity/**/*.php')
Assert-True ($LASTEXITCODE -eq 0) 'private_e2e_fixture_lease_plugin_inventory_failed'
foreach ($relative in $pluginPhp) {
    if ($relative.Replace('\', '/') -eq 'plugins/ClassIdentity/src/PrivateE2EFixtureLeaseService.php') { continue }
    $source = [IO.File]::ReadAllText((Join-Path $projectRoot $relative))
    Assert-True (-not $source.Contains('->acquireIdentityLease(')) ('private_e2e_fixture_lease_http_acquire_call_' + ($relative -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
    Assert-True (-not $source.Contains('->recoverAbandonedIdentityLease(')) ('private_e2e_fixture_lease_http_recover_call_' + ($relative -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

foreach ($needle in @(
    'random table prefix',
    "putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED')",
    'class_identity_private_e2e_disabled',
    'class_identity_fixture_lease_conflict',
    'class_identity_fixture_lease_expired_recovery_required',
    'class_identity_fixture_lease_release_version_conflict',
    'recoverAbandonedIdentityLease',
    'DROP TABLE IF EXISTS'
)) {
    Assert-Contains $runtime $needle ('private_e2e_fixture_lease_runtime_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The opt-in flag may appear in local test harnesses, but never in a tracked
# deployment environment or Compose definition.
$deploymentFiles = @(& git -C $projectRoot ls-files -- 'infra/*.yml' 'infra/**/*.yml' 'infra/*.yaml' 'infra/**/*.yaml' 'infra/*.env*')
Assert-True ($LASTEXITCODE -eq 0) 'private_e2e_fixture_lease_git_inventory_failed'
foreach ($relative in $deploymentFiles) {
    $source = [IO.File]::ReadAllText((Join-Path $projectRoot $relative))
    Assert-True (-not $source.Contains('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1')) ('private_e2e_fixture_lease_enabled_in_deployment_' + ($relative -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PRIVATE_E2E_FIXTURE_LEASE_PROTOCOL=PASS assertions=$assertions deployment_files=$($deploymentFiles.Count) plugin_files=$($pluginPhp.Count) http_fixture_endpoint=ABSENT"
