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
    throw [InvalidOperationException]::new('PRIVATE_FULL_STORAGE_PREFLIGHT_STOP:' + $Code)
}

function Get-PrivateFullManagedVolumeCapacity {
    # Docker Desktop may keep its Linux data disk outside the Windows C:
    # filesystem. Measuring C: therefore double-counts an already imported
    # library and can reject a safe blue/green candidate. Probe the exact
    # Docker-managed payload volume read-only instead, under the same Linux
    # storage semantics that enforce media mode 0660 at runtime.
    $wsl = "$env:SystemRoot\System32\wsl.exe"
    $volume = 'class_archive_private_full_v3_piwigo_uploads'
    $image = 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
    if (-not (Test-Path -LiteralPath $wsl -PathType Leaf)) { Stop-Preflight 'wsl_unavailable' }

    $inspect = @(& $wsl -d Ubuntu --exec docker volume inspect $volume 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-Preflight 'target_volume_unavailable' }
    try { $records = ([string]::Join("`n", $inspect) | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-Preflight 'target_volume_inspect_invalid' }
    if (@($records).Count -ne 1 -or [string]@($records)[0].Driver -ne 'local') { Stop-Preflight 'target_volume_untrusted' }
    $options = @($records)[0].Options
    if ($null -ne $options -and -not [string]::IsNullOrWhiteSpace([string]$options.device)) { Stop-Preflight 'target_volume_not_docker_managed' }

    $imageCheck = @(& $wsl -d Ubuntu --exec docker image inspect $image 2>$null)
    if ($LASTEXITCODE -ne 0 -or $imageCheck.Count -lt 1) { Stop-Preflight 'target_probe_image_unavailable' }
    # The locked Piwigo image uses BusyBox tooling, whose df has no GNU
    # --output flag. POSIX output with one-byte blocks is available in both
    # the runtime image and ordinary Linux utilities.
    $probe = 'set -eu; used=$(du -sb /payload | awk ''{print $1}''); available=$(df -P -B 1 /payload | awk ''NR == 2 {print $4}''); case "$used $available" in (*[!0-9\ ]*) exit 12;; esac; printf ''%s %s\n'' "$used" "$available"'
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($probe))
    $output = @(& $wsl -d Ubuntu --exec docker run --rm --network none --entrypoint sh --mount ("type=volume,source=$volume,target=/payload,readonly") $image -lc ("printf %s $encoded | base64 -d | sh") 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-Preflight 'target_volume_probe_failed' }
    $lines = @($output | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -match '^\d+ \d+$' })
    if ($lines.Count -ne 1) { Stop-Preflight 'target_volume_probe_invalid' }
    $parts = $lines[0].Split(' ')
    try {
        $used = [Int64]$parts[0]
        $free = [Int64]$parts[1]
    } catch { Stop-Preflight 'target_volume_probe_invalid' }
    if ($used -lt 0 -or $free -le 0) { Stop-Preflight 'target_volume_probe_invalid' }
    return [pscustomobject]@{ UsedBytes = $used; FreeBytes = $free }
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
    $capacity = Get-PrivateFullManagedVolumeCapacity
    $freeBytes = [Int64]$capacity.FreeBytes
    $existingBytes = [Int64]$capacity.UsedBytes
    # A completed import occupies this dedicated volume already. Do not
    # subtract its canonical originals a second time; a nonempty but partial
    # volume is ambiguous and intentionally blocks instead of guessing.
    if ($existingBytes -eq 0) {
        $remainingOriginalBytes = $canonicalBytes
        $payloadState = 'EMPTY'
    } elseif ($existingBytes -ge $canonicalBytes) {
        $remainingOriginalBytes = [Int64]0
        $payloadState = 'CANONICAL_PRESENT'
    } else {
        Stop-Preflight 'target_volume_partial_import_ambiguous'
    }
    $postImportFree = $freeBytes - $remainingOriginalBytes - $derivativeBytes - $controlBytes
    if ($postImportFree -lt $safetyBytes) { Stop-Preflight 'docker_managed_capacity_insufficient' }

    Write-Output ('PRIVATE_FULL_STORAGE_PREFLIGHT=PASS source_image_bytes=' + $sourceBytes + ' canonical_bytes=' + $canonicalBytes + ' existing_payload_bytes=' + $existingBytes + ' payload_state=' + $payloadState + ' estimated_derivative_bytes=' + $derivativeBytes + ' control_budget_bytes=' + $controlBytes + ' ml_budget=DEFERRED post_import_free_estimate=' + $postImportFree + ' required_safety_bytes=' + $safetyBytes + ' storage=DOCKER_MANAGED_POSIX_VOLUMES')
    exit 0
}
catch {
    $code = if ($_.Exception.Message -match '^PRIVATE_FULL_STORAGE_PREFLIGHT_STOP:([a-z0-9_]{1,96})$') {
        [string]$Matches[1]
    } else {
        $type = $_.Exception.GetType().Name
        if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
        'unexpected_' + $type
    }
    Write-Output ('PRIVATE_FULL_STORAGE_PREFLIGHT=FAIL code=' + $code)
    exit 2
}
