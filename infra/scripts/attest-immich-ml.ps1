[CmdletBinding()]
param()

# Persist a digest-bound operational record only after the exact, local ML
# artifact closure has passed host verification, an offline cold start, and a
# real synthetic People/Search pipeline.  It never downloads models, changes
# the production media path, or prints credentials/asset identifiers.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attestedPaths = @(
    'infra/immich-spike/ml-artifacts/manifest.json',
    'infra/immich-spike/docker-compose.yml',
    'infra/docker-compose.yml',
    'infra/scripts/verify-immich-ml-artifacts.ps1',
    'infra/scripts/prepare-immich-ml-artifacts.ps1',
    'infra/scripts/attest-immich-ml.ps1',
    'infra/scripts/invalidate-immich-ml-artifact-attestation.php',
    'infra/scripts/write-immich-ml-artifact-attestation.php',
    'plugins/ClassIdentity/src/MlArtifactAttestation.php',
    'plugins/ClassIdentity/src/BuildCommit.php',
    'tests/phase2/immich-ml-artifact-readiness.ps1',
    'tests/phase2/immich-ml-offline-cold-start.ps1',
    'tests/phase2/immich-people-search-runtime.ps1',
    'tests/phase2/immich-people-search-runtime.mjs',
    'tests/phase2/immich-ml-artifact-fail-closed.ps1',
    'tests/phase2/immich-gateway-bridge-runtime.ps1',
    'tests/phase2/immich-people-fixture.php',
    'tests/phase2/immich-people-search-browser.mjs',
    'tests/phase2/immich-runtime-isolation.ps1',
    'tests/fixtures/phase2-synthetic',
    'plugins/ClassIdentity/main.inc.php',
    'plugins/ClassIdentity/src',
    'infra/immich-spike/bridge/server.mjs',
    'infra/immich-spike/web-compat/server.mjs',
    'infra/php-fpm/class-archive-env.conf',
    'infra/piwigo-nginx/nginx.conf'
)

$dirty = @(& git -C $projectRoot status --porcelain)
if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the Git working tree.' }
if ($dirty.Count -gt 0) {
    throw 'Refusing ML attestation while the Git working tree is not clean.'
}
$commit = (& git -C $projectRoot rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[0-9a-f]{40}$') {
    throw 'Unable to resolve the exact Git commit for the ML attestation.'
}

$commands = @(
    @('verify', (Join-Path $projectRoot 'infra\scripts\verify-immich-ml-artifacts.ps1')),
    @('artifact_fail_closed', (Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-fail-closed.ps1')),
    @('readiness', (Join-Path $projectRoot 'tests\phase2\immich-ml-artifact-readiness.ps1'), '-RequireReady'),
    @('cold_start', (Join-Path $projectRoot 'tests\phase2\immich-ml-offline-cold-start.ps1')),
    @('runtime', (Join-Path $projectRoot 'tests\phase2\immich-people-search-runtime.ps1')),
    @('gateway_browser', (Join-Path $projectRoot 'tests\phase2\immich-gateway-bridge-runtime.ps1'), '-RuntimePeopleSearch', '-BrowserE2E')
)
foreach ($command in $commands) {
    $name = [string]$command[0]
    $script = [string]$command[1]
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $script) + @($command | Select-Object -Skip 2)
    $output = @(& powershell.exe @arguments 2>&1)
    $exitCode = $LASTEXITCODE
    $output | ForEach-Object { Write-Output $_ }
    if ($exitCode -ne 0) { throw ('ML artifact attestation gate failed: ' + $name) }
}

# The evidence is meaningful only if neither the commit nor any tracked or
# untracked source changed while the long-running runtime probes executed.
$finalCommit = (& git -C $projectRoot rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or ![string]::Equals($commit, $finalCommit, [StringComparison]::Ordinal)) {
    throw 'Refusing ML attestation because HEAD changed during validation.'
}
$dirty = @(& git -C $projectRoot status --porcelain)
if ($LASTEXITCODE -ne 0 -or $dirty.Count -gt 0) {
    throw 'Refusing ML attestation because the Git working tree changed during validation.'
}

$arguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/write-immich-ml-artifact-attestation.php',
    ("--commit=$commit")
)
& "$env:SystemRoot\System32\wsl.exe" @arguments
exit $LASTEXITCODE
