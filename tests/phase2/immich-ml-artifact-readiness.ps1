[CmdletBinding()]
param(
    [switch]$RequireReady
)

# Runtime-side half of the ML Artifact Gate.  The host-side verifier checks a
# received staging directory before import; this script proves that the running
# isolated ML service sees exactly those immutable bytes and is configured to
# fail instead of fetching a cache miss.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composeFile = 'infra/immich-spike/docker-compose.yml'
$composeEnv = 'infra/immich-spike/.env'
$manifestPath = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
$expectedMlImage = 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
$assertions = 0

function Fail([string]$reason) {
    throw "IMMICH_ML_MODEL_ARTIFACTS=BLOCKED evidence=RUNTIME_TESTED reason=$reason assertions=$script:assertions"
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
    $raw = Invoke-UbuntuDocker @('inspect', $container)
    $value = $raw | ConvertFrom-Json -ErrorAction Stop
    if ($value -is [System.Array]) {
        if ($value.Count -ne 1) { throw 'docker_inspect_ambiguous' }
        return $value[0]
    }
    return $value
}

function Get-Sha256([string]$path) {
    # Keep the runtime gate usable from Windows PowerShell hosts whose module
    # auto-loading policy does not expose Get-FileHash.
    $stream = [IO.File]::OpenRead($path)
    $hasher = [Security.Cryptography.SHA256]::Create()
    try {
        return -join ($hasher.ComputeHash($stream) | ForEach-Object { $_.ToString('x2') })
    } finally {
        $hasher.Dispose()
        $stream.Dispose()
    }
}

function Get-RelativeArtifactPath([object]$artifact) {
    return ([string]$artifact.relative_cache_path).Trim('/').Replace('\', '/')
}

try {
    Assert-Exact (Test-Path -LiteralPath $manifestPath -PathType Leaf) 'tracked_manifest_missing'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
    foreach ($property in @('manifest_version', 'generated_for', 'artifacts')) {
        Assert-Exact ($null -ne $manifest.PSObject.Properties[$property]) ('manifest_missing_' + $property)
    }
    Assert-Exact ([string]$manifest.generated_for.immich_version -eq '3.1.0') 'unexpected_immich_version'
    Assert-Exact ([string]$manifest.generated_for.immich_commit -eq '8aa95c67470a02a8ddedf03c2e52963af33065ff') 'unexpected_immich_commit'
    Assert-Exact ([string]$manifest.generated_for.machine_learning_image -ceq $expectedMlImage) 'unexpected_ml_image'
    $required = @($manifest.artifacts | Where-Object { [bool]$_.required })
    Assert-Exact ($required.Count -eq 8) 'unexpected_required_artifact_count'

    $expected = @{}
    foreach ($artifact in $required) {
        foreach ($property in @('artifact_id', 'relative_cache_path', 'file_name', 'file_size', 'sha256', 'exact_revision', 'license', 'license_source')) {
            Assert-Exact ($null -ne $artifact.PSObject.Properties[$property] -and -not [string]::IsNullOrWhiteSpace([string]$artifact.$property)) ('manifest_artifact_missing_' + $property)
        }
        $relativePath = Get-RelativeArtifactPath $artifact
        Assert-Exact ($relativePath -notmatch '(^|/)\.\.(/|$)' -and $relativePath -notmatch '^[\\/]') 'unsafe_manifest_cache_path'
        Assert-Exact ($relativePath -notmatch '[\r\n]') 'unsafe_manifest_cache_path'
        Assert-Exact ($artifact.sha256 -match '^[0-9a-f]{64}$') 'invalid_manifest_sha256'
        Assert-Exact ($artifact.file_size -as [long]) 'invalid_manifest_file_size'
        Assert-Exact (-not $expected.ContainsKey($relativePath)) 'duplicate_manifest_cache_path'
        $expected[$relativePath] = @{
            Bytes = [long]$artifact.file_size
            Sha256 = ([string]$artifact.sha256).ToLowerInvariant()
        }
    }

    $manifestSha = Get-Sha256 $manifestPath
    $containerName = 'class-archive-immich-spike-immich-machine-learning-1'
    $inspect = Get-DockerInspect $containerName
    Assert-Exact ($inspect.State.Running -eq $true) 'ml_container_not_running'
    Assert-Exact ([string]$inspect.Config.Image -ceq $expectedMlImage) 'ml_runtime_image_mismatch'
    Assert-Exact ([string]$inspect.Config.User -eq '65532:65532') 'ml_runtime_user_invalid'
    Assert-Exact ($inspect.HostConfig.ReadonlyRootfs -eq $true) 'ml_rootfs_not_read_only'
    Assert-Exact (@($inspect.HostConfig.CapDrop) -contains 'ALL') 'ml_capabilities_not_dropped'
    Assert-Exact (@($inspect.HostConfig.SecurityOpt) -contains 'no-new-privileges:true') 'ml_no_new_privileges_missing'
    if ($RequireReady) {
        Assert-Exact ([string]$inspect.State.Health.Status -eq 'healthy') 'ml_container_not_healthy'
    }
    $hasHostPort = $false
    if ($null -ne $inspect.HostConfig.PortBindings) {
        $hasHostPort = @($inspect.HostConfig.PortBindings.PSObject.Properties).Count -gt 0
    }
    Assert-Exact (-not $hasHostPort) 'ml_has_host_port'
    $networkNames = @($inspect.NetworkSettings.Networks.PSObject.Properties | ForEach-Object { $_.Name })
    Assert-Exact ($networkNames.Count -eq 1 -and $networkNames[0] -match 'immich_ml_internal$') 'ml_network_scope_invalid'
    $network = Get-DockerInspect ($networkNames[0])
    Assert-Exact ($network.Driver -eq 'bridge' -and $network.Internal -eq $true) 'ml_network_not_internal'

    $environment = @{}
    foreach ($entry in @($inspect.Config.Env)) {
        $split = $entry.IndexOf('=')
        if ($split -gt 0) { $environment[$entry.Substring(0, $split)] = $entry.Substring($split + 1) }
    }
    foreach ($forbidden in @('DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE_NAME', 'PIWIGO_UPLOADS_VOLUME', 'PIWIGO_GALLERIES_VOLUME')) {
        Assert-Exact (-not $environment.ContainsKey($forbidden)) ('ml_forbidden_environment_' + $forbidden)
    }
    foreach ($expectation in @{
        'HF_HUB_OFFLINE' = '1'
        'TRANSFORMERS_OFFLINE' = '1'
        'HF_DATASETS_OFFLINE' = '1'
        'MACHINE_LEARNING_PRELOAD__CLIP__TEXTUAL' = 'ViT-B-32__openai'
        'MACHINE_LEARNING_PRELOAD__CLIP__VISUAL' = 'ViT-B-32__openai'
        'MACHINE_LEARNING_PRELOAD__FACIAL_RECOGNITION__DETECTION' = 'buffalo_l'
        'MACHINE_LEARNING_PRELOAD__FACIAL_RECOGNITION__RECOGNITION' = 'buffalo_l'
        'MACHINE_LEARNING_MAX_BATCH_SIZE__FACIAL_RECOGNITION' = '1'
        'MPLCONFIGDIR' = '/tmp/matplotlib'
    }.GetEnumerator()) {
        Assert-Exact ($environment.ContainsKey($expectation.Key) -and $environment[$expectation.Key] -eq $expectation.Value) ('ml_environment_' + $expectation.Key + '_invalid')
    }

    $cacheMount = @($inspect.Mounts | Where-Object { $_.Destination -eq '/cache' })
    Assert-Exact ($cacheMount.Count -eq 1 -and $cacheMount[0].Type -eq 'volume' -and $cacheMount[0].Name -eq 'class_archive_immich_spike_model_cache' -and $cacheMount[0].RW -eq $false) 'ml_cache_mount_not_read_only'
    $tmpfs = $inspect.HostConfig.Tmpfs
    $tmpfsProperty = if ($null -eq $tmpfs) { $null } else { $tmpfs.PSObject.Properties['/tmp'] }
    $tmpfsValue = if ($null -eq $tmpfsProperty) { '' } else { [string]$tmpfsProperty.Value }
    Assert-Exact ($null -ne $tmpfs -and @($tmpfs.PSObject.Properties).Count -eq 1 -and $tmpfsValue -match '(^|,)rw(,|$)' -and $tmpfsValue -match '(^|,)noexec(,|$)' -and $tmpfsValue -match '(^|,)nosuid(,|$)' -and $tmpfsValue -match '(^|,)nodev(,|$)') 'ml_tmpfs_missing'

    # The imported cache includes the immutable manifest alongside exactly the
    # runtime minimum closure.  Generate only machine-readable records in the
    # container and compare every byte on the Windows host.
    $cacheListing = Invoke-ImmichCompose @('--profile', 'immich-ml', 'exec', '-T', 'immich-machine-learning', 'sh', '-lc', "cd /cache && find . -type f -printf '%P|%s|%m\\n' | LC_ALL=C sort")
    $actual = @{}
    foreach ($line in @($cacheListing -split "`r?`n" | Where-Object { $_ -ne '' })) {
        $parts = $line -split '\|', 3
        Assert-Exact ($parts.Count -eq 3 -and $parts[0] -notmatch '(^|/)\.\.(/|$)' -and $parts[1] -match '^\d+$' -and $parts[2] -match '^\d+$') 'malformed_cache_listing'
        Assert-Exact (-not $actual.ContainsKey($parts[0])) 'duplicate_cache_listing'
        $actual[$parts[0]] = @{ Bytes = [long]$parts[1]; Mode = [int]$parts[2] }
    }
    $manifestCacheName = 'class-archive-model-manifest.json'
    Assert-Exact ($actual.ContainsKey($manifestCacheName)) 'runtime_manifest_missing'
    Assert-Exact ($actual[$manifestCacheName].Bytes -eq (Get-Item -LiteralPath $manifestPath).Length) 'runtime_manifest_size_mismatch'
    Assert-Exact ($actual[$manifestCacheName].Mode -eq 444) 'runtime_manifest_mode_invalid'
    $runtimeManifestSha = (Invoke-ImmichCompose @('--profile', 'immich-ml', 'exec', '-T', 'immich-machine-learning', 'sh', '-lc', "sha256sum /cache/$manifestCacheName | cut -d ' ' -f1")).Trim().ToLowerInvariant()
    Assert-Exact ($runtimeManifestSha -eq $manifestSha) 'runtime_manifest_sha256_mismatch'

    foreach ($relativePath in $expected.Keys) {
        Assert-Exact ($actual.ContainsKey($relativePath)) 'runtime_artifact_missing'
        Assert-Exact ($actual[$relativePath].Bytes -eq $expected[$relativePath].Bytes) 'runtime_artifact_size_mismatch'
        Assert-Exact ($actual[$relativePath].Mode -eq 444) 'runtime_artifact_mode_invalid'
        $quoted = $relativePath.Replace("'", "'\\''")
        $actualSha = (Invoke-ImmichCompose @('--profile', 'immich-ml', 'exec', '-T', 'immich-machine-learning', 'sh', '-lc', ("sha256sum '/cache/$quoted' | cut -d ' ' -f1"))).Trim().ToLowerInvariant()
        Assert-Exact ($actualSha -eq $expected[$relativePath].Sha256) 'runtime_artifact_sha256_mismatch'
    }
    Assert-Exact ($actual.Count -eq ($expected.Count + 1)) 'runtime_cache_contains_unknown_file'

    Write-Output ("IMMICH_ML_MODEL_ARTIFACTS=READY evidence=RUNTIME_TESTED artifacts=$($expected.Count) manifest_sha256=$manifestSha assertions=$assertions")
    exit 0
} catch {
    $message = [string]$_.Exception.Message
    Write-Verbose ("ML artifact readiness diagnostic: " + $message)
    if ($message -match '^IMMICH_ML_MODEL_ARTIFACTS=BLOCKED evidence=RUNTIME_TESTED reason=[a-z0-9_]+ assertions=[0-9]+$') {
        Write-Output $message
    } else {
        Write-Output ("IMMICH_ML_MODEL_ARTIFACTS=BLOCKED evidence=RUNTIME_TESTED reason=unexpected assertions=$assertions")
    }
    exit 1
}
