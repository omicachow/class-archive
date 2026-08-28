[CmdletBinding()]
param(
    [switch]$Download,
    [switch]$Import,
    [switch]$ReplaceCache,
    [string]$ArtifactRoot,
    [string]$ManifestPath,
    [string]$Proxy
)

# The only supported artifact admission path. Fetching is optional and always
# uses immutable official Hugging Face URLs from the committed manifest. Import
# is deliberately separate and refuses to replace a nonempty Docker volume
# unless -ReplaceCache is explicit.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ([string]::IsNullOrWhiteSpace($ArtifactRoot)) {
    $ArtifactRoot = Join-Path $projectRoot '.codex-work\immich-ml-artifacts\staging'
}
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
}
$trackedManifestPath = [IO.Path]::GetFullPath((Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'))
$verifyScript = Join-Path $PSScriptRoot 'verify-immich-ml-artifacts.ps1'
$spikeCompose = 'infra/immich-spike/docker-compose.yml'
$spikeEnv = 'infra/immich-spike/.env'
$volume = 'class_archive_immich_spike_model_cache'
$expectedMlImage = 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
$expectedManifestSha256 = '46380b30910608a8f0226d6ed14e3535cdd3f43c6080115e19842a8eaeda7e7a'

function Stop-Prepare([string]$Code) {
    Write-Output "ML_ARTIFACT_PREPARE=FAIL code=$Code"
    exit 2
}

function Invoke-UbuntuDocker([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --exec docker @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($exitCode -ne 0) { throw 'docker_command_failed' }
    return [string]::Join("`n", $lines)
}

function Invoke-SpikeCompose([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec docker compose --env-file $spikeEnv -f $spikeCompose @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($exitCode -ne 0) { throw 'spike_compose_failed' }
    return [string]::Join("`n", $lines)
}

function Invalidate-MlAttestation {
    $arguments = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--', 'docker', 'compose',
        '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/invalidate-immich-ml-artifact-attestation.php'
    )
    $output = @(& "$env:SystemRoot\System32\wsl.exe" @arguments 2>&1)
    if ($LASTEXITCODE -ne 0 -or $output -notcontains 'ML_ARTIFACT_ATTESTATION=INVALIDATED') {
        throw 'ml_attestation_invalidation_failed'
    }
}

function Invoke-Verify {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $verifyScript -ArtifactRoot $ArtifactRoot -ManifestPath $ManifestPath
    if ($LASTEXITCODE -ne 0) { throw 'artifact_verify_failed' }
}

function Assert-TrackedManifest {
    if ([IO.Path]::GetFullPath($ManifestPath) -cne $trackedManifestPath) { Stop-Prepare 'operation_requires_tracked_manifest' }
    & git -C $projectRoot ls-files --error-unmatch -- 'infra/immich-spike/ml-artifacts/manifest.json' *> $null
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'tracked_manifest_unavailable' }
    & git -C $projectRoot diff --quiet HEAD -- 'infra/immich-spike/ml-artifacts/manifest.json'
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'tracked_manifest_modified' }
    & git -C $projectRoot diff --cached --quiet HEAD -- 'infra/immich-spike/ml-artifacts/manifest.json'
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'tracked_manifest_staged_change' }
}

function Get-WslPath([string]$Path) {
    $result = @(& wsl.exe -d Ubuntu --exec wslpath -a $Path 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or $result[0] -notmatch '^/mnt/[a-z]/') { throw 'wsl_path_conversion_failed' }
    return [string]$result[0]
}

try {
    $manifestBytes = [IO.File]::ReadAllBytes([IO.Path]::GetFullPath($ManifestPath))
    if ($manifestBytes.Length -lt 64 -or $manifestBytes.Length -gt 512KB) { Stop-Prepare 'manifest_size_invalid' }
    $manifestHasher = [Security.Cryptography.SHA256]::Create()
    try {
    $manifestSha = -join ($manifestHasher.ComputeHash($manifestBytes) | ForEach-Object { $_.ToString('x2') })
    } finally {
        $manifestHasher.Dispose()
    }
    $manifestRaw = [Text.UTF8Encoding]::new($false, $true).GetString($manifestBytes)
    $manifest = $manifestRaw | ConvertFrom-Json -ErrorAction Stop
    if ($manifest.manifest_version -ne 1 -or @($manifest.artifacts).Count -lt 1) { Stop-Prepare 'manifest_invalid' }
    if ($manifestSha -cne $expectedManifestSha256) { Stop-Prepare 'manifest_digest_mismatch' }
    $root = [IO.Path]::GetFullPath($ArtifactRoot)

    if ($Download) {
        Assert-TrackedManifest
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $verifyScript -ManifestPath $ManifestPath -ManifestOnly
        if ($LASTEXITCODE -ne 0) { Stop-Prepare 'manifest_preflight_failed' }
        if (-not [string]::IsNullOrWhiteSpace($Proxy) -and $Proxy -notmatch '^(?:socks5h?|https?)://127\.0\.0\.1:[1-9][0-9]{0,4}$') {
            Stop-Prepare 'proxy_must_be_localhost'
        }
        foreach ($artifact in @($manifest.artifacts)) {
            $relative = [string]$artifact.relative_cache_path
            if ($relative -notmatch '^(?:clip|facial-recognition)/[A-Za-z0-9._/-]+$' -or $relative.Contains('..')) { Stop-Prepare 'manifest_relative_path_invalid' }
            $destination = Join-Path $root ($relative -replace '/', '\\')
            $parent = Split-Path -Parent $destination
            New-Item -ItemType Directory -Force -Path $parent | Out-Null
            $validExisting = $false
            if (Test-Path -LiteralPath $destination -PathType Leaf) {
                $existing = Get-Item -LiteralPath $destination
                if ($existing.Length -eq [int64]$artifact.file_size) {
                    $existingHash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()
                    $validExisting = $existingHash -eq [string]$artifact.sha256
                }
            }
            if ($validExisting) {
                Write-Output "ML_ARTIFACT_FETCH=REUSED path=$relative"
                continue
            }
            $partial = $destination + '.partial'
            if (Test-Path -LiteralPath $partial -PathType Leaf) {
                $partialItem = Get-Item -LiteralPath $partial -Force
                if (($partialItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $partialItem.Length -gt [int64]$artifact.file_size) {
                    Remove-Item -LiteralPath $partial -Force
                }
            }
            $curl = @('--fail', '--location', '--silent', '--show-error', '--retry', '5', '--retry-all-errors', '--retry-delay', '3', '--connect-timeout', '30', '--max-time', '3600', '--max-filesize', [string]$artifact.file_size, '--continue-at', '-', '--proto', '=https', '--proto-redir', '=https', '-A', 'ClassArchive-ML-Artifact-Gate/1.0')
            if (-not [string]::IsNullOrWhiteSpace($Proxy)) { $curl += @('--proxy', $Proxy) }
            $curl += @([string]$artifact.source, '-o', $partial)
            & curl.exe @curl
            if ($LASTEXITCODE -ne 0) { Stop-Prepare 'official_fetch_failed' }
            $downloaded = Get-Item -LiteralPath $partial -Force
            if (($downloaded.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $downloaded.Length -ne [int64]$artifact.file_size) {
                Stop-Prepare 'downloaded_size_mismatch'
            }
            $downloadedHash = (Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($downloadedHash -cne [string]$artifact.sha256) {
                Remove-Item -LiteralPath $partial -Force
                Stop-Prepare 'downloaded_sha256_mismatch'
            }
            if (Test-Path -LiteralPath $destination -PathType Leaf) { Remove-Item -LiteralPath $destination -Force }
            Move-Item -LiteralPath $partial -Destination $destination
            Write-Output "ML_ARTIFACT_FETCH=DOWNLOADED path=$relative"
        }
    }

    Invoke-Verify
    if (-not $Import) {
        Write-Output 'ML_ARTIFACT_PREPARE=VERIFIED_NOT_IMPORTED'
        exit 0
    }
    if (-not $ReplaceCache) { Stop-Prepare 'replace_cache_switch_required' }
    Assert-TrackedManifest
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $spikeEnv))) { Stop-Prepare 'local_spike_environment_missing' }

    $volumeData = @(Invoke-UbuntuDocker @('volume', 'inspect', $volume) | ConvertFrom-Json -ErrorAction Stop)
    $dockerRoot = (Invoke-UbuntuDocker @('info', '--format', '{{.DockerRootDir}}')).TrimEnd('/')
    if ($dockerRoot -notmatch '^/[A-Za-z0-9._/-]{1,240}$' -or $dockerRoot.Contains('..')) { Stop-Prepare 'docker_root_untrusted' }
    $expectedMountpoint = $dockerRoot + '/volumes/' + $volume + '/_data'
    $volumeRecord = if ($volumeData.Count -eq 1) { $volumeData[0] } else { $null }
    if ($null -eq $volumeRecord) { Stop-Prepare 'model_cache_volume_identity_invalid' }
    $labels = $volumeRecord.Labels
    $identityInvalid = ([string]$volumeRecord.Name -cne $volume) -or
        ([string]$volumeRecord.Driver -cne 'local') -or
        ([string]$volumeRecord.Scope -cne 'local') -or
        ($null -ne $volumeRecord.Options) -or
        ([string]$volumeRecord.Mountpoint -cne $expectedMountpoint) -or
        ($null -eq $labels) -or
        ([string]($labels.'com.docker.compose.project') -cne 'class-archive-immich-spike') -or
        ([string]($labels.'com.docker.compose.volume') -cne 'immich_model_cache')
    if ($identityInvalid) {
        Stop-Prepare 'model_cache_volume_identity_invalid'
    }
    $image = [string]$manifest.generated_for.machine_learning_image
    if ($image -cne $expectedMlImage) { Stop-Prepare 'manifest_ml_image_invalid' }
    $imageId = Invoke-UbuntuDocker @('image', 'inspect', $image, '--format', '{{index .RepoDigests 0}}')
    if ($imageId -notmatch '^ghcr\.io/immich-app/immich-machine-learning@sha256:[0-9a-f]{64}$') { Stop-Prepare 'ml_image_not_locally_pinned' }

    # Any previously published release evidence must become stale before the
    # admitted bytes are touched. If invalidation fails, leave the cache alone.
    Invalidate-MlAttestation

    # Stop first, then replace only the named cache volume that was inspected
    # above. No Piwigo volume, database volume, original, credential, or
    # browser-facing service is mounted in the importer.
    [void](Invoke-SpikeCompose @('--profile', 'immich-ml', 'stop', 'immich-machine-learning'))
    $wslArtifacts = Get-WslPath $root
    $wslManifestDirectory = Get-WslPath (Split-Path -Parent ([IO.Path]::GetFullPath($ManifestPath)))
    if ($manifestSha -notmatch '^[0-9a-f]{64}$') { Stop-Prepare 'manifest_sha256_invalid' }
    $currentManifestSha = (Get-FileHash -LiteralPath $ManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($currentManifestSha -cne $manifestSha) { Stop-Prepare 'manifest_changed_after_read' }
    # The host check is followed by a second, independent admission check
    # inside the no-network importer. This closes verify-to-copy races: ML is
    # still stopped, and no copied byte can execute until the exact-set,
    # regular-file, size and SHA-256 checks below succeed.
    $cacheVerifier = @'
import hashlib, json, os, re, stat, sys

cache = "/cache"
manifest_path = "/spec/manifest.json"
cache_manifest_path = "/cache/class-archive-model-manifest.json"
expected_manifest_sha = sys.argv[1]

manifest_bytes = open(manifest_path, "rb").read()
if hashlib.sha256(manifest_bytes).hexdigest() != expected_manifest_sha:
    raise SystemExit("manifest_digest_mismatch")
if open(cache_manifest_path, "rb").read() != manifest_bytes:
    raise SystemExit("runtime_manifest_mismatch")
manifest = json.loads(manifest_bytes)
expected = {"class-archive-model-manifest.json": (len(manifest_bytes), expected_manifest_sha)}
for artifact in manifest.get("artifacts", []):
    if artifact.get("required") is not True:
        raise SystemExit("optional_artifact_forbidden")
    relative = artifact.get("relative_cache_path")
    if not isinstance(relative, str) or not re.fullmatch(r"(?:clip|facial-recognition)/[A-Za-z0-9._/-]+", relative) or ".." in relative or "//" in relative:
        raise SystemExit("artifact_path_invalid")
    if relative in expected:
        raise SystemExit("artifact_path_duplicate")
    size = artifact.get("file_size")
    digest = artifact.get("sha256")
    if not isinstance(size, int) or size < 1 or not isinstance(digest, str) or not re.fullmatch(r"[0-9a-f]{64}", digest):
        raise SystemExit("artifact_metadata_invalid")
    expected[relative] = (size, digest)

actual = {}
for root, directories, files in os.walk(cache, topdown=True, followlinks=False):
    for directory in directories:
        path = os.path.join(root, directory)
        mode = os.lstat(path).st_mode
        if not stat.S_ISDIR(mode) or stat.S_ISLNK(mode):
            raise SystemExit("cache_directory_untrusted")
    for filename in files:
        path = os.path.join(root, filename)
        mode = os.lstat(path).st_mode
        if not stat.S_ISREG(mode) or stat.S_ISLNK(mode):
            raise SystemExit("cache_file_untrusted")
        relative = os.path.relpath(path, cache).replace(os.sep, "/")
        actual[relative] = path
if set(actual) != set(expected):
    raise SystemExit("cache_exact_set_mismatch")
for relative, (size, digest) in expected.items():
    path = actual[relative]
    if os.path.getsize(path) != size:
        raise SystemExit("cache_size_mismatch")
    hasher = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            hasher.update(chunk)
    if hasher.hexdigest() != digest:
        raise SystemExit("cache_digest_mismatch")
'@
    $cacheVerifierEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($cacheVerifier))
    $importScript = 'set -eu; clear_cache() { find /cache -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; }; trap clear_cache EXIT INT TERM; clear_cache; cp -a /source/. /cache/; cp /spec/manifest.json /cache/class-archive-model-manifest.json; echo ' + $cacheVerifierEncoded + ' | base64 -d | python - ' + $manifestSha + '; find /cache -type f -exec chmod 0444 {} +; test -f /cache/class-archive-model-manifest.json; trap - EXIT INT TERM'
    [void](Invoke-UbuntuDocker @(
        'run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--user', '0:0', '--tmpfs', '/tmp:mode=1777,size=8m',
        '-v', ($volume + ':/cache:rw'), '-v', ($wslArtifacts + ':/source:ro'), '-v', ($wslManifestDirectory + ':/spec:ro'),
        $image, 'sh', '-ceu', $importScript
    ))
    [void](Invoke-SpikeCompose @('--profile', 'immich-ml', 'up', '-d', '--force-recreate', 'immich-machine-learning'))
    Write-Output 'ML_ARTIFACT_PREPARE=IMPORTED cache=class_archive_immich_spike_model_cache'
    exit 0
} catch {
    Stop-Prepare 'prepare_exception'
}
