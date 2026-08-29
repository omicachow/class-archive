[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('capture', 'compare', 'verify-source')]
    [string]$Action,

    # There is intentionally no staging selector.  The private-full staging
    # stack and owner stack share durable history, and this baseline is only
    # meaningful for the owner listener pair 8190/8191.
    [Parameter(Mandatory = $true)]
    [ValidateSet('owner')]
    [string]$Endpoint,

    [Parameter(Mandatory = $true)]
    [ValidateSet(16, 18)]
    [int]$ExpectedSchema,

    # Capture writes a numeric-only, ignored report.  Compare reads this exact
    # report and emits no database values other than PASS/FAIL protocol fields.
    [string]$OutputPath,
    # A leaf name is deliberately used instead of a host path. The adapter
    # never needs to receive or print the private report directory.
    [string]$BaselineName,

    # Compare is bound to the exact ignored baseline file that Snapshot
    # produced.  A name alone is not an integrity boundary: the caller must
    # also supply the SHA-256 emitted during capture.
    [string]$ExpectedSha256
)

# A narrow count baseline for the forward-only owner V16 -> V18 migration.
# It deliberately records no filenames, paths, photo IDs, account IDs,
# comments, secrets, or image metadata.  It is not a backup and never mounts
# or reads media. `capture` runs only after the maintenance gate is active and
# Piwigo's writer is stopped; the read-only `verify-source` and `compare`
# actions are also used before a later lock to reject drift rather than masking
# it with a migration attempt.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-full\migration-v16-to-v18'
$piwigoEnv = 'infra/private-full/.env.piwigo.owner'
$immichEnv = 'infra/private-full/.env.immich.owner'
$piwigoProject = 'class_archive_private_full_v3_piwigo'
$immichProject = 'class_archive_private_full_v3_immich'
$immichEnvWindows = Join-Path $projectRoot $immichEnv.Replace('/', '\')
$immichCompose = $null
$boundedNativeHelper = Join-Path $PSScriptRoot 'class-archive-bounded-native-process.ps1'

function Stop-V16ToV18Baseline([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_V16_TO_V18_BASELINE_STOP:' + $Code)
}
if (-not (Test-Path -LiteralPath $boundedNativeHelper -PathType Leaf)) { Stop-V16ToV18Baseline 'bounded_native_helper_missing' }
. $boundedNativeHelper

function Get-FileSha256([string]$Path) {
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            $algorithm = [Security.Cryptography.SHA256]::Create()
            try { $bytes = $algorithm.ComputeHash($stream) }
            finally { $algorithm.Dispose() }
        }
        finally { $stream.Dispose() }
        $hash = [BitConverter]::ToString($bytes).Replace('-','')
    }
    catch {
        Stop-V16ToV18Baseline 'file_hash_runtime_failed'
    }
    if ([string]$hash -notmatch '^[a-fA-F0-9]{64}$') { Stop-V16ToV18Baseline 'file_hash_result_invalid' }
    return ([string]$hash).ToLowerInvariant()
}

function Invoke-WslCapture([string[]]$Arguments, [string]$Code, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    try {
        $boundedArgs = Add-ClassArchiveWslTimeout -Arguments $Arguments -TimeoutSeconds $TimeoutSeconds
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $boundedArgs -TimeoutSeconds ($TimeoutSeconds + 15) -WorkingDirectory $projectRoot
    }
    catch { Stop-V16ToV18Baseline ($Code + '_start_failed') }
    if ($result.TimedOut) { Stop-V16ToV18Baseline ($Code + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-V16ToV18Baseline $Code }
    return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Get-WslPath([string]$WindowsPath) {
    $full = [IO.Path]::GetFullPath($WindowsPath)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V16ToV18Baseline 'immich_env_missing' }
    if ($full -match '[\s\"]' -or $full.Contains("`0")) { Stop-V16ToV18Baseline 'immich_env_path_invalid' }
    try {
        $boundedArgs = Add-ClassArchiveWslTimeout -Arguments @('-d','Ubuntu','--exec','wslpath','-a',$full) -TimeoutSeconds 15
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $boundedArgs -TimeoutSeconds 30 -WorkingDirectory $projectRoot
    }
    catch { Stop-V16ToV18Baseline 'immich_env_path_invalid' }
    if ($result.TimedOut) { Stop-V16ToV18Baseline 'immich_env_path_timeout' }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace([string]$result.Stderr)) { Stop-V16ToV18Baseline 'immich_env_path_invalid' }
    $lines = @(([string]$result.Stdout -split "`r?`n") | Where-Object { $_ -ne '' })
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^/mnt/[a-z]/') { Stop-V16ToV18Baseline 'immich_env_path_invalid' }
    return [string]$lines[0]
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { Stop-V16ToV18Baseline 'output_path_outside_checkout' }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-DockerDesktopEnginePipe {
    # This helper is only for the Windows/WSL Owner runtime. Avoid spawning a
    # potentially hanging WSL Docker client when Desktop's Linux engine pipe
    # is visibly absent; callers receive the normal fail-closed protocol code.
    $pipes = @('\\.\pipe\dockerDesktopLinuxEngine','\\.\pipe\docker_engine')
    if (@($pipes | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) {
        Stop-V16ToV18Baseline 'docker_engine_pipe_unavailable'
    }
}

function Assert-IgnoredPrivateDirectory([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $privateFull = [IO.Path]::GetFullPath($privateRoot).TrimEnd('\')
    $expectedPrefix = $privateFull + '\'
    if (-not [string]::Equals($full.TrimEnd('\'), $privateFull, [StringComparison]::OrdinalIgnoreCase) -and
        -not $full.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) { Stop-V16ToV18Baseline 'output_path_outside_private_root' }
    New-Item -ItemType Directory -Path $full -Force | Out-Null
    $item = Get-Item -LiteralPath $full -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V16ToV18Baseline 'output_directory_untrusted' }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V16ToV18Baseline 'output_directory_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V16ToV18Baseline 'output_directory_tracked' }
    return $item.FullName
}

function Assert-PlainIgnoredBaseline([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $parent = Split-Path -Parent $full
    [void](Assert-IgnoredPrivateDirectory $parent)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V16ToV18Baseline 'baseline_missing' }
    $item = Get-Item -LiteralPath $full -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V16ToV18Baseline 'baseline_path_untrusted' }
    return $item.FullName
}

$piwigoCompose = @(
    '-d','Ubuntu','--cd',$projectRoot,'--',
    'docker','compose','--env-file',$piwigoEnv,
    '-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml',
    '-p',$piwigoProject
)
function Initialize-ImmichCompose {
    # Resolve the WSL path lazily so runtime loss is reported through the
    # structured fail-closed result rather than while the script is loading.
    if ($null -ne $script:immichCompose) { return }
    $immichEnvWsl = Get-WslPath $script:immichEnvWindows
    $script:immichCompose = @(
        '-d','Ubuntu','--cd',$projectRoot,'--',
        'env',('IMMICH_SPIKE_ENV_FILE=' + $immichEnvWsl),
        'docker','compose','--env-file',$immichEnv,
        '-f','infra/immich-spike/docker-compose.yml','-f','infra/private-full/docker-compose.immich.override.yml',
        '-p',$immichProject,
        '--profile','immich-spike','--profile','immich-ml','--profile','immich-web-compat','--profile','immich-gateway-integration'
    )
}

function Invoke-PiwigoComposeCapture([string[]]$Arguments, [string]$Code, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    return Invoke-WslCapture @($script:piwigoCompose + $Arguments) $Code -TimeoutSeconds $TimeoutSeconds
}

function Invoke-ImmichComposeCapture([string[]]$Arguments, [string]$Code, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    Initialize-ImmichCompose
    return Invoke-WslCapture @($script:immichCompose + $Arguments) $Code -TimeoutSeconds $TimeoutSeconds
}

function ConvertTo-StrictCounts([string[]]$Lines, [string[]]$ExpectedKeys, [string]$Code) {
    $counts = [ordered]@{}
    foreach ($line in $Lines) {
        if ($line -notmatch '\A([a-z_]+)=([0-9]+)\z') { Stop-V16ToV18Baseline $Code }
        $key = [string]$Matches[1]
        if ($counts.Contains($key)) { Stop-V16ToV18Baseline $Code }
        $counts[$key] = [uint64]$Matches[2]
    }
    if ($counts.Count -ne $ExpectedKeys.Count) { Stop-V16ToV18Baseline $Code }
    foreach ($key in $ExpectedKeys) {
        if (-not $counts.Contains($key)) { Stop-V16ToV18Baseline $Code }
    }
    return $counts
}

function ConvertTo-StrictFingerprints([string[]]$Lines, [string[]]$ExpectedKeys, [string]$Code) {
    $fingerprints = [ordered]@{}
    foreach ($line in $Lines) {
        if ($line -notmatch '\A([a-z_]+)=([a-f0-9]{64})\z') { Stop-V16ToV18Baseline $Code }
        $key = [string]$Matches[1]
        if ($fingerprints.Contains($key)) { Stop-V16ToV18Baseline $Code }
        $fingerprints[$key] = [string]$Matches[2]
    }
    if ($fingerprints.Count -ne $ExpectedKeys.Count) { Stop-V16ToV18Baseline $Code }
    foreach ($key in $ExpectedKeys) {
        if (-not $fingerprints.Contains($key)) { Stop-V16ToV18Baseline $Code }
    }
    return $fingerprints
}

function Get-OwnerV16ToV18Counts([int]$Schema) {
    # This script is intentionally read-only.  The only SQL statements are
    # SELECT/COUNT against known current schema tables.  Do not add a media
    # volume, external source path, or DML here.
    Assert-DockerDesktopEnginePipe
    $mariaSql = @'
set -eu
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
cleanup_secret() { unset MYSQL_PWD || true; }
trap cleanup_secret EXIT HUP INT TERM
q() { mariadb --batch --skip-column-names --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ] || exit 92
  # A MAX(version) probe alone accepts a malformed or gapped ledger. Require
  # exactly one contiguous row for every version before trusting either V16 or
  # V18 as a migration boundary.
  ledger_shape=$(q "SELECT CONCAT(COUNT(*),':',COUNT(DISTINCT version),':',COALESCE(MIN(version),0),':',COALESCE(MAX(version),0)) FROM ${base}migration;")
  case "$ledger_shape" in
    16:16:1:16) schema_version=16 ;;
    18:18:1:18) schema_version=18 ;;
    *) exit 93 ;;
  esac
table_count() { q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$1';"; }
required() { [ "$(table_count "$1")" = 1 ] || exit 94; }
  for suffix in photo_source photo_source_presentation photo photo_comment person person_merge person_photo_rule album spotlight auto_collection audit_event ai_asset_index ai_index_job; do required "${base}${suffix}"; done
  for suffix in users user_access user_group user_infos groups; do required "${pwg}${suffix}"; done
if [ "$schema_version" = 16 ]; then
  # A dedicated V16 source proof must not silently accept a partially applied
  # target schema. These target-only structures belong to later migrations.
  for suffix in collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state spotlight_rotation_state; do
    [ "$(table_count "${base}${suffix}")" = 0 ] || exit 95
  done
else
  for suffix in collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state spotlight_rotation_state; do required "${base}${suffix}"; done
  rotation_rows=$(q "SELECT COUNT(*) FROM ${base}spotlight_rotation_state;")
  case "$rotation_rows" in 0|1|2) ;; *) exit 96 ;; esac
  rotation_scopes=$(q "SELECT COUNT(DISTINCT scope) FROM ${base}spotlight_rotation_state;")
  [ "$rotation_scopes" = "$rotation_rows" ] || exit 97
  rotation_invalid=$(q "SELECT COUNT(*) FROM ${base}spotlight_rotation_state WHERE scope NOT IN ('FULL','HERITAGE') OR OCTET_LENGTH(candidate_digest) <> 32 OR OCTET_LENGTH(revision) <> 32 OR next_rotation_at IS NULL OR (last_rotated_at IS NOT NULL AND next_rotation_at <= last_rotated_at);")
  [ "$rotation_invalid" = 0 ] || exit 98
fi
printf 'class_identity_schema_version=%s\n' "$schema_version"
printf 'migration_ledger_rows=%s\n' "$(q "SELECT COUNT(*) FROM ${base}migration;")"
printf 'source_records=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source;")"
printf 'source_presentations=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source_presentation;")"
printf 'canonical_photos=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo;")"
printf 'piwigo_images=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}images;")"
printf 'album_relationships=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}image_category;")"
printf 'leaf_albums=%s\n' "$(q "SELECT COUNT(*) FROM ${base}album a WHERE a.state='ACTIVE' AND EXISTS (SELECT 1 FROM ${pwg}image_category ic WHERE ic.category_id=a.piwigo_category_id);")"
printf 'comments=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment;")"
printf 'replies=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE parent_comment_id IS NOT NULL;")"
printf 'visible_people=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person WHERE state='ACTIVE' AND visibility='VISIBLE';")"
printf 'person_merges=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_merge;")"
printf 'person_rules=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_photo_rule;")"
printf 'spotlights=%s\n' "$(q "SELECT COUNT(*) FROM ${base}spotlight;")"
printf 'memories=%s\n' "$(q "SELECT COUNT(*) FROM ${base}auto_collection;")"
printf 'audit_events=%s\n' "$(q "SELECT COUNT(*) FROM ${base}audit_event;")"
printf 'ai_asset_index=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_asset_index;")"
printf 'ai_jobs_total=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job;")"
printf 'ai_jobs_complete=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='COMPLETE';")"
printf 'ai_jobs_open=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state IN ('PENDING','RUNNING','UNAVAILABLE');")"
'@
    $mariaKeys = @(
        'class_identity_schema_version','migration_ledger_rows','source_records','source_presentations','canonical_photos','piwigo_images','album_relationships','leaf_albums',
        'comments','replies','visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index',
        'ai_jobs_total','ai_jobs_complete','ai_jobs_open'
    )
    $maria = ConvertTo-StrictCounts (Invoke-PiwigoComposeCapture @('exec','-T','db','sh','-eu','-c',$mariaSql) 'mariadb_count_query_failed' 90) $mariaKeys 'mariadb_count_output_invalid'
    if ([int]$maria.class_identity_schema_version -ne $Schema -or [int]$maria.migration_ledger_rows -ne $Schema) { Stop-V16ToV18Baseline 'schema_not_expected' }

    $pgSql = @'
SELECT 'immich_assets='||COUNT(*) FROM asset
UNION ALL SELECT 'immich_face_records='||COUNT(*) FROM asset_face
UNION ALL SELECT 'immich_raw_persons='||COUNT(*) FROM person
UNION ALL SELECT 'immich_face_search='||COUNT(*) FROM face_search
UNION ALL SELECT 'immich_search_index='||COUNT(*) FROM smart_search;
'@
    $pgKeys = @('immich_assets','immich_face_records','immich_raw_persons','immich_face_search','immich_search_index')
    $postgres = ConvertTo-StrictCounts (Invoke-ImmichComposeCapture @('exec','-T','--user','postgres','database','psql','--no-psqlrc','--tuples-only','--no-align','--set','ON_ERROR_STOP=1','--dbname=immich','--command',$pgSql) 'immich_count_query_failed' 90) $pgKeys 'immich_count_output_invalid'

    $all = [ordered]@{}
    foreach ($entry in $maria.GetEnumerator()) { $all[[string]$entry.Key] = [uint64]$entry.Value }
    foreach ($entry in $postgres.GetEnumerator()) { $all[[string]$entry.Key] = [uint64]$entry.Value }
    return $all
}

function Get-OwnerV16ToV18SemanticFingerprints([int]$Schema) {
    # Each result is an opaque SHA-256 of a deterministic, ordered query
    # stream. The transient query rows remain inside the MariaDB container; no
    # filename, source path, comment body, account identifier, or media value
    # crosses into this PowerShell process. These fingerprints complement (not
    # replace) aggregate counts and catch equal-count corruption of the state
    # V18 must preserve: media mappings, album aliases/membership, comments,
    # person curation, collections, audit, and AI control state.
    Assert-DockerDesktopEnginePipe
    $semanticSql = @'
set -eu
umask 077
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
  # Keep the client's deterministic escaping enabled. Raw TSV cannot
  # distinguish embedded tabs/newlines in a comment body from row boundaries.
  q_to_file() { mariadb --batch --skip-column-names --binary-as-hex --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1" > "$2"; }
q() { mariadb --batch --skip-column-names --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ] || exit 92
  # Fingerprints must be bound to a contiguous exact ledger too; otherwise a
  # malformed source could have the same maximum version as a reviewed state.
  ledger_shape=$(q "SELECT CONCAT(COUNT(*),':',COUNT(DISTINCT version),':',COALESCE(MIN(version),0),':',COALESCE(MAX(version),0)) FROM ${base}migration;")
  case "$ledger_shape" in
    16:16:1:16) schema_version=16 ;;
    18:18:1:18) schema_version=18 ;;
    *) exit 93 ;;
  esac
for suffix in identity seat account principal token submission archive_image photo photo_source photo_source_presentation photo_duplicate person person_merge person_photo_rule album spotlight auto_collection auto_collection_photo photo_comment audit_event ai_asset_index ai_index_job native_source_epoch batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item; do
  present=$(q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${base}${suffix}';")
  [ "$present" = 1 ] || exit 94
done
tmp=$(mktemp) || exit 95
cleanup() { rm -f -- "$tmp"; unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM
fingerprint() {
  name="$1"
  sql="$2"
  : > "$tmp"
  q_to_file "$sql" "$tmp"
  digest=$(sha256sum "$tmp" | awk '{print $1}')
  case "$digest" in [a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9]) ;; *) exit 96 ;; esac
  printf '%s=%s\n' "$name" "$digest"
}
fingerprint canonical_media "SELECT 'submission'; SELECT * FROM ${base}submission ORDER BY id; SELECT 'photo'; SELECT * FROM ${base}photo ORDER BY class_photo_id; SELECT 'photo_source'; SELECT * FROM ${base}photo_source ORDER BY id; SELECT 'photo_source_presentation'; SELECT * FROM ${base}photo_source_presentation ORDER BY photo_source_id; SELECT 'photo_duplicate'; SELECT * FROM ${base}photo_duplicate ORDER BY duplicate_id; SELECT 'private_library_collection'; SELECT * FROM ${base}private_library_collection ORDER BY source_collection_id; SELECT 'private_library_folder'; SELECT * FROM ${base}private_library_folder ORDER BY folder_id; SELECT 'private_library_import'; SELECT * FROM ${base}private_library_import ORDER BY import_id; SELECT 'private_library_import_item'; SELECT * FROM ${base}private_library_import_item ORDER BY import_id,item_digest; SELECT 'piwigo_images'; SELECT * FROM ${pwg}images ORDER BY id;"
fingerprint album_membership "SELECT 'archive_image'; SELECT * FROM ${base}archive_image ORDER BY id; SELECT 'album'; SELECT * FROM ${base}album ORDER BY class_album_id; SELECT 'batch_operation'; SELECT * FROM ${base}batch_operation ORDER BY batch_id; SELECT 'batch_operation_item'; SELECT * FROM ${base}batch_operation_item ORDER BY batch_id,id; SELECT 'categories'; SELECT * FROM ${pwg}categories ORDER BY id; SELECT 'image_category'; SELECT * FROM ${pwg}image_category ORDER BY image_id,category_id;"
fingerprint comments "SELECT * FROM ${base}photo_comment ORDER BY comment_id;"
fingerprint person_curation "SELECT 'person'; SELECT * FROM ${base}person ORDER BY class_person_id; SELECT 'person_merge'; SELECT * FROM ${base}person_merge ORDER BY merge_id; SELECT 'person_photo_rule'; SELECT * FROM ${base}person_photo_rule ORDER BY class_person_id,class_photo_id;"
fingerprint spotlight_collections "SELECT 'spotlight'; SELECT * FROM ${base}spotlight ORDER BY spotlight_id; SELECT 'auto_collection'; SELECT * FROM ${base}auto_collection ORDER BY auto_collection_id; SELECT 'auto_collection_photo'; SELECT * FROM ${base}auto_collection_photo ORDER BY auto_collection_id,ordinal,class_photo_id;"
fingerprint ai_control "SELECT 'ai_asset_index'; SELECT * FROM ${base}ai_asset_index ORDER BY class_photo_id; SELECT 'ai_index_job'; SELECT * FROM ${base}ai_index_job ORDER BY job_id; SELECT 'native_source_epoch'; SELECT * FROM ${base}native_source_epoch ORDER BY source_key;"
  fingerprint identity_and_audit "SELECT 'identity'; SELECT * FROM ${base}identity ORDER BY id; SELECT 'seat'; SELECT * FROM ${base}seat ORDER BY id; SELECT 'account'; SELECT * FROM ${base}account ORDER BY id; SELECT 'principal'; SELECT * FROM ${base}principal ORDER BY id; SELECT 'token'; SELECT * FROM ${base}token ORDER BY id; SELECT 'audit_event'; SELECT * FROM ${base}audit_event ORDER BY id; SELECT 'pwg_users'; SELECT * FROM ${pwg}users ORDER BY id; SELECT 'pwg_user_access'; SELECT * FROM ${pwg}user_access ORDER BY user_id,cat_id; SELECT 'pwg_user_group'; SELECT * FROM ${pwg}user_group ORDER BY user_id,group_id; SELECT 'pwg_user_infos'; SELECT * FROM ${pwg}user_infos ORDER BY user_id; SELECT 'pwg_groups'; SELECT * FROM ${pwg}groups ORDER BY id;"
'@
    $mariaKeys = @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','identity_and_audit')
    $lines = Invoke-PiwigoComposeCapture @('exec','-T','db','sh','-eu','-c',$semanticSql) 'mariadb_semantic_fingerprint_failed' 300
    $mariaFingerprints = ConvertTo-StrictFingerprints $lines $mariaKeys 'mariadb_semantic_fingerprint_output_invalid'
    # The immutable index state lives in Immich Postgres. Compute a single
    # ordered, opaque digest inside that container so no face embedding, asset
    # path, person mapping, or search vector reaches PowerShell.
    $postgresFingerprintSql = @'
set -eu
fingerprint_sql="SELECT 'asset'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM asset) AS t ORDER BY row; SELECT 'asset_face'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM asset_face) AS t ORDER BY row; SELECT 'face_search'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM face_search) AS t ORDER BY row; SELECT 'person'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM person) AS t ORDER BY row; SELECT 'smart_search'; SELECT row_to_json(t)::text AS row FROM (SELECT * FROM smart_search) AS t ORDER BY row;"
tmp=$(mktemp) || exit 97
cleanup() { rm -f -- "$tmp"; }
trap cleanup EXIT HUP INT TERM
# Do not pipe psql directly into sha256sum: without a portable pipefail this
# would turn a SQL failure into a digest of an error stream. A temporary file
# keeps the failure exit status authoritative and is removed by the trap.
psql --no-psqlrc --tuples-only --no-align --set ON_ERROR_STOP=1 --dbname=immich --command "$fingerprint_sql" > "$tmp"
digest=$(sha256sum "$tmp" | awk '{print $1}')
case "$digest" in ''|*[!a-f0-9]*) exit 97 ;; esac
[ "${#digest}" -eq 64 ] || exit 97
printf 'immich_ai_state=%s\n' "$digest"
'@
    $postgresFingerprint = ConvertTo-StrictFingerprints (Invoke-ImmichComposeCapture @('exec','-T','--user','postgres','database','sh','-eu','-c',$postgresFingerprintSql) 'immich_semantic_fingerprint_failed' 300) @('immich_ai_state') 'immich_semantic_fingerprint_output_invalid'
    $fingerprints = [ordered]@{}
    foreach ($entry in $mariaFingerprints.GetEnumerator()) { $fingerprints[[string]$entry.Key] = [string]$entry.Value }
    $fingerprints['immich_ai_state'] = [string]$postgresFingerprint['immich_ai_state']
    if ($Schema -notin @(16,18)) { Stop-V16ToV18Baseline 'semantic_schema_invalid' }
    return $fingerprints
}

function Read-Baseline([string]$Name, [string]$ExpectedSha256) {
    if ($Name -notmatch '^owner-v16-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-V16ToV18Baseline 'baseline_name_invalid' }
    $safePath = Assert-PlainIgnoredBaseline (Join-Path $privateRoot $Name)
    if ($ExpectedSha256 -notmatch '^[a-f0-9]{64}$') { Stop-V16ToV18Baseline 'baseline_sha256_invalid' }
    $actualSha256 = Get-FileSha256 $safePath
    if (-not [string]::Equals($actualSha256, $ExpectedSha256, [StringComparison]::Ordinal)) { Stop-V16ToV18Baseline 'baseline_sha256_mismatch' }
    try { $document = Get-Content -LiteralPath $safePath -Raw | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-V16ToV18Baseline 'baseline_json_invalid' }
    if ([int]$document.format -ne 2 -or [string]$document.scope -ne 'OWNER_V16_TO_V18_NUMERIC_BASELINE' -or
        [string]$document.privacy -ne 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS' -or
        [int]$document.source_schema -ne 16 -or [int]$document.target_schema -ne 18 -or $null -eq $document.counts -or $null -eq $document.semantic_fingerprints) {
        Stop-V16ToV18Baseline 'baseline_contract_invalid'
    }
    $counts = [ordered]@{}
    foreach ($property in $document.counts.PSObject.Properties) {
        if ($property.Name -notmatch '^[a-z_]+$' -or ([string]$property.Value) -notmatch '^[0-9]+$') { Stop-V16ToV18Baseline 'baseline_count_invalid' }
        $counts[$property.Name] = [uint64]$property.Value
    }
    $expectedCounts = @(
        'class_identity_schema_version','migration_ledger_rows','source_records','source_presentations','canonical_photos','piwigo_images','album_relationships','leaf_albums',
        'comments','replies','visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index',
        'ai_jobs_total','ai_jobs_complete','ai_jobs_open','immich_assets','immich_face_records','immich_raw_persons','immich_face_search','immich_search_index'
    )
    if ($counts.Count -ne $expectedCounts.Count -or @($expectedCounts | Where-Object { -not $counts.Contains($_) }).Count -ne 0) { Stop-V16ToV18Baseline 'baseline_count_set_invalid' }
    $semantic = [ordered]@{}
    foreach ($property in $document.semantic_fingerprints.PSObject.Properties) {
        if ($property.Name -notmatch '^[a-z_]+$' -or ([string]$property.Value) -notmatch '^[a-f0-9]{64}$') { Stop-V16ToV18Baseline 'baseline_semantic_fingerprint_invalid' }
        $semantic[$property.Name] = [string]$property.Value
    }
    $expectedSemantic = @('canonical_media','album_membership','comments','person_curation','spotlight_collections','ai_control','identity_and_audit','immich_ai_state')
    if ($semantic.Count -ne $expectedSemantic.Count -or @($expectedSemantic | Where-Object { -not $semantic.Contains($_) }).Count -ne 0) { Stop-V16ToV18Baseline 'baseline_semantic_set_invalid' }
    return @{ Path = $safePath; Sha256 = $actualSha256; Document = $document; Counts = $counts; Semantic = $semantic }
}

function Write-Baseline([string]$Path, [hashtable]$Counts, [hashtable]$Semantic) {
    $directory = Assert-IgnoredPrivateDirectory (Split-Path -Parent $Path)
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($directory.TrimEnd('\') + '\', [StringComparison]::OrdinalIgnoreCase)) { Stop-V16ToV18Baseline 'baseline_path_outside_private_root' }
    if (Test-Path -LiteralPath $full) { Stop-V16ToV18Baseline 'baseline_already_exists' }
    $record = [ordered]@{
        format = 2
        scope = 'OWNER_V16_TO_V18_NUMERIC_BASELINE'
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        source_schema = 16
        target_schema = 18
        privacy = 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_OR_SECRETS'
        counts = $Counts
        semantic_fingerprints = $Semantic
    }
    $json = $record | ConvertTo-Json -Depth 5
    [IO.File]::WriteAllText($full, $json + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    $item = Get-Item -LiteralPath $full -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V16ToV18Baseline 'baseline_write_untrusted' }
    return (Get-FileSha256 $full)
}

function Assert-SourceBaselineMatches([hashtable]$Baseline) {
    $actual = Get-OwnerV16ToV18Counts 16
    foreach ($key in $Baseline.Counts.Keys) {
        if (-not $actual.Contains($key) -or [uint64]$actual[$key] -ne [uint64]$Baseline.Counts[$key]) {
            Stop-V16ToV18Baseline ('source_baseline_count_mismatch_' + $key)
        }
    }
    $actualSemantic = Get-OwnerV16ToV18SemanticFingerprints 16
    foreach ($key in $Baseline.Semantic.Keys) {
        if (-not $actualSemantic.Contains($key) -or -not [string]::Equals([string]$actualSemantic[$key], [string]$Baseline.Semantic[$key], [StringComparison]::Ordinal)) {
            Stop-V16ToV18Baseline ('source_baseline_semantic_mismatch_' + $key)
        }
    }
}

try {
    if ($Endpoint -ne 'owner') { Stop-V16ToV18Baseline 'owner_endpoint_required' }
    if ($Action -eq 'capture') {
        if ($ExpectedSchema -ne 16) { Stop-V16ToV18Baseline 'capture_requires_source_schema_v16' }
        if ([string]::IsNullOrWhiteSpace($OutputPath)) {
            $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
            $OutputPath = Join-Path $privateRoot ('owner-v16-to-v18-baseline-' + $stamp + '.json')
        }
        $counts = Get-OwnerV16ToV18Counts 16
        if ([uint64]$counts['ai_jobs_open'] -ne 0) { Stop-V16ToV18Baseline 'capture_requires_no_open_ai_jobs' }
        $semantic = Get-OwnerV16ToV18SemanticFingerprints 16
        $sha256 = Write-Baseline $OutputPath $counts $semantic
        Write-Output ('PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=capture endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=' + (Split-Path -Leaf $OutputPath) + ' sha256=' + $sha256 + ' privacy=COUNTS_AND_OPAQUE_HASHES_ONLY media=NOT_MOUNTED')
        exit 0
    }

    if ($Action -eq 'verify-source') {
        if ([string]::IsNullOrWhiteSpace($BaselineName) -or $ExpectedSchema -ne 16 -or [string]::IsNullOrWhiteSpace($ExpectedSha256)) { Stop-V16ToV18Baseline 'verify_source_requires_v16_baseline' }
        $baseline = Read-Baseline $BaselineName $ExpectedSha256
        Assert-SourceBaselineMatches $baseline
        Write-Output ('PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=verify-source endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=' + (Split-Path -Leaf $baseline.Path) + ' sha256=' + $baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED media=NOT_MOUNTED')
        exit 0
    }

    if ([string]::IsNullOrWhiteSpace($BaselineName) -or $ExpectedSchema -ne 18 -or [string]::IsNullOrWhiteSpace($ExpectedSha256)) { Stop-V16ToV18Baseline 'compare_requires_target_schema_v18' }
    $baseline = Read-Baseline $BaselineName $ExpectedSha256
    $actual = Get-OwnerV16ToV18Counts 18
    $requiredKeys = @($baseline.Counts.Keys)
    foreach ($key in $requiredKeys) {
        if (-not $actual.Contains($key)) { Stop-V16ToV18Baseline 'post_migration_count_key_missing' }
        # The source ledger changes by exactly two additive versions. New
        # target projections are structurally validated in the SQL probe but
        # intentionally do not alter this V16 source baseline's key set.
        if ($key -eq 'class_identity_schema_version' -or $key -eq 'migration_ledger_rows') {
            if ([uint64]$actual[$key] -ne 18) { Stop-V16ToV18Baseline 'post_migration_schema_mismatch' }
        }
        elseif ([uint64]$actual[$key] -ne [uint64]$baseline.Counts[$key]) {
            Stop-V16ToV18Baseline ('post_migration_count_mismatch_' + $key)
        }
    }
    $actualSemantic = Get-OwnerV16ToV18SemanticFingerprints 18
    foreach ($key in $baseline.Semantic.Keys) {
        if (-not $actualSemantic.Contains($key) -or -not [string]::Equals([string]$actualSemantic[$key], [string]$baseline.Semantic[$key], [StringComparison]::Ordinal)) {
            Stop-V16ToV18Baseline ('post_migration_semantic_mismatch_' + $key)
        }
    }
    Write-Output ('PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=compare endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=' + (Split-Path -Leaf $baseline.Path) + ' sha256=' + $baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED v17_v18_expansion=STRUCTURALLY_VALID rotation=IDLE_OR_OPERATIONAL media=NOT_MOUNTED')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^PRIVATE_V16_TO_V18_BASELINE_STOP:([a-z0-9_]{1,96})$') { $Matches[1] } else { 'private_v16_to_v18_baseline_failed' }
    Write-Output "PRIVATE_V16_TO_V18_NUMERIC_BASELINE=FAIL action=$Action endpoint=$Endpoint code=$code"
    exit 2
}
