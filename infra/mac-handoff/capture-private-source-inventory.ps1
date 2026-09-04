[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$SourceA,
    [Parameter(Mandatory)]
    [string]$SourceB,
    [Parameter(Mandatory)]
    [string]$OutputPath,
    [Parameter(Mandatory)]
    [string]$ApprovedStagingRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Fail([string]$Code) {
    throw "PRIVATE_SOURCE_INVENTORY=FAIL reason=$Code"
}

function Is-Within([string]$Candidate, [string]$Parent) {
    $prefix = $Parent.TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    return $Candidate.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)
}

$roots = @(
    [ordered]@{ source_id = 'PRIVATE_SOURCE_A'; path = (Resolve-Path -LiteralPath $SourceA).Path },
    [ordered]@{ source_id = 'PRIVATE_SOURCE_B'; path = (Resolve-Path -LiteralPath $SourceB).Path }
)
if ($roots[0].path -eq $roots[1].path) { Fail 'source_roots_not_distinct' }

$outputParent = (Resolve-Path -LiteralPath (Split-Path -Parent $OutputPath)).Path
$resolvedOutput = [IO.Path]::GetFullPath((Join-Path $outputParent (Split-Path -Leaf $OutputPath)))
$approvedBase = (Resolve-Path -LiteralPath $ApprovedStagingRoot).Path
$approvedBaseItem = Get-Item -LiteralPath $approvedBase -Force
if (-not $approvedBaseItem.PSIsContainer -or ($approvedBaseItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    Fail 'approved_staging_root_invalid_or_reparse_point'
}
$relativeOutput = [IO.Path]::GetRelativePath($approvedBase, $resolvedOutput).Replace('\','/')
if (-not (Is-Within $resolvedOutput $approvedBase) -or $relativeOutput -notmatch '^[.]staging-[0-9]{8}T[0-9]{6}Z/ClassArchive-Complete-Mac-Handoff-[0-9]{8}T[0-9]{6}Z/payloads/private-metadata/source-inventory-(?:before|after)[.]json$') {
    Fail 'output_path_outside_approved_private_staging'
}
if (Test-Path -LiteralPath $resolvedOutput) { Fail 'output_already_exists' }
foreach ($root in $roots) {
    if (Is-Within $resolvedOutput $root.path) { Fail 'output_inside_source_root' }
    $rootInfo = Get-Item -LiteralPath $root.path -Force
    if (-not $rootInfo.PSIsContainer -or ($rootInfo.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Fail 'source_root_invalid_or_reparse_point'
    }
}

$records = [Collections.Generic.List[object]]::new()
$rootSummaries = [Collections.Generic.List[object]]::new()
foreach ($root in $roots) {
    $entries = @(Get-ChildItem -LiteralPath $root.path -Recurse -Force -ErrorAction Stop)
    if (@($entries | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count -ne 0) {
        Fail ('source_reparse_point_detected_' + $root.source_id)
    }
    $files = @($entries | Where-Object { -not $_.PSIsContainer } | Sort-Object FullName)
    $extensionCounts = [ordered]@{}
    [int64]$rootBytes = 0
    foreach ($file in $files) {
        $relative = [IO.Path]::GetRelativePath($root.path, $file.FullName).Replace('\','/')
        if ([string]::IsNullOrWhiteSpace($relative) -or $relative.StartsWith('/') -or $relative.Contains('\') -or $relative -match '(^|/)[.][.]($|/)') {
            Fail ('source_relative_path_invalid_' + $root.source_id)
        }
        $extension = $file.Extension.ToLowerInvariant()
        if (-not $extensionCounts.Contains($extension)) { $extensionCounts[$extension] = 0 }
        $extensionCounts[$extension] = [int]$extensionCounts[$extension] + 1
        $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        $rootBytes += [int64]$file.Length
        $records.Add([ordered]@{
            source_id = $root.source_id
            relative_path = $relative
            size = [int64]$file.Length
            # Preserve the source filesystem value at full .NET precision.  The
            # integrity verifier parses the timestamp semantically, so exFAT's
            # fractional-second value is not lost between the before/after pass.
            mtime_utc = $file.LastWriteTimeUtc.ToString('o')
            sha256 = $hash
            extension = $extension
        })
    }
    $rootSummaries.Add([ordered]@{
        source_id = $root.source_id
        file_count = $files.Count
        bytes = $rootBytes
        extension_counts = $extensionCounts
    })
}

[int64]$totalBytes = 0
foreach ($rootSummary in $rootSummaries) { $totalBytes += [int64]$rootSummary.bytes }
$inventory = [ordered]@{
    format = 'class-archive-private-source-inventory-v1'
    created_at = [DateTime]::UtcNow.ToString('o')
    algorithm = 'SHA-256'
    roots = $rootSummaries
    total_files = $records.Count
    total_bytes = $totalBytes
    files = $records
}
$utf8 = [Text.UTF8Encoding]::new($false)
$partial = $resolvedOutput + '.partial'
if (Test-Path -LiteralPath $partial) { Fail 'partial_output_already_exists' }
[IO.File]::WriteAllText($partial, (($inventory | ConvertTo-Json -Depth 8) + "`n"), $utf8)
Move-Item -LiteralPath $partial -Destination $resolvedOutput
Write-Output "PRIVATE_SOURCE_FILES=$($records.Count)"
Write-Output "PRIVATE_SOURCE_BYTES=$totalBytes"
Write-Output 'PRIVATE_SOURCE_INVENTORY=PASS'
