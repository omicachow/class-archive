[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'initialize', 'clone', 'start', 'recreate-piwigo', 'verify', 'status', 'cleanup')]
    [string]$Action = 'validate',

    [switch]$ConfirmPrivateRoleShadow,
    [switch]$ConfirmCleanup
)

# Disabled-by-default operator for the Phase 3.4.1 high-risk mutation Shadow.
# All Docker commands are routed to the Ubuntu WSL daemon.  Projects, ports,
# networks, volumes and the Owner source are fixed constants rather than
# caller-controlled input.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoProject = 'class_archive_private_role_shadow_v1_piwigo'
$immichProject = 'class_archive_private_role_shadow_v1_immich'
$scope = 'private-role-shadow'
$httpPort = 11990
$compatPort = 11991
$gatewayNetwork = 'class_archive_private_role_shadow_v1_gateway'
$gatewayBff = '10.180.0.10'
$runtimeRoot = Join-Path $projectRoot '.codex-work\private-role-e2e\shadow-v1'
$piwigoEnvPath = Join-Path $runtimeRoot 'piwigo.env'
$immichEnvPath = Join-Path $runtimeRoot 'immich.env'
$nginxPath = Join-Path $runtimeRoot 'nginx.conf'
$cloneMarker = Join-Path $runtimeRoot 'CLONE_COMPLETE'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/private-role-shadow/docker-compose.piwigo.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/private-role-shadow/docker-compose.immich.override.yml'
$cloneHelper = 'infra/scripts/clone-private-role-shadow.sh'
$script:stage = 'initialization'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-Shadow([string]$Code) {
    throw "PRIVATE_ROLE_SHADOW_STOP:$Code"
}

function Invoke-Wsl([string[]]$Arguments, [string]$Code = 'wsl_command_failed', [switch]$Capture) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --exec @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-Shadow $Code }
    if ($Capture) { return @($lines | ForEach-Object { [string]$_ }) }
}

function Invoke-Docker([string[]]$Arguments, [string]$Code = 'docker_command_failed', [switch]$Capture) {
    return Invoke-Wsl (@('docker') + $Arguments) $Code -Capture:$Capture
}

function Invoke-Compose([ValidateSet('piwigo', 'immich')][string]$Stack, [string[]]$Arguments, [switch]$Capture) {
    if (-not (Test-Path -LiteralPath $piwigoEnvPath -PathType Leaf) -or
        -not (Test-Path -LiteralPath $immichEnvPath -PathType Leaf)) {
        Stop-Shadow 'shadow_environment_not_initialized'
    }
    $piwigoEnvWsl = Get-WslPath $piwigoEnvPath
    $immichEnvWsl = Get-WslPath $immichEnvPath
    if ($Stack -eq 'piwigo') {
        $command = @('docker', 'compose', '--env-file', $piwigoEnvWsl,
            '-f', $piwigoCompose, '-f', $piwigoOverride, '-p', $piwigoProject, '--profile', 'ops') + $Arguments
    }
    else {
        $command = @('env', ('IMMICH_SPIKE_ENV_FILE=' + $immichEnvWsl), 'docker', 'compose', '--env-file', $immichEnvWsl,
            '-f', $immichCompose, '-f', $immichOverride, '-p', $immichProject,
            '--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-web-compat', '--profile', 'immich-gateway-integration') + $Arguments
    }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec @command 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-Shadow ('compose_' + $Stack + '_failed') }
    if ($Capture) { return @($lines | ForEach-Object { [string]$_ }) }
}

function Get-WslPath([string]$Path) {
    $raw = @(Invoke-Wsl @('wslpath', '-a', $Path) 'wsl_path_failed' -Capture)
    if ($raw.Count -ne 1 -or [string]$raw[0] -notmatch '^/mnt/[a-z]/') { Stop-Shadow 'wsl_path_invalid' }
    return ([string]$raw[0]).Trim()
}

function Get-PropertyValue([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Get-DockerInspectObject(
    [ValidateSet('container', 'network', 'volume')][string]$Kind,
    [string]$Id,
    [string]$Code
) {
    if ([string]::IsNullOrWhiteSpace($Id) -or $Code -notmatch '^[a-z0-9_]{1,96}$') { Stop-Shadow 'inspect_arguments_invalid' }
    $arguments = if ($Kind -eq 'container') { @('inspect', $Id) } else { @($Kind, 'inspect', $Id) }
    $raw = @(Invoke-Docker $arguments $Code -Capture)
    try {
        $document = [string]::Join("`n", $raw)
        $parsed = $document | ConvertFrom-Json -ErrorAction Stop
        $objects = @($parsed)
    }
    catch { Stop-Shadow ($Code + '_json_invalid') }
    finally {
        $parsed = $null
        $document = $null
        $raw = $null
    }
    if ($objects.Count -ne 1 -or $null -eq $objects[0]) { Stop-Shadow ($Code + '_count_invalid') }
    return $objects[0]
}

function Get-ShadowPiwigoRecoveryFingerprint([string]$Code) {
    $container = Get-DockerInspectObject container ($piwigoProject + '-piwigo-1') $Code
    $id = [string](Get-PropertyValue $container 'Id')
    if ($id -notmatch '^[a-f0-9]{64}$') { Stop-Shadow $Code }
    $mounts = @(Get-PropertyValue $container 'Mounts')
    $recovery = @($mounts | Where-Object {
        [string](Get-PropertyValue $_ 'Destination') -ceq '/var/lib/class-archive-private-e2e'
    })
    if ($recovery.Count -ne 1 -or
        [string](Get-PropertyValue $recovery[0] 'Name') -cne 'class_archive_private_role_shadow_v1_private_e2e_recovery') {
        Stop-Shadow $Code
    }
    return ($id + '|class_archive_private_role_shadow_v1_private_e2e_recovery')
}

function Get-RandomSecret([int]$Bytes = 48) {
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Assert-OptIn([switch]$Cleanup) {
    if ([string]$env:CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED -cne '1') { Stop-Shadow 'shadow_disabled_by_default' }
    if (-not $ConfirmPrivateRoleShadow.IsPresent) { Stop-Shadow 'explicit_shadow_confirmation_required' }
    if ($Cleanup -and -not $ConfirmCleanup.IsPresent) { Stop-Shadow 'explicit_cleanup_confirmation_required' }
}

function Assert-IgnoredRuntimeRoot {
    $relative = '.codex-work/private-role-e2e/shadow-v1'
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-Shadow 'runtime_root_not_ignored' }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) { Stop-Shadow 'runtime_root_tracked' }
    $cursor = [IO.Path]::GetFullPath($runtimeRoot)
    $boundary = [IO.Path]::GetFullPath($projectRoot).TrimEnd('\') + '\'
    if (-not $cursor.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) { Stop-Shadow 'runtime_root_outside_workspace' }
    while ($cursor.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $cursor)) {
        $item = Get-Item -LiteralPath $cursor -Force
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-Shadow 'runtime_root_reparse_forbidden' }
        $cursor = Split-Path -Parent $cursor
    }
}

function Write-OwnerOnlyText([string]$Path, [string]$Value) {
    $parent = Split-Path -Parent $Path
    [IO.Directory]::CreateDirectory($parent) | Out-Null
    $temporary = $Path + '.partial.' + [Guid]::NewGuid().ToString('N')
    [IO.File]::WriteAllText($temporary, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $temporary
    if (Test-Path -LiteralPath $Path) { Stop-Shadow 'immutable_runtime_file_exists' }
    Move-Item -LiteralPath $temporary -Destination $Path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Get-PinnedContainerImage([string]$Container) {
    $raw = @(Invoke-Docker @('inspect', '--format', '{{.Config.Image}}', $Container) 'owner_image_inspect_failed' -Capture)
    if ($raw.Count -ne 1) { Stop-Shadow 'owner_image_invalid' }
    $image = ([string]$raw[0]).Trim()
    if ($image -notmatch '@sha256:[a-f0-9]{64}$') { Stop-Shadow 'owner_image_not_digest_pinned' }
    return $image
}

function New-GeneratedNginxConfiguration {
    $sourcePath = Join-Path $projectRoot 'infra\piwigo-nginx\nginx.conf'
    $source = [IO.File]::ReadAllText($sourcePath)
    if ($source.Contains("$gatewayBff 1;") -or $source.Contains("set_real_ip_from $gatewayBff/32;")) {
        Stop-Shadow 'shadow_nginx_trust_already_in_source'
    }
    if (([regex]::Matches($source, '10\.241\.0\.10 1;')).Count -ne 1 -or
        ([regex]::Matches($source, 'set_real_ip_from 10\.241\.0\.10/32;')).Count -ne 1) {
        Stop-Shadow 'nginx_trust_anchor_invalid'
    }
    $generated = $source.Replace('        10.241.0.10 1;', "        10.241.0.10 1;`n        $gatewayBff 1;")
    $generated = $generated.Replace('        set_real_ip_from 10.241.0.10/32;', "        set_real_ip_from 10.241.0.10/32;`n        set_real_ip_from $gatewayBff/32;")
    if (([regex]::Matches($generated, [regex]::Escape("$gatewayBff 1;"))).Count -ne 1 -or
        ([regex]::Matches($generated, [regex]::Escape("set_real_ip_from $gatewayBff/32;"))).Count -ne 1) {
        Stop-Shadow 'shadow_nginx_generation_failed'
    }
    Write-OwnerOnlyText $nginxPath $generated
}

function New-ShadowEnvironment {
    Assert-IgnoredRuntimeRoot
    if (Test-Path -LiteralPath $runtimeRoot) {
        $existing = @(Get-ChildItem -LiteralPath $runtimeRoot -Force -ErrorAction Stop)
        if ($existing.Count -gt 0) { Stop-Shadow 'shadow_runtime_directory_not_empty' }
    }
    [IO.Directory]::CreateDirectory($runtimeRoot) | Out-Null
    $piwigoImage = Get-PinnedContainerImage 'class_archive_private_full_v3_piwigo-piwigo-1'
    $mariadbImage = Get-PinnedContainerImage 'class_archive_private_full_v3_piwigo-db-1'
    $piwigoPassword = Get-RandomSecret
    $piwigoRootPassword = Get-RandomSecret
    $immichPassword = Get-RandomSecret
    $claimPepper = Get-RandomSecret 64
    $anonymousSecret = Get-RandomSecret 64
    $nginxWsl = Get-WslPath $nginxPath
    $piwigo = @(
        "COMPOSE_PROJECT_NAME=$piwigoProject",
        "CLASS_ARCHIVE_HTTP_PORT=$httpPort",
        "CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compatPort",
        "CLASS_ARCHIVE_GATEWAY_NETWORK=$gatewayNetwork",
        "CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:$httpPort",
        'CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai',
        "CLASS_ARCHIVE_SHADOW_NGINX_CONFIG=$nginxWsl",
        'PIWIGO_UID=1000', 'PIWIGO_GID=1000',
        'PIWIGO_DATA_VOLUME=class_archive_private_role_shadow_v1_piwigo_data',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_role_shadow_v1_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_role_shadow_v1_piwigo_galleries',
        'PIWIGO_DERIVATIVES_VOLUME=class_archive_private_role_shadow_v1_piwigo_derivatives',
        'PIWIGO_DB_VOLUME=class_archive_private_role_shadow_v1_piwigo_db',
        'PIWIGO_SCRIPTS_VOLUME=class_archive_private_role_shadow_v1_piwigo_scripts',
        'PIWIGO_BACKUPS_VOLUME=class_archive_private_role_shadow_v1_piwigo_backups',
        "PIWIGO_IMAGE=$piwigoImage", "MARIADB_IMAGE=$mariadbImage",
        'DB_NAME=piwigo', 'DB_USER=piwigo', "DB_PASSWORD=$piwigoPassword", "DB_ROOT_PASSWORD=$piwigoRootPassword",
        'PIWIGO_ADMIN_USERNAME=shadow-disabled-admin', 'PIWIGO_ADMIN_EMAIL=shadow-admin@invalid.local',
        "CLASS_ARCHIVE_CLAIM_CODE_PEPPER=$claimPepper",
        "CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=$anonymousSecret",
        'SMTP_HOST=', 'SMTP_PORT=', 'SMTP_USERNAME=', 'SMTP_PASSWORD=', 'SMTP_ENCRYPTION='
    ) -join "`n"
    $immich = @(
        "IMMICH_COMPOSE_PROJECT_NAME=$immichProject",
        "CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compatPort", "CLASS_ARCHIVE_CORE_PUBLIC_PORT=$httpPort",
        "CLASS_ARCHIVE_GATEWAY_NETWORK=$gatewayNetwork",
        'IMMICH_UPLOAD_VOLUME=class_archive_private_role_shadow_v1_immich_upload',
        'IMMICH_MODEL_CACHE_VOLUME=class_archive_private_role_shadow_v1_immich_model_cache',
        'IMMICH_DB_VOLUME=class_archive_private_role_shadow_v1_immich_db',
        'IMMICH_GATEWAY_SECRET_VOLUME=class_archive_private_role_shadow_v1_immich_gateway_secret',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_role_shadow_v1_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_role_shadow_v1_piwigo_galleries',
        "DB_PASSWORD=$immichPassword", 'DB_USERNAME=postgres', 'DB_DATABASE_NAME=immich', 'TZ=Asia/Shanghai'
    ) -join "`n"
    Write-OwnerOnlyText $piwigoEnvPath ($piwigo + "`n")
    Write-OwnerOnlyText $immichEnvPath ($immich + "`n")
    New-GeneratedNginxConfiguration
}

function New-StaticEnvironment([string]$Root) {
    [IO.Directory]::CreateDirectory($Root) | Out-Null
    $nginx = Join-Path $Root 'nginx.conf'
    [IO.File]::WriteAllText($nginx, 'events{} http{}', [Text.UTF8Encoding]::new($false))
    $nginxWsl = Get-WslPath $nginx
    $piwigo = @(
        "COMPOSE_PROJECT_NAME=$piwigoProject", "CLASS_ARCHIVE_HTTP_PORT=$httpPort", "CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compatPort",
        "CLASS_ARCHIVE_GATEWAY_NETWORK=$gatewayNetwork", "CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:$httpPort",
        'CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai', "CLASS_ARCHIVE_SHADOW_NGINX_CONFIG=$nginxWsl",
        'PIWIGO_UID=1000', 'PIWIGO_GID=1000',
        'PIWIGO_DATA_VOLUME=class_archive_private_role_shadow_v1_piwigo_data',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_role_shadow_v1_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_role_shadow_v1_piwigo_galleries',
        'PIWIGO_DERIVATIVES_VOLUME=class_archive_private_role_shadow_v1_piwigo_derivatives',
        'PIWIGO_DB_VOLUME=class_archive_private_role_shadow_v1_piwigo_db',
        'PIWIGO_SCRIPTS_VOLUME=class_archive_private_role_shadow_v1_piwigo_scripts',
        'PIWIGO_BACKUPS_VOLUME=class_archive_private_role_shadow_v1_piwigo_backups',
        'PIWIGO_IMAGE=piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84',
        'MARIADB_IMAGE=mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf',
        'DB_NAME=piwigo', 'DB_USER=piwigo', 'DB_PASSWORD=STATIC_CONFIG_ONLY', 'DB_ROOT_PASSWORD=STATIC_CONFIG_ONLY_ROOT',
        'PIWIGO_ADMIN_USERNAME=static-shadow-admin', 'PIWIGO_ADMIN_EMAIL=shadow-admin@invalid.local',
        'CLASS_ARCHIVE_CLAIM_CODE_PEPPER=STATIC_CONFIG_ONLY_CLAIM',
        'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=STATIC_CONFIG_ONLY_ANONYMOUS',
        'SMTP_HOST=', 'SMTP_PORT=', 'SMTP_USERNAME=', 'SMTP_PASSWORD=', 'SMTP_ENCRYPTION='
    ) -join "`n"
    $immich = @(
        "IMMICH_COMPOSE_PROJECT_NAME=$immichProject", "CLASS_ARCHIVE_COMPAT_HTTP_PORT=$compatPort", "CLASS_ARCHIVE_CORE_PUBLIC_PORT=$httpPort",
        "CLASS_ARCHIVE_GATEWAY_NETWORK=$gatewayNetwork",
        'IMMICH_UPLOAD_VOLUME=class_archive_private_role_shadow_v1_immich_upload',
        'IMMICH_MODEL_CACHE_VOLUME=class_archive_private_role_shadow_v1_immich_model_cache',
        'IMMICH_DB_VOLUME=class_archive_private_role_shadow_v1_immich_db',
        'IMMICH_GATEWAY_SECRET_VOLUME=class_archive_private_role_shadow_v1_immich_gateway_secret',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_role_shadow_v1_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_role_shadow_v1_piwigo_galleries',
        'DB_PASSWORD=STATIC_CONFIG_ONLY_POSTGRES', 'DB_USERNAME=postgres', 'DB_DATABASE_NAME=immich', 'TZ=Asia/Shanghai'
    ) -join "`n"
    $p = Join-Path $Root 'piwigo.env'; $i = Join-Path $Root 'immich.env'
    [IO.File]::WriteAllText($p, $piwigo + "`n", [Text.UTF8Encoding]::new($false))
    [IO.File]::WriteAllText($i, $immich + "`n", [Text.UTF8Encoding]::new($false))
    return @{ piwigo = $p; immich = $i }
}

function Invoke-StaticComposeConfig([ValidateSet('piwigo', 'immich')][string]$Stack, [hashtable]$EnvPaths) {
    $p = Get-WslPath $EnvPaths.piwigo
    $i = Get-WslPath $EnvPaths.immich
    if ($Stack -eq 'piwigo') {
        $args = @('docker', 'compose', '--env-file', $p, '-f', $piwigoCompose, '-f', $piwigoOverride,
            '-p', $piwigoProject, '--profile', 'ops', 'config', '--format', 'json')
    }
    else {
        $args = @('env', ('IMMICH_SPIKE_ENV_FILE=' + $i), 'docker', 'compose', '--env-file', $i,
            '-f', $immichCompose, '-f', $immichOverride, '-p', $immichProject,
            '--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-web-compat', '--profile', 'immich-gateway-integration',
            'config', '--format', 'json')
    }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe -d Ubuntu --cd $projectRoot --exec @args 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-Shadow ('static_compose_' + $Stack + '_failed') }
    try { return ([string]::Join("`n", $lines) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-Shadow ('static_compose_' + $Stack + '_json_invalid') }
}

function Assert-StaticConfig {
    foreach ($path in @($piwigoOverride, $immichOverride, $cloneHelper)) {
        if (-not (Test-Path -LiteralPath (Join-Path $projectRoot ($path -replace '/', '\')) -PathType Leaf)) { Stop-Shadow 'shadow_source_missing' }
    }
    $sourceText = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\private-role-shadow\docker-compose.piwigo.override.yml')) +
        [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\private-role-shadow\docker-compose.immich.override.yml')) +
        [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\scripts\clone-private-role-shadow.sh'))
    foreach ($forbidden in @('0.0.0.0:', '/mnt/m/', '8490:', '8491:', '8190:', '8191:', '8290:', '8291:')) {
        if ($sourceText.Contains($forbidden)) { Stop-Shadow 'forbidden_boundary_in_shadow_source' }
    }
    $temp = Join-Path ([IO.Path]::GetTempPath()) ('class-archive-shadow-config-' + [Guid]::NewGuid().ToString('N'))
    try {
        $envPaths = New-StaticEnvironment $temp
        $piwigo = Invoke-StaticComposeConfig piwigo $envPaths
        $immich = Invoke-StaticComposeConfig immich $envPaths
        if ([string](Get-PropertyValue $piwigo 'name') -ne $piwigoProject -or [string](Get-PropertyValue $immich 'name') -ne $immichProject) {
            Stop-Shadow 'resolved_project_invalid'
        }
        $services = Get-PropertyValue $piwigo 'services'
        $piwigoService = Get-PropertyValue $services 'piwigo'
        $ports = @((Get-PropertyValue $piwigoService 'ports'))
        if ($ports.Count -ne 2) { Stop-Shadow 'resolved_port_count_invalid' }
        $actualPorts = @{}
        foreach ($port in $ports) {
            $hostIp = [string](Get-PropertyValue $port 'host_ip')
            $published = [string](Get-PropertyValue $port 'published')
            $target = [string](Get-PropertyValue $port 'target')
            if ($hostIp -ne '127.0.0.1' -or $published -notin @('11990', '11991')) { Stop-Shadow 'resolved_port_boundary_invalid' }
            $actualPorts[$published] = $target
        }
        if ($actualPorts['11990'] -ne '80' -or $actualPorts['11991'] -ne '8081') { Stop-Shadow 'resolved_port_mapping_invalid' }
        foreach ($service in $services.PSObject.Properties) {
            if ($service.Name -eq 'piwigo') { continue }
            $servicePorts = Get-PropertyValue $service.Value 'ports'
            if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) { Stop-Shadow 'unexpected_piwigo_host_port' }
        }
        foreach ($service in (Get-PropertyValue $immich 'services').PSObject.Properties) {
            $servicePorts = Get-PropertyValue $service.Value 'ports'
            if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) { Stop-Shadow 'immich_host_port_forbidden' }
        }
        foreach ($config in @($piwigo, $immich)) {
            foreach ($service in (Get-PropertyValue $config 'services').PSObject.Properties) {
                foreach ($mount in @((Get-PropertyValue $service.Value 'volumes'))) {
                    $source = [string](Get-PropertyValue $mount 'source')
                    if ($source -match '(?i)(^|[/\\])mnt[/\\]m([/\\]|$)|private-real-full[/\\]staging') {
                        Stop-Shadow 'private_source_mount_forbidden'
                    }
                }
            }
        }
        $env = Get-PropertyValue $piwigoService 'environment'
        if ([string](Get-PropertyValue $env 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED') -ne '1' -or
            [string](Get-PropertyValue $env 'CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW') -ne '1') { Stop-Shadow 'shadow_gate_not_in_container' }
        $networks = Get-PropertyValue $piwigo 'networks'
        if ([string](Get-PropertyValue (Get-PropertyValue $networks 'app') 'name') -ne 'class_archive_private_role_shadow_v1_app' -or
            (Get-PropertyValue (Get-PropertyValue $networks 'app') 'internal') -eq $true -or
            [string](Get-PropertyValue (Get-PropertyValue $networks 'immich_gateway') 'name') -ne $gatewayNetwork -or
            (Get-PropertyValue (Get-PropertyValue $networks 'immich_gateway') 'internal') -ne $true) { Stop-Shadow 'resolved_piwigo_network_invalid' }
        foreach ($logical in @('immich_internal', 'immich_ml_internal', 'immich_bridge_internal')) {
            $network = Get-PropertyValue (Get-PropertyValue $immich 'networks') $logical
            if ((Get-PropertyValue $network 'internal') -ne $true) { Stop-Shadow 'resolved_immich_network_not_internal' }
        }
        foreach ($config in @($piwigo, $immich)) {
            foreach ($volume in (Get-PropertyValue $config 'volumes').PSObject.Properties) {
                $name = [string](Get-PropertyValue $volume.Value 'name')
                if ($name -notmatch '^class_archive_private_role_shadow_v1_' -or
                    ([string](Get-PropertyValue $volume.Value 'driver') -notin @('', 'local'))) { Stop-Shadow 'resolved_volume_boundary_invalid' }
            }
        }
    }
    finally {
        if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp -Recurse -Force }
    }
}

function Assert-PortsFree {
    foreach ($port in @($httpPort, $compatPort)) {
        $listener = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
        if ($listener.Count -gt 0) { Stop-Shadow ('shadow_port_in_use_' + $port) }
    }
}

function Convert-CidrToRange([string]$Cidr) {
    $parts = $Cidr -split '/'; if ($parts.Count -ne 2) { Stop-Shadow 'cidr_invalid' }
    if ($parts[0] -notmatch '^(?:\d{1,3}\.){3}\d{1,3}$' -or $parts[1] -notmatch '^(?:[0-9]|[12][0-9]|3[0-2])$') {
        Stop-Shadow 'cidr_invalid'
    }
    $address = $null
    if (-not [Net.IPAddress]::TryParse($parts[0], [ref]$address) -or
        $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork) { Stop-Shadow 'cidr_invalid' }
    $bytes = $address.GetAddressBytes(); [Array]::Reverse($bytes)
    $ip = [uint64][BitConverter]::ToUInt32($bytes, 0); $prefix = [int]$parts[1]
    $hostBits = 32 - $prefix
    $hostMask = if ($hostBits -eq 32) { [uint64][uint32]::MaxValue }
        elseif ($hostBits -eq 0) { [uint64]0 }
        else { ([uint64]1 -shl $hostBits) - 1 }
    $mask = [uint64][uint32]::MaxValue - $hostMask
    $first = $ip -band $mask; $last = $first + $hostMask
    return @([uint64]$first, [uint64]$last)
}

function Assert-NetworkRangesFree {
    $reserved = @('10.180.0.0/24', '10.180.1.0/24', '10.180.2.0/24', '10.180.3.0/24', '10.180.4.0/24')
    $ids = @(Invoke-Docker @('network', 'ls', '-q') 'network_list_failed' -Capture | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    foreach ($id in $ids) {
        $network = Get-DockerInspectObject network ([string]$id).Trim() 'network_inspect_failed'
        $name = [string](Get-PropertyValue $network 'Name')
        if ([string]::IsNullOrWhiteSpace($name)) { Stop-Shadow 'network_identity_invalid' }
        if ($name -like 'class_archive_private_role_shadow_v1_*') {
            $labels = Get-PropertyValue $network 'Labels'
            if ([string](Get-PropertyValue $labels 'com.classarchive.scope') -ne 'private-role-shadow' -or
                [string](Get-PropertyValue $labels 'com.classarchive.shadow-version') -ne '1') { Stop-Shadow 'shadow_network_prefix_unlabelled' }
            continue
        }
        $ipam = Get-PropertyValue $network 'IPAM'
        foreach ($configuration in @((Get-PropertyValue $ipam 'Config'))) {
            $existing = [string](Get-PropertyValue $configuration 'Subnet')
            if ([string]::IsNullOrWhiteSpace($existing) -or $existing -notmatch '^\d+\.\d+\.\d+\.\d+/\d+$') { continue }
            $left = Convert-CidrToRange $existing.Trim()
            foreach ($candidate in $reserved) {
                $right = Convert-CidrToRange $candidate
                if ($left[0] -le $right[1] -and $right[0] -le $left[1]) { Stop-Shadow 'shadow_network_range_in_use' }
            }
        }
    }
}

function Get-ProtectedRuntimeFingerprint {
    $ids = @(Invoke-Docker @('ps', '-aq', '--no-trunc') 'container_list_failed' -Capture |
        ForEach-Object { ([string]$_).Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    $requested = @{}
    foreach ($id in $ids) {
        if ($id -notmatch '^[a-f0-9]{64}$' -or $requested.ContainsKey($id)) { Stop-Shadow 'container_list_invalid' }
        $requested[$id] = $true
    }
    if ($ids.Count -eq 0) { return '' }

    # Windows PowerShell 5.1 strips the quotes from nested Go-template map
    # indexes passed to native commands.  Inspect once as JSON instead: this
    # avoids that parser boundary and sharply reduces the interval in which an
    # unrelated short-lived container can disappear between list and inspect.
    $raw = @()
    foreach ($attempt in 1..2) {
        try {
            $raw = @(Invoke-Docker (@('inspect') + $ids) 'container_fingerprint_failed' -Capture)
            break
        }
        catch {
            if ($attempt -eq 2) { throw }
            Start-Sleep -Milliseconds 200
        }
    }
    try {
        $document = [string]::Join("`n", $raw)
        # Windows PowerShell 5.1 preserves a JSON top-level array as one
        # pipeline object when ConvertFrom-Json is nested directly in @(...).
        # Assign first so the following array expression enumerates its items.
        $parsed = $document | ConvertFrom-Json -ErrorAction Stop
        $containers = @($parsed)
    }
    catch { Stop-Shadow 'container_fingerprint_json_invalid' }
    finally {
        $parsed = $null
        $document = $null
        $raw = $null
    }
    if ($containers.Count -ne $ids.Count) { Stop-Shadow 'container_fingerprint_count_invalid' }

    $records = @()
    $seen = @{}
    foreach ($container in $containers) {
        $id = [string](Get-PropertyValue $container 'Id')
        if ($id -notmatch '^[a-f0-9]{64}$' -or -not $requested.ContainsKey($id) -or $seen.ContainsKey($id)) {
            Stop-Shadow 'container_fingerprint_id_set_invalid'
        }
        $seen[$id] = $true

        $config = Get-PropertyValue $container 'Config'
        $state = Get-PropertyValue $container 'State'
        $runningProperty = if ($null -eq $state) { $null } else { $state.PSObject.Properties['Running'] }
        $startedProperty = if ($null -eq $state) { $null } else { $state.PSObject.Properties['StartedAt'] }
        if ($null -eq $config -or $null -eq $runningProperty -or $runningProperty.Value -isnot [bool] -or
            $null -eq $startedProperty -or $null -eq $startedProperty.Value) {
            Stop-Shadow 'container_fingerprint_state_invalid'
        }
        $containerScope = [string](Get-PropertyValue (Get-PropertyValue $config 'Labels') 'com.classarchive.scope')
        if ([string]::Equals($containerScope, $scope, [StringComparison]::Ordinal)) { continue }

        $mounts = @()
        foreach ($mount in @((Get-PropertyValue $container 'Mounts'))) {
            $rwProperty = if ($null -eq $mount) { $null } else { $mount.PSObject.Properties['RW'] }
            $type = [string](Get-PropertyValue $mount 'Type')
            $name = [string](Get-PropertyValue $mount 'Name')
            $source = [string](Get-PropertyValue $mount 'Source')
            $destination = [string](Get-PropertyValue $mount 'Destination')
            if ([string]::IsNullOrWhiteSpace($type) -or [string]::IsNullOrWhiteSpace($source) -or
                [string]::IsNullOrWhiteSpace($destination) -or $null -eq $rwProperty -or $rwProperty.Value -isnot [bool]) {
                Stop-Shadow 'container_fingerprint_mount_invalid'
            }
            $rw = if ([bool]$rwProperty.Value) { 'true' } else { 'false' }
            $mounts += ($type + '|' + $name + '|' + $source + '|' + $destination + '|' + $rw)
        }

        $networks = @()
        $networkSettings = Get-PropertyValue $container 'NetworkSettings'
        $networkMap = Get-PropertyValue $networkSettings 'Networks'
        if ($null -ne $networkMap) {
            foreach ($property in $networkMap.PSObject.Properties) {
                $network = $property.Value
                $networkIdProperty = if ($null -eq $network) { $null } else { $network.PSObject.Properties['NetworkID'] }
                $ipProperty = if ($null -eq $network) { $null } else { $network.PSObject.Properties['IPAddress'] }
                if ([string]::IsNullOrWhiteSpace([string]$property.Name) -or $null -eq $networkIdProperty -or
                    $null -eq $networkIdProperty.Value -or $null -eq $ipProperty -or $null -eq $ipProperty.Value) {
                    Stop-Shadow 'container_fingerprint_network_invalid'
                }
                $networks += ([string]$property.Name + '|' + [string]$networkIdProperty.Value + '|' + [string]$ipProperty.Value)
            }
        }

        $running = if ([bool]$runningProperty.Value) { 'true' } else { 'false' }
        $identity = $containerScope + '|' + $id + '|' + $running + '|' + [string]$startedProperty.Value
        $records += ($identity + '|mounts=' + (($mounts | Sort-Object) -join ';') + '|networks=' + (($networks | Sort-Object) -join ';'))
    }
    if ($seen.Count -ne $requested.Count) { Stop-Shadow 'container_fingerprint_id_set_invalid' }
    return (($records | Sort-Object) -join "`n")
}

function Assert-ProtectedFingerprint([string]$Before) {
    $after = Get-ProtectedRuntimeFingerprint
    if (-not [string]::Equals($Before, $after, [StringComparison]::Ordinal)) { Stop-Shadow 'protected_runtime_changed' }
}

function New-ShadowVolume([string]$Name, [string]$Project, [string]$Logical) {
    $existing = @(Invoke-Docker @('volume', 'ls', '-q', '--filter', ('name=^' + [regex]::Escape($Name) + '$')) 'volume_list_failed' -Capture)
    if ($existing.Count -eq 0) {
        [void](Invoke-Docker @('volume', 'create', '--driver', 'local',
            '--label', ('com.docker.compose.project=' + $Project), '--label', ('com.docker.compose.volume=' + $Logical),
            '--label', ('com.classarchive.scope=' + $scope), '--label', 'com.classarchive.shadow-version=1', $Name) 'volume_create_failed')
    }
    $volume = Get-DockerInspectObject volume $Name 'volume_identity_failed'
    $labels = Get-PropertyValue $volume 'Labels'
    if ([string](Get-PropertyValue $volume 'Name') -cne $Name -or [string](Get-PropertyValue $volume 'Driver') -cne 'local' -or
        [string](Get-PropertyValue $labels 'com.docker.compose.project') -cne $Project -or
        [string](Get-PropertyValue $labels 'com.docker.compose.volume') -cne $Logical -or
        [string](Get-PropertyValue $labels 'com.classarchive.scope') -cne $scope -or
        [string](Get-PropertyValue $labels 'com.classarchive.shadow-version') -cne '1') { Stop-Shadow 'volume_identity_invalid' }
}

function Initialize-ShadowVolumes {
    foreach ($spec in @(
        @('class_archive_private_role_shadow_v1_piwigo_data', $piwigoProject, 'piwigo_data'),
        @('class_archive_private_role_shadow_v1_piwigo_uploads', $piwigoProject, 'piwigo_uploads'),
        @('class_archive_private_role_shadow_v1_piwigo_galleries', $piwigoProject, 'piwigo_galleries'),
        @('class_archive_private_role_shadow_v1_piwigo_derivatives', $piwigoProject, 'piwigo_derivatives'),
        @('class_archive_private_role_shadow_v1_piwigo_db', $piwigoProject, 'piwigo_db'),
        @('class_archive_private_role_shadow_v1_piwigo_scripts', $piwigoProject, 'piwigo_scripts'),
        @('class_archive_private_role_shadow_v1_piwigo_backups', $piwigoProject, 'backups'),
        @('class_archive_private_role_shadow_v1_private_e2e_recovery', $piwigoProject, 'private_e2e_recovery'),
        @('class_archive_private_role_shadow_v1_immich_upload', $immichProject, 'immich_upload'),
        @('class_archive_private_role_shadow_v1_immich_model_cache', $immichProject, 'immich_model_cache'),
        @('class_archive_private_role_shadow_v1_immich_db', $immichProject, 'immich_db'),
        @('class_archive_private_role_shadow_v1_immich_gateway_secret', $immichProject, 'immich_gateway_secret')
    )) { New-ShadowVolume $spec[0] $spec[1] $spec[2] }
}

function Wait-ContainerHealthy([string]$Name, [int]$Seconds = 180) {
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    while ([DateTime]::UtcNow -lt $deadline) {
        $raw = @()
        try { $raw = @(Invoke-Docker @('inspect', '--format', '{{.State.Running}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $Name) 'container_wait_probe_failed' -Capture) } catch { }
        if ($raw.Count -eq 1 -and ([string]$raw[0]).Trim() -match '^true\|(healthy|none)$') { return }
        Start-Sleep -Seconds 2
    }
    Stop-Shadow 'container_health_timeout'
}

function Invoke-CloneHelper([string]$HelperAction) {
    $env:CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_CONFIRM = 'CLONE_V18_OWNER_TO_ISOLATED_SHADOW'
    try {
        $lines = @(Invoke-Wsl @('env', 'CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_ENABLED=1',
            'CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_CONFIRM=CLONE_V18_OWNER_TO_ISOLATED_SHADOW',
            'bash', (Get-WslPath (Join-Path $projectRoot ($cloneHelper -replace '/', '\'))), $HelperAction) 'shadow_clone_helper_failed' -Capture)
        return $lines
    }
    finally { Remove-Item Env:CLASS_ARCHIVE_PRIVATE_ROLE_SHADOW_CONFIRM -ErrorAction SilentlyContinue }
}

function Assert-ExactCleanupScope([ValidateSet('container', 'network', 'volume')][string]$Kind, [string]$Id) {
    $code = 'cleanup_' + $Kind + '_inspect_failed'
    $resource = Get-DockerInspectObject $Kind $Id $code
    $labels = if ($Kind -eq 'container') { Get-PropertyValue (Get-PropertyValue $resource 'Config') 'Labels' }
        else { Get-PropertyValue $resource 'Labels' }
    $resourceName = [string](Get-PropertyValue $resource 'Name')
    if ([string](Get-PropertyValue $labels 'com.classarchive.scope') -cne 'private-role-shadow' -or
        [string](Get-PropertyValue $labels 'com.classarchive.shadow-version') -cne '1' -or
        $resourceName -notmatch '^/?class_archive_private_role_shadow_v1_[A-Za-z0-9_.-]+$') {
        Stop-Shadow ('cleanup_' + $Kind + '_scope_invalid')
    }
}

function Get-PrefixResourceIds([ValidateSet('container', 'network', 'volume')][string]$Kind) {
    $prefix = 'class_archive_private_role_shadow_v1_'
    if ($Kind -eq 'container') {
        $lines = @(Invoke-Docker @('ps', '-a', '--format', '{{.ID}}|{{.Names}}') 'prefix_container_list_failed' -Capture)
    }
    elseif ($Kind -eq 'network') {
        $lines = @(Invoke-Docker @('network', 'ls', '--format', '{{.ID}}|{{.Name}}') 'prefix_network_list_failed' -Capture)
    }
    else {
        $lines = @(Invoke-Docker @('volume', 'ls', '--format', '{{.Name}}|{{.Name}}') 'prefix_volume_list_failed' -Capture)
    }
    $ids = @()
    foreach ($line in $lines) {
        $parts = ([string]$line).Trim() -split '\|', 2
        if ($parts.Count -eq 2 -and $parts[1].StartsWith($prefix, [StringComparison]::Ordinal)) { $ids += $parts[0] }
    }
    return @($ids | Sort-Object -Unique)
}

function Assert-NoShadowResources {
    foreach ($kind in @('container', 'network', 'volume')) {
        $labelArgs = if ($kind -eq 'volume') { @('volume', 'ls', '-q', '--filter', ('label=com.classarchive.scope=' + $scope)) }
            else { @($kind, 'ls', '-q', '--filter', ('label=com.classarchive.scope=' + $scope)) }
        $labelled = @(Invoke-Docker $labelArgs ('shadow_' + $kind + '_label_probe_failed') -Capture | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
        $prefixed = @(Get-PrefixResourceIds $kind)
        if ($labelled.Count -gt 0 -or $prefixed.Count -gt 0) { Stop-Shadow 'shadow_runtime_already_exists' }
    }
}

function Remove-ExactShadowResources {
    foreach ($kind in @('container', 'network', 'volume')) {
        $args = if ($kind -eq 'volume') { @('volume', 'ls', '-q', '--filter', ('label=com.classarchive.scope=' + $scope), '--filter', 'label=com.classarchive.shadow-version=1') }
            else { @($kind, 'ls', '-q', '--filter', ('label=com.classarchive.scope=' + $scope), '--filter', 'label=com.classarchive.shadow-version=1') }
        $labelIds = @(Invoke-Docker $args ('cleanup_' + $kind + '_list_failed') -Capture | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
        $prefixIds = @(Get-PrefixResourceIds $kind)
        $ids = @(($labelIds + $prefixIds) | Sort-Object -Unique)
        foreach ($id in $ids) { Assert-ExactCleanupScope $kind $id }
        if ($ids.Count -eq 0) { continue }
        if ($kind -eq 'container') { [void](Invoke-Docker (@('rm', '-f') + $ids) 'cleanup_container_remove_failed') }
        elseif ($kind -eq 'network') { [void](Invoke-Docker (@('network', 'rm') + $ids) 'cleanup_network_remove_failed') }
        else { [void](Invoke-Docker (@('volume', 'rm') + $ids) 'cleanup_volume_remove_failed') }
    }
}

try {
    Assert-StaticConfig
    if ($Action -eq 'validate') {
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=validate evidence=STATIC_COMPOSE_CONFIG protocol=DISABLED_BY_DEFAULT ports=11990_11991 media=EMPTY_INDEPENDENT_FIXTURE_ONLY owner_mutation=NONE'
        exit 0
    }

    if ($Action -eq 'status') {
        $containers = @(Invoke-Docker @('ps', '-a', '--filter', ('label=com.classarchive.scope=' + $scope), '--format', '{{.Names}}|{{.State}}') 'shadow_status_failed' -Capture)
        $state = if ($containers.Count -eq 0) { 'ABSENT' } else { 'PRESENT' }
        Write-Output "PRIVATE_ROLE_SHADOW=PASS action=status runtime=$state containers=$($containers.Count) ports=11990_11991"
        exit 0
    }

    Assert-OptIn -Cleanup:($Action -eq 'cleanup')
    Assert-IgnoredRuntimeRoot
    $before = Get-ProtectedRuntimeFingerprint

    if ($Action -eq 'initialize') {
        Assert-PortsFree
        Assert-NetworkRangesFree
        Assert-NoShadowResources
        New-ShadowEnvironment
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=initialize secrets=OWNER_ONLY_IGNORED runtime=NOT_STARTED owner_mutation=NONE'
        exit 0
    }

    if (-not (Test-Path -LiteralPath $piwigoEnvPath -PathType Leaf) -or -not (Test-Path -LiteralPath $immichEnvPath -PathType Leaf) -or
        -not (Test-Path -LiteralPath $nginxPath -PathType Leaf)) { Stop-Shadow 'shadow_environment_not_initialized' }
    foreach ($file in @($piwigoEnvPath, $immichEnvPath, $nginxPath)) { Assert-ClassArchiveOwnerOnlyFileAcl -Path $file }

    if ($Action -eq 'clone') {
        Assert-PortsFree
        Assert-NetworkRangesFree
        Initialize-ShadowVolumes
        [void](Invoke-Compose piwigo @('up', '-d', 'db'))
        [void](Invoke-Compose immich @('up', '-d', 'database'))
        Wait-ContainerHealthy ($piwigoProject + '-db-1')
        Wait-ContainerHealthy ($immichProject + '-database-1')
        $result = @(Invoke-CloneHelper 'clone')
        if ($result -notcontains 'PRIVATE_ROLE_SHADOW_CLONE=PASS schema=18 mariadb=LOGICAL_LOCK_ALL_TABLES postgres=CUSTOM_LOGICAL control_volumes=VERIFIED media=EMPTY_INDEPENDENT_FIXTURE_ONLY source_mutation=NONE') {
            Stop-Shadow 'clone_evidence_missing'
        }
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=clone schema=18 databases=CONSISTENT_LOGICAL control=VERIFIED media=EMPTY_INDEPENDENT_FIXTURE_ONLY protected_runtimes=UNCHANGED'
        exit 0
    }

    if (-not (Test-Path -LiteralPath $cloneMarker -PathType Leaf)) { Stop-Shadow 'clone_complete_required' }

    if ($Action -eq 'start') {
        Assert-PortsFree
        Assert-NetworkRangesFree
        [void](Invoke-CloneHelper 'verify')
        [void](Invoke-Compose piwigo @('up', '-d', '--force-recreate', 'db', 'piwigo'))
        Wait-ContainerHealthy ($piwigoProject + '-piwigo-1') 300
        [void](Invoke-Compose immich @('up', '-d', '--force-recreate', 'immich-web-compat'))
        Wait-ContainerHealthy ($immichProject + '-immich-web-compat-1') 300
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=start endpoint=11990_11991 mode=CONTROL_PLANE_MUTATION_SHADOW ai_bridge=DISABLED media=FIXTURE_ONLY protected_runtimes=UNCHANGED'
        exit 0
    }

    if ($Action -eq 'recreate-piwigo') {
        $old = Get-ShadowPiwigoRecoveryFingerprint 'shadow_recreate_preflight_failed'
        $oldId = ($old -split '\|', 2)[0]
        [void](Invoke-Compose piwigo @('up', '-d', '--force-recreate', '--no-deps', 'piwigo'))
        Wait-ContainerHealthy ($piwigoProject + '-piwigo-1') 300
        $new = Get-ShadowPiwigoRecoveryFingerprint 'shadow_recreate_postcheck_failed'
        $newId = ($new -split '\|', 2)[0]
        if ($newId -eq $oldId) { Stop-Shadow 'piwigo_container_not_recreated' }
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=recreate-piwigo container=RECREATED recovery_plan_volume=PRESERVED protected_runtimes=UNCHANGED'
        exit 0
    }

    if ($Action -eq 'verify') {
        [void](Invoke-CloneHelper 'verify')
        $piwigoInspect = Get-DockerInspectObject container ($piwigoProject + '-piwigo-1') 'shadow_piwigo_verify_failed'
        $piwigoLabels = Get-PropertyValue (Get-PropertyValue $piwigoInspect 'Config') 'Labels'
        $portBindings = Get-PropertyValue (Get-PropertyValue $piwigoInspect 'HostConfig') 'PortBindings'
        if ([string](Get-PropertyValue $piwigoLabels 'com.classarchive.scope') -cne 'private-role-shadow' -or
            $null -eq $portBindings -or @($portBindings.PSObject.Properties).Count -ne 2) { Stop-Shadow 'shadow_piwigo_exposure_invalid' }
        foreach ($port in @(@('80/tcp', '11990'), @('8081/tcp', '11991'))) {
            $binding = @((Get-PropertyValue $portBindings $port[0]))
            if ($binding.Count -ne 1 -or [string](Get-PropertyValue $binding[0] 'HostIp') -cne '127.0.0.1' -or
                [string](Get-PropertyValue $binding[0] 'HostPort') -cne $port[1]) { Stop-Shadow 'shadow_piwigo_exposure_invalid' }
        }
        foreach ($name in @(($piwigoProject + '-db-1'), ($immichProject + '-database-1'), ($immichProject + '-immich-web-compat-1'))) {
            $ports = @(Invoke-Docker @('inspect', '--format', '{{json .HostConfig.PortBindings}}', $name) 'shadow_port_verify_failed' -Capture)
            if ($name -ne ($immichProject + '-immich-web-compat-1') -and $ports.Count -eq 1 -and ([string]$ports[0]).Trim() -notin @('null', '{}')) {
                Stop-Shadow 'shadow_internal_service_exposed'
            }
        }
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=verify ports=LOOPBACK_ONLY databases=INDEPENDENT sessions=INDEPENDENT recovery=RECREATE_PERSISTENT owner_mutation=NONE'
        exit 0
    }

    if ($Action -eq 'cleanup') {
        Remove-ExactShadowResources
        Assert-ProtectedFingerprint $before
        Write-Output 'PRIVATE_ROLE_SHADOW=PASS action=cleanup selector=EXACT_LABEL_AND_PREFIX protected_runtimes=UNCHANGED evidence_files=PRESERVED'
        exit 0
    }

    Stop-Shadow 'action_unhandled'
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^PRIVATE_ROLE_SHADOW_STOP:([a-z0-9_]{1,128})$') { [string]$Matches[1] } else { 'unexpected_failure' }
    Write-Output "PRIVATE_ROLE_SHADOW=FAIL action=$Action stage=$script:stage code=$code"
    exit 2
}
