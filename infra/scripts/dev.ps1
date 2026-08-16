[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'stop', 'down', 'ps', 'logs', 'pull', 'config', 'yii', 'modules', 'modules-verify', 'backup')]
    [string]$Action = 'ps',

    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Arguments
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env. Run infra\scripts\init-dev-env.ps1 first.'
}

$runtimeDirectory = Join-Path $projectRoot '.codex-work'
$keepAlivePidPath = Join-Path $runtimeDirectory 'wsl-keepalive.pid'

function Get-KeepAliveProcess {
    if (-not (Test-Path -LiteralPath $keepAlivePidPath)) {
        return $null
    }

    $storedPid = [int]([IO.File]::ReadAllText($keepAlivePidPath).Trim())
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $storedPid" -ErrorAction SilentlyContinue
    if ($process -and $process.Name -eq 'wsl.exe' -and $process.CommandLine -match '--exec sleep infinity') {
        return $process
    }

    Remove-Item -LiteralPath $keepAlivePidPath -Force -ErrorAction SilentlyContinue
    return $null
}

function Start-KeepAlive {
    if (Get-KeepAliveProcess) {
        return
    }

    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    $process = Start-Process -FilePath "$env:SystemRoot\System32\wsl.exe" `
        -ArgumentList @('-d', 'Ubuntu', '--exec', 'sleep', 'infinity') `
        -WindowStyle Hidden -PassThru
    [IO.File]::WriteAllText($keepAlivePidPath, [string]$process.Id, [Text.UTF8Encoding]::new($false))
}

function Stop-KeepAlive {
    $process = Get-KeepAliveProcess
    if ($process) {
        Stop-Process -Id $process.ProcessId -Force
    }
    Remove-Item -LiteralPath $keepAlivePidPath -Force -ErrorAction SilentlyContinue
}

$composeArguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env',
    '-f', 'infra/docker-compose.yml'
)

switch ($Action) {
    'up' { $commandArguments = $composeArguments + @('up', '-d') }
    'stop' { $commandArguments = $composeArguments + @('stop') }
    'down' { $commandArguments = $composeArguments + @('down') }
    'ps' { $commandArguments = $composeArguments + @('ps') }
    'logs' { $commandArguments = $composeArguments + @('logs', '--tail=200') }
    'pull' { $commandArguments = $composeArguments + @('pull') }
    'config' { $commandArguments = $composeArguments + @('config', '--quiet') }
    'modules' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'root', 'humhub',
            'php', '/workspace/infra/scripts/install-locked-modules.php'
        )
    }
    'modules-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'root', 'humhub',
            'php', '/workspace/infra/scripts/install-locked-modules.php', '--verify-only'
        )
    }
    'backup' { $commandArguments = $composeArguments + @('--profile', 'ops', 'run', '--rm', 'backup') }
    'yii' {
        if (-not $Arguments -or $Arguments.Count -eq 0) {
            throw 'Provide a HumHub console command, for example: dev.ps1 yii module/list'
        }
        $commandArguments = $composeArguments + @('exec', '-T', 'humhub', '/app/yii') + $Arguments
    }
}

if ($Action -eq 'up' -or $Action -eq 'stop' -or $Action -eq 'down') {
    Start-KeepAlive
}

& wsl.exe @commandArguments
$commandExitCode = $LASTEXITCODE

if ($Action -eq 'stop' -or $Action -eq 'down') {
    Stop-KeepAlive
}

exit $commandExitCode
