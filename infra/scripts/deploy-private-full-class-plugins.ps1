[CmdletBinding()]
param(
    # Never default this selector. The private full-library staging and owner
    # runtime deliberately share durable volumes but bind different loopback
    # endpoints; silently falling back to staging could re-create 8191 as 8291.
    [Parameter(Mandatory = $true)]
    [ValidateSet('staging', 'owner')]
    [string]$Endpoint
)

# Maintenance-gated Class Archive deployment for the isolated private
# full-library runtime. It deliberately never creates synthetic fixtures. The
# owner path additionally records a v14 DB-only rollback point before the
# forward-only v15 migration; no media volume is mounted by that snapshot.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$lockPath = Join-Path $projectRoot '.codex-work\private-real-full\runtime\class-plugin-workflow.lock'
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')

$endpointConfig = @{
    staging = @{
        piwigo_env = 'infra/private-full/.env.piwigo.staging'
        immich_env = 'infra/private-full/.env.immich.staging'
        http_port = '8290'
        compat_port = '8291'
        validation_action = 'validate'
        runtime_action = 'runtime-staging'
    }
    owner = @{
        piwigo_env = 'infra/private-full/.env.piwigo.owner'
        immich_env = 'infra/private-full/.env.immich.owner'
        http_port = '8190'
        compat_port = '8191'
        validation_action = 'validate-owner'
        runtime_action = 'runtime-owner'
    }
}
$target = $endpointConfig[$Endpoint]
if ($null -eq $target) { throw 'private_full_endpoint_invalid' }

$piwigoCompose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', [string]$target.piwigo_env,
    '-f', 'infra/docker-compose.yml', '-f', 'infra/private-full/docker-compose.override.yml',
    '-p', 'class_archive_private_full_v3_piwigo', '--profile', 'ops'
)

function Invoke-FullCompose([string[]]$Arguments) {
    & "$env:SystemRoot\System32\wsl.exe" @($script:piwigoCompose + $Arguments)
    if ($LASTEXITCODE -ne 0) { throw 'private_full_plugin_compose_failed' }
}

function Invoke-FullComposeCapture([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" @($script:piwigoCompose + $Arguments) 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) { throw 'private_full_plugin_compose_failed' }
    return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Get-WslPath([string]$WindowsPath) {
    $full = [IO.Path]::GetFullPath($WindowsPath)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { throw 'private_full_immich_env_missing' }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec wslpath -a $full 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0 -or $lines.Count -ne 1) { throw 'private_full_immich_env_path_invalid' }
    $path = ([string]$lines[0]).Trim()
    if ($path -notmatch '^/mnt/[a-z]/') { throw 'private_full_immich_env_path_invalid' }
    return $path
}

$immichEnvWindows = Join-Path $projectRoot ([string]$target.immich_env).Replace('/', '\')
$immichEnvWsl = Get-WslPath $immichEnvWindows
$immichCompose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'env', ('IMMICH_SPIKE_ENV_FILE=' + $immichEnvWsl),
    'docker', 'compose', '--env-file', [string]$target.immich_env,
    '-f', 'infra/immich-spike/docker-compose.yml', '-f', 'infra/private-full/docker-compose.immich.override.yml',
    '-p', 'class_archive_private_full_v3_immich',
    '--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-web-compat', '--profile', 'immich-gateway-integration'
)

function Invoke-ImmichCompose([string[]]$Arguments) {
    & "$env:SystemRoot\System32\wsl.exe" @($script:immichCompose + $Arguments)
    if ($LASTEXITCODE -ne 0) { throw 'private_full_compat_compose_failed' }
}

function Invoke-EndpointLifecycle([string]$Action) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $lifecycle $Action | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'private_full_lifecycle_invalid' }
}

function Wait-Maintenance {
    foreach ($attempt in 1..60) {
        # Invoke-WebRequest turns the intentional 503 into a terminating error.
        # Probe in Piwigo and require both the exact fail-closed body and status.
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $lines = @(& "$env:SystemRoot\System32\wsl.exe" @($script:piwigoCompose + @(
                'exec', '-T', 'piwigo',
                'curl', '--silent', '--show-error', '--write-out', 'CLASS_ARCHIVE_STATUS:%{http_code}', 'http://127.0.0.1/'
            )) 2>&1)
            $exit = $LASTEXITCODE
        }
        finally { $ErrorActionPreference = $previous }
        if ($exit -eq 0 -and $lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return }
        Start-Sleep -Seconds 1
    }
    throw 'private_full_maintenance_not_ready'
}

function Assert-PiwigoPhpFpmReady {
    Invoke-FullCompose @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/tests/phase1/php-fpm-ready.php'
    )
}

function Assert-PiwigoStoppedForSnapshot {
    $container = 'class_archive_private_full_v3_piwigo-piwigo-1'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec `
            docker inspect --format '{{.State.Running}}|{{.State.Status}}' $container 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0 -or $lines.Count -ne 1 -or ([string]$lines[0]).Trim() -ne 'false|exited') {
        throw 'private_full_writer_not_stopped'
    }
}

function Create-OwnerPreMigrationSnapshot {
    # This is intentionally DB-only. The compose service mounts only the local
    # backup volume and the read-only script tree; it has no original,
    # derivative, Piwigo-data, or source-staging mount.
    Invoke-FullCompose @('stop', 'piwigo')
    Assert-PiwigoStoppedForSnapshot
    Invoke-FullCompose @(
        'run', '--rm',
        '-e', 'CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=snapshot',
        '-e', 'CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true',
        'pre-migration-db-backup'
    )
}

function Get-OwnerPreMigrationSnapshotRequirement {
    # Probe the actual DB ledger before stopping the writer.  The service is
    # database-only and probe mode cannot create a bundle.  This makes owner
    # deployment repeatable after a successful v15 migration while unknown
    # schema states remain fail-closed.
    $lines = Invoke-FullComposeCapture @(
        'run', '--rm',
        '-e', 'CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe',
        'pre-migration-db-backup'
    )
    $records = @($lines | Where-Object {
        $_ -match '^PRE_MIGRATION_DB_SNAPSHOT=(REQUIRED_CURRENT_V14|NOT_REQUIRED_CURRENT_V15) schema_from=[0-9]+ schema_to=[0-9]+ scope=(DB_ONLY|NONE) media=NOT_INCLUDED$'
    })
    if ($records.Count -ne 1) { throw 'private_full_owner_schema_probe_invalid' }
    if ($records[0] -match '^PRE_MIGRATION_DB_SNAPSHOT=REQUIRED_CURRENT_V14 ') { return 'REQUIRED_CURRENT_V14' }
    if ($records[0] -match '^PRE_MIGRATION_DB_SNAPSHOT=NOT_REQUIRED_CURRENT_V15 ') { return 'NOT_REQUIRED_CURRENT_V15' }
    throw 'private_full_owner_schema_probe_invalid'
}

function RecreatePiwigoUnderMaintenance {
    Invoke-FullCompose @('up', '-d', '--force-recreate', '--no-deps', 'piwigo')
    Wait-Maintenance
    Assert-PiwigoPhpFpmReady
    # Startup can normalize ownership of the persistent marker. Re-assert its
    # exact trusted form before any verify/finalize operation can run.
    Invoke-FullCompose @(
        'exec', '-T', '--user', 'root', 'piwigo',
        'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare'
    )
}

$lock = $null
$preMigrationSnapshot = 'NOT_REQUIRED'
try {
    Invoke-EndpointLifecycle ([string]$target.validation_action)
    $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath

    # First make normal HTTP writes fail closed. The later CLI calls are the
    # bounded, audited deployment path; browser/BFF traffic can never race it.
    Invoke-FullCompose @(
        'exec', '-T', '--user', 'root', 'piwigo',
        'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare'
    )
    Wait-Maintenance

    # Owner migration is forward-only from schema 14 to 15. Probe the ledger
    # first: exact v14 gets a local, integrity-checked DB-only rollback
    # snapshot before changing plugin bytes, while exact v15 is a safe,
    # explicit no-op for snapshotting. Unknown versions fail closed.
    if ($Endpoint -eq 'owner') {
        $ownerSnapshotRequirement = Get-OwnerPreMigrationSnapshotRequirement
        if ($ownerSnapshotRequirement -eq 'REQUIRED_CURRENT_V14') {
            Create-OwnerPreMigrationSnapshot
            $preMigrationSnapshot = 'PASS'
            RecreatePiwigoUnderMaintenance
        }
        elseif ($ownerSnapshotRequirement -eq 'NOT_REQUIRED_CURRENT_V15') {
            $preMigrationSnapshot = 'NOT_REQUIRED_CURRENT_V15'
        }
        else {
            throw 'private_full_owner_schema_probe_invalid'
        }
    }

    # Baseline configuration selects the locked Bootstrap Darkroom theme, so
    # install reviewed Core extensions before ClassArchivePolicy → baseline →
    # ClassIdentity bootstrap. The web gate remains closed throughout.
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php')
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php')
    Invoke-FullCompose @('exec', '-T', '--user', 'root', 'piwigo', '/bin/ash', '/workspace/infra/scripts/restore-piwigo-user-script.sh')
    RecreatePiwigoUnderMaintenance
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--verify-runtime')
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-locked-piwigo-extensions.php', '--verify-only')

    # The v15 migrations add persistent read domains. Build them explicitly;
    # product reads remain behind the exact maintenance gate until both the
    # projection and the matching BFF process are ready.
    Invoke-FullCompose @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php', '--scope=all', '--json'
    )

    # Node loads its BFF routes at process start. Recreate only the restricted,
    # read-only compat service: never recreate the Immich server/ML runtime or
    # give the BFF a media mount.
    Invoke-ImmichCompose @('up', '-d', '--wait', '--wait-timeout', '60', '--force-recreate', 'immich-web-compat')

    # Finalization is deliberately last in the publish sequence. If either
    # projection rebuild or BFF recreation fails, nginx keeps returning the
    # exact fail-closed maintenance response instead of exposing a mixed
    # schema/UI generation.
    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance')
    Invoke-EndpointLifecycle ([string]$target.runtime_action)

    # Default maintenance is deliberately non-destructive. In particular, this
    # deployment path never passes --apply-rejected-cleanup and does not turn a
    # schema-migration rollback snapshot into a falsely current restore proof.
    Invoke-FullCompose @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/run-maintenance.php', '--json'
    )

    Write-Output ('PRIVATE_FULL_CLASS_PLUGINS=PASS endpoint=' + $Endpoint + ' ports=' + $target.http_port + '_' + $target.compat_port + ' fixtures=NONE protocol=MAINTENANCE_SNAPSHOT_RECREATE_VERIFY_FINALIZE_PROJECT_BFF_MAINTENANCE pre_migration_db_snapshot=' + $preMigrationSnapshot + ' bff=COMPAT_ONLY projection=REBUILT maintenance=NON_DESTRUCTIVE backup_restore=NOT_REVALIDATED')
}
catch {
    $code = if ([string]$_.Exception.Message -match '^[a-z0-9_]{1,96}$') { [string]$_.Exception.Message } else { 'private_full_plugin_deploy_failed' }
    Write-Output "PRIVATE_FULL_CLASS_PLUGINS=FAIL endpoint=$Endpoint code=$code"
    exit 2
}
finally {
    Exit-ClassArchivePluginWorkflowLock -Handle $lock
}
