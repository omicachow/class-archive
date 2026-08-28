[CmdletBinding()]
param(
    [ValidateSet('first-screen', 'covers', 'all')]
    [string]$Scope = 'first-screen',
    [string]$Profiles = 'thumbnail,xsmall,small,medium,large,preview',
    [switch]$DryRun,
    [switch]$Json
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$arguments = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/warm-photo-cache.php',
    "--scope=$Scope",
    "--profiles=$Profiles"
)
if ($DryRun) { $arguments += '--dry-run' }
if ($Json) { $arguments += '--json' }

& "$env:SystemRoot\System32\wsl.exe" @arguments
exit $LASTEXITCODE
