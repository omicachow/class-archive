[CmdletBinding()]
param()

# Exercises the artifact gate with disposable, tiny synthetic copies.  The
# trusted staging cache is read-only input and is never modified.  A missing
# file and a same-size byte mutation must both be rejected before an artifact
# can reach the Immich ML cache.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$manifestPath = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
$verifyScript = Join-Path $projectRoot 'infra\scripts\verify-immich-ml-artifacts.ps1'
$trustedRoot = Join-Path $projectRoot '.codex-work\immich-ml-artifacts\staging'
$workParent = Join-Path $projectRoot '.codex-work'
if (-not (Test-Path -LiteralPath $workParent -PathType Container)) {
    $null = New-Item -ItemType Directory -Path $workParent
}
$workRoot = Join-Path $workParent ('phase25-ml-fault-' + [Guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $workRoot
$resolvedParent = (Resolve-Path -LiteralPath $workParent).Path.TrimEnd('\')
$resolvedWork = (Resolve-Path -LiteralPath $workRoot).Path
if (-not $resolvedWork.StartsWith($resolvedParent + '\phase25-ml-fault-', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Disposable fault-injection path escaped the ignored work directory.'
}

function Invoke-ExpectedFailure([string]$ArtifactRoot, [string]$FixtureManifest, [string]$Code) {
    $output = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $verifyScript -ArtifactRoot $ArtifactRoot -ManifestPath $FixtureManifest 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -eq 0 -or -not (($output -join "`n").Contains("ML_ARTIFACT_VERIFY=FAIL code=$Code"))) {
        throw "Artifact gate did not fail closed for $Code."
    }
}

try {
    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop
    $artifactRoot = Join-Path $workRoot 'cache'
    $null = New-Item -ItemType Directory -Path $artifactRoot
    Invoke-ExpectedFailure $artifactRoot $manifestPath 'artifact_missing'

    $target = $null
    foreach ($artifact in @($manifest.artifacts)) {
        $relativeWindows = ([string]$artifact.relative_cache_path) -replace '/', '\'
        $source = Join-Path $trustedRoot $relativeWindows
        $destination = Join-Path $artifactRoot $relativeWindows
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw 'Trusted artifact closure is unavailable.' }
        $null = New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination)
        if ([string]$artifact.relative_cache_path -eq 'clip/ViT-B-32__openai/config.json') {
            Copy-Item -LiteralPath $source -Destination $destination
            $target = $destination
        } else {
            $null = New-Item -ItemType HardLink -Path $destination -Target $source
        }
    }
    if ($null -eq $target) { throw 'Deterministic tiny artifact was not found in the tracked manifest.' }
    $bytes = [IO.File]::ReadAllBytes($target)
    if ($bytes.Length -lt 1) { throw 'Tiny artifact unexpectedly empty.' }
    $bytes[0] = $bytes[0] -bxor 1
    [IO.File]::WriteAllBytes($target, $bytes)
    Invoke-ExpectedFailure $artifactRoot $manifestPath 'artifact_sha256_mismatch'

    Write-Output 'ML_ARTIFACT_FAIL_CLOSED=PASS missing=PASS hash_mismatch=PASS evidence=RUNTIME_TESTED assertions=2'
} finally {
    if (Test-Path -LiteralPath $resolvedWork) {
        [IO.Directory]::Delete($resolvedWork, $true)
    }
}
