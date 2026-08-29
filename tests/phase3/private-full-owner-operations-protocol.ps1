[CmdletBinding()]
param()

# Static-only protocol gate for owner private-full backup/audit and MediaGuard
# attestation. It intentionally reads no ignored owner env, starts no Docker
# service, and never reaches private originals. Runtime evidence is created
# only by the explicitly-confirmed owner commands after deployment approval.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$paths = @{
    lifecycle = Join-Path $projectRoot 'infra\scripts\private-full.ps1'
    attestation = Join-Path $projectRoot 'infra\scripts\attest-private-full-media.ps1'
    attestationDomain = Join-Path $projectRoot 'plugins\ClassIdentity\src\MediaAttestation.php'
    ownerHttp = Join-Path $projectRoot 'tests\phase3\private-full-owner-media-http.ps1'
    ownerHttpRuntime = Join-Path $projectRoot 'tests\phase3\private-full-owner-media-http.php'
    ownerMediaRuntime = Join-Path $projectRoot 'tests\phase3\private-full-media-runtime.ps1'
    ownerMediaRuntimePhp = Join-Path $projectRoot 'tests\phase3\private-full-media-runtime.php'
    dev = Join-Path $projectRoot 'infra\scripts\dev.ps1'
}

$assertions = 0
function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Read-Source([string]$Name) {
    $path = [string]$paths[$Name]
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('private_full_owner_operations_missing_' + $Name)
    return [IO.File]::ReadAllText($path)
}

function Slice-OrFail([string]$Text, [string]$Start, [string]$End, [string]$Code) {
    $startIndex = $Text.IndexOf($Start, [StringComparison]::Ordinal)
    Assert-True ($startIndex -ge 0) ($Code + '_start_missing')
    $endIndex = $Text.IndexOf($End, $startIndex, [StringComparison]::Ordinal)
    Assert-True ($endIndex -gt $startIndex) ($Code + '_end_missing')
    return $Text.Substring($startIndex, $endIndex - $startIndex)
}

$lifecycle = Read-Source 'lifecycle'
$attestation = Read-Source 'attestation'
$domain = Read-Source 'attestationDomain'
$ownerHttp = Read-Source 'ownerHttp'
$ownerHttpRuntime = Read-Source 'ownerHttpRuntime'
$ownerMediaRuntime = Read-Source 'ownerMediaRuntime'
$ownerMediaRuntimePhp = Read-Source 'ownerMediaRuntimePhp'
$dev = Read-Source 'dev'

# Both the env reader and Compose configuration use UTF-8 paths. The
# lifecycle must establish a process-local UTF-8 boundary before it asks WSL
# for JSON, and must never fall back to the locale-sensitive wslpath round
# trip for the private owner checkout.
Assert-True ($lifecycle.Contains('function Set-PrivateFullUtf8ConsoleEncoding')) 'owner_runtime_utf8_console_guard_missing'
Assert-True ($lifecycle.Contains('[Console]::OutputEncoding = $utf8')) 'owner_runtime_utf8_console_encoding_not_set'
Assert-True ($lifecycle.Contains('$script:OutputEncoding = $utf8')) 'owner_runtime_native_output_encoding_not_set'
Assert-True ($lifecycle.Contains("Stop-PrivateFull 'utf8_console_encoding_unavailable'")) 'owner_runtime_utf8_console_not_fail_closed'
Assert-True (-not ($lifecycle -match '(?m)^\s*\$result\s*=\s*@\(&\s*\$wsl\b.*\bwslpath\s+-[aw]\b')) 'owner_runtime_locale_sensitive_wslpath_reintroduced'
Assert-True ($lifecycle.Contains("if (`$Path -notmatch '^/mnt/([a-zA-Z])/(.+)$')")) 'owner_runtime_strict_wsl_to_windows_parser_missing'
Assert-True ($lifecycle.Contains("if (`$full -notmatch '^([a-zA-Z]):\\(.+)$')")) 'owner_runtime_strict_windows_to_wsl_parser_missing'

# 8191 owner backup is a separate, deliberately confirmed action. It may not
# fall through to staging's default lifecycle behavior.
Assert-True ($lifecycle.Contains("'backup-owner'")) 'owner_backup_action_missing'
Assert-True ($lifecycle -match '(?s)\[switch\]\$ConfirmOwnerPrivateBackup') 'owner_backup_confirmation_switch_missing'
$backupBranch = Slice-OrFail $lifecycle "if (`$Action -eq 'backup-owner')" "`$singleEndpointActions = @{" 'owner_backup_branch'
Assert-True ($backupBranch.Contains('ConfirmOwnerPrivateBackup.IsPresent')) 'owner_backup_confirmation_not_enforced'
Assert-True ($backupBranch.Contains("Get-ValidatedEndpoint 'owner'")) 'owner_backup_not_bound_to_owner_env'
Assert-True ($backupBranch.Contains('Assert-GatewaySubnetAvailable')) 'owner_backup_gateway_boundary_missing'
Assert-True ($backupBranch.Contains('Invoke-OwnerBusinessBackup $endpoint')) 'owner_backup_business_runner_missing'
Assert-True ($backupBranch.Contains('endpoint=8190_8191')) 'owner_backup_loopback_scope_missing'
Assert-True ($backupBranch.Contains('backup=CREATED_AND_AUDITED')) 'owner_backup_audit_result_missing'
Assert-True ($backupBranch.Contains('restore=NOT_RUN')) 'owner_backup_restore_boundary_missing'

$backupMatch = [regex]::Match($lifecycle, '(?s)function Invoke-OwnerBusinessBackup.*?\r?\n}\r?\n\r?\ntry \{')
Assert-True $backupMatch.Success 'owner_backup_function_missing'
$backupFunction = if ($backupMatch.Success) { $backupMatch.Value } else { '' }
Assert-True ($backupFunction.Contains("[string]`$Endpoint.mode -ne 'owner'")) 'owner_backup_endpoint_type_guard_missing'
Assert-True ($backupFunction.Contains('Assert-EndpointRuntime $Endpoint')) 'owner_backup_preflight_runtime_missing'
Assert-True ($backupFunction.Contains("[string]`$before.core_http -ne 'READY'")) 'owner_backup_requires_normal_http_missing'
Assert-True ($backupFunction.Contains("Invoke-Compose `$Endpoint.piwigoPrefix @('stop', 'piwigo')")) 'owner_backup_does_not_stop_writer_only'
Assert-True ($backupFunction.Contains('Assert-OwnerPiwigoStoppedForBackup')) 'owner_backup_stop_state_assertion_missing'
Assert-True ($backupFunction.Contains("'CLASS_ARCHIVE_BACKUP_QUIESCED=true', 'backup'")) 'owner_backup_quiesce_gate_missing'
Assert-True ($backupFunction.Contains("'CLASS_ARCHIVE_BACKUP_AUDIT_WRITE=true', 'backup-audit'")) 'owner_backup_audit_gate_missing'
Assert-True ($backupFunction -match '(?s)try\s*\{.*?finally\s*\{.*?Invoke-Compose \$Endpoint\.piwigoPrefix @\(\x27start\x27, \x27piwigo\x27\).*?Assert-EndpointRuntime \$Endpoint') 'owner_backup_restart_finally_missing'
Assert-True ($backupFunction.Contains("[string]`$after.core_http -ne 'READY'")) 'owner_backup_recovery_runtime_missing'
foreach ($forbidden in @('Stop-Endpoint', 'Invoke-PrivateQa', "'restore'", "'down'", "'prune'", "'rm'", '--apply-rejected-cleanup')) {
    Assert-True (-not $backupFunction.Contains($forbidden)) ('owner_backup_forbidden_operation_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$stopFunction = Slice-OrFail $lifecycle 'function Assert-OwnerPiwigoStoppedForBackup' 'function Invoke-OwnerBusinessBackup' 'owner_backup_stop_assertion'
Assert-True ($lifecycle.Contains("`$piwigoProject = 'class_archive_private_full_v3_piwigo'")) 'owner_backup_project_identity_missing'
Assert-True ($stopFunction.Contains("`$piwigoProject + '-piwigo-1'")) 'owner_backup_exact_container_missing'
Assert-True ($stopFunction.Contains("'false|exited'")) 'owner_backup_exact_stopped_state_missing'

# Owner attestation never delegates to the synthetic attestation writer. It
# runs Phase 0 as a component but binds a separate suite id plus two owner
# runtime probes to the owner Piwigo persistence record.
Assert-True ($attestation -match '(?s)\[switch\]\$ConfirmOwnerPrivateAttestation') 'owner_attestation_confirmation_switch_missing'
Assert-True ($attestation.Contains('ConfirmOwnerPrivateAttestation.IsPresent')) 'owner_attestation_confirmation_not_enforced'
Assert-True ($attestation.Contains('git -C $projectRoot status --porcelain')) 'owner_attestation_clean_checkout_missing'
Assert-True ($attestation.Contains('$phase0Runner test-phase0')) 'owner_attestation_phase0_missing'
Assert-True ($attestation.Contains('$privateLifecycle runtime-owner')) 'owner_attestation_owner_runtime_missing'
Assert-True ($attestation.Contains('$ownerMediaRuntime -Mode owner')) 'owner_attestation_owner_media_runtime_missing'
Assert-True ($attestation.Contains('$ownerMediaHttp')) 'owner_attestation_owner_media_http_missing'
Assert-True ($attestation.Contains('$ownerOperationsProtocol')) 'owner_attestation_operations_protocol_missing'
Assert-True ($attestation.Contains("'infra/private-full/.env.piwigo.owner'")) 'owner_attestation_owner_env_missing'
Assert-True ($attestation.Contains("'--test-suite-version=private-full-owner-media-guard-v1'")) 'owner_attestation_owner_suite_missing'
Assert-True ($attestation.Contains("'exec', '-T', '--user', 'nginx', 'piwigo'")) 'owner_attestation_nonroot_runtime_missing'
Assert-True ($attestation.Contains('Get-OnlySafeRecord')) 'owner_attestation_safe_output_filter_missing'
Assert-True ($attestation.Contains('attestation_checkout_changed')) 'owner_attestation_post_test_clean_check_missing'
Assert-True ($attestation.Contains('scope=OWNER_8190_8191')) 'owner_attestation_scope_summary_missing'
Assert-True (-not $attestation.Contains('attest-media.ps1')) 'owner_attestation_misuses_synthetic_record'
Assert-True (-not $attestation.Contains("'restore'")) 'owner_attestation_restore_forbidden'

# Changing any private owner test/config/script that participates in the
# evidence requires another attestation. An unknown suite must fail closed.
Assert-True ($domain.Contains("TEST_SUITE_PRIVATE_FULL_OWNER = 'private-full-owner-media-guard-v1'")) 'owner_attestation_domain_suite_missing'
Assert-True ($domain.Contains('currentEvidence((string) $record[''test_suite_version''])')) 'owner_attestation_status_suite_binding_missing'
Assert-True ($domain.Contains('currentEvidence($testSuiteVersion)')) 'owner_attestation_create_suite_binding_missing'
foreach ($requiredPath in @(
    '/workspace/infra/scripts/attest-private-full-media.ps1',
    '/workspace/infra/scripts/private-full.ps1',
    '/workspace/tests/phase3/private-full-media-runtime.ps1',
    '/workspace/tests/phase3/private-full-owner-media-http.ps1',
    '/workspace/tests/phase3/private-full-owner-media-http.php',
    '/workspace/tests/phase3/private-full-owner-operations-protocol.ps1'
)) {
    Assert-True ($domain.Contains($requiredPath)) ('owner_attestation_digest_input_missing_' + ([IO.Path]::GetFileName($requiredPath) -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($domain.Contains('default => throw new') -and $domain.Contains('class_identity_media_attestation_suite_unknown')) 'owner_attestation_unknown_suite_not_fail_closed'

# The actual owner HTTP fixture is loopback-only, read-only and does not print
# real-library identifiers. It makes the direct original/derivative request
# path part of the owner attestation rather than merely trusting the BFF.
Assert-True ($ownerHttp.Contains("'infra/private-full/.env.piwigo.owner'")) 'owner_media_http_owner_env_missing'
Assert-True ($ownerHttp.Contains("[ValidateSet('owner', 'restore')]")) 'owner_media_http_runtime_selector_missing'
Assert-True ($ownerHttp.Contains("'infra/owner-restore/.env.piwigo'")) 'owner_media_http_restore_env_missing'
Assert-True ($ownerHttp.Contains("'class_archive_owner_restore_v1_piwigo'")) 'owner_media_http_restore_project_missing'
Assert-True ($ownerHttp.Contains("'RESTORE_8290'")) 'owner_media_http_restore_scope_missing'
Assert-True ($ownerHttp.Contains("'-d', 'Ubuntu'")) 'owner_media_http_wsl_boundary_missing'
Assert-True ($ownerHttpRuntime.Contains('START TRANSACTION READ ONLY')) 'owner_media_http_read_only_missing'
Assert-True ($ownerHttpRuntime.Contains('tcp://127.0.0.1:80')) 'owner_media_http_loopback_missing'
Assert-True ($ownerHttpRuntime.Contains("['GET', 'HEAD', 'RANGE']")) 'owner_media_http_method_matrix_missing'
Assert-True ($ownerHttpRuntime.Contains('status === 403')) 'owner_media_http_guest_deny_missing'
Assert-True ($ownerHttpRuntime.Contains('direct_guest_requests=') -and $ownerHttpRuntime.Contains("['OWNER_8190', 'RESTORE_8290']")) 'owner_media_http_safe_summary_missing'
Assert-True (-not ($ownerHttpRuntime -match '(?i)(?:source_root|staging_path|relative_source_path|original_filename|absolute_path)')) 'owner_media_http_private_field_reference_detected'
Assert-True ($ownerMediaRuntime.Contains("[ValidateSet('staging', 'owner')]")) 'owner_media_runtime_mode_missing'
Assert-True ($ownerMediaRuntime.Contains(".env.piwigo.owner")) 'owner_media_runtime_owner_env_missing'
Assert-True ($ownerMediaRuntimePhp.Contains('START TRANSACTION READ ONLY')) 'owner_media_runtime_php_read_only_missing'
Assert-True ($ownerMediaRuntimePhp.Contains('WHERE `state`=''COMPLETED'' ORDER BY `completed_at` ASC,`import_id` ASC')) 'owner_media_runtime_all_completed_imports_missing'
Assert-True (-not ($ownerMediaRuntimePhp.Contains('ORDER BY `completed_at` DESC LIMIT 1'))) 'owner_media_runtime_latest_import_regression'
Assert-True ($ownerMediaRuntimePhp.Contains('unique_applied_photo_count')) 'owner_media_runtime_unique_photo_aggregate_missing'
Assert-True ($ownerMediaRuntimePhp.Contains('unique_applied_image_count')) 'owner_media_runtime_unique_image_aggregate_missing'
Assert-True ($ownerMediaRuntimePhp.Contains('completed_import_applied_target_ambiguous')) 'owner_media_runtime_applied_ambiguity_fail_closed_missing'
Assert-True ($ownerMediaRuntimePhp.Contains('completed_import_journal_invalid')) 'owner_media_runtime_journal_drift_fail_closed_missing'
Assert-True (-not ($ownerMediaRuntimePhp -match '(?im)\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|OUTFILE)\b')) 'owner_media_runtime_php_mutation_statement_detected'
Assert-True (-not ($ownerMediaRuntimePhp -match '(?i)(?:source_root|staging_path|relative_source_path|original_filename)')) 'owner_media_runtime_php_private_field_read_detected'

# Public synthetic Phase 3 contract must keep this pure static guard in its
# suite, without running the owner commands or accessing their ignored env.
Assert-True ($dev.Contains('private-full-owner-operations-protocol.ps1')) 'owner_operations_protocol_not_in_synthetic_contract'

Write-Output "PRIVATE_FULL_OWNER_OPERATIONS_PROTOCOL=PASS assertions=$assertions"
