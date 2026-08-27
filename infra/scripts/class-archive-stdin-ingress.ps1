function ConvertTo-ClassArchiveNativeArgument {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$Value
    )

    if ($Value.Length -gt 0 -and $Value -notmatch '[\s"]') {
        return $Value
    }

    # ProcessStartInfo.ArgumentList does not exist under Windows PowerShell
    # 5.1. Apply the CommandLineToArgvW quoting rules explicitly instead of
    # interpolating through cmd.exe or another shell.
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

function Invoke-ClassArchiveBinaryStdinProcess {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Executable,

        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,

        [Parameter(Mandatory = $true)]
        [IO.FileStream]$InputStream,

        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 16777216)]
        [int64]$ExpectedSize,

        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[0-9a-f]{64}$')]
        [string]$ExpectedSha256,

        [ValidateRange(1, 300)]
        [int]$TimeoutSeconds = 120
    )

    if (-not $InputStream.CanRead -or -not $InputStream.CanSeek `
        -or $InputStream.Length -ne $ExpectedSize -or $InputStream.Position -ne 0) {
        throw [InvalidOperationException]::new('Class Archive stdin ingress received an invalid source stream.')
    }

    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = $Executable
    $start.Arguments = [string]::Join(' ', @($Arguments | ForEach-Object {
        ConvertTo-ClassArchiveNativeArgument -Value ([string]$_)
    }))
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $hasStandardInputEncoding = @($start.PSObject.Properties.Name) -contains 'StandardInputEncoding'
    if ($hasStandardInputEncoding) {
        # PowerShell 7 / modern .NET can configure the redirected writer
        # directly. This avoids mutating Console.InputEncoding and, more
        # importantly, prevents the redirected pipe from inheriting a BOM.
        $start.StandardInputEncoding = [Text.UTF8Encoding]::new($false)
    }

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    $started = $false
    try {
        # Windows PowerShell 5.1 lacks ProcessStartInfo.StandardInputEncoding.
        # Process snapshots Console.InputEncoding while creating redirected
        # stdin, so select a no-BOM encoder for only the bounded start/getter
        # window and immediately restore the process-wide setting.
        if ($hasStandardInputEncoding) {
            if (-not $process.Start()) {
                throw [InvalidOperationException]::new('Class Archive stdin ingress process did not start.')
            }
            $started = $true
            $stdinStream = $process.StandardInput.BaseStream
        } else {
            $priorInputEncoding = [Console]::InputEncoding
            try {
                [Console]::InputEncoding = [Text.UTF8Encoding]::new($false)
                if (-not $process.Start()) {
                    throw [InvalidOperationException]::new('Class Archive stdin ingress process did not start.')
                }
                $started = $true
                $stdinStream = $process.StandardInput.BaseStream
            } finally {
                [Console]::InputEncoding = $priorInputEncoding
            }
        }
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        try {
            $InputStream.CopyTo($stdinStream, 65536)
            $stdinStream.Flush()
        } finally {
            # Close the raw pipe, not Process.StandardInput (a StreamWriter).
            # Closing/flushing that unused writer under Windows PowerShell 5.1
            # appends its UTF-8 preamble and corrupts binary payloads by 3 bytes.
            $stdinStream.Close()
        }
        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            try { $process.Kill() } catch { }
            [void]$process.WaitForExit(10000)
            throw [TimeoutException]::new('Class Archive stdin ingress process timed out.')
        }
        # Required after the bounded wait so asynchronous output readers have
        # consumed all redirected bytes under Windows PowerShell 5.1.
        $process.WaitForExit()
        $stdout = [string]$stdoutTask.Result
        $stderr = [string]$stderrTask.Result
        return [pscustomobject]@{
            ExitCode = [int]$process.ExitCode
            Stdout = $stdout
            Stderr = $stderr
            ExpectedSize = $ExpectedSize
            ExpectedSha256 = $ExpectedSha256
        }
    } finally {
        if ($started) {
            try { if (-not $process.HasExited) { $process.Kill() } } catch { }
        }
        $process.Dispose()
    }
}
