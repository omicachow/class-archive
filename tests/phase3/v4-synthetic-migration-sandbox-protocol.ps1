[CmdletBinding()]
param()

# Static-only contract for the Phase 3.4 v16 -> v17 synthetic migration
# laboratory.  It reads source code only: no ignored input bundle, no Docker
# project, no source database, no 8091/8191/8291 service and no media is used.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

$paths = @{
    runner = Join-Path $projectRoot 'infra\scripts\v4-synthetic-migration.ps1'
    compose = Join-Path $projectRoot 'infra\v4-synthetic-migration\docker-compose.override.yml'
    restore = Join-Path $projectRoot 'infra\scripts\restore-v4-synthetic-pre-migration-db.sh'
    dbprobe = Join-Path $projectRoot 'infra\scripts\v4-synthetic-db-probe.sh'
    verify = Join-Path $projectRoot 'infra\scripts\verify-v4-synthetic-post-migration.php'
    snapshot = Join-Path $projectRoot 'infra\scripts\create-pre-migration-db-snapshot.sh'
}

$assertions = 0
function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Source([string]$Name) {
    $path = [string]$paths[$Name]
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('v4_sandbox_missing_' + $Name)
    return [IO.File]::ReadAllText($path)
}

$runner = Read-Source 'runner'
$compose = Read-Source 'compose'
$restore = Read-Source 'restore'
$dbprobe = Read-Source 'dbprobe'
$verify = Read-Source 'verify'
$snapshot = Read-Source 'snapshot'
$installer = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\install-class-archive-plugins.php'))
$trustedBootstrap = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\class-archive-trusted-bootstrap-context.php'))
$activationHelper = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\activate-class-archive-policy.php'))
$baselineHelper = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\configure-piwigo-baseline.php'))
$privateSourcePathMarker = 'M' + [char]58 + [char]92 + 'private-media-root'
$privateDriveRootMarker = 'M' + [char]58 + [char]92
$privateDriveSlashMarker = 'M' + [char]58 + '/'

# The runner cannot select any owner/private endpoint or path.  Its only
# input location is ignored .codex-work, and neither restore nor migration is
# the default action.
Assert-True ($runner.Contains("[ValidateSet('validate', 'initialize', 'restore', 'migrate', 'verify', 'browser', 'status')]") -and $runner.Contains("[string]`$Action = 'validate'") -and $runner.Contains('[switch]$ConfirmSyntheticBrowser')) 'v4_sandbox_action_boundary_missing'
Assert-True ($runner.Contains("[ValidateSet('primary', 'attempt2', 'attempt3', 'attempt4', 'attempt5', 'attempt6', 'attempt7')]") -and $runner.Contains("[string]`$Attempt = 'primary'") -and $runner.Contains('function Get-SandboxSpec')) 'v4_sandbox_fixed_retry_selector_missing'
Assert-True ($runner.Contains("output_root = '.codex-work\v4-synthetic-migration'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt2'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt3'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt4'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt5'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt6'") -and $runner.Contains("output_root = '.codex-work\v4-synthetic-migration-attempt7'") -and $runner.Contains("`$canonicalInputRoot = Join-Path `$projectRoot '.codex-work\v4-synthetic-migration\input'")) 'v4_sandbox_ignored_root_missing'
Assert-True ($runner.Contains("project_name = 'class_archive_v4_synthetic_migration'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt2'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt3'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt4'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt5'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt6'") -and $runner.Contains("project_name = 'class_archive_v4_synthetic_migration_attempt7'")) 'v4_sandbox_project_identity_missing'
Assert-True ($runner.Contains("http_port = '8490'") -and $runner.Contains("compat_port = '8491'") -and $runner.Contains("http_port = '8590'") -and $runner.Contains("compat_port = '8591'") -and $runner.Contains("http_port = '8690'") -and $runner.Contains("compat_port = '8691'") -and $runner.Contains("http_port = '8790'") -and $runner.Contains("compat_port = '8791'") -and $runner.Contains("http_port = '8890'") -and $runner.Contains("compat_port = '8891'") -and $runner.Contains("http_port = '8990'") -and $runner.Contains("compat_port = '8991'") -and $runner.Contains("http_port = '9090'") -and $runner.Contains("compat_port = '9091'")) 'v4_sandbox_ports_missing'
Assert-True ($runner.Contains("app_network = 'class_archive_v4_synthetic_migration_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt2_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt2_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt3_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt3_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt4_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt4_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt5_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt5_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt6_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt6_gateway'") -and $runner.Contains("app_network = 'class_archive_v4_synthetic_migration_attempt7_app'") -and $runner.Contains("gateway_network = 'class_archive_v4_synthetic_migration_attempt7_gateway'") -and $runner.Contains("app_subnet = '192.168.208.0/20'") -and $runner.Contains("app_subnet = '192.168.224.0/20'") -and $runner.Contains("app_subnet = '192.168.240.0/20'") -and $runner.Contains("app_subnet = '10.254.0.0/24'") -and $runner.Contains("app_subnet = '10.255.0.0/24'") -and $runner.Contains("app_subnet = '10.255.1.0/24'") -and $runner.Contains("app_subnet = '10.255.2.0/24'") -and $runner.Contains("gateway_subnet = '10.249.0.0/16'") -and $runner.Contains("gateway_subnet = '10.250.0.0/16'") -and $runner.Contains("gateway_subnet = '10.251.0.0/16'") -and $runner.Contains("gateway_subnet = '10.252.0.0/16'") -and $runner.Contains("gateway_subnet = '10.253.0.0/16'") -and $runner.Contains("gateway_subnet = '10.248.0.0/16'") -and $runner.Contains("gateway_subnet = '10.247.0.0/16'") -and $runner.Contains("bff_gateway_ip = '10.249.0.10'") -and $runner.Contains("bff_gateway_ip = '10.250.0.10'") -and $runner.Contains("bff_gateway_ip = '10.251.0.10'") -and $runner.Contains("bff_gateway_ip = '10.252.0.10'") -and $runner.Contains("bff_gateway_ip = '10.253.0.10'") -and $runner.Contains("bff_gateway_ip = '10.248.0.10'") -and $runner.Contains("bff_gateway_ip = '10.247.0.10'")) 'v4_sandbox_network_identity_missing'
Assert-True ($runner.Contains('function Assert-SandboxPath') -and $runner.Contains('private_or_source_path_forbidden') -and $runner.Contains('Assert-NoReparsePoints')) 'v4_sandbox_path_boundary_missing'
Assert-True ($runner.Contains('Assert-IgnoredUntracked') -and $runner.Contains('git -C $projectRoot check-ignore --quiet --no-index') -and $runner.Contains('git -C $projectRoot ls-files')) 'v4_sandbox_git_ignore_boundary_missing'
Assert-True ($runner.Contains("'--cd', `$wslProjectRoot, '--exec', 'docker', 'compose'") -and $runner.Contains('Get-WslPath $projectRoot')) 'v4_sandbox_wsl_direct_exec_missing'
Assert-True ($runner.Contains("'--profile','v4-synthetic-migration'") -and $runner.Contains("'--profile','v4-synthetic-browser'") -and $runner.Contains("'config','--format','json'")) 'v4_sandbox_profiled_topology_audit_missing'
Assert-True ($runner.Contains('function Get-SnapshotDirectory') -and $runner.Contains('snapshot_input_not_exactly_one_bundle') -and $runner.Contains('pre-migration-db-v16-to-v17')) 'v4_sandbox_exact_snapshot_directory_missing'
Assert-True ($runner.Contains('$entries = @(Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop)') -and $runner.Contains('$childPaths = @($children | ForEach-Object { $_.FullName })') -and $runner.Contains('$_.FullName -notin $childPaths')) 'v4_sandbox_snapshot_input_must_not_depend_on_directoryinfo_reference_equality'
Assert-True ($runner.Contains('function Assert-SourceDbOnlySnapshot') -and $runner.Contains('DB_ONLY_PRE_MIGRATION_ROLLBACK') -and $runner.Contains('schema_current -ne 16') -and $runner.Contains('schema_to -ne 17')) 'v4_sandbox_snapshot_version_boundary_missing'
Assert-True ($runner.Contains('snapshot_not_created_by_existing_mechanism') -and $runner.Contains('create-pre-migration-db-snapshot.sh') -and $runner.Contains('Get-FileHash')) 'v4_sandbox_existing_snapshot_mechanism_missing'
Assert-True ($runner.Contains('synthetic_restore_confirmation_required') -and $runner.Contains('synthetic_migration_confirmation_required')) 'v4_sandbox_explicit_confirmation_missing'
Assert-True ($runner.Contains('function Wait-SandboxServiceRunning') -and $runner.Contains("Wait-SandboxServiceRunning -Service 'piwigo'") -and $runner.Contains('sandbox_service_not_running_') -and $runner.Contains('fail-closed')) 'v4_sandbox_maintenance_runtime_wait_missing'
Assert-True ($installer.Contains('Piwigo 16') -and $installer.Contains('verifyPluginState($pluginId);') -and -not $installer.Contains('activation returned an unexpected result.')) 'v4_sandbox_silent_native_activation_must_use_read_only_state_verifier'
Assert-True ($trustedBootstrap.Contains("CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_ROOT = '/var/www/html/piwigo'") -and $trustedBootstrap.Contains("CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_MARKER") -and $trustedBootstrap.Contains("CLASS_ARCHIVE_TRUSTED_BOOTSTRAP_VALUE = 'class-archive-cli-bootstrap-v1'") -and $trustedBootstrap.Contains("'nginx'") -and $trustedBootstrap.Contains('0600, 0660, 0670') -and $trustedBootstrap.Contains("define('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT'")) 'v4_sandbox_trusted_cli_context_contract_missing'
Assert-True ($activationHelper.Contains("require_once '/workspace/infra/scripts/class-archive-trusted-bootstrap-context.php'") -and $activationHelper.Contains('classArchiveEnableTrustedCliBootstrapContext();') -and $baselineHelper.Contains("require_once '/workspace/infra/scripts/class-archive-trusted-bootstrap-context.php'") -and $baselineHelper.Contains('classArchiveEnableTrustedCliBootstrapContext();')) 'v4_sandbox_native_helpers_must_validate_trusted_cli_context'
Assert-True ($activationHelper.Contains("`$_SERVER['SCRIPT_NAME'] = '/identification.php';") -and $baselineHelper.Contains("`$_SERVER['SCRIPT_NAME'] = '/identification.php';")) 'v4_sandbox_native_helpers_must_bypass_piwigo_presentation_exits'
Assert-True ($baselineHelper.Contains("'--verify-v4-synthetic-existing-runtime'") -and $baselineHelper.Contains("getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION'") -and $baselineHelper.Contains("getenv('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION') !== '1'") -and $baselineHelper.Contains('BASELINE_SYNTHETIC_EXISTING_RUNTIME_VERIFIED') -and $baselineHelper.Contains('if (!$verifyV4SyntheticExistingRuntime) {')) 'v4_sandbox_existing_runtime_theme_exception_not_strictly_scoped'
Assert-True ($installer.Contains('function isV4SyntheticExistingRuntime()') -and $installer.Contains("getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') === 'SYNTHETIC_V4_MIGRATION'") -and $installer.Contains("getenv('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION') === '1'") -and $installer.Contains("configurePiwigoBaseline(true, isV4SyntheticExistingRuntime());") -and $installer.Contains("configurePiwigoBaseline(`$existingSyntheticRuntime, `$existingSyntheticRuntime);")) 'v4_sandbox_existing_runtime_baseline_verifier_not_wired'
Assert-True ($runner.Contains('function Assert-FreshSandboxVolumes') -and $runner.Contains('sandbox_volumes_already_exist') -and $runner.Contains('sandbox_containers_already_exist') -and $runner.Contains('sandbox_networks_already_exist') -and $runner.Contains('docker network ls')) 'v4_sandbox_fresh_target_gate_missing'
Assert-True ($runner.Contains('function Get-SandboxRestoreMode') -and $runner.Contains("return 'FRESH'") -and $runner.Contains("return 'RESUME_EMPTY'") -and $runner.Contains('sandbox_resume_state_invalid') -and $runner.Contains('sandbox_restore_target_not_empty')) 'v4_sandbox_resume_must_be_exact_and_empty'
Assert-True (-not $runner.Contains("'down'") -and -not $runner.Contains("'rm'") -and -not $runner.Contains('docker volume rm') -and -not $runner.Contains('docker system prune')) 'v4_sandbox_destructive_cleanup_present'
Assert-True (-not $runner.Contains("CLASS_ARCHIVE_HTTP_PORT=8091") -and -not $runner.Contains("CLASS_ARCHIVE_HTTP_PORT=8191") -and -not $runner.Contains("CLASS_ARCHIVE_HTTP_PORT=8291") -and -not $runner.Contains($privateSourcePathMarker)) 'v4_sandbox_existing_runtime_or_source_reference_present'

# The Compose overlay must be self-contained: unique named volumes/bridges,
# exact loopback mappings inherited from the base compose, only a DB snapshot
# bind input, and no media/Immich runtime service.
Assert-True ($compose.Contains('v4-synthetic-db-restore:') -and $compose.Contains('profiles: ["v4-synthetic-migration"]')) 'v4_sandbox_restore_service_missing'
Assert-True ($compose.Contains('source: ../infra/scripts/v4-synthetic-db-probe.sh') -and $compose.Contains('target: /workspace/infra/scripts/v4-synthetic-db-probe.sh') -and $compose.Contains('read_only: true') -and $compose.Contains('create_host_path: false')) 'v4_sandbox_db_probe_mount_not_read_only'
Assert-True ($compose.Contains('/var/lib/mysql:rw,nosuid,nodev,noexec,size=1m')) 'v4_sandbox_restore_mariadb_anonymous_volume_mask_missing'
Assert-True ($compose.Contains('cap_drop: ["ALL"]') -and $compose.Contains('cap_add: ["CHOWN", "FOWNER", "DAC_OVERRIDE"]') -and $compose.Contains('security_opt: ["no-new-privileges:true"]')) 'v4_sandbox_restore_must_grant_only_config_ownership_capability'
Assert-True ($compose.Contains('source: ${V4_SYNTHETIC_SNAPSHOT_PATH') -and $compose.Contains('target: /snapshot') -and $compose.Contains('read_only: true') -and $compose.Contains('create_host_path: false')) 'v4_sandbox_snapshot_mount_not_read_only'
Assert-True ($compose.Contains('CLASS_ARCHIVE_RUNTIME_SCOPE: SYNTHETIC_V4_MIGRATION') -and $compose.Contains('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION: "1"')) 'v4_sandbox_runtime_scope_missing'
Assert-True ($compose.Contains('name: ${CLASS_ARCHIVE_V4_SANDBOX_APP_NETWORK:?Set CLASS_ARCHIVE_V4_SANDBOX_APP_NETWORK') -and $compose.Contains('subnet: ${CLASS_ARCHIVE_V4_SANDBOX_APP_SUBNET:?Set CLASS_ARCHIVE_V4_SANDBOX_APP_SUBNET') -and $compose.Contains('name: ${CLASS_ARCHIVE_GATEWAY_NETWORK:?Set CLASS_ARCHIVE_GATEWAY_NETWORK') -and $compose.Contains('subnet: ${CLASS_ARCHIVE_GATEWAY_SUBNET:?Set CLASS_ARCHIVE_GATEWAY_SUBNET') -and $compose.Contains('ipv4_address: ${CLASS_ARCHIVE_BFF_GATEWAY_IP:?Set CLASS_ARCHIVE_BFF_GATEWAY_IP') -and $runner.Contains('sandbox_requested_subnet_already_in_use')) 'v4_sandbox_network_compose_config_missing'
Assert-True (($compose | Select-String -Pattern 'internal: true' -AllMatches).Matches.Count -ge 2) 'v4_sandbox_internal_networks_missing'
foreach ($volume in @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts')) {
    Assert-True ($compose.Contains('class_archive_v4_synthetic_migration_' + $volume)) ('v4_sandbox_unique_volume_missing_' + $volume)
}
Assert-True ($runner.Contains("foreach (`$logical in @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts'))") -and $runner.Contains('Docker Compose omits a declared volume from rendered config')) 'v4_sandbox_unmounted_backup_volume_not_treated_as_runtime_state'
Assert-True (-not ($compose -match '(?m)^  immich-server:') -and -not ($compose -match '(?m)^  immich-machine-learning:') -and -not $compose.Contains('/external/piwigo') -and -not $compose.Contains('/private-real')) 'v4_sandbox_ai_or_private_mount_present'
Assert-True (-not $compose.Contains($privateDriveRootMarker) -and -not $compose.Contains($privateDriveSlashMarker) -and -not $compose.Contains('127.0.0.1:8091') -and -not $compose.Contains('127.0.0.1:8191') -and -not $compose.Contains('127.0.0.1:8291')) 'v4_sandbox_forbidden_endpoint_or_source_present'

# Browser E2E is explicitly opt-in after schema verification.  The BFF itself
# has no host port, no named volume and no ML/Immich runtime peer; it is
# reachable only when Piwigo's 8491 loopback listener proxies to the fixed
# service name and exact gateway IP.
Assert-True ($compose.Contains('immich-web-compat:') -and $compose.Contains('profiles: ["v4-synthetic-browser"]')) 'v4_sandbox_browser_profile_missing'
Assert-True ($compose.Contains('CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT: ${CLASS_ARCHIVE_COMPAT_HTTP_PORT:?Set CLASS_ARCHIVE_COMPAT_HTTP_PORT') -and $compose.Contains('CLASS_ARCHIVE_CORE_PUBLIC_PORT: ${CLASS_ARCHIVE_CORE_PUBLIC_PORT:?Set CLASS_ARCHIVE_CORE_PUBLIC_PORT') -and $runner.Contains('CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT = $compatPort') -and $runner.Contains('CLASS_ARCHIVE_CORE_PUBLIC_PORT = $httpPort') -and $compose.Contains('CLASS_ARCHIVE_GATEWAY_ORIGIN: http://piwigo:8088')) 'v4_sandbox_browser_fixed_endpoint_missing'
Assert-True ($compose.Contains('ipv4_address: ${CLASS_ARCHIVE_BFF_GATEWAY_IP:?Set CLASS_ARCHIVE_BFF_GATEWAY_IP') -and $compose.Contains('immich_gateway:') -and $runner.Contains("bff_gateway_ip = '10.250.0.10'")) 'v4_sandbox_browser_gateway_address_missing'
Assert-True ($compose.Contains('entrypoint: ["node", "/compat/server.mjs"]') -and $compose.Contains('user: "65532:65532"') -and $compose.Contains('read_only: true') -and $compose.Contains('cap_drop: ["ALL"]') -and $compose.Contains('no-new-privileges:true')) 'v4_sandbox_browser_bff_hardening_missing'
foreach ($mount in @('./immich-spike/web-compat','./immich-spike/photo-ui','./immich-spike/source/official-v3.1.0/web/build','./immich-spike/web-compat/empty-data')) {
    Assert-True ($compose.Contains('source: ' + $mount) -and $compose.Contains('read_only: true') -and $compose.Contains('create_host_path: false')) ('v4_sandbox_browser_read_only_source_mount_missing_' + ($mount -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}
$browserServiceStart = $compose.IndexOf("  immich-web-compat:")
$browserServiceEnd = $compose.IndexOf("`n  pre-migration-db-backup:", $browserServiceStart)
Assert-True ($browserServiceStart -ge 0 -and $browserServiceEnd -gt $browserServiceStart) 'v4_sandbox_browser_service_block_missing'
$browserService = $compose.Substring($browserServiceStart, $browserServiceEnd - $browserServiceStart)
Assert-True (-not $browserService.Contains("`n    ports:") -and $browserService.Contains("`n    expose:") -and -not $browserService.Contains('piwigo_uploads') -and -not $browserService.Contains('piwigo_galleries') -and -not $browserService.Contains('piwigo_derivatives') -and -not $browserService.Contains('piwigo_db') -and -not $browserService.Contains('immich_db') -and -not $browserService.Contains('immich_model_cache')) 'v4_sandbox_browser_bff_volume_or_host_port_scope_invalid'
Assert-True (-not $browserService.Contains("`n      - app") -and $browserService.Contains("`n      immich_gateway:")) 'v4_sandbox_browser_bff_network_scope_invalid'
$browserStart = $runner.IndexOf("function Invoke-SandboxBrowser")
Assert-True ($browserStart -ge 0 -and $runner.Contains('synthetic_browser_confirmation_required') -and $runner.Contains('sandbox_browser_requires_schema_v17') -and $runner.Contains("'--profile','v4-synthetic-browser','up','-d','immich-web-compat'") -and $runner.Contains("Wait-SandboxService -Service 'immich-web-compat'")) 'v4_sandbox_browser_runner_opt_in_missing'
Assert-True ($runner.Contains('function Assert-OptionalBrowserBffTopology') -and $runner.Contains('sandbox_browser_bff_host_port_present') -and $runner.Contains('sandbox_browser_bff_mount_invalid') -and $runner.Contains('sandbox_browser_forbidden_service_')) 'v4_sandbox_browser_topology_gate_missing'
Assert-True ($runner.Contains('@($networks.PSObject.Properties).Count') -and -not $runner.Contains('$networks.PSObject.Properties.Count')) 'v4_sandbox_browser_network_property_collection_must_be_windows_powershell_safe'
Assert-True ($runner.Contains('$result = @(Invoke-SandboxCompose') -and $runner.Contains('$lines = @(Invoke-SandboxCompose') -and $dbprobe.Contains('MARIADB_ROOT_PASSWORD') -and $dbprobe.Contains('MARIADB_DATABASE')) 'v4_sandbox_windows_powershell_command_capture_and_db_environment_missing'
Assert-True ($runner.Contains("'exec','-T','db','sh','/workspace/infra/scripts/v4-synthetic-db-probe.sh','schema'") -and $runner.Contains("'exec','-T','db','sh','/workspace/infra/scripts/v4-synthetic-db-probe.sh','table-count'") -and -not $runner.Contains("'exec','-T','db','sh','-eu','-c'")) 'v4_sandbox_database_probe_must_not_cross_windows_shell_bridge'
Assert-True ($runner.Contains('sandbox_db_probe_mount_invalid') -and $runner.Contains("'piwigo_db'") -and $runner.Contains("'infra\scripts\v4-synthetic-db-probe.sh'")) 'v4_sandbox_db_probe_topology_gate_missing'
Assert-True ($dbprobe.Contains('schema|table-count') -and $dbprobe.Contains('MARIADB_DATABASE') -and $dbprobe.Contains('MARIADB_ROOT_PASSWORD') -and $dbprobe.Contains('SELECT COUNT(*) FROM information_schema.TABLES') -and $dbprobe.Contains('class_identity_migration') -and -not $dbprobe.Contains('/snapshot') -and -not $dbprobe.Contains('/private-real') -and -not $dbprobe.Contains($privateDriveRootMarker)) 'v4_sandbox_db_probe_scope_or_schema_contract_missing'

# The restore helper accepts only the existing four-file snapshot shape,
# checks checksum/manifest/script provenance, imports into an empty database,
# writes a sandbox-only DB config, and leaves the restored v16 runtime in the
# exact maintenance gate rather than publishing it to a browser.
Assert-True ($restore.Contains('CLASS_ARCHIVE_V4_SYNTHETIC_RESTORE') -and $restore.Contains('V4_SYNTHETIC_DB_RESTORE=FAIL')) 'v4_sandbox_restore_scope_gate_missing'
Assert-True ($restore.Contains('for secret in "$DB_PASSWORD" "$DB_ROOT_PASSWORD"; do') -and -not $restore.Contains('case "${DB_PASSWORD}:${DB_ROOT_PASSWORD}" in')) 'v4_sandbox_restore_secret_validation_must_not_reject_its_separator'
Assert-True ($restore.Contains("expected_files='COMPLETE MANIFEST.json SHA256SUMS database.sql.gz'") -and $restore.Contains('snapshot_file_set_invalid')) 'v4_sandbox_restore_exact_file_set_missing'
Assert-True ($restore.Contains('schema_current":16') -and $restore.Contains('schema_from":16') -and $restore.Contains('schema_to":17') -and $restore.Contains('media":"NOT_INCLUDED')) 'v4_sandbox_restore_manifest_transition_missing'
Assert-True ($restore.Contains('snapshot_not_created_by_current_mechanism') -and $restore.Contains('/workspace/infra/scripts/create-pre-migration-db-snapshot.sh')) 'v4_sandbox_restore_snapshot_provenance_missing'
Assert-True ($restore.Contains('sha256sum -c SHA256SUMS') -and $restore.Contains('gzip -t')) 'v4_sandbox_restore_checksum_or_gzip_check_missing'
Assert-True ($restore.Contains('target_database_not_empty') -and $restore.Contains('restored_schema_not_v16')) 'v4_sandbox_restore_empty_target_or_v16_gate_missing'
Assert-True ($restore.Contains('database.inc.php') -and $restore.Contains('target_database_config_already_exists')) 'v4_sandbox_restore_config_overwrite_guard_missing'
Assert-True ($restore.Contains("define('PHPWG_INSTALLED', true);") -and $restore.Contains("`$prefixeTable = 'piwigo_';")) 'v4_sandbox_restore_must_create_a_core_installed_database_config'
Assert-True ($restore.Contains('.class-archive-maintenance') -and $restore.Contains('chmod 0660 "$temporary_marker"') -and $restore.Contains('maintenance=FAIL_CLOSED')) 'v4_sandbox_restore_maintenance_gate_missing'
Assert-True (-not $restore.Contains('/source/') -and -not $restore.Contains('/private-real') -and -not $restore.Contains($privateDriveRootMarker)) 'v4_sandbox_restore_private_source_reference_present'

# The post-migration verifier declares only what a DB-only sandbox can prove:
# the fixed 72-photo synthetic source, v17 schema, all eight active pointers
# and persistent snapshot state.  It must not rebrand that evidence as media
# or browser success.
Assert-True ($verify.Contains('CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION') -and $verify.Contains('SYNTHETIC_V4_MIGRATION')) 'v4_sandbox_verify_scope_gate_missing'
Assert-True ($verify.Contains('Schema::CURRENT_VERSION !== 17') -and $verify.Contains('$schema->verifyCurrent()')) 'v4_sandbox_verify_schema_gate_missing'
Assert-True ($verify.Contains('activePhotos !== 72') -and $verify.Contains('synthetic_baseline_photo_count_invalid')) 'v4_sandbox_verify_fixture_count_missing'
Assert-True ($verify.Contains('$expectedPointers = 8') -and $verify.Contains('collection_snapshot_pointer_count_invalid') -and $verify.Contains('collection_snapshot_maintenance_not_complete')) 'v4_sandbox_verify_collection_persistence_missing'
Assert-True ($verify.Contains('media=NOT_MOUNTED media_guard=NOT_CLAIMED browser=NOT_CLAIMED')) 'v4_sandbox_verify_evidence_scope_overclaimed'
Assert-True (-not $verify.Contains('MediaGuard::') -and -not $verify.Contains('X-Accel') -and -not $verify.Contains('Immich')) 'v4_sandbox_verify_media_or_ai_scope_expanded'

# Existing snapshot helper stays the sole source mechanism, with its explicit
# DB-only v16 -> v17 boundary intact.
Assert-True ($snapshot.Contains('16:17') -and $snapshot.Contains('DB_ONLY_PRE_MIGRATION_ROLLBACK') -and $snapshot.Contains('media=NOT_INCLUDED')) 'v4_sandbox_existing_snapshot_contract_missing'

Write-Output "V4_SYNTHETIC_MIGRATION_SANDBOX_PROTOCOL=PASS assertions=$assertions"
