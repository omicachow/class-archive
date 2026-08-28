[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'config', 'ps', 'up', 'stop')]
    [string]$Action = 'validate',

    [string]$PiwigoEnvPath,
    [string]$ImmichEnvPath
)

# Private real-data QA runtime boundary. This runner accepts only ignored,
# owner-only env files and an ignored staging copy below .codex-work. It never
# accepts or mounts an original source directory, and exposes no volume-delete
# or caller-supplied Docker arguments.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-qa'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/private-qa/docker-compose.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/private-qa/docker-compose.immich.override.yml'
$piwigoProject = 'class_archive_private_qa_piwigo'
$immichProject = 'class_archive_private_qa_immich'
$gatewayNetwork = 'class_archive_private_qa_gateway'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$script:stage = 'startup'

if ([string]::IsNullOrWhiteSpace($PiwigoEnvPath)) {
    $PiwigoEnvPath = Join-Path $projectRoot 'infra\private-qa\.env.piwigo'
}
if ([string]::IsNullOrWhiteSpace($ImmichEnvPath)) {
    $ImmichEnvPath = Join-Path $projectRoot 'infra\private-qa\.env.immich'
}

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-PrivateQa([string]$Code) {
    Write-Output "PRIVATE_QA=FAIL code=$Code"
    exit 2
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateQa 'config_must_be_inside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredUntrackedFile([string]$Path, [string]$Label) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-PrivateQa ($Label + '_path_untrusted')
    }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateQa ($Label + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-PrivateQa ($Label + '_git_check_failed') }
    if ($tracked.Count -gt 0) { Stop-PrivateQa ($Label + '_is_tracked') }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
    return $item.FullName
}

function Read-StrictEnvironment([string]$Path, [string[]]$Allowed, [string]$Label) {
    $bytes = [IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -lt 16 -or $bytes.Length -gt 65536) {
        Stop-PrivateQa ($Label + '_size_invalid')
    }
    try {
        $text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes)
    } catch {
        Stop-PrivateQa ($Label + '_encoding_invalid')
    }
    if ($text.Contains("`0")) { Stop-PrivateQa ($Label + '_encoding_invalid') }
    $allowedSet = @{}
    foreach ($name in $Allowed) { $allowedSet[$name] = $true }
    $values = @{}
    foreach ($line in ($text -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') {
            Stop-PrivateQa ($Label + '_line_invalid')
        }
        $name = [string]$Matches[1]
        $value = [string]$Matches[2]
        if ($name -match '(?:SOURCE|NAS|ORIGINAL|REAL_MEDIA|HOST_MOUNT)') {
            Stop-PrivateQa 'source_configuration_forbidden'
        }
        if (-not $allowedSet.ContainsKey($name) -or $values.ContainsKey($name)) {
            Stop-PrivateQa ($Label + '_key_invalid')
        }
        if ($value.Contains("`r") -or $value.Contains("`n") -or $value.Contains("`0")) {
            Stop-PrivateQa ($Label + '_value_invalid')
        }
        $values[$name] = $value
    }
    return $values
}

function Require-Value([hashtable]$Values, [string]$Name, [string]$Label) {
    if (-not $Values.ContainsKey($Name) -or [string]::IsNullOrWhiteSpace([string]$Values[$Name])) {
        Stop-PrivateQa ($Label + '_required_value_missing')
    }
    return [string]$Values[$Name]
}

function Assert-ExactValue([hashtable]$Values, [string]$Name, [string]$Expected, [string]$Code) {
    $value = Require-Value $Values $Name $Code
    if (-not [string]::Equals($value, $Expected, [StringComparison]::Ordinal)) {
        Stop-PrivateQa $Code
    }
}

function Assert-Secret([hashtable]$Values, [string]$Name, [string]$Code) {
    $value = Require-Value $Values $Name $Code
    if ($value.Length -lt 32 -or $value.Length -gt 190 -or $value -notmatch '^[A-Za-z0-9_-]+$' -or $value -match '^__.*__$') {
        Stop-PrivateQa $Code
    }
}

function Assert-Volume([hashtable]$Values, [string]$Name, [string]$Expected) {
    Assert-ExactValue $Values $Name $Expected 'private_volume_identity_invalid'
    $value = [string]$Values[$Name]
    if (
        $value -notmatch '^class_archive_private_qa_[a-z0-9_]+$' -or
        $value -match '^class_archive_(?:piwigo|immich_spike|immich_gateway)'
    ) {
        Stop-PrivateQa 'private_volume_identity_invalid'
    }
}

function Get-WslPath([string]$Path) {
    $result = @(& $wsl -d Ubuntu --exec wslpath -a $Path 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or [string]$result[0] -notmatch '^/mnt/[a-z]/') {
        Stop-PrivateQa 'wsl_path_conversion_failed'
    }
    return [string]$result[0]
}

function Assert-StagingPath([hashtable]$Values) {
    $configured = Require-Value $Values 'PRIVATE_QA_STAGING_PATH' 'staging_path_missing'
    if (
        $configured -notmatch '^/mnt/[a-z]/[A-Za-z0-9._/\p{L}-]+$' -or
        $configured.Contains('..') -or
        $configured.Contains('//')
    ) {
        Stop-PrivateQa 'staging_path_invalid'
    }
    $windows = @(& $wsl -d Ubuntu --exec wslpath -w $configured 2>&1)
    if ($LASTEXITCODE -ne 0 -or $windows.Count -ne 1) { Stop-PrivateQa 'staging_path_invalid' }
    $item = Get-Item -LiteralPath ([string]$windows[0]) -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-PrivateQa 'staging_path_untrusted'
    }
    $full = [IO.Path]::GetFullPath($item.FullName).TrimEnd('\', '/')
    $privatePrefix = [IO.Path]::GetFullPath($privateRoot).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not ($full + [IO.Path]::DirectorySeparatorChar).StartsWith($privatePrefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateQa 'staging_outside_ignored_private_root'
    }
    $cursor = $item
    while ($null -ne $cursor -and $cursor.FullName.StartsWith($privatePrefix.TrimEnd('\', '/'), [StringComparison]::OrdinalIgnoreCase)) {
        if ($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-PrivateQa 'staging_path_untrusted' }
        $cursor = $cursor.Parent
    }
    $relative = Get-ProjectRelativePath $full
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateQa 'staging_not_ignored' }
    $canonical = Get-WslPath $full
    if (-not [string]::Equals($configured.TrimEnd('/'), $canonical.TrimEnd('/'), [StringComparison]::Ordinal)) {
        Stop-PrivateQa 'staging_path_not_canonical'
    }
    return $canonical.TrimEnd('/')
}

function Assert-SelectionManifestPath([hashtable]$Values) {
    $configured = Require-Value $Values 'PRIVATE_QA_SELECTION_MANIFEST_PATH' 'selection_manifest_path_missing'
    if (
        $configured -notmatch '^/mnt/[a-z]/[A-Za-z0-9._/\p{L}-]+$' -or
        $configured.Contains('..') -or
        $configured.Contains('//') -or
        -not $configured.EndsWith('/selection/private-selection-manifest.json', [StringComparison]::Ordinal)
    ) {
        Stop-PrivateQa 'selection_manifest_path_invalid'
    }
    $windows = @(& $wsl -d Ubuntu --exec wslpath -w $configured 2>&1)
    if ($LASTEXITCODE -ne 0 -or $windows.Count -ne 1) { Stop-PrivateQa 'selection_manifest_path_invalid' }
    $item = Get-Item -LiteralPath ([string]$windows[0]) -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-PrivateQa 'selection_manifest_path_untrusted'
    }
    $full = [IO.Path]::GetFullPath($item.FullName)
    $privatePrefix = [IO.Path]::GetFullPath($privateRoot).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($privatePrefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateQa 'selection_manifest_outside_ignored_private_root'
    }
    $cursor = $item.Directory
    while ($null -ne $cursor -and $cursor.FullName.StartsWith($privatePrefix.TrimEnd('\', '/'), [StringComparison]::OrdinalIgnoreCase)) {
        if ($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-PrivateQa 'selection_manifest_path_untrusted' }
        $cursor = $cursor.Parent
    }
    $relative = Get-ProjectRelativePath $full
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateQa 'selection_manifest_not_ignored' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
    $canonical = Get-WslPath $full
    if (-not [string]::Equals($configured, $canonical, [StringComparison]::Ordinal)) {
        Stop-PrivateQa 'selection_manifest_path_not_canonical'
    }
    return $canonical
}

function Get-PropertyValue([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Invoke-ComposeJson([string[]]$Arguments) {
    $output = @(& $wsl -d Ubuntu --cd $projectRoot -- @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0 -or $output.Count -lt 1) { Stop-PrivateQa 'compose_config_failed' }
    try {
        return ([string]::Join("`n", $output) | ConvertFrom-Json -ErrorAction Stop)
    } catch {
        Stop-PrivateQa 'compose_config_invalid'
    }
}

function Get-PiwigoComposePrefix([string]$EnvRelative) {
    return @(
        'docker', 'compose', '--env-file', $EnvRelative,
        '-f', $piwigoCompose, '-f', $piwigoOverride,
        '-p', $piwigoProject, '--profile', 'ops'
    )
}

function Get-ImmichComposePrefix([string]$EnvRelative, [string]$EnvWslPath) {
    return @(
        'env', ('IMMICH_SPIKE_ENV_FILE=' + $EnvWslPath),
        'docker', 'compose', '--env-file', $EnvRelative,
        '-f', $immichCompose, '-f', $immichOverride,
        '-p', $immichProject,
        '--profile', 'immich-spike', '--profile', 'immich-ml',
        '--profile', 'immich-web-compat', '--profile', 'immich-gateway-integration'
    )
}

function Assert-PortBindings([object]$Config) {
    $services = Get-PropertyValue $Config 'services'
    $piwigo = Get-PropertyValue $services 'piwigo'
    $ports = @((Get-PropertyValue $piwigo 'ports'))
    if ($ports.Count -ne 2) { Stop-PrivateQa 'private_port_count_invalid' }
    $actual = @{}
    foreach ($port in $ports) {
        $hostIp = [string](Get-PropertyValue $port 'host_ip')
        $published = [string](Get-PropertyValue $port 'published')
        $target = [string](Get-PropertyValue $port 'target')
        if ($hostIp -ne '127.0.0.1' -or $published -notin @('8190', '8191')) {
            Stop-PrivateQa 'private_port_binding_invalid'
        }
        $actual[$published] = $target
    }
    if ($actual['8190'] -ne '80' -or $actual['8191'] -ne '8081') {
        Stop-PrivateQa 'private_port_binding_invalid'
    }
    foreach ($serviceProperty in $services.PSObject.Properties) {
        if ($serviceProperty.Name -eq 'piwigo') { continue }
        $servicePorts = Get-PropertyValue $serviceProperty.Value 'ports'
        if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) {
            Stop-PrivateQa ('unexpected_host_port_' + $serviceProperty.Name)
        }
    }
}

function Assert-VolumeConfig([object]$Config, [hashtable]$Expected) {
    $volumes = Get-PropertyValue $Config 'volumes'
    foreach ($logical in $Expected.Keys) {
        $record = Get-PropertyValue $volumes $logical
        if ($null -eq $record) { Stop-PrivateQa ('resolved_volume_missing_' + $logical) }
        $name = [string](Get-PropertyValue $record 'name')
        if ($name -ne [string]$Expected[$logical] -or $name -notmatch '^class_archive_private_qa_') {
            Stop-PrivateQa ('resolved_volume_identity_invalid_' + $logical)
        }
    }
}

function Assert-NetworkConfig([object]$Config, [string]$GatewayLogical) {
    $networks = Get-PropertyValue $Config 'networks'
    foreach ($property in $networks.PSObject.Properties) {
        $name = [string](Get-PropertyValue $property.Value 'name')
        if ($name -notmatch '^class_archive_private_qa_') { Stop-PrivateQa 'resolved_network_identity_invalid' }
    }
    $gateway = Get-PropertyValue $networks $GatewayLogical
    if ([string](Get-PropertyValue $gateway 'name') -ne $gatewayNetwork) {
        Stop-PrivateQa 'resolved_gateway_network_invalid'
    }
}

function Assert-PiwigoConfig([object]$Config, [string]$StagingWsl, [string]$SelectionWsl) {
    if ([string](Get-PropertyValue $Config 'name') -ne $piwigoProject) { Stop-PrivateQa 'piwigo_project_invalid' }
    Assert-PortBindings $Config
    Assert-NetworkConfig $Config 'immich_gateway'
    $piwigoNetworks = Get-PropertyValue $Config 'networks'
    if ((Get-PropertyValue (Get-PropertyValue $piwigoNetworks 'app') 'internal') -eq $true) {
        Stop-PrivateQa 'piwigo_loopback_ingress_network_invalid'
    }
    if ((Get-PropertyValue (Get-PropertyValue $piwigoNetworks 'immich_gateway') 'internal') -ne $true) {
        Stop-PrivateQa 'piwigo_gateway_network_not_internal'
    }
    Assert-VolumeConfig $Config @{
        piwigo_data = 'class_archive_private_qa_piwigo_data'
        piwigo_uploads = 'class_archive_private_qa_piwigo_uploads'
        piwigo_galleries = 'class_archive_private_qa_piwigo_galleries'
        piwigo_derivatives = 'class_archive_private_qa_piwigo_derivatives'
        piwigo_db = 'class_archive_private_qa_piwigo_db'
        piwigo_scripts = 'class_archive_private_qa_piwigo_scripts'
        backups = 'class_archive_private_qa_piwigo_backups'
    }
    $piwigo = Get-PropertyValue (Get-PropertyValue $Config 'services') 'piwigo'
    $environment = Get-PropertyValue $piwigo 'environment'
    if ([string](Get-PropertyValue $environment 'CLASS_ARCHIVE_PRIVATE_REAL_QA') -ne '1') {
        Stop-PrivateQa 'private_real_qa_gate_missing'
    }
    $staging = @((Get-PropertyValue $piwigo 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/private-real-qa/staging' })
    if (
        $staging.Count -ne 1 -or
        [string](Get-PropertyValue $staging[0] 'type') -ne 'bind' -or
        [string](Get-PropertyValue $staging[0] 'source') -ne $StagingWsl -or
        (Get-PropertyValue $staging[0] 'read_only') -ne $true -or
        (Get-PropertyValue (Get-PropertyValue $staging[0] 'bind') 'create_host_path') -ne $false
    ) {
        Stop-PrivateQa 'staging_mount_not_read_only'
    }
    $selection = @((Get-PropertyValue $piwigo 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/private-real-qa/selection/private-selection-manifest.json' })
    if (
        $selection.Count -ne 1 -or
        [string](Get-PropertyValue $selection[0] 'type') -ne 'bind' -or
        [string](Get-PropertyValue $selection[0] 'source') -ne $SelectionWsl -or
        (Get-PropertyValue $selection[0] 'read_only') -ne $true -or
        (Get-PropertyValue (Get-PropertyValue $selection[0] 'bind') 'create_host_path') -ne $false
    ) {
        Stop-PrivateQa 'selection_manifest_mount_not_read_only'
    }
}

function Assert-ImmichConfig([object]$Config, [string]$StagingWsl, [string]$SelectionWsl, [string]$PhotoUiWsl) {
    if ([string](Get-PropertyValue $Config 'name') -ne $immichProject) { Stop-PrivateQa 'immich_project_invalid' }
    Assert-NetworkConfig $Config 'class_archive_gateway'
    $networks = Get-PropertyValue $Config 'networks'
    foreach ($logical in @('immich_internal', 'immich_ml_internal', 'immich_bridge_internal')) {
        if ((Get-PropertyValue (Get-PropertyValue $networks $logical) 'internal') -ne $true) {
            Stop-PrivateQa ('immich_network_not_internal_' + $logical)
        }
    }
    if ((Get-PropertyValue (Get-PropertyValue $networks 'class_archive_gateway') 'external') -ne $true) {
        Stop-PrivateQa 'immich_gateway_not_external'
    }
    Assert-VolumeConfig $Config @{
        immich_upload = 'class_archive_private_qa_immich_upload'
        immich_model_cache = 'class_archive_private_qa_immich_model_cache'
        immich_db = 'class_archive_private_qa_immich_db'
        immich_gateway_secret = 'class_archive_private_qa_immich_gateway_secret'
        piwigo_uploads = 'class_archive_private_qa_piwigo_uploads'
        piwigo_galleries = 'class_archive_private_qa_piwigo_galleries'
    }
    $services = Get-PropertyValue $Config 'services'
    foreach ($serviceProperty in $services.PSObject.Properties) {
        $servicePorts = Get-PropertyValue $serviceProperty.Value 'ports'
        if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) {
            Stop-PrivateQa ('immich_host_port_forbidden_' + $serviceProperty.Name)
        }
        foreach ($mount in @((Get-PropertyValue $serviceProperty.Value 'volumes'))) {
            if (
                [string](Get-PropertyValue $mount 'source') -in @($StagingWsl, $SelectionWsl) -or
                [string](Get-PropertyValue $mount 'target') -like '/private-real-qa*'
            ) {
                Stop-PrivateQa 'staging_mounted_into_immich'
            }
        }
    }
    $web = Get-PropertyValue $services 'immich-web-compat'
    $environment = Get-PropertyValue $web 'environment'
    if ([string](Get-PropertyValue $environment 'CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT') -ne '8191') {
        Stop-PrivateQa 'immich_compat_port_invalid'
    }
    if ([string](Get-PropertyValue $environment 'CLASS_ARCHIVE_PHOTO_UI_ROOT') -ne '/photo-ui') {
        Stop-PrivateQa 'immich_photo_ui_root_invalid'
    }
    $photoUi = @((Get-PropertyValue $web 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/photo-ui' })
    if (
        $photoUi.Count -ne 1 -or
        [string](Get-PropertyValue $photoUi[0] 'type') -ne 'bind' -or
        [string](Get-PropertyValue $photoUi[0] 'source') -ne $PhotoUiWsl -or
        (Get-PropertyValue $photoUi[0] 'read_only') -ne $true
    ) {
        Stop-PrivateQa 'immich_photo_ui_mount_invalid'
    }
    $server = Get-PropertyValue $services 'immich-server'
    foreach ($target in @('/external/piwigo-upload', '/external/piwigo-galleries')) {
        $mount = @((Get-PropertyValue $server 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq $target })
        if ($mount.Count -ne 1 -or (Get-PropertyValue $mount[0] 'read_only') -ne $true) {
            Stop-PrivateQa 'immich_original_mount_not_read_only'
        }
    }
}

function Invoke-Compose([string[]]$Prefix, [string[]]$Command) {
    $arguments = $Prefix + $Command
    & $wsl -d Ubuntu --cd $projectRoot -- @arguments
    if ($LASTEXITCODE -ne 0) { Stop-PrivateQa 'compose_command_failed' }
}

try {
    $script:stage = 'piwigo_env_file'
    $piwigoEnvFull = Assert-IgnoredUntrackedFile $PiwigoEnvPath 'piwigo_env'
    $script:stage = 'immich_env_file'
    $immichEnvFull = Assert-IgnoredUntrackedFile $ImmichEnvPath 'immich_env'

    $piwigoAllowed = @(
        'COMPOSE_PROJECT_NAME', 'CLASS_ARCHIVE_HTTP_PORT', 'CLASS_ARCHIVE_COMPAT_HTTP_PORT',
        'CLASS_ARCHIVE_GATEWAY_NETWORK', 'CLASS_ARCHIVE_BASE_URL', 'CLASS_ARCHIVE_TIMEZONE',
        'PRIVATE_QA_STAGING_PATH', 'PRIVATE_QA_SELECTION_MANIFEST_PATH', 'PIWIGO_UID', 'PIWIGO_GID', 'PIWIGO_DATA_VOLUME',
        'PIWIGO_UPLOADS_VOLUME', 'PIWIGO_GALLERIES_VOLUME', 'PIWIGO_DERIVATIVES_VOLUME',
        'PIWIGO_DB_VOLUME', 'PIWIGO_SCRIPTS_VOLUME', 'PIWIGO_BACKUPS_VOLUME', 'PIWIGO_IMAGE',
        'MARIADB_IMAGE', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_ROOT_PASSWORD',
        'PIWIGO_ADMIN_USERNAME', 'PIWIGO_ADMIN_EMAIL', 'CLASS_ARCHIVE_CLAIM_CODE_PEPPER',
        'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME',
        'SMTP_PASSWORD', 'SMTP_ENCRYPTION'
    )
    $immichAllowed = @(
        'IMMICH_COMPOSE_PROJECT_NAME', 'CLASS_ARCHIVE_COMPAT_HTTP_PORT', 'CLASS_ARCHIVE_CORE_PUBLIC_PORT', 'CLASS_ARCHIVE_GATEWAY_NETWORK',
        'IMMICH_UPLOAD_VOLUME', 'IMMICH_MODEL_CACHE_VOLUME', 'IMMICH_DB_VOLUME',
        'IMMICH_GATEWAY_SECRET_VOLUME', 'PIWIGO_UPLOADS_VOLUME', 'PIWIGO_GALLERIES_VOLUME',
        'DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE_NAME', 'TZ'
    )
    $script:stage = 'env_parse'
    $piwigoEnv = Read-StrictEnvironment $piwigoEnvFull $piwigoAllowed 'piwigo_env'
    $immichEnv = Read-StrictEnvironment $immichEnvFull $immichAllowed 'immich_env'

    $script:stage = 'piwigo_values'
    Assert-ExactValue $piwigoEnv 'COMPOSE_PROJECT_NAME' $piwigoProject 'piwigo_project_invalid'
    Assert-ExactValue $piwigoEnv 'CLASS_ARCHIVE_HTTP_PORT' '8190' 'private_http_port_invalid'
    Assert-ExactValue $piwigoEnv 'CLASS_ARCHIVE_COMPAT_HTTP_PORT' '8191' 'private_compat_port_invalid'
    Assert-ExactValue $piwigoEnv 'CLASS_ARCHIVE_GATEWAY_NETWORK' $gatewayNetwork 'gateway_network_invalid'
    Assert-ExactValue $piwigoEnv 'CLASS_ARCHIVE_BASE_URL' 'http://127.0.0.1:8190' 'private_base_url_invalid'
    Assert-ExactValue $piwigoEnv 'CLASS_ARCHIVE_TIMEZONE' 'Asia/Shanghai' 'private_timezone_invalid'
    Assert-ExactValue $piwigoEnv 'PIWIGO_IMAGE' 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84' 'piwigo_image_invalid'
    Assert-ExactValue $piwigoEnv 'MARIADB_IMAGE' 'mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf' 'mariadb_image_invalid'
    foreach ($smtp in @('SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION')) {
        if ($piwigoEnv.ContainsKey($smtp) -and [string]$piwigoEnv[$smtp] -ne '') { Stop-PrivateQa 'smtp_must_remain_disabled' }
    }
    Assert-Secret $piwigoEnv 'DB_PASSWORD' 'piwigo_db_secret_invalid'
    Assert-Secret $piwigoEnv 'DB_ROOT_PASSWORD' 'piwigo_root_secret_invalid'
    Assert-Secret $piwigoEnv 'CLASS_ARCHIVE_CLAIM_CODE_PEPPER' 'claim_pepper_invalid'
    Assert-Secret $piwigoEnv 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET' 'pseudonym_secret_invalid'
    Assert-Volume $piwigoEnv 'PIWIGO_DATA_VOLUME' 'class_archive_private_qa_piwigo_data'
    Assert-Volume $piwigoEnv 'PIWIGO_UPLOADS_VOLUME' 'class_archive_private_qa_piwigo_uploads'
    Assert-Volume $piwigoEnv 'PIWIGO_GALLERIES_VOLUME' 'class_archive_private_qa_piwigo_galleries'
    Assert-Volume $piwigoEnv 'PIWIGO_DERIVATIVES_VOLUME' 'class_archive_private_qa_piwigo_derivatives'
    Assert-Volume $piwigoEnv 'PIWIGO_DB_VOLUME' 'class_archive_private_qa_piwigo_db'
    Assert-Volume $piwigoEnv 'PIWIGO_SCRIPTS_VOLUME' 'class_archive_private_qa_piwigo_scripts'
    Assert-Volume $piwigoEnv 'PIWIGO_BACKUPS_VOLUME' 'class_archive_private_qa_piwigo_backups'
    $script:stage = 'staging'
    $stagingWsl = Assert-StagingPath $piwigoEnv
    $script:stage = 'selection_manifest'
    $selectionWsl = Assert-SelectionManifestPath $piwigoEnv
    $stagingParentWsl = $stagingWsl.Substring(0, $stagingWsl.LastIndexOf('/'))
    $expectedSelectionWsl = $stagingParentWsl + '/selection/private-selection-manifest.json'
    if (-not [string]::Equals($selectionWsl, $expectedSelectionWsl, [StringComparison]::Ordinal)) {
        Stop-PrivateQa 'private_real_qa_importer_layout_invalid'
    }

    $script:stage = 'immich_values'
    Assert-ExactValue $immichEnv 'IMMICH_COMPOSE_PROJECT_NAME' $immichProject 'immich_project_invalid'
    Assert-ExactValue $immichEnv 'CLASS_ARCHIVE_COMPAT_HTTP_PORT' '8191' 'immich_compat_port_invalid'
    Assert-ExactValue $immichEnv 'CLASS_ARCHIVE_CORE_PUBLIC_PORT' '8190' 'immich_core_public_port_invalid'
    Assert-ExactValue $immichEnv 'CLASS_ARCHIVE_GATEWAY_NETWORK' $gatewayNetwork 'gateway_network_invalid'
    Assert-Secret $immichEnv 'DB_PASSWORD' 'immich_db_secret_invalid'
    Assert-Volume $immichEnv 'IMMICH_UPLOAD_VOLUME' 'class_archive_private_qa_immich_upload'
    Assert-Volume $immichEnv 'IMMICH_MODEL_CACHE_VOLUME' 'class_archive_private_qa_immich_model_cache'
    Assert-Volume $immichEnv 'IMMICH_DB_VOLUME' 'class_archive_private_qa_immich_db'
    Assert-Volume $immichEnv 'IMMICH_GATEWAY_SECRET_VOLUME' 'class_archive_private_qa_immich_gateway_secret'
    Assert-Volume $immichEnv 'PIWIGO_UPLOADS_VOLUME' 'class_archive_private_qa_piwigo_uploads'
    Assert-Volume $immichEnv 'PIWIGO_GALLERIES_VOLUME' 'class_archive_private_qa_piwigo_galleries'

    $script:stage = 'compose_paths'
    $piwigoRelative = Get-ProjectRelativePath $piwigoEnvFull
    $immichRelative = Get-ProjectRelativePath $immichEnvFull
    $immichEnvWsl = Get-WslPath $immichEnvFull
    $photoUiWsl = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\photo-ui')
    $piwigoPrefix = Get-PiwigoComposePrefix $piwigoRelative
    $immichPrefix = Get-ImmichComposePrefix $immichRelative $immichEnvWsl
    $script:stage = 'piwigo_config'
    $piwigoConfig = Invoke-ComposeJson @($piwigoPrefix + @('config', '--format', 'json'))
    $script:stage = 'immich_config'
    $immichConfig = Invoke-ComposeJson @($immichPrefix + @('config', '--format', 'json'))
    $script:stage = 'piwigo_assert'
    Assert-PiwigoConfig $piwigoConfig $stagingWsl $selectionWsl
    $script:stage = 'immich_assert'
    Assert-ImmichConfig $immichConfig $stagingWsl $selectionWsl $photoUiWsl

    if ($Action -eq 'up') {
        $dirty = @(& git -C $projectRoot status --porcelain)
        if ($LASTEXITCODE -ne 0 -or $dirty.Count -gt 0) { Stop-PrivateQa 'refusing_dirty_runtime_start' }
        Invoke-Compose $piwigoPrefix @('up', '-d', 'db', 'piwigo')
        Invoke-Compose $immichPrefix @('up', '-d', 'database', 'redis', 'immich-machine-learning', 'immich-server', 'immich-web-compat')
    } elseif ($Action -eq 'stop') {
        Invoke-Compose $immichPrefix @('stop')
        Invoke-Compose $piwigoPrefix @('stop', 'piwigo', 'db')
    } elseif ($Action -eq 'ps') {
        Invoke-Compose $piwigoPrefix @('ps')
        Invoke-Compose $immichPrefix @('ps')
    }

    Write-Output "PRIVATE_QA=PASS action=$Action evidence=CONFIG_VALIDATED"
    exit 0
} catch {
    $type = $_.Exception.GetType().Name
    if ($type -notmatch '^[A-Za-z0-9]{1,64}$') { $type = 'Exception' }
    Stop-PrivateQa ('private_qa_validation_exception_' + $script:stage + '_' + $type)
}
