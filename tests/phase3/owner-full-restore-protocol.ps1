[CmdletBinding()]
param()

# Static/protocol-only gate. It does not read M:, create an ext4 image, create a
# volume, decrypt a payload, or contact either runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\owner-full-restore-drill.ps1'
$helperPath = Join-Path $projectRoot 'infra\scripts\restore-owner-temporary-backup.sh'
$immichRunnerPath = Join-Path $projectRoot 'infra\scripts\private-qa-immich.ps1'
$piwigoOverlayPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.piwigo.override.yml'
$immichOverlayPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.immich.override.yml'
$ownerBrowserPath = Join-Path $projectRoot 'tests\phase3\full-real-browser-qa.ps1'
$ownerBrowserNodePath = Join-Path $projectRoot 'tests\phase3\full-real-browser-qa.mjs'
$familyBrowserPath = Join-Path $projectRoot 'tests\phase3\full-real-family-browser-qa.ps1'
$familyBrowserNodePath = Join-Path $projectRoot 'tests\phase3\full-real-family-browser-qa.mjs'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

foreach ($path in @($runnerPath,$helperPath,$immichRunnerPath,$piwigoOverlayPath,$immichOverlayPath,$ownerBrowserPath,$ownerBrowserNodePath,$familyBrowserPath,$familyBrowserNodePath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) 'restore_protocol_file_missing'
}
$runner = [IO.File]::ReadAllText($runnerPath)
$helper = [IO.File]::ReadAllText($helperPath)
$immichRunner = [IO.File]::ReadAllText($immichRunnerPath)
$piwigoOverlay = [IO.File]::ReadAllText($piwigoOverlayPath)
$immichOverlay = [IO.File]::ReadAllText($immichOverlayPath)
$ownerBrowser = [IO.File]::ReadAllText($ownerBrowserPath)
$ownerBrowserNode = [IO.File]::ReadAllText($ownerBrowserNodePath)
$familyBrowser = [IO.File]::ReadAllText($familyBrowserPath)
$familyBrowserNode = [IO.File]::ReadAllText($familyBrowserNodePath)

foreach ($needle in @(
    "[ValidateSet('validate', 'prepare-storage', 'restore', 'resume', 'verify', 'cold-restart', 'status')]",
    '[switch]$ConfirmCreateRestoreStorage', '[switch]$ConfirmIsolatedRestore', '[switch]$ConfirmColdRestart',
    "`$targetRoot = [IO.Path]::Combine(", "'M:' + [IO.Path]::DirectorySeparatorChar", 'CLASS_ARCHIVE_BACKUP_TARGET',
    "`$PSVersionTable.PSEdition -ne 'Core'", 'powershell_7_required',
    '.Replace("`r`n", "`n")', 'ubuntu_argument_carriage_return_invalid', '@nativeArguments',
    "'last-error-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssfffZ') + '.json'",
    "`$mountPoint = '/mnt/classarchive-owner-restore-v1'", "`$restoreVolumeRoot = `$mountPoint + '/volumes'",
    "`$legacyDockerSocket = '/run/classarchive-owner-restore-v1/docker.sock'", "`$dockerHost = 'unix:///var/run/docker.sock'",
    "`$dockerRoot = '/var/lib/docker'", 'fallocate', 'mkfs.ext4', 'command -v blkid', 'CLASSARCHIVE_OWN',
    'mount -t ext4 -o nodev,nosuid', 'legacy_restore_daemon_active',
    "'--driver','local','--opt','type=none','--opt','o=bind','--opt',('device=' + `$device)",
    'com.classarchive.storage=m-ext4-bind', 'restore_volume_backing_identity_invalid',
    "`$script:controlPlaneId = [string]`$Matches[1]", '127.0.0.1:8290', '127.0.0.1:8291',
    "format -eq 'owner-temporary-recovery-v1'", "scope -eq 'OWNER_PRIVATE_FULL'",
    '$manifestSchemaVersion -in @(15,16)', 'manifest_schema_version=$manifestSchemaVersion',
    "if (`$manifestSchemaVersion -eq 16) { `$countKeys += 'source_presentations' }",
    "`$manifest.counts.PSObject.Properties['source_presentations']", 'manifest_legacy_presentation_count_invalid',
    'temporary_recovery_target -eq $true', 'independent_disaster_backup -eq $false',
    "archive -eq 'GPG_SYMMETRIC_AES256'", "key_protection -eq 'WINDOWS_DPAPI_CURRENT_USER'",
    'plaintext_archive_on_exfat -eq $false', 'must_use_fresh_volumes -eq $true',
    'current_owner_runtime_must_not_be_destroyed -eq $true', 'Get-FileHash -LiteralPath $path -Algorithm SHA256',
    'Test-FixedAsciiEqual', 'restore_checkout_head_invalid', 'restore_checkout_dirty', 'manifest_container_image_invalid', 'bundle_supply_chain_contract_mismatch',
    "protection -eq 'WINDOWS_DPAPI_CURRENT_USER'", "dpapi_scope -eq 'CurrentUser'",
    "'anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password'",
    'Set-ClassArchiveOwnerOnlyFileAcl', 'git -C $projectRoot check-ignore',
    'Get-RestoreToolCommitAllowlist', 'Assert-RestoreCheckout', 'restore_checkout_dirty',
    'merge-base --is-ancestor', 'restore_tool_head_not_source_descendant', 'rev-list --merges', 'restore_tool_history_merge_forbidden',
    'log --format= --name-only --no-renames', 'restore_tool_diff_outside_allowlist',
    'restore_tool_head=', "`$BundleInfo.restore_tool_head", "git -C `$projectRoot merge-base --is-ancestor `$stateToolHead",
    'class_archive_owner_restore_v1_piwigo', 'class_archive_owner_restore_v1_immich',
    'Assert-RestoreGatewayNetwork', "`$piwigoProject + '|immich_gateway|owner-restore-drill|true|10.245.0.0/24'",
    'Assert-RestoreNetworkRangesFree', 'Assert-RestoreNetworkIsolation', 'restore_network_foreign_member', 'restore_container_foreign_network',
    'Assert-FreshRestoreRuntime', 'Assert-AllRestoreVolumeIdentities', 'restore_volume_backing_mount_invalid',
    'New-RestoreNginxConfiguration', 'set_real_ip_from 10.245.0.10/32;', 'restore_nginx_sha256',
    'Initialize-RestoreGitEvidence', '[string]$BundleInfo.manifest.source_head + "`n"', 'restore_git_evidence_head_mismatch',
    'restore_git_evidence_refs_untrusted', 'restore_git_evidence_git_visible', 'restore_state_tool_head_not_ancestor',
    "storage_kind='M_EXT4_BIND'", 'Assert-PrimaryOwnerHttp', 'primary_owner_http_unhealthy',
    'class_archive_owner_restore_v1_immich_model_cache', 'Copy-PinnedImages', "Invoke-RestoreDocker @('image','inspect',`$ref)",
    'source_model_manifest_mismatch', 'target_model_manifest_mismatch', 'Assert-TargetModelCache', 'Copy-VerifiedModelCache $bundleInfo',
    'Assert-PartialRestoreRuntime', "`$expectedVolumes.Count -eq 11", 'resume_volume_topology_invalid',
    "(`$piwigoProject + '-db-1'), (`$piwigoProject + '-piwigo-1')", "@((`$piwigoProject + '-db-1'),(`$immichProject + '-database-1'),(`$immichProject + '-redis-1'))",
    "@((`$piwigoProject + '_app'),`$piwigoProject,'app','false','10.245.1.0/24')",
    'resume_container_topology_invalid', "'true|healthy|running'", "'false|none|created'", 'resume_piwigo_container_state_invalid',
    'resume_network_topology_invalid', 'resume_network_identity_invalid', 'resume_network_foreign_member', 'resume_container_foreign_network',
    'restore_secret_stager_not_stopped', "'-immich-gateway-secret-stager-1'",
    'resume_private_runtime_file_missing', 'Assert-ClassArchiveOwnerOnlyFileAcl -Path $path', 'resume_passphrase_present',
    'resume_restored_count_mismatch', 'Assert-TargetModelCache $BundleInfo',
    'Ensure-RestoreImmichEnvBinding', 'IMMICH_SPIKE_ENV_FILE=../owner-restore/.env.immich', 'resume_immich_environment_binding_invalid',
    "'BEFORE_PIWIGO'", "'AFTER_PIWIGO'", 'resume_piwigo_ports_invalid', '$healthStatus = 0', '$_.Exception.Response.StatusCode', 'resume_piwigo_http_unhealthy',
    "a.state='ACTIVE' AND EXISTS (SELECT 1 FROM `${pwg}image_category ic WHERE ic.category_id=a.piwigo_category_id)",
    'PRIVATE_QA_IMMICH=PASS action=finish', '-Runtime restore', 'pwsh.exe -NoProfile -File',
    'Assert-AiRestoreEvidence', 'reused_existing_indexes -eq $true', 'restore_ai_reindex_detected',
    'metrics.face_jobs -eq 0', 'metrics.recognition_jobs -eq 0', 'metrics.smart_jobs -eq 0',
    'PRIVATE_FULL_OWNER_MEDIA_HTTP=PASS', 'direct_guest_requests=6',
    'find /var/www/html/piwigo/upload /var/www/html/piwigo/galleries -type f ! -perm 0660', 'restored_original_mode_invalid',
    "'--profile','immich-web-compat','up','-d','immich-web-compat'",
    "`$immichProject + '-immich-web-compat-1'", 'browser_e2e=NOT_RUN', 'ai_results=IMMEDIATE', 'primary_owner_changed_during_restore'
)) { Assert-True ($runner.Contains($needle)) ('restore_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
foreach ($needle in @(
    'schema_version=$(q "SELECT COALESCE(MAX(version),0) FROM ${base}migration;")',
    '15) source_presentations=0',
    '16) source_presentations=$(q "SELECT COUNT(*) FROM ${base}photo_source_presentation;")',
    "printf 'class_identity_schema_version=%s\n'",
    "printf 'source_presentations=%s\n'",
    "`$counts.ContainsKey('class_identity_schema_version')",
    'restored_schema_version_mismatch'
)) { Assert-True ($runner.Contains($needle)) ('restore_schema_compatibility_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
Assert-True (-not $runner.Contains('[int]$manifest.schema_versions.class_identity -eq 15')) 'restore_manifest_schema_must_not_be_v15_only'
foreach ($needle in @("'.codex-work\owner-restore\reports'", "'last-error-'", 'Write-OwnerOnlyText', 'exception_type', 'script_stack')) {
    Assert-True ($runner.Contains($needle)) ('restore_local_diagnostic_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains('tool=$(command -v mkfs.ext4)')) 'restore_mkfs_shell_resolution_missing'
Assert-True (-not $runner.Contains("Invoke-Ubuntu @('mkfs.ext4'")) 'restore_mkfs_direct_wsl_exec_detected'
Assert-True ($runner.Contains('restore_unformatted_image_requires_confirmation')) 'restore_unformatted_retry_confirmation_missing'
Assert-True ($runner.Contains('restore_unformatted_image_size_invalid')) 'restore_unformatted_retry_size_guard_missing'
Assert-True ($runner.Contains("[string]`$imageType[0] -eq 'ext4'")) 'restore_existing_image_type_guard_missing'
Assert-True (-not $runner.Contains('--bip=')) 'restore_daemon_bip_must_be_absent'
Assert-True (-not $runner.Contains('nohup dockerd')) 'restore_second_daemon_forbidden'
Assert-True (-not $runner.Contains('--data-root=/mnt/classarchive-owner-restore-v1')) 'restore_second_data_root_forbidden'
foreach ($needle in @('`$line = @(Invoke-RestoreDocker', '`$loopLines = @(Invoke-Ubuntu', '`$lines = @(Invoke-Ubuntu', '`$rootLines = @(Invoke-RestoreDocker', '`$sourceManifestLines = @(Invoke-RestoreDocker', '`$targetManifestLines = @(Invoke-RestoreDocker')) {
    Assert-True ($runner.Contains($needle.Replace('`$','$'))) ('restore_native_array_normalization_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}
Assert-True (-not $runner.Contains("Invoke-RestoreDocker @('network','create'")) 'restore_network_must_be_compose_owned'
Assert-True ($runner.Contains('docker --host "$2" run --rm --log-driver none --network none --read-only --cap-drop ALL --cap-add DAC_READ_SEARCH')) 'restore_model_cache_source_log_driver_missing'
Assert-True (-not $runner.Contains("[string]`$checkoutHead[0] -eq [string]`$manifest.source_head")) 'restore_checkout_must_distinguish_source_and_tool_heads'

$resumeBlock = [regex]::Match($runner, '(?ms)^\s*if \(\$Action -eq ''resume''\) \{.*?(?=^\s*if \(\$Action -eq ''cold-restart''\))').Value
Assert-True (-not [string]::IsNullOrWhiteSpace($resumeBlock)) 'restore_resume_action_missing'
foreach ($needle in @(
    'resume_confirmation_required', 'Assert-PrimaryOwnerHttp', 'Get-PrimaryOwnerFingerprint',
    '$resumeCheckpoint = Assert-PartialRestoreRuntime $bundleInfo', "Invoke-RestoreCompose piwigo @('up','-d','piwigo')",
    'Initialize-RestoreGitEvidence $bundleInfo',
    'Ensure-RestoreImmichEnvBinding', "if (`$resumeCheckpoint -eq 'BEFORE_PIWIGO')",
    "Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','up','-d','immich-machine-learning','immich-server')",
    'Invoke-PrivateImmichFinish', "Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat')",
    'Write-RestoreState $bundleInfo', 'primary_owner_changed_during_resume', 'Invoke-AggregateVerify $bundleInfo'
)) { Assert-True ($resumeBlock.Contains($needle)) ('restore_resume_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
foreach ($forbidden in @('Assert-FreshRestoreRuntime','New-RestoreVolume','Invoke-StreamHelper','Copy-VerifiedModelCache','Read-RecoverySecrets','Initialize-RestoreEnvironments','Remove-Item')) {
    Assert-True (-not $resumeBlock.Contains($forbidden)) ('restore_resume_destructive_or_reimport_action_detected_' + $forbidden.ToLowerInvariant())
}
foreach ($needle in @(
    'source: ../.codex-work/owner-restore/runtime/git-evidence/HEAD', 'target: /workspace/git/HEAD',
    'source: ../.codex-work/owner-restore/runtime/git-evidence/refs', 'target: /workspace/git/refs', 'create_host_path: false'
)) { Assert-True ($piwigoOverlay.Contains($needle)) ('restore_git_evidence_mount_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

# Execute the real environment renderer with synthetic in-memory secrets. This
# catches PowerShell's surprising `string + value, next-item` precedence, which
# otherwise stringifies the remaining array with spaces and produces a one-line
# Compose env file. No file, runtime, backup target or network is touched here.
$initializeEnvironmentBlock = [regex]::Match(
    $runner,
    '(?ms)^function Initialize-RestoreEnvironments\(\[hashtable\]\$Secrets\) \{.*?^\}'
).Value
Assert-True (-not [string]::IsNullOrWhiteSpace($initializeEnvironmentBlock)) 'restore_environment_renderer_missing'
$capturedEnvironment = & {
    $piwigoProject = 'test_piwigo'
    $immichProject = 'test_immich'
    $gatewayNetwork = 'test_gateway'
    $piwigoEnvPath = 'piwigo.env'
    $immichEnvPath = 'immich.env'
    $captured = @{}
    function New-RandomSecret { return ('R' * 48) }
    function Write-OwnerOnlyText([string]$Path, [string]$Text) { $captured[$Path] = $Text }
    . ([ScriptBlock]::Create($initializeEnvironmentBlock))
    Initialize-RestoreEnvironments @{
        piwigo_db_password = ('D' * 48)
        claim_code_pepper = ('C' * 48)
        anonymous_pseudonym_secret = ('A' * 48)
    }
    return $captured
}
Assert-True ($capturedEnvironment.Count -eq 2) 'restore_environment_output_count_invalid'
$expectedEnvironmentLineCounts = @{ 'piwigo.env' = 31; 'immich.env' = 15 }
foreach ($environmentPath in $expectedEnvironmentLineCounts.Keys) {
    $environmentText = [string]$capturedEnvironment[$environmentPath]
    Assert-True ($environmentText.EndsWith("`n", [StringComparison]::Ordinal)) ('restore_environment_final_lf_missing_' + $environmentPath)
    Assert-True (-not $environmentText.Contains("`r")) ('restore_environment_cr_detected_' + $environmentPath)
    $environmentLines = @($environmentText.TrimEnd("`n").Split([char]10))
    Assert-True ($environmentLines.Count -eq $expectedEnvironmentLineCounts[$environmentPath]) ('restore_environment_line_count_invalid_' + $environmentPath)
    Assert-True (@($environmentLines | Where-Object { $_ -notmatch '\A[A-Z][A-Z0-9_]*=.*\z' }).Count -eq 0) ('restore_environment_line_shape_invalid_' + $environmentPath)
    $environmentKeys = @($environmentLines | ForEach-Object { $_.Substring(0, $_.IndexOf('=')) })
    Assert-True (($environmentKeys | Select-Object -Unique).Count -eq $environmentKeys.Count) ('restore_environment_duplicate_key_' + $environmentPath)
}
Assert-True ($capturedEnvironment['piwigo.env'].Contains("COMPOSE_PROJECT_NAME=test_piwigo`nCLASS_ARCHIVE_HTTP_PORT=8290`n")) 'restore_piwigo_environment_newline_serialization_failed'
Assert-True ($capturedEnvironment['immich.env'].Contains("IMMICH_COMPOSE_PROJECT_NAME=test_immich`nCLASS_ARCHIVE_COMPAT_HTTP_PORT=8291`n")) 'restore_immich_environment_newline_serialization_failed'

$allowlistBlock = [regex]::Match($runner, '(?s)function Get-RestoreToolCommitAllowlist \{.*?\n\}').Value
Assert-True (-not [string]::IsNullOrWhiteSpace($allowlistBlock)) 'restore_tool_allowlist_block_missing'
$expectedToolAllowlist = @(
    'infra/owner-restore/README.md','infra/owner-restore/docker-compose.immich.override.yml','infra/owner-restore/docker-compose.piwigo.override.yml',
    'infra/scripts/owner-full-restore-drill.ps1','infra/scripts/private-qa-immich.ps1','infra/scripts/restore-owner-temporary-backup.sh',
    'tests/phase3/private-full-owner-media-http.php',
    'tests/phase3/full-real-browser-qa.mjs','tests/phase3/full-real-browser-qa.ps1',
    'tests/phase3/full-real-family-browser-qa.mjs','tests/phase3/full-real-family-browser-qa.ps1','tests/phase3/owner-full-restore-protocol.ps1'
)
$actualToolAllowlist = @([regex]::Matches($allowlistBlock, "'([^']+)'") | ForEach-Object { $_.Groups[1].Value })
Assert-True (@(Compare-Object ($expectedToolAllowlist | Sort-Object) ($actualToolAllowlist | Sort-Object)).Count -eq 0) 'restore_tool_allowlist_not_exact'

foreach ($needle in @(
    'owner-temporary-recovery-v1', 'business-state/runtime-counts.json', 'business-state/immich-upstream.lock.json',
    'business-state/ml-artifact-manifest.json', 'business-state/recovery-secrets.dpapi.json',
    'databases/mariadb.sql.gz.gpg', 'databases/immich-postgres.dump.gpg',
    'business-state/piwigo-data.tar.gpg', 'business-state/piwigo-scripts.tar.gpg',
    'media-archives/piwigo-uploads.tar.gpg', 'media-archives/piwigo-galleries.tar.gpg',
    'immich-state/immich-upload.tar.gpg'
)) { Assert-True ($runner.Contains($needle)) ('restore_manifest_payload_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

$privateSourceRootMarker = 'M:' + [IO.Path]::DirectorySeparatorChar + 'private-media-root'
foreach ($forbidden in @(
    'docker compose down', 'docker volume rm', 'docker system prune', 'docker image rm', 'docker rm ',
    'losetup -d', 'umount ', 'Remove-Item -Recurse', '0.0.0.0:', '[::]:', $privateSourceRootMarker
)) { Assert-True (-not $runner.Contains($forbidden)) ('restore_runner_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
Assert-True (-not ($runner -match '(?i)Write-(?:Output|Host|Error).*(?:PASSWORD|PASSPHRASE|TOKEN|PEPPER|PSEUDONYM)=')) 'restore_runner_secret_output_detected'
Assert-True (($runner | Select-String -Pattern 'Remove-Item -LiteralPath' -AllMatches).Matches.Count -eq 1 -and
    $runner.Contains('Remove-Item -LiteralPath $passphrasePath -Force')) 'restore_runner_cleanup_not_secret_only'

foreach ($needle in @(
    'set -euo pipefail', 'OWNER_RESTORE_STREAM=FAIL', '/var/run/docker.sock', '/var/lib/docker',
    '/mnt/classarchive-owner-restore-v1/volumes/', 'm-ext4-bind', '/mnt/m/ClassArchive-Temporary-Recovery/bundles/owner-full-',
    '.codex-work/owner-restore/runtime/owner-full-', 'DrvFs projects Windows ACL-protected files as mode 0777',
    'passphrase_bytes=$(wc -c < "$passphrase_file"',
    '--pinentry-mode loopback', '--passphrase-file "$passphrase_file"', '--decrypt "$1"',
    'tar -tf -', '/(^|\/)\.\.($|\/)/', '--numeric-owner --same-owner --same-permissions --acls --xattrs',
    'restore_volume_not_empty', 'mariadb_not_empty', 'pg_restore --exit-on-error --clean --if-exists --no-owner --no-privileges',
    'postgres_gpg_integrity_invalid', 'postgres_list_status=("${PIPESTATUS[@]}")',
    'class-archive-owner-verify.XXXXXXXX', 'cat > "$dump"', 'pg_restore --list "$dump"',
    'postgres_restore_status=("${PIPESTATUS[@]}")', 'class-archive-owner-restore.XXXXXXXX',
    'local/config/database.inc.php', 'chmod 0660', 'OWNER_RESTORE_STREAM=PASS action=$action'
)) { Assert-True ($helper.Contains($needle)) ('restore_helper_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
Assert-True (-not ($helper -match '(?i)(?:printf|echo).*(?:PASSWORD|PASSPHRASE|TOKEN|PEPPER|PSEUDONYM)=')) 'restore_helper_secret_output_detected'
foreach ($forbidden in @('docker volume rm','docker compose down','docker system prune','curl ','wget ','/run/classarchive-owner-restore-v1/docker.sock','0.0.0.0')) {
    Assert-True (-not $helper.Contains($forbidden)) ('restore_helper_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

foreach ($overlay in @($piwigoOverlay,$immichOverlay)) {
    Assert-True ($overlay.Contains('com.classarchive.scope: owner-restore-drill')) 'restore_overlay_scope_label_missing'
    Assert-True ($overlay.Contains('com.classarchive.storage: m-ext4-bind')) 'restore_overlay_storage_label_missing'
    Assert-True (-not ($overlay -match '(?m)^\s*ports:\s*$')) 'restore_overlay_unexpected_ports'
}
foreach ($subnet in @('10.245.0.0/24','10.245.1.0/24')) { Assert-True ($piwigoOverlay.Contains($subnet)) 'restore_piwigo_subnet_missing' }
foreach ($subnet in @('10.245.2.0/24','10.245.3.0/24','10.245.4.0/24')) { Assert-True ($immichOverlay.Contains($subnet)) 'restore_immich_subnet_missing' }
Assert-True ($immichOverlay.Contains('10.245.0.10')) 'restore_bff_address_missing'
Assert-True ($piwigoOverlay.Contains('create_host_path: false')) 'restore_nginx_bind_may_autocreate'
Assert-True ($runner.Contains("Invoke-RestoreCompose piwigo @('create','--no-recreate','piwigo')")) 'restore_gateway_not_compose_created_before_identity_check'
Assert-True ($runner.Contains("Stop-Restore ('restore_stream_' + [string]`$Matches[1])")) 'restore_stream_failure_code_not_safely_propagated'

foreach ($needle in @(
    "[ValidateSet('qa', 'full', 'restore', 'restore-v2')]", "`$Runtime -in @('restore', 'restore-v2')", "private_relative = `$(if (`$restoreV2) { '.codex-work/owner-restore-v2' } else { '.codex-work/owner-restore' })",
    "piwigo_env_relative = `$(if (`$restoreV2) { 'infra/owner-restore-v2/.env.piwigo' } else { 'infra/owner-restore/.env.piwigo' })",
    "immich_env_relative = `$(if (`$restoreV2) { 'infra/owner-restore-v2/.env.immich' } else { 'infra/owner-restore/.env.immich' })",
    "piwigo_project = `$(if (`$restoreV2) { 'class_archive_owner_restore_v2_piwigo' } else { 'class_archive_owner_restore_v1_piwigo' })",
    "immich_project = `$(if (`$restoreV2) { 'class_archive_owner_restore_v2_immich' } else { 'class_archive_owner_restore_v1_immich' })",
    'core_port = $(if ($restoreV2) { 8390 } else { 8290 })', 'compat_port = $(if ($restoreV2) { 8391 } else { 8291 })',
    "`$Runtime -in @('full', 'restore', 'restore-v2')"
)) { Assert-True ($immichRunner.Contains($needle)) ('immich_restore_adapter_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
$restoreIdentityBlock = [regex]::Match(
    $immichRunner,
    "(?ms)^\s*\} elseif \(\`$Runtime -in @\('restore', 'restore-v2'\)\) \{.*?^\s*\} else \{"
).Value
Assert-True (-not [string]::IsNullOrWhiteSpace($restoreIdentityBlock)) 'immich_restore_identity_block_missing'
Assert-True ($restoreIdentityBlock.Contains("technical_name = 'Class Archive Private Full Technical User'") `
    -and $restoreIdentityBlock.Contains("library_name = 'Class Archive Private Full Library'")) 'immich_restore_durable_identity_not_preserved'
foreach ($needle in @(
    "'recover-transients'", "`$Runtime -in @('restore', 'restore-v2')", 'transient_recovery_scope_invalid',
    'aborted_transient_recovery', 'runtime-input.json', 'password-reset-input.txt',
    'databases=UNTOUCHED media=UNTOUCHED', "Remove-PrivateFile `$path",
    'orphan_runtime_process_detected', '/proc/[0-9]*/cmdline',
    "Invoke-ImmichCompose (@('exec', '-T', 'immich-server', 'rm', '-f', '--') + `$immichTemporary)",
    "Invoke-PiwigoCompose (@('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--') + `$piwigoTemporary)"
)) { Assert-True ($immichRunner.Contains($needle)) ('immich_restore_transient_recovery_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
foreach ($needle in @('bridge_stager_stop', "'stop', '-t', '5', 'immich-gateway-secret-stager'", 'bridge_stager_stop_failed', 'exited|none|null')) {
    Assert-True ($immichRunner.Contains($needle)) ('immich_restore_stager_shutdown_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

foreach ($browser in @($ownerBrowser,$familyBrowser)) {
    foreach ($needle in @(
        "[ValidateSet('staging', 'owner', 'restore')]", "`$isRestore = `$Mode -eq 'restore'",
        "'infra/owner-restore/.env.piwigo'", "'infra/owner-restore/docker-compose.piwigo.override.yml'",
        "'infra/private-full/docker-compose.ai-worker.override.yml'", "'class_archive_owner_restore_v1_piwigo'",
        "'OWNER_RESTORE_DRILL'", 'Get-RestoreSystemAdminUsername', "p.system_role='SYSTEM_ADMIN'",
        "'.codex-work\owner-restore\runtime", "'.codex-work\owner-restore\screenshots"
    )) { Assert-True ($browser.Contains($needle)) ('restore_browser_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
    Assert-True (-not $browser.Contains("if (`$isRestore) { 'infra/private-full/.env.piwigo.staging'")) 'restore_browser_staging_env_alias_detected'
    Assert-True ($browser.Contains("`$restoreDockerHost = 'unix:///var/run/docker.sock'") -and $browser.Contains("@('--host',`$restoreDockerHost)")) 'restore_browser_control_plane_not_explicit'
}
foreach ($browserNode in @($ownerBrowserNode,$familyBrowserNode)) {
    foreach ($needle in @("mode === 'restore'", "'OWNER_RESTORE_DRILL'", '/.codex-work/owner-restore/screenshots/')) {
        Assert-True ($browserNode.Contains($needle)) ('restore_browser_node_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
    }
}

[void][ScriptBlock]::Create($runner)
[void][ScriptBlock]::Create($immichRunner)
[void][ScriptBlock]::Create($ownerBrowser)
[void][ScriptBlock]::Create($familyBrowser)
$helperWsl = '/mnt/' + $helperPath.Substring(0,1).ToLowerInvariant() + '/' + $helperPath.Substring(3).Replace('\','/')
& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec bash -n $helperWsl
Assert-True ($LASTEXITCODE -eq 0) 'restore_helper_shell_parse_failed'

Write-Output "OWNER_FULL_RESTORE_PROTOCOL=PASS assertions=$assertions evidence=STATIC_ONLY"
