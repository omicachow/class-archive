[CmdletBinding()]
param()

# Static/Compose contract only. It never starts, stops, kills, clones or cleans
# a container and never reads the ignored Owner environment file.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composePath = Join-Path $projectRoot 'infra\private-e2e-recreate-lab\docker-compose.override.yml'
$runnerPath = Join-Path $projectRoot 'infra\scripts\private-e2e-recreate-lab.ps1'
$docsPath = Join-Path $projectRoot 'docs\private-e2e-recreate-lab.md'
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
function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True (-not $Text.Contains($Needle)) $Code
}

$compose = Read-Required $composePath 'recreate_lab_compose_missing'
$runner = Read-Required $runnerPath 'recreate_lab_runner_missing'
$docs = Read-Required $docsPath 'recreate_lab_docs_missing'
$privateDriveMarker = 'M' + [char]58 + [char]92
$privateSourceMarker = $privateDriveMarker + '图片资源'

$tokens = $null
$parseErrors = $null
[void][Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'recreate_lab_runner_parse_failed'

foreach ($needle in @(
    'name: class_archive_private_e2e_recreate_lab',
    'com.classarchive.scope: private-e2e-recreate-lab',
    'com.classarchive.disposable: "true"',
    'internal: true',
    'subnet: 10.180.10.0/24',
    'network_mode: none',
    'read_only: true',
    'owner_piwigo_data:/source/piwigo:ro',
    'owner_piwigo_scripts:/source/scripts:ro',
    'class_archive_private_full_v3_control_piwigo_data',
    'class_archive_private_full_v3_control_piwigo_scripts',
    'class_archive_private_e2e_recreate_lab_db',
    'class_archive_private_e2e_recreate_lab_piwigo_data',
    'class_archive_private_e2e_recreate_lab_recovery',
    'durable-private-e2e-recovery-plan',
    'lab_recovery:/var/lib/class-archive-private-e2e',
    'recovery-init:',
    'condition: service_completed_successfully',
    'install -d -o nginx -g nginx -m 0700 /target',
    'cap_add: ["CHOWN", "DAC_OVERRIDE", "FOWNER"]',
    'nginx:nginx:700',
    'test -z "$$(find /target/piwigo -mindepth 1 -print -quit)"',
    'tar -C /source/piwigo -cf - . | tar -C /target/piwigo -xf -'
)) {
    Assert-Contains $compose $needle ('recreate_lab_compose_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-NotContains $compose "`n    ports:" 'recreate_lab_host_ports_present'
Assert-NotContains $compose 'network_mode: host' 'recreate_lab_host_network_present'
Assert-NotContains $compose '0.0.0.0' 'recreate_lab_public_listener_present'
Assert-NotContains $compose 'class_archive_private_full_v3_piwigo_uploads' 'recreate_lab_owner_upload_volume_present'
Assert-NotContains $compose 'class_archive_private_full_v3_piwigo_galleries' 'recreate_lab_owner_gallery_volume_present'
Assert-NotContains $compose 'class_archive_private_full_v3_piwigo_derivatives' 'recreate_lab_owner_derivative_volume_present'
Assert-NotContains $compose $privateDriveMarker 'recreate_lab_private_source_path_present'

foreach ($needle in @(
    "[ValidateSet('validate', 'config', 'prepare', 'drill', 'verify', 'cleanup')]",
    '[switch]$ConfirmOwnerReadOnlyClone',
    '[switch]$ConfirmLabSigkill',
    '[switch]$ConfirmLabCleanup',
    "`$labProject = 'class_archive_private_e2e_recreate_lab'",
    "`$labScope = 'private-e2e-recreate-lab'",
    "`$ownerProject = 'class_archive_private_full_v3_piwigo'",
    "`$ownerDbContainer = 'class_archive_private_full_v3_piwigo-db-1'",
    "`$ownerPiwigoContainer = 'class_archive_private_full_v3_piwigo-piwigo-1'",
    "`$runtimeRoot = Join-Path `$projectRoot '.codex-work\private-e2e-recreate-lab'",
    "owner_env_path_not_exact",
    "owner_env_not_ignored",
    "owner_env_tracked",
    '$wslTimeoutSeconds = [Math]::Min($TimeoutSeconds, 885)',
    '$hostTimeoutSeconds = [Math]::Min($wslTimeoutSeconds + 15, 900)',
    'Assert-ContainerBinding $ownerDbContainer $ownerProject',
    'Assert-ContainerBinding $ownerPiwigoContainer $ownerProject',
    '$lines = @(Invoke-Docker',
    '$lines = @(Invoke-Bash',
    '$config = @(Invoke-LabCompose',
    '$recovery = @(Invoke-LabCompose',
    '$remaining = @(Invoke-Docker',
    'lab_container_id_ambiguous',
    'lab_recreated_container_id_ambiguous',
    'logical_dump_digest_failed',
    'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"',
    '--quick --lock-all-tables --skip-comments --skip-dump-date --hex-blob',
    '--ignore-table-data="$MARIADB_DATABASE.${prefix}sessions"',
    '--ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache"',
    '--ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache_categories"',
    "' | docker exec -i class_archive_private_e2e_recreate_lab-db-1",
    'lab_database_clone_digest_mismatch',
    'lab_database_clone_count_mismatch',
    'owner_changed_during_lab_prepare',
    'owner_drift_before_drill',
    'owner_changed_during_drill',
    'owner_drift_after_drill',
    "Invoke-LabCompose @('--profile', 'seed', 'run', '--rm', '--no-deps', 'seed-piwigo')",
    "'/workspace/infra/scripts/install-class-archive-plugins.php')",
    "'/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance'",
    'lab_current_plugin_finalize_failed',
    "'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1'",
    "'CLASS_ARCHIVE_V4_OWNER_FQA_LEASE=1'",
    "'/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php'",
    'V4_OWNER_FQA_LEASE=READY roles=3 ttl=',
    'Assert-ContainerBinding ($labProject + ''-piwigo-1'') $labProject ''piwigo'' $labScope',
    "Invoke-LabCompose @('kill', '--signal', 'SIGKILL', 'piwigo')",
    'lab_broker_survived_container_kill',
    'WHERE test_run_id=\"__RUN__\" AND fixture_owner=\"v4-owner-fqa-broker\" AND state=\"ACTIVE\"',
    "Invoke-LabCompose @('rm', '-f', '-s', 'piwigo')",
    "Invoke-LabCompose @('up', '-d', '--force-recreate', 'piwigo')",
    'lab_container_not_recreated',
    "'lab_recovery_plan_not_durable'",
    "'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1'",
    'V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked',
    'lab_recovery_plan_not_removed',
    "`$leaseAfterParts[0] -ne 'RELEASED'",
    '[int]$afterParts[3] -ne ([int]$beforeParts[3] + 3)',
    'ae.action=\"IDENTITY_UNFREEZE\"',
    'ae.action=\"IDENTITY_FREEZE\"',
    'LOCAL_FQA_LEASE_CLEANUP',
    'Get-LabAuditEvidence',
    'Get-LabLeaseLineageEvidence',
    'ORDER BY l.acquired_at DESC,l.lease_id DESC LIMIT 1',
    "`$leaseLineage -ne '1|1|1'",
    '[int]$auditOpenParts[0] -ne ([int]$auditBeforeParts[0] + 1)',
    '[int]$auditOpenParts[2] -ne ([int]$auditBeforeParts[2] + 3)',
    '[int]$auditAfterParts[1] -ne ([int]$auditBeforeParts[1] + 1)',
    '[int]$auditAfterParts[3] -ne ([int]$auditBeforeParts[3] + 3)',
    "signal = 'SIGKILL'",
    "recovery_plan = 'PERSISTED_ACROSS_RECREATE_THEN_REMOVED'",
    'Assert-CleanupScope',
    "'down', '--volumes', '--remove-orphans'",
    'cleanup_prefix_container_unlabelled',
    'cleanup_prefix_volume_unlabelled',
    'cleanup_prefix_network_unlabelled'
)) {
    Assert-Contains $runner $needle ('recreate_lab_runner_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-True ($runner.IndexOf('Assert-ContainerBinding ($labProject + ''-piwigo-1'') $labProject ''piwigo'' $labScope') -lt
    $runner.IndexOf("Invoke-LabCompose @('kill', '--signal', 'SIGKILL', 'piwigo')")) 'recreate_lab_kill_not_preceded_by_binding'
Assert-True ($runner.IndexOf("Get-DumpDigest `$ownerDbContainer") -lt $runner.IndexOf("Invoke-LabCompose @('up', '-d', 'db')")) 'recreate_lab_owner_digest_not_before_clone'
Assert-True ($runner.LastIndexOf("Get-DumpDigest `$ownerDbContainer") -gt $runner.IndexOf('lab_broker_recovery_attestation_invalid')) 'recreate_lab_owner_digest_not_after_drill'
Assert-NotContains $runner 'docker system prune' 'recreate_lab_system_prune_present'
Assert-NotContains $runner 'docker volume prune' 'recreate_lab_volume_prune_present'
Assert-NotContains $runner 'docker image prune' 'recreate_lab_image_prune_present'
Assert-NotContains $runner "Invoke-Docker @('stop', `$ownerPiwigoContainer)" 'recreate_lab_owner_stop_present'
Assert-NotContains $runner "Invoke-Docker @('kill', `$ownerPiwigoContainer)" 'recreate_lab_owner_kill_present'
Assert-NotContains $runner "Invoke-Docker @('rm', `$ownerPiwigoContainer)" 'recreate_lab_owner_remove_present'
Assert-NotContains $runner 'Write-Output $result.Stdout' 'recreate_lab_native_output_reflection_present'
Assert-NotContains $runner 'Write-Output $result.Stderr' 'recreate_lab_native_error_reflection_present'
Assert-NotContains $runner 'DB_ROOT_PASSWORD=' 'recreate_lab_root_password_literal_present'
Assert-NotContains $runner 'DB_PASSWORD=' 'recreate_lab_db_password_literal_present'
Assert-NotContains $runner 'export MARIADB_PWD=' 'recreate_lab_unsupported_client_password_env_present'
Assert-NotContains $runner 'password_hash =' 'recreate_lab_password_hash_logging_present'
Assert-NotContains $runner $privateSourceMarker 'recreate_lab_private_source_present'

foreach ($needle in @(
    'mariadb-dump --quick --lock-all-tables',
    'never written to the checkout',
    'No service publishes a host port',
    'Owner uploads, galleries, derivatives, canonical originals',
    'survived container recreation',
    'the Identity is frozen',
    'the lease is terminal (`RELEASED`)',
    '`IDENTITY_UNFREEZE`, `IDENTITY_FREEZE`, lease-open, and lease-close audit',
    'never prints usernames, passwords, password hashes, bearer tokens',
    '`cleanup` enumerates both directions',
    'never runs a Docker-wide'
)) {
    Assert-Contains $docs $needle ('recreate_lab_docs_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PRIVATE_E2E_RECREATE_LAB_PROTOCOL=PASS assertions=$assertions evidence=STATIC_COMPOSE_CONTRACT execution=NONE"
