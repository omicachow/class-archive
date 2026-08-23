[CmdletBinding()]
param()

# Recreates the ML process after the artifact cache has been sealed.  The
# service is attached only to Docker's `internal: true` network and receives
# explicit Hugging Face offline flags, so successful preload is evidence of a
# complete local closure rather than an already-warm or online cache.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composeFile = 'infra/immich-spike/docker-compose.yml'
$composeEnv = 'infra/immich-spike/.env'
$readinessScript = Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-readiness.ps1'
$assertions = 0

function Fail([string]$reason) {
    throw "ML_OFFLINE_COLD_START=FAIL evidence=RUNTIME_TESTED reason=$reason assertions=$script:assertions"
}

function Assert-Exact([bool]$condition, [string]$reason) {
    $script:assertions++
    if (-not $condition) { Fail $reason }
}

function Invoke-UbuntuDocker([string[]]$arguments) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu -- docker @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) { throw ('docker_command_failed_' + ($arguments -join '_')) }
    return [string]::Join("`n", $lines)
}

function Invoke-ImmichCompose([string[]]$arguments) {
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot -- docker compose --env-file $composeEnv -f $composeFile @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) { throw ('immich_compose_failed_' + ($arguments -join '_')) }
    return [string]::Join("`n", $lines)
}

function Get-DockerInspect([string]$container) {
    $parsed = (Invoke-UbuntuDocker @('inspect', $container)) | ConvertFrom-Json -ErrorAction Stop
    if ($parsed -is [System.Array]) {
        if ($parsed.Count -ne 1) { throw 'docker_inspect_ambiguous' }
        return $parsed[0]
    }
    return $parsed
}

function Wait-ForMlHealthy {
    $container = 'class-archive-immich-spike-immich-machine-learning-1'
    for ($attempt = 0; $attempt -lt 180; $attempt++) {
        try {
            $inspect = Get-DockerInspect $container
            if ([string]$inspect.State.Health.Status -eq 'healthy') { return }
            if ([string]$inspect.State.Status -eq 'exited' -or [string]$inspect.State.Status -eq 'dead') {
                throw 'ml_container_exited_during_cold_start'
            }
        } catch {
            if ($_.Exception.Message -eq 'ml_container_exited_during_cold_start') { throw }
        }
        Start-Sleep -Seconds 1
    }
    throw 'ml_cold_start_health_timeout'
}

try {
    Assert-Exact (Test-Path -LiteralPath $readinessScript -PathType Leaf) 'readiness_script_missing'
    # Force a new process, not merely an HTTP retry against a model already in
    # memory.  The named cache persists, whereas the container is recreated.
    [void](Invoke-ImmichCompose @('--profile', 'immich-ml', 'up', '-d', '--force-recreate', 'immich-machine-learning'))
    Wait-ForMlHealthy
    $assertions++

    $logs = Invoke-UbuntuDocker @('logs', 'class-archive-immich-spike-immich-machine-learning-1')
    foreach ($requiredLine in @(
        'Preloading models:',
        'Loading textual model',
        'Loading visual model',
        'Loading detection model',
        'Loading recognition model',
        'Application startup complete'
    )) {
        Assert-Exact ($logs.Contains($requiredLine)) ('cold_start_log_missing_' + ($requiredLine -replace '[^a-zA-Z]+', '_').Trim('_').ToLowerInvariant())
    }
    Assert-Exact (-not ($logs -match '(?im)\b(download|snapshot_download|hf_hub_download)\b')) 'cold_start_attempted_model_fetch'

    $readinessPassed = $false
    $readiness = ''
    for ($attempt = 0; $attempt -lt 3 -and -not $readinessPassed; $attempt++) {
        $previousErrorActionPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $readinessParameters = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $readinessScript, '-RequireReady')
            if ($VerbosePreference -eq 'Continue') { $readinessParameters += '-Verbose' }
            $readinessLines = @(& powershell.exe @readinessParameters 2>&1)
            $readinessExit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }
        $readiness = [string]::Join("`n", $readinessLines).Trim()
        $readinessPassed = $readinessExit -eq 0 -and $readiness -match 'IMMICH_ML_MODEL_ARTIFACTS=READY evidence=RUNTIME_TESTED artifacts=8 manifest_sha256=[0-9a-f]{64} assertions=[0-9]+'
        if (-not $readinessPassed -and $attempt -lt 2) { Start-Sleep -Seconds 2 }
    }
    Write-Verbose ('Offline ML readiness result: ' + $readiness)
    Assert-Exact $readinessPassed 'runtime_artifact_readiness_failed'

    Write-Output "ML_OFFLINE_COLD_START=PASS evidence=RUNTIME_TESTED assertions=$assertions"
    exit 0
} catch {
    $message = [string]$_.Exception.Message
    if ($message -match '^ML_OFFLINE_COLD_START=FAIL evidence=RUNTIME_TESTED reason=[a-z0-9_]+ assertions=[0-9]+$') {
        Write-Output $message
    } else {
        Write-Output "ML_OFFLINE_COLD_START=FAIL evidence=RUNTIME_TESTED reason=unexpected assertions=$assertions"
    }
    exit 1
}
