[CmdletBinding()]
param()

# Static boundary contract for the generic Teacher fixture lease adapter. It
# does not invoke Docker, Chrome, HTTP, M: sources or a private Owner runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$adapterPath = Join-Path $projectRoot 'tests\phase3\private-e2e-teacher-fixture-lease.php'
$runtimePath = Join-Path $projectRoot 'tests\phase3\private-e2e-teacher-fixture-lease-runtime.php'
$fqaBrokerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-fqa-lease.php'
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

foreach ($path in @($adapterPath, $runtimePath, $fqaBrokerPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('teacher_fixture_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}
$adapter = [IO.File]::ReadAllText($adapterPath)
$runtime = [IO.File]::ReadAllText($runtimePath)
$fqaBroker = [IO.File]::ReadAllText($fqaBrokerPath)

foreach ($needle in @(
    "const PRIVATE_E2E_TEACHER_FIXTURE_OWNER = 'v4-owner-teacher-fixture-broker';",
    "const PRIVATE_E2E_TEACHER_FIXTURE_ROSTER_PREFIX = 'FQA-T-';",
    "const PRIVATE_E2E_TEACHER_FIXTURE_ROLE = 'TEACHER';",
    "const PRIVATE_E2E_TEACHER_FIXTURE_ACK = 'LEASED_TEACHER_FIXTURE_V1';",
    "getenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') !== '1'",
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE') !== '1'",
    "getenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK')",
    "PHP_SAPI !== 'cli'",
    "privateE2ETeacherFixtureValidateDescriptor",
    "privateE2ETeacherFixtureAcquireLease",
    "acquireIdentityLease(",
    "compareAndSetFixturePasswordHash(",
    "compareAndSetLeasedPasswordHash(",
    "releaseIdentityLease(",
    "privateE2ETeacherFixtureRecoveryDocument",
    "privateE2ETeacherFixtureBrowserCredentialDocument",
    "PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY",
    "teacher_fixture_orchestrator_required"
)) {
    Assert-Contains $adapter $needle ('teacher_fixture_adapter_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($forbidden in @('$_GET', '$_POST', '$_REQUEST', 'add_event_handler', 'register_api_handler', 'CoreAdapter::registerUser', 'createIdentity(')) {
    Assert-NotContains $adapter $forbidden ('teacher_fixture_adapter_http_or_provisioning_surface_present_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $adapter "|| str_starts_with((string) `$roster, 'FQA-C-')" 'teacher_fixture_fqa_aggregate_reuse_not_rejected'
Assert-Contains $adapter "`$identity['identity_type'] !== PRIVATE_E2E_TEACHER_FIXTURE_ROLE" 'teacher_fixture_identity_role_guard_missing'
Assert-Contains $adapter "'reason_code' => 'TEACHER_FIXTURE'" 'teacher_fixture_audit_reason_code_missing'
Assert-NotContains $adapter 'LOCAL_PRIVATE_E2E_TEACHER_FIXTURE_LEASE' 'teacher_fixture_audit_reason_code_looks_credential_like'
Assert-Contains $adapter "`$seat['seat_type'] !== PRIVATE_E2E_TEACHER_FIXTURE_ROLE" 'teacher_fixture_seat_role_guard_missing'
Assert-Contains $adapter "`$identity['state'] !== 'FROZEN'" 'teacher_fixture_frozen_preflight_missing'
Assert-Contains $adapter "`$account['current_marker'] !== 1" 'teacher_fixture_current_account_guard_missing'
Assert-Contains $adapter "`$principal['principal_type'] !== 'SEAT_ACCOUNT'" 'teacher_fixture_principal_guard_missing'
Assert-Contains $adapter "`$revokeCredentials(`$fixture['user_id']);" 'teacher_fixture_unconditional_credential_revoke_missing'
Assert-Contains $adapter "'LEASE_CONFLICT'" 'teacher_fixture_cleanup_conflict_audit_missing'
Assert-Contains $adapter "'before_password_sha256' => hash('sha256', `$beforePasswordHash)" 'teacher_fixture_recovery_before_digest_missing'
Assert-Contains $adapter "'lease_password_sha256' => hash('sha256', `$leasePasswordHash)" 'teacher_fixture_recovery_lease_digest_missing'
Assert-NotContains $adapter "'before_password_hash' =>" 'teacher_fixture_recovery_before_hash_exposed'
Assert-NotContains $adapter "'lease_password_hash' =>" 'teacher_fixture_recovery_lease_hash_exposed'

# The legacy aggregate stays exactly three roles; Teacher is a separate fixture
# adapter, not an extra seat hidden inside the Classmate test identity.
Assert-Contains $fqaBroker "const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY'];" 'teacher_fixture_changed_historical_fqa_roles'
Assert-NotContains $fqaBroker "const V4_FQA_ROLES = ['ANONYMOUS', 'CLASSMATE', 'FAMILY', 'TEACHER'];" 'teacher_fixture_fqa_teacher_injection_present'

foreach ($needle in @(
    'random MariaDB table prefix',
    "define('PRIVATE_E2E_TEACHER_FIXTURE_LIBRARY_ONLY', true)",
    "putenv('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1')",
    "putenv('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1')",
    'teacher_fixture_disabled',
    'teacher_fixture_descriptor_invariant_failed',
    'teacher_fixture_credential_install_invalid',
    'teacher_fixture_credential_cleanup_invalid',
    'teacher_fixture_recovery_secret_boundary_invalid',
    'DROP TABLE IF EXISTS'
)) {
    Assert-Contains $runtime $needle ('teacher_fixture_runtime_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-NotContains $runtime '127.0.0.1:819' 'teacher_fixture_runtime_private_endpoint_present'
Assert-NotContains $runtime 'chrome' 'teacher_fixture_runtime_browser_present'
$privateDrivePrefix = ([char]77).ToString() + ':' + ([char]92).ToString()
Assert-NotContains $runtime $privateDrivePrefix 'teacher_fixture_runtime_source_path_present'

$deploymentFiles = @(& git -C $projectRoot ls-files -- 'infra/*.yml' 'infra/**/*.yml' 'infra/*.yaml' 'infra/**/*.yaml' 'infra/*.env*')
Assert-True ($LASTEXITCODE -eq 0) 'teacher_fixture_deployment_inventory_failed'
foreach ($relative in $deploymentFiles) {
    $source = [IO.File]::ReadAllText((Join-Path $projectRoot $relative))
    Assert-True (-not $source.Contains('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1')) ('teacher_fixture_enabled_in_deployment_' + ($relative -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PRIVATE_E2E_TEACHER_FIXTURE_LEASE_PROTOCOL=PASS assertions=$assertions deployment_files=$($deploymentFiles.Count) http_fixture_endpoint=ABSENT mode=DISABLED_BY_DEFAULT"
