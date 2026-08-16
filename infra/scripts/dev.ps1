[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'stop', 'down', 'ps', 'logs', 'pull', 'config', 'bootstrap', 'extensions', 'extensions-verify', 'class-plugins', 'class-plugins-verify', 'baseline-verify', 'seed', 'test-access', 'test-phase0', 'backup')]
    [string]$Action = 'ps'
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env.piwigo. Run infra\scripts\init-dev-env.ps1 first.'
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
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml'
)

if ($Action -eq 'up' -or $Action -eq 'stop' -or $Action -eq 'down') {
    Start-KeepAlive
}

switch ($Action) {
    'up' { $commandArguments = $composeArguments + @('up', '-d') }
    'stop' { $commandArguments = $composeArguments + @('stop') }
    'down' { $commandArguments = $composeArguments + @('down') }
    'ps' { $commandArguments = $composeArguments + @('ps') }
    'logs' { $commandArguments = $composeArguments + @('logs', '--tail=200') }
    'pull' { $commandArguments = $composeArguments + @('pull') }
    'config' { $commandArguments = $composeArguments + @('config', '--quiet') }
    'extensions' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php'
        )
    }
    'extensions-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php', '--verify-only'
        )
    }
    'class-plugins' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php'
        )
    }
    'class-plugins-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-only'
        )
    }
    'baseline-verify' {
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/configure-piwigo-baseline.php', '--verify-only'
        )
    }
    'bootstrap' {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $PSScriptRoot 'bootstrap-piwigo.ps1')
        exit $LASTEXITCODE
    }
    'seed' {
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/generate-test-images.php', '72'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        $commandArguments = $composeArguments + @(
            'exec', '-T', '--user', 'nginx',
            '-e', 'CLASS_ARCHIVE_ALLOW_SYNTHETIC_SEED=1', 'piwigo',
            'php', '/workspace/tests/fixtures/seed-piwigo.php'
        )
    }
    'test-phase0' {
        & wsl.exe @($composeArguments + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/tests/phase0/assert-photo-model.php'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & wsl.exe @($composeArguments + @(
            'exec', '-T', 'piwigo',
            'sh', '/workspace/tests/phase0/assert-media-permissions.sh'
        ))
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\smoke-photo-ui.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\access-matrix.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-http.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-tiny-preview.ps1')
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\media-guard-state-transitions.ps1')
        exit $LASTEXITCODE
    }
    'test-access' {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File `
            (Join-Path $projectRoot 'tests\phase0\access-matrix.ps1')
        exit $LASTEXITCODE
    }
    'backup' {
        New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
        $backupLockPath = Join-Path $runtimeDirectory 'backup.lock'
        $backupLock = $null
        try {
            try {
                $backupLock = [IO.File]::Open(
                    $backupLockPath,
                    [IO.FileMode]::OpenOrCreate,
                    [IO.FileAccess]::ReadWrite,
                    [IO.FileShare]::None
                )
            }
            catch [IO.IOException] {
                [Console]::Error.WriteLine('Refusing overlapping backup: another helper owns the local backup lock.')
                exit 1
            }

            $runningServices = @(& wsl.exe @($composeArguments + @('ps', '--status', 'running', '--services')))
            if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
            $piwigoWasRunning = 'piwigo' -in $runningServices
            $databaseWasRunning = 'db' -in $runningServices
            if (-not $databaseWasRunning) {
                [Console]::Error.WriteLine('Refusing backup because the database was not already running.')
                exit 1
            }
            if ($piwigoWasRunning) {
                & wsl.exe @($composeArguments + @('stop', 'piwigo'))
                if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
            }
            $backupExitCode = 1
            $restartExitCode = 0
            try {
                & wsl.exe @($composeArguments + @('--profile', 'ops', 'run', '--rm', '-e', 'CLASS_ARCHIVE_BACKUP_QUIESCED=true', 'backup'))
                $backupExitCode = $LASTEXITCODE
            }
            finally {
                if ($piwigoWasRunning) {
                    & wsl.exe @($composeArguments + @('start', 'piwigo'))
                    $restartExitCode = $LASTEXITCODE
                }
            }
            if ($restartExitCode -ne 0) {
                [Console]::Error.WriteLine("Piwigo restart failed after backup (backup exit $backupExitCode, restart exit $restartExitCode).")
                exit $restartExitCode
            }
            exit $backupExitCode
        }
        finally {
            if ($null -ne $backupLock) {
                $backupLock.Dispose()
            }
            Remove-Item -LiteralPath $backupLockPath -Force -ErrorAction SilentlyContinue
        }
    }
}

& wsl.exe @commandArguments
$commandExitCode = $LASTEXITCODE

if ($Action -eq 'stop' -or $Action -eq 'down') {
    Stop-KeepAlive
}

exit $commandExitCode
