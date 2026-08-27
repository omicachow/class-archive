[CmdletBinding()]
param()

# Static/protocol-only gate. It never reads owner env/source paths, never starts
# Docker, and never creates or reads the exFAT target. Identity probes use only
# deliberately nonexistent synthetic WSL paths and fail before filesystem I/O.

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
    "[ValidateSet('preflight', 'backup', 'verify', 'verify-portable')]",
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
    "'PRESERVED_BY_DPAPI'",
    "database_root_credentials = 'REGENERATE'",
    "immich_gateway_token = 'ROTATE_AND_REBIND'",
    "'PRESERVED_BY_DPAPI_CLAIM_PEPPER'",
    "'PRESERVED_BY_DPAPI_PSEUDONYM_SECRET'"
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_runner_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

Assert-True ($runner.Contains('[IO.FileShare]::None')) 'owner_temp_backup_single_instance_lock_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_already_running'")) 'owner_temp_backup_single_instance_fail_closed_missing'
Assert-True ($runner.Contains('function Set-OwnerBackupUtf8ConsoleEncoding') `
    -and $runner.Contains('[Console]::OutputEncoding = $utf8') `
    -and $runner.Contains('$script:OutputEncoding = $utf8') `
    -and $runner.Contains("Stop-OwnerBackup 'utf8_console_encoding_unavailable'")) 'owner_temp_backup_utf8_console_guard_missing'
Assert-True (-not ($runner -match '(?m)^\s*\$lines\s*=\s*@\(&\s*\$wsl\b.*\bwslpath\s+-a\b')) 'owner_temp_backup_locale_sensitive_wslpath_reintroduced'
Assert-True ($runner.Contains("if (`$full -notmatch '^([a-zA-Z]):\\(.+)$')")) 'owner_temp_backup_strict_windows_to_wsl_parser_missing'
Assert-True ($runner.Contains("'SAME_WINDOWS_CURRENTUSER_PROFILE_REQUIRED'")) 'owner_temp_backup_dpapi_recovery_boundary_missing'
Assert-True ($runner.Contains('Legacy v1 bundles have only a DPAPI recovery envelope')) 'owner_temp_backup_dpapi_readme_warning_missing'
foreach ($needle in @(
    'function Get-WslSwapCapacityGuard',
    "Join-Path `$env:USERPROFILE '.wslconfig'",
    "'TARGET_NON_SYSTEM_DRIVE'",
    "'SYSTEM_DRIVE_CAPACITY_FALLBACK'",
    "Stop-OwnerBackup 'system_drive_wsl_swap_safety_margin_insufficient'",
    "'WSL_SWAP_PLACEMENT'",
    "'WSL_SWAP_TARGET_DRIVE_MATCH'",
    "'WSL_SWAP_ACTIVE'",
    "'WSL_SWAP_ACTIVE_BYTES'",
    "'WSL_CONFIG_APPLIED_TO_VM'",
    "'SYSTEM_DRIVE_FREE_BYTES'",
    "'SYSTEM_DRIVE_REQUIRED_FREE_BYTES'",
    "'SYSTEM_DRIVE_CAPACITY_GUARD'",
    "'ARCHIVE_HELPER_MEMORY_BYTES'",
    "'ARCHIVE_HELPER_LOG_DRIVER'",
    'private_host_path_recorded = $false'
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_wsl_capacity_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

foreach ($needle in @(
    "'/^btime / {print `$2}'",
    '/proc/stat',
    '/proc/swaps',
    '$configItem.LastWriteTimeUtc -le $bootUtc',
    '$activeSwapCount -eq 1',
    '$sizeDelta -le [uint64](16MB)',
    '$swapFileTrusted -and $configAppliedToVm'
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_active_wsl_evidence_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

$privateDriveMarker = 'M' + ':' + [IO.Path]::DirectorySeparatorChar + ([char]0x56FE) + ([char]0x7247)
Assert-True (-not $runner.Contains($privateDriveMarker)) 'owner_temp_backup_private_source_hardcoded'
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
    'docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128',
    '--format=posix --numeric-owner --acls --xattrs',
    '--cipher-algo AES256',
    '--s2k-digest-algo SHA512',
    '--compress-algo none',
    '--passphrase-file "$passphrase_file"',
    'mariadb-dump --quick --lock-all-tables',
    'pg_dump --format=custom',
    "tablename IN ('asset_face','face_search','person','smart_search')",
    'trap cleanup EXIT HUP INT TERM',
    'capture-restore-fixture.php',
    'pg_snapshot_xmax(pg_current_snapshot())',
    'owner_state_changed_during_backup',
    'postgres_state_changed_during_backup',
    'postgres_state_digest()',
    'immich_upload_archive_snapshot_mismatch',
    'case "$schema_version" in 15|16)',
    'source_presentations=$(mariadb_query "SELECT COUNT(*) FROM ${ci_base}photo_source_presentation;"',
    'source_presentations=0',
    'SOURCE_PRESENTATIONS=$source_presentations',
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
Assert-True ($helper.Contains('assert_container "$immich_server" class_archive_private_full_v3_immich immich-server') `
    -and $helper.Contains('assert_container "$immich_ml" class_archive_private_full_v3_immich immich-machine-learning')) 'owner_temp_backup_optional_immich_scope_guard_missing'
Assert-True ($helper.Contains('assert_running "$piwigo" piwigo') `
    -and $helper.Contains('assert_running "$mariadb" mariadb') `
    -and $helper.Contains('assert_running "$postgres" immich_postgres') `
    -and $helper.Contains('runtime_${role}_not_running')) 'owner_temp_backup_required_runtime_role_guard_missing'
Assert-True (-not $helper.Contains('assert_running "$immich_server"') `
    -and -not $helper.Contains('assert_running "$immich_ml"')) 'owner_temp_backup_optional_immich_runtime_required'
Assert-True ($helper.Contains('persisted index is complete') `
    -and $helper.Contains('before/after database and media guards')) 'owner_temp_backup_optional_immich_rationale_missing'
$archiveHelperRunCount = [regex]::Matches($helper, 'docker run --rm').Count
$limitedArchiveHelperRunCount = [regex]::Matches(
    $helper,
    'docker run --rm --log-driver none --network none --read-only --memory 256m --memory-swap 256m --pids-limit 128'
).Count
Assert-True ($archiveHelperRunCount -gt 0 -and $limitedArchiveHelperRunCount -eq $archiveHelperRunCount) 'owner_temp_backup_archive_helper_memcg_incomplete'
Assert-True ($runner.Contains("`$values['ARCHIVE_HELPER_LOG_DRIVER'] = 'none'")) 'owner_temp_backup_log_driver_evidence_missing'
Assert-True ($runner.Contains('archive_helper_log_driver = [string]$preflight.ARCHIVE_HELPER_LOG_DRIVER')) 'owner_temp_backup_log_driver_manifest_missing'
Assert-True ($runner.Contains("owner_runtime_reads = 'AVAILABLE_DURING_BACKUP'")) 'owner_temp_backup_read_availability_manifest_missing'
Assert-True ($runner.Contains('services_stopped = $false')) 'owner_temp_backup_online_manifest_missing'
Assert-True ($runner.Contains("immich_postgres_guard = 'FULL_LOGICAL_CONTENT_DIGEST_BEFORE_AFTER'")) 'owner_temp_backup_postgres_content_guard_manifest_missing'
Assert-True ($runner.Contains("immich_upload_guard = 'DETERMINISTIC_TAR_DIGEST_BEFORE_ARCHIVE_AFTER'")) 'owner_temp_backup_immich_upload_guard_manifest_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_bundle_inventory_invalid'")) 'owner_temp_backup_exact_bundle_inventory_missing'
Assert-True ($runner.Contains("Stop-OwnerBackup 'backup_manifest_archive_contract_invalid'")) 'owner_temp_backup_manifest_archive_verification_missing'
Assert-True ($runner.Contains("'SOURCE_RECORDS','SOURCE_PRESENTATIONS','CANONICAL_PHOTOS'")) 'owner_temp_backup_presentation_count_evidence_missing'
Assert-True ($runner.Contains('[uint64]$evidence.CLASS_IDENTITY_SCHEMA_VERSION -notin @(15,16)')) 'owner_temp_backup_schema_compatibility_missing'
Assert-True ($runner.Contains('class_identity = [uint64]$evidence.CLASS_IDENTITY_SCHEMA_VERSION')) 'owner_temp_backup_manifest_schema_not_runtime_bound'
Assert-True (-not $runner.Contains('schema_versions = [ordered]@{ class_identity = 15;')) 'owner_temp_backup_manifest_schema_hardcoded'

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
foreach ($needle in @(
    'case "$mode" in preflight|backup|verify|verify-pending)',
    '--expected-backup-id)',
    'case "$target_parent" in /mnt/[a-z])',
    'ClassArchive-Temporary-Recovery-*)',
    'case "$bundle_name" in .partial-owner-full-*)',
    'backup_id=${bundle_name#.partial-}',
    '[ -z "$expected_backup_id" ] || fail unexpected_backup_id_argument',
    'assert_backup_id "$expected_backup_id"',
    '[ "$backup_id" = "$expected_backup_id" ] || fail pending_backup_id_mismatch',
    'expected_passphrase_parent=${backup_id}-verify',
    'expected_passphrase_parent=$backup_id',
    '[ "${passphrase_parent##*/}" = "$expected_passphrase_parent" ] || fail passphrase_bundle_identity_mismatch',
    '[ "${passphrase_runtime_parent##*/}" = owner-temporary-backup ] || fail passphrase_path_invalid',
    '[ "${passphrase_runtime_root##*/}" = runtime ] || fail passphrase_path_invalid',
    '[ "${passphrase_scope_root##*/}" = private-real-full ] || fail passphrase_path_invalid',
    '[ "${passphrase_work_root##*/}" = .codex-work ] || fail passphrase_path_invalid',
    'case "${passphrase_work_root%/*}" in /mnt/c/*)'
)) { Assert-True ($helper.Contains($needle)) ('owner_temp_backup_identity_boundary_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

$verifyDispatchIndex = $helper.IndexOf('if [ "$mode" = verify ] || [ "$mode" = verify-pending ]; then')
$verifyIdentityIndex = $helper.IndexOf('validate_bundle_passphrase_identity "$mode"', $verifyDispatchIndex)
$verifyBundleTrustIndex = $helper.IndexOf('[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_untrusted', $verifyDispatchIndex)
$verifyGpgIndex = $helper.IndexOf('gpg_decrypt "$bundle/databases/mariadb.sql.gz.gpg" | gzip -t', $verifyDispatchIndex)
Assert-True ($verifyDispatchIndex -ge 0 -and $verifyDispatchIndex -lt $helper.IndexOf('assert_container "$piwigo"')) 'owner_temp_backup_verify_must_precede_source_runtime_assertions'
Assert-True ($verifyIdentityIndex -gt $verifyDispatchIndex -and $verifyIdentityIndex -lt $verifyBundleTrustIndex -and $verifyBundleTrustIndex -lt $verifyGpgIndex) 'owner_temp_backup_pending_identity_must_precede_trust_and_gpg'

foreach ($needle in @(
    "`$lines = Invoke-Helper @('verify', '--bundle', (Get-WslPath `$Bundle), '--passphrase-file', (Get-WslPath `$passphrasePath))",
    "'backup', '--bundle', (Get-WslPath `$partial), '--passphrase-file', (Get-WslPath `$passphrasePath),",
    "'verify-pending', '--bundle', (Get-WslPath `$partial), '--passphrase-file', (Get-WslPath `$passphrasePath),",
    "'--expected-backup-id', `$newBackupId"
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_runner_identity_binding_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

$completeIndex = $runner.IndexOf("[IO.File]::WriteAllText((Join-Path `$partial 'COMPLETE')")
$shaIndex = $runner.IndexOf("[IO.File]::WriteAllText((Join-Path `$partial 'SHA256SUMS')")
$pendingChecksumIndex = $runner.LastIndexOf('Verify-ChecksumFile $partial')
$pendingIdentityIndex = $runner.IndexOf('Assert-BundleIdentity $partial $newBackupId -Pending')
$pendingVerifyIndex = $runner.IndexOf("'verify-pending', '--bundle'")
$publishIndex = $runner.IndexOf('Move-Item -LiteralPath $partial -Destination $published')
$publishedVerifyIndex = $runner.IndexOf('Invoke-VerifyBundle $boundary $published')
Assert-True ($completeIndex -ge 0 -and $completeIndex -lt $shaIndex -and $shaIndex -lt $pendingChecksumIndex -and
    $pendingChecksumIndex -lt $pendingIdentityIndex -and $pendingIdentityIndex -lt $pendingVerifyIndex -and
    $pendingVerifyIndex -lt $publishIndex -and
    $publishIndex -lt $publishedVerifyIndex) 'owner_temp_backup_atomic_verify_publish_order_invalid'
Assert-True ($runner.Contains('$manifest.backup_id -ne [IO.Path]::GetFileName($Bundle)')) 'owner_temp_backup_published_manifest_identity_missing'
Assert-True ($runner.Contains('Verify-ChecksumFile $Bundle')) 'owner_temp_backup_standalone_checksum_missing'
foreach ($needle in @(
    'function Assert-BundleIdentity',
    'Test-OwnerBackupId $ExpectedBackupId',
    "'.partial-' + `$ExpectedBackupId",
    '[IO.File]::ReadAllText($completePath), $ExpectedBackupId + "`n"',
    "Stop-OwnerBackup 'backup_complete_marker_invalid'",
    "[string]`$identityManifest.backup_id, `$ExpectedBackupId",
    "Stop-OwnerBackup 'backup_manifest_identity_invalid'",
    'Assert-BundleIdentity $Bundle $publishedBackupId'
)) { Assert-True ($runner.Contains($needle)) ('owner_temp_backup_complete_identity_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()) }

# Parse both scripts without executing any backup or runtime action.
[void][ScriptBlock]::Create($runner)
$helperWslPath = '/mnt/' + $helperPath.Substring(0,1).ToLowerInvariant() + '/' + $helperPath.Substring(3).Replace('\','/')
& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec bash -n $helperWslPath
Assert-True ($LASTEXITCODE -eq 0) 'owner_temp_backup_helper_shell_parse_failed'

function Assert-HelperRejects([string]$ExpectedCode, [string[]]$ProbeArguments) {
    $previousErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec bash $helperWslPath @ProbeArguments 2>&1 |
            ForEach-Object { [string]$_ })
    }
    finally { $ErrorActionPreference = $previousErrorAction }
    Assert-True ($LASTEXITCODE -eq 1) ('owner_temp_backup_identity_probe_exit_' + $ExpectedCode)
    Assert-True (($lines -join "`n") -eq ('OWNER_TEMP_BACKUP_HELPER=FAIL code=' + $ExpectedCode)) ('owner_temp_backup_identity_probe_code_' + $ExpectedCode)
}

$probeId = 'owner-full-20990101T010203Z'
$otherProbeId = 'owner-full-20990101T010204Z'
$v2ProbeId = 'owner-full-v2-20990101T010205Z'
$publishedProbe = '/mnt/z/ClassArchive-Temporary-Recovery/bundles/' + $probeId
$partialProbe = '/mnt/z/ClassArchive-Temporary-Recovery/bundles/.partial-' + $probeId
$pendingPassphraseProbe = '/mnt/c/synthetic/.codex-work/private-real-full/runtime/owner-temporary-backup/' + $probeId + '/gpg-passphrase.txt'
$publishedPassphraseProbe = '/mnt/c/synthetic/.codex-work/private-real-full/runtime/owner-temporary-backup/' + $probeId + '-verify/gpg-passphrase.txt'

Assert-HelperRejects 'bundle_path_invalid' @('verify', '--bundle', $partialProbe, '--passphrase-file', $publishedPassphraseProbe)
Assert-HelperRejects 'pending_bundle_path_invalid' @('verify-pending', '--bundle', $publishedProbe, '--passphrase-file', $pendingPassphraseProbe, '--expected-backup-id', $probeId)
Assert-HelperRejects 'pending_backup_id_mismatch' @('verify-pending', '--bundle', $partialProbe, '--passphrase-file', $pendingPassphraseProbe, '--expected-backup-id', $otherProbeId)
Assert-HelperRejects 'passphrase_bundle_identity_mismatch' @('verify-pending', '--bundle', $partialProbe, '--passphrase-file', ($pendingPassphraseProbe.Replace($probeId, $otherProbeId)), '--expected-backup-id', $probeId)
Assert-HelperRejects 'bundle_path_invalid' @('verify-pending', '--bundle', ('/mnt/z/ClassArchive-Temporary-RecoveryEvil/bundles/.partial-' + $probeId), '--passphrase-file', $pendingPassphraseProbe, '--expected-backup-id', $probeId)
Assert-HelperRejects 'bundle_untrusted' @('verify-pending', '--bundle', $partialProbe, '--passphrase-file', $pendingPassphraseProbe, '--expected-backup-id', $probeId)
Assert-HelperRejects 'bundle_untrusted' @('verify', '--bundle', $publishedProbe, '--passphrase-file', $publishedPassphraseProbe)

$v2PublishedProbe = '/mnt/z/ClassArchive-Temporary-Recovery/bundles/' + $v2ProbeId
$v2PartialProbe = '/mnt/z/ClassArchive-Temporary-Recovery/bundles/.partial-' + $v2ProbeId
$v2PendingPassphraseProbe = '/mnt/c/synthetic/.codex-work/private-real-full/runtime/owner-temporary-backup/' + $v2ProbeId + '/gpg-passphrase.txt'
$v2PublishedPassphraseProbe = '/mnt/c/synthetic/.codex-work/private-real-full/runtime/owner-temporary-backup/' + $v2ProbeId + '-verify/gpg-passphrase.txt'
Assert-HelperRejects 'bundle_untrusted' @('verify-pending', '--bundle', $v2PartialProbe, '--passphrase-file', $v2PendingPassphraseProbe, '--expected-backup-id', $v2ProbeId)
Assert-HelperRejects 'bundle_untrusted' @('verify', '--bundle', $v2PublishedProbe, '--passphrase-file', $v2PublishedPassphraseProbe)

Write-Output "OWNER_TEMP_BACKUP_PROTOCOL=PASS assertions=$assertions"
