[CmdletBinding()]
param(
    # Both arguments are intentionally caller-supplied: this tracked script
    # never embeds a workstation path or a private source name.
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SourceA,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$SourceB,

    # Private manifests can contain raw source paths.  Keep every report below
    # the ignored Class Archive private-work root, never beside originals.
    [string]$OutputRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = [IO.Path]::GetFullPath((Join-Path (Join-Path $PSScriptRoot '..') '..'))
$defaultOutput = Join-Path (Join-Path $projectRoot '.codex-work') 'private-real-qa'
if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = $defaultOutput
}

function Stop-PrivateMediaPreflight([string]$Reason) {
    [Console]::Error.WriteLine('PRIVATE_REAL_MEDIA_FIXTURE_PREFLIGHT=FAIL reason=' + $Reason)
    exit 2
}

function Normalize-ExistingDirectory([string]$Value, [string]$Reason) {
    try {
        $item = Get-Item -LiteralPath $Value -Force -ErrorAction Stop
    }
    catch {
        Stop-PrivateMediaPreflight $Reason
    }
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        Stop-PrivateMediaPreflight $Reason
    }
    return [IO.Path]::GetFullPath($item.FullName)
}

function Is-Within([string]$Candidate, [string]$Container) {
    $trimChars = [char[]]@('\', '/')
    $candidateFull = [IO.Path]::GetFullPath($Candidate).TrimEnd($trimChars)
    $containerFull = [IO.Path]::GetFullPath($Container).TrimEnd($trimChars)
    return $candidateFull.Equals($containerFull, [StringComparison]::OrdinalIgnoreCase) -or
        $candidateFull.StartsWith($containerFull + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)
}

function Assert-DirectoryListReadable([string]$Directory, [string]$Reason) {
    try {
        # This intentionally opens at most the root directory.  It does not
        # recurse, hash, decode, open, or write any source media file.
        $enumerator = [IO.Directory]::EnumerateFileSystemEntries($Directory).GetEnumerator()
        try { [void]$enumerator.MoveNext() }
        finally { if ($null -ne $enumerator) { $enumerator.Dispose() } }
    }
    catch {
        Stop-PrivateMediaPreflight $Reason
    }
}

try {
    $sourceARoot = Normalize-ExistingDirectory $SourceA 'source_a_invalid'
    $sourceBRoot = Normalize-ExistingDirectory $SourceB 'source_b_invalid'
    if ($sourceARoot.Equals($sourceBRoot, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateMediaPreflight 'source_roots_not_distinct'
    }
    Assert-DirectoryListReadable $sourceARoot 'source_a_not_readable'
    Assert-DirectoryListReadable $sourceBRoot 'source_b_not_readable'

    $ignoredRoot = [IO.Path]::GetFullPath($defaultOutput)
    $resolvedOutput = [IO.Path]::GetFullPath($OutputRoot)
    if (-not (Is-Within $resolvedOutput $ignoredRoot)) {
        Stop-PrivateMediaPreflight 'output_not_under_ignored_private_root'
    }
    if ((Is-Within $resolvedOutput $sourceARoot) -or (Is-Within $sourceARoot $resolvedOutput) -or
        (Is-Within $resolvedOutput $sourceBRoot) -or (Is-Within $sourceBRoot $resolvedOutput)) {
        Stop-PrivateMediaPreflight 'output_source_overlap'
    }

    if (-not (Is-Within $resolvedOutput $projectRoot)) {
        Stop-PrivateMediaPreflight 'output_outside_project'
    }
    # Windows PowerShell 5.1 runs on .NET Framework, which lacks
    # [IO.Path]::GetRelativePath. The containment check above makes this
    # suffix operation safe and keeps the script compatible with it.
    $pathTrimChars = [char[]]@('\', '/')
    $projectRootTrimmed = $projectRoot.TrimEnd($pathTrimChars)
    $relativeOutput = $resolvedOutput.Substring($projectRootTrimmed.Length).TrimStart($pathTrimChars).Replace('\', '/')
    & git -C $projectRoot check-ignore -q --no-index -- $relativeOutput
    if ($LASTEXITCODE -ne 0) {
        Stop-PrivateMediaPreflight 'output_not_git_ignored'
    }

    # A path matching .gitignore is not enough if someone force-added a file
    # beneath it in an earlier commit.  Refuse to share a report directory
    # with any tracked content before creating or replacing local evidence.
    $trackedOutput = @(& git -C $projectRoot ls-files --full-name -- $relativeOutput)
    if ($LASTEXITCODE -ne 0) {
        Stop-PrivateMediaPreflight 'output_tracking_probe_failed'
    }
    if ($trackedOutput.Count -ne 0) {
        Stop-PrivateMediaPreflight 'output_has_tracked_content'
    }

    # Existing ancestors must not use a reparse point that could move local
    # private manifests outside the checked ignored tree.
    $cursor = $resolvedOutput
    while (-not $cursor.Equals($projectRoot, [StringComparison]::OrdinalIgnoreCase)) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                Stop-PrivateMediaPreflight 'output_reparse_untrusted'
            }
        }
        $parent = [IO.Path]::GetDirectoryName($cursor)
        if ([string]::IsNullOrEmpty($parent) -or $parent.Equals($cursor, [StringComparison]::OrdinalIgnoreCase)) {
            Stop-PrivateMediaPreflight 'output_path_invalid'
        }
        $cursor = $parent
    }

    $reportDirectory = Join-Path $resolvedOutput 'reports'
    [IO.Directory]::CreateDirectory($reportDirectory) | Out-Null
    $report = [ordered]@{
        version = 1
        created_at = [DateTime]::UtcNow.ToString('o')
        scope = 'LOCAL_ONLY_PRE_INVENTORY'
        sources = @(
            [ordered]@{ label = 'Private Source A'; root_path_recorded = $false; root_directory = $true; root_reparse = $false; list_readable = $true; source_write_attempt = 'NOT_PERFORMED' },
            [ordered]@{ label = 'Private Source B'; root_path_recorded = $false; root_directory = $true; root_reparse = $false; list_readable = $true; source_write_attempt = 'NOT_PERFORMED' }
        )
        output = [ordered]@{ git_ignored = $true; tracked_content = $false; source_overlap = $false; reparse = $false }
        inventory = [ordered]@{ hashing = 'NOT_RUN'; recursion = 'NOT_RUN'; media_decode = 'NOT_RUN' }
        next_step = 'Run private-real-data-qa.py inventory with the same two approved roots and this ignored output root.'
    }
    $reportPath = Join-Path $reportDirectory 'private-media-fixture-preflight.json'
    $temporaryPath = $reportPath + '.tmp'
    [IO.File]::WriteAllText($temporaryPath, ($report | ConvertTo-Json -Depth 6), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $reportPath -Force
    Write-Output 'PRIVATE_REAL_MEDIA_FIXTURE_PREFLIGHT=PASS sources=2 output=IGNORED_PRIVATE_ROOT hashing=NOT_RUN source_writes=0'
}
catch {
    Stop-PrivateMediaPreflight 'preflight_runtime_error'
}
