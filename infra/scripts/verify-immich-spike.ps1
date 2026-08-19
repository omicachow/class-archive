[CmdletBinding()]
param(
    [switch]$ReadyToStart
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$spikeRoot = Join-Path $projectRoot 'infra\immich-spike'
$lockPath = Join-Path $spikeRoot 'immich-upstream.lock.json'
$composePath = Join-Path $spikeRoot 'docker-compose.yml'
$exampleEnvPath = Join-Path $spikeRoot '.env.example'

function Fail([string]$message) {
    [Console]::Error.WriteLine("IMMICH_SPIKE_PREFLIGHT=BLOCKED reason=$message")
    exit 3
}

foreach ($path in @($lockPath, $composePath, $exampleEnvPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        Fail "required_file_missing"
    }
}

try {
    $lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json -ErrorAction Stop
} catch {
    Fail 'invalid_upstream_lock'
}

if ($lock.lock_version -ne 1 -or $lock.upstream.repository -ne 'https://github.com/immich-app/immich.git') {
    Fail 'unexpected_upstream_lock'
}
if ($lock.upstream.version -notmatch '^v\d+\.\d+\.\d+$' -or $lock.upstream.commit -notmatch '^[0-9a-f]{40}$') {
    Fail 'invalid_source_pin'
}
if ($lock.upstream.license -ne 'AGPL-3.0-only') {
    Fail 'unexpected_license'
}

$compose = Get-Content -LiteralPath $composePath -Raw
$forbidden = @(
    'piwigo_db:',
    'piwigo_data:',
    'piwigo_derivatives:',
    'piwigo_scripts:',
    'class_archive_piwigo_app',
    'ports:'
)
foreach ($needle in $forbidden) {
    if ($compose.Contains($needle)) {
        Fail 'forbidden_piwigo_mount_or_direct_port'
    }
}
foreach ($needle in @(
    'piwigo_uploads:/external/piwigo-upload:ro',
    'piwigo_galleries:/external/piwigo-galleries:ro',
    'internal: true',
    'external: true'
)) {
    if (-not $compose.Contains($needle)) {
        Fail 'missing_read_only_or_isolation_constraint'
    }
}

# Docker Compose validates the full model without starting, pulling or exposing
# a container. The example has a non-secret placeholder password by design.
# WSL accepts the current Windows path directly for --cd. Keeping the source
# ASCII-only preserves Windows PowerShell 5.1 parsing in a Chinese workspace.
$configOutput = & wsl.exe -d Ubuntu --cd $projectRoot -- docker compose --env-file infra/immich-spike/.env.example -f infra/immich-spike/docker-compose.yml --profile immich-spike config 2>&1
if ($LASTEXITCODE -ne 0) {
    Fail 'compose_model_invalid'
}
$configText = [string]::Join("`n", @($configOutput))
if ($configText -match '(?m)^\s+ports:' -or $configText -match 'piwigo_db|piwigo_data|piwigo_derivatives|piwigo_scripts') {
    Fail 'resolved_model_violates_media_boundary'
}

$pending = @()
foreach ($imageName in @('immich_server', 'immich_machine_learning')) {
    $entry = $lock.images.$imageName
    if ($null -eq $entry -or [string]::IsNullOrWhiteSpace([string]$entry.digest) -or [string]$entry.digest -notmatch '^sha256:[0-9a-f]{64}$') {
        $pending += $imageName
    }
}

if ($ReadyToStart -and $pending.Count -gt 0) {
    Fail 'official_image_digest_not_verified'
}

$status = if ($pending.Count -eq 0) { 'READY_FOR_ISOLATED_START' } else { 'STATIC_PASS_IMAGE_DIGEST_PENDING' }
Write-Output "IMMICH_SPIKE_PREFLIGHT=$status source_commit=$($lock.upstream.commit) pending_images=$($pending.Count)"
