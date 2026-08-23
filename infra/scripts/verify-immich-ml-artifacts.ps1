[CmdletBinding()]
param(
    [string]$ArtifactRoot,
    [string]$ManifestPath,
    [switch]$ManifestOnly
)

# Verifies a locally staged Immich v3.1.0 ML artifact closure before it can
# enter the isolated Docker cache. It never downloads, imports, starts a
# container, or accepts an unlisted file.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ([string]::IsNullOrWhiteSpace($ArtifactRoot)) {
    $ArtifactRoot = Join-Path $projectRoot '.codex-work\immich-ml-artifacts\staging'
}
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
}
$expectedImmichCommit = '8aa95c67470a02a8ddedf03c2e52963af33065ff'
$expectedMlImage = 'ghcr.io/immich-app/immich-machine-learning:v3.1.0@sha256:a25ddad7d6d2ab18a161176731dc171bb7e39c0e9dd3884fb1ec629dab535d05'
$expectedManifestSha256 = '46380b30910608a8f0226d6ed14e3535cdd3f43c6080115e19842a8eaeda7e7a'
$allowedRedistribution = @('ALLOWED', 'PROHIBITED', 'RESTRICTED', 'UNKNOWN')

function Stop-Verify([string]$Code) {
    Write-Output "ML_ARTIFACT_VERIFY=FAIL code=$Code"
    exit 2
}

function Assert-Field([object]$Value, [string]$Code) {
    if ($null -eq $Value -or ($Value -is [string] -and [string]::IsNullOrWhiteSpace($Value))) {
        Stop-Verify $Code
    }
}

try {
    $manifestItem = Get-Item -LiteralPath $ManifestPath -Force
    if ($manifestItem.PSIsContainer -or ($manifestItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-Verify 'manifest_path_untrusted'
    }
    $manifestRaw = Get-Content -LiteralPath $manifestItem.FullName -Raw -Encoding UTF8
    if ($manifestRaw.Length -lt 64 -or $manifestRaw.Length -gt 512KB) { Stop-Verify 'manifest_size_invalid' }
    $manifestHash = (Get-FileHash -LiteralPath $manifestItem.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($manifestHash -cne $expectedManifestSha256) { Stop-Verify 'manifest_digest_mismatch' }
    $manifest = $manifestRaw | ConvertFrom-Json -ErrorAction Stop
    if ($manifest.manifest_version -ne 1 -or $manifest.artifact_root -ne '/cache') { Stop-Verify 'manifest_version_invalid' }
    if ($manifest.generated_for.immich_version -ne '3.1.0' -or [string]$manifest.generated_for.immich_commit -cne $expectedImmichCommit) {
        Stop-Verify 'manifest_immich_compatibility_invalid'
    }
    if ([string]$manifest.generated_for.machine_learning_image -cne $expectedMlImage) {
        Stop-Verify 'manifest_ml_image_invalid'
    }
    $artifacts = @($manifest.artifacts)
    if ($artifacts.Count -lt 1 -or $artifacts.Count -gt 64) { Stop-Verify 'manifest_artifacts_invalid' }

    $expected = @{}
    foreach ($artifact in $artifacts) {
        foreach ($field in @('artifact_id', 'purpose', 'model_family', 'model_name', 'upstream_provider', 'source', 'exact_revision', 'relative_cache_path', 'file_name', 'file_size', 'sha256', 'license', 'license_source', 'redistribution_status', 'required_by_immich_version', 'required_by_immich_commit', 'required', 'notes')) {
            Assert-Field $artifact.$field "manifest_field_missing_$field"
        }
        $relative = [string]$artifact.relative_cache_path
        if ($relative -notmatch '^(?:clip|facial-recognition)/[A-Za-z0-9._/-]+$' -or $relative.Contains('..') -or $relative.Contains('//') -or $relative.Contains('\\')) {
            Stop-Verify 'manifest_relative_path_invalid'
        }
        if ($expected.ContainsKey($relative)) { Stop-Verify 'manifest_duplicate_relative_path' }
        if ([string]$artifact.file_name -ne [IO.Path]::GetFileName($relative)) { Stop-Verify 'manifest_file_name_invalid' }
        if ([int64]$artifact.file_size -lt 1 -or [int64]$artifact.file_size -gt 4GB) { Stop-Verify 'manifest_file_size_invalid' }
        if ([string]$artifact.sha256 -notmatch '^[0-9a-f]{64}$') { Stop-Verify 'manifest_sha256_invalid' }
        if ([string]$artifact.exact_revision -notmatch '^[0-9a-f]{40}$') { Stop-Verify 'manifest_revision_invalid' }
        if ([string]$artifact.source -notmatch ('^https://huggingface\.co/immich-app/(?:buffalo_l|ViT-B-32__openai)/resolve/' + [regex]::Escape([string]$artifact.exact_revision) + '/[A-Za-z0-9._/-]+\?download=true$')) {
            Stop-Verify 'manifest_source_invalid'
        }
        if ($artifact.required -ne $true -or $artifact.required_by_immich_version -ne '3.1.0' -or $artifact.required_by_immich_commit -ne $manifest.generated_for.immich_commit) {
            Stop-Verify 'manifest_requirement_invalid'
        }
        if ([string]$artifact.license_source -notmatch '^https://') { Stop-Verify 'manifest_license_source_invalid' }
        if ([string]$artifact.redistribution_status -cnotin $allowedRedistribution) { Stop-Verify 'manifest_redistribution_status_invalid' }
        $expected[$relative] = $artifact
    }

    if ($ManifestOnly) {
        Write-Output "ML_ARTIFACT_MANIFEST_VERIFY=PASS artifacts=$($expected.Count) manifest_sha256=$manifestHash"
        exit 0
    }

    $rootItem = Get-Item -LiteralPath $ArtifactRoot -Force
    if (-not $rootItem.PSIsContainer -or ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-Verify 'artifact_root_untrusted' }
    $root = $rootItem.FullName.TrimEnd('\', '/')
    $actual = @{}
    foreach ($file in @(Get-ChildItem -LiteralPath $root -Recurse -File -Force)) {
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-Verify 'artifact_link_forbidden' }
        $relative = $file.FullName.Substring($root.Length).TrimStart([char[]]@('\', '/')) -replace '\\', '/'
        if ($actual.ContainsKey($relative)) { Stop-Verify 'artifact_duplicate_path' }
        $actual[$relative] = $file
    }
    foreach ($relative in $expected.Keys) {
        if (-not $actual.ContainsKey($relative)) { Stop-Verify 'artifact_missing' }
    }
    foreach ($relative in $actual.Keys) {
        if (-not $expected.ContainsKey($relative)) { Stop-Verify 'artifact_unknown_file' }
    }

    $bytes = [int64]0
    foreach ($relative in ($expected.Keys | Sort-Object)) {
        $artifact = $expected[$relative]
        $file = $actual[$relative]
        if ([int64]$file.Length -ne [int64]$artifact.file_size) { Stop-Verify 'artifact_size_mismatch' }
        $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($hash -cne [string]$artifact.sha256) { Stop-Verify 'artifact_sha256_mismatch' }
        $bytes += [int64]$file.Length
    }

    Write-Output "ML_ARTIFACT_VERIFY=PASS artifacts=$($expected.Count) bytes=$bytes manifest_sha256=$manifestHash"
    exit 0
} catch {
    Stop-Verify 'verification_exception'
}
