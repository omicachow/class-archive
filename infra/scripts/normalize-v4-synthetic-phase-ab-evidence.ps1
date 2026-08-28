[CmdletBinding()]
param(
    # These are raw, already-produced Synthetic 8091 runner transcripts.  The
    # normalizer never launches Chrome, Docker, WSL, HTTP probes, or any
    # Owner/private stack; it narrows existing local evidence only.
    [Parameter(Mandatory = $true)]
    [string]$ChromeMainTranscript,

    [Parameter(Mandatory = $true)]
    [string]$ChromeDeepTranscript,

    [Parameter(Mandatory = $true)]
    [string]$ScopeTranscript,

    [Parameter(Mandatory = $true)]
    [string]$UploadTranscript,

    [Parameter(Mandatory = $true)]
    [string]$RestartTranscript,

    # A timestamped, leaf-only token makes the output immutable without
    # printing or accepting a host-private destination path.
    [string]$EvidenceName
)

# Convert verbose, ignored local test output into narrow result and terminal
# completion records consumed by attest-v4-synthetic-phase-ab.ps1. Raw runner output can
# legitimately contain local screenshot paths; those paths are never copied
# to the safe evidence directory or to the durable migration attestation.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$rawRoot = Join-Path $projectRoot '.codex-work'
$attestationRoot = Join-Path $rawRoot 'v4-synthetic-acceptance'
$evidenceRoot = Join-Path $attestationRoot 'evidence'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-V4SyntheticEvidence([string]$Code) {
    throw [InvalidOperationException]::new('V4_SYNTHETIC_EVIDENCE_STOP:' + $Code)
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { Stop-V4SyntheticEvidence 'path_outside_checkout' }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredDirectory([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetFullPath($rawRoot).TrimEnd('\')
    if (-not [string]::Equals($full.TrimEnd('\'), $root, [StringComparison]::OrdinalIgnoreCase) -and
        -not $full.StartsWith($root + '\', [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4SyntheticEvidence 'ignored_workspace_required'
    }
    [void][IO.Directory]::CreateDirectory($full)
    $item = Get-Item -LiteralPath $full -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V4SyntheticEvidence 'ignored_directory_untrusted' }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -ne 0) { Stop-V4SyntheticEvidence 'ignored_directory_not_private' }
    return $item.FullName
}

function Read-RawTranscript([string]$Path, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $parent = Split-Path -Parent $full
    [void](Assert-IgnoredDirectory $parent)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V4SyntheticEvidence $Code }
    $item = Get-Item -LiteralPath $full -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) -or $item.Length -gt 1048576) { Stop-V4SyntheticEvidence $Code }
    try {
        $bytes = [IO.File]::ReadAllBytes($item.FullName)
        $text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes)
    }
    catch { Stop-V4SyntheticEvidence $Code }
    if ($text.Contains("`0")) { Stop-V4SyntheticEvidence $Code }
    $lines = @($text -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    if (@($lines | Where-Object { $_ -match '=FAIL\b' }).Count -ne 0) { Stop-V4SyntheticEvidence $Code }
    return $lines
}

function Select-ExactlyOneSafeLine([string[]]$Lines, [string]$Pattern, [string]$Code) {
    $matches = @($Lines | Where-Object { $_ -match $Pattern })
    if ($matches.Count -ne 1) { Stop-V4SyntheticEvidence $Code }
    return [string]$matches[0]
}

function Select-TerminalCompletionLine([string[]]$Lines, [string]$Pattern, [string]$Code) {
    $completion = Select-ExactlyOneSafeLine $Lines $Pattern $Code
    # A runner only emits this record after its finally block completed its
    # cleanup/baseline checks. A pass line earlier in raw stdout is therefore
    # insufficient: any trailing output, including a generic cleanup error,
    # invalidates the transcript before it can be narrowed into evidence.
    if ($Lines.Count -lt 2 -or $Lines[$Lines.Count - 1] -cne $completion) { Stop-V4SyntheticEvidence $Code }
    return $completion
}

function Write-SafeEvidenceFile([string]$Directory, [string]$Leaf, [string[]]$Lines) {
    if ($Leaf -notmatch '^[a-z0-9-]{3,64}\.out$' -or $Lines.Count -eq 0) { Stop-V4SyntheticEvidence 'safe_evidence_shape_invalid' }
    $path = Join-Path $Directory $Leaf
    if (Test-Path -LiteralPath $path) { Stop-V4SyntheticEvidence 'safe_evidence_already_exists' }
    foreach ($line in $Lines) {
        # The caller passes only exact protocol records.  Enforce that the
        # normalized files can never become an arbitrary log sink.
        if ($line -match '[\r\n\x00]' -or $line.Length -gt 512) { Stop-V4SyntheticEvidence 'safe_evidence_line_invalid' }
    }
    [IO.File]::WriteAllText($path, (($Lines -join [Environment]::NewLine) + [Environment]::NewLine), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
}

try {
    $mainPattern = '^V4_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
    $mainCompletionPattern = '^V4_CHROME_QA_COMPLETE=PASS$'
    $deepPattern = '^V4_CHROME_DEEP_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
    $mediaPattern = '^V4_CHROME_DEEP_MEDIAGUARD=PASS source=dev\.ps1:test-phase0\+test-phase1$'
    $deepCompletionPattern = '^V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS$'
    $scopePattern = '^V4_SCOPE_PROJECTION=PASS assertions=[0-9]+ screenshots=[0-9]+ chrome_version=[0-9.]+ people_required=yes$'
    $scopeCompletionPattern = '^V4_SCOPE_PROJECTION_COMPLETE=PASS$'
    $uploadPattern = '^V4_CHROME_UPLOAD_LIFECYCLE=PASS assertions=[0-9]+ uploads=5 channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
    $uploadCompletionPattern = '^V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS$'
    $restartPattern = '^V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8$'
    $restartCompletionPattern = '^V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS$'

    # Parse and validate every input before creating an immutable output
    # directory, so malformed evidence never leaves a partial acceptance set.
    $mainLines = Read-RawTranscript $ChromeMainTranscript 'chrome_main_transcript_invalid'
    $mainLine = Select-ExactlyOneSafeLine $mainLines $mainPattern 'chrome_main_transcript_invalid'
    $mainCompletion = Select-TerminalCompletionLine $mainLines $mainCompletionPattern 'chrome_main_transcript_incomplete'
    $deepLines = Read-RawTranscript $ChromeDeepTranscript 'chrome_deep_transcript_invalid'
    $deepLine = Select-ExactlyOneSafeLine $deepLines $deepPattern 'chrome_deep_transcript_invalid'
    $mediaLine = Select-ExactlyOneSafeLine $deepLines $mediaPattern 'chrome_deep_mediaguard_missing'
    $deepCompletion = Select-TerminalCompletionLine $deepLines $deepCompletionPattern 'chrome_deep_transcript_incomplete'
    $scopeLines = Read-RawTranscript $ScopeTranscript 'scope_transcript_invalid'
    $scopeLine = Select-ExactlyOneSafeLine $scopeLines $scopePattern 'scope_transcript_invalid'
    $scopeCompletion = Select-TerminalCompletionLine $scopeLines $scopeCompletionPattern 'scope_transcript_incomplete'
    $uploadLines = Read-RawTranscript $UploadTranscript 'upload_transcript_invalid'
    $uploadLine = Select-ExactlyOneSafeLine $uploadLines $uploadPattern 'upload_transcript_invalid'
    $uploadCompletion = Select-TerminalCompletionLine $uploadLines $uploadCompletionPattern 'upload_transcript_incomplete'
    $restartLines = Read-RawTranscript $RestartTranscript 'restart_transcript_invalid'
    $restartLine = Select-ExactlyOneSafeLine $restartLines $restartPattern 'restart_transcript_invalid'
    $restartCompletion = Select-TerminalCompletionLine $restartLines $restartCompletionPattern 'restart_transcript_incomplete'

    if ([string]::IsNullOrWhiteSpace($EvidenceName)) { $EvidenceName = 'v4-synthetic-phase-ab-evidence-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') }
    if ($EvidenceName -notmatch '^v4-synthetic-phase-ab-evidence-[0-9]{8}T[0-9]{6}Z$') { Stop-V4SyntheticEvidence 'evidence_name_invalid' }
    [void](Assert-IgnoredDirectory $attestationRoot)
    [void](Assert-IgnoredDirectory $evidenceRoot)
    $outputDirectory = Join-Path $evidenceRoot $EvidenceName
    if (Test-Path -LiteralPath $outputDirectory) { Stop-V4SyntheticEvidence 'evidence_directory_already_exists' }
    [void](Assert-IgnoredDirectory $outputDirectory)

    Write-SafeEvidenceFile $outputDirectory 'chrome-main.out' @($mainLine, $mainCompletion)
    Write-SafeEvidenceFile $outputDirectory 'chrome-deep.out' @($deepLine, $mediaLine, $deepCompletion)
    Write-SafeEvidenceFile $outputDirectory 'scope.out' @($scopeLine, $scopeCompletion)
    Write-SafeEvidenceFile $outputDirectory 'upload.out' @($uploadLine, $uploadCompletion)
    Write-SafeEvidenceFile $outputDirectory 'restart.out' @($restartLine, $restartCompletion)

    # Intentionally not a V4 gate PASS: the next attester binds these safe
    # records to the exact checked-out source and immutable gate record.
    Write-Output ('V4_SYNTHETIC_PHASE_AB_EVIDENCE=PASS evidence=' + $EvidenceName + ' records=11 privacy=SAFE_PROTOCOL_LINES_ONLY')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^V4_SYNTHETIC_EVIDENCE_STOP:([a-z0-9_]{1,128})$') { $Matches[1] } else { 'v4_synthetic_evidence_failed' }
    Write-Output "V4_SYNTHETIC_PHASE_AB_EVIDENCE=FAIL code=$code"
    exit 2
}
