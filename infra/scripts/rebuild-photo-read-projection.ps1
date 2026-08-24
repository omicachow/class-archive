[CmdletBinding()]
param(
    [switch]$DryRun,
    [switch]$Json,
    [ValidateSet('all', 'photos', 'aggregates')]
    [string]$Scope = 'all',
    [string]$Kinds = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$kindValues = @()
if ($Kinds -ne '') {
    $kindValues = @($Kinds.Split(',') | ForEach-Object { $_.Trim().ToUpperInvariant() } | Select-Object -Unique)
    $allowedKinds = @('TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT')
    if ($kindValues.Count -eq 0 -or @($kindValues | Where-Object { $_ -notin $allowedKinds }).Count -gt 0) {
        throw 'Kinds must be a comma-separated subset of TIMELINE, ALBUMS, PEOPLE, MEMORIES, SPOTLIGHT.'
    }
}
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$arguments = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php', "--scope=$Scope"
)
if ($DryRun) { $arguments += '--dry-run' }
if ($Json) { $arguments += '--json' }
if ($kindValues.Count -gt 0) { $arguments += '--kinds=' + ($kindValues -join ',') }
& "$env:SystemRoot\System32\wsl.exe" @arguments
exit $LASTEXITCODE
