[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'prepare-storage', 'restore', 'verify', 'cold-restart', 'status')]
    [string]$Action = 'validate',

    [Parameter(Mandatory = $true)]
    [string]$BackupBundlePath,

    [ValidateRange(32, 256)]
    [int]$RuntimeImageSizeGiB = 64,

    [string]$VerifiedModelCacheDirectory = '',
    [switch]$ConfirmCreateRestoreStorage,
    [switch]$ConfirmIsolatedRestore,
    [switch]$ConfirmColdRestart
)

# Independent v2 recovery. The portable backup is read only from C:, while
# restored runtime state is written only to a new ext4 image on M:. This tool
# has no delete, reset, prune or down action and never selects another Class
# Archive project. Any failure after Piwigo starts reasserts maintenance.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
if ($PSVersionTable.PSEdition -ne 'Core' -or $PSVersionTable.PSVersion.Major -lt 7) {
    throw 'OWNER_RESTORE_V2_STOP:powershell_7_required'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wsl = "$env:SystemRoot\System32\wsl.exe"
$sourceDrive = 'C:'
$targetDrive = 'M:'
$sourceRoot = Join-Path ($sourceDrive + '\') 'ClassArchive-Independent-Recovery'
$sourceBundleRoot = Join-Path $sourceRoot 'bundles'
$targetRoot = Join-Path ($targetDrive + '\') 'ClassArchive-Independent-Restore-v2'
$runtimeImage = Join-Path $targetRoot 'runtime\classarchive-owner-restore-v2.ext4'
$mountPoint = '/mnt/classarchive-owner-restore-v2'
$restoreVolumeRoot = $mountPoint + '/volumes'
$dockerHost = 'unix:///var/run/docker.sock'
$piwigoProject = 'class_archive_owner_restore_v2_piwigo'
$immichProject = 'class_archive_owner_restore_v2_immich'
$gatewayNetwork = 'class_archive_owner_restore_v2_gateway'
$scopeLabel = 'owner-independent-restore-v2'
$storageLabel = 'm-ext4-bind-v2'
$piwigoEnvPath = Join-Path $projectRoot 'infra\owner-restore-v2\.env.piwigo'
$immichEnvPath = Join-Path $projectRoot 'infra\owner-restore-v2\.env.immich'
$privateRuntimeRoot = Join-Path $projectRoot '.codex-work\owner-restore-v2\runtime'
$restoreNginxPath = Join-Path $privateRuntimeRoot 'nginx.conf'
$statePath = Join-Path $privateRuntimeRoot 'restore-state.json'
$lockPath = Join-Path $privateRuntimeRoot 'workflow.lock'
$streamHelper = Join-Path $PSScriptRoot 'restore-owner-independent-backup-v2.sh'
$portableHelper = Join-Path $PSScriptRoot 'owner-portable-recovery-helper.sh'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/owner-restore-v2/docker-compose.piwigo.override.yml'
$piwigoWorkerOverride = 'infra/private-full/docker-compose.ai-worker.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/owner-restore-v2/docker-compose.immich.override.yml'
$script:stage = 'initialization'
$script:assertions = 0
$script:maintenanceMayBeOpen = $false

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')
. (Join-Path $PSScriptRoot 'owner-portable-recovery.ps1')

function Stop-RestoreV2([string]$Code) {
    throw [InvalidOperationException]::new('OWNER_RESTORE_V2_STOP:' + $Code)
}

function Set-OwnerRestoreV2Utf8ConsoleEncoding {
    # WSL helpers are launched from this checkout, whose path can contain
    # non-ASCII characters. Keep native command output decoding process-local
    # UTF-8 and fail closed rather than accepting an ambiguous helper path.
    try {
        $utf8 = [Text.UTF8Encoding]::new($false)
        [Console]::OutputEncoding = $utf8
        $script:OutputEncoding = $utf8
        if ([Console]::OutputEncoding.CodePage -ne 65001) { Stop-RestoreV2 'utf8_console_encoding_unavailable' }
    }
    catch {
        Stop-RestoreV2 'utf8_console_encoding_unavailable'
    }
}

Set-OwnerRestoreV2Utf8ConsoleEncoding

function Assert-RestoreV2([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-RestoreV2 $Code }
}

function Test-FixedAsciiEqual([string]$Left, [string]$Right) {
    if ($Left.Length -ne $Right.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor (([int][char]$Left[$index]) -bxor ([int][char]$Right[$index]))
    }
    return $difference -eq 0
}

function Assert-PlainFile([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-RestoreV2 (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -and $item.Length -gt 0) $Code
}

function Assert-PlainDirectory([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    Assert-RestoreV2 ($item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) $Code
}

function Assert-DirectoryChain([string]$Root, [string]$Leaf) {
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\')
    $leafFull = [IO.Path]::GetFullPath($Leaf).TrimEnd('\')
    Assert-RestoreV2 ($leafFull.StartsWith($rootFull + '\', [StringComparison]::OrdinalIgnoreCase)) 'bundle_outside_fixed_source_root'
    $current = $leafFull
    while ($true) {
        Assert-PlainDirectory $current 'bundle_directory_untrusted'
        if ([string]::Equals($current, $rootFull, [StringComparison]::OrdinalIgnoreCase)) { break }
        $parent = [IO.Directory]::GetParent($current)
        Assert-RestoreV2 ($null -ne $parent) 'bundle_directory_parent_invalid'
        $current = $parent.FullName.TrimEnd('\')
    }
}

function Get-WslPath([string]$Path) {
    # Avoid a locale-sensitive `wslpath` stdout round trip. The restore
    # protocol accepts only local-drive paths for its source, runtime and
    # helper inputs; reject UNC, traversal and alternate separators here.
    try { $full = [IO.Path]::GetFullPath($Path) }
    catch { Stop-RestoreV2 'wsl_path_conversion_failed' }
    if ($full -notmatch '^([a-zA-Z]):\\(.+)$') { Stop-RestoreV2 'wsl_path_conversion_failed' }
    $drive = $Matches[1].ToLowerInvariant()
    $segments = @($Matches[2] -split '\\')
    Assert-RestoreV2 ($segments.Count -ge 1 -and @($segments | Where-Object {
            [string]::IsNullOrWhiteSpace($_) -or $_ -eq '.' -or $_ -eq '..' -or $_ -match '[/\x00:]'
        }).Count -eq 0) 'wsl_path_conversion_failed'
    return '/mnt/' + $drive + '/' + ($segments -join '/')
}

function Invoke-Ubuntu([string[]]$Arguments, [string]$FailureCode = 'ubuntu_command_failed') {
    $nativeArguments = @($Arguments | ForEach-Object { ([string]$_).Replace("`r`n", "`n") })
    Assert-RestoreV2 (@($nativeArguments | Where-Object { $_.Contains("`r") }).Count -eq 0) 'ubuntu_argument_carriage_return_invalid'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $result = @(& $wsl -d Ubuntu --exec @nativeArguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        if ($FailureCode -eq 'restore_stream_failed') {
            $safe = @($result | Where-Object { [string]$_ -match '\AOWNER_RESTORE_V2_STREAM=FAIL code=([a-z0-9_]{1,128})\z' })
            if ($safe.Count -eq 1 -and [string]$safe[0] -match '\AOWNER_RESTORE_V2_STREAM=FAIL code=([a-z0-9_]{1,128})\z') {
                Stop-RestoreV2 ('restore_stream_' + [string]$Matches[1])
            }
        }
        Stop-RestoreV2 $FailureCode
    }
    return @($result | ForEach-Object { [string]$_ })
}

function Invoke-RestoreDocker([string[]]$Arguments, [string]$FailureCode = 'restore_docker_failed') {
    return Invoke-Ubuntu (@('docker', '--host', $dockerHost) + $Arguments) $FailureCode
}

function Invoke-RestoreCompose([ValidateSet('piwigo','immich')][string]$Scope, [string[]]$Arguments) {
    if ($Scope -eq 'piwigo') {
        $composeArguments = @(
            'env', ('DOCKER_HOST=' + $dockerHost), 'docker', 'compose', '--env-file', 'infra/owner-restore-v2/.env.piwigo',
            '-f', $piwigoCompose, '-f', $piwigoOverride, '-f', $piwigoWorkerOverride, '-p', $piwigoProject
        ) + $Arguments
    }
    else {
        $composeArguments = @(
            'env', ('DOCKER_HOST=' + $dockerHost), 'docker', 'compose', '--env-file', 'infra/owner-restore-v2/.env.immich',
            '-f', $immichCompose, '-f', $immichOverride, '-p', $immichProject
        ) + $Arguments
    }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        # --cd belongs to wsl.exe, not the executable launched by --exec.
        $result = @(& $wsl -d Ubuntu --cd $projectRoot --exec @composeArguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { Stop-RestoreV2 ($Scope + '_compose_failed') }
    return @($result | ForEach-Object { [string]$_ })
}

function Enter-WorkflowLock {
    if (-not (Test-Path -LiteralPath $privateRuntimeRoot -PathType Container)) {
        [void][IO.Directory]::CreateDirectory($privateRuntimeRoot)
    }
    Assert-PlainDirectory $privateRuntimeRoot 'private_runtime_root_untrusted'
    try {
        return [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    }
    catch { Stop-RestoreV2 'workflow_lock_held' }
}

function Write-OwnerOnlyText([string]$Path, [string]$Text, [switch]$AllowReplace) {
    $parent = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $parent -PathType Container)) { [void][IO.Directory]::CreateDirectory($parent) }
    Assert-PlainDirectory $parent 'private_output_parent_untrusted'
    if ((Test-Path -LiteralPath $Path) -and -not $AllowReplace) { Stop-RestoreV2 'private_output_already_exists' }
    $temporary = $Path + '.tmp.' + [Guid]::NewGuid().ToString('N')
    try {
        [IO.File]::WriteAllText($temporary, $Text, [Text.UTF8Encoding]::new($false))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $temporary
        if ($AllowReplace -and (Test-Path -LiteralPath $Path)) { [IO.File]::Replace($temporary, $Path, $null, $true) }
        else { [IO.File]::Move($temporary, $Path) }
        Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
        $relative = [IO.Path]::GetFullPath($Path).Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\','/')
        & git -C $projectRoot check-ignore --quiet --no-index -- $relative
        Assert-RestoreV2 ($LASTEXITCODE -eq 0 -and @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) 'private_output_git_visible'
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    }
    finally { if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force } }
}

function Remove-PrivateTemporaryFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = [IO.Path]::GetFullPath($privateRuntimeRoot).TrimEnd('\') + '\'
    Assert-RestoreV2 ($full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) 'temporary_cleanup_path_invalid'
    Remove-Item -LiteralPath $full -Force
}

function Get-PayloadSpecs {
    return @(
        @{ id='runtime_counts'; file='business-state/runtime-counts.json'; kind='SANITIZED_EVIDENCE'; order=1; posix=$false },
        @{ id='immich_upstream_lock'; file='business-state/immich-upstream.lock.json'; kind='NON_SECRET_CONFIGURATION'; order=2; posix=$false },
        @{ id='ml_artifact_manifest'; file='business-state/ml-artifact-manifest.json'; kind='NON_SECRET_CONFIGURATION'; order=3; posix=$false },
        # Compatibility payload retained by the backup format. V2 hashes it as
        # immutable inventory but never opens or uses it; only the portable
        # envelope below is accepted as a recovery-secret input.
        @{ id='recovery_secrets'; file='business-state/recovery-secrets.dpapi.json'; kind='WINDOWS_DPAPI_ENVELOPE'; order=5; posix=$false },
        @{ id='mariadb'; file='databases/mariadb.sql.gz.gpg'; kind='DATABASE_LOGICAL_GPG'; order=10; posix=$false },
        @{ id='immich_postgres'; file='databases/immich-postgres.dump.gpg'; kind='DATABASE_LOGICAL_GPG'; order=20; posix=$false },
        @{ id='piwigo_data'; file='business-state/piwigo-data.tar.gpg'; kind='POSIX_TAR_GPG'; order=30; posix=$true },
        @{ id='piwigo_scripts'; file='business-state/piwigo-scripts.tar.gpg'; kind='POSIX_TAR_GPG'; order=40; posix=$true },
        @{ id='piwigo_uploads'; file='media-archives/piwigo-uploads.tar.gpg'; kind='POSIX_TAR_GPG'; order=50; posix=$true },
        @{ id='piwigo_galleries'; file='media-archives/piwigo-galleries.tar.gpg'; kind='POSIX_TAR_GPG'; order=60; posix=$true },
        @{ id='immich_upload'; file='immich-state/immich-upload.tar.gpg'; kind='POSIX_TAR_GPG'; order=70; posix=$true },
        @{ id='portable_readme'; file='recovery-kit/README-PORTABLE-RESTORE.txt'; kind='PORTABLE_RECOVERY_DOCUMENTATION'; order=81; posix=$false },
        @{ id='portable_manifest'; file='recovery-kit/manifest.json'; kind='PORTABLE_RECOVERY_MANIFEST'; order=82; posix=$false },
        @{ id='portable_checksums'; file='recovery-kit/checksums.sha256'; kind='PORTABLE_RECOVERY_CHECKSUMS'; order=83; posix=$false },
        @{ id='portable_restore_powershell'; file='recovery-kit/restore.ps1'; kind='PORTABLE_RECOVERY_TOOL'; order=84; posix=$false },
        @{ id='portable_restore_shell'; file='recovery-kit/restore.sh'; kind='PORTABLE_RECOVERY_TOOL'; order=85; posix=$false },
        @{ id='portable_container_lock'; file='recovery-kit/container-lock.json'; kind='NON_SECRET_CONFIGURATION'; order=86; posix=$false },
        @{ id='portable_migration_info'; file='recovery-kit/migration-info.json'; kind='NON_SECRET_CONFIGURATION'; order=87; posix=$false },
        @{ id='portable_ml_manifest'; file='recovery-kit/ml-artifact-manifest.json'; kind='NON_SECRET_CONFIGURATION'; order=88; posix=$false },
        @{ id='portable_key_envelope'; file='recovery-kit/portable-key-envelope.gpg'; kind='GPG_PORTABLE_SECRET_ENVELOPE'; order=6; posix=$false }
    )
}

function Read-ChecksumFile([string]$Path) {
    $result = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        Assert-RestoreV2 ($line -match '\A([0-9a-f]{64})  ([A-Za-z0-9._/-]+)\z') 'checksum_line_invalid'
        $relative = [string]$Matches[2]
        Assert-RestoreV2 (-not $result.ContainsKey($relative) -and -not $relative.Contains('..') -and -not $relative.StartsWith('/')) 'checksum_path_invalid'
        $result[$relative] = [string]$Matches[1]
    }
    return $result
}

function Assert-Checksums([string]$Root, [hashtable]$Checksums, [string[]]$Required) {
    foreach ($relative in $Required) { Assert-RestoreV2 $Checksums.ContainsKey($relative) 'checksum_required_file_missing' }
    foreach ($relative in $Checksums.Keys) {
        $path = Join-Path $Root ([string]$relative).Replace('/', '\')
        Assert-PlainFile $path 'checksummed_file_untrusted'
        $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-RestoreV2 (Test-FixedAsciiEqual $actual ([string]$Checksums[$relative])) 'bundle_sha256_mismatch'
    }
}

function Read-VerifiedBundle {
    $script:stage = 'bundle_validation'
    $bundle = [IO.Path]::GetFullPath($BackupBundlePath).TrimEnd('\')
    Assert-RestoreV2 ([string]::Equals([IO.Directory]::GetParent($bundle).FullName, $sourceBundleRoot, [StringComparison]::OrdinalIgnoreCase)) 'bundle_parent_invalid'
    Assert-RestoreV2 ([IO.Path]::GetFileName($bundle) -match '\Aowner-full-v2-[0-9]{8}T[0-9]{6}Z\z') 'bundle_name_invalid'
    Assert-DirectoryChain $sourceRoot $bundle
    $sourceMarker = Join-Path $sourceRoot 'CLASS_ARCHIVE_INDEPENDENT_RECOVERY_TARGET'
    Assert-PlainFile $sourceMarker 'independent_recovery_marker_untrusted'
    Assert-RestoreV2 ([string]::Equals([IO.File]::ReadAllText($sourceMarker),
        "CLASS_ARCHIVE_INDEPENDENT_RECOVERY_TARGET`nversion=1`nscope=OWNER_V2_SECOND_MEDIA`n",
        [StringComparison]::Ordinal)) 'independent_recovery_marker_invalid'

    $manifestPath = Join-Path $bundle 'manifest.json'
    $checksumPath = Join-Path $bundle 'SHA256SUMS'
    $completePath = Join-Path $bundle 'COMPLETE'
    foreach ($path in @($manifestPath,$checksumPath,$completePath)) { Assert-PlainFile $path 'bundle_control_file_untrusted' }
    try { $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-RestoreV2 'manifest_invalid' }
    Assert-RestoreV2 ($manifest.format -eq 'owner-full-recovery-v2' -and [int]$manifest.version -eq 2 -and
        $manifest.scope -eq 'OWNER_PRIVATE_FULL' -and $manifest.temporary_recovery_target -eq $true -and
        $manifest.independent_disaster_backup -eq $false -and $manifest.filesystem -eq 'exFAT') 'manifest_identity_invalid'
    Assert-RestoreV2 ([string]$manifest.backup_id -eq [IO.Path]::GetFileName($bundle) -and
        [string]::Equals([IO.File]::ReadAllText($completePath), ([string]$manifest.backup_id + "`n"), [StringComparison]::Ordinal)) 'manifest_complete_invalid'
    Assert-RestoreV2 ([string]$manifest.source_head -match '\A[0-9a-f]{40}\z' -and [int]$manifest.schema_versions.class_identity -eq 16 -and
        [string]$manifest.schema_versions.piwigo -eq '16.4.0' -and [string]$manifest.schema_versions.immich -eq '3.1.0') 'manifest_schema_invalid'
    Assert-RestoreV2 ($manifest.encryption.archive -eq 'GPG_SYMMETRIC_AES256' -and
        $manifest.encryption.key_protection -eq 'WINDOWS_DPAPI_CURRENT_USER_PLUS_PORTABLE_GPG_ENVELOPE' -and
        $manifest.encryption.dpapi_retained -eq $true -and $manifest.encryption.plaintext_archive_on_exfat -eq $false) 'manifest_archive_encryption_invalid'
    Assert-RestoreV2 ([string]$manifest.encryption.portable_envelope.payload_format -eq 'owner-portable-recovery-secrets-v1' -and
        $manifest.encryption.portable_envelope.protection -eq 'GPG_SYMMETRIC_AES256' -and
        $manifest.encryption.portable_envelope.depends_on_windows_profile -eq $false -and
        $manifest.encryption.portable_envelope.dpapi_required -eq $false) 'manifest_portable_envelope_invalid'
    Assert-RestoreV2 ($manifest.restore_runtime.must_use_fresh_volumes -eq $true -and $manifest.restore_runtime.current_owner_runtime_must_not_be_destroyed -eq $true) 'manifest_restore_policy_invalid'
    $expectedImages = [ordered]@{
        piwigo='piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
        mariadb='mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf'
        immich_server='ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa'
        immich_machine_learning='ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
        postgres='ghcr.io/immich-app/postgres:14-vectorchord0.4.3-pgvectors0.2.0@sha256:bcf63357191b76a916ae5eb93464d65c07511da41e3bf7a8416db519b40b1c23'
    }
    Assert-RestoreV2 (@(Compare-Object @($expectedImages.Keys | Sort-Object) @($manifest.container_images.PSObject.Properties.Name | Sort-Object)).Count -eq 0) 'manifest_container_image_inventory_invalid'
    foreach ($property in $expectedImages.GetEnumerator()) {
        Assert-RestoreV2 ([string]$manifest.container_images.($property.Key) -ceq [string]$property.Value) 'manifest_container_image_invalid'
    }
    $requiredCounts = @(
        'source_records','source_presentations','canonical_photos','piwigo_images','album_relationships','leaf_albums','comments','replies',
        'visible_people','person_merges','person_rules','spotlights','memories','audit_events','ai_asset_index',
        'ai_jobs_total','ai_jobs_complete','ai_jobs_pending','ai_jobs_running','ai_jobs_unavailable','ai_jobs_failed','ai_jobs_cancelled',
        'immich_assets','immich_face_records','immich_raw_persons','immich_search_index'
    ) | Sort-Object
    Assert-RestoreV2 (@(Compare-Object $requiredCounts @($manifest.counts.PSObject.Properties.Name | Sort-Object)).Count -eq 0) 'manifest_count_inventory_invalid'
    foreach ($countName in $requiredCounts) {
        Assert-RestoreV2 ([string]$manifest.counts.$countName -match '\A(?:0|[1-9][0-9]{0,11})\z') 'manifest_count_invalid'
    }
    Assert-RestoreV2 (@(Compare-Object @('cancelled','complete','failed','pending','running','total','unavailable') @($manifest.ai_job_state.PSObject.Properties.Name | Sort-Object)).Count -eq 0) 'manifest_ai_job_state_inventory_invalid'
    foreach ($stateName in @('total','complete','pending','running','unavailable','failed','cancelled')) {
        $countName='ai_jobs_' + $stateName
        Assert-RestoreV2 ([uint64]$manifest.ai_job_state.$stateName -eq [uint64]$manifest.counts.$countName) 'manifest_ai_job_state_mismatch'
    }

    $requiredPayloads = @((Get-PayloadSpecs | ForEach-Object { [string]$_.file }) + @('manifest.json','COMPLETE')) | Sort-Object
    $checksums = Read-ChecksumFile $checksumPath
    Assert-RestoreV2 (@(Compare-Object $requiredPayloads @($checksums.Keys | Sort-Object)).Count -eq 0) 'checksum_inventory_invalid'
    Assert-Checksums $bundle $checksums $requiredPayloads
    $bundlePrefix=$bundle.TrimEnd('\') + '\'
    $actualFiles=@(Get-ChildItem -LiteralPath $bundle -Recurse -Force | ForEach-Object {
        Assert-RestoreV2 (-not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'backup_bundle_reparse_point'
        if (-not $_.PSIsContainer) { $_.FullName.Substring($bundlePrefix.Length).Replace('\','/') }
    } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Sort-Object)
    $allowedFiles=@($requiredPayloads + 'SHA256SUMS') | Sort-Object
    Assert-RestoreV2 (@(Compare-Object $allowedFiles $actualFiles).Count -eq 0) 'backup_bundle_inventory_invalid'

    $specs = @(Get-PayloadSpecs)
    $archives = @($manifest.archives)
    Assert-RestoreV2 ($archives.Count -eq $specs.Count) 'manifest_archive_count_invalid'
    foreach ($spec in $specs) {
        $record = @($archives | Where-Object { [string]$_.artifact_id -ceq [string]$spec.id -and [string]$_.file -ceq [string]$spec.file })
        $encrypted=([string]$spec.file).EndsWith('.gpg',[StringComparison]::Ordinal)
        Assert-RestoreV2 ($record.Count -eq 1 -and [uint64]$record[0].size -gt 0 -and [string]$record[0].sha256 -match '\A[0-9a-f]{64}\z' -and
            [string]$record[0].kind -ceq [string]$spec.kind -and [int]$record[0].restore_order -eq [int]$spec.order -and
            [bool]$record[0].preserves_posix_metadata -eq [bool]$spec.posix -and [bool]$record[0].encrypted -eq $encrypted) 'manifest_archive_contract_invalid'
        $path = Join-Path $bundle ([string]$spec.file).Replace('/','\')
        $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-RestoreV2 ([uint64](Get-Item -LiteralPath $path).Length -eq [uint64]$record[0].size -and (Test-FixedAsciiEqual $actual ([string]$record[0].sha256))) 'manifest_archive_digest_mismatch'
    }

    $kitRoot = Join-Path $bundle 'recovery-kit'
    try { $kitManifest = Get-Content -LiteralPath (Join-Path $kitRoot 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-RestoreV2 'portable_kit_manifest_invalid' }
    Assert-RestoreV2 ($kitManifest.format -eq 'owner-portable-recovery-kit-v1' -and [int]$kitManifest.version -eq 1 -and
        [string]$kitManifest.backup_id -eq [string]$manifest.backup_id -and $kitManifest.scope -eq 'OWNER_PRIVATE_FULL' -and
        $kitManifest.dpapi_required -eq $false -and [string]$kitManifest.envelope.payload_format -eq 'owner-portable-recovery-secrets-v1') 'portable_kit_manifest_identity_invalid'
    $kitChecksums = Read-ChecksumFile (Join-Path $kitRoot 'checksums.sha256')
    $kitRequired=@('README-PORTABLE-RESTORE.txt','container-lock.json','manifest.json','migration-info.json','ml-artifact-manifest.json','portable-key-envelope.gpg','restore.ps1','restore.sh') | Sort-Object
    Assert-RestoreV2 (@(Compare-Object $kitRequired @($kitChecksums.Keys | Sort-Object)).Count -eq 0) 'portable_kit_checksum_inventory_invalid'
    Assert-Checksums $kitRoot $kitChecksums $kitRequired
    try {
        $containerLock = Get-Content -LiteralPath (Join-Path $kitRoot 'container-lock.json') -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
        $migrationInfo = Get-Content -LiteralPath (Join-Path $kitRoot 'migration-info.json') -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
        $kitModelManifest = Get-Content -LiteralPath (Join-Path $kitRoot 'ml-artifact-manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
    }
    catch { Stop-RestoreV2 'portable_kit_json_invalid' }
    Assert-RestoreV2 ($kitManifest.source_head -eq $manifest.source_head -and $kitManifest.source_branch -eq $manifest.source_branch -and
        $kitManifest.recovery_phrase_stored -eq $false -and $kitManifest.plaintext_secret_payload_stored -eq $false -and
        $kitManifest.envelope.protection -eq 'GPG_SYMMETRIC_AES256' -and $kitManifest.envelope.depends_on_windows_profile -eq $false) 'portable_kit_policy_invalid'
    Assert-RestoreV2 ($containerLock.format -eq 'owner-portable-container-lock-v1' -and [int]$containerLock.version -eq 1 -and
        $containerLock.backup_id -eq $manifest.backup_id -and
        @(Compare-Object @($expectedImages.Keys | Sort-Object) @($containerLock.images.PSObject.Properties.Name | Sort-Object)).Count -eq 0) 'portable_container_lock_invalid'
    foreach ($property in $expectedImages.GetEnumerator()) {
        Assert-RestoreV2 ([string]$containerLock.images.($property.Key) -ceq [string]$property.Value -and
            [string]$containerLock.images.($property.Key) -ceq [string]$manifest.container_images.($property.Key)) 'portable_container_lock_mismatch'
    }
    $expectedRestoreOrder = @('MARIADB','PIWIGO_POSIX_STATE','CANONICAL_MEDIA','IMMICH_POSTGRES','IMMICH_UPLOAD','RESTORE_SECRETS','VALIDATE')
    Assert-RestoreV2 ($migrationInfo.format -eq 'owner-portable-migration-info-v1' -and [int]$migrationInfo.version -eq 1 -and
        $migrationInfo.backup_id -eq $manifest.backup_id -and [int]$migrationInfo.schema_versions.class_identity -eq 16 -and
        $migrationInfo.schema_versions.piwigo -eq '16.4.0' -and $migrationInfo.schema_versions.immich -eq '3.1.0' -and
        [string]::Join('|',@($migrationInfo.restore_order)) -ceq [string]::Join('|',$expectedRestoreOrder)) 'portable_migration_info_invalid'
    Assert-RestoreV2 ($null -ne $kitModelManifest) 'portable_model_manifest_invalid'
    $businessModelHash = (Get-FileHash -LiteralPath (Join-Path $bundle 'business-state\ml-artifact-manifest.json') -Algorithm SHA256).Hash
    $kitModelHash = (Get-FileHash -LiteralPath (Join-Path $kitRoot 'ml-artifact-manifest.json') -Algorithm SHA256).Hash
    Assert-RestoreV2 ([string]::Equals($businessModelHash,$kitModelHash,[StringComparison]::OrdinalIgnoreCase)) 'portable_kit_model_manifest_mismatch'
    foreach ($pair in @(
        @('business-state\immich-upstream.lock.json','infra\immich-spike\immich-upstream.lock.json'),
        @('business-state\ml-artifact-manifest.json','infra\immich-spike\ml-artifacts\manifest.json')
    )) {
        $bundleDigest = (Get-FileHash -LiteralPath (Join-Path $bundle $pair[0]) -Algorithm SHA256).Hash.ToLowerInvariant()
        $trackedDigest = (Get-FileHash -LiteralPath (Join-Path $projectRoot $pair[1]) -Algorithm SHA256).Hash.ToLowerInvariant()
        Assert-RestoreV2 (Test-FixedAsciiEqual $bundleDigest $trackedDigest) 'bundle_supply_chain_contract_mismatch'
    }

    $head = @(& git -C $projectRoot rev-parse --verify HEAD 2>$null)
    $status = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    Assert-RestoreV2 ($LASTEXITCODE -eq 0 -and $head.Count -eq 1 -and [string]$head[0] -match '\A[0-9a-f]{40}\z' -and $status.Count -eq 0) 'restore_checkout_untrusted'
    & git -C $projectRoot merge-base --is-ancestor ([string]$manifest.source_head) ([string]$head[0]) 2>$null
    Assert-RestoreV2 ($LASTEXITCODE -eq 0) 'restore_checkout_not_source_descendant'

    return [pscustomobject]@{ bundle=$bundle; manifest=$manifest; kit=$kitManifest; restore_tool_head=[string]$head[0] }
}

function Get-PhysicalDiskIndex([string]$DriveId) {
    $logical = Get-CimInstance Win32_LogicalDisk -Filter ("DeviceID='" + $DriveId + "'") -ErrorAction Stop
    $partitions = @(Get-CimAssociatedInstance -InputObject $logical -Association Win32_LogicalDiskToPartition -ErrorAction Stop)
    Assert-RestoreV2 ($partitions.Count -eq 1) 'physical_disk_partition_ambiguous'
    $disks = @(Get-CimAssociatedInstance -InputObject $partitions[0] -Association Win32_DiskDriveToDiskPartition -ErrorAction Stop)
    Assert-RestoreV2 ($disks.Count -eq 1 -and [int]$disks[0].Index -ge 0) 'physical_disk_identity_ambiguous'
    return [int]$disks[0].Index
}

function Assert-HostCapabilities {
    $script:stage = 'host_capabilities'
    Assert-RestoreV2 (Test-Path -LiteralPath $wsl -PathType Leaf) 'wsl_unavailable'
    $uid = @(Invoke-Ubuntu @('id','-u') 'wsl_user_check_failed')
    Assert-RestoreV2 ($uid.Count -eq 1 -and $uid[0] -eq '0') 'wsl_root_required'
    foreach ($tool in @('losetup','mkfs.ext4','blkid','mount','findmnt','gpg','tar')) {
        $resolved = @(Invoke-Ubuntu @('sh','-eu','-c','command -v "$1"','sh',$tool) 'host_tooling_unavailable')
        Assert-RestoreV2 ($resolved.Count -eq 1 -and $resolved[0] -match '\A/') 'host_tooling_path_invalid'
    }
    $dockerRoot = @(Invoke-RestoreDocker @('info','--format','{{.DockerRootDir}}') 'primary_docker_unavailable')
    Assert-RestoreV2 ($dockerRoot.Count -eq 1 -and $dockerRoot[0] -eq '/var/lib/docker') 'primary_docker_root_changed'
    $c = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'" -ErrorAction Stop
    $m = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='M:'" -ErrorAction Stop
    Assert-RestoreV2 ([string]::Equals([string]$c.FileSystem,'NTFS',[StringComparison]::OrdinalIgnoreCase) -and
        [string]::Equals([string]$m.FileSystem,'exFAT',[StringComparison]::OrdinalIgnoreCase)) 'recovery_filesystem_contract_invalid'
    Assert-RestoreV2 ((Get-PhysicalDiskIndex 'C:') -ne (Get-PhysicalDiskIndex 'M:')) 'recovery_media_not_physically_independent'
}

function Assert-PortsFree {
    foreach ($port in @(8390,8391)) {
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$port)
        try { $listener.Start() } catch { Stop-RestoreV2 'restore_port_in_use' } finally { $listener.Stop() }
    }
}

function Get-ProtectedRuntimeFingerprint {
    $containers = @(Invoke-RestoreDocker @('ps','-a','--no-trunc','--format','{{.ID}}|{{.Names}}|{{.Image}}'))
    $protected = @($containers | Where-Object { $_ -notmatch ('\|' + [regex]::Escape($piwigoProject) + '-|\|' + [regex]::Escape($immichProject) + '-') } | Sort-Object)
    Assert-RestoreV2 ($protected.Count -gt 0) 'protected_runtime_inventory_empty'
    $parts = foreach ($line in $protected) {
        Assert-RestoreV2 ($line -match '\A([0-9a-f]{64})\|([^|]+)\|(.+)\z') 'protected_runtime_inventory_invalid'
        $inspect = @(Invoke-RestoreDocker @('inspect','--format','{{.Id}}|{{.State.Running}}|{{.State.StartedAt}}|{{json .Mounts}}|{{json .NetworkSettings.Networks}}',$Matches[1]))
        Assert-RestoreV2 ($inspect.Count -eq 1) 'protected_runtime_inspect_invalid'
        $inspect[0]
    }
    $bytes = [Text.Encoding]::UTF8.GetBytes(($parts -join "`n"))
    try { return [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant() }
    finally { [Array]::Clear($bytes,0,$bytes.Length) }
}

function Assert-FreshRestoreRuntime {
    $containers = @(Invoke-RestoreDocker @('ps','-a','--format','{{.Names}}'))
    $volumes = @(Invoke-RestoreDocker @('volume','ls','--quiet'))
    $networks = @(Invoke-RestoreDocker @('network','ls','--format','{{.Name}}'))
    foreach ($set in @($containers,$volumes,$networks)) {
        Assert-RestoreV2 (@($set | Where-Object { $_ -like 'class_archive_owner_restore_v2_*' }).Count -eq 0) 'restore_runtime_not_fresh'
    }
    foreach ($kind in @('container','volume','network')) {
        $args = if ($kind -eq 'container') { @('ps','-a','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Names}}') }
            else { @($kind,'ls','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Name}}') }
        Assert-RestoreV2 (@(Invoke-RestoreDocker $args).Count -eq 0) 'restore_scoped_object_not_fresh'
    }
}

function Get-Ipv4CidrRange([string]$Cidr) {
    if ($Cidr -notmatch '\A([0-9]{1,3}(?:\.[0-9]{1,3}){3})/([0-9]|[12][0-9]|3[0-2])\z') { return $null }
    try { $bytes = [Net.IPAddress]::Parse($Matches[1]).GetAddressBytes() } catch { return $null }
    if ($bytes.Length -ne 4) { return $null }
    $prefix = [int]$Matches[2]
    $value = ([uint64]$bytes[0] -shl 24) -bor ([uint64]$bytes[1] -shl 16) -bor ([uint64]$bytes[2] -shl 8) -bor [uint64]$bytes[3]
    $size = [uint64]1 -shl (32 - $prefix)
    $start = [uint64]([Math]::Floor($value / $size) * $size)
    return @($start,($start + $size - 1))
}

function Assert-RestoreNetworkRangesFree {
    $candidates = @('10.246.0.0/24','10.246.1.0/24','10.246.2.0/24','10.246.3.0/24','10.246.4.0/24')
    $networkScript = 'docker --host "$1" network ls -q | xargs -r docker --host "$1" network inspect --format ''{{range .IPAM.Config}}{{println .Subnet}}{{end}}''; ip -o -4 route show | awk ''$1 ~ /^[0-9]+\./ && $1 ~ /\// {print $1}'''
    $existing = @(Invoke-Ubuntu @('sh','-eu','-c',$networkScript,'sh',$dockerHost) 'restore_network_inventory_failed' | Where-Object { $_ -match '\A[0-9.]+/[0-9]+\z' })
    foreach ($candidate in $candidates) {
        $left = @(Get-Ipv4CidrRange $candidate)
        Assert-RestoreV2 ($left.Count -eq 2) 'restore_candidate_subnet_invalid'
        foreach ($cidr in $existing) {
            $used = @(Get-Ipv4CidrRange $cidr)
            if ($used.Count -eq 2) { Assert-RestoreV2 ($left[1] -lt $used[0] -or $used[1] -lt $left[0]) 'restore_subnet_not_free' }
        }
    }
}

function Mount-RestoreStorage([bool]$AllowCreate) {
    $script:stage = 'restore_storage'
    if (-not (Test-Path -LiteralPath $runtimeImage -PathType Leaf)) {
        Assert-RestoreV2 ($AllowCreate -and $ConfirmCreateRestoreStorage.IsPresent) 'restore_image_creation_not_confirmed'
        $runtimeDirectory = Split-Path -Parent $runtimeImage
        if (-not (Test-Path -LiteralPath $runtimeDirectory -PathType Container)) { [void][IO.Directory]::CreateDirectory($runtimeDirectory) }
        Assert-PlainDirectory $runtimeDirectory 'restore_runtime_directory_untrusted'
        $m = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='M:'" -ErrorAction Stop
        $bytes = [uint64]$RuntimeImageSizeGiB * [uint64]1GB
        Assert-RestoreV2 ([uint64]$m.FreeSpace -gt ($bytes + [uint64]10GB)) 'restore_image_space_insufficient'
        $imageWsl = Get-WslPath $runtimeImage
        [void](Invoke-Ubuntu @('sh','-eu','-c','if ! fallocate -l "$1" "$2"; then truncate -s "$1" "$2"; fi; test "$(stat -c %s "$2")" = "$3"','sh',($RuntimeImageSizeGiB.ToString() + 'G'),$imageWsl,[string]$bytes) 'restore_image_allocate_failed')
    }
    Assert-PlainFile $runtimeImage 'restore_image_untrusted'
    $imageWsl = Get-WslPath $runtimeImage
    $type = @(Invoke-Ubuntu @('sh','-eu','-c','blkid -p -s TYPE -o value "$1" 2>/dev/null || true','sh',$imageWsl) 'restore_image_probe_failed')
    Assert-RestoreV2 ($type.Count -le 1) 'restore_image_type_ambiguous'
    if ($type.Count -eq 0 -or [string]::IsNullOrWhiteSpace($type[0])) {
        Assert-RestoreV2 ($AllowCreate -and $ConfirmCreateRestoreStorage.IsPresent -and
            [uint64](Get-Item -LiteralPath $runtimeImage).Length -eq ([uint64]$RuntimeImageSizeGiB * [uint64]1GB)) 'restore_unformatted_image_not_confirmed'
        [void](Invoke-Ubuntu @('sh','-eu','-c','exec mkfs.ext4 -F -L CLASSARCHIVE_V2 "$1"','sh',$imageWsl) 'restore_image_format_failed')
    }
    else { Assert-RestoreV2 ($type[0] -eq 'ext4') 'restore_image_filesystem_invalid' }
    $loop = @(Invoke-Ubuntu @('sh','-eu','-c','existing=$(losetup -j "$1" | sed -n "1s/:.*//p"); if [ -n "$existing" ]; then printf "%s" "$existing"; else losetup --find --show --nooverlap "$1"; fi','sh',$imageWsl) 'loop_attach_failed' | Where-Object { $_ -match '\A/dev/loop[0-9]+\z' })
    Assert-RestoreV2 ($loop.Count -eq 1) 'loop_device_invalid'
    [void](Invoke-Ubuntu @('sh','-eu','-c','mkdir -p "$1"; if ! mountpoint -q "$1"; then mount -t ext4 -o nodev,nosuid "$2" "$1"; fi; test "$(findmnt -n -o SOURCE -T "$1")" = "$2"; test "$(findmnt -n -o FSTYPE -T "$1")" = ext4; test "$(blkid -s LABEL -o value "$2")" = CLASSARCHIVE_V2; install -d -m 0755 "$1/volumes"','sh',$mountPoint,$loop[0]) 'restore_mount_failed')
}

function Get-RestoreVolumeSpecs {
    return @(
        @('class_archive_owner_restore_v2_piwigo_data',$piwigoProject,'piwigo_data'),
        @('class_archive_owner_restore_v2_piwigo_uploads',$piwigoProject,'piwigo_uploads'),
        @('class_archive_owner_restore_v2_piwigo_galleries',$piwigoProject,'piwigo_galleries'),
        @('class_archive_owner_restore_v2_piwigo_derivatives',$piwigoProject,'piwigo_derivatives'),
        @('class_archive_owner_restore_v2_piwigo_db',$piwigoProject,'piwigo_db'),
        @('class_archive_owner_restore_v2_piwigo_scripts',$piwigoProject,'piwigo_scripts'),
        @('class_archive_owner_restore_v2_piwigo_backups',$piwigoProject,'backups'),
        @('class_archive_owner_restore_v2_immich_upload',$immichProject,'immich_upload'),
        @('class_archive_owner_restore_v2_immich_model_cache',$immichProject,'immich_model_cache'),
        @('class_archive_owner_restore_v2_immich_db',$immichProject,'immich_db'),
        @('class_archive_owner_restore_v2_immich_gateway_secret',$immichProject,'immich_gateway_secret')
    )
}

function Assert-RestoreVolumeIdentity([string]$Name, [string]$Project, [string]$Logical) {
    $device = $restoreVolumeRoot + '/' + $Name
    $identity = @(Invoke-RestoreDocker @('volume','inspect','--format','{{.Driver}}|{{index .Options "type"}}|{{index .Options "o"}}|{{index .Options "device"}}|{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.volume"}}|{{index .Labels "com.classarchive.scope"}}|{{index .Labels "com.classarchive.storage"}}',$Name))
    $expected = 'local|none|bind|' + $device + '|' + $Project + '|' + $Logical + '|' + $scopeLabel + '|' + $storageLabel
    Assert-RestoreV2 ($identity.Count -eq 1 -and $identity[0] -eq $expected) 'restore_volume_identity_invalid'
    [void](Invoke-Ubuntu @('sh','-eu','-c','test -d "$1"; test ! -L "$1"; test "$(findmnt -n -o TARGET -T "$1")" = "$2"; test "$(findmnt -n -o FSTYPE -T "$1")" = ext4; loop=$(findmnt -n -o SOURCE -T "$1"); test "$(blkid -s LABEL -o value "$loop")" = CLASSARCHIVE_V2','sh',$device,$mountPoint) 'restore_volume_backing_invalid')
}

function New-RestoreVolume([string]$Name, [string]$Project, [string]$Logical) {
    Assert-RestoreV2 (@(Invoke-RestoreDocker @('volume','ls','-q','--filter',('name=^' + $Name + '$'))).Count -eq 0) 'restore_volume_not_fresh'
    $device = $restoreVolumeRoot + '/' + $Name
    [void](Invoke-Ubuntu @('sh','-eu','-c','case "$1" in /mnt/classarchive-owner-restore-v2/volumes/class_archive_owner_restore_v2_*) ;; *) exit 71;; esac; install -d -m 0755 "$1"; test ! -L "$1"; test "$(findmnt -n -o TARGET -T "$1")" = "$2"; test -z "$(find "$1" -mindepth 1 -print -quit)"','sh',$device,$mountPoint) 'restore_volume_backing_directory_invalid')
    [void](Invoke-RestoreDocker @('volume','create','--driver','local','--opt','type=none','--opt','o=bind','--opt',('device=' + $device),
        '--label',('com.docker.compose.project=' + $Project),'--label',('com.docker.compose.volume=' + $Logical),
        '--label',('com.classarchive.scope=' + $scopeLabel),'--label',('com.classarchive.storage=' + $storageLabel),$Name))
    Assert-RestoreVolumeIdentity $Name $Project $Logical
}

function Assert-AllRestoreVolumeIdentities {
    foreach ($spec in Get-RestoreVolumeSpecs) { Assert-RestoreVolumeIdentity $spec[0] $spec[1] $spec[2] }
}

function Copy-PinnedImages([object]$Manifest) {
    $refs = @(
        [string]$Manifest.container_images.piwigo,[string]$Manifest.container_images.mariadb,
        [string]$Manifest.container_images.immich_server,[string]$Manifest.container_images.immich_machine_learning,
        [string]$Manifest.container_images.postgres,
        'docker.io/valkey/valkey:9@sha256:8e8d64b405ce18f41b8e5ee20aa4687a8ed0022d1298f2ce31cdcf3a76e09411'
    ) | Sort-Object -Unique
    foreach ($ref in $refs) {
        Assert-RestoreV2 ($ref -match '\A[A-Za-z0-9._/-]+(?::[A-Za-z0-9._-]+)?@sha256:[0-9a-f]{64}\z') 'container_image_ref_invalid'
        [void](Invoke-RestoreDocker @('image','inspect',$ref) 'pinned_restore_image_missing')
    }
}

function New-RandomSecret {
    $bytes = New-Object byte[] 48
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes); return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+','-').Replace('/','_') }
    finally { $rng.Dispose(); [Array]::Clear($bytes,0,$bytes.Length) }
}

function Read-PortableRecoverySecrets([object]$BundleInfo) {
    $script:stage = 'portable_envelope'
    $runRoot = Join-Path $privateRuntimeRoot ([string]$BundleInfo.manifest.backup_id)
    if (-not (Test-Path -LiteralPath $runRoot -PathType Container)) { [void][IO.Directory]::CreateDirectory($runRoot) }
    Assert-PlainDirectory $runRoot 'portable_runtime_directory_untrusted'
    $secure = Read-ClassArchivePortableRecoveryPhrase
    try {
        return Read-ClassArchivePortableRecoveryEnvelope -BackupId ([string]$BundleInfo.manifest.backup_id) `
            -EnvelopePath (Join-Path $BundleInfo.bundle 'recovery-kit\portable-key-envelope.gpg') `
            -SecretRoot $runRoot -PortablePhrase $secure -Wsl $wsl -HelperPath $portableHelper
    }
    finally {
        if ($secure -is [IDisposable]) { $secure.Dispose() }
        $secure = $null
    }
}

function Initialize-RestoreEnvironments([hashtable]$Secrets) {
    $rootPassword = New-RandomSecret
    $immichPassword = New-RandomSecret
    try {
        $piwigo = @(
            ('COMPOSE_PROJECT_NAME=' + $piwigoProject),'CLASS_ARCHIVE_HTTP_PORT=8390','CLASS_ARCHIVE_COMPAT_HTTP_PORT=8391',
            ('CLASS_ARCHIVE_GATEWAY_NETWORK=' + $gatewayNetwork),'CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:8390',
            'CLASS_ARCHIVE_RESTORE_NGINX_CONFIG=../.codex-work/owner-restore-v2/runtime/nginx.conf',
            'CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai','PIWIGO_UID=1000','PIWIGO_GID=1000',
            'PIWIGO_DATA_VOLUME=class_archive_owner_restore_v2_piwigo_data','PIWIGO_UPLOADS_VOLUME=class_archive_owner_restore_v2_piwigo_uploads',
            'PIWIGO_GALLERIES_VOLUME=class_archive_owner_restore_v2_piwigo_galleries','PIWIGO_DERIVATIVES_VOLUME=class_archive_owner_restore_v2_piwigo_derivatives',
            'PIWIGO_DB_VOLUME=class_archive_owner_restore_v2_piwigo_db','PIWIGO_SCRIPTS_VOLUME=class_archive_owner_restore_v2_piwigo_scripts',
            'PIWIGO_BACKUPS_VOLUME=class_archive_owner_restore_v2_piwigo_backups',
            'PIWIGO_IMAGE=piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84',
            'MARIADB_IMAGE=mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf',
            'DB_NAME=piwigo','DB_USER=piwigo',('DB_PASSWORD=' + $Secrets.piwigo_db_password),('DB_ROOT_PASSWORD=' + $rootPassword),
            'PIWIGO_ADMIN_USERNAME=owner-restore-v2-admin','PIWIGO_ADMIN_EMAIL=admin@owner-restore-v2.invalid',
            ('CLASS_ARCHIVE_CLAIM_CODE_PEPPER=' + $Secrets.claim_code_pepper),
            ('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=' + $Secrets.anonymous_pseudonym_secret),
            'SMTP_HOST=','SMTP_PORT=','SMTP_USERNAME=','SMTP_PASSWORD=','SMTP_ENCRYPTION='
        ) -join "`n"
        $immich = @(
            ('IMMICH_COMPOSE_PROJECT_NAME=' + $immichProject),'CLASS_ARCHIVE_COMPAT_HTTP_PORT=8391','CLASS_ARCHIVE_CORE_PUBLIC_PORT=8390',
            'IMMICH_SPIKE_ENV_FILE=../owner-restore-v2/.env.immich',('CLASS_ARCHIVE_GATEWAY_NETWORK=' + $gatewayNetwork),
            'IMMICH_UPLOAD_VOLUME=class_archive_owner_restore_v2_immich_upload','IMMICH_MODEL_CACHE_VOLUME=class_archive_owner_restore_v2_immich_model_cache',
            'IMMICH_DB_VOLUME=class_archive_owner_restore_v2_immich_db','IMMICH_GATEWAY_SECRET_VOLUME=class_archive_owner_restore_v2_immich_gateway_secret',
            'PIWIGO_UPLOADS_VOLUME=class_archive_owner_restore_v2_piwigo_uploads','PIWIGO_GALLERIES_VOLUME=class_archive_owner_restore_v2_piwigo_galleries',
            ('DB_PASSWORD=' + $immichPassword),'DB_USERNAME=postgres','DB_DATABASE_NAME=immich','TZ=Asia/Shanghai'
        ) -join "`n"
        Write-OwnerOnlyText $piwigoEnvPath ($piwigo + "`n")
        Write-OwnerOnlyText $immichEnvPath ($immich + "`n")
    }
    finally { $rootPassword=$null; $immichPassword=$null; $piwigo=$null; $immich=$null }
}

function New-RestoreNginxConfiguration {
    $sourcePath = Join-Path $projectRoot 'infra\piwigo-nginx\nginx.conf'
    Assert-PlainFile $sourcePath 'restore_nginx_source_untrusted'
    $source = [IO.File]::ReadAllText($sourcePath,[Text.Encoding]::UTF8)
    $anchor = '        set_real_ip_from 10.241.0.10/32;'
    Assert-RestoreV2 (([regex]::Matches($source,[regex]::Escape($anchor))).Count -eq 1 -and -not $source.Contains('set_real_ip_from 10.246.0.10/32;')) 'restore_nginx_trust_anchor_invalid'
    $generated = $source.Replace($anchor,$anchor + "`n        # Independent v2 restore compatibility BFF.`n        set_real_ip_from 10.246.0.10/32;")
    Assert-RestoreV2 (([regex]::Matches($generated,'set_real_ip_from 10\.246\.0\.10/32;')).Count -eq 1) 'restore_nginx_generation_failed'
    Write-OwnerOnlyText $restoreNginxPath $generated
}

function Initialize-RestoreGitEvidence([object]$BundleInfo) {
    $evidenceRoot = Join-Path $privateRuntimeRoot 'git-evidence'
    $refs = Join-Path $evidenceRoot 'refs'
    if (-not (Test-Path -LiteralPath $evidenceRoot -PathType Container)) { [void][IO.Directory]::CreateDirectory($evidenceRoot) }
    if (-not (Test-Path -LiteralPath $refs -PathType Container)) { [void][IO.Directory]::CreateDirectory($refs) }
    Assert-PlainDirectory $evidenceRoot 'restore_git_evidence_root_untrusted'
    Assert-PlainDirectory $refs 'restore_git_evidence_refs_untrusted'
    Assert-RestoreV2 (@(Get-ChildItem -LiteralPath $refs -Force).Count -eq 0) 'restore_git_evidence_refs_not_empty'
    # BuildCommit must describe the code mounted from this checkout. The
    # immutable backup source commit is recorded separately in restore state.
    $headPath = Join-Path $evidenceRoot 'HEAD'
    $expected = [string]$BundleInfo.restore_tool_head + "`n"
    if (Test-Path -LiteralPath $headPath) {
        Assert-PlainFile $headPath 'restore_git_evidence_head_untrusted'
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $headPath
        Assert-RestoreV2 ([string]::Equals([IO.File]::ReadAllText($headPath),$expected,[StringComparison]::Ordinal)) 'restore_git_evidence_head_mismatch'
    }
    else { Write-OwnerOnlyText $headPath $expected }
}

function Copy-VerifiedModelCache([object]$BundleInfo) {
    $script:stage = 'model_cache'
    $allowed = Join-Path $sourceRoot 'rebuildable\immich-model-cache'
    $requested = if ([string]::IsNullOrWhiteSpace($VerifiedModelCacheDirectory)) { $allowed } else { $VerifiedModelCacheDirectory }
    $source = [IO.Path]::GetFullPath($requested).TrimEnd('\')
    Assert-RestoreV2 ([string]::Equals($source,$allowed,[StringComparison]::OrdinalIgnoreCase)) 'model_cache_source_path_invalid'
    Assert-DirectoryChain $sourceRoot $source
    Assert-RestoreV2 (@(Get-ChildItem -LiteralPath $source -Recurse -Force | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count -eq 0) 'model_cache_reparse_forbidden'
    $sourceManifest = Join-Path $source 'class-archive-model-manifest.json'
    Assert-PlainFile $sourceManifest 'model_cache_manifest_missing'
    $expected = (Get-FileHash -LiteralPath (Join-Path $BundleInfo.bundle 'recovery-kit\ml-artifact-manifest.json') -Algorithm SHA256).Hash.ToLowerInvariant()
    $actual = (Get-FileHash -LiteralPath $sourceManifest -Algorithm SHA256).Hash.ToLowerInvariant()
    Assert-RestoreV2 (Test-FixedAsciiEqual $actual $expected) 'model_cache_manifest_mismatch'
    $target = 'class_archive_owner_restore_v2_immich_model_cache'
    Assert-RestoreVolumeIdentity $target $immichProject 'immich_model_cache'
    $mlImage = [string]$BundleInfo.manifest.container_images.immich_machine_learning
    $copy = @'
set -o pipefail
test -d "$1" && test ! -L "$1"
tar --numeric-owner --acls --xattrs --xattrs-include="*" -C "$1" -cf - . |
docker --host "$2" run --rm -i --network none --read-only --cap-drop ALL --cap-add CHOWN --cap-add FOWNER --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true --entrypoint sh -v "$3:/target" "$4" -eu -c 'test -z "$(find /target -mindepth 1 -print -quit)"; exec tar --numeric-owner --same-owner --same-permissions --acls --xattrs --xattrs-include="*" -C /target -xf -'
'@
    [void](Invoke-Ubuntu @('bash','-c',$copy,'bash',(Get-WslPath $source),$dockerHost,$target,$mlImage) 'model_cache_copy_failed')
    $result = @(Invoke-RestoreDocker @('run','--rm','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true','--entrypoint','sha256sum','-v',($target + ':/cache:ro'),$mlImage,'/cache/class-archive-model-manifest.json'))
    Assert-RestoreV2 ($result.Count -eq 1 -and $result[0] -match '\A([0-9a-f]{64})  /cache/class-archive-model-manifest\.json\z' -and (Test-FixedAsciiEqual $Matches[1] $expected)) 'target_model_cache_manifest_mismatch'
}

function Invoke-StreamHelper([string]$Mode, [object]$BundleInfo, [string]$PassphrasePath, [switch]$NeedsPiwigoEnv) {
    $arguments = @((Get-WslPath $streamHelper),$Mode,'--source-root',(Get-WslPath $sourceRoot),'--bundle',(Get-WslPath $BundleInfo.bundle),'--passphrase-file',(Get-WslPath $PassphrasePath))
    if ($NeedsPiwigoEnv) { $arguments += @('--piwigo-env',(Get-WslPath $piwigoEnvPath)) }
    $lines = @(Invoke-Ubuntu (@('bash') + $arguments) 'restore_stream_failed')
    Assert-RestoreV2 ($lines.Count -eq 1 -and $lines[0] -eq ('OWNER_RESTORE_V2_STREAM=PASS action=' + $Mode)) 'restore_stream_output_invalid'
}

function Wait-RestoreContainer([string]$Name, [int]$Seconds=300) {
    for ($attempt=0; $attempt -lt $Seconds; $attempt++) {
        $state = @(Invoke-Ubuntu @('sh','-c','docker --host "$1" inspect --format ''{{.State.Running}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}'' "$2" 2>/dev/null || true','sh',$dockerHost,$Name))
        if ($state -contains 'true|healthy' -or $state -contains 'true|none') { return }
        Start-Sleep -Seconds 1
    }
    Stop-RestoreV2 'restore_container_health_timeout'
}

function Set-RestoreMaintenanceMarker([object]$BundleInfo) {
    $volume = 'class_archive_owner_restore_v2_piwigo_data'
    Assert-RestoreVolumeIdentity $volume $piwigoProject 'piwigo_data'
    $image = [string]$BundleInfo.manifest.container_images.mariadb
    [void](Invoke-RestoreDocker @('run','--rm','--network','none','--read-only','--cap-drop','ALL','--cap-add','CHOWN','--cap-add','FOWNER','--cap-add','DAC_OVERRIDE',
        '--security-opt','no-new-privileges:true','--entrypoint','sh','-v',($volume + ':/target'),$image,'-eu','-c',
        'install -d -o 1000 -g 1000 -m 0770 /target/_data; : > /target/_data/.class-archive-maintenance; chown 1000:1000 /target/_data/.class-archive-maintenance; chmod 0660 /target/_data/.class-archive-maintenance'))
}

function Invoke-PrivateImmichFinish {
    $oldDockerHost = $env:DOCKER_HOST
    $oldWslEnv = $env:WSLENV
    try {
        $env:DOCKER_HOST = $dockerHost
        $env:WSLENV = if ([string]::IsNullOrWhiteSpace($oldWslEnv)) { 'DOCKER_HOST/u' } elseif ($oldWslEnv -match '(^|:)DOCKER_HOST(?:/u)?(:|$)') { $oldWslEnv } else { $oldWslEnv + ':DOCKER_HOST/u' }
        $lines = @(& pwsh.exe -NoProfile -File (Join-Path $PSScriptRoot 'private-qa-immich.ps1') finish -Runtime restore-v2 2>&1)
        Assert-RestoreV2 ($LASTEXITCODE -eq 0 -and @($lines | Where-Object { [string]$_ -match '\APRIVATE_QA_IMMICH=PASS action=finish ' }).Count -eq 1) 'immich_finish_failed'
    }
    finally { $env:DOCKER_HOST=$oldDockerHost; $env:WSLENV=$oldWslEnv }
}

function Assert-RestoreNetworkIsolation {
    $expected = [ordered]@{
        $gatewayNetwork = @($piwigoProject,'immich_gateway','true','10.246.0.0/24')
        ($piwigoProject + '_app') = @($piwigoProject,'app','false','10.246.1.0/24')
        ($immichProject + '_immich_internal') = @($immichProject,'immich_internal','true','10.246.2.0/24')
        ($immichProject + '_immich_ml_internal') = @($immichProject,'immich_ml_internal','true','10.246.3.0/24')
        ($immichProject + '_immich_bridge_internal') = @($immichProject,'immich_bridge_internal','true','10.246.4.0/24')
    }
    $allowed = @($expected.Keys)
    foreach ($entry in $expected.GetEnumerator()) {
        $identity = @(Invoke-RestoreDocker @('network','inspect','--format','{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.network"}}|{{index .Labels "com.classarchive.scope"}}|{{.Internal}}|{{range .IPAM.Config}}{{.Subnet}}{{end}}',$entry.Key))
        $wanted = $entry.Value[0] + '|' + $entry.Value[1] + '|' + $scopeLabel + '|' + $entry.Value[2] + '|' + $entry.Value[3]
        Assert-RestoreV2 ($identity.Count -eq 1 -and $identity[0] -eq $wanted) 'restore_network_identity_invalid'
        $members = @(Invoke-RestoreDocker @('network','inspect','--format','{{range $id,$container := .Containers}}{{println $container.Name}}{{end}}',$entry.Key) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
        foreach ($member in $members) {
            $scope = @(Invoke-RestoreDocker @('inspect','--format','{{index .Config.Labels "com.classarchive.scope"}}',$member))
            Assert-RestoreV2 ($scope.Count -eq 1 -and $scope[0] -eq $scopeLabel) 'restore_network_foreign_member'
        }
    }
    $containers = @(Invoke-RestoreDocker @('ps','-a','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Names}}'))
    foreach ($container in $containers) {
        if ($container -eq ($immichProject + '-immich-gateway-secret-stager-1')) {
            $state = @(Invoke-RestoreDocker @('inspect','--format','{{.State.Status}}|{{.HostConfig.NetworkMode}}|{{json .HostConfig.PortBindings}}',$container))
            Assert-RestoreV2 ($state.Count -eq 1 -and $state[0] -in @('exited|none|null','exited|none|{}')) 'restore_secret_stager_boundary_invalid'
            continue
        }
        $attached = @(Invoke-RestoreDocker @('inspect','--format','{{range $name,$network := .NetworkSettings.Networks}}{{println $name}}{{end}}',$container) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
        Assert-RestoreV2 (@($attached | Where-Object { $_ -notin $allowed }).Count -eq 0) 'restore_container_foreign_network'
    }
    $published = @(Invoke-RestoreDocker @('ps','--filter',('label=com.classarchive.scope=' + $scopeLabel),'--format','{{.Names}}|{{.Ports}}')) -join "`n"
    Assert-RestoreV2 ($published -match '127\.0\.0\.1:8390->80/tcp' -and $published -match '127\.0\.0\.1:8391->8081/tcp') 'restore_loopback_ports_missing'
    Assert-RestoreV2 (-not ($published -match '0\.0\.0\.0|\[::\]|:2283->|:3000->|:8080->')) 'restore_internal_service_exposed'
}

function Get-RestoreCounts {
    $mariaSql = @'
set -eu
q() { mariadb --batch --skip-column-names --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" --execute "$1"; }
ci=$(q "SELECT COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$';")
case "$ci" in ''|*[!A-Za-z0-9_]*) exit 91 ;; esac
base=${ci%migration}; pwg=${base%class_identity_}; [ "$pwg" != "$base" ]
printf 'class_identity_schema_version=%s\n' "$(q "SELECT COALESCE(MAX(version),0) FROM ${base}migration;")"
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
printf 'ai_jobs_pending=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='PENDING';")"
printf 'ai_jobs_running=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='RUNNING';")"
printf 'ai_jobs_unavailable=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='UNAVAILABLE';")"
printf 'ai_jobs_failed=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='FAILED';")"
printf 'ai_jobs_cancelled=%s\n' "$(q "SELECT COUNT(*) FROM ${base}ai_index_job WHERE state='CANCELLED';")"
'@
    $lines = @(Invoke-RestoreDocker @('exec',($piwigoProject + '-db-1'),'sh','-eu','-c',$mariaSql) 'restore_mariadb_verify_failed')
    $pgSql = "SELECT 'immich_assets='||COUNT(*) FROM asset UNION ALL SELECT 'immich_face_records='||COUNT(*) FROM asset_face UNION ALL SELECT 'immich_raw_persons='||COUNT(*) FROM person UNION ALL SELECT 'immich_search_index='||COUNT(*) FROM smart_search;"
    $lines += @(Invoke-RestoreDocker @('exec','--user','postgres',($immichProject + '-database-1'),'psql','--no-psqlrc','--tuples-only','--no-align','--set','ON_ERROR_STOP=1','--dbname=immich','--command',$pgSql) 'restore_postgres_verify_failed')
    $result = @{}
    foreach ($line in $lines) {
        if ($line -match '\A([a-z_]+)=([0-9]+)\z' -and -not $result.ContainsKey($Matches[1])) { $result[[string]$Matches[1]]=[uint64]$Matches[2] }
        elseif (-not [string]::IsNullOrWhiteSpace($line)) { Stop-RestoreV2 'restore_count_output_invalid' }
    }
    return $result
}

function Assert-RestoreCounts([object]$BundleInfo) {
    $counts = Get-RestoreCounts
    Assert-RestoreV2 ($counts.class_identity_schema_version -eq 16) 'restored_schema_version_invalid'
    foreach ($property in $BundleInfo.manifest.counts.PSObject.Properties) {
        Assert-RestoreV2 ($counts.ContainsKey($property.Name) -and [uint64]$counts[$property.Name] -eq [uint64]$property.Value) 'restored_count_mismatch'
    }
    return $counts
}

function Assert-AiRestoreEvidence {
    $path = Join-Path $projectRoot '.codex-work\owner-restore-v2\reports\owner-restore-v2-immich-runtime.json'
    Assert-PlainFile $path 'restore_ai_evidence_missing'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    try { $evidence = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-RestoreV2 'restore_ai_evidence_invalid' }
    Assert-RestoreV2 ($evidence.version -eq 1 -and $evidence.scope -eq 'PRIVATE_REAL_FULL' -and $evidence.ai_index_state -eq 'READY' -and
        $evidence.media_mount -eq 'PIWIGO_ORIGINALS_READ_ONLY' -and $evidence.media_delivery -eq 'MEDIAGUARD_ONLY') 'restore_ai_evidence_contract_invalid'
    Assert-RestoreV2 ($evidence.metrics.reused_existing_indexes -eq $true -and [uint64]$evidence.metrics.face_jobs -eq 0 -and
        [uint64]$evidence.metrics.recognition_jobs -eq 0 -and [uint64]$evidence.metrics.smart_jobs -eq 0 -and [uint64]$evidence.people_count -gt 0) 'restore_ai_reindex_detected'
}

function Write-RestoreState([object]$BundleInfo,[hashtable]$Counts) {
    $state = [ordered]@{
        version=1; backup_id=[string]$BundleInfo.manifest.backup_id; source_head=[string]$BundleInfo.manifest.source_head
        restore_tool_head=[string]$BundleInfo.restore_tool_head; restored_at=(Get-Date).ToUniversalTime().ToString('o')
        source_root=$sourceRoot; runtime_image=$runtimeImage; volume_root=$restoreVolumeRoot
        piwigo_project=$piwigoProject; immich_project=$immichProject; ports=@(8390,8391)
        counts=$Counts; browser_e2e='NOT_RUN_BY_RESTORE_TOOL'
    }
    Write-OwnerOnlyText $statePath (($state | ConvertTo-Json -Depth 8 -Compress) + "`n")
}

function Read-RestoreState([object]$BundleInfo) {
    Assert-PlainFile $statePath 'restore_state_missing'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $statePath
    try { $state=Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-RestoreV2 'restore_state_invalid' }
    Assert-RestoreV2 ([int]$state.version -eq 1 -and [string]$state.backup_id -eq [string]$BundleInfo.manifest.backup_id -and
        [string]$state.source_head -eq [string]$BundleInfo.manifest.source_head -and [string]$state.restore_tool_head -eq [string]$BundleInfo.restore_tool_head -and
        [string]$state.source_root -eq $sourceRoot -and [string]$state.runtime_image -eq $runtimeImage -and
        [string]$state.piwigo_project -eq $piwigoProject -and [string]$state.immich_project -eq $immichProject -and
        @($state.ports).Count -eq 2 -and [int]$state.ports[0] -eq 8390 -and [int]$state.ports[1] -eq 8391) 'restore_state_identity_invalid'
    return $state
}

function Assert-MaintenanceHttp {
    for ($attempt=0; $attempt -lt 30; $attempt++) {
        $status=0; $body=''
        try {
            $response=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8390/identification.php' -MaximumRedirection 0 -TimeoutSec 5 -ErrorAction Stop
            $status=[int]$response.StatusCode; $body=[string]$response.Content
        }
        catch {
            if ($null -ne $_.Exception.Response) {
                $status=[int]$_.Exception.Response.StatusCode
                try { $body=[IO.StreamReader]::new($_.Exception.Response.GetResponseStream()).ReadToEnd() } catch { }
            }
        }
        if ($status -eq 503 -and $body -match 'Class Archive maintenance mode') {
            Assert-RestoreV2 $true 'restore_maintenance_not_fail_closed'
            return
        }
        Start-Sleep -Seconds 1
    }
    Assert-RestoreV2 $false 'restore_maintenance_not_fail_closed'
}

function Assert-RestoreMediaGuard {
    $script:stage='mediaguard_verify'
    $media=@(Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_PRIVATE_FULL_OWNER_MEDIA_HTTP=1','piwigo','php','/workspace/tests/phase3/private-full-owner-media-http.php'))
    Assert-RestoreV2 (@($media | Where-Object { $_ -match '\APRIVATE_FULL_OWNER_MEDIA_HTTP=PASS .*direct_guest_requests=6 ' }).Count -eq 1) 'restore_mediaguard_probe_failed'
}

function Invoke-PreReleaseVerify([object]$BundleInfo) {
    $script:stage='pre_release_verify'
    $counts=Assert-RestoreCounts $BundleInfo
    Assert-AiRestoreEvidence
    Assert-RestoreNetworkIsolation
    $mode=@(Invoke-RestoreCompose piwigo @('exec','-T','piwigo','sh','-eu','-c','find /var/www/html/piwigo/upload /var/www/html/piwigo/galleries -type f ! -perm 0660 -print -quit'))
    Assert-RestoreV2 ([string]::IsNullOrWhiteSpace(($mode -join ''))) 'restored_original_mode_invalid'
    $post=@(Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_OWNER_RESTORE_VERIFY=1','piwigo','php','/workspace/infra/scripts/verify-owner-restore-post-migration.php'))
    Assert-RestoreV2 (@($post | Where-Object { $_ -match '\AOWNER_RESTORE_POST_MIGRATION=PASS schema=16 reconciliation=PASS ' }).Count -eq 1) 'restore_business_verification_failed'
    Assert-MaintenanceHttp
    return $counts
}

function Invoke-AggregateVerify([object]$BundleInfo) {
    $script:stage='aggregate_verify'
    $null=Read-RestoreState $BundleInfo
    Assert-AllRestoreVolumeIdentities
    foreach ($name in @(
        ($piwigoProject + '-db-1'),($piwigoProject + '-piwigo-1'),
        ($immichProject + '-database-1'),($immichProject + '-redis-1'),
        ($immichProject + '-immich-server-1'),($immichProject + '-immich-machine-learning-1'),
        ($immichProject + '-immich-gateway-1'),($immichProject + '-immich-web-compat-1')
    )) { Wait-RestoreContainer $name 120 }
    $counts=Assert-RestoreCounts $BundleInfo
    Assert-AiRestoreEvidence
    Assert-RestoreNetworkIsolation
    $mode=@(Invoke-RestoreCompose piwigo @('exec','-T','piwigo','sh','-eu','-c','find /var/www/html/piwigo/upload /var/www/html/piwigo/galleries -type f ! -perm 0660 -print -quit'))
    Assert-RestoreV2 ([string]::IsNullOrWhiteSpace(($mode -join ''))) 'restored_original_mode_invalid'
    Assert-RestoreMediaGuard
    $coreStatus=0
    try {
        $core=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8390/identification.php' -MaximumRedirection 0 -TimeoutSec 15 -ErrorAction Stop
        $coreStatus=[int]$core.StatusCode
    }
    catch { if ($null -ne $_.Exception.Response) { $coreStatus=[int]$_.Exception.Response.StatusCode } }
    $compat=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8391/healthz' -TimeoutSec 15 -ErrorAction Stop
    Assert-RestoreV2 ($coreStatus -in @(200,301,302,303) -and [int]$compat.StatusCode -eq 200) 'restore_http_health_failed'
    Write-Output ('OWNER_RESTORE_V2_VERIFY=PASS backup_id=' + $BundleInfo.manifest.backup_id + ' source_head=' + $BundleInfo.manifest.source_head +
        ' restore_tool_head=' + $BundleInfo.restore_tool_head + ' counts=' + $counts.Count +
        ' media=MEDIAGUARD_ONLY ai_results=IMMEDIATE reindex=NO browser_e2e=NOT_RUN assertions=' + $script:assertions)
    return $counts
}

function Reassert-MaintenanceAfterFailure {
    if (-not $script:maintenanceMayBeOpen) { return }
    try {
        if ((Test-Path -LiteralPath $piwigoEnvPath -PathType Leaf) -and
            @(Invoke-RestoreDocker @('ps','-q','--filter',('name=^/' + $piwigoProject + '-piwigo-1$'))).Count -eq 1) {
            [void](Invoke-RestoreCompose piwigo @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare'))
            Assert-MaintenanceHttp
        }
    }
    catch { Stop-RestoreV2 'maintenance_reassert_failed' }
}

$workflowLock=$null
$archivePassphrasePath=$null
$secrets=$null
try {
    $bundleInfo=Read-VerifiedBundle
    Assert-HostCapabilities
    Assert-PlainFile $streamHelper 'stream_helper_missing'

    if ($Action -eq 'validate') {
        Assert-PortsFree
        Assert-FreshRestoreRuntime
        Assert-RestoreNetworkRangesFree
        $c=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
        $m=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='M:'"
        Write-Output ('OWNER_RESTORE_V2_VALIDATE=PASS backup_id=' + $bundleInfo.manifest.backup_id +
            ' source=C_FIXED_NTFS target=M_EXT4_IMAGE physical_media=DIFFERENT c_free_bytes=' + [uint64]$c.FreeSpace +
            ' m_free_bytes=' + [uint64]$m.FreeSpace + ' portable_envelope=REQUIRED schema=16 assertions=' + $script:assertions)
        exit 0
    }

    $workflowLock=Enter-WorkflowLock
    $protectedBefore=Get-ProtectedRuntimeFingerprint

    if ($Action -eq 'prepare-storage') {
        Assert-PortsFree
        Assert-FreshRestoreRuntime
        Assert-RestoreNetworkRangesFree
        Mount-RestoreStorage $true
        Copy-PinnedImages $bundleInfo.manifest
        Assert-RestoreV2 ($protectedBefore -eq (Get-ProtectedRuntimeFingerprint)) 'protected_runtime_changed'
        Write-Output ('OWNER_RESTORE_V2_STORAGE=PASS image=' + $runtimeImage + ' volume_root=' + $restoreVolumeRoot +
            ' projects=FRESH ports=NONE protected_runtimes=UNCHANGED assertions=' + $script:assertions)
        exit 0
    }

    Mount-RestoreStorage $false

    if ($Action -eq 'status') {
        $null=Read-RestoreState $bundleInfo
        Assert-AllRestoreVolumeIdentities
        Assert-RestoreV2 ($protectedBefore -eq (Get-ProtectedRuntimeFingerprint)) 'protected_runtime_changed'
        Write-Output ('OWNER_RESTORE_V2_STATUS=PASS runtime=ISOLATED projects=' + $piwigoProject + ',' + $immichProject +
            ' ports=8390,8391 protected_runtimes=UNCHANGED assertions=' + $script:assertions)
        exit 0
    }

    if ($Action -eq 'restore') {
        Assert-RestoreV2 $ConfirmIsolatedRestore.IsPresent 'restore_confirmation_required'
        Assert-PortsFree
        Assert-FreshRestoreRuntime
        Assert-RestoreNetworkRangesFree
        Assert-RestoreV2 (-not (Test-Path -LiteralPath $statePath) -and -not (Test-Path -LiteralPath $piwigoEnvPath) -and
            -not (Test-Path -LiteralPath $immichEnvPath) -and -not (Test-Path -LiteralPath $restoreNginxPath)) 'restore_state_not_fresh'
        $secrets=Read-PortableRecoverySecrets $bundleInfo
        $runRoot=Join-Path $privateRuntimeRoot ([string]$bundleInfo.manifest.backup_id)
        $archivePassphrasePath=Join-Path $runRoot 'archive-passphrase.txt'
        try {
            Initialize-RestoreEnvironments $secrets
            New-RestoreNginxConfiguration
            Initialize-RestoreGitEvidence $bundleInfo
            Write-OwnerOnlyText $archivePassphrasePath ([string]$secrets.gpg_passphrase + "`n")
            Copy-PinnedImages $bundleInfo.manifest
            foreach ($spec in Get-RestoreVolumeSpecs) { New-RestoreVolume $spec[0] $spec[1] $spec[2] }
            [void](Invoke-RestoreCompose piwigo @('up','-d','db'))
            [void](Invoke-RestoreCompose piwigo @('create','--no-recreate','piwigo'))
            Wait-RestoreContainer ($piwigoProject + '-db-1')
            [void](Invoke-RestoreCompose immich @('--profile','immich-spike','up','-d','database','redis'))
            Wait-RestoreContainer ($immichProject + '-database-1')
            Invoke-StreamHelper verify $bundleInfo $archivePassphrasePath
            foreach ($mode in @('restore-piwigo-data','restore-piwigo-scripts','restore-piwigo-uploads','restore-piwigo-galleries','restore-mariadb','write-piwigo-config','restore-immich-upload','restore-immich-postgres')) {
                Invoke-StreamHelper $mode $bundleInfo $archivePassphrasePath -NeedsPiwigoEnv:($mode -eq 'write-piwigo-config')
            }
            Copy-VerifiedModelCache $bundleInfo
            Set-RestoreMaintenanceMarker $bundleInfo
            $script:maintenanceMayBeOpen=$true
            [void](Invoke-RestoreCompose piwigo @('up','-d','piwigo'))
            Wait-RestoreContainer ($piwigoProject + '-piwigo-1')
            [void](Invoke-RestoreCompose piwigo @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare'))
            Assert-MaintenanceHttp
            $projection=@(Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all'))
            Assert-RestoreV2 (@($projection | Where-Object { $_ -match '\AREAD_PROJECTION_REBUILD=PASS SCOPE=ALL ' }).Count -eq 1) 'restore_projection_rebuild_failed'
            [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','up','-d','immich-machine-learning','immich-server'))
            Wait-RestoreContainer ($immichProject + '-immich-machine-learning-1') 600
            Wait-RestoreContainer ($immichProject + '-immich-server-1') 600
            Invoke-PrivateImmichFinish
            $counts=Invoke-PreReleaseVerify $bundleInfo
            $finalize=@(Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance'))
            Assert-RestoreV2 (@($finalize | Where-Object { $_ -eq 'MAINTENANCE FINALIZED' }).Count -eq 1) 'restore_maintenance_finalize_failed'
            Assert-RestoreMediaGuard
            [void](Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat'))
            Wait-RestoreContainer ($immichProject + '-immich-web-compat-1') 300
            Write-RestoreState $bundleInfo $counts
            $null=Invoke-AggregateVerify $bundleInfo
            Assert-RestoreV2 ($protectedBefore -eq (Get-ProtectedRuntimeFingerprint)) 'protected_runtime_changed'
            $script:maintenanceMayBeOpen=$false
        }
        finally {
            $secrets=$null
            if ($null -ne $archivePassphrasePath) { Remove-PrivateTemporaryFile $archivePassphrasePath }
        }
        exit 0
    }

    if ($Action -eq 'cold-restart') {
        Assert-RestoreV2 $ConfirmColdRestart.IsPresent 'cold_restart_confirmation_required'
        $null=Read-RestoreState $bundleInfo
        $before=Get-RestoreCounts
        $script:maintenanceMayBeOpen=$true
        [void](Invoke-RestoreCompose piwigo @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare'))
        Assert-MaintenanceHttp
        [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','--profile','immich-gateway-integration','--profile','immich-web-compat','stop','-t','30'))
        [void](Invoke-RestoreCompose piwigo @('stop','-t','30'))
        [void](Invoke-RestoreCompose piwigo @('up','-d','db','piwigo'))
        [void](Invoke-RestoreCompose immich @('--profile','immich-spike','--profile','immich-ml','--profile','immich-gateway-integration','up','-d',
            'database','redis','immich-machine-learning','immich-server','immich-gateway'))
        Wait-RestoreContainer ($piwigoProject + '-piwigo-1')
        Wait-RestoreContainer ($immichProject + '-immich-server-1') 600
        Wait-RestoreContainer ($immichProject + '-immich-machine-learning-1') 600
        Wait-RestoreContainer ($immichProject + '-immich-gateway-1') 300
        Assert-MaintenanceHttp
        $after=Get-RestoreCounts
        Assert-RestoreV2 (@(Compare-Object @($before.Keys | Sort-Object) @($after.Keys | Sort-Object)).Count -eq 0) 'cold_restart_counts_changed'
        foreach ($key in $before.Keys) { Assert-RestoreV2 ([uint64]$before[$key] -eq [uint64]$after[$key]) 'cold_restart_counts_changed' }
        $null=Invoke-PreReleaseVerify $bundleInfo
        $finalize=@(Invoke-RestoreCompose piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance'))
        Assert-RestoreV2 (@($finalize | Where-Object { $_ -eq 'MAINTENANCE FINALIZED' }).Count -eq 1) 'restore_maintenance_finalize_failed'
        Assert-RestoreMediaGuard
        [void](Invoke-RestoreCompose immich @('--profile','immich-web-compat','up','-d','immich-web-compat'))
        Wait-RestoreContainer ($immichProject + '-immich-web-compat-1') 300
        $null=Invoke-AggregateVerify $bundleInfo
        Assert-RestoreV2 ($protectedBefore -eq (Get-ProtectedRuntimeFingerprint)) 'protected_runtime_changed'
        $script:maintenanceMayBeOpen=$false
        Write-Output ('OWNER_RESTORE_V2_COLD_START=PASS ai_results=IMMEDIATE reindex=NO assertions=' + $script:assertions)
        exit 0
    }

    $script:maintenanceMayBeOpen=$true
    $null=Invoke-AggregateVerify $bundleInfo
    Assert-RestoreV2 ($protectedBefore -eq (Get-ProtectedRuntimeFingerprint)) 'protected_runtime_changed'
    $script:maintenanceMayBeOpen=$false
}
catch {
    $code=if ($_.Exception.Message -match '\AOWNER_RESTORE_V2_STOP:([a-z0-9_]{1,128})\z') { [string]$Matches[1] } else { 'unexpected_failure' }
    try { Reassert-MaintenanceAfterFailure } catch { $code='maintenance_reassert_failed' }
    Write-Error ('OWNER_RESTORE_V2=FAIL action=' + $Action + ' stage=' + $script:stage + ' code=' + $code + ' maintenance=FAIL_CLOSED')
    exit 1
}
finally {
    $secrets=$null
    if ($null -ne $workflowLock) { $workflowLock.Dispose() }
}
