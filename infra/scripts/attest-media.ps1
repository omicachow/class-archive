[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attestedPaths = @(
    'plugins/ClassArchivePolicy',
    'plugins/ClassIdentity/src/Schema.php',
    'infra/piwigo-nginx/nginx.conf',
    'tests/phase0'
)

$dirty = @(& git -C $projectRoot status --porcelain -- $attestedPaths)
if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the attested source paths.' }
if ($dirty.Count -gt 0) {
    throw 'Refusing attestation while an attested source path differs from Git.'
}
$commit = (& git -C $projectRoot rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[0-9a-f]{40}$') {
    throw 'Unable to resolve the exact Git commit for the attestation.'
}

$testOutput = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') test-phase0 2>&1)
$testExit = $LASTEXITCODE
$testOutput | ForEach-Object { Write-Output $_ }
if ($testExit -ne 0) {
    throw "MediaGuard regression suite failed with exit code $testExit."
}

$probeCount = 0
foreach ($line in $testOutput) {
    $match = [regex]::Match([string]$line, 'HTTP_PROBES=(\d+)')
    if ($match.Success) { $probeCount += [int]$match.Groups[1].Value }
}
if ($probeCount -lt 1) {
    throw 'MediaGuard suite did not emit a probe count.'
}

$arguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/write-media-attestation.php',
    "--commit=$commit",
    "--probe-count=$probeCount",
    '--test-suite-version=phase0-media-guard-v1'
)
& "$env:SystemRoot\System32\wsl.exe" @arguments
exit $LASTEXITCODE
