[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$operator = Join-Path $root 'infra\scripts\private-role-shadow.ps1'
$clone = Join-Path $root 'infra\scripts\clone-private-role-shadow.sh'
$piwigo = Join-Path $root 'infra\private-role-shadow\docker-compose.piwigo.override.yml'
$immich = Join-Path $root 'infra\private-role-shadow\docker-compose.immich.override.yml'
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw "PRIVATE_ROLE_SHADOW_PROTOCOL=FAIL code=$Code assertions=$assertions" }
    $script:assertions++
}

foreach ($path in @($operator, $clone, $piwigo, $immich)) {
    Assert-Protocol (Test-Path -LiteralPath $path -PathType Leaf) 'source_missing'
}

$operatorText = [IO.File]::ReadAllText($operator)
$cloneText = [IO.File]::ReadAllText($clone)
$composeText = [IO.File]::ReadAllText($piwigo) + [IO.File]::ReadAllText($immich)

Assert-Protocol $operatorText.Contains("[string]`$Action = 'validate'") 'validate_not_default'
Assert-Protocol $operatorText.Contains('CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED') 'disabled_gate_missing'
Assert-Protocol $operatorText.Contains('ConfirmPrivateRoleShadow') 'confirmation_missing'
Assert-Protocol $operatorText.Contains('ConfirmCleanup') 'cleanup_confirmation_missing'
Assert-Protocol $operatorText.Contains("`$httpPort = 11990") 'core_port_not_fixed'
Assert-Protocol $operatorText.Contains("`$compatPort = 11991") 'compat_port_not_fixed'
Assert-Protocol $operatorText.Contains('Get-ProtectedRuntimeFingerprint') 'protected_fingerprint_missing'
Assert-Protocol $operatorText.Contains("@('inspect') + `$ids") 'protected_fingerprint_bulk_inspect_missing'
Assert-Protocol $operatorText.Contains('ConvertFrom-Json -ErrorAction Stop') 'protected_fingerprint_json_parse_missing'
Assert-Protocol $operatorText.Contains('$containers = @($parsed)') 'protected_fingerprint_ps51_array_flatten_missing'
Assert-Protocol $operatorText.Contains("'container_fingerprint_count_invalid'") 'protected_fingerprint_count_guard_missing'
Assert-Protocol $operatorText.Contains("'container_fingerprint_id_set_invalid'") 'protected_fingerprint_id_guard_missing'
Assert-Protocol $operatorText.Contains("'container_fingerprint_state_invalid'") 'protected_fingerprint_state_guard_missing'
Assert-Protocol $operatorText.Contains("'container_fingerprint_mount_invalid'") 'protected_fingerprint_mount_guard_missing'
Assert-Protocol $operatorText.Contains("'container_fingerprint_network_invalid'") 'protected_fingerprint_network_guard_missing'
Assert-Protocol (-not $operatorText.Contains("@('inspect', '--format', '{{with .Config.Labels}}")) 'protected_fingerprint_native_template_present'
Assert-Protocol $operatorText.Contains("`$parts[1] -notmatch '^(?:[0-9]|[12][0-9]|3[0-2])`$'") 'cidr_prefix_guard_missing'
Assert-Protocol $operatorText.Contains('[Net.Sockets.AddressFamily]::InterNetwork') 'cidr_ipv4_guard_missing'
Assert-Protocol $operatorText.Contains('[uint64][uint32]::MaxValue') 'cidr_ps51_uint32_max_missing'
Assert-Protocol (-not $operatorText.Contains('[uint64]0xffffffff')) 'cidr_ps51_unsafe_literal_present'
Assert-Protocol $operatorText.Contains('function Get-DockerInspectObject') 'strict_inspect_helper_missing'
Assert-Protocol $operatorText.Contains('function Get-ShadowPiwigoRecoveryFingerprint') 'recovery_fingerprint_helper_missing'
Assert-Protocol $operatorText.Contains("'shadow_piwigo_exposure_invalid'") 'strict_port_binding_guard_missing'
Assert-Protocol (-not $operatorText.Contains('{{index .')) 'native_map_index_template_present'
Assert-Protocol (-not $operatorText.Contains('{{range .Mounts}}')) 'native_recovery_mount_template_present'
Assert-Protocol $operatorText.Contains("if (`$newId -eq `$oldId)") 'container_recreate_proof_missing'
Assert-Protocol $operatorText.Contains('class_archive_private_role_shadow_v1_private_e2e_recovery') 'recovery_volume_missing'
Assert-Protocol ($operatorText.Contains('cleanup_') -and $operatorText.Contains('scope_invalid')) 'cleanup_scope_guard_missing'
Assert-Protocol (-not $operatorText.Contains('docker system prune')) 'prune_forbidden'

Assert-Protocol $cloneText.Contains('mariadb-dump --quick --lock-all-tables') 'mariadb_lock_all_tables_missing'
Assert-Protocol (-not $cloneText.Contains('--skip-comments --compact')) 'mariadb_foreign_key_restore_safety_missing'
Assert-Protocol $cloneText.Contains('pg_dump --format=custom') 'postgres_custom_dump_missing'
Assert-Protocol $cloneText.Contains('--serializable-deferrable') 'postgres_consistency_missing'
Assert-Protocol $cloneText.Contains('owner_mariadb_drift') 'mariadb_drift_guard_missing'
Assert-Protocol $cloneText.Contains('owner_postgres_drift') 'postgres_drift_guard_missing'
Assert-Protocol $cloneText.Contains('owner_piwigo_data_drift') 'control_data_drift_guard_missing'
Assert-Protocol (([regex]::Matches($cloneText, [regex]::Escape('COALESCE(MIN(TABLE_NAME), LEFT(DATABASE(),0))'))).Count -eq 2) 'schema_empty_string_shell_quote_unsafe'
Assert-Protocol $cloneText.Contains('set -eEuo pipefail') 'clone_errtrace_missing'
Assert-Protocol $cloneText.Contains('unexpected_${clone_stage}') 'clone_safe_unexpected_stage_missing'
Assert-Protocol $cloneText.Contains('control_volume_partial_mismatch') 'control_volume_resume_fail_closed_missing'
Assert-Protocol $cloneText.Contains('seed_stream_digest mariadb.sql') 'actual_mariadb_snapshot_digest_missing'
Assert-Protocol $cloneText.Contains('shadow_mariadb_reset_failed') 'shadow_mariadb_retry_reset_missing'
Assert-Protocol $cloneText.Contains('--ignore-table="$MARIADB_DATABASE.piwigo_activity"') 'business_drift_activity_exclusion_missing'
Assert-Protocol $cloneText.Contains('--ignore-table="$MARIADB_DATABASE.piwigo_sessions"') 'business_drift_session_exclusion_missing'
Assert-Protocol $cloneText.Contains('mariadb_business_before') 'business_drift_before_digest_missing'
Assert-Protocol $cloneText.Contains('mariadb_business_after') 'business_drift_after_digest_missing'
Assert-Protocol $cloneText.Contains('CREATE TEMP TABLE classarchive_fingerprint') 'postgres_semantic_fingerprint_missing'
Assert-Protocol $cloneText.Contains("md5(COALESCE(string_agg(md5(to_jsonb(t)::text)") 'postgres_order_independent_row_fingerprint_missing'
Assert-Protocol $cloneText.Contains("VALUES ('sequence-value'") 'postgres_sequence_value_fingerprint_missing'
Assert-Protocol $cloneText.Contains("SELECT 'sequence-definition'") 'postgres_sequence_definition_fingerprint_missing'
Assert-Protocol $cloneText.Contains("n.nspname NOT LIKE 'pg_temp_%'") 'postgres_temp_schema_exclusion_missing'
Assert-Protocol $cloneText.Contains('system_metadata') 'postgres_liveness_drift_boundary_missing'
Assert-Protocol $cloneText.Contains('shadow_postgres_reset_failed') 'shadow_postgres_retry_reset_missing'
Assert-Protocol $cloneText.Contains('dropdb --if-exists --force --username=postgres immich') 'shadow_postgres_target_database_guard_missing'
Assert-Protocol $cloneText.Contains('EMPTY_INDEPENDENT_FIXTURE_ONLY') 'independent_media_policy_missing'
Assert-Protocol $cloneText.Contains('DELETE FROM piwigo_sessions') 'session_revoke_missing'
Assert-Protocol $cloneText.Contains('UPDATE piwigo_user_auth_keys SET revoked_on=COALESCE') 'core_api_key_revoke_missing'
Assert-Protocol $cloneText.Contains('DELETE FROM "session"; DELETE FROM "api_key";') 'immich_credential_revoke_missing'
Assert-Protocol $cloneText.Contains('remove_seed_volume') 'plaintext_seed_cleanup_missing'

Assert-Protocol $composeText.Contains('127.0.0.1:11990:80') 'core_loopback_binding_missing'
Assert-Protocol $composeText.Contains('127.0.0.1:11991:8081') 'compat_loopback_binding_missing'
Assert-Protocol (-not $composeText.Contains('0.0.0.0:')) 'wildcard_binding_forbidden'
Assert-Protocol $composeText.Contains('com.classarchive.scope: private-role-shadow') 'scope_label_missing'
Assert-Protocol $composeText.Contains('CLASS_ARCHIVE_PRIVATE_E2E_ENABLED: "1"') 'container_test_gate_missing'
Assert-Protocol $composeText.Contains('class_archive_private_role_shadow_v1_private_e2e_recovery') 'compose_recovery_volume_missing'
Assert-Protocol (-not $composeText.Contains('/mnt/m/')) 'private_source_mount_forbidden'

$syntaxErrors = $null
[Management.Automation.Language.Parser]::ParseFile($operator, [ref]$null, [ref]$syntaxErrors) | Out-Null
Assert-Protocol ($syntaxErrors.Count -eq 0) 'powershell_syntax_invalid'
& wsl.exe -d Ubuntu --exec bash -n (('/mnt/' + $clone.Substring(0, 1).ToLowerInvariant() + '/' + $clone.Substring(3).Replace('\', '/')))
Assert-Protocol ($LASTEXITCODE -eq 0) 'bash_syntax_invalid'

$oldGate = [Environment]::GetEnvironmentVariable('CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED', 'Process')
try {
    [Environment]::SetEnvironmentVariable('CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED', $null, 'Process')
    $validate = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $operator -Action validate 2>&1)
    Assert-Protocol ($LASTEXITCODE -eq 0 -and $validate -contains 'PRIVATE_ROLE_SHADOW=PASS action=validate evidence=STATIC_COMPOSE_CONFIG protocol=DISABLED_BY_DEFAULT ports=11990_11991 media=EMPTY_INDEPENDENT_FIXTURE_ONLY owner_mutation=NONE') 'static_validate_failed'
    $disabled = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $operator -Action initialize 2>&1)
    Assert-Protocol ($LASTEXITCODE -eq 2 -and ($disabled -join "`n") -match 'code=shadow_disabled_by_default') 'disabled_default_failed'
}
finally {
    [Environment]::SetEnvironmentVariable('CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED', $oldGate, 'Process')
}

Write-Output "PRIVATE_ROLE_SHADOW_PROTOCOL=PASS assertions=$assertions static=PASS compose=PASS runtime=NOT_RUN owner_mutation=NONE"
