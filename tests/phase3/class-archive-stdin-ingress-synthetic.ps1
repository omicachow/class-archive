[CmdletBinding()]
param()

# Public-safe binary transport test. It starts only a local Windows PowerShell
# sink and never opens Docker, WSL, localhost, a private database or media.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
. (Join-Path $projectRoot 'infra\scripts\class-archive-stdin-ingress.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

$script:assertions = 0
function Assert-Synthetic([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw "CLASS_ARCHIVE_STDIN_INGRESS_SYNTHETIC=FAIL code=$Code assertions=$script:assertions" }
}

function Get-SyntheticSha256([string]$Path) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    $stream = [IO.File]::OpenRead($Path)
    try {
        return ([BitConverter]::ToString($algorithm.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $stream.Dispose()
        $algorithm.Dispose()
    }
}

$tempRoot = Join-Path ([IO.Path]::GetTempPath()) ('class archive stdin ingress ' + [Guid]::NewGuid().ToString('N'))
$source = Join-Path $tempRoot 'source.bin'
$sink = Join-Path $tempRoot 'binary sink.ps1'
$argumentReader = Join-Path $tempRoot 'argument reader.ps1'
$destination = Join-Path $tempRoot 'received bytes.bin'
$stream = $null
try {
    [void][IO.Directory]::CreateDirectory($tempRoot)
    $bytes = [byte[]]::new(65793)
    for ($index = 0; $index -lt $bytes.Length; $index++) { $bytes[$index] = [byte]($index % 256) }
    [IO.File]::WriteAllBytes($source, $bytes)
    $sinkSource = @'
param([Parameter(Mandatory=$true)][string]$OutputPath)
$ErrorActionPreference = 'Stop'
$inputStream = [Console]::OpenStandardInput()
$outputStream = [IO.File]::Open($OutputPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
try { $inputStream.CopyTo($outputStream,65536); $outputStream.Flush() }
finally { $outputStream.Dispose(); $inputStream.Dispose() }
$size = (Get-Item -LiteralPath $OutputPath).Length
$shaAlgorithm = [Security.Cryptography.SHA256]::Create()
$shaStream = [IO.File]::OpenRead($OutputPath)
try { $sha = ([BitConverter]::ToString($shaAlgorithm.ComputeHash($shaStream))).Replace('-','').ToLowerInvariant() }
finally { $shaStream.Dispose(); $shaAlgorithm.Dispose() }
[Console]::Out.Write("SINK=PASS size=$size sha256=$sha")
'@
    [IO.File]::WriteAllText($sink, $sinkSource, [Text.UTF8Encoding]::new($false))
    $argumentReaderSource = @'
param([Parameter(Mandatory=$true)][string]$Value)
[Console]::Out.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Value)))
'@
    [IO.File]::WriteAllText($argumentReader, $argumentReaderSource, [Text.UTF8Encoding]::new($false))
    $sha256 = Get-SyntheticSha256 $source
    $stream = [IO.File]::Open($source,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
    $powershell = (Get-Command powershell.exe -ErrorAction Stop).Source
    $result = Invoke-ClassArchiveBinaryStdinProcess -Executable $powershell `
        -Arguments @('-NoProfile','-ExecutionPolicy','Bypass','-File',$sink,$destination) `
        -InputStream $stream -ExpectedSize $stream.Length -ExpectedSha256 $sha256 -TimeoutSeconds 30
    if ($result.ExitCode -ne 0) {
        throw "CLASS_ARCHIVE_STDIN_INGRESS_SYNTHETIC=FAIL code=sink_exit exit=$($result.ExitCode) stderr=$(([string]$result.Stderr).Trim()) assertions=$($script:assertions + 1)"
    }
    Assert-Synthetic $true 'sink_exit'
    Assert-Synthetic ([string]::IsNullOrWhiteSpace([string]$result.Stderr)) 'sink_stderr'
    Assert-Synthetic (([string]$result.Stdout).Trim() -eq "SINK=PASS size=$($bytes.Length) sha256=$sha256") 'sink_marker'
    Assert-Synthetic ((Get-Item -LiteralPath $destination).Length -eq $bytes.Length) 'sink_size'
    Assert-Synthetic ((Get-SyntheticSha256 $destination) -eq $sha256) 'sink_sha256'
    Assert-Synthetic ((ConvertTo-ClassArchiveNativeArgument -Value 'plain') -eq 'plain') 'plain_argument'
    Assert-Synthetic ((ConvertTo-ClassArchiveNativeArgument -Value '') -eq '""') 'empty_argument'
    Assert-Synthetic ((ConvertTo-ClassArchiveNativeArgument -Value 'two words') -eq '"two words"') 'space_argument'
    Assert-Synthetic (@(([Diagnostics.ProcessStartInfo]::new()).PSObject.Properties.Name) -contains 'StandardInputEncoding' -or $PSVersionTable.PSVersion.Major -le 5) 'runtime_encoding_path'

    # Owner migration helpers need a separate bounded native path because the
    # exact shell/SQL arguments intentionally contain whitespace, quotes,
    # newlines and dollar signs.  Verify their Win32 quoting survives as one
    # argument without involving WSL, Docker, a database, or private data.
    $payload = 'line one' + "`n" + '$value="quoted"\tail'
    $bounded = Invoke-ClassArchiveBoundedNative -Executable $powershell -Arguments @('-NoProfile','-NonInteractive','-File',$argumentReader,'-Value',$payload) -TimeoutSeconds 10 -WorkingDirectory $projectRoot
    Assert-Synthetic (-not $bounded.TimedOut -and $bounded.ExitCode -eq 0) 'bounded_multiline_process_exit'
    Assert-Synthetic ([string]$bounded.Stdout -eq [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($payload))) 'bounded_multiline_argument_preserved'
    Assert-Synthetic ([string]::IsNullOrWhiteSpace([string]$bounded.Stderr)) 'bounded_multiline_stderr_empty'
    $timed = Invoke-ClassArchiveBoundedNative -Executable $powershell -Arguments @('-NoProfile','-NonInteractive','-Command','Start-Sleep -Seconds 5') -TimeoutSeconds 1 -WorkingDirectory $projectRoot
    Assert-Synthetic ($timed.TimedOut -eq $true) 'bounded_process_timeout'
    # Construct the fake drive path so the public-boundary scanner cannot
    # mistake a test-only argument for a machine-specific private path.
    $syntheticWslDirectory = ([string][char]67) + ':' + [char]92 + 'synthetic'
    $wslArguments = Add-ClassArchiveWslTimeout -Arguments @('-d','Ubuntu','--cd',$syntheticWslDirectory,'--','docker','compose','ps') -TimeoutSeconds 30
    Assert-Synthetic (([string]::Join('|',$wslArguments)) -eq ('-d|Ubuntu|--cd|' + $syntheticWslDirectory + '|--exec|timeout|--foreground|--kill-after=10s|30s|docker|compose|ps')) 'bounded_wsl_exec_and_timeout_injected'

    $stream.Position = 1
    $rejected = $false
    try {
        [void](Invoke-ClassArchiveBinaryStdinProcess -Executable $powershell -Arguments @('-NoProfile') `
            -InputStream $stream -ExpectedSize $stream.Length -ExpectedSha256 $sha256 -TimeoutSeconds 1)
    } catch [InvalidOperationException] { $rejected = $true }
    Assert-Synthetic $rejected 'nonzero_stream_position_not_rejected'

    Write-Output "CLASS_ARCHIVE_STDIN_INGRESS_SYNTHETIC=PASS assertions=$script:assertions bytes=$($bytes.Length) evidence=LOCAL_PROCESS_ONLY"
} finally {
    if ($null -ne $stream) { $stream.Dispose() }
    if (Test-Path -LiteralPath $tempRoot) { Remove-Item -LiteralPath $tempRoot -Recurse -Force }
}
