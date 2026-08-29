<#
.SYNOPSIS
Shared bounded native-process and WSL command helpers.

.DESCRIPTION
Windows PowerShell 5.1 does not expose ProcessStartInfo.ArgumentList.  The
Class Archive owner migration paths need to pass deliberately multiline shell
scripts to WSL without an intermediate shell parsing them.  These helpers use
the Win32 CommandLineToArgvW escaping rules, bound both the host process and
the Linux command, and deliberately return captured output only to their
callers.  They have no parameters or side effects when dot-sourced.
#>

Set-StrictMode -Version Latest

function ConvertTo-ClassArchiveWin32Argument {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$Value
    )

    if ($Value.Contains("`0")) {
        throw [ArgumentException]::new('Native process argument contains a NUL byte.')
    }
    if ($Value.Length -gt 65535) {
        throw [ArgumentException]::new('Native process argument exceeds the bounded command-line limit.')
    }
    if ($Value.Length -gt 0 -and $Value -notmatch '[\s"]') {
        return $Value
    }

    # ProcessStartInfo.ArgumentList is unavailable under Windows PowerShell
    # 5.1.  Quote exactly as CommandLineToArgvW expects: backslashes before a
    # quote are doubled and escaped, and trailing backslashes are doubled
    # before the closing quote.  This preserves multiline sh -c / SQL payloads
    # as one argument without using cmd.exe or a shell interpolation layer.
    $builder = [Text.StringBuilder]::new()
    [void]$builder.Append('"')
    $backslashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq '\') {
            $backslashes++
            continue
        }
        if ($character -eq '"') {
            [void]$builder.Append(('\' * (2 * $backslashes + 1)))
            [void]$builder.Append('"')
            $backslashes = 0
            continue
        }
        if ($backslashes -gt 0) {
            [void]$builder.Append(('\' * $backslashes))
            $backslashes = 0
        }
        [void]$builder.Append($character)
    }
    if ($backslashes -gt 0) {
        [void]$builder.Append(('\' * (2 * $backslashes)))
    }
    [void]$builder.Append('"')
    return $builder.ToString()
}

function Invoke-ClassArchiveBoundedNative {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Executable,

        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,

        [ValidateRange(1, 900)]
        [int]$TimeoutSeconds = 120,

        [string]$WorkingDirectory
    )

    if ([string]::IsNullOrWhiteSpace($Executable) -or -not (Test-Path -LiteralPath $Executable -PathType Leaf)) {
        throw [InvalidOperationException]::new('Bounded native executable is unavailable.')
    }
    if (-not [string]::IsNullOrWhiteSpace($WorkingDirectory) -and -not (Test-Path -LiteralPath $WorkingDirectory -PathType Container)) {
        throw [InvalidOperationException]::new('Bounded native working directory is unavailable.')
    }

    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $Executable
    $start.Arguments = [string]::Join(' ', @($Arguments | ForEach-Object {
        ConvertTo-ClassArchiveWin32Argument -Value ([string]$_)
    }))
    if (-not [string]::IsNullOrWhiteSpace($WorkingDirectory)) { $start.WorkingDirectory = $WorkingDirectory }
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $start.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    $started = $false
    try {
        if (-not $process.Start()) {
            throw [InvalidOperationException]::new('Bounded native process did not start.')
        }
        $started = $true
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $completed = $process.WaitForExit($TimeoutSeconds * 1000)
        $timedOut = -not $completed
        if ($timedOut) {
            try { $process.Kill() } catch { }
            [void]$process.WaitForExit(15000)
        }
        if (-not $process.HasExited) {
            return [pscustomobject]@{ TimedOut = $true; ExitCode = $null; Stdout = ''; Stderr = '' }
        }

        # Async readers are already draining both pipes.  This final wait only
        # completes their pending EOF notifications after the bounded process
        # has exited, avoiding the Windows PowerShell 5.1 redirected-pipe race.
        $process.WaitForExit()
        return [pscustomobject]@{
            TimedOut = [bool]$timedOut
            ExitCode = [int]$process.ExitCode
            Stdout = [string]$stdoutTask.GetAwaiter().GetResult()
            Stderr = [string]$stderrTask.GetAwaiter().GetResult()
        }
    }
    finally {
        if ($started) {
            try { if (-not $process.HasExited) { $process.Kill() } } catch { }
        }
        $process.Dispose()
    }
}

function Add-ClassArchiveWslTimeout {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,

        [ValidateRange(1, 900)]
        [int]$TimeoutSeconds
    )

    if ($Arguments.Count -lt 1) {
        throw [ArgumentException]::new('WSL command arguments are empty.')
    }
    foreach ($argument in $Arguments) {
        if (($null -eq $argument) -or ([string]$argument).Contains("`0")) {
            throw [ArgumentException]::new('WSL command arguments are invalid.')
        }
    }

    $delimiterIndices = [System.Collections.Generic.List[int]]::new()
    for ($index = 0; $index -lt $Arguments.Count; $index++) {
        if ($Arguments[$index] -eq '--' -or $Arguments[$index] -eq '--exec') {
            [void]$delimiterIndices.Add($index)
        }
    }
    if ($delimiterIndices.Count -ne 1) {
        throw [ArgumentException]::new('WSL command delimiter is invalid.')
    }
    $delimiter = [int]$delimiterIndices[0]
    if ($delimiter -ge ($Arguments.Count - 1)) {
        throw [ArgumentException]::new('WSL command payload is missing.')
    }

    $prefix = if ($delimiter -gt 0) { @($Arguments[0..($delimiter - 1)]) } else { @() }
    $payload = @($Arguments[($delimiter + 1)..($Arguments.Count - 1)])
    # `--exec` avoids WSL's compatibility shell re-parsing a multiline SQL or
    # shell program.  Linux timeout bounds any Docker child that could outlive
    # a killed Windows wsl.exe client; the host process adds a second guard.
    return @($prefix + @('--exec', 'timeout', '--foreground', '--kill-after=10s', ($TimeoutSeconds.ToString() + 's')) + $payload)
}
