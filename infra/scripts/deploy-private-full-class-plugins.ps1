[CmdletBinding()]
param()

# Maintenance-gated plugin deployment for the isolated private full-library
# staging endpoint. It deliberately never creates synthetic fixtures.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$lockPath = Join-Path $projectRoot '.codex-work\private-real-full\runtime\class-plugin-workflow.lock'
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')

function Invoke-FullCompose([string[]]$Arguments) {
    $compose = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', 'infra/private-full/.env.piwigo.staging',
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo'
    )
    & "$env:SystemRoot\System32\wsl.exe" @($compose + $Arguments)
    if ($LASTEXITCODE -ne 0) { throw 'private_full_plugin_compose_failed' }
}

function Wait-Maintenance {
    foreach ($attempt in 1..60) {
        # Invoke-WebRequest turns the intentional 503 into a terminating
        # PowerShell error. Probe from the container and retain both the exact
        # fail-closed body and status as the readiness contract instead.
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- `
            docker compose --env-file infra/private-full/.env.piwigo.staging `
            -f infra/docker-compose.yml -f infra/private-full/docker-compose.override.yml `
            -p class_archive_private_full_v3_piwigo exec -T piwigo `
            curl --silent --show-error --write-out 'CLASS_ARCHIVE_STATUS:%{http_code}' http://127.0.0.1/ 2>&1)
        if ($LASTEXITCODE -eq 0 -and $lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return }
        Start-Sleep -Seconds 1
    }
    throw 'private_full_maintenance_not_ready'
}

$lock = $null
try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $lifecycle validate | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'private_full_lifecycle_invalid' }
    $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
    # Baseline configuration selects the locked Bootstrap Darkroom theme, so
    # install the reviewed Core extensions before the ClassArchivePolicy →
    # baseline → ClassIdentity bootstrap ordering begins.
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php')
    Invoke-FullCompose @('exec', '-T', '--user', 'root', 'piwigo', 'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare')
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php')
    Invoke-FullCompose @('exec', '-T', '--user', 'root', 'piwigo', '/bin/ash', '/workspace/infra/scripts/restore-piwigo-user-script.sh')
    Invoke-FullCompose @('up', '-d', '--force-recreate', '--no-deps', 'piwigo')
    Wait-Maintenance
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-runtime')
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance')
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php', '--verify-only')
    Write-Output 'PRIVATE_FULL_CLASS_PLUGINS=PASS fixtures=NONE protocol=MAINTENANCE_RECREATE_VERIFY_FINALIZE'
}
catch {
    $code = if ([string]$_.Exception.Message -match '^[a-z0-9_]{1,96}$') { [string]$_.Exception.Message } else { 'private_full_plugin_deploy_failed' }
    Write-Output "PRIVATE_FULL_CLASS_PLUGINS=FAIL code=$code"
    exit 2
}
finally {
    Exit-ClassArchivePluginWorkflowLock -Handle $lock
}
