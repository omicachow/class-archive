[CmdletBinding()]
param()

# Static-only gate. It never reads owner env/source paths, never starts Docker,
# and never creates the exFAT target.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\owner-temporary-backup.ps1'
$helperPath = Join-Path $projectRoot 'infra\scripts\create-owner-temporary-backup.sh'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'owner_temp_backup_runner_missing'
Assert-True (Test-Path -LiteralPath $helperPath -PathType Leaf) 'owner_temp_backup_helper_missing'
$runner = [IO.File]::ReadAllText($runnerPath)
$helper = [IO.File]::ReadAllText($helperPath)

foreach ($needle in @(
    "[ValidateSet('preflight', 'backup', 'verify')]",
    '[string[]]$ProtectedSourceRootPath',
    '[switch]$ConfirmOwnerTemporaryBackup',
    '[switch]$AcceptSameDiskTemporaryRecoveryLimitation',
    "'target_must_be_drive_root_child'",
    "'target_filesystem_not_exfat'",
    "'target_overlaps_protected_source'",
    "'target_marker_invalid'",
    "'existing_target_marker_missing'",
    "'target_free_space_insufficient'",
    "'checkout_not_clean'",
    'CLASS_ARCHIVE_BACKUP_TARGET',
    'temporary owner recovery target',
    'independent_disaster_backup = $false',
    'ConvertFrom-SecureString',
    'WINDOWS_DPAPI_CURRENT_USER',
    'Set-ClassArchiveOwnerOnlyFileAcl',
    'Remove-Item -LiteralPath $passphrasePath',
    'GPG_SYMMETRIC_AES256',
    "'databases/mariadb.sql.gz.gpg'",
    "'databases/immich-postgres.dump.gpg'",
    "'media-archives/piwigo-uploads.tar.gpg'",
    "'media-archives/piwigo-galleries.tar.gpg'",
    "'immich-state/immich-upload.tar.gpg'",
    "'business-state/recovery-secrets.dpapi.json'",
    "'manifest.json'",
    "'SHA256SUMS'",
    "'COMPLETE'",
    "piwigo_database_password = 'PRESERVED_BY_DPAPI'",
    "database_root_credentials = 'REGENERATE'",
    "immich_gateway_token = 'ROTATE_AND_REBIND'",
    "outstanding_claim_tokens = 'PRESERVED_BY_DPAPI_CLAIM_PEPPER'",
    "anonymous_pseudonyms = 'PRESERVED_BY_DPAPI_PSEUDONYM_SECRET'"
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

Assert-True ($runner.Contains('[IO.FileShare]::None')) 'owner_temp_backup_single_instance_lock_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_already_running'")) 'owner_temp_backup_single_instance_fail_closed_missing'
Assert-True ($runner.Contains("recovery_key_boundary = 'SAME_WINDOWS_CURRENTUSER_PROFILE_REQUIRED'")) 'owner_temp_backup_dpapi_recovery_boundary_missing'
Assert-True ($runner.Contains('does not recover from loss of that')) 'owner_temp_backup_dpapi_readme_warning_missing'

Assert-True (-not $runner.Contains('<private-source-root>')) 'owner_temp_backup_private_source_hardcoded'
Assert-True (-not ($runner -match '(?i)Write-(?:Output|Host).*(?:Passphrase|Pseudonym|Pepper|DB_PASSWORD|TOKEN)')) 'owner_temp_backup_secret_output_detected'
Assert-True ($runner.Contains('Get-FileHash -LiteralPath $path -Algorithm SHA256')) 'owner_temp_backup_sha256_missing'
Assert-True ($runner.Contains('function Test-FixedAsciiEqual') -and $runner.Contains('Test-FixedAsciiEqual $actual')) 'owner_temp_backup_constant_time_verify_missing'
Assert-True ($runner.Contains("plaintext_archive_on_exfat = `$false")) 'owner_temp_backup_plaintext_exfat_policy_missing'
Assert-True ($runner.Contains("piwigo_derivative_cache") -and $runner.Contains("immich_model_binaries")) 'owner_temp_backup_rebuildable_exclusions_missing'

foreach ($needle in @(
    'class_archive_private_full_v3_piwigo-piwigo-1',
    'class_archive_private_full_v3_immich-immich-server-1',
    'com.classarchive.scope',
    'private-real-full',
    'docker run --rm --network none --read-only --cap-drop ALL',
    '--format=posix --numeric-owner --acls --xattrs',
    '--cipher-algo AES256',
    '--s2k-digest-algo SHA512',
    '--compress-algo none',
    '--passphrase-file "$passphrase_file"',
    'mariadb-dump --quick --lock-all-tables',
    'pg_dump --format=custom',
    'trap cleanup EXIT HUP INT TERM',
    'capture-restore-fixture.php',
    'pg_snapshot_xmax(pg_current_snapshot())',
    'owner_state_changed_during_backup',
    'postgres_state_changed_during_backup',
    'postgres_state_digest()',
    'immich_upload_archive_snapshot_mismatch',
    '--sort=name --format=posix --pax-option=delete=atime,delete=ctime',
    'postgres_dump_authentication_failed',
    'restore_status=${PIPESTATUS[1]}',
    'Verification is deliberately independent of the source 8191 runtime',
    '[ "$postgres_magic" = PGDMP ]',
    "EXISTS (SELECT 1 FROM `${pwg_base}image_category ic WHERE ic.category_id=a.piwigo_category_id)",
    '--exclude=./local/config/database.inc.php',
    '--exclude=./_data/.class-archive-immich-bridge.json',
    '--exclude=./_data/sessions',
    'gpg_decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -t',
    'pg_restore --list',
    'OWNER_TEMP_BACKUP_HELPER=PASS action=backup'
)) { Assert-True ($helper.Contains($needle)) ('owner_temp_backup_helper_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

Assert-True (-not $helper.Contains('docker stop')) 'owner_temp_backup_must_not_stop_owner_runtime'
Assert-True (-not $helper.Contains('docker start')) 'owner_temp_backup_must_not_restart_owner_runtime'
Assert-True ($helper.IndexOf('if [ "$mode" = verify ]; then') -lt $helper.IndexOf('assert_container "$piwigo"')) 'owner_temp_backup_verify_must_precede_source_runtime_assertions'
Assert-True ($runner.Contains("owner_runtime_reads = 'AVAILABLE_DURING_BACKUP'")) 'owner_temp_backup_read_availability_manifest_missing'
Assert-True ($runner.Contains('services_stopped = $false')) 'owner_temp_backup_online_manifest_missing'
Assert-True ($runner.Contains("immich_postgres_guard = 'FULL_LOGICAL_CONTENT_DIGEST_BEFORE_AFTER'")) 'owner_temp_backup_postgres_content_guard_manifest_missing'
Assert-True ($runner.Contains("immich_upload_guard = 'DETERMINISTIC_TAR_DIGEST_BEFORE_ARCHIVE_AFTER'")) 'owner_temp_backup_immich_upload_guard_manifest_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_bundle_inventory_invalid'")) 'owner_temp_backup_exact_bundle_inventory_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_manifest_archive_contract_invalid'")) 'owner_temp_backup_manifest_archive_verification_missing'

foreach ($forbidden in @(
    'class_archive_private_full_v3_immich_gateway_secret',
    'class_archive_private_full_v3_immich_model_cache',
    'class_archive_private_full_v3_piwigo_derivatives',
    'docker volume rm',
    'docker compose down',
    'docker system prune',
    'curl ',
    'wget '
)) { Assert-True (-not $helper.Contains($forbidden)) ('owner_temp_backup_forbidden_helper_operation_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

Assert-True (-not ($helper -match '(?i)printf.*(?:PASSWORD|PASSPHRASE|TOKEN|PEPPER|PSEUDONYM)=')) 'owner_temp_backup_helper_secret_output_detected'
Assert-True ($helper.Contains('case "$bundle" in /mnt/[a-z]/ClassArchive-Temporary-Recovery*/bundles/*)')) 'owner_temp_backup_helper_target_boundary_missing'
Assert-True ($helper.Contains('case "$passphrase_file" in /mnt/c/*/.codex-work/private-real-full/runtime/owner-temporary-backup/*/gpg-passphrase.txt)')) 'owner_temp_backup_helper_passphrase_boundary_missing'

# Parse both scripts without executing them.
[void][ScriptBlock]::Create($runner)
& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec bash -n (('/mnt/' + $helperPath.Substring(0,1).ToLowerInvariant() + '/' + $helperPath.Substring(3).Replace('\','/')))
Assert-True ($LASTEXITCODE -eq 0) 'owner_temp_backup_helper_shell_parse_failed'

Write-Output "OWNER_TEMP_BACKUP_PROTOCOL=PASS assertions=$assertions"
