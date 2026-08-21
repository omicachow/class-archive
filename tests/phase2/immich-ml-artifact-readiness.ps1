[CmdletBinding()]
param(
    [switch]$RequireReady
)

# This is an intentionally offline diagnostic gate. The isolated ML container
# is on an internal Docker network, so an empty model cache must not cause it
# to download weights as a side effect of a browser or test request. A BLOCKED
# result is therefore a correct, safe state rather than a reason to relax the
# network policy.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$spikeEnvPath = Join-Path $projectRoot 'infra\immich-spike\.env'
$spikeComposePath = Join-Path $projectRoot 'infra\immich-spike\docker-compose.yml'
$container = 'class-archive-immich-spike-immich-machine-learning-1'

function Invoke-UbuntuDocker {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)

    $output = @(& wsl.exe -d Ubuntu --exec docker @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'immich_ml_artifact_docker_failed' }
    return [string]::Join("`n", $output)
}

if (-not (Test-Path -LiteralPath $spikeEnvPath) -or -not (Test-Path -LiteralPath $spikeComposePath)) {
    throw 'immich_ml_artifact_missing_local_spike_environment'
}

$inspection = (Invoke-UbuntuDocker -Arguments @('inspect', $container) | ConvertFrom-Json -ErrorAction Stop)[0]
if ($inspection.State.Running -ne $true -or $inspection.State.Health.Status -ne 'healthy') {
    throw 'immich_ml_artifact_machine_learning_not_healthy'
}

$networks = @($inspection.NetworkSettings.Networks.PSObject.Properties.Name)
if ($networks.Count -ne 1 -or $networks[0] -ne 'class-archive-immich-spike_immich_internal') {
    throw 'immich_ml_artifact_network_boundary_invalid'
}

# A valid future offline provisioner will write this immutable manifest into
# the model-cache volume. It must enumerate every payload with SHA-256 and a
# source lock reference; this script deliberately does not accept arbitrary
# cache files as evidence that model execution is trustworthy.
$manifestPath = '/cache/class-archive-model-manifest.json'
$manifest = Invoke-UbuntuDocker -Arguments @('exec', $container, 'sh', '-lc', "if test -f '$manifestPath'; then cat '$manifestPath'; fi")
$payload = Invoke-UbuntuDocker -Arguments @('exec', $container, 'sh', '-lc', "find /cache -type f ! -name 'class-archive-model-manifest.json' -printf '%p %s\\n' 2>/dev/null | sort")

$ready = $false
$reason = 'offline_model_manifest_absent'
if (-not [string]::IsNullOrWhiteSpace($manifest)) {
    try {
        $parsed = $manifest | ConvertFrom-Json -ErrorAction Stop
        $validVersion = $parsed.version -eq 1
        $validFiles = $null -ne $parsed.files -and @($parsed.files).Count -gt 0
        $validEntries = $validFiles -and @($parsed.files | Where-Object {
            $_.path -is [string] -and $_.path -match '^/cache/[A-Za-z0-9._/-]+$' -and
            $_.sha256 -is [string] -and $_.sha256 -match '^[0-9a-f]{64}$' -and
            $_.source_lock -is [string] -and $_.source_lock.Length -gt 0
        }).Count -eq @($parsed.files).Count
        if ($validVersion -and $validEntries -and -not [string]::IsNullOrWhiteSpace($payload)) {
            # Do not call an online hash service: the container itself computes
            # every listed digest inside the already-internal boundary.
            $checks = Invoke-UbuntuDocker -Arguments @('exec', $container, 'sh', '-lc', "sha256sum `$(find /cache -type f ! -name 'class-archive-model-manifest.json' -print | sort)")
            $actual = @{}
            foreach ($line in $checks -split "`n") {
                if ($line -match '^([0-9a-f]{64})  (/.+)$') { $actual[$Matches[2]] = $Matches[1] }
            }
            $ready = @($parsed.files | Where-Object { $actual[$_.path] -ne $_.sha256 }).Count -eq 0
            if (-not $ready) { $reason = 'offline_model_digest_mismatch' }
        }
        elseif ($validVersion -and -not $validEntries) { $reason = 'offline_model_manifest_invalid' }
    }
    catch {
        $reason = 'offline_model_manifest_invalid'
    }
}

if ($ready) {
    Write-Output 'IMMICH_ML_MODEL_ARTIFACTS=READY evidence=RUNTIME_TESTED'
    Write-Output 'IMMICH_ML_NETWORK=INTERNAL_ONLY'
    exit 0
}

Write-Output "IMMICH_ML_MODEL_ARTIFACTS=BLOCKED_OFFLINE_MODEL_ARTIFACTS reason=$reason evidence=RUNTIME_TESTED"
Write-Output 'IMMICH_ML_NETWORK=INTERNAL_ONLY'
if ($RequireReady) { exit 2 }
