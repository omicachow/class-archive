[CmdletBinding()]
param(
    [switch]$RequireLocal
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$spikeRoot = Split-Path -Parent $PSCommandPath
$projectRoot = Split-Path -Parent (Split-Path -Parent $spikeRoot)
$lockPath = Join-Path $spikeRoot 'immich-upstream.lock.json'
$composePath = Join-Path $spikeRoot 'docker-compose.yml'

function Fail([string]$reason) {
    [Console]::Error.WriteLine("IMMICH_SUPPLY_CHAIN=BLOCKED evidence=STATIC reason=$reason")
    exit 3
}

function Assert-Exact([bool]$condition, [string]$reason) {
    if (-not $condition) {
        Fail $reason
    }
}

foreach ($path in @($lockPath, $composePath)) {
    Assert-Exact (Test-Path -LiteralPath $path -PathType Leaf) "missing_required_file"
}

try {
    $lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json -ErrorAction Stop
} catch {
    Fail 'invalid_upstream_lock'
}

Assert-Exact ($lock.upstream.repository -eq 'https://github.com/immich-app/immich.git') 'unexpected_upstream_repository'
Assert-Exact ($lock.upstream.version -match '^v\d+\.\d+\.\d+$') 'invalid_upstream_tag'
Assert-Exact ($lock.upstream.commit -match '^[0-9a-f]{40}$') 'invalid_upstream_commit'
Assert-Exact ($lock.upstream.license -eq 'AGPL-3.0-only') 'unexpected_license'
Assert-Exact ($lock.source_archive.origin -eq 'official_github_codeload') 'unexpected_source_origin'
Assert-Exact ($lock.source_archive.url -match '^https://codeload\.github\.com/immich-app/immich/tar\.gz/refs/tags/') 'unexpected_source_url'
Assert-Exact ($lock.source_archive.tag_ref_commit -eq $lock.upstream.commit) 'source_tag_commit_mismatch'
Assert-Exact ($lock.source_archive.sha256 -match '^[0-9a-f]{64}$') 'invalid_source_sha256'

$compose = Get-Content -LiteralPath $composePath -Raw
$imageEntries = @($lock.images.immich_server, $lock.images.immich_machine_learning)
foreach ($entry in $imageEntries) {
    Assert-Exact ($entry.registry -eq 'official_ghcr') 'unexpected_image_registry'
    Assert-Exact ($entry.platform -eq 'linux/amd64') 'unexpected_image_platform'
    Assert-Exact ($entry.digest -match '^sha256:[0-9a-f]{64}$') 'invalid_image_digest'
    Assert-Exact ($entry.pinned_reference -match '^ghcr\.io/immich-app/') 'unexpected_pinned_image_reference'
    Assert-Exact ($entry.pinned_reference.EndsWith("@$($entry.digest)")) 'pinned_reference_digest_mismatch'
    Assert-Exact ($compose.Contains([string]$entry.pinned_reference)) 'compose_image_not_locked_to_verified_digest'
}
Assert-Exact (-not $compose.Contains('IMMICH_VERSION')) 'floating_immich_version_variable_present'

if ($RequireLocal) {
    $archivePath = Join-Path $projectRoot ([string]$lock.source_archive.local_archive)
    Assert-Exact (Test-Path -LiteralPath $archivePath -PathType Leaf) 'local_source_archive_missing'
    $archiveHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    Assert-Exact ($archiveHash -eq $lock.source_archive.sha256) 'local_source_archive_sha256_mismatch'

    $extractionPath = Join-Path $projectRoot ([string]$lock.source_archive.local_extraction)
    foreach ($file in @('LICENSE', 'package.json', 'web/package.json', 'server/package.json')) {
        Assert-Exact (Test-Path -LiteralPath (Join-Path $extractionPath $file) -PathType Leaf) 'local_source_extraction_incomplete'
    }
    $rootPackage = Get-Content -LiteralPath (Join-Path $extractionPath 'package.json') -Raw | ConvertFrom-Json
    $webPackage = Get-Content -LiteralPath (Join-Path $extractionPath 'web/package.json') -Raw | ConvertFrom-Json
    $serverPackage = Get-Content -LiteralPath (Join-Path $extractionPath 'server/package.json') -Raw | ConvertFrom-Json
    $expectedVersion = ([string]$lock.upstream.version).TrimStart('v')
    Assert-Exact ($rootPackage.version -eq $expectedVersion -and $webPackage.version -eq $expectedVersion -and $serverPackage.version -eq $expectedVersion) 'local_source_version_mismatch'

    # Keep Docker's Go-template out of the native-command argument grammar.
    # Windows PowerShell 5.1 otherwise misparses the nested `{{...}}` token
    # when it appears inline inside the array expression below.
    $dockerInspectFormat = '{{.Os}}|{{.Architecture}}|{{range .RepoDigests}}{{.}};{{end}}'
    foreach ($entry in $imageEntries) {
        # Image inspection is daemon-scoped and does not require a project
        # directory, which also avoids a source-encoding-dependent WSL path.
        $inspect = @(& wsl.exe -d Ubuntu -- docker image inspect ([string]$entry.pinned_reference) --format $dockerInspectFormat 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Fail 'local_official_image_missing'
        }
        $inspectText = [string]::Join("`n", $inspect)
        Assert-Exact ($inspectText -match 'linux\|amd64\|') 'local_image_platform_mismatch'
        Assert-Exact ($inspectText.Contains("@$($entry.digest)")) 'local_image_repo_digest_mismatch'
    }
}

$localStatus = if ($RequireLocal) { 'VERIFIED' } else { 'NOT_REQUESTED' }
Write-Output "IMMICH_SUPPLY_CHAIN=PASS evidence=STATIC source_archive=VERIFIED local_artifacts=$localStatus images=VERIFIED platform=linux/amd64"
