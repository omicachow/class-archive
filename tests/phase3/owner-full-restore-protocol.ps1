[CmdletBinding()]
param()

# Static/protocol-only gate. It does not read M:, create an ext4 image, start a
# daemon, create a volume, decrypt a payload, or contact either runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\owner-full-restore-drill.ps1'
$helperPath = Join-Path $projectRoot 'infra\scripts\restore-owner-temporary-backup.sh'
$immichRunnerPath = Join-Path $projectRoot 'infra\scripts\private-qa-immich.ps1'
$piwigoOverlayPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.piwigo.override.yml'
$immichOverlayPath = Join-Path $projectRoot 'infra\owner-restore\docker-compose.immich.override.yml'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

foreach ($path in @($runnerPath,$helperPath,$immichRunnerPath,$piwigoOverlayPath,$immichOverlayPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) 'restore_protocol_file_missing'
}
$runner = [IO.File]::ReadAllText($runnerPath)
$helper = [IO.File]::ReadAllText($helperPath)
$immichRunner = [IO.File]::ReadAllText($immichRunnerPath)
$piwigoOverlay = [IO.File]::ReadAllText($piwigoOverlayPath)
$immichOverlay = [IO.File]::ReadAllText($immichOverlayPath)

foreach ($needle in @(
    "[ValidateSet('validate', 'prepare-storage', 'restore', 'verify', 'cold-restart', 'status')]",
    '[switch]$ConfirmCreateRestoreStorage', '[switch]$ConfirmIsolatedRestore', '[switch]$ConfirmColdRestart',
    "`$targetRoot = '<temporary-recovery-target>'", 'CLASS_ARCHIVE_BACKUP_TARGET',
    "`$mountPoint = '/mnt/classarchive-owner-restore-v1'", "`$dockerSocket = '/run/classarchive-owner-restore-v1/docker.sock'",
    "`$dockerRoot = `$mountPoint + '/docker-data'", 'fallocate', 'mkfs.ext4', "'-L','CLASSARCHIVE_OWNER_RESTORE_V1'",
    'mount -t ext4 -o nodev,nosuid', '--host=unix:///run/classarchive-owner-restore-v1/docker.sock',
    '--data-root=/mnt/classarchive-owner-restore-v1/docker-data', '--exec-root=/run/classarchive-owner-restore-v1/exec',
    '--pidfile=/run/classarchive-owner-restore-v1/dockerd.pid', '--bridge=ca_restore0', '--bip=10.246.0.1/24',
    '--default-address-pool=base=10.247.0.0/16,size=24', '--userland-proxy=false',
    "`$primaryRoot -eq '/var/lib/docker'", '127.0.0.1:8290', '127.0.0.1:8291',
    "format -eq 'owner-temporary-recovery-v1'", "scope -eq 'OWNER_PRIVATE_FULL'",
    'temporary_recovery_target -eq $true', 'independent_disaster_backup -eq $false',
    "archive -eq 'GPG_SYMMETRIC_AES256'", "key_protection -eq 'WINDOWS_DPAPI_CURRENT_USER'",
    'plaintext_archive_on_exfat -eq $false', 'must_use_fresh_volumes -eq $true',
    'current_owner_runtime_must_not_be_destroyed -eq $true', 'Get-FileHash -LiteralPath $path -Algorithm SHA256',
    'Test-FixedAsciiEqual', 'restore_checkout_mismatch', 'manifest_container_image_invalid', 'bundle_supply_chain_contract_mismatch',
    "protection -eq 'WINDOWS_DPAPI_CURRENT_USER'", "dpapi_scope -eq 'CurrentUser'",
    "'anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password'",
    'Set-ClassArchiveOwnerOnlyFileAcl', 'git -C $projectRoot check-ignore',
    'class_archive_owner_restore_v1_piwigo', 'class_archive_owner_restore_v1_immich',
    'class_archive_owner_restore_v1_immich_model_cache', 'Copy-PinnedImages', 'docker image save',
    'source_model_manifest_mismatch', 'target_model_manifest_mismatch', 'Copy-VerifiedModelCache $bundleInfo',
    'PRIVATE_QA_IMMICH=PASS action=finish', '-Runtime restore',
    'Assert-AiRestoreEvidence', 'reused_existing_indexes -eq $true', 'restore_ai_reindex_detected',
    'metrics.face_jobs -eq 0', 'metrics.recognition_jobs -eq 0', 'metrics.smart_jobs -eq 0',
    'PRIVATE_FULL_OWNER_MEDIA_HTTP=PASS', 'direct_guest_requests=6',
    'find /var/www/html/piwigo/upload /var/www/html/piwigo/galleries -type f ! -perm 0660', 'restored_original_mode_invalid',
    'browser_e2e=NOT_RUN', 'ai_results=IMMEDIATE', 'primary_owner_changed_during_restore'
)) { Assert-True ($runner.Contains($needle)) ('restore_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

foreach ($needle in @(
    'owner-temporary-recovery-v1', 'business-state/runtime-counts.json', 'business-state/immich-upstream.lock.json',
    'business-state/ml-artifact-manifest.json', 'business-state/recovery-secrets.dpapi.json',
    'databases/mariadb.sql.gz.gpg', 'databases/immich-postgres.dump.gpg',
    'business-state/piwigo-data.tar.gpg', 'business-state/piwigo-scripts.tar.gpg',
    'media-archives/piwigo-uploads.tar.gpg', 'media-archives/piwigo-galleries.tar.gpg',
    'immich-state/immich-upload.tar.gpg'
)) { Assert-True ($runner.Contains($needle)) ('restore_manifest_payload_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

foreach ($forbidden in @(
    'docker compose down', 'docker volume rm', 'docker system prune', 'docker image rm', 'docker rm ',
    'losetup -d', 'umount ', 'Remove-Item -Recurse', '0.0.0.0:', '[::]:', '<private-source-root>'
)) { Assert-True (-not $runner.Contains($forbidden)) ('restore_runner_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
Assert-True (-not ($runner -match '(?i)Write-(?:Output|Host|Error).*(?:PASSWORD|PASSPHRASE|TOKEN|PEPPER|PSEUDONYM)=')) 'restore_runner_secret_output_detected'
Assert-True (($runner | Select-String -Pattern 'Remove-Item -LiteralPath' -AllMatches).Matches.Count -eq 1 -and
    $runner.Contains('Remove-Item -LiteralPath $passphrasePath -Force')) 'restore_runner_cleanup_not_secret_only'

foreach ($needle in @(
    'set -euo pipefail', 'OWNER_RESTORE_STREAM=FAIL', '/run/classarchive-owner-restore-v1/docker.sock',
    '/mnt/classarchive-owner-restore-v1/docker-data', '/mnt/m/ClassArchive-Temporary-Recovery/bundles/owner-full-',
    '.codex-work/owner-restore/runtime/owner-full-', 'DrvFs projects Windows ACL-protected files as mode 0777',
    'passphrase_bytes=$(wc -c < "$passphrase_file"',
    '--pinentry-mode loopback', '--passphrase-file "$passphrase_file"', '--decrypt "$1"',
    'tar -tf -', '/(^|\/)\.\.($|\/)/', '--numeric-owner --same-owner --same-permissions --acls --xattrs',
    'restore_volume_not_empty', 'mariadb_not_empty', 'pg_restore --exit-on-error --clean --if-exists --no-owner --no-privileges',
    'postgres_gpg_integrity_invalid', 'postgres_list_status=("${PIPESTATUS[@]}")', '0|141',
    'local/config/database.inc.php', 'chmod 0660', 'OWNER_RESTORE_STREAM=PASS action=$action'
)) { Assert-True ($helper.Contains($needle)) ('restore_helper_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }
Assert-True (-not ($helper -match '(?i)(?:printf|echo).*(?:PASSWORD|PASSPHRASE|TOKEN|PEPPER|PSEUDONYM)=')) 'restore_helper_secret_output_detected'
foreach ($forbidden in @('docker volume rm','docker compose down','docker system prune','curl ','wget ','/var/run/docker.sock','0.0.0.0')) {
    Assert-True (-not $helper.Contains($forbidden)) ('restore_helper_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant())
}

foreach ($overlay in @($piwigoOverlay,$immichOverlay)) {
    Assert-True ($overlay.Contains('com.classarchive.scope: owner-restore-drill')) 'restore_overlay_scope_label_missing'
    Assert-True ($overlay.Contains('com.classarchive.storage: m-ext4-image')) 'restore_overlay_storage_label_missing'
    Assert-True (-not ($overlay -match '(?m)^\s*ports:\s*$')) 'restore_overlay_unexpected_ports'
}
Assert-True ($piwigoOverlay.Contains('10.245.0.0/16')) 'restore_gateway_subnet_missing'
Assert-True ($immichOverlay.Contains('10.245.0.10')) 'restore_bff_address_missing'

foreach ($needle in @(
    "[ValidateSet('qa', 'full', 'restore')]", "`$Runtime -eq 'restore'", "private_relative = '.codex-work/owner-restore'",
    "piwigo_env_relative = 'infra/owner-restore/.env.piwigo'", "immich_env_relative = 'infra/owner-restore/.env.immich'",
    "piwigo_project = 'class_archive_owner_restore_v1_piwigo'", "immich_project = 'class_archive_owner_restore_v1_immich'",
    'core_port = 8290', 'compat_port = 8291', "`$Runtime -in @('full', 'restore')"
)) { Assert-True ($immichRunner.Contains($needle)) ('immich_restore_adapter_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

[void][ScriptBlock]::Create($runner)
[void][ScriptBlock]::Create($immichRunner)
$helperWsl = '/mnt/' + $helperPath.Substring(0,1).ToLowerInvariant() + '/' + $helperPath.Substring(3).Replace('\','/')
& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec bash -n $helperWsl
Assert-True ($LASTEXITCODE -eq 0) 'restore_helper_shell_parse_failed'

Write-Output "OWNER_FULL_RESTORE_PROTOCOL=PASS assertions=$assertions evidence=STATIC_ONLY"
