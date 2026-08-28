<#!
.SYNOPSIS
Stages one already-complete synthetic v16 -> v17 DB-only rollback bundle for
the isolated Phase 3.4 migration laboratory.

.DESCRIPTION
This is deliberately not a snapshot producer.  It never starts or stops a
Compose service, never executes SQL, and never touches the Piwigo database.
The only permitted source is the exact DB-only bundle made by
create-pre-migration-db-snapshot.sh in the base synthetic 8091 backup volume.

The source volume is mounted read-only into a stopped, networkless temporary
container solely so Docker can copy one verified bundle into the ignored
.codex-work/v4-synthetic-migration/input directory.  No media, owner/private
runtime, restore runtime, source-photo directory, or external path can be
selected by a caller.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'stage')]
    [string]$Action = 'validate',

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^pre-migration-db-v16-to-v17-[0-9]{8}T[0-9]{6}Z$')]
    [string]$BundleName,

    [switch]$ConfirmStage
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$sandboxRoot = Join-Path $projectRoot '.codex-work\v4-synthetic-migration'
$inputRoot = Join-Path $sandboxRoot 'input'
$baseComposeFile = 'infra/docker-compose.yml'
$baseEnvFile = '.env.piwigo'
$baseProjectName = 'class_archive_piwigo'
$baseBackupVolume = 'class_archive_piwigo_backups'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$script:temporaryContainer = $null

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-V4SnapshotStage([string]$Code) {
    throw [InvalidOperationException]::new('V4_SYNTHETIC_SNAPSHOT_STAGE_STOP:' + $Code)
}

function Write-V4SnapshotStage([string]$State, [string]$Stage, [string]$Extra = '') {
    $suffix = if ([string]::IsNullOrWhiteSpace($Extra)) { '' } else { ' ' + $Extra }
    Write-Output ('V4_SYNTHETIC_SNAPSHOT_STAGE={0} stage={1}{2}' -f $State, $Stage, $suffix)
}

function Set-V4SnapshotStageUtf8ConsoleEncoding {
    try {
        $utf8 = [Text.UTF8Encoding]::new($false)
        [Console]::OutputEncoding = $utf8
        $script:OutputEncoding = $utf8
        if ([Console]::OutputEncoding.CodePage -ne 65001) { Stop-V4SnapshotStage 'utf8_console_encoding_unavailable' }
    }
    catch {
        Stop-V4SnapshotStage 'utf8_console_encoding_unavailable'
    }
}

Set-V4SnapshotStageUtf8ConsoleEncoding

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4SnapshotStage 'path_outside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-NoReparsePoints([string]$Path, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    if ($MustExist -and -not (Test-Path -LiteralPath $full)) { Stop-V4SnapshotStage 'required_path_missing' }
    $current = if (Test-Path -LiteralPath $full) {
        Get-Item -LiteralPath $full -Force -ErrorAction Stop
    }
    else {
        Get-Item -LiteralPath (Split-Path -Parent $full) -Force -ErrorAction Stop
    }
    while ($null -ne $current) {
        if (($current.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            Stop-V4SnapshotStage 'reparse_point_forbidden'
        }
        # FileInfo has Directory rather than Parent; use the concrete type so
        # StrictMode cannot turn a file-boundary check into an unexpected
        # property exception before any Docker or Compose operation occurs.
        $current = if ($current -is [IO.DirectoryInfo]) { $current.Parent } else { $current.Directory }
    }
}

function Assert-SandboxPath([string]$Path, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    $rootPrefix = [IO.Path]::GetFullPath($sandboxRoot).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not ($full + [IO.Path]::DirectorySeparatorChar).StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4SnapshotStage 'sandbox_path_invalid'
    }
    if ($full -match '(^|[\\/])(?:owner|private|real|nas)(?:[\\/]|$)' -or $full -match '^[Mm]:') {
        Stop-V4SnapshotStage 'private_or_source_path_forbidden'
    }
    Assert-NoReparsePoints -Path $full -MustExist:$MustExist
    return $full
}

function Assert-IgnoredUntracked([string]$Path, [string]$Label, [bool]$Directory, [bool]$MustExist = $true) {
    $full = Assert-SandboxPath -Path $Path -MustExist:$MustExist
    if ($MustExist) {
        $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
        if (($Directory -and -not $item.PSIsContainer) -or (-not $Directory -and $item.PSIsContainer)) {
            Stop-V4SnapshotStage ($Label + '_type_invalid')
        }
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V4SnapshotStage ($Label + '_untrusted') }
    }
    $relative = Get-ProjectRelativePath $full
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage ($Label + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V4SnapshotStage ($Label + '_tracked') }
    return $full
}

function Get-WslPath([string]$Path) {
    if (-not (Test-Path -LiteralPath $wsl -PathType Leaf)) { Stop-V4SnapshotStage 'wsl_unavailable' }
    $full = [IO.Path]::GetFullPath($Path)
    $result = @(& $wsl -d Ubuntu --exec wslpath -a $full 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1) { Stop-V4SnapshotStage 'wsl_path_conversion_failed' }
    $path = ([string]$result[0]).Trim()
    if ($path -notmatch '^/mnt/[a-z]/' -or $path.Contains('..') -or $path.Contains('//')) {
        Stop-V4SnapshotStage 'wsl_path_invalid'
    }
    return $path
}

function Get-ObjectProperty([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Assert-BaseEnvBoundary {
    $path = Join-Path $projectRoot $baseEnvFile
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-V4SnapshotStage 'base_synthetic_env_missing' }
    Assert-NoReparsePoints -Path $path | Out-Null
    & git -C $projectRoot check-ignore --quiet --no-index -- $baseEnvFile
    if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'base_synthetic_env_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $baseEnvFile 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V4SnapshotStage 'base_synthetic_env_tracked' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
}

function Get-BaseSyntheticSnapshotSource {
    Assert-BaseEnvBoundary
    $rootWsl = Get-WslPath $projectRoot
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& $wsl -d Ubuntu --cd $rootWsl -- docker compose --env-file $baseEnvFile -f $baseComposeFile -p $baseProjectName --profile ops config --format json 2>&1)
        $exit = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }
    if ($exit -ne 0) { Stop-V4SnapshotStage 'base_synthetic_compose_config_failed' }
    try { $config = ([string]::Join("`n", $lines) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-V4SnapshotStage 'base_synthetic_compose_config_invalid' }
    if ([string](Get-ObjectProperty $config 'name') -ne $baseProjectName) { Stop-V4SnapshotStage 'base_synthetic_project_invalid' }

    $services = Get-ObjectProperty $config 'services'
    $piwigo = Get-ObjectProperty $services 'piwigo'
    $snapshot = Get-ObjectProperty $services 'pre-migration-db-backup'
    if ($null -eq $piwigo -or $null -eq $snapshot) { Stop-V4SnapshotStage 'base_synthetic_snapshot_service_missing' }

    $ports = @((Get-ObjectProperty $piwigo 'ports'))
    $actualPorts = @{}
    foreach ($port in $ports) {
        if ([string](Get-ObjectProperty $port 'host_ip') -ne '127.0.0.1') { Stop-V4SnapshotStage 'base_synthetic_non_loopback_port' }
        $actualPorts[[string](Get-ObjectProperty $port 'published')] = [string](Get-ObjectProperty $port 'target')
    }
    if ($actualPorts['8090'] -ne '80' -or $actualPorts['8091'] -ne '8081' -or $actualPorts.Count -ne 2) {
        Stop-V4SnapshotStage 'base_synthetic_port_binding_invalid'
    }

    $entrypoint = @((Get-ObjectProperty $snapshot 'entrypoint')) -join ' '
    if ($entrypoint -notmatch '(?<![A-Za-z0-9_])create-pre-migration-db-snapshot\.sh(?![A-Za-z0-9_])') {
        Stop-V4SnapshotStage 'base_synthetic_snapshot_producer_invalid'
    }
    if ((Get-ObjectProperty $snapshot 'read_only') -ne $true) { Stop-V4SnapshotStage 'base_synthetic_snapshot_rootfs_not_read_only' }
    if (@((Get-ObjectProperty $snapshot 'cap_drop')) -notcontains 'ALL') { Stop-V4SnapshotStage 'base_synthetic_snapshot_cap_drop_invalid' }
    if (@((Get-ObjectProperty $snapshot 'security_opt')) -notcontains 'no-new-privileges:true') { Stop-V4SnapshotStage 'base_synthetic_snapshot_security_opt_invalid' }

    $backupMount = $null
    foreach ($mount in @((Get-ObjectProperty $snapshot 'volumes'))) {
        $target = [string](Get-ObjectProperty $mount 'target')
        if ($target -match '^/(?:source|media|private|owner|real)(?:/|$)') { Stop-V4SnapshotStage 'base_synthetic_snapshot_forbidden_mount' }
        if ($target -eq '/backup') {
            if ($null -ne $backupMount) { Stop-V4SnapshotStage 'base_synthetic_snapshot_backup_mount_ambiguous' }
            $backupMount = $mount
        }
    }
    if ($null -eq $backupMount -or [string](Get-ObjectProperty $backupMount 'type') -ne 'volume' -or
        [string](Get-ObjectProperty $backupMount 'source') -ne 'backups' -or
        (Get-ObjectProperty $backupMount 'read_only') -eq $true) {
        Stop-V4SnapshotStage 'base_synthetic_snapshot_backup_mount_invalid'
    }

    $volumes = Get-ObjectProperty $config 'volumes'
    $backupVolume = Get-ObjectProperty $volumes 'backups'
    if ($null -eq $backupVolume -or [string](Get-ObjectProperty $backupVolume 'name') -ne $baseBackupVolume) {
        Stop-V4SnapshotStage 'base_synthetic_backup_volume_invalid'
    }
    $image = [string](Get-ObjectProperty $snapshot 'image')
    if ($image -notmatch '^mariadb:11\.8\.8@sha256:[a-f0-9]{64}$') { Stop-V4SnapshotStage 'base_synthetic_snapshot_image_unpinned' }

    return @{ Volume = $baseBackupVolume; Image = $image }
}

function Assert-BaseBackupVolume([hashtable]$Source) {
    $inspect = @(& $wsl -d Ubuntu --exec docker volume inspect $Source.Volume 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'base_synthetic_backup_volume_unavailable' }
    try { $records = ([string]::Join("`n", $inspect) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-V4SnapshotStage 'base_synthetic_backup_volume_inspect_invalid' }
    $volumeRecord = @($records)[0]
    if (@($records).Count -ne 1 -or [string]$volumeRecord.Name -ne $Source.Volume -or [string]$volumeRecord.Driver -ne 'local') {
        Stop-V4SnapshotStage 'base_synthetic_backup_volume_untrusted'
    }
    $options = $volumeRecord.Options
    if ($null -ne $options -and -not [string]::IsNullOrWhiteSpace([string]$options.device)) {
        Stop-V4SnapshotStage 'base_synthetic_backup_volume_not_docker_managed'
    }
    & $wsl -d Ubuntu --exec docker image inspect $Source.Image *> $null
    if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'base_synthetic_snapshot_image_unavailable' }
}

function Assert-InputRootForStage([switch]$Create) {
    if (-not (Test-Path -LiteralPath $sandboxRoot)) {
        if (-not $Create) { return (Assert-IgnoredUntracked -Path $sandboxRoot -Label 'sandbox_root' -Directory:$true -MustExist:$false) }
        Assert-IgnoredUntracked -Path $sandboxRoot -Label 'sandbox_root' -Directory:$true -MustExist:$false | Out-Null
        [void][IO.Directory]::CreateDirectory($sandboxRoot)
    }
    Assert-IgnoredUntracked -Path $sandboxRoot -Label 'sandbox_root' -Directory:$true | Out-Null
    if (-not (Test-Path -LiteralPath $inputRoot)) {
        if (-not $Create) { return (Assert-IgnoredUntracked -Path $inputRoot -Label 'sandbox_input' -Directory:$true -MustExist:$false) }
        Assert-IgnoredUntracked -Path $inputRoot -Label 'sandbox_input' -Directory:$true -MustExist:$false | Out-Null
        [void][IO.Directory]::CreateDirectory($inputRoot)
    }
    return (Assert-IgnoredUntracked -Path $inputRoot -Label 'sandbox_input' -Directory:$true)
}

function Assert-EmptyInputRoot([string]$Root) {
    if (@(Get-ChildItem -LiteralPath $Root -Force -ErrorAction Stop).Count -ne 0) {
        Stop-V4SnapshotStage 'sandbox_input_not_empty'
    }
}

function Assert-ExactSnapshotBundle([string]$SnapshotPath) {
    $snapshot = Assert-IgnoredUntracked -Path $SnapshotPath -Label 'snapshot_bundle' -Directory:$true
    if ([IO.Path]::GetFileName($snapshot) -ne $BundleName) { Stop-V4SnapshotStage 'snapshot_bundle_name_invalid' }
    $allowed = @('COMPLETE', 'MANIFEST.json', 'SHA256SUMS', 'database.sql.gz')
    $entries = @(Get-ChildItem -LiteralPath $snapshot -Force -ErrorAction Stop)
    if ($entries.Count -ne $allowed.Count -or @($entries | Where-Object { $_.Name -notin $allowed }).Count -ne 0) {
        Stop-V4SnapshotStage 'snapshot_file_set_invalid'
    }
    foreach ($name in $allowed) {
        $path = Join-Path $snapshot $name
        $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            Stop-V4SnapshotStage 'snapshot_file_untrusted'
        }
    }
    try { $manifest = Get-Content -LiteralPath (Join-Path $snapshot 'MANIFEST.json') -Raw | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-V4SnapshotStage 'snapshot_manifest_invalid' }
    if (
        $manifest.format -ne 1 -or $manifest.scope -ne 'DB_ONLY_PRE_MIGRATION_ROLLBACK' -or
        $manifest.schema_current -ne 16 -or $manifest.schema_from -ne 16 -or $manifest.schema_to -ne 17 -or
        $manifest.lock_strategy -ne 'MARIADB_DUMP_LOCK_ALL_TABLES' -or $manifest.media -ne 'NOT_INCLUDED' -or
        $manifest.dump_file -ne 'database.sql.gz' -or
        [string]$manifest.created_at -ne ($BundleName.Substring('pre-migration-db-v16-to-v17-'.Length)) -or
        [string]$manifest.dump_sha256 -notmatch '^[a-f0-9]{64}$' -or
        [string]$manifest.snapshot_script_sha256 -notmatch '^[a-f0-9]{64}$'
    ) { Stop-V4SnapshotStage 'snapshot_manifest_invalid' }
    $sourceScript = Join-Path $projectRoot 'infra\scripts\create-pre-migration-db-snapshot.sh'
    $sourceScriptHash = (Get-FileHash -LiteralPath $sourceScript -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not [string]::Equals($sourceScriptHash, [string]$manifest.snapshot_script_sha256, [StringComparison]::Ordinal)) {
        Stop-V4SnapshotStage 'snapshot_not_created_by_existing_mechanism'
    }
    $dump = Join-Path $snapshot 'database.sql.gz'
    $dumpHash = (Get-FileHash -LiteralPath $dump -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not [string]::Equals($dumpHash, [string]$manifest.dump_sha256, [StringComparison]::Ordinal) -or
        [Int64]$manifest.dump_bytes -ne (Get-Item -LiteralPath $dump -Force).Length) {
        Stop-V4SnapshotStage 'snapshot_dump_hash_invalid'
    }
    $sumLines = @(Get-Content -LiteralPath (Join-Path $snapshot 'SHA256SUMS') | Where-Object { $_ -ne '' })
    if ($sumLines.Count -ne 3 -or @($sumLines | Where-Object { $_ -notmatch '^[a-f0-9]{64}  (COMPLETE|MANIFEST\.json|database\.sql\.gz)$' }).Count -ne 0) {
        Stop-V4SnapshotStage 'snapshot_checksum_manifest_invalid'
    }
    foreach ($line in $sumLines) {
        $parts = $line -split '  ', 2
        $actual = (Get-FileHash -LiteralPath (Join-Path $snapshot $parts[1]) -Algorithm SHA256).Hash.ToLowerInvariant()
        if (-not [string]::Equals($actual, $parts[0], [StringComparison]::Ordinal)) { Stop-V4SnapshotStage 'snapshot_checksum_failed' }
    }
    $complete = Get-Content -LiteralPath (Join-Path $snapshot 'COMPLETE') -Raw
    if ($complete -notmatch '^completed_at=[0-9]{8}T[0-9]{6}Z\r?\n$') { Stop-V4SnapshotStage 'snapshot_complete_invalid' }
    foreach ($item in $entries) { Set-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName }
    return @{ Bundle = $snapshot; DumpSha256 = $dumpHash }
}

function New-StageContainerName {
    $bytes = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    # Keep the narrow staging helper compatible with Windows PowerShell 5.1,
    # which lacks Convert.ToHexString but is the documented local runner.
    return 'class_archive_v4_synthetic_snapshot_stage_' + ([BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant())
}

function Remove-TemporaryStageContainer {
    if ([string]::IsNullOrWhiteSpace($script:temporaryContainer)) { return }
    $name = $script:temporaryContainer
    $script:temporaryContainer = $null
    $inspect = @(& $wsl -d Ubuntu --exec docker inspect $name 2>$null)
    if ($LASTEXITCODE -ne 0) { return }
    try { $record = ([string]::Join("`n", $inspect) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-V4SnapshotStage 'stage_container_inspect_invalid' }
    $labels = @($record)[0].Config.Labels
    if ($null -eq $labels -or [string]$labels.'com.classarchive.scope' -ne 'V4_SYNTHETIC_SNAPSHOT_STAGING') {
        Stop-V4SnapshotStage 'stage_container_label_invalid'
    }
    & $wsl -d Ubuntu --exec docker rm $name *> $null
    if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'stage_container_cleanup_failed' }
}

function Copy-ReadOnlyBundleFromBaseVolume([hashtable]$Source, [string]$StageParent) {
    $name = New-StageContainerName
    $script:temporaryContainer = $name
    try {
        # The pinned database image declares its data directory as a VOLUME.
        # Give that unused path a bounded tmpfs so `docker create` cannot allocate
        # a dangling anonymous volume merely to copy a DB-only backup bundle.
        # This container is never started and receives no writable host mount.
        & $wsl -d Ubuntu --exec docker create --name $name --label 'com.classarchive.scope=V4_SYNTHETIC_SNAPSHOT_STAGING' --read-only --network none --cap-drop ALL --security-opt 'no-new-privileges:true' --tmpfs '/var/lib/mysql:rw,nosuid,nodev,noexec,size=1m' --mount ("type=volume,source=" + $Source.Volume + ",target=/backup,readonly") $Source.Image true *> $null
        if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'stage_container_create_failed' }
        $inspect = @(& $wsl -d Ubuntu --exec docker inspect $name 2>$null)
        if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'stage_container_inspect_failed' }
        try { $record = ([string]::Join("`n", $inspect) | ConvertFrom-Json -ErrorAction Stop) }
        catch { Stop-V4SnapshotStage 'stage_container_inspect_invalid' }
        $container = @($record)[0]
        if ($container.HostConfig.ReadonlyRootfs -ne $true -or [string]$container.HostConfig.NetworkMode -ne 'none') {
            Stop-V4SnapshotStage 'stage_container_isolation_invalid'
        }
        $mounts = @($container.Mounts)
        if ($mounts.Count -ne 1 -or [string]$mounts[0].Type -ne 'volume' -or [string]$mounts[0].Name -ne $Source.Volume -or
            [string]$mounts[0].Destination -ne '/backup' -or $mounts[0].RW -ne $false) {
            Stop-V4SnapshotStage 'stage_container_mount_invalid'
        }
        # `docker cp` is executed inside WSL.  Passing a Windows `C:` path
        # makes Docker parse it as a second container reference, so convert
        # the verified ignored destination to its canonical /mnt/c path.
        $stageParentWsl = Get-WslPath $StageParent
        & $wsl -d Ubuntu --exec docker cp ($name + ':/backup/' + $BundleName) $stageParentWsl *> $null
        if ($LASTEXITCODE -ne 0) { Stop-V4SnapshotStage 'stage_container_copy_failed' }
    }
    finally {
        Remove-TemporaryStageContainer
    }
}

try {
    $source = Get-BaseSyntheticSnapshotSource
    Assert-BaseBackupVolume -Source $source
    $input = Assert-InputRootForStage -Create:($Action -eq 'stage')

    if ($Action -eq 'validate') {
        Write-V4SnapshotStage 'READY' 'validate' 'source=BASE_SYNTHETIC_DB_ONLY destination=IGNORED_INPUT snapshot=NOT_COPIED'
        exit 0
    }

    if (-not $ConfirmStage) { Stop-V4SnapshotStage 'stage_confirmation_required' }
    Assert-EmptyInputRoot -Root $input
    $token = [Guid]::NewGuid().ToString('N')
    $stageParent = Join-Path $sandboxRoot ('.stage-' + $token + '.partial')
    Assert-IgnoredUntracked -Path $stageParent -Label 'stage_partial' -Directory:$true -MustExist:$false | Out-Null
    [void][IO.Directory]::CreateDirectory($stageParent)
    Copy-ReadOnlyBundleFromBaseVolume -Source $source -StageParent $stageParent
    $copied = Join-Path $stageParent $BundleName
    $verified = Assert-ExactSnapshotBundle -SnapshotPath $copied
    $published = Join-Path $input $BundleName
    if (Test-Path -LiteralPath $published) { Stop-V4SnapshotStage 'snapshot_destination_already_exists' }
    Move-Item -LiteralPath $copied -Destination $published -ErrorAction Stop
    try { [IO.Directory]::Delete($stageParent, $false) } catch { Stop-V4SnapshotStage 'stage_partial_parent_not_empty' }
    Assert-ExactSnapshotBundle -SnapshotPath $published | Out-Null
    Write-V4SnapshotStage 'PASS' 'stage' ('source=BASE_SYNTHETIC_DB_ONLY destination=IGNORED_INPUT dump_sha256=' + $verified.DumpSha256)
    exit 0
}
catch {
    $code = if ($_.Exception.Message -match '^V4_SYNTHETIC_SNAPSHOT_STAGE_STOP:([a-z0-9_]{1,128})$') {
        [string]$Matches[1]
    }
    else {
        $type = $_.Exception.GetType().Name
        if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
        'unexpected_' + $type
    }
    Write-V4SnapshotStage 'FAIL' $Action ('code=' + $code)
    exit 2
}
