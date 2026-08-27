[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'apply')]
    [string]$Action = 'validate',

    # A caller must opt into owner explicitly.  The safer restored copy is the
    # default and remains the required first execution target.
    [ValidateSet('restore', 'owner')]
    [string]$Target = 'restore',

    [switch]$ConfirmSupplementalApply,
    [switch]$ConfirmOwnerRuntime,
    [string]$OutputPath,
    [string]$StagingPath
)

# One-shot apply boundary for the reviewed 28-source / 26-presentation batch.
# It never reads original source roots. The normal Piwigo writer is put behind
# its durable maintenance gate and stopped before the isolated writer starts.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-qa\supplemental'
$diagnosticRoot = if ($Target -eq 'restore') {
    Join-Path $projectRoot '.codex-work\owner-restore\runtime'
} else {
    Join-Path $projectRoot '.codex-work\private-real-full\runtime'
}
if ([string]::IsNullOrWhiteSpace($OutputPath)) { $OutputPath = $privateRoot }
if ([string]::IsNullOrWhiteSpace($StagingPath)) { $StagingPath = Join-Path $privateRoot 'staging' }
$manifestPath = Join-Path $OutputPath 'manifests\supplemental-import-manifest.json'
$verifyTool = Join-Path $PSScriptRoot 'private-real-supplemental.ps1'
$applyOverlayRelative = 'infra/private-full/docker-compose.supplemental-apply.override.yml'
$lockPath = Join-Path $projectRoot '.codex-work\private-real-full\runtime\supplemental-apply.lock'
$script:stage = 'initialization'
$script:assertions = 0

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')

$targets = @{
    restore = @{
        env = 'infra/owner-restore/.env.piwigo'
        project = 'class_archive_owner_restore_v1_piwigo'
        scope = 'owner-restore-drill'
        http = '8290'
        compat = '8291'
        compose = @('infra/docker-compose.yml', 'infra/owner-restore/docker-compose.piwigo.override.yml', 'infra/private-full/docker-compose.ai-worker.override.yml', $applyOverlayRelative)
        volumes = @{
            piwigo_data = 'class_archive_owner_restore_v1_piwigo_data'
            piwigo_uploads = 'class_archive_owner_restore_v1_piwigo_uploads'
            piwigo_galleries = 'class_archive_owner_restore_v1_piwigo_galleries'
            piwigo_derivatives = 'class_archive_owner_restore_v1_piwigo_derivatives'
        }
    }
    owner = @{
        env = 'infra/private-full/.env.piwigo.owner'
        project = 'class_archive_private_full_v3_piwigo'
        scope = 'private-real-full'
        http = '8190'
        compat = '8191'
        compose = @('infra/docker-compose.yml', 'infra/private-full/docker-compose.override.yml', $applyOverlayRelative)
        volumes = @{
            piwigo_data = 'class_archive_private_full_v3_control_piwigo_data'
            piwigo_uploads = 'class_archive_private_full_v3_piwigo_uploads'
            piwigo_galleries = 'class_archive_private_full_v3_piwigo_galleries'
            piwigo_derivatives = 'class_archive_private_full_v3_piwigo_derivatives'
        }
    }
}

function Stop-SupplementalApply([string]$Code) {
    if ($Code -notmatch '^[a-z0-9_]{1,96}$') { $Code = 'supplemental_apply_failed' }
    Write-Output "PRIVATE_REAL_SUPPLEMENTAL_APPLY=FAIL action=$Action target=$Target stage=$script:stage code=$Code"
    exit 2
}

function Assert-Apply([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw $Code }
}

function Get-Property([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Assert-NoReparse([string]$Path, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetPathRoot($full)
    $current = $root
    foreach ($component in @($full.Substring($root.Length) -split '[\\/]' | Where-Object { $_ -ne '' })) {
        $current = Join-Path $current $component
        Assert-Apply (Test-Path -LiteralPath $current) $Code
        $item = Get-Item -LiteralPath $current -Force -ErrorAction Stop
        Assert-Apply (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) $Code
    }
    return $full
}

function Assert-IgnoredPrivateLeaf([string]$Path, [string]$Code) {
    $full = Assert-NoReparse $Path ($Code + '_reparse')
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    Assert-Apply (-not $item.PSIsContainer) ($Code + '_not_file')
    $relative = [IO.Path]::GetRelativePath($projectRoot, $full).Replace('\', '/')
    Assert-Apply (-not $relative.StartsWith('../', [StringComparison]::Ordinal)) ($Code + '_outside_checkout')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-Apply ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    Assert-Apply ($LASTEXITCODE -eq 0 -and $tracked.Count -eq 0) ($Code + '_tracked')
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
    $script:assertions++
    return $full
}

function Get-WslPath([string]$Path, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --exec wslpath -a $Path 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Apply ($exit -eq 0 -and $lines.Count -eq 1 -and [string]$lines[0] -match '^/mnt/c/') $Code
    return [string]$lines[0]
}

function Save-PrivateFailureDiagnostic([string]$Code, [object[]]$Lines) {
    try {
        $path = Join-Path $diagnosticRoot 'supplemental-apply-error.json'
        [void][IO.Directory]::CreateDirectory((Split-Path -Parent $path))
        $record = [ordered]@{
            generated_at = (Get-Date).ToUniversalTime().ToString('o')
            stage = $script:stage
            requested_code = $Code
            output = @($Lines | ForEach-Object { [string]$_ })
        }
        [IO.File]::WriteAllText($path, ($record | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $path
        $relative = [IO.Path]::GetRelativePath($projectRoot, $path).Replace('\', '/')
        & git -C $projectRoot check-ignore --quiet --no-index -- $relative
        if ($LASTEXITCODE -ne 0) { [IO.File]::Delete($path) }
    }
    catch { }
}

function Invoke-WslCapture([string[]]$Arguments, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec @Arguments 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) {
        Save-PrivateFailureDiagnostic $Code $lines
        # The one-shot importer intentionally emits bounded machine error
        # codes. Preserve only that non-sensitive code and never forward raw
        # container output, which may contain a private staging path.
        foreach ($line in $lines) {
            $match = [regex]::Match([string]$line, '(?:^|\s)code=(?<code>[a-z0-9_]{1,96})(?:\s|$)')
            if ($match.Success) { throw [string]$match.Groups['code'].Value }
        }
    }
    Assert-Apply ($exit -eq 0) $Code
    return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Invoke-VerifiedArtifact {
    $script:stage = 'artifact_verify'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $verifyTool verify `
            -OutputPath $OutputPath -StagingPath $StagingPath 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Apply ($exit -eq 0) 'artifact_verify_failed'
    $safe = @($lines | Where-Object { [string]$_ -match '^PRIVATE_REAL_SUPPLEMENTAL_OPERATOR=PASS action=verify sources=28 presentations=26 artifact_acl=OWNER_ONLY git=IGNORED_UNTRACKED source_paths=NOT_PRINTED docker=NOT_STARTED assertions=[0-9]+$' })
    Assert-Apply ($safe.Count -eq 1) 'artifact_verify_result_invalid'
}

function Get-ComposePrefix([hashtable]$Spec, [string]$ManifestWsl, [string]$StagingWsl, [string]$ProjectWsl) {
    $arguments = @('env', ('PRIVATE_SUPPLEMENTAL_MANIFEST_PATH=' + $ManifestWsl), ('PRIVATE_SUPPLEMENTAL_STAGING_PATH=' + $StagingWsl),
        ('PRIVATE_SUPPLEMENTAL_IMPORTER_PATH=' + $ProjectWsl.TrimEnd('/') + '/infra/scripts/import-private-real-full.php'),
        ('PRIVATE_SUPPLEMENTAL_TARGET_GATE_PATH=' + $ProjectWsl.TrimEnd('/') + '/infra/scripts/verify-private-real-supplemental-target.php'),
        'docker', 'compose', '--env-file', [string]$Spec.env)
    foreach ($file in @($Spec.compose)) { $arguments += @('-f', [string]$file) }
    return @($arguments + @('-p', [string]$Spec.project, '--profile', 'ops', '--profile', 'private-supplemental-apply'))
}

function Invoke-Compose([string[]]$Prefix, [string[]]$Arguments, [string]$Code) {
    return Invoke-WslCapture @($Prefix + $Arguments) $Code
}

function Assert-ComposeModel([hashtable]$Spec, [string[]]$Prefix, [string]$ManifestWsl, [string]$StagingWsl, [string]$ProjectWsl) {
    $script:stage = 'compose_model'
    $lines = Invoke-Compose $Prefix @('config', '--format', 'json') 'compose_config_failed'
    try { $config = [string]::Join("`n", $lines) | ConvertFrom-Json -ErrorAction Stop } catch { throw 'compose_config_invalid' }
    Assert-Apply ([string](Get-Property $config 'name') -ceq [string]$Spec.project) 'compose_project_invalid'
    $services = Get-Property $config 'services'
    $service = Get-Property $services 'supplemental-apply'
    Assert-Apply ($null -ne $service) 'apply_service_missing'
    Assert-Apply ((Get-Property $service 'read_only') -eq $true) 'apply_root_not_read_only'
    Assert-Apply ([string](Get-Property $service 'pull_policy') -ceq 'never') 'apply_pull_policy_invalid'
    Assert-Apply ([string](Get-Property $service 'restart') -ceq 'no') 'apply_restart_policy_invalid'
    Assert-Apply ([string](Get-Property $service 'user') -match '^[0-9]+:[0-9]+$') 'apply_user_invalid'
    Assert-Apply (@((Get-Property $service 'cap_drop')) -contains 'ALL') 'apply_cap_drop_missing'
    Assert-Apply (@((Get-Property $service 'security_opt')) -contains 'no-new-privileges:true') 'apply_no_new_privileges_missing'
    Assert-Apply ($null -eq (Get-Property $service 'ports') -and $null -eq (Get-Property $service 'expose')) 'apply_host_port_forbidden'
    $networks = Get-Property $service 'networks'
    $networkNames = @($networks.PSObject.Properties.Name)
    Assert-Apply ($networkNames.Count -eq 1 -and $networkNames[0] -ceq 'supplemental_internal') 'apply_network_set_invalid'
    $network = Get-Property (Get-Property $config 'networks') 'supplemental_internal'
    Assert-Apply ((Get-Property $network 'internal') -eq $true) 'apply_network_not_internal'
    $networkName = [string](Get-Property $network 'name')
    Assert-Apply ($networkName -ceq ([string]$Spec.project + '_supplemental_internal')) 'apply_network_identity_invalid'
    $environment = Get-Property $service 'environment'
    foreach ($pair in @{
        CLASS_ARCHIVE_RUNTIME_SCOPE = 'PRIVATE_REAL_FULL'
        CLASS_ARCHIVE_PRIVATE_REAL_FULL = '1'
        CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL = '1'
        CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_APPLY = '1'
        CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_ACTION = 'preflight'
    }.GetEnumerator()) {
        Assert-Apply ([string](Get-Property $environment ([string]$pair.Key)) -ceq [string]$pair.Value) 'apply_environment_invalid'
    }
    $mounts = @((Get-Property $service 'volumes'))
    Assert-Apply ($mounts.Count -eq 8) 'apply_mount_count_invalid'
    $declaredVolumes = Get-Property $config 'volumes'
    $expectedVolumes = @{
        '/var/www/html/piwigo' = @{ key = 'piwigo_data'; name = [string]$Spec.volumes.piwigo_data }
        '/var/www/html/piwigo/upload' = @{ key = 'piwigo_uploads'; name = [string]$Spec.volumes.piwigo_uploads }
        '/var/www/html/piwigo/galleries' = @{ key = 'piwigo_galleries'; name = [string]$Spec.volumes.piwigo_galleries }
        '/var/www/html/piwigo/_data/i' = @{ key = 'piwigo_derivatives'; name = [string]$Spec.volumes.piwigo_derivatives }
    }
    $expectedBinds = @{
        '/private-real-full/manifests/supplemental-import-manifest.json' = $ManifestWsl
        '/private-real-full/supplemental-staging' = $StagingWsl
    }
    $expectedCode = @{
        '/opt/class-archive/import-private-real-full.php' = $ProjectWsl.TrimEnd('/') + '/infra/scripts/import-private-real-full.php'
        '/opt/class-archive/verify-supplemental-target.php' = $ProjectWsl.TrimEnd('/') + '/infra/scripts/verify-private-real-supplemental-target.php'
    }
    foreach ($mount in $mounts) {
        $targetPath = [string](Get-Property $mount 'target')
        $source = [string](Get-Property $mount 'source')
        if ($expectedVolumes.ContainsKey($targetPath)) {
            $expected = $expectedVolumes[$targetPath]
            $declaration = Get-Property $declaredVolumes ([string]$expected.key)
            Assert-Apply ([string](Get-Property $mount 'type') -ceq 'volume' -and $source -ceq [string]$expected.key `
                -and [string](Get-Property $declaration 'name') -ceq [string]$expected.name `
                -and (Get-Property $mount 'read_only') -ne $true) 'apply_runtime_volume_invalid'
        }
        elseif ($expectedBinds.ContainsKey($targetPath)) {
            Assert-Apply ([string](Get-Property $mount 'type') -ceq 'bind' -and $source -ceq $expectedBinds[$targetPath] `
                -and (Get-Property $mount 'read_only') -eq $true `
                -and (Get-Property (Get-Property $mount 'bind') 'create_host_path') -eq $false) 'apply_ingress_mount_invalid'
        }
        elseif ($expectedCode.ContainsKey($targetPath)) {
            Assert-Apply ([string](Get-Property $mount 'type') -ceq 'bind' -and $source -ceq $expectedCode[$targetPath] `
                -and (Get-Property $mount 'read_only') -eq $true `
                -and (Get-Property (Get-Property $mount 'bind') 'create_host_path') -eq $false) 'apply_code_mount_invalid'
        }
        else { throw 'apply_unknown_mount' }
    }
    $serialized = $service | ConvertTo-Json -Depth 30 -Compress
    foreach ($forbidden in @('/private-real-full/staging', 'full-real-import-manifest.json', 'FULL_REAL_STAGING_PATH', '/mnt/m/', 'source_root', 'relative_source_path')) {
        Assert-Apply ($serialized.IndexOf($forbidden, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'apply_source_or_legacy_mount_detected'
    }
    return $networkName
}

function Assert-ContainerReady([hashtable]$Spec, [string]$Suffix) {
    $name = [string]$Spec.project + '-' + $Suffix + '-1'
    $lines = @(Invoke-WslCapture @('docker', 'inspect', '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $name) 'target_container_missing')
    Assert-Apply ($lines.Count -eq 1 -and $lines[0] -eq 'running|healthy') ('target_container_not_ready_' + $Suffix)
}

function Assert-PiwigoReadyOrMaintenance([hashtable]$Spec) {
    $name = [string]$Spec.project + '-piwigo-1'
    $lines = @(Invoke-WslCapture @('docker', 'inspect', '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $name) 'target_container_missing')
    Assert-Apply ($lines.Count -eq 1) 'target_container_not_ready_piwigo'
    if ($lines[0] -eq 'running|healthy') { return }
    Assert-Apply ($lines[0] -in @('running|starting', 'running|unhealthy')) 'target_container_not_ready_piwigo'
    $maintenance = @(Invoke-WslCapture @('docker', 'exec', $name, 'curl', '--silent', '--show-error',
        '--write-out', 'CLASS_ARCHIVE_STATUS:%{http_code}', 'http://127.0.0.1/') 'maintenance_resume_probe_failed')
    Assert-Apply ($maintenance.Count -eq 2 -and $maintenance[0] -eq 'Class Archive maintenance mode.' `
        -and $maintenance[1] -eq 'CLASS_ARCHIVE_STATUS:503') 'maintenance_resume_state_invalid'
}

function Wait-ContainerReady([hashtable]$Spec, [string]$Suffix) {
    foreach ($attempt in 1..60) {
        try {
            Assert-ContainerReady $Spec $Suffix
            return
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw ('target_container_ready_timeout_' + $Suffix)
}

function Wait-Maintenance([hashtable]$Spec, [string[]]$Prefix) {
    foreach ($attempt in 1..60) {
        try {
            $lines = Invoke-Compose $Prefix @('exec', '-T', 'piwigo', 'curl', '--silent', '--show-error', '--write-out', 'CLASS_ARCHIVE_STATUS:%{http_code}', 'http://127.0.0.1/') 'maintenance_probe_retry'
            if ($lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return }
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw 'maintenance_not_ready'
}

function Wait-PiwigoCli([string[]]$Prefix) {
    foreach ($attempt in 1..60) {
        try {
            $lines = Invoke-Compose $Prefix @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/tests/phase1/php-fpm-ready.php') 'piwigo_cli_retry'
            if (@($lines | Where-Object { $_ -match '^PHP_FPM_READY=PASS' }).Count -eq 1) { return }
        } catch { }
        Start-Sleep -Seconds 1
    }
    throw 'piwigo_cli_not_ready'
}

function Open-ApplyNetwork([hashtable]$Spec, [string[]]$Prefix, [string]$NetworkName) {
    $script:stage = 'isolated_apply_network'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        [void](& wsl.exe -d Ubuntu --exec docker network inspect $NetworkName 2>&1)
        $networkExists = $LASTEXITCODE -eq 0
    }
    finally { $ErrorActionPreference = $previous }
    Assert-Apply (-not $networkExists) 'apply_network_not_fresh'
    $script:networkCreated = $true
    [void](Invoke-Compose $Prefix @('create', '--no-build', '--no-recreate', 'supplemental-apply') 'apply_network_create_failed')
    [void](Invoke-Compose $Prefix @('rm', '--force', '--stop', 'supplemental-apply') 'apply_placeholder_remove_failed')
    $identity = @(Invoke-WslCapture @('docker', 'network', 'inspect', '--format',
        '{{.Internal}}|{{index .Labels "com.classarchive.scope"}}|{{len .Containers}}', $NetworkName) 'apply_network_inspect_failed')
    Assert-Apply ($identity.Count -eq 1 -and $identity[0] -eq 'true|private-real-supplemental-apply|0') 'apply_network_boundary_invalid'
    $dbContainer = [string]$Spec.project + '-db-1'
    [void](Invoke-WslCapture @('docker', 'network', 'connect', '--alias', 'db', $NetworkName, $dbContainer) 'apply_db_network_connect_failed')
    $script:dbNetworkConnected = $true
    $membership = @(Invoke-WslCapture @('docker', 'network', 'inspect', '--format', '{{len .Containers}}', $NetworkName) 'apply_network_membership_failed')
    Assert-Apply ($membership.Count -eq 1 -and $membership[0] -eq '1') 'apply_network_membership_invalid'
}

function Close-ApplyNetwork([hashtable]$Spec, [string]$NetworkName, [switch]$Strict) {
    if ($script:dbNetworkConnected) {
        $dbContainer = [string]$Spec.project + '-db-1'
        try {
            [void](Invoke-WslCapture @('docker', 'network', 'disconnect', '--force', $NetworkName, $dbContainer) 'apply_db_network_disconnect_failed')
            $script:dbNetworkConnected = $false
        }
        catch { if ($Strict.IsPresent) { throw } }
    }
    if ($script:networkCreated) {
        try {
            $membership = @(Invoke-WslCapture @('docker', 'network', 'inspect', '--format', '{{len .Containers}}', $NetworkName) 'apply_network_cleanup_inspect_failed')
            Assert-Apply ($membership.Count -eq 1 -and $membership[0] -eq '0') 'apply_network_cleanup_membership_invalid'
            [void](Invoke-WslCapture @('docker', 'network', 'rm', $NetworkName) 'apply_network_remove_failed')
            $script:networkCreated = $false
        }
        catch { if ($Strict.IsPresent) { throw } }
    }
}

$lock = $null
$piwigoStopped = $false
$spec = $null
$prefix = $null
$maintenanceNetwork = $null
$script:networkCreated = $false
$script:dbNetworkConnected = $false
try {
    $spec = $targets[$Target]
    Assert-Apply ($null -ne $spec) 'target_invalid'
    if ($Action -eq 'apply') {
        Assert-Apply $ConfirmSupplementalApply.IsPresent 'apply_confirmation_required'
        if ($Target -eq 'owner') { Assert-Apply $ConfirmOwnerRuntime.IsPresent 'owner_confirmation_required' }
    }
    Invoke-VerifiedArtifact
    $manifest = Assert-IgnoredPrivateLeaf $manifestPath 'manifest'
    [void](Assert-NoReparse $StagingPath 'staging_reparse')
    $stagingItem = Get-Item -LiteralPath $StagingPath -Force -ErrorAction Stop
    Assert-Apply ($stagingItem.PSIsContainer) 'staging_not_directory'
    $envPath = Join-Path $projectRoot ([string]$spec.env -replace '/', '\')
    $envFile = Assert-IgnoredPrivateLeaf $envPath 'target_env'
    $manifestWsl = Get-WslPath $manifest 'manifest_wsl_invalid'
    $stagingWsl = Get-WslPath $StagingPath 'staging_wsl_invalid'
    $projectWsl = Get-WslPath $projectRoot 'project_wsl_invalid'
    Assert-Apply (-not $manifestWsl.StartsWith('/mnt/m/', [StringComparison]::OrdinalIgnoreCase) `
        -and -not $stagingWsl.StartsWith('/mnt/m/', [StringComparison]::OrdinalIgnoreCase)) 'm_source_mount_forbidden'
    $prefix = Get-ComposePrefix $spec $manifestWsl $stagingWsl $projectWsl
    $maintenanceNetwork = Assert-ComposeModel $spec $prefix $manifestWsl $stagingWsl $projectWsl

    if ($Action -eq 'validate') {
        Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_APPLY=PASS action=validate target=' + $Target `
            + ' schema=REQUIRED_V16 sources=28 presentations=26 expected_applied=26 expected_deduplicated=2 ' `
            + ' ingress=READ_ONLY source_mount=NONE historical_manifest=NOT_MOUNTED docker=NOT_STARTED assertions=' + $script:assertions)
        exit 0
    }

    $script:stage = 'workflow_lock'
    $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
    $script:stage = 'runtime_preflight_db'
    Assert-ContainerReady $spec 'db'
    $script:stage = 'runtime_preflight_piwigo'
    Assert-PiwigoReadyOrMaintenance $spec
    $script:stage = 'runtime_preflight_schema'
    $schemaLines = Invoke-Compose $prefix @('exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL=1', '-e', 'CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_APPLY=1',
        'piwigo', 'php', '/workspace/infra/scripts/verify-private-real-supplemental-target.php', 'schema') 'schema_preflight_failed'
    $script:stage = 'runtime_preflight_schema_evidence'
    Assert-Apply (@($schemaLines | Where-Object { $_ -eq 'PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=schema schema=16 source_paths=NOT_READ' }).Count -eq 1) 'schema_preflight_result_invalid'

    $script:stage = 'maintenance_gate'
    [void](Invoke-Compose $prefix @('exec', '-T', '--user', 'root', 'piwigo', 'php', '/workspace/infra/scripts/prepare-class-archive-maintenance.php', '--prepare') 'maintenance_prepare_failed')
    Wait-Maintenance $spec $prefix
    [void](Invoke-Compose $prefix @('stop', 'piwigo') 'piwigo_stop_failed')
    $piwigoStopped = $true
    $state = @(Invoke-WslCapture @('docker', 'inspect', '--format', '{{.State.Status}}', ([string]$spec.project + '-piwigo-1')) 'piwigo_stop_verify_failed')
    Assert-Apply ($state.Count -eq 1 -and $state[0] -eq 'exited') 'piwigo_writer_not_stopped'
    Open-ApplyNetwork $spec $prefix $maintenanceNetwork

    $script:stage = 'one_shot_apply'
    $lines = Invoke-Compose $prefix @('run', '--rm', '--no-deps',
        '-e', 'CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_ACTION=apply',
        '-e', 'CLASS_ARCHIVE_PRIVATE_SUPPLEMENTAL_CONFIRM=APPLY_VERIFIED_28_SOURCE_26_PRESENTATION_BATCH',
        'supplemental-apply') 'supplemental_import_failed'
    $preflight = @($lines | Where-Object { $_ -match '^PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=preflight schema=16 sources=28 presentations=26 mode=(?:FRESH|RESUME|REPLAY) source_existing=[0-9]+ canonical_existing=[0-9]+ source_paths=NOT_PRESENT$' })
    $import = @($lines | Where-Object { $_ -match '^PRIVATE_FULL_LIBRARY_IMPORT=PASS imported=(?<imported>[0-9]+) deduplicated=(?<deduplicated>[0-9]+) skipped=(?<skipped>[0-9]+) failed=0 state=COMPLETED ai_jobs_queued=[0-9]+ ai_jobs_unchanged=[0-9]+ originals_mode=0660$' })
    $postflight = @($lines | Where-Object { $_ -eq 'PRIVATE_REAL_SUPPLEMENTAL_TARGET=PASS action=postflight schema=16 sources=28 presentations=26 applied=26 deduplicated=2 failed=0 idempotent=PASS source_paths=NOT_PRESENT' })
    Assert-Apply ($preflight.Count -eq 1 -and $import.Count -eq 1 -and $postflight.Count -eq 1) 'supplemental_import_evidence_invalid'
    $match = [regex]::Match([string]$import[0], '^PRIVATE_FULL_LIBRARY_IMPORT=PASS imported=(?<imported>[0-9]+) deduplicated=(?<deduplicated>[0-9]+) skipped=(?<skipped>[0-9]+)')
    $runImported = [int]$match.Groups['imported'].Value
    $runDeduplicated = [int]$match.Groups['deduplicated'].Value
    $runSkipped = [int]$match.Groups['skipped'].Value
    Assert-Apply ($runImported + $runDeduplicated + $runSkipped -eq 28) 'supplemental_run_count_invalid'
    Close-ApplyNetwork $spec $maintenanceNetwork -Strict

    $script:stage = 'normal_writer_restore'
    [void](Invoke-Compose $prefix @('up', '-d', '--no-deps', 'piwigo') 'piwigo_restart_failed')
    $piwigoStopped = $false
    Wait-PiwigoCli $prefix
    $script:stage = 'projection_rebuild'
    $projection = @(Invoke-Compose $prefix @('exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php', '--scope=all', '--json') 'projection_rebuild_failed')
    Assert-Apply (@($projection | Where-Object { $_ -match '^\{"result":"PASS",' }).Count -eq 1) 'projection_rebuild_evidence_invalid'
    $script:stage = 'maintenance_finalize'
    [void](Invoke-Compose $prefix @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance') 'maintenance_finalize_failed')
    Wait-ContainerReady $spec 'piwigo'

    Write-Output ('PRIVATE_REAL_SUPPLEMENTAL_APPLY=PASS action=apply target=' + $Target `
        + ' schema=16 sources=28 presentations=26 durable_applied=26 durable_deduplicated=2 failed=0 ' `
        + ' run_imported=' + $runImported + ' run_deduplicated=' + $runDeduplicated + ' run_skipped=' + $runSkipped `
        + ' idempotent=PASS ingress=READ_ONLY source_mount=NONE historical_manifest=NOT_MOUNTED assertions=' + $script:assertions)
}
catch {
    $messages = [Collections.Generic.List[string]]::new()
    $exception = $_.Exception
    while ($null -ne $exception) {
        [void]$messages.Add([string]$exception.Message)
        $exception = $exception.InnerException
    }
    $safe = @($messages | Where-Object { $_ -match '^[a-z0-9_]{1,96}$' } | Select-Object -First 1)
    $code = if ($safe.Count -eq 1) { [string]$safe[0] } else { 'supplemental_apply_failed' }
    Stop-SupplementalApply $code
}
finally {
    if ($script:networkCreated -and $null -ne $prefix) {
        try { [void](Invoke-Compose $prefix @('rm', '--force', '--stop', 'supplemental-apply') 'apply_placeholder_final_cleanup_failed') } catch { }
    }
    if ($null -ne $spec -and $null -ne $maintenanceNetwork) {
        Close-ApplyNetwork $spec $maintenanceNetwork
    }
    if ($piwigoStopped -and $null -ne $spec -and $null -ne $prefix) {
        try { [void](Invoke-Compose $prefix @('up', '-d', '--no-deps', 'piwigo') 'piwigo_fail_closed_restart_failed') } catch { }
        # Never finalize here. A failed apply leaves the durable maintenance
        # gate closed for explicit recovery and an idempotent retry.
    }
    Exit-ClassArchivePluginWorkflowLock -Handle $lock
}
