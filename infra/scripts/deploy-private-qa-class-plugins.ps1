[CmdletBinding()]
param()

# Controlled Class Archive plugin deployment for the isolated private-real-data
# QA instance. The source media staging mount remains read-only and this script
# never accepts caller-supplied compose, volume, path, or credential arguments.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateValidator = Join-Path $PSScriptRoot 'private-qa.ps1'
$workflowLockPath = Join-Path $projectRoot '.codex-work\runtime\private-class-plugin-workflow.lock'
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')

$composeArguments = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose',
    '--env-file', 'infra/private-qa/.env.piwigo',
    '-f', 'infra/docker-compose.yml',
    '-f', 'infra/private-qa/docker-compose.override.yml',
    '-p', 'class_archive_private_qa_piwigo'
)

function Invoke-ComposeChecked([string[]]$Arguments, [string]$FailureCode) {
    & "$env:SystemRoot\System32\wsl.exe" @($composeArguments + $Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw $FailureCode
    }
}

function Prepare-Maintenance {
    Invoke-ComposeChecked @(
        'exec', '-T', '--user', 'root', 'piwigo',
        'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare'
    ) 'private_plugin_maintenance_prepare_failed'
}

function Wait-MaintenanceReady {
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $previousErrorAction = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $lines = @(& "$env:SystemRoot\System32\wsl.exe" @($composeArguments + @(
                'exec', '-T', 'piwigo',
                'curl', '--silent', '--show-error',
                '--write-out', 'CLASS_ARCHIVE_STATUS:%{http_code}',
                'http://127.0.0.1/'
            )) 2>&1)
            $probeExit = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $previousErrorAction
        }
        if (
            $probeExit -eq 0 `
            -and $lines.Count -eq 2 `
            -and $lines[0] -eq 'Class Archive maintenance mode.' `
            -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503'
        ) {
            & "$env:SystemRoot\System32\wsl.exe" @($composeArguments + @(
                'exec', '-T', '--user', 'nginx', 'piwigo',
                'php', '/workspace/tests/phase1/php-fpm-ready.php'
            ))
            if ($LASTEXITCODE -eq 0) { return }
        }
        Start-Sleep -Seconds 1
    }
    throw 'private_plugin_maintenance_readiness_failed'
}

$workflowLock = $null
try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $privateValidator validate
    if ($LASTEXITCODE -ne 0) { throw 'private_plugin_boundary_validation_failed' }

    $workflowLock = Enter-ClassArchivePluginWorkflowLock -LockPath $workflowLockPath
    Prepare-Maintenance
    Invoke-ComposeChecked @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/install-class-archive-plugins.php'
    ) 'private_plugin_install_failed'
    Invoke-ComposeChecked @(
        'exec', '-T', '--user', 'root', 'piwigo',
        '/bin/ash', '/workspace/infra/scripts/restore-piwigo-user-script.sh'
    ) 'private_plugin_media_permission_hook_failed'
    Invoke-ComposeChecked @('up', '-d', '--force-recreate', '--no-deps', 'piwigo') 'private_plugin_recreate_failed'
    Wait-MaintenanceReady
    Prepare-Maintenance
    Invoke-ComposeChecked @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-runtime'
    ) 'private_plugin_runtime_verify_failed'
    Invoke-ComposeChecked @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance'
    ) 'private_plugin_finalize_failed'
    Write-Output 'PRIVATE_QA_CLASS_PLUGINS=PASS protocol=MAINTENANCE_RECREATE_VERIFY_FINALIZE'
}
finally {
    Exit-ClassArchivePluginWorkflowLock -Handle $workflowLock
}
