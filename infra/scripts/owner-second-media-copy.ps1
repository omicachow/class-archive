[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('preflight', 'copy', 'verify')]
    [string]$Action = 'preflight',

    [Parameter(Mandatory = $true)]
    [string]$BundlePath,

    [string]$TargetRoot = ([IO.Path]::Combine('C:' + [IO.Path]::DirectorySeparatorChar, 'ClassArchive-Independent-Recovery')),

    [ValidateRange(4, 64)]
    [int]$MinimumPostCopyFreeGiB = 8,

    [switch]$ConfirmSecondMediaCopy
)

# Copies one already-complete Owner v2 bundle from the removable M: recovery
# target to an ordinary NTFS directory on the C: physical disk. The source is
# immutable and read-only to this tool. Publication is partial-directory first,
# per-file size/SHA-256 verified, and finally an atomic same-volume rename.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:stage = 'initialization'

function Stop-SecondMedia([string]$Code) {
    throw [InvalidOperationException]::new('OWNER_SECOND_MEDIA_STOP:' + $Code)
}

function Normalize-Path([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path) -or $Path.IndexOf([char]0) -ge 0) {
        Stop-SecondMedia 'path_invalid'
    }
    try { return [IO.Path]::GetFullPath($Path).TrimEnd('\', '/') }
    catch { Stop-SecondMedia 'path_invalid' }
}

function Assert-PlainDirectory([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-SecondMedia $Code
    }
}

function Get-DriveDisk([string]$DriveLetter) {
    $wanted = $DriveLetter.TrimEnd(':').ToUpperInvariant() + ':'
    $records = @()
    foreach ($disk in @(Get-CimInstance Win32_DiskDrive -ErrorAction Stop)) {
        foreach ($partition in @(Get-CimAssociatedInstance -InputObject $disk -Association Win32_DiskDriveToDiskPartition -ErrorAction Stop)) {
            foreach ($logical in @(Get-CimAssociatedInstance -InputObject $partition -Association Win32_LogicalDiskToPartition -ErrorAction Stop)) {
                if ([string]::Equals([string]$logical.DeviceID, $wanted, [StringComparison]::OrdinalIgnoreCase)) {
                    $records += [pscustomobject]@{
                        drive = $wanted
                        disk_index = [int]$disk.Index
                        model = [string]$disk.Model
                        size = [uint64]$disk.Size
                        free = [uint64]$logical.FreeSpace
                        filesystem = [string]$logical.FileSystem
                    }
                }
            }
        }
    }
    if ($records.Count -ne 1) { Stop-SecondMedia 'physical_disk_identity_invalid' }
    return $records[0]
}

function Get-SafeRelativePath([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value.Length -gt 512 -or
        $Value.Contains('\') -or $Value.StartsWith('/') -or $Value.Contains('..') -or
        $Value.IndexOf([char]0) -ge 0 -or $Value -notmatch '\A[A-Za-z0-9._/-]+\z') {
        Stop-SecondMedia 'bundle_relative_path_invalid'
    }
    return $Value.Replace('/', [IO.Path]::DirectorySeparatorChar)
}

function Test-FixedHash([string]$Left, [string]$Right) {
    if ($Left.Length -ne 64 -or $Right.Length -ne 64) { return $false }
    $difference = 0
    for ($index = 0; $index -lt 64; $index++) {
        $difference = $difference -bor ([int][char]$Left[$index] -bxor [int][char]$Right[$index])
    }
    return $difference -eq 0
}

function Read-ChecksumIndex([string]$Bundle) {
    $path = Join-Path $Bundle 'SHA256SUMS'
    $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -lt 80 -or $item.Length -gt 1MB) {
        Stop-SecondMedia 'checksum_index_invalid'
    }
    $records = @{}
    foreach ($line in [IO.File]::ReadAllLines($path, [Text.UTF8Encoding]::new($false, $true))) {
        if ($line -notmatch '\A([0-9a-f]{64})  ([A-Za-z0-9._/-]+)\z') { Stop-SecondMedia 'checksum_index_invalid' }
        $relative = [string]$Matches[2]
        [void](Get-SafeRelativePath $relative)
        if ($relative -eq 'SHA256SUMS' -or $records.ContainsKey($relative)) { Stop-SecondMedia 'checksum_index_invalid' }
        $records[$relative] = [string]$Matches[1]
    }
    if ($records.Count -lt 12) { Stop-SecondMedia 'checksum_index_incomplete' }
    return $records
}

function Assert-Checksums([string]$Bundle, [hashtable]$Records) {
    foreach ($entry in $Records.GetEnumerator()) {
        $relative = Get-SafeRelativePath ([string]$entry.Key)
        $path = Join-Path $Bundle $relative
        $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Stop-SecondMedia 'bundle_payload_untrusted'
        }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
        if (-not (Test-FixedHash $actual ([string]$entry.Value))) { Stop-SecondMedia 'bundle_sha256_mismatch' }
    }
}

function Get-BundleEvidence([string]$Candidate) {
    $bundle = Normalize-Path $Candidate
    Assert-PlainDirectory $bundle 'bundle_untrusted'
    $name = [IO.Path]::GetFileName($bundle)
    if ($name -notmatch '\Aowner-full-v2-[0-9]{8}T[0-9]{6}Z\z') { Stop-SecondMedia 'bundle_name_invalid' }
    $expectedParent = Normalize-Path ([IO.Path]::Combine(
        'M:' + [IO.Path]::DirectorySeparatorChar,
        'ClassArchive-Temporary-Recovery',
        'bundles'
    ))
    if (-not [string]::Equals((Split-Path -Parent $bundle), $expectedParent, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-SecondMedia 'bundle_source_boundary_invalid'
    }
    Assert-PlainDirectory $expectedParent 'bundle_source_boundary_invalid'

    $complete = (Get-Content -Raw -LiteralPath (Join-Path $bundle 'COMPLETE')).Trim()
    if (-not [string]::Equals($complete, $name, [StringComparison]::Ordinal)) { Stop-SecondMedia 'complete_marker_invalid' }
    try { $manifest = Get-Content -Raw -LiteralPath (Join-Path $bundle 'manifest.json') | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-SecondMedia 'manifest_invalid' }
    if ($manifest.format -ne 'owner-full-recovery-v2' -or [int]$manifest.version -ne 2 -or
        $manifest.backup_id -ne $name -or $manifest.scope -ne 'OWNER_PRIVATE_FULL' -or
        $manifest.encryption.archive -ne 'GPG_SYMMETRIC_AES256' -or
        $manifest.encryption.portable_envelope.dpapi_required -ne $false) {
        Stop-SecondMedia 'manifest_contract_invalid'
    }
    if (-not (Test-Path -LiteralPath (Join-Path $bundle 'recovery-kit\portable-key-envelope.gpg') -PathType Leaf)) {
        Stop-SecondMedia 'portable_envelope_missing'
    }
    $records = Read-ChecksumIndex $bundle
    $files = @(Get-ChildItem -LiteralPath $bundle -File -Recurse -Force)
    $expected = @($records.Keys + @('SHA256SUMS')) | Sort-Object
    $actual = @($files | ForEach-Object {
        $_.FullName.Substring($bundle.Length + 1).Replace('\', '/')
    } | Sort-Object)
    if ($actual.Count -ne $expected.Count) { Stop-SecondMedia 'bundle_inventory_invalid' }
    for ($index = 0; $index -lt $expected.Count; $index++) {
        if (-not [string]::Equals($actual[$index], $expected[$index], [StringComparison]::Ordinal)) {
            Stop-SecondMedia 'bundle_inventory_invalid'
        }
    }
    $bytes = [uint64](($files | Measure-Object Length -Sum).Sum)
    if ($bytes -le 0) { Stop-SecondMedia 'bundle_size_invalid' }
    return @{ path=$bundle; name=$name; manifest=$manifest; checksums=$records; bytes=$bytes }
}

function Get-TargetEvidence([uint64]$BundleBytes) {
    $target = Normalize-Path $TargetRoot
    $expectedTarget = Normalize-Path ([IO.Path]::Combine(
        'C:' + [IO.Path]::DirectorySeparatorChar,
        'ClassArchive-Independent-Recovery'
    ))
    if (-not [string]::Equals($target, $expectedTarget, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-SecondMedia 'target_root_invalid'
    }
    $sourceDisk = Get-DriveDisk 'M:'
    $targetDisk = Get-DriveDisk 'C:'
    if ($sourceDisk.disk_index -eq $targetDisk.disk_index) { Stop-SecondMedia 'source_target_same_physical_disk' }
    if (-not [string]::Equals($sourceDisk.filesystem, 'exFAT', [StringComparison]::OrdinalIgnoreCase) -or
        -not [string]::Equals($targetDisk.filesystem, 'NTFS', [StringComparison]::OrdinalIgnoreCase)) {
        Stop-SecondMedia 'filesystem_boundary_invalid'
    }
    $minimumMargin = [uint64]$MinimumPostCopyFreeGiB * 1GB
    $minimumRequired = $BundleBytes + $minimumMargin
    $recommendedRequired = ($BundleBytes * 2) + ([uint64]20 * 1GB)
    if ($targetDisk.free -lt $minimumRequired) { Stop-SecondMedia 'target_capacity_insufficient' }
    return @{
        target=$target; source_disk=$sourceDisk; target_disk=$targetDisk;
        minimum_margin=$minimumMargin; minimum_required=$minimumRequired;
        recommended_required=$recommendedRequired; recommended_met=($targetDisk.free -ge $recommendedRequired)
    }
}

function Ensure-TargetRoot([hashtable]$Target) {
    if (-not (Test-Path -LiteralPath $Target.target)) {
        [void](New-Item -ItemType Directory -Path $Target.target)
    }
    Assert-PlainDirectory $Target.target 'target_root_untrusted'
    $marker = Join-Path $Target.target 'CLASS_ARCHIVE_INDEPENDENT_RECOVERY_TARGET'
    $expected = "CLASS_ARCHIVE_INDEPENDENT_RECOVERY_TARGET`nversion=1`nscope=OWNER_V2_SECOND_MEDIA`n"
    if (-not (Test-Path -LiteralPath $marker)) {
        [IO.File]::WriteAllText($marker, $expected, [Text.UTF8Encoding]::new($false))
    }
    elseif (-not [string]::Equals([IO.File]::ReadAllText($marker), $expected, [StringComparison]::Ordinal)) {
        Stop-SecondMedia 'target_marker_invalid'
    }
    $bundles = Join-Path $Target.target 'bundles'
    if (-not (Test-Path -LiteralPath $bundles)) { [void](New-Item -ItemType Directory -Path $bundles) }
    Assert-PlainDirectory $bundles 'target_bundles_untrusted'
    return $bundles
}

function Get-BundleEvidenceForDestination([string]$Candidate, [string]$ExpectedName) {
    $bundle = Normalize-Path $Candidate
    Assert-PlainDirectory $bundle 'destination_untrusted'
    $complete = (Get-Content -Raw -LiteralPath (Join-Path $bundle 'COMPLETE')).Trim()
    if (-not [string]::Equals($complete, $ExpectedName, [StringComparison]::Ordinal)) { Stop-SecondMedia 'destination_complete_invalid' }
    try { $manifest = Get-Content -Raw -LiteralPath (Join-Path $bundle 'manifest.json') | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-SecondMedia 'destination_manifest_invalid' }
    if ($manifest.format -ne 'owner-full-recovery-v2' -or [int]$manifest.version -ne 2 -or $manifest.backup_id -ne $ExpectedName) {
        Stop-SecondMedia 'destination_manifest_invalid'
    }
    $records = Read-ChecksumIndex $bundle
    $files = @(Get-ChildItem -LiteralPath $bundle -File -Recurse -Force)
    $sourceNames = @($records.Keys + @('SHA256SUMS')) | Sort-Object
    $actualNames = @($files | ForEach-Object { $_.FullName.Substring($bundle.Length + 1).Replace('\','/') } | Sort-Object)
    if ($sourceNames.Count -ne $actualNames.Count) { Stop-SecondMedia 'destination_inventory_invalid' }
    for ($index=0; $index -lt $sourceNames.Count; $index++) {
        if (-not [string]::Equals($sourceNames[$index],$actualNames[$index],[StringComparison]::Ordinal)) {
            Stop-SecondMedia 'destination_inventory_invalid'
        }
    }
    return @{ manifest=$manifest; checksums=$records; bytes=[uint64](($files|Measure-Object Length -Sum).Sum) }
}

try {
    $script:stage = 'bundle_validation'
    $bundle = Get-BundleEvidence $BundlePath
    $script:stage = 'target_preflight'
    $target = Get-TargetEvidence ([uint64]$bundle.bytes)

    if ($Action -eq 'preflight') {
        Write-Output ('OWNER_SECOND_MEDIA=PASS action=preflight backup_id=' + $bundle.name +
            ' bundle_bytes=' + $bundle.bytes + ' target_free_bytes=' + $target.target_disk.free +
            ' minimum_required_bytes=' + $target.minimum_required +
            ' recommended_required_bytes=' + $target.recommended_required +
            ' recommended_margin=' + $(if ($target.recommended_met) { 'PASS' } else { 'NOT_MET' }) +
            ' physical_disks=DIFFERENT filesystems=EXFAT_TO_NTFS')
        exit 0
    }

    $bundles = Ensure-TargetRoot $target
    $destination = Join-Path $bundles $bundle.name
    if ($Action -eq 'verify') {
        if (-not (Test-Path -LiteralPath $destination -PathType Container)) { Stop-SecondMedia 'destination_missing' }
        Assert-PlainDirectory $destination 'destination_untrusted'
        $script:stage = 'destination_sha256'
        $destinationEvidence = Get-BundleEvidenceForDestination $destination $bundle.name
        Assert-Checksums $destination $destinationEvidence.checksums
        Write-Output ('OWNER_SECOND_MEDIA=PASS action=verify backup_id=' + $bundle.name +
            ' source=COPY_ON_M destination=COPY_ON_C files=' + $destinationEvidence.checksums.Count +
            ' size=PASS sha256=PASS physical_disks=DIFFERENT')
        exit 0
    }

    if (-not $ConfirmSecondMediaCopy.IsPresent) { Stop-SecondMedia 'copy_confirmation_required' }
    if (Test-Path -LiteralPath $destination) { Stop-SecondMedia 'destination_exists' }
    $partial = Join-Path $bundles ('.partial-' + $bundle.name)
    if (Test-Path -LiteralPath $partial) { Stop-SecondMedia 'partial_destination_exists' }
    [void](New-Item -ItemType Directory -Path $partial)
    $script:stage = 'copy'
    & robocopy.exe $bundle.path $partial /E /COPY:DAT /DCOPY:DAT /R:2 /W:2 /J /NFL /NDL /NP /NJH /NJS
    $copyExit = $LASTEXITCODE
    if ($copyExit -gt 7) { Stop-SecondMedia 'robocopy_failed' }
    $script:stage = 'copy_sha256'
    $partialEvidence = Get-BundleEvidenceForDestination $partial $bundle.name
    Assert-Checksums $partial $partialEvidence.checksums
    [IO.Directory]::Move($partial, $destination)
    $script:stage = 'published_sha256'
    $destinationEvidence = Get-BundleEvidenceForDestination $destination $bundle.name
    Assert-Checksums $destination $destinationEvidence.checksums
    foreach ($file in @(Get-ChildItem -LiteralPath $destination -File -Recurse -Force)) {
        $file.IsReadOnly = $true
    }
    Write-Output ('OWNER_SECOND_MEDIA=PASS action=copy backup_id=' + $bundle.name +
        ' source=COPY_ON_M destination=COPY_ON_C files=' + $destinationEvidence.checksums.Count +
        ' size=PASS sha256=PASS atomic_publish=PASS physical_disks=DIFFERENT')
    exit 0
}
catch {
    $code = $null
    if ($_.Exception.Message -match '^OWNER_SECOND_MEDIA_STOP:([a-z0-9_]{1,128})$') { $code = [string]$Matches[1] }
    if ($null -eq $code) {
        $kind = $_.Exception.GetType().Name
        if ($kind -notmatch '\A[A-Za-z0-9]{1,64}\z') { $kind = 'Exception' }
        $code = 'unexpected_' + $kind
    }
    Write-Output ('OWNER_SECOND_MEDIA=FAIL stage=' + $script:stage + ' code=' + $code)
    exit 2
}
