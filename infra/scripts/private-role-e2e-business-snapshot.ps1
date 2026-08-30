[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Capture', 'Compare')]
    [string]$Action,

    [Parameter(Mandatory = $true)]
    [ValidateSet('owner')]
    [string]$Endpoint,

    [ValidateSet('pre', 'post')]
    [string]$Phase = 'pre',

    # The marker is an opaque local test-run nonce. It is used only to bind
    # the two ignored bundles; the manifest stores a domain-separated digest,
    # and no PASS/FAIL record prints the marker.
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-f0-9]{24}$')]
    [string]$RunMarker,

    [ValidatePattern('^[a-f0-9]{64}$')]
    [string]$ExpectedPreManifestSha256,

    [ValidatePattern('^[a-f0-9]{64}$')]
    [string]$ExpectedPostManifestSha256,

    [switch]$ConfirmOwnerPrivateSnapshot
)

# Local-private DB-only rollback point for Phase 3.4.1.
#
# Capture is deliberately owner-only, localhost-bound, ignored, and explicit.
# The dump contains private database state, so it never leaves the restricted
# bundle. Aggregate counts and deterministic semantic SHA-256 values are safe
# to compare because the queried rows never leave the MariaDB container.
# Originals, derivatives, source directories, Immich/Postgres, and browser
# state are not mounted or read by this tool.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-role-e2e\business-snapshots'
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
$expectedSchema = 18
$piwigoEnv = 'infra/private-full/.env.piwigo.owner'
$piwigoProject = 'class_archive_private_full_v3_piwigo'
$privacyMarker = 'COUNTS_AND_OPAQUE_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_COMMENT_BODIES_OR_SECRETS'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

$countKeys = @(
    'class_identity_schema_version', 'migration_ledger_rows',
    'source_records', 'canonical_photos', 'piwigo_images',
    'albums', 'album_relationships',
    'comment_rows', 'active_comments', 'reply_rows', 'active_replies',
    'spotlights', 'memories', 'active_pins',
    'identities', 'seats', 'accounts', 'principals',
    'people_mappings', 'visible_people', 'person_merges', 'person_rules',
    'claims', 'invitations', 'submissions', 'audit_events',
    'ai_asset_index', 'ai_jobs_total', 'ai_jobs_open',
    'projection_epoch_rows'
)

$semanticKeys = @(
    'schema_ledger',
    'canonical_media',
    'album_membership',
    'comments',
    'identity_security',
    'submissions',
    'person_curation',
    'spotlight_memories_pins',
    'ai_projection_control',
    'audit_full',
    'audit_preexisting_prefix',
    'audit_high_water_opaque'
)

$stableSemanticKeys = @($semanticKeys | Where-Object {
    $_ -notin @('audit_full', 'audit_preexisting_prefix', 'audit_high_water_opaque')
})

function Stop-PrivateRoleSnapshot([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_ROLE_SNAPSHOT_STOP:' + $Code)
}

function Get-Sha256([string]$Path) {
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-RunMarkerDigest {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes("classarchive-private-role-e2e-business-snapshot-v1`0$RunMarker")
        return (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '')
    }
    finally { $sha.Dispose() }
}

function Assert-NoReparseAncestor([string]$Candidate, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $root = $projectRoot.TrimEnd('\', '/')
    $boundary = $root + [IO.Path]::DirectorySeparatorChar
    if (-not [string]::Equals($full.TrimEnd('\', '/'), $root, [StringComparison]::OrdinalIgnoreCase) -and
        -not $full.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateRoleSnapshot ($Code + '_outside_checkout')
    }
    $cursor = $full
    while ($true) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                Stop-PrivateRoleSnapshot ($Code + '_reparse')
            }
        }
        if ([string]::Equals($cursor.TrimEnd('\', '/'), $root, [StringComparison]::OrdinalIgnoreCase)) { return }
        $parent = [IO.Directory]::GetParent($cursor)
        if ($null -eq $parent) { Stop-PrivateRoleSnapshot ($Code + '_ancestor_invalid') }
        $cursor = $parent.FullName
    }
}

function Set-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        Stop-PrivateRoleSnapshot 'private_directory_untrusted'
    }
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    }
    catch {}
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) { Stop-PrivateRoleSnapshot 'private_directory_identity_unavailable' }
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    Set-Acl -LiteralPath $resolved -AclObject $acl
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function Assert-IgnoredPrivateRoot {
    Assert-NoReparseAncestor $privateRoot 'private_root'
    [IO.Directory]::CreateDirectory($privateRoot) | Out-Null
    Assert-NoReparseAncestor $privateRoot 'private_root'
    Set-OwnerOnlyDirectoryAcl $privateRoot
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $fullPrivateRoot = [IO.Path]::GetFullPath($privateRoot)
    if (-not $fullPrivateRoot.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateRoleSnapshot 'private_root_outside_checkout'
    }
    $relative = $fullPrivateRoot.Substring($projectBoundary.Length).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateRoleSnapshot 'private_root_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-PrivateRoleSnapshot 'private_root_tracked' }
}

function Get-BundlePath([string]$BundlePhase) {
    if ($BundlePhase -notin @('pre', 'post')) { Stop-PrivateRoleSnapshot 'bundle_phase_invalid' }
    $name = $BundlePhase + '-' + $RunMarker
    if ($name -notmatch '^(pre|post)-[a-f0-9]{24}$') { Stop-PrivateRoleSnapshot 'bundle_name_invalid' }
    $path = [IO.Path]::GetFullPath((Join-Path $privateRoot $name))
    $boundary = [IO.Path]::GetFullPath($privateRoot).TrimEnd('\') + '\'
    if (-not $path.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateRoleSnapshot 'bundle_outside_private_root'
    }
    return $path
}

function Assert-CleanCheckout {
    $lines = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 0) { Stop-PrivateRoleSnapshot 'checkout_not_clean' }
}

function Assert-SchemaSourceContract {
    if (-not (Test-Path -LiteralPath $schemaPath -PathType Leaf)) { Stop-PrivateRoleSnapshot 'schema_source_missing' }
    $source = [IO.File]::ReadAllText($schemaPath)
    $matches = [regex]::Matches($source, 'public\s+const\s+CURRENT_VERSION\s*=\s*([0-9]+)\s*;')
    if ($matches.Count -ne 1 -or [int]$matches[0].Groups[1].Value -ne $expectedSchema) {
        Stop-PrivateRoleSnapshot 'schema_source_not_current_v18'
    }
}

function Invoke-WslCapture([string[]]$Arguments, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-PrivateRoleSnapshot $Code }
    return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Get-WslPath([string]$WindowsPath) {
    $full = [IO.Path]::GetFullPath($WindowsPath)
    $lines = @(Invoke-WslCapture @('-d', 'Ubuntu', '--exec', 'wslpath', '-a', $full) 'wsl_path_invalid')
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^/mnt/[a-z]/') { Stop-PrivateRoleSnapshot 'wsl_path_invalid' }
    return [string]$lines[0]
}

function Get-PiwigoComposePrefix {
    return @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', $piwigoEnv,
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', $piwigoProject
    )
}

function Invoke-PiwigoComposeCapture([string[]]$Arguments, [string]$Code) {
    return Invoke-WslCapture @((Get-PiwigoComposePrefix) + $Arguments) $Code
}

function Assert-OwnerRuntimeProof {
    if ($Endpoint -ne 'owner') { Stop-PrivateRoleSnapshot 'owner_endpoint_required' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $lifecycle runtime-owner | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-PrivateRoleSnapshot 'owner_runtime_proof_failed' }
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) { Stop-PrivateRoleSnapshot 'loopback_probe_unavailable' }
    foreach ($uri in @('http://127.0.0.1:8190', 'http://127.0.0.1:8191')) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $lines = @(& $curl --noproxy '*' --silent --show-error --max-time 15 --output NUL --write-out 'STATUS:%{http_code}' $uri 2>&1)
            $exitCode = $LASTEXITCODE
        }
        finally { $ErrorActionPreference = $previous }
        $match = [regex]::Match(([string]::Join("`n", @($lines))), 'STATUS:(?<status>[0-9]{3})\z')
        if ($exitCode -ne 0 -or -not $match.Success -or [int]$match.Groups['status'].Value -notin @(200, 301, 302, 303)) {
            Stop-PrivateRoleSnapshot 'owner_loopback_endpoint_unhealthy'
        }
    }
}

function ConvertTo-StrictState([string[]]$Lines) {
    $counts = [ordered]@{}
    $semantic = [ordered]@{}
    foreach ($line in $Lines) {
        if ($line -match '^count\.([a-z_]+)=([0-9]+)$') {
            $key = [string]$Matches[1]
            if ($counts.Contains($key)) { Stop-PrivateRoleSnapshot 'state_duplicate_count' }
            $counts[$key] = [uint64]$Matches[2]
        }
        elseif ($line -match '^fp\.([a-z_]+)=([a-f0-9]{64})$') {
            $key = [string]$Matches[1]
            if ($semantic.Contains($key)) { Stop-PrivateRoleSnapshot 'state_duplicate_fingerprint' }
            $semantic[$key] = [string]$Matches[2]
        }
        else { Stop-PrivateRoleSnapshot 'state_output_invalid' }
    }
    if ($counts.Count -ne $countKeys.Count -or $semantic.Count -ne $semanticKeys.Count) {
        Stop-PrivateRoleSnapshot 'state_key_set_invalid'
    }
    foreach ($key in $countKeys) {
        if (-not $counts.Contains($key)) { Stop-PrivateRoleSnapshot 'state_count_key_missing' }
    }
    foreach ($key in $semanticKeys) {
        if (-not $semantic.Contains($key)) { Stop-PrivateRoleSnapshot 'state_fingerprint_key_missing' }
    }
    if ([uint64]$counts.class_identity_schema_version -ne $expectedSchema -or
        [uint64]$counts.migration_ledger_rows -ne $expectedSchema) {
        Stop-PrivateRoleSnapshot 'database_schema_not_current_v18'
    }
    return @{ Counts = $counts; Semantic = $semantic }
}

function Get-OwnerBusinessState([string]$AuditPrefixRows) {
    # All raw row streams are created, hashed, and deleted inside the MariaDB
    # container. Only aggregate decimal counts and opaque SHA-256 values cross
    # this boundary.
    $stateScript = @'
set -eu
umask 077
: "${MARIADB_ROOT_PASSWORD:?}"
: "${MARIADB_DATABASE:?}"
: "${CLASS_ARCHIVE_EXPECTED_SCHEMA:?}"
: "${CLASS_ARCHIVE_AUDIT_PREFIX_ROWS:?}"
case "$MARIADB_DATABASE" in ''|*[!A-Za-z0-9_]*) exit 81 ;; esac
case "$CLASS_ARCHIVE_EXPECTED_SCHEMA" in ''|*[!0-9]*) exit 82 ;; esac
case "$CLASS_ARCHIVE_AUDIT_PREFIX_ROWS" in FULL) ;; ''|*[!0-9]*) exit 82 ;; *) ;; esac
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
q() { mariadb --batch --skip-column-names --raw --binary-as-hex --protocol=socket --user=root "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 83 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ] || exit 84
schema_version=$(q "SELECT COALESCE(MAX(version),0) FROM ${base}migration;")
[ "$schema_version" = "$CLASS_ARCHIVE_EXPECTED_SCHEMA" ] || exit 85
table_exists() { q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$1';"; }
for suffix in migration identity seat account principal operation token submission archive_image photo photo_source photo_source_presentation photo_duplicate person person_merge person_photo_rule album spotlight auto_collection auto_collection_photo photo_comment audit_event ai_asset_index ai_index_job native_source_epoch collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item spotlight_rotation_state role_group; do
  [ "$(table_exists "${base}${suffix}")" = 1 ] || exit 86
done
for suffix in images categories image_category users user_infos groups user_group group_access user_access; do
  [ "$(table_exists "${pwg}${suffix}")" = 1 ] || exit 87
done
audit_rows=$(q "SELECT COUNT(*) FROM ${base}audit_event;")
case "$audit_rows" in ''|*[!0-9]*) exit 88 ;; esac
[ "$CLASS_ARCHIVE_AUDIT_PREFIX_ROWS" != "FULL" ] || CLASS_ARCHIVE_AUDIT_PREFIX_ROWS="$audit_rows"
[ "$CLASS_ARCHIVE_AUDIT_PREFIX_ROWS" -le "$audit_rows" ] || exit 89
tmp=$(mktemp) || exit 90
cleanup() { rm -f -- "$tmp"; unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM
fingerprint() {
  name="$1"; sql="$2"
  : > "$tmp"
  q "$sql" > "$tmp"
  digest=$(sha256sum "$tmp" | awk '{print $1}')
  case "$digest" in *[!a-f0-9]*|'') exit 91 ;; esac
  [ "${#digest}" = 64 ] || exit 92
  printf 'fp.%s=%s\n' "$name" "$digest"
}
printf 'count.class_identity_schema_version=%s\n' "$schema_version"
printf 'count.migration_ledger_rows=%s\n' "$(q "SELECT COUNT(*) FROM ${base}migration;")"
printf 'count.source_records=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_source;")"
printf 'count.canonical_photos=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo;")"
printf 'count.piwigo_images=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}images;")"
printf 'count.albums=%s\n' "$(q "SELECT COUNT(*) FROM ${base}album;")"
printf 'count.album_relationships=%s\n' "$(q "SELECT COUNT(*) FROM ${pwg}image_category;")"
printf 'count.comment_rows=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment;")"
printf 'count.active_comments=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE state='ACTIVE';")"
printf 'count.reply_rows=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE parent_comment_id IS NOT NULL;")"
printf 'count.active_replies=%s\n' "$(q "SELECT COUNT(*) FROM ${base}photo_comment WHERE state='ACTIVE' AND parent_comment_id IS NOT NULL;")"
printf 'count.spotlights=%s\n' "$(q "SELECT COUNT(*) FROM ${base}spotlight;")"
printf 'count.memories=%s\n' "$(q "SELECT COUNT(*) FROM ${base}auto_collection;")"
printf 'count.active_pins=%s\n' "$(q "SELECT COUNT(*) FROM ${base}collection_pin WHERE state='ACTIVE';")"
printf 'count.identities=%s\n' "$(q "SELECT COUNT(*) FROM ${base}identity;")"
printf 'count.seats=%s\n' "$(q "SELECT COUNT(*) FROM ${base}seat;")"
printf 'count.accounts=%s\n' "$(q "SELECT COUNT(*) FROM ${base}account;")"
printf 'count.principals=%s\n' "$(q "SELECT COUNT(*) FROM ${base}principal;")"
printf 'count.people_mappings=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person WHERE immich_person_id IS NOT NULL;")"
printf 'count.visible_people=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person WHERE state='ACTIVE' AND visibility='VISIBLE';")"
printf 'count.person_merges=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_merge;")"
printf 'count.person_rules=%s\n' "$(q "SELECT COUNT(*) FROM ${base}person_photo_rule;")"
printf 'count.claims=%s\n' "$(q "SELECT COUNT(*) FROM ${base}token WHERE purpose='CLAIM';")"
printf 'count.invitations=%s\n' "$(q "SELECT COUNT(*) FROM ${base}token WHERE purpose='FAMILY_INVITE';")"
printf 'count.submissions=%s\n' "$(q "SELECT COUNT(*) FROM ${base}submission;")"
printf 'count.audit_events=%s\n' "$audit_rows"
printf 'count.ai_asset_index=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_asset_index;")"
printf 'count.ai_jobs_total=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job;")"
printf 'count.ai_jobs_open=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state IN ('PENDING','RUNNING','UNAVAILABLE');")"
printf 'count.projection_epoch_rows=%s\n' "$(q "SELECT (SELECT COUNT(*) FROM ${base}native_source_epoch)+(SELECT COUNT(*) FROM ${base}collection_snapshot_pointer)+(SELECT COUNT(*) FROM ${base}collection_maintenance_state)+(SELECT COUNT(*) FROM ${base}spotlight_rotation_state);")"
fingerprint schema_ledger "SELECT * FROM ${base}migration ORDER BY version;"
fingerprint canonical_media "SELECT 'submission'; SELECT * FROM ${base}submission ORDER BY id; SELECT 'photo'; SELECT * FROM ${base}photo ORDER BY class_photo_id; SELECT 'photo_source'; SELECT * FROM ${base}photo_source ORDER BY id; SELECT 'photo_source_presentation'; SELECT * FROM ${base}photo_source_presentation ORDER BY photo_source_id; SELECT 'photo_duplicate'; SELECT * FROM ${base}photo_duplicate ORDER BY duplicate_id; SELECT 'private_library_collection'; SELECT * FROM ${base}private_library_collection ORDER BY source_collection_id; SELECT 'private_library_folder'; SELECT * FROM ${base}private_library_folder ORDER BY folder_id; SELECT 'private_library_import'; SELECT * FROM ${base}private_library_import ORDER BY import_id; SELECT 'private_library_import_item'; SELECT * FROM ${base}private_library_import_item ORDER BY import_id,item_digest; SELECT 'piwigo_images'; SELECT * FROM ${pwg}images ORDER BY id;"
fingerprint album_membership "SELECT 'archive_image'; SELECT * FROM ${base}archive_image ORDER BY id; SELECT 'album'; SELECT * FROM ${base}album ORDER BY class_album_id; SELECT 'batch_operation'; SELECT * FROM ${base}batch_operation ORDER BY batch_id; SELECT 'batch_operation_item'; SELECT * FROM ${base}batch_operation_item ORDER BY batch_id,id; SELECT 'categories'; SELECT * FROM ${pwg}categories ORDER BY id; SELECT 'image_category'; SELECT * FROM ${pwg}image_category ORDER BY image_id,category_id;"
fingerprint comments "SELECT * FROM ${base}photo_comment ORDER BY created_at,comment_id;"
fingerprint identity_security "SELECT 'identity'; SELECT * FROM ${base}identity ORDER BY id; SELECT 'seat'; SELECT * FROM ${base}seat ORDER BY id; SELECT 'account'; SELECT * FROM ${base}account ORDER BY id; SELECT 'principal'; SELECT * FROM ${base}principal ORDER BY id; SELECT 'operation'; SELECT * FROM ${base}operation ORDER BY id; SELECT 'token'; SELECT * FROM ${base}token ORDER BY id; SELECT 'role_group'; SELECT * FROM ${base}role_group ORDER BY id; SELECT 'pwg_users'; SELECT * FROM ${pwg}users ORDER BY id; SELECT 'pwg_user_infos'; SELECT * FROM ${pwg}user_infos ORDER BY user_id; SELECT 'pwg_groups'; SELECT * FROM ${pwg}groups ORDER BY id; SELECT 'pwg_user_group'; SELECT * FROM ${pwg}user_group ORDER BY user_id,group_id; SELECT 'pwg_group_access'; SELECT * FROM ${pwg}group_access ORDER BY group_id,cat_id; SELECT 'pwg_user_access'; SELECT * FROM ${pwg}user_access ORDER BY user_id,cat_id;"
fingerprint submissions "SELECT * FROM ${base}submission ORDER BY id;"
fingerprint person_curation "SELECT 'person'; SELECT * FROM ${base}person ORDER BY class_person_id; SELECT 'person_merge'; SELECT * FROM ${base}person_merge ORDER BY merge_id; SELECT 'person_photo_rule'; SELECT * FROM ${base}person_photo_rule ORDER BY class_person_id,class_photo_id;"
fingerprint spotlight_memories_pins "SELECT 'spotlight'; SELECT * FROM ${base}spotlight ORDER BY spotlight_id; SELECT 'auto_collection'; SELECT * FROM ${base}auto_collection ORDER BY auto_collection_id; SELECT 'auto_collection_photo'; SELECT * FROM ${base}auto_collection_photo ORDER BY auto_collection_id,ordinal,class_photo_id; SELECT 'collection_pin'; SELECT * FROM ${base}collection_pin ORDER BY pin_id; SELECT 'collection_feedback'; SELECT * FROM ${base}collection_feedback ORDER BY feedback_id;"
fingerprint ai_projection_control "SELECT 'ai_asset_index'; SELECT * FROM ${base}ai_asset_index ORDER BY class_photo_id; SELECT 'ai_index_job'; SELECT * FROM ${base}ai_index_job ORDER BY job_id; SELECT 'native_source_epoch'; SELECT * FROM ${base}native_source_epoch ORDER BY source_key; SELECT 'collection_snapshot'; SELECT * FROM ${base}collection_snapshot ORDER BY snapshot_id; SELECT 'collection_snapshot_item'; SELECT * FROM ${base}collection_snapshot_item ORDER BY snapshot_id,ordinal; SELECT 'collection_snapshot_pointer'; SELECT * FROM ${base}collection_snapshot_pointer ORDER BY scope,projection_kind; SELECT 'collection_maintenance_state'; SELECT * FROM ${base}collection_maintenance_state ORDER BY maintenance_key; SELECT 'spotlight_rotation_state'; SELECT * FROM ${base}spotlight_rotation_state ORDER BY scope;"
fingerprint audit_full "SELECT * FROM ${base}audit_event ORDER BY id;"
fingerprint audit_preexisting_prefix "SELECT * FROM ${base}audit_event ORDER BY id LIMIT ${CLASS_ARCHIVE_AUDIT_PREFIX_ROWS};"
fingerprint audit_high_water_opaque "SELECT COALESCE(MAX(id),0) FROM ${base}audit_event;"
'@
    $lines = Invoke-PiwigoComposeCapture @(
        'exec', '-T',
        '-e', ('CLASS_ARCHIVE_EXPECTED_SCHEMA=' + $expectedSchema),
        '-e', ('CLASS_ARCHIVE_AUDIT_PREFIX_ROWS=' + $AuditPrefixRows),
        'db', 'sh', '-eu', '-c', $stateScript
    ) 'mariadb_business_state_failed'
    return ConvertTo-StrictState $lines
}

function Assert-StateEqual([hashtable]$Expected, [hashtable]$Actual, [string]$CodePrefix) {
    foreach ($key in $countKeys) {
        if ([uint64]$Expected.Counts[$key] -ne [uint64]$Actual.Counts[$key]) {
            Stop-PrivateRoleSnapshot ($CodePrefix + '_count_' + $key)
        }
    }
    foreach ($key in $semanticKeys) {
        if (-not [string]::Equals([string]$Expected.Semantic[$key], [string]$Actual.Semantic[$key], [StringComparison]::Ordinal)) {
            Stop-PrivateRoleSnapshot ($CodePrefix + '_fingerprint_' + $key)
        }
    }
}

function New-OwnerDatabaseDump([string]$Destination) {
    $containerFile = '/tmp/classarchive-private-role-e2e-' + ([Guid]::NewGuid().ToString('N')) + '.sql.gz'
    $dumpScript = @'
set -eu
umask 077
: "${MARIADB_ROOT_PASSWORD:?}"
: "${MARIADB_DATABASE:?}"
case "$MARIADB_DATABASE" in ''|*[!A-Za-z0-9_]*) exit 81 ;; esac
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
unset MARIADB_ROOT_PASSWORD
out="$CLASS_ARCHIVE_DB_DUMP_FILE"
case "$out" in /tmp/classarchive-private-role-e2e-*.sql.gz) ;; *) exit 82 ;; esac
raw=${out%.gz}
cleanup() { rm -f -- "$raw"; unset MYSQL_PWD || true; }
trap cleanup EXIT HUP INT TERM
rm -f -- "$out" "$raw"
mariadb-dump --quick --lock-all-tables --triggers --routines --events --add-drop-table --protocol=socket --user=root "$MARIADB_DATABASE" > "$raw"
[ -s "$raw" ]
gzip -6 "$raw"
[ -s "$out" ]
'@
    try {
        $script:snapshotStage = 'database_dump_execute'
        [void](Invoke-PiwigoComposeCapture @(
            'exec', '-T', '-e', ('CLASS_ARCHIVE_DB_DUMP_FILE=' + $containerFile),
            'db', 'sh', '-eu', '-c', $dumpScript
        ) 'mariadb_dump_failed')
        $script:snapshotStage = 'database_dump_container_lookup'
        $containerIds = @(Invoke-PiwigoComposeCapture @('ps', '-q', 'db') 'mariadb_container_lookup_failed')
        if ($containerIds.Count -ne 1 -or $containerIds[0] -notmatch '^[a-f0-9]{12,64}$') {
            Stop-PrivateRoleSnapshot 'mariadb_container_identity_invalid'
        }
        $script:snapshotStage = 'database_dump_destination'
        $destinationWsl = Get-WslPath $Destination
        $script:snapshotStage = 'database_dump_copy'
        [void](Invoke-WslCapture @('-d', 'Ubuntu', '--exec', 'docker', 'cp', ($containerIds[0] + ':' + $containerFile), $destinationWsl) 'mariadb_dump_copy_failed')
        $script:snapshotStage = 'database_dump_validate'
        if (-not (Test-Path -LiteralPath $Destination -PathType Leaf) -or (Get-Item -LiteralPath $Destination).Length -le 0) {
            Stop-PrivateRoleSnapshot 'mariadb_dump_copy_empty'
        }
        $script:snapshotStage = 'database_dump_acl'
        Set-ClassArchiveOwnerOnlyFileAcl -Path $Destination
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $Destination
    }
    finally {
        try {
            [void](Invoke-PiwigoComposeCapture @('exec', '-T', 'db', 'rm', '-f', '--', $containerFile) 'mariadb_dump_cleanup_failed')
        }
        catch {}
    }
}

function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function New-SnapshotManifest([string]$Bundle, [hashtable]$State, [string]$SourceHead, [string]$CapturePhase, [uint64]$AuditPrefixRows) {
    $dumpPath = Join-Path $Bundle 'database.sql.gz'
    $dump = Get-Item -LiteralPath $dumpPath -Force
    $record = [ordered]@{
        format = 1
        scope = 'PRIVATE_ROLE_E2E_OWNER_DB_ONLY_ROLLBACK'
        phase = $CapturePhase
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        source_head = $SourceHead
        schema_version = $expectedSchema
        run_marker_sha256 = Get-RunMarkerDigest
        privacy = $privacyMarker
        consistency = 'MARIADB_DUMP_LOCK_ALL_TABLES_WITH_PRE_POST_STATE_EQUALITY'
        media = 'NOT_INCLUDED'
        disaster_backup = $false
        dump = [ordered]@{
            file = 'database.sql.gz'
            bytes = [uint64]$dump.Length
            sha256 = Get-Sha256 $dumpPath
        }
        counts = $State.Counts
        semantic_fingerprints = $State.Semantic
        audit_policy = [ordered]@{
            mode = 'APPEND_ONLY_PREFIX_PRESERVED'
            preexisting_rows = $AuditPrefixRows
            preexisting_prefix_sha256 = [string]$State.Semantic.audit_preexisting_prefix
            high_water_mark = 'OPAQUE_SHA256_ONLY'
        }
        excluded = @(
            'CANONICAL_ORIGINALS', 'DERIVATIVES', 'SOURCE_DIRECTORIES',
            'IMMICH_POSTGRES', 'FACE_EMBEDDINGS', 'SEARCH_EMBEDDINGS',
            'BROWSER_STATE', 'PLAINTEXT_SECRETS'
        )
    }
    $manifestPath = Join-Path $Bundle 'MANIFEST.json'
    Write-OwnerOnlyText $manifestPath (($record | ConvertTo-Json -Depth 8) + [Environment]::NewLine)
    $manifestSha = Get-Sha256 $manifestPath
    Write-OwnerOnlyText (Join-Path $Bundle 'MANIFEST.sha256') ($manifestSha + '  MANIFEST.json' + [Environment]::NewLine)
    Write-OwnerOnlyText (Join-Path $Bundle 'COMPLETE') ('PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT_COMPLETE' + [Environment]::NewLine)
    $sumLines = foreach ($name in @('database.sql.gz', 'MANIFEST.json', 'MANIFEST.sha256', 'COMPLETE')) {
        (Get-Sha256 (Join-Path $Bundle $name)) + '  ' + $name
    }
    Write-OwnerOnlyText (Join-Path $Bundle 'SHA256SUMS') (($sumLines -join [Environment]::NewLine) + [Environment]::NewLine)
    return $manifestSha
}

function Read-SnapshotBundle([string]$BundlePhase, [string]$ExpectedManifestSha256) {
    if ($ExpectedManifestSha256 -notmatch '^[a-f0-9]{64}$') { Stop-PrivateRoleSnapshot 'expected_manifest_sha256_required' }
    $bundle = Get-BundlePath $BundlePhase
    Assert-NoReparseAncestor $bundle 'bundle'
    if (-not (Test-Path -LiteralPath $bundle -PathType Container)) { Stop-PrivateRoleSnapshot 'bundle_missing' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $bundle
    $expectedFiles = @('database.sql.gz', 'MANIFEST.json', 'MANIFEST.sha256', 'COMPLETE', 'SHA256SUMS')
    $actualFiles = @(Get-ChildItem -LiteralPath $bundle -Force | Select-Object -ExpandProperty Name | Sort-Object)
    if (@(Compare-Object ($expectedFiles | Sort-Object) $actualFiles).Count -ne 0) { Stop-PrivateRoleSnapshot 'bundle_file_set_invalid' }
    foreach ($name in $expectedFiles) {
        $path = Join-Path $bundle $name
        $item = Get-Item -LiteralPath $path -Force
        if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-PrivateRoleSnapshot 'bundle_file_untrusted' }
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    }
    $manifestPath = Join-Path $bundle 'MANIFEST.json'
    $actualManifestSha = Get-Sha256 $manifestPath
    if (-not [string]::Equals($actualManifestSha, $ExpectedManifestSha256, [StringComparison]::Ordinal)) {
        Stop-PrivateRoleSnapshot 'manifest_sha256_mismatch'
    }
    $manifestShaRecord = ([IO.File]::ReadAllText((Join-Path $bundle 'MANIFEST.sha256'))).Trim()
    if ($manifestShaRecord -ne ($actualManifestSha + '  MANIFEST.json')) { Stop-PrivateRoleSnapshot 'manifest_sha256_record_invalid' }
    if (([IO.File]::ReadAllText((Join-Path $bundle 'COMPLETE'))).Trim() -ne 'PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT_COMPLETE') {
        Stop-PrivateRoleSnapshot 'complete_marker_invalid'
    }
    $seen = @{}
    foreach ($line in Get-Content -LiteralPath (Join-Path $bundle 'SHA256SUMS')) {
        if ($line -notmatch '^([a-f0-9]{64})  (database\.sql\.gz|MANIFEST\.json|MANIFEST\.sha256|COMPLETE)$') {
            Stop-PrivateRoleSnapshot 'checksum_record_invalid'
        }
        $name = [string]$Matches[2]
        if ($seen.ContainsKey($name) -or (Get-Sha256 (Join-Path $bundle $name)) -ne [string]$Matches[1]) {
            Stop-PrivateRoleSnapshot 'bundle_checksum_mismatch'
        }
        $seen[$name] = $true
    }
    if ($seen.Count -ne 4) { Stop-PrivateRoleSnapshot 'checksum_set_invalid' }
    try { $document = [IO.File]::ReadAllText($manifestPath) | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-PrivateRoleSnapshot 'manifest_json_invalid' }
    if ([int]$document.format -ne 1 -or [string]$document.scope -ne 'PRIVATE_ROLE_E2E_OWNER_DB_ONLY_ROLLBACK' -or
        [string]$document.phase -ne $BundlePhase -or [int]$document.schema_version -ne $expectedSchema -or
        [string]$document.run_marker_sha256 -ne (Get-RunMarkerDigest) -or [string]$document.privacy -ne $privacyMarker -or
        [string]$document.media -ne 'NOT_INCLUDED' -or [bool]$document.disaster_backup -ne $false) {
        Stop-PrivateRoleSnapshot 'manifest_contract_invalid'
    }
    $dumpItem = Get-Item -LiteralPath (Join-Path $bundle 'database.sql.gz') -Force
    if ([string]$document.dump.file -ne 'database.sql.gz' -or ([string]$document.dump.bytes) -notmatch '^[1-9][0-9]*$' -or
        [uint64]$document.dump.bytes -ne [uint64]$dumpItem.Length -or
        [string]$document.dump.sha256 -ne (Get-Sha256 $dumpItem.FullName)) {
        Stop-PrivateRoleSnapshot 'dump_manifest_binding_invalid'
    }
    if ([string]$document.source_head -notmatch '^[a-f0-9]{40}$' -or
        [string]$document.audit_policy.mode -ne 'APPEND_ONLY_PREFIX_PRESERVED' -or
        ([string]$document.audit_policy.preexisting_rows) -notmatch '^[0-9]+$' -or
        ([string]$document.audit_policy.preexisting_prefix_sha256) -notmatch '^[a-f0-9]{64}$' -or
        [string]$document.audit_policy.high_water_mark -ne 'OPAQUE_SHA256_ONLY') {
        Stop-PrivateRoleSnapshot 'manifest_audit_or_source_binding_invalid'
    }
    $counts = [ordered]@{}
    foreach ($property in $document.counts.PSObject.Properties) {
        if ($property.Name -notmatch '^[a-z_]+$' -or ([string]$property.Value) -notmatch '^[0-9]+$') { Stop-PrivateRoleSnapshot 'manifest_count_invalid' }
        $counts[$property.Name] = [uint64]$property.Value
    }
    $semantic = [ordered]@{}
    foreach ($property in $document.semantic_fingerprints.PSObject.Properties) {
        if ($property.Name -notmatch '^[a-z_]+$' -or ([string]$property.Value) -notmatch '^[a-f0-9]{64}$') { Stop-PrivateRoleSnapshot 'manifest_fingerprint_invalid' }
        $semantic[$property.Name] = [string]$property.Value
    }
    if ($counts.Count -ne $countKeys.Count -or @($countKeys | Where-Object { -not $counts.Contains($_) }).Count -ne 0 -or
        $semantic.Count -ne $semanticKeys.Count -or @($semanticKeys | Where-Object { -not $semantic.Contains($_) }).Count -ne 0) {
        Stop-PrivateRoleSnapshot 'manifest_state_key_set_invalid'
    }
    return @{ Path = $bundle; ManifestSha256 = $actualManifestSha; Document = $document; Counts = $counts; Semantic = $semantic }
}

function Compare-PrePost([hashtable]$Pre, [hashtable]$Post) {
    if ([string]$Pre.Document.source_head -ne [string]$Post.Document.source_head) { Stop-PrivateRoleSnapshot 'source_head_changed' }
    if ([uint64]$Pre.Document.audit_policy.preexisting_rows -ne [uint64]$Pre.Counts.audit_events -or
        [uint64]$Post.Document.audit_policy.preexisting_rows -ne [uint64]$Pre.Counts.audit_events) {
        Stop-PrivateRoleSnapshot 'audit_prefix_row_binding_changed'
    }
    foreach ($key in $countKeys) {
        if ($key -eq 'audit_events') { continue }
        if ([uint64]$Pre.Counts[$key] -ne [uint64]$Post.Counts[$key]) {
            Stop-PrivateRoleSnapshot ('post_cleanup_count_mismatch_' + $key)
        }
    }
    foreach ($key in $stableSemanticKeys) {
        if (-not [string]::Equals([string]$Pre.Semantic[$key], [string]$Post.Semantic[$key], [StringComparison]::Ordinal)) {
            Stop-PrivateRoleSnapshot ('post_cleanup_fingerprint_mismatch_' + $key)
        }
    }
    if ([uint64]$Post.Counts.audit_events -lt [uint64]$Pre.Counts.audit_events) {
        Stop-PrivateRoleSnapshot 'audit_rows_deleted'
    }
    if (-not [string]::Equals([string]$Pre.Semantic.audit_full, [string]$Pre.Semantic.audit_preexisting_prefix, [StringComparison]::Ordinal) -or
        -not [string]::Equals([string]$Pre.Semantic.audit_full, [string]$Post.Semantic.audit_preexisting_prefix, [StringComparison]::Ordinal)) {
        Stop-PrivateRoleSnapshot 'audit_preexisting_prefix_changed'
    }
}

function Enter-SnapshotLock {
    $lockPath = Join-Path $privateRoot '.business-snapshot.lock'
    try {
        $stream = [IO.File]::Open($lockPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $stream.Dispose()
        Set-ClassArchiveOwnerOnlyFileAcl -Path $lockPath
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $lockPath
        return $lockPath
    }
    catch { Stop-PrivateRoleSnapshot 'snapshot_lock_unavailable' }
}

function Remove-ValidatedPrivateArtifact([string]$Path, [switch]$Recurse) {
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) { return }
    $full = [IO.Path]::GetFullPath($Path)
    $boundary = [IO.Path]::GetFullPath($privateRoot).TrimEnd('\') + '\'
    if (-not $full.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) { Stop-PrivateRoleSnapshot 'cleanup_path_outside_private_root' }
    Assert-NoReparseAncestor $full 'cleanup_path'
    if ($Recurse) { Remove-Item -LiteralPath $full -Recurse -Force }
    else { Remove-Item -LiteralPath $full -Force }
}

$script:snapshotStage = 'initial'
try {
    if ($Endpoint -ne 'owner') { Stop-PrivateRoleSnapshot 'owner_endpoint_required' }
    $script:snapshotStage = 'private_root'
    Assert-IgnoredPrivateRoot
    $script:snapshotStage = 'schema_contract'
    Assert-SchemaSourceContract

    if ($Action -eq 'Compare') {
        if ([string]::IsNullOrWhiteSpace($ExpectedPreManifestSha256) -or [string]::IsNullOrWhiteSpace($ExpectedPostManifestSha256)) {
            Stop-PrivateRoleSnapshot 'compare_hash_binding_required'
        }
        $pre = Read-SnapshotBundle 'pre' $ExpectedPreManifestSha256
        $post = Read-SnapshotBundle 'post' $ExpectedPostManifestSha256
        Compare-PrePost $pre $post
        Write-Output 'PRIVATE_ROLE_E2E_BUSINESS_STATE=PASS action=compare records=PRESERVED semantics=PRESERVED audit=APPEND_ONLY_PREFIX_PRESERVED scope=DB_ONLY'
        exit 0
    }

    if (-not $ConfirmOwnerPrivateSnapshot.IsPresent) { Stop-PrivateRoleSnapshot 'explicit_confirmation_required' }
    if ($Phase -eq 'post' -and [string]::IsNullOrWhiteSpace($ExpectedPreManifestSha256)) {
        Stop-PrivateRoleSnapshot 'post_requires_pre_hash_binding'
    }
    $script:snapshotStage = 'clean_checkout'
    Assert-CleanCheckout
    $script:snapshotStage = 'owner_runtime'
    Assert-OwnerRuntimeProof
    $preBundle = $null
    $auditPrefixRows = 'FULL'
    if ($Phase -eq 'post') {
        $preBundle = Read-SnapshotBundle 'pre' $ExpectedPreManifestSha256
        $auditPrefixRows = [string][uint64]$preBundle.Counts.audit_events
    }
    $bundle = Get-BundlePath $Phase
    if (Test-Path -LiteralPath $bundle) { Stop-PrivateRoleSnapshot 'immutable_bundle_already_exists' }
    $partial = $bundle + '.partial'
    if (Test-Path -LiteralPath $partial) { Stop-PrivateRoleSnapshot 'partial_bundle_already_exists' }
    $script:snapshotStage = 'lock'
    $lockPath = Enter-SnapshotLock
    try {
        $script:snapshotStage = 'partial_directory'
        [IO.Directory]::CreateDirectory($partial) | Out-Null
        Set-OwnerOnlyDirectoryAcl $partial
        $script:snapshotStage = 'state_before'
        $stateBefore = Get-OwnerBusinessState $auditPrefixRows
        $script:snapshotStage = 'database_dump'
        New-OwnerDatabaseDump (Join-Path $partial 'database.sql.gz')
        $script:snapshotStage = 'state_after'
        $stateAfter = Get-OwnerBusinessState $auditPrefixRows
        $script:snapshotStage = 'state_consistency'
        Assert-StateEqual $stateBefore $stateAfter 'snapshot_consistency_drift'
        if ($Phase -eq 'pre' -and [string]$stateAfter.Semantic.audit_full -ne [string]$stateAfter.Semantic.audit_preexisting_prefix) {
            Stop-PrivateRoleSnapshot 'pre_audit_prefix_not_full'
        }
        if ($Phase -eq 'post' -and [string]$stateAfter.Semantic.audit_preexisting_prefix -ne [string]$preBundle.Semantic.audit_full) {
            Stop-PrivateRoleSnapshot 'post_audit_prefix_not_preserved'
        }
        $sourceHead = ([string](& git -C $projectRoot rev-parse HEAD 2>$null)).Trim()
        if ($LASTEXITCODE -ne 0 -or $sourceHead -notmatch '^[a-f0-9]{40}$') { Stop-PrivateRoleSnapshot 'source_head_invalid' }
        $manifestAuditPrefixRows = if ($Phase -eq 'pre') { [uint64]$stateAfter.Counts.audit_events } else { [uint64]$auditPrefixRows }
        $script:snapshotStage = 'manifest'
        $manifestSha = New-SnapshotManifest $partial $stateAfter $sourceHead $Phase $manifestAuditPrefixRows
        $script:snapshotStage = 'finalize'
        Move-Item -LiteralPath $partial -Destination $bundle
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $bundle
        $gate = if ($Phase -eq 'pre') { 'PRIVATE_ROLE_E2E_PRE_SNAPSHOT' } else { 'PRIVATE_ROLE_E2E_POST_SNAPSHOT' }
        Write-Output ("$gate=PASS action=capture phase=$Phase manifest_sha256=$manifestSha scope=DB_ONLY media=NOT_INCLUDED privacy=OPAQUE_ONLY")
    }
    catch {
        Remove-ValidatedPrivateArtifact $partial -Recurse
        throw
    }
    finally {
        Remove-ValidatedPrivateArtifact $lockPath
    }
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^PRIVATE_ROLE_SNAPSHOT_STOP:([a-z0-9_]{1,120})$') { [string]$Matches[1] } else { 'private_role_snapshot_failed_' + $script:snapshotStage }
    Write-Output "PRIVATE_ROLE_E2E_BUSINESS_SNAPSHOT=FAIL action=$Action phase=$Phase code=$code"
    exit 2
}
