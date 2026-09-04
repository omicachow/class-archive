[CmdletBinding()]
param()

# Static-only public contract. It does not inspect private packages, Docker,
# owner configuration, source photographs, or local secret material.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$handoffRoot = Join-Path $projectRoot 'infra\mac-handoff'
$preflightPath = Join-Path $handoffRoot 'mac-preflight.sh'
$verifyPath = Join-Path $handoffRoot 'verify-handoff-package.sh'
$archiveVerifyPath = Join-Path $handoffRoot 'verify-handoff-archive.sh'
$capturePath = Join-Path $handoffRoot 'capture-local-private-runtime.sh'
$finalizePath = Join-Path $handoffRoot 'finalize-local-private-handoff.ps1'
$sourceInventoryPath = Join-Path $handoffRoot 'capture-private-source-inventory.ps1'
$sourceVerifyPath = Join-Path $handoffRoot 'verify-private-source-archives.py'
$restorePath = Join-Path $handoffRoot 'restore-mac.sh'
$docPath = Join-Path $handoffRoot 'HANDOFF-MAC-PRIVATE.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

foreach ($path in @($preflightPath,$verifyPath,$archiveVerifyPath,$capturePath,$finalizePath,$sourceInventoryPath,$sourceVerifyPath,$restorePath,$docPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('mac_handoff_file_missing_' + [IO.Path]::GetFileName($path))
}

$preflight = [IO.File]::ReadAllText($preflightPath)
$verify = [IO.File]::ReadAllText($verifyPath)
$archiveVerify = [IO.File]::ReadAllText($archiveVerifyPath)
$capture = [IO.File]::ReadAllText($capturePath)
$finalize = [IO.File]::ReadAllText($finalizePath)
$sourceInventory = [IO.File]::ReadAllText($sourceInventoryPath)
$sourceVerify = [IO.File]::ReadAllText($sourceVerifyPath)
$restore = [IO.File]::ReadAllText($restorePath)
$doc = [IO.File]::ReadAllText($docPath)

foreach ($needle in @(
    'Darwin','classarchive-mac-restore','docker compose version','fresh_project_has_no_docker_objects',
    'container_arch_gate_amd64_lock_on_apple_silicon_requires_isolated_runtime_proof',
    "node_version_24.15.0","pnpm_version_11.13.1",'google_chrome_stable_present',
    'PACKAGE_VERIFIED=','MAC_RUNTIME_TESTED=NO','MAC_PREFLIGHT=PASS_STATIC_ONLY',
    'command_name in git docker gpg python3 tar gzip zstd shasum lsof',
    'port_${port}_out_of_range','port_${port}_duplicate','container-lock.json',
    'container_lock_contract_invalid','docker_desktop_vm_capacity_requires_manual_gate',
    'restore_compose_render_and_named_volume_gate_required'
)) { Assert-True ($preflight.Contains($needle)) ('mac_preflight_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'gsha256sum','sha256sum','shasum -a 256','CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V1',
    'class-archive-mac-private-handoff-v1','PRIVATE_LOCAL_ARTIFACT','PRIVATE_NONSECRET_METADATA','PRIVATE_ENCRYPTED_DATA',
    'symlink_in_outer_package_forbidden','PACKAGE_VERIFIED=PASS','MAC_RUNTIME_TESTED=NO'
)) { Assert-True ($verify.Contains($needle)) ('mac_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'class-archive-mac-private-handoff-v2','CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2',
    'PRIVATE_UNENCRYPTED_LOCAL_DATA','LOCAL_PHYSICAL_MEDIA_ONLY','OUT_OF_BAND_REQUIRED',
    'contains_plaintext_runtime_secrets','required_component_class','component_items','payload_paths',
    'path.stat().st_nlink == 1','PRIVATE_NONSECRET_METADATA','validate_inner_tar(candidate)',
    'member.isfile()','member.isdir()','tarfile.open(path, mode="r:*")',
    'verify_source_snapshot(source_archive, bundle, branch, head)',
    'git", "ls-tree", "-rz", "--full-tree", head',
    'mode in {b"100644", b"100755"} and object_type == b"blob"',
    'git", "archive", "--format=tar", "--prefix=class-archive/", head',
    'git", "hash-object", "--stdin", f"--path={relative}"',
    'cleaned_object_id == expected["object_id"]',
    'hashlib.sha256(data).hexdigest() == reference["sha256"]',
    'actual_files == expected_files','actual_directories == expected_directories',
    'verify_official_upstream_cache','official_github_codeload',
    '8aa95c67470a02a8ddedf03c2e52963af33065ff',
    'hashlib.sha256(source_data).hexdigest() == source_lock["sha256"]',
    'refs/tags/v3.1.0','verify_oci_manifest',
    'immich-3.1.0/web/package.json','build/_app/version.json',
    'verify_zip_plugin','Version: 16.f'
)) { Assert-True ($verify.Contains($needle)) ('mac_v2_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'EXPECTED_SHA256','outer_sha256_mismatch','archive_member_boundary_invalid','member.isfile() or member.isdir()',
    'len(roots) == 1','--no-same-owner','HANDOFF_ARCHIVE_VERIFY=PASS',
    'unicodedata.normalize("NFC", canonical).casefold()','portable_types.get(parent) != "file"',
    'outer_sha256_changed_during_verification','EXPECTED_SHA256 [WORK_DIR]',
    'work_directory_symlink_forbidden','work_directory_insufficient_space',
    '.classarchive-handoff-verify.'
)) { Assert-True ($archiveVerify.Contains($needle)) ('mac_archive_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'package_root_outside_approved_staging','CLASS_ARCHIVE_HANDOFF_STAGING_ROOT',
    'approved_staging_root_symlink_forbidden','mariadb_sanitization_incomplete',
    'piwigo_sessions','piwigo_user_auth_keys','activation_key=NULL','param=''secret_key''',
    '--exclude-table-data=public.session','--exclude-table-data=public.api_key',
    '--exclude-table-data=public.shared_link','--exclude-table-data=public.system_metadata',
    'owner-immich-canonical.tar','./library ./upload ./profile','./thumbs ./encoded-video',
    'immich_upload_root_shape_unknown','--exclude=./local/config/database.inc.php',
    '--exclude=./_data/.class-archive-immich-bridge.json','runtime_secrets_included":false',
    'org.classarchive.disposable=handoff-sanitizer','realpath -sm','docker stop -t 60',
    'wait_container_ready','staging_package_timestamp_mismatch','concurrent_capture_in_progress',
    'unexpected_running_${scope}_network_container_'
)) { Assert-True ($capture.Contains($needle)) ('mac_capture_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'class-archive-mac-private-handoff-v2','LOCAL_PHYSICAL_MEDIA_ONLY',
    'contains_plaintext_runtime_secrets=$false','OWNER_MARIADB','OWNER_IMMICH_POSTGRES',
    'OWNER_CANONICAL_MEDIA','OWNER_DERIVATIVES','PRIVATE_SOURCE_LIBRARY',
    'READ_ONLY_PROVENANCE_DO_NOT_AUTO_IMPORT','NONE_OWNER_DECLINED_PASSPHRASE',
    'NOT_GUARANTEED_WITHOUT_OUT_OF_BAND_ORIGINAL_SECRET','CLASS_ARCHIVE_MAC_PRIVATE_HANDOFF_COMPLETE_V2',
    'git_worktree_dirty','git fsck --full --strict','verify-public-boundary.ps1','-Mode Head','-Mode Outgoing',
    'git_head_public_boundary','git_outgoing_public_boundary','payload_reparse_point_forbidden',
    'secret_or_transient_named_payload_forbidden','ApprovedStagingRoot','approved_staging_root_invalid_or_reparse_point'
)) { Assert-True ($finalize.Contains($needle)) ('mac_finalize_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'class-archive-private-source-inventory-v1','source_root_invalid_or_reparse_point',
    'source_relative_path_invalid_','Get-FileHash','SHA256','LastWriteTimeUtc.ToString(''o'')',
    'output_path_outside_approved_private_staging','ApprovedStagingRoot','approved_staging_root_invalid_or_reparse_point'
)) { Assert-True ($sourceInventory.Contains($needle)) ('mac_source_inventory_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'PRIVATE_SOURCE_ARCHIVE_VERIFY=PASS','source_inventory_path_set_changed',
    'source_inventory_content_changed','source_archive_member_sha256_mismatch',
    'source_archive_nonregular_member','unicodedata.normalize','fromisoformat'
)) { Assert-True ($sourceVerify.Contains($needle)) ('mac_source_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    '--init-env','RUNTIME_SECRETS=GENERATED_NOT_PRINTED','--prepare-source',
    '--preflight-only','--dry-run','DOCKER_OBJECTS_CREATED=0',
    'fresh_project_container_exists','fresh_project_volume_exists','fresh_project_network_exists',
    'state_dir_not_fresh','restore_volume_not_empty','127.0.0.1',
    'owner-mariadb.sql.gz','owner-immich-postgres.dump',
    '--numeric-owner --same-owner --same-permissions --acls --xattrs',
    'piwigo_database_config_restore_failed','OWNER_DATABASE_COUNTS=PASS_MANIFEST_EXACT',
    'IMMICH_METADATA_BOOTSTRAP=NOT_RUN','IMMICH_BRIDGE_BOOTSTRAP=NOT_RUN',
    'ML_MODEL_CACHE=EXCLUDED_NOT_RESTORED','AI_RESULTS_AVAILABLE_IMMEDIATELY=NOT_RUNTIME_TESTED',
    'ANONYMOUS_PSEUDONYM_CONTINUITY=NOT_GUARANTEED','MAC_RUNTIME_TESTED=NO',
    'DATA_RESTORED_PIWIGO_CORE_READY'
)) { Assert-True ($restore.Contains($needle)) ('mac_restore_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($forbidden in @(
    'docker volume rm','docker volume prune','docker system prune','docker compose down',
    'docker network rm','docker container rm','docker rm '
)) { Assert-True (-not $restore.Contains($forbidden)) ('mac_restore_destructive_contract_detected_' + ($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'PACKAGE_VERIFIED=PASS','MAC_RUNTIME_TESTED=YES','Apple Silicon','linux/amd64',
    'Docker Compose v2','GnuPG','Node `24.15.0`','pnpm `11.13.1`','Google Chrome Stable',
    'fresh','127.0.0.1','Synthetic-first','portable GPG envelope','Windows DPAPI',
    'MediaGuard','X-Accel-Redirect','redistribution','PROHIBITED','UNKNOWN',
    'PRODUCTION_READY=NO','PRIVATE_UNENCRYPTED_LOCAL_DATA','HANDOFF_V2_MODE=LOCAL_PHYSICAL_MEDIA_ONLY',
    'HANDOFF_V2_ENCRYPTION=NONE','HANDOFF_V2_PUBLIC_OR_CLOUD_TRANSFER=FORBIDDEN',
    'HANDOFF_V2_MAC_FILEVAULT_RECOMMENDED=YES','v2 **没有恢复口令，也没有便携密钥 envelope**',
    'ANONYMOUS_PSEUDONYM_CONTINUITY=NOT_GUARANTEED'
)) { Assert-True ($doc.Contains($needle)) ('mac_handoff_doc_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($text in @($preflight,$verify,$archiveVerify,$capture,$finalize,$sourceInventory,$sourceVerify,$restore,$doc)) {
    Assert-True (-not ($text -match '(?im)^\s*(?:docker\s+system\s+prune|docker\s+volume\s+prune|docker\s+compose\s+down\s+-v|rm\s+-rf\s+/)')) 'mac_handoff_destructive_command_detected'
    Assert-True (-not $text.Contains('PRIVATE_SOURCE_ROOT_LITERAL')) 'mac_handoff_private_source_path_detected'
    Assert-True (-not ($text -match '(?i)(?:password|passphrase|token|secret)\s*=\s*["''](?!\$)[^"'']+["'']')) 'mac_handoff_literal_secret_detected'
}

Assert-True ($preflight.Contains('docker ps -aq --filter') -and $preflight.Contains('docker volume ls -q --filter')) 'mac_preflight_fresh_volume_readonly_check_missing'
Assert-True (-not $preflight.Contains('docker volume rm')) 'mac_preflight_volume_mutation_detected'
Assert-True ($verify.Contains('checksummed_file_missing_or_not_regular') -and $verify.Contains('checksum_path_unsafe')) 'mac_verifier_path_boundary_missing'
Assert-True ($verify.Contains('evidence.get("capture_completed") is True')) 'mac_verifier_capture_completed_missing'
Assert-True ($verify.Contains('evidence.get("package_verification") == "EXTERNAL_VERIFIER_REQUIRED"')) 'mac_verifier_external_verification_required_missing'
Assert-True ($verify.Contains('evidence.get("private_source_archive_verification") == "PASS"')) 'mac_verifier_source_archive_evidence_missing'
Assert-True ($verify.Contains('evidence.get("runtime_sanitization") == "PASS"')) 'mac_verifier_runtime_sanitization_evidence_missing'
Assert-True ($verify.Contains('evidence.get("git_head_public_boundary") == "PASS"')) 'mac_verifier_git_boundary_evidence_missing'
Assert-True ($verify.Contains('stat.S_ISREG(mode) or stat.S_ISDIR(mode)')) 'mac_verifier_special_file_rejection_missing'
Assert-True ($verify.Contains('datetime.fromisoformat')) 'mac_verifier_created_at_validation_missing'
Assert-True ($doc.Contains('OWNER_SYNTHETIC_ISOLATION=DIFFERENT_DATABASES_VOLUMES_COMPOSE_PROJECTS')) 'mac_owner_synthetic_isolation_missing'
Assert-True ($preflight.Contains('payloads/source/immich-upstream.lock.json') -and -not $preflight.Contains('package_root/metadata/')) 'mac_preflight_lock_boundary_mismatch'

Write-Output "MAC_PRIVATE_HANDOFF_PROTOCOL=PASS assertions=$assertions private_data_read=NO docker_used=NO"
