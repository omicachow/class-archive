[CmdletBinding()]
param(
    [string]$ManifestPath
)

# Capacity proof for the Docker-managed full private library. It deliberately
# budgets only the browse-ready Piwigo path; full Immich indexing remains
# deferred until the legacy sample-QA volumes are retired after cutover.
# The input manifest is ignored/local and is read only for opaque checksum and
# byte counters. It never prints a source path or filename.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ([string]::IsNullOrWhiteSpace($ManifestPath)) {
    $ManifestPath = Join-Path $projectRoot '.codex-work\private-real-full\manifests\full-real-import-manifest.json'
}

function Stop-Preflight([string]$Code) {
    Write-Output ('PRIVATE_FULL_STORAGE_PREFLIGHT=FAIL code=' + $Code)
    exit 2
}

try {
    $manifest = Get-Item -LiteralPath $ManifestPath -Force -ErrorAction Stop
    if ($manifest.PSIsContainer -or ($manifest.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-Preflight 'manifest_untrusted' }
    $relative = $manifest.FullName.Substring($projectRoot.TrimEnd('\').Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-Preflight 'manifest_not_ignored' }
    . (Join-Path $PSScriptRoot 'secret-file-acl.ps1')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $manifest.FullName
    try { $data = Get-Content -LiteralPath $manifest.FullName -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-Preflight 'manifest_invalid' }
    if ([string]$data.import_digest -notmatch '^[a-f0-9]{64}$' -or @($data.items).Count -lt 1) { Stop-Preflight 'manifest_invalid' }

    $sourceBytes = [Int64]0
    $canonical = @{}
    foreach ($item in @($data.items)) {
        $hash = [string]$item.source_sha256
        $size = $item.file_size
        if ($hash -notmatch '^[a-f0-9]{64}$' -or $size -isnot [Int64] -and $size -isnot [Int32] -and $size -isnot [Int64]) { Stop-Preflight 'manifest_item_invalid' }
        $value = [Int64]$size
        if ($value -le 0) { Stop-Preflight 'manifest_item_invalid' }
        $sourceBytes += $value
        if (-not $canonical.ContainsKey($hash)) { $canonical[$hash] = $value }
        elseif ([Int64]$canonical[$hash] -ne $value) { Stop-Preflight 'canonical_size_drift' }
    }
    $canonicalBytes = [Int64]0
    foreach ($value in $canonical.Values) { $canonicalBytes += [Int64]$value }
    # Conservative browse-ready derivative budget (35% of originals), based
    # on the already verified derivative policy. ML cache/index is expressly
    # zero here because it cannot start before post-cutover capacity review.
    $derivativeBytes = [Int64][Math]::Ceiling([double]$canonicalBytes * 0.35)
    $controlBytes = [Int64]1GB
    $safetyBytes = [Int64]10GB
    $drive = Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'" -ErrorAction Stop
    $freeBytes = [Int64]$drive.FreeSpace
    $postImportFree = $freeBytes - $canonicalBytes - $derivativeBytes - $controlBytes
    if ($postImportFree -lt $safetyBytes) { Stop-Preflight 'docker_managed_capacity_insufficient' }

    Write-Output ('PRIVATE_FULL_STORAGE_PREFLIGHT=PASS source_image_bytes=' + $sourceBytes + ' canonical_bytes=' + $canonicalBytes + ' estimated_derivative_bytes=' + $derivativeBytes + ' control_budget_bytes=' + $controlBytes + ' ml_budget=DEFERRED post_import_free_estimate=' + $postImportFree + ' required_safety_bytes=' + $safetyBytes + ' storage=DOCKER_MANAGED_POSIX_VOLUMES')
    exit 0
}
catch {
    $message = [string]$_.Exception.Message
    if ($message -match '^PRIVATE_FULL_STORAGE_PREFLIGHT=FAIL') { throw }
    $type = $_.Exception.GetType().Name
    if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
    Write-Output ('PRIVATE_FULL_STORAGE_PREFLIGHT=FAIL code=unexpected_' + $type)
    exit 2
}
