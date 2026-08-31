[CmdletBinding()]
param()

# This is a static/synthetic-only protocol. It deliberately does not read a
# real photo source, start Docker, or touch a private Owner runtime.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path (Join-Path $PSScriptRoot '..') '..')).Path
$preflightPath = Join-Path $projectRoot 'infra\scripts\private-real-media-fixture-preflight.ps1'
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-Protocol $Text.Contains($Needle) $Code
}

function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-Protocol (-not $Text.Contains($Needle)) $Code
}

function Get-ProtocolPowerShellHost {
    foreach ($candidate in @(
        (Join-Path $PSHOME 'pwsh'),
        (Join-Path $PSHOME 'powershell.exe')
    )) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return $candidate
        }
    }
    foreach ($commandName in @('pwsh', 'powershell.exe', 'powershell')) {
        $command = Get-Command $commandName -ErrorAction SilentlyContinue
        if ($null -ne $command -and -not [string]::IsNullOrWhiteSpace([string]$command.Source)) {
            return [string]$command.Source
        }
    }
    throw 'protocol_powershell_host_missing'
}

function Invoke-PreflightChild([string]$PowerShellHost, [string]$SourceA, [string]$SourceB, [string]$OutputRoot) {
    $arguments = @(
        '-NoProfile',
        '-NonInteractive'
    )
    if ([IO.Path]::GetFileName($PowerShellHost).Equals('powershell.exe', [StringComparison]::OrdinalIgnoreCase)) {
        $arguments += @('-ExecutionPolicy', 'Bypass')
    }
    $arguments += @(
        '-File', $preflightPath,
        '-SourceA', $SourceA,
        '-SourceB', $SourceB,
        '-OutputRoot', $OutputRoot
    )
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # The negative cases intentionally return exit 2. Capture those child
        # process lines as data rather than letting PowerShell elevate them to
        # a terminating NativeCommandError in this protocol process.
        $ErrorActionPreference = 'Continue'
        $captured = @(& $PowerShellHost @arguments 2>&1)
        $exitCode = [int]$LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    return [pscustomobject]@{
        exit_code = $exitCode
        output = (@($captured | ForEach-Object { [string]$_ }) -join "`n")
    }
}

function Test-Within([string]$Candidate, [string]$Container) {
    $trimChars = [char[]]@('\', '/')
    $candidateFull = [IO.Path]::GetFullPath($Candidate).TrimEnd($trimChars)
    $containerFull = [IO.Path]::GetFullPath($Container).TrimEnd($trimChars)
    return $candidateFull.Equals($containerFull, [StringComparison]::OrdinalIgnoreCase) -or
        $candidateFull.StartsWith($containerFull + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)
}

function Remove-ProtocolDirectory([string]$Path, [string]$AllowedRoot, [string]$ExpectedPrefix) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $resolved = [IO.Path]::GetFullPath($Path)
    Assert-Protocol (Test-Within $resolved $AllowedRoot) 'protocol_cleanup_outside_ignored_root'
    Assert-Protocol ([IO.Path]::GetFileName($resolved).StartsWith($ExpectedPrefix, [StringComparison]::Ordinal)) 'protocol_cleanup_prefix_invalid'
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    Assert-Protocol (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) 'protocol_cleanup_reparse_refused'
    Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction Stop
}

Assert-Protocol (Test-Path -LiteralPath $preflightPath -PathType Leaf) 'private_media_preflight_tool_missing'
$source = [IO.File]::ReadAllText($preflightPath)
$tokens = $null
$parseErrors = $null
[void][Management.Automation.Language.Parser]::ParseFile($preflightPath, [ref]$tokens, [ref]$parseErrors)
Assert-Protocol (@($parseErrors).Count -eq 0) 'private_media_preflight_parse_invalid'

# Roots are only provided by the caller. The tracked implementation must not
# contain a known private mount, source collection name, profile lookup, or a
# hidden environment-based fallback.
foreach ($needle in @(
    '[string]$SourceA',
    '[string]$SourceB',
    '[string]$OutputRoot',
    'Normalize-ExistingDirectory $SourceA',
    'Normalize-ExistingDirectory $SourceB',
    '$defaultOutput = Join-Path (Join-Path $projectRoot ''.codex-work'') ''private-real-qa'''
)) {
    Assert-Contains $source $needle ('private_media_preflight_caller_input_contract_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$privateDrivePrefix = ([char]77).ToString() + ':' + ([char]92).ToString()
foreach ($forbidden in @(
    $privateDrivePrefix, 'CNZX', 'QQ相册', '毕业相册', '图片资源',
    '$env:', 'GetEnvironmentVariable', 'USERPROFILE', 'HOMEDRIVE',
    'Get-ChildItem', '-Recurse', 'Get-FileHash', 'Get-Content',
    'System.Drawing', 'ImageMagick', 'magick', 'ffmpeg', 'exiftool',
    'Pillow', 'Copy-Item', 'Remove-Item', 'Set-Content', 'Add-Content',
    'Invoke-WebRequest', 'Invoke-RestMethod'
)) {
    Assert-NotContains $source $forbidden ('private_media_preflight_forbidden_private_or_media_operation_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The only source I/O is a one-entry root-directory readability probe. It must
# happen after source roots are validated and must not recurse into files.
Assert-Contains $source 'function Assert-DirectoryListReadable' 'private_media_preflight_root_list_probe_missing'
Assert-Contains $source '[IO.Directory]::EnumerateFileSystemEntries($Directory).GetEnumerator()' 'private_media_preflight_nonrecursive_enumerator_missing'
Assert-Contains $source '[void]$enumerator.MoveNext()' 'private_media_preflight_root_readability_probe_missing'
Assert-Contains $source "source_write_attempt = 'NOT_PERFORMED'" 'private_media_preflight_source_no_write_attestation_missing'
Assert-Protocol (([regex]::Matches($source, [regex]::Escape('[IO.File]::WriteAllText'))).Count -eq 1) 'private_media_preflight_unexpected_file_write_surface'
Assert-Protocol (([regex]::Matches($source, [regex]::Escape('Move-Item -LiteralPath $temporaryPath -Destination $reportPath -Force'))).Count -eq 1) 'private_media_preflight_atomic_report_publish_missing'

# Report creation is permitted only after all containment, ignored-status,
# tracked-content, and reparse protections have passed.
foreach ($needle in @(
    'if (-not (Is-Within $resolvedOutput $ignoredRoot))',
    "Stop-PrivateMediaPreflight 'output_not_under_ignored_private_root'",
    'check-ignore -q --no-index -- $relativeOutput',
    'git -C $projectRoot ls-files --full-name -- $relativeOutput',
    "Stop-PrivateMediaPreflight 'output_has_tracked_content'",
    'function Is-Within',
    "Stop-PrivateMediaPreflight 'output_source_overlap'",
    "Stop-PrivateMediaPreflight 'output_reparse_untrusted'",
    "`$reportDirectory = Join-Path `$resolvedOutput 'reports'",
    'tracked_content = $false'
)) {
    Assert-Contains $source $needle ('private_media_preflight_output_boundary_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$outputContainment = $source.IndexOf('if (-not (Is-Within $resolvedOutput $ignoredRoot))', [StringComparison]::Ordinal)
$outputOverlap = $source.IndexOf("Stop-PrivateMediaPreflight 'output_source_overlap'", [StringComparison]::Ordinal)
$outputIgnore = $source.IndexOf('check-ignore -q --no-index -- $relativeOutput', [StringComparison]::Ordinal)
$outputTracked = $source.IndexOf('git -C $projectRoot ls-files --full-name -- $relativeOutput', [StringComparison]::Ordinal)
$outputCreate = $source.IndexOf('[IO.Directory]::CreateDirectory($reportDirectory)', [StringComparison]::Ordinal)
Assert-Protocol ($outputContainment -ge 0 -and $outputOverlap -gt $outputContainment -and $outputIgnore -gt $outputOverlap -and $outputTracked -gt $outputIgnore -and $outputCreate -gt $outputTracked) 'private_media_preflight_output_guards_must_precede_write'
Assert-Contains $source 'if ((Is-Within $resolvedOutput $sourceARoot) -or (Is-Within $sourceARoot $resolvedOutput) -or' 'private_media_preflight_source_a_overlap_bidirectional_guard_missing'
Assert-Contains $source '(Is-Within $resolvedOutput $sourceBRoot) -or (Is-Within $sourceBRoot $resolvedOutput))' 'private_media_preflight_source_b_overlap_bidirectional_guard_missing'

# No ignored evidence directory may already be tracked. This check does not
# inspect a real source directory; it only reads the Git index.
$trackedPrivateWork = @(& git -C $projectRoot ls-files --full-name -- '.codex-work')
Assert-Protocol ($LASTEXITCODE -eq 0) 'private_media_preflight_git_index_probe_failed'
Assert-Protocol ($trackedPrivateWork.Count -eq 0) 'private_media_preflight_private_work_tracked'

# Exercise the tool only with fresh empty directories created below ignored
# .codex-work. No media, real source, Docker, or network endpoint is involved.
$runId = [guid]::NewGuid().ToString('N')
$privateWorkRoot = Join-Path $projectRoot '.codex-work'
$privateQaRoot = Join-Path $privateWorkRoot 'private-real-qa'
$workRoot = Join-Path $privateWorkRoot ('phase3-media-preflight-protocol-' + $runId)
$privateOutputRoot = Join-Path $privateQaRoot ('protocol-preflight-' + $runId)
$overlapRoot = Join-Path $privateQaRoot ('protocol-overlap-' + $runId)
$sourceA = Join-Path $workRoot 'source-a'
$sourceB = Join-Path $workRoot 'source-b'
$overlapSourceA = Join-Path $overlapRoot 'source-a'
$engine = Get-ProtocolPowerShellHost

try {
    [IO.Directory]::CreateDirectory($sourceA) | Out-Null
    [IO.Directory]::CreateDirectory($sourceB) | Out-Null
    [IO.Directory]::CreateDirectory($overlapSourceA) | Out-Null

    $success = Invoke-PreflightChild $engine $sourceA $sourceB $privateOutputRoot
    Assert-Protocol ($success.exit_code -eq 0) 'private_media_preflight_synthetic_success_exit_invalid'
    Assert-Contains $success.output 'PRIVATE_REAL_MEDIA_FIXTURE_PREFLIGHT=PASS sources=2 output=IGNORED_PRIVATE_ROOT hashing=NOT_RUN source_writes=0' 'private_media_preflight_synthetic_success_evidence_invalid'
    $reportPath = Join-Path (Join-Path $privateOutputRoot 'reports') 'private-media-fixture-preflight.json'
    Assert-Protocol (Test-Path -LiteralPath $reportPath -PathType Leaf) 'private_media_preflight_synthetic_report_missing'
    $reportText = [IO.File]::ReadAllText($reportPath)
    Assert-NotContains $reportText $sourceA 'private_media_preflight_report_leaked_source_a'
    Assert-NotContains $reportText $sourceB 'private_media_preflight_report_leaked_source_b'
    $report = $reportText | ConvertFrom-Json
    Assert-Protocol ($report.output.git_ignored -eq $true -and $report.output.tracked_content -eq $false -and $report.output.source_overlap -eq $false) 'private_media_preflight_synthetic_report_boundary_invalid'
    Assert-Protocol ($report.inventory.hashing -eq 'NOT_RUN' -and $report.inventory.recursion -eq 'NOT_RUN' -and $report.inventory.media_decode -eq 'NOT_RUN') 'private_media_preflight_synthetic_report_scope_invalid'
    Assert-Protocol (@(Get-ChildItem -LiteralPath $sourceA -Force).Count -eq 0 -and @(Get-ChildItem -LiteralPath $sourceB -Force).Count -eq 0) 'private_media_preflight_synthetic_source_mutated'

    $outside = Invoke-PreflightChild $engine $sourceA $sourceB (Join-Path $workRoot 'outside-private-output')
    Assert-Protocol ($outside.exit_code -eq 2) 'private_media_preflight_outside_output_exit_invalid'
    Assert-Contains $outside.output 'output_not_under_ignored_private_root' 'private_media_preflight_outside_output_not_rejected'

    $overlap = Invoke-PreflightChild $engine $overlapSourceA $sourceB $overlapSourceA
    Assert-Protocol ($overlap.exit_code -eq 2) 'private_media_preflight_overlap_exit_invalid'
    Assert-Contains $overlap.output 'output_source_overlap' 'private_media_preflight_overlap_not_rejected'
    Assert-Protocol (@(Get-ChildItem -LiteralPath $overlapSourceA -Force).Count -eq 0) 'private_media_preflight_overlap_source_mutated'

    $trackedAfter = @(& git -C $projectRoot ls-files --full-name -- '.codex-work')
    Assert-Protocol ($LASTEXITCODE -eq 0 -and $trackedAfter.Count -eq 0) 'private_media_preflight_synthetic_output_tracked'
}
finally {
    Remove-ProtocolDirectory $workRoot $privateWorkRoot 'phase3-media-preflight-protocol-'
    Remove-ProtocolDirectory $privateOutputRoot $privateQaRoot 'protocol-preflight-'
    Remove-ProtocolDirectory $overlapRoot $privateQaRoot 'protocol-overlap-'
}

Write-Output "PRIVATE_REAL_MEDIA_FIXTURE_PREFLIGHT_PROTOCOL=PASS assertions=$assertions evidence=STATIC_PLUS_SYNTHETIC_EMPTY_DIRECTORIES"
