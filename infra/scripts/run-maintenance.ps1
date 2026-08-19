[CmdletBinding()]
param(
    [switch]$ApplyRejectedCleanup,
    [switch]$RequireReady,
    [switch]$Json
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$auditArguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml',
    '--profile', 'ops',
    'run', '--rm',
    '-e', 'CLASS_ARCHIVE_BACKUP_AUDIT_WRITE=true',
    'backup-audit'
)
& "$env:SystemRoot\System32\wsl.exe" @auditArguments
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$arguments = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/run-maintenance.php'
)
if ($ApplyRejectedCleanup) { $arguments += '--apply-rejected-cleanup' }
if ($RequireReady) { $arguments += '--require-ready' }
if ($Json) { $arguments += '--json' }

& "$env:SystemRoot\System32\wsl.exe" @arguments
exit $LASTEXITCODE
