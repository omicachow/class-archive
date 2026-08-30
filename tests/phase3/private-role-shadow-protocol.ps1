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
Assert-Protocol $operatorText.Contains("if (`$newId -eq `$oldId)") 'container_recreate_proof_missing'
Assert-Protocol $operatorText.Contains('class_archive_private_role_shadow_v1_private_e2e_recovery') 'recovery_volume_missing'
Assert-Protocol ($operatorText.Contains('cleanup_') -and $operatorText.Contains('scope_invalid')) 'cleanup_scope_guard_missing'
Assert-Protocol (-not $operatorText.Contains('docker system prune')) 'prune_forbidden'

Assert-Protocol $cloneText.Contains('mariadb-dump --quick --lock-all-tables') 'mariadb_lock_all_tables_missing'
Assert-Protocol $cloneText.Contains('pg_dump --format=custom') 'postgres_custom_dump_missing'
Assert-Protocol $cloneText.Contains('--serializable-deferrable') 'postgres_consistency_missing'
Assert-Protocol $cloneText.Contains('owner_mariadb_drift') 'mariadb_drift_guard_missing'
Assert-Protocol $cloneText.Contains('owner_postgres_drift') 'postgres_drift_guard_missing'
Assert-Protocol $cloneText.Contains('owner_piwigo_data_drift') 'control_data_drift_guard_missing'
Assert-Protocol $cloneText.Contains('EMPTY_INDEPENDENT_FIXTURE_ONLY') 'independent_media_policy_missing'
Assert-Protocol $cloneText.Contains('DELETE FROM piwigo_sessions') 'session_revoke_missing'
Assert-Protocol $cloneText.Contains('UPDATE piwigo_user_auth_keys SET revoked_on=COALESCE') 'core_api_key_revoke_missing'
Assert-Protocol $cloneText.Contains('DELETE FROM "sessions"; DELETE FROM "api_keys";') 'immich_credential_revoke_missing'
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
