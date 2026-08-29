<#
.SYNOPSIS
Runs one isolated, direct-current-source synthetic V16 -> V18 migration proof.

.DESCRIPTION
This is deliberately narrower than v18-synthetic-migration.ps1.  It has one
hard-coded laboratory identity: attempt26 on loopback ports 11090/11091.  The
runner may ask the existing runner to initialise and DB-only restore that
fresh laboratory, but it never calls its historical V17 bootstrap or migrate
actions.  The proof itself is always the current checked-out
v16-to-v18-synthetic-direct-proof.php script, executed as the image's nginx
account with its explicit synthetic scope gates.

No cleanup action exists. Any failed laboratory state is retained for
forensics. attempt13 is preserved after an interrupted Docker recovery and
attempt14 is preserved after its valid proof plus rejected pre-fix attestation,
and attempt15 is preserved after its interrupted V16 restore; attempts16-22
remain preserved after their respective source/host failures; attempt23 is
preserved after its cold-start Compose record ambiguity; attempt24 is
preserved after its nested pipe stall; attempt25 is preserved after its
unexpected child exit; attempt26 is the one new fixed, empty replacement
laboratory.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'initialize', 'restore', 'restore-and-prove', 'prove', 'verify')]
    [string]$Action = 'status',
    [switch]$ConfirmSyntheticRestore,
    [switch]$ConfirmSyntheticMigration
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wsl = "$env:SystemRoot\System32\wsl.exe"
$windowsPowerShell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$attempt = 'attempt26'
$httpPort = '11090'
$compatPort = '11091'
$composeProject = 'class_archive_v18_synthetic_migration_attempt26'
$baseRunner = Join-Path $PSScriptRoot 'v18-synthetic-migration.ps1'
$proofPath = Join-Path $PSScriptRoot 'v16-to-v18-synthetic-direct-proof.php'
$sandboxRoot = Join-Path $projectRoot ('.codex-work\v18-synthetic-migration-' + $attempt)
$configRoot = Join-Path $sandboxRoot 'config'
$reportRoot = Join-Path $sandboxRoot 'reports'
$captureRoot = Join-Path $sandboxRoot 'base-runner-capture'
$envPath = Join-Path $configRoot '.env.piwigo'
$reportPath = Join-Path $reportRoot 'v16-to-v18-direct-proof.json'
$composePath = 'infra/docker-compose.yml'
$overridePath = 'infra/v18-synthetic-migration/docker-compose.override.yml'
$proofSourcePaths = @(
    'infra/docker-compose.yml',
    'infra/v18-synthetic-migration/docker-compose.override.yml',
    'infra/scripts/v18-synthetic-db-probe.sh',
    'infra/scripts/v18-synthetic-migration.ps1',
    'infra/scripts/create-pre-migration-db-snapshot.sh',
    'infra/scripts/restore-v4-synthetic-pre-migration-db.sh',
    'infra/scripts/v16-to-v18-synthetic-direct-proof.php',
    'infra/scripts/v16-to-v18-synthetic-direct-runtime.ps1',
    'infra/scripts/attest-v16-to-v18-synthetic-direct-runtime.ps1',
    'plugins/ClassIdentity/src/Schema.php'
)
$script:stage = 'initialization'

function Stop-V16ToV18DirectRuntime([string]$Code) {
    throw [InvalidOperationException]::new('V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME_STOP:' + $Code)
}

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
        Stop-V16ToV18DirectRuntime 'file_hash_runtime_failed'
    }
    if ([string]$hash -notmatch '^[a-fA-F0-9]{64}$') { Stop-V16ToV18DirectRuntime 'file_hash_result_invalid' }
    return ([string]$hash).ToLowerInvariant()
}

function Write-V16ToV18DirectRuntime([string]$State, [string]$Stage, [string]$Extra = '') {
    $suffix = if ([string]::IsNullOrWhiteSpace($Extra)) { '' } else { ' ' + $Extra }
    Write-Output ("V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME={0} stage={1}{2}" -f $State, $Stage, $suffix)
}

function Assert-PathInside([string]$Path, [string]$Root, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not (($full + [IO.Path]::DirectorySeparatorChar).StartsWith($rootFull, [StringComparison]::OrdinalIgnoreCase))) {
        Stop-V16ToV18DirectRuntime 'path_outside_allowed_root'
    }
    if ($MustExist -and -not (Test-Path -LiteralPath $full)) { Stop-V16ToV18DirectRuntime 'required_path_missing' }
    $cursor = if (Test-Path -LiteralPath $full) { Get-Item -LiteralPath $full -Force } else { Get-Item -LiteralPath (Split-Path -Parent $full) -Force }
    while ($null -ne $cursor) {
        if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V16ToV18DirectRuntime 'reparse_point_forbidden' }
        $cursor = if ($cursor -is [IO.DirectoryInfo]) { $cursor.Parent } else { $cursor.Directory }
    }
    return $full
}

function Assert-IgnoredUntracked([string]$Path, [bool]$Directory, [bool]$MustExist = $true) {
    $full = Assert-PathInside $Path $projectRoot $MustExist
    if ($MustExist) {
        $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
        if (($Directory -and -not $item.PSIsContainer) -or (-not $Directory -and $item.PSIsContainer)) {
            Stop-V16ToV18DirectRuntime 'ignored_path_type_invalid'
        }
    }
    $relative = $full.Substring($projectRoot.TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectRuntime 'sandbox_path_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V16ToV18DirectRuntime 'sandbox_path_tracked' }
    return $full
}

function Assert-TrackedLeaf([string]$Path) {
    $full = Assert-PathInside $Path $projectRoot $true
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    if ($item.PSIsContainer) { Stop-V16ToV18DirectRuntime 'tracked_path_not_leaf' }
    $relative = $full.Substring($projectRoot.TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\','/')
    $tracked = @(& git -C $projectRoot ls-files --error-unmatch -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 1) { Stop-V16ToV18DirectRuntime 'required_tracked_source_missing' }
    return $full
}

function Get-Head {
    $head = @(& git -C $projectRoot rev-parse --verify HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $head.Count -ne 1 -or ([string]$head[0]).Trim() -notmatch '^[a-f0-9]{40}$') {
        Stop-V16ToV18DirectRuntime 'git_head_invalid'
    }
    return ([string]$head[0]).Trim()
}

function ConvertTo-NormalizedSourceEntries([object[]]$Entries, [string]$InvalidCode) {
    $normalized = @($Entries | ForEach-Object {
        $path = [string]$_.path
        $sha256 = [string]$_.sha256
        if ($path -notmatch '^[A-Za-z0-9_./-]+$' -or $path.Contains('..') -or $sha256 -notmatch '^[a-f0-9]{64}$') {
            Stop-V16ToV18DirectRuntime $InvalidCode
        }
        [pscustomobject]@{ path = $path; sha256 = $sha256 }
    } | Sort-Object -Property path)
    if ($normalized.Count -ne $Entries.Count -or @($normalized.path | Select-Object -Unique).Count -ne $normalized.Count) {
        Stop-V16ToV18DirectRuntime $InvalidCode
    }
    return $normalized
}

function Get-ProofSourceClosure {
    # A runtime proof is evidence for exact executable sources, not merely the
    # attempt16 database state. Require a clean, tracked source closure before
    # it mutates the synthetic lab and record its deterministic digest.
    $head = Get-Head
    $records = [System.Collections.Generic.List[object]]::new()
    foreach ($relative in $proofSourcePaths) {
        if ($relative -notmatch '^[A-Za-z0-9_./-]+$' -or $relative.Contains('..')) {
            Stop-V16ToV18DirectRuntime 'proof_source_path_invalid'
        }
        $full = Assert-TrackedLeaf (Join-Path $projectRoot $relative)
        & git -C $projectRoot diff --quiet -- $relative
        if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectRuntime 'proof_source_worktree_not_head_bound' }
        & git -C $projectRoot diff --cached --quiet -- $relative
        if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectRuntime 'proof_source_index_not_head_bound' }
        [void]$records.Add([pscustomobject]@{ path = $relative; sha256 = Get-FileSha256 $full })
    }
    $ordered = ConvertTo-NormalizedSourceEntries @($records) 'proof_source_entry_invalid'
    $material = [string]::Join("`n", @($ordered | ForEach-Object { $_.path + "`0" + $_.sha256 })) + "`n"
    $bytes = [Text.Encoding]::UTF8.GetBytes($material)
    $digest = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash($bytes)).Replace('-','').ToLowerInvariant()
    if ($head -ne (Get-Head)) { Stop-V16ToV18DirectRuntime 'proof_source_head_changed_during_capture' }
    return [ordered]@{ Commit = $head; SourceDigest = $digest }
}

function Assert-ProofSourceClosure([hashtable]$Expected, [string]$Code) {
    if ($null -eq $Expected -or [string]$Expected.Commit -notmatch '^[a-f0-9]{40}$' -or [string]$Expected.SourceDigest -notmatch '^[a-f0-9]{64}$') {
        Stop-V16ToV18DirectRuntime ($Code + '_reference_invalid')
    }
    $actual = Get-ProofSourceClosure
    if (-not [string]::Equals([string]$Expected.Commit,[string]$actual.Commit,[StringComparison]::Ordinal) -or
        -not [string]::Equals([string]$Expected.SourceDigest,[string]$actual.SourceDigest,[StringComparison]::Ordinal)) {
        Stop-V16ToV18DirectRuntime $Code
    }
    return $actual
}

function Invoke-NativeCapture([string]$FileName, [string[]]$Arguments, [string]$FailureCode, [string]$ChildFailureStopPrefix = '', [int]$TimeoutSeconds = 240) {
    # Every argument is fixed by this runner or an independently validated
    # local path.  Keep the native command-line surface intentionally simple:
    # paths with whitespace or quotes are rejected rather than re-quoted.
    foreach ($argument in $Arguments) {
        $value = [string]$argument
        if ($value -match '[\s\"]' -or $value.Contains("`0")) { Stop-V16ToV18DirectRuntime 'native_argument_invalid' }
    }
    if ($TimeoutSeconds -lt 30 -or $TimeoutSeconds -gt 600) { Stop-V16ToV18DirectRuntime 'native_timeout_invalid' }
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $FileName
    $info.Arguments = $Arguments -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V16ToV18DirectRuntime ($FailureCode + '_start_failed') }
    try {
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $timedOut = -not $process.WaitForExit($TimeoutSeconds * 1000)
        if ($timedOut) {
            try { $process.Kill() } catch { }
            $process.WaitForExit()
        }
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        $exitCode = $process.ExitCode
    } finally {
        $process.Dispose()
    }
    # Deliberately never echo stderr: an engine error can include ignored
    # synthetic credentials created by the base runner. For the one nested
    # base-runner call, a strictly bounded STOP code can be extracted solely
    # to diagnose the next fresh laboratory without exposing its stderr.
    if ($timedOut) { Stop-V16ToV18DirectRuntime ($FailureCode + '_timeout') }
    if ($exitCode -ne 0) {
        if ($ChildFailureStopPrefix -ne '') {
            if ($ChildFailureStopPrefix -notmatch '^V18_SYNTHETIC_MIGRATION_STOP:$') { Stop-V16ToV18DirectRuntime 'child_failure_prefix_invalid' }
            $match = [regex]::Match($stderr, ([regex]::Escape($ChildFailureStopPrefix) + '([a-z0-9_]{1,96})'))
            if ($match.Success) { Stop-V16ToV18DirectRuntime ($FailureCode + '_' + $match.Groups[1].Value) }
        }
        Stop-V16ToV18DirectRuntime $FailureCode
    }
    return @($stdout -split "`r?`n" | Where-Object { $_ -ne '' })
}

function Get-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if ($full -match '[\s\"]' -or $full.Contains("`0")) { Stop-V16ToV18DirectRuntime 'wsl_path_argument_invalid' }
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $wsl
    $info.Arguments = (@('-d','Ubuntu','--exec','wslpath','-a',$full) -join ' ')
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $info.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V16ToV18DirectRuntime 'wsl_path_conversion_start_failed' }
    try {
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        $exitCode = $process.ExitCode
    }
    finally { $process.Dispose() }
    if ($exitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($stderr)) { Stop-V16ToV18DirectRuntime 'wsl_path_conversion_failed' }
    $result = @($stdout -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($result.Count -ne 1) { Stop-V16ToV18DirectRuntime 'wsl_path_conversion_invalid' }
    $value = ([string]$result[0]).Trim()
    if ($value -notmatch '^/mnt/[a-z]/' -or $value.Contains('..') -or $value.Contains('//') -or $value -match '\s') {
        Stop-V16ToV18DirectRuntime 'wsl_path_invalid'
    }
    return $value
}

function Assert-DockerDesktopEnginePipe {
    # The direct synthetic lab is a Windows/WSL runner. Fail before invoking
    # the base runner or Docker compose when the Desktop Linux engine cannot
    # accept commands, preserving the forensic lab and avoiding hung probes.
    $pipes = @('\\.\pipe\dockerDesktopLinuxEngine','\\.\pipe\docker_engine')
    if (@($pipes | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) {
        Stop-V16ToV18DirectRuntime 'docker_engine_pipe_unavailable'
    }
}

function New-BaseRunnerCapturePaths([string]$BaseAction) {
    if ($BaseAction -notin @('initialize','restore')) { Stop-V16ToV18DirectRuntime 'base_capture_action_invalid' }
    if (-not (Test-Path -LiteralPath $captureRoot)) {
        New-Item -ItemType Directory -Path $captureRoot -Force | Out-Null
    }
    Assert-IgnoredUntracked $captureRoot $true | Out-Null
    # Nested PowerShell pipe capture can stall while Docker Desktop emits
    # cold-start compose status. Capture child output only in this ignored,
    # owner-only root instead. The files can contain synthetic diagnostics, so
    # they are never echoed and never become tracked evidence.
    & icacls.exe $captureRoot /inheritance:r /grant:r "${env:USERNAME}:(OI)(CI)(F)" | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-V16ToV18DirectRuntime 'base_capture_acl_failed' }
    $name = $BaseAction + '-' + [guid]::NewGuid().ToString('N')
    return [ordered]@{
        Stdout = Join-Path $captureRoot ($name + '.stdout.log')
        Stderr = Join-Path $captureRoot ($name + '.stderr.log')
    }
}

function Invoke-BaseRunner([string]$BaseAction, [switch]$RestoreConfirmation) {
    if ($BaseAction -notin @('initialize','restore')) { Stop-V16ToV18DirectRuntime 'base_action_forbidden' }
    Assert-DockerDesktopEnginePipe
    Assert-TrackedLeaf $baseRunner | Out-Null
    if (-not (Test-Path -LiteralPath $windowsPowerShell -PathType Leaf)) { Stop-V16ToV18DirectRuntime 'windows_powershell_missing' }
    $arguments = [System.Collections.Generic.List[string]]::new()
    foreach ($part in @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$baseRunner,'-Action',$BaseAction,'-Attempt',$attempt)) {
        [void]$arguments.Add([string]$part)
    }
    if ($RestoreConfirmation) { [void]$arguments.Add('-ConfirmSyntheticRestore') }
    $capture = New-BaseRunnerCapturePaths $BaseAction
    $process = Start-Process -FilePath $windowsPowerShell -ArgumentList $arguments.ToArray() -RedirectStandardOutput $capture.Stdout -RedirectStandardError $capture.Stderr -PassThru
    if (-not $process.WaitForExit(240000)) {
        try { Stop-Process -Id $process.Id -Force } catch { }
        $process.WaitForExit()
        Stop-V16ToV18DirectRuntime ('base_runner_' + $BaseAction + '_timeout')
    }
    $exitCode = $process.ExitCode
    $process.Dispose()
    foreach ($path in @([string]$capture.Stdout,[string]$capture.Stderr)) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-V16ToV18DirectRuntime 'base_capture_missing' }
        if ((Get-Item -LiteralPath $path -Force).Length -gt 1048576) { Stop-V16ToV18DirectRuntime 'base_capture_too_large' }
    }
    $stdout = [IO.File]::ReadAllText([string]$capture.Stdout,[Text.UTF8Encoding]::new($false))
    $stderr = [IO.File]::ReadAllText([string]$capture.Stderr,[Text.UTF8Encoding]::new($false))
    if ($exitCode -ne 0) {
        $match = [regex]::Match($stderr, ([regex]::Escape('V18_SYNTHETIC_MIGRATION_STOP:') + '([a-z0-9_]{1,96})'))
        if ($match.Success) { Stop-V16ToV18DirectRuntime ('base_runner_' + $BaseAction + '_failed_' + $match.Groups[1].Value) }
        $safeExitCode = [Math]::Abs([int]$exitCode)
        Stop-V16ToV18DirectRuntime ('base_runner_' + $BaseAction + '_failed_exit_' + $safeExitCode)
    }
    return @($stdout -split "`r?`n" | Where-Object { $_ -ne '' })
}

function Get-SandboxValues {
    Assert-IgnoredUntracked $sandboxRoot $true | Out-Null
    Assert-IgnoredUntracked $configRoot $true | Out-Null
    Assert-IgnoredUntracked $reportRoot $true | Out-Null
    Assert-IgnoredUntracked $envPath $false | Out-Null
    $contents = [IO.File]::ReadAllText($envPath, [Text.UTF8Encoding]::new($false))
    if ($contents.Contains("`0")) { Stop-V16ToV18DirectRuntime 'sandbox_env_invalid' }
    $values = @{}
    foreach ($line in ($contents -split "`r?`n")) {
        if ($line -eq '') { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-V16ToV18DirectRuntime 'sandbox_env_line_invalid' }
        $key = [string]$Matches[1]
        if ($values.ContainsKey($key)) { Stop-V16ToV18DirectRuntime 'sandbox_env_duplicate_key' }
        $values[$key] = [string]$Matches[2]
    }
    foreach ($expected in @{
        COMPOSE_PROJECT_NAME = $composeProject
        CLASS_ARCHIVE_HTTP_PORT = $httpPort
        CLASS_ARCHIVE_COMPAT_HTTP_PORT = $compatPort
        CLASS_ARCHIVE_CORE_PUBLIC_PORT = $httpPort
        CLASS_ARCHIVE_BASE_URL = ('http://127.0.0.1:' + $httpPort)
        CLASS_ARCHIVE_V18_SANDBOX_APP_NETWORK = ($composeProject + '_app')
        CLASS_ARCHIVE_V18_GATEWAY_NETWORK = ($composeProject + '_gateway')
    }.GetEnumerator()) {
        if (-not $values.ContainsKey($expected.Key) -or -not [string]::Equals([string]$values[$expected.Key], [string]$expected.Value, [StringComparison]::Ordinal)) {
            Stop-V16ToV18DirectRuntime ('sandbox_env_contract_invalid_' + $expected.Key.ToLowerInvariant())
        }
    }
    return $values
}

function Assert-DirectRuntimeSources {
    Assert-TrackedLeaf $baseRunner | Out-Null
    Assert-TrackedLeaf $proofPath | Out-Null
    $proof = [IO.File]::ReadAllText($proofPath)
    foreach ($required in @('CLASS_ARCHIVE_V16_TO_V18_DIRECT_PROOF','CLASS_ARCHIVE_RUNTIME_SCOPE','SYNTHETIC_V4_MIGRATION','--migrate-current-source','--verify-current-source','--fail-closed')) {
        if (-not $proof.Contains($required)) { Stop-V16ToV18DirectRuntime 'direct_proof_source_contract_invalid' }
    }
    foreach ($forbidden in @('bootstrap-v17','V18_SYNTHETIC_V17_SCHEMA','LoadHistoricalSchema')) {
        if ($proof.Contains($forbidden)) { Stop-V16ToV18DirectRuntime 'direct_proof_historical_bridge_detected' }
    }
}

function Invoke-DirectCompose([string[]]$ComposeArguments) {
    Assert-DockerDesktopEnginePipe
    $wslRoot = Get-WslPath $projectRoot
    $wslEnv = Get-WslPath $envPath
    $all = @('-d','Ubuntu','--cd',$wslRoot,'--exec','docker','compose','--env-file',$wslEnv,'-f',$composePath,'-f',$overridePath) + $ComposeArguments
    return @(Invoke-NativeCapture $wsl $all ('direct_compose_failed_' + $script:stage))
}

function Get-DirectSchemaVersion {
    $lines = @(Invoke-DirectCompose @('exec','-T','db','sh','/workspace/infra/scripts/v18-synthetic-db-probe.sh','schema'))
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(16|18)$') { Stop-V16ToV18DirectRuntime 'direct_schema_probe_invalid' }
    return [int]$lines[0]
}

function Invoke-DirectProof([string]$Mode) {
    if ($Mode -notin @('--migrate-current-source','--verify-current-source','--fail-closed')) { Stop-V16ToV18DirectRuntime 'direct_proof_mode_invalid' }
    $script:stage = $Mode.TrimStart('-')
    $lines = @(Invoke-DirectCompose @(
        'exec','-T','--user','nginx',
        '-e','CLASS_ARCHIVE_V16_TO_V18_DIRECT_PROOF=1',
        '-e','CLASS_ARCHIVE_RUNTIME_SCOPE=SYNTHETIC_V4_MIGRATION',
        'piwigo','php','/workspace/infra/scripts/v16-to-v18-synthetic-direct-proof.php',$Mode
    ))
    $records = @($lines | Where-Object { $_ -match '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS ' })
    if ($records.Count -ne 1) { Stop-V16ToV18DirectRuntime ('direct_proof_evidence_invalid_' + $script:stage) }
    return [string]$records[0]
}

function Get-RecordField([string]$Record, [string]$Name) {
    $match = [regex]::Match($Record, ('(?:^|\s)' + [regex]::Escape($Name) + '=([^\s]+)'))
    if (-not $match.Success) { Stop-V16ToV18DirectRuntime ('direct_proof_field_missing_' + $Name) }
    return $match.Groups[1].Value
}

function Assert-FirstMigrationRecord([string]$Record) {
    if ($Record -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE legacy_tables_preserved=PASS new_tables=EMPTY new_table_count=7 legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$') {
        Stop-V16ToV18DirectRuntime 'direct_first_migration_evidence_invalid'
    }
    return Get-RecordField $Record 'legacy_fingerprint'
}

function Assert-ReplayRecord([string]$Record, [string]$Fingerprint) {
    if ($Record -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=migrate_current_source schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS new_tables=EMPTY legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$' -or (Get-RecordField $Record 'legacy_fingerprint') -ne $Fingerprint) {
        Stop-V16ToV18DirectRuntime 'direct_replay_evidence_invalid'
    }
}

function Assert-VerifyRecord([string]$Record, [string]$Fingerprint) {
    if ($Record -notmatch '^V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=verify_current_source schema=18 ledger=18 new_tables=EMPTY legacy_fingerprint=[a-f0-9]{64} media=NOT_TOUCHED$' -or (Get-RecordField $Record 'legacy_fingerprint') -ne $Fingerprint) {
        Stop-V16ToV18DirectRuntime 'direct_verify_evidence_invalid'
    }
}

function Assert-FailClosedRecord([string]$Record) {
    if ($Record -ne 'V16_TO_V18_SYNTHETIC_DIRECT_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED') {
        Stop-V16ToV18DirectRuntime 'direct_fail_closed_evidence_invalid'
    }
}

function Write-ProofReport([hashtable]$SourceClosure, [string]$Fingerprint, [string]$First, [string]$Replay, [string]$Verify, [string]$FailClosed) {
    if (Test-Path -LiteralPath $reportPath) { Stop-V16ToV18DirectRuntime 'direct_proof_report_already_exists' }
    if ($null -eq $SourceClosure -or [string]$SourceClosure.Commit -notmatch '^[a-f0-9]{40}$' -or [string]$SourceClosure.SourceDigest -notmatch '^[a-f0-9]{64}$') {
        Stop-V16ToV18DirectRuntime 'direct_proof_source_closure_invalid'
    }
    $record = [ordered]@{
        format = 2
        attempt = $attempt
        scope = 'SYNTHETIC_V4_MIGRATION'
        ports = ('127.0.0.1:' + $httpPort + '_' + $compatPort)
        created_at_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ss.fffffffZ')
        source_schema = 16
        target_schema = 18
        migration = 'CURRENT_SOURCE_DIRECT_17_18'
        source_commit = $SourceClosure.Commit
        source_digest = $SourceClosure.SourceDigest
        legacy_fingerprint = $Fingerprint
        first_migration = $First
        replay = $Replay
        verify = $Verify
        fail_closed = $FailClosed
        media = 'NOT_MOUNTED'
    }
    $json = $record | ConvertTo-Json -Depth 3
    [IO.File]::WriteAllText($reportPath, ($json + "`n"), [Text.UTF8Encoding]::new($false))
    Assert-IgnoredUntracked $reportPath $false | Out-Null
}

function Read-ProofReport {
    Assert-IgnoredUntracked $reportPath $false | Out-Null
    $record = [IO.File]::ReadAllText($reportPath, [Text.UTF8Encoding]::new($false)) | ConvertFrom-Json -ErrorAction Stop
    if ($record.format -ne 2 -or $record.attempt -ne $attempt -or $record.scope -ne 'SYNTHETIC_V4_MIGRATION' -or
        $record.ports -ne ('127.0.0.1:' + $httpPort + '_' + $compatPort) -or $record.source_schema -ne 16 -or $record.target_schema -ne 18 -or
        $record.migration -ne 'CURRENT_SOURCE_DIRECT_17_18' -or ([string]$record.legacy_fingerprint) -notmatch '^[a-f0-9]{64}$' -or
        ([string]$record.source_commit) -notmatch '^[a-f0-9]{40}$' -or ([string]$record.source_digest) -notmatch '^[a-f0-9]{64}$' -or
        $record.media -ne 'NOT_MOUNTED') {
        Stop-V16ToV18DirectRuntime 'direct_proof_report_invalid'
    }
    Assert-FirstMigrationRecord ([string]$record.first_migration) | Out-Null
    Assert-ReplayRecord ([string]$record.replay) ([string]$record.legacy_fingerprint)
    Assert-VerifyRecord ([string]$record.verify) ([string]$record.legacy_fingerprint)
    Assert-FailClosedRecord ([string]$record.fail_closed)
    Assert-ProofSourceClosure @{ Commit = [string]$record.source_commit; SourceDigest = [string]$record.source_digest } 'direct_proof_source_closure_stale' | Out-Null
    return $record
}

function Invoke-Initialize {
    Assert-DirectRuntimeSources
    $script:stage = 'initialize'
    $lines = @(Invoke-BaseRunner 'initialize')
    $record = @($lines | Where-Object { $_ -eq 'V18_SYNTHETIC_MIGRATION=READY stage=initialize attempt=attempt26 source=V16_DB_ONLY historical_schema=V17_PINNED media=NOT_MOUNTED' })
    if ($record.Count -ne 1) { Stop-V16ToV18DirectRuntime 'base_initialize_evidence_invalid' }
    Get-SandboxValues | Out-Null
    Write-V16ToV18DirectRuntime 'PASS' 'initialize' 'attempt=attempt26 ports=127.0.0.1:11090_11091 source=V16_DB_ONLY media=NOT_MOUNTED'
}

function Invoke-Restore {
    if (-not $ConfirmSyntheticRestore) { Stop-V16ToV18DirectRuntime 'synthetic_restore_confirmation_required' }
    Assert-DirectRuntimeSources
    Get-SandboxValues | Out-Null
    $script:stage = 'restore'
    $lines = @(Invoke-BaseRunner 'restore' -RestoreConfirmation)
    $record = @($lines | Where-Object { $_ -match '^V18_SYNTHETIC_MIGRATION=PASS stage=restore schema=16 source=pre-migration-db-v16-to-v17-[0-9]{8}T[0-9]{6}Z target=attempt26 media=NOT_MOUNTED$' })
    if ($record.Count -ne 1) { Stop-V16ToV18DirectRuntime 'base_restore_evidence_invalid' }
    if ((Get-DirectSchemaVersion) -ne 16) { Stop-V16ToV18DirectRuntime 'direct_restore_not_v16' }
    Write-V16ToV18DirectRuntime 'PASS' 'restore' 'attempt=attempt26 schema=16 target=ISOLATED media=NOT_MOUNTED'
}

function Invoke-RestoreAndProve {
    # Keep the V16 restore and the first current-source proof in one bounded
    # synthetic invocation. This eliminates an avoidable handoff window while
    # retaining both explicit confirmations and all existing fail-closed
    # assertions. It never reuses a lab: Invoke-Restore still requires fresh
    # isolated state, and Invoke-Prove still requires that exact V16 result.
    if (-not $ConfirmSyntheticRestore -or -not $ConfirmSyntheticMigration) {
        Stop-V16ToV18DirectRuntime 'synthetic_restore_and_migration_confirmation_required'
    }
    Invoke-Restore
    Invoke-Prove
}

function Invoke-Prove {
    if (-not $ConfirmSyntheticMigration) { Stop-V16ToV18DirectRuntime 'synthetic_migration_confirmation_required' }
    Assert-DirectRuntimeSources
    $sourceClosure = Get-ProofSourceClosure
    Get-SandboxValues | Out-Null
    if (Test-Path -LiteralPath $reportPath) { Stop-V16ToV18DirectRuntime 'direct_proof_report_exists_preserved_lab' }
    if ((Get-DirectSchemaVersion) -ne 16) { Stop-V16ToV18DirectRuntime 'direct_proof_requires_fresh_v16_lab' }
    $first = Invoke-DirectProof '--migrate-current-source'
    $fingerprint = Assert-FirstMigrationRecord $first
    if ((Get-DirectSchemaVersion) -ne 18) { Stop-V16ToV18DirectRuntime 'direct_first_migration_not_v18' }
    $replay = Invoke-DirectProof '--migrate-current-source'
    Assert-ReplayRecord $replay $fingerprint
    $verify = Invoke-DirectProof '--verify-current-source'
    Assert-VerifyRecord $verify $fingerprint
    $failClosed = Invoke-DirectProof '--fail-closed'
    Assert-FailClosedRecord $failClosed
    Assert-ProofSourceClosure $sourceClosure 'direct_proof_source_changed_during_run' | Out-Null
    Write-ProofReport $sourceClosure $fingerprint $first $replay $verify $failClosed
    Write-V16ToV18DirectRuntime 'PASS' 'prove' ('attempt=attempt26 schema_from=16 schema_to=18 direct_current_source=PASS replay=PASS verify=PASS fail_closed=PASS legacy_fingerprint=' + $fingerprint + ' media=NOT_MOUNTED')
}

function Invoke-Verify {
    Assert-DirectRuntimeSources
    Get-SandboxValues | Out-Null
    $proof = Read-ProofReport
    $sourceClosure = @{ Commit = [string]$proof.source_commit; SourceDigest = [string]$proof.source_digest }
    if ((Get-DirectSchemaVersion) -ne 18) { Stop-V16ToV18DirectRuntime 'direct_verify_not_v18' }
    $fingerprint = [string]$proof.legacy_fingerprint
    $replay = Invoke-DirectProof '--migrate-current-source'
    Assert-ReplayRecord $replay $fingerprint
    $verify = Invoke-DirectProof '--verify-current-source'
    Assert-VerifyRecord $verify $fingerprint
    $failClosed = Invoke-DirectProof '--fail-closed'
    Assert-FailClosedRecord $failClosed
    Assert-ProofSourceClosure $sourceClosure 'direct_verify_source_changed_during_run' | Out-Null
    Write-V16ToV18DirectRuntime 'PASS' 'verify' ('attempt=attempt26 schema=18 direct_current_source=REPLAY_VERIFIED verify=PASS fail_closed=PASS legacy_fingerprint=' + $fingerprint + ' media=NOT_MOUNTED')
}

try {
    switch ($Action) {
        'initialize' { Invoke-Initialize }
        'restore' { Invoke-Restore }
        'restore-and-prove' { Invoke-RestoreAndProve }
        'prove' { Invoke-Prove }
        'verify' { Invoke-Verify }
        'status' {
            $initialized = (Test-Path -LiteralPath $envPath)
            $proof = (Test-Path -LiteralPath $reportPath)
            if ($initialized) { Get-SandboxValues | Out-Null }
            Write-V16ToV18DirectRuntime 'STATUS' 'status' ('attempt=attempt26 ports=127.0.0.1:11090_11091 initialized=' + $initialized.ToString().ToUpperInvariant() + ' proof=' + $proof.ToString().ToUpperInvariant() + ' media=NOT_MOUNTED')
        }
    }
} catch {
    # Keep failure evidence single-line and path-free. Write-Error adds the
    # host script location and invocation context, which is not needed for a
    # fail-closed gate and can leak private workstation details into logs.
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME_STOP:([a-z0-9_]{1,96})$') {
        $Matches[1]
    } else {
        $type = $_.Exception.GetType().Name
        if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
        'unexpected_' + $type.ToLowerInvariant()
    }
    Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME=FAIL stage=' + $script:stage + ' code=' + $code)
    exit 1
}
