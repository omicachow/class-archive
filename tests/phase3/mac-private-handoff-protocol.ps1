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
$docPath = Join-Path $handoffRoot 'HANDOFF-MAC-PRIVATE.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

foreach ($path in @($preflightPath,$verifyPath,$archiveVerifyPath,$capturePath,$docPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('mac_handoff_file_missing_' + [IO.Path]::GetFileName($path))
}

$preflight = [IO.File]::ReadAllText($preflightPath)
$verify = [IO.File]::ReadAllText($verifyPath)
$archiveVerify = [IO.File]::ReadAllText($archiveVerifyPath)
$capture = [IO.File]::ReadAllText($capturePath)
$doc = [IO.File]::ReadAllText($docPath)

foreach ($needle in @(
    'Darwin','classarchive-mac-restore','docker compose version','fresh_project_has_no_docker_objects',
    'container_arch_gate_amd64_lock_on_apple_silicon_requires_isolated_runtime_proof',
    "node_version_24.15.0","pnpm_version_11.13.1",'google_chrome_stable_present',
    'PACKAGE_VERIFIED=','MAC_RUNTIME_TESTED=NO','MAC_PREFLIGHT=PASS_STATIC_ONLY',
    'command_name in git docker gpg python3 tar gzip zstd shasum'
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
    'member.isfile()','member.isdir()'
)) { Assert-True ($verify.Contains($needle)) ('mac_v2_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'EXPECTED_SHA256','outer_sha256_mismatch','archive_member_boundary_invalid','member.isfile() or member.isdir()',
    'len(roots) == 1','--no-same-owner','HANDOFF_ARCHIVE_VERIFY=PASS',
    'unicodedata.normalize("NFC", canonical).casefold()','portable_types.get(parent) != "file"',
    'outer_sha256_changed_during_verification'
)) { Assert-True ($archiveVerify.Contains($needle)) ('mac_archive_verifier_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

foreach ($needle in @(
    'package_root_outside_approved_m_staging','mariadb_sanitization_incomplete',
    'piwigo_sessions','piwigo_user_auth_keys','activation_key=NULL','param=''secret_key''',
    '--exclude-table-data=public.session','--exclude-table-data=public.api_key',
    '--exclude-table-data=public.shared_link','--exclude-table-data=public.system_metadata',
    'owner-immich-canonical.tar','./library ./upload ./profile','./thumbs ./encoded-video',
    'immich_upload_root_shape_unknown','--exclude=./local/config/database.inc.php',
    '--exclude=./_data/.class-archive-immich-bridge.json','runtime_secrets_included":false',
    'org.classarchive.disposable=handoff-sanitizer'
)) { Assert-True ($capture.Contains($needle)) ('mac_capture_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+','_').Trim('_')) }

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

foreach ($text in @($preflight,$verify,$archiveVerify,$capture,$doc)) {
    Assert-True (-not ($text -match '(?im)^\s*(?:docker\s+system\s+prune|docker\s+volume\s+prune|docker\s+compose\s+down\s+-v|rm\s+-rf\s+/)')) 'mac_handoff_destructive_command_detected'
    Assert-True (-not $text.Contains('PRIVATE_SOURCE_ROOT_LITERAL')) 'mac_handoff_private_source_path_detected'
    Assert-True (-not ($text -match '(?i)(?:password|passphrase|token|secret)\s*=\s*["''](?!\$)[^"'']+["'']')) 'mac_handoff_literal_secret_detected'
}

Assert-True ($preflight.Contains('docker ps -aq --filter') -and $preflight.Contains('docker volume ls -q --filter')) 'mac_preflight_fresh_volume_readonly_check_missing'
Assert-True (-not $preflight.Contains('docker volume rm')) 'mac_preflight_volume_mutation_detected'
Assert-True ($verify.Contains('checksummed_file_missing_or_not_regular') -and $verify.Contains('checksum_path_unsafe')) 'mac_verifier_path_boundary_missing'
Assert-True ($verify.Contains('evidence.get("package_verified") is True')) 'mac_verifier_package_verified_true_missing'
Assert-True ($verify.Contains('stat.S_ISREG(mode) or stat.S_ISDIR(mode)')) 'mac_verifier_special_file_rejection_missing'
Assert-True ($verify.Contains('datetime.fromisoformat')) 'mac_verifier_created_at_validation_missing'
Assert-True ($doc.Contains('OWNER_SYNTHETIC_ISOLATION=DIFFERENT_DATABASES_VOLUMES_COMPOSE_PROJECTS')) 'mac_owner_synthetic_isolation_missing'
Assert-True ($preflight.Contains('payloads/source/immich-upstream.lock.json') -and -not $preflight.Contains('package_root/metadata/')) 'mac_preflight_lock_boundary_mismatch'

Write-Output "MAC_PRIVATE_HANDOFF_PROTOCOL=PASS assertions=$assertions private_data_read=NO docker_used=NO"
